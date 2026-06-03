<?php
// My Notes — list of verse_notes for the current user (or all notes for admins).

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
        Notes require a local database and a logged-in user. Disable
        <code>use_remote_api</code> in <code>web/config.php</code> and configure
        a local database + optional <code>phpbb_path</code> to use this feature.
    </div>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
</body>
</html>
<?php
    exit;
}

$user  = get_bible_user();
$notes = (!$user['is_guest'] && !empty($user['id'])) ? get_user_verse_notes($user) : [];
$is_admin = !empty($user['is_admin']);
$_cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$_phpbb_url = trim($_cfg['phpbb_url'] ?? '');
$_login_url = $_phpbb_url !== '' ? htmlspecialchars(rtrim($_phpbb_url, '/') . '/ucp.php?mode=login', ENT_QUOTES, 'UTF-8') : '';
?>
<?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title><?= $is_admin ? 'All Notes' : 'My Notes' ?> — Bible Wheel</title>
<?php bible_render_layout_styles(); ?>
<style>
  .notes-table { width:100%; border-collapse:collapse; font-size:0.93rem; }
  .notes-table th { text-align:left; background:var(--bg-alt,#f5f5f5); color:#333; font-weight:600;
                    padding:6px 10px; border-bottom:2px solid var(--border,#ddd); }
  .notes-table td { padding:7px 10px; border-bottom:1px solid var(--border,#eee); vertical-align:top; }
  .notes-table tr:hover td { background:var(--bg-alt,#f9f9f9); }
  .notes-table a { color:var(--link,#1a73e8); text-decoration:none; }
  .notes-table a:hover { text-decoration:underline; }
  .notes-ref { white-space:nowrap; }
  .notes-types { color:#555; font-size:0.85rem; }
  .note-priv-badge { font-size:0.8rem; }
  .notes-date { white-space:nowrap; color:#777; font-size:0.85rem; }
  .notes-empty { color:#666; font-style:italic; margin:20px 0; }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
  <h1><?= $is_admin ? 'All Verse Notes' : 'My Verse Notes' ?></h1>

  <?php if ($user['is_guest']): ?>
    <?php if ($_login_url !== ''): ?>
      <p>Please <a href="<?= $_login_url ?>">log in</a> to see your notes.</p>
    <?php else: ?>
      <p>Please log in to see your notes.</p>
    <?php endif; ?>
  <?php elseif (empty($notes)): ?>
    <p class="notes-empty">No notes yet &mdash; click <strong>+ note</strong> on any verse to add one.</p>
  <?php else: ?>
    <p><?= count($notes) ?> note<?= count($notes) !== 1 ? 's' : '' ?></p>
    <table class="notes-table">
      <thead>
        <tr>
          <th>Verse</th>
          <th>Title</th>
          <th>Types</th>
          <?php if ($is_admin): ?><th>Author</th><?php endif; ?>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($notes as $n):
          $href = 'index.php?book=' . urlencode($n['book_code'])
                . '&chapter=' . (int)$n['chapter']
                . '&verse='   . (int)$n['verse'];
          $ref = h($n['book_code']) . ' ' . (int)$n['chapter'] . ':' . (int)$n['verse'];
      ?>
        <tr>
          <td class="notes-ref"><a href="<?= $href ?>"><?= $ref ?></a></td>
          <td>
            <?php if ($is_admin && !$n['is_public']): ?>
              <span class="note-priv-badge" title="Private">&#x1F512;</span>
            <?php endif; ?>
            <a href="<?= $href ?>"><?= h($n['title'] ?: '(untitled)') ?></a>
          </td>
          <td class="notes-types"><?= h($n['types_label']) ?></td>
          <?php if ($is_admin): ?><td><?= h($n['username']) ?></td><?php endif; ?>
          <td class="notes-date"><?= htmlspecialchars(substr($n['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
</body>
</html>


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
