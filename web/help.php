<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
$_help_cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$_help_forum_url = htmlspecialchars(rtrim($_help_cfg['phpbb_url'] ?? '', '/'), ENT_QUOTES, 'UTF-8');
?><?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Help &amp; What's New — Bible Gems</title>
<?php bible_render_layout_styles(); ?>
<style>
  .help-wrap { max-width: 760px; padding: 0 8px 40px; }
  .help-wrap h1 { font-size: 1.5rem; margin-bottom: 4px; }
  /* shrink-wrap the title block and center it over the page; the tagline
     then centers under the title text */
  .help-head { display: table; margin: 0 auto; }
  .help-tagline { font-style: italic; color: var(--muted,#6b7280); margin: 0 0 14px; font-size: 0.95rem; text-align: center; }
  .help-wrap h2 { font-size: 1.15rem; margin: 28px 0 8px; border-bottom: 1px solid var(--border,#ddd); padding-bottom: 4px; }
  .help-wrap h3 { font-size: 1rem; margin: 18px 0 4px; }
  .help-wrap p  { margin: 6px 0 10px; line-height: 1.6; }
  .help-wrap kbd {
      background: #f3f4f6; border: 1px solid #d1d5db; border-bottom-width: 2px;
      border-radius: 4px; padding: 0 5px; font-size: 0.85em; font-family: monospace;
  }
  /* bullet styling that survives the site-wide banner stylesheets */
  .bible-main .help-wrap ul {
      margin: 4px 0 10px 0 !important;
      padding-left: 0 !important;
      line-height: 1.7;
      list-style: none !important;
  }
  .bible-main .help-wrap li {
      position: relative;
      margin: 0 0 2px 0;
      padding: 0 0 0 1.15em !important;
      text-indent: 0 !important;
  }
  .bible-main .help-wrap li::before {
      content: "\2022";
      position: absolute;
      left: 0;
      top: 0;
      color: currentColor;
      line-height: 1.7;
  }
  .beta-banner {
      background: #fff8e1; border: 1px solid #f0c040; border-radius: 6px;
      padding: 12px 16px; margin: 12px 0 24px; font-size: 13px; line-height: 1.6;
  }
  .beta-banner strong { color: #7a5000; }
  .bugfix-jump {
      background: #eef6ff; border: 1px solid #8fb5de; border-radius: 6px;
      padding: 10px 14px; margin: -8px 0 18px; font-size: 13px; line-height: 1.55;
  }
  .bugfix-jump a { color: #174a7a; font-weight: 600; text-decoration: underline; }
  .bugfix-entry { margin: 12px 0 16px; }
  .bugfix-entry h3 { margin-bottom: 6px; }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
<div class="help-wrap">

  <div class="help-head">
    <h1>Bible Gems — Help &amp; What's New</h1>
    <p class="help-tagline">Interlinear Hebrew and Greek Bible with gematria study tools</p>
  </div>

  <div class="beta-banner">
    <strong>⚠ Beta Notice</strong><br>
    Bible Gems is in active development and evolving rapidly. You may occasionally
    encounter missing data, display anomalies, or features that change between
    visits. If something looks wrong, a hard refresh (<kbd>Ctrl+Shift+R</kbd> /
    <kbd>⌘⇧R</kbd>) will clear any cached pages. Please report persistent issues
    via the <a href="<?= $_help_forum_url ?>" style="color:inherit">Bible Wheel forum</a> — your feedback directly shapes what gets fixed or added next.
  </div>

  <div class="bugfix-jump">
    Looking for recent fixes? Jump to the <a href="#bug-fix-log">Bug Fix Log</a> at the bottom of this page.
  </div>

  <!-- ═══════════════════════════════════════════════════════ QUICK START -->
  <h2>Quick Start</h2>
  <ul>
    <li><strong>Navigate</strong> — pick a book, chapter, and verse from the dropdowns at the top of the <a href="index.php">Gem Finder</a>. The <em>+N</em> selector shows additional verses after the selected one.</li>
    <li><strong>Read</strong> — each word appears in a column: gematria value, original text, transliteration, English gloss, Strong's number, and grammar code. Toggle any of these rows from the <strong>⚙ Display options</strong> panel.</li>
    <li><strong>Select words</strong> — click a word to select it; sweep with the left mouse button held to paint a selection. The gematria bar above the text shows running totals with factorizations.</li>
    <li><strong>Group words</strong> — with words selected, press <strong>⊔ group</strong> to bracket them as a labeled group under the text. This is the heart of the gematria toolkit — see <a href="#word-groups">Word Groups</a> below.</li>
    <li><strong>Share</strong> — the <strong>🔗 copy link</strong> button copies a URL that reproduces your exact view: verse range, selected words, groups, labels, and pinned primes.</li>
  </ul>

  <!-- ═══════════════════════════════════════════════════════ WHAT'S NEW -->
  <h2>What's New</h2>

  <h3>June 2026 — Gematria Word Groups</h3>
  <ul>
    <li><strong>Labeled brackets</strong>: select any words and press <strong>⊔ group</strong> to draw a bracket beneath them showing the group's gematria total with its prime factorization (e.g. <em>2701 = 37 × 73</em>).</li>
    <li><strong>Nesting</strong>: groups containing other groups stack outward automatically — build whole structures like Genesis 1:1's 999 / 703 / 1998 / 2701 lattice.</li>
    <li><strong>Non-contiguous groups</strong>: words don't have to be adjacent. Separated runs are tied together by a connector line passing through the value label.</li>
    <li><strong>Above or below the text</strong>: each group can render below (⊔) or above (⊓) the verse. Drag its chip across the divider in the gematria bar to flip it.</li>
    <li><strong>Prime coloring</strong>: any prime appearing in two or more group totals is colored consistently everywhere. Click a prime in a label to pin its color.</li>
    <li><strong>Custom labels</strong>: click a label to edit it (e.g. <em>ONE × GOD = 13 × 86</em>); ↺ restores the computed value.</li>
    <li><strong>Presentation highlight</strong>: click a group's chip to light up its words and label; click again to clear, or click another chip to move the spotlight.</li>
    <li>Everything persists in the page URL — copy the link and your whole arrangement travels with it.</li>
  </ul>

  <h3>June 2026 — Greek NT Critical Texts &amp; Variants</h3>
  <ul>
    <li>NA28 (Nestle-Aland 28th) and TR (Textus Receptus) editions render word-for-word from their source texts.</li>
    <li>Verses with textual variants show an indicator strip beside the verse selector.</li>
    <li>Where variant display is enabled, words carry a clickable bar to switch between readings, and gematria recomputes for the displayed text.</li>
  </ul>

  <h3>June 2026 — User Notes</h3>
  <ul>
    <li>Registered forum users can add notes to any verse using the <strong>+ note</strong> button beside each verse number.</li>
    <li>Notes support BBCode formatting via the same toolbar used on the forum.</li>
    <li>Notes can be tagged by type: General, Bible Wheel, Isaiah-Bible Correlation, or Gematria.</li>
    <li>Gematria notes can have standard, ordinal, and reduced values autofilled from the current word selection or the full verse.</li>
    <li>Guests can view all public notes but cannot write or edit.</li>
  </ul>

  <h3>Earlier 2026 — Core Viewer</h3>
  <ul>
    <li>Interlinear display of Hebrew (OT) and Greek (NT) with transliteration, English gloss, and grammar tags.</li>
    <li>Strong's number tooltips with cross-references and equivalences.</li>
    <li>LXX (Septuagint, Rahlfs) mode for OT books.</li>
    <li>ELS (Equidistant Letter Sequences) grid search.</li>
    <li>Gematria values computed per word and per verse.</li>
    <li>Full-text and grammar search across all editions.</li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════ HELP -->
  <h2>Features Guide</h2>

  <h3>Gem Finder (Bible Viewer)</h3>
  <p>
    The <a href="index.php">Gem Finder</a> shows any verse range from the
    Hebrew OT, Greek NT, or LXX. Use the book / chapter / verse dropdowns to
    navigate, and the <em>+N</em> dropdown to display additional verses after
    the selected one. The edition selector switches between the tagged source
    text, NA28, TR, and (for OT books) the LXX.
  </p>

  <h3>Editions</h3>
  <ul>
    <li><strong>Hebrew OT</strong> — Translators Amalgamated Hebrew OT (TAHOT) via STEPBible.org.</li>
    <li><strong>Greek NT</strong> — Translators Amalgamated Greek NT (TAGNT) via STEPBible.org.</li>
    <li><strong>NA28</strong> — Nestle-Aland 28th edition critical text.</li>
    <li><strong>TR</strong> — Textus Receptus (Scrivener).</li>
    <li><strong>LXX</strong> — Septuagint (Rahlfs edition), available for OT books.</li>
    <li><strong>KJV</strong> — King James Version with inline Strong's numbers.</li>
  </ul>

  <h3>Selecting Words &amp; the Gematria Bar</h3>
  <p>
    Click any word to select or deselect it. Sweep across words with the left
    mouse button held (or hold <kbd>S</kbd> and move the mouse) to paint a
    selection; sweep with the right button (or hold <kbd>D</kbd>) to erase one.
    <kbd>Esc</kbd> clears everything. As you select, the gematria bar above the
    text shows the running standard / ordinal / reduced totals (whichever are
    enabled in options) with live prime factorizations, plus:
  </p>
  <ul>
    <li><strong>× clear</strong> — deselect all words.</li>
    <li><strong>🔗 copy link</strong> — copy a deep link that restores this selection (and any groups) for anyone who opens it.</li>
    <li><strong>⊔ group</strong> — turn the selection into a labeled bracket group.</li>
  </ul>

  <h3 id="word-groups">Word Groups (Brackets)</h3>
  <p>
    Groups annotate the interlinear with labeled brackets, turning a verse into
    a gematria diagram. Select words and press <strong>⊔ group</strong>:
  </p>
  <ul>
    <li><strong>Label</strong> — shows the group total and its factorization. Click the text to type a custom label; <strong>↺</strong> restores the computed value; <strong>×</strong> removes the group.</li>
    <li><strong>Nesting</strong> — a group containing another sits one level farther from the text, so nested structures read naturally.</li>
    <li><strong>Non-contiguous words</strong> — separated runs are joined by a connector through the label, with a small tick at each member word.</li>
    <li><strong>Prime colors</strong> — primes shared by two or more groups are auto-colored. Click any prime in a label to pin (or unpin) its color permanently.</li>
    <li><strong>Chips</strong> — each group gets a chip in the gematria bar (⊔ below-text, ⊓ above-text) ordered to mirror the bracket levels. <em>Click</em> a chip to spotlight its words and label (click again to clear — ideal for presentations). <em>Drag</em> chips to reorder which overlapping group sits closer to the text.</li>
    <li><strong>Above / below</strong> — drag a chip across the vertical divider at the end of the chip list to flip its bracket to the other side of the text.</li>
    <li><strong>Spacing</strong> — the <em>Group rows</em> slider in Display options adjusts the vertical pitch of the bracket stack.</li>
    <li><strong>Sharing</strong> — groups, labels, sides, and pinned primes are all encoded in the URL; the 🔗 button includes them automatically.</li>
  </ul>

  <h3>Display Options</h3>
  <p>
    Open with the <strong>⚙</strong> button (or <em>Display options</em> in the
    sidebar). Options persist in your browser between visits:
  </p>
  <ul>
    <li><strong>Gematria</strong> — toggle Standard, Ordinal, and Reduced values; pick the gematria color.</li>
    <li><strong>Word rows</strong> — show/hide transliteration, English, Strong's, grammar.</li>
    <li><strong>Verse text</strong> — assembled original / English verse lines; one verse per line ("Newlines"); full-width layout.</li>
    <li><strong>Sizes</strong> — font sizes for every row, word spacing, and bracket row spacing.</li>
  </ul>

  <h3>Variants (NA28 / TR)</h3>
  <p>
    Verses with textual variants show an indicator strip beside the verse
    selector. Where variant bars are enabled, a thin bar under a word means
    alternate readings exist — click it to switch readings; gematria totals
    follow the displayed text.
  </p>

  <h3>Search</h3>
  <p>
    Use the search box above the verse text (or the banner) to search by
    reference (<em>Jhn 3:16</em>), English word or phrase, Hebrew, Greek, or
    Strong's number (<em>H0430</em>, <em>G3056</em>). Phrase mode supports
    wildcards (e.g. <em>יהוה אלהי*</em>), and results report both verse counts
    and total occurrences.
  </p>

  <h3>Study Tools (sidebar)</h3>
  <ul>
    <li><strong>ELS Grid</strong> — search for equidistant letter sequences in the Hebrew or Greek text at any skip interval, displayed on an adjustable grid.</li>
    <li><strong>Number Sequences</strong> — explore figurate numbers (triangles, hexagons, stars…) and their relationships to text values.</li>
    <li><strong>Contiguous Sums</strong> — find runs of adjacent words summing to a target value anywhere in Scripture.</li>
    <li><strong>Stats</strong> — corpus-wide statistics.</li>
  </ul>

  <h3>Verse Notes</h3>
  <p>
    Click <strong>+ note</strong> beside any verse number to read or write notes
    on that verse. Notes are public commentary visible to all visitors (authors
    can also keep a note private). You must be logged in to your Bible Wheel
    forum account to write. The count badge next to the verse number shows how
    many notes exist. <em>My Notes</em> in the sidebar lists everything you have
    written, with links back to each verse.
  </p>

  <h3>Account / Log In</h3>
  <p>
    Bible Gems uses your existing Bible Wheel forum account — no separate
    registration needed. Click the <strong>Log in</strong> link in the
    top-right corner or visit the
    <a href="<?= $_help_forum_url ?>">Bible Wheel Forum</a> to sign in.
    Once logged in, reload the page and your username will appear in the banner.
  </p>

  <!-- ═══════════════════════════════════════════ MOUSE & KEYBOARD REFERENCE -->
  <h2>Mouse &amp; Keyboard Reference</h2>
  <ul>
    <li><strong>Click word</strong> — toggle selection.</li>
    <li><strong>Left-drag</strong> (or hold <kbd>S</kbd> + move) — paint-select words.</li>
    <li><strong>Right-drag</strong> (or hold <kbd>D</kbd> + move) — paint-deselect words.</li>
    <li><kbd>Esc</kbd> — clear the selection.</li>
    <li><strong>Click group label</strong> — edit the label text (<kbd>Enter</kbd> commits, <kbd>Esc</kbd> cancels).</li>
    <li><strong>Click prime in label</strong> — pin/unpin its theme color.</li>
    <li><strong>Click group chip</strong> — spotlight the group on/off.</li>
    <li><strong>Drag group chip</strong> — reorder groups; across the divider to flip above/below the text.</li>
  </ul>

  <!-- ═════════════════════════════════════════════════════ BUG FIX LOG -->
  <h2 id="bug-fix-log">Bug Fix Log</h2>

  <div class="bugfix-entry">
    <h3>June 2026 — Group Bracket Layout</h3>
    <ul>
      <li>Adjacent groups now share a bracket row; only true overlaps stack.</li>
      <li>Overlapping non-contiguous groups (including a group inside another group's gap) now stack correctly instead of colliding with the connector label.</li>
      <li>Verse numbers stay vertically centered when interlinear rows are shown or hidden.</li>
    </ul>
  </div>

  <div class="bugfix-entry">
    <h3>June 2026 — NA28/TR Variant Display</h3>
    <ul>
      <li>NA28 and TR now render directly from their source texts, fixing word-order and substitution inconsistencies.</li>
      <li>Variant toggling and gematria marker handling corrected for critical-text editions.</li>
    </ul>
  </div>

  <div class="bugfix-entry">
    <h3>June 2026 — Mobile Layout + Sidebar Drawer</h3>
    <ul>
      <li>Restored responsive wrapping for the verse header controls (including prev/next links and search row) to prevent horizontal overflow on narrow screens.</li>
      <li>Fixed mobile sidebar drawer width/position so it opens to the correct visible width and aligns to the viewport edge.</li>
      <li>Re-enabled smooth drawer slide animation on mobile while keeping the corrected width and edge alignment behavior.</li>
    </ul>
  </div>

  <div class="bugfix-entry">
    <h3>June 2026 — Search Wildcard Occurrence Totals</h3>
    <ul>
      <li>Fixed phrase wildcard occurrence counting so totals are no longer assumed to equal verse count.</li>
      <li>English phrase wildcard and single-word wildcard searches now count actual token matches across matched verses.</li>
      <li>Hebrew and Greek phrase wildcard searches now count real wildcard phrase matches in normalized verse text.</li>
      <li>Example resolved: phrase search <strong>יהוה אלהי*</strong> now reports distinct verse count and total occurrence count correctly.</li>
    </ul>
  </div>

</div>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
</body>
</html>
