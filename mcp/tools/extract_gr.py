"""
Extract curated gematria reference data from the legacy GR_<number>.php pages
into the biblewheel_research database.

For each GR_<n>.php page we capture:
  - the title epithet  (e.g. "The Heart of Wisdom" for 37)
  - the key Bible verse(s)  (BVerse / BCit blocks)
  - the word entries: name, transliteration, Strong's code, picture key
    (from singlegem(...) and tblNVT(...) $desc strings)
  - mathematical identity lines and cross-referenced numbers

The result populates two tables:
  gr_reference  (number, title, epithet, verses, identities, url, raw_excerpt)
  gr_word       (number, name, translit, strongs, source_page)

Run:  python tools/extract_gr.py [--dry-run]
"""

from __future__ import annotations
import os
import re
import sys
import glob
import json

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from shared.db import execute, execute_many, query  # noqa: E402

GR_DIR = r"C:\Work\Resurrected\Bible Wheel Site\public_html\GR"


# ---------------------------------------------------------------------------
# Parsing helpers
# ---------------------------------------------------------------------------

def _read(path: str) -> str:
    return open(path, encoding="windows-1252", errors="replace").read()


def _resolve_php_numvars(txt: str) -> str:
    """
    Resolve PHP number-link variables so identity prose keeps its numbers.
    Builds a map from '$nNNN = returnnumlink("NNN")' assignments, then
    substitutes '<?php echo $nNNN ?>' (and short-echo) with the number.
    """
    varmap: dict[str, str] = {}
    for m in re.finditer(r'(\$\w+)\s*=\s*(?:return|write)numlink\(\s*"(\d+)"', txt):
        varmap[m.group(1)] = m.group(2)

    def _sub(m: re.Match) -> str:
        var = m.group(1)
        return varmap.get(var, "")

    txt = re.sub(r'<\?php\s+echo\s+(\$\w+)\s*\?>', _sub, txt)
    txt = re.sub(r'<\?=\s*(\$\w+)\s*\?>', _sub, txt)
    return txt


def _clean_name(s: str) -> str:
    """Strip the $desc concatenation artefacts (@ delimiters, orphan brackets)."""
    s = s.strip()
    s = s.lstrip("@").strip()
    s = re.sub(r'^[)\]]+\s*', '', s)   # orphan close-brackets from split fragments
    s = re.sub(r'\s+', ' ', s)
    return s.strip()


def parse_title(txt: str) -> tuple[str | None, str | None]:
    """Return (full_title, epithet). Epithet is the part after 'The Number N -'."""
    m = re.search(r'\$title\s*=\s*"([^"]*)"', txt)
    if not m:
        return None, None
    full = m.group(1).strip()
    # "The Number 37 - The Heart of Wisdom"  →  epithet "The Heart of Wisdom"
    em = re.search(r'[Tt]he\s+Number\s+[\d,]+\s*[-–—]\s*(.+)', full)
    epithet = em.group(1).strip() if em else None
    return full, epithet


def parse_verses(txt: str) -> list[dict]:
    """Extract BVerse text + following BCit citation pairs."""
    verses = []
    # BVerse ... </p> then optionally a BCit ... </p>
    for m in re.finditer(
        r'class=[\'"]?BVerse[\'"]?[^>]*>(.*?)</p>\s*(?:<p\s+class=[\'"]?BCit[\'"]?[^>]*>(.*?)</p>)?',
        txt, re.IGNORECASE | re.DOTALL,
    ):
        body = _strip_html(m.group(1))
        cit = _strip_html(m.group(2) or "")
        if body:
            verses.append({"text": body, "citation": cit})
    return verses


def parse_singlegem(txt: str) -> list[dict]:
    """
    singlegem("Name","picfile","Translit","extra","align")
    The picfile prefix encodes the word's gematria value + language,
    e.g. 00073H_Wisdom -> value 73, Hebrew  (NOT a Strong's number).
    """
    out = []
    for m in re.finditer(r'singlegem\(\s*"([^"]*)"\s*,\s*"([^"]*)"\s*,\s*"([^"]*)"', txt):
        name, picfile, translit = m.group(1), m.group(2), m.group(3)
        value, lang = _value_lang_from_picfile(picfile)
        out.append({
            "name": _clean_name(name),
            "translit": translit.strip(),
            "value": value,
            "lang": lang,
            "picfile": picfile.strip(),
        })
    return out


def parse_tblnvt(txt: str) -> list[dict]:
    """
    tblNVT("title", $desc) where $desc is built from segments joined by '@',
    each segment "Name$picfile$Translit". The $desc is usually assembled in a
    PHP variable, so we scan for the literal "Name$picfile$Translit" patterns.
    """
    out = []
    # Match  "...text...$<picfile>$ <translit>"  inside quoted PHP strings
    for m in re.finditer(r'"([^"$]+)\$([0-9A-Za-z_,]+)\$\s*([^"]*)"', txt):
        name, picfile, translit = m.group(1), m.group(2), m.group(3)
        value, lang = _value_lang_from_picfile(picfile)
        cleaned = _clean_name(name)
        if value is not None and cleaned and len(cleaned) > 1:
            out.append({
                "name": cleaned,
                "translit": translit.strip(),
                "value": value,
                "lang": lang,
                "picfile": picfile.strip(),
            })
    return out


def parse_identities(txt: str) -> list[str]:
    """Capture math identity lines like '153 = Sum(17)' or '2701 = 37 x 73'."""
    ids = []
    for m in re.finditer(r"class='[bc]b?'>(.*?)</p>", txt, re.DOTALL):
        line = _strip_html(m.group(1))
        if re.search(r'\d\s*(=|x|×|\+)\s*\d', line) or 'Sum(' in line:
            if len(line) < 200:
                ids.append(line)
    return ids


def parse_xrefs(txt: str) -> list[int]:
    """Numbers referenced via returnnumlink('N') / writenumlink('N')."""
    refs = set()
    for m in re.finditer(r'(?:return|write)numlink\(\s*"(\d+)"', txt):
        refs.add(int(m.group(1)))
    return sorted(refs)


# ---------------------------------------------------------------------------
# Small utilities
# ---------------------------------------------------------------------------

def _value_lang_from_picfile(pic: str) -> tuple[int | None, str | None]:
    """
    '00073H_Wisdom'  -> (73, 'H')
    '00153G_Side,Part' -> (153, 'G')
    The numeric prefix is the word's GEMATRIA VALUE, not a Strong's number.
    """
    m = re.match(r'0*(\d+)([HG])', pic)
    if m:
        return int(m.group(1)), m.group(2)
    return None, None


def _strip_html(s: str) -> str:
    s = re.sub(r'<[^>]+>', ' ', s)
    s = re.sub(r'<\?php.*?\?>', ' ', s, flags=re.DOTALL)
    s = re.sub(r'&nbsp;', ' ', s)
    s = re.sub(r'\s+', ' ', s)
    return s.strip()


# ---------------------------------------------------------------------------
# Main extraction
# ---------------------------------------------------------------------------

def extract_all() -> list[dict]:
    records = []
    for path in glob.glob(os.path.join(GR_DIR, "GR_*.php")):
        b = os.path.basename(path)
        m = re.match(r'GR_(\d+)\.php$', b)
        if not m:
            continue
        number = int(m.group(1))
        txt = _resolve_php_numvars(_read(path))
        full, epithet = parse_title(txt)
        words = parse_singlegem(txt) + parse_tblnvt(txt)
        # dedupe words by (name, strongs)
        seen = set()
        uniq_words = []
        for w in words:
            key = (w["name"], w["value"], w["lang"])
            if key not in seen:
                seen.add(key)
                uniq_words.append(w)
        records.append({
            "number": number,
            "title": full,
            "epithet": epithet,
            "verses": parse_verses(txt),
            "words": uniq_words,
            "identities": parse_identities(txt),
            "xrefs": parse_xrefs(txt),
            "page": b,
        })
    records.sort(key=lambda r: r["number"])
    return records


def main():
    dry = "--dry-run" in sys.argv
    records = extract_all()
    titled = [r for r in records if r["epithet"]]
    total_words = sum(len(r["words"]) for r in records)
    print(f"Parsed {len(records)} numbered GR pages")
    print(f"  with epithets : {len(titled)}")
    print(f"  word entries  : {total_words}")
    print()
    for r in titled[:25]:
        print(f"  {r['number']:>5} : {r['epithet']}  ({len(r['words'])} words)")

    if dry:
        print("\n[dry-run] no DB writes")
        # Dump a sample record for inspection
        sample = next((r for r in records if r["number"] == 37), records[0])
        print("\nSample (37):")
        print(json.dumps(sample, ensure_ascii=False, indent=2)[:2000])
        return

    _write_db(records)


def _write_db(records: list[dict]) -> None:
    execute("""
        CREATE TABLE IF NOT EXISTS gr_reference (
          number      INT UNSIGNED NOT NULL PRIMARY KEY,
          title       VARCHAR(255),
          epithet     VARCHAR(255),
          verses      JSON,
          identities  JSON,
          xrefs       JSON,
          page        VARCHAR(64),
          url         VARCHAR(128),
          FULLTEXT KEY idx_ft_title (title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """, (), database="biblewheel_research")

    execute("""
        CREATE TABLE IF NOT EXISTS gr_word (
          id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          page      INT UNSIGNED NOT NULL COMMENT 'the GR_<page>.php this entry was found on',
          value     INT UNSIGNED COMMENT 'gematria value of the word itself',
          lang      CHAR(1)      COMMENT 'H or G',
          name      VARCHAR(255),
          translit  VARCHAR(255),
          picfile   VARCHAR(128),
          KEY idx_page (page),
          KEY idx_value (value),
          FULLTEXT KEY idx_ft_name (name, translit)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """, (), database="biblewheel_research")

    # Clear and repopulate (idempotent)
    execute("DELETE FROM gr_word", (), database="biblewheel_research")
    execute("DELETE FROM gr_reference", (), database="biblewheel_research")

    ref_rows = [
        (
            r["number"], r["title"], r["epithet"],
            json.dumps(r["verses"], ensure_ascii=False),
            json.dumps(r["identities"], ensure_ascii=False),
            json.dumps(r["xrefs"]),
            r["page"],
            f"https://www.biblewheel.com/GR/{r['page']}",
        )
        for r in records
    ]
    word_rows = [
        (r["number"], w["value"], w["lang"], w["name"], w["translit"], w["picfile"])
        for r in records for w in r["words"]
    ]
    execute_many(
        """INSERT INTO gr_reference (number, title, epithet, verses, identities, xrefs, page, url)
           VALUES (%s,%s,%s,%s,%s,%s,%s,%s)""",
        ref_rows, database="biblewheel_research",
    )
    execute_many(
        """INSERT INTO gr_word (page, value, lang, name, translit, picfile)
           VALUES (%s,%s,%s,%s,%s,%s)""",
        word_rows, database="biblewheel_research",
    )
    print(f"\nWrote {len(ref_rows)} reference rows and {len(word_rows)} word rows "
          f"to biblewheel_research.")


if __name__ == "__main__":
    main()
