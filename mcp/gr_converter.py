"""
GR Page Converter MCP Server
=============================
Converts old PHP Gematria Reference pages (GR_N.php) into Unicode verse notes
and inserts them into BibleDB's verse_notes table.

Tools:
  list_gr_pages     — list available GR page numbers
  preview_gr_page   — parse a GR page and preview the converted note (no DB write)
  insert_gr_note    — convert a GR page and insert as a verse note in the DB
"""

import asyncio
import base64
import json
import os
import re
from pathlib import Path
from typing import Optional

import anthropic
import pymysql
import pymysql.cursors
from mcp.server import Server
from mcp.server.stdio import stdio_server
from mcp.types import Tool, TextContent

# ── Paths ─────────────────────────────────────────────────────────────────────
GR_DIR    = Path(r"C:\Work\Resurrected\Bible Wheel Site\public_html\GR")
IMG_DIR   = Path(r"C:\Work\Resurrected\Bible Wheel Site\public_html\images\GR")
CACHE_FILE = Path(__file__).parent / "gr_image_cache.json"

# ── DB (write path to stepbible3) ─────────────────────────────────────────────
DB_CONFIG = dict(
    host="127.0.0.1", port=3306,
    user="root", password="Zubi3168^2!!",
    database="stepbible3", charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
)

DEFAULT_USER_ID  = 2
DEFAULT_USERNAME = "RAMcGough"

# ── Book abbreviation → OSIS code ─────────────────────────────────────────────
BOOK_MAP: dict[str, str] = {
    "gen": "Gen", "genesis": "Gen",
    "ex": "Exo", "exo": "Exo", "exodus": "Exo",
    "lev": "Lev", "leviticus": "Lev",
    "num": "Num", "numbers": "Num",
    "deu": "Deu", "deut": "Deu", "deuteronomy": "Deu",
    "jos": "Jos", "josh": "Jos", "joshua": "Jos",
    "jdg": "Jdg", "judg": "Jdg", "judges": "Jdg",
    "rut": "Rut", "ruth": "Rut",
    "1sa": "1Sa", "1sam": "1Sa",
    "2sa": "2Sa", "2sam": "2Sa",
    "1ki": "1Ki", "1kgs": "1Ki",
    "2ki": "2Ki", "2kgs": "2Ki",
    "1ch": "1Ch", "1chr": "1Ch",
    "2ch": "2Ch", "2chr": "2Ch",
    "ezr": "Ezr", "ezra": "Ezr",
    "neh": "Neh", "nehemiah": "Neh",
    "est": "Est", "esther": "Est",
    "job": "Job",
    "ps": "Psa", "psa": "Psa", "psalm": "Psa", "psalms": "Psa",
    "pr": "Pro", "pro": "Pro", "prov": "Pro", "proverbs": "Pro",
    "ecc": "Ecc", "eccl": "Ecc", "ecclesiastes": "Ecc",
    "son": "Son", "song": "Son", "sos": "Son",
    "is": "Isa", "isa": "Isa", "isaiah": "Isa",
    "jer": "Jer", "jeremiah": "Jer",
    "lam": "Lam", "lamentations": "Lam",
    "eze": "Eze", "ezek": "Eze", "ezekiel": "Eze",
    "dan": "Dan", "daniel": "Dan",
    "hos": "Hos", "hosea": "Hos",
    "joe": "Joe", "joel": "Joe",
    "amo": "Amo", "amos": "Amo",
    "oba": "Oba", "obad": "Oba", "obadiah": "Oba",
    "jon": "Jon", "jonah": "Jon",
    "mic": "Mic", "micah": "Mic",
    "nah": "Nah", "nahum": "Nah",
    "hab": "Hab", "habakkuk": "Hab",
    "zep": "Zep", "zeph": "Zep", "zephaniah": "Zep",
    "hag": "Hag", "haggai": "Hag",
    "zec": "Zec", "zech": "Zec", "zechariah": "Zec",
    "mal": "Mal", "malachi": "Mal",
    "mt": "Mat", "mat": "Mat", "matt": "Mat", "matthew": "Mat",
    "mk": "Mar", "mar": "Mar", "mark": "Mar",
    "lk": "Luk", "luc": "Luk", "luk": "Luk", "luke": "Luk",
    "jn": "Jhn", "joh": "Jhn", "john": "Jhn",
    "act": "Act", "acts": "Act",
    "rom": "Rom", "romans": "Rom",
    "1co": "1Co", "1cor": "1Co",
    "2co": "2Co", "2cor": "2Co",
    "gal": "Gal", "galatians": "Gal",
    "eph": "Eph", "ephesians": "Eph",
    "php": "Php", "phi": "Php", "phil": "Php", "philippians": "Php",
    "col": "Col", "colossians": "Col",
    "1th": "1Th", "1thess": "1Th",
    "2th": "2Th", "2thess": "2Th",
    "1ti": "1Ti", "1tim": "1Ti",
    "2ti": "2Ti", "2tim": "2Ti",
    "tit": "Tit", "titus": "Tit",
    "phm": "Phm", "philemon": "Phm",
    "heb": "Heb", "hebrews": "Heb",
    "jas": "Jas", "james": "Jas",
    "1pe": "1Pe", "1pet": "1Pe",
    "2pe": "2Pe", "2pet": "2Pe",
    "1jo": "1Jn", "1jn": "1Jn",
    "2jo": "2Jn", "2jn": "2Jn",
    "3jo": "3Jn", "3jn": "3Jn",
    "jud": "Jud", "jude": "Jud",
    "rev": "Rev", "revelation": "Rev", "revelations": "Rev",
}

# ── Image cache ────────────────────────────────────────────────────────────────
def _load_cache() -> dict[str, str]:
    if CACHE_FILE.exists():
        try:
            return json.loads(CACHE_FILE.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {}

def _save_cache(cache: dict[str, str]) -> None:
    CACHE_FILE.write_text(json.dumps(cache, ensure_ascii=False, indent=2), encoding="utf-8")

# ── Vision: image → Unicode ────────────────────────────────────────────────────
def image_to_unicode(stem: str) -> str:
    """Return Unicode text for a Hebrew/Greek word GIF, using Claude vision.

    stem is the filename without extension, e.g. '00037H_TheHeart'.
    Caches results in gr_image_cache.json.
    """
    cache = _load_cache()
    if stem in cache:
        return cache[stem]

    # Look for the image file (try .gif, .jpg, .png)
    img_path: Optional[Path] = None
    for ext in (".gif", ".jpg", ".png"):
        candidate = IMG_DIR / (stem + ext)
        if candidate.exists():
            img_path = candidate
            break
    # Also check GR dir itself
    if img_path is None:
        for ext in (".gif", ".jpg", ".png"):
            candidate = GR_DIR / (stem + ext)
            if candidate.exists():
                img_path = candidate
                break

    if img_path is None:
        return ""

    # Determine language
    if re.search(r'\d{5}H_', stem):
        lang = "Hebrew"
    elif re.search(r'\d{5}G_', stem):
        lang = "Greek"
    else:
        lang = "Hebrew or Greek"

    # Determine media type
    suffix = img_path.suffix.lower()
    media_map = {".gif": "image/gif", ".jpg": "image/jpeg", ".png": "image/png"}
    media_type = media_map.get(suffix, "image/gif")

    data_b64 = base64.standard_b64encode(img_path.read_bytes()).decode("utf-8")

    api_key = os.environ.get("ANTHROPIC_API_KEY", "")
    if not api_key:
        return ""   # no key → fall through to img tag fallback in caller
    client = anthropic.Anthropic(api_key=api_key)
    resp = client.messages.create(
        model="claude-haiku-4-5-20251001",
        max_tokens=80,
        messages=[{
            "role": "user",
            "content": [
                {
                    "type": "image",
                    "source": {"type": "base64", "media_type": media_type, "data": data_b64},
                },
                {
                    "type": "text",
                    "text": (
                        f"This image shows a {lang} word or short phrase rendered in its original script. "
                        "Please return ONLY the Unicode characters of that word/phrase — "
                        "no transliteration, no explanation, no punctuation around it."
                    ),
                },
            ],
        }],
    )
    text = resp.content[0].text.strip()

    cache[stem] = text
    _save_cache(cache)
    return text


# ── PHP parsing helpers ────────────────────────────────────────────────────────

def _parse_citation(cit: str) -> tuple[str, int, int]:
    """Parse a citation like 'Psalm 2.6' → ('Psa', 2, 6).

    Returns ('', 0, 0) on failure.
    """
    # Normalise: 'Ps 2.6f' → 'Ps 2.6'; '1 John 3.16' OK
    cit = re.sub(r'[a-z]$', '', cit.strip())          # strip trailing verse letter
    cit = re.sub(r'\s*-\s*\d+$', '', cit)              # strip end of range
    m = re.match(
        r'^((?:\d\s+)?[A-Za-z]+(?:\s+of\s+[A-Za-z]+)?)\s+(\d+)[.:,](\d+)',
        cit,
        re.IGNORECASE,
    )
    if not m:
        # Try just chapter: 'Genesis 1' → chapter 1, verse 1
        m2 = re.match(r'^((?:\d\s+)?[A-Za-z]+)\s+(\d+)$', cit, re.IGNORECASE)
        if m2:
            book_raw, chapter = m2.group(1), int(m2.group(2))
            osis = BOOK_MAP.get(book_raw.lower().strip())
            return (osis or "", chapter, 1)
        return ("", 0, 0)

    book_raw = m.group(1).strip()
    chapter  = int(m.group(2))
    verse    = int(m.group(3))

    # Try full name first, then abbreviation
    key = book_raw.lower()
    osis = BOOK_MAP.get(key)
    if not osis:
        # Try just first word (handles "Song of Solomon 1.1")
        osis = BOOK_MAP.get(key.split()[0])
    if not osis:
        # Try stripped of spaces
        osis = BOOK_MAP.get(key.replace(" ", ""))
    return (osis or "", chapter, verse)


def _resolve_php_vars(text: str, php_vars: dict[str, str]) -> str:
    """Replace <?php echo $var ?> and inline $var references in HTML context."""
    for name, val in php_vars.items():
        text = text.replace(f"<?php echo ${name} ?>", val)
        text = text.replace(f"<?php echo ${name}; ?>", val)
        text = re.sub(rf'\$\b{re.escape(name)}\b', val, text)
    return text


def _simplify_php_inline(text: str) -> str:
    """Replace common PHP inline expressions with plain text."""
    # writenumlink("37") or echo writenumlink("37") → [37]
    text = re.sub(r"writenumlink\(['\"](\d+)['\"]\)", r"[\1]", text)
    text = re.sub(r"returnnumlink\(['\"](\d+)['\"]\)", r"[\1]", text)
    # strongs("3231","h") → [S# 3231]
    text = re.sub(r'strongs\([\'"](\d+)[\'"],\s*[\'"][hgHG][\'"]\)', r'[S# \1]', text)
    # greek("Ihsou~",13) or hebrew("qvP",14) — just drop (image-based encoding)
    text = re.sub(r'(?:greek|hebrew)\([^)]+\)', '', text)
    # echo $n37 where var was not captured → try to infer number from name
    text = re.sub(r'\$n(\d+)', r'[\1]', text)
    # Remove remaining simple PHP echo of variables
    text = re.sub(r"<\?php\s+echo\s+\$[a-z_]+\s*;?\s*\?>", '', text, flags=re.IGNORECASE)
    # Remove variable assignments (non-capturing ones)
    text = re.sub(r"<\?php[^>]*?\$[a-z_]+\s*=\s*returnnumlink[^;]+;\s*\?>", '', text,
                  flags=re.IGNORECASE | re.DOTALL)
    return text


def _build_word_entry_bbcode(desc: str, img_stem: str, translit: str, extract_unicode: bool) -> str:
    """Build a BBCode block for one word/phrase entry."""
    unicode_text = ""
    if img_stem and extract_unicode:
        unicode_text = image_to_unicode(img_stem)

    parts = [f"[b]{desc}[/b]"]
    if unicode_text:
        parts.append(unicode_text)
    if translit:
        parts.append(f"[i]{translit}[/i]")
    return "  ".join(parts)


def _parse_desc_string(raw_php_desc: str) -> list[tuple[str, str, str]]:
    """Parse the PHP $desc string (entries separated by @, fields by $).

    Returns list of (description, image_stem, transliteration).
    """
    # Clean up PHP concatenation artifacts: ' . $var . ' → var placeholder
    raw = raw_php_desc
    raw = re.sub(r'"\s*\.\s*\$(?:ord|full|cnt)\s*\.\s*"', '(ord)', raw)
    raw = re.sub(r'"\s*\.\s*writenumlink\([\'"](\d+)[\'"]\)\s*\.\s*"', r'[\1]', raw)
    raw = re.sub(r'"\s*\.\s*returnnumlink\([\'"](\d+)[\'"]\)\s*\.\s*"', r'[\1]', raw)
    raw = re.sub(r'"\s*\.\s*strongs\([\'"](\d+)[\'"],\s*[\'"][hgHG][\'"]\)\s*\.\s*"',
                 r'[S# \1]', raw)
    raw = re.sub(r'"\s*\.\s*\$n(\d+)\s*\.\s*"', r'[\1]', raw)
    raw = re.sub(r'"\s*\.\s*[^"]+\s*\.\s*"', ' ', raw)  # unknown inline expressions

    entries = raw.split("@")
    result = []
    for entry in entries:
        parts = entry.split("$")
        desc    = parts[0].strip() if len(parts) > 0 else ""
        img     = parts[1].strip() if len(parts) > 1 else ""
        translit= parts[2].strip() if len(parts) > 2 else ""
        if desc or img:
            result.append((desc, img, translit))
    return result


def convert_gr_page(gr_number: int, extract_unicode: bool = True) -> dict:
    """Parse a GR_N.php file and return a note dict ready for DB insertion.

    Returns dict with keys:
      title, book_code, chapter, verse, note_text, gem_std, citation_raw
    """
    php_path = GR_DIR / f"GR_{gr_number}.php"
    if not php_path.exists():
        raise FileNotFoundError(f"GR_{gr_number}.php not found at {php_path}")

    raw = php_path.read_text(encoding="windows-1252", errors="replace")

    # ── 1. Extract title ──────────────────────────────────────────────────
    title_match = re.search(r'\$title\s*=\s*["\']([^"\']+)["\']', raw)
    title = title_match.group(1) if title_match else f"The Number {gr_number}"
    # Strip "[GR] > " prefix if present
    title = re.sub(r'^\[GR\]\s*>\s*', '', title)

    # ── 2. Extract main content region ───────────────────────────────────
    # Structure: <tr><td class='TopBar'>...</td></tr>\n<tr><td>\n[CONTENT]\n</td></tr>
    # Skip the TopBar row and grab the next <tr><td> content block.
    topbar_end = re.search(r"<td[^>]*class=['\"]TopBar['\"][^>]*>.*?</td>", raw,
                           re.DOTALL | re.IGNORECASE)
    search_from = topbar_end.end() if topbar_end else 0

    content_match = re.search(
        r'<tr>\s*<td[^>]*>\s*(.*?)(?=\s*</td>\s*</tr>\s*<\?php\s+require'
        r'|\s*</td>\s*</tr>\s*</table>)',
        raw[search_from:], re.DOTALL | re.IGNORECASE,
    )
    if content_match:
        content = content_match.group(1)
    else:
        # Fallback: everything between the second <tr><td> and the footer
        second_td = re.search(r'(?:.*?<tr><td[^>]*>){2}', raw, re.DOTALL)
        body_m = re.search(r'<body[^>]*>(.*)', raw, re.DOTALL | re.IGNORECASE)
        content = body_m.group(1) if body_m else raw

    # ── 3. Extract opening verse and citation ─────────────────────────────
    verse_match = re.search(r"<p[^>]*class=['\"]BVerse['\"][^>]*>(.*?)</p>",
                             content, re.DOTALL | re.IGNORECASE)
    cit_match   = re.search(r"<p[^>]*class=['\"]BCit['\"][^>]*>(.*?)</p>",
                             content, re.DOTALL | re.IGNORECASE)

    opening_verse = verse_match.group(1).strip() if verse_match else ""
    citation_raw  = re.sub(r'<[^>]+>', '', cit_match.group(1)).strip() if cit_match else ""

    book_code, chapter, verse = _parse_citation(citation_raw)

    # ── 4. Capture PHP variable assignments ($n37 etc.) ───────────────────
    php_vars: dict[str, str] = {}
    for m in re.finditer(
        r'\$([a-z_][a-z0-9_]*)\s*=\s*returnnumlink\([\'"](\d+)[\'"]\)\s*;',
        raw, re.IGNORECASE
    ):
        php_vars[m.group(1)] = f"[{m.group(2)}]"

    # ── 5. Process the main content into HTML ─────────────────────────────
    html_parts: list[str] = []

    if opening_verse:
        opening_verse = re.sub(r'<[^>]+>', '', opening_verse).strip()
        html_parts.append(f"[quote]{opening_verse}[/quote]")
    if citation_raw:
        html_parts.append(f"[i]{citation_raw}[/i]")

    # Remove the opening verse/cit from further processing to avoid duplication
    working = content
    if verse_match:
        working = working[:verse_match.start()] + working[verse_match.end():]
    if cit_match:
        # Recompute positions after first removal (simple approach: strip both)
        working = re.sub(r"<p[^>]*class=['\"]BVerse['\"][^>]*>.*?</p>", '',
                         working, flags=re.DOTALL | re.IGNORECASE)
        working = re.sub(r"<p[^>]*class=['\"]BCit['\"][^>]*>.*?</p>", '',
                         working, flags=re.DOTALL | re.IGNORECASE)

    # ── 5a. Handle tblNVT blocks ─────────────────────────────────────────
    # Pattern: PHP block containing $desc assignments and a tblNVT() call.
    # Strategy: match any <?php ... ?> block that contains tblNVT, then parse it.
    def replace_tblnvt_block(m_full_block: re.Match) -> str:
        block = m_full_block.group(0)

        # Collect ALL string literals from $desc lines, in order.
        # Each line is like: $desc = "..." or $desc .= "@..." . $var . "..."
        # We extract all double-quoted string fragments and join them.
        desc_lines = re.findall(r'\$desc\s*\.?=\s*(.*?);', block, re.DOTALL)
        if not desc_lines:
            return "<!-- tblNVT: could not parse $desc -->"

        # From each RHS expression, extract all quoted string fragments
        raw_parts: list[str] = []
        for expr in desc_lines:
            frags = re.findall(r'"((?:[^"\\]|\\.)*)"', expr)
            raw_parts.extend(frags)

        full_desc = "".join(raw_parts)

        # Extract tblNVT title
        tnvt_m = re.search(r'tblNVT\w*\s*\(\s*"([^"]*)"', block)
        tbl_title = tnvt_m.group(1) if tnvt_m else ""

        entries = _parse_desc_string(full_desc)
        items = []
        for desc, img, translit in entries:
            items.append("[*]" + _build_word_entry_bbcode(desc, img, translit, extract_unicode))

        result = ""
        if tbl_title:
            result += f"[b]{tbl_title}[/b]\n"
        result += "[list]\n" + "\n".join(items) + "\n[/list]"
        return result

    # Match any <?php ... ?> block that contains a tblNVT call
    tblnvt_pattern = re.compile(
        r'<\?php\b(?:(?!\?>).)*tblNVT\w*\s*\([^)]+\)\s*;?\s*\?>',
        re.DOTALL | re.IGNORECASE,
    )
    working = tblnvt_pattern.sub(replace_tblnvt_block, working)

    # ── 5b. Handle singlegem() calls ─────────────────────────────────────
    def replace_singlegem(m: re.Match) -> str:
        inner = m.group(1)
        # Parse 5 arguments (may contain nested quotes — simple split on ","<space>")
        args = _split_php_args(inner)
        if len(args) < 3:
            return f"<!-- singlegem parse error: {inner[:60]} -->"
        desc1  = args[0].strip('"\'').strip()
        img    = args[1].strip('"\'').strip()
        translit = args[2].strip('"\'').strip()
        num    = args[3].strip('"\'').strip() if len(args) > 3 else ""
        if num:
            desc1 = f"{desc1} = {num}"
        return _build_word_entry_bbcode(desc1, img, translit, extract_unicode)

    working = re.sub(
        r'<\?php\s*(?:echo\s+)?singlegem(?:L|R)?\s*\(([^;]+?)\)\s*;?\s*\?>',
        replace_singlegem, working, flags=re.DOTALL | re.IGNORECASE,
    )

    # ── 5c. Handle getpic() calls ─────────────────────────────────────────
    def replace_getpic(m: re.Match) -> str:
        inner = m.group(1)
        args  = _split_php_args(inner)
        if not args:
            return ""
        pic = args[0].strip('"\'').strip()
        if re.match(r'^\d{5}[HG]_', pic):
            uni = image_to_unicode(pic) if extract_unicode else ""
            if uni:
                return uni
        return f"[img]/images/GR/{pic}.gif[/img]"

    working = re.sub(
        r'<\?php\s*(?:echo\s+)?(?:getpic|grpic)\s*\(([^;)]+)\)\s*;?\s*\?>',
        replace_getpic, working, flags=re.DOTALL | re.IGNORECASE,
    )

    # ── 5d. Strip remaining PHP boilerplate ──────────────────────────────
    working = _resolve_php_vars(working, php_vars)
    working = _simplify_php_inline(working)
    # Strip remaining <?php ... ?> blocks
    working = re.sub(r'<\?php.*?\?>', '', working, flags=re.DOTALL)
    # Strip server-side includes
    working = re.sub(r'<!--#include[^>]+-->', '', working)
    # Convert remaining HTML to BBCode equivalents
    working = re.sub(r'<b>(.*?)</b>', r'[b]\1[/b]', working, flags=re.DOTALL | re.IGNORECASE)
    working = re.sub(r'<strong>(.*?)</strong>', r'[b]\1[/b]', working, flags=re.DOTALL | re.IGNORECASE)
    working = re.sub(r'<i>(.*?)</i>', r'[i]\1[/i]', working, flags=re.DOTALL | re.IGNORECASE)
    working = re.sub(r'<em>(.*?)</em>', r'[i]\1[/i]', working, flags=re.DOTALL | re.IGNORECASE)
    working = re.sub(r'<br\s*/?>', '\n', working, flags=re.IGNORECASE)
    working = re.sub(r'<p[^>]*>(.*?)</p>', r'\1\n', working, flags=re.DOTALL | re.IGNORECASE)
    working = re.sub(r'<hr[^>]*>', '\n', working, flags=re.IGNORECASE)
    # Strip any remaining HTML tags
    working = re.sub(r'<[^>]+>', '', working)

    # ── 5e. Clean up whitespace ───────────────────────────────────────────
    working = re.sub(r'\n{3,}', '\n\n', working)
    working = working.strip()

    if working:
        html_parts.append(working)

    note_text = "\n".join(html_parts)

    # Add a source attribution footer
    note_text += (
        f"\n\nSource: [url=https://www.biblewheel.com/GR/GR_{gr_number}.php]"
        f"Gematria Reference – The Number {gr_number}[/url]"
    )

    return {
        "title": title,
        "book_code": book_code,
        "chapter": chapter,
        "verse": verse,
        "citation_raw": citation_raw,
        "note_text": note_text,
        "gem_std": gr_number,
    }


def _split_php_args(args_str: str) -> list[str]:
    """Split a PHP argument list string on commas, respecting quoted strings."""
    args: list[str] = []
    depth  = 0
    buf    = []
    in_q   = None
    for ch in args_str:
        if in_q:
            buf.append(ch)
            if ch == in_q:
                in_q = None
        elif ch in ('"', "'"):
            in_q = ch
            buf.append(ch)
        elif ch in ('(', '[', '{'):
            depth += 1
            buf.append(ch)
        elif ch in (')', ']', '}'):
            depth -= 1
            buf.append(ch)
        elif ch == ',' and depth == 0:
            args.append("".join(buf).strip())
            buf = []
        else:
            buf.append(ch)
    if buf:
        args.append("".join(buf).strip())
    return args


def insert_note(note: dict, user_id: int, username: str) -> int:
    """Insert a note dict into verse_notes. Returns the new note ID."""
    conn = pymysql.connect(**DB_CONFIG)
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO verse_notes
                    (user_id, username, book_code, chapter, verse,
                     title, note_text, is_public, gem_std)
                VALUES (%s, %s, %s, %s, %s, %s, %s, 1, %s)
                """,
                (
                    user_id, username,
                    note["book_code"], note["chapter"], note["verse"],
                    note["title"], note["note_text"], note["gem_std"],
                ),
            )
            conn.commit()
            return cur.lastrowid
    finally:
        conn.close()


def list_gr_pages() -> list[int]:
    """Return sorted list of available GR page numbers."""
    nums = []
    for p in GR_DIR.glob("GR_*.php"):
        m = re.match(r'GR_(\d+)\.php', p.name, re.IGNORECASE)
        if m:
            nums.append(int(m.group(1)))
    return sorted(nums)


# ── MCP server ─────────────────────────────────────────────────────────────────
server = Server("gr-converter")


@server.list_tools()
async def handle_list_tools() -> list[Tool]:
    return [
        Tool(
            name="list_gr_pages",
            description="List all available GR_N page numbers found in the GR directory.",
            inputSchema={"type": "object", "properties": {}, "required": []},
        ),
        Tool(
            name="preview_gr_page",
            description=(
                "Parse a GR_N.php page, convert Hebrew/Greek images to Unicode, "
                "and return a preview of the note that would be inserted. "
                "Does NOT write to the database."
            ),
            inputSchema={
                "type": "object",
                "properties": {
                    "gr_number": {
                        "type": "integer",
                        "description": "The N in GR_N.php (e.g. 37 for GR_37.php).",
                    },
                    "extract_unicode": {
                        "type": "boolean",
                        "description": "If true (default), call Claude vision to extract Hebrew/Greek Unicode from images.",
                        "default": True,
                    },
                },
                "required": ["gr_number"],
            },
        ),
        Tool(
            name="insert_gr_note",
            description=(
                "Convert a GR_N.php page to a Unicode verse note and insert it into "
                "the BibleDB verse_notes table. Defaults to user RAMcGough (user_id=2). "
                "Set dry_run=true to preview without inserting."
            ),
            inputSchema={
                "type": "object",
                "properties": {
                    "gr_number": {
                        "type": "integer",
                        "description": "The N in GR_N.php.",
                    },
                    "user_id": {
                        "type": "integer",
                        "description": f"DB user ID for the note author (default {DEFAULT_USER_ID}).",
                        "default": DEFAULT_USER_ID,
                    },
                    "username": {
                        "type": "string",
                        "description": f"Username for the note (default '{DEFAULT_USERNAME}').",
                        "default": DEFAULT_USERNAME,
                    },
                    "dry_run": {
                        "type": "boolean",
                        "description": "If true, parse and convert but do not write to the DB.",
                        "default": False,
                    },
                    "extract_unicode": {
                        "type": "boolean",
                        "description": "If true (default), call Claude vision to extract Hebrew/Greek Unicode.",
                        "default": True,
                    },
                },
                "required": ["gr_number"],
            },
        ),
    ]


@server.call_tool()
async def handle_call_tool(name: str, arguments: dict) -> list[TextContent]:
    try:
        if name == "list_gr_pages":
            pages = list_gr_pages()
            return [TextContent(type="text", text=f"Found {len(pages)} GR pages:\n{pages}")]

        elif name == "preview_gr_page":
            gr_num  = int(arguments["gr_number"])
            extract = bool(arguments.get("extract_unicode", True))
            note    = convert_gr_page(gr_num, extract_unicode=extract)
            summary = (
                f"=== Preview: GR_{gr_num} ===\n"
                f"Title      : {note['title']}\n"
                f"Citation   : {note['citation_raw']}\n"
                f"Book code  : {note['book_code']}\n"
                f"Chapter    : {note['chapter']}\n"
                f"Verse      : {note['verse']}\n"
                f"Gem std    : {note['gem_std']}\n"
                f"\n--- note_text ({len(note['note_text'])} chars) ---\n"
                f"{note['note_text']}"
            )
            return [TextContent(type="text", text=summary)]

        elif name == "insert_gr_note":
            gr_num   = int(arguments["gr_number"])
            user_id  = int(arguments.get("user_id", DEFAULT_USER_ID))
            username = str(arguments.get("username", DEFAULT_USERNAME))
            dry_run  = bool(arguments.get("dry_run", False))
            extract  = bool(arguments.get("extract_unicode", True))

            note = convert_gr_page(gr_num, extract_unicode=extract)

            if dry_run:
                return [TextContent(
                    type="text",
                    text=(
                        f"[DRY RUN] Would insert note for GR_{gr_num}:\n"
                        f"  title={note['title']!r}\n"
                        f"  book={note['book_code']}  ch={note['chapter']}  v={note['verse']}\n"
                        f"  gem_std={note['gem_std']}\n"
                        f"  user_id={user_id}  username={username!r}\n"
                        f"  note_text length={len(note['note_text'])} chars\n\n"
                        f"{note['note_text']}"
                    ),
                )]

            note_id = insert_note(note, user_id=user_id, username=username)
            return [TextContent(
                type="text",
                text=(
                    f"Inserted note for GR_{gr_num} as verse_notes.id={note_id}\n"
                    f"  title    : {note['title']}\n"
                    f"  verse    : {note['book_code']} {note['chapter']}:{note['verse']}\n"
                    f"  gem_std  : {note['gem_std']}\n"
                    f"  username : {username}  (user_id={user_id})"
                ),
            )]

        else:
            return [TextContent(type="text", text=f"Unknown tool: {name}")]

    except Exception as exc:  # noqa: BLE001
        import traceback
        return [TextContent(type="text", text=f"Error: {exc}\n{traceback.format_exc()}")]


async def main() -> None:
    async with stdio_server() as (read_stream, write_stream):
        await server.run(read_stream, write_stream, server.create_initialization_options())


if __name__ == "__main__":
    asyncio.run(main())
