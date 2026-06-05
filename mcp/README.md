# Bible Allusion MCP

Scores intertextual allusions between Bible verses using Matthew Swale's three-instinct method from *Scripture's Use of Scripture in the Old Testament: Three Instincts for Identifying Allusions*.

## Tools

### `fetch_verse`
Fetches a verse's original-language text and word-level Strong's data.

### `score_terms`
Algorithmic Terms score: finds shared Strong's numbers, weights them by corpus-wide rarity (rare shared terms = strong linguistic evidence), returns a 0–9 score.

### `score_allusion`
Full Swale analysis. Returns:
- Computed **Terms** score (algorithmic)
- Both verses' text and word data (for Claude to reason about)
- **Themes** and **Thesis** rubrics for Claude to apply
- Confidence label: `high / moderate / low / unlikely`

**Cross-language note:** For OT Hebrew → NT Greek allusions, the Terms score is computed within-language only (H-codes and G-codes don't overlap). The tool flags this and prompts evaluation of LXX vocabulary alignment manually.

## Installation (Claude Desktop)

Add to `%APPDATA%\Claude\claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "bible-allusion": {
      "command": "python",
      "args": ["C:/Work/Resurrected/Bible Wheel Site/BibleDB/mcp/server.py"],
      "env": {}
    }
  }
}
```

Requires the local Bible DB server running at `http://localhost/bibledb`.

## Example usage

> "Score the allusion between Isa 40:3 and John 1:23 using Swale's method"

Claude will call `score_allusion("Isa 40:3", "John 1:23")`, receive the Terms score and both verse texts, then reason through Themes and Thesis to produce a full structured analysis.

## Swale's Rubric

| Instinct | What it measures | Scoring |
|----------|-----------------|---------|
| **Terms** | Shared rare/distinctive vocabulary | +3 rare (≤10 verses), +2 uncommon (11–100), +1 common (101–500) |
| **Themes** | Shared motifs, narrative parallels, theological patterns | +3 multi-layered, +2 clear motif, +1 general similarity |
| **Thesis** | Does the allusion serve the later text's argument? | +3 essential, +2 strengthening, +1 compatible |

**Confidence:** high (total ≥7, all three have evidence) · moderate (4–6) · low (2–3) · unlikely (<2)
