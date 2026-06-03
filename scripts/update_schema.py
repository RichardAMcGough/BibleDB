#!/usr/bin/env python3
"""
update_schema.py — apply all pending schema updates to an existing database.

Idempotent: every change is guarded by an information_schema check or a
CREATE/INSERT IF NOT EXISTS clause, so it is safe to run repeatedly and safe
to run against a live database that already has user data.  Nothing is ever
dropped except old migration scaffolding (e.g. the verse_notes.note_type ENUM
column, which is migrated to the junction table before being dropped).

What it does
------------
  - variant.position column + index (backfills from word.position)
  - verse_views table + record_verse_view stored proc
  - GetGematriaWords stored proc
  - user_notes table
  - verse_notes table:
      * creates if missing
      * migrates title to NOT NULL (fills placeholder before altering)
      * adds is_public column if missing
      * migrates old note_type ENUM → verse_note_types junction, then drops column
  - note_type lookup table + four seed rows (INSERT IGNORE)
  - verse_note_types junction table
  - strongs_primary lexical-form heal for compound Strong's tags

Usage
-----
  # Local (uses config.ini in project root):
  python scripts/update_schema.py --db-name my_local_db

  # Production server via SSH (set credentials in config.ini first):
  python scripts/update_schema.py --db-name biblewhe_stepbible

  # Override config file location:
  python scripts/update_schema.py --db-name biblewhe_stepbible --config /path/to/config.ini
"""

from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
SCRIPTS_ROOT = PROJECT_ROOT / "scripts"
sys.path.insert(0, str(SCRIPTS_ROOT))

from _db import load_config                              # noqa: E402
from run_pipeline import ensure_schema_migrations        # noqa: E402


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n", 1)[0])
    ap.add_argument("--db-name", required=True,
                    help="Target database name.")
    ap.add_argument("--config", default=str(PROJECT_ROOT / "config.ini"),
                    help="Path to config.ini (default: project root)")
    args = ap.parse_args(argv)

    db_name = args.db_name.strip()
    if not db_name:
        print("ERROR: --db-name cannot be empty.")
        sys.exit(2)

    os.environ["BIBLE_DB_NAME"] = db_name
    cfg = load_config(Path(args.config))

    def log(msg: str):
        print(msg, flush=True)

    log(f"Running migrations against '{db_name}' ...")
    log("")
    ok = ensure_schema_migrations(cfg, log)
    log("")
    if ok:
        log("All migrations complete.")
    else:
        log("Migration failed — see errors above.")
        sys.exit(1)


if __name__ == "__main__":
    main()
