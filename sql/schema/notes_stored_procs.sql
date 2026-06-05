-- notes_stored_procs.sql
--
-- Stored procedures for notes writes.
-- Intended for least-privilege web DB users with SELECT + EXECUTE only.
--
-- Run in phpMyAdmin after notes_live_migration.sql.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_create_verse_note $$
CREATE PROCEDURE sp_create_verse_note(
    IN p_user_id      INT UNSIGNED,
    IN p_username     VARCHAR(100),
    IN p_book_code    VARCHAR(10),
    IN p_chapter      SMALLINT UNSIGNED,
    IN p_verse        SMALLINT UNSIGNED,
    IN p_title        VARCHAR(255),
    IN p_note_text    TEXT,
    IN p_is_public    TINYINT,
    IN p_gem_std      INT,
    IN p_gem_ord      INT,
    IN p_gem_red      INT,
    IN p_selected_words VARCHAR(255),
    IN p_edition_code VARCHAR(40),
    IN p_type_ids_csv VARCHAR(255)
)
BEGIN
    DECLARE v_note_id INT UNSIGNED DEFAULT 0;
    DECLARE v_csv TEXT;
    DECLARE v_pos INT DEFAULT 0;
    DECLARE v_tok VARCHAR(20);
    DECLARE v_tid INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SELECT 0 AS ok, 'database error' AS error, 0 AS note_id;
    END;

    START TRANSACTION;

    INSERT INTO verse_notes (
        user_id, username, book_code, chapter, verse,
        title, note_text, is_public, gem_std, gem_ord, gem_red, selected_words, edition_code
    ) VALUES (
        p_user_id, p_username, p_book_code, p_chapter, p_verse,
        p_title, p_note_text, IFNULL(p_is_public, 0), p_gem_std, p_gem_ord, p_gem_red,
        NULLIF(p_selected_words, ''), NULLIF(p_edition_code, '')
    );

    SET v_note_id = LAST_INSERT_ID();
    SET v_csv = TRIM(BOTH ',' FROM IFNULL(p_type_ids_csv, ''));
    IF v_csv = '' THEN
        SET v_csv = '1';
    END IF;
    SET v_csv = CONCAT(v_csv, ',');

    SET v_pos = LOCATE(',', v_csv);
    WHILE v_pos > 0 DO
        SET v_tok = TRIM(SUBSTRING(v_csv, 1, v_pos - 1));
        IF v_tok <> '' THEN
            SET v_tid = CAST(v_tok AS UNSIGNED);
            IF v_tid > 0 THEN
                INSERT IGNORE INTO verse_note_types (note_id, type_id)
                VALUES (v_note_id, v_tid);
            END IF;
        END IF;
        SET v_csv = SUBSTRING(v_csv, v_pos + 1);
        SET v_pos = LOCATE(',', v_csv);
    END WHILE;

    INSERT IGNORE INTO verse_note_types (note_id, type_id)
    SELECT v_note_id, 1
    WHERE NOT EXISTS (SELECT 1 FROM verse_note_types WHERE note_id = v_note_id);

    COMMIT;

    SELECT 1 AS ok, '' AS error, v_note_id AS note_id;
END $$


DROP PROCEDURE IF EXISTS sp_update_verse_note $$
CREATE PROCEDURE sp_update_verse_note(
    IN p_note_id      INT UNSIGNED,
    IN p_user_id      INT UNSIGNED,
    IN p_book_code    VARCHAR(10),
    IN p_chapter      SMALLINT UNSIGNED,
    IN p_verse        SMALLINT UNSIGNED,
    IN p_title        VARCHAR(255),
    IN p_note_text    TEXT,
    IN p_is_public    TINYINT,
    IN p_gem_std      INT,
    IN p_gem_ord      INT,
    IN p_gem_red      INT,
    IN p_selected_words VARCHAR(255),
    IN p_edition_code VARCHAR(40),
    IN p_type_ids_csv VARCHAR(255)
)
BEGIN
    DECLARE v_csv TEXT;
    DECLARE v_pos INT DEFAULT 0;
    DECLARE v_tok VARCHAR(20);
    DECLARE v_tid INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SELECT 0 AS ok, 'database error' AS error;
    END;

    START TRANSACTION;

    UPDATE verse_notes
       SET book_code = p_book_code,
           chapter = p_chapter,
           verse = p_verse,
           title = p_title,
           note_text = p_note_text,
           is_public = IFNULL(p_is_public, 0),
           gem_std = p_gem_std,
           gem_ord = p_gem_ord,
           gem_red = p_gem_red,
                     selected_words = NULLIF(p_selected_words, ''),
                     edition_code = NULLIF(p_edition_code, ''),
           updated_at = CURRENT_TIMESTAMP
     WHERE id = p_note_id
       AND user_id = p_user_id;

    IF ROW_COUNT() = 0 THEN
        ROLLBACK;
        SELECT 0 AS ok, 'note not found or you are not the owner' AS error;
    ELSE
        DELETE FROM verse_note_types WHERE note_id = p_note_id;

        SET v_csv = TRIM(BOTH ',' FROM IFNULL(p_type_ids_csv, ''));
        IF v_csv = '' THEN
            SET v_csv = '1';
        END IF;
        SET v_csv = CONCAT(v_csv, ',');

        SET v_pos = LOCATE(',', v_csv);
        WHILE v_pos > 0 DO
            SET v_tok = TRIM(SUBSTRING(v_csv, 1, v_pos - 1));
            IF v_tok <> '' THEN
                SET v_tid = CAST(v_tok AS UNSIGNED);
                IF v_tid > 0 THEN
                    INSERT IGNORE INTO verse_note_types (note_id, type_id)
                    VALUES (p_note_id, v_tid);
                END IF;
            END IF;
            SET v_csv = SUBSTRING(v_csv, v_pos + 1);
            SET v_pos = LOCATE(',', v_csv);
        END WHILE;

        INSERT IGNORE INTO verse_note_types (note_id, type_id)
        SELECT p_note_id, 1
        WHERE NOT EXISTS (SELECT 1 FROM verse_note_types WHERE note_id = p_note_id);

        COMMIT;
        SELECT 1 AS ok, '' AS error;
    END IF;
END $$


DROP PROCEDURE IF EXISTS sp_delete_verse_note $$
CREATE PROCEDURE sp_delete_verse_note(
    IN p_note_id   INT UNSIGNED,
    IN p_user_id   INT UNSIGNED,
    IN p_is_admin  TINYINT
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SELECT 0 AS ok, 'database error' AS error;
    END;

    IF IFNULL(p_is_admin, 0) = 1 THEN
        DELETE FROM verse_notes WHERE id = p_note_id;
    ELSE
        DELETE FROM verse_notes WHERE id = p_note_id AND user_id = p_user_id;
    END IF;

    IF ROW_COUNT() = 0 THEN
        IF IFNULL(p_is_admin, 0) = 1 THEN
            SELECT 0 AS ok, 'note not found' AS error;
        ELSE
            SELECT 0 AS ok, 'note not found or you are not the owner' AS error;
        END IF;
    ELSE
        SELECT 1 AS ok, '' AS error;
    END IF;
END $$


DROP PROCEDURE IF EXISTS sp_set_verse_note_visibility $$
CREATE PROCEDURE sp_set_verse_note_visibility(
    IN p_note_id    INT UNSIGNED,
    IN p_is_public  TINYINT
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SELECT 0 AS ok, 'database error' AS error;
    END;

    UPDATE verse_notes
       SET is_public = IFNULL(p_is_public, 0),
           updated_at = CURRENT_TIMESTAMP
     WHERE id = p_note_id;

    IF ROW_COUNT() = 0 THEN
        SELECT 0 AS ok, 'note not found' AS error;
    ELSE
        SELECT 1 AS ok, '' AS error;
    END IF;
END $$

DELIMITER ;
