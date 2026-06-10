#!/usr/bin/env python3
"""
import_bw_bibles.py — Import NA27 and Scrivener NT texts from bw_bible
into the stepbible MariaDB, decoding BibleWorks transliteration to Unicode.

Source : bw_bible database (same host/port as stepbible)
Target : stepbible database

New tables created in stepbible
  nt_version  — one row per text tradition
  nt_verse    — verse text per version

Columns in nt_verse
  text_raw     — original BibleWorks transliteration (preserved unchanged)
  text_unicode — decoded Unicode Greek
  text_search  — normalised for LIKE phrase search (same scheme as verse.text_search)

Configuration
  Reads config.ini [mariadb] section.
  Optional key  bw_database = bw_bible  (default: bw_bible)

Run:  python import_bw_bibles.py
Safe to re-run — nt_verse rows are deleted and re-inserted each time.
"""

import re
import sys
import unicodedata
import configparser

try:
    import pymysql as _drv
    DRIVER = 'pymysql'
except ImportError:
    import mariadb as _drv
    DRIVER = 'mariadb'


# ─────────────────────────────────────────────────────────────────────────────
# Book mapping: BibleWorks NT book number (40–66) → OSIS abbreviation
# BW numbers match the standard Protestant canonical order used by stepbible
# (book_order 1–39 = OT, 40–66 = NT), so BW number == stepbible book_order.
# ─────────────────────────────────────────────────────────────────────────────
BW_NT_OSIS = {
    40: 'Matt',   41: 'Mark',    42: 'Luke',    43: 'John',
    44: 'Acts',   45: 'Rom',     46: '1Cor',    47: '2Cor',
    48: 'Gal',    49: 'Eph',     50: 'Phil',    51: 'Col',
    52: '1Thess', 53: '2Thess',  54: '1Tim',    55: '2Tim',
    56: 'Titus',  57: 'Phlm',    58: 'Heb',     59: 'Jas',
    60: '1Pet',   61: '2Pet',    62: '1John',   63: '2John',
    64: '3John',  65: 'Jude',    66: 'Rev',
}


# ─────────────────────────────────────────────────────────────────────────────
# BibleWorks Greek transliteration → Unicode decoder
#
# Encoding summary
# ----------------
# Base letters (lowercase Latin → lowercase Greek):
#   a=α  b=β  g=γ  d=δ  e=ε  z=ζ  h=η  q=θ  i=ι  k=κ  l=λ  m=μ
#   n=ν  x=ξ  o=ο  p=π  r=ρ  s=σ  j=ς  t=τ  u=υ  f=φ  c=χ  y=ψ  w=ω
#   Same set for uppercase (J/j are the only sigma; S/s = medial Σ/σ)
#
# Diacritics — placed AFTER the vowel they modify:
#   v  = smooth breathing (psili,  U+0313)
#   `  = rough breathing  (dasia,  U+0314)
#   ,  = acute  accent    (oxia,   U+0301)
#   .  = grave  accent    (varia,  U+0300)
#   =  = circumflex       (perispomeni, U+0342)
#   /  = circumflex (alternate; used when iota subscript | follows)
#   |  = iota subscript   (ypogegrammeni, U+0345)
#   -  = rough breathing + circumflex on second vowel of diphthong
#        (e.g. ou- → οὗ in οὗτος).  Single-vowel rough breathing uses `.
#
# Capital prefix:
#   V before an uppercase vowel = smooth breathing on that vowel
#   e.g. VEn → Ἐν
#
# Punctuation:
#   (  = Greek comma → output as ,
#   All other non-alpha chars passed through unchanged.
# ─────────────────────────────────────────────────────────────────────────────

_BASE = {
    'a': 'α', 'b': 'β', 'g': 'γ', 'd': 'δ', 'e': 'ε', 'z': 'ζ',
    'h': 'η', 'q': 'θ', 'i': 'ι', 'k': 'κ', 'l': 'λ', 'm': 'μ',
    'n': 'ν', 'x': 'ξ', 'o': 'ο', 'p': 'π', 'r': 'ρ', 's': 'σ',
    'j': 'ς', 't': 'τ', 'u': 'υ', 'f': 'φ', 'c': 'χ', 'y': 'ψ', 'w': 'ω',
}
_BASE_UC = {
    'A': 'Α', 'B': 'Β', 'G': 'Γ', 'D': 'Δ', 'E': 'Ε', 'Z': 'Ζ',
    'H': 'Η', 'Q': 'Θ', 'I': 'Ι', 'K': 'Κ', 'L': 'Λ', 'M': 'Μ',
    'N': 'Ν', 'X': 'Ξ', 'O': 'Ο', 'P': 'Π', 'R': 'Ρ', 'S': 'Σ',
    'J': 'Σ', 'T': 'Τ', 'U': 'Υ', 'F': 'Φ', 'C': 'Χ', 'Y': 'Ψ', 'W': 'Ω',
}

_VOWELS_LC = set('aehiouw')
_VOWELS_UC = set('AEHIOUW')

# Combining diacritics
_SMOOTH = '̓'   # combining comma above       (psili)
_ROUGH  = '̔'   # combining rev. comma above  (dasia)
_ACUTE  = '́'   # combining acute
_GRAVE  = '̀'   # combining grave
_CIRCUM = '͂'   # combining greek perispomeni
_IOSUB  = 'ͅ'   # combining greek ypogegrammeni (iota subscript)

# Diacritic source chars → combining codepoint(s)
# Values are strings that may contain more than one combining char.
_DIACRIT = {
    'v': _SMOOTH,
    '`': _ROUGH,
    ',': _ACUTE,
    '.': _GRAVE,
    '=': _CIRCUM,
    '/': _CIRCUM,           # alternate circumflex (used before |)
    '|': _IOSUB,
    '-': _ROUGH + _CIRCUM,  # diphthong: rough breathing + circumflex
                            # e.g. ou- → ο + ὗ = οὗ (in οὗτος)
}

_NFC = unicodedata.normalize


def _compose(base: str, dc: str) -> str:
    """Append combining diacritics to base, return NFC form."""
    return _NFC('NFC', base + dc) if dc else base


def decode_pointed(text: str) -> str:
    """
    Decode BibleWorks pointed Greek (Verse_Text_Pointed) → NFC Unicode.
    Used for NA27.
    """
    out = []
    i, n = 0, len(text)

    while i < n:
        ch = text[i]

        # ── 'V' smooth-breathing prefix before an uppercase vowel ────────────
        if ch == 'V' and i + 1 < n and text[i + 1] in _VOWELS_UC:
            i += 1
            base = _BASE_UC[text[i]]; i += 1
            dc = _SMOOTH                            # V implies smooth breathing
            while i < n and text[i] in _DIACRIT:
                dc += _DIACRIT[text[i]]; i += 1
            out.append(_compose(base, dc))
            continue

        # ── Uppercase vowel (no V prefix) ────────────────────────────────────
        if ch in _VOWELS_UC:
            base = _BASE_UC[ch]; i += 1
            dc = ''
            while i < n and text[i] in _DIACRIT:
                dc += _DIACRIT[text[i]]; i += 1
            out.append(_compose(base, dc))
            continue

        # ── Uppercase consonant ───────────────────────────────────────────────
        if ch in _BASE_UC:
            out.append(_BASE_UC[ch]); i += 1
            continue

        # ── Lowercase vowel ───────────────────────────────────────────────────
        if ch in _VOWELS_LC:
            base = _BASE[ch]; i += 1
            dc = ''
            while i < n and text[i] in _DIACRIT:
                dc += _DIACRIT[text[i]]; i += 1
            out.append(_compose(base, dc))
            continue

        # ── Lowercase consonant ───────────────────────────────────────────────
        if ch in _BASE:
            out.append(_BASE[ch]); i += 1
            continue

        # ── Punctuation / whitespace / other ─────────────────────────────────
        out.append(',' if ch == '(' else ch)
        i += 1

    return ''.join(out)


def decode_unpointed(text: str) -> str:
    """
    Decode BibleWorks unpointed Greek (Verse_Text) → Unicode (no diacritics).
    Used for Scrivener.  | following a vowel becomes iota subscript;
    any | after a consonant is skipped.
    """
    out = []
    i, n = 0, len(text)

    while i < n:
        ch = text[i]; i += 1

        if ch in _VOWELS_UC:
            base = _BASE_UC[ch]
            if i < n and text[i] == '|':
                out.append(_compose(base, _IOSUB)); i += 1
            else:
                out.append(base)
            continue

        if ch in _BASE_UC:
            out.append(_BASE_UC[ch])
            continue

        if ch in _VOWELS_LC:
            base = _BASE[ch]
            if i < n and text[i] == '|':
                out.append(_compose(base, _IOSUB)); i += 1
            else:
                out.append(base)
            continue

        if ch in _BASE:
            out.append(_BASE[ch])
            continue

        if ch != '|':       # skip any orphaned |
            out.append(ch)

    return ''.join(out)


# ─────────────────────────────────────────────────────────────────────────────
# text_search normalisation
#
# Mirrors normalize_query() in search.php (Greek branch) plus the
# iota-subscript stripping in add_verse_search.py so phrase LIKE search
# works under utf8mb4_unicode_ci (which treats U+0345 as zero-weight).
# ─────────────────────────────────────────────────────────────────────────────

def make_text_search(text: str) -> str:
    t = unicodedata.normalize('NFD', text)
    # Strip combining diacritics U+0300-U+0344 and U+0346-U+036F
    # (keeps U+0345 iota subscript, stripped explicitly below)
    t = re.sub(u'[̀-̈́͆-ͯ]', '', t)
    # Deduplicate stray iota subscript (browser selection sometimes adds extra)
    t = re.sub(u'ͅ{2,}', u'ͅ', t)
    t = unicodedata.normalize('NFC', t)
    t = t.lower()
    # Strip iota-subscript vowel forms (same as add_verse_search.py REPLACE)
    t = t.replace('ᾳ', 'α')   # ᾳ -> α
    t = t.replace('ῃ', 'η')   # ῃ -> η
    t = t.replace('ῳ', 'ω')   # ῳ -> ω
    t = t.replace('ͅ', '')          # bare combining iota subscript
    return t


# ─────────────────────────────────────────────────────────────────────────────
# DB connection helper
# ─────────────────────────────────────────────────────────────────────────────

def connect(s):
    kw = dict(
        host=s.get('host', '127.0.0.1'),
        port=int(s.get('port', 3306)),
        user=s.get('user', 'root'),
        password=s.get('password', ''),
    )
    if DRIVER == 'pymysql':
        return _drv.connect(**kw, charset='utf8mb4',
                            database=s.get('database', 'stepbible'))
    else:
        return _drv.connect(**kw, database=s.get('database', 'stepbible'))


# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────

def main():
    cfg = configparser.ConfigParser()
    cfg.read('config.ini')
    s     = cfg['mariadb']
    bw_db = s.get('bw_database', 'bw_bible')

    host = s.get('host', '127.0.0.1')
    db   = s.get('database', 'stepbible')
    print(f"Connecting to {host}/{db}  (source: {bw_db}) …")
    conn = connect(s)
    cur  = conn.cursor()

    # ── 1. Create tables ──────────────────────────────────────────────────────
    print("\n[1] Creating tables …")

    cur.execute("""
        CREATE TABLE IF NOT EXISTS nt_version (
            id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(64)  NOT NULL,
            description TEXT,
            PRIMARY KEY (id),
            UNIQUE KEY uq_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """)

    cur.execute("""
        CREATE TABLE IF NOT EXISTS nt_verse (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            version_id   TINYINT UNSIGNED NOT NULL,
            book_id      SMALLINT UNSIGNED NOT NULL,
            chapter      SMALLINT UNSIGNED NOT NULL,
            verse        SMALLINT UNSIGNED NOT NULL,
            verse_order  INT UNSIGNED,
            gematria     INT UNSIGNED,
            text_raw     TEXT  COMMENT 'Original BibleWorks transliteration',
            text_unicode TEXT  COMMENT 'Decoded Unicode Greek',
            text_search  TEXT  COMMENT 'Normalised for LIKE phrase search',
            PRIMARY KEY (id),
            KEY idx_ref  (version_id, book_id, chapter, verse),
            CONSTRAINT fk_ntv_ver  FOREIGN KEY (version_id) REFERENCES nt_version(id),
            CONSTRAINT fk_ntv_book FOREIGN KEY (book_id)    REFERENCES book(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """)
    conn.commit()
    print("    Tables ready.")

    # ── 2. Seed nt_version ────────────────────────────────────────────────────
    cur.execute("""
        INSERT IGNORE INTO nt_version (id, name, description) VALUES
          (1, 'NA27',      'Nestle-Aland 27th edition (diacritics from Verse_Text_Pointed)'),
          (2, 'Scrivener', 'Scrivener Textus Receptus (unpointed, no diacritics)')
    """)
    conn.commit()

    # ── 3. book_order → book.id lookup ───────────────────────────────────────
    cur.execute("SELECT book_order, id FROM book")
    book_id = {int(r[0]): int(r[1]) for r in cur.fetchall()}
    missing = [k for k in BW_NT_OSIS if k not in book_id]
    if missing:
        print(f"    WARNING: no book row for BW numbers {missing}")

    # ── 4. Clear previous data (idempotent) ───────────────────────────────────
    print("\n[2] Clearing previous nt_verse rows …")
    cur.execute("DELETE FROM nt_verse")
    conn.commit()

    # ── 5. Import NA27 ────────────────────────────────────────────────────────
    print(f"\n[3] Reading `{bw_db}`.bible_na27 …")
    try:
        cur.execute(f"""
            SELECT Verse_Order, Book, Chapter, Verse,
                   Verse_Text_Pointed, Gematria
              FROM `{bw_db}`.bible_na27
             ORDER BY Verse_Order
        """)
    except Exception as e:
        print(f"    ERROR reading bible_na27: {e}")
        sys.exit(1)

    rows = cur.fetchall()
    print(f"    {len(rows):,} rows")

    batch, errs = [], 0
    for vo, bk, ch, vs, raw, gem in rows:
        bid = book_id.get(int(bk))
        if bid is None:
            print(f"    SKIP unknown book {bk}  (verse_order={vo})")
            errs += 1
            continue
        try:
            uni = decode_pointed(raw or '')
            ts  = make_text_search(uni)
        except Exception as e:
            print(f"    DECODE ERROR verse_order={vo}: {e}")
            uni = ts = ''
            errs += 1
        gem_i = _safe_int(gem)
        batch.append((1, bid, int(ch), int(vs), int(vo), gem_i, raw, uni, ts))

    if batch:
        cur.executemany("""
            INSERT INTO nt_verse
                (version_id, book_id, chapter, verse, verse_order, gematria,
                 text_raw, text_unicode, text_search)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """, batch)
        conn.commit()
    print(f"    Inserted {len(batch):,}  ({errs} skipped/errored)")

    # ── 6. Import Scrivener ───────────────────────────────────────────────────
    print(f"\n[4] Reading `{bw_db}`.bible_scr …")
    try:
        cur.execute(f"""
            SELECT Verse_Order, Book, Chapter, Verse,
                   Verse_Text, Gematria
              FROM `{bw_db}`.bible_scr
             ORDER BY Verse_Order
        """)
    except Exception as e:
        print(f"    ERROR reading bible_scr: {e}")
        sys.exit(1)

    rows = cur.fetchall()
    print(f"    {len(rows):,} rows")

    batch, errs = [], 0
    for vo, bk, ch, vs, raw, gem in rows:
        bid = book_id.get(int(bk))
        if bid is None:
            print(f"    SKIP unknown book {bk}  (verse_order={vo})")
            errs += 1
            continue
        try:
            uni = decode_unpointed(raw or '')
            ts  = make_text_search(uni)
        except Exception as e:
            print(f"    DECODE ERROR verse_order={vo}: {e}")
            uni = ts = ''
            errs += 1
        gem_i = _safe_int(gem)
        batch.append((2, bid, int(ch), int(vs), int(vo), gem_i, raw, uni, ts))

    if batch:
        cur.executemany("""
            INSERT INTO nt_verse
                (version_id, book_id, chapter, verse, verse_order, gematria,
                 text_raw, text_unicode, text_search)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """, batch)
        conn.commit()
    print(f"    Inserted {len(batch):,}  ({errs} skipped/errored)")

    # ── 7. Spot-check ─────────────────────────────────────────────────────────
    print("\n[5] Spot-check verification")
    print(f"    {'Ver':<10} {'Ref':<14}  Unicode text (first 72 chars)")
    print(f"    {'-'*10} {'-'*14}  {'-'*72}")

    checks = [
        (1, 43, 1,  1),    # NA27  John 1:1  — Ἐν ἀρχῇ ἦν ὁ λόγος …
        (2, 43, 1,  1),    # SCR   John 1:1  — (no accents)
        (1, 43, 1,  2),    # NA27  John 1:2
        (1, 43, 3, 16),    # NA27  John 3:16 — οὕτως γὰρ ἠγάπησεν ὁ θεὸς …
        (1, 40, 1,  1),    # NA27  Matt 1:1
        (1, 66,22, 21),    # NA27  Rev 22:21 (last NT verse)
    ]
    ver_names = {1: 'NA27', 2: 'SCR'}
    for ver, bw_bk, ch, vs in checks:
        bid = book_id.get(bw_bk)
        if bid is None:
            continue
        cur.execute("""
            SELECT text_unicode FROM nt_verse
             WHERE version_id=%s AND book_id=%s AND chapter=%s AND verse=%s
        """, (ver, bid, ch, vs))
        row = cur.fetchone()
        uni = (row[0] or '')[:72] if row else '(not found)'
        bk_name = BW_NT_OSIS.get(bw_bk, str(bw_bk))
        ref = f"{bk_name} {ch}:{vs}"
        print(f"    {ver_names[ver]:<10} {ref:<14}  {uni}")

    print("""
Verification notes
------------------
NA27  John 1:1 should begin:  Ἐν ἀρχῇ ἦν ὁ λόγος, καὶ ὁ λόγος ἦν πρὸς τὸν θεόν,
SCR   John 1:1 should begin:  Εν αρχη ην ο λογος  (no diacritics)

If the diacritics look wrong (e.g. / decoded as acute rather than circumf