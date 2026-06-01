<?php
// search.php â€” Bible Browser search results page.
// GET params:
//   q    â€” one term, or several comma-separated terms (AND logic across a verse)
//   mode â€” strongs | text
//   lang â€” Hebrew | Greek  (used for text normalisation)

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

// In remote API mode, search features (which rely on local tables, fulltext indexes,
// and stored procedures) are not available.
if (should_use_remote_api()) {
    echo "<p>Search functionality is currently disabled when using remote API mode.</p>";
    echo "<p>Please use a local database connection for search features.</p>";
    exit;
}

// Escape LIKE special characters in a user-supplied string so that
// '%' and '_' are treated as literals, not wildcards.
function escape_like(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

$q_raw = trim($_GET['q']    ?? '');
$mode  = strtolower(trim($_GET['mode'] ?? 'strongs'));
$lang  = trim($_GET['lang'] ?? '');

// Handle JSON API requests forwarded here (strongs-tooltip.js, verse-tooltip.js).
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_GET['api']) {
        case 'strongs':
            $code = trim($_GET['code'] ?? '');
            echo json_encode(bible_strongs_lookup($code));
            break;
        case 'kjv_verse':
            $osis    = trim($_GET['book']    ?? '');
            $chapter = (int)($_GET['chapter'] ?? 0);
            $verse   = (int)($_GET['verse']   ?? 0);
            $text    = null;
            if ($osis !== '' && $chapter > 0 && $verse > 0) {
                try {
                    $stmt = bible_pdo()->prepare(
                        'SELECT Verse_Text_Clean FROM bible_kjv
                          WHERE Book = (SELECT id FROM book WHERE osis_code = ? LIMIT 1)
                            AND Chapter = ? AND Verse = ? LIMIT 1'
                    );
                    $stmt->execute([$osis, $chapter, $verse]);
                    $row  = $stmt->fetch();
                    $text = $row ? (string)$row['Verse_Text_Clean'] : null;
                } catch (Throwable $e) { /* fall through, $text stays null */ }
            }
            echo json_encode(['text' => $text]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown api']);
    }
    exit;
}

// â”€â”€ Gematria mode: find all words with a given standard gematria value â”€â”€â”€â”€â”€â”€â”€
if ($mode === 'gematria') {
    $gem_value = (int)($_GET['standard'] ?? 0);
    if ($gem_value <= 0) {
        header('Location: index.php');
        exit;
    }
    $pdo = bible_pdo();

    // Pre-fetch book id â†’ name/osis_code lookup
    $books = [];
    foreach ($pdo->query('SELECT id, name, osis_code FROM book ORDER BY book_order') as $b) {
        $books[(int)$b['id']] = $b;
    }

    // Call stored procedure (returns one row per word occurrence, Bible order)
    $stmt = $pdo->prepare('CALL GetGematriaWords(?)');
    $stmt->execute([$gem_value]);
    $gem_rows = $stmt->fetchAll();
    $stmt->closeCursor();

    // Group by text_search, deduplicating verse references
    $groups = [];
    $gem_occ_count = 0;
    $gem_truncated = false;
    foreach ($gem_rows as $row) {
        if ($gem_truncated) break;
        // Strip Unicode punctuation so λόγῳ, / λόγω. / λόγῳ all map to the same bucket
        $ts = preg_replace('/\p{P}+/u', '', $row['text_search']);
        if (!isset($groups[$ts])) {
            $groups[$ts] = [
                'text_original'   => $row['text_original'],
                'transliteration' => $row['transliteration'],
                'translation'     => $row['translation'],
                'strongs_primary' => $row['strongs_primary'],
                'language'        => $row['language'],
                'seen'            => [],
                'verses'          => [],
            ];
        }
        $book = $books[(int)$row['book_id']] ?? null;
        if ($book) {
            $vkey = $row['book_id'] . ':' . $row['chapter'] . ':' . $row['verse'];
            if (!isset($groups[$ts]['seen'][$vkey])) {
                $groups[$ts]['seen'][$vkey] = true;
                $groups[$ts]['verses'][] = [
                    'book_name' => $book['name'],
                    'osis_code' => $book['osis_code'],
                    'chapter'   => (int)$row['chapter'],
                    'verse'     => (int)$row['verse'],
                ];
                $gem_occ_count++;
                if ($gem_occ_count >= 6000) $gem_truncated = true;
            }
        }
    }
    foreach ($groups as &$g) unset($g['seen']);
    unset($g);

    $form_count = count($groups);
    $total_occ  = array_sum(array_map(fn($g) => count($g['verses']), $groups));
    ?><?php require('../include/bwHeader.inc'); ?>

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Gematria <?= (int)$gem_value ?> &mdash; Bible Browser</title>
<link href="/include/bw.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/include/bw.css') ?>" rel=stylesheet type='text/css'>
<link rel="stylesheet" href="/bible/style.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bible/style.css') ?>">
</head>
<body>
<?php require('../include/bwBanner.php'); ?>
<div class="bible-layout">
<main class="bible-main">
<div class="selector">
    <a href="javascript:history.back()" class="back-link">&larr; Back</a>
    <span class="search-summary">
        Standard gematria&nbsp;<strong><?= (int)$gem_value ?></strong>
        &mdash;&nbsp;<?= $form_count ?>&nbsp;word form<?= $form_count !== 1 ? 's' : '' ?>,
        <?= $total_occ ?>&nbsp;total occurrence<?= $total_occ !== 1 ? 's' : '' ?>
        <?= $gem_truncated ? '<span class="trunc-note">(showing first 6 000)</span>' : '' ?>
    </span>
    <?php
        $gr_file = $_SERVER['DOCUMENT_ROOT'] . '/GR/GR_' . (int)$gem_value . '.php';
        if (file_exists($gr_file)):
    ?>
        <a href="/GR/GR_<?= (int)$gem_value ?>.php" class="gr-article-link" target="_blank">
            Article on <?= (int)$gem_value ?> &rarr;
        </a>
    <?php endif; ?>
</div>

<?php if (empty($groups)): ?>
    <div class="verse-card empty">No words found with standard gematria = <?= (int)$gem_value ?>.</div>
<?php else: ?>
<table class="search-table">
<thead>
    <tr>
        <th>Word</th>
        <th>Strong&rsquo;s</th>
        <th class="gem-count-col">&times;</th>
        <th>Verses</th>
    </tr>
</thead>
<tbody>
<?php foreach ($groups as $g):
    $lcls = ($g['language'] === 'Hebrew') ? 'heb' : 'grk';
    if ($lcls === 'heb') {
        $orig = clean_inline($g['text_original']);
        $tlit = clean_inline($g['transliteration'] ?? '');
    } else {
        [$orig, $tlit_raw] = split_greek_word($g['text_original']);
        $orig = preg_replace('/\p{P}+$/u', '', $orig ?? '');
        $tlit = $tlit_raw ?? clean_inline($g['transliteration'] ?? '');
    }
    $eng  = clean_inline($g['translation'] ?? '');
    // strongs_full_code strips leading zeros (e.g. H430) â€” correct for the
    // strongs table tooltip lookup. For display and LIKE search we need the
    // zero-padded form (H0430) to match the word.strongs column ({H0430G}).
    $strg_key  = strongs_full_code($g['strongs_primary'], $g['language']);
    $strg_raw  = strongs_display($g['strongs_primary']);
    $strg_disp = $strg_raw !== ''
        ? (($g['language'] === 'Hebrew' ? 'H' : 'G') . $strg_raw)
        : '';

    // Group verse links by book, preserving Bible order
    $by_book = [];
    foreach ($g['verses'] as $vr) {
        $code = $vr['osis_code'];
        if (!isset($by_book[$code])) {
            $by_book[$code] = ['name' => $vr['book_name'], 'links' => []];
        }
        $url = 'index.php?book=' . urlencode($code)
             . '&chapter=' . $vr['chapter'] . '&verse=' . $vr['verse'];
        $by_book[$code]['links'][] = '<a href="' . h($url) . '"'
            . ' class="verse-ref"'
            . ' data-book="' . h($code) . '"'
            . ' data-chapter="' . (int)$vr['chapter'] . '"'
            . ' data-verse="' . (int)$vr['verse'] . '">'
            . $vr['chapter'] . ':' . $vr['verse'] . '</a>';
    }
    $bparts = [];
    foreach ($by_book as $bk_code => $bk) {
        $bparts[] = '<strong>' . h($bk_code) . '</strong> '
                  . implode(', ', $bk['links']);
    }
?>
<tr>
    <td class="gem-word-cell">
        <span class="original <?= $lcls ?>"
              style="font-family:var(--<?= $lcls === 'heb' ? 'hebrew' : 'greek' ?>);font-size:<?= $lcls === 'heb' ? '22px' : '18px' ?>"><?= h($orig) ?></span>
        <?php if ($tlit): ?><br><span class="gem-tlit"><?= h($tlit) ?></span><?php endif; ?>
        <?php if ($eng):  ?><br><span class="gem-eng"><?= h($eng) ?></span><?php endif; ?>
    </td>
    <td class="gem-strongs"><?php if ($strg_key): ?><a href="search.php?q=<?= urlencode($strg_disp) ?>&amp;mode=strongs" class="strongs-link" data-strongs="<?= h($strg_key) ?>"><?= h($strg_disp) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
    <td class="gem-count-col"><?= count($g['verses']) ?></td>
    <td class="search-verses"><?= implode('; ', $bparts) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
<script src="js/strongs-tooltip.js"></script>
<script src="js/verse-tooltip.js"></script>
</body>
</html>
<?php
    exit;
}

if ($q_raw === '') {
    header('Location: index.php');
    exit;
}

// Split on commas, drop empties, re-index.
$terms = array_values(array_filter(array_map('trim', explode(',', $q_raw))));
if (empty($terms)) {
    header('Location: index.php');
    exit;
}

// â”€â”€ Normalise a text query to match the text_search column â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function normalize_query(string $text, string $lang): string {
    $text = preg_replace('/\s*\([^)]+\)/u', '', $text);   // strip transliteration parens
    $text = str_replace(['/', '\\'], '', $text);           // strip STEPBible separators
    $text = trim($text);

    if (strtolower($lang) === 'hebrew') {
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, Normalizer::NFD);
        }
        // Strip Hebrew vowel points and cantillation (U+0591â€“U+05C7, U+FB1E)
        $text = preg_replace('/[\x{0591}-\x{05C7}\x{FB1E}]/u', '', $text);
        return trim($text);
    } else {
        // Greek: NFD â†’ strip combining diacritics (preserve U+0345 iota subscript)
        //        â†’ deduplicate U+0345 (browser text-selection can yield a stray
        //          extra U+0345 after precomposed chars like á¿‡ U+1FC7 or á¿† U+1FC6)
        //        â†’ NFC â†’ lowercase
        //        â†’ explicit vowel+U+0345 composition in case ICU NFC missed it
        //          (á¿† U+1FC6 is perispomeni-only; the separate U+0345 must still
        //          compose with the base Î· to give á¿ƒ U+1FC3 for BINARY matching).
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, Normalizer::NFD);
            $text = preg_replace('/[\x{0300}-\x{0344}\x{0346}-\x{036F}]/u', '', $text);
            $text = preg_replace('/\x{0345}{2,}/u', "\u{0345}", $text);
            $text = normalizer_normalize($text, Normalizer::NFC);
        }
        $text = mb_strtolower(trim($text));
        // Defensive explicit composition: Î±/Î·/Ï‰ + U+0345 â†’ á¾³/á¿ƒ/á¿³.
        // Catches the case where NFC left them uncomposed (e.g. PHP ICU quirks).
        return str_replace(
            ["\u{03B1}\u{0345}", "\u{03B7}\u{0345}", "\u{03C9}\u{0345}"],
            ["\u{1FB3}",         "\u{1FC3}",         "\u{1FF3}"],
            $text
        );
    }
}

// â”€â”€ Build SQL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const RESULT_LIMIT = 6001;
$pdo    = bible_pdo();
$rows   = [];
$error  = null;
$norms  = [];   // display terms for the results header

$where_sql  = '';
$params     = [];
$not_found  = false;

if ($mode === 'phrase' && strtolower($lang) === 'english') {
    // English KJV phrase search â€” match against bible_kjv.Verse_Text_Clean.
    // Strip sentence punctuation (,;:.!?) from both the needle and the stored
    // column so that "weeping, and wailing;" finds the same verses as
    // "weeping and wailing". Comparison is case-insensitive via LOWER().
    $needle   = trim(preg_replace('/\s+/u', ' ', $q_raw));
    $needle   = preg_replace('/[,;:.!?]/u', '', $needle);         // strip punctuation
    $needle   = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $needle)));  // lowercase + tidy
    $norms[]  = $q_raw;   // show what the user typed in the results header
    $where_sql = "EXISTS (
                    SELECT 1
                      FROM bible_kjv k
                     WHERE k.Book    = b.id
                       AND k.Chapter = v.chapter
                       AND k.Verse   = v.verse
                       AND LOWER(REGEXP_REPLACE(k.Verse_Text_Clean, '[,;:.!?]', '')) LIKE ?)";
    $params[]  = '%' . escape_like($needle) . '%';
} elseif ($mode === 'phrase') {
    // Whole-phrase search against verse.text_search (Hebrew / Greek).
    // Normalise the full phrase (treats it as one string â€” spaces are preserved).
    $norm    = normalize_query($q_raw, $lang);
    // Strip iota-subscript forms from the phrase query so that LIKE comparisons
    // work correctly under utf8mb4_unicode_ci (which treats U+0345 as a
    // zero-weight combining character and silently fails LIKE patterns that
    // contain á¾³/á¿ƒ/á¿³).  verse.text_search is rebuilt with the same stripping
    // by add_verse_search.py, so both sides are comparable.
    if (strtolower($lang) !== 'hebrew') {
        $norm = str_replace(
            ["\u{1FB3}", "\u{1FC3}", "\u{1FF3}", "\u{0345}"],
            ["\u{03B1}", "\u{03B7}", "\u{03C9}", ''],
            $norm
        );
    }
    $norms[]   = $norm ?: $q_raw;
    $where_sql = "v.text_search LIKE ?";
    $params[]  = '%' . escape_like($norm) . '%';
} else {
    $exists_clauses = [];
    if ($mode === 'strongs') {
        // Strong's: comma-separated codes, AND logic.
        // Normalise to zero-padded form so 'H430' matches stored '{H0430G}'.
        // Validate each term against the strongs dictionary first â€” grammatical
        // helper codes (e.g. H9001â€“H9999) exist in word.strongs but have no
        // lexical entry and would otherwise return thousands of spurious hits.
        foreach ($terms as $term) {
            $search_term = $term;
            if (preg_match('/^([HG])(\d{1,5})([A-Za-z]?)$/', $term, $sm)) {
                $search_term = $sm[1] . str_pad($sm[2], 4, '0', STR_PAD_LEFT) . $sm[3];
            }
            // Lookup key: strip leading zeros (H0430 â†’ H430) to match strongs.number.
            $lookup_key = $term;
            if (preg_match('/^([HG])0*(\d+)([A-Za-z]?)$/', $term, $sm)) {
                $lookup_key = $sm[1] . $sm[2] . $sm[3];
            }
            if (bible_strongs_lookup($lookup_key) === null) {
                // Code not in the strongs dictionary â€” skip the SQL query.
                $norms     = [$term];
                $not_found = true;
                break;
            }
            $exists_clauses[] =
                "EXISTS (SELECT 1 FROM word w\n"
              . "         WHERE w.verse_id = v.id AND w.strongs LIKE ?)";
            $params[] = '%' . escape_like($search_term) . '%';
            $norms[]  = $term;
        }
    } else {
        // Text: split on commas AND whitespace so "beginning God" becomes
        // two independent word searches with AND logic.
        $text_terms = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/u', $q_raw))
        ));
        if (strtolower($lang) === 'english') {
            // English words: search KJV Verse_Text_Clean per word (AND logic).
            // Strip punctuation and lowercase both sides, same as phrase mode.
            foreach ($text_terms as $term) {
                $needle = mb_strtolower(preg_replace('/[,;:.!?]/u', '', $term));
                $exists_clauses[] =
                    "EXISTS (\n"
                  . "  SELECT 1 FROM bible_kjv k\n"
                  . "   WHERE k.Book = b.id AND k.Chapter = v.chapter AND k.Verse = v.verse\n"
                  . "     AND LOWER(REGEXP_REPLACE(k.Verse_Text_Clean, '[,;:.!?]', '')) LIKE ?)";
                $params[] = '%' . escape_like($needle) . '%';
                $norms[]  = $term;
            }
        } else {
            // Hebrew / Greek: search word.text_search (normalised, no diacritics).
            // LIKE gives partial matching so "logos" hits "Î»ÏŒÎ³Î¿Ï‚" after normalisation.
            foreach ($text_terms as $term) {
                $norm = normalize_query($term, $lang);
                $exists_clauses[] =
                    "EXISTS (SELECT 1 FROM word w\n"
                  . "         WHERE w.verse_id = v.id AND w.text_search LIKE ?)";
                $params[] = '%' . escape_like($norm) . '%';
                $norms[]  = $norm ?: $term;
            }
        }
    }
    $where_sql = implode("\n  AND ", $exists_clauses);
}

if (!$not_found) {
    try {
        $stmt = $pdo->prepare(
            "SELECT b.name AS book_name, b.osis_code, b.testament,
                    b.book_order, v.chapter, v.verse
               FROM verse v
               JOIN book b ON b.id = v.book_id
              WHERE $where_sql
              ORDER BY b.book_order, v.chapter, v.verse
              LIMIT " . RESULT_LIMIT
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Bible search error: ' . $e->getMessage());
        $error = 'A database error occurred. Please try again.';
    }
}

$truncated   = !empty($rows) && (count($rows) === RESULT_LIMIT);
if ($truncated) array_pop($rows);
$verse_count = count($rows);

// â”€â”€ Group: testament â†’ book â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$grouped = [];
foreach ($rows as $r) {
    $test = $r['testament'] ?: 'OT';
    $code = $r['osis_code'];
    if (!isset($grouped[$test][$code])) {
        $grouped[$test][$code] = ['name' => $r['book_name'], 'verses' => []];
    }
    $grouped[$test][$code]['verses'][] = [(int)$r['chapter'], (int)$r['verse']];
}

$mode_label  = match($mode) {
    'strongs' => "Strong's",
    'phrase'  => (strtolower($lang) === 'english') ? "KJV phrase" : "Phrase",
    'text'    => (strtolower($lang) === 'english') ? "KJV words"  : "Text",
    default   => "Text",
};
$multi       = ($mode !== 'phrase') && count($terms) > 1;
$display_q   = ($mode === 'phrase') ? h($norms[0]) : implode(' + ', array_map('h', $norms));
?><?php require('../include/bwHeader.inc'); ?>

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Search: <?= h($q_raw) ?> &mdash; Bible Browser</title>
<link href="/include/bw.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/include/bw.css') ?>" rel=stylesheet type='text/css'>
<link rel="stylesheet" href="/bible/style.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bible/style.css') ?>">
</head>
<body>
<?php require('../include/bwBanner.php'); ?>
<div class="bible-layout">
<main class="bible-main">
    <div class="selector">
        <a href="javascript:history.back()" class="back-link">&#8592; Back</a>
        <span class="search-summary" dir="ltr">
            <?= h($mode_label) ?> search:
            <strong><bdi><?= $display_q ?></bdi></strong>
            <?php if ($multi && !$error): ?>
                <span class="search-all-label">(all in same verse)</span>
            <?php endif; ?>
            <?php if (!$error): ?>
                &nbsp;&mdash;&nbsp;<?= $verse_count ?> verse<?= $verse_count !== 1 ? 's' : '' ?>
                <?= $truncated ? '<span class="trunc-note">(showing first 6 000)</span>' : '' ?>
            <?php endif; ?>
        </span>
    </div>

<?php if ($error): ?>
    <div class="verse-card empty">
        Query error: <?= h($error) ?>
        <?php if (str_contains($error, 'text_search')): ?>
            <br><small>
            <?= $mode === 'phrase'
                ? 'The <code>verse.text_search</code> column hasn\'t been populated yet â€” run <code>add_verse_search.py</code> from the project root first.'
                : 'The <code>word.text_search</code> column hasn\'t been populated yet â€” run <code>add_text_search.py</code> from the project root first.' ?>
            </small>
        <?php endif; ?>
    </div>
<?php elseif ($verse_count === 0): ?>
    <div class="verse-card empty">
        No verses found containing
        <?= $multi ? 'all of: <strong>' . $display_q . '</strong>' : '&ldquo;' . h($terms[0]) . '&rdquo;' ?>.
    </div>
<?php else: ?>

    <?php foreach (['OT' => 'Old Testament', 'NT' => 'New Testament'] as $test => $test_label): ?>
        <?php if (empty($grouped[$test])) continue; ?>
        <div class="search-testament"><?= $test_label ?></div>
        <table class="search-table">
            <thead><tr><th>Book</th><th>Verses</th></tr></thead>
            <tbody>
            <?php foreach ($grouped[$test] as $code => $bk): ?>
                <tr>
                    <td class="search-book"><?= h($code) ?></td>
                    <td class="search-verses">
                    <?php
                        $links = [];
                        foreach ($bk['verses'] as [$ch, $vs]) {
                            $url     = 'index.php?book=' . urlencode($code)
                                     . '&chapter=' . $ch . '&verse=' . $vs;
                            $links[] = '<a href="' . h($url) . '"'
                                     . ' class="verse-ref"'
                                     . ' data-book="' . h($code) . '"'
                                     . ' data-chapter="' . $ch . '"'
                                     . ' data-verse="' . $vs . '">'
                                     . $ch . ':' . $vs . '</a>';
                        }
                        echo implode(', ', $links);
                    ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

<?php endif; ?>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
<script src="js/verse-tooltip.js"></script>
</body>
</html>

