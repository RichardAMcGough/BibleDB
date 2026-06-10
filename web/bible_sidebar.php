<?php
// Highlight the current page in the sidebar nav
$_bsCurrentPage = basename($_SERVER['PHP_SELF']);
function _bsActive(string $file): string {
    global $_bsCurrentPage;
    return basename($file) === $_bsCurrentPage ? ' class="active"' : '';
}
?>
<aside id="bible-sidebar" class="bible-sidebar">
  <div class="bible-sidebar-inner">
    <p class="bible-sidebar-title">Bible DB</p>
    <nav class="bible-sidebar-nav">
      <div class="sidebar-inline-row">
        <a href="index.php"<?= _bsActive('index.php') ?>>Bible Explorer</a>
        <?php if ($_bsCurrentPage === 'index.php'): ?>
        <a id="sidebar-options-link" class="sidebar-options-inline sidebar-options-icon" href="index.php?open_options=1" aria-label="Display options" title="Display options">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
            <path fill="currentColor" d="M19.14 12.94a7.49 7.49 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.61-.22l-2.39.96a7.4 7.4 0 0 0-1.62-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96a.5.5 0 0 0-.61.22L2.71 8.84a.5.5 0 0 0 .12.64L4.86 11.06a7.5 7.5 0 0 0 0 1.88l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.43.34.68.24l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.26.42.5.42h3.84c.24 0 .45-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.25.1.54 0 .68-.24l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7z"/>
          </svg>
        </a>
        <?php endif; ?>
      </div>
      <a href="help.php"<?= _bsActive('help.php') ?>>Help &amp; What's New</a>
      <?php if ($_bsCurrentPage === 'search.php'): ?>
      <a href="search.php" class="active">Search Results</a>
      <?php endif; ?>
    <?php /* <a href="versecomparison.php"<?= _bsActive('versecomparison.php') ?>>Verse Comparison</a> */ ?>
      <a href="els.php"<?= _bsActive('els.php') ?>>ELS Grid</a>
      <a href="numbers.php"<?= _bsActive('numbers.php') ?>>Number Sequences</a>
    <a href="contiguous.php"<?= _bsActive('contiguous.php') ?>>Contiguous Sums</a>
      <a href="stats.php"<?= _bsActive('stats.php') ?>>Stats</a>
            <?php if (function_exists('bible_notes_enabled') && bible_notes_enabled()): ?>
      <a href="notes.php"<?= _bsActive('notes.php') ?>>My Notes</a>
            <?php endif; ?>
    </nav>
  </div>
</aside>

<div id="bible-sidebar-backdrop"></div>

<script>
(function () {
    'use strict';
    var sidebar  = document.getElementById('bible-sidebar');
    var toggle   = document.getElementById('sidebar-toggle');
    var backdrop = document.getElementById('bible-sidebar-backdrop');
    if (!sidebar || !toggle) return;

    var MOBILE = window.matchMedia('(max-width: 768px)');

    /* Restore desktop collapsed state */
    if (!MOBILE.matches && localStorage.getItem('bible-sidebar-collapsed') === '1') {
        sidebar.classList.add('collapsed');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function openMobile() {
        sidebar.classList.add('open');
        backdrop.classList.add('visible');
        toggle.setAttribute('aria-expanded', 'true');
    }
    function closeMobile() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('visible');
        toggle.setAttribute('aria-expanded', 'false');
    }
    function toggleDesktop() {
        var now = sidebar.classList.toggle('collapsed');
        toggle.setAttribute('aria-expanded', now ? 'false' : 'true');
        localStorage.setItem('bible-sidebar-collapsed', now ? '1' : '0');
    }

    toggle.addEventListener('click', function () {
        MOBILE.matches ? (sidebar.classList.contains('open') ? closeMobile() : openMobile())
                       : toggleDesktop();
    });

    MOBILE.addEventListener('change', function () {
      if (!MOBILE.matches) {
        sidebar.classList.remove('open');
        backdrop.classList.remove('visible');
        toggle.setAttribute('aria-expanded', sidebar.classList.contains('collapsed') ? 'false' : 'true');
      } else {
        toggle.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
      }
    });

    backdrop.addEventListener('click', closeMobile);

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.keyCode === 27) && sidebar.classList.contains('open')) {
            closeMobile();
            toggle.focus();
        }
    });

    // Fallback: if options.js didn't bind its trigger (timing/cache edge case),
    // still allow the sidebar gear link to toggle the display options panel.
    document.addEventListener('click', function (e) {
      if (window.__optionsTriggerBound) return;
      var link = e.target && e.target.closest ? e.target.closest('#sidebar-options-link') : null;
      if (!link) return;
      var panel = document.getElementById('options-panel');
      if (!panel) return;
      e.preventDefault();
      e.stopPropagation();
      if (sidebar.classList.contains('open')) closeMobile();
      var open = panel.hasAttribute('hidden');
      if (open) {
        panel.removeAttribute('hidden');
        link.setAttribute('aria-expanded', 'true');
        link.classList.add('active');
      } else {
        panel.setAttribute('hidden', '');
        link.setAttribute('aria-expanded', 'false');
        link.classList.remove('active');
      }
    });
}());
</script>
