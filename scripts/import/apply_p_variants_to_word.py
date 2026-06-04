#!/usr/bin/env python3
"""
apply_p_variants_to_word.py
---------------------------
Apply p_variants_import.P_value to matching Hebrew words and verses.

Match key:
  p_variants_import(book, chapter, verse, position)
    -> book.osis_code + word(book/chapter/verse/position)

Safety behavior:
  - Requires p_variants_import table to exist; otherwise exits cleanly (no-op).
  - Updates only Hebrew rows (word.language = 'Hebrew').
    - Applies simple token replacement in verse.text_original by verse_id
        using L_value -> P_value for matched rows.
  - Skips rows where P_value contains obvious non-Hebrew payload
    (ASCII letters/digits), which protects against parser glitches.
  - Supports --dry-run to preview update/skip counts.

Usage:
  python scripts/import/apply_p_variants_to_word.py [--config path/to/config.ini] [--dry-run]
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

_scripts_dir = Path(__file__).resolve().parent.parent
if str(_scripts_dir) not in sys.path:
    sys.path.insert(0, str(_scripts_dir))

from _db import connect  # type: ignore[import-not-found]

# Accept Hebrew block + combining marks + common punctuation/separators seen in source.
_ALLOWED_CHARS_RE = re.compile(r"^[\u0590-\u05FF\uFB1D-\uFB4F/\\\-\u05BE\u05C0\u05C3\u05F3\u05F4\s]+$")
_ASCII_WORD_RE = re.compile(r"[A-Za-z0-9]")


def is_safe_p_value(value: str) -> bool:
    if not value:
        return False
    if _ASCII_WORD_RE.search(value):
        return False
    return _ALLOWED_CHARS_RE.match(value) is not None


def table_exists(cur, db_name: str, table_name: str) -> bool:
    cur.execute(
        """
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
        """,
        (db_name, table_name),
    )
    return (cur.fetchone()[0] or 0) > 0


def main() -> int:
    ap = argparse.ArgumentParser(description="Apply p_variants_import P_value into word.text_original and verse.text_original")
    project_root = Path(__file__).resolve().parent.parent.parent
    ap.add_argument("--config", default=str(project_root / "config.ini"), help="Path to config.ini")
    ap.add_argument("--dry-run", action="store_true", help="Preview only; do not write updates")
    args = ap.parse_args()

    conn, cur, cfg = connect(args.config)
    print(f"Target DB: {cfg['database']}")

    try:
        if not table_exists(cur, cfg["database"], "p_variants_import"):
            print("p_variants_import table not found; nothing to apply.")
            return 0

        cur.execute(
            """
            SELECT
                w.id,
                w.verse_id,
                p.full_ref,
                p.L_value,
                p.P_value,
                w.text_original
            FROM p_variants_import p
            JOIN book b
              ON b.osis_code = p.book
            JOIN word w
              ON w.book_id = b.id
             AND w.chapter = p.chapter
             AND w.verse = p.verse
             AND w.position = p.position
            WHERE w.language = 'Hebrew'
            ORDER BY b.id, p.chapter, p.verse, p.position
            """
        )
        rows = cur.fetchall()

        to_update: list[tuple[str, int]] = []
        verse_replacements: list[tuple[int, str, str]] = []
        skipped_unsafe = 0
        unchanged = 0

        for word_id, verse_id, full_ref, l_value, p_value, current_text in rows:
            l_value = l_value or ""
            p_value = p_value or ""
            current_text = current_text or ""
            if not is_safe_p_value(p_value):
                skipped_unsafe += 1
                continue

            # Keep verse-level replacement candidates independent of whether
            # word.text_original is already updated.
            if l_value and l_value != p_value:
                verse_replacements.append((int(verse_id), l_value, p_value))

            if current_text == p_value:
                unchanged += 1
                continue
            to_update.append((p_value, int(word_id)))

        print(f"Matched rows: {len(rows):,}")
        print(f"Unchanged (already P): {unchanged:,}")
        print(f"Skipped unsafe P_value: {skipped_unsafe:,}")
        print(f"Pending updates: {len(to_update):,}")

        verse_exists = table_exists(cur, cfg["database"], "verse")
        pending_verse_replace = 0
        if verse_exists and verse_replacements:
            verse_ids = sorted({vid for vid, _, _ in verse_replacements})
            placeholders = ",".join(["%s"] * len(verse_ids))
            cur.execute(
                f"SELECT id, text_original FROM verse WHERE id IN ({placeholders})",
                tuple(verse_ids),
            )
            verse_text = {int(vid): (txt or "") for vid, txt in cur.fetchall()}
            for verse_id, old_text, new_text in verse_replacements:
                txt = verse_text.get(verse_id)
                if txt and old_text and old_text in txt and old_text != new_text:
                    pending_verse_replace += 1

        if verse_exists:
            print(f"Pending verse.text_original replacements: {pending_verse_replace:,}")
        else:
            print("verse table not found; skipping verse-text replacement.")

        if args.dry_run:
            print("Dry run complete; no updates written.")
            return 0

        if to_update:
            cur.executemany("UPDATE word SET text_original = %s WHERE id = %s", to_update)
            conn.commit()
            print(f"Applied updates: {len(to_update):,}")
        else:
            print("No updates needed.")

        if verse_exists and verse_replacements:
            verse_updates = 0
            for verse_id, old_text, new_text in verse_replacements:
                if not old_text or old_text == new_text:
                    continue
                cur.execute(
                    """
                    UPDATE verse
                    SET text_original = REPLACE(text_original, %s, %s)
                    WHERE id = %s
                      AND text_original LIKE %s
                    """,
                    (old_text, new_text, verse_id, f"%{old_text}%"),
                )
                if cur.rowcount and cur.rowcount > 0:
                    verse_updates += int(cur.rowcount)
            conn.commit()
            print(f"Applied verse.text_original replacements: {verse_updates:,}")

        return 0
    finally:
        try:
            cur.close()
        except Exception:
            pass
        try:
            conn.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
