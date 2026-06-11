#!/usr/bin/env python3
"""
add_verse_search.py — adds a text_search TEXT column to the verse table and
populates it by concatenating normalised word forms (from word.text_search) in
position order.  This powers phrase search with LIKE '%phrase%'.

Run AFTER add_text_search.py (which populates word.text_search).
Safe to re-run — UPDATE is idempotent.

Supports --config for custom config.ini location (otherwise uses auto-discover + BIBLE_DB_* env).
"""

import sys
import re
import argparse
from pathlib import Path

# Shared DB helpers (single source of truth for DB name via BIBLE_DB_NAME only)
_scripts_dir = Path(__file__).resolve().parent.parent
if str(_scripts_dir) not in sys.path:
    sys.path.insert(0, str(_scripts_dir))
from _db import connect  # type: ignore[import-not-found]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--config', default=None, help='Path to config.ini (optional; default auto-discover + BIBLE_DB_* env)')
    args = ap.parse_args()

    conn, cur, cfg = connect(args.config)

    # ── Guard: word.text_search must exist ────────────────────────────────────
    cur.execute("""
        SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'word'
           AND COLUMN_NAME = 'text_search'
    """, (cfg["database"],))
    if cur.fetchone()[0] == 0:
        print("ERROR: word.text_search column not found.")
        print("Run add_text_search.py first, then re-run this script.")
        sys.exit(1)

    # ── Add verse.text_search if missing ─────────────────────────────────────
    cur.execute("""
        SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'verse'
           AND COLUMN_NAME = 'text_search'
    """, (cfg["database"],))
    if cur.fetchone()[0] == 0:
        print("Adding verse.text_search column …")
        cur.execute("ALTER TABLE verse ADD COLUMN text_search TEXT DEFAULT NULL")
        conn.commit()
        print("Column created.")
    else:
        print("Column already exists — refreshing values.")

    # ── Populate via one UPDATE … JOIN ────────────────────────────────────────
    # GROUP_CONCAT joins each verse's normalised word forms in position order,
    # producing a single space-separated string ready for LIKE phrase search.
    #
    # Iota-subscript forms (ᾳ U+1FB3, ῃ U+1FC3, ῳ U+1FF3, and bare U+0345) are
    # stripped from the stored string so that LIKE comparisons work correctly
    # under utf8mb4_unicode_ci, which treats U+0345 as a zero-weight combining
    # character and silently fails LIKE patterns containing those characters.
    # search.php phrase mode applies the same stripping to the query.
    # word.text_search is NOT changed — text-mode exact-match (=) still works.
    print("Populating verse.text_search from word.text_search …")
    cur.execute("""
        UPDATE verse v
          JOIN (
              SELECT verse_id,
                     GROUP_CONCAT(text_search ORDER BY position SEPARATOR ' ') AS ts
                FROM word
               GROUP BY verse_id
          ) agg ON agg.verse_id = v.id
           SET v.text_search = REPLACE(
                               REPLACE(
                               REPLACE(
                               REPLACE(
                                   agg.ts,
                                   'ᾳ', 'α'),
                                   'ῃ', 'η'),
                                   'ῳ', 'ω'),
                                   'ͅ', '')
    """)
    conn.commit()

    cur.execute("SELECT COUNT(*) FROM verse WHERE text_search IS NOT NULL")
    n = cur.fetchone()[0]
    print(f"Done. {n:,} verses populated.")

    # ── search_corpus ─────────────────────────────────────────────────────────
    # One flat table of DISTINCT normalized witness texts per verse:
    #   1. canonical tagged text        (verse.text_search)
    #   2. each edition's full text     (edition_verse_text, where present)
    #   3. apparatus-patched canonical  (canonical tokens with per-edition
    #      variant substitutions applied — covers readings the dumps miss,
    #      e.g. TR ἄρρενες in Rom 1:27)
    # Phrase search runs ONE un-correlated scan over this table instead of
    # OR-ing per-verse EXISTS probes across several tables. Editions are
    # textually identical for most verses, so dedupe keeps it small.
    print("Building search_corpus ...")
    punct_re = re.compile('[,\\.;:··¶]+')

    def clean(text):
        if not text:
            return ''
        text = punct_re.sub('', text)
        text = (text.replace('ᾳ', 'α')   # ᾳ -> α
                    .replace('ῃ', 'η')   # ῃ -> η
                    .replace('ῳ', 'ω')   # ῳ -> ω
                    .replace('ͅ', ''))
        return re.sub(r'\s+', ' ', text).strip()

    corpus = {}

    def add(vid, text):
        t = clean(text)
        if t:
            corpus.setdefault(vid, set()).add(t)

    # 1. canonical
    cur.execute("SELECT id, text_search FROM verse WHERE text_search IS NOT NULL")
    for vid, ts in cur.fetchall():
        add(vid, ts)

    # 2. edition texts
    cur.execute("""
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'edition_verse_text'
    """, (cfg["database"],))
    if cur.fetchone()[0] > 0:
        cur.execute("SELECT verse_id, text_norm FROM edition_verse_text")
        for vid, tn in cur.fetchall():
            add(vid, tn)

    # 3. apparatus-patched canonical token streams
    cur.execute("""
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'variant'
          AND COLUMN_NAME = 'text_search'
    """, (cfg["database"],))
    if cur.fetchone()[0] > 0:
        cur.execute("""
            SELECT ve.edition_id, w.verse_id, v.position, v.kind,
                   COALESCE(v.text_search, '')
            FROM variant v
            JOIN variant_edition ve ON ve.variant_id = v.id
            JOIN word w ON w.id = v.word_id
            ORDER BY ve.edition_id, w.verse_id, v.position, v.id
        """)
        groups = {}
        verse_ids = set()
        for ed, vid, pos, kind, ts in cur.fetchall():
            groups.setdefault((ed, vid), []).append((float(pos), kind, ts))
            verse_ids.add(vid)

        canon = {}
        if verse_ids:
            vids = sorted(verse_ids)
            CHUNK = 2000
            for i in range(0, len(vids), CHUNK):
                chunk = vids[i:i + CHUNK]
                marks = ','.join(['%s'] * len(chunk))
                cur.execute(f"""
                    SELECT verse_id, position, COALESCE(text_search, '')
                    FROM word WHERE verse_id IN ({marks})
                    ORDER BY verse_id, position
                """, chunk)
                for vid, pos, ts in cur.fetchall():
                    canon.setdefault(vid, []).append((float(pos), ts))

        for (ed, vid), vlist in groups.items():
            tokens = {pos: ts for pos, ts in canon.get(vid, [])}
            for pos, kind, ts in vlist:
                if kind == 'omission':
                    tokens.pop(pos, None)
                elif kind == 'addition':
                    if ts:
                        tokens[pos] = ts
                else:  # spelling / meaning substitution
                    if ts and pos in tokens:
                        tokens[pos] = ts
            add(vid, ' '.join(t for _, t in sorted(tokens.items()) if t))

    cur.execute("""
        CREATE TABLE IF NOT EXISTS search_corpus (
            verse_id  INT UNSIGNED NOT NULL,
            text_norm TEXT NOT NULL,
            KEY idx_sc_verse (verse_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    """)
    cur.execute("TRUNCATE TABLE search_corpus")
    rows = [(vid, t) for vid, texts in corpus.items() for t in sorted(texts)]
    for i in range(0, len(rows), 2000):
        cur.executemany(
            "INSERT INTO search_corpus (verse_id, text_norm) VALUES (%s, %s)",
            rows[i:i + 2000]
        )
        conn.commit()
    print(f"Done. search_corpus rows: {len(rows):,} over {len(corpus):,} verses.")

    # superseded by search_corpus (was an intermediate design)
    cur.execute("DROP TABLE IF EXISTS apparatus_verse_text")
    conn.commit()

    # ── Spot-checks ───────────────────────────────────────────────────────────
    for ref in [('Gen', 1, 1), ('Jhn', 1, 1)]:
        cur.execute("""
            SELECT v.text_search
              FROM verse v JOIN book b ON b.id = v.book_id
             WHERE b.osis_code = %s AND v.chapter = %s AND v.verse = %s
             LIMIT 1
        """, ref)
        row = cur.fetchone()
        ts = row[0] if row else None
        print(f"  {ref[0]} {ref[1]}:{ref[2]}: {ts!r}")

    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
