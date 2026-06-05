"""
Bible Allusion MCP Server
=========================
Scores intertextual allusions using Matthew Swale's three-instinct method
(Terms, Themes, Thesis). Supports single verses and multi-verse passages,
the Isaiah-Bible Correlation, and Bible Wheel spoke analysis.

Tools:
  fetch_verse            — retrieve single verse text + Strong's data
  fetch_passage          — retrieve a verse range (e.g. Isa 40:1-8)
  score_terms            — algorithmic Terms score for two single verses
  score_allusion         — full Swale analysis for two single verses
  score_passage_allusion — full Swale analysis for two passages (verse ranges)
  isaiah_correlation     — analyze Isaiah ch N ↔ Bible book N connection
  spoke_companions       — return the spoke and companion books for any book
  save_allusion          — persist a scored allusion to biblewheel_research
  recall_allusions       — search previously saved allusions
"""

import json
import re
from typing import Any

from mcp.server import Server
from mcp.server.stdio import stdio_server
from mcp.types import Tool, TextContent

from bible_client import (
    fetch_verse as api_fetch_verse,
    fetch_passage as api_fetch_passage,
    fetch_strongs,
    search_by_strongs,
    extract_strongs_codes,
    parse_reference,
)
from terms_scorer import score_terms as compute_terms_score
from shared.db import execute, query
from wheel import (
    get_spoke_for_book,
    get_spoke,
    isaiah_chapter_to_book,
    ISAIAH_CORRELATION_NOTE,
)

app = Server("bible-allusion")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _plain_text(verse_data: dict) -> str:
    words = verse_data.get("words", [])
    parts = []
    for w in words:
        text = (w.get("text_original") or w.get("text") or w.get("token_text") or "").strip()
        if text:
            parts.append(text)
    return " ".join(parts) if parts else "(text unavailable)"


def _kjv_text(verse_data: dict) -> str:
    words = verse_data.get("words", [])
    parts = []
    for w in words:
        text = (w.get("gloss") or w.get("kjv") or "").strip()
        if text:
            parts.append(text)
    return " ".join(parts) if parts else ""


def _word_table(verse_data: dict) -> list[dict]:
    out = []
    for w in verse_data.get("words", []):
        entry: dict[str, Any] = {}
        orig = (w.get("text_original") or w.get("text") or w.get("token_text") or "").strip()
        if orig:
            entry["text"] = orig
        strongs = (w.get("strongs") or w.get("strongs_primary") or "").strip()
        if strongs:
            entry["strongs"] = strongs
        gloss = (w.get("gloss") or w.get("kjv") or "").strip()
        if gloss:
            entry["gloss"] = gloss
        lemma = (w.get("lemma") or "").strip()
        if lemma:
            entry["lemma"] = lemma
        if entry:
            out.append(entry)
    return out


def _get_rarity(codes: set[str]) -> dict[str, int]:
    rarity: dict[str, int] = {}
    for code in codes:
        results = search_by_strongs(code)
        rarity[code] = len(results)
    return rarity


def _code_language(code: str) -> str:
    return "greek" if code.upper().startswith("G") else "hebrew"


def _is_cross_language(source_codes: list[str], target_codes: list[str]) -> bool:
    src_lang = {_code_language(c) for c in source_codes if c}
    tgt_lang = {_code_language(c) for c in target_codes if c}
    return bool(src_lang and tgt_lang and src_lang != tgt_lang)


def _confidence_label(total: int) -> str:
    if total >= 7:
        return "high"
    if total >= 4:
        return "moderate"
    if total >= 2:
        return "low"
    return "unlikely"


def _enrich_shared_terms(shared: list[dict]) -> None:
    for item in shared:
        info = fetch_strongs(item["code"])
        if info:
            item["lemma"] = info.get("lemma", "")
            item["meaning"] = info.get("description", "")[:120]


def _cross_language_note(cross: bool) -> str | None:
    if not cross:
        return None
    return (
        "These verses span Hebrew OT and Greek NT. Strong's codes don't overlap "
        "across languages, so the computed Terms score may be 0 even when a genuine "
        "verbal allusion exists. For OT→NT allusions, evaluate the Terms instinct by "
        "inspecting whether the NT text uses Greek words matching the LXX translation "
        "of the OT verse. The word glosses and lemmas above will help."
    )


SWALE_RUBRIC = {
    "terms": (
        "ALREADY COMPUTED above. "
        "+3 rare shared term (≤10 verses), "
        "+2 uncommon (11–100), "
        "+1 common but distinctive (101–500), "
        "0 ubiquitous function word. Cap: 9."
    ),
    "themes": (
        "Score 0–9. Assess shared motifs, narrative parallels, symbolic/imagistic "
        "overlap, theological patterns (creation, exodus, covenant, judgment, "
        "restoration, kingship, wisdom, divine testing, deliverance). "
        "+3 strong multi-layered thematic parallel, "
        "+2 clear shared motif, "
        "+1 general conceptual similarity. Cap: 9."
    ),
    "thesis": (
        "Score 0–9. Does the allusion serve the argument/purpose of the later text? "
        "Would it be meaningful to the original audience? Does invoking the source "
        "text make the target more coherent or theologically richer? "
        "+3 essential to the passage's argument, "
        "+2 strengthens the argument, "
        "+1 compatible but not necessary. Cap: 9."
    ),
    "confidence": (
        "Sum all three scores (0–27). "
        "high: total ≥ 7 AND all three instincts have at least some evidence; "
        "moderate: total 4–6 OR strong in two instincts; "
        "low: total 2–3 OR strong in only one instinct; "
        "unlikely: total < 2."
    ),
}

CLAUDE_INSTRUCTIONS = (
    "Using the verse/passage data and shared terms above, now score Themes and Thesis "
    "according to the rubric. Then compute confidence. Write a narrative explanation "
    "that names the specific shared terms, motifs, and theological purpose. "
    "Return your final result in the format:\n"
    "{\n"
    "  \"terms_score\": <computed above>,\n"
    "  \"themes_score\": <your score 0-9>,\n"
    "  \"thesis_score\": <your score 0-9>,\n"
    "  \"total\": <sum>,\n"
    "  \"confidence\": \"high|moderate|low|unlikely\",\n"
    "  \"explanation\": \"<narrative>\"\n"
    "}"
)


# ---------------------------------------------------------------------------
# Tool definitions
# ---------------------------------------------------------------------------

TOOLS = [
    Tool(
        name="fetch_verse",
        description=(
            "Fetch a Bible verse from the local database. Returns the verse text "
            "in the original language (Hebrew/Greek), word-by-word Strong's numbers, "
            "glosses, and lemmas. Useful before calling score_allusion to inspect "
            "the raw linguistic data.\n\n"
            "Reference format: 'Book chapter:verse', e.g. 'Gen 1:1', 'Isa 40:3', "
            "'John 1:1', '1 Kings 3:5'."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "reference": {
                    "type": "string",
                    "description": "Verse reference, e.g. 'Isa 40:3' or 'John 1:23'",
                }
            },
            "required": ["reference"],
        },
    ),
    Tool(
        name="fetch_passage",
        description=(
            "Fetch a multi-verse passage from the local database. Returns each verse's "
            "original-language text, word-by-word Strong's data, and a flat list of all "
            "Strong's codes across the passage.\n\n"
            "Reference format: 'Book chapter:start-end', e.g. 'Isa 40:1-8', 'Gen 1:1-5', "
            "'Rom 8:1-4'. A single-verse reference like 'Isa 40:3' is also accepted."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "reference": {
                    "type": "string",
                    "description": "Passage reference, e.g. 'Isa 40:1-8' or 'John 1:1-14'",
                }
            },
            "required": ["reference"],
        },
    ),
    Tool(
        name="score_terms",
        description=(
            "Score the Terms instinct (Swale's first instinct: linguistic/verbal evidence) "
            "for a proposed allusion between two verses.\n\n"
            "This tool is purely algorithmic: it fetches both verses, extracts their "
            "Strong's numbers, queries the database for how common each code is across "
            "the whole Bible, then assigns points:\n"
            "  +3  rare term shared (≤10 verses in the Bible use it)\n"
            "  +2  uncommon shared term (11–100 verses)\n"
            "  +1  common term in distinctive use (101–500 verses)\n"
            "   0  ubiquitous function word (>500 verses — not counted)\n\n"
            "Returns the numeric score (0–9), the list of shared terms with their "
            "rarity tier, and an evidence summary.\n\n"
            "Reference format: 'Book chapter:verse', e.g. 'Isa 40:3'."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "source": {
                    "type": "string",
                    "description": "The earlier (source) verse, e.g. 'Isa 40:3'",
                },
                "target": {
                    "type": "string",
                    "description": "The later (target/alluding) verse, e.g. 'John 1:23'",
                },
            },
            "required": ["source", "target"],
        },
    ),
    Tool(
        name="score_allusion",
        description=(
            "Perform a full Swale three-instinct allusion analysis between two Bible verses.\n\n"
            "Returns:\n"
            "1. The computed Terms score (algorithmic, from Strong's rarity analysis)\n"
            "2. Both verses' full text and word data — so the caller (Claude) can reason "
            "   about Themes and Thesis\n"
            "3. The Swale scoring rubric for Themes and Thesis, ready for Claude to apply\n"
            "4. A structured template the caller should fill in with scores + narrative\n\n"
            "After receiving this tool's output, YOU (Claude) must:\n"
            "  - Score Themes (0–9): shared motifs, narrative parallels, theological patterns\n"
            "    (+3 multi-layered thematic parallel, +2 clear motif, +1 general similarity)\n"
            "  - Score Thesis (0–9): does the allusion serve the argument of the later text?\n"
            "    (+3 essential to the argument, +2 strengthens it, +1 compatible but peripheral)\n"
            "  - Compute confidence: high (total ≥7), moderate (4–6), low (2–3), unlikely (<2)\n"
            "  - Write a narrative explanation\n\n"
            "Reference format: 'Book chapter:verse'."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "source": {
                    "type": "string",
                    "description": "The earlier (source/alluded-to) verse",
                },
                "target": {
                    "type": "string",
                    "description": "The later (alluding) verse",
                },
                "context_note": {
                    "type": "string",
                    "description": (
                        "Optional: any interpretive context the user has provided "
                        "(e.g. 'this is about the new exodus motif', 'compare the "
                        "use of hesed in both passages'). Helps guide the Themes and "
                        "Thesis analysis."
                    ),
                },
            },
            "required": ["source", "target"],
        },
    ),
    Tool(
        name="score_passage_allusion",
        description=(
            "Perform a full Swale three-instinct allusion analysis between two Bible passages "
            "(verse ranges). Treats each passage as a unit: all Strong's codes across all "
            "verses are pooled for the Terms score.\n\n"
            "Use this for the Isaiah-Bible Correlation (e.g. 'Isa 40:1-8' → 'Matt 3:1-4'), "
            "for spoke connections spanning multiple verses, or any passage-level comparison.\n\n"
            "Reference format: 'Book chapter:start-end', e.g. 'Isa 40:1-8', 'Gen 1:1-5'.\n"
            "Single-verse references are also accepted.\n\n"
            "After receiving this tool's output, YOU (Claude) must score Themes and Thesis "
            "and write the narrative explanation (same rubric as score_allusion)."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "source": {
                    "type": "string",
                    "description": "The earlier (source) passage, e.g. 'Isa 40:1-8'",
                },
                "target": {
                    "type": "string",
                    "description": "The later (alluding) passage, e.g. 'Matt 3:1-4'",
                },
                "context_note": {
                    "type": "string",
                    "description": "Optional interpretive context to guide Themes/Thesis analysis",
                },
            },
            "required": ["source", "target"],
        },
    ),
    Tool(
        name="isaiah_correlation",
        description=(
            "Analyze the Isaiah-Bible Correlation for a given Isaiah chapter.\n\n"
            "Isaiah's 66 chapters mirror the 66 books of the Bible (mise en abyme): "
            "chapters 1–39 correspond to the 39 OT books; chapters 40–66 correspond "
            "to the 27 NT books.\n\n"
            "Given an Isaiah chapter number, this tool returns:\n"
            "  - The corresponding Bible book\n"
            "  - The spoke and Hebrew letter they share on the Bible Wheel\n"
            "  - The passage reference for both the Isaiah chapter opening (vv. 1-3) "
            "    and the corresponding book opening (1:1-3)\n"
            "  - A ready-to-run score_passage_allusion call\n\n"
            "Optionally provide a specific Isaiah passage (e.g. 'Isa 40:3') and a "
            "target passage to score directly."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "isaiah_chapter": {
                    "type": "integer",
                    "description": "Isaiah chapter number (1–66)",
                },
                "source_passage": {
                    "type": "string",
                    "description": "Optional: specific Isaiah passage to analyze, e.g. 'Isa 40:3'",
                },
                "target_passage": {
                    "type": "string",
                    "description": "Optional: specific target passage, e.g. 'Matt 3:3'. "
                                   "If omitted, defaults to the opening of the corresponding book.",
                },
            },
            "required": ["isaiah_chapter"],
        },
    ),
    Tool(
        name="spoke_companions",
        description=(
            "Return the Bible Wheel spoke data for a given book: its spoke number, "
            "Hebrew letter and meaning, and companion books on the same spoke.\n\n"
            "The Bible Wheel places all 66 books on 22 spokes (one per Hebrew letter). "
            "Books on the same spoke share thematic, linguistic, and structural connections "
            "(KeyLinks). This tool is the starting point for spoke-based allusion analysis.\n\n"
            "Accepts any book name, e.g. 'Isaiah', 'Romans', 'Genesis', '1 Kings'."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "book": {
                    "type": "string",
                    "description": "Book name, e.g. 'Isaiah' or 'Romans'",
                },
            },
            "required": ["book"],
        },
    ),
    Tool(
        name="save_allusion",
        description=(
            "Persist a scored allusion to the biblewheel_research database so it "
            "survives across sessions. Call this after completing a score_allusion or "
            "score_passage_allusion analysis to record the finding.\n\n"
            "Good allusions to save:\n"
            "  - Isaiah-Bible Correlation connections with moderate or high confidence\n"
            "  - Spoke KeyLinks with identified shared vocabulary\n"
            "  - Cross-testament allusions verified through LXX vocabulary\n"
            "  - Any connection that advances the Bible Wheel thesis"
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "source_ref":    {"type": "string",  "description": "Source passage reference (start verse, e.g. 'Isa 34:5')"},
                "target_ref":    {"type": "string",  "description": "Target passage reference (start verse, e.g. 'Nah 3:3')"},
                "source_range":  {"type": "integer", "description": "Additional verses after source start verse (0 or omit = single verse; 3 = 4 verses total)"},
                "target_range":  {"type": "integer", "description": "Additional verses after target start verse (0 or omit = single verse)"},
                "terms_score":   {"type": "integer", "description": "Terms score (0–9)"},
                "themes_score":  {"type": "integer", "description": "Themes score (0–9)"},
                "thesis_score":  {"type": "integer", "description": "Thesis score (0–9)"},
                "confidence":    {"type": "string",  "description": "high | moderate | low | unlikely"},
                "explanation":   {"type": "string",  "description": "Narrative explanation of the allusion"},
                "context":       {"type": "string",  "description": "e.g. 'Isaiah-Bible Correlation ch 40', 'Spoke 1 Aleph'"},
                "tags":          {"type": "string",  "description": "Comma-separated tags, e.g. 'spoke1,isaiah-correlation,new-exodus'"},
            },
            "required": ["source_ref", "target_ref", "confidence", "explanation"],
        },
    ),
    Tool(
        name="recall_allusions",
        description=(
            "Search previously saved allusions from biblewheel_research. "
            "Filter by source/target reference, confidence level, tag, or keyword.\n\n"
            "At least one filter parameter must be provided."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "source_ref":  {"type": "string",  "description": "Filter by source passage reference"},
                "target_ref":  {"type": "string",  "description": "Filter by target passage reference"},
                "confidence":  {"type": "string",  "description": "Filter by confidence: high | moderate | low | unlikely"},
                "tag":         {"type": "string",  "description": "Filter by tag"},
                "keyword":     {"type": "string",  "description": "Full-text search in explanation"},
                "limit":       {"type": "integer", "description": "Max results to return (default 20)"},
            },
        },
    ),
]


# ---------------------------------------------------------------------------
# Tool handlers
# ---------------------------------------------------------------------------

@app.list_tools()
async def list_tools() -> list[Tool]:
    return TOOLS


@app.call_tool()
async def call_tool(name: str, arguments: dict) -> list[TextContent]:
    handlers = {
        "fetch_verse":            _handle_fetch_verse,
        "fetch_passage":          _handle_fetch_passage,
        "score_terms":            _handle_score_terms,
        "score_allusion":         _handle_score_allusion,
        "score_passage_allusion": _handle_score_passage_allusion,
        "isaiah_correlation":     _handle_isaiah_correlation,
        "spoke_companions":       _handle_spoke_companions,
        "save_allusion":          _handle_save_allusion,
        "recall_allusions":       _handle_recall_allusions,
    }
    if name not in handlers:
        raise ValueError(f"Unknown tool: {name}")
    return await handlers[name](arguments)


async def _handle_fetch_verse(args: dict) -> list[TextContent]:
    ref = args["reference"]
    try:
        data = api_fetch_verse(ref)
    except (ValueError, RuntimeError) as e:
        return [TextContent(type="text", text=f"Error: {e}")]

    vrow = data.get("verse", {})
    words = _word_table(data)
    orig = _plain_text(data)

    result = {
        "reference": ref,
        "book": vrow.get("book_name", ""),
        "chapter": vrow.get("chapter"),
        "verse": vrow.get("verse"),
        "testament": vrow.get("testament", ""),
        "language": vrow.get("language", ""),
        "text_original": orig,
        "words": words,
    }
    return [TextContent(type="text", text=json.dumps(result, ensure_ascii=False, indent=2))]


async def _handle_fetch_passage(args: dict) -> list[TextContent]:
    ref = args["reference"]
    try:
        data = api_fetch_passage(ref)
    except (ValueError, RuntimeError) as e:
        return [TextContent(type="text", text=f"Error: {e}")]
    return [TextContent(type="text", text=json.dumps(data, ensure_ascii=False, indent=2))]


async def _handle_score_terms(args: dict) -> list[TextContent]:
    source_ref = args["source"]
    target_ref = args["target"]

    try:
        source_data = api_fetch_verse(source_ref)
        target_data = api_fetch_verse(target_ref)
    except (ValueError, RuntimeError) as e:
        return [TextContent(type="text", text=f"Error fetching verses: {e}")]

    source_codes = extract_strongs_codes(source_data)
    target_codes = extract_strongs_codes(target_data)

    cross_lang = _is_cross_language(source_codes, target_codes)
    shared = set(source_codes) & set(target_codes)
    rarity = _get_rarity(shared)

    result = compute_terms_score(source_codes, target_codes, rarity)
    _enrich_shared_terms(result["shared"])

    output: dict[str, Any] = {
        "source": source_ref,
        "target": target_ref,
        "terms_score": result["score"],
        "shared_terms": result["shared"],
        "evidence_summary": result["evidence_summary"],
        "rubric_note": (
            "Terms score is 0–9. Swale: ≥3 → strong linguistic evidence; "
            "1–2 → weak; 0 → no significant lexical connection."
        ),
    }
    if cross_lang:
        output["cross_language_note"] = _cross_language_note(True)
    return [TextContent(type="text", text=json.dumps(output, ensure_ascii=False, indent=2))]


async def _handle_score_allusion(args: dict) -> list[TextContent]:
    source_ref = args["source"]
    target_ref = args["target"]
    context_note = args.get("context_note", "")

    try:
        source_data = api_fetch_verse(source_ref)
        target_data = api_fetch_verse(target_ref)
    except (ValueError, RuntimeError) as e:
        return [TextContent(type="text", text=f"Error fetching verses: {e}")]

    source_codes = extract_strongs_codes(source_data)
    target_codes = extract_strongs_codes(target_data)
    cross_lang = _is_cross_language(source_codes, target_codes)
    shared = set(source_codes) & set(target_codes)
    rarity = _get_rarity(shared)
    terms_result = compute_terms_score(source_codes, target_codes, rarity)
    _enrich_shared_terms(terms_result["shared"])

    output = {
        "analysis_framework": "Swale — Three Instincts for Identifying Allusions",
        "source": {
            "reference": source_ref,
            "language": source_data.get("verse", {}).get("language", ""),
            "text_original": _plain_text(source_data),
            "words": _word_table(source_data),
        },
        "target": {
            "reference": target_ref,
            "language": target_data.get("verse", {}).get("language", ""),
            "text_original": _plain_text(target_data),
            "words": _word_table(target_data),
        },
        "context_note": context_note,
        "computed": {
            "terms_score": terms_result["score"],
            "shared_terms": terms_result["shared"],
            "terms_evidence_summary": terms_result["evidence_summary"],
        },
        "rubric": SWALE_RUBRIC,
        "cross_language_note": _cross_language_note(cross_lang),
        "instructions_for_claude": CLAUDE_INSTRUCTIONS,
    }
    return [TextContent(type="text", text=json.dumps(output, ensure_ascii=False, indent=2))]


async def _handle_score_passage_allusion(args: dict) -> list[TextContent]:
    source_ref = args["source"]
    target_ref = args["target"]
    context_note = args.get("context_note", "")

    try:
        source_data = api_fetch_passage(source_ref)
        target_data = api_fetch_passage(target_ref)
    except (ValueError, RuntimeError) as e:
        return [TextContent(type="text", text=f"Error fetching passages: {e}")]

    source_codes = source_data["all_strongs"]
    target_codes = target_data["all_strongs"]
    cross_lang = _is_cross_language(source_codes, target_codes)
    shared = set(source_codes) & set(target_codes)
    rarity = _get_rarity(shared)
    terms_result = compute_terms_score(source_codes, target_codes, rarity)
    _enrich_shared_terms(terms_result["shared"])

    # Build passage-level text summaries
    def _passage_text(pdata: dict) -> str:
        lines = []
        for v in pdata["verses"]:
            lines.append(f"v.{v['verse']}: {v['text_original']}")
        return "\n".join(lines)

    output = {
        "analysis_framework": "Swale — Three Instincts (passage-level)",
        "source": {
            "reference": source_data["reference"],
            "verse_count": source_data["verse_count"],
            "text": _passage_text(source_data),
            "verses": source_data["verses"],
        },
        "target": {
            "reference": target_data["reference"],
            "verse_count": target_data["verse_count"],
            "text": _passage_text(target_data),
            "verses": target_data["verses"],
        },
        "context_note": context_note,
        "computed": {
            "terms_score": terms_result["score"],
            "shared_terms": terms_result["shared"],
            "terms_evidence_summary": terms_result["evidence_summary"],
            "note": "Terms score pools all Strong's codes across all verses in each passage.",
        },
        "rubric": SWALE_RUBRIC,
        "cross_language_note": _cross_language_note(cross_lang),
        "instructions_for_claude": CLAUDE_INSTRUCTIONS,
    }
    return [TextContent(type="text", text=json.dumps(output, ensure_ascii=False, indent=2))]


async def _handle_isaiah_correlation(args: dict) -> list[TextContent]:
    chapter = int(args["isaiah_chapter"])
    source_passage = args.get("source_passage", "")
    target_passage = args.get("target_passage", "")

    try:
        corr = isaiah_chapter_to_book(chapter)
    except ValueError as e:
        return [TextContent(type="text", text=f"Error: {e}")]

    book = corr["corresponding_book"]

    # Default passages: Isaiah chapter opening and corresponding book opening
    if not source_passage:
        source_passage = f"Isa {chapter}:1-3"
    if not target_passage:
        target_passage = f"{book} 1:1-3"

    # Run passage allusion on the defaults / specified passages
    try:
        source_data = api_fetch_passage(source_passage)
        target_data = api_fetch_passage(target_passage)
    except (ValueError, RuntimeError) as e:
        # Still return correlation data even if passage fetch fails
        source_data = None
        target_data = None

    result: dict[str, Any] = {
        "isaiah_chapter": chapter,
        "corresponding_book": book,
        "book_number": corr["book_number"],
        "spoke": corr["spoke"],
        "letter": corr["letter"],
        "letter_meaning": corr["letter_meaning"],
        "testament": corr["testament"],
        "correlation_note": ISAIAH_CORRELATION_NOTE,
        "passages_analyzed": {
            "source": source_passage,
            "target": target_passage,
        },
    }

    if source_data and target_data:
        source_codes = source_data["all_strongs"]
        target_codes = target_data["all_strongs"]
        cross_lang = _is_cross_language(source_codes, target_codes)
        shared = set(source_codes) & set(target_codes)
        rarity = _get_rarity(shared)
        terms_result = compute_terms_score(source_codes, target_codes, rarity)
        _enrich_shared_terms(terms_result["shared"])

        def _passage_text(pdata: dict) -> str:
            lines = []
            for v in pdata["verses"]:
                lines.append(f"v.{v['verse']}: {v['text_original']}")
            return "\n".join(lines)

        result["source_text"] = _passage_text(source_data)
        result["target_text"] = _passage_text(target_data)
        result["source_verses"] = source_data["verses"]
        result["target_verses"] = target_data["verses"]
        result["computed"] = {
            "terms_score": terms_result["score"],
            "shared_terms": terms_result["shared"],
            "terms_evidence_summary": terms_result["evidence_summary"],
        }
        result["cross_language_note"] = _cross_language_note(cross_lang)
    else:
        result["note"] = f"Could not fetch passages — try: score_passage_allusion('{source_passage}', '{target_passage}')"

    result["rubric"] = SWALE_RUBRIC
    result["instructions_for_claude"] = CLAUDE_INSTRUCTIONS
    return [TextContent(type="text", text=json.dumps(result, ensure_ascii=False, indent=2))]


async def _handle_spoke_companions(args: dict) -> list[TextContent]:
    book_name = args["book"]
    spoke_data = get_spoke_for_book(book_name)
    if not spoke_data:
        return [TextContent(type="text", text=json.dumps({
            "error": f"Book '{book_name}' not found on the Bible Wheel. "
                     "Check the spelling — use full name e.g. 'Song of Solomon', '1 Corinthians'."
        }))]

    c1 = spoke_data["cycle1_book"]
    c2 = spoke_data["cycle2_book"]
    c3 = spoke_data["cycle3_book"]
    spoke_num = spoke_data["spoke"]

    output = {
        "book": book_name,
        "spoke": spoke_num,
        "letter": spoke_data["letter"],
        "symbol": spoke_data["symbol"],
        "letter_meaning": spoke_data["meaning"],
        "companion_books": {
            f"cycle1_book{spoke_num}": c1,
            f"cycle2_book{spoke_num + 22}": c2,
            f"cycle3_book{spoke_num + 44}": c3,
        },
        "book_pairs_to_analyze": [
            f"{c1} ↔ {c2}",
            f"{c1} ↔ {c3}",
            f"{c2} ↔ {c3}",
        ],
        "suggested_allusion_calls": [
            f"score_passage_allusion('{c1} 1:1-5', '{c2} 1:1-5')",
            f"score_passage_allusion('{c1} 1:1-5', '{c3} 1:1-5')",
            f"score_passage_allusion('{c2} 1:1-5', '{c3} 1:1-5')",
        ],
        "note": (
            f"Books on Spoke {spoke_num} ({spoke_data['letter']}) share the symbolic meaning "
            f"'{spoke_data['meaning']}'. Look for KeyWords and KeyLinks — shared vocabulary "
            f"and motifs that integrate the letter's meaning with the books' content."
        ),
    }
    return [TextContent(type="text", text=json.dumps(output, ensure_ascii=False, indent=2))]


async def _handle_save_allusion(args: dict) -> list[TextContent]:
    terms  = args.get("terms_score")
    themes = args.get("themes_score")
    thesis = args.get("thesis_score")
    total  = None
    if terms is not None and themes is not None and thesis is not None:
        total = int(terms) + int(themes) + int(thesis)

    src_range = args.get("source_range")
    tgt_range = args.get("target_range")

    row_id = execute(
        """
        INSERT INTO allusions
          (source_ref, target_ref, source_range, target_range, context,
           terms_score, themes_score, thesis_score, total_score,
           confidence, explanation, tags)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        (
            args["source_ref"],
            args["target_ref"],
            src_range,
            tgt_range,
            args.get("context"),
            terms,
            themes,
            thesis,
            total,
            args["confidence"],
            args["explanation"],
            args.get("tags"),
        ),
        database="biblewheel_research",
    )
    return [TextContent(type="text", text=json.dumps({
        "status": "saved",
        "id": row_id,
        "source_ref": args["source_ref"],
        "source_range": src_range,
        "target_ref": args["target_ref"],
        "target_range": tgt_range,
        "confidence": args["confidence"],
        "total_score": total,
    }))]


async def _handle_recall_allusions(args: dict) -> list[TextContent]:
    limit = min(int(args.get("limit", 20)), 100)
    conditions = []
    params = []

    if args.get("source_ref"):
        conditions.append("source_ref LIKE %s")
        params.append(f"%{args['source_ref']}%")
    if args.get("target_ref"):
        conditions.append("target_ref LIKE %s")
        params.append(f"%{args['target_ref']}%")
    if args.get("confidence"):
        conditions.append("confidence = %s")
        params.append(args["confidence"])
    if args.get("tag"):
        conditions.append("FIND_IN_SET(%s, tags) > 0")
        params.append(args["tag"])
    if args.get("keyword"):
        conditions.append("MATCH(explanation) AGAINST(%s IN BOOLEAN MODE)")
        params.append(args["keyword"])

    if not conditions:
        return [TextContent(type="text", text='{"error": "Provide at least one filter: source_ref, target_ref, confidence, tag, or keyword"}')]

    where = " AND ".join(conditions)
    rows = query(
        f"""
        SELECT id, source_ref, source_range, target_ref, target_range, context,
               terms_score, themes_score, thesis_score, total_score,
               confidence, explanation, tags, created_at
        FROM allusions
        WHERE {where}
        ORDER BY created_at DESC
        LIMIT %s
        """,
        tuple(params) + (limit,),
        database="biblewheel_research",
    )
    return [TextContent(type="text", text=json.dumps({
        "count": len(rows),
        "allusions": rows,
    }, ensure_ascii=False, indent=2, default=str))]


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

async def main():
    async with stdio_server() as (read_stream, write_stream):
        await app.run(read_stream, write_stream, app.create_initialization_options())


if __name__ == "__main__":
    import asyncio
    asyncio.run(main())
