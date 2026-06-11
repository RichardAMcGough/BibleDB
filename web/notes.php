<?php
// My Notes — list of verse_notes for the current user (or all notes for admins).

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (!bible_notes_enabled()) {
  ?><?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Notes — Bible Gems</title>
<?php bible_render_layout_styles(); ?>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
  <h1>My Notes</h1>
  <div class="verse-card empty">
    Notes are currently disabled by site configuration.
  </div>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
</body>
</html>
<?php
  exit;
}

if (should_use_remote_api()) {
    ?><?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Notes — Bible Gems</title>
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
$is_admin = !empty($user['is_admin']);

$sort = strtolower(trim((string)($_GET['sort'] ?? '')));
if (!in_array($sort, ['recent', 'oldest', 'verse'], true)) {
  $sort = $is_admin ? 'recent' : 'verse';
}
$author_filter = (int)($_GET['author'] ?? 0);
if ($author_filter < 0) $author_filter = 0;

$authors = (!$user['is_guest'] && !empty($user['id']) && $is_admin) ? get_note_authors() : [];
$notes = [];
if (!$user['is_guest'] && !empty($user['id'])) {
  $notes = get_user_verse_notes($user, [
    'sort' => $sort,
    'author_user_id' => ($is_admin && $author_filter > 0) ? $author_filter : null,
  ]);
}

$_cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$_login_url = bible_phpbb_login_url($_cfg['phpbb_url'] ?? '');
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
  .notes-filters { margin:8px 0 14px; padding:8px 10px; border:1px solid var(--border,#ddd); border-radius:6px; background:var(--bg-alt,#f9f9f9); display:flex; flex-wrap:wrap; gap:10px 14px; align-items:end; }
  .notes-filters label { font-size:0.85rem; color:#444; display:flex; flex-direction:column; gap:4px; }
  .notes-filters select { min-width:180px; }
  .notes-filters button { padding:4px 10px; }
</style>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
  <h1><?= $is_admin ? 'All Verse Notes' : 'My Verse Notes' ?></h1>

  <?php if (!$user['is_guest'] && $is_admin): ?>
    <form method="get" action="notes.php" class="notes-filters">
      <label>
        Sort
        <select name="sort">
          <option value="recent"<?= $sort === 'recent' ? ' selected' : '' ?>>Most Recent</option>
          <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>Oldest First</option>
          <option value="verse"<?= $sort === 'verse' ? ' selected' : '' ?>>By Verse</option>
        </select>
      </label>
      <label>
        Author
        <select name="author">
          <option value="0">All Authors</option>
          <?php foreach ($authors as $a): ?>
            <option value="<?= (int)$a['user_id'] ?>"<?= $author_filter === (int)$a['user_id'] ? ' selected' : '' ?>>
              <?= h($a['username']) ?> (<?= (int)$a['note_count'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">Apply</button>
    </form>
  <?php endif; ?>

  <?php if ($user['is_guest']): ?>
    <?php if ($_login_url !== ''): ?>
      <p>Please <a href="<?= h($_login_url) ?>">log in</a> to see your notes.</p>
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
          $sel = trim((string)($n['selected_words'] ?? ''));
          if ($sel !== '') {
            $href .= '&selected=' . urlencode($sel);
          }
            $ed = trim((string)($n['edition_code'] ?? ''));
            if ($ed !== '') {
              $href .= '&edition=' . urlencode($ed);
            }
          $title_href = $href . '&open_notes=1&focus_note=' . (int)$n['id'];
          $ref = h($n['book_code']) . ' ' . (int)$n['chapter'] . ':' . (int)$n['verse'];
      ?>
        <tr>
          <td class="notes-ref"><a href="<?= $href ?>"><?= $ref ?></a></td>
          <td>
            <?php if ($is_admin && !$n['is_public']): ?>
              <span class="note-priv-badge" title="Private">&#x1F512;</span>
            <?php endif; ?>
            <a href="<?= $title_href ?>"><?= h($n['title'] ?: '(untitled)') ?></a>
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
