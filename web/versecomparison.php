<?php
// versecomparison.php — side-by-side interlinear comparison of multiple verses.
//
// URL format: ?v[]=Gen.1.1.BHS&v[]=Jhn.3.16.NA28
// Each entry: BOOK.CHAPTER.VERSE.EDITION  (edition optional; defaults by book type)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/book_aliases.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/render_context.php';

$_cmp_cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$_show_variant_indicator = !empty($_cmp_cfg['show_variant_indicator']);
unset($_cmp_cfg);

const OT_BOOK_CODES_CMP = [
    'Gen','Exo','Lev','Num','Deu','Jos','Jdg','Rut',
    '1Sa','2Sa','1Ki','2Ki','1Ch','2Ch','Ezr','Neh','Est',
    'Job','Psa','Pro','Ecc','Sng','Isa','Jer','Lam','Ezk',
    'Dan','Hos','Jol','Amo','Oba','Jon','Mic','Nam','Hab',
    'Zep','Hag','Zec','Mal',
];

function cmp_default_edition(string $book): string {
    if (strpos($book, 'Lxx') === 0) return 'LXX-Rahlfs';
    if (in_array($book, OT_BOOK_CODES_CMP, true)) return 'BHS';
    return 'NA28';
}

function cmp_parse_entry(string $s): ?array {
    $parts = explode('.', $s, 4);
    if (count($parts) < 3) return null;
    $book    = trim($parts[0]);
    $chapter = (int)$parts[1];
    $verse   = (int)$parts[2];
    $edition = isset($parts[3]) ? trim($parts[3]) : '';
    if (!$book || $chapter < 1 || $verse < 1) return null;
    if ($edition === '') $edition = cmp_default_edition($book);
    return compact('book', 'chapter', 'verse', 'edition');
}

function cmp_entry_key(array $e): string {
    return $e['book'] . '.' . $e['chapter'] . '.' . $e['verse'] . '.' . $e['edition'];
}

function cmp_list_url(array $raws): string {
    if (empty($raws)) return 'versecomparison.php';
    return 'versecomparison.php?' . implode('&', array_map(fn($r) => 'v[]=' . urlencode($r), $raws));
}

// --- Handle "Add verse" form submission → redirect to clean URL ---
if (isset($_GET['book'])) {
    $new_book    = trim($_GET['book'] ?? '');
    $new_chapter = (int)($_GET['chapter'] ?? 1);
    $new_verse   = (int)($_GET['verse']   ?? 1);
    $new_edition = trim($_GET['edition']  ?? '');
    if ($new_edition === '') $new_edition = cmp_default_edition($new_book);

    $existing = array_values(array_filter(array_map('trim', (array)($_GET['v'] ?? []))));
    $new_raw  = $new_book . '.' . $new_chapter . '.' . $new_verse . '.' . $new_edition;

    // Avoid exact duplicate
    if (!in_array($new_raw, $existing, true)) {
        $existing[] = $new_raw;
    }
    header('Location: ' . cmp_list_url($existing));
    exit;
}

// --- Parse verse list ---
$raw_entries = array_values(array_filter(array_map('trim', (array)($_GET['v'] ?? []))));
$entries = [];
foreach ($raw_entries as $raw) {
    $e = cmp_parse_entry($raw);
    if ($e) $entries[] = ['raw' => cmp_entry_key($e), 'entry' => $e];
}

// Deduplicate (keep first occurrence)
$seen = [];
$entries = array_filter($entries, function ($ve) use (&$seen) {
    if (isset($seen[$ve['raw']])) return false;
    $seen[$ve['raw']] = true;
    return true;
});
$entries = array_values($entries);

// --- Selector defaults (always start at Gen 1:1 for the add-verse form) ---
$sel_book    = 'Gen';
$sel_chapter = 1;
$sel_verse   = 1;
$sel_edition = cmp_default_edition($sel_book); // 'BHS' for Gen
$books      = bible_books();
$books_lxx  = lxx_books();
$chapters   = bible_chapters($sel_book);
$verses_sel = $chapters ? bible_verses($sel_book, $sel_chapter) : [];

// --- Fetch verse data and build render contexts ---
$cards = [];
$combined_payload = [];

foreach ($entries as $ve) {
    $e    = $ve['entry'];
    $lxx  = ($e['edition'] === 'LXX-Rahlfs');

    $vd = $lxx
        ? lxx_verse_full($e['book'], $e['chapter'], $e['verse'])
        : bible_verse_full($e['book'], $e['chapter'], $e['verse'], $e['edition']);

    if (!$vd) continue;

    $ctx = build_render_context([$vd], $e['chapter'], $e['verse'], 1, $e['edition'], 1, $e['verse']);
    $combined_payload += $ctx['detail_payload'];

    // Build short reference label: "Gen 1:1 · BHS"
    $osis = $vd['verse']['osis_code'] ?? $e['book'];
    $abbr = preg_replace('/^Lxx/', '', $osis);
    $ref_label = $abbr . ' ' . $e['chapter'] . ':' . $e['verse'] . ' · ' . $e['edition'];

    $cards[] = [
        'raw'       => $ve['raw'],
        'entry'     => $e,
        'lxx'       => $lxx,
        'vd'        => $vd,
        'ctx'       => $ctx,
        'ref_label' => $ref_label,
    ];
}

// Remaining raw keys (for removal links)
$all_raws = array_column($entries, 'raw');

?>
<?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verse Comparison — Bible Browser</title>
<?php bible_render_layout_styles(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>
.cmp-add-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 8px 0 10px; border-bottom: 1px solid var(--border, #e5e7eb); margin-bottom: 16px; }
.cmp-add-bar select, .cmp-add-bar button { font-size: 13px; }
.cmp-add-bar .sel-label { font-size: 12px; color: var(--muted, #6b7280); font-weight: 600; }
.cmp-verse-card { margin-bottom: 28px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; padding: 12px 14px; }
.cmp-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.cmp-card-head h2 { margin: 0; font-size: 16px; font-weight: 700; }
.cmp-card-head a.cmp-view-link { font-size: 12px; color: var(--accent, #1d4ed8); margin-left: 10px; font-weight: 400; }
.cmp-remove-btn { font-size: 18px; line-height: 1; color: var(--muted, #9ca3af); text-decoration: none; padding: 2px 6px; border-radius: 4px; }
.cmp-remove-btn:hover { color: #dc2626; background: #fef2f2; }
.cmp-back { font-size: 13px; margin-bottom: 12px; display: inline-block; }
.cmp-empty { padding: 40px 0; text-align: center; color: var(--muted, #9ca3af); font-size: 15px; }
.cmp-empty p { margin: 6px 0; }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">

<a class="cmp-back" href="index.php">← Bible Browser</a>

<!-- Shared options panel (gear button) -->
<div class="selector" style="margin-bottom:8px">
    <button type="button" id="gear-btn" class="gear" aria-expanded="false" aria-controls="options-panel" title="Display options">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
            <path fill="currentColor" d="M19.14 12.94a7.49 7.49 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.61-.22l-2.39.96a7.4 7.4 0 0 0-1.62-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96a.5.5 0 0 0-.61.22L2.71 8.84a.5.5 0 0 0 .12.64L4.86 11.06a7.5 7.5 0 0 0 0 1.88l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.43.34.68.24l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.26.42.5.42h3.84c.24 0 .45-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.25.1.54 0 .68-.24l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7z"/>
        </svg>
    </button>
</div>

<div id="options-panel" class="options-panel" hidden>
    <div class="options-title">Display</div>
    <div class="options-group">
        <span class="options-grouplabel">Gematria</span>
        <label><input type="checkbox" data-opt="gem-std"> Standard</label>
        <label><input type="checkbox" data-opt="gem-ord"> Ordinal</label>
        <label><input type="checkbox" data-opt="gem-red"> Reduced</label>
    </div>
    <div class="options-divider"></div>
    <label><input type="checkbox" data-opt="translit" checked> Transliteration</label>
    <label><input type="checkbox" data-opt="english"  checked> English</label>
    <label><input type="checkbox" data-opt="strongs"> Strong's</label>
    <label><input type="checkbox" data-opt="grammar"> Grammar</label>
    <label><input type="checkbox" data-opt="full-width"> Full width</label>
    <div class="options-divider"></div>
    <div class="options-group">
        <span class="options-grouplabel">Verse text</span>
        <label><input type="checkbox" data-opt="verse-original" checked> Original</label>
        <label><input type="checkbox" data-opt="verse-english"> English</label>
        <label><input type="checkbox" data-opt="verse-newlines"> Newlines</label>
    </div>
    <div class="options-size-section">
        <span class="options-grouplabel">Font sizes</span>
        <label class="size-ctrl">Verse orig (Heb) <input type="number" data-size="verse-orig-heb" value="22" min="8" max="72" step="1"> px</label>
        <label class="size-ctrl">Verse orig (Grk) <input type="number" data-size="verse-orig-grk" value="18" min="8" max="72" step="1"> px</label>
        <label class="size-ctrl">Verse eng        <input type="number" data-size="verse-eng"       value="16" min="8" max="48" step="1"> px</label>
        <div class="options-divider"></div>
        <label class="size-ctrl">Orig (Heb) <input type="number" data-size="word-orig-heb" value="26" min="8" max="72" step="1"> px</label>
        <label class="size-ctrl">Orig (Grk) <input type="number" data-size="word-orig-grk" value="18" min="8" max="72" step="1"> px</label>
        <label class="size-ctrl">Translit  <input type="number" data-size="translit"  value="13" min="8" max="36" step="1"> px</label>
        <label class="size-ctrl">English   <input type="number" data-size="word-eng"   value="13" min="8" max="36" step="1"> px</label>
        <label class="size-ctrl">Strong's  <input type="number" data-size="strongs"    value="12" min="8" max="36" step="1"> px</label>
        <label class="size-ctrl">Grammar   <input type="number" data-size="grammar"    value="11" min="8" max="36" step="1"> px</label>
        <label class="size-ctrl">Gematria  <input type="number" data-size="gematria"   value="12" min="8" max="36" step="1"> px</label>
        <label class="size-ctrl">Gematria color  <input type="color" data-color="gematria" value="#1e40af"></label>
    </div>
    <div class="options-reset-row">
        <button type="button" id="opts-reset-btn" class="opts-reset-btn">Reset to defaults</button>
    </div>
</div>

<!-- Add verse bar -->
<form method="get" action="versecomparison.php" id="dd-form" class="cmp-add-bar">
    <?php foreach ($all_raws as $r): ?>
    <input type="hidden" name="v[]" value="<?= h($r) ?>">
    <?php endforeach; ?>

    <label class="sel-label">Book</label>
    <select name="book" id="sel-book">
    <?php foreach ($books as $b):
        $b_full = preg_replace('/^LXX\s+/i', '', $b['name']);
        $b_abbr = preg_replace('/^Lxx/', '', $b['osis_code']);
    ?>
        <option value="<?= h($b['osis_code']) ?>"
                data-lang="<?= h($b['language']) ?>"
                data-full="<?= h($b_full) ?>"
                data-abbr="<?= h($b_abbr) ?>"
                <?= $b['osis_code'] === $sel_book ? 'selected' : '' ?>>
            <?= h($b_full) ?>
        </option>
    <?php endforeach; ?>
    </select>

    <label class="sel-label">Ch</label>
    <select name="chapter" id="sel-chapter">
    <?php foreach ($chapters as $c): ?>
        <option value="<?= (int)$c ?>" <?= (int)$c === $sel_chapter ? 'selected' : '' ?>><?= (int)$c ?></option>
    <?php endforeach; ?>
    </select>

    <label class="sel-label">Vs</label>
    <select name="verse" id="sel-verse">
    <?php foreach ($verses_sel as $vn): ?>
        <option value="<?= (int)$vn ?>" <?= (int)$vn === $sel_verse ? 'selected' : '' ?>><?= (int)$vn ?></option>
    <?php endforeach; ?>
    </select>

    <label class="sel-label">Ed</label>
    <select name="edition" id="sel-edition">
        <option value="BHS"        <?= $sel_edition === 'BHS'        ? 'selected' : '' ?>>BHS</option>
        <option value="LXX-Rahlfs" <?= $sel_edition === 'LXX-Rahlfs' ? 'selected' : '' ?>>LXX</option>
        <option value="NA28"       <?= $sel_edition === 'NA28'       ? 'selected' : '' ?>>NA28</option>
        <option value="TR"         <?= $sel_edition === 'TR'         ? 'selected' : '' ?>>TR</option>
    </select>

    <button type="submit">+ Add Verse</button>
</form>

<?php if (empty($cards)): ?>
<div class="cmp-empty">
    <p>No verses added yet.</p>
    <p>Use the selector above to add your first verse.</p>
</div>
<?php else: ?>

<div id="interlinear" class="interlinear" data-letter-mode="compare">
<?php foreach ($cards as $card):
    extract($card['ctx']);  // detail_payload, first_verse_row, primary_lang, primary_lcls, etc.
    $e    = $card['entry'];
    $vd   = $card['vd'];
    $v    = $vd['verse'];
    $lxx  = $card['lxx'];
    $words = $vd['words'];
    $lang  = $v['language'];
    $lcls  = lang_class($lang);
    $remove_url = cmp_list_url(array_values(array_filter($all_raws, fn($r) => $r !== $card['raw'])));
    $view_url   = 'index.php?book=' . urlencode($e['book']) . '&chapter=' . $e['chapter'] . '&verse=' . $e['verse'] . '&edition=' . urlencode($e['edition']);
?>
<div class="verse-card cmp-verse-card">
    <div class="cmp-card-head">
        <h2>
            <?= h($card['ref_label']) ?>
            <a class="cmp-view-link" href="<?= h($view_url) ?>" title="Open in Bible Browser">↗ view</a>
        </h2>
        <a class="cmp-remove-btn" href="<?= h($remove_url) ?>" title="Remove this verse" aria-label="Remove <?= h($card['ref_label']) ?>">×</a>
    </div>

    <!-- Assembled prose lines -->
    <div class="assembled">
        <div class="label verse-orig-label">Original</div>
        <div class="original <?= $lcls ?>">
            <?php
            $text_a = $lang === 'Hebrew' ? clean_inline($v['text_original']) : strip_greek_parens($v['text_original']);
            ?>
            <span class="assembled-verse"><?= h(trim($text_a)) ?></span>
        </div>
        <div class="label verse-eng-label">English<?php if (!$lxx): ?> <span class="english-source">(KJV)</span><?php endif; ?></div>
        <div class="english">
            <?php
            $kjv_html = null;
            if (!$lxx) {
                $kjv_raw = kjv_verse_text((int)$v['book_id'], (int)$v['chapter'], (int)$v['verse']);
                if ($kjv_raw !== null && $kjv_raw !== '') {
                    $kjv_html = render_kjv_tagged($kjv_raw, (string)$v['testament']);
                }
            }
            if ($kjv_html === null) {
                $text_e = $lang === 'Hebrew' ? clean_inline($v['text_english']) : $v['text_english'];
            }
            ?>
            <span class="assembled-verse"><?php if ($kjv_html !== null): ?><?= $kjv_html ?><?php else: ?><?= h(trim($text_e)) ?><?php endif; ?></span>
        </div>
    </div>

    <!-- Interlinear word cells -->
    <div class="interlinear <?= $lcls ?>" data-edition="<?= h($e['edition']) ?>">
    <div class="verse-block" data-book="<?= h($e['book']) ?>" data-chapter="<?= $e['chapter'] ?>" data-verse="<?= $e['verse'] ?>">
    <div class="verse-num"><?= $e['verse'] ?></div>
    <?php $word_pos = 0; foreach ($words as $w): $word_pos++;
        if ($lang === 'Greek') {
            [$orig_display, $translit] = split_greek_word($w['text_original']);
            $english_display = $w['translation'] ?? '';
            $grammar_display = $w['grammar'] ?? '';
        } else {
            $orig_display    = clean_inline($w['text_original']);
            $translit        = clean_inline($w['transliteration']);
            $english_display = clean_inline($w['translation']);
            $grammar_display = format_hebrew_grammar($w['grammar'], $w['morphemes']);
        }
        $sd_num            = strongs_display($w['strongs']);
        $sd_full           = strongs_full_code($w['strongs'], $lang);
        $has_variant_class = (!empty($w['variants']) && $_show_variant_indicator) ? ' has-variant' : '';
        $active_variant    = 'base';
        if (!empty($w['source_variant_id']) && !empty($w['variants'])) {
            foreach ($w['variants'] as $vi => $vt_chk) {
                if ((int)($vt_chk['id'] ?? 0) === (int)$w['source_variant_id']) {
                    $active_variant = (string)$vi; break;
                }
            }
        }
    ?>
        <div class="word-cell<?= $has_variant_class ?>"
             data-pos="<?= $word_pos ?>"
             data-word-id="<?= (int)$w['id'] ?>"
             data-verse-num="<?= (int)$v['verse'] ?>"
             data-active-variant="<?= h($active_variant) ?>"
             data-gem-std="<?= (int)$w['gem_std'] ?>"
             data-gem-ord="<?= (int)$w['gem_ord'] ?>"
             data-gem-red="<?= (int)$w['gem_red'] ?>"
             data-letter-count="<?= (int)letter_count($w['text_original'] ?? '', $lang) ?>">
            <div class="gematria"></div>
            <div class="original <?= $lcls ?>"><?= h($orig_display ?? '') ?></div>
            <div class="translit"><?= h($translit ?? '') ?></div>
            <div class="english"><?= h($english_display) ?></div>
            <div class="strongs<?= $sd_full ? ' strongs-link' : '' ?>" data-strongs="<?= h($sd_full) ?>"><?= h($sd_num) ?></div>
            <div class="grammar" data-lang="<?= $lcls ?>"><?= h($grammar_display) ?></div>
            <?php if ((int)$w['chunk_num'] > 1): ?>
                <div class="chunk-badge">chunk <?= (int)$w['chunk_num'] ?></div>
            <?php endif; ?>
            <?php if ($_show_variant_indicator && !empty($w['variants'])): ?>
                <button class="variant-btn" title="<?= count($w['variants']) ?> variant<?= count($w['variants']) > 1 ? 's' : '' ?> — click to switch" tabindex="-1"></button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    </div><!-- /interlinear -->

</div><!-- /verse-card -->
<?php endforeach; ?>
</div><!-- /#interlinear wrapper -->

<?php endif; ?>

<!-- Book list data for JS-driven switcher -->
<script>
const BIBLE_BOOKS_DATA = <?= json_encode(array_map(fn($b) => [
    'osis' => $b['osis_code'],
    'name' => preg_replace('/^LXX\s+/i', '', $b['name']),
    'lang' => $b['language'],
], $books), JSON_UNESCAPED_UNICODE) ?>;
const LXX_BOOKS_DATA = <?= json_encode(array_map(fn($b) => [
    'osis' => $b['osis_code'],
    'name' => preg_replace('/^LXX\s+/i', '', $b['name']),
    'lang' => 'Greek',
], $books_lxx), JSON_UNESCAPED_UNICODE) ?>;
</script>
<!-- Combined word-data payload for all verses -->
<script id="word-data" type="application/json"><?= json_encode($combined_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
const VERSE_LANG = 'Greek'; // fallback for variant-switcher; per-word lang comes from word-data
const VERSE_REF  = 'Verse Comparison';
</script>

<script src="js/options.js"></script>
<script>
// options.js targets only the first .assembled for verse-text toggles.
// Mirror those class changes to every .assembled on this page.
(function () {
    const assembleds = document.querySelectorAll('.assembled');
    if (assembleds.length <= 1) return;
    const first   = assembleds[0];
    const watched = ['hide-verse-original', 'hide-verse-english', 'verse-newlines'];
    function sync() {
        for (let i = 1; i < assembleds.length; i++) {
            for (const cls of watched) {
                assembleds[i].classList.toggle(cls, first.classList.contains(cls));
            }
        }
    }
    new MutationObserver(sync).observe(first, { attributes: true, attributeFilter: ['class'] });
    // Catch reset-button clicks which call applyAll() synchronously
    const resetBtn = document.getElementById('opts-reset-btn');
    if (resetBtn) resetBtn.addEventListener('click', () => setTimeout(sync, 0));
    sync();
})();
</script>
<script src="js/word-selection.js"></script>
<script src="js/variant-switcher.js"></script>
<script src="js/letter-select.js"></script>
<script src="js/strongs-tooltip.js"></script>
<script src="js/grammar-tooltip.js"></script>
<script src="js/verse-tooltip.js"></script>
<script>
// Chained dropdowns for the Add Verse form — no auto-submit on change.
(function () {
    const selBook    = document.getElementById('sel-book');
    const selChapter = document.getElementById('sel-chapter');
    const selVerse   = document.getElementById('sel-verse');
    const selEdition = document.getElementById('sel-edition');
    if (!selBook || !selChapter || !selVerse) return;

    const OT_EDITIONS = [
        { code: 'BHS',        label: 'BHS', name: 'Biblia Hebraica Stuttgartensia' },
        { code: 'LXX-Rahlfs', label: 'LXX', name: 'Rahlfs LXX 1935' }
    ];
    const NT_EDITIONS = [
        { code: 'NA28',       label: 'NA28', name: 'Nestle-Aland 28th edition' },
        { code: 'TR',         label: 'TR',   name: 'Scrivener Textus Receptus 1894' },
        { code: 'LXX-Rahlfs', label: 'LXX',  name: 'Rahlfs LXX 1935' }
    ];

    function syncEditionOptions() {
        if (!selEdition) return;
        const opt      = selBook.selectedOptions[0];
        const lang     = opt ? opt.dataset.lang : '';
        const isLxx    = opt ? opt.value.startsWith('Lxx') : false;
        const editions = (lang === 'Hebrew' || isLxx) ? OT_EDITIONS : NT_EDITIONS;
        const current  = selEdition.value;
        selEdition.innerHTML = '';
        for (const ed of editions) {
            const o = document.createElement('option');
            o.value = ed.code;
            o.textContent = ed.label;
            o.title = ed.name;
            selEdition.appendChild(o);
        }
        const codes = editions.map(e => e.code);
        selEdition.value = codes.includes(current) ? current : editions[0].code;
    }

    // Repopulate the book list based on edition: LXX-Rahlfs → LXX books, else canonical.
    async function syncBookOptions(keepBook) {
        const isLxxEd = selEdition.value === 'LXX-Rahlfs';
        const list    = isLxxEd
            ? (typeof LXX_BOOKS_DATA !== 'undefined' ? LXX_BOOKS_DATA : [])
            : (typeof BIBLE_BOOKS_DATA !== 'undefined' ? BIBLE_BOOKS_DATA : []);
        selBook.innerHTML = '';
        list.forEach(b => {
            const o = document.createElement('option');
            o.value = b.osis;
            o.textContent = b.name;
            o.dataset.lang = b.lang;
            o.dataset.full = b.name;
            o.dataset.abbr = b.osis.replace(/^Lxx/, '');
            selBook.appendChild(o);
        });
        // Restore previously selected book if still in list, else default to first.
        if (keepBook && list.some(b => b.osis === keepBook)) {
            selBook.value = keepBook;
        }
        // Refresh chapters/verses for the now-selected book.
        await refreshChaptersVerses();
    }

    async function fetchList(url) {
        return fetch(url).then(r => r.json()).catch(() => []);
    }

    async function repopulate(sel, items) {
        sel.innerHTML = '';
        for (const v of items) {
            const o = document.createElement('option');
            o.value = v; o.textContent = v;
            sel.appendChild(o);
        }
    }

    async function refreshChaptersVerses() {
        const book     = selBook.value;
        const chapters = await fetchList(`api.php?api=chapters&book=${encodeURIComponent(book)}`);
        await repopulate(selChapter, chapters);
        if (chapters.length) {
            const verses = await fetchList(`api.php?api=verses&book=${encodeURIComponent(book)}&chapter=${encodeURIComponent(chapters[0])}`);
            await repopulate(selVerse, verses);
        }
    }

    selBook.addEventListener('change', async function () {
        syncEditionOptions();
        await refreshChaptersVerses();
        // No form submit — user clicks "+ Add Verse" when ready.
    });

    selChapter.addEventListener('change', async function () {
        const book   = selBook.value;
        const verses = await fetchList(`api.php?api=verses&book=${encodeURIComponent(book)}&chapter=${encodeURIComponent(this.value)}`);
        await repopulate(selVerse, verses);
        // No form submit.
    });

    selEdition.addEventListener('change', async function () {
        const prevBook = selBook.value;
        await syncBookOptions(prevBook);
        syncEditionOptions();
        // No form submit — user clicks "+ Add Verse" when ready.
    });

    // Initial state: sync edition options and book list for the default book.
    syncEditionOptions();
})();
</script>
</main>
</div>
</body>
</html>
