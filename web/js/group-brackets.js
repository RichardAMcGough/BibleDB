// group-brackets.js — labeled underline brackets for gematria word groups.
// Stacked-underbrace rendering: vertical depth encodes nesting; primes shared
// by 2+ groups are auto-colored (pinnable); labels are click-to-edit; state
// persists in ?groups= / ?gpin= URL params (data-pos ordinals, same convention
// as deep-link.js's ?selected=). See docs/group-brackets-design.md.
(function () {
    'use strict';

    var interlinear = document.getElementById('interlinear');
    if (!interlinear) return;

    var PRIME_COLORS = ['#D85A30', '#1D9E75', '#534AB7', '#D4537E', '#BA7517', '#378ADD'];
    var BRACKET_H = 8;    // bracket glyph height (px)
    var ROW_H     = 28;   // bracket + label row pitch (px)
    var GAP_TOP   = 5;    // gap between text line bottom and first bracket row
    var BASE_GAP  = 14;   // matches .interlinear row gap in style.css

    var groups  = [];     // { id, positions:[int asc], label:null|string }
    var pins    = [];     // user-pinned primes
    var nextId  = 1;
    var layer   = null;
    var editing = false;

    // ---- math ----------------------------------------------------------
    function factorize(n) {
        var f = [];
        n = Math.floor(n);
        if (n < 2) return f;
        for (var d = 2; d * d <= n; d++) {
            while (n % d === 0) { f.push(d); n = Math.floor(n / d); }
        }
        if (n > 1) f.push(n);
        return f;
    }
    function isPrime(n) { return n > 1 && factorize(n).length === 1; }
    function distinctPrimes(n) {
        var out = [], last = 0;
        factorize(n).forEach(function (p) { if (p !== last) { out.push(p); last = p; } });
        return out;
    }

    // ---- group helpers --------------------------------------------------
    function cellByPos(p) {
        return interlinear.querySelector('.word-cell[data-pos="' + p + '"]');
    }
    function groupSum(g, attr) {
        var t = 0;
        g.positions.forEach(function (p) {
            var c = cellByPos(p);
            if (c) t += parseInt(c.dataset[attr] || 0, 10) || 0;
        });
        return t;
    }
    function byId(id) {
        for (var i = 0; i < groups.length; i++) if (groups[i].id === id) return groups[i];
        return null;
    }
    function removeGroup(id) {
        groups = groups.filter(function (g) { return g.id !== id; });
        save(); scheduleRender();
    }
    function togglePin(p) {
        var i = pins.indexOf(p);
        if (i >= 0) pins.splice(i, 1); else pins.push(p);
        save(); scheduleRender();
    }

    // ---- URL persistence (?groups=1-7;6-7|label&gpin=13) ----------------
    function posToRanges(arr) {
        var out = [], i = 0;
        while (i < arr.length) {
            var j = i;
            while (j + 1 < arr.length && arr[j + 1] === arr[j] + 1) j++;
            out.push(arr[i] === arr[j] ? String(arr[i]) : arr[i] + '-' + arr[j]);
            i = j + 1;
        }
        return out.join(',');
    }
    function save() {
        var params = new URLSearchParams(location.search);
        if (groups.length) {
            params.set('groups', groups.map(function (g) {
                return posToRanges(g.positions) +
                       (g.label != null ? '|' + encodeURIComponent(g.label) : '');
            }).join(';'));
        } else {
            params.delete('groups');
        }
        if (pins.length) params.set('gpin', pins.join(',')); else params.delete('gpin');
        var qs = params.toString();
        history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
    }
    function load() {
        var params = new URLSearchParams(location.search);
        var raw = params.get('groups');
        if (raw) raw.split(';').forEach(function (gs) {
            if (!gs) return;
            var bar        = gs.indexOf('|');
            var rangesPart = bar >= 0 ? gs.slice(0, bar) : gs;
            var label      = bar >= 0 ? decodeURIComponent(gs.slice(bar + 1)) : null;
            var positions  = [];
            rangesPart.split(',').forEach(function (r) {
                var m = r.match(/^(\d+)(?:-(\d+))?$/);
                if (!m) return;
                var a = parseInt(m[1], 10), b = m[2] ? parseInt(m[2], 10) : a;
                for (var p = a; p <= b && p - a < 2000; p++) {
                    if (cellByPos(p) && positions.indexOf(p) < 0) positions.push(p);
                }
            });
            positions.sort(function (a, b) { return a - b; });
            if (positions.length) groups.push({ id: nextId++, positions: positions, label: label });
        });
        var rp = params.get('gpin');
        if (rp) pins = rp.split(',').map(function (s) { return parseInt(s, 10); }).filter(isPrime);
    }

    // ---- theme primes (auto: appears in >= 2 groups; pins always) --------
    function themePrimes() {
        var counts = {};
        groups.forEach(function (g) {
            distinctPrimes(groupSum(g, 'gemStd')).forEach(function (p) {
                counts[p] = (counts[p] || 0) + 1;
            });
        });
        var theme = pins.slice().sort(function (a, b) { return a - b; });
        Object.keys(counts).map(Number)
            .filter(function (p) { return counts[p] >= 2 && pins.indexOf(p) < 0; })
            .sort(function (a, b) { return counts[b] - counts[a] || a - b; })
            .forEach(function (p) { theme.push(p); });
        var map = {};
        theme.forEach(function (p, i) { map[p] = PRIME_COLORS[i % PRIME_COLORS.length]; });
        return map;
    }

    // ---- label HTML -------------------------------------------------------
    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function primeSpan(p, theme) {
        var col = theme[p];
        var cls = 'bl-prime' + (pins.indexOf(p) >= 0 ? ' pinned' : '');
        return '<span class="' + cls + '" data-prime="' + p + '"' +
               (col ? ' style="color:' + col + '"' : '') + '>' + p + '</span>';
    }
    function factorHTML(n, theme) {
        var f = factorize(n);
        if (!f.length) return '';
        var parts = [], i = 0;
        while (i < f.length) {
            var e = 1;
            while (i + e < f.length && f[i + e] === f[i]) e++;
            parts.push(primeSpan(f[i], theme) + (e > 1 ? '<sup>' + e + '</sup>' : ''));
            i += e;
        }
        return parts.join(' × ');
    }
    function activeGemTypes() {
        var t = [];
        ['std', 'ord', 'red'].forEach(function (k) {
            var box = document.querySelector('input[data-opt="gem-' + k + '"]');
            if (box && box.checked) t.push(k);
        });
        return t;
    }
    function defaultLabelHTML(g, theme) {
        var std = groupSum(g, 'gemStd');
        var f   = factorize(std);
        var head;
        if (f.length === 1)      head = primeSpan(std, theme);
        else if (f.length === 0) head = esc(String(std));
        else                     head = esc(String(std)) + ' = ' + factorHTML(std, theme);
        var extras = [], types = activeGemTypes();
        if (types.indexOf('ord') >= 0) extras.push('O ' + groupSum(g, 'gemOrd'));
        if (types.indexOf('red') >= 0) extras.push('R ' + groupSum(g, 'gemRed'));
        return head + (extras.length
            ? ' <span class="bl-extra">· ' + extras.join(' · ') + '</span>' : '');
    }
    function customLabelHTML(g, theme) {
        return esc(g.label).replace(/\d+/g, function (numStr) {
            var n = parseInt(numStr, 10);
            return theme[n] ? primeSpan(n, theme) : numStr;
        });
    }

    // ---- geometry + render ------------------------------------------------
    function ensureLayer() {
        if (!layer) {
            layer = document.createElement('div');
            layer.className = 'bracket-layer';
            interlinear.appendChild(layer);
            wireLayerEvents();
        }
        return layer;
    }

    var lastExtra = -1;
    function setGap(extra) {
        if (extra === lastExtra) return false;
        lastExtra = extra;
        var rg = extra ? (BASE_GAP + extra) + 'px' : '';
        interlinear.style.rowGap        = rg;
        interlinear.style.paddingBottom = extra ? extra + 'px' : '';
        // verse-newlines mode: gaps live on the .verse-block flex containers
        Array.prototype.forEach.call(
            interlinear.querySelectorAll('.verse-block'),
            function (vb) { vb.style.rowGap = rg; }
        );
        return true;
    }

    function render() {
        if (editing) return;
        var lyr = ensureLayer();

        renderPanelList();
        syncGroupBtn();

        if (!groups.length) {
            lyr.innerHTML = '';
            setGap(0);
            return;
        }

        var iRect = interlinear.getBoundingClientRect();
        var rtl   = getComputedStyle(interlinear).direction === 'rtl';

        // Cluster ALL cells into visual lines so brackets clear the tallest
        // cell on each line, member or not.
        var lines = [], cellLine = {};
        Array.prototype.forEach.call(interlinear.querySelectorAll('.word-cell'), function (c) {
            var r = c.getBoundingClientRect();
            if (!r.width && !r.height) return;
            var top = r.top - iRect.top, li = -1;
            for (var i = 0; i < lines.length; i++) {
                if (Math.abs(lines[i].top - top) < 8) { li = i; break; }
            }
            if (li < 0) { lines.push({ top: top, bottom: r.bottom - iRect.top }); li = lines.length - 1; }
            else lines[li].bottom = Math.max(lines[li].bottom, r.bottom - iRect.top);
            cellLine[c.dataset.pos] = li;
        });

        // Containment depth: innermost = 0; a group strictly containing
        // another sits at least one row below it.
        var depthMemo = {};
        function depthOf(g) {
            if (depthMemo[g.id] !== undefined) return depthMemo[g.id];
            depthMemo[g.id] = 0;
            var set = {}, d = 0;
            g.positions.forEach(function (p) { set[p] = 1; });
            groups.forEach(function (o) {
                if (o === g || o.positions.length >= g.positions.length) return;
                var inside = true;
                for (var i = 0; i < o.positions.length; i++) {
                    if (!set[o.positions[i]]) { inside = false; break; }
                }
                if (inside) d = Math.max(d, depthOf(o) + 1);
            });
            depthMemo[g.id] = d;
            return d;
        }

        // One fragment per (visual line, contiguous pos run).
        function fragsFor(g) {
            var frags = [], cur = null, prevPos = null, prevLine = null;
            g.positions.forEach(function (p) {
                var c = cellByPos(p);
                if (!c) return;
                var li = cellLine[String(p)];
                if (li === undefined) return;
                var r  = c.getBoundingClientRect();
                var x1 = r.left - iRect.left, x2 = r.right - iRect.left;
                if (!cur || li !== prevLine || p !== prevPos + 1) {
                    cur = { line: li, x1: x1, x2: x2, lineBreak: cur !== null && li !== prevLine };
                    frags.push(cur);
                } else {
                    cur.x1 = Math.min(cur.x1, x1);
                    cur.x2 = Math.max(cur.x2, x2);
                }
                prevPos = p; prevLine = li;
            });
            return frags;
        }

        // Row assignment: start at containment depth, bump past horizontal
        // collisions with already-placed fragments on the same line.
        var occupied = {};
        function rowFree(frags, row) {
            for (var i = 0; i < frags.length; i++) {
                var f = frags[i];
                var ivs = occupied[f.line] && occupied[f.line][row];
                if (!ivs) continue;
                for (var k = 0; k < ivs.length; k++) {
                    if (!(f.x2 < ivs[k][0] - 4 || f.x1 > ivs[k][1] + 4)) return false;
                }
            }
            return true;
        }
        var ordered = groups.slice().sort(function (a, b) {
            return depthOf(a) - depthOf(b) ||
                   a.positions.length - b.positions.length || a.id - b.id;
        });
        var drawn = [], maxRows = 0;
        ordered.forEach(function (g) {
            var frags = fragsFor(g);
            if (!frags.length) return;
            var row = depthOf(g);
            while (!rowFree(frags, row)) row++;
            frags.forEach(function (f) {
                occupied[f.line] = occupied[f.line] || {};
                (occupied[f.line][row] = occupied[f.line][row] || []).push([f.x1, f.x2]);
            });
            drawn.push({ g: g, frags: frags, row: row });
            maxRows = Math.max(maxRows, row + 1);
        });

        // Reserve vertical room below every line, then let the resize
        // observer re-render once geometry settles.
        if (setGap(maxRows ? GAP_TOP + maxRows * ROW_H : 0)) { scheduleRender(); return; }

        var theme = themePrimes();
        var html  = '';
        drawn.forEach(function (d) {
            var widest = d.frags[0];
            d.frags.forEach(function (f) {
                if (f.x2 - f.x1 > widest.x2 - widest.x1) widest = f;
            });
            d.frags.forEach(function (f, i) {
                var contStart = i > 0 && f.lineBreak;
                var contEnd   = i < d.frags.length - 1 && d.frags[i + 1].lineBreak;
                var openLeft  = rtl ? contEnd   : contStart;
                var openRight = rtl ? contStart : contEnd;
                var yTop = lines[f.line].bottom + GAP_TOP + d.row * ROW_H;
                html += '<div class="bracket-frag' +
                        (openLeft ? ' open-left' : '') + (openRight ? ' open-right' : '') +
                        '" style="left:' + f.x1.toFixed(1) + 'px;top:' + yTop.toFixed(1) +
                        'px;width:' + (f.x2 - f.x1).toFixed(1) + 'px;height:' + BRACKET_H + 'px"></div>';
            });
            var lx = (widest.x1 + widest.x2) / 2;
            var ly = lines[widest.line].bottom + GAP_TOP + d.row * ROW_H + BRACKET_H + 1;
            var inner = d.g.label != null ? customLabelHTML(d.g, theme) : defaultLabelHTML(d.g, theme);
            html += '<div class="bracket-label" data-gid="' + d.g.id +
                    '" style="left:' + lx.toFixed(1) + 'px;top:' + ly.toFixed(1) + 'px">' +
                    '<span class="bl-text" spellcheck="false">' + inner + '</span>' +
                    (d.g.label != null
                        ? '<span class="bl-btn bl-reset" title="Reset to computed value">↺</span>' : '') +
                    '<span class="bl-btn bl-del" title="Remove group">×</span>' +
                    '</div>';
        });
        lyr.innerHTML = html;
    }

    var pending = false;
    function scheduleRender() {
        if (pending) return;
        pending = true;
        requestAnimationFrame(function () { pending = false; render(); });
    }

    // ---- label editing ------------------------------------------------------
    function startEdit(lbl) {
        var g = byId(parseInt(lbl.dataset.gid, 10));
        if (!g) return;
        var txt = lbl.querySelector('.bl-text');
        txt.textContent = txt.textContent;   // flatten colored spans to plain text
        txt.contentEditable = 'true';
        editing = true;
        txt.focus();
        try { document.execCommand('selectAll', false, null); } catch (e) {}
        var done = false;
        function commit(cancel) {
            if (done) return;
            done = true;
            editing = false;
            txt.contentEditable = 'false';
            if (!cancel) {
                var t = txt.textContent.replace(/\s+/g, ' ').trim();
                g.label = t === '' ? null : t;
                save();
            }
            scheduleRender();
        }
        txt.addEventListener('blur', function () { commit(false); }, { once: true });
        txt.addEventListener('keydown', function (ev) {
            ev.stopPropagation();   // keep s/d/Escape from word-selection.js
            if (ev.key === 'Enter')       { ev.preventDefault(); txt.blur(); }
            else if (ev.key === 'Escape') { commit(true); txt.blur(); }
        });
    }

    // ---- events -------------------------------------------------------------
    function wireLayerEvents() {
        layer.addEventListener('click', function (ev) {
            var lbl = ev.target.closest('.bracket-label');
            if (!lbl) return;
            var gid = parseInt(lbl.dataset.gid, 10);
            var prime = ev.target.closest('.bl-prime');
            if (prime && !editing) { togglePin(parseInt(prime.dataset.prime, 10)); return; }
            if (ev.target.closest('.bl-del'))   { removeGroup(gid); return; }
            if (ev.target.closest('.bl-reset')) {
                var g = byId(gid);
                if (g) { g.label = null; save(); scheduleRender(); }
                return;
            }
            var txt = ev.target.closest('.bl-text');
            if (txt && txt.contentEditable !== 'true') startEdit(lbl);
        });
    }

    function flashGroup(id) {
        var lbl = layer && layer.querySelector('.bracket-label[data-gid="' + id + '"]');
        if (!lbl) return;
        lbl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        lbl.classList.add('flash');
        setTimeout(function () { lbl.classList.remove('flash'); }, 1200);
    }

    var groupBtn = document.getElementById('gem-group-btn');
    function syncGroupBtn() {
        if (!groupBtn) return;
        groupBtn.style.display = interlinear.querySelector('.word-cell.selected') ? '' : 'none';
    }
    if (groupBtn) groupBtn.addEventListener('click', function () {
        var sel = interlinear.querySelectorAll('.word-cell.selected');
        if (!sel.length) return;
        var positions = Array.prototype.map.call(sel, function (c) {
            return parseInt(c.dataset.pos, 10);
        }).filter(function (n) { return !isNaN(n); }).sort(function (a, b) { return a - b; });
        if (!positions.length) return;
        var key = positions.join(','), dup = null;
        groups.forEach(function (g) { if (g.positions.join(',') === key) dup = g; });
        if (dup) { flashGroup(dup.id); return; }
        groups.push({ id: nextId++, positions: positions, label: null });
        Array.prototype.forEach.call(sel, function (c) { c.classList.remove('selected'); });
        save();
        if (window._gemRebuild) window._gemRebuild();
        if (window._refreshFromCells) window._refreshFromCells();
        scheduleRender();
    });

    // panel group list
    function renderPanelList() {
        var box = document.getElementById('gem-groups');
        if (!box) return;
        if (!groups.length) { box.innerHTML = ''; return; }
        var html = '';
        groups.forEach(function (g) {
            html += '<span class="gem-group-row" data-gid="' + g.id + '" title="Click to locate">⊓ ' +
                    groupSum(g, 'gemStd') +
                    ' <span class="gg-n">(' + g.positions.length + 'w)</span>' +
                    '<span class="gg-del" title="Remove group">×</span></span>';
        });
        box.innerHTML = html;
    }
    document.addEventListener('click', function (ev) {
        var del = ev.target.closest('.gg-del');
        if (del) {
            removeGroup(parseInt(del.closest('.gem-group-row').dataset.gid, 10));
            return;
        }
        var row = ev.target.closest('.gem-group-row');
        if (row) flashGroup(parseInt(row.dataset.gid, 10));
    });

    // ---- refresh hooks -------------------------------------------------------
    // Values: variant cycling / page-load sync / selection changes all funnel
    // through _gemRebuild (set by gematria.js, which loads before this file).
    var _origRebuild = window._gemRebuild;
    window._gemRebuild = function () {
        if (_origRebuild) _origRebuild();
        syncGroupBtn();
        scheduleRender();
    };

    // Geometry: covers window resize, font-size CSS-var changes, display-row
    // toggles, web-font load shifts. setGap() guards against feedback loops.
    if (window.ResizeObserver) {
        new ResizeObserver(function () { scheduleRender(); }).observe(interlinear);
    } else {
        window.addEventListener('resize', scheduleRender);
    }

    // ---- init -----------------------------------------------------------------
    load();
    scheduleRender();
})();
