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
├── docs/
│   ├── HANDOFF-current.md      # Recommended current handoff
│   └── HANDOFF.md              # Historical/archival notes
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

1. Create a MariaDB database (name is up to you, e.g. `stepbible` or `stepbibletest` for a clean test DB). The database name has a **strict single source of truth**: it must be set via the `BIBLE_DB_NAME` environment variable. It is no longer read from `config.ini`.
2. Copy `config.ini.sample` → `config.ini` and fill in your credentials.
3. Run the import pipeline. The easiest way to create the required tables is to pass `--create-schema` to `import_bible.py` (see root `HANDOFF.md` for the full ordered steps and other options).

### 2. Web UI Setup

1. Copy `web/config.php.sample` → `web/config.php` and update the database credentials.  
   (On shared hosting the database name is usually prefixed, e.g. `youruser_stepbible`.)
2. Point your web server (Apache, nginx, PHP built-in server, etc.) at the `web/` directory.
3. Access the interface (example: `http://localhost/stepbible`).

**Tip for developers without a local database:**

Set `'use_remote_api' => true` and provide a `'remote_api_base'` in `config.php`. The UI will then pull data from a remote instance of this project instead of requiring a local MariaDB database.

## Development

- The **web UI** can run completely independently of the data import scripts.
- Most frontend development work happens inside the `web/` folder.
- The root contains the data loading and processing tools (import pipeline is still being stabilized).

**External contributors welcome!**  
The web UI is now in the repository and ready for development. You can work on the frontend without setting up the full local database or running the import scripts by using the built-in remote API mode (see below).

## Documentation

- **`docs/HANDOFF-current.md`** — Recommended starting point (current workflows, single source of truth for DB name, easy fresh database creation).
- **Root `HANDOFF.md`** — Historical/archival notes from earlier development sessions (still useful for deep context).
- **`web/HANDOFF.md`** — Details about the web UI, JavaScript components, and frontend architecture.

## License

Data sources have their own licenses (primarily CC BY 4.0 from STEPBible.org and related projects). See individual source files and the root HANDOFF for attribution details.

## Contributing / External Development

The web UI is ready for contributors right now.

Developers who do not have (or do not want to set up) the full local database + import pipeline can work on the frontend by enabling remote API mode:

1. Copy `web/config.php.sample` → `web/config.php`
2. Set `'use_remote_api' => true`
3. Set `'remote_api_base'` to a live instance of the site (e.g. the maintainer’s development server)

See `web/README.md` for more details on remote development.

