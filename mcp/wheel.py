"""
Bible Wheel spoke structure — 22 spokes × 3 cycles = 66 books.

The wheel is formed by rolling the 66 canonical books like a scroll onto
22 spokes, one for each Hebrew letter. Each spoke holds exactly three books:
  Cycle 1 (inner):  OT books  1–22  (Torah + Historical)
  Cycle 2 (middle): OT books 23–44  (Prophets through Acts)
  Cycle 3 (outer):  NT books 45–66  (Epistles through Revelation)

Book N on Cycle 1 is on spoke N.
Book N on Cycle 2 is on spoke N-22.
Book N on Cycle 3 is on spoke N-44.
"""

from __future__ import annotations

HEBREW_LETTERS = [
    "Aleph", "Bet", "Gimel", "Dalet", "Hey", "Vav", "Zayin", "Chet",
    "Tet", "Yod", "Kaph", "Lamed", "Mem", "Nun", "Samek", "Ayin",
    "Pey", "Tzaddi", "Quph", "Resh", "Shin", "Tav",
]

HEBREW_SYMBOLS = [
    "א", "ב", "ג", "ד", "ה", "ו", "ז", "ח",
    "ט", "י", "כ", "ל", "מ", "נ", "ס", "ע",
    "פ", "צ", "ק", "ר", "ש", "ת",
]

# (cycle1_book, cycle2_book, cycle3_book) — canonical canonical order within each cycle
SPOKE_BOOKS: list[tuple[str, str, str]] = [
    ("Genesis",          "Isaiah",        "Romans"),           # 1  Aleph
    ("Exodus",           "Jeremiah",      "1 Corinthians"),    # 2  Bet
    ("Leviticus",        "Lamentations",  "2 Corinthians"),    # 3  Gimel
    ("Numbers",          "Ezekiel",       "Galatians"),        # 4  Dalet
    ("Deuteronomy",      "Daniel",        "Ephesians"),        # 5  Hey
    ("Joshua",           "Hosea",         "Philippians"),      # 6  Vav
    ("Judges",           "Joel",          "Colossians"),       # 7  Zayin
    ("Ruth",             "Amos",          "1 Thessalonians"),  # 8  Chet
    ("1 Samuel",         "Obadiah",       "2 Thessalonians"),  # 9  Tet
    ("2 Samuel",         "Jonah",         "1 Timothy"),        # 10 Yod
    ("1 Kings",          "Micah",         "2 Timothy"),        # 11 Kaph
    ("2 Kings",          "Nahum",         "Titus"),            # 12 Lamed
    ("1 Chronicles",     "Habakkuk",      "Philemon"),         # 13 Mem
    ("2 Chronicles",     "Zephaniah",     "Hebrews"),          # 14 Nun
    ("Ezra",             "Haggai",        "James"),            # 15 Samek
    ("Nehemiah",         "Zechariah",     "1 Peter"),          # 16 Ayin
    ("Esther",           "Malachi",       "2 Peter"),          # 17 Pey
    ("Job",              "Matthew",       "1 John"),           # 18 Tzaddi
    ("Psalms",           "Mark",          "2 John"),           # 19 Quph
    ("Proverbs",         "Luke",          "3 John"),           # 20 Resh
    ("Ecclesiastes",     "John",          "Jude"),             # 21 Shin
    ("Song of Solomon",  "Acts",          "Revelation"),       # 22 Tav
]

LETTER_MEANINGS: dict[str, str] = {
    "Aleph":  "Ox — God, Leader, First, Strength",
    "Bet":    "House — Son, In/Within, Household",
    "Gimel":  "Camel — Giver, Lift Up, Spirit",
    "Dalet":  "Door — Way, Path, Lowly",
    "Hey":    "Window/Behold — Life, Grace, Lo!",
    "Vav":    "Hook/Nail — And, Link, Connection",
    "Zayin":  "Weapon/Sword — Cut, Nourish, Remember",
    "Chet":   "Fence/Wall — Hedge, Mercy, Grace",
    "Tet":    "Serpent — Good, Twisted, Hidden",
    "Yod":    "Hand — Work, Deed, Praise",
    "Kaph":   "Palm/Open Hand — Open, Allow, Bend",
    "Lamed":  "Staff/Ox Goad — Teach, Authority, Learn",
    "Mem":    "Water — Chaos, Flow, Mighty, Source",
    "Nun":    "Fish — Activity, Heir, Faithfulness",
    "Samek":  "Prop/Support — Help, Support, Lean on",
    "Ayin":   "Eye — See, Understand, Experience",
    "Pey":    "Mouth — Speak, Word, Face",
    "Tzaddi": "Fish Hook — Righteous, Hunt, Harvest",
    "Quph":   "Back of Head — Circle, Behind, Cry out",
    "Resh":   "Head/Person — First, Top, Prince",
    "Shin":   "Teeth/Fire — Sharp, Press, Fire, Consume",
    "Tav":    "Mark/Cross — Sign, Seal, Covenant, Last",
}

ISAIAH_CORRELATION_NOTE = (
    "Isaiah's 66 chapters mirror the 66 books of the Bible — the Bible embedded "
    "within the Bible (mise en abyme). Chapters 1–39 parallel the 39 OT books "
    "(law and judgment); chapters 40–66 parallel the 27 NT books (grace and gospel). "
    "Isaiah 40 opens with 'Comfort ye' — exactly where Matthew opens the NT era."
)

# Flat canonical list: index 0 = book 1 (Genesis), index 65 = book 66 (Revelation)
_CANONICAL_BOOKS: list[str] = (
    [books[0] for books in SPOKE_BOOKS] +   # books 1-22  (cycle 1)
    [books[1] for books in SPOKE_BOOKS] +   # books 23-44 (cycle 2)
    [books[2] for books in SPOKE_BOOKS]     # books 45-66 (cycle 3)
)


def canonical_book_name(book_number: int) -> str:
    """Return the canonical book name for book number 1–66."""
    if not 1 <= book_number <= 66:
        raise ValueError(f"Book number must be 1–66, got {book_number}")
    return _CANONICAL_BOOKS[book_number - 1]


def book_number(book_name: str) -> int | None:
    """Return the canonical book number (1–66) for a book name, or None."""
    name_lower = book_name.lower().strip()
    for i, name in enumerate(_CANONICAL_BOOKS):
        if name.lower() == name_lower:
            return i + 1
    return None


def get_spoke(spoke_number: int) -> dict:
    """Return spoke data for spoke 1–22."""
    if not 1 <= spoke_number <= 22:
        raise ValueError(f"Spoke number must be 1–22, got {spoke_number}")
    idx = spoke_number - 1
    c1, c2, c3 = SPOKE_BOOKS[idx]
    letter = HEBREW_LETTERS[idx]
    return {
        "spoke": spoke_number,
        "letter": letter,
        "symbol": HEBREW_SYMBOLS[idx],
        "meaning": LETTER_MEANINGS[letter],
        "cycle1_book": c1,  "cycle1_book_number": spoke_number,
        "cycle2_book": c2,  "cycle2_book_number": spoke_number + 22,
        "cycle3_book": c3,  "cycle3_book_number": spoke_number + 44,
        "books": [c1, c2, c3],
    }


def get_spoke_for_book(book_name: str) -> dict | None:
    """Return spoke data for a given book name (case-insensitive)."""
    name_lower = book_name.lower().strip()
    for i, (c1, c2, c3) in enumerate(SPOKE_BOOKS):
        if name_lower in (c1.lower(), c2.lower(), c3.lower()):
            return get_spoke(i + 1)
    return None


def isaiah_chapter_to_book(chapter: int) -> dict:
    """
    Return the Bible book corresponding to Isaiah chapter N
    under the Isaiah-Bible Correlation.
    """
    if not 1 <= chapter <= 66:
        raise ValueError(f"Isaiah chapter must be 1–66, got {chapter}")
    bname = canonical_book_name(chapter)
    if chapter <= 22:
        cycle, spoke_num = 1, chapter
    elif chapter <= 44:
        cycle, spoke_num = 2, chapter - 22
    else:
        cycle, spoke_num = 3, chapter - 44
    spoke_data = get_spoke(spoke_num)
    return {
        "isaiah_chapter": chapter,
        "corresponding_book": bname,
        "book_number": chapter,
        "cycle": cycle,
        "spoke": spoke_num,
        "letter": spoke_data["letter"],
        "letter_meaning": spoke_data["meaning"],
        "testament": "OT" if chapter <= 39 else "NT",
        "correlation_note": ISAIAH_CORRELATION_NOTE,
    }
