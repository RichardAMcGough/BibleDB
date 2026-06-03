/**
 * BBCode toolbar adapter for BibleDB notes (verse notes modal + personal notes.php).
 *
 * Goals:
 * - Provide the *exact same insertion behavior* as phpBB when we load phpBB's editor.js.
 * - Work standalone (vanilla JS fallbacks) when the phpBB script is not (or not yet) loaded.
 * - Support the safe subset that our bbcode_to_html renderer understands.
 *
 * Usage on a page:
 *   <script>
 *     var form_name = 'notesform';
 *     var text_name = 'message';   // must match the textarea's *name* attribute
 *     var bbtags = ['[b]','[/b]','[i]','[/i]', ... ]; // optional, extends stock
 *   </script>
 *   <script src="js/phpbb-editor.js"></script>   <!-- the vendored phpBB control -->
 *   <script src="js/bbcode-toolbar.js"></script>
 *
 * Then in the form:
 *   <?php echo render_bbcode_toolbar('notesform', 'message'); ?>
 *   <textarea name="message" id="..." onfocus="initInsertions();" onclick="storeCaret(this);" ...>
 */

(function () {
  'use strict';

  // Ensure the globals the phpBB editor expects exist (pages set form_name / text_name before loading scripts).
  window.form_name = window.form_name || 'postform';
  window.text_name = window.text_name || 'message';

  // Extend/augment bbtags so stock bbstyle(N) works for the buttons we render.
  // phpBB stock order (from their posting_buttons.html):
  // 0 b, 2 i, 4 u, 6 quote, 8 code, 10 list, 12 list=, 14 img, 16 url, 18 flash, 20 size
  // We add [s] at a safe high index and make sure the array is long enough.
  if (!Array.isArray(window.bbtags)) {
    window.bbtags = [];
  }
  // Fill gaps if the real editor.js or the page didn't populate it.
  var defaults = ['[b]','[/b]','[i]','[/i]','[u]','[/u]','[quote]','[/quote]','[code]','[/code]','[list]','[/list]','[list=]','[/list]','[img]','[/img]','[url]','[/url]'];
  for (var i = 0; i < defaults.length; i++) {
    if (!window.bbtags[i]) window.bbtags[i] = defaults[i];
  }
  // [s] support (our renderer handles it). Use a high index so we don't collide.
  window.bbtags[22] = '[s]';
  window.bbtags[23] = '[/s]';

  /**
   * Safe wrapper that works whether or not phpBB's editor.js has been loaded.
   * Pages (or render_bbcode_toolbar) can use onclick="insert_bbcode('[b]', '[/b]')"
   * for tags that are outside the numeric bbtags table (e.g. [s]).
   */
  window.insert_bbcode = function (open, close) {
    if (typeof bbfontstyle === 'function') {
      // Real phpBB function is present (we loaded phpbb-editor.js and set the globals).
      bbfontstyle(open, close);
      return;
    }
    // Fallback (vanilla, good enough caret handling for modern browsers)
    var form = document.forms[window.form_name];
    var ta = form ? form.elements[window.text_name] : null;
    if (!ta) {
      // last resort: try common ids used in our pages
      ta = document.getElementById('notes-text') || document.getElementById('notes-ta') || document.querySelector('textarea');
    }
    if (!ta) return;

    var start = ta.selectionStart || 0;
    var end = ta.selectionEnd || 0;
    var sel = ta.value.substring(start, end);
    var before = ta.value.substring(0, start);
    var after = ta.value.substring(end);

    var insert = open + (sel || 'text') + close;
    ta.value = before + insert + after;

    var newPos = start + open.length + (sel ? sel.length : 'text'.length);
    ta.focus();
    if (typeof ta.setSelectionRange === 'function') {
      ta.setSelectionRange(newPos, newPos);
    }
  };

  /**
   * If the real phpBB bbstyle is not present when a button calls it (e.g. timing or
   * the script didn't load), provide a delegating fallback.
   */
  if (typeof window.bbstyle !== 'function') {
    window.bbstyle = function (bbnumber) {
      if (bbnumber === -1) {
        window.insert_bbcode('[*]', '');
        return;
      }
      if (Array.isArray(window.bbtags) && window.bbtags[bbnumber] && window.bbtags[bbnumber + 1]) {
        window.insert_bbcode(window.bbtags[bbnumber], window.bbtags[bbnumber + 1]);
      } else {
        // Unknown index – try s at 22/23 as a special case
        if (bbnumber === 22) window.insert_bbcode('[s]', '[/s]');
      }
    };
  }

  /**
   * Also provide a bbfontstyle fallback (used by some ABBC3 paths and our s shim).
   */
  if (typeof window.bbfontstyle !== 'function') {
    window.bbfontstyle = function (open, close) {
      window.insert_bbcode(open, close);
    };
  }

  // Expose a tiny helper the modal / pages can call after showing a textarea
  // if they want to ensure caret tracking (matches phpBB template attributes).
  window.initNotesTextarea = function (ta) {
    if (!ta) return;
    if (typeof initInsertions === 'function') {
      try { initInsertions(); } catch (e) {}
    }
    // The attributes on the tag already call storeCaret on the events.
  };

  // Optional: if a toolbar is present, clicking a button can ensure focus on its textarea.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.bbcode-toolbar button, .format-buttons button, .abbc3-toolbar button');
    if (!btn) return;
    var bar = btn.closest('.bbcode-toolbar, .format-buttons, .abbc3-toolbar');
    if (!bar) return;
    var formName = bar.getAttribute('data-form') || window.form_name;
    var fieldName = bar.getAttribute('data-field') || window.text_name;
    var form = document.forms[formName];
    var ta = form ? form.elements[fieldName] : null;
    if (ta) {
      // Defer focus so the bbstyle/insert sees the right selection
      setTimeout(function () { ta.focus(); }, 0);
    }
  });

})();
