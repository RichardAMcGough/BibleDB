<?php
// helpers.php — PHP helper functions for the Bible Browser template.
// Included by index.php; must not produce any output.

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function lang_class($lang) { return $lang === 'Hebrew' ? 'heb' : 'grk'; }

// Strip parenthesised Romanisation from a Greek string that may contain
// multiple words, e.g. 'Ἐν (En) ἀρχῇ (archēa) ἦν (ēn)' → 'Ἐν ἀρχῇ ἦν'.
function strip_greek_parens(?string $s): string {
    if ($s === null) return '';
    $s = preg_replace('/\s*\([^)]+\)/u', '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

// Split a Greek word like 'Ἐν (En)' into [original, transliteration].
function split_greek_word(?string $orig): array {
    if ($orig === null) return [null, null];
    if (preg_match('/^(.*?)\s+\((.+?)\)\s*$/u', $orig, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return [trim($orig), null];
}

// Pick the Strong's number to display (prefer root word in {} brackets).
function strongs_display(?string $strongs): string {
    if (!$strongs) return '';
    if (preg_match('/\{[HG](\d{3,5})[A-Za-z]?\}/', $strongs, $m)) return $m[1];
    if (preg_match('/[HG](\d{3,5})[A-Za-z]?/',     $strongs, $m)) return $m[1];
    return $strongs;
}

// Build the canonical Strong's lookup key for the `strongs` DB table, where
// entries are stored unpadded as 'H1', 'H430', 'G851' etc. The word.strongs
// column carries padded forms like '{H0430}' or 'G0851/G1234'; this helper
// extracts the primary code (prefers the root word in {} brackets, same as
// strongs_display) and strips leading zeros. Falls back to deriving the
// H/G prefix from $language if the source has bare digits.
function strongs_full_code(?string $strongs, string $language): string {
    if (!$strongs) return '';
    if (preg_match('/\{([HG])(\d{3,5})[A-Za-z]?\}/', $strongs, $m)
        || preg_match('/([HG])(\d{3,5})[A-Za-z]?/',     $strongs, $m)) {
        return $m[1] . (ltrim($m[2], '0') ?: '0');
    }
    if (preg_match('/(\d{3,5})/', $strongs, $m)) {
        $prefix = $language === 'Hebrew' ? 'H' : 'G';
        return $prefix . (ltrim($m[1], '0') ?: '0');
    }
    return '';
}

// Hebrew morphology decoder lives in its own file. hebrew_letter_translit(),
// _decode_noun_suffix(), _decode_verb_suffix(), and format_hebrew_grammar()
// are all defined there. Required here so any caller that includes helpers.php
// gets them transparently.
require_once __DIR__ . '/hebrew_grammar.php';

// ----------------------------------------------------------------------
// Page layout helpers.
// ----------------------------------------------------------------------
// The web UI is normally embedded inside biblewheel.com's external
// header/banner includes. When those files aren't present (standalone
// dev mode, e.g. `php -S localhost:8080` from the web/ directory), we
// fall back to minimal local_header.inc.php / local_banner.inc.php.
//
// These helpers centralise the file_exists() check so every page calls
// the same code path. Pages should:
//   bible_render_layout_header();   // before <html>
//   bible_render_layout_styles();   // inside <head>
//   bible_render_layout_banner();   // first thing inside <body>

function bible_external_include_dir(): ?string {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cfg_path = __DIR__ . '/config.php';
    $cfg = file_exists($cfg_path) ? require $cfg_path : [];
    $candidates = [];

    if (!empty($cfg['include_dir'])) {
        $candidates[] = rtrim((string)$cfg['include_dir'], "\\/");
    }

    // Common layouts:
    // 1) deployed: app lives at <docroot>/bible, includes at <docroot>/include
    // 2) dev checkout: BibleDB/web with the served copy in sibling public_html
    // The include dir is never replicated into the repo (it carries live
    // credentials in wp-config.php); the canonical local copy is
    // public_html/include.
    $candidates[] = __DIR__ . '/../include';
    $candidates[] = __DIR__ . '/../../public_html/include';

    foreach ($candidates as $dir) {
        if ($dir === '') continue;
        $hdr = $dir . '/bwHeader.inc';
        $bnr = $dir . '/bwBanner.php';
        if (file_exists($hdr) && file_exists($bnr)) {
            return $cache = $dir;
        }
    }

    return $cache = null;
}

function bible_is_local_layout(): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    return $cache = (bible_external_include_dir() === null);
}

function bible_render_layout_header(): void {
    if (bible_is_local_layout()) {
        require __DIR__ . '/local_header.inc.php';
    } else {
        $inc = bible_external_include_dir();
        require $inc . '/bwHeader.inc';
    }
}

function bible_render_layout_banner(): void {
    if (bible_is_local_layout()) {
        require __DIR__ . '/local_banner.inc.php';
    } else {
        $inc = bible_external_include_dir();
        require $inc . '/bwBanner.php';
        // Production banner is external — inject our badge as a fixed overlay.
        bible_render_user_badge(false);
    }
}

/**
 * Build phpBB login URL with redirect back to the current page.
 * Returns empty string when phpbb_url is not configured.
 */
function bible_phpbb_login_url(?string $phpbb_url = null): string {
    static $cfg = null;
    if ($phpbb_url === null) {
        if ($cfg === null) {
            $cfg_path = __DIR__ . '/config.php';
            $cfg = file_exists($cfg_path) ? require $cfg_path : [];
        }
        $phpbb_url = trim($cfg['phpbb_url'] ?? '');
    } else {
        $phpbb_url = trim($phpbb_url);
    }

    if ($phpbb_url === '') return '';

    $login_url = rtrim($phpbb_url, '/') . '/ucp.php?mode=login';
    $req_uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($req_uri === '') return $login_url;

    // Keep redirect relative to this host/path and avoid absolute URLs.
    if ($req_uri[0] !== '/') {
        $req_uri = '/' . ltrim($req_uri, '/');
    }

    return $login_url . '&redirect=' . rawurlencode($req_uri);
}

/**
 * Feature flag for verse notes UI/API.
 * Defaults to true when key is not present.
 */
function bible_notes_enabled(): bool {
    static $cfg = null;
    if ($cfg === null) {
        $cfg_path = __DIR__ . '/config.php';
        $cfg = file_exists($cfg_path) ? require $cfg_path : [];
    }
    if (!array_key_exists('enable_notes', $cfg)) {
        return true;
    }
    return !empty($cfg['enable_notes']);
}

/**
 * Emit a small "logged in as" badge.
 * $inflow=true  → plain inline element (placed inside a flex banner by the caller).
 * $inflow=false → position:fixed top-right overlay (used with the external production banner).
 * Safe to call when db.php is not loaded — returns silently.
 */
function bible_render_user_badge(bool $inflow = true): void {
    if (!function_exists('get_bible_user')) return;

    // Read config to decide whether to show the badge.
    static $cfg = null;
    if ($cfg === null) {
        $cfg_path = __DIR__ . '/config.php';
        $cfg = file_exists($cfg_path) ? require $cfg_path : [];
    }
    $phpbb_path = trim($cfg['phpbb_path'] ?? '');
    $phpbb_url  = trim($cfg['phpbb_url']  ?? '');
    $force_show = !empty($cfg['show_user_badge']);
    // If phpBB isn't configured and no login URL is available we'd only show a
    // generic dev user badge — suppress unless explicitly enabled.
    if ($phpbb_path === '' && $phpbb_url === '' && !$force_show) return;

    $user = get_bible_user();
    $cls_fixed = $inflow ? '' : ' bible-user-badge--fixed';
    if ($user['is_guest']) {
        // Show a "Log in" link when phpBB URL is known; otherwise nothing.
        $login_url = bible_phpbb_login_url($phpbb_url);
        if ($login_url === '') return;
        echo '<div class="bible-user-badge bible-user-badge--guest' . $cls_fixed . '">';
        echo '<a href="' . htmlspecialchars($login_url, ENT_QUOTES, 'UTF-8') . '">Log in</a>';
        echo '</div>';
        return;
    }
    $name = htmlspecialchars($user['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $is_dev = ((int)$user['id'] === 999999);
    $cls = 'bible-user-badge';
    if (!$inflow) $cls .= ' bible-user-badge--fixed';
    if ($is_dev)  $cls .= ' bible-user-badge--dev';
    echo '<div class="' . $cls . '" title="Logged in as ' . $name . '">';
    echo '<i class="fa fa-user-circle" aria-hidden="true"></i>';
    echo '<span class="bible-user-name">' . $name . '</span>';
    echo '</div>';
}

// Emit the page's stylesheet <link> tags. Hrefs are RELATIVE so the page
// renders correctly whether served at /bible/ (Apache/IIS) or at the
// origin root (php -S localhost:8080). Cache-busted by file mtime.
function bible_render_layout_styles(): void {
    if (!bible_is_local_layout()) {
        $inc = bible_external_include_dir();
        // Production/live-layout mode: pull shared bw.css from the resolved
        // include directory.
        $bw = $inc ? ($inc . '/bw.css') : ($_SERVER['DOCUMENT_ROOT'] . '/include/bw.css');
        if (file_exists($bw)) {
            echo '<link href="/include/bw.css?v=' . filemtime($bw)
               . '" rel="stylesheet" type="text/css">' . "\n";
        }
    }
    $local_css = __DIR__ . '/style.css';
    $v = file_exists($local_css) ? filemtime($local_css) : '';
    echo '<link rel="stylesheet" href="style.css?v=' . h($v) . '">' . "\n";
}

// ----------------------------------------------------------------------
// KJV inline-Strong's-tag renderer.
// ----------------------------------------------------------------------
// Source text looks like:
//   "In the beginning <07225> God <0430> created <01254> <0853> the heaven
//    <08064> and <0853> the earth <0776>."
// Each <NNNN> tag attaches to the immediately preceding English word.
// Multiple tags can follow one word ("created <01254> <0853>" → both
// codes attach to "created"). [bracketed] words are KJV-supplied
// insertions and become <em> italics; they do NOT consume tags.
//
// The output is sanitized HTML: hoverable spans for tagged words plus
// plain text / italics elsewhere. Class .kjv-tag is the hover hook for
// strongs-tooltip.js; data-strongs carries one or more space-separated
// canonical Strong's codes ("H430" or "G3056") for it to fetch.
//
// $testament must be 'OT' (→ H-prefix) or 'NT' (→ G-prefix).
function render_kjv_tagged(?string $raw, string $testament): string {
    if ($raw === null || $raw === '') return '';
    $prefix = $testament === 'NT' ? 'G' : 'H';

    // Tokenize. The five alternatives match in order:
    //   1) <NNNN>          inline Strong's tag (digits only)
    //   2) [text]          KJV-supplied insertion (rendered italic)
    //   3) {text}          STEPBible alt-marker (rare; show in braces)
    //   4) A word          letters + internal apostrophe
    //   5) Whitespace      collapsed later
    //   6) Everything else punctuation, kept verbatim
    // Note the trailing /u for Unicode safety.
    $pattern = '/<(\d+)>|\[([^\]]*)\]|\{([^}]*)\}|([A-Za-z][A-Za-z\']*)|(\s+)|([^\s<\[\{A-Za-z]+)/u';
    if (!preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER)) {
        return h($raw);
    }

    // Pass 1: build a flat list of tokens, deferring tag attachment.
    // We branch on the first character of the whole match ($m[0]) — that
    // unambiguously identifies which alternative fired and avoids leaning
    // on cross-PHP-version differences in how unmatched capture groups
    // are filled in by preg_match_all.
    $tokens = [];
    $lastWordIdx = -1;
    foreach ($matches as $m) {
        $whole = $m[0];
        if ($whole === '') continue;
        $c0 = $whole[0];
        if ($c0 === '<') {
            // <NNNN> — append to the most recent word token, if any.
            $num  = ltrim((string)($m[1] ?? ''), '0');
            $code = $prefix . ($num === '' ? '0' : $num);
            if ($lastWordIdx >= 0) {
                $tokens[$lastWordIdx]['codes'][] = $code;
            } // else: tag with no preceding word — silently drop
        } elseif ($c0 === '[') {
            // [supplied] — italic, does not become a tag target.
            $tokens[] = ['type' => 'bracket', 'text' => (string)($m[2] ?? '')];
        } elseif ($c0 === '{') {
            // {brace} — rare alt marker; render literally.
            $tokens[] = ['type' => 'text', 'text' => '{' . (string)($m[3] ?? '') . '}'];
        } elseif (ctype_alpha($c0)) {
            $tokens[] = ['type' => 'word', 'text' => $whole, 'codes' => []];
            $lastWordIdx = count($tokens) - 1;
        } elseif (ctype_space($c0)) {
            $tokens[] = ['type' => 'space'];
        } else {
            $tokens[] = ['type' => 'text', 'text' => $whole];
        }
    }

    // Pass 2: emit HTML.
    $out = '';
    foreach ($tokens as $t) {
        switch ($t['type']) {
            case 'word':
                if (!empty($t['codes'])) {
                    $codes = implode(' ', $t['codes']);
                    $out .= '<span class="kjv-tag strongs-link" data-strongs="' . h($codes) . '">'
                          . h($t['text']) . '</span>';
                } else {
                    $out .= h($t['text']);
                }
                break;
            case 'bracket':
                $out .= '<em class="kjv-supplied">' . h($t['text']) . '</em>';
                break;
            case 'space':
                $out .= ' ';
                break;
            case 'text':
                $out .= h($t['text']);
                break;
        }
    }

    // Tidy up runs of whitespace left behind where tags were stripped, and
    // remove the space we'd otherwise leave between a word and trailing
    // punctuation (e.g. "void <0922>;" → "void ;" → "void;").
    $out = preg_replace('/ {2,}/', ' ', $out);
    $out = preg_replace('/ ([,.;:!?])/', '$1', $out);
    return trim($out);
}


// Remove STEPBible morpheme separators ('/' and '\') for inline display.
function clean_inline(?string $s): string {
    if ($s === null) return '';
    $s = str_replace(['/', '\\'], '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

// Count letters in a word's original text. For Hebrew this strips the
// section markers \פ (Petuhah) and \ס (Setumah) BEFORE counting --
// otherwise the bare peh/samek would inflate the letter total. Mirrors
// the logic in compute_gematria.py's clean_hebrew().
function letter_count(?string $text, string $language): int {
    if (!$text) return 0;
    if ($language === 'Hebrew') {
        // Strip Petuhah \פ and Setumah \ס section markers in BOTH formats:
        //   1. STEPBible's backslash form: \פ or \ס
        //   2. Trailing space form: ' פ' / ' ס' (common after sof passuq ׃)
        // Without (2) the samek/peh would be counted as a real letter,
        // producing inconsistent counts when the same logical word stores
        // its section marker in different formats across rows.
        $text = preg_replace('/\\\\[\x{05E4}\x{05E1}]/u',     '', $text);
        // Standalone parashah markers (פ Petuhah / ס Setumah) — only ones
        // bracketed by whitespace or string-end. Won't touch a samek/peh
        // that's part of a real Hebrew word like כּוֹס.
        $text = preg_replace('/(?<=\s)[\x{05E4}\x{05E1}](?=\s|$)/u', '', $text);
        $text = str_replace(['/', '\\'], '', $text);
        return preg_match_all('/[\x{05D0}-\x{05EA}]/u', $text);
    }
    $text = preg_replace('/\s*\([^)]+\)/u', '', $text);
    return preg_match_all(
        '/[\x{0345}\x{0391}-\x{03A9}\x{03B1}-\x{03C9}' .
        '\x{0386}\x{0388}-\x{038A}\x{038C}\x{038E}\x{038F}' .
        '\x{03AC}-\x{03CE}\x{1F00}-\x{1FBC}\x{1FBE}\x{1FC0}-\x{1FFD}]/u',
        $text
    );
}

// ----------------------------------------------------------------------
// BBCode renderer for user notes (forum-post style formatting).
// See db.php for get_bible_user() and the notes storage functions.
// ----------------------------------------------------------------------

/**
 * Convert a limited, safe subset of BBCode to HTML (forum-post style).
 * Input should be user-controlled text. Output is safe HTML.
 * Supported: [b], [i], [u], [s], [color=], [size=], [quote], [quote=Name], [code], [list][*], [url], [img]
 */
function bbcode_to_html(?string $text): string {
    if ($text === null || trim($text) === '') {
        return '';
    }
    // Escape first for safety
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Protect [code]...[/code] from nl2br (pre already preserves whitespace/newlines)
    $codeBlocks = [];
    $text = preg_replace_callback('/\[code\](.*?)\[\/code\]/is', function($m) use (&$codeBlocks) {
        $ph = "\0CODE" . count($codeBlocks) . "\0";
        $codeBlocks[$ph] = $m[1];
        return $ph;
    }, $text);

    // Order: more specific / multi-line first (on the masked text)
    // Quotes
    $text = preg_replace('/\[quote=([^\]]{1,100})\](.*?)\[\/quote\]/is', '<blockquote><strong>$1 wrote:</strong><br>$2</blockquote>', $text);
    $text = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote>$1</blockquote>', $text);

    // Lists (basic, single-level only; nested lists have limited support)
    $text = preg_replace('/\[list\](.*?)\[\/list\]/is', '<ul>$1</ul>', $text);
    $text = preg_replace('/\[\*\](.*?)(?=\[\*|\[\/list\]|<\/ul>|$)/is', '<li>$1</li>', $text);

    // Inline
    $text = preg_replace('/\[b\](.*?)\[\/b\]/is', '<strong>$1</strong>', $text);
    $text = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $text);
    $text = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $text);
    $text = preg_replace('/\[s\](.*?)\[\/s\]/is', '<s>$1</s>', $text);

    // Color — only accept safe hex or named-color values (no CSS injection)
    $text = preg_replace('/\[color=([a-zA-Z#0-9]{1,30})\](.*?)\[\/color\]/is', '<span style="color:$1">$2</span>', $text);

    // Size — percent-based (clamped 50–300 to prevent absurdly large text)
    $text = preg_replace_callback('/\[size=(\d{1,3})\](.*?)\[\/size\]/is', function ($m) {
        $pct = max(50, min(300, (int)$m[1]));
        return '<span style="font-size:' . $pct . '%">' . $m[2] . '</span>';
    }, $text);

    // Links
    $text = preg_replace('/\[url\](https?:\/\/[^\s<"]+?)\[\/url\]/i', '<a href="$1" rel="noopener noreferrer">$1</a>', $text);
    $text = preg_replace('/\[url=(https?:\/\/[^\s<"]+?)\](.*?)\[\/url\]/i', '<a href="$1" rel="noopener noreferrer">$2</a>', $text);

    // Images (limited)
    $text = preg_replace('/\[img\](https?:\/\/[^\s<"]+?)\[\/img\]/i', '<img src="$1" alt="user image" style="max-width:100%; height:auto;">', $text);

    // Convert newlines to <br> (after blocks) -- code regions are still masked so \n inside stay literal
    $text = nl2br($text, false);

    // Restore code blocks (inner content keeps its original \n, wrapped in pre which preserves them)
    foreach ($codeBlocks as $ph => $inner) {
        $text = str_replace($ph, '<pre><code>' . $inner . '</code></pre>', $text);
    }

    return $text;
}

/**
 * Render an ABBC3-style BBCode toolbar using Font Awesome 4 icons.
 * Compatible with phpBB's editor.js (bbstyle / bbfontstyle API).
 *
 * Pages must load before </body>:
 *   <script> var form_name = '…'; var text_name = '…'; </script>
 *   <script src="js/phpbb-editor.js"></script>
 *   <script src="js/bbcode-toolbar.js"></script>
 *   <script src="js/abbc3-toolbar.js"></script>
 * And in <head>:
 *   <link rel="stylesheet" href="…/font-awesome.min.css">
 *
 * Pass the *name* attributes (not ids) of the <form> and <textarea>.
 */
function render_bbcode_toolbar(string $form_name = 'postform', string $text_name = 'message'): string {
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $f = $h($form_name);
    $t = $h($text_name);

    $out  = '<div class="abbc3-toolbar" data-form="' . $f . '" data-field="' . $t . '">';

    // Bold / Italic / Underline / Strikethrough
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(0)" title="Bold [b]" accesskey="b"><i class="fa fa-bold"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(2)" title="Italic [i]" accesskey="i"><i class="fa fa-italic"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(4)" title="Underline [u]" accesskey="u"><i class="fa fa-underline"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="insert_bbcode(\'[s]\',\'[/s]\')" title="Strikethrough [s]"><i class="fa fa-strikethrough"></i></button>';

    $out .= '<span class="abbc3-sep"></span>';

    // Quote / Code
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(6)" title="Quote [quote]" accesskey="q"><i class="fa fa-quote-left"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(8)" title="Code block [code]" accesskey="c"><i class="fa fa-code"></i></button>';

    $out .= '<span class="abbc3-sep"></span>';

    // List / List-item
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(10)" title="Bullet list [list]" accesskey="l"><i class="fa fa-list"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(-1)" title="List item [*]" accesskey="y"><i class="fa fa-asterisk"></i></button>';

    $out .= '<span class="abbc3-sep"></span>';

    // URL / Image
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(16)" title="Link [url]" accesskey="w"><i class="fa fa-link"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="bbstyle(14)" title="Image [img]" accesskey="p"><i class="fa fa-picture-o"></i></button>';

    $out .= '<span class="abbc3-sep"></span>';

    // Color + size + copy/paste — wrapped in a no-break group so they stay together
    $out .= '<span class="abbc3-group">';

    // Color picker
    $out .= '<span class="abbc3-color-wrap">';
    $out .= '<button type="button" class="abbc3-btn abbc3-color-btn" onclick="changePalette(\'abbc3_palette\')" title="Font color [color=]"><i class="fa fa-tint"></i></button>';
    $out .= '<div id="abbc3_palette"></div>';
    $out .= '</span>';

    // Font size
    $out .= '<select class="abbc3-size-sel" onchange="abbc3InsertSize(this)" title="Font size [size=]">';
    $out .= '<option value="">Size…</option>';
    $out .= '<option value="50">Tiny</option>';
    $out .= '<option value="85">Small</option>';
    $out .= '<option value="100">Normal</option>';
    $out .= '<option value="150">Large</option>';
    $out .= '<option value="200">Huge</option>';
    $out .= '</select>';

    $out .= '<span class="abbc3-sep"></span>';

    // Copy / Paste / Strip BBCode
    $out .= '<button type="button" class="abbc3-btn" onclick="bbCopy()" title="Copy selection to internal buffer"><i class="fa fa-copy"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="bbPaste()" title="Paste from internal buffer"><i class="fa fa-paste"></i></button>';
    $out .= '<button type="button" class="abbc3-btn" onclick="bbPlain()" title="Strip BBCode from selection"><i class="fa fa-eraser"></i></button>';

    $out .= '</span>'; // end .abbc3-group

    $out .= '</div>';

    return $out;
}

// ===================================================================
// Session helper — defined in db.php; duplicated here as fallback
// when helpers.php is loaded without db.php.
// ===================================================================

if (!function_exists('_start_session_safe')) {
    function _start_session_safe(): void {
        if (session_status() !== PHP_SESSION_NONE) return;
        $path = session_save_path();
        if ($path !== '' && !is_dir($path)) {
            session_save_path(sys_get_temp_dir());
        }
        session_start();
    }
}

// ===================================================================
// CSRF protection helpers (for note POSTs etc.)
// ===================================================================

/**
 * Get (or generate) the per-session CSRF token.
 * Starts the session if needed (dev fallback path).
 * Call early, before any output.
 */
function get_csrf_token(): string {
    _start_session_safe();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from request.
 * Accepts token from $_POST['csrf_token'], $_REQUEST['csrf_token'],
 * or X-CSRF-Token header (for AJAX).
 * Uses hash_equals for timing-safe compare.
 */
function validate_csrf_token(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($sessionToken === '') {
        return false;
    }
    $token = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? '';
    if ($token === '' && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    return hash_equals($sessionToken, (string)$token);
}

/**
 * Emit a <meta> tag with the CSRF token for JS to pick up.
 * Also useful for forms.
 */
function emit_csrf_meta(): void {
    $token = get_csrf_token();
    echo '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">' . "\n";
}
