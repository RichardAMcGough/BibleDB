"""
Accessors for the curated Gematria Reference (GR) knowledge base.

The GR data is extracted from Richard McGough's published GR_<number>.php
articles (see tools/extract_gr.py) into two tables in biblewheel_research:

  gr_reference (number, title, epithet, verses, identities, xrefs, page, url)
  gr_word      (page, value, lang, name, translit, picfile)

These functions are the read API used by the gematria MCP to surface the
hand-written commentary alongside computed gematria results.
"""

from __future__ import annotations
import json
from typing import Any

from shared.db import query

_DB = "biblewheel_research"


def _loads(v: Any) -> Any:
    if v is None:
        return None
    if isinstance(v, (list, dict)):
        return v
    try:
        return json.loads(v)
    except (ValueError, TypeError):
        return v


def lookup(number: int) -> dict | None:
    """Full curated reference article for a number, including its word entries."""
    rows = query(
        "SELECT number, title, epithet, verses, identities, xrefs, url "
        "FROM gr_reference WHERE number = ?",
        (number,), database=_DB,
    )
    if not rows:
        return None
    r = rows[0]
    words = query(
        "SELECT value, lang, name, translit FROM gr_word WHERE page = ? ORDER BY value",
        (number,), database=_DB,
    )
    return {
        "number": r["number"],
        "title": r["title"],
        "epithet": r["epithet"],
        "verses": _loads(r["verses"]),
        "identities": _loads(r["identities"]),
        "cross_references": _loads(r["xrefs"]),
        "url": r["url"],
        "words": words,
    }


def brief(number: int) -> dict | None:
    """A compact one-liner for embedding in other tools' output."""
    rows = query(
        "SELECT number, epithet, url FROM gr_reference WHERE number = ? AND epithet IS NOT NULL",
        (number,), database=_DB,
    )
    if not rows:
        return None
    return {"number": rows[0]["number"], "epithet": rows[0]["epithet"], "url": rows[0]["url"]}


def words_for_value(value: int, limit: int = 50) -> list[dict]:
    """Curated, transliterated words whose own gematria equals `value`,
    gathered across all GR articles (deduplicated by name+translit)."""
    rows = query(
        "SELECT DISTINCT name, translit, lang, page FROM gr_word "
        "WHERE value = ? ORDER BY lang, name LIMIT ?",
        (value, limit), database=_DB,
    )
    return rows


def search_words(term: str, limit: int = 30) -> list[dict]:
    """Full-text search over curated word names and transliterations."""
    rows = query(
        "SELECT value, lang, name, translit, page FROM gr_word "
        "WHERE MATCH(name, translit) AGAINST(? IN BOOLEAN MODE) "
        "ORDER BY value LIMIT ?",
        (term, limit), database=_DB,
    )
    return rows


def search_titles(term: str, limit: int = 30) -> list[dict]:
    """Full-text search over reference titles/epithets."""
    rows = query(
        "SELECT number, epithet, url FROM gr_reference "
        "WHERE MATCH(title) AGAINST(? IN BOOLEAN MODE) "
        "ORDER BY number LIMIT ?",
        (term, limit), database=_DB,
    )
    return rows
