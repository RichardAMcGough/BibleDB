<?php
// local_banner.inc.php
// Minimal standalone banner for development / remote API mode.
$_bub_user = function_exists('get_bible_user') ? get_bible_user() : ['name' => 'Dev', 'id' => 999999, 'is_guest' => false];
$_bub_dev  = ((int)$_bub_user['id'] === 999999);
$_bub_name = htmlspecialchars($_bub_user['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="local-dev-banner">
  <span><strong>BibleDB</strong> <small style="opacity:.7">— dev mode</small></span>
  <div class="bible-user-badge<?= $_bub_dev ? ' bible-user-badge--dev' : '' ?>" title="Logged in as <?= $_bub_name ?>">
    <i class="fa fa-user-circle" aria-hidden="true"></i>
    <span class="bible-user-name"><?= $_bub_name ?></span>
  </div>
</div>
