<?php
require_once __DIR__ . '/helpers.php';
?><?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Help &amp; What's New — Bible Wheel</title>
<?php bible_render_layout_styles(); ?>
<style>
  .help-wrap { max-width: 760px; padding: 0 8px 40px; }
  .help-wrap h1 { font-size: 1.5rem; margin-bottom: 4px; }
  .help-wrap h2 { font-size: 1.15rem; margin: 28px 0 8px; border-bottom: 1px solid var(--border,#ddd); padding-bottom: 4px; }
  .help-wrap h3 { font-size: 1rem; margin: 18px 0 4px; }
  .help-wrap p  { margin: 6px 0 10px; line-height: 1.6; }
  .help-wrap ul { margin: 4px 0 10px 20px; line-height: 1.7; }
  .beta-banner {
      background: #fff8e1; border: 1px solid #f0c040; border-radius: 6px;
      padding: 12px 16px; margin: 12px 0 24px; font-size: 13px; line-height: 1.6;
  }
  .beta-banner strong { color: #7a5000; }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
<div class="help-wrap">

  <h1>Bible DB — Help &amp; What's New</h1>

  <div class="beta-banner">
    <strong>⚠ Beta Notice</strong><br>
    Bible DB is in active development and evolving rapidly. You may occasionally
    encounter missing data, display anomalies, or features that change between
    visits. If something looks wrong, a hard refresh (<kbd>Ctrl+Shift+R</kbd> /
    <kbd>⌘⇧R</kbd>) will clear any cached pages. Please report persistent issues
    via the forum — your feedback directly shapes what gets fixed or added next.
  </div>

  <!-- ═══════════════════════════════════════════════════════ WHAT'S NEW -->
  <h2>What's New</h2>

  <h3>June 2026 — User Notes</h3>
  <ul>
    <li>Registered forum users can now add personal notes to any verse using the <strong>+ note</strong> button beside each verse number.</li>
    <li>Notes support BBCode formatting via the same toolbar used on the forum.</li>
    <li>Notes can be tagged by type: General, Bible Wheel, Isaiah-Bible Correlation, or Gematria.</li>
    <li>Gematria notes can have standard, ordinal, and reduced values autofilled from the current text selection or the full verse.</li>
    <li>A <strong>Log in</strong> link now appears in the top-right corner for guests.</li>
    <li>Guests can view all existing notes but cannot write or edit.</li>
  </ul>

  <h3>Earlier 2026 — Core Viewer</h3>
  <ul>
    <li>Interlinear display of Hebrew (OT) and Greek (NT) with transliteration, English gloss, and grammar tags.</li>
    <li>Strong's number tooltips with cross-references and equivalences.</li>
    <li>LXX (Septuagint) mode for OT books.</li>
    <li>ELS (Equidistant Letter Sequences) grid search.</li>
    <li>Gematria values computed per word and per verse.</li>
    <li>Full-text and grammar search across all editions.</li>
    <li>Verse view statistics page.</li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════ HELP -->
  <h2>Features Guide</h2>

  <h3>Bible Viewer</h3>
  <p>
    The main viewer shows any verse range from the Hebrew OT, Greek NT, or LXX.
    Use the book / chapter / verse dropdowns at the top to navigate. Each word
    is clickable and opens a detail panel with its Strong's entry, grammar code,
    transliteration, and related words.
  </p>

  <h3>Editions</h3>
  <ul>
    <li><strong>Hebrew OT</strong> — Translators Amalgamated Hebrew OT (TAHOT) via STEPBible.org.</li>
    <li><strong>Greek NT</strong> — Translators Amalgamated Greek NT (TAGNT) via STEPBible.org.</li>
    <li><strong>LXX</strong> — Septuagint (Rahlfs edition), available for OT books.</li>
    <li><strong>KJV</strong> — King James Version with inline Strong's numbers.</li>
  </ul>

  <h3>Strong's Tooltips</h3>
  <p>
    Hover over any Strong's number (shown in the word detail panel or KJV view)
    to see a quick definition. Click to open the full entry.
  </p>

  <h3>Gematria</h3>
  <p>
    Standard, ordinal, and reduced gematria values are shown per word and
    summed per verse. Select any range of words with the mouse to see the
    combined gematria of your selection in the notes form autofill.
  </p>

  <h3>Search</h3>
  <p>
    Use the search box in the banner to search by English, Hebrew, Greek, or
    Strong's number across all editions simultaneously.
  </p>

  <h3>ELS Grid</h3>
  <p>
    The ELS page lets you search for equidistant letter sequences in the
    Hebrew or Greek text at any skip interval.
  </p>

  <h3>Verse Notes</h3>
  <p>
    Click the <strong>+ note</strong> button beside any verse number to open the
    notes panel. You must be logged in to your Bible Wheel forum account to
    write notes. Existing notes on a verse are visible to all visitors.
  </p>

  <h3>My Notes</h3>
  <p>
    The <em>My Notes</em> sidebar link shows all notes you have written, with
    links back to the corresponding verse.
  </p>

  <h3>Account / Log In</h3>
  <p>
    Bible DB uses your existing Bible Wheel forum account — no separate
    registration needed. Click the <strong>Log in</strong> link in the
    top-right corner or visit the
    <a href="https://www.biblewheel.com/forum/">Bible Wheel Forum</a> to sign in.
    Once logged in, reload this page and your username will appear in the banner.
  </p>

</div>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
</body>
</html>
