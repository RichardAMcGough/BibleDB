# Gematria group brackets — design (agreed 2026-06-09)

Feature: annotate word groups in the interlinear with labeled underline brackets
showing each group's gematria value, so nested/overlapping numeric relations
(e.g. the Shema's four nested multiples of 13) are visible in the running text.

Design history: boxes-around-words (the user's original sketch) were prototyped
conceptually and rejected — overlapping boxes become unreadable. Stacked
underline brackets (math "underbrace" / linguistics span-annotation idiom) won:
vertical depth encodes nesting, overlaps never cross lines.

## Representation

- Each group renders as a bracket (left edge + bottom + right edge, 2px) drawn
  **below** the word cells it spans, with a label underneath the bracket.
- **Depth encodes nesting**: innermost groups hug the text; a group that
  strictly contains another sits at least one row lower. Computed from
  set-containment over member positions. Non-nested overlapping groups get
  bumped to the first collision-free row (per-line interval tracking).
- **Line wrapping**: a group spanning a visual line break renders one fragment
  per line; the continuing edge is drawn open (no side border, bottom border
  fades). Label sits under the group's widest same-line fragment cluster,
  centered across that cluster's full span. RTL-safe: fragments are computed
  from cell bounding rects, so direction never matters.
- **Adjacency vs. overlap** (2026-06-09 session 2): only a true horizontal
  overlap bumps a group to a lower row — adjacent groups share the row. Each
  fragment is inset 1.5px per end (3px narrower) so same-row neighbors never
  touch, even at word-gap 0.
- **Non-contiguous groups**: fragments on one visual line are tied together by
  a single connector — risers drop from the centers of the first and last
  fragments to a bar at the label text's midline (the label's background masks
  the bar behind the text), and each middle fragment drops a tiny riser to the
  bar unless it would collide with the label (checked post-render against the
  measured label box). The connector lives inside the existing label row, so
  it costs no extra vertical space.
- **Phase 2 (deferred)**: optional keep-together pass — force a line break
  before a group that would fit unwrapped on one line. Needs iterate-to-stable
  reflow; nothing in phase 1 blocks it.
- Vertical space: flex-wrap lines aren't DOM nodes, so per-line padding is
  impossible; instead `#interlinear`'s row-gap (and padding-bottom for the last
  line) is set to fit the deepest bracket stack on any line. Uniform but stable.

## Labels & prime coloring

- Default label = computed from the displayed cells' `data-gem-*` attributes:
  standard value with factorization (`1118 = 2 × 13 × 43`), plus `Ord`/`Red`
  values appended when those option checkboxes are active. Recomputed on
  variant cycling / edition load via the `_gemRebuild` wrapper (see Hooks).
- **Theme primes**: any prime appearing in ≥ 2 groups' standard factorizations
  is automatically assigned a consistent color everywhere it appears
  (discovery affordance). User can **pin** primes (click a prime in a default
  label to toggle); pins are colored first and persist in the URL (`gpin=`).
- **Custom labels**: click label → contenteditable; Enter/blur commits, Esc
  cancels. Custom text replaces the computed label (e.g. `ONE × GOD = 13 × 86`);
  numbers in custom text matching theme primes still get colored. A small ↺
  on hover resets to computed; × deletes the group.

## Group identity & URL persistence

- Cells are addressed by `data-pos` — the global 1-based ordinal across the
  displayed verse range (same convention as the existing `?selected=` deep
  link). Deterministic given book/chapter/verse/show/edition, all already in
  the URL.
- `&groups=` = `;`-separated groups; each is comma-separated ranges
  (`1-7,9` — non-contiguous allowed), optional `|`-suffixed
  encodeURIComponent'd custom label. `&gpin=13,43` for pinned primes.
- Every mutation calls `history.replaceState`, so the existing copy-link
  button (which serializes `location.search`) shares groups for free.
- Groups may span verse boundaries (positions are continuous across the range).

## Interaction (phase 1 = desktop)

- Create: select cells with the existing click/paint mechanism → "⊓ group"
  button in the gematria panel (visible when selection non-empty) creates a
  group and clears the selection.
- Manage: group list in the gematria panel (value · word count · ×); label
  edit/reset/delete on the brackets themselves.
- Boxed groups do NOT affect the gematria panel's selection-driven sum —
  brackets are display annotations (agreed).
- **Phase 3 (deferred, mobile)**: tap on bracket/label opens a popover with
  full factorization + edit/recolor/delete (replaces hover affordances and
  rescues labels too wide for narrow fragments); long-press to paint-select.
  Rendering already degrades gracefully on narrow screens (more fragments).

## Implementation map

- `web/js/group-brackets.js` (new) — model, URL codec, renderer, label editor.
- `web/style.css` — `.bracket-layer`, `.bracket-frag`, `.bracket-label`,
  `.gem-group-btn`, `#gem-groups` styles.
- `web/index.php` — `⊓ group` button + `#gem-groups` div in the gematria
  panel; `<script src="js/group-brackets.js">` after `deep-link.js`.
- No PHP/DB changes. No changes to existing JS modules.

## Hooks

- Geometry: `ResizeObserver` on `#interlinear` (covers window resize,
  font-size CSS-var changes from options.js, display-row toggles). Row-gap
  writes are guarded (only when changed) to avoid RO feedback loops.
- Values: wrap `window._gemRebuild` — variant-switcher calls it after cycling
  and on `syncGematriaOnLoad`, so labels recompute automatically.

## Palette

Bracket strokes neutral (`#6b7280`, matches --muted). Prime colors (mid-ramp,
assigned pins-first then by occurrence count): coral `#D85A30`, teal `#1D9E75`,
purple `#534AB7`, pink `#D4537E`, amber `#BA7517`, blue `#378ADD`.
