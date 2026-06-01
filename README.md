# BibleDB

Full featured Bible Database with Greek, Hebrew, interlinear, grammar, concordance, gematria and more!

## Overview

This project consists of two main parts:

1. **Data Import Pipeline** (root directory)
   - Python scripts that parse and load STEPBible.org tagged Hebrew/Greek texts, BibleWorks editions (NA27, Scrivener TR), and the Rahlfs LXX into a MariaDB database.
   - Extensive support for textual variants, morphology, Strong's numbers, gematria, and cross-edition comparison.

2. **Web Interface** (`web/` folder)
   - A PHP-based interlinear Bible browser.
   - Features include edition switching (NA28, TR, LXX-Rahlfs, etc.), variant cycling, gematria calculations, Strong's tooltips, grammar tooltips, word selection, and powerful search.

## Project Structure

```
BibleDB/
├── *.py, *.sql                 # Data import pipeline and schemas
├── config.ini                  # Database credentials (not committed)
├── config.ini.sample           # Template for database config
├── HANDOFF.md                  # Detailed technical documentation (data side)
│
└── web/                        # PHP Web UI
    ├── index.php               # Main interlinear viewer
    ├── db.php                  # Database access layer
    ├── api.php                 # AJAX + remote API endpoints
    ├── config.php              # Web config (not committed)
    ├── config.php.sample       # Template for web config
    ├── js/                     # Frontend JavaScript
    └── HANDOFF.md              # Web UI specific documentation
```

## Getting Started

### 1. Database Setup (Optional for UI Development)

If you want to run the full stack locally:

1. Create a MariaDB database called `stepbible`.
2. Copy `config.ini.sample` → `config.ini` and fill in your credentials.
3. Run the import pipeline (see root `HANDOFF.md` for the full ordered steps).

### 2. Web UI Setup

1. Copy `web/config.php.sample` → `web/config.php` and update the database credentials.
2. Point your web server (Apache, nginx, PHP built-in server, etc.) at the `web/` directory.
3. Access the interface (example: `http://localhost/stepbible`).

**Tip for developers without a local database:**

Set `'use_remote_api' => true` and provide a `'remote_api_base'` in `config.php`. The UI will then pull data from a remote instance of this project instead of requiring a local MariaDB database.

## Development

- The web UI can run completely independently of the data import scripts.
- Most development work happens inside the `web/` folder.
- The root contains the data loading and processing tools.

## Documentation

- **Root `HANDOFF.md`** — Architecture of the database schema, import pipeline, variant handling, and data conventions.
- **`web/HANDOFF.md`** — Details about the web UI, JavaScript components, and frontend architecture.

## License

Data sources have their own licenses (primarily CC BY 4.0 from STEPBible.org and related projects). See individual source files and the root HANDOFF for attribution details.

## Contributing / External Development

Developers who do not have the full database locally can still work on the web UI by pointing `config.php` at a remote instance using the `use_remote_api` setting.

