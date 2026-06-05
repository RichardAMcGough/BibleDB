"""
BibleWheel Gematria MCP Server
==============================
Gematria research tools over the local stepbible3 database, enriched with the
curated Gematria Reference (GR) knowledge base and persistent research memory
in biblewheel_research.

Computation tools (stepbible3):
  verse_gematria    — all gematria variants for a verse + word-by-word breakdown
  passage_gematria  — gematria for a verse range (e.g. Gen 1:1-3)
  united_analysis   — two passages: individual totals + combined sum + figurate analysis
  search_by_value   — find verses whose gematria total equals a value
  analyze_number    — factors + figurate forms + named/GR associations + saved insights
  holograph_search  — embedded figurate patterns within a passage

Curated reference (GR knowledge base, from Richard McGough's GR_<n>.php articles):
  gr_reference      — the published article for a number (epithet, verses, identities, words)
  gr_words          — curated transliterated words whose value equals a number
  gr_search         — full-text search of GR titles and words

Research memory (biblewheel_research):
  save_insight      — persist a research observation
  recall_insights   — search previously saved insights
"""

import json
import os
import sys
import asyncio

# Allow imports from ../shared and ../ (bible_client)
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from mcp.server import Server
from mcp.server.stdio import stdio_server
from mcp.types import Tool, TextContent

from shared.db import query, execute
from shared.figurate import analyze, known_notable, figurate_summary
from shared import gr_reference as gr
from bible_client import parse_passage_reference

app = Server("biblewheel-gematria")

METHODS = ("standard", "sofit", "ordinal", "reduced")
_METHOD_COL = {"standard": "standard", "sofit": "standard_sofit",
               "ordinal": "ordinal", "reduced": "reduced"}

# Selects exactly the base-text word at each position so the word breakdown
# reconstructs the stored gematria_verse total. STEP tags each word with the
# textforms that contain it; pure-lowercase tags (e.g. 'ko', 'no') are
# variant-only readings and are excluded.
#   OT (Hebrew): the word carries any uppercase letter  (Leningrad base + its variants)
#   NT (Greek):  the word is present in N, K, or O       (the three primary Greek texts)
# Both rules were verified to reproduce 100% of stored verse totals
# (23261/23261 OT verses, 7958/7958 NT verses).
_BASE_TEXT_PREDICATE = (
    "( (b.testament = 'OT' AND BINARY w.source_type <> LOWER(w.source_type)) "
    "  OR "
    "  (b.testament = 'NT' AND ( LOCATE(BINARY 'N', w.source_type) > 0 "
    "                         OR LOCATE(BINARY 'K', w.source_type) > 0 "
    "                         OR LOCATE(BINARY 'O', w.source_type) > 0 )) )"
)


# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

def _json(obj) -> list[TextContent]:
    return [TextContent(type="text", text=json.dumps(obj, ensure_ascii=False, indent=2, default=str))]


def _err(message: str, **extra) -> list[TextContent]:
    return _json({"error": message, **extra})


def _method_col(method: str | None) -> str:
    return _METHOD_COL.get(method or "standard", "standard")


# ---------------------------------------------------------------------------
# Number analysis — merges computation + curated knowledge
# ---------------------------------------------------------------------------

def _number_analysis(n: int, include_gr: bool = True, gr_word_limit: int = 25) -> dict:
    """Full analysis of an integer: figurate forms, factors, named associations
    (hand-curated overlay), and the GR reference article if one exists."""
    result = analyze(n)
    result["figurate_summary"] = figurate_summary(n)
    result["named_associations"] = known_notable(n)
    if include_gr:
        ref = gr.lookup(n)
        if ref:
            result["gr_reference"] = {
                "epithet": ref["epithet"],
                "title": ref["title"],
                "url": ref["url"],
                "key_verses": ref["verses"][:3] if ref["verses"] else [],
                "identities": ref["identities"],
                "cross_references": ref["cross_references"],
                "words": ref["words"][:gr_word_limit],
            }
    return result


# ---------------------------------------------------------------------------
# Passage fetch — batched (2 queries regardless of passage length)
# ---------------------------------------------------------------------------

def _fetch_passage(osis: str, chapter: int, start_verse: int, end_verse: int) -> list[dict]:
    """Return ordered verse rows with all gematria methods + word breakdowns.
    Each row: {verse, standard, sofit, ordinal, reduced, words:[...]}."""
    verse_rows = query(
        """
        SELECT v.id, v.verse,
               gv.standard, gv.standard_sofit, gv.ordinal, gv.reduced
        FROM verse v
        JOIN book b ON b.id = v.book_id
        JOIN gematria_verse gv ON gv.verse_id = v.id
        WHERE b.osis_code = ? AND v.chapter = ? AND v.verse BETWEEN ? AND ?
        ORDER BY v.verse
        """,
        (osis, chapter, start_verse, end_verse),
    )
    if not verse_rows:
        return []

    word_rows = query(
        f"""
        SELECT w.verse_id, w.position, w.text_original, w.strongs_primary,
               gw.standard, gw.standard_sofit, gw.ordinal, gw.reduced
        FROM word w
        JOIN verse v ON v.id = w.verse_id
        JOIN book b ON b.id = v.book_id
        JOIN gematria_word gw ON gw.word_id = w.id
        WHERE b.osis_code = ? AND v.chapter = ? AND v.verse BETWEEN ? AND ?
          AND {_BASE_TEXT_PREDICATE}
        ORDER BY w.verse_id, w.position
        """,
        (osis, chapter, start_verse, end_verse),
    )

    words_by_verse: dict[int, list[dict]] = {}
    for r in word_rows:
        words_by_verse.setdefault(r["verse_id"], []).append({
            "position": r["position"],
            "text": r["text_original"] or "",
            "strongs": r["strongs_primary"] or "",
            "standard": r["standard"],
            "sofit": r["standard_sofit"],
            "ordinal": r["ordinal"],
            "reduced": r["reduced"],
        })

    out = []
    for v in verse_rows:
        out.append({
            "verse": v["verse"],
            "standard": v["standard"],
            "sofit": v["standard_sofit"],
            "ordinal": v["ordinal"],
            "reduced": v["reduced"],
            "words": words_by_verse.get(v["id"], []),
        })
    return out


def _passage_total(verse_rows: list[dict], mcol: str) -> int:
    key = {"standard": "standard", "standard_sofit": "sofit",
           "ordinal": "ordinal", "reduced": "reduced"}[mcol]
    return sum(v[key] or 0 for v in verse_rows)


def _ref_label(osis: str, chapter: int, sv: int, ev: int) -> str:
    return f"{osis} {chapter}:{sv}" if sv == ev else f"{osis} {chapter}:{sv}-{ev}"


# ---------------------------------------------------------------------------
# Tool definitions
# ---------------------------------------------------------------------------

_METHOD_PROP = {
    "type": "string",
    "enum": list(METHODS),
    "description": "Gematria method: standard (mispar hechrachi, default), "
                   "sofit (final-letter values), ordinal (mispar siduri), reduced (mispar katan).",
}

TOOLS = [
    Tool(
        name="verse_gematria",
        description=(
            "Gematria for a single Bible verse: standard, sofit, ordinal, and reduced "
            "totals, a word-by-word breakdown in the original Hebrew/Greek, and a full "
            "analysis of the verse total (factors, figurate forms, named associations, and "
            "the curated GR reference article if one exists).\n\n"
            "Reference format: 'Book chapter:verse', e.g. 'Gen 1:1', 'Psa 119:1'."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "reference": {"type": "string", "description": "Verse reference, e.g. 'Gen 1:1'"},
                "method": _METHOD_PROP,
            },
            "required": ["reference"],
        },
    ),
    Tool(
        name="passage_gematria",
        description=(
            "Gematria for a multi-verse passage. Returns a per-verse breakdown plus the "
            "combined passage total, with full analysis of that total.\n\n"
            "Reference format: 'Book chapter:start-end', e.g. 'Gen 1:1-3', 'Isa 40:1-8'. "
            "A single-verse reference is also accepted."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "reference": {"type": "string", "description": "Passage reference, e.g. 'Gen 1:1-3'"},
                "method": _METHOD_PROP,
            },
            "required": ["reference"],
        },
    ),
    Tool(
        name="united_analysis",
        description=(
            "United gematria analysis of two passages — the pattern exemplified by "
            "Genesis 1:1 (2701) + John 1:1 (3627) = 6328 = T(112), where 112 = YHVH Elohim.\n\n"
            "Returns each passage's total with full analysis, plus the sum, difference, and "
            "product, each analyzed for factors, figurate forms, and curated associations. "
            "Use for cross-testament holographic analysis.\n\n"
            "Reference format: 'Book chapter:verse' or 'Book chapter:start-end'."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "passage1": {"type": "string", "description": "First passage, e.g. 'Gen 1:1'"},
                "passage2": {"type": "string", "description": "Second passage, e.g. 'John 1:1'"},
                "method": _METHOD_PROP,
            },
            "required": ["passage1", "passage2"],
        },
    ),
    Tool(
        name="search_by_value",
        description=(
            "Find all Bible verses whose gematria total equals a given value — for locating "
            "numerical parallels and cross-references. Returns up to 50 verses by default.\n\n"
            "Specify method: standard (default), sofit, ordinal, or reduced."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "value": {"type": "integer", "description": "Gematria value to search for"},
                "method": _METHOD_PROP,
                "limit": {"type": "integer", "description": "Max results (default 50, max 200)"},
            },
            "required": ["value"],
        },
    ),
    Tool(
        name="analyze_number",
        description=(
            "Analyze an integer for gematria research:\n"
            "  - Prime factorization and primality\n"
            "  - Figurate forms: triangular T(n), square Sq(n), pentagonal P(n), "
            "centered-hexagonal H(n)=3n(n-1)+1, star S(n)=6n(n-1)+1 "
            "(a Hex/Star pair like 37/73 is the Creation Holograph signature)\n"
            "  - Named associations (curated holograph overlay)\n"
            "  - The full GR reference article if one exists (epithet, verses, identities, words)\n"
            "  - Any saved research insights about the number\n\n"
            "The tool to reach for whenever a value looks interesting."
        ),
        inputSchema={
            "type": "object",
            "properties": {"number": {"type": "integer", "description": "Integer to analyze"}},
            "required": ["number"],
        },
    ),
    Tool(
        name="holograph_search",
        description=(
            "Search for embedded geometric / figurate patterns within a passage — the "
            "'biblical holograph' phenomenon where sub-units of a text mirror the figurate "
            "structure of the whole (e.g. Gen 1:1 = T(73); its words 6-7 = 703 = T(37)).\n\n"
            "Scans every contiguous run of words, reporting those whose running total is a "
            "triangular, square, hex, or star number, or carries a named/GR association. "
            "Results are ranked by significance (star/hex and named hits rank highest). "
            "Also reports verse-level cumulative milestones.\n\n"
            "Reference format: 'Book chapter:verse' or 'Book chapter:start-end'."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "reference": {"type": "string", "description": "Verse or passage, e.g. 'Gen 1:1'"},
                "method": _METHOD_PROP,
                "limit": {"type": "integer", "description": "Max notable segments to return (default 40)"},
            },
            "required": ["reference"],
        },
    ),
    Tool(
        name="gr_reference",
        description=(
            "Fetch the curated Gematria Reference article for a number — Richard McGough's "
            "published commentary from the GR database. Returns the number's epithet "
            "(e.g. 37 = 'The Heart of Wisdom'), key Bible verses, mathematical identities, "
            "cross-referenced numbers, and the list of significant words sharing related "
            "values (with transliterations). Returns nothing if no article exists.\n\n"
            "This is the authoritative source for what a gematria value *means* in this research."
        ),
        inputSchema={
            "type": "object",
            "properties": {"number": {"type": "integer", "description": "The number to look up"}},
            "required": ["number"],
        },
    ),
    Tool(
        name="gr_words",
        description=(
            "List the curated, transliterated Hebrew/Greek words whose gematria value equals "
            "a given number, gathered from across the entire GR reference. Complements "
            "search_by_value (which finds whole verses) by giving named words with their "
            "transliterations and the GR page each was documented on.\n\n"
            "E.g. value 86 → Elohim, Gaon YHVH (Majesty of the LORD), Koachi HaGadol "
            "(My Great Power), Hallelu-Yah, HaTeva (Nature)..."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "value": {"type": "integer", "description": "The gematria value"},
                "limit": {"type": "integer", "description": "Max words (default 50)"},
            },
            "required": ["value"],
        },
    ),
    Tool(
        name="gr_search",
        description=(
            "Full-text search of the curated Gematria Reference. Searches both the article "
            "titles/epithets (e.g. 'wisdom', 'glory', 'covenant') and the curated word list "
            "(by English name or transliteration, e.g. 'Elohim', 'Chokmah'). Returns matching "
            "numbers so you can then call gr_reference for the full article."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "term": {"type": "string", "description": "Search term (theme word or transliteration)"},
                "limit": {"type": "integer", "description": "Max results per category (default 25)"},
            },
            "required": ["term"],
        },
    ),
    Tool(
        name="save_insight",
        description=(
            "Persist a research insight to biblewheel_research so it survives across sessions. "
            "Use whenever you discover a meaningful pattern, connection, or interpretation "
            "worth remembering (a surprising factorization, a numerical link between passages, "
            "a named word matching a key figurate number, a structural pattern)."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "observation": {"type": "string", "description": "The insight to record"},
                "verse_ref": {"type": "string", "description": "Primary verse reference (optional)"},
                "value": {"type": "integer", "description": "Gematria value involved (optional)"},
                "method": {"type": "string", "description": "Gematria method (optional)"},
                "tags": {"type": "string", "description": "Comma-separated tags, e.g. 'hex-star,gen1,37'"},
            },
            "required": ["observation"],
        },
    ),
    Tool(
        name="recall_insights",
        description=(
            "Search previously saved research insights from biblewheel_research by verse "
            "reference, gematria value, tag, or keyword. At least one filter is required."
        ),
        inputSchema={
            "type": "object",
            "properties": {
                "verse_ref": {"type": "string", "description": "Filter by verse reference"},
                "value": {"type": "integer", "description": "Filter by gematria value"},
                "tag": {"type": "string", "description": "Filter by tag"},
                "keyword": {"type": "string", "description": "Full-text search in observation"},
                "limit": {"type": "integer", "description": "Max results (default 20)"},
            },
        },
    ),
]


# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------

@app.list_tools()
async def list_tools() -> list[Tool]:
    return TOOLS


@app.call_tool()
async def call_tool(name: str, arguments: dict) -> list[TextContent]:
    handlers = {
        "verse_gematria":   _handle_verse_gematria,
        "passage_gematria": _handle_passage_gematria,
        "united_analysis":  _handle_united_analysis,
        "search_by_value":  _handle_search_by_value,
        "analyze_number":   _handle_analyze_number,
        "holograph_search": _handle_holograph_search,
        "gr_reference":     _handle_gr_reference,
        "gr_words":         _handle_gr_words,
        "gr_search":        _handle_gr_search,
        "save_insight":     _handle_save_insight,
        "recall_insights":  _handle_recall_insights,
    }
    handler = handlers.get(name)
    if handler is None:
        return _err(f"Unknown tool: {name}")
    try:
        return await handler(arguments)
    except ValueError as e:
        return _err(str(e))


# ---------------------------------------------------------------------------
# Handlers — computation
# ---------------------------------------------------------------------------

async def _handle_verse_gematria(args: dict) -> list[TextContent]:
    osis, chapter, sv, ev = parse_passage_reference(args["reference"])
    method = args.get("method", "standard")
    rows = _fetch_passage(osis, chapter, sv, sv)  # single verse
    if not rows:
        return _err(f"No gematria data for {args['reference']}")
    v = rows[0]
    total = v[method] if method in v else v["standard"]
    return _json({
        "reference": _ref_label(osis, chapter, sv, sv),
        "verse_totals": {"standard": v["standard"], "sofit": v["sofit"],
                         "ordinal": v["ordinal"], "reduced": v["reduced"]},
        "analyzed_method": method,
        "analyzed_total": total,
        "number_analysis": _number_analysis(total),
        "words": v["words"],
    })


async def _handle_passage_gematria(args: dict) -> list[TextContent]:
    osis, chapter, sv, ev = parse_passage_reference(args["reference"])
    method = args.get("method", "standard")
    mcol = _method_col(method)
    rows = _fetch_passage(osis, chapter, sv, ev)
    if not rows:
        return _err(f"No gematria data found for {args['reference']}")
    total = _passage_total(rows, mcol)
    # attach per-verse total for the chosen method
    mkey = {"standard": "standard", "standard_sofit": "sofit",
            "ordinal": "ordinal", "reduced": "reduced"}[mcol]
    for r in rows:
        r["verse_total"] = r[mkey]
    return _json({
        "reference": _ref_label(osis, chapter, sv, ev),
        "method": method,
        "verse_count": len(rows),
        "passage_total": total,
        "number_analysis": _number_analysis(total),
        "verses": rows,
    })


async def _handle_united_analysis(args: dict) -> list[TextContent]:
    method = args.get("method", "standard")
    mcol = _method_col(method)

    def total_for(ref: str) -> tuple[int, list[dict], str]:
        osis, chapter, sv, ev = parse_passage_reference(ref)
        rows = _fetch_passage(osis, chapter, sv, ev)
        return _passage_total(rows, mcol), rows, _ref_label(osis, chapter, sv, ev)

    t1, rows1, label1 = total_for(args["passage1"])
    t2, rows2, label2 = total_for(args["passage2"])
    if not rows1 or not rows2:
        return _err("One or both passages returned no gematria data",
                    passage1=label1, passage2=label2)

    combined = t1 + t2
    return _json({
        "method": method,
        "passage1": {"reference": label1, "total": t1, "analysis": _number_analysis(t1)},
        "passage2": {"reference": label2, "total": t2, "analysis": _number_analysis(t2)},
        "united": {
            "sum": combined,
            "difference": abs(t1 - t2),
            "product": t1 * t2,
            "sum_analysis": _number_analysis(combined),
        },
        "note": (
            f"Classic example: Gen 1:1 (2701) + John 1:1 (3627) = 6328 = T(112), where "
            f"112 = YHVH Elohim. Here {label1} ({t1}) + {label2} ({t2}) = {combined}."
        ),
    })


async def _handle_search_by_value(args: dict) -> list[TextContent]:
    value = int(args["value"])
    method = args.get("method", "standard")
    mcol = _method_col(method)
    limit = min(int(args.get("limit", 50)), 200)
    rows = query(
        f"""
        SELECT v.osis_ref, b.name AS book_name, v.chapter, v.verse,
               v.text_english, gv.{mcol} AS value
        FROM gematria_verse gv
        JOIN verse v ON v.id = gv.verse_id
        JOIN book b ON b.id = v.book_id
        WHERE gv.{mcol} = ?
        ORDER BY v.book_id, v.chapter, v.verse
        LIMIT ?
        """,
        (value, limit),
    )
    gr_words = gr.words_for_value(value, limit=25)
    return _json({
        "value": value,
        "method": method,
        "figurate_summary": figurate_summary(value),
        "verse_count": len(rows),
        "verses": [
            {"reference": r["osis_ref"], "book": r["book_name"],
             "chapter": r["chapter"], "verse": r["verse"],
             "text_snippet": (r["text_english"] or "")[:120]}
            for r in rows
        ],
        "curated_words_with_this_value": gr_words,
    })


async def _handle_analyze_number(args: dict) -> list[TextContent]:
    n = int(args["number"])
    analysis = _number_analysis(n)
    saved = query(
        "SELECT verse_ref, observation, tags, created_at FROM insights "
        "WHERE value = ? ORDER BY created_at DESC LIMIT 10",
        (n,), database="biblewheel_research",
    )
    if saved:
        analysis["saved_insights"] = saved
    return _json(analysis)


async def _handle_holograph_search(args: dict) -> list[TextContent]:
    osis, chapter, sv, ev = parse_passage_reference(args["reference"])
    method = args.get("method", "standard")
    mcol_key = {"standard": "standard", "standard_sofit": "sofit",
                "ordinal": "ordinal", "reduced": "reduced"}[_method_col(method)]
    limit = min(int(args.get("limit", 40)), 200)

    rows = _fetch_passage(osis, chapter, sv, ev)
    if not rows:
        return _err(f"No gematria data found for {args['reference']}")

    flat = [
        {"verse": vr["verse"], "text": w["text"], "value": w[mcol_key]}
        for vr in rows for w in vr["words"] if w.get(mcol_key)
    ]

    _brief_cache: dict[int, dict | None] = {}

    def brief(total: int) -> dict | None:
        if total not in _brief_cache:
            _brief_cache[total] = gr.brief(total)
        return _brief_cache[total]

    segments = []
    n = len(flat)
    for i in range(n):
        running = 0
        for j in range(i, n):
            running += flat[j]["value"]
            forms = analyze(running)["figurate"]
            named = known_notable(running)
            ref = brief(running) if (forms or named) else None
            if forms or named or ref:
                segments.append({
                    "words": flat[i]["text"] if j == i else f"{flat[i]['text']} … {flat[j]['text']}",
                    "start_word": i + 1,
                    "end_word": j + 1,
                    "verse_range": (f"v.{flat[i]['verse']}" if flat[i]["verse"] == flat[j]["verse"]
                                    else f"v.{flat[i]['verse']}-{flat[j]['verse']}"),
                    "total": running,
                    "figurate": [f["formula"] for f in forms],
                    "named": named,
                    "gr": ref,
                    "_score": _significance(forms, named, ref),
                })

    segments.sort(key=lambda s: (-s["_score"], s["start_word"], s["end_word"]))
    for s in segments:
        del s["_score"]

    # verse-level cumulative milestones
    cumulative = []
    cum = 0
    for vr in rows:
        cum += vr[mcol_key] or 0
        forms = analyze(cum)["figurate"]
        named = known_notable(cum)
        ref = brief(cum) if (forms or named) else None
        if forms or named or ref:
            cumulative.append({
                "after_verse": vr["verse"], "cumulative_total": cum,
                "figurate": [f["formula"] for f in forms], "named": named, "gr": ref,
            })

    return _json({
        "reference": _ref_label(osis, chapter, sv, ev),
        "method": method,
        "word_count": len(flat),
        "notable_word_segments": segments[:limit],
        "segments_found": len(segments),
        "verse_cumulative_milestones": cumulative,
        "note": (
            "A holograph is present when sub-units of the text mirror the figurate structure "
            "of the whole. Star/Hex forms and named associations are the strongest signals; "
            "bare triangular/square hits are common and rank lower."
        ),
    })


def _significance(forms: list[dict], named: list[str], ref: dict | None) -> int:
    score = 0
    for f in forms:
        score += {"star": 5, "hex": 5, "pentagonal": 3,
                  "triangular": 2, "square": 1}.get(f["type"], 1)
    if named:
        score += 6
    if ref:
        score += 6
    return score


# ---------------------------------------------------------------------------
# Handlers — curated GR reference
# ---------------------------------------------------------------------------

async def _handle_gr_reference(args: dict) -> list[TextContent]:
    n = int(args["number"])
    ref = gr.lookup(n)
    if not ref:
        return _json({"number": n, "found": False,
                      "message": f"No GR reference article exists for {n}.",
                      "computed": _number_analysis(n, include_gr=False)})
    ref["found"] = True
    ref["figurate_summary"] = figurate_summary(n)
    return _json(ref)


async def _handle_gr_words(args: dict) -> list[TextContent]:
    value = int(args["value"])
    limit = min(int(args.get("limit", 50)), 200)
    words = gr.words_for_value(value, limit=limit)
    return _json({
        "value": value,
        "figurate_summary": figurate_summary(value),
        "count": len(words),
        "words": words,
    })


async def _handle_gr_search(args: dict) -> list[TextContent]:
    term = args["term"].strip()
    limit = min(int(args.get("limit", 25)), 100)
    if not term:
        return _err("Provide a non-empty search term")
    return _json({
        "term": term,
        "matching_numbers": gr.search_titles(term, limit=limit),
        "matching_words": gr.search_words(term, limit=limit),
    })


# ---------------------------------------------------------------------------
# Handlers — research memory
# ---------------------------------------------------------------------------

async def _handle_save_insight(args: dict) -> list[TextContent]:
    row_id = execute(
        "INSERT INTO insights (verse_ref, value, method, observation, tags) VALUES (?,?,?,?,?)",
        (args.get("verse_ref"), args.get("value"), args.get("method"),
         args["observation"], args.get("tags")),
        database="biblewheel_research",
    )
    return _json({"status": "saved", "id": row_id, "observation": args["observation"]})


async def _handle_recall_insights(args: dict) -> list[TextContent]:
    limit = min(int(args.get("limit", 20)), 100)
    conditions, params = [], []
    if args.get("verse_ref"):
        conditions.append("verse_ref = %s"); params.append(args["verse_ref"])
    if args.get("value") is not None:
        conditions.append("value = %s"); params.append(int(args["value"]))
    if args.get("tag"):
        conditions.append("FIND_IN_SET(%s, tags) > 0"); params.append(args["tag"])
    if args.get("keyword"):
        conditions.append("MATCH(observation) AGAINST(%s IN BOOLEAN MODE)"); params.append(args["keyword"])
    if not conditions:
        return _err("Provide at least one filter: verse_ref, value, tag, or keyword")
    rows = query(
        f"SELECT id, verse_ref, value, method, observation, tags, created_at "
        f"FROM insights WHERE {' AND '.join(conditions)} ORDER BY created_at DESC LIMIT %s",
        tuple(params) + (limit,), database="biblewheel_research",
    )
    return _json({"count": len(rows), "insights": rows})


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

async def main():
    async with stdio_server() as (read_stream, write_stream):
        await app.run(read_stream, write_stream, app.create_initialization_options())


if __name__ == "__main__":
    asyncio.run(main())
