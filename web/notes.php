<?php
// Personal Notes / Notebook — per-user BBCode formatted text, stored in DB.
// Works only in local DB mode. User identity comes from phpBB (if configured)
// or a dev session fallback.

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (should_use_remote_api()) {
    ?><?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Notes — Bible Browser</title>
<?php bible_render_layout_styles(); ?>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
    <h1>My Notes</h1>
    <div class="verse-card empty">
        Personal notes are not available in remote API mode (they require a local database
        and a logged-in user). Disable <code>use_remote_api</code> in <code>web/config.php</code>
        and configure a local database + optional <code>phpbb_path</code> to use this feature.
    </div>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
</body>
</html>
<?php
    exit;
}

$user = get_bible_user();
$raw_notes = get_user_notes();
$cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notes'])) {
    if (!validate_csrf_token()) {
        $error = 'Security token mismatch (CSRF). Please reload the page and try again.';
    } else {
        $new_notes = (string)($_POST['notes'] ?? '');
        if (save_user_notes($new_notes)) {
            $raw_notes = $new_notes;
            $saved = true;
            // Re-fetch to be sure
            $raw_notes = get_user_notes();
        } else {
            $error = 'Failed to save notes. Please try again.';
        }
    }
}

$rendered = bbcode_to_html($raw_notes);
?>
<?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<?php emit_csrf_meta(); ?>
<title>My Notes — Bible Wheel</title>
<?php bible_render_layout_styles(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>
  .notes-page {
    /* Fixed to viewport — immune to any ancestor max-width or overflow constraint */
    position: fixed;
    inset: 0;
    padding: 20vh 20vw;
    overflow-y: auto;
    background: var(--bg, #fafafa);
    box-sizing: border-box;
    z-index: 100;
  }
  .notes-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:8px; }
  .notes-user { font-size:0.95rem; color:#555; }
  .notes-render {
    background:#fff; border:1px solid var(--border,#ddd); border-radius:6px;
    padding:16px 18px; min-height:200px; line-height:1.5; margin-bottom:16px;
    white-space:pre-wrap;
  }
  .notes-render blockquote { margin:8px 0; padding:8px 12px; border-left:3px solid #aaa; background:#f9f9f9; }
  .notes-render pre { background:#f4f4f4; padding:8px; overflow:auto; }
  .notes-form textarea {
    width:100%; min-height:40vh; font-family: ui-monospace, monospace; font-size:14px;
    padding:10px; border:1px solid var(--border,#ddd); border-radius:4px; resize:vertical;
    box-sizing:border-box;
  }
  .notes-actions { margin-top:8px; display:flex; gap:8px; align-items:center; }
  .notes-saved { color:green; font-size:0.9rem; }
  .notes-error { color:#b00; font-size:0.9rem; }
  .notes-help { font-size:0.8rem; color:#666; margin-top:6px; }
  .notes-help code { background:#f4f4f4; padding:1px 3px; border-radius:2px; }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="notes-page">
  <div class="notes-header">
    <h1>My Notes</h1>
    <div class="notes-user">
      <?php if ($user['is_guest']): ?>
        (guest / demo)
      <?php else: ?>
        for <strong><?= h($user['name']) ?></strong> (id <?= (int)$user['id'] ?>)
      <?php endif; ?>
    </div>
  </div>

  <?php if ($saved): ?>
    <div class="notes-saved">Notes saved successfully.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="notes-error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="notes-render">
    <?php if (trim($raw_notes) === ''): ?>
      <em style="color:#888">Your personal notebook is empty. Use the editor below to add formatted notes (BBCode supported).</em>
    <?php else: ?>
      <?= $rendered ?>
    <?php endif; ?>
  </div>

  <h2 style="font-size:1rem; margin:16px 0 4px;">Edit Notes</h2>

  <?php
    // Setup for phpBB-compatible editor (we load the real insertion logic from the vendored phpBB editor.js).
    // This gives identical caret/selection behavior to composing a forum post.
  ?>
  <script>
    var form_name = 'notesform';
    var text_name = 'notes';
    // bbtags extended with [s] support at a high index; the toolbar buttons use bbstyle(N) or insert_bbcode().
  </script>
  <script src="js/phpbb-editor.js"></script>
  <script src="js/bbcode-toolbar.js"></script>
  <script src="js/abbc3-toolbar.js"></script>

  <form method="post" name="notesform" action="notes.php">
    <?= render_bbcode_toolbar('notesform', 'notes') ?>
    <input type="hidden" name="csrf_token" value="<?= h(get_csrf_token()) ?>">
    <textarea name="notes" id="notes-ta" placeholder="Enter your notes here using BBCode..." onfocus="if(typeof initInsertions==='function')initInsertions();" onclick="if(typeof storeCaret==='function')storeCaret(this);" onkeyup="if(typeof storeCaret==='function')storeCaret(this);"><?= h($raw_notes) ?></textarea>
    <div class="notes-actions">
      <button type="submit" name="save_notes" value="1" class="btn">Save Notes</button>
      <span class="notes-help">
        Supports: <code>[b]</code> <code>[i]</code> <code>[u]</code> <code>[s]</code>
        <code>[color=#hex]</code> <code>[size=100]</code>
        <code>[quote]</code> <code>[quote=Name]</code> <code>[code]</code>
        <code>[list][*]item[/list]</code> <code>[url]</code> <code>[url=http://...]</code> <code>[img]</code>
        &nbsp;• Newlines become &lt;br&gt;
      </span>
    </div>
  </form>

  <p class="notes-help">
    Notes are private to your account and stored in the local BibleDB. 
    <?php if (empty($cfg['phpbb_path'] ?? '')): ?>
      Currently using dev/demo user (configure <code>phpbb_path</code> in config.php for real phpBB login).
    <?php endif; ?>
  </p>
  <p class="notes-help" style="margin-top:8px;">
    The editor toolbar and insertion logic are the same ones used by phpBB (when available) so the feel matches your forum posts.
  </p>
</div>
</body>
</html>
