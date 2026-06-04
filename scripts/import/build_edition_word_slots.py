#!/usr/bin/env python3
"""
build_edition_word_slots.py — build deterministic NA27/TR slot mapping.

For each NT verse and each target edition (NA27, TR), align canonical NT words
(word table, ordered by position) to edition_verse_text.text_norm and persist a
slot stream in edition_word_slot.

This gives rendering a stable source-of-truth projection of source text onto
interlinear word anchors without relying on accumulated variant rows.

Run:
  python build_edition_word_slots.py
  python build_edition_word_slots.py --limit 500
  python build_edition_word_slots.py --config /path/to/config.ini
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


def fractional_offset(k):
    if k < 3:
        return 0.25 * (k + 1)
    return min(0.99, 0.85 + 0.01 * (k - 2))


def align_tokens(canonical_tokens, edition_tokens):
    n = len(canonical_tokens)
    m = len(edition_tokens)

    dp = [[0] * (m + 1) for _ in range(n + 1)]
    back = [[None] * (m + 1) for _ in range(n + 1)]

    for i in range(1, n + 1):
        dp[i][0] = i
        back[i][0] = 'delete'
    for j in range(1, m + 1):
        dp[0][j] = j
        back[0][j] = 'insert'

    for i in range(1, n + 1):
        for j in range(1, m + 1):
            can = canonical_tokens[i - 1]
            edt = edition_tokens[j - 1]

            sub_cost = 0 if can == edt else 1
            c_sub = dp[i - 1][j - 1] + sub_cost
            c_del = dp[i - 1][j] + 1
            c_ins = dp[i][j - 1] + 1

            best = min(c_sub, c_del, c_ins)
            dp[i][j] = best

            if c_sub == best:
                back[i][j] = 'equal' if sub_cost == 0 else 'replace'
            elif c_del == best:
                back[i][j] = 'delete'
            else:
                back[i][j] = 'insert'

    ops = []
    i, j = n, m
    while i > 0 or j > 0:
        op = back[i][j]
        if op in ('equal', 'replace'):
            ops.append((op, i - 1, j - 1))
            i -= 1
            j -= 1
        elif op == 'delete':
            ops.append(('delete', i - 1, None))
            i -= 1
        else:
            ops.append(('insert', None, j - 1))
            j -= 1

    ops.reverse()
    return ops


def fetch_inputs(cur):
    print('[1] Fetching inputs ...')
    cur.execute("SELECT id, code FROM edition WHERE code IN ('NA27','TR')")
    ed_id = {code: int(eid) for eid, code in cur.fetchall()}

    cur.execute("""
        SELECT w.id, w.verse_id, w.position, w.text_original
          FROM word w
          JOIN book b ON b.id = w.book_id
         WHERE b.testament = 'NT'
         ORDER BY w.verse_id, w.position
    """)
    canonical_by_verse = defaultdict(list)
    for wid, vid, pos, raw in cur.fetchall():
        canonical_by_verse[int(vid)].append((int(wid), float(pos), normalize_for_diff(strip_greek_parens(raw or ''))))

    cur.execute(f"""
        SELECT edition_id, verse_id, text_norm
          FROM edition_verse_text
         WHERE edition_id IN ({ed_id['NA27']}, {ed_id['TR']})
    """)
    edition_text = {(int(eid), int(vid)): (txt or '') for eid, vid, txt in cur.fetchall()}

    print(f"    editions: NA27={ed_id['NA27']}, TR={ed_id['TR']}")
    print(f"    canonical NT verses: {len(canonical_by_verse):,}")
    print(f"    edition_verse_text rows: {len(edition_text):,}")

    return ed_id, canonical_by_verse, edition_text


def ensure_schema(cur, conn):
    print('[0] Ensuring edition_word_slot table exists ...')
    cur.execute("""
        CREATE TABLE IF NOT EXISTS edition_word_slot (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            edition_id  TINYINT UNSIGNED NOT NULL,
            verse_id    INT UNSIGNED NOT NULL,
            slot_num    SMALLINT UNSIGNED NOT NULL,
            position    DECIMAL(6,2) NOT NULL,
            word_id     INT UNSIGNED NULL,
            token_text  VARCHAR(200) NOT NULL,
            op_type     ENUM('equal','replace','insert') NOT NULL,
            UNIQUE KEY uq_ews_slot (edition_id, verse_id, slot_num),
            KEY idx_ews_verse (edition_id, verse_id),
            KEY idx_ews_word (word_id),
            CONSTRAINT fk_ews_edition FOREIGN KEY (edition_id) REFERENCES edition(id),
            CONSTRAINT fk_ews_verse   FOREIGN KEY (verse_id) REFERENCES verse(id),
            CONSTRAINT fk_ews_word    FOREIGN KEY (word_id) REFERENCES word(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """)
    conn.commit()


def build_slots_for_verse(canonical, edition_text):
    can_wids = [c[0] for c in canonical]
    can_pos = [c[1] for c in canonical]
    can_toks = [c[2] for c in canonical]
    ed_toks = (edition_text or '').split()

    ops = align_tokens(can_toks, ed_toks)

    slots = []
    slot_num = 0
    pending_insertions = []
    insert_anchor_can_idx = None
    current_can_idx = 0

    def flush_insertions(anchor_idx, ed_indices):
        nonlocal slot_num
        if not ed_indices:
            return
        if not can_wids:
            for ed_idx in ed_indices:
                slot_num += 1
                slots.append((slot_num, 0.0, None, ed_toks[ed_idx], 'insert'))
            return
        if anchor_idx is None or anchor_idx < 0:
            anchor_pos = 0.0
        else:
            anchor_pos = can_pos[anchor_idx]

        for n, ed_idx in enumerate(ed_indices):
            slot_num += 1
            slots.append((slot_num, anchor_pos + fractional_offset(n), None, ed_toks[ed_idx], 'insert'))

    for op, can_idx, ed_idx in ops:
        if op == 'insert':
            if insert_anchor_can_idx is None:
                insert_anchor_can_idx = current_can_idx - 1
            pending_insertions.append(ed_idx)
            continue

        if pending_insertions:
            flush_insertions(insert_anchor_can_idx, pending_insertions)
            pending_insertions = []
            insert_anchor_can_idx = None

        if op == 'equal':
            slot_num += 1
            slots.append((slot_num, can_pos[can_idx], can_wids[can_idx], ed_toks[ed_idx], 'equal'))
            current_can_idx += 1
            continue

        if op == 'replace':
            slot_num += 1
            slots.append((slot_num, can_pos[can_idx], can_wids[can_idx], ed_toks[ed_idx], 'replace'))
            current_can_idx += 1
            continue

        if op == 'delete':
            current_can_idx += 1

    if pending_insertions:
        flush_insertions(insert_anchor_can_idx, pending_insertions)

    return slots


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--config', default=str(project_root / 'config.ini'))
    ap.add_argument('--limit', type=int, default=0)
    args = ap.parse_args()

    cfg = load_config(args.config)
    print(f"Connecting to {cfg['user']}@{cfg['host']}:{cfg['port']}/{cfg['database']} ...")
    conn, _ = get_connection(cfg)
    cur = conn.cursor()

    ensure_schema(cur, conn)

    ed_id, canonical_by_verse, edition_text = fetch_inputs(cur)
    targets = [ed_id['NA27'], ed_id['TR']]

    print('\n[2] Clearing existing NA27/TR slot rows ...')
    cur.execute("DELETE FROM edition_word_slot WHERE edition_id IN (%s, %s)", tuple(targets))
    conn.commit()
    print(f"    deleted: {cur.rowcount:,}")

    print('\n[3] Building slot rows ...')
    verse_ids = sorted(canonical_by_verse.keys())
    if args.limit:
        verse_ids = verse_ids[:args.limit]

    insert_rows = []
    for idx, verse_id in enumerate(verse_ids, 1):
        canonical = canonical_by_verse[verse_id]
        for edition_id in targets:
            txt = edition_text.get((edition_id, verse_id))
            if txt is None:
                continue
            slots = build_slots_for_verse(canonical, txt)
            for slot_num, pos, wid, token, op_type in slots:
                insert_rows.append((edition_id, verse_id, slot_num, pos, wid, token, op_type))

        if idx % 1000 == 0:
            print(f"    {idx:,} / {len(verse_ids):,} verses prepared")

    print(f"\n[4] Inserting {len(insert_rows):,} slot rows ...")
    sql = """
        INSERT INTO edition_word_slot
            (edition_id, verse_id, slot_num, position, word_id, token_text, op_type)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
    """
    chunk = 5000
    for start in range(0, len(insert_rows), chunk):
        cur.executemany(sql, insert_rows[start:start + chunk])
        conn.commit()

    print('[5] Done.')
    cur.close()
    conn.close()


if __name__ == '__main__':
    main()
