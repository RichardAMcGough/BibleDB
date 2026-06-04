#!/usr/bin/env python3
"""
verify_edition_rendering.py — whole-NT consistency check for edition rendering.

Compares rendered interlinear text (canonical words + edition-scoped variants,
using the same slot merge rules as web/db.php) against edition_verse_text.

This is intended to eliminate manual verse-by-verse review by turning drift
into a deterministic pipeline failure.

Run:
  python verify_edition_rendering.py
  python verify_edition_rendering.py --editions NA27,TR
  python verify_edition_rendering.py --limit 200 --show 20
"""

import argparse
import re
import sys
import unicodedata
from collections import defaultdict
from pathlib import Path

project_root = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(project_root / "scripts"))
from _db import load_config, get_connection  # type: ignore[import-not-found]


_DIA_STRIP = re.compile('[\u0300-\u0344\u0346-\u036F]')
_WHITESPACE = re.compile(r'\s+')
_APOSTROPHES = 'ʼʹ\u1FBD\u1FBF\u1FFE\u0384\u0385'
_PAREN_TAIL = re.compile(r'\s*\([^)]*\)\s*$')


def normalize_for_diff(text):
    if not text:
        return ''
    t = (text.replace('Î', '').replace('Ð', '').replace('�', ''))
    t = unicodedata.normalize('NFD', t)
    t = _DIA_STRIP.sub('', t)
    t = unicodedata.normalize('NFC', t)
    t = t.lower()
    t = ''.join(c for c in t
                if not unicodedata.category(c).startswith('P')
                and c not in _APOSTROPHES)
    t = _WHITESPACE.sub(' ', t).strip()
    return t


def strip_greek_parens(text):
    if not text:
        return ''
    return _PAREN_TAIL.sub('', text).strip()


def parse_args():
    ap = argparse.ArgumentParser()
    ap.add_argument('--config', default=str(project_root / 'config.ini'))
    ap.add_argument('--editions', default='NA27,TR',
                    help='Comma-separated edition codes (default: NA27,TR)')
    ap.add_argument('--limit', type=int, default=0,
                    help='Limit verses checked per edition (debug)')
    ap.add_argument('--show', type=int, default=25,
                    help='Show at most N mismatch samples (default: 25)')
    return ap.parse_args()


def build_rendered_map(cur, edition_ids):
    rendered = {}
    marks = ','.join(['%s'] * len(edition_ids))

    cur.execute(f"""
        SELECT ews.edition_id, ews.verse_id, ews.slot_num, ews.token_text
          FROM edition_word_slot ews
          JOIN verse v ON v.id = ews.verse_id
          JOIN book b ON b.id = v.book_id
         WHERE b.testament = 'NT'
           AND ews.edition_id IN ({marks})
         ORDER BY ews.edition_id, ews.verse_id, ews.slot_num
    """, tuple(edition_ids))

    parts_by_key = defaultdict(list)
    for eid, vid, _slot, txt in cur.fetchall():
        parts_by_key[(int(eid), int(vid))].append(strip_greek_parens(txt or ''))

    for key, parts in parts_by_key.items():
        rendered[key] = normalize_for_diff(' '.join(p for p in parts if p))

    return rendered


def main():
    args = parse_args()
    wanted_codes = [x.strip() for x in args.editions.split(',') if x.strip()]
    if not wanted_codes:
        raise SystemExit('No editions selected')

    cfg = load_config(args.config)
    print(f"Connecting to {cfg['user']}@{cfg['host']}:{cfg['port']}/{cfg['database']} ...")
    conn, _ = get_connection(cfg)
    cur = conn.cursor()

    marks = ','.join(['%s'] * len(wanted_codes))
    cur.execute(f"SELECT id, code FROM edition WHERE code IN ({marks})", tuple(wanted_codes))
    ed_rows = cur.fetchall()
    if not ed_rows:
        raise SystemExit(f"No matching editions found for: {wanted_codes}")

    code_by_id = {int(eid): str(code) for eid, code in ed_rows}
    edition_ids = sorted(code_by_id.keys())

    print(f"Checking editions: {', '.join(code_by_id[eid] for eid in edition_ids)}")
    rendered = build_rendered_map(cur, edition_ids)

    marks_ids = ','.join(['%s'] * len(edition_ids))
    cur.execute(f"""
        SELECT evt.edition_id, evt.verse_id, evt.text_norm,
               b.osis_code, v.chapter, v.verse
          FROM edition_verse_text evt
          JOIN verse v ON v.id = evt.verse_id
          JOIN book b ON b.id = v.book_id
         WHERE evt.edition_id IN ({marks_ids})
           AND b.testament = 'NT'
         ORDER BY evt.edition_id, evt.verse_id
    """, tuple(edition_ids))

    totals = defaultdict(int)
    mismatches = []
    per_edition_mismatch = defaultdict(int)

    for eid, vid, expected, osis, chap, verse in cur.fetchall():
        eid = int(eid)
        vid = int(vid)
        totals[eid] += 1
        if args.limit and totals[eid] > args.limit:
            continue

        got = rendered.get((eid, vid), '')
        exp = normalize_for_diff(expected or '')
        if got != exp:
            per_edition_mismatch[eid] += 1
            if len(mismatches) < args.show:
                mismatches.append((code_by_id[eid], osis, int(chap), int(verse), exp, got))

    print('\nSummary:')
    total_mismatch = 0
    for eid in edition_ids:
        mm = per_edition_mismatch[eid]
        total_mismatch += mm
        print(f"  {code_by_id[eid]}: checked {totals[eid]:,} verses, mismatches {mm:,}")

    if mismatches:
        print('\nMismatch samples:')
        for code, osis, chap, verse, exp, got in mismatches:
            print(f"  {code} {osis} {chap}:{verse}")
            print(f"    expected: {exp}")
            print(f"    rendered: {got}")

    cur.close()
    conn.close()

    if total_mismatch > 0:
        raise SystemExit(1)

    print('\nAll checked verses matched edition_verse_text.')


if __name__ == '__main__':
    main()
