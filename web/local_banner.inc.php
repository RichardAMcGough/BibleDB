<?php
// local_banner.inc.php
// Minimal standalone banner for development / remote API mode.
$_bub_user = function_exists('get_bible_user') ? get_bible_user() : ['name' => 'Dev', 'id' => 999999, 'is_guest' => false];
$_bub_dev  = ((int)$_bub_user['id'] === 999999);
$_bub_name = htmlspecialchars($_bub_user['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
// Only show badge if phpBB is configured or show_user_badge is explicitly enabled.
$_bub_cfg       = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$_bub_show      = (trim($_bub_cfg['phpbb_path'] ?? '') !== '')
               || (trim($_bub_cfg['phpbb_url'] ?? '') !== '')
               || !empty($_bub_cfg['show_user_badge']);
$_local_menu_links = [
  ['label' => 'Home',         'href' => 'https://biblewheel.com/wheel/intro.php'],
  ['label' => 'Bible Wheel',  'href' => 'https://biblewheel.com/wheel/intro.php'],
  ['label' => 'Canon Wheel',  'href' => 'https://biblewheel.com/wheel/canon.php'],
  ['label' => 'Christian Art','href' => 'https://biblewheel.com/art/'],
  ['label' => 'Bible DB',     'href' => 'https://biblewheel.com/bible/index.php'],
  ['label' => 'Forum',        'href' => 'https://biblewheel.com/phpbb'],
];
?>
<div class="local-dev-banner">
  <div class="local-dev-left">
    <a class="local-wheel-icon" href="https://biblewheel.com/wheel/intro.php" aria-label="Bible Wheel Home" title="Bible Wheel Home">
      <img class="local-wheel-img" src="https://biblewheel.com/newwp/wp-content/uploads/2025/12/snowyday_brighter-96x96.png" alt="Bible Wheel">
    </a>
  </div>
  <div class="local-dev-center">
    <nav class="local-site-menu local-site-menu-inline" aria-label="Site menu">
      <?php foreach ($_local_menu_links as $_lm): ?>
      <a href="<?= htmlspecialchars($_lm['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($_lm['label'], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="local-site-menu-dropdown-wrap">
      <button id="local-site-menu-toggle" type="button" class="local-site-menu-toggle" aria-expanded="false" aria-controls="local-site-menu-panel">Menu ▾</button>
      <nav id="local-site-menu-panel" class="local-site-menu local-site-menu-panel" aria-label="Collapsed site menu" hidden>
        <?php foreach ($_local_menu_links as $_lm): ?>
        <a href="<?= htmlspecialchars($_lm['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($_lm['label'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>
  <div class="local-dev-actions">
    <?php if ($_bub_show): ?>
    <div class="bible-user-badge<?= $_bub_dev ? ' bible-user-badge--dev' : '' ?>" title="Logged in as <?= $_bub_name ?>">
      <i class="fa fa-user-circle" aria-hidden="true"></i>
      <span class="bible-user-name"><?= $_bub_name ?></span>
    </div>
    <?php endif; ?>
    <button id="sidebar-toggle" type="button" aria-expanded="true" aria-label="Toggle contents" title="Contents">Contents ☰</button>
  </div>
</div>

<script>
(function () {
  'use strict';
  var btn = document.getElementById('local-site-menu-toggle');
  var panel = document.getElementById('local-site-menu-panel');
  if (!btn || !panel) return;

  function closePanel() {
    panel.setAttribute('hidden', 'hidden');
    btn.setAttribute('aria-expanded', 'false');
  }

  function openPanel() {
    panel.removeAttribute('hidden');
    btn.setAttribute('aria-expanded', 'true');
  }

  btn.addEventListener('click', function () {
    if (panel.hasAttribute('hidden')) openPanel();
    else closePanel();
  });

  document.addEventListener('click', function (evt) {
    if (!panel.hasAttribute('hidden') && !panel.contains(evt.target) && evt.target !== btn) {
      closePanel();
    }
  });

  document.addEventListener('keydown', function (evt) {
    if (evt.key === 'Escape' && !panel.hasAttribute('hidden')) {
      closePanel();
      btn.focus();
    }
  });
}());
</script>
