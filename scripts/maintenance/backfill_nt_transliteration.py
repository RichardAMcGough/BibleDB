#!/usr/bin/env python3
"""
backfill_nt_transliteration.py

Populate transliteration for NT Greek editions NA28/TR in three places:

1) word.transliteration for words tagged to NA28/TR
2) variant.transliteration for variants tagged to NA28/TR
3) edition_word_slot.transliteration (adds the column if missing), including
   slot-only outliers where word_id IS NULL

Primary source is parenthesized transliteration in text fields, e.g.
    "Ἐν (En)" -> transliteration "En"

For rows without parenthesized transliteration, the script falls back to:
  - a learned lexicon built from observed Greek->translit pairs in the DB
  - then deterministic character mapping for any remaining Greek tokens

Run:
  python scripts/maintenance/backfill_nt_transliteration.py --apply
  python scripts/maintenance/backfill_nt_transliteration.py --dry-run
"""

from __future__ import annotations

import argparse
import collections
import re
import sys
import unicodedata
from pathlib import Path
from typing import Dict, Optional, Tuple

project_root = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(project_root / "scripts"))
from _db import load_config, get_connection  # type: ignore[import-not-found]


PAREN_RE = re.compile(r"^(.*?)\s+\((.+?)\)\s*$", re.UNICODE)
WS_RE = re.compile(r"\s+", re.UNICODE)
COMBINING_RE = re.compile("[\u0300-\u036F]", re.UNICODE)
NON_GREEK_LETTER_RE = re.compile(r"[^\u0370-\u03FF\u1F00-\u1FFF\s]+", re.UNICODE)

# Simple deterministic fallback mapping for Greek letters.
GREEK_CHAR_MAP = {
    "α": "a", "β": "b", "γ": "g", "δ": "d", "ε": "e", "ζ": "z",
    "η": "e", "θ": "th", "ι": "i", "κ": "k", "λ": "l", "μ": "m",
    "ν": "n", "ξ": "x", "ο": "o", "π": "p", "ρ": "r", "σ": "s",
    "ς": "s", "τ": "t", "υ": "u", "φ": "ph", "χ": "ch", "ψ": "ps",
    "ω": "o",
}


def parse_args() -> argparse.Namespace:
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default=str(project_root / "config.ini"))
    g = ap.add_mutually_exclusive_group()
    g.add_argument("--apply", action="store_true", help="Apply DB updates")
    g.add_argument("--dry-run", action="store_true", help="Preview only (default)")
    ap.add_argument(
        "--refresh-slots",
        action="store_true",
        help="Recompute slot transliteration for ALL NA28/TR slots from token_text (not only blanks)",
    )
    return ap.parse_args()


def strip_paren_translit(text: str) -> str:
    m = PAREN_RE.match((text or "").strip())
    if not m:
        return (text or "").strip()
    return WS_RE.sub(" ", m.group(1).strip())


def extract_paren_pair(text: str) -> Optional[Tuple[str, str]]:
    m = PAREN_RE.match((text or "").strip())
    if not m:
        return None
    greek = WS_RE.sub(" ", m.group(1).strip())
    translit = WS_RE.sub(" ", m.group(2).strip())
    if not greek or not translit:
        return None
    return greek, translit


def normalize_greek_key(text: str) -> str:
    if not text:
        return ""
    t = strip_paren_translit(text)
    t = unicodedata.normalize("NFD", t)
    t = COMBINING_RE.sub("", t)
    t = unicodedata.normalize("NFC", t)
    t = t.lower()
    t = NON_GREEK_LETTER_RE.sub("", t)
    t = WS_RE.sub(" ", t).strip()
    return t


def translit_fallback(text: str) -> str:
    key = normalize_greek_key(text)
    if not key:
        return ""
    out = []
    for ch in key:
        if ch.isspace():
            out.append(" ")
            continue
        out.append(GREEK_CHAR_MAP.get(ch, ""))
    return WS_RE.sub(" ", "".join(out)).strip()


def ensure_slot_translit_column(cur, conn, db_name: str) -> None:
    cur.execute(
        """
        SELECT COUNT(*)
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = %s
           AND TABLE_NAME = 'edition_word_slot'
           AND COLUMN_NAME = 'transliteration'
        """,
        (db_name,),
    )
    exists = (cur.fetchone()[0] or 0) > 0
    if exists:
        return
    cur.execute(
        "ALTER TABLE edition_word_slot ADD COLUMN transliteration VARCHAR(255) NULL AFTER token_text"
    )
    conn.commit()


def load_edition_ids(cur) -> Dict[str, int]:
    cur.execute("SELECT id, code FROM edition WHERE code IN ('NA28','TR')")
    rows = cur.fetchall()
    out = {str(code): int(eid) for eid, code in rows}
    if "NA28" not in out or "TR" not in out:
        raise RuntimeError("Could not resolve edition IDs for NA28/TR")
    return out


def build_lexicon(cur, edition_ids: Dict[str, int]) -> Dict[str, str]:
    counts: Dict[str, collections.Counter[str]] = {}

    marks = ",".join(["%s", "%s"])
    cur.execute(
        f"""
        SELECT DISTINCT w.text_original
          FROM word w
          JOIN word_edition we ON we.word_id = w.id
         WHERE we.edition_id IN ({marks})
           AND w.text_original IS NOT NULL
        """,
        (edition_ids["NA28"], edition_ids["TR"]),
    )
    for (txt,) in cur.fetchall():
        pair = extract_paren_pair(txt or "")
        if not pair:
            continue
        greek, translit = pair
        key = normalize_greek_key(greek)
        if not key:
            continue
        counts.setdefault(key, collections.Counter())[translit] += 1

    cur.execute(
        f"""
        SELECT DISTINCT v.text_original, v.transliteration
          FROM variant v
          JOIN variant_edition ve ON ve.variant_id = v.id
         WHERE ve.edition_id IN ({marks})
        """,
        (edition_ids["NA28"], edition_ids["TR"]),
    )
    for txt, tl in cur.fetchall():
        pair = extract_paren_pair(txt or "")
        if pair:
            greek, translit = pair
            key = normalize_greek_key(greek)
            if key and translit:
                counts.setdefault(key, collections.Counter())[translit] += 1
        if tl:
            key = normalize_greek_key(txt or "")
            if key:
                counts.setdefault(key, collections.Counter())[str(tl).strip()] += 1

    lexicon = {}
    for k, ctr in counts.items():
        if not ctr:
            continue
        lexicon[k] = ctr.most_common(1)[0][0]
    return lexicon


def choose_translit(text: str, lexicon: Dict[str, str]) -> str:
    pair = extract_paren_pair(text or "")
    if pair:
        return pair[1]
    key = normalize_greek_key(text or "")
    if key and key in lexicon:
        return lexicon[key]
    return translit_fallback(text or "")


def backfill_words(cur, edition_ids: Dict[str, int], lexicon: Dict[str, str], apply: bool) -> Tuple[int, int]:
    marks = ",".join(["%s", "%s"])
    cur.execute(
        f"""
        SELECT DISTINCT w.id, w.text_original, w.transliteration
          FROM word w
          JOIN word_edition we ON we.word_id = w.id
         WHERE we.edition_id IN ({marks})
        """,
        (edition_ids["NA28"], edition_ids["TR"]),
    )
    rows = cur.fetchall()
    updates = []
    considered = 0
    for wid, txt, tl in rows:
        if tl is not None and str(tl).strip() != "":
            continue
        considered += 1
        new_tl = choose_translit(txt or "", lexicon)
        if not new_tl:
            continue
        updates.append((new_tl, int(wid)))

    if apply and updates:
        cur.executemany("UPDATE word SET transliteration = %s WHERE id = %s", updates)
    return considered, len(updates)


def backfill_variants(cur, edition_ids: Dict[str, int], lexicon: Dict[str, str], apply: bool) -> Tuple[int, int]:
    marks = ",".join(["%s", "%s"])
    cur.execute(
        f"""
        SELECT DISTINCT v.id, v.text_original, v.transliteration
          FROM variant v
          JOIN variant_edition ve ON ve.variant_id = v.id
         WHERE ve.edition_id IN ({marks})
        """,
        (edition_ids["NA28"], edition_ids["TR"]),
    )
    rows = cur.fetchall()
    updates = []
    considered = 0
    for vid, txt, tl in rows:
        if tl is not None and str(tl).strip() != "":
            continue
        considered += 1
        new_tl = choose_translit(txt or "", lexicon)
        if not new_tl:
            continue
        updates.append((new_tl, int(vid)))

    if apply and updates:
        cur.executemany("UPDATE variant SET transliteration = %s WHERE id = %s", updates)
    return considered, len(updates)


def backfill_slots(cur, edition_ids: Dict[str, int], lexicon: Dict[str, str], apply: bool, refresh_all: bool = False) -> Tuple[int, int, int]:
    marks = ",".join(["%s", "%s"])
    cur.execute(
        f"""
        SELECT ews.id,
               ews.token_text,
               ews.transliteration,
               ews.word_id,
               w.text_original,
               w.transliteration
          FROM edition_word_slot ews
          LEFT JOIN word w ON w.id = ews.word_id
         WHERE ews.edition_id IN ({marks})
        """,
        (edition_ids["NA28"], edition_ids["TR"]),
    )
    rows = cur.fetchall()
    updates = []
    considered = 0
    outlier_updates = 0

    for slot_id, token, slot_tl, word_id, word_text, word_tl in rows:
        if not refresh_all and slot_tl is not None and str(slot_tl).strip() != "":
            continue
        considered += 1

        # Slot token text is the rendered edition truth for this slot, so
        # transliteration should be derived from token first.
        new_tl = choose_translit(token or "", lexicon)
        if not new_tl:
            # Fallback for rare empty/odd token cases.
            if word_tl is not None and str(word_tl).strip() != "":
                new_tl = str(word_tl).strip()
            else:
                seed_text = word_text if (word_text and str(word_text).strip()) else token
                new_tl = choose_translit(seed_text or "", lexicon)

        if not new_tl:
            continue
        if word_id is None:
            outlier_updates += 1
        updates.append((new_tl, int(slot_id)))

    if apply and updates:
        cur.executemany("UPDATE edition_word_slot SET transliteration = %s WHERE id = %s", updates)
    return considered, len(updates), outlier_updates


def main() -> None:
    args = parse_args()
    apply = bool(args.apply)
    if not args.apply and not args.dry_run:
        # Safe default.
        apply = False

    cfg = load_config(args.config)
    print(f"Connecting to {cfg['user']}@{cfg['host']}:{cfg['port']}/{cfg['database']} ...")
    conn, _driver = get_connection(cfg)
    cur = conn.cursor()

    edition_ids = load_edition_ids(cur)
    print(f"Edition IDs: NA28={edition_ids['NA28']}  TR={edition_ids['TR']}")

    ensure_slot_translit_column(cur, conn, cfg["database"])

    lexicon = build_lexicon(cur, edition_ids)
    print(f"Learned Greek->translit lexicon entries: {len(lexicon):,}")

    w_considered, w_updates = backfill_words(cur, edition_ids, lexicon, apply)
    v_considered, v_updates = backfill_variants(cur, edition_ids, lexicon, apply)
    s_considered, s_updates, s_outlier_updates = backfill_slots(cur, edition_ids, lexicon, apply, args.refresh_slots)

    if apply:
        conn.commit()

    mode = "APPLY" if apply else "DRY-RUN"
    print(f"\n[{mode}] Summary")
    print(f"  word rows considered (blank transliteration): {w_considered:,}")
    print(f"  word rows to update:                          {w_updates:,}")
    print(f"  variant rows considered (blank translit):    {v_considered:,}")
    print(f"  variant rows to update:                       {v_updates:,}")
    print(f"  slot rows considered (blank translit):       {s_considered:,}")
    print(f"  slot rows to update:                          {s_updates:,}")
    print(f"  slot outliers covered (word_id NULL):         {s_outlier_updates:,}")

    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
