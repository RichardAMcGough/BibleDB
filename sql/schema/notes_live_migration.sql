-- notes_live_migration.sql
--
-- phpMyAdmin-safe, idempotent notes schema migration.
-- No PREPARE/EXECUTE dynamic SQL (to avoid phpMyAdmin parser/result bugs).
--
-- Applies the modern notes schema used by the web app:
--   - verse_notes core columns (including is_public + gem_* fields)
--   - note_type lookup + seeds
--   - verse_note_types junction
--
-- If your DB still has a legacy verse_notes.note_type ENUM column, run the
-- optional block at the bottom manually.

-- 1) Ensure verse_notes exists (modern baseline)
CREATE TABLE IF NOT EXISTS verse_notes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    username     VARCHAR(100) NOT NULL,
    book_code    VARCHAR(10) NOT NULL,
    chapter      SMALLINT UNSIGNED NOT NULL,
    verse        SMALLINT UNSIGNED NOT NULL,
    title        VARCHAR(255) NOT NULL,
    note_text    TEXT NOT NULL,
    is_public    TINYINT(1) NOT NULL DEFAULT 0,
    gem_std      INT NULL,
    gem_ord      INT NULL,
    gem_red      INT NULL,
    selected_words VARCHAR(255) NULL,
    edition_code VARCHAR(40) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_vn_verse (book_code, chapter, verse),
    KEY idx_vn_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Bring existing verse_notes up to expected shape
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS title VARCHAR(255) NOT NULL DEFAULT '' AFTER verse;
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS note_text TEXT NOT NULL AFTER title;
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER note_text;
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS gem_std INT NULL AFTER is_public;
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS gem_ord INT NULL AFTER gem_std;
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS gem_red INT NULL AFTER gem_ord;
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS selected_words VARCHAR(255) NULL AFTER gem_red;
ALTER TABLE verse_notes ADD COLUMN IF NOT EXISTS edition_code VARCHAR(40) NULL AFTER selected_words;

-- Ensure title is NOT NULL and non-empty where possible.
UPDATE verse_notes SET title = CONCAT('Note ', id) WHERE title IS NULL OR title = '';
ALTER TABLE verse_notes MODIFY COLUMN title VARCHAR(255) NOT NULL;

-- 3) note_type lookup + seeds
CREATE TABLE IF NOT EXISTS note_type (
    id     TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(30) NOT NULL,
    label  VARCHAR(80) NOT NULL,
    UNIQUE KEY uq_note_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO note_type (id, name, label) VALUES
    (1, 'General',  'General (commentary)'),
    (2, 'BW',       'Bible Wheel'),
    (3, 'IBC',      'Isaiah-Bible Correlation'),
    (4, 'Gematria', 'Gematria');

-- 4) verse_note_types junction
CREATE TABLE IF NOT EXISTS verse_note_types (
    note_id  INT UNSIGNED NOT NULL,
    type_id  TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (note_id, type_id),
    KEY idx_vnt_type (type_id),
    CONSTRAINT fk_vnt_note FOREIGN KEY (note_id) REFERENCES verse_notes (id) ON DELETE CASCADE,
    CONSTRAINT fk_vnt_type FOREIGN KEY (type_id) REFERENCES note_type (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure every note has at least a General tag.
INSERT IGNORE INTO verse_note_types (note_id, type_id)
SELECT vn.id, 1
FROM verse_notes vn
WHERE NOT EXISTS (SELECT 1 FROM verse_note_types vnt WHERE vnt.note_id = vn.id);


-- =====================================================================
-- Optional legacy migration (run ONLY if verse_notes.note_type exists)
-- =====================================================================
-- If your old schema has verse_notes.note_type ENUM('general','gematria'),
-- run these lines manually after confirming the column exists:
--
-- INSERT IGNORE INTO verse_note_types (note_id, type_id)
-- SELECT vn.id, nt.id
-- FROM verse_notes vn
-- JOIN note_type nt ON (
--     (vn.note_type = 'general'  AND nt.name = 'General') OR
--     (vn.note_type = 'gematria' AND nt.name = 'Gematria')
-- );
--
-- ALTER TABLE verse_notes DROP COLUMN note_type;
