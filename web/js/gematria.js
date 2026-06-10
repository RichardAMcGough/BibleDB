// gematria.js — gematria analysis panel with prime factorization.
(function () {
    const gemPanel    = document.getElementById('gematria-panel');
    const rowsEl      = document.getElementById('gem-rows');
    const interlinear = document.getElementById('interlinear');
    if (!gemPanel || !rowsEl || !interlinear) return;

    function factorize(n) {
        const factors = [];
        for (let d = 2; d * d <= n; d++) {
            while (n % d === 0) { factors.push(d); n = Math.floor(n / d); }
        }
        if (n > 1) factors.push(n);
        return factors;
    }

    function formatFactors(n) {
        if (n <= 1) return String(n);
        const f = factorize(n);
        if (f.length === 1) return 'prime';
        const parts = [];
        let i = 0;
        while (i < f.length) {
            let exp = 1;
            while (i + exp < f.length && f[i + exp] === f[i]) exp++;
            parts.push(exp > 1 ? `${f[i]}<sup>${exp}</sup>` : String(f[i]));
            i += exp;
        }
        return parts.join(' × ');
    }

    // Like formatFactors but wraps each prime in a search link.
    function formatFactorsLinked(n) {
        if (n <= 1) return String(n);
        const f = factorize(n);
        if (f.length === 1) return 'prime';
        const parts = [];
        let i = 0;
        while (i < f.length) {
            let exp = 1;
            while (i + exp < f.length && f[i + exp] === f[i]) exp++;
            const link = `<a href="search.php?mode=gematria&amp;standard=${f[i]}" class="gem-factor-link">${f[i]}</a>`;
            parts.push(exp > 1 ? `${link}<sup>${exp}</sup>` : link);
            i += exp;
        }
        return parts.join(' × ');
    }

    // Count how many primes are ≤ n (i.e. n's 1-based position in the primes).
    function primeIndex(n) {
        if (n < 2) return 0;
        let count = 0;
        outer: for (let i = 2; i <= n; i++) {
            for (let d = 2; d * d <= i; d++) { if (i % d === 0) continue outer; }
            count++;
        }
        return count;
    }

    function ordinal(n) {
        const v = n % 100;
        const s = (v >= 11 && v <= 13) ? 'th'
                : (n % 10 === 1) ? 'st'
                : (n % 10 === 2) ? 'nd'
                : (n % 10 === 3) ? 'rd' : 'th';
        return n + s;
    }

    const typeLabels = { std: 'Standard', ord: 'Ordinal', red: 'Reduced' };
    const dataAttrs  = { std: 'gemStd',   ord: 'gemOrd',  red: 'gemRed'  };

    const HEB_STD = {
        'א':1,'ב':2,'ג':3,'ד':4,'ה':5,'ו':6,'ז':7,'ח':8,'ט':9,
        'י':10,'כ':20,'ל':30,'מ':40,'נ':50,'ס':60,'ע':70,'פ':80,'צ':90,
        'ק':100,'ר':200,'ש':300,'ת':400,
        'ך':20,'ם':40,'ן':50,'ף':80,'ץ':90
    };
    const HEB_ORD = {
        'א':1,'ב':2,'ג':3,'ד':4,'ה':5,'ו':6,'ז':7,'ח':8,'ט':9,
        'י':10,'כ':11,'ל':12,'מ':13,'נ':14,'ס':15,'ע':16,'פ':17,'צ':18,
        'ק':19,'ר':20,'ש':21,'ת':22,
        'ך':11,'ם':13,'ן':14,'ף':17,'ץ':18
    };

    // ---- letter-counting helpers ----
    // Greek diacritic-strip regex preserves iota subscript (U+0345) so it
    // gets counted as one iota. Hebrew counts only consonants in U+05D0-05EA
    // (alef-tav + sofit forms).
    const _GREEK_DIA = /[\u0300-\u0344\u0346-\u036F]/g;
    const _SECTION_MARKERS = new Set(['פ', 'ס']);

    function isSectionMarkerCell(cell) {
        const orig = cell.querySelector('.original');
        if (!orig) return false;
        return _SECTION_MARKERS.has(orig.textContent.trim());
    }

    function countLetters(text, isHeb) {
        if (!text) return 0;
        let n = 0;
        if (isHeb) {
            for (const ch of text) {
                const cp = ch.codePointAt(0);
                if (cp >= 0x05D0 && cp <= 0x05EA) n++;
            }
            return n;
        }
        // Greek: NFD-strip diacritics (keep U+0345), then count letters
        const clean = text.normalize('NFD').replace(_GREEK_DIA, '');
        for (const ch of clean) {
            const cp = ch.codePointAt(0);
            if (cp === 0x0345)                        { n++; continue; }
            if (cp >= 0x0391 && cp <= 0x03A9)         { n++; continue; }
            if (cp >= 0x03B1 && cp <= 0x03C9)         { n++; continue; }
        }
        return n;
    }

    function activeTypes() {
        const types = [];
        for (const t of ['std', 'ord', 'red']) {
            const box = document.querySelector(`input[data-opt="gem-${t}"]`);
            if (box && box.checked) types.push(t);
        }
        return types;
    }

    function sumCells(t, cells) {
        let total = 0;
        cells.forEach(cell => {
            const orig = cell.querySelector('.original');
            const isHeb = !!(orig && orig.classList.contains('heb'));
            if (isHeb && orig) {
                total += hebCellValue(orig.textContent || '', t);
            } else {
                total += parseInt(cell.dataset[dataAttrs[t]] || 0, 10);
            }
        });
        return total;
    }

    function digitalRoot(n) {
        if (!n) return 0;
        while (n > 9) {
            let s = 0;
            while (n > 0) {
                s += n % 10;
                n = Math.floor(n / 10);
            }
            n = s;
        }
        return n;
    }

    function cleanHebrewForGematria(text) {
        if (!text) return '';
        let t = text;
        // Remove STEPBible marker form and standalone parashah markers.
        t = t.replace(/\\[פס]/g, '');
        t = t.replace(/[֑-ׇ]/g, '');
        t = t.replace(/[\\/]/g, '');
        t = t.replace(/(?:(?<=\s)|^)[פס](?=\s|$)/gu, '');
        return t;
    }

    function hebScore(text, map) {
        let s = 0;
        for (const ch of text) s += map[ch] || 0;
        return s;
    }

    function hebCellValue(text, type) {
        const cleaned = cleanHebrewForGematria(text);
        if (type === 'std') return hebScore(cleaned, HEB_STD);
        if (type === 'ord') return hebScore(cleaned, HEB_ORD);
        if (type === 'red') return digitalRoot(hebScore(cleaned, HEB_STD));
        return 0;
    }

    function rebuild() {
        const types = activeTypes();
        if (types.length === 0) { gemPanel.setAttribute('hidden', ''); return; }
        gemPanel.removeAttribute('hidden');

        const allCells      = Array.from(interlinear.querySelectorAll('.word-cell'));
        const selectedCells = allCells.filter(c => c.classList.contains('selected'));
        const useAll        = selectedCells.length === 0 || selectedCells.length === allCells.length;
        const cells         = useAll ? allCells : selectedCells;

        const clearBtn   = document.getElementById('gem-clear');
        const linkBtn    = document.getElementById('gem-link-btn');
        if (clearBtn) clearBtn.style.display = selectedCells.length > 0 ? '' : 'none';
        if (linkBtn)  linkBtn.style.display  = selectedCells.length > 0 ? '' : 'none';

        // Word and letter counts. Letter count comes from PHP-computed
        // data-letter-count attribute (set in index.php via letter_count()).
        // Synthetic 'addition' cells (negative ids) fall back to JS counting.
        const realCells = cells.filter(c => !isSectionMarkerCell(c));
        let wordCount = realCells.length;
        let letterCount = 0;
        realCells.forEach(c => {
            const attr = c.dataset.letterCount;
            if (attr !== undefined && attr !== '') {
                letterCount += parseInt(attr, 10) || 0;
            } else {
                const origEl = c.querySelector('.original');
                if (!origEl) return;
                const isHeb = origEl.classList.contains('heb');
                letterCount += countLetters(origEl.textContent.trim(), isHeb);
            }
        });

        rowsEl.innerHTML = '';

        // Prepend a counts row (Words · Letters)
        const countsRow = document.createElement('div');
        countsRow.className = 'gem-row gem-counts';
        countsRow.innerHTML =
            `<span class="gem-row-type">Words</span>` +
            `<span class="gem-row-value">${wordCount}</span>` +
            `<span class="gem-row-sep">·</span>` +
            `<span class="gem-row-type">Letters</span>` +
            `<span class="gem-row-value">${letterCount}</span>`;
        rowsEl.appendChild(countsRow);

        for (const t of types) {
            const val     = sumCells(t, cells);
            const fStr    = t === 'std' ? formatFactorsLinked(val) : formatFactors(val);
            const isPrime = fStr === 'prime';
            const valStr  = t === 'std'
                ? `<a href="search.php?mode=gematria&amp;standard=${val}" class="gem-link">${val}</a>`
                : String(val);
            const row     = document.createElement('div');
            row.className = 'gem-row';
            const primeLabel = isPrime ? '(' + ordinal(primeIndex(val)) + ' prime)' : '= ' + fStr;
            row.innerHTML =
                `<span class="gem-row-type">${typeLabels[t]}</span>` +
                `<span class="gem-row-value">${valStr}</span>` +
                `<span class="gem-row-factors${isPrime ? ' is-prime' : ''}">${primeLabel}</span>`;
            rowsEl.appendChild(row);
        }
    }

    document.querySelectorAll('input[data-opt^="gem-"]').forEach(box => {
        box.addEventListener('change', rebuild);
    });

    window._gemRebuild = rebuild;
    rebuild();
})();
