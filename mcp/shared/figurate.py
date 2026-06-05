"""
Figurate number detection and prime factorization utilities.

Supported figurate types (matching the Bible Wheel GR convention):
  triangular   T(n)  = n(n+1)/2          [also written Sum(n)]
  square        Sq(n) = n²
  pentagonal   P(n)  = n(3n-1)/2
  hex           H(n)  = 3n(n-1)+1        [centered hexagonal — e.g. 37 = H(4)]
  star          S(n)  = 6n(n-1)+1        [Star of David / hexagram — e.g. 73 = S(4)]

Note: the "standard" hexagonal H(n)=n(2n-1) is intentionally NOT included —
it is merely a subset of the triangular numbers and not used in this research.
A Hex/Star pair like 37/73 (H(4)/S(4)) is the signature of the Creation Holograph.
"""

import math
from typing import Optional


# ---------------------------------------------------------------------------
# Prime factorization
# ---------------------------------------------------------------------------

def factorize(n: int) -> dict[int, int]:
    """Return prime factorization of n as {prime: exponent}."""
    factors: dict[int, int] = {}
    if n < 2:
        return factors
    d = 2
    while d * d <= n:
        while n % d == 0:
            factors[d] = factors.get(d, 0) + 1
            n //= d
        d += 1
    if n > 1:
        factors[n] = factors.get(n, 0) + 1
    return factors


def factor_string(n: int) -> str:
    """Return a readable factorization like '2² × 3 × 37'."""
    factors = factorize(n)
    if not factors:
        return str(n)
    parts = []
    for p in sorted(factors):
        e = factors[p]
        parts.append(str(p) if e == 1 else f"{p}^{e}")
    return " × ".join(parts)


def is_prime(n: int) -> bool:
    if n < 2:
        return False
    if n == 2:
        return True
    if n % 2 == 0:
        return False
    for i in range(3, int(math.isqrt(n)) + 1, 2):
        if n % i == 0:
            return False
    return True


# ---------------------------------------------------------------------------
# Inverse figurate formulas — return n if the number IS that type, else None
# ---------------------------------------------------------------------------

def _is_triangular(x: int) -> Optional[int]:
    # T(n) = n(n+1)/2  →  n = (-1 + sqrt(1+8x)) / 2
    disc = 1 + 8 * x
    sq = math.isqrt(disc)
    if sq * sq == disc and (sq - 1) % 2 == 0:
        return (sq - 1) // 2
    return None


def _is_square(x: int) -> Optional[int]:
    sq = math.isqrt(x)
    return sq if sq * sq == x else None


def _is_pentagonal(x: int) -> Optional[int]:
    # P(n) = n(3n-1)/2  →  24x+1 must be a perfect square ≡ 5 mod 6
    disc = 1 + 24 * x
    sq = math.isqrt(disc)
    if sq * sq == disc and (sq + 1) % 6 == 0:
        return (sq + 1) // 6
    return None


def _is_hex(x: int) -> Optional[int]:
    # H(n) = 3n(n-1)+1  (centered hexagonal — Richard's "Hex")
    # 3n^2 - 3n + (1-x) = 0  →  disc = 9 - 12(1-x) = 12x - 3
    disc = 12 * x - 3
    if disc < 0:
        return None
    sq = math.isqrt(disc)
    if sq * sq == disc and (3 + sq) % 6 == 0:
        return (3 + sq) // 6
    return None


def _is_star(x: int) -> Optional[int]:
    # S(n) = 6n^2 - 6n + 1  →  disc = 36 - 24(1-x) = 24x - 12 + 36 = wait...
    # 6n^2 - 6n + (1-x) = 0  →  disc = 36 - 24(1-x) = 36 + 24x - 24 = 24x + 12
    disc = 24 * x + 12
    sq = math.isqrt(disc)
    if sq * sq == disc and (6 + sq) % 12 == 0:
        return (6 + sq) // 12
    return None


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

FIGURATE_CHECKS = [
    ("triangular", _is_triangular, "T"),
    ("square",     _is_square,     "Sq"),
    ("pentagonal", _is_pentagonal, "P"),
    ("hex",        _is_hex,        "H"),   # centered hexagonal: H(n) = 3n(n-1)+1
    ("star",       _is_star,       "S"),   # star/hexagram:      S(n) = 6n(n-1)+1
]


def analyze(n: int) -> dict:
    """
    Full analysis of an integer n:
      - prime factorization
      - whether it is prime
      - all figurate types it belongs to
    """
    figurate = []
    for type_name, check_fn, abbrev in FIGURATE_CHECKS:
        order = check_fn(n)
        if order is not None and order >= 1:
            figurate.append({
                "type": type_name,
                "order": order,
                "formula": f"{abbrev}({order})",
            })

    return {
        "number": n,
        "is_prime": is_prime(n),
        "factorization": factor_string(n),
        "factors": factorize(n),
        "figurate": figurate,
    }


def figurate_summary(n: int) -> str:
    """One-line human summary, e.g. '37 = H(4) = S(3)' or '2701 = T(73)'."""
    figs = [f["formula"] for f in analyze(n)["figurate"]]
    return f"{n} = " + " = ".join(figs) if figs else f"{n} (no figurate form)"


def known_notable(n: int) -> list[str]:
    """
    Return a list of named gematria associations for well-known numbers.
    Extend this table as research accumulates.
    """
    table: dict[int, list[str]] = {
        # ── Unity Holograph generating set {2, 13, 43} and its 7 products ──────
        # Shema (Deut 6:4) total = 1118 = 2×13×43
        2:    ["Ben (Son) — Number of the Son; Image, Reflection (Spoke 2 / Bet)"],
        7:    ["Days of creation (Gen 1)", "Words in Gen 1:1"],
        13:   ["Echad (One) = Ahavah (Love) = Avi (My Father)", "S(2) — first Star of David",
               "Unity Holograph prime: Father"],
        19:   ["H(3) — centered hexagon", "HaLev ordinal (The Heart)"],
        26:   ["YHVH (Tetragrammaton) = 2×13 = Image×Father",
               "Maimonides: 26 Propositions proving God's existence",
               "Unity Holograph: YHVH reveals the Son"],
        28:   ["Letters in Gen 1:1", "T(7)"],
        37:   ["Yechidah", "CH(4) = S(3) — hinge number", "Chokmah ordinal (Wisdom)"],
        39:   ["YHVH Echad (The LORD is One) = 3×13 = Three×One — Trinity in Unity declaration"],
        43:   ["Gadlo (His Greatness, Deut 5:24)", "Geel (Exceeding Joy, Ps 43:4)",
               "Unity Holograph prime: Holy Spirit — Gimel KeyWords",
               "Psalm 43: Light+Truth KeyLink to John (Book 43)"],
        52:   ["Ben (Son) = 2×26 = 2×YHVH", "T(B) = T(Aleph+Bet)"],
        73:   ["Chokmah (Wisdom)", "S(4)"],
        86:   ["Elohim = 2×43 = Image×Joy", "HaTeva (Nature)",
               "Gaon YHVH (Majesty of the LORD, Jer 24:14)",
               "Hallelu-Yah (Praise the LORD)",
               "Koachi HaGadol (My Great Power, Jer 27:5)",
               "Unity Holograph: Elohim reveals the Holy Spirit"],
        102:  ["Eloheinu (our God) — Shema word 4; center of double YHVH frame"],
        111:  ["Aleph (full spelling אלף)", "Triple 1 — Aleph×Aleph×Aleph"],
        112:  ["YHVH Elohim"],
        137:  ["Elohi Amen (God of Truth)", "HGS sum {27+37+73}", "≈ 1/α fine-structure constant"],
        203:  ["Bara (created) — Gen 1:1 word 2"],
        222:  ["Barak (Blessing) — Bet KeyWord"],
        284:  ["Theos (God, Greek) = Hagios (Holy, Greek)"],
        296:  ["HaAretz (the earth)"],
        373:  ["Logos (the Word, Greek)"],
        395:  ["HaShamayim (the heavens)"],
        401:  ["Aleph-Tav (את)"],
        410:  ["Shema (Hear!) — Deut 6:4 word 1"],
        541:  ["Yisrael (Israel) — Shema word 2", "S(10) — 10th Star of David number"],
        543:  ["Ehyeh Asher Ehyeh (I AM THAT I AM) — related acronym"],
        559:  ["Ho Pater (The Father, Greek) = 13×43",
               "No Hebrew title of God = 559 — Shema requires NT to complete",
               "Unity Holograph: The Father (composite)"],
        703:  ["V'et HaAretz (and the earth) — Gen 1:1 words 6-7", "T(37) = 19×37"],
        801:  ["Alpha-Omega (Greek)"],
        852:  ["Hagios Hagios Hagios (Holy Holy Holy, Gk) = Barak Shalash (Threefold Blessing, Hb)"],
        888:  ["Iesous (Jesus, Greek) = 111×8 = Aleph×8",
               "1118 (Shema) → 111×8 = 888: Jesus hidden in the Shema"],
        913:  ["Bereishit (In the beginning) — Gen 1:1 word 1",
               "Ho Theos Ho Pater (God the Father, Greek) = 913"],
        946:  ["To Pneuma (The Spirit, Greek) = T(43) = 11×86 = 22×43",
               "'The Spirit answers: Where is Elohim?'"],
        1118: ["Sum of Shema (Deut 6:4) = 2×13×43 = ONE(13)×GOD(86)",
               "Ho en ap arches (That which was from the beginning, 1 Jn 1:1) = 1118",
               "Unity Holograph total: the Godhead"],
        1369: ["Spirit of God on waters — Gen 1:2 segment", "37²"],
        2701: ["Genesis 1:1 total", "T(73) = H(4)×S(4) = 37×73"],
        3627: ["John 1:1 (with iota subscript)", "39×93"],
        6328: ["Gen 1:1 + John 1:1", "T(112)"],
    }
    return table.get(n, [])
