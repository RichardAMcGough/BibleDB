# BibleWheel Gematria MCP

Gematria research tools over the local `stepbible3` database, enriched with the
curated **Gematria Reference (GR)** knowledge base and persistent research memory
in `biblewheel_research`.

## Tools

### Computation (from `stepbible3`)
| Tool | Purpose |
|------|---------|
| `verse_gematria` | All four gematria methods for a verse + word breakdown + full number analysis of the total |
| `passage_gematria` | Per-verse breakdown + combined total for a verse range |
| `united_analysis` | Two passages → individual totals, sum, difference, product, each fully analyzed (the Gen 1:1 + John 1:1 = T(112) pattern) |
| `search_by_value` | All verses whose gematria total equals a value (+ curated words of that value) |
| `analyze_number` | Factors, figurate forms, named associations, GR article, saved insights |
| `holograph_search` | Embedded figurate patterns in a passage, ranked by significance, with GR epithets per segment |

### Curated reference (GR knowledge base)
| Tool | Purpose |
|------|---------|
| `gr_reference` | The published GR article for a number — epithet, key verses, identities, cross-refs, words |
| `gr_words` | Curated transliterated words whose value equals a number (e.g. 86 → Elohim, Gaon YHVH, Hallelu-Yah…) |
| `gr_search` | Full-text search of GR titles/epithets and the curated word list |

### Research memory (`biblewheel_research`)
| Tool | Purpose |
|------|---------|
| `save_insight` | Persist a research observation |
| `recall_insights` | Search saved insights by ref/value/tag/keyword |

## Gematria methods
- **standard** — mispar hechrachi (default)
- **sofit** — final-letter (sofit) values
- **ordinal** — mispar siduri
- **reduced** — mispar katan

## Figurate forms (GR convention)
- `T(n)` triangular = n(n+1)/2 (also written `Sum(n)`)
- `Sq(n)` square
- `P(n)` pentagonal
- `H(n)` **centered** hexagonal = 3n(n−1)+1 (e.g. 37 = H(4))
- `S(n)` star / hexagram = 6n(n−1)+1 (e.g. 73 = S(4))

The standard hexagonal n(2n−1) is intentionally excluded — it is a subset of the
triangulars and unused in this research. A Hex/Star pair like **37/73** is the
signature of the Creation Holograph.

## The GR knowledge base

`tools/extract_gr.py` parses Richard McGough's ~275 published `GR_<number>.php`
articles (in `public_html/GR/`) into two tables in `biblewheel_research`:

- **`gr_reference`** — `number, title, epithet, verses, identities, xrefs, page, url`
  (183 numbers carry a descriptive epithet, e.g. 37 = "The Heart of Wisdom")
- **`gr_word`** — `page, value, lang, name, translit, picfile`
  (~2,000 transliterated word identities)

These surface automatically inside `analyze_number`, `verse_gematria`,
`passage_gematria`, `united_analysis`, and `holograph_search`.

### Re-running the extractor
```
python tools/extract_gr.py --dry-run   # inspect what would be written
python tools/extract_gr.py             # rebuild gr_reference + gr_word (idempotent)
```

## Design notes
- Single source of truth for figurate math in `shared/figurate.py`; no duplicated
  inverse formulas in the server.
- Passage fetches are batched: **2 queries regardless of passage length**.
- No figurate cache — `analyze()` is deterministic and fast, and the old cache
  table was a source of stale/incorrect data.
- All tools return **JSON** on both success and error (consistent contract).
