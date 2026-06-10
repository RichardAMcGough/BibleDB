Bible Database project — STEPBible-tagged Hebrew OT + Greek NT in local
MariaDB (`stepbible`), PHP web UI in `web/`, deployed to
`..\public_html\bible` via `scripts/sync-web-to-public.ps1 -Apply`.

THIS folder (`Bible Wheel Site\BibleDB`) is the canonical source of truth.
Never edit `public_html\bible` directly. The old folder at
`C:\Work\Resurrected\Claude\BibleDB\Bible Database` is deprecated.

Read `docs/HANDOFF-current.md` first (current state + conventions), starting
with the FOLDER LAYOUT section at the end. `docs/HANDOFF.md` is the longer
historical log. Feature design docs live in `docs/` (e.g.
`group-brackets-design.md`).
