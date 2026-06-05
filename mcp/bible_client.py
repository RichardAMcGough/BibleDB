"""
Bible database API client for localhost/bibledb.
Handles reference parsing and HTTP calls to the existing PHP API.
"""

import re
import httpx
from typing import Optional

BASE_URL = "http://localhost/bibledb/api.php"

# Maps common name variants → OSIS codes used by the database
BOOK_ALIASES: dict[str, str] = {
    # Pentateuch
    "genesis": "Gen", "gen": "Gen",
    "exodus": "Exo", "exo": "Exo", "ex": "Exo",
    "leviticus": "Lev", "lev": "Lev",
    "numbers": "Num", "num": "Num",
    "deuteronomy": "Deu", "deu": "Deu", "deut": "Deu", "dt": "Deu",
    # Historical
    "joshua": "Jos", "jos": "Jos", "josh": "Jos",
    "judges": "Jdg", "jdg": "Jdg", "judg": "Jdg",
    "ruth": "Rut", "rut": "Rut",
    "1samuel": "1Sa", "1sa": "1Sa", "1sam": "1Sa",
    "2samuel": "2Sa", "2sa": "2Sa", "2sam": "2Sa",
    "1kings": "1Ki", "1ki": "1Ki", "1kgs": "1Ki",
    "2kings": "2Ki", "2ki": "2Ki", "2kgs": "2Ki",
    "1chronicles": "1Ch", "1ch": "1Ch", "1chr": "1Ch", "1chron": "1Ch",
    "2chronicles": "2Ch", "2ch": "2Ch", "2chr": "2Ch", "2chron": "2Ch",
    "ezra": "Ezr", "ezr": "Ezr",
    "nehemiah": "Neh", "neh": "Neh",
    "esther": "Est", "est": "Est",
    # Poetry / Wisdom
    "job": "Job",
    "psalms": "Psa", "psalm": "Psa", "psa": "Psa", "ps": "Psa",
    "proverbs": "Pro", "pro": "Pro", "prov": "Pro",
    "ecclesiastes": "Ecc", "ecc": "Ecc", "eccl": "Ecc",
    "songofsolomon": "Sng", "sng": "Sng", "song": "Sng", "sos": "Sng", "canticles": "Sng",
    # Prophets
    "isaiah": "Isa", "isa": "Isa",
    "jeremiah": "Jer", "jer": "Jer",
    "lamentations": "Lam", "lam": "Lam",
    "ezekiel": "Ezk", "ezk": "Ezk", "ezek": "Ezk",
    "daniel": "Dan", "dan": "Dan",
    "hosea": "Hos", "hos": "Hos",
    "joel": "Jol", "jol": "Jol",
    "amos": "Amo", "amo": "Amo",
    "obadiah": "Oba", "oba": "Oba", "obad": "Oba",
    "jonah": "Jon", "jon": "Jon",
    "micah": "Mic", "mic": "Mic",
    "nahum": "Nam", "nam": "Nam", "nah": "Nam",
    "habakkuk": "Hab", "hab": "Hab",
    "zephaniah": "Zep", "zep": "Zep", "zeph": "Zep",
    "haggai": "Hag", "hag": "Hag",
    "zechariah": "Zec", "zec": "Zec", "zech": "Zec",
    "malachi": "Mal", "mal": "Mal",
    # NT
    "matthew": "Mat", "mat": "Mat", "matt": "Mat", "mt": "Mat",
    "mark": "Mrk", "mrk": "Mrk", "mk": "Mrk",
    "luke": "Luk", "luk": "Luk", "lk": "Luk",
    "john": "Jhn", "jhn": "Jhn", "jn": "Jhn",
    "acts": "Act", "act": "Act",
    "romans": "Rom", "rom": "Rom",
    "1corinthians": "1Co", "1co": "1Co", "1cor": "1Co",
    "2corinthians": "2Co", "2co": "2Co", "2cor": "2Co",
    "galatians": "Gal", "gal": "Gal",
    "ephesians": "Eph", "eph": "Eph",
    "philippians": "Php", "php": "Php", "phil": "Php",
    "colossians": "Col", "col": "Col",
    "1thessalonians": "1Th", "1th": "1Th", "1thess": "1Th",
    "2thessalonians": "2Th", "2th": "2Th", "2thess": "2Th",
    "1timothy": "1Ti", "1ti": "1Ti", "1tim": "1Ti",
    "2timothy": "2Ti", "2ti": "2Ti", "2tim": "2Ti",
    "titus": "Tit", "tit": "Tit",
    "philemon": "Phm", "phm": "Phm", "phlm": "Phm",
    "hebrews": "Heb", "heb": "Heb",
    "james": "Jas", "jas": "Jas",
    "1peter": "1Pe", "1pe": "1Pe", "1pet": "1Pe",
    "2peter": "2Pe", "2pe": "2Pe", "2pet": "2Pe",
    "1john": "1Jn", "1jn": "1Jn",
    "2john": "2Jn", "2jn": "2Jn",
    "3john": "3Jn", "3jn": "3Jn",
    "jude": "Jud", "jud": "Jud",
    "revelation": "Rev", "rev": "Rev", "apoc": "Rev",
}


def parse_reference(ref: str) -> tuple[str, int, int]:
    """
    Parse a verse reference like "Gen 1:1", "1 Kings 3:5", "Ps 119:105"
    into (osis_code, chapter, verse).
    Raises ValueError on unrecognised format or unknown book.
    """
    ref = ref.strip()
    # Split off chapter:verse — allow spaces around colon
    m = re.match(r'^(.+?)\s+(\d+)\s*:\s*(\d+)$', ref)
    if not m:
        raise ValueError(f"Cannot parse reference '{ref}' — expected 'Book chapter:verse'")
    book_raw, chapter, verse = m.group(1), int(m.group(2)), int(m.group(3))

    # Normalise: strip spaces, lowercase
    key = re.sub(r'\s+', '', book_raw).lower()
    osis = BOOK_ALIASES.get(key)
    if not osis:
        raise ValueError(f"Unknown book '{book_raw}' in reference '{ref}'")
    return osis, chapter, verse


def _get(params: dict) -> dict:
    """Synchronous GET to the local Bible API."""
    with httpx.Client(timeout=10) as client:
        resp = client.get(BASE_URL, params=params)
        resp.raise_for_status()
        return resp.json()


def fetch_verse(ref: str) -> dict:
    """
    Fetch full verse data from the API.
    Returns the raw API dict: {'verse': {...}, 'words': [...], 'summaries': [...]}.
    Raises ValueError if the reference is bad, RuntimeError if the API fails.
    """
    osis, chapter, verse = parse_reference(ref)
    data = _get({"api": "verse_full", "book": osis, "chapter": chapter, "verse": verse})
    if data is None or "error" in data:
        raise RuntimeError(f"API returned error for {ref}: {data}")
    return data


def fetch_strongs(code: str) -> dict | None:
    """Look up a Strong's number (e.g. 'H430', 'G3056')."""
    return _get({"api": "strongs", "code": code})


def search_by_strongs(strongs_code: str) -> list[dict]:
    """
    Search for all verses containing a given Strong's number.
    Returns list of verse hit dicts from the API.
    """
    result = _get({"api": "search_verses", "mode": "strongs", "q": strongs_code})
    if isinstance(result, list):
        return result
    if isinstance(result, dict):
        # Primary format: {"rows": [...], "truncated": bool, ...}
        if "rows" in result:
            return result["rows"]
        return result.get("results", [])
    return []


def parse_passage_reference(ref: str) -> tuple[str, int, int, int]:
    """
    Parse a passage reference like "Gen 1:1-5", "Isa 40:1-8", or "Rom 8:1-4".
    Returns (osis_code, chapter, start_verse, end_verse).
    A single-verse ref like "Gen 1:1" returns start_verse == end_verse.
    """
    ref = ref.strip()
    m = re.match(r'^(.+?)\s+(\d+)\s*:\s*(\d+)\s*(?:-\s*(\d+))?$', ref)
    if not m:
        raise ValueError(f"Cannot parse passage reference '{ref}' — expected 'Book chapter:verse' or 'Book chapter:start-end'")
    book_raw = m.group(1)
    chapter = int(m.group(2))
    start_verse = int(m.group(3))
    end_verse = int(m.group(4)) if m.group(4) else start_verse
    key = re.sub(r'\s+', '', book_raw).lower()
    osis = BOOK_ALIASES.get(key)
    if not osis:
        raise ValueError(f"Unknown book '{book_raw}' in reference '{ref}'")
    return osis, chapter, start_verse, end_verse


def fetch_passage(ref: str) -> dict:
    """
    Fetch all verses for a passage reference like "Isa 40:1-8".
    Returns:
      {
        "reference": str,
        "book": str, "chapter": int, "start_verse": int, "end_verse": int,
        "verses": [ {verse_number, text_original, words: [...]} ... ],
        "all_strongs": [str]   # flat list of all Strong's codes in the passage
      }
    """
    osis, chapter, start_verse, end_verse = parse_passage_reference(ref)
    verses = []
    all_strongs: list[str] = []
    for v in range(start_verse, end_verse + 1):
        vref = f"{osis} {chapter}:{v}"
        try:
            data = fetch_verse(vref)
        except (ValueError, RuntimeError):
            continue
        vrow = data.get("verse", {})
        words = []
        for w in data.get("words", []):
            orig = (w.get("text_original") or w.get("text") or "").strip()
            strongs = (w.get("strongs") or w.get("strongs_primary") or "").strip()
            gloss = (w.get("gloss") or "").strip()
            entry: dict = {}
            if orig:
                entry["text"] = orig
            if strongs:
                entry["strongs"] = strongs
            if gloss:
                entry["gloss"] = gloss
            if entry:
                words.append(entry)
        strongs_codes = extract_strongs_codes(data)
        all_strongs.extend(strongs_codes)
        # Build plain text
        parts = [w.get("text", "") for w in words if w.get("text")]
        verses.append({
            "verse": v,
            "text_original": " ".join(parts),
            "words": words,
            "strongs_codes": strongs_codes,
        })
    ref_label = f"{osis} {chapter}:{start_verse}" if start_verse == end_verse else f"{osis} {chapter}:{start_verse}-{end_verse}"
    return {
        "reference": ref_label,
        "osis": osis,
        "chapter": chapter,
        "start_verse": start_verse,
        "end_verse": end_verse,
        "verse_count": len(verses),
        "verses": verses,
        "all_strongs": all_strongs,
    }


def _is_morpheme_code(code: str) -> bool:
    """
    Return True for OSHB grammatical morpheme markers, which should not be
    treated as lexical content words.

    H9xxx codes (H9000–H9999) are OSHB tags for prefixes (waw, bet, lamed,
    mem, he-locale…), pronominal suffixes, maqqef, setumah/petuha paragraph
    markers, and sof-pasuq.  They appear in virtually every Hebrew verse and
    carry no distinctive lexical meaning useful for allusion detection.
    """
    return code.startswith('H9') and len(code) >= 3 and code[2:].isdigit()


def extract_strongs_codes(verse_data: dict) -> list[str]:
    """
    Extract all Strong's codes from a verse_full response.
    Returns a list of content-word codes (H/G prefix), one per word token,
    with duplicates preserved (repeated words count once each).

    H9xxx morphological-marker codes are filtered out.
    The trailing variant-letter suffix (e.g. the 'G' in H3068G, 'H' in
    H3559H) is kept so that the original code can be used for rarity lookup
    against the API.  Root-level comparison (stripping the suffix) is done
    inside the terms scorer.
    """
    codes = []
    for word in verse_data.get("words", []):
        raw = word.get("strongs") or word.get("strongs_primary") or ""
        if not raw:
            continue
        # The field may contain multiple codes or bracket notation like {H430}/H9021
        # Extract all H/G + digit sequences (with optional trailing letter)
        found = re.findall(r'[HG]\d{1,5}[a-zA-Z]?', raw)
        for f in found:
            code = f.upper()
            if _is_morpheme_code(code):
                continue
            codes.append(code)
    return codes
