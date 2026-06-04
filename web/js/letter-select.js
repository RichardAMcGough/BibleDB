// letter-select.js — individual letter highlighting and gematria exclusion.
//
// Splits each .word-cell .original div into clickable .letter <span> elements.
//
// Modes (set via data-letter-mode on #interlinear):
//   "compare" (versecomparison.php) — click cycles highlight colors hl-1/2/3.
//   "study"   (index.php, future)   — click toggles letter-off (excluded from
//                                     gematria sum, shown dimmed).
//
// In both modes, clicking a letter stops propagation so word-cell selection
// is not triggered.
//
// URL state: lh=WORDID-LETIDX-COLOR|...  (highlight)
//            lo=WORDID-LETIDX|...         (letter-off/excluded)
// Saved via replaceState on every change; loaded on init.

(function () {

    // ── Gematria maps ────────────────────────────────────────────────────────
    // Mirrors the maps in variant-switcher.js — kept in sync manually.
    const HEB_STD = {
        'א':1,'ב':2,'ג':3,'ד':4,'ה':5,'ו':6,'ז':7,'ח':8,'ט':9,
        'י':10,'כ':20,'ך':20,'ל':30,'מ':40,'ם':40,'נ':50,'ן':50,
        'ס':60,'ע':70,'פ':80,'ף':80,'צ':90,'ץ':90,
        'ק':100,'ר':200,'ש':300,'ת':400
    };
    const HEB_ORD = {
        'א':1,'ב':2,'ג':3,'ד':4,'ה':5,'ו':6,'ז':7,'ח':8,'ט':9,
        'י':10,'כ':11,'ך':23,'ל':12,'מ':13,'ם':24,'נ':14,'ן':25,
        'ס':15,'ע':16,'פ':17,'ף':26,'צ':18,'ץ':27,
        'ק':19,'ר':20,'ש':21,'ת':22
    };
    const GRK_STD = {
        'α':1,'β':2,'γ':3,'δ':4,'ε':5,'ζ':7,'η':8,'θ':9,
        'ι':10,'κ':20,'λ':30,'μ':40,'ν':50,'ξ':60,'ο':70,'π':80,
        'ρ':100,'σ':200,'ς':200,'τ':300,'υ':400,'φ':500,'χ':600,'ψ':700,'ω':800
    };
    const GRK_ORD = {
        'α':1,'β':2,'γ':3,'δ':4,'ε':5,'ζ':6,'η':7,'θ':8,
        'ι':9,'κ':10,'λ':11,'μ':12,'ν':13,'ξ':14,'ο':15,'π':16,
        'ρ':17,'σ':18,'ς':18,'τ':19,'υ':20,'φ':21,'χ':22,'ψ':23,'ω':24
    };

    function digitRoot(n) { return n === 0 ? 0 : 1 + ((n - 1) % 9); }

    // ── Letter splitting ─────────────────────────────────────────────────────

    // Hebrew: each base letter (U+05D0–U+05EA, incl. sofit forms) plus any
    // following nikud / cantillation marks (U+0591–U+05C7, U+FB1E).
    function splitHebrew(text) {
        const units = [];
        let cur = '';
        for (const ch of text) {
            const cp = ch.codePointAt(0);
            const isBase     = cp >= 0x05D0 && cp <= 0x05EA;
            const isCombining = (cp >= 0x0591 && cp <= 0x05C7) || cp === 0xFB1E;
            if (isBase) {
                if (cur) units.push(cur);
                cur = ch;
            } else if (isCombining && cur) {
                cur += ch;
            }
            // Spaces, maqqef, punctuation etc. are silently skipped.
        }
        if (cur) units.push(cur);
        return units;
    }

    // Greek: NFD-decompose, group each base Greek letter (U+03B1–U+03C9 /
    // U+0391–U+03A9) with following combining diacritics (U+0300–U+036F).
    // Iota subscript (U+0345) is treated as a separate iota letter, because
    // it carries a gematria value of its own (ι = 10 std / 9 ord).
    function splitGreek(text) {
        const nfd   = text.normalize('NFD');
        const units = [];
        let cur = '';
        for (const ch of nfd) {
            const cp = ch.codePointAt(0);
            const isBase     = (cp >= 0x03B1 && cp <= 0x03C9) || (cp >= 0x0391 && cp <= 0x03A9);
            const isIotaSub  = cp === 0x0345;
            const isCombining = cp >= 0x0300 && cp <= 0x036F && !isIotaSub;
            if (isBase) {
                if (cur) units.push(cur.normalize('NFC'));
                cur = ch;
            } else if (isIotaSub) {
                // Flush current letter; emit iota subscript as a separate iota.
                if (cur) units.push(cur.normalize('NFC'));
                units.push('ι');
                cur = '';
            } else if (isCombining && cur) {
                cur += ch;
            }
        }
        if (cur) units.push(cur.normalize('NFC'));
        return units;
    }

    // Strip diacritics from a displayed character to get the base for lookup.
    function baseChar(display) {
        return display.normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .normalize('NFC')
            .toLowerCase();
    }

    function letterGem(base, lang) {
        const std = lang === 'heb' ? (HEB_STD[base] || 0) : (GRK_STD[base] || 0);
        const ord = lang === 'heb' ? (HEB_ORD[base] || 0) : (GRK_ORD[base] || 0);
        return { std, ord, red: digitRoot(std) };
    }

    // ── Build / rebuild letter spans for one .original div ───────────────────

    function buildLetterSpans(origDiv, lang) {
        // Preserve any existing per-letter state (highlight or off) keyed by index.
        const prevHL  = {};
        const prevOff = {};
        origDiv.querySelectorAll('.letter').forEach(s => {
            const i = s.dataset.idx;
            if (s.dataset.hlState)              prevHL[i]  = s.dataset.hlState;
            if (s.classList.contains('letter-off')) prevOff[i] = true;
        });

        // textContent works whether the div currently has plain text or existing spans.
        const rawText = origDiv.textContent;
        const units   = lang === 'heb' ? splitHebrew(rawText) : splitGreek(rawText);

        origDiv.innerHTML = '';
        units.forEach((display, idx) => {
            const base       = baseChar(display);
            const { std, ord, red } = letterGem(base, lang);
            const s          = document.createElement('span');
            s.className      = 'letter';
            s.textContent    = display;
            s.dataset.base   = base;
            s.dataset.std    = std;
            s.dataset.ord    = ord;
            s.dataset.red    = red;
            s.dataset.idx    = idx;
            if (prevHL[idx])  { s.dataset.hlState = prevHL[idx]; s.classList.add('hl-' + prevHL[idx]); }
            if (prevOff[idx]) { s.classList.add('letter-off'); }
            origDiv.appendChild(s);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    function cellLang(cell) {
        const orig = cell.querySelector('.original');
        return (orig && orig.classList.contains('heb')) ? 'heb' : 'grk';
    }

    function processCell(cell) {
        const orig = cell.querySelector('.original');
        if (orig) buildLetterSpans(orig, cellLang(cell));
    }

    // Recompute word-cell gem data attributes from active (non-off) letters,
    // then ask options.js to refresh the gematria display row.
    function recomputeGem(cell) {
        let std = 0, ord = 0, red = 0;
        cell.querySelectorAll('.letter').forEach(s => {
            if (!s.classList.contains('letter-off')) {
                std += parseInt(s.dataset.std || 0, 10);
                ord += parseInt(s.dataset.ord || 0, 10);
                red += parseInt(s.dataset.red || 0, 10);
            }
        });
        cell.dataset.gemStd = std;
        cell.dataset.gemOrd = ord;
        cell.dataset.gemRed = red;
        if (typeof window._updateGematria === 'function') window._updateGematria();
    }

    // ── Mode and wrapper ──────────────────────────────────────────────────────

    const wrapper = document.getElementById('interlinear');
    const mode    = (wrapper && wrapper.dataset.letterMode) || 'study';
    const MAX_HL  = 3; // hl-1 = amber, hl-2 = red, hl-3 = blue

    // ── Click handler (event-delegated on wrapper) ────────────────────────────

    if (wrapper) {
        wrapper.addEventListener('click', function (e) {
            const s    = e.target.closest('.letter');
            if (!s) return;
            const cell = e.target.closest('.word-cell');
            if (!cell) return;

            // Stop here — do NOT let the click bubble to word-cell selection.
            e.stopPropagation();

            if (mode === 'compare') {
                const cur  = parseInt(s.dataset.hlState || '0', 10);
                const next = (cur + 1) % (MAX_HL + 1);
                for (let i = 1; i <= MAX_HL; i++) s.classList.remove('hl-' + i);
                if (next > 0) { s.classList.add('hl-' + next); s.dataset.hlState = String(next); }
                else          { delete s.dataset.hlState; }
            } else {
                // Study mode: toggle excluded from gematria
                s.classList.toggle('letter-off');
            }

            recomputeGem(cell);
            saveState();
        });
    }

    // ── Re-split after variant switch ─────────────────────────────────────────
    // variant-switcher.js sets data-active-variant when cycling; we watch for
    // that and rebuild the letter spans for the affected cell.
    if (wrapper) {
        new MutationObserver(function (mutations) {
            const changed = new Set();
            mutations.forEach(m => {
                if (m.attributeName === 'data-active-variant') changed.add(m.target);
            });
            // rAF ensures variant-switcher.js has finished writing the new text.
            requestAnimationFrame(() => changed.forEach(cell => {
                processCell(cell);
                recomputeGem(cell);
            }));
        }).observe(wrapper, { attributeFilter: ['data-active-variant'], subtree: true });
    }

    // ── Spacer controls (compare mode only) ──────────────────────────────────
    // Each word cell can have a left-margin spacer (in units of SPACER_PX px).
    // A small +/− control appears on hover at the top-left of the cell.
    // Shift-click the + button removes a unit; plain click adds one.

    const SPACER_PX  = 28;
    const SPACER_MAX = 20;

    function applySpacerToCell(cell, units) {
        cell.dataset.spacer = units;
        cell.style.marginLeft = units > 0 ? (units * SPACER_PX) + 'px' : '';
        const badge = cell.querySelector('.spacer-badge');
        if (badge) {
            badge.textContent = units > 0 ? units : '';
            badge.style.display = units > 0 ? '' : 'none';
        }
    }

    function addSpacerControl(cell) {
        if (cell.querySelector('.spacer-ctrl')) return;
        const ctrl  = document.createElement('div');
        ctrl.className = 'spacer-ctrl';
        ctrl.innerHTML = '<button class="spacer-btn spacer-add" title="Add space before word (Shift+click to remove)">+</button>'
                       + '<span class="spacer-badge" style="display:none"></span>';
        ctrl.addEventListener('click', function (e) {
            e.stopPropagation();
            const cur   = parseInt(cell.dataset.spacer || '0', 10);
            const delta = e.shiftKey ? -1 : 1;
            const next  = Math.max(0, Math.min(SPACER_MAX, cur + delta));
            applySpacerToCell(cell, next);
            saveState();
        });
        cell.prepend(ctrl);
        applySpacerToCell(cell, parseInt(cell.dataset.spacer || '0', 10));
    }

    if (mode === 'compare') {
        // Add spacer controls to every word cell now and whenever new cards load.
        document.querySelectorAll('#interlinear .word-cell').forEach(addSpacerControl);
    }

    // ── URL state ─────────────────────────────────────────────────────────────
    // lh = WORDID-LETIDX-COLOR|...   (letter highlights)
    // lo = WORDID-LETIDX|...         (letter-off / excluded)
    // sp = WORDID-UNITS|...          (word spacers)

    function saveState() {
        const hlParts = [], loParts = [], spParts = [];
        document.querySelectorAll('#interlinear .word-cell').forEach(cell => {
            const wid = cell.dataset.wordId;
            if (!wid) return;
            cell.querySelectorAll('.letter').forEach(s => {
                const idx = s.dataset.idx;
                if (s.dataset.hlState)                  hlParts.push(wid + '-' + idx + '-' + s.dataset.hlState);
                if (s.classList.contains('letter-off')) loParts.push(wid + '-' + idx);
            });
            const sp = parseInt(cell.dataset.spacer || '0', 10);
            if (sp > 0) spParts.push(wid + '-' + sp);
        });
        try {
            const url = new URL(window.location.href);
            if (hlParts.length) url.searchParams.set('lh', hlParts.join('|')); else url.searchParams.delete('lh');
            if (loParts.length) url.searchParams.set('lo', loParts.join('|')); else url.searchParams.delete('lo');
            if (spParts.length) url.searchParams.set('sp', spParts.join('|')); else url.searchParams.delete('sp');
            history.replaceState(null, '', url.toString());
        } catch (_) {}
    }

    function loadState() {
        try {
            const url = new URL(window.location.href);
            const lh  = url.searchParams.get('lh') || '';
            const lo  = url.searchParams.get('lo') || '';
            const sp  = url.searchParams.get('sp') || '';

            if (lh) {
                lh.split('|').forEach(part => {
                    const [wid, idx, color] = part.split('-');
                    const cell = wrapper && wrapper.querySelector(`.word-cell[data-word-id="${CSS.escape(wid)}"]`);
                    const s    = cell && cell.querySelector(`.letter[data-idx="${idx}"]`);
                    if (!s) return;
                    for (let i = 1; i <= MAX_HL; i++) s.classList.remove('hl-' + i);
                    s.classList.add('hl-' + color);
                    s.dataset.hlState = color;
                });
            }
            if (lo) {
                lo.split('|').forEach(part => {
                    const [wid, idx] = part.split('-');
                    const cell = wrapper && wrapper.querySelector(`.word-cell[data-word-id="${CSS.escape(wid)}"]`);
                    const s    = cell && cell.querySelector(`.letter[data-idx="${idx}"]`);
                    if (!s) return;
                    s.classList.add('letter-off');
                });
            }
            if (sp) {
                sp.split('|').forEach(part => {
                    const [wid, units] = part.split('-');
                    const cell = wrapper && wrapper.querySelector(`.word-cell[data-word-id="${CSS.escape(wid)}"]`);
                    if (!cell) return;
                    applySpacerToCell(cell, parseInt(units, 10));
                });
            }
            document.querySelectorAll('#interlinear .word-cell').forEach(recomputeGem);
        } catch (_) {}
    }

    // ── Initialize ────────────────────────────────────────────────────────────
    // Run after variant-switcher.js and gematria.js have finished their own
    // DOMContentLoaded work (setTimeout(0) defers to the next task).
    function init() {
        document.querySelectorAll('#interlinear .word-cell').forEach(cell => {
            processCell(cell);
            recomputeGem(cell);
        });
        loadState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(init, 0));
    } else {
        setTimeout(init, 0);
    }

})();
