/**
 * ABBC3-compatible toolbar extensions for BibleDB notes.
 * Vanilla JS — no jQuery required.
 *
 * Provides: color picker, font-size insert, copy/paste/plain-BBCode helpers.
 * Works alongside phpbb-editor.js and bbcode-toolbar.js (which register
 * bbfontstyle / bbstyle / insert_bbcode / storeCaret on window).
 *
 * The PHP helper render_bbcode_toolbar() emits the toolbar HTML that calls
 * these functions.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Standard web-safe colour palette (matches phpBB's unixsafe palette)
    // ------------------------------------------------------------------
    var COLORS = [
        '#000000','#222222','#444444','#666666','#888888','#aaaaaa','#cccccc','#ffffff',
        '#800000','#ff0000','#ff4500','#ff8c00','#ffa500','#ffd700','#ffff00','#adff2f',
        '#008000','#00ff00','#00fa9a','#00ced1','#00bfff','#1e90ff','#0000ff','#4169e1',
        '#8b0082','#9400d3','#ee82ee','#ff69b4','#ff1493','#c71585','#cd5c5c','#daa520',
    ];

    // ------------------------------------------------------------------
    // Color palette toggle
    // ------------------------------------------------------------------
    window.changePalette = function (id) {
        var el = document.getElementById(id || 'abbc3_palette');
        if (!el) return;
        if (el.style.display === 'block') {
            el.style.display = 'none';
        } else {
            _buildPalette(el);
            el.style.display = 'block';
        }
    };

    window.insertColor = function (hex) {
        var el = document.getElementById('abbc3_palette');
        if (el) el.style.display = 'none';
        if (typeof insert_bbcode === 'function') {
            insert_bbcode('[color=' + hex + ']', '[/color]');
        } else if (typeof bbfontstyle === 'function') {
            bbfontstyle('[color=' + hex + ']', '[/color]');
        }
    };

    function _buildPalette(el) {
        if (el.dataset.built) return;
        el.dataset.built = '1';
        var html = '<div class="abbc3-palette-grid">';
        for (var i = 0; i < COLORS.length; i++) {
            var c = COLORS[i];
            html += '<span class="abbc3-swatch" style="background:' + c
                 + '" onclick="insertColor(\'' + c + '\')" title="' + c + '"></span>';
        }
        html += '</div>';
        el.innerHTML = html;
    }

    // Close palette when clicking outside of it
    document.addEventListener('click', function (e) {
        var el = document.getElementById('abbc3_palette');
        if (!el || el.style.display !== 'block') return;
        if (!e.target.closest('#abbc3_palette') && !e.target.closest('.abbc3-color-btn')) {
            el.style.display = 'none';
        }
    });

    // ------------------------------------------------------------------
    // Font-size insert
    // ------------------------------------------------------------------
    window.abbc3InsertSize = function (sel) {
        var val = sel.options[sel.selectedIndex].value;
        if (!val) return;
        sel.selectedIndex = 0; // reset to placeholder
        if (typeof bbfontstyle === 'function') {
            bbfontstyle('[size=' + val + ']', '[/size]');
        } else if (typeof insert_bbcode === 'function') {
            insert_bbcode('[size=' + val + ']', '[/size]');
        }
    };

    // ------------------------------------------------------------------
    // Copy / Paste / Plain helpers
    // ------------------------------------------------------------------
    var _copyBuffer = '';

    window.bbCopy = function () {
        var ta = _getTA();
        if (!ta) return;
        var sel = ta.value.substring(ta.selectionStart || 0, ta.selectionEnd || 0);
        if (sel) {
            _copyBuffer = sel;
        } else {
            alert('Select some text first, then click Copy.');
        }
    };

    window.bbPaste = function () {
        if (!_copyBuffer) { alert('Nothing to paste — use Copy first.'); return; }
        if (typeof bbfontstyle === 'function') {
            bbfontstyle(_copyBuffer, '');
        } else if (typeof insert_bbcode === 'function') {
            insert_bbcode(_copyBuffer, '');
        }
    };

    window.bbPlain = function () {
        var ta = _getTA();
        if (!ta) return;
        var start = ta.selectionStart || 0;
        var end   = ta.selectionEnd   || 0;
        if (start === end) { alert('Select some text first, then click Strip BBCode.'); return; }
        var sel   = ta.value.substring(start, end);
        var plain = sel.replace(/\[[^\]]*\]/g, '');
        ta.value  = ta.value.substring(0, start) + plain + ta.value.substring(end);
        ta.selectionStart = start;
        ta.selectionEnd   = start + plain.length;
        ta.focus();
    };

    // ------------------------------------------------------------------
    // Internal: resolve the active textarea
    // ------------------------------------------------------------------
    function _getTA() {
        var formName  = window.form_name  || 'postform';
        var fieldName = window.text_name  || 'message';
        var form = document.forms[formName];
        var ta   = form ? form.elements[fieldName] : null;
        if (!ta) {
            ta = document.getElementById('notes-text')
              || document.getElementById('notes-ta')
              || document.querySelector('textarea[name="message"], textarea[name="notes"]');
        }
        return ta || null;
    }

})();
