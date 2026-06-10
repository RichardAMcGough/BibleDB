// dropdowns.js — chained book/chapter/verse dropdowns.
// Also keeps the "Show N verses" count dropdown in sync with the current
// chapter's verse count (max = number of verses in chapter).
// Repopulates the edition dropdown when the user selects a different book.
// The edition list remains global and the server handles compatibility jumps.
// Auto-submits the form when the user changes the Edition selection.
(function () {
    const selBook    = document.getElementById('sel-book');
    const selChapter = document.getElementById('sel-chapter');
    const selVerse   = document.getElementById('sel-verse');
    const selCount   = document.getElementById('sel-count');
    const selEdition = document.getElementById('sel-edition');
    if (!selBook || !selChapter || !selVerse) return;

    const ALL_EDITIONS = [
        { code: 'BHS',        name: 'Biblia Hebraica Stuttgartensia' },
        { code: 'NA28',       name: 'Nestle-Aland 28th edition' },
        { code: 'TR',         name: 'Scrivener Textus Receptus 1894' },
        { code: 'LXX-Rahlfs', label: 'LXX', name: 'Rahlfs LXX 1935' }
    ];

    function populate(sel, items, resetTo) {
        sel.innerHTML = '';
        for (const v of items) {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v;
            sel.appendChild(opt);
        }
        sel.value = resetTo && items.includes(resetTo) ? resetTo : items[0];
    }

    function populateCount(maxN) {
        if (!selCount) return;
        selCount.innerHTML = '';
        for (let i = 1; i <= maxN; i++) {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = i === 1 ? '1 verse' : i + ' verses';
            selCount.appendChild(opt);
        }
        selCount.value = '1';
        selCount.dataset.max = String(maxN);
    }

    // Keep a global edition list regardless of selected book.
    // The server will auto-jump to a compatible book when needed.
    function syncEditionOptions() {
        if (!selEdition) return;
        const current  = selEdition.value;
        selEdition.innerHTML = '';
        for (const ed of ALL_EDITIONS) {
            const o = document.createElement('option');
            o.value = ed.code;
            o.textContent = ed.label || ed.code;
            o.title = ed.name;
            selEdition.appendChild(o);
        }
        const codes = ALL_EDITIONS.map(e => e.code);
        selEdition.value = codes.includes(current) ? current : ALL_EDITIONS[0].code;
        selEdition.disabled = false;
    }

    // Pick an edition that can render the chosen book directly.
    // Priority order follows the global list order: BHS, NA28, TR, LXX.
    function pickCompatibleEdition(bookCode, bookLang, currentEdition) {
        const isLxxBook = !!bookCode && bookCode.startsWith('Lxx');
        if (isLxxBook) {
            return 'LXX-Rahlfs';
        }
        if (bookLang === 'Hebrew') {
            // MT OT books are native in BHS.
            return 'BHS';
        }
        // Greek canonical books (NT) prefer NA28 then TR.
        if (currentEdition === 'NA28' || currentEdition === 'TR') {
            return currentEdition;
        }
        return 'NA28';
    }

    selBook.addEventListener('change', function () {
        // Only sync edition options on pages that use dynamic edition lists
        // (i.e. not pages with data-static on the edition select).
        if (!selEdition || !selEdition.dataset.static) {
            syncEditionOptions();
        }
        const opt = this.selectedOptions[0];
        const targetBook = this.value;
        const targetLang = opt ? (opt.dataset.lang || '') : '';
        if (selEdition) {
            selEdition.value = pickCompatibleEdition(targetBook, targetLang, selEdition.value);
        }
        // Navigate directly so chapter/verse always reset to 1:1.
        // Preserve any extra form fields (width, letters, etc.) from the
        // current form so page-specific params survive book changes.
        const form = this.closest('form');
        const params = new URLSearchParams();
        params.set('book',    targetBook);
        params.set('chapter', '1');
        params.set('verse',   '1');
        if (selEdition) params.set('edition', selEdition.value);
        if (form) {
            const skip = new Set(['book', 'chapter', 'verse', 'edition', 'count']);
            for (const el of form.elements) {
                if (el.name && !skip.has(el.name)) params.set(el.name, el.value);
            }
        }
        window.location.href = '?' + params.toString();
    });

    selChapter.addEventListener('change', async function () {
        const book    = selBook.value;
        const chapter = this.value;
        // Relative URL — works whether the page is served at /bible/ or root.
        const verses  = await fetch(`api.php?api=verses&book=${encodeURIComponent(book)}&chapter=${chapter}`)
            .then(r => r.json()).catch(() => []);
        populate(selVerse, verses, null);
        populateCount(verses.length || 1);
        const form = selChapter.closest('form');
        if (form) form.submit();
    });

    selVerse.addEventListener('change', function () {
        if (selCount) selCount.value = '1';
        const form = selVerse.closest('form');
        if (form) form.submit();
    });

    if (selCount) {
        selCount.addEventListener('change', function () {
            const form = selCount.closest('form');
            if (form) form.submit();
        });
    }

    // Auto-submit when the user picks a new edition — no need to click Go.
    if (selEdition) {
        selEdition.addEventListener('change', function () {
            const form = this.closest('form');
            if (form) form.submit();
        });
    }
})();
