<?php
// contiguous.php -- contiguous word-sum analysis for a selected verse.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function contig_factorize(int $n): array {
  $n = abs($n);
  if ($n < 2) return [];
  $out = [];
  $d = 2;
  while ($d * $d <= $n) {
    while ($n % $d === 0) {
      $out[$d] = (int)($out[$d] ?? 0) + 1;
      $n = (int)($n / $d);
    }
    $d = ($d === 2) ? 3 : ($d + 2);
  }
  if ($n > 1) $out[$n] = (int)($out[$n] ?? 0) + 1;
  ksort($out, SORT_NUMERIC);
  return $out;
}

function contig_factor_html(array $factors): string {
  if (empty($factors)) return '<span class="contig-prime-note">prime / 1</span>';
  $parts = [];
  foreach ($factors as $p => $e) {
    $p = (int)$p;
    $e = (int)$e;
    $parts[] = $e > 1
      ? ($p . '<sup>' . $e . '</sup>')
      : (string)$p;
  }
  return implode(' x ', $parts);
}

// Forward AJAX calls from the verse-selector dropdowns.
if (isset($_GET['api'])) { require __DIR__ . '/api.php'; }

// Resolve selection.
$book_code = $_GET['book']    ?? 'Gen';
$chapter   = max(1, (int)($_GET['chapter'] ?? 1));
$verse     = max(1, (int)($_GET['verse']   ?? 1));

const CONTIG_OT_BOOK_CODES = [
  'Gen','Exo','Lev','Num','Deu','Jos','Jdg','Rut',
  '1Sa','2Sa','1Ki','2Ki','1Ch','2Ch','Ezr','Neh','Est',
  'Job','Psa','Pro','Ecc','Sng','Isa','Jer','Lam','Ezk',
  'Dan','Hos','Jol','Amo','Oba','Jon','Mic','Nam','Hab',
  'Zep','Hag','Zec','Mal',
];

$books = bible_books();
if (empty($books)) {
    $books = [['osis_code' => 'Gen', 'name' => 'Genesis', 'language' => 'Hebrew']];
}

$valid_book_codes = array_column($books, 'osis_code');
if (!in_array($book_code, $valid_book_codes, true)) {
    $book_code = (string)$books[0]['osis_code'];
}

$is_ot_book = in_array($book_code, CONTIG_OT_BOOK_CODES, true);
$edition_options = [
    ['code' => 'BHS',  'name' => 'Biblia Hebraica Stuttgartensia'],
    ['code' => 'NA28', 'name' => 'Nestle-Aland 28th edition'],
    ['code' => 'TR',   'name' => 'Scrivener Textus Receptus 1894'],
];
$default_edition = $is_ot_book ? 'BHS' : 'NA28';

$edition_code = trim((string)($_GET['edition'] ?? $default_edition));
$edition_codes = array_column($edition_options, 'code');
if (!in_array($edition_code, $edition_codes, true)) {
  $edition_code = $default_edition;
}

// Keep edition selection global; auto-jump to a compatible testament when needed.
if ($edition_code === 'BHS' && !$is_ot_book) {
    $book_code = 'Gen';
    $chapter = 1;
    $verse = 1;
    $is_ot_book = true;
} elseif (($edition_code === 'NA28' || $edition_code === 'TR') && $is_ot_book) {
    $book_code = 'Mat';
    $chapter = 1;
    $verse = 1;
    $is_ot_book = false;
}

$chapters = bible_chapters($book_code);
if (!$chapters) { $chapters = [1]; }
$chapter = in_array($chapter, $chapters, true) ? $chapter : (int)$chapters[0];

$verses = bible_verses($book_code, $chapter);
if (!$verses) { $verses = [1]; }
$verse = in_array($verse, $verses, true) ? $verse : (int)$verses[0];

$vd = bible_verse_full($book_code, $chapter, $verse, $edition_code);
$vrow = $vd['verse'] ?? null;
$words_raw = $vd['words'] ?? [];

ob_start(); ?>
  <label class="sel-label">Source</label>
  <select name="edition" id="sel-edition" data-static="1" title="Text edition">
  <?php foreach ($edition_options as $ed): ?>
    <option value="<?= h($ed['code']) ?>" <?= $ed['code'] === $edition_code ? 'selected' : '' ?> title="<?= h($ed['name']) ?>"><?= h($ed['code']) ?></option>
  <?php endforeach; ?>
  </select>
<?php $selector_extra_fields = ob_get_clean();

$display_words = [];
$primary_lang = $vrow['language'] ?? 'Greek';
foreach ($words_raw as $w) {
    $orig = (string)($w['text_original'] ?? '');
    if ($orig === '') continue;

    if ($primary_lang === 'Hebrew') {
        $text = clean_inline($orig);
    } else {
        [$g_orig, ] = split_greek_word($orig);
        $text = trim((string)$g_orig);
        $text = preg_replace('/\p{P}+$/u', '', $text);
    }

    if ($text === '' || letter_count($text, $primary_lang) === 0) continue;

    $display_words[] = [
        'text' => $text,
        'gem'  => (int)($w['gem_std'] ?? 0),
    ];
}

$word_count = count($display_words);
$groups = [];
$factor_counts = [];
$fact_cache = [];
for ($start = 0; $start < $word_count; $start++) {
    $running = 0;
    $rows = [];
    for ($end = $start; $end < $word_count; $end++) {
        $running += (int)$display_words[$end]['gem'];
    if (!array_key_exists($running, $fact_cache)) {
      $fact_cache[$running] = contig_factorize($running);
    }
    $factors = $fact_cache[$running];
    foreach ($factors as $p => $e) {
      $factor_counts[(int)$p] = (int)($factor_counts[(int)$p] ?? 0) + (int)$e;
    }
        $phrase_words = array_column(array_slice($display_words, $start, $end - $start + 1), 'text');
        $rows[] = [
            'start'  => $start + 1,
            'end'    => $end + 1,
            'phrase' => implode(' ', $phrase_words),
            'sum'    => $running,
      'factors' => $factors,
        ];
    }
    $groups[] = [
        'start_idx'  => $start + 1,
        'start_word' => $display_words[$start]['text'],
        'rows'       => $rows,
    ];
}

$title_ref = $vrow
    ? (string)$vrow['osis_code'] . ' ' . (int)$vrow['chapter'] . ':' . (int)$vrow['verse']
    : ($book_code . ' ' . (int)$chapter . ':' . (int)$verse);

ksort($factor_counts, SORT_NUMERIC);
?>
<?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Contiguous Sums -- <?= h($title_ref) ?> (<?= h($edition_code) ?>) -- Bible Wheel</title>
<?php bible_render_layout_styles(); ?>
<style>
  .contig-wrap { margin-top: 14px; }
    .selector.contig-selector { position: relative; }
    .contig-selector-help {
      position: absolute;
      top: 8px;
      right: 8px;
      z-index: 2;
    }
    .contig-controls {
      margin: 0 2px 12px;
      display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center;
    }
    .contig-controls label {
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .contig-controls select {
      margin-left: 6px;
      padding: 4px 8px;
      border: 1px solid var(--border);
      border-radius: 4px;
      font-size: 13px;
      background: var(--card);
      color: var(--fg);
    }
    .contig-filter-info {
      color: var(--muted);
      font-size: 12px;
      white-space: nowrap;
    }
  .contig-head {
      display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: baseline;
      margin: 8px 2px 12px;
  }
  .contig-head h1 {
      margin: 0; font-size: 1.35rem; color: var(--accent);
  }
      .selector.contig-selector .ctx-help-btn {
      width: 24px;
        min-width: 24px;
      height: 24px;
      border-radius: 50%;
        padding: 0;
      border: 0;
      background: #1976d2;
      color: #fff;
      font-size: 15px;
      font-weight: 700;
      line-height: 1;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
    }
    .selector.contig-selector .ctx-help-btn:hover,
    .selector.contig-selector .ctx-help-btn:focus-visible {
      background: #145ea6;
      outline: none;
    }
    .ctx-help-modal-bg {
      position: fixed;
      inset: 0;
      z-index: 1200;
      background: rgba(0, 0, 0, 0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 18px;
    }
    .ctx-help-modal {
      width: min(680px, 100%);
      max-height: min(84vh, 760px);
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--card);
      color: var(--fg);
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.35);
    }
    .ctx-help-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      border-bottom: 1px solid var(--border);
      padding: 12px 14px;
      position: sticky;
      top: 0;
      background: var(--card);
    }
    .ctx-help-head h2 {
      margin: 0;
      font-size: 1.05rem;
      color: var(--accent);
    }
    .ctx-help-close {
      border: 1px solid var(--border);
      background: #f8fafc;
      color: var(--fg);
      border-radius: 4px;
      font-size: 13px;
      line-height: 1;
      padding: 6px 8px;
      cursor: pointer;
    }
    .ctx-help-close:hover,
    .ctx-help-close:focus-visible {
      border-color: var(--accent);
      color: var(--accent);
      outline: none;
    }
    .ctx-help-body {
      padding: 12px 14px 16px;
      font-size: 14px;
      line-height: 1.62;
    }
    .ctx-help-body h3 {
      margin: 12px 0 6px;
      font-size: 0.95rem;
      color: var(--fg);
    }
    .ctx-help-body p {
      margin: 5px 0 10px;
    }
    .ctx-help-body ul {
      margin: 6px 0 12px 20px;
    }
    .ctx-help-foot {
      border-top: 1px solid var(--border);
      padding: 10px 14px;
      display: flex;
      justify-content: flex-end;
      background: #fcfcfd;
    }
    .ctx-help-modal-bg[hidden] {
      display: none;
    }
  .contig-meta {
      color: var(--muted); font-size: 13px;
  }
  .contig-meta .ref {
      color: var(--fg); font-weight: 600;
  }
  .contig-start {
      margin: 10px 0 14px;
      border: 1px solid var(--border); border-radius: 6px;
      background: var(--card);
      overflow: auto;
  }
  .contig-start-title {
      margin: 0;
      padding: 8px 10px;
      font-size: 13px;
      color: var(--fg);
      border-bottom: 1px solid var(--border);
      background: #f9fafb;
  }
  .contig-start-title .idx { color: var(--accent); font-weight: 700; }
  .contig-start-title .word {
      color: var(--muted); font-weight: 500;
      font-family: <?= $primary_lang === 'Hebrew' ? 'var(--hebrew)' : 'var(--greek)' ?>;
      font-size: <?= $primary_lang === 'Hebrew' ? '18px' : '16px' ?>;
  }
  .contig-table {
      width: auto;
      border-collapse: collapse;
      table-layout: auto;
  }
  .contig-table th, .contig-table td {
      width: auto;
      padding: 5px 8px;
      border-bottom: 1px solid var(--border);
      vertical-align: top;
      font-size: 13px;
  }
  .contig-table th {
      text-align: left;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-size: 10px;
      background: #fcfcfd;
  }
  .contig-table tbody tr:last-child td { border-bottom: 0; }
  .contig-range { white-space: nowrap; color: var(--fg); font-weight: 600; }
      .contig-range-col, .contig-sum-col, .contig-factors-col {
      width: 1%;
      white-space: nowrap;
    }
      .contig-sum-col, .contig-sum {
        min-width: 78px;
        max-width: 78px;
        width: 78px;
      }
      .contig-factors-col, .contig-factors {
        min-width: 128px;
        max-width: 128px;
        width: 128px;
      }
      .contig-range-col, .contig-range {
        min-width: 78px;
        max-width: 78px;
        width: 78px;
        text-align: right;
      }
  .contig-phrase {
      color: var(--fg);
      font-family: <?= $primary_lang === 'Hebrew' ? 'var(--hebrew)' : 'var(--greek)' ?>;
      font-size: <?= $primary_lang === 'Hebrew' ? '19px' : '15px' ?>;
      <?= $primary_lang === 'Hebrew' ? 'direction: rtl; text-align: right;' : '' ?>
  }
    .contig-factors {
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
      color: var(--fg);
      font-size: 12px;
    }
    .contig-factors sup {
      font-size: 0.75em;
      line-height: 0;
      vertical-align: super;
    }
    .contig-prime-note {
      color: var(--muted);
      font-style: italic;
    }
  .contig-sum {
      text-align: right;
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
      font-weight: 700;
      color: var(--gematria);
  }
  .contig-sum a {
      color: var(--gematria);
      text-decoration: none;
      border-bottom: 1px dashed currentColor;
  }
  .contig-sum a:hover { border-bottom-style: solid; }
  .contig-empty {
      margin-top: 14px;
      border: 1px solid var(--border);
      border-radius: 6px;
      background: var(--card);
      padding: 12px;
      color: var(--muted);
  }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">

<div class="selector contig-selector">
<div class="contig-selector-help">
  <button type="button"
          class="ctx-help-btn"
          id="contig-help-open"
          aria-label="Open help for contiguous sums"
          aria-haspopup="dialog"
          aria-controls="contig-help-modal">?</button>
</div>
<?php require __DIR__ . '/verse_selector.inc.php'; ?>
</div>

<div class="contig-wrap">
  <div class="contig-head">
    <h1>Contiguous Sums</h1>
    <div class="contig-meta">
      Verse <span class="ref"><?= h($title_ref) ?></span>
      <?php if ($word_count > 0): ?>
        -- <?= (int)$word_count ?> words
      <?php endif; ?>
      -- <?= h($edition_code) ?> -- standard gematria
    </div>
  </div>

  <div class="ctx-help-modal-bg" id="contig-help-modal" hidden>
    <div class="ctx-help-modal" role="dialog" aria-modal="true" aria-labelledby="contig-help-title">
      <div class="ctx-help-head">
        <h2 id="contig-help-title">Contiguous Sums Help</h2>
        <button type="button" class="ctx-help-close" data-help-close="contig-help-modal" aria-label="Close help popup">X</button>
      </div>
      <div class="ctx-help-body">
        <p>
          This page lists every contiguous word span in the selected verse and
          computes its standard gematria sum.
        </p>
        <h3>How To Read The Table</h3>
        <ul>
          <li><strong>Value</strong>: standard gematria sum for the span; click to open gematria search for that value.</li>
          <li><strong>Factors</strong>: prime factorization of the span value.</li>
          <li><strong>Text</strong>: the actual contiguous phrase from the verse.</li>
          <li><strong>Range</strong>: 1-based word positions included in the phrase.</li>
        </ul>
        <h3>Filter Factor</h3>
        <p>
          Use <strong>Filter factor</strong> to show only rows whose value includes
          the selected prime factor. Choose <strong>All factors</strong> to reset.
        </p>
        <h3>Scope Notes</h3>
        <ul>
          <li>Calculations use the selected edition and verse only.</li>
          <li>Displayed words are normalized for readable Hebrew/Greek rendering.</li>
        </ul>
      </div>
      <div class="ctx-help-foot">
        <button type="button" class="ctx-help-close" data-help-close="contig-help-modal">Close</button>
      </div>
    </div>
  </div>

  <?php if ($word_count > 0): ?>
    <div class="contig-controls">
      <label for="factor-filter">Filter factor
        <select id="factor-filter">
          <option value="">All factors</option>
          <?php foreach ($factor_counts as $factor => $freq): ?>
            <option value="<?= (int)$factor ?>"><?= (int)$factor ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <span class="contig-filter-info" id="contig-filter-info"></span>
    </div>
  <?php endif; ?>

  <?php if ($word_count === 0): ?>
    <div class="contig-empty">No analyzable words were found for this verse.</div>
  <?php else: ?>
    <?php foreach ($groups as $g): ?>
      <section class="contig-start">
        <h2 class="contig-start-title">
          Start word <span class="idx">#<?= (int)$g['start_idx'] ?></span>
          <span class="word">(<?= h($g['start_word']) ?>)</span>
        </h2>
        <table class="contig-table">
          <thead>
            <tr>
              <th class="contig-sum-col" style="text-align:right">Value</th>
              <th class="contig-factors-col">Factors</th>
              <th>Text</th>
              <th class="contig-range-col">Range</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($g['rows'] as $r): ?>
            <?php $range_label = $r['start'] === $r['end'] ? (string)$r['start'] : ($r['start'] . '-' . $r['end']); ?>
            <?php $factor_keys = array_map('intval', array_keys($r['factors'])); ?>
            <tr data-factors="<?= h(implode(',', $factor_keys)) ?>">
              <td class="contig-sum"><a href="search.php?mode=gematria&amp;standard=<?= (int)$r['sum'] ?>"><?= (int)$r['sum'] ?></a></td>
              <td class="contig-factors"><?= contig_factor_html($r['factors']) ?></td>
              <td class="contig-phrase"><?= h($r['phrase']) ?></td>
              <td class="contig-range"><?= h($range_label) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>

<script src="js/dropdowns.js"></script>
<script>
(function () {
  'use strict';

  function bindContextHelp(openId, modalId) {
    var openBtn = document.getElementById(openId);
    var modal = document.getElementById(modalId);
    if (!openBtn || !modal) return;

    function closeModal() {
      modal.setAttribute('hidden', 'hidden');
      openBtn.setAttribute('aria-expanded', 'false');
      openBtn.focus();
    }

    function openModal() {
      modal.removeAttribute('hidden');
      openBtn.setAttribute('aria-expanded', 'true');
      var closeBtn = modal.querySelector('.ctx-help-close');
      if (closeBtn) closeBtn.focus();
    }

    openBtn.addEventListener('click', openModal);
    modal.addEventListener('click', function (evt) {
      if (evt.target === modal) closeModal();
    });
    modal.querySelectorAll('[data-help-close="' + modalId + '"]').forEach(function (btn) {
      btn.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (evt) {
      if (evt.key === 'Escape' && !modal.hasAttribute('hidden')) {
        closeModal();
      }
    });
  }

  bindContextHelp('contig-help-open', 'contig-help-modal');

  var sel = document.getElementById('factor-filter');
  if (!sel) return;

  var rows = Array.prototype.slice.call(document.querySelectorAll('.contig-table tbody tr[data-factors]'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('.contig-start'));
  var info = document.getElementById('contig-filter-info');

  function rowHasFactor(row, factor) {
    if (!factor) return true;
    var raw = row.getAttribute('data-factors') || '';
    if (!raw) return false;
    var parts = raw.split(',');
    return parts.indexOf(factor) !== -1;
  }

  function updateInfo(visible, total, factor) {
    if (!info) return;
    if (!factor) {
      info.textContent = visible + ' of ' + total + ' ranges shown';
      return;
    }
    info.textContent = visible + ' of ' + total + ' ranges include factor ' + factor;
  }

  function applyFilter() {
    var factor = sel.value;
    var visible = 0;
    var total = rows.length;

    rows.forEach(function (row) {
      var show = rowHasFactor(row, factor);
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    sections.forEach(function (sec) {
      var secRows = sec.querySelectorAll('tbody tr[data-factors]');
      var any = false;
      for (var i = 0; i < secRows.length; i++) {
        if (secRows[i].style.display !== 'none') { any = true; break; }
      }
      sec.style.display = any ? '' : 'none';
    });

    updateInfo(visible, total, factor);
  }

  sel.addEventListener('change', applyFilter);
  applyFilter();
}());
</script>
</body>
</html>
