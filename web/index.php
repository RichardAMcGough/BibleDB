<?php
// Bible Browser — main page (biblehub-style interlinear).

require __DIR__ . '/db.php';
require __DIR__ . '/book_aliases.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/render_context.php';

// ---------- Visitor counter (verse_views table) ----------
function is_bot(): bool {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return true;
    $patterns = ['bot','crawler','spider','slurp','mediapartners','python','curl','wget',
                 'libwww','scrapy','httpclient','go-http','java/','ruby','perl','php/',
                 'scan','zgrab','semrush','ahrefsbot','dotbot','mj12bot','petalbot',
                 'yandex','baiduspider','duckduck','facebookexternalhit','twitterbot',
                 'linkedinbot','whatsapp','applebot','ia_archiver'];
    foreach ($patterns as $p) {
        if (str_contains($ua, $p)) return true;
    }
    return false;
}

function record_verse_view(string $book, int $chapter, int $verse): array {
    if (is_bot()) return ['verse' => 0, 'total' => 0];

    // In remote API mode we don't track visits against the remote server
    if (should_use_remote_api()) {
        return ['verse' => 0, 'total' => 0];
    }

    try {
        $pdo = bible_pdo();
        $pdo->prepare("CALL record_verse_view(?, ?, ?, @verse_count, @total)")
            ->execute([$book, $chapter, $verse]);
        $row = $pdo->query("SELECT @verse_count AS verse_count, @total AS total")->fetch();
        return [
            'verse' => (int)($row['verse_count'] ?? 0),
            'total' => (int)($row['total']        ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('verse_views error: ' . $e->getMessage());
        if (!empty($_GET['debug'])) echo '<pre style="color:red">verse_views: ' . htmlspecialchars($e->getMessage()) . '</pre>';
        return ['verse' => 0, 'total' => 0];
    }
}

// ---------- AJAX endpoints for dropdown chaining ----------
if (isset($_GET['api'])) { require __DIR__ . '/api.php'; }

// ---------- normal page render ----------
// Resolve book / chapter / verse from URL (reference text overrides).
$book_code = null; $chapter = null; $verse = null;
if (!empty($_GET['ref'])) {
    $r = parse_reference($_GET['ref']);
    if ($r) { $book_code = $r['osis_code']; $chapter = $r['chapter']; $verse = $r['verse']; }
}
if (!$book_code) {
    $book_code = $_GET['book']        ?? 'Gen';
    $chapter   = (int)($_GET['chapter'] ?? 1);
    $verse     = (int)($_GET['verse']   ?? 1);
}
$subverse = (string)($_GET['subverse'] ?? '');

// Record visit and fetch counts (silently fails if DB unavailable)
$view_counts = record_verse_view($book_code, (int)$chapter, (int)$verse);

// Current user for notes (phpBB or dev fallback). Needed for edit ownership.
$user = get_bible_user();
// Build a login URL for the guest prompt (falls back gracefully when phpbb_url is not configured).
$_idx_cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$_notes_login_url = bible_phpbb_login_url($_idx_cfg['phpbb_url'] ?? '');
$_notes_enabled = bible_notes_enabled();
$_show_variant_indicator = !empty($_idx_cfg['show_variant_indicator']);
unset($_idx_cfg);

// Edition dropdown. OT Hebrew books get BHS + LXX-Rahlfs; NT + LXX books
// get NA28 + TR + LXX-Rahlfs. LXX-Rahlfs is a mode switch that routes
// lookups to the book_lxx / verse_lxx / word_lxx tables.
const OT_BOOK_CODES = [
    'Gen','Exo','Lev','Num','Deu','Jos','Jdg','Rut',
    '1Sa','2Sa','1Ki','2Ki','1Ch','2Ch','Ezr','Neh','Est',
    'Job','Psa','Pro','Ecc','Sng','Isa','Jer','Lam','Ezk',
    'Dan','Hos','Jol','Amo','Oba','Jon','Mic','Nam','Hab',
    'Zep','Hag','Zec','Mal',
];

$current_is_lxx_book = (strpos($book_code, 'Lxx') === 0);
$is_ot_book  = !$current_is_lxx_book && in_array($book_code, OT_BOOK_CODES, true);
$is_lxx_ot   = $current_is_lxx_book && in_array(substr($book_code, 3), OT_BOOK_CODES, true);

if ($is_ot_book || $is_lxx_ot) {
    $editions        = [
        ['code' => 'BHS',        'name' => 'Biblia Hebraica Stuttgartensia'],
        ['code' => 'LXX-Rahlfs', 'name' => 'Rahlfs LXX 1935'],
    ];
    $default_edition = $is_lxx_ot ? 'LXX-Rahlfs' : 'BHS';
} else {
    $editions        = bible_greek_editions(); // NA28, TR, LXX-Rahlfs
    $default_edition = $current_is_lxx_book ? 'LXX-Rahlfs' : 'NA28';
}
$valid_codes  = array_column($editions, 'code');
$edition_code = $_GET['edition'] ?? $default_edition;
if (!in_array($edition_code, $valid_codes, true)) $edition_code = $default_edition;

$lxx_mode = ($edition_code === 'LXX-Rahlfs');

// Auto-jump when book + edition disagree. The user flipped the Edition
// dropdown but the book is from the other tradition — keep the chapter
// and verse, swap the book to the parallel.
if ($lxx_mode && !$current_is_lxx_book) {
    $par = lxx_book_by_mt_osis($book_code);
    if ($par) {
        $book_code = $par['osis_code'];
    } else {
        // No LXX parallel (NT book or unknown) — land on LXX Genesis.
        $book_code = 'LxxGen';
        $chapter = 1; $verse = 1;
    }
    $current_is_lxx_book = true;
} elseif (!$lxx_mode && $current_is_lxx_book) {
    // Switching from LXX to BHS → land on the OT MT parallel (or Gen 1:1).
    // Switching from LXX to NA28/TR → land on Matthew 1:1 (NT edition, OT not available).
    if ($edition_code === 'BHS') {
        $lxx_row = lxx_book_by_osis($book_code);
        if ($lxx_row && !empty($lxx_row['mt_parallel_osis'])) {
            $book_code = $lxx_row['mt_parallel_osis'];
        } else {
            $book_code = 'Gen';
            $chapter = 1; $verse = 1;
        }
    } else {
        $book_code = 'Mat';
        $chapter = 1; $verse = 1;
    }
    $current_is_lxx_book = false;
}

// After any auto-jump the $book_code may have changed (e.g. LxxGen → Gen or
// Gen → LxxGen). Recompute the edition list so the dropdown reflects the
// ACTUAL book being displayed, not the one that was in the URL.
$current_is_lxx_book = (strpos($book_code, 'Lxx') === 0);
$is_ot_book          = !$current_is_lxx_book && in_array($book_code, OT_BOOK_CODES, true);
$is_lxx_ot           = $current_is_lxx_book && in_array(substr($book_code, 3), OT_BOOK_CODES, true);
if ($is_ot_book || $is_lxx_ot) {
    $editions = [
        ['code' => 'BHS',        'name' => 'Biblia Hebraica Stuttgartensia'],
        ['code' => 'LXX-Rahlfs', 'name' => 'Rahlfs LXX 1935'],
    ];
} elseif ($current_is_lxx_book) {
    $editions = bible_greek_editions(); // NT LXX books: NA28, TR, LXX-Rahlfs
}
// (else: NT book — $editions is already correct from the block above)
$valid_codes  = array_column($editions, 'code');
if (!in_array($edition_code, $valid_codes, true)) {
    $edition_code = $is_ot_book ? 'BHS' : ($current_is_lxx_book ? 'LXX-Rahlfs' : 'NA28');
}
$lxx_mode = ($edition_code === 'LXX-Rahlfs');

// Books list, chapters, verses come from the right tables for this mode.
$books    = $lxx_mode ? lxx_books() : bible_books();
$chapters = $lxx_mode ? lxx_chapters($book_code) : bible_chapters($book_code);

if ($lxx_mode) {
    // lxx_verses returns rows with subverse — collapse to unique verse
    // numbers for the dropdown. Subverse stepping is reachable via
    // prev/next once you're on a subverse-bearing verse.
    $verse_rows = $chapters ? lxx_verses($book_code, $chapter ?: $chapters[0]) : [];
    $verses = [];
    foreach ($verse_rows as $vr) {
        $vn = (int)$vr['verse'];
        if (!in_array($vn, $verses, true)) $verses[] = $vn;
    }
} else {
    $verses = $chapters ? bible_verses($book_code, $chapter ?: $chapters[0]) : [];
}

// How many consecutive verses to display, starting at $verse.
$max_count = max(1, count($verses));
$count     = max(1, min($max_count, (int)($_GET['count'] ?? 1)));

// Track the current book's MT language for the (now-edge-case) Hebrew
// disable rule. With LXX in the dropdown we generally leave Edition
// enabled, so the Hebrew user can flip to LXX. NT books still see only
// NA28/TR meaningfully — but the dropdown stays clickable.
$current_book_lang = 'Greek';
if (!$lxx_mode) {
    foreach ($books as $b_chk) {
        if ($b_chk['osis_code'] === $book_code) {
            $current_book_lang = $b_chk['language'];
            break;
        }
    }
}
// Fetch each verse in the range. Stop at chapter end.
$verses_data = [];
for ($i = 0; $i < $count; $i++) {
    $vd = $lxx_mode
        ? lxx_verse_full($book_code, $chapter, $verse + $i, $i === 0 ? $subverse : '')
        : bible_verse_full($book_code, $chapter, $verse + $i, $edition_code);
    if (!$vd) break;
    $verses_data[] = $vd;
}
$actual_count   = count($verses_data);
$last_verse_num = $actual_count > 0 ? ($verse + $actual_count - 1) : $verse;

$prev = $lxx_mode
    ? lxx_neighbor($book_code, $chapter, $verse,          $subverse, 'prev')
    : bible_neighbor($book_code, $chapter, $verse,        'prev');
$next = $lxx_mode
    ? lxx_neighbor($book_code, $chapter, $last_verse_num, $subverse, 'next')
    : bible_neighbor($book_code, $chapter, $last_verse_num, 'next');

?>
<?php bible_render_layout_header(); ?>

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<?php emit_csrf_meta(); ?>
<title>Bible Browser — <?= h($book_code) ?> <?= (int)$chapter ?>:<?= (int)$verse ?><?= $actual_count > 1 ? '-' . (int)$last_verse_num : '' ?></title>
<?php bible_render_layout_styles(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>
<body>

<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
<div class="selector">
<?php
ob_start(); ?>
    <label>+</label>
    <select name="count" id="sel-count" data-max="<?= (int)$max_count ?>" title="Additional verses to display after the selected verse">
    <?php for ($cn = 1; $cn <= $max_count; $cn++): ?>
        <option value="<?= $cn ?>" <?= $cn === (int)$count ? 'selected' : '' ?>><?= $cn === 1 ? 'None' : (($cn - 1) === 1 ? '1 vs' : ($cn - 1) . ' vss') ?></option>
    <?php endfor; ?>
    </select>
    <label class="sel-label">Source</label>
    <select name="edition" id="sel-edition" title="Edition">
    <?php foreach ($editions as $ed): ?>
        <option value="<?= h($ed['code']) ?>" <?= $ed['code'] === $edition_code ? 'selected' : '' ?> title="<?= h($ed['name']) ?>"><?= h($ed['code'] === 'LXX-Rahlfs' ? 'LXX' : $ed['code']) ?></option>
    <?php endforeach; ?>
    </select>
<?php $selector_extra_fields = ob_get_clean();
require __DIR__ . '/verse_selector.inc.php'; ?>
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
        <label><input type="checkbox" data-opt="gem-std" checked> Standard</label>
        <label><input type="checkbox" data-opt="gem-ord"> Ordinal</label>
        <label><input type="checkbox" data-opt="gem-red"> Reduced</label>
    </div>
    <div class="options-divider"></div>
    <label><input type="checkbox" data-opt="translit" checked> Transliteration</label>
    <label><input type="checkbox" data-opt="english"  checked> English</label>
    <label><input type="checkbox" data-opt="strongs"  checked> Strong's</label>
    <label><input type="checkbox" data-opt="grammar"  checked> Grammar</label>
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

<?php
// Detect "verse exists but no words in this edition" -- the verse row is
// fetched OK but the filter empties the words array.
$all_empty_in_edition = false;
if ($actual_count > 0) {
    $all_empty_in_edition = true;
    foreach ($verses_data as $vd_chk) {
        if (!empty($vd_chk['words'])) { $all_empty_in_edition = false; break; }
    }
}
?>
<?php if ($actual_count === 0): ?>
    <div class="verse-card empty">
        Verse <?= h($book_code) ?> <?= (int)$chapter ?>:<?= (int)$verse ?> not found.
    </div>
<?php elseif ($all_empty_in_edition): ?>
    <div class="verse-card empty">
        <?= h($book_code) ?> <?= (int)$chapter ?>:<?= (int)$verse ?><?= $count > 1 ? '-' . ($verse + $count - 1) : '' ?>
        is not present in edition <strong><?= h($edition_code) ?></strong>.
        <div style="margin-top:8px; font-size:0.9em">
            <?php
            $alt_qs = '?book=' . h($book_code) . '&amp;chapter=' . (int)$chapter . '&amp;verse=' . (int)$verse;
            if ($count > 1) $alt_qs .= '&amp;count=' . (int)$count;
            ?>
            Try <a href="<?= $alt_qs ?>&amp;edition=NA28">NA28</a>
            &nbsp;or&nbsp; <a href="<?= $alt_qs ?>&amp;edition=TR">TR</a>.
        </div>
    </div>
<?php else:
    // Build the render-context (per-word JS payload + range-level scalars).
    // See web/render_context.php for the full contract.
    extract(build_render_context(
        $verses_data, $chapter, $verse, $count,
        $edition_code, $actual_count, $last_verse_num
    ));
?>
<div class="verse-card">
    <div class="ref-line">
        <h2>
            <span class="ref-full"><?= $range_title ?></span>
            <span class="ref-abbr"><?= h(preg_replace('/^Lxx/', '', $book_code)) ?>&nbsp;<?= (int)$chapter ?>:<?= (int)$verse ?><?= $actual_count > 1 ? '-'.(int)$last_verse_num : '' ?></span>
        </h2>
        <div class="meta" hidden>
            <?= $actual_count ?> verse<?= $actual_count > 1 ? 's' : '' ?> &nbsp;·&nbsp;
            <?= $total_words ?> words
            <?php if ($any_sig_variant): ?>&nbsp;·&nbsp;<span style="color:var(--variant)">significant variant</span><?php endif; ?>
        </div>
        <div class="nav">
            <div id="word-search-bar" class="word-search-bar">
                <input type="text" id="search-input" placeholder="Jhn 3:16, word, phrase, or H0430" size="28">
                <label id="search-phrase-label" class="search-phrase-label" hidden>
                    <input type="checkbox" id="search-phrase" checked> Phrase
                </label>
                <button type="button" id="search-clear" class="search-clear" title="Clear" aria-label="Clear search" hidden>&#10005;</button>
                <button type="button" id="search-btn" aria-label="Search"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M21 19.59l-5.4-5.4a7 7 0 1 0-1.41 1.41L19.59 21 21 19.59zM11 16a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg></button>
            </div>
            <?php if ($prev): ?>
                <a href="?book=<?= h($prev['osis_code']) ?>&amp;chapter=<?= (int)$prev['chapter'] ?>&amp;verse=<?= (int)$prev['verse'] ?><?= $count_qs ?>">&#8249;<span class="nav-label"> prev</span></a>
            <?php endif; ?>
            <?php if ($next): ?>
                <a href="?book=<?= h($next['osis_code']) ?>&amp;chapter=<?= (int)$next['chapter'] ?>&amp;verse=<?= (int)$next['verse'] ?><?= $count_qs ?>"><span class="nav-label">next </span>&#8250;</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Combined Original / English text for the whole range -->
    <div class="assembled">
        <div class="label verse-orig-label">Original</div>
        <div class="original <?= $primary_lcls ?>">
            <?php foreach ($verses_data as $vd):
                $v_a    = $vd['verse'];
                $lang_a = $v_a['language'];
                $text_a = $lang_a === 'Hebrew' ? clean_inline($v_a['text_original']) : strip_greek_parens($v_a['text_original']);
                $text_a = trim($text_a);
            ?><span class="assembled-verse"><?php if ($actual_count > 1): ?><sup class="vno"><?= (int)$v_a['verse'] === 0 ? 'title' : (int)$v_a['verse'] ?></sup> <?php endif; ?><?= h($text_a) ?></span> <?php endforeach; ?>
        </div>
        <div class="label verse-eng-label">English<?php if (!$lxx_mode): ?> <span class="english-source">(KJV)</span><?php endif; ?></div>
        <div class="english">
            <?php foreach ($verses_data as $vd):
                $v_e    = $vd['verse'];
                $lang_e = $v_e['language'];
                // Prefer the tagged KJV text for Hebrew OT / Greek NT verses
                // (mt_pa MT/NT books). LXX mode falls through to the
                // STEPBible-supplied text_english (KJV doesn't cover the
                // deuterocanonical / LXX-only material).
                $kjv_html = null;
                if (!$lxx_mode) {
                    $kjv_raw = kjv_verse_text((int)$v_e['book_id'], (int)$v_e['chapter'], (int)$v_e['verse']);
                    if ($kjv_raw !== null && $kjv_raw !== '') {
                        $kjv_html = render_kjv_tagged($kjv_raw, (string)$v_e['testament']);
                    }
                }
                if ($kjv_html === null) {
                    $text_e = $lang_e === 'Hebrew' ? clean_inline($v_e['text_english']) : $v_e['text_english'];
                    $text_e = trim($text_e);
                }
            ?><span class="assembled-verse"><?php if ($actual_count > 1): ?><sup class="vno"><?= (int)$v_e['verse'] === 0 ? 'title' : (int)$v_e['verse'] ?></sup> <?php endif; ?><?php if ($kjv_html !== null): ?><?= $kjv_html ?><?php else: ?><?= h($text_e) ?><?php endif; ?></span> <?php endforeach; ?>
        </div>
    </div>

    <div id="gematria-panel" class="gematria-panel" hidden>
        <div id="gem-rows" class="gem-rows"></div>
        <button id="gem-clear" class="gem-clear" title="Clear selection" style="display:none">× clear</button>
        <button id="gem-link-btn" class="gem-link-btn" title="Copy deep link to clipboard" style="display:none">&#128279; copy link</button>
    </div>

    <!-- Single continuous interlinear across the whole range -->
    <div class="interlinear <?= $primary_lcls ?>" id="interlinear" data-edition="<?= h($edition_code) ?>">
    <?php $word_pos = 0; foreach ($verses_data as $vd):
        $v      = $vd['verse'];
        $words  = $vd['words'];
        $lang   = $v['language'];
        $lcls   = lang_class($lang);
        $is_title = ((int)$v['verse'] === 0);
        $vorder = (!$lxx_mode && !$is_title)
                    ? kjv_verse_order((int)$v['book_id'], (int)$v['chapter'], (int)$v['verse'])
                    : null;
    ?>
        <div class="verse-block" data-book="<?= h($v['osis_code'] ?? $book_code) ?>" data-chapter="<?= (int)$v['chapter'] ?>" data-verse="<?= (int)$v['verse'] ?>">
        <div class="verse-num">
            <?= $is_title ? '<span class="verse-num-title">title</span>' : (int)$v['verse'] ?><?php if ($vorder !== null): ?><span class="verse-order">#<?= $vorder ?></span><?php endif; ?>
            <?php if ($_notes_enabled): ?>
            <button type="button" class="add-note-btn" title="Add a note / commentary for this verse (gematria notes can autofill from selection or verse)">+ note</button>
            <span class="verse-notes-count" data-count="0"></span>
            <?php endif; ?>
        </div>
        <?php foreach ($words as $w): $word_pos++;
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
            // If db.php substituted a variant onto this canonical word, find
            // its index in $w['variants'] so variant-switcher can start
            // cycling from the correct state. 'base' = canonical text shown.
            $active_variant = 'base';
            if (!empty($w['source_variant_id']) && !empty($w['variants'])) {
                foreach ($w['variants'] as $vi => $vt_chk) {
                    if ((int)($vt_chk['id'] ?? 0) === (int)$w['source_variant_id']) {
                        $active_variant = (string)$vi;
                        break;
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
    <?php endforeach; ?>
    </div>

    <?php if ($_notes_enabled): ?>
    <!-- Notes modal / form (injected for verse commentary) -->
    <div id="notes-modal" class="notes-modal" hidden>
        <div class="notes-modal-inner">
            <div class="notes-modal-head">
                <h3 id="notes-modal-title">Notes for <span id="notes-verse-ref"></span></h3>
                <div class="notes-head-actions">
                    <?php if (!$user['is_guest']): ?>
                    <button type="button" id="notes-add-btn" class="notes-add-btn">+ Add Note</button>
                    <?php endif; ?>
                    <button type="button" id="notes-modal-close" class="notes-close">×</button>
                </div>
            </div>
            <?php if ($user['is_guest']): ?>
            <p class="notes-guest-msg"><?php
                if ($_notes_login_url) {
                    echo '<a href="' . htmlspecialchars($_notes_login_url, ENT_QUOTES, 'UTF-8') . '">Log in</a> to add or edit notes.';
                } else {
                    echo 'Log in to add or edit notes.';
                }
            ?></p>
            <?php else: ?>
            <div id="notes-form-wrap">
            <form id="notes-form" name="notesform">
                <input type="hidden" id="notes-book">
                <input type="hidden" id="notes-chapter">
                <input type="hidden" id="notes-verse">
                <label>Type (select all that apply):</label>
                <div class="notes-type-checks">
                    <label><input type="checkbox" class="notes-type-cb" value="1" checked> General (commentary)</label>
                    <label><input type="checkbox" class="notes-type-cb" value="2"> Bible Wheel</label>
                    <label><input type="checkbox" class="notes-type-cb" value="3"> Isaiah-Bible Correlation</label>
                    <label><input type="checkbox" class="notes-type-cb" value="4"> Gematria</label>
                </div>
                <?php if (!empty($user['is_admin'])): ?>
                <div id="notes-public-wrap" style="margin-top:4px">
                    <label><input type="checkbox" id="notes-is-public" checked> Make public (visible to all visitors)</label>
                </div>
                <?php endif; ?>
                <label>Title <span style="color:red" aria-hidden="true">*</span> <input type="text" id="notes-title" placeholder="e.g. First word 913"></label>
                <label>Note text (toolbar uses the same editor as phpBB forum posts):</label>
                <?= render_bbcode_toolbar('notesform', 'message') ?>
                <textarea id="notes-text" name="message" rows="8" placeholder="Your commentary or gematria observation..." onfocus="if(typeof initInsertions==='function')try{initInsertions();}catch(e){}" onclick="if(typeof storeCaret==='function')storeCaret(this);" onkeyup="if(typeof storeCaret==='function')storeCaret(this);"></textarea>
                <div id="notes-gem-section" hidden>
                    <div>Gematria values (autofilled):</div>
                    <label>Std: <input type="number" id="notes-gem-std" size="6"></label>
                    <label>Ord: <input type="number" id="notes-gem-ord" size="6"></label>
                    <label>Red: <input type="number" id="notes-gem-red" size="6"></label>
                    <button type="button" id="notes-fill-verse">Fill from verse</button>
                    <button type="button" id="notes-fill-sel">Fill from selection</button>
                </div>
                <div class="notes-actions">
                    <button type="submit" id="notes-submit">Save Note</button>
                    <button type="button" id="notes-cancel">Cancel</button>
                    <button type="button" id="notes-create-new" style="display:none; margin-left:12px;">Create new note</button>
                </div>
            </form>
            </div><!-- /notes-form-wrap -->
            <?php endif; ?>
            <div id="notes-existing">
                <div id="notes-list"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="word-detail" id="word-detail">
        <div class="wd-head">
            <h3 id="wd-title"></h3>
            <button class="wd-close" onclick="hideDetail()">close ×</button>
        </div>
        <div id="wd-body"></div>
    </div>

    <?php
    // Combined source-file summaries across the range, collapsed by default.
    $all_sums = [];
    foreach ($verses_data as $vd):
        if (!empty($vd['summaries'])) {
            $all_sums[(int)$vd['verse']['verse']] = $vd['summaries'];
        }
    endforeach;
    ?>
</div>

<script id="word-data" type="application/json"><?= json_encode($detail_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<style>
.notes-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 10000; display: flex; align-items: center; justify-content: center; }
.notes-modal[hidden] { display: none !important; }
.notes-modal-inner { background: #fff; border: 1px solid #ccc; border-radius: 6px; width: 80vw; max-width: none; max-height: 80vh; overflow: auto; padding: 12px 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); font-size: 13px; }
.notes-modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.notes-close { font-size: 18px; background: none; border: none; cursor: pointer; }
.notes-head-actions { display: flex; align-items: center; gap: 6px; }
.notes-add-btn { font-size: 12px; padding: 3px 10px; cursor: pointer; }
#notes-form-wrap[hidden] { display: none !important; }
.notes-guest-msg { margin: 6px 0 2px; font-size: 12px; color: #666; }
#notes-form label { display: block; margin: 6px 0 2px; font-size: 11px; }
/* The blanket `select` rule must exclude .abbc3-size-sel, otherwise
   `width:100%` overrides the toolbar's `width:auto` and stretches the
   size dropdown to fill its flex row, breaking the toolbar layout. */
#notes-form input[type=text], #notes-form select:not(.abbc3-size-sel), #notes-form textarea { width: 100%; box-sizing: border-box; }
#notes-gem-section { background: #f8f8f8; padding: 6px; margin: 6px 0; border-radius: 3px; }
.notes-actions { margin-top: 8px; }
.notes-actions button { margin-right: 6px; }
#notes-list { padding: 4px 0; font-size: 13px; }
#notes-list .note-item { border-bottom: 1px solid #f0f0f0; padding: 6px 0; }
#notes-list .note-item:last-child { border-bottom: none; }
.note-title-line { display: flex; align-items: baseline; flex-wrap: wrap; gap: 2px 4px; margin-bottom: 4px; }
.note-date { color: #888; font-size: 11px; }
.note-action-btn { font-size: 10px; padding: 1px 6px; margin-left: 4px; cursor: pointer; }
.delete-note-btn { color: #b00; }
.note-body { line-height: 1.5; }
.add-note-btn { font-size: 10px; padding: 1px 4px; vertical-align: middle; margin-left: 4px; cursor: pointer; }
.verse-notes-count { cursor: pointer; }
/* notes-type checkbox row */
.notes-type-checks { display:flex; flex-wrap:wrap; gap:8px 16px; margin:4px 0 6px; }
.notes-type-checks label { font-weight:normal; font-size:12px; display:flex; align-items:center; gap:4px; cursor:pointer; }
/* type tag badges shown in rendered notes list */
.note-type-tag { display:inline-block; font-size:10px; padding:1px 5px; border-radius:3px; background:#dde4f0; color:#445; margin-right:2px; vertical-align:middle; }
</style>
<script>
const VERSE_LANG = <?= json_encode($primary_lang) ?>;
const VERSE_REF  = <?= json_encode($range_ref_str) ?>;
</script>

<?php endif; ?>

<div class="view-counter" id="view-counter"
     data-book="<?= htmlspecialchars($book_code) ?>"
     data-chapter="<?= (int)$chapter ?>"
     data-verse="<?= (int)$verse ?>"
     <?= $view_counts['total'] > 0 ? '' : 'hidden' ?>>
    This verse viewed <?= number_format($view_counts['verse']) ?> time<?= $view_counts['verse'] === 1 ? '' : 's' ?> &nbsp;·&nbsp;
    <a href="stats.php"><?= number_format($view_counts['total']) ?> total Bible page view<?= $view_counts['total'] === 1 ? '' : 's' ?></a>
</div>
<script>
(function () {
    var el = document.getElementById('view-counter');
    if (!el) return;
    function fmtN(n) { return n.toLocaleString(); }
    function refresh() {
        // Relative URL — works whether the page is served at /bible/ or root.
        fetch(`api.php?api=viewcount`
            + '&book='    + encodeURIComponent(el.dataset.book)
            + '&chapter=' + el.dataset.chapter
            + '&verse='   + el.dataset.verse)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.total) return;
            el.innerHTML =
                'This verse viewed ' + fmtN(d.verse) + ' time' + (d.verse===1?'':'s') +
                ' &nbsp;&middot;&nbsp; ' +
                '<a href="stats.php">' + fmtN(d.total) + ' total Bible page view' + (d.total===1?'':'s') + '</a>';
            el.removeAttribute('hidden');
        });
    }
    window.addEventListener('pageshow', function(e) { if (e.persisted) refresh(); });
})();
</script>
</main>

<script src="js/options.js"></script>
<script src="js/gematria.js"></script>
<script src="js/word-selection.js"></script>
<script src="js/variant-switcher.js"></script>
<script src="js/dropdowns.js"></script>
<script src="js/search-trigger.js"></script>
<script src="js/strongs-tooltip.js"></script>
<script src="js/grammar-tooltip.js"></script>
<script src="js/deep-link.js"></script>
<!-- BBCode toolbar (reuses phpBB editor.js for insertion/caret so it feels like posting on the forum).
     The globals must be declared before the editor script runs. -->
<script>
  var form_name = 'notesform';
  var text_name = 'message';
</script>
<script src="js/phpbb-editor.js"></script>
<script src="js/bbcode-toolbar.js"></script>
<script src="js/abbc3-toolbar.js"></script>
<script>
(function () {
    var sel = document.getElementById('sel-book');
    if (!sel) return;
    var mq = window.matchMedia('(max-width: 768px)');
    function applyBookNames(e) {
        var abbr = e.matches;
        for (var i = 0; i < sel.options.length; i++) {
            var opt = sel.options[i];
            opt.textContent = abbr ? opt.dataset.abbr : opt.dataset.full;
        }
    }
    applyBookNames(mq);
    mq.addEventListener('change', applyBookNames);
})();
</script>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>

<script>
const CURRENT_USER_ID = <?= json_encode((int)$user['id']) ?>;
const CURRENT_USER_NAME = <?= json_encode($user['name']) ?>;
const CSRF_TOKEN = <?= json_encode(get_csrf_token()) ?>;
const NOTES_CAN_WRITE = <?= json_encode(!$user['is_guest']) ?>;
const NOTES_IS_ADMIN  = <?= json_encode(!empty($user['is_admin'])) ?>;
</script>

<script>
// Verse notes UI: +note button opens modal prefilled for edit if current user already has note(s)
// for that verse (per the "edit existing + Create new note" requirement). Title is required
// (enforced client+server+DB) to distinguish e.g. separate notes on word-ranges 913 vs 703.
// Form supports create or update; list below shows all notes (public collab) with [edit] for own.
// No full page reload on save; list and badge refresh in place.
(function () {
    const modal = document.getElementById('notes-modal');
    if (!modal) return;
    if (modal.hasAttribute('hidden')) modal.style.display = 'none';
    const form = document.getElementById('notes-form');
    const formWrap = document.getElementById('notes-form-wrap');
    const closeBtn = document.getElementById('notes-modal-close');
    const addBtn = document.getElementById('notes-add-btn');
    const gemSec = document.getElementById('notes-gem-section');

    function showForm() { if (formWrap) formWrap.hidden = false; }
    function hideForm() { if (formWrap) formWrap.hidden = true; }
    const typeCbs = document.querySelectorAll('.notes-type-cb');  // NodeList of checkboxes
    const gemCb   = document.querySelector('.notes-type-cb[value="4"]'); // Gematria checkbox
    const listEl = document.getElementById('notes-list');
    let currentVerse = null;
    let editingNoteId = null;

    // Returns comma-separated string of checked type IDs, e.g. "1,4"
    function getCheckedTypeIds() {
        const ids = [];
        typeCbs.forEach(cb => { if (cb.checked) ids.push(cb.value); });
        return ids.length ? ids.join(',') : '1';
    }

    // Set checkboxes from an array of type IDs or names (handles both)
    function setCheckedTypes(types) {
        typeCbs.forEach(cb => { cb.checked = false; });
        if (!types || types.length === 0) {
            // Default to General
            const defCb = document.querySelector('.notes-type-cb[value="1"]');
            if (defCb) defCb.checked = true;
            return;
        }
        // types is array of IDs (numbers) from server, e.g. [1, 4]
        types.forEach(t => {
            const cb = document.querySelector('.notes-type-cb[value="' + t + '"]');
            if (cb) cb.checked = true;
        });
    }

    function renderNotesList(notes) {
        listEl.innerHTML = '';
        if (!notes || notes.length === 0) {
            listEl.innerHTML = '<em>No notes yet for this verse.</em>';
            return;
        }
        notes.forEach(n => {
            const div = document.createElement('div');
            div.className = 'note-item';
            const safeTitle = (n.title || '').replace(/</g, '&lt;');
            const safeUser = (n.username || 'User').replace(/</g, '&lt;');
            const safeBody = n.rendered || (n.note_text || '').replace(/</g, '&lt;');
            const typeTags = (n.types || ['General']).map(t =>
                '<span class="note-type-tag">' + t.replace(/</g, '&lt;') + '</span>'
            ).join(' ');
            const gemNote = (n.type_ids && n.type_ids.includes(4)) ? ' [gematria ' + (n.gem_std || 0) + ']' : '';
            const isMine = parseInt(n.user_id || 0) === CURRENT_USER_ID;
            const canDelete = isMine || NOTES_IS_ADMIN;
            const canToggleVisibility = NOTES_IS_ADMIN && !isMine;

            // Title line: title · type tags · by user · date · [Edit] [Delete]
            const titleEl = document.createElement('div');
            titleEl.className = 'note-title-line';
            let titleHtml = '';
            if (safeTitle) titleHtml += '<strong>' + safeTitle + '</strong> ';
            if (NOTES_IS_ADMIN && !n.is_public) titleHtml += '<span class="note-private-badge" title="Private note">&#x1F512;</span> ';
            titleHtml += typeTags + ' by <strong>' + safeUser + '</strong>' + gemNote +
                ' <span class="note-date">' + ((n.created_at || '').substring(0, 10)) + '</span>';
            titleEl.innerHTML = titleHtml;

            if (isMine) {
                const eb = document.createElement('button');
                eb.type = 'button';
                eb.textContent = 'Edit';
                eb.className = 'note-action-btn';
                eb.onclick = () => loadNoteForEdit(n);
                titleEl.appendChild(eb);
            }
            if (canToggleVisibility) {
                const vb = document.createElement('button');
                vb.type = 'button';
                vb.textContent = n.is_public ? 'Make Private' : 'Make Public';
                vb.className = 'note-action-btn';
                vb.onclick = () => {
                    const nextPublic = n.is_public ? 0 : 1;
                    const actionLabel = nextPublic ? 'make this note public' : 'make this note private';
                    if (!confirm('Admin action: ' + actionLabel + '?')) return;
                    setNoteVisibility(n.id, nextPublic, currentVerse);
                };
                titleEl.appendChild(vb);
            }
            if (canDelete) {
                const db = document.createElement('button');
                db.type = 'button';
                db.textContent = 'Delete';
                db.className = 'note-action-btn delete-note-btn';
                db.onclick = () => {
                    if (!confirm('Delete this note? This cannot be undone.')) return;
                    deleteNote(n.id, currentVerse);
                };
                titleEl.appendChild(db);
            }
            div.appendChild(titleEl);

            // Body: fully rendered content
            const bodyEl = document.createElement('div');
            bodyEl.className = 'note-body';
            bodyEl.innerHTML = safeBody;
            div.appendChild(bodyEl);

            listEl.appendChild(div);
        });
    }

    function loadNoteForEdit(n) {
        if (!n) return;
        editingNoteId = parseInt(n.id || 0) || null;
        setCheckedTypes(n.type_ids || []);
        document.getElementById('notes-title').value = n.title || '';
        document.getElementById('notes-text').value = n.note_text || '';
        document.getElementById('notes-gem-std').value = (n.gem_std != null ? n.gem_std : '');
        document.getElementById('notes-gem-ord').value = (n.gem_ord != null ? n.gem_ord : '');
        document.getElementById('notes-gem-red').value = (n.gem_red != null ? n.gem_red : '');
        gemSec.hidden = !(gemCb && gemCb.checked);
        if (NOTES_IS_ADMIN) { const cb = document.getElementById('notes-is-public'); if (cb) cb.checked = !!n.is_public; }
        const subBtn = document.getElementById('notes-submit');
        if (subBtn) subBtn.textContent = 'Update Note';
        const cnb = document.getElementById('notes-create-new');
        if (cnb) cnb.style.display = '';
        showForm();
    }

    function startNewNote() {
        editingNoteId = null;
        if (!form) return; // guest: form not rendered, nothing to reset
        setCheckedTypes([1]); // default to General
        document.getElementById('notes-title').value = '';
        document.getElementById('notes-text').value = '';
        document.getElementById('notes-gem-std').value = '';
        document.getElementById('notes-gem-ord').value = '';
        document.getElementById('notes-gem-red').value = '';
        if (gemSec) gemSec.hidden = true;
        if (NOTES_IS_ADMIN) { const cb = document.getElementById('notes-is-public'); if (cb) cb.checked = true; }
        const subBtn = document.getElementById('notes-submit');
        if (subBtn) subBtn.textContent = 'Save Note';
        const cnb = document.getElementById('notes-create-new');
        if (cnb) cnb.style.display = 'none';
    }

    function refreshNotesList(book, ch, vs) {
        listEl.innerHTML = '<em>Loading...</em>';
        fetch('api.php?api=verse_notes&book=' + encodeURIComponent(book) + '&chapter=' + ch + '&verse=' + vs)
            .then(r => r.json())
            .then(notes => { renderNotesList(notes || []); })
            .catch(() => { listEl.innerHTML = '<em>Could not refresh notes.</em>'; });
    }

    function updateVerseCountBadge(book, ch, vs) {
        const block = document.querySelector('.verse-block[data-book="' + book + '"][data-chapter="' + ch + '"][data-verse="' + vs + '"]');
        if (!block) return;
        const cntEl = block.querySelector('.verse-notes-count');
        if (!cntEl) return;
        fetch('api.php?api=verse_notes&book=' + encodeURIComponent(book) + '&chapter=' + ch + '&verse=' + vs)
            .then(r => r.json())
            .then(list => {
                const n = (list && list.length) || 0;
                cntEl.textContent = n > 0 ? '(' + n + ')' : '';
                cntEl.dataset.count = n;
            })
            .catch(() => {});
    }

    function deleteNote(noteId, verseCtx) {
        if (!noteId) return;
        const data = new URLSearchParams({
            api: 'delete_verse_note',
            id: noteId,
            csrf_token: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
        });
        fetch('api.php', { method: 'POST', body: data, headers: {'Content-Type': 'application/x-www-form-urlencoded'} })
            .then(r => r.json())
            .then(resp => {
                if (resp && resp.success) {
                    if (verseCtx) {
                        refreshNotesList(verseCtx.book, verseCtx.ch, verseCtx.vs);
                        updateVerseCountBadge(verseCtx.book, verseCtx.ch, verseCtx.vs);
                    }
                    // if we were editing the deleted one, reset form
                    if (editingNoteId && parseInt(editingNoteId) === parseInt(noteId)) {
                        startNewNote();
                    }
                } else {
                    alert('Delete failed: ' + (resp && resp.error ? resp.error : 'unknown'));
                }
            })
            .catch(() => alert('Network error deleting note.'));
    }

    function setNoteVisibility(noteId, isPublic, verseCtx) {
        if (!noteId) return;
        const data = new URLSearchParams({
            api: 'set_verse_note_visibility',
            id: noteId,
            is_public: isPublic ? 1 : 0,
            csrf_token: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
        });
        fetch('api.php', { method: 'POST', body: data, headers: {'Content-Type': 'application/x-www-form-urlencoded'} })
            .then(r => r.json())
            .then(resp => {
                if (resp && resp.success) {
                    if (verseCtx) {
                        refreshNotesList(verseCtx.book, verseCtx.ch, verseCtx.vs);
                        updateVerseCountBadge(verseCtx.book, verseCtx.ch, verseCtx.vs);
                    }
                } else {
                    alert('Visibility update failed: ' + (resp && resp.error ? resp.error : 'unknown'));
                }
            })
            .catch(() => alert('Network error updating visibility.'));
    }

    function showModal(book, ch, vs) {
        currentVerse = {book, ch, vs};
        const bookOpt = document.querySelector('#sel-book option[value="' + book + '"]');
        const longName = (bookOpt && bookOpt.dataset.full) ? bookOpt.dataset.full : book;
        document.getElementById('notes-verse-ref').textContent = longName + ' ' + ch + ':' + vs;
        if (form) {
            document.getElementById('notes-book').value = book;
            document.getElementById('notes-chapter').value = ch;
            document.getElementById('notes-verse').value = vs;
        }
        startNewNote(); // reset form to clean state (no-op for guests)
        hideForm();     // hide form; shown automatically if no existing notes, or on Add/Edit click
        listEl.innerHTML = '<em>Loading...</em>';
        modal.hidden = false;
        modal.style.display = 'flex';

        // fetch existing notes for this verse (public list)
        fetch('api.php?api=verse_notes&book=' + encodeURIComponent(book) + '&chapter=' + ch + '&verse=' + vs)
            .then(r => r.json())
            .then(notes => {
                renderNotesList(notes || []);
                // No notes yet — open form immediately so the user can add one
                if ((!notes || notes.length === 0) && NOTES_CAN_WRITE) showForm();
            })
            .catch(() => { listEl.innerHTML = '<em>Could not load notes.</em>'; });
    }

    function hideModal() {
        modal.hidden = true;
        modal.style.display = 'none';
    }

    if (closeBtn) closeBtn.addEventListener('click', hideModal);
    const cancelBtn = document.getElementById('notes-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', hideForm);
    modal.addEventListener('click', e => { if (e.target === modal) hideModal(); });

    if (typeCbs.length) typeCbs.forEach(cb => {
        cb.addEventListener('change', () => {
            if (gemCb) gemSec.hidden = !gemCb.checked;
        });
    });

    if (form) form.addEventListener('submit', function(ev) {
        ev.preventDefault();
        const titleVal = document.getElementById('notes-title').value.trim();
        const textVal = document.getElementById('notes-text').value || '';
        if (!titleVal) {
            const titleInput = document.getElementById('notes-title');
            titleInput.setCustomValidity('A title is required. Use something descriptive like "First word 913" or "Aleph connection" to identify this note.');
            titleInput.reportValidity();
            titleInput.addEventListener('input', function clearMsg() {
                titleInput.setCustomValidity('');
                titleInput.removeEventListener('input', clearMsg);
            });
            return;
        }
        if (titleVal.length > 255) { alert('Title is too long (max 255 characters).'); return; }
        if (textVal.length > 65000) { alert('Note text is too long (max ~64KB).'); return; }
        const isUpdate = !!editingNoteId;
        const data = new URLSearchParams({
            api: isUpdate ? 'update_verse_note' : 'create_verse_note',
            book: document.getElementById('notes-book').value,
            chapter: document.getElementById('notes-chapter').value,
            verse: document.getElementById('notes-verse').value,
            note_type_ids: getCheckedTypeIds(),
            title: document.getElementById('notes-title').value,
            note_text: document.getElementById('notes-text').value,
            gem_std: document.getElementById('notes-gem-std').value || '',
            gem_ord: document.getElementById('notes-gem-ord').value || '',
            gem_red: document.getElementById('notes-gem-red').value || '',
            is_public: (NOTES_IS_ADMIN && document.getElementById('notes-is-public')?.checked) ? 1 : 0,
            csrf_token: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
        });
        if (isUpdate) {
            data.set('id', editingNoteId);
        }
        fetch('api.php', { method: 'POST', body: data, headers: {'Content-Type': 'application/x-www-form-urlencoded'} })
            .then(r => r.json())
            .then(resp => {
                if (resp.success) {
                    alert('Note saved! Thank you for contributing to the commentary.');
                    if (currentVerse) {
                        refreshNotesList(currentVerse.book, currentVerse.ch, currentVerse.vs);
                        updateVerseCountBadge(currentVerse.book, currentVerse.ch, currentVerse.vs);
                    }
                    startNewNote();
                    hideForm(); // return to notes-only view after save
                } else {
                    alert('Error saving note: ' + (resp.error || 'save failed'));
                }
            })
            .catch(() => alert('Network error saving note.'));
    });

    if (addBtn) addBtn.addEventListener('click', () => { startNewNote(); showForm(); });

    // Wire Create new note button (shown only while editing an existing)
    const createNewBtn = document.getElementById('notes-create-new');
    if (createNewBtn) createNewBtn.addEventListener('click', startNewNote);

    // Autofill gem from verse or selection (sums data-gem-* on .word-cell)
    function sumGems(cells) {
        let s=0, o=0, r=0;
        cells.forEach(c => {
            s += parseInt(c.dataset.gemStd || c.getAttribute('data-gem-std') || 0, 10);
            o += parseInt(c.dataset.gemOrd || c.getAttribute('data-gem-ord') || 0, 10);
            r += parseInt(c.dataset.gemRed || c.getAttribute('data-gem-red') || 0, 10);
        });
        return {std: s, ord: o, red: r};
    }

    const fillVerseBtn = document.getElementById('notes-fill-verse');
    if (fillVerseBtn) fillVerseBtn.addEventListener('click', () => {
        if (!currentVerse) return;
        const block = document.querySelector('.verse-block[data-book="' + currentVerse.book + '"][data-chapter="' + currentVerse.ch + '"][data-verse="' + currentVerse.vs + '"]');
        if (!block) return;
        const cells = block.querySelectorAll('.word-cell');
        const g = sumGems(cells);
        document.getElementById('notes-gem-std').value = g.std;
        document.getElementById('notes-gem-ord').value = g.ord;
        document.getElementById('notes-gem-red').value = g.red;
    });

    const fillSelBtn = document.getElementById('notes-fill-sel');
    if (fillSelBtn) fillSelBtn.addEventListener('click', () => {
        const selCells = document.querySelectorAll('#interlinear .word-cell.selected');
        if (selCells.length === 0) {
            alert('No words selected. Click or S+drag to select words first, or use "Fill from verse".');
            return;
        }
        const g = sumGems(selCells);
        document.getElementById('notes-gem-std').value = g.std;
        document.getElementById('notes-gem-ord').value = g.ord;
        document.getElementById('notes-gem-red').value = g.red;
    });

    // Wire up all +note buttons (they exist in the static HTML from PHP)
    document.querySelectorAll('.add-note-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const block = btn.closest('.verse-block');
            if (!block) return;
            const b = block.dataset.book || 'Gen';
            const c = parseInt(block.dataset.chapter || '1', 10);
            const v = parseInt(block.dataset.verse || '1', 10);
            showModal(b, c, v);
        });
    });

    // Optional: clicking the count badge could open the modal too (future)
    // For now, the create button is the main entry.

    // On load, optionally fetch counts for visible verses to show (N) badges.
    // To avoid too many requests, we can do one call per unique verse or skip for perf.
    // Simple: for each block fetch count separately (small).
    document.querySelectorAll('.verse-block').forEach(block => {
        const b = block.dataset.book;
        const c = block.dataset.chapter;
        const v = block.dataset.verse;
        if (!b || !c || !v) return;
        const cntEl = block.querySelector('.verse-notes-count');
        if (!cntEl) return;
        fetch('api.php?api=verse_notes&book=' + encodeURIComponent(b) + '&chapter=' + c + '&verse=' + v)
            .then(r => r.json())
            .then(list => {
                const n = (list && list.length) || 0;
                cntEl.textContent = n > 0 ? '(' + n + ')' : '';
                cntEl.dataset.count = n;
                // clicking count also opens create/view
                cntEl.addEventListener('click', () => {
                    const btn = block.querySelector('.add-note-btn');
                    if (btn) btn.click();
                });
            })
            .catch(()=>{});
    });
})();
</script>
</div>
</body>
</html>
