"""
Terms scorer — Swale's first instinct (linguistic/verbal).

Computes a 0–9 Terms score from Strong's number overlap between two verses,
weighted by lexical rarity (how many verses in the whole Bible share that word).

Scoring rubric (per Swale):
  +3  rare or unique shared term       (≤10 verses share it)
  +2  uncommon shared term             (11–100 verses)
  +1  common term used distinctively   (101–500 verses, but still flagged)
   0  ubiquitous function word         (>500 verses — not counted)

Total is capped at 9 (the max a single pair could plausibly earn).

Root normalization:
  Codes are compared at root level by stripping the trailing variant-letter
  suffix used by some tagging systems (e.g. H3068G → H3068, H3559H → H3559).
  The original code (with suffix) is used for rarity lookup so the API query
  still finds the correct verse count.

  verse_count == 0 is treated as "lookup failed / unknown" and the code is
  skipped rather than scored as rare — this prevents false positives when the
  API does not recognise a normalized code.
"""

from __future__ import annotations
import re

# Rarity thresholds (number of verses the Strong's code appears in)
RARE_THRESHOLD      = 10
UNCOMMON_THRESHOLD  = 100
COMMON_THRESHOLD    = 500   # above this → skip (function words, 'the', 'and', etc.)

# Points per tier
POINTS_RARE     = 3
POINTS_UNCOMMON = 2
POINTS_COMMON   = 1


def _root_code(code: str) -> str:
    """
    Strip the trailing variant-letter suffix to get the root Strong's number.
    H3068G → H3068,  H3559H → H3559,  G3004G → G3004,  H4467 → H4467 (unchanged).
    Only strips when the last character is a letter and the body is H/G + digits.
    """
    if len(code) >= 3 and code[-1].isalpha() and code[1:-1].isdigit():
        return code[:-1]
    return code


def _tier_label(count: int) -> str:
    if count <= RARE_THRESHOLD:
        return "rare"
    if count <= UNCOMMON_THRESHOLD:
        return "uncommon"
    if count <= COMMON_THRESHOLD:
        return "common"
    return "ubiquitous"


def score_terms(
    source_codes: list[str],
    target_codes: list[str],
    rarity_lookup: dict[str, int],  # strongs_code → verse_count across the whole Bible
) -> dict:
    """
    Given the Strong's codes of two verses and a rarity table, compute the
    Terms score component of Swale's rubric.

    Intersection is computed at root level (variant-letter suffix stripped) so
    that different inflected forms of the same root match.  The original code
    is used for the rarity lookup.

    verse_count == 0 means the rarity lookup found nothing (lookup failure or
    truly unattested) — these codes are skipped rather than scored as rare.

    Returns:
        {
          "score": int,           # 0–9
          "shared": [             # one entry per scoring unique root
            {
              "code": str,        # root code (suffix stripped)
              "verse_count": int,
              "tier": str,
              "points": int,
            }
          ],
          "evidence_summary": str,
        }
    """
    # Build root → original-code maps (last seen wins for duplicates)
    source_map: dict[str, str] = {_root_code(c): c for c in source_codes}
    target_map: dict[str, str] = {_root_code(c): c for c in target_codes}

    shared_roots = set(source_map.keys()) & set(target_map.keys())

    shared_items = []
    total_score = 0

    for root in shared_roots:
        orig = source_map[root]  # use source's original code for rarity lookup
        count = rarity_lookup.get(orig, rarity_lookup.get(root, 0))

        # verse_count == 0 → rarity lookup failed; skip rather than score as rare
        if count == 0:
            continue

        tier = _tier_label(count)
        if tier == "ubiquitous":
            continue  # function words — not diagnostic

        pts = POINTS_RARE if tier == "rare" else (POINTS_UNCOMMON if tier == "uncommon" else POINTS_COMMON)
        total_score += pts
        shared_items.append({
            "code": root,
            "verse_count": count,
            "tier": tier,
            "points": pts,
        })

    total_score = min(total_score, 9)

    # Sort by most diagnostic first
    shared_items.sort(key=lambda x: x["points"], reverse=True)

    # Build a human-readable summary
    if not shared_items:
        summary = "No significant shared lexical terms found."
    else:
        rare_terms    = [x["code"] for x in shared_items if x["tier"] == "rare"]
        uncommon_terms = [x["code"] for x in shared_items if x["tier"] == "uncommon"]
        common_terms  = [x["code"] for x in shared_items if x["tier"] == "common"]
        parts = []
        if rare_terms:
            parts.append(f"{len(rare_terms)} rare term(s): {', '.join(rare_terms)}")
        if uncommon_terms:
            parts.append(f"{len(uncommon_terms)} uncommon term(s): {', '.join(uncommon_terms)}")
        if common_terms:
            parts.append(f"{len(common_terms)} common term(s): {', '.join(common_terms)}")
        summary = "Shared lexical terms — " + "; ".join(parts) + "."

    return {
        "score": total_score,
        "shared": shared_items,
        "evidence_summary": summary,
    }


def build_rarity_table(
    code_sets: list[list[str]],
) -> dict[str, int]:
    """
    Build a rarity table from a list of per-verse code lists.
    Each entry in code_sets is the list of Strong's codes for one verse.
    Returns {code: verse_count}.

    This is used when we query the search_verses API to estimate rarity.
    For the MCP we build this lazily from API search results.
    """
    table: dict[str, int] = {}
    for verse_codes in code_sets:
        for code in set(verse_codes):  # set: count each code once per verse
            table[code] = table.get(code, 0) + 1
    return table
