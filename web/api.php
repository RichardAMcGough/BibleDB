<?php
// api.php — AJAX JSON endpoints for the Bible Browser dropdown chain.
//
// Called by dropdowns.js via /bible/api.php?api=<endpoint>&...
// Also handles the strongs and viewcount lookups used by other JS files.
//
// This file always sends a JSON response and exits. It must NOT produce
// any output before the header() call.

require __DIR__ . '/db.php';
require __DIR__ . '/book_aliases.php';

header('Content-Type: application/json; charset=utf-8');
try {
    $api_is_lxx = static function (string $book): bool {
        return $book !== '' && strpos($book, 'Lxx') === 0;
    };

    switch ($_GET['api'] ?? '') {
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

        case 'lxx_books':
            // Simple list of LXX books (can be expanded later)
            echo json_encode(lxx_books());
            break;

        case 'strongs':
            $code = trim($_GET['code'] ?? '');
            echo json_encode(bible_strongs_lookup($code));
            break;

        case 'viewcount':
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
    echo json_encode(['error' => 'Internal server error']);
}
exit;
