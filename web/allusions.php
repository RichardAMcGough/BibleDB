<?php
/**
 * allusions.php — Browser for the biblewheel_research.allusions table.
 *
 * Displays the saved intertextual allusions (source → target verse pairs with
 * Swale Terms/Themes/Thesis scores) and renders the full markdown analysis
 * report attached to each record (full_analysis column).
 *
 * The allusions live in the `biblewheel_research` database, separate from the
 * main `stepbible` Bible DB. We reuse config.php for host/user/password but
 * override the database name.
 *
 * AJAX endpoint:  allusions.php?ajax=report&id=N  → JSON {ok, row}
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Open a PDO connection to the research DB (reuses config.php credentials). */
function research_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $cfg_path = __DIR__ . '/config.php';
    if (!file_exists($cfg_path)) {
        http_response_code(500);
        die("Missing config.php");
    }
    $cfg = require $cfg_path;
    $db  = 'biblewheel_research';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                   $cfg['host'], $cfg['port'], $db);
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], $opts);
    } catch (PDOException $e) {
        http_response_code(500);
        die("Research DB connection failed" . (!empty($cfg['debug']) ? ": " . htmlspecialchars($e->getMessage()) : "."));
    }
    return $pdo;
}

/** Coarse grade band from total_score (informal AAA+/AAA/AA/A scale). */
function allusion_grade(?int $total): string {
    if ($total === null) return '—';
    if ($total >= 26) return 'AAA+';
    if ($total >= 23) return 'AAA';
    if ($total >= 20) return 'AA+';
    if ($total >= 17) return 'AA';
    if ($total >= 14) return 'A';
    if ($total >= 11) return 'A–';
    if ($total >= 8)  return 'B+';
    return 'B';
}

/** Extract a coarse "book" label from a reference like "Isa 1:9" → "Isaiah". */
function ref_book(string $ref): string {
    $ref = trim($ref);
    // strip leading verse range / chapter:verse
    if (preg_match('/^((?:[1-3]\s)?[A-Za-z]+)/', $ref, $m)) {
        $b = trim($m[1]);
        static $map = [
            'Gen'=>'Genesis','Exod'=>'Exodus','Exodus'=>'Exodus','Lev'=>'Leviticus',
            'Num'=>'Numbers','Numbers'=>'Numbers','Deut'=>'Deuteronomy','Josh'=>'Joshua',
            'Judg'=>'Judges','Ruth'=>'Ruth','Sam'=>'Samuel','Kgs'=>'Kings','Isa'=>'Isaiah',
            'Jer'=>'Jeremiah','Lam'=>'Lamentations','Ezek'=>'Ezekiel','Nah'=>'Nahum',
            'Joel'=>'Joel','Est'=>'Esther','Matt'=>'Matthew','Mark'=>'Mark','Luke'=>'Luke',
            'John'=>'John','Acts'=>'Acts','Rom'=>'Romans','Eph'=>'Ephesians','Jas'=>'James',
            'Rev'=>'Revelation','Pet'=>'Peter',
        ];
        // handle "1 Sam", "2 Pet"
        if (preg_match('/^([1-3])\s+([A-Za-z]+)/', $ref, $mm)) {
            $base = $map[$mm[2]] ?? $mm[2];
            return $mm[1] . ' ' . $base;
        }
        return $map[$b] ?? $b;
    }
    return $ref;
}

// ── AJAX: return one record's full data (incl. markdown) ──────────────────
if (($_GET['ajax'] ?? '') === 'report') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    try {
        $stmt = research_pdo()->prepare(
            "SELECT id, source_ref, source_range, target_ref, target_range,
                    terms_score, themes_score, thesis_score, total_score,
                    confidence, context, explanation, tags, full_analysis, created_at
               FROM allusions WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        echo json_encode(['ok' => (bool)$row, 'row' => $row ?: null], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'query failed']);
    }
    exit;
}

// ── Main page: fetch all rows for the table ───────────────────────────────
$rows = [];
$db_error = '';
try {
    $stmt = research_pdo()->query(
        "SELECT id, source_ref, source_range, target_ref, target_range,
                terms_score, themes_score, thesis_score, total_score,
                confidence, context, tags,
                (full_analysis IS NOT NULL AND full_analysis <> '') AS has_report
           FROM allusions
          ORDER BY total_score DESC, id ASC");
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $db_error = 'Could not read the allusions table. Is the biblewheel_research database present?';
}

// Build filter option sets
$books = []; $confs = [];
foreach ($rows as $r) {
    $books[ref_book((string)$r['source_ref'])] = true;
    $books[ref_book((string)$r['target_ref'])] = true;
    if (!empty($r['confidence'])) $confs[$r['confidence']] = true;
}
$books = array_keys($books); sort($books);
$confs = array_keys($confs);

$total_count = count($rows);
$scored = array_filter($rows, fn($r) => $r['total_score'] !== null);
$avg = $scored ? round(array_sum(array_column($scored, 'total_score')) / count($scored), 1) : 0;

bible_render_layout_header();
?>
<html><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Bible Allusions — Bible Wheel</title>
<?php bible_render_layout_styles(); ?>
<style>
  main h1 { font-size: 1.4rem; margin-bottom: 2px; }
  .al-sub { font-size: 13px; color: var(--muted); margin: 0 0 14px; }
  .al-controls {
      display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
      margin: 12px 0; padding: 12px; background: var(--card);
      border: 1px solid var(--border); border-radius: 6px;
  }
  .al-controls label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  .al-controls select, .al-controls input {
      font-size: 13px; padding: 5px 8px; margin-left: 5px;
      border: 1px solid var(--border); border-radius: 4px;
      background: var(--card); color: var(--fg);
  }
  .al-controls input { min-width: 180px; }
  .al-stat { font-size: 12px; color: var(--muted); margin-left: auto; }
  table.al { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--card);
             border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
  table.al th, table.al td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--border); }
  table.al th { background: var(--bg-alt); font-size: 11px; text-transform: uppercase;
                letter-spacing: .04em; color: var(--muted); cursor: pointer; user-select: none; white-space: nowrap; }
  table.al th:hover { color: var(--accent); }
  table.al tbody tr { cursor: pointer; transition: background .1s; }
  table.al tbody tr:hover { background: var(--accent-bg); }
  .ref { font-weight: 600; color: var(--accent); white-space: nowrap; font-variant-numeric: tabular-nums; }
  .arrow { color: var(--muted); padding: 0 4px; }
  .sc { font-variant-numeric: tabular-nums; text-align: center; color: var(--muted); }
  .sc-total { font-weight: 700; color: var(--fg); }
  .grade { display: inline-block; padding: 2px 7px; border-radius: 4px; font-weight: 700;
           font-size: 11px; background: var(--accent-bg); color: var(--accent); }
  .grade.g-AAAp, .grade.g-AAA { background: #fde68a; color: #92400e; }
  .grade.g-AAp, .grade.g-AA  { background: #bfdbfe; color: #1e40af; }
  .conf { font-size: 11px; padding: 2px 7px; border-radius: 4px; }
  .conf-high { background: #dcfce7; color: #166534; }
  .conf-moderate { background: #fef9c3; color: #854d0e; }
  .conf-low { background: #fee2e2; color: #991b1b; }
  .tags { font-size: 11px; color: var(--muted); }
  .rpt-dot { color: #16a34a; font-weight: 700; }
  /* Modal */
  .al-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: none; z-index: 1000;
                 align-items: flex-start; justify-content: center; padding: 30px 16px; overflow-y: auto; }
  .al-modal-bg.open { display: flex; }
  .al-modal { background: var(--card); border-radius: 8px; max-width: 920px; width: 100%;
              box-shadow: 0 10px 40px rgba(0,0,0,.3); margin-bottom: 40px; }
  .al-modal-head { display: flex; align-items: flex-start; justify-content: space-between;
                   padding: 16px 22px; border-bottom: 1px solid var(--border); position: sticky; top: 0;
                   background: var(--card); border-radius: 8px 8px 0 0; }
  .al-modal-head h2 { margin: 0; font-size: 18px; color: var(--accent); }
  .al-modal-head .x { cursor: pointer; font-size: 24px; color: var(--muted); line-height: 1; border: none;
                      background: none; padding: 0 4px; }
  .al-modal-head .x:hover { color: var(--fg); }
  .al-modal-meta { padding: 12px 22px; display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px;
                   border-bottom: 1px solid var(--border); background: var(--bg-alt); }
  .al-modal-meta b { color: var(--fg); }
  .al-modal-body { padding: 8px 26px 30px; }
  .al-modal-body h1 { font-size: 22px; color: var(--accent); border-bottom: 2px solid var(--border); padding-bottom: 6px; }
  .al-modal-body h2 { font-size: 18px; color: var(--accent); margin-top: 26px; }
  .al-modal-body h3 { font-size: 15px; margin-top: 20px; }
  .al-modal-body table { border-collapse: collapse; width: 100%; font-size: 13px; margin: 12px 0; }
  .al-modal-body th, .al-modal-body td { border: 1px solid var(--border); padding: 6px 9px; text-align: left; }
  .al-modal-body th { background: var(--bg-alt); }
  .al-modal-body code { background: var(--bg-alt); padding: 1px 5px; border-radius: 3px; font-size: 12px; }
  .al-modal-body blockquote { border-left: 3px solid var(--accent); margin: 12px 0; padding: 4px 14px; color: var(--muted); }
  .al-expl { padding: 14px 22px; font-size: 14px; line-height: 1.55; border-bottom: 1px solid var(--border); }
  .al-expl b { color: var(--accent); }
  .no-report { color: var(--muted); font-style: italic; padding: 20px 26px; }
  .empty { padding: 30px; text-align: center; color: var(--muted); }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">

<h1>Bible Allusions</h1>
<p class="al-sub">Intertextual allusions scored by Matthew Swale's three-instinct method
(Terms · Themes · Thesis). Click any row to read the full analysis.</p>

<?php if ($db_error): ?>
  <div class="empty"><?= htmlspecialchars($db_error) ?></div>
<?php else: ?>

<div class="al-controls">
  <div><label>Book</label>
    <select id="f-book"><option value="">All</option>
      <?php foreach ($books as $b): ?><option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div><label>Confidence</label>
    <select id="f-conf"><option value="">All</option>
      <?php foreach ($confs as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars(ucfirst($c)) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div><label>Search</label>
    <input id="f-kw" type="text" placeholder="ref, tag, or context…">
  </div>
  <span class="al-stat"><span id="shown"><?= $total_count ?></span> of <?= $total_count ?> · avg score <?= $avg ?></span>
</div>

<table class="al" id="al-table">
  <thead><tr>
    <th data-sort="id">#</th>
    <th data-sort="src">Source</th>
    <th data-sort="tgt">Target</th>
    <th data-sort="te" title="Terms">Te</th>
    <th data-sort="th" title="Themes">Th</th>
    <th data-sort="ts" title="Thesis">Ts</th>
    <th data-sort="tot" title="Total score">Total</th>
    <th data-sort="grade">Grade</th>
    <th data-sort="conf">Conf.</th>
    <th>Context / Tags</th>
    <th>Report</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $srcBook = ref_book((string)$r['source_ref']);
      $tgtBook = ref_book((string)$r['target_ref']);
      $grade = allusion_grade($r['total_score'] !== null ? (int)$r['total_score'] : null);
      $gradeCls = 'g-' . str_replace(['+','–','-'], ['p','',''], $grade);
      $srcDisp = htmlspecialchars($r['source_ref']) . ($r['source_range'] ? '<span class="arrow">+'.(int)$r['source_range'].'</span>' : '');
      $tgtDisp = htmlspecialchars($r['target_ref']) . ($r['target_range'] ? '<span class="arrow">+'.(int)$r['target_range'].'</span>' : '');
      $hay = strtolower($r['source_ref'].' '.$r['target_ref'].' '.($r['tags']??'').' '.($r['context']??''));
  ?>
    <tr data-id="<?= (int)$r['id'] ?>"
        data-books="<?= htmlspecialchars($srcBook.'|'.$tgtBook) ?>"
        data-conf="<?= htmlspecialchars($r['confidence']??'') ?>"
        data-kw="<?= htmlspecialchars($hay) ?>"
        data-tot="<?= $r['total_score']!==null ? (int)$r['total_score'] : -1 ?>">
      <td class="sc"><?= (int)$r['id'] ?></td>
      <td><span class="ref"><?= $srcDisp ?></span></td>
      <td><span class="ref"><?= $tgtDisp ?></span></td>
      <td class="sc"><?= $r['terms_score']  ?? '·' ?></td>
      <td class="sc"><?= $r['themes_score'] ?? '·' ?></td>
      <td class="sc"><?= $r['thesis_score'] ?? '·' ?></td>
      <td class="sc sc-total"><?= $r['total_score'] ?? '·' ?></td>
      <td><span class="grade <?= $gradeCls ?>"><?= $grade ?></span></td>
      <td><?php if(!empty($r['confidence'])): ?><span class="conf conf-<?= htmlspecialchars($r['confidence']) ?>"><?= htmlspecialchars($r['confidence']) ?></span><?php endif; ?></td>
      <td class="tags"><?= htmlspecialchars(mb_strimwidth((string)($r['context'] ?: $r['tags'] ?: ''), 0, 70, '…')) ?></td>
      <td style="text-align:center"><?= $r['has_report'] ? '<span class="rpt-dot">●</span>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$rows): ?><div class="empty">No allusions saved yet.</div><?php endif; ?>

<?php endif; ?>
</main>
</div>

<!-- Modal -->
<div class="al-modal-bg" id="al-modal-bg">
  <div class="al-modal">
    <div class="al-modal-head">
      <h2 id="m-title">…</h2>
      <button class="x" onclick="closeModal()">&times;</button>
    </div>
    <div class="al-modal-meta" id="m-meta"></div>
    <div class="al-expl" id="m-expl"></div>
    <div class="al-modal-body" id="m-body"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
// ---- Filtering ----
const tbody = document.querySelector('#al-table tbody');
const allRows = tbody ? [...tbody.querySelectorAll('tr')] : [];
const fBook = document.getElementById('f-book'),
      fConf = document.getElementById('f-conf'),
      fKw   = document.getElementById('f-kw'),
      shown = document.getElementById('shown');

function applyFilter() {
  const b = fBook.value, c = fConf.value, kw = fKw.value.trim().toLowerCase();
  let n = 0;
  for (const tr of allRows) {
    const books = tr.dataset.books, conf = tr.dataset.conf, hay = tr.dataset.kw;
    const ok = (!b || books.split('|').includes(b))
            && (!c || conf === c)
            && (!kw || hay.includes(kw));
    tr.style.display = ok ? '' : 'none';
    if (ok) n++;
  }
  shown.textContent = n;
}
[fBook, fConf].forEach(el => el && el.addEventListener('change', applyFilter));
fKw && fKw.addEventListener('input', applyFilter);

// ---- Sorting ----
let sortState = {};
document.querySelectorAll('#al-table th[data-sort]').forEach((th, idx) => {
  th.addEventListener('click', () => {
    const key = th.dataset.sort;
    const dir = sortState[key] === 'asc' ? 'desc' : 'asc';
    sortState = { [key]: dir };
    const cellIndex = idx;
    const rows = allRows.slice();
    rows.sort((a, b) => {
      let av, bv;
      if (key === 'tot' || key === 'id' || key==='te'||key==='th'||key==='ts') {
        av = parseFloat(a.children[cellIndex].textContent) || -1;
        bv = parseFloat(b.children[cellIndex].textContent) || -1;
      } else {
        av = a.children[cellIndex].textContent.trim().toLowerCase();
        bv = b.children[cellIndex].textContent.trim().toLowerCase();
      }
      if (av < bv) return dir === 'asc' ? -1 : 1;
      if (av > bv) return dir === 'asc' ? 1 : -1;
      return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
  });
});

// ---- Modal / report viewer ----
const modalBg = document.getElementById('al-modal-bg');
function openModal(id) {
  fetch('allusions.php?ajax=report&id=' + id)
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { alert('Could not load record.'); return; }
      const r = d.row;
      document.getElementById('m-title').textContent =
        r.source_ref + ' → ' + r.target_ref;
      const grade = gradeOf(r.total_score);
      document.getElementById('m-meta').innerHTML =
        `<span><b>Terms</b> ${nz(r.terms_score)}</span>
         <span><b>Themes</b> ${nz(r.themes_score)}</span>
         <span><b>Thesis</b> ${nz(r.thesis_score)}</span>
         <span><b>Total</b> ${nz(r.total_score)} (${grade})</span>
         <span><b>Confidence</b> ${r.confidence||'—'}</span>
         ${r.context ? `<span><b>Context</b> ${esc(r.context)}</span>` : ''}`;
      document.getElementById('m-expl').innerHTML =
        r.explanation ? '<b>Summary.</b> ' + esc(r.explanation) : '';
      const body = document.getElementById('m-body');
      if (r.full_analysis && r.full_analysis.trim()) {
        body.innerHTML = marked.parse(r.full_analysis);
      } else {
        body.innerHTML = '<div class="no-report">No full analysis report attached to this record.</div>';
      }
      modalBg.classList.add('open');
      modalBg.scrollTop = 0;
    })
    .catch(() => alert('Network error.'));
}
function closeModal() { modalBg.classList.remove('open'); }
modalBg.addEventListener('click', e => { if (e.target === modalBg) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
allRows.forEach(tr => tr.addEventListener('click', () => openModal(tr.dataset.id)));

function nz(v){ return (v===null||v===undefined||v==='') ? '—' : v; }
function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function gradeOf(t){
  if(t===null||t===undefined) return '—';
  t=+t;
  if(t>=26)return'AAA+'; if(t>=23)return'AAA'; if(t>=20)return'AA+';
  if(t>=17)return'AA'; if(t>=14)return'A'; if(t>=11)return'A–';
  if(t>=8)return'B+'; return'B';
}
</script>
</body>
</html>
