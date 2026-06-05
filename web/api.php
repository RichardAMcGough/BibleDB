<?php
// api.php — AJAX JSON endpoints for the Bible Browser dropdown chain.
//
// Called by dropdowns.js, strongs-tooltip.js, etc. via the relative URL
// `api.php?api=<endpoint>&...` — resolves correctly whether the site is
// served at /bible/ (Apache/IIS) or at the origin root (php -S).
// Also handles the strongs and viewcount lookups used by other JS files.
//
// This file always sends a JSON response and exits. It must NOT produce
// any output before the header() call.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/remote_api.php';
require_once __DIR__ . '/book_aliases.php';
require_once __DIR__ . '/search_lib.php';
require_once __DIR__ . '/els_lib.php';
require_once __DIR__ . '/helpers.php';

function api_proxy_secret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;
    $cfg_path = __DIR__ . '/config.php';
    if (!file_exists($cfg_path)) {
        $secret = '';
        return $secret;
    }
    $cfg = require $cfg_path;
    $secret = trim((string)($cfg['remote_api_proxy_secret'] ?? ''));
    return $secret;
}

function api_verify_proxy_signature(string $endpoint, array $post): bool {
    $secret = api_proxy_secret();
    if ($secret === '') {
        return false;
    }
    $sig = (string)($post['proxy_sig'] ?? '');
    $tsRaw = $post['proxy_ts'] ?? null;
    if ($sig === '' || $tsRaw === null || $tsRaw === '') {
        return false;
    }

    $ts = (int)$tsRaw;
    if ($ts <= 0) {
        return false;
    }
    if (abs(time() - $ts) > 300) {
        return false;
    }

    $signedData = $post;
    unset($signedData['proxy_sig']);
    unset($signedData['proxy_ts']);
    unset($signedData['api']);
    ksort($signedData);
    $canonical = http_build_query($signedData, '', '&', PHP_QUERY_RFC3986);
    $payload = $endpoint . "\n" . $ts . "\n" . $canonical;
    $expected = hash_hmac('sha256', $payload, $secret);

    return hash_equals($expected, $sig);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
try {
    $api_is_lxx = static function (string $book): bool {
        return $book !== '' && strpos($book, 'Lxx') === 0;
    };

    $api = $_GET['api'] ?? $_POST['api'] ?? $_REQUEST['api'] ?? '';
    switch ($api) {
        case 'books':
            echo json_encode(bible_books());
            break;

        case 'chapters':
            $book = trim($_GET['book'] ?? '');
            if (!$book) { echo json_encode([]); break; }
            echo json_encode($api_is_lxx($book)
                ? lxx_chapters($book)
                : bible_chapters($book));
            break;

        case 'verses':
            $book = trim($_GET['book'] ?? '');
            $chap = (int)($_GET['chapter'] ?? 0);
            if (!$book || !$chap) { echo json_encode([]); break; }
            if ($api_is_lxx($book)) {
                $rows   = lxx_verses($book, $chap);
                $unique = [];
                foreach ($rows as $r) {
                    $vn = (int)$r['verse'];
                    if (!in_array($vn, $unique, true)) $unique[] = $vn;
                }
                echo json_encode($unique);
            } else {
                echo json_encode(bible_verses($book, $chap));
            }
            break;

        case 'neighbor':
            $book   = trim($_GET['book'] ?? '');
            $chap   = (int)($_GET['chapter'] ?? 0);
            $vrs    = (int)($_GET['verse'] ?? 0);
            $dir    = $_GET['direction'] ?? 'next';
            if (!$book || !$chap || !$vrs) {
                echo json_encode(null);
                break;
            }
            echo json_encode(bible_neighbor($book, $chap, $vrs, $dir));
            break;

        case 'lxx_neighbor':
            $book   = trim($_GET['book'] ?? '');
            $chap   = (int)($_GET['chapter'] ?? 0);
            $vrs    = (int)($_GET['verse'] ?? 0);
            $sub    = (string)($_GET['subverse'] ?? '');
            $dir    = $_GET['direction'] ?? 'next';
            if (!$book || !$chap || !$vrs) {
                echo json_encode(null);
                break;
            }
            echo json_encode(lxx_neighbor($book, $chap, $vrs, $sub, $dir));
            break;

        case 'lxx_books':
            // Simple list of LXX books (can be expanded later)
            echo json_encode(lxx_books());
            break;

        case 'strongs':
            $code = trim($_GET['code'] ?? '');
            echo json_encode(bible_strongs_lookup($code));
            break;

        case 'viewcount':
            if (should_use_remote_api()) {
                // We don't expose visit counts from the remote server for privacy
                echo json_encode(['verse' => 0, 'total' => 0]);
                break;
            }
            $pdo  = bible_pdo();
            $book = trim($_GET['book']    ?? '');
            $chap = (int)($_GET['chapter'] ?? 0);
            $vrs  = (int)($_GET['verse']   ?? 0);
            if (!$book || !$chap || !$vrs) {
                echo json_encode(['verse' => 0, 'total' => 0]);
                break;
            }
            $cols = $pdo->query("SHOW COLUMNS FROM verse_views")->fetchAll(PDO::FETCH_COLUMN);
            $bcol = in_array('book_code', $cols) ? 'book_code' : 'book';
            $s    = $pdo->prepare(
                "SELECT COALESCE(view_count,0) FROM verse_views
                  WHERE {$bcol}=? AND chapter=? AND verse=?"
            );
            $s->execute([$book, $chap, $vrs]);
            $vc = (int)($s->fetchColumn() ?: 0);
            $tc = (int)$pdo->query("SELECT COALESCE(SUM(view_count),0) FROM verse_views")->fetchColumn();
            echo json_encode(['verse' => $vc, 'total' => $tc]);
            break;

        case 'kjv_verse':
            $book   = trim($_GET['book']    ?? '');
            $chap   = (int)($_GET['chapter'] ?? 0);
            $vrs    = (int)($_GET['verse']   ?? 0);
            $text   = null;
            if ($book !== '' && $chap > 0 && $vrs > 0) {
                $text = bible_kjv_verse_clean($book, $chap, $vrs);
            }
            echo json_encode(['text' => $text]);
            break;

        case 'search_gematria':
            $value = (int)($_GET['value'] ?? 0);
            $isProxy = !empty($_GET['proxy_user_id']) && !empty($_GET['proxy_username']);
            if ($isProxy) {
                if (!api_verify_proxy_signature('search_gematria', $_GET)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'invalid proxy signature']);
                    break;
                }
                $u = [
                    'id'       => (int)$_GET['proxy_user_id'],
                    'name'     => trim((string)$_GET['proxy_username']),
                    'is_guest' => false,
                    'is_admin' => !empty($_GET['proxy_is_admin']),
                ];
            } else {
                $u = get_bible_user();
            }
            echo json_encode(bible_search_gematria($value, $u));
            break;

        case 'search_verses':
            $mode = strtolower(trim($_GET['mode'] ?? 'strongs'));
            $q    = trim($_GET['q'] ?? '');
            $lang = trim($_GET['lang'] ?? '');
            echo json_encode(bible_search_verses($mode, $q, $lang));
            break;

        case 'els_fetch':
            $book    = trim($_GET['book']    ?? '');
            $chap    = (int)($_GET['chapter'] ?? 0);
            $vrs     = (int)($_GET['verse']   ?? 0);
            $edition = trim($_GET['edition'] ?? '');
            $letters = (int)($_GET['letters'] ?? 0);
            if ($book === '' || $chap < 1 || $vrs < 1 || $edition === '' || $letters < 1) {
                http_response_code(400);
                echo json_encode(['error' => 'book, chapter, verse, edition, and letters are required']);
                break;
            }
            echo json_encode(els_fetch($book, $chap, $vrs, $edition, $letters));
            break;

        case 'verse_notes':
            if (!bible_notes_enabled()) {
                http_response_code(403);
                echo json_encode(['error' => 'notes feature disabled']);
                break;
            }
            $book = trim($_GET['book'] ?? '');
            $chap = (int)($_GET['chapter'] ?? 0);
            $vrs  = (int)($_GET['verse'] ?? 0);
            if (!$book || !$chap || !$vrs) {
                http_response_code(400);
                echo json_encode(['error' => 'book, chapter, verse required']);
                break;
            }
            $isProxy = !empty($_GET['proxy_user_id']) && !empty($_GET['proxy_username']);
            if ($isProxy) {
                if (!api_verify_proxy_signature('verse_notes', $_GET)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'invalid proxy signature']);
                    break;
                }
                $u = [
                    'id'       => (int)$_GET['proxy_user_id'],
                    'name'     => trim((string)$_GET['proxy_username']),
                    'is_guest' => false,
                    'is_admin' => !empty($_GET['proxy_is_admin']),
                ];
            } else {
                $u = get_bible_user();
            }
            $notes = get_verse_notes($book, $chap, $vrs, $u);
            foreach ($notes as &$n) {
                $n['rendered'] = bbcode_to_html($n['note_text']);
            }
            unset($n);
            echo json_encode($notes);
            break;

        case 'create_verse_note':
            if (!bible_notes_enabled()) {
                http_response_code(403);
                echo json_encode(['error' => 'notes feature disabled']);
                break;
            }
            $isProxy = !empty($_REQUEST['proxy_user_id']) && !empty($_REQUEST['proxy_username']);
            if ($isProxy && !api_verify_proxy_signature('create_verse_note', $_POST)) {
                http_response_code(403);
                echo json_encode(['error' => 'invalid proxy signature']);
                break;
            }
            if (!$isProxy && !validate_csrf_token()) {
                http_response_code(403);
                echo json_encode(['error' => 'csrf token validation failed']);
                break;
            }
            // Resolve user: normal phpBB/dev session, or override from trusted proxy (remote dev instance)
            if ($isProxy) {
                $u = [
                    'id'       => (int)$_REQUEST['proxy_user_id'],
                    'name'     => trim($_REQUEST['proxy_username']),
                    'is_guest' => false,
                    'is_admin' => !empty($_REQUEST['proxy_is_admin']),
                ];
            } else {
                $u = get_bible_user();
            }
            if ($u['is_guest']) {
                http_response_code(403);
                echo json_encode(['error' => 'login required to create notes']);
                break;
            }
            $book   = trim($_REQUEST['book'] ?? '');
            $chap   = (int)($_REQUEST['chapter'] ?? 0);
            $vrs    = (int)($_REQUEST['verse'] ?? 0);
            // note_type_ids: comma-separated type IDs from the checkboxes, e.g. "1,4"
            $type_ids = array_values(array_filter(array_map('intval',
                explode(',', $_REQUEST['note_type_ids'] ?? '1')
            )));
            if (empty($type_ids)) $type_ids = [1];
            $title  = trim($_REQUEST['title'] ?? '');
            $text   = trim($_REQUEST['note_text'] ?? '');
            $gstd   = (($_REQUEST['gem_std'] ?? '') !== '') ? (int)$_REQUEST['gem_std'] : null;
            $gord   = (($_REQUEST['gem_ord'] ?? '') !== '') ? (int)$_REQUEST['gem_ord'] : null;
            $gred   = (($_REQUEST['gem_red'] ?? '') !== '') ? (int)$_REQUEST['gem_red'] : null;
            // is_public: only honoured for admins; non-admins always 0.
            $is_public = (!empty($u['is_admin']) && !empty($_REQUEST['is_public'])) ? 1 : 0;

            if (!$book || !$chap || !$vrs || $title === '' || $text === '') {
                http_response_code(400);
                echo json_encode(['error' => 'book, chapter, verse, title, and note_text required']);
                break;
            }
            if (strlen($title) > 255) {
                http_response_code(400);
                echo json_encode(['error' => 'title too long (maximum 255 characters)']);
                break;
            }
            if (strlen($text) > 65535) {
                http_response_code(400);
                echo json_encode(['error' => 'note text too long (maximum 64KB)']);
                break;
            }

            if (should_use_remote_api()) {
                // Proxy to live site so remote-dev contributors see/create notes (parity with other features)
                $proxyData = [
                    'book'          => $book,
                    'chapter'       => $chap,
                    'verse'         => $vrs,
                    'note_type_ids' => implode(',', $type_ids),
                    'title'         => $title,
                    'note_text'     => $text,
                    'gem_std'       => $gstd !== null ? $gstd : '',
                    'gem_ord'       => $gord !== null ? $gord : '',
                    'gem_red'       => $gred !== null ? $gred : '',
                    'proxy_user_id' => $u['id'],
                    'proxy_username' => $u['name'],
                    'proxy_is_admin' => !empty($u['is_admin']) ? 1 : 0,
                ];
                $resp = remote_api_call('create_verse_note', [], 'POST', $proxyData);
                if ($resp && !empty($resp['success'])) {
                    echo json_encode(['success' => true]);
                } else {
                    $err = (is_array($resp) && !empty($resp['error'])) ? $resp['error'] : 'remote note creation failed';
                    echo json_encode(['error' => $err]);
                }
                break;
            }

            $ok = create_verse_note($u, $book, $chap, $vrs, $type_ids, $title, $text, $gstd, $gord, $gred, $is_public);
            if ($ok) {
                echo json_encode(['success' => true]);
            } else {
                $why = function_exists('get_note_last_error') ? trim(get_note_last_error()) : '';
                echo json_encode(['error' => $why !== '' ? ('failed to save note: ' . $why) : 'failed to save note (db error or duplicate)']);
            }
            break;

        case 'update_verse_note':
            if (!bible_notes_enabled()) {
                http_response_code(403);
                echo json_encode(['error' => 'notes feature disabled']);
                break;
            }
            $isProxy = !empty($_REQUEST['proxy_user_id']) && !empty($_REQUEST['proxy_username']);
            if ($isProxy && !api_verify_proxy_signature('update_verse_note', $_POST)) {
                http_response_code(403);
                echo json_encode(['error' => 'invalid proxy signature']);
                break;
            }
            if (!$isProxy && !validate_csrf_token()) {
                http_response_code(403);
                echo json_encode(['error' => 'csrf token validation failed']);
                break;
            }
            if ($isProxy) {
                $u = [
                    'id'       => (int)$_REQUEST['proxy_user_id'],
                    'name'     => trim($_REQUEST['proxy_username']),
                    'is_guest' => false,
                    'is_admin' => !empty($_REQUEST['proxy_is_admin']),
                ];
            } else {
                $u = get_bible_user();
            }
            if ($u['is_guest']) {
                http_response_code(403);
                echo json_encode(['error' => 'login required to update notes']);
                break;
            }
            $note_id = (int)($_REQUEST['id'] ?? 0);
            $book   = trim($_REQUEST['book'] ?? '');
            $chap   = (int)($_REQUEST['chapter'] ?? 0);
            $vrs    = (int)($_REQUEST['verse'] ?? 0);
            $type_ids = array_values(array_filter(array_map('intval',
                explode(',', $_REQUEST['note_type_ids'] ?? '1')
            )));
            if (empty($type_ids)) $type_ids = [1];
            $title  = trim($_REQUEST['title'] ?? '');
            $text   = trim($_REQUEST['note_text'] ?? '');
            $gstd   = (($_REQUEST['gem_std'] ?? '') !== '') ? (int)$_REQUEST['gem_std'] : null;
            $gord   = (($_REQUEST['gem_ord'] ?? '') !== '') ? (int)$_REQUEST['gem_ord'] : null;
            $gred   = (($_REQUEST['gem_red'] ?? '') !== '') ? (int)$_REQUEST['gem_red'] : null;
            $is_public = (!empty($u['is_admin']) && !empty($_REQUEST['is_public'])) ? 1 : 0;

            if (!$note_id || !$book || !$chap || !$vrs || !$title || $text === '') {
                http_response_code(400);
                echo json_encode(['error' => 'id, book, chapter, verse, title, and note_text required']);
                break;
            }
            if (strlen($title) > 255) {
                http_response_code(400);
                echo json_encode(['error' => 'title too long (maximum 255 characters)']);
                break;
            }
            if (strlen($text) > 65535) {
                http_response_code(400);
                echo json_encode(['error' => 'note text too long (maximum 64KB)']);
                break;
            }

            if (should_use_remote_api()) {
                $proxyData = [
                    'id'            => $note_id,
                    'book'          => $book,
                    'chapter'       => $chap,
                    'verse'         => $vrs,
                    'note_type_ids' => implode(',', $type_ids),
                    'title'         => $title,
                    'note_text'     => $text,
                    'gem_std'       => $gstd !== null ? $gstd : '',
                    'gem_ord'       => $gord !== null ? $gord : '',
                    'gem_red'       => $gred !== null ? $gred : '',
                    'proxy_user_id' => $u['id'],
                    'proxy_username' => $u['name'],
                    'proxy_is_admin' => !empty($u['is_admin']) ? 1 : 0,
                ];
                $resp = remote_api_call('update_verse_note', [], 'POST', $proxyData);
                if ($resp && !empty($resp['success'])) {
                    echo json_encode(['success' => true]);
                } else {
                    $err = (is_array($resp) && !empty($resp['error'])) ? $resp['error'] : 'remote note update failed';
                    echo json_encode(['error' => $err]);
                }
                break;
            }

            $ok = update_verse_note($note_id, $u, $book, $chap, $vrs, $type_ids, $title, $text, $gstd, $gord, $gred, $is_public);
            if ($ok) {
                echo json_encode(['success' => true]);
            } else {
                $why = function_exists('get_note_last_error') ? trim(get_note_last_error()) : '';
                echo json_encode(['error' => $why !== '' ? ('failed to update note: ' . $why) : 'failed to update note (not owner or db error)']);
            }
            break;

        case 'delete_verse_note':
            if (!bible_notes_enabled()) {
                http_response_code(403);
                echo json_encode(['error' => 'notes feature disabled']);
                break;
            }
            $isProxy = !empty($_REQUEST['proxy_user_id']) && !empty($_REQUEST['proxy_username']);
            if ($isProxy && !api_verify_proxy_signature('delete_verse_note', $_POST)) {
                http_response_code(403);
                echo json_encode(['error' => 'invalid proxy signature']);
                break;
            }
            if (!$isProxy && !validate_csrf_token()) {
                http_response_code(403);
                echo json_encode(['error' => 'csrf token validation failed']);
                break;
            }
            if ($isProxy) {
                $u = [
                    'id'       => (int)$_REQUEST['proxy_user_id'],
                    'name'     => trim($_REQUEST['proxy_username']),
                    'is_guest' => false,
                    'is_admin' => !empty($_REQUEST['proxy_is_admin']),
                ];
            } else {
                $u = get_bible_user();
            }
            if ($u['is_guest']) {
                http_response_code(403);
                echo json_encode(['error' => 'login required to delete notes']);
                break;
            }
            $note_id = (int)($_REQUEST['id'] ?? 0);
            if (!$note_id) {
                http_response_code(400);
                echo json_encode(['error' => 'id required']);
                break;
            }

            if (should_use_remote_api()) {
                $proxyData = [
                    'id' => $note_id,
                    'proxy_user_id' => $u['id'],
                    'proxy_username' => $u['name'],
                    'proxy_is_admin' => !empty($u['is_admin']) ? 1 : 0,
                ];
                $resp = remote_api_call('delete_verse_note', [], 'POST', $proxyData);
                if ($resp && !empty($resp['success'])) {
                    echo json_encode(['success' => true]);
                } else {
                    $err = (is_array($resp) && !empty($resp['error'])) ? $resp['error'] : 'remote note delete failed';
                    echo json_encode(['error' => $err]);
                }
                break;
            }

            $ok = delete_verse_note($note_id, $u);
            if ($ok) {
                echo json_encode(['success' => true]);
            } else {
                $why = function_exists('get_note_last_error') ? trim(get_note_last_error()) : '';
                echo json_encode(['error' => $why !== '' ? ('failed to delete note: ' . $why) : 'failed to delete note (not owner or db error)']);
            }
            break;

        case 'set_verse_note_visibility':
            if (!bible_notes_enabled()) {
                http_response_code(403);
                echo json_encode(['error' => 'notes feature disabled']);
                break;
            }
            $isProxy = !empty($_REQUEST['proxy_user_id']) && !empty($_REQUEST['proxy_username']);
            if ($isProxy && !api_verify_proxy_signature('set_verse_note_visibility', $_POST)) {
                http_response_code(403);
                echo json_encode(['error' => 'invalid proxy signature']);
                break;
            }
            if (!$isProxy && !validate_csrf_token()) {
                http_response_code(403);
                echo json_encode(['error' => 'csrf token validation failed']);
                break;
            }
            if ($isProxy) {
                $u = [
                    'id'       => (int)$_REQUEST['proxy_user_id'],
                    'name'     => trim($_REQUEST['proxy_username']),
                    'is_guest' => false,
                    'is_admin' => !empty($_REQUEST['proxy_is_admin']),
                ];
            } else {
                $u = get_bible_user();
            }
            if ($u['is_guest'] || empty($u['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'admin required']);
                break;
            }
            $note_id = (int)($_REQUEST['id'] ?? 0);
            $is_public = !empty($_REQUEST['is_public']) ? 1 : 0;
            if ($note_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'id required']);
                break;
            }

            if (should_use_remote_api()) {
                $proxyData = [
                    'id' => $note_id,
                    'is_public' => $is_public,
                    'proxy_user_id' => $u['id'],
                    'proxy_username' => $u['name'],
                    'proxy_is_admin' => !empty($u['is_admin']) ? 1 : 0,
                ];
                $resp = remote_api_call('set_verse_note_visibility', [], 'POST', $proxyData);
                if ($resp && !empty($resp['success'])) {
                    echo json_encode(['success' => true]);
                } else {
                    $err = (is_array($resp) && !empty($resp['error'])) ? $resp['error'] : 'remote note visibility update failed';
                    echo json_encode(['error' => $err]);
                }
                break;
            }

            $ok = set_verse_note_visibility($note_id, $u, $is_public);
            if ($ok) {
                echo json_encode(['success' => true]);
            } else {
                $why = function_exists('get_note_last_error') ? trim(get_note_last_error()) : '';
                echo json_encode(['error' => $why !== '' ? ('failed to update note visibility: ' . $why) : 'failed to update note visibility']);
            }
            break;

        // New coarse-grained endpoint for remote dev use
        case 'verse_full':
            $book   = trim($_GET['book'] ?? '');
            $chap   = (int)($_GET['chapter'] ?? 0);
            $vrs    = (int)($_GET['verse'] ?? 0);
            $sub    = (string)($_GET['subverse'] ?? '');
            $edition = $_GET['edition'] ?? null;

            if (!$book || !$chap || !$vrs) {
                http_response_code(400);
                echo json_encode(['error' => 'book, chapter, and verse are required']);
                break;
            }

            $is_lxx = strpos($book, 'Lxx') === 0;
            $data = $is_lxx
                ? lxx_verse_full($book, $chap, $vrs, $sub, $edition)
                : bible_verse_full($book, $chap, $vrs, $edition);

            echo json_encode($data);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown api']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Bible API error: ' . $e->getMessage());
    $cfg = @include __DIR__ . '/config.php';  // may not exist
    if (!empty($cfg['debug'])) {
        echo json_encode(['error' => 'Internal server error', 'detail' => $e->getMessage()]);
    } else {
        echo json_encode(['error' => 'Internal server error']);
    }
}
exit;
