<?php
// PDO connection helper. Loads credentials from config.php (which the user
// creates by copying config.sample.php). Returns a PDO instance.

require_once __DIR__ . '/remote_api.php';

/**
 * Start the PHP session safely.
 * If the configured session.save_path doesn't exist (e.g. a cPanel Linux path
 * on a Windows dev machine), fall back to sys_get_temp_dir() before starting.
 */
if (!function_exists('_start_session_safe')) {
    function _start_session_safe(): void {
        if (session_status() !== PHP_SESSION_NONE) return;
        if (headers_sent()) return; // can't start session after output
        $path = session_save_path();
        if ($path !== '' && !is_dir($path)) {
            session_save_path(sys_get_temp_dir());
        }
        session_start();
    }
}

function bible_pdo(): PDO {
    // Guard: never allow local DB access when remote API mode is enabled.
    if (should_use_remote_api()) {
        $cfg = require __DIR__ . '/config.php';

        $msg = "Direct local database access is disabled (use_remote_api is true in config.php).";

        if (!empty($cfg['debug'])) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
            $caller = $trace[1] ?? [];
            $msg .= "<br><br><strong>Called from:</strong> " . 
                    htmlspecialchars(($caller['class'] ?? '') . ($caller['type'] ?? '') . ($caller['function'] ?? '')) .
                    " in " . htmlspecialchars($caller['file'] ?? '') . ":" . ($caller['line'] ?? '') . "<br><br>";

            $msg .= "<strong>Stack trace (first few frames):</strong><pre>" . 
                    htmlspecialchars(print_r(array_slice($trace, 0, 6), true)) . "</pre>";
        } else {
            $msg .= " Some code paths are not yet routed through the remote API. Enable debug in config.php for details.";
        }

        // Only try to set status code if headers haven't been sent yet.
        if (!headers_sent()) {
            http_response_code(500);
        }

        die($msg);
    }

    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg_path = __DIR__ . '/config.php';
    if (!file_exists($cfg_path)) {
        http_response_code(500);
        die("Missing config.php — copy config.sample.php to config.php and set your DB credentials.");
    }
    $cfg = require $cfg_path;

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'], $cfg['port'], $cfg['database']
    );
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], $opts);
    } catch (PDOException $e) {
        http_response_code(500);
        if (!empty($cfg['debug'])) {
            die("DB connection failed: " . htmlspecialchars($e->getMessage()));
        }
        die("DB connection failed.");
    }
    return $pdo;
}

/**
 * Returns true if we should use the remote API instead of local DB.
 * Controlled by 'use_remote_api' in config.php.
 */
function should_use_remote_api(): bool {
    static $use_remote = null;
    if ($use_remote !== null) return $use_remote;

    $cfg_path = __DIR__ . '/config.php';
    if (!file_exists($cfg_path)) {
        return false;
    }
    $cfg = require $cfg_path;
    $use_remote = !empty($cfg['use_remote_api']);

    // If remote mode is on but no base URL is set, fall back to local DB with a warning
    if ($use_remote && empty($cfg['remote_api_base'])) {
        error_log('use_remote_api is true but remote_api_base is empty — falling back to local DB.');
        $use_remote = false;
    }

    return $use_remote;
}

// ===================================================================
// User context for per-user features (notes etc.)
// ===================================================================

/**
 * Get the current user for per-user features like notes.
 * Tries to read the current phpBB session directly from the phpBB database
 * (avoids bootstrapping phpBB's Symfony DI container, which is not safe from
 * external PHP files). Falls back to a dev PHP session (demo user).
 * Returns array with 'id', 'name', 'is_guest'.
 * (Renamed from get_current_user to avoid collision with PHP built-in.)
 */
function get_bible_user(): array {
    static $u = null;
    if ($u !== null) return $u;

    $cfg_path = __DIR__ . '/config.php';
    $cfg = file_exists($cfg_path) ? require $cfg_path : [];

    $phpbb_path = _resolve_phpbb_path($cfg);
    if ($phpbb_path !== '') {
        // Resolve relative paths against the web/ directory.
        if ($phpbb_path[0] !== '/' && !(strlen($phpbb_path) > 1 && $phpbb_path[1] === ':')) {
            $phpbb_path = __DIR__ . '/' . $phpbb_path;
        }
        $resolved = realpath($phpbb_path);
        $phpbb_path = ($resolved !== false ? $resolved : $phpbb_path);
        $phpbb_path = rtrim($phpbb_path, '/\\') . '/';
    }

    if ($phpbb_path && file_exists($phpbb_path . 'config.php')) {
        $phpbb_user = _phpbb_user_from_session($phpbb_path);
        if ($phpbb_user !== null) {
            $u = $phpbb_user;
            return $u;
        }
    }

    // Standalone / dev fallback: use PHP session so notes work without phpBB.
    _start_session_safe();
    // On production without working phpBB auth, treat everyone as a guest.
    $is_local_dev = !file_exists(__DIR__ . '/../include/bwHeader.inc');

    // Dev-only demo identities for local testing (can be switched with ?demo_user=<key>).
    // Example config:
    // 'dev_demo_users' => [
    //   'a' => ['id' => 999999, 'name' => 'Demo User A', 'is_admin' => true],
    //   'b' => ['id' => 999998, 'name' => 'Demo User B', 'is_admin' => false],
    // ],
    // 'dev_demo_user_default' => 'a',
    $demo_users = $cfg['dev_demo_users'] ?? [];
    if (!is_array($demo_users) || empty($demo_users)) {
        $demo_users = [
            'default' => ['id' => 999999, 'name' => 'Demo User (local dev)', 'is_admin' => false],
        ];
    }

    $selected_key = '';
    if ($is_local_dev && isset($_GET['demo_user'])) {
        $requested = trim((string)$_GET['demo_user']);
        if ($requested !== '' && isset($demo_users[$requested]) && is_array($demo_users[$requested])) {
            $selected_key = $requested;
        }
    }

    if ($selected_key === '' && $is_local_dev && !empty($_SESSION['bible_notes_demo_key'])) {
        $session_key = (string)$_SESSION['bible_notes_demo_key'];
        if (isset($demo_users[$session_key]) && is_array($demo_users[$session_key])) {
            $selected_key = $session_key;
        }
    }

    if ($selected_key === '') {
        $selected_key = (string)($cfg['dev_demo_user_default'] ?? '');
        if ($selected_key === '' || !isset($demo_users[$selected_key]) || !is_array($demo_users[$selected_key])) {
            $keys = array_keys($demo_users);
            $selected_key = (string)$keys[0];
        }
    }

    $session_key_before = (string)($_SESSION['bible_notes_demo_key'] ?? '');
    if ($is_local_dev && ($selected_key !== '' || empty($_SESSION['bible_notes_user_id']))
        && ($session_key_before !== $selected_key || empty($_SESSION['bible_notes_user_id']))) {
        $du = $demo_users[$selected_key] ?? null;
        if (is_array($du)) {
            $_SESSION['bible_notes_user_id'] = (int)($du['id'] ?? 999999);
            $_SESSION['bible_notes_username'] = (string)($du['name'] ?? 'Demo User (local dev)');
            $_SESSION['bible_notes_is_admin'] = !empty($du['is_admin']) ? 1 : 0;
            $_SESSION['bible_notes_demo_key'] = $selected_key;
        }
    }

    if (empty($_SESSION['bible_notes_user_id'])) {
        $_SESSION['bible_notes_user_id'] = 999999;
        $_SESSION['bible_notes_username'] = 'Demo User (local dev)';
        $_SESSION['bible_notes_is_admin'] = 0;
    }

    $u = [
        'id'       => (int)$_SESSION['bible_notes_user_id'],
        'name'     => $_SESSION['bible_notes_username'],
        'is_guest' => !$is_local_dev,
        'is_admin' => !empty($_SESSION['bible_notes_is_admin']),
    ];
    return $u;
}

/**
 * Resolve phpBB filesystem path from config, with production-safe fallbacks.
 * This keeps auth working when phpbb_path was accidentally omitted from config.php.
 */
function _resolve_phpbb_path(array $cfg): string {
    $configured = trim((string)($cfg['phpbb_path'] ?? ''));
    $candidates = [];

    if ($configured !== '') {
        $candidates[] = $configured;
    }

    // Derive candidate from phpbb_url + DOCUMENT_ROOT, e.g. /phpBB/ -> <docroot>/phpBB
    $phpbb_url = trim((string)($cfg['phpbb_url'] ?? ''));
    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($phpbb_url !== '' && $doc_root !== '') {
        $url_path = parse_url($phpbb_url, PHP_URL_PATH);
        if (is_string($url_path) && $url_path !== '') {
            $candidates[] = $doc_root . '/' . trim($url_path, '/\\');
        }
    }

    // Common production and local conventions.
    if ($doc_root !== '') {
        $candidates[] = $doc_root . '/phpBB';
        $candidates[] = $doc_root . '/phpbb';
    }
    $candidates[] = __DIR__ . '/../phpBB';
    $candidates[] = __DIR__ . '/../phpbb';

    foreach ($candidates as $candidate) {
        $candidate = rtrim((string)$candidate, '/\\');
        if ($candidate === '') continue;

        // Resolve relative candidates against web/.
        if ($candidate[0] !== '/' && !(strlen($candidate) > 1 && $candidate[1] === ':')) {
            $candidate = __DIR__ . '/' . ltrim($candidate, '/\\');
        }

        $resolved = realpath($candidate);
        if ($resolved === false) {
            $resolved = $candidate;
        }
        $resolved = rtrim($resolved, '/\\');
        if ($resolved !== '' && file_exists($resolved . '/config.php')) {
            return $resolved . '/';
        }
    }

    return '';
}

/**
 * Read the logged-in phpBB user directly from the phpBB database —
 * no common.php, no Symfony DI container, no chdir().
 * Parses phpBB's config.php for DB credentials and table prefix,
 * finds the session cookie, then queries phpbb_sessions + phpbb_users.
 * Returns null if the user is a guest or the session cannot be found.
 */
function _phpbb_user_from_session(string $phpbb_path): ?array {
    // Parse phpBB's config.php with regex so we never execute it in our scope.
    $src = @file_get_contents($phpbb_path . 'config.php');
    if ($src === false) return null;
    $get = function(string $var) use ($src): string {
        return preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)
            ? $m[1] : '';
    };
    $table_prefix = $get('table_prefix') ?: 'phpbb_';
    $dbhost   = $get('dbhost')   ?: '127.0.0.1';
    $dbname   = $get('dbname');
    $dbuser   = $get('dbuser');
    $dbpasswd = $get('dbpasswd');
    $dbport   = (int)($get('dbport') ?: 3306);
    if (!$dbname || !$dbuser) return null;

    // Find phpBB session cookie: phpBB names it "<cookie_name>_sid"
    // where cookie_name is a short string. We detect it by scanning for
    // cookies whose value is a 32-char hex string and name ends in '_sid'.
    $sid = null;
    foreach ($_COOKIE as $cname => $cval) {
        $cval = trim((string)$cval);
        if (substr($cname, -4) === '_sid' && preg_match('/^[0-9a-f]{32}$/i', $cval)) {
            $sid = $cval;
            break;
        }
    }
    // All-zeros SID = not logged in
    if (!$sid || $sid === str_repeat('0', 32)) return null;

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbhost, $dbport, $dbname);
        $pdo = new PDO($dsn, $dbuser, $dbpasswd, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $tbl_s = $table_prefix . 'sessions';
        $tbl_u = $table_prefix . 'users';
        $stmt = $pdo->prepare(
            "SELECT s.session_user_id, u.username
               FROM `{$tbl_s}` s
               JOIN `{$tbl_u}` u ON u.user_id = s.session_user_id
              WHERE s.session_id = ?
              LIMIT 1"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $uid = (int)$row['session_user_id'];
        if ($uid <= 1) return null; // phpBB ANONYMOUS
        // Check phpBB Administrators group membership.
        $tbl_ug = $table_prefix . 'user_group';
        $tbl_g  = $table_prefix . 'groups';
        $astmt  = $pdo->prepare(
            "SELECT COUNT(*) FROM `{$tbl_ug}` ug
             INNER JOIN `{$tbl_g}` g ON g.group_id = ug.group_id
             WHERE ug.user_id = ? AND g.group_name = 'ADMINISTRATORS' AND ug.user_pending = 0"
        );
        $astmt->execute([$uid]);
        $is_admin = ((int)$astmt->fetchColumn()) > 0;
        return [
            'id'       => $uid,
            'name'     => $row['username'],
            'is_guest' => false,
            'is_admin' => $is_admin,
        ];
    } catch (\Exception $e) {
        // DB error or session not found — fall through to dev fallback
        return null;
    }
}

// ===================================================================
// Per-user notes (local DB only - requires local mode + user context)
// ===================================================================

/**
 * Fetch the current user's notes text (raw BBCode).
 * Returns empty string if none or if remote mode.
 */
function get_user_notes(): string {
    if (should_use_remote_api()) {
        return '';
    }
    $u = get_bible_user();
    if ($u['is_guest']) {
        return '';
    }
    try {
        $pdo = bible_pdo();
        $stmt = $pdo->prepare("SELECT notes FROM user_notes WHERE user_id = ? LIMIT 1");
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
        return $row ? (string)$row['notes'] : '';
    } catch (Throwable $e) {
        error_log('get_user_notes error: ' . $e->getMessage());
        return '';
    }
}

/**
 * Save/replace the current user's notes text.
 * Returns true on success.
 */
function save_user_notes(string $notes): bool {
    if (should_use_remote_api()) {
        return false;
    }
    $u = get_bible_user();
    if ($u['is_guest']) {
        return false;
    }
    try {
        $pdo = bible_pdo();
        $stmt = $pdo->prepare("
            INSERT INTO user_notes (user_id, notes) VALUES (?, ?)
            AS new ON DUPLICATE KEY UPDATE notes = new.notes, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$u['id'], $notes]);
        return true;
    } catch (Throwable $e) {
        error_log('save_user_notes error: ' . $e->getMessage());
        return false;
    }
}

// ===================================================================
// Verse notes (collaborative commentary per verse, local DB only)
// ===================================================================

function set_note_last_error(string $msg): void {
    $GLOBALS['bible_note_last_error'] = $msg;
}

function get_note_last_error(): string {
    return (string)($GLOBALS['bible_note_last_error'] ?? '');
}

function _note_db_error_reason(Throwable $e): string {
    $msg = (string)$e->getMessage();
    if (stripos($msg, 'unknown column') !== false || stripos($msg, 'doesn\'t exist') !== false
        || stripos($msg, 'no such table') !== false) {
        return 'notes schema is outdated on this database (run scripts/update_schema.py)';
    }
    if (stripos($msg, 'command denied') !== false && stripos($msg, 'verse_notes') !== false) {
        return 'database user lacks write privileges for notes; install notes stored procedures and grant EXECUTE';
    }
    if (stripos($msg, 'data too long') !== false) {
        return 'note data exceeds one of the database column limits';
    }
    return $msg !== '' ? $msg : 'database error';
}

function _note_proc_exists(string $proc_name): bool {
    static $cache = [];
    if (array_key_exists($proc_name, $cache)) {
        return $cache[$proc_name];
    }
    try {
        $pdo = bible_pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM information_schema.ROUTINES
              WHERE ROUTINE_SCHEMA = DATABASE()
                AND ROUTINE_TYPE = 'PROCEDURE'
                AND ROUTINE_NAME = ?"
        );
        $stmt->execute([$proc_name]);
        $cache[$proc_name] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$proc_name] = false;
    }
    return $cache[$proc_name];
}

function _note_column_exists(string $column_name): bool {
    static $cache = [];
    if (array_key_exists($column_name, $cache)) {
        return $cache[$column_name];
    }
    try {
        $pdo = bible_pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'verse_notes'
                AND COLUMN_NAME = ?"
        );
        $stmt->execute([$column_name]);
        $cache[$column_name] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$column_name] = false;
    }
    return $cache[$column_name];
}

function _note_proc_call(string $proc_name, array $params): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $proc_name)) {
        return ['ok' => false, 'error' => 'invalid procedure name'];
    }
    try {
        $pdo = bible_pdo();
        $marks = implode(',', array_fill(0, count($params), '?'));
        $sql = "CALL {$proc_name}({$marks})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
            // consume extra result sets to keep connection state clean
        }
        $stmt->closeCursor();
        $ok = !empty($row['ok']);
        $err = isset($row['error']) ? trim((string)$row['error']) : '';
        return ['ok' => $ok, 'error' => $err, 'row' => $row];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => _note_db_error_reason($e)];
    }
}

/**
 * Fetch all notes for a specific verse (public, any user).
 * Returns rows with id, user_id, username, title, note_text,
 * gem_std/ord/red, created_at, updated_at, plus:
 *   types     — array of type name strings, e.g. ['General','Gematria']
 *   type_ids  — array of type id ints
 * Ordered by created_at ASC.
 */
function get_verse_notes(string $book_code, int $chapter, int $verse, array $user = []): array {
    // Visibility rules:
    //   guest             → public notes only (is_public = 1)
    //   logged-in user    → own notes (any) + public notes from others
    //   admin             → all notes
    if (should_use_remote_api()) {
        $data = remote_verse_notes($book_code, $chapter, $verse, $user);
        if (is_array($data)) {
            return $data;
        }
        return [];
    }
    try {
        $pdo = bible_pdo();
        $is_admin = !empty($user['is_admin']);
        $is_guest = empty($user['id']) || !empty($user['is_guest']);
        $uid = (int)($user['id'] ?? 0);

        $has_selected_words = _note_column_exists('selected_words');
        $sel_col = $has_selected_words ? 'vn.selected_words,' : "'' AS selected_words,";
        $has_edition_code = _note_column_exists('edition_code');
        $ed_col = $has_edition_code ? 'vn.edition_code,' : "'' AS edition_code,";
         $sql = "
             SELECT vn.id, vn.user_id, vn.username, vn.title, vn.note_text, vn.is_public,
                 {$sel_col}
                   {$ed_col}
                 vn.gem_std, vn.gem_ord, vn.gem_red, vn.created_at, vn.updated_at,
                   GROUP_CONCAT(nt.name  ORDER BY nt.id SEPARATOR ',') AS types_csv,
                   GROUP_CONCAT(nt.id    ORDER BY nt.id SEPARATOR ',') AS type_ids_csv
            FROM verse_notes vn
            LEFT JOIN verse_note_types vnt ON vnt.note_id = vn.id
            LEFT JOIN note_type nt ON nt.id = vnt.type_id
            WHERE vn.book_code = ? AND vn.chapter = ? AND vn.verse = ?
        ";
        $params = [$book_code, $chapter, $verse];

        if ($is_admin) {
            // Admins see everything — no extra filter.
        } elseif ($is_guest) {
            $sql .= ' AND vn.is_public = 1';
        } else {
            // Logged-in non-admin: own notes OR public notes from others.
            $sql .= ' AND (vn.user_id = ? OR vn.is_public = 1)';
            $params[] = $uid;
        }
        $sql .= ' GROUP BY vn.id ORDER BY vn.created_at ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['types']     = $row['types_csv']    ? explode(',', $row['types_csv'])    : ['General'];
            $row['type_ids']  = $row['type_ids_csv'] ? array_map('intval', explode(',', $row['type_ids_csv'])) : [1];
            $row['is_public'] = (int)$row['is_public'];
            unset($row['types_csv'], $row['type_ids_csv']);
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        error_log('get_verse_notes error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Create a new note for a verse.
 * $user must come from get_bible_user().
 * $type_ids is an array of note_type.id values (e.g. [1, 4] for General + Gematria).
 * Defaults to [1] (General) if empty.
 * Title is required (non-empty) to let users distinguish notes for the same verse.
 */
function create_verse_note(array $user, string $book_code, int $chapter, int $verse,
                           array $type_ids, string $title, string $note_text,
                           ?int $gem_std = null, ?int $gem_ord = null, ?int $gem_red = null,
                           int $is_public = 0, ?string $selected_words = null, ?string $edition_code = null): bool {
    set_note_last_error('');
    if (should_use_remote_api()) {
        set_note_last_error('local note writes are disabled while use_remote_api is true');
        return false;
    }
    if ($user['is_guest']) {
        set_note_last_error('login required');
        return false;
    }
    // Non-admins are always private.
    if (empty($user['is_admin'])) $is_public = 0;
    // Sanitize type_ids: keep only positive ints; default to General (1) if none.
    $type_ids = array_values(array_unique(array_filter(array_map('intval', $type_ids))));
    if (empty($type_ids)) $type_ids = [1];
    if (trim($title) === '') {
        set_note_last_error('title is required');
        return false; // title required
    }
    if (strlen($title) > 255 || strlen($note_text) > 65535) {
        set_note_last_error('title or note text exceeds allowed size');
        return false;
    }
    $selected_words = is_string($selected_words) ? trim($selected_words) : null;
    if ($selected_words === '') $selected_words = null;
    $edition_code = is_string($edition_code) ? trim($edition_code) : null;
    if ($edition_code === '') $edition_code = null;

    $type_ids_csv = implode(',', $type_ids);
    if (_note_proc_exists('sp_create_verse_note')) {
        $resp = _note_proc_call('sp_create_verse_note', [
            (int)$user['id'], (string)$user['name'], $book_code, $chapter, $verse,
            $title, $note_text, $is_public ? 1 : 0,
            $gem_std, $gem_ord, $gem_red,
            $selected_words,
            $edition_code,
            $type_ids_csv,
        ]);
        if (empty($resp['ok']) && stripos((string)($resp['error'] ?? ''), 'incorrect number of arguments') !== false) {
            $resp = _note_proc_call('sp_create_verse_note', [
                (int)$user['id'], (string)$user['name'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public ? 1 : 0,
                $gem_std, $gem_ord, $gem_red,
                $selected_words,
                $type_ids_csv,
            ]);
        }
        if (empty($resp['ok']) && stripos((string)($resp['error'] ?? ''), 'incorrect number of arguments') !== false) {
            // Backward compatibility with older procedure signature.
            $resp = _note_proc_call('sp_create_verse_note', [
                (int)$user['id'], (string)$user['name'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public ? 1 : 0,
                $gem_std, $gem_ord, $gem_red,
                $type_ids_csv,
            ]);
        }
        if (!empty($resp['ok'])) {
            return true;
        }
        set_note_last_error($resp['error'] ?: 'database error');
        return false;
    }

    $pdo = null;
    try {
        $pdo = bible_pdo();
        $pdo->beginTransaction();
        if (_note_column_exists('selected_words') && _note_column_exists('edition_code')) {
            $stmt = $pdo->prepare("
                INSERT INTO verse_notes
                    (user_id, username, book_code, chapter, verse, title, note_text, is_public,
                     gem_std, gem_ord, gem_red, selected_words, edition_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'], $user['name'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red,
                $selected_words, $edition_code
            ]);
        } elseif (_note_column_exists('selected_words')) {
            $stmt = $pdo->prepare("
                INSERT INTO verse_notes
                    (user_id, username, book_code, chapter, verse, title, note_text, is_public,
                     gem_std, gem_ord, gem_red, selected_words)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'], $user['name'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red,
                $selected_words
            ]);
        } elseif (_note_column_exists('edition_code')) {
            $stmt = $pdo->prepare("
                INSERT INTO verse_notes
                    (user_id, username, book_code, chapter, verse, title, note_text, is_public,
                     gem_std, gem_ord, gem_red, edition_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'], $user['name'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red,
                $edition_code
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO verse_notes
                    (user_id, username, book_code, chapter, verse, title, note_text, is_public,
                     gem_std, gem_ord, gem_red)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'], $user['name'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red
            ]);
        }
        $note_id = (int)$pdo->lastInsertId();
        $junc = $pdo->prepare('INSERT IGNORE INTO verse_note_types (note_id, type_id) VALUES (?, ?)');
        foreach ($type_ids as $tid) {
            $junc->execute([$note_id, $tid]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $re) {}
        error_log('create_verse_note error: ' . $e->getMessage());
        set_note_last_error(_note_db_error_reason($e));
        return false;
    }
}

/**
 * Update an existing note (only by owner).
 * $type_ids replaces all existing type tags for this note.
 */
function update_verse_note(int $note_id, array $user, string $book_code, int $chapter, int $verse,
                           array $type_ids, string $title, string $note_text,
                           ?int $gem_std = null, ?int $gem_ord = null, ?int $gem_red = null,
                           int $is_public = 0, ?string $selected_words = null, ?string $edition_code = null): bool {
    set_note_last_error('');
    if (should_use_remote_api()) {
        set_note_last_error('local note writes are disabled while use_remote_api is true');
        return false;
    }
    if ($user['is_guest']) {
        set_note_last_error('login required');
        return false;
    }
    // Non-admins cannot change visibility.
    if (empty($user['is_admin'])) $is_public = 0;
    $type_ids = array_values(array_unique(array_filter(array_map('intval', $type_ids))));
    if (empty($type_ids)) $type_ids = [1];
    if (empty($title)) {
        set_note_last_error('title is required');
        return false; // title required
    }
    if (strlen($title) > 255 || strlen($note_text) > 65535) {
        set_note_last_error('title or note text exceeds allowed size');
        return false;
    }
    $selected_words = is_string($selected_words) ? trim($selected_words) : null;
    if ($selected_words === '') $selected_words = null;
    $edition_code = is_string($edition_code) ? trim($edition_code) : null;
    if ($edition_code === '') $edition_code = null;

    $type_ids_csv = implode(',', $type_ids);
    if (_note_proc_exists('sp_update_verse_note')) {
        $resp = _note_proc_call('sp_update_verse_note', [
            $note_id, (int)$user['id'], $book_code, $chapter, $verse,
            $title, $note_text, $is_public ? 1 : 0,
            $gem_std, $gem_ord, $gem_red,
            $selected_words,
            $edition_code,
            $type_ids_csv,
        ]);
        if (empty($resp['ok']) && stripos((string)($resp['error'] ?? ''), 'incorrect number of arguments') !== false) {
            $resp = _note_proc_call('sp_update_verse_note', [
                $note_id, (int)$user['id'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public ? 1 : 0,
                $gem_std, $gem_ord, $gem_red,
                $selected_words,
                $type_ids_csv,
            ]);
        }
        if (empty($resp['ok']) && stripos((string)($resp['error'] ?? ''), 'incorrect number of arguments') !== false) {
            // Backward compatibility with older procedure signature.
            $resp = _note_proc_call('sp_update_verse_note', [
                $note_id, (int)$user['id'], $book_code, $chapter, $verse,
                $title, $note_text, $is_public ? 1 : 0,
                $gem_std, $gem_ord, $gem_red,
                $type_ids_csv,
            ]);
        }
        if (!empty($resp['ok'])) {
            return true;
        }
        set_note_last_error($resp['error'] ?: 'database error');
        return false;
    }

    $pdo = null;
    try {
        $pdo = bible_pdo();
        $pdo->beginTransaction();
        if (_note_column_exists('selected_words') && _note_column_exists('edition_code')) {
            $stmt = $pdo->prepare("
                UPDATE verse_notes
                SET title = ?, note_text = ?, is_public = ?,
                    gem_std = ?, gem_ord = ?, gem_red = ?, selected_words = ?, edition_code = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red, $selected_words, $edition_code,
                $note_id, $user['id']
            ]);
        } elseif (_note_column_exists('selected_words')) {
            $stmt = $pdo->prepare("
                UPDATE verse_notes
                SET title = ?, note_text = ?, is_public = ?,
                    gem_std = ?, gem_ord = ?, gem_red = ?, selected_words = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red, $selected_words,
                $note_id, $user['id']
            ]);
        } elseif (_note_column_exists('edition_code')) {
            $stmt = $pdo->prepare("
                UPDATE verse_notes
                SET title = ?, note_text = ?, is_public = ?,
                    gem_std = ?, gem_ord = ?, gem_red = ?, edition_code = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red, $edition_code,
                $note_id, $user['id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE verse_notes
                SET title = ?, note_text = ?, is_public = ?,
                    gem_std = ?, gem_ord = ?, gem_red = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $title, $note_text, $is_public,
                $gem_std, $gem_ord, $gem_red,
                $note_id, $user['id']
            ]);
        }
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            set_note_last_error('note not found or you are not the owner');
            return false; // not owner or note doesn't exist
        }
        // Replace all type tags atomically.
        $pdo->prepare('DELETE FROM verse_note_types WHERE note_id = ?')->execute([$note_id]);
        $junc = $pdo->prepare('INSERT INTO verse_note_types (note_id, type_id) VALUES (?, ?)');
        foreach ($type_ids as $tid) {
            $junc->execute([$note_id, $tid]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $re) {}
        error_log('update_verse_note error: ' . $e->getMessage());
        set_note_last_error(_note_db_error_reason($e));
        return false;
    }
}

/**
 * Delete a verse note (only by owner).
 */
function delete_verse_note(int $note_id, array $user): bool {
    set_note_last_error('');
    if (should_use_remote_api()) {
        set_note_last_error('local note writes are disabled while use_remote_api is true');
        return false;
    }
    if ($user['is_guest']) {
        set_note_last_error('login required');
        return false;
    }

    if (_note_proc_exists('sp_delete_verse_note')) {
        $resp = _note_proc_call('sp_delete_verse_note', [
            $note_id,
            (int)$user['id'],
            !empty($user['is_admin']) ? 1 : 0,
        ]);
        if (!empty($resp['ok'])) {
            return true;
        }
        set_note_last_error($resp['error'] ?: 'database error');
        return false;
    }

    try {
        $pdo = bible_pdo();
        if (!empty($user['is_admin'])) {
            // Admins can delete any note.
            $stmt = $pdo->prepare('DELETE FROM verse_notes WHERE id = ?');
            $stmt->execute([$note_id]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM verse_notes WHERE id = ? AND user_id = ?');
            $stmt->execute([$note_id, $user['id']]);
        }
        $ok = $stmt->rowCount() > 0;
        if (!$ok) {
            set_note_last_error(!empty($user['is_admin']) ? 'note not found' : 'note not found or you are not the owner');
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('delete_verse_note error: ' . $e->getMessage());
        set_note_last_error(_note_db_error_reason($e));
        return false;
    }
}

/**
 * Admin-only visibility toggle for a verse note.
 * Allows admins to publish or unpublish notes they do not own.
 */
function set_verse_note_visibility(int $note_id, array $user, int $is_public): bool {
    set_note_last_error('');
    if (should_use_remote_api()) {
        set_note_last_error('local note writes are disabled while use_remote_api is true');
        return false;
    }
    if ($note_id <= 0 || empty($user['is_admin'])) {
        set_note_last_error('admin required');
        return false;
    }

    if (_note_proc_exists('sp_set_verse_note_visibility')) {
        $resp = _note_proc_call('sp_set_verse_note_visibility', [
            $note_id,
            $is_public ? 1 : 0,
        ]);
        if (!empty($resp['ok'])) {
            return true;
        }
        set_note_last_error($resp['error'] ?: 'database error');
        return false;
    }

    try {
        $pdo = bible_pdo();
        $stmt = $pdo->prepare(
            'UPDATE verse_notes SET is_public = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$is_public ? 1 : 0, $note_id]);
        $ok = $stmt->rowCount() > 0;
        if (!$ok) {
            set_note_last_error('note not found');
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('set_verse_note_visibility error: ' . $e->getMessage());
        set_note_last_error(_note_db_error_reason($e));
        return false;
    }
}

/**
 * Fetch verse notes for notes.php.
 * - Non-admins: own notes only.
 * - Admins: all notes, optional author filter and sort mode.
 *
 * $options:
 *   - author_user_id: int|null (admin only)
 *   - sort: 'recent'|'oldest'|'verse'
 */
function get_user_verse_notes(array $user, array $options = []): array {
    if (should_use_remote_api()) return [];
    if (!empty($user['is_guest']) || empty($user['id'])) return [];
    try {
        $pdo = bible_pdo();
        $is_admin = !empty($user['is_admin']);
        $author_user_id = isset($options['author_user_id']) ? (int)$options['author_user_id'] : 0;
        $sort = strtolower((string)($options['sort'] ?? ''));
        if (!in_array($sort, ['recent', 'oldest', 'verse'], true)) {
            $sort = $is_admin ? 'recent' : 'verse';
        }

         $has_selected_words = _note_column_exists('selected_words');
         $sel_col = $has_selected_words ? 'vn.selected_words,' : "'' AS selected_words,";
         $has_edition_code = _note_column_exists('edition_code');
         $ed_col = $has_edition_code ? 'vn.edition_code,' : "'' AS edition_code,";
         $sql = "
             SELECT vn.id, vn.user_id, vn.username, vn.title, vn.note_text, vn.is_public,
                   vn.book_code, vn.chapter, vn.verse,
                 {$sel_col}
                 {$ed_col}
                   vn.gem_std, vn.gem_ord, vn.gem_red, vn.created_at, vn.updated_at,
                   GROUP_CONCAT(nt.name ORDER BY nt.id SEPARATOR ', ') AS types_label
            FROM verse_notes vn
            LEFT JOIN book b ON b.osis_code = vn.book_code
            LEFT JOIN verse_note_types vnt ON vnt.note_id = vn.id
            LEFT JOIN note_type nt ON nt.id = vnt.type_id
        ";
        $params = [];
        if (!$is_admin) {
            $sql .= ' WHERE vn.user_id = ?';
            $params[] = (int)$user['id'];
        } elseif ($author_user_id > 0) {
            $sql .= ' WHERE vn.user_id = ?';
            $params[] = $author_user_id;
        }

        $order_sql = 'COALESCE(b.book_order, 9999), vn.book_code, vn.chapter, vn.verse, vn.created_at ASC';
        if ($sort === 'recent') {
            $order_sql = 'vn.created_at DESC, vn.id DESC';
        } elseif ($sort === 'oldest') {
            $order_sql = 'vn.created_at ASC, vn.id ASC';
        }
        $sql .= ' GROUP BY vn.id ORDER BY ' . $order_sql;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['is_public'] = (int)$row['is_public'];
            $row['types_label'] = $row['types_label'] ?: 'General';
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        error_log('get_user_verse_notes error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Admin helper for notes.php filters.
 * Returns distinct authors with note counts.
 */
function get_note_authors(): array {
    if (should_use_remote_api()) return [];
    try {
        $pdo = bible_pdo();
        $stmt = $pdo->query(
            "SELECT user_id, username, COUNT(*) AS note_count
               FROM verse_notes
              GROUP BY user_id, username
              ORDER BY username ASC"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['user_id'] = (int)$r['user_id'];
            $r['note_count'] = (int)$r['note_count'];
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        error_log('get_note_authors error: ' . $e->getMessage());
        return [];
    }
}

// ===================================================================
// MT / NT query helpers (existing 11-table v2 schema)
// ===================================================================

function bible_books(): array {
    if (should_use_remote_api()) {
        return remote_api_call('books') ?? [];
    }

    $stmt = bible_pdo()->query(
        "SELECT id, osis_code, name, testament, language
           FROM book
          ORDER BY book_order"
    );
    return $stmt->fetchAll();
}

function bible_chapters(string $osis_code): array {
    if (should_use_remote_api()) {
        return remote_bible_chapters($osis_code);
    }

    $sql = "SELECT DISTINCT v.chapter
              FROM verse v JOIN book b ON b.id = v.book_id
             WHERE b.osis_code = ?
             ORDER BY v.chapter";
    $stmt = bible_pdo()->prepare($sql);
    $stmt->execute([$osis_code]);
    return array_column($stmt->fetchAll(), 'chapter');
}

function bible_verses(string $osis_code, int $chapter): array {
    if (should_use_remote_api()) {
        return remote_bible_verses($osis_code, $chapter);
    }

    $sql = "SELECT v.verse
              FROM verse v JOIN book b ON b.id = v.book_id
             WHERE b.osis_code = ? AND v.chapter = ?
             ORDER BY v.verse";
    $stmt = bible_pdo()->prepare($sql);
    $stmt->execute([$osis_code, $chapter]);
    return array_column($stmt->fetchAll(), 'verse');
}

// Look up edition.id from a code like 'NA28' (or null). Cached.
function bible_edition_id(?string $code): ?int {
    if (should_use_remote_api()) {
        // Not needed in remote mode for most paths
        return null;
    }

    static $cache = null;
    if ($code === null || $code === '') return null;
    if ($cache === null) {
        $cache = [];
        $stmt = bible_pdo()->query("SELECT id, code FROM edition");
        foreach ($stmt->fetchAll() as $r) $cache[$r['code']] = (int)$r['id'];
    }
    return $cache[$code] ?? null;
}

function bible_ews_has_transliteration(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'edition_word_slot'
            AND COLUMN_NAME = 'transliteration'"
    );
    $stmt->execute();
    $cached = ((int)$stmt->fetchColumn()) > 0;
    return $cached;
}

// Normalize Greek for variant-equivalence checks:
// strip combining diacritics except U+0345 (iota subscript), lowercase,
// and collapse whitespace.
function bible_normalize_greek_variant_text(?string $text): string {
    $t = trim((string)($text ?? ''));
    if ($t === '') return '';
    if (function_exists('normalizer_normalize')) {
        $t = normalizer_normalize($t, Normalizer::NFD);
    }
    $t = preg_replace('/[\x{0300}-\x{0344}\x{0346}-\x{036F}]/u', '', $t);
    if (function_exists('normalizer_normalize')) {
        $t = normalizer_normalize($t, Normalizer::NFC);
    }
    $t = mb_strtolower($t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim((string)$t);
}

function bible_norm_variant_field(?string $text): string {
    $t = mb_strtolower(trim((string)($text ?? '')));
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim((string)$t);
}

// Greek canonical text embeds the transliteration: "Χριστοῦ (Christou)".
function bible_greek_text_parts(?string $text): array {
    $t = trim((string)($text ?? ''));
    if ($t === '') return ['', ''];
    $p = mb_strpos($t, '(');
    if ($p === false) return [$t, ''];
    $greek    = trim(mb_substr($t, 0, $p));
    $translit = trim(mb_substr($t, $p), "() \t");
    return [$greek, $translit];
}

// Strip sentence punctuation (commas, periods, Greek high/question marks)
// but keep elision apostrophes — used both for match keys and for clean
// display of a word borrowed from a different slot.
function bible_strip_greek_punct(string $t): string {
    return trim((string)preg_replace('/[,\.;:\x{00B7}\x{0387}]+/u', '', $t));
}

// Restore accents/case for normalized slot/variant tokens by matching the
// verse's tagged words. The diff pipeline compares accent-stripped
// lowercase text, so transposed readings (e.g. Rom 1:1 TR "ιησου χριστου")
// land in the DB normalized; the same lexical words exist fully pointed in
// the tagged text of the verse, so we can borrow their display form.
// Returns "Ἰησοῦ (Iēsou)" style text, or null when nothing in the verse
// matches (a genuinely different reading stays as stored).
function bible_accented_form(PDO $pdo, int $verse_id, string $token): ?string {
    static $cache = [];
    if (!isset($cache[$verse_id])) {
        $map = [];
        $stmt = $pdo->prepare(
            'SELECT text_original FROM word WHERE verse_id = ? AND text_original IS NOT NULL'
        );
        $stmt->execute([$verse_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $txt) {
            [$greek, $translit] = bible_greek_text_parts((string)$txt);
            $bare = bible_strip_greek_punct($greek);
            $norm = bible_normalize_greek_variant_text($bare);
            if ($norm === '' || isset($map[$norm])) continue;
            $map[$norm] = $bare . ($translit !== '' ? ' (' . $translit . ')' : '');
        }
        $cache[$verse_id] = $map;
    }
    $norm = bible_normalize_greek_variant_text(bible_strip_greek_punct($token));
    if ($norm === '') return null;
    if (isset($cache[$verse_id][$norm])) return $cache[$verse_id][$norm];

    // Movable-nu: borrow accents from the form that differs only by a
    // final ν (e.g. TR "αρσεσι" from tagged "ἄρσεσιν", or vice versa).
    $donor = $cache[$verse_id][$norm . 'ν'] ?? null;
    if ($donor !== null) {
        [$g, $tl] = bible_greek_text_parts($donor);
        $g  = (string)preg_replace('/ν$/u', '', $g);
        $tl = (string)preg_replace('/n$/u', '', $tl);
        return $g . ($tl !== '' ? ' (' . $tl . ')' : '');
    }
    if (mb_substr($norm, -1) === 'ν') {
        $donor = $cache[$verse_id][mb_substr($norm, 0, mb_strlen($norm) - 1)] ?? null;
        if ($donor !== null) {
            [$g, $tl] = bible_greek_text_parts($donor);
            return $g . 'ν' . ($tl !== '' ? ' (' . $tl . 'n)' : '');
        }
    }

    // Corpus-wide fallback: the tagged corpus itself is the accent lexicon —
    // word.text_search holds the same normalization, so any occurrence of
    // this form anywhere in the NT can donate its accents (e.g. TR-only
    // υιος in Jhn 1:18 borrows from υἱός elsewhere).
    static $corpus = [];
    if (!array_key_exists($norm, $corpus)) {
        $corpus[$norm] = null;
        try {
            $stmt = $pdo->prepare(
                "SELECT text_original FROM word
                  WHERE text_search = ? AND language = 'Greek'
                    AND text_original IS NOT NULL
                  LIMIT 1"
            );
            $stmt->execute([$norm]);
            $txt = $stmt->fetchColumn();
            if (is_string($txt) && $txt !== '') {
                [$g, $tl] = bible_greek_text_parts($txt);
                $g = bible_strip_greek_punct($g);
                if ($g !== '') {
                    $corpus[$norm] = $g . ($tl !== '' ? ' (' . $tl . ')' : '');
                }
            }
        } catch (Throwable $e) { /* column missing — degrade silently */ }
    }
    return $corpus[$norm];
}

function bible_variant_dedupe_key(array $v): string {
    return implode('|', [
        (string)($v['kind'] ?? ''),
        bible_normalize_greek_variant_text($v['text_original'] ?? ''),
        bible_norm_variant_field($v['translation'] ?? ''),
        bible_norm_variant_field($v['strongs'] ?? ''),
        bible_norm_variant_field($v['grammar'] ?? ''),
    ]);
}

function bible_merge_variant_editions(array $a, array $b): array {
    $by_code = [];
    foreach (array_merge($a, $b) as $ed) {
        $code = (string)($ed['code'] ?? '');
        if ($code === '') continue;
        if (!isset($by_code[$code])) $by_code[$code] = $ed;
    }
    return array_values($by_code);
}

// Editions surfaced in the UI dropdown. NA28 / TR are NT critical/Byz texts;
// LXX-Rahlfs is a *mode switch* — selecting it routes lookups to the LXX
// tables (book_lxx / verse_lxx / word_lxx) via the lxx_* helpers below.
function bible_greek_editions(): array {
    if (should_use_remote_api()) {
        // Static list for remote mode (avoids local DB)
        return [
            ['id' => 1, 'code' => 'NA28',        'name' => 'Nestle-Aland 28th'],
            ['id' => 2, 'code' => 'TR',          'name' => 'Textus Receptus'],
            ['id' => 3, 'code' => 'LXX-Rahlfs',  'name' => 'Rahlfs LXX 1935'],
        ];
    }

    $stmt = bible_pdo()->query(
        "SELECT id, code, name FROM edition
          WHERE code IN ('NA28','TR','LXX-Rahlfs')
          ORDER BY edition_order"
    );
    return $stmt->fetchAll();
}

// Look up a Strong's entry by canonical lookup key (e.g. 'H430', 'G851').
// Returns ['number','lemma','xlit','pronounce','description'] or null on miss.
function bible_strongs_lookup(string $code): ?array {
    static $cache = [];
    if ($code === '' || !preg_match('/^[HG]\d+[A-Za-z]?$/', $code)) return null;
    if (array_key_exists($code, $cache)) return $cache[$code];

    if (should_use_remote_api()) {
        $row = remote_api_call('strongs', ['code' => $code]);
        return $cache[$code] = (is_array($row) && !empty($row) ? $row : null);
    }

    $stmt = bible_pdo()->prepare(
        "SELECT number, lemma, xlit, pronounce, description
           FROM strongs WHERE number = ? LIMIT 1"
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $cache[$code] = ($row ?: null);
}

// Look up the clean (tag-stripped) KJV text for one verse. Used by the
// verse-tooltip API in search.php. Returns null on miss or in remote mode
// where the remote API serves the same endpoint.
function bible_kjv_verse_clean(string $osis_code, int $chapter, int $verse): ?string {
    static $cache = [];
    $key = "$osis_code.$chapter.$verse";
    if (array_key_exists($key, $cache)) return $cache[$key];

    if (should_use_remote_api()) {
        $resp = remote_api_call('kjv_verse', [
            'book'    => $osis_code,
            'chapter' => $chapter,
            'verse'   => $verse,
        ]);
        $text = is_array($resp) ? ($resp['text'] ?? null) : null;
        return $cache[$key] = (is_string($text) ? $text : null);
    }

    try {
        $pdo = bible_pdo();
        // Resolve book_id once so we can pass it to both queries and to
        // kjv_alt_ref() (which is keyed by book_id, not osis_code).
        $bk = $pdo->prepare("SELECT id FROM book WHERE osis_code = ? LIMIT 1");
        $bk->execute([$osis_code]);
        $book_id = (int)($bk->fetchColumn() ?: 0);
        if ($book_id === 0) return $cache[$key] = null;

        $stmt = $pdo->prepare(
            'SELECT Verse_Text_Clean FROM bible_kjv
              WHERE Book = ? AND Chapter = ? AND Verse = ? LIMIT 1'
        );
        $stmt->execute([$book_id, $chapter, $verse]);
        $row = $stmt->fetch();
        if ($row) return $cache[$key] = (string)$row['Verse_Text_Clean'];

        // Direct miss — check the NA28→KJV versification remap.
        if (($alt = kjv_alt_ref($book_id, $chapter, $verse)) !== null) {
            $stmt->execute([$book_id, $alt['chapter'], $alt['verse']]);
            $row = $stmt->fetch();
            if ($row) return $cache[$key] = (string)$row['Verse_Text_Clean'];
        }
        return $cache[$key] = null;
    } catch (Throwable $e) {
        return $cache[$key] = null;
    }
}

// Resolve a (book_osis, chapter, verse) into the verse row plus all
// joined per-word data (words, editions, variants, morphemes, links,
// alt strongs). When $edition_code is set and the verse is Greek, the
// returned `words` list is the edition-specific position-sorted merge
// of canonical words (filtered by word_edition) and variants (filtered
// by variant_edition). When $edition_code is null, the canonical word
// list is returned unchanged.
function bible_verse_full(string $osis_code, int $chapter, int $verse,
                          ?string $edition_code = null): ?array {
    if (should_use_remote_api()) {
        return remote_bible_verse_full($osis_code, $chapter, $verse, $edition_code);
    }

    $pdo = bible_pdo();

    $stmt = $pdo->prepare(
        "SELECT v.*, b.osis_code, b.name AS book_name,
                b.testament, b.language
           FROM verse v JOIN book b ON b.id = v.book_id
          WHERE b.osis_code = ? AND v.chapter = ? AND v.verse = ?"
    );
    $stmt->execute([$osis_code, $chapter, $verse]);
    $vrow = $stmt->fetch();
    if (!$vrow) return null;
    $verse_id = (int)$vrow['id'];

    $edition_id = ($vrow['language'] === 'Greek') ? bible_edition_id($edition_code) : null;
    $words = bible_assemble_words($pdo, $verse_id, $edition_id, $edition_code);

    $vrow['has_any_variant_current_edition'] = 0;
    if ($edition_id !== null) {
        $na28_id = bible_edition_id('NA28');
        $tr_id = bible_edition_id('TR');
        $compare_mode = ($na28_id !== null && $tr_id !== null && ($edition_id === $na28_id || $edition_id === $tr_id));
        $variant_count = 0;
        if ($compare_mode) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM variant v
                   JOIN word w ON w.id = v.word_id
                   JOIN variant_edition ve ON ve.variant_id = v.id
                  WHERE w.verse_id = ?
                    AND ve.edition_id IN (?, ?)"
            );
            $stmt->execute([$verse_id, $na28_id, $tr_id]);
            $variant_count = (int)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM variant v
                   JOIN word w ON w.id = v.word_id
                   JOIN variant_edition ve ON ve.variant_id = v.id
                  WHERE w.verse_id = ?
                    AND ve.edition_id = ?"
            );
            $stmt->execute([$verse_id, $edition_id]);
            $variant_count = (int)$stmt->fetchColumn();
        }
        if ($variant_count > 0) {
            $vrow['has_any_variant_current_edition'] = 1;
        } elseif ($compare_mode) {
            // Fallback: surface NA28/TR position mismatches as verse-level
            // variants even when variant table rows are absent.
            $other_id = ($edition_id === $na28_id) ? $tr_id : $na28_id;
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM (
                     SELECT DISTINCT w.position
                       FROM word w
                       JOIN word_edition we ON we.word_id = w.id
                      WHERE w.verse_id = ? AND we.edition_id = ?
                   ) a
                   LEFT JOIN (
                     SELECT DISTINCT w.position
                       FROM word w
                       JOIN word_edition we ON we.word_id = w.id
                      WHERE w.verse_id = ? AND we.edition_id = ?
                   ) b ON b.position = a.position
                  WHERE b.position IS NULL"
            );
            $stmt->execute([$verse_id, $edition_id, $verse_id, $other_id]);
                        $missing_in_other = (int)$stmt->fetchColumn();

                        $stmt = $pdo->prepare(
                                "SELECT COUNT(*)
                                     FROM (
                                         SELECT DISTINCT w.position
                                             FROM word w
                                             JOIN word_edition we ON we.word_id = w.id
                                            WHERE w.verse_id = ? AND we.edition_id = ?
                                     ) a
                                     LEFT JOIN (
                                         SELECT DISTINCT w.position
                                             FROM word w
                                             JOIN word_edition we ON we.word_id = w.id
                                            WHERE w.verse_id = ? AND we.edition_id = ?
                                     ) b ON b.position = a.position
                                    WHERE b.position IS NULL"
                        );
                        $stmt->execute([$verse_id, $other_id, $verse_id, $edition_id]);
                        $missing_in_current = (int)$stmt->fetchColumn();

                        $vrow['has_any_variant_current_edition'] = (($missing_in_other + $missing_in_current) > 0) ? 1 : 0;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM verse_summary WHERE verse_id = ? ORDER BY block_num"
    );
    $stmt->execute([$verse_id]);
    $summaries = $stmt->fetchAll();

    bible_attach_per_word_data($pdo, $words, $vrow['language'], $edition_id);

    return ['verse' => $vrow, 'words' => $words, 'summaries' => $summaries];
}

// Merge canonical words and variant rows by position. For an edition E:
//   - canonical words tagged with E in word_edition are kept
//   - variants tagged with E in variant_edition are merged in:
//       * variant.position == canonical word's position -> variant substitutes
//         (or, for kind='omission', drops the slot entirely)
//       * variant.position has no matching canonical word -> variant fills
//         the slot on its own (e.g. John 1:18 'υἱός' in TR -- canonical
//         θεος isn't tagged for TR, variant is)
//       * fractional positions (e.g. 4.5) -> variant inserts as a new slot
function bible_assemble_words(PDO $pdo, int $verse_id,
                              ?int $edition_id, ?string $edition_code): array {
    if ($edition_id === null) {
        $stmt = $pdo->prepare(
            "SELECT * FROM word WHERE verse_id = ? ORDER BY position"
        );
        $stmt->execute([$verse_id]);
        return $stmt->fetchAll();
    }

    // NA28/TR: render directly from persisted source-of-truth slot map
    // built from bible_na27/bible_scr (edition_verse_text) alignment.
    if ($edition_code === 'NA28' || $edition_code === 'TR') {
        $slot_tl_sql = bible_ews_has_transliteration($pdo)
            ? 'ews.transliteration AS slot_transliteration,'
            : "'' AS slot_transliteration,";
        $stmt = $pdo->prepare(
            "SELECT ews.id AS ews_id,
                    ews.slot_num,
                    ews.position AS slot_position,
                    ews.word_id AS slot_word_id,
                    ews.token_text,
                    {$slot_tl_sql}
                    ews.op_type,
                    w.*
               FROM edition_word_slot ews
               LEFT JOIN word w ON w.id = ews.word_id
              WHERE ews.edition_id = ?
                AND ews.verse_id = ?
              ORDER BY ews.slot_num"
        );
        $stmt->execute([$edition_id, $verse_id]);
        $rows = $stmt->fetchAll();

        // If slot rows are unavailable for this verse/edition (e.g. partial
        // backfill or pre-slot data), fall back to the canonical edition path
        // below instead of returning an empty verse.
        if (!$rows) {
            // fall through
        } else {
            // TAGNT apparatus overrides for 'equal' slots: the slot map is
            // aligned from the bible_na27/bible_scr dumps, but the STEPBible
            // apparatus sometimes collates an edition reading those dumps
            // miss (e.g. Rom 1:27 TR ἄρρενες where BibleWorks SCR has
            // αρσενες). Only accented apparatus rows qualify — the
            // machine-generated diff rows are stored normalized and must
            // never override the slot map.
            $apparatus = [];
            $ovStmt = $pdo->prepare(
                "SELECT v.id AS variant_id, v.word_id, v.text_original,
                        v.transliteration, v.translation, v.strongs, v.grammar
                   FROM variant v
                   JOIN variant_edition ve ON ve.variant_id = v.id
                   JOIN word w2 ON w2.id = v.word_id
                  WHERE w2.verse_id = ?
                    AND ve.edition_id = ?
                    AND v.kind IN ('spelling','meaning')
                    AND v.position = w2.position"
            );
            $ovStmt->execute([$verse_id, $edition_id]);
            foreach ($ovStmt->fetchAll() as $av) {
                $avTxt = trim((string)($av['text_original'] ?? ''));
                if ($avTxt === '') continue;
                if ($avTxt === bible_normalize_greek_variant_text($avTxt)) continue;
                $apparatus[(int)$av['word_id']] = $av;
            }

            $out = [];
            foreach ($rows as $r) {
                $token = (string)($r['token_text'] ?? '');
                $slotTranslit = trim((string)($r['slot_transliteration'] ?? ''));
                $slotPos = isset($r['slot_position']) ? (float)$r['slot_position'] : 0.0;
                $op = (string)($r['op_type'] ?? 'equal');

                if (!empty($r['slot_word_id']) && !empty($r['id'])) {
                    $w = $r;
                    // 'equal' slots: the canonical word IS this edition's
                    // word — keep its accented display untouched. token_text
                    // is accent-stripped lowercase for alignment only;
                    // substituting it here is what made TR render unaccented.
                    if ($op === 'equal' && isset($apparatus[(int)$r['slot_word_id']])) {
                        $av = $apparatus[(int)$r['slot_word_id']];
                        $avNorm  = bible_normalize_greek_variant_text(
                            bible_strip_greek_punct((string)$av['text_original']));
                        $tokNorm = bible_normalize_greek_variant_text(
                            bible_strip_greek_punct($token));
                        if ($avNorm !== '' && $avNorm !== $tokNorm) {
                            // Apparatus says this edition reads differently
                            // here even though the dumps aligned as equal.
                            $w['canonical_text_original']   = $w['text_original'] ?? null;
                            $w['canonical_transliteration'] = $w['transliteration'] ?? null;
                            $w['canonical_translation']     = $w['translation'] ?? null;
                            $w['canonical_strongs']         = $w['strongs'] ?? null;
                            $w['canonical_grammar']         = $w['grammar'] ?? null;
                            $w['text_original'] = (string)$av['text_original'];
                            if (!empty($av['transliteration'])) $w['transliteration'] = $av['transliteration'];
                            if (!empty($av['translation']))     $w['translation']     = $av['translation'];
                            if (!empty($av['strongs']))         $w['strongs']         = $av['strongs'];
                            if (!empty($av['grammar']))         $w['grammar']         = $av['grammar'];
                            $w['source_variant_id'] = (int)$av['variant_id'];
                        }
                    }
                    if ($op !== 'equal' && $token !== '') {
                        $display = bible_accented_form($pdo, $verse_id, $token) ?? $token;
                        if (!isset($w['text_original']) || $display !== (string)$w['text_original']) {
                            $w['canonical_text_original'] = $w['text_original'] ?? null;
                            $w['text_original'] = $display;
                        }
                        if ($slotTranslit !== '') {
                            $w['canonical_transliteration'] = $w['transliteration'] ?? null;
                            $w['transliteration'] = $slotTranslit;
                        }
                    }
                    // Keep the slot position for deterministic ordering when inserts surround words.
                    $w['position'] = $slotPos;
                    $out[] = $w;
                } elseif ($token !== '') {
                    $display = bible_accented_form($pdo, $verse_id, $token) ?? $token;
                    $out[] = bible_slot_token_as_word_row((int)$r['ews_id'], $verse_id, $slotPos, $display, $slotTranslit);
                }
            }
            return $out;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT w.*
           FROM word w
           JOIN word_edition we ON we.word_id = w.id
                              AND we.edition_id = ?
          WHERE w.verse_id = ?"
    );
    $stmt->execute([$edition_id, $verse_id]);
    $canonical = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT v.*
           FROM variant v
           JOIN word w ON w.id = v.word_id
           JOIN variant_edition ve ON ve.variant_id = v.id
                                  AND ve.edition_id = ?
          WHERE w.verse_id = ?
          ORDER BY v.word_id, v.position,
                   CASE v.kind WHEN 'omission' THEN 0 ELSE 1 END,
                   v.id"
    );
    $stmt->execute([$edition_id, $verse_id]);
    $variants = $stmt->fetchAll();

    $slots = [];   // string(position) => ['word' => ..., 'variant' => ...]

    // sprintf('%.2f') normalizes slot keys (word.position is SMALLINT,
    // variant.position is DECIMAL(6,2) — without this they'd collide).
    foreach ($canonical as $w) {
        $key = sprintf('%.2f', (float)$w['position']);
        $slots[$key] = ['word' => $w, 'variant' => null];
    }
    foreach ($variants as $v) {
        $key = sprintf('%.2f', (float)$v['position']);
        if (!isset($slots[$key])) {
            $slots[$key] = ['word' => null, 'variant' => $v];
        } else {
            $slots[$key]['variant'] = $v;
        }
    }

    uksort($slots, fn($a, $b) => (float)$a <=> (float)$b);

    $out = [];
    foreach ($slots as $slot) {
        $w = $slot['word'];
        $v = $slot['variant'];

        if ($v && $v['kind'] === 'omission') continue;

        if ($w && $v) {
            $w['canonical_text_original']   = $w['text_original'];
            $w['canonical_transliteration'] = $w['transliteration'] ?? null;
            $w['canonical_translation']     = $w['translation']     ?? null;
            $w['canonical_strongs']         = $w['strongs']         ?? null;
            $w['canonical_grammar']         = $w['grammar']         ?? null;
            if (!empty($v['text_original'])) {
                $w['text_original'] = bible_accented_form($pdo, $verse_id, (string)$v['text_original'])
                                      ?? $v['text_original'];
            }
            if (!empty($v['transliteration'])) $w['transliteration'] = $v['transliteration'];
            if (!empty($v['translation']))     $w['translation']     = $v['translation'];
            if (!empty($v['strongs']))         $w['strongs']         = $v['strongs'];
            if (!empty($v['grammar']))         $w['grammar']         = $v['grammar'];
            $w['source_variant_id'] = (int)$v['id'];
            $out[] = $w;
        } elseif ($w) {
            $out[] = $w;
        } elseif ($v) {
            if (!empty($v['text_original'])) {
                $v['text_original'] = bible_accented_form($pdo, $verse_id, (string)$v['text_original'])
                                      ?? $v['text_original'];
            }
            $out[] = bible_variant_as_word_row($v, $verse_id, $edition_code);
        }
    }
    return $out;
}

function bible_variant_as_word_row(array $v, int $verse_id, ?string $edition_code): array {
    return [
        'id'                => -((int)$v['id']),
        'verse_id'          => $verse_id,
        'book_id'           => null,
        'chapter'           => 0,
        'verse'             => 0,
        'position'          => $v['position'],
        'word_num'          => 0,
        'chunk_num'         => 1,
        'source_type'       => $v['kind'],
        'is_variant_marked' => 1,
        'language'          => 'Greek',
        'text_original'     => $v['text_original']   ?? '',
        'transliteration'   => $v['transliteration'] ?? '',
        'translation'       => $v['translation']     ?? '',
        'strongs'           => $v['strongs']         ?? '',
        'strongs_primary'   => '',
        'grammar'           => $v['grammar']         ?? '',
        'dictionary_form'   => '',
        'submeaning'        => '',
        'sstrong_instance'  => '',
        'text_search'       => '',
        'source_variant_id' => (int)$v['id'],
    ];
}

function bible_slot_token_as_word_row(int $slot_id, int $verse_id, float $position, string $token, string $transliteration = ''): array {
    return [
        'id'                => -((int)$slot_id),
        'verse_id'          => $verse_id,
        'book_id'           => null,
        'chapter'           => 0,
        'verse'             => 0,
        'position'          => $position,
        'word_num'          => 0,
        'chunk_num'         => 1,
        'source_type'       => 'insert',
        'is_variant_marked' => 1,
        'language'          => 'Greek',
        'text_original'     => $token,
        'transliteration'   => $transliteration,
        'translation'       => '',
        'strongs'           => '',
        'strongs_primary'   => '',
        'grammar'           => '',
        'dictionary_form'   => '',
        'submeaning'        => '',
        'sstrong_instance'  => '',
        'text_search'       => '',
        'source_variant_id' => 0,
    ];
}

function bible_attach_per_word_data(PDO $pdo, array &$words, string $language, ?int $edition_id = null): void {
    if (empty($words)) return;

    $real_ids = [];
    $real_word_pos = [];
    foreach ($words as $w) {
        $wid = (int)$w['id'];
        if ($wid > 0) {
            $real_ids[] = $wid;
            $real_word_pos[$wid] = sprintf('%.2f', (float)($w['position'] ?? 0));
        }
    }

    $eds = $alts = $morphs = $links = $vars = $gem = [];

    $na28_id = bible_edition_id('NA28');
    $tr_id = bible_edition_id('TR');
    $compare_mode = ($edition_id !== null && $na28_id !== null && $tr_id !== null && ($edition_id === $na28_id || $edition_id === $tr_id));
    $other_compare_edition_id = $compare_mode ? (($edition_id === $na28_id) ? $tr_id : $na28_id) : null;
    $other_compare_edition_code = ($other_compare_edition_id === $na28_id) ? 'NA28' : (($other_compare_edition_id === $tr_id) ? 'TR' : null);
    $other_positions_by_verse = [];
    $other_text_by_verse_pos = [];

    if (!empty($real_ids)) {
        $marks = implode(',', array_fill(0, count($real_ids), '?'));

        $stmt = $pdo->prepare("SELECT we.word_id, e.code, e.name, we.is_minor
                                 FROM word_edition we JOIN edition e ON e.id = we.edition_id
                                WHERE we.word_id IN ($marks)
                                ORDER BY e.edition_order");
        $stmt->execute($real_ids);
        foreach ($stmt->fetchAll() as $r) $eds[(int)$r['word_id']][] = $r;

        $stmt = $pdo->prepare("SELECT word_id, alt_strong FROM word_alt_strong
                                WHERE word_id IN ($marks) ORDER BY id");
        $stmt->execute($real_ids);
        foreach ($stmt->fetchAll() as $r) $alts[(int)$r['word_id']][] = $r['alt_strong'];

        $stmt = $pdo->prepare("SELECT * FROM word_morpheme WHERE word_id IN ($marks)
                                ORDER BY word_id, morpheme_num");
        $stmt->execute($real_ids);
        foreach ($stmt->fetchAll() as $r) $morphs[(int)$r['word_id']][] = $r;

        $stmt = $pdo->prepare("SELECT wl.*, tw.text_original AS target_text
                                 FROM word_link wl
                                 LEFT JOIN word tw ON tw.id = wl.target_word_id
                                WHERE wl.word_id IN ($marks)");
        $stmt->execute($real_ids);
        foreach ($stmt->fetchAll() as $r) $links[(int)$r['word_id']][] = $r;

                if ($edition_id !== null) {
                    if ($compare_mode) {
                        $stmt = $pdo->prepare("SELECT DISTINCT v.* FROM variant v
                                                JOIN variant_edition ve ON ve.variant_id = v.id
                                                 WHERE v.word_id IN ($marks)
                                                 AND ve.edition_id IN (?, ?)
                                                 ORDER BY v.word_id, v.position, v.id");
                        $params = $real_ids;
                        $params[] = $na28_id;
                        $params[] = $tr_id;
                        $stmt->execute($params);
                    } else {
                        $stmt = $pdo->prepare("SELECT DISTINCT v.* FROM variant v
                                                JOIN variant_edition ve ON ve.variant_id = v.id
                                                 WHERE v.word_id IN ($marks)
                                                 AND ve.edition_id = ?
                                                 ORDER BY v.word_id, v.position, v.id");
                        $params = $real_ids;
                        $params[] = $edition_id;
                        $stmt->execute($params);
                    }
                } else {
                        $stmt = $pdo->prepare("SELECT DISTINCT v.* FROM variant v
                                                                        JOIN variant_edition ve ON ve.variant_id = v.id
                                                                     WHERE v.word_id IN ($marks)
                                                                         AND ve.edition_id IN (1,2,7,8,11,12)
                                                                     ORDER BY v.word_id, v.position, v.id");
                        $stmt->execute($real_ids);
                }
        foreach ($stmt->fetchAll() as $r) $vars[(int)$r['word_id']][] = $r + ['editions' => []];

        if ($compare_mode && $other_compare_edition_id !== null) {
            $verse_ids = [];
            foreach ($words as $w) {
                $wid = (int)($w['id'] ?? 0);
                if ($wid > 0) $verse_ids[(int)($w['verse_id'] ?? 0)] = true;
            }
            $posStmt = $pdo->prepare(
                "SELECT DISTINCT w.position
                   FROM word w
                   JOIN word_edition we ON we.word_id = w.id
                  WHERE w.verse_id = ?
                    AND we.edition_id = ?"
            );
            $slotTextStmt = $pdo->prepare(
                "SELECT ews.position, ews.token_text
                   FROM edition_word_slot ews
                  WHERE ews.edition_id = ?
                    AND ews.verse_id = ?
                    AND ews.token_text <> ''
                  ORDER BY ews.slot_num"
            );
            $wordTextStmt = $pdo->prepare(
                "SELECT w.position, w.text_original
                   FROM word w
                   JOIN word_edition we ON we.word_id = w.id
                  WHERE w.verse_id = ?
                    AND we.edition_id = ?
                  ORDER BY w.position"
            );
            foreach (array_keys($verse_ids) as $vid) {
                if ($vid <= 0) continue;
                $posStmt->execute([$vid, $other_compare_edition_id]);
                $other_positions_by_verse[$vid] = [];
                foreach ($posStmt->fetchAll() as $pr) {
                    $other_positions_by_verse[$vid][sprintf('%.2f', (float)$pr['position'])] = true;
                }

                $other_text_by_verse_pos[$vid] = [];
                $slotTextStmt->execute([$other_compare_edition_id, $vid]);
                $slotRows = $slotTextStmt->fetchAll();
                if (!empty($slotRows)) {
                    foreach ($slotRows as $sr) {
                        $pkey = sprintf('%.2f', (float)($sr['position'] ?? 0));
                        if (!isset($other_text_by_verse_pos[$vid][$pkey])) {
                            $other_text_by_verse_pos[$vid][$pkey] = (string)($sr['token_text'] ?? '');
                        }
                    }
                } else {
                    $wordTextStmt->execute([$vid, $other_compare_edition_id]);
                    foreach ($wordTextStmt->fetchAll() as $wr) {
                        $pkey = sprintf('%.2f', (float)($wr['position'] ?? 0));
                        if (!isset($other_text_by_verse_pos[$vid][$pkey])) {
                            $other_text_by_verse_pos[$vid][$pkey] = (string)($wr['text_original'] ?? '');
                        }
                    }
                }
            }
        }

        $vids = [];
        foreach ($vars as $vlist) foreach ($vlist as $v) $vids[] = (int)$v['id'];
        if (!empty($vids)) {
            $vmarks = implode(',', array_fill(0, count($vids), '?'));
            $stmt = $pdo->prepare("SELECT ve.variant_id, e.code, e.name, ve.is_minor
                                     FROM variant_edition ve
                                     JOIN edition e ON e.id = ve.edition_id
                                    WHERE ve.variant_id IN ($vmarks)
                                    ORDER BY e.edition_order");
            $stmt->execute($vids);
            $ve_by_var = [];
            foreach ($stmt->fetchAll() as $r) $ve_by_var[(int)$r['variant_id']][] = $r;
            foreach ($vars as &$vlist) foreach ($vlist as &$v) {
                $v['editions'] = $ve_by_var[(int)$v['id']] ?? [];
            }
            unset($vlist, $v);
        }

        try {
            $stmt = $pdo->prepare("SELECT word_id, standard, ordinal, reduced
                                     FROM gematria_word WHERE word_id IN ($marks)");
            $stmt->execute($real_ids);
            foreach ($stmt->fetchAll() as $r) $gem[(int)$r['word_id']] = $r;
        } catch (Throwable $e) { /* table missing -- degrade silently */ }
    }

    foreach ($words as &$w) {
        $wid = (int)$w['id'];
        $is_real = $wid > 0;
        $w['editions']  = $is_real ? ($eds[$wid]   ?? []) : [];
        $w['alts']      = $is_real ? ($alts[$wid]  ?? []) : [];
        $w['morphemes'] = $is_real ? ($morphs[$wid]?? []) : [];
        $w['links']     = $is_real ? ($links[$wid] ?? []) : [];
        if ($is_real) {
            $vlist = $vars[$wid] ?? [];
            $wpos = $real_word_pos[$wid] ?? null;
            if ($wpos !== null) {
                // Only variants at this exact slot mark the word as variant.
                // Anchored additions at fractional positions should not mark
                // the anchor word itself as changed.
                $vlist = array_values(array_filter($vlist, static function ($v) use ($wpos) {
                    return sprintf('%.2f', (float)($v['position'] ?? 0)) === $wpos;
                }));
            }

            // Restore accents/case on normalized variant texts (the diff
            // pipeline stores accent-stripped lowercase) so the variant menu
            // and click-to-switch substitution display real Greek. Must match
            // the same enrichment in the slot/merge render paths, or the
            // active-variant inference below would fail its raw comparison.
            if ($language === 'Greek' && !empty($vlist)) {
                $enrich_vid = (int)($w['verse_id'] ?? 0);
                if ($enrich_vid > 0) {
                    foreach ($vlist as &$ev) {
                        $vt = trim((string)($ev['text_original'] ?? ''));
                        if ($vt === '') continue;
                        $acc = bible_accented_form($pdo, $enrich_vid, $vt);
                        if ($acc !== null) $ev['text_original'] = $acc;
                    }
                    unset($ev);
                }
            }

            // In NA28/TR compare mode we intentionally load both editions' variant
            // rows. Collapse purely diacritic Greek duplicates (e.g. same letters,
            // different accents) so users don't cycle through no-op visual changes.
            // Iota subscript (U+0345) is preserved by the normalizer above.
            if ($language === 'Greek' && !empty($vlist)) {
                $dedup = [];
                foreach ($vlist as $v) {
                    $key = bible_variant_dedupe_key($v);
                    if (!isset($dedup[$key])) {
                        $dedup[$key] = $v;
                    } else {
                        $dedup[$key]['editions'] = bible_merge_variant_editions(
                            $dedup[$key]['editions'] ?? [],
                            $v['editions'] ?? []
                        );
                        if (empty($dedup[$key]['note']) && !empty($v['note'])) {
                            $dedup[$key]['note'] = $v['note'];
                        }
                    }
                }
                $vlist = array_values($dedup);
            }

            if ($compare_mode && $language === 'Greek' && $wpos !== null) {
                $vid = (int)($w['verse_id'] ?? 0);
                $other_tok = $other_text_by_verse_pos[$vid][$wpos] ?? null;
                $cur_tok = (string)($w['text_original'] ?? '');
                if (is_string($other_tok)
                    && bible_normalize_greek_variant_text($other_tok) !== ''
                    && bible_normalize_greek_variant_text($other_tok) === bible_normalize_greek_variant_text($cur_tok)) {
                    // Same token at same slot across NA28/TR: suppress stale
                    // lexical variant rows so non-variant words don't show bars.
                    $vlist = [];
                }
            }

            // In NA28/TR compare mode, if the opposite edition has no word at
            // this exact position, this slot-level difference should behave as
            // "absent" for toggling. Drop non-omission lexical variants here
            // to avoid mis-mapping to a neighbor token (e.g. Jhn 8:38 first slot).
            $slot_absent_in_other = false;
            if ($compare_mode && $other_compare_edition_code !== null) {
                $vid = (int)($w['verse_id'] ?? 0);
                $other_tok = $other_text_by_verse_pos[$vid][$wpos] ?? null;
                $other_pos_set = $other_positions_by_verse[$vid] ?? null;
                $has_slot_token = is_string($other_tok) && bible_normalize_greek_variant_text($other_tok) !== '';
                $has_word_pos = is_array($other_pos_set) && isset($other_pos_set[$wpos]);
                $slot_absent_in_other = !($has_slot_token || $has_word_pos);
                if ($slot_absent_in_other && !empty($vlist)) {
                    $vlist = array_values(array_filter($vlist, static function ($v) {
                        $kind = strtolower(trim((string)($v['kind'] ?? '')));
                        return $kind === 'omission' || $kind === 'absent';
                    }));
                }
            }

            if ($compare_mode && empty($vlist) && $other_compare_edition_code !== null) {
                if ($slot_absent_in_other) {
                    // Fallback for cases where NA28/TR differ by presence at
                    // a position but variant rows were not imported.
                    $vlist[] = [
                        'id' => 0,
                        'word_id' => $wid,
                        'position' => (float)($w['position'] ?? 0),
                        'kind' => 'absent',
                        'text_original' => '',
                        'transliteration' => '',
                        'translation' => '',
                        'strongs' => '',
                        'grammar' => '',
                        'note' => 'Absent in ' . $other_compare_edition_code,
                        'editions' => [
                            ['code' => $other_compare_edition_code, 'name' => $other_compare_edition_code, 'is_minor' => 0],
                        ],
                    ];
                }
            }

            $w['variants'] = $vlist;

            // Slot-based TR/NA28 rendering can already have substituted
            // text/translit in the base row while source_variant_id is empty.
            // Infer the active variant from current row content so initial
            // render uses the same English/Strong's/grammar that toggle uses.
            $has_substituted_row =
                ((!empty($w['canonical_text_original']) && (string)$w['canonical_text_original'] !== (string)($w['text_original'] ?? ''))
                 || (!empty($w['canonical_transliteration']) && (string)$w['canonical_transliteration'] !== (string)($w['transliteration'] ?? '')));
            if ($has_substituted_row && empty($w['source_variant_id']) && !empty($vlist)) {
                $cur_text = trim((string)($w['text_original'] ?? ''));
                $cur_tlit = trim((string)($w['transliteration'] ?? ''));
                $chosen = null;

                foreach ($vlist as $vt) {
                    if (trim((string)($vt['text_original'] ?? '')) !== ''
                        && $cur_text !== ''
                        && trim((string)$vt['text_original']) === $cur_text) {
                        $chosen = $vt;
                        break;
                    }
                }

                if ($chosen === null && $cur_tlit !== '') {
                    foreach ($vlist as $vt) {
                        if (trim((string)($vt['transliteration'] ?? '')) !== ''
                            && trim((string)$vt['transliteration']) === $cur_tlit) {
                            $chosen = $vt;
                            break;
                        }
                    }
                }

                if ($chosen !== null) {
                    if (!array_key_exists('canonical_translation', $w) || $w['canonical_translation'] === null || $w['canonical_translation'] === '') {
                        $w['canonical_translation'] = $w['translation'] ?? null;
                    }
                    if (!array_key_exists('canonical_strongs', $w) || $w['canonical_strongs'] === null || $w['canonical_strongs'] === '') {
                        $w['canonical_strongs'] = $w['strongs'] ?? null;
                    }
                    if (!array_key_exists('canonical_grammar', $w) || $w['canonical_grammar'] === null || $w['canonical_grammar'] === '') {
                        $w['canonical_grammar'] = $w['grammar'] ?? null;
                    }
                    if (!array_key_exists('canonical_transliteration', $w) || $w['canonical_transliteration'] === null || $w['canonical_transliteration'] === '') {
                        $w['canonical_transliteration'] = $w['transliteration'] ?? null;
                    }
                    $w['source_variant_id'] = (int)($chosen['id'] ?? 0);
                    if (!empty($chosen['translation']))     $w['translation'] = $chosen['translation'];
                    if (!empty($chosen['strongs']))         $w['strongs']     = $chosen['strongs'];
                    if (!empty($chosen['grammar']))         $w['grammar']     = $chosen['grammar'];
                    if (!empty($chosen['transliteration'])) $w['transliteration'] = $chosen['transliteration'];
                }
            }
        } else {
            $w['variants'] = [];
        }
        $g = $is_real ? ($gem[$wid] ?? null) : null;
        $w['gem_std'] = $g ? (int)$g['standard'] : 0;
        $w['gem_ord'] = $g ? (int)$g['ordinal']  : 0;
        $w['gem_red'] = $g ? (int)$g['reduced']  : 0;
    }
    unset($w);
}


// Map for navigation: previous/next verse references.
function bible_neighbor(string $osis_code, int $chapter, int $verse, string $direction): ?array {
    if (should_use_remote_api()) {
        return remote_bible_neighbor($osis_code, $chapter, $verse, $direction);
    }

    $pdo = bible_pdo();
    if ($direction === 'next') {
        $sql = "SELECT b.osis_code, v.chapter, v.verse
                  FROM verse v JOIN book b ON b.id = v.book_id
                 WHERE (b.book_order, v.chapter, v.verse) > (
                       (SELECT book_order FROM book WHERE osis_code = ?),
                       ?, ?)
                 ORDER BY b.book_order, v.chapter, v.verse
                 LIMIT 1";
    } else {
        $sql = "SELECT b.osis_code, v.chapter, v.verse
                  FROM verse v JOIN book b ON b.id = v.book_id
                 WHERE (b.book_order, v.chapter, v.verse) < (
                       (SELECT book_order FROM book WHERE osis_code = ?),
                       ?, ?)
                 ORDER BY b.book_order DESC, v.chapter DESC, v.verse DESC
                 LIMIT 1";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$osis_code, $chapter, $verse]);
    return $stmt->fetch() ?: null;
}


// ===================================================================
// KJV English text (the `bible_kjv` table inside stepbible)
// ===================================================================
// bible_kjv is keyed by stepbible book.id (1..66 canonical OT+NT),
// Chapter, Verse. Verse_Text carries inline Strong's tags like
// "In the beginning <07225> God <0430> created <01254> ..."; the tags
// follow the English word they refer to. Verse_Text_Clean is the same
// text with the tags stripped.
//
// Cross-tradition versification mismatches (Rev 12:18, Php 1:16/1:17,
// 2Co 13:13, 3Jn 1:15) are handled via the `verse_kjv_alt` table — see
// kjv_alt_ref() below and scripts/maintenance/fix_kjv_versification.py.
//
// Returns null only when the verse genuinely has no KJV mapping (e.g.
// the table is missing) — the caller falls back to the STEPBible English.

// Look up an NA28-style ref in verse_kjv_alt. Returns ['chapter','verse']
// for the KJV equivalent, or null if no remap applies. Caches the whole
// table on first call (it's tiny — single-digit rows).
function kjv_alt_ref(int $book_id, int $chapter, int $verse): ?array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $stmt = bible_pdo()->query(
                "SELECT book_id, na28_chapter, na28_verse, kjv_chapter, kjv_verse
                   FROM verse_kjv_alt"
            );
            foreach ($stmt->fetchAll() as $r) {
                $k = (int)$r['book_id'] . '|' . (int)$r['na28_chapter']
                   . '|' . (int)$r['na28_verse'];
                $cache[$k] = [
                    'chapter' => (int)$r['kjv_chapter'],
                    'verse'   => (int)$r['kjv_verse'],
                ];
            }
        } catch (Throwable $e) {
            // Table missing (e.g. script hasn't been run yet) — degrade
            // silently and let direct lookups continue to work.
        }
    }
    return $cache["$book_id|$chapter|$verse"] ?? null;
}

function kjv_verse_text(int $book_id, int $chapter, int $verse): ?string {
    // In remote API mode we don't have the bible_kjv table locally.
    // Fall back to the English text that comes from the remote data.
    if (should_use_remote_api()) {
        return null;
    }

    static $cache = [];
    $key = "$book_id.$chapter.$verse";
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $pdo  = bible_pdo();
        $stmt = $pdo->prepare(
            "SELECT Verse_Text
               FROM bible_kjv
              WHERE Book = ? AND Chapter = ? AND Verse = ?
              LIMIT 1"
        );
        $stmt->execute([$book_id, $chapter, $verse]);
        $row = $stmt->fetch();
        if ($row) {
            return $cache[$key] = (string)$row['Verse_Text'];
        }
        // Direct miss — check the NA28→KJV versification remap.
        if (($alt = kjv_alt_ref($book_id, $chapter, $verse)) !== null) {
            $stmt->execute([$book_id, $alt['chapter'], $alt['verse']]);
            $row = $stmt->fetch();
            if ($row) return $cache[$key] = (string)$row['Verse_Text'];
        }
        return $cache[$key] = null;
    } catch (PDOException $e) {
        return $cache[$key] = null;
    }
}

// Return the global Verse_Order (1..31102) for a given verse, or null if not
// found in bible_kjv (verse=0 psalm titles have no KJV row).
function kjv_verse_order(int $book_id, int $chapter, int $verse): ?int {
    // In remote API mode we don't have the bible_kjv table locally.
    if (should_use_remote_api()) {
        return null;
    }

    static $cache = [];
    $key = "$book_id.$chapter.$verse";
    if (array_key_exists($key, $cache)) return $cache[$key];
    if ($verse === 0) return $cache[$key] = null;
    try {
        $pdo  = bible_pdo();
        $stmt = $pdo->prepare(
            "SELECT Verse_Order
               FROM bible_kjv
              WHERE Book = ? AND Chapter = ? AND Verse = ?
              LIMIT 1"
        );
        $stmt->execute([$book_id, $chapter, $verse]);
        $row = $stmt->fetch();
        if ($row) return $cache[$key] = (int)$row['Verse_Order'];
        // Direct miss — check the NA28→KJV versification remap.
        if (($alt = kjv_alt_ref($book_id, $chapter, $verse)) !== null) {
            $stmt->execute([$book_id, $alt['chapter'], $alt['verse']]);
            $row = $stmt->fetch();
            if ($row) return $cache[$key] = (int)$row['Verse_Order'];
        }
        return $cache[$key] = null;
    } catch (PDOException $e) {
        return $cache[$key] = null;
    }
}


// ===================================================================
// LXX query helpers (book_lxx / verse_lxx / word_lxx)
// ===================================================================
// These mirror the bible_* set above but target the LXX tables.
// The web UI routes to these when edition_code = 'LXX-Rahlfs'.

function lxx_books(): array {
    // Synthesize testament/language to match bible_books()'s shape so the
    // existing index.php template can iterate the result uniformly.
    $stmt = bible_pdo()->query(
        "SELECT id, osis_code, name,
                'OT'    AS testament,
                'Greek' AS language,
                mt_parallel_osis, tradition, rahlfs_code, book_order
           FROM book_lxx
          ORDER BY book_order"
    );
    return $stmt->fetchAll();
}

function lxx_chapters(string $lxx_osis_code): array {
    $sql = "SELECT DISTINCT v.chapter
              FROM verse_lxx v JOIN book_lxx b ON b.id = v.book_id
             WHERE b.osis_code = ?
             ORDER BY v.chapter";
    $stmt = bible_pdo()->prepare($sql);
    $stmt->execute([$lxx_osis_code]);
    return array_column($stmt->fetchAll(), 'chapter');
}

// Returns one row per (verse, subverse) — Esther 1:1, 1:1a, 1:1b ...
// emit as separate rows so the verse dropdown can step through them.
function lxx_verses(string $lxx_osis_code, int $chapter): array {
    $sql = "SELECT v.verse, v.subverse
              FROM verse_lxx v JOIN book_lxx b ON b.id = v.book_id
             WHERE b.osis_code = ? AND v.chapter = ?
             ORDER BY v.verse, v.subverse";
    $stmt = bible_pdo()->prepare($sql);
    $stmt->execute([$lxx_osis_code, $chapter]);
    return $stmt->fetchAll();
}

// Look up a single LXX book row by osis_code.
function lxx_book_by_osis(string $lxx_osis_code): ?array {
    static $cache = [];
    if (array_key_exists($lxx_osis_code, $cache)) return $cache[$lxx_osis_code];
    $stmt = bible_pdo()->prepare(
        "SELECT id, osis_code, name, mt_parallel_osis, tradition, rahlfs_code, book_order
           FROM book_lxx WHERE osis_code = ? LIMIT 1"
    );
    $stmt->execute([$lxx_osis_code]);
    $row = $stmt->fetch();
    return $cache[$lxx_osis_code] = ($row ?: null);
}

// Given an MT osis_code (e.g. 'Gen'), return the *primary* LXX book row
// that parallels it (e.g. LxxGen). Prefers tradition='LXX' over 'LXX-alt',
// 'LXX-OG', 'Theodotion'. Returns null if no LXX parallel exists.
function lxx_book_by_mt_osis(string $mt_osis_code): ?array {
    static $cache = [];
    if (array_key_exists($mt_osis_code, $cache)) return $cache[$mt_osis_code];
    $stmt = bible_pdo()->prepare(
        "SELECT id, osis_code, name, mt_parallel_osis, tradition, rahlfs_code, book_order
           FROM book_lxx
          WHERE mt_parallel_osis = ?
          ORDER BY FIELD(tradition,'LXX','LXX-OG','LXX-alt','Theodotion'),
                   book_order
          LIMIT 1"
    );
    $stmt->execute([$mt_osis_code]);
    $row = $stmt->fetch();
    return $cache[$mt_osis_code] = ($row ?: null);
}

// Resolve (lxx_osis_code, chapter, verse, subverse) into the LXX verse row
// + word list. Mirrors bible_verse_full's return shape: ['verse', 'words',
// 'summaries'] (summaries is always empty for LXX rows since we have no
// equivalent of verse_summary).
function lxx_verse_full(string $lxx_osis_code, int $chapter, int $verse,
                        string $subverse = ''): ?array {
    if (should_use_remote_api()) {
        return remote_lxx_verse_full($lxx_osis_code, $chapter, $verse, $subverse);
    }

    $pdo = bible_pdo();

    // SELECT synthesizes the columns the index.php template expects from
    // the MT verse shape (has_significant_variant, testament, language)
    // so the rendering path stays unified.
    $stmt = $pdo->prepare(
        "SELECT v.*, b.osis_code, b.name AS book_name,
                b.mt_parallel_osis, b.tradition, b.rahlfs_code,
                'OT' AS testament, 'Greek' AS language,
                0 AS has_significant_variant
           FROM verse_lxx v JOIN book_lxx b ON b.id = v.book_id
          WHERE b.osis_code = ? AND v.chapter = ? AND v.verse = ? AND v.subverse = ?
          LIMIT 1"
    );
    $stmt->execute([$lxx_osis_code, $chapter, $verse, $subverse]);
    $vrow = $stmt->fetch();
    if (!$vrow) return null;
    $verse_id = (int)$vrow['id'];

    // Load words. Synthesize the canonical_* and editions/alts/morphemes/
    // links/variants keys the template expects, so the interlinear renderer
    // doesn't have to special-case LXX rows.
    $stmt = $pdo->prepare(
        "SELECT * FROM word_lxx WHERE verse_id = ? ORDER BY position"
    );
    $stmt->execute([$verse_id]);
    $words_raw = $stmt->fetchAll();

    // Gather gematria for the LXX words if the precompute table covers them.
    // (gematria_word.word_id references word.id, not word_lxx.id. For LXX
    // rows we compute on-the-fly in the JS layer via syncGematriaOnLoad.)
    $words = [];
    foreach ($words_raw as $w) {
        $w['language']                = 'Greek';
        $w['word_num']                = (int)$w['position'];
        $w['chunk_num']               = 1;
        $w['source_type']             = 'LXX';
        $w['is_variant_marked']       = 0;
        $w['submeaning']              = $w['lemma'] ?? '';
        $w['sstrong_instance']        = '';
        $w['canonical_text_original'] = $w['text_original'];
        $w['canonical_transliteration'] = $w['transliteration'] ?? null;
        $w['canonical_translation']   = $w['translation']     ?? null;
        $w['canonical_strongs']       = $w['strongs']         ?? null;
        $w['canonical_grammar']       = $w['grammar']         ?? null;
        $w['source_variant_id']       = null;
        $w['editions']                = [['code' => 'LXX-Rahlfs',
                                          'name' => 'LXX Rahlfs 1935',
                                          'is_minor' => 0]];
        $w['alts']                    = [];
        $w['morphemes']               = [];
        $w['links']                   = [];
        $w['variants']                = [];
        // Gematria is computed client-side from the displayed Greek
        // (variant-switcher.js: syncGematriaOnLoad). Seed with zeros.
        $w['gem_std'] = 0;
        $w['gem_ord'] = 0;
        $w['gem_red'] = 0;
        $words[] = $w;
    }

    return ['verse' => $vrow, 'words' => $words, 'summaries' => []];
}

// Walk LXX verses in book_order/chapter/verse/subverse order to find
// the previous or next LXX verse. Returns ['osis_code', 'chapter',
// 'verse', 'subverse'] or null at canon ends.
function lxx_neighbor(string $lxx_osis_code, int $chapter, int $verse,
                      string $subverse, string $direction): ?array {
    if (should_use_remote_api()) {
        return remote_lxx_neighbor($lxx_osis_code, $chapter, $verse, $subverse, $direction);
    }

    $pdo = bible_pdo();
    if ($direction === 'next') {
        $sql = "SELECT b.osis_code, v.chapter, v.verse, v.subverse
                  FROM verse_lxx v JOIN book_lxx b ON b.id = v.book_id
                 WHERE (b.book_order, v.chapter, v.verse, v.subverse) > (
                       (SELECT book_order FROM book_lxx WHERE osis_code = ?),
                       ?, ?, ?)
                 ORDER BY b.book_order, v.chapter, v.verse, v.subverse
                 LIMIT 1";
    } else {
        $sql = "SELECT b.osis_code, v.chapter, v.verse, v.subverse
                  FROM verse_lxx v JOIN book_lxx b ON b.id = v.book_id
                 WHERE (b.book_order, v.chapter, v.verse, v.subverse) < (
                       (SELECT book_order FROM book_lxx WHERE osis_code = ?),
                       ?, ?, ?)
                 ORDER BY b.book_order DESC, v.chapter DESC, v.verse DESC,
                          v.subverse DESC
                 LIMIT 1";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lxx_osis_code, $chapter, $verse, $subverse]);
    return $stmt->fetch() ?: null;
}
