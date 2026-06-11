# Current Handoff — BibleDB (as of 2026)

**This is the recommended document for anyone picking up the project now.**

The original `HANDOFF.md` contains a lot of historical session notes. This file focuses on the current state, conventions, and the easiest ways to work with the project.

**For very long conversations:** See `docs/NEW_SESSION_STARTER.md` for the best way to start a fresh session with good context.

---

## Core Principle: Single Source of Truth for Database Name

**The database name has one and only one source:**

- `BIBLE_DB_NAME` environment variable (required)

It is **never** read from `config.ini`.

This was made strict so you can easily target different databases (e.g. `stepbible` for production work, `stepbibletest` for clean testing) without editing files.

**Example (for creating the real "stepbible" database):**
Use the new orchestrator for the full pipeline (recommended for normal use):

```powershell
$env:BIBLE_DB_NAME = "stepbible"
python scripts/run_pipeline.py --db-name stepbible
```

It runs the 7 steps in order (import + SQL dumps for editions + gematria compute + unicode + edition text/diff + search columns + KJV versification fix), with resume support, preflight checks, self-healing migrations, and a safety prompt if the DB has data.

For a quick test or single step, you can still run individual scripts (e.g. `python scripts/import/import_bible.py`), but they now default to full schema + gematria creation.

When the target database already contains data, the relevant script will print a warning and prompt exactly as:
"Selected database has data. It will be erased and replaced. Continue? (Y/N)"
This makes the process safe and simple for others to use right out of the box (no extra parameters or manual cleanup required for the common case).

**Critical PowerShell gotcha:** `$env:FOO = "bar"` only affects the **current PowerShell window** and programs you launch from it. Open a new terminal or restart PowerShell and the variable is gone.

There is a helper that makes this easy and also shows you the persistent command:

```powershell
# Session only (what you will use 95% of the time)
.\scripts\set-bible-db.ps1 -Name stepbibletest

# Make it survive new shells (writes to your user profile)
.\scripts\set-bible-db.ps1 -Name stepbibletest -Persist
# Then close this window and open a fresh one.
```

After using `-Persist` you must start a **new** PowerShell instance before Python (or the scripts) will see the value.

You can always check what the current process sees with:
```powershell
$env:BIBLE_DB_NAME
```

Every script that connects to the database now prints a live verification:

```
✓ Server reports current database: stepbibletest
✓ Confirmed: connection is using the resolved database name.
```

If the verification ever shows a mismatch, something is wrong with how the connection was obtained.

---

## Easiest Way to Create the stepbible Database (out-of-the-box for others)

```powershell
# 1. Create a clean empty database (or use an existing empty one)
mysql -u root -p -e "DROP DATABASE IF EXISTS stepbible; 
                     CREATE DATABASE stepbible 
                     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Point the scripts at it (use the helper for convenience + guidance)
.\scripts\set-bible-db.ps1 -Name stepbible
# (or for one-liner:  $env:BIBLE_DB_NAME="stepbible" )

# 3. Run the full pipeline (recommended).
#    It will create/refresh everything, warn/prompt if data exists, and remind you about web/config.php at the end.
python scripts/run_pipeline.py --db-name stepbible
```

This is the recommended "just works" path. No manual cleanup or extra flags needed for normal use or re-runs. The advanced flags (`--limit-verses`, `--dry-run`, `--truncate`) are kept only for development/debugging.

---

## Current Recommended Pipeline Order

For a complete fresh database (using `stepbible` as the example name):

```powershell
python scripts\run_pipeline.py --db-name stepbible
```

That single command:

- creates the target database (utf8mb4) if it doesn't exist yet
  (existing databases are left untouched; `import_bible.py` prompts before
  clobbering if there's data),
- runs all seven steps in order,
- streams output to the terminal and tees to `logs/pipeline-YYYYMMDD-HHMMSS.log`,
- stops on the first failure and prints a summary of what completed.

That's it. The orchestrator handles:

```
[1/7]  import_bible.py                STEPBible schema + TAHOT/TAGNT load
[2/7]  bible_na27.sql + bible_scr.sql + bible_kjv.sql
                                      External reference text dumps
             (must be present in data/raw/)
  + optional data/processed/p_variants_import.sql import
  + apply_p_variants_to_word.py  updates Hebrew word.text_original from P_value
[3/7]  compute_gematria.py            gematria_word + gematria_verse
[4/7]  populate_verseunicode.py       decode BibleWorks transliteration
[5/7]  build_edition_verse_text.py
  build_edition_word_slots.py    deterministic NA27/TR slot mapping
       diff_editions.py               Phase 3 variant emission
  verify_edition_rendering.py    global NA27/TR consistency check
[6/7]  add_text_search.py
       add_verse_search.py            phrase-search columns
[7/7]  fix_kjv_versification.py       verse_kjv_alt mapping (Rev 12:18 etc.)
```

`--db-name` is REQUIRED — no env-var fallback. If `BIBLE_DB_NAME` is set in
your shell to a different value, the orchestrator prints a warning so a
stale env var doesn't silently route the build at the wrong DB. The flag's
value is then propagated to every child via the subprocess environment, so
`_db.py`'s env-var contract continues to work downstream.

Useful flags:

```powershell
python scripts\run_pipeline.py --db-name stepbibletest --dry-run   # preview
python scripts\run_pipeline.py --db-name stepbible --force         # auto-yes to clobber-prompt
python scripts\run_pipeline.py --db-name stepbible --skip diff_editions  # debug
```

### Optional extras (run after the pipeline)

- **LXX-Rahlfs**: `python scripts\import\import_lxx.py` — loads `book_lxx`,
  `verse_lxx`, `word_lxx` (see `web/HANDOFF.md` § 12).
- **Diagnostic / cleanup scripts** in `scripts/maintenance/`:
  `cleanup_stale_variants.py`, `cleanup_hebrew_variants.py`,
  `find_strongs_equiv.py`, `fix_strongs_primary.py`. Run individually as
  needed; they are not part of a standard rebuild.

### Where the SQL dumps come from

`data/raw/bible_na27.sql`, `bible_scr.sql`, and `bible_kjv.sql` are mysqldump
exports of the corresponding tables from a fully-populated reference instance
(e.g., the live `biblewhe_stepbible` database). They never change once
captured. If you need to regenerate them after a content update on the live
side:

```bash
mysqldump -u USER -p biblewhe_stepbible bible_na27 > data/raw/bible_na27.sql
mysqldump -u USER -p biblewhe_stepbible bible_scr  > data/raw/bible_scr.sql
mysqldump -u USER -p biblewhe_stepbible bible_kjv  > data/raw/bible_kjv.sql
```

The advanced flags on `import_bible.py` (`--limit-verses`, `--dry-run`, `--truncate`) are only for development and debugging. Normal users invoke the orchestrator and never run `import_bible.py` directly.

Most steps are idempotent and safe to re-run.

Notes for P-variants workflow:
- Generate/update processed SQL with
  `python scripts/import/extract-and-insert-l-p-variants-into-sql.py "data/raw/TAHOT Gen-Deu - Translators Amalgamated Hebrew OT - STEPBible.org CC BY.txt"`.
- Place/update `data/processed/p_variants_import.sql` before running `run_pipeline.py`.
- The pipeline imports that table (if present) and applies `P_value` into matching Hebrew rows in `word.text_original`.
- The applier skips rows with obvious non-Hebrew payloads for safety.

---

## Current Folder Structure

```
BibleDB/
├── data/
│   ├── raw/                    # STEPBible source files + SQL dumps
│   │   ├── TAHOT *.txt         # 4 files (Hebrew OT)
│   │   ├── TAGNT *.txt         # 2 files (Greek NT)
│   │   ├── bible_na27.sql      # NA27 critical text dump
│   │   ├── bible_scr.sql       # Scrivener TR dump
│   │   ├── bible_kjv.sql       # KJV English (with inline Strong's) dump
│   │   └── LXX/                # LXX-Rahlfs source (optional)
│   └── processed/
├── docs/
│   ├── HANDOFF.md              # Historical / detailed session notes (older)
│   └── HANDOFF-current.md      # ← You are here (recommended)
├── logs/                       # Pipeline run logs (auto-created; gitignored)
├── scripts/
│   ├── _db.py                  # Shared connection helpers
│   ├── run_pipeline.py         # ← Orchestrator (the one command)
│   ├── set-bible-db.ps1        # PowerShell helper to set BIBLE_DB_NAME
│   ├── import/
│   │   ├── import_bible.py
│   │   ├── compute_gematria.py
│   │   ├── populate_verseunicode.py
│   │   ├── build_edition_verse_text.py
│   │   ├── build_edition_word_slots.py
│   │   ├── diff_editions.py
│   │   └── import_lxx.py       # optional extra
│   └── maintenance/
│       ├── add_text_search.py
│       ├── add_verse_search.py
│       ├── verify_edition_rendering.py
│       ├── fix_kjv_versification.py
│       ├── cleanup_*.py
│       └── find_strongs_equiv.py
├── sql/schema/
│   ├── schema.sql              # Core 11 tables + view
│   ├── gematria_schema.sql     # gematria_word + gematria_verse
│   └── lxx_schema.sql          # LXX tables (optional)
├── config.ini.sample
├── config.ini                  # (gitignored)
└── web/                        # PHP UI (has its own HANDOFF.md)
```

---

## Key Improvements Made Recently

- **Single-command orchestrator** (`scripts/run_pipeline.py`): seven steps with preflight checks, live + tee'd logging, and a stop-on-failure summary.
- **NA27, Scrivener TR, and KJV come from SQL dumps** in `data/raw/` — no external DB dependency. The old DB-to-DB importer (`import_bw_bibles.py`) has been removed.
- Database name is now **strictly** from `BIBLE_DB_NAME` (single source of truth) for individual scripts, and from `--db-name` (no env fallback) for the orchestrator.
- The import script always ensures core schema + gematria tables (with safety prompt on data) as part of creating a clean database.
- Schema loading from Python is now robust:
  - Strips hard-coded `CREATE DATABASE` / `USE` statements
  - Uses `SET FOREIGN_KEY_CHECKS` during drops
  - Works with both pymysql and mariadb connector
- Every database connection prints a live `SELECT DATABASE()` verification.

### Textual variant resolution (June 2026)

- **Problem:** NA27/TR displayed text was mostly correct, but variant indicators and occasional substitutions were unstable because rendering depended on accumulated/generated variant rows and slot-collision behavior.
- **Root cause:** generated `variant` + `variant_edition` rows were being used as part of the primary rendering path, so stale rows could survive algorithm changes and still affect UI output.
- **Resolution:**
  - `bible_na27` and `bible_scr` are treated as source of truth for NA27/TR text.
  - `build_edition_verse_text.py` overwrites NA27/TR from those source tables.
  - `build_edition_word_slots.py` creates deterministic `edition_word_slot` rows for NA27/TR.
  - web rendering for NA27/TR reads `edition_word_slot` rather than reconstructing from variant collisions.
  - `verify_edition_rendering.py` checks rendered NA27/TR output against `edition_verse_text` across all NT verses.
- **Result:** full-NT verifier reached zero mismatches for NA27 and TR in fresh-db reruns; manual verse-by-verse review is no longer required for text correctness validation.
- **UI note:** variant indicator bar is now config-controlled (`show_variant_indicator`) and currently disabled by default to avoid user-facing inconsistency while variant UX is refined.

---

## When to Use the Old HANDOFF.md

Keep using `docs/HANDOFF.md` if you need deep historical context, rationale from specific sessions, or details about one-off scripts and variant cleanup work.

For day-to-day work or onboarding, prefer this file (`HANDOFF-current.md`).

---

## Quick Commands

| Goal | Command |
|---|---|
| Build the full DB end-to-end | `python scripts\run_pipeline.py --db-name stepbible` |
| Preview without running | `python scripts\run_pipeline.py --db-name stepbibletest --dry-run` |
| Force-clobber re-run | `python scripts\run_pipeline.py --db-name stepbible --force` |
| Skip one step (debug) | `python scripts\run_pipeline.py --db-name stepbible --skip diff_editions` |
| Add the LXX (optional) | `python scripts\import\import_lxx.py` |
| See what DB you're using | Any single script prints a live `SELECT DATABASE()` verification on connect |

---

**Last major update:** Data folder (`data/`) added to the repository with Git LFS for the large source files and SQL dumps (required for running the pipeline out-of-the-box). Previous major work: full collaborative verse notes system (with phpBB editor reuse, edit-existing + "Create new note", CSRF protection, remote API parity/proxy, delete support, length validation, renderer fixes).

## Collaborative per-verse notes / commentary (with gematria autofill)

Each user (via phpBB integration or dev fallback) can create any number of notes linked to specific verses. Notes are public and visible to all (for building a shared Bible commentary).

- Table `verse_notes` is ensured by the pipeline (run `python scripts/run_pipeline.py --db-name yourdb` to create on existing DBs; new DBs get it from schema.sql).
- On verse pages (index.php), each verse number has a "+ note" button.
- Modal behavior (title is **required**, never optional):
  - If you already have one or more notes on this verse, clicking + note pre-fills the form in **edit mode** using your most recent note (so a single growing note per verse is the common case).
  - A "Create new note" button appears; click it to clear the form for an additional note on the same verse (distinguished by its title, e.g. "First word 913" vs "Last two words 703").
  - Your own notes in the "Existing notes" list below the form get an [edit] button to load any of them into the form.
  - note_type: general (commentary) or gematria
  - title (required — used to distinguish multiple notes for same verse)
  - note_text (BBCode supported via toolbar that reuses phpBB's editor.js insertion logic + caret handling for consistency with forum posts; same toolbar also powers the personal notebook)
  - For gematria type: gem_std/ord/red fields with buttons to autopopulate:
    - "Fill from verse": sums gem values of all words in that verse block (using data-gem-* attrs on .word-cell, which come from gematria_word).
    - "Fill from selection": sums only currently selected words (reuses the S+drag selection UI and gem data already powering the gematria panel).
- Existing notes for the verse are listed in the modal (titles are prominent for distinction; username, type, rendered BBCode). Your notes are editable from the list or auto-loaded.
- Count badges (N) appear next to verse numbers; clicking them opens the modal (edit or create as appropriate).
- Save uses `api.php?api=create_verse_note` or `update_verse_note` (POST). On success the list + count badge refresh in-place (no full reload).
- Listing via `api.php?api=verse_notes`.
- Title column is NOT NULL in schema + pipeline (with migration that backfills legacy empty titles using "Note <id>").
- The personal notebook (`notes.php` + old user_notes table) remains separate for private scratchpad use.

Configure `'phpbb_path'` in web/config.php for real user IDs/usernames from your forum (light bootstrap only for notes feature; no heavy integration required on every page). Dev fallback uses session.

This fulfills per-user multiple notes (with required titles for distinction), auto edit-existing on +note click + explicit "Create new note", verse-linked storage for commentary, edit/update/delete flow, CSRF protection on mutating endpoints, remote-API parity (proxied reads/writes so `use_remote_api` dev mode works for notes too), phpBB editor.js reuse for input, and gematria value autofill from verse/selected words. `fix_kjv_versification.py` handles the five NA28↔KJV verse-numbering anomalies (Rev 12:18, Php 1:16/1:17, 2Co 13:13, 3Jn 1:15).

### Post-deploy / architecture notes (from review)
- CSRF tokens are per-session (generated on first access to a notes page). API POSTs for notes (create/update/delete) and the personal notebook form POST validate it (via meta tag for JS, hidden input for forms, or X-CSRF-Token header).
- Remote proxy for verse_notes: when `use_remote_api=true`, GET lists and (create/update/delete) POSTs are forwarded via `remote_api_call` (with `proxy_user_*` for authorship on writes). The receiving live instance trusts the proxy context for user attribution (normal browser posts to live still use phpBB session).
- `user_notes` (personal) remain local-only (scratchpad); `verse_notes` are the public/collaborative ones. They are intentionally separate for MVP (see discussion below).
- Dev fallback (id 999999) shares state across "users" on that machine — documented as dev-only.
- `get_bible_user()` (and thus CSRF token gen) must be called before output to avoid "headers already sent" on session_start.
- Username is denormalized at create time (audit choice). Renames in phpBB won't update historical notes.
- The two stores don't auto-sync (adding via modal doesn't populate personal notebook). Possible futures: keep separate, unify under verse_notes, or add "my verse notes" view in notebook.
- List regex and basic BBCode renderer are sufficient for common use; advanced nesting in lists or heavy use may need a real parser later.
- Added length caps (title 255, text ~64k) with 400 errors + client pre-checks. Delete support added symmetrically (owner only, CSRF, remote proxy, UI button next to "edit" in modal list).
- `save_user_notes` uses modern INSERT ... AS new ON DUPLICATE syntax.
- `bbcode_to_html` now masks [code] regions before nl2br to avoid <br> inside <pre>.

## Latest (as of 2026-06-02)

### Notes modal UX overhaul (committed this session)
The verse-notes modal popup was significantly improved:

- **Collapsed form by default when notes exist**: Opening the modal now shows only the existing notes list. The add/edit form is hidden until needed, so the user's first view is the rendered notes.
- **"+ Add Note" button in header**: Added to the title bar next to the × close button. Opens the blank form for a new note.
- **Long book name in title**: Modal header now shows the full book name (e.g. "Genesis" not "Gen") pulled from the book selector's `data-full` attribute.
- **Edit/Delete buttons on the title line**: Each note's title line shows the note title, type tags, author, date, and then (for own notes) **Edit** / **Delete** buttons inline — no separate row below the note body.
- **Notes render fully, no internal scrollbar**: The notes list no longer has `max-height` or its own scroll. Notes render top-to-bottom in full, and the modal itself scrolls as a unit.
- **Title field**: Label simplified to `Title *` (red asterisk). The HTML `required` attribute was replaced with a JS `setCustomValidity` check that shows a descriptive inline tooltip: *"A title is required. Use something descriptive like 'First word 913' or 'Aleph connection' to identify this note."* Clears automatically when the user starts typing.
- **Cancel closes form only** (not the whole modal) when existing notes are present.
- **After save**: Form collapses back to the notes list view.
- **No notes yet**: Form opens immediately (no point showing an empty list first).

**To pick up in a brand new session:** Read this entire file first, then ask the AI to focus on the specific next item.

## Recommendation on long sessions
For best results, start a fresh conversation and paste the key parts of this HANDOFF (or just say "read docs/HANDOFF-current.md and tell me the current state"). Fresh sessions tend to stay sharper on complex projects like this.
## Latest (as of 2026-06-09)

### FOLDER LAYOUT — READ FIRST (settled this session after much confusion)

Three locations exist; only one is canonical:

1. **`C:\Work\Resurrected\Bible Wheel Site\BibleDB`** — ★ CANONICAL source of
   truth. Git repo. All development happens here.
2. **`C:\Work\Resurrected\Bible Wheel Site\public_html\bible`** — localhost
   deployment target. NEVER edit directly. Deploy with
   `scripts/sync-web-to-public.ps1` (dry-run by default; add `-Apply`).
3. **`C:\Work\Resurrected\Claude\BibleDB\Bible Database`** — DEPRECATED old
   snapshot (pre-reorganization layout, no git). A Cowork session on
   2026-06-09 accidentally developed there first; everything of value has
   been ported here (including `scripts/import/import_bw_bibles.py`, which
   existed only there). Its HANDOFF.md now carries a deprecation banner.
   Do not develop there.

### Gematria group brackets — phase 1 (this session, via Cowork)

Labeled underline brackets annotate word groups in the interlinear with
their gematria values (e.g. the Shema's nested 13 / 2×13 / 3×13 / 13×86).
Design rationale, rendering model, and phase plan: **`docs/group-brackets-design.md`**.

- `web/js/group-brackets.js` (new) — groups are sets of `data-pos` ordinals;
  one bracket fragment per (visual line × contiguous run), open edges mark
  line-wrap continuation; nesting depth from set-containment; primes shared
  by ≥2 groups auto-colored, click a prime to pin (`?gpin=`); labels
  click-to-edit (↺ resets, × deletes); state in `?groups=1-7;6-7|label` via
  history.replaceState (the copy-link button inherits it). Hooks: wraps
  `_gemRebuild` + ResizeObserver; reserves bracket room via row-gap override.
- `web/style.css` — `.bracket-layer/.bracket-frag/.bracket-label/.gem-groups`
  block appended; `.interlinear` gains `position:relative`.
- `web/index.php` — `⊓ group` button + `#gem-groups` list in the gematria
  panel; script tag after deep-link.js.
- Usage: select words (click/paint) → `⊓ group` appears in the gematria
  panel. Verified working by Richard.
- Deferred: phase 2 = keep-group-on-one-line layout pass + label collision
  polish; phase 3 = mobile (tap popover, long-press paint). See design doc.

**All of the above committed 2026-06-09 evening** (commits `5f8e7a9`,
`ef7db9f`, `d0d517a`). Remember to deploy web changes with
sync-web-to-public.ps1.

### Session 2 (2026-06-09 evening)

- **Verse number column** now vertically centers against whatever interlinear
  rows are visible (was a hardcoded 36px offset).
- **Group brackets phase 1.5**: adjacent groups share a bracket row (only true
  overlap bumps; fragments inset 1.5px/end so neighbors never touch);
  non-contiguous groups get a single connector (risers from first/last/middle
  fragment centers to a bar through the label midline, label-collision-aware);
  labels center across the cluster span; word-spacing slider triggers live
  bracket redraw via `window._bracketsRedraw`. Verified beautifully on Gen 1:1
  (999/703/1998/2701 all × 37) and John 8:38. Design doc updated.
- **include/ cleanup**: the legacy biblewheel.com chrome replicas
  (`include/`, `web/include/`) were deleted from the repo — they contained
  live DB credentials (wp-config.php) and an outdated nav implementation.
  Canonical local copy: `public_html/include` (config.php `include_dir` and
  helpers.php fallbacks now point there; gitignore guards prevent re-adding).
  The third copy at `Bible Wheel Site/include/include` is now referenced by
  nothing — archive or delete at leisure.

### Session 3 (2026-06-10) — brackets above/below, Bible Gems branding

Commits `ef13f7b`, `f7cf6d1` (plus `bf81b1d`/`d0d517a` follow-ups earlier).
All deployed to localhost AND uploaded to the live site (verified working).

- **Brackets above/below the text**: each group has an `above` flag
  (persisted as `^` prefix in `?groups=`). Chips sit either side of a
  vertical pipe divider in the gematria bar — drag across it to flip.
  Above-side renders fully inverted; independent collision stacks per side.
- **Collision model**: a group occupies fragments + connector bars; any
  horizontal overlap bumps. Chip glyphs: ⊔ below / ⊓ above.
- **Presentation highlight**: chip click toggles a persistent amber light
  on the group's words + label (one at a time, survives re-renders).
- **Bible Gems branding**: tool renamed from Bible DB/Browser/Explorer.
  Sidebar = brand + Prov 25:2 tagline (justified, hyphens, lang="en");
  index nav link = "Gem Finder"; dismissable orientation subtitle on
  index.php (localStorage `gem-subtitle-dismissed`); help.php fully
  rewritten (Quick Start, word-groups guide, mouse/keyboard reference).
- **Live-site note**: the site menu label lives in
  `public_html/include/menu/main_menu.inc` ("Bible Gems" + tooltip) —
  that file deploys to the live `/include/menu/` separately from the
  bible app folder. Live style.css must be re-uploaded whenever web/
  changes ship (a stale copy caused unstyled tagline on live).

### Session 4 (2026-06-10 evening) — letter toggling (commit 406b9ab)

- **Letter toggling live on the Gem Finder**: Alt+click (or hold L) a
  letter → dims light gray, excluded from word value / selection sums /
  bracket labels / letter count. Whole-verse totals ALWAYS include every
  letter (the toggle separates a letter from its word for study; it never
  changes the verse). Persists as `lo=WORDID-LETIDX|...` in the URL.
  Implementation: letter-select.js study mode activated on index.php
  (capture-phase click so word-selection.js doesn't fire first), parashah
  markers skipped in splitHebrew, baseChar fixed for pointed Hebrew,
  gematria.js sums letter spans with an includeOff flag for verse totals.
  Showcase: Gen 2:9 minus the two vav prefixes → Tree of Life = 233,
  Tree of Knowledge of good and evil = 932 = 4 × 233. Works in Greek too
  (iota subscript = its own ι = 10 letter).
- Sidebar tagline "Proverbs 25:2" is now a quiet dotted link to that verse
  with words 1–3 bracketed: 777 = 3 × 7 × 37 (the concealed gem).

### PARKED — mobile letter-toggle popover (plan agreed, not built)

Letters are far below the 44px touch-target minimum, so mobile letter
toggling needs a magnified popover, not direct taps:

- **Long-press a word cell** (~500ms, pointer events; context-menu and
  user-select are already suppressed) → popover anchored to the cell.
- Popover shows the word at 3–4× size, letters spaced as fat tap targets;
  **tap a letter to toggle** — grays in popover and verse simultaneously,
  all values update live.
- Each letter gets a small value chip beneath it (ב = 2, ר = 200 …) and
  the word's running total recomputes at the bottom — a teaching view,
  worth exposing on desktop too (right-click or modifier).
- Dismiss = tap outside. State rides the existing lo= machinery; the
  popover is just a magnified input surface.
- **Gesture budget decision**: long-press belongs to the letter popover,
  NOT paint-select (design doc phase 3 originally reserved it for paint).
  Word selection stays plain taps; if mobile paint-select is ever needed,
  use an explicit select-mode toggle button instead.
- Build is self-contained in letter-select.js + CSS.

Also parked: bracket phase 2 (keep-group-on-one-line, label collision
polish between different groups) and phase 3 mobile for groups.
