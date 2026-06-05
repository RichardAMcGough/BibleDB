"""
Shared MariaDB connection factory for all BibleWheel MCP servers.
Uses the bwresearch user: read-only on stepbible3, full access on biblewheel_research.
"""

import pymysql
import pymysql.cursors
from typing import Optional

_CREDENTIALS = dict(
    host="127.0.0.1",
    port=3306,
    user="bwresearch",
    password="BwResearch2024!",
    charset="utf8mb4",
)


def get_connection(database: str = "stepbible3") -> pymysql.Connection:
    return pymysql.connect(**_CREDENTIALS, database=database,
                           cursorclass=pymysql.cursors.DictCursor)


def query(sql: str, params: tuple = (), database: str = "stepbible3") -> list[dict]:
    """Run a SELECT and return rows as list of dicts."""
    # PyMySQL uses %s placeholders; convert ? → %s
    sql = sql.replace("?", "%s")
    conn = get_connection(database)
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            return list(cur.fetchall())
    finally:
        conn.close()


def execute(sql: str, params: tuple = (), database: str = "biblewheel_research") -> int:
    """Run an INSERT/UPDATE/DELETE and return lastrowid."""
    sql = sql.replace("?", "%s")
    conn = get_connection(database)
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            conn.commit()
            return cur.lastrowid
    finally:
        conn.close()


def execute_many(sql: str, rows: list[tuple], database: str = "biblewheel_research") -> int:
    """Bulk INSERT/UPDATE over many parameter tuples on a single connection.
    Returns the number of affected rows."""
    if not rows:
        return 0
    sql = sql.replace("?", "%s")
    conn = get_connection(database)
    try:
        with conn.cursor() as cur:
            cur.executemany(sql, rows)
            conn.commit()
            return cur.rowcount
    finally:
        conn.close()


def resolve_verse_id(osis: str, chapter: int, verse: int) -> Optional[int]:
    """Return the verse.id for a given book OSIS code, chapter, verse."""
    rows = query(
        """
        SELECT v.id FROM verse v
        JOIN book b ON b.id = v.book_id
        WHERE b.osis_code = ? AND v.chapter = ? AND v.verse = ?
        """,
        (osis, chapter, verse),
    )
    return rows[0]["id"] if rows else None
