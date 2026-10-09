<?php
declare(strict_types=1);

/**
 * JSONDB Lite HTTP/API layer.
 * Database storage and query logic live in lib/php/index.php.
 */
require_once dirname(__DIR__, 2) . '/lib/php/index.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $db = new JSONDBLite();
        respond(200, ['success' => true, 'name' => 'JSONDB Lite', ...$db->health()]);
    } catch (Throwable $e) {
        respond(500, ['success' => false, 'error' => 'Database health check failed.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['success' => false, 'error' => 'Use GET or POST.']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
    respond(400, ['success' => false, 'error' => 'Malformed JSON request body.']);
}

$action = $body['action'] ?? '';
try {
    $db = new JSONDBLite();
    if ($action === 'health') respond(200, ['success' => true, ...$db->health()]);

    $collection = $body['collection'] ?? null;
    if (!is_string($collection) || $collection === '') {
        respond(400, ['success' => false, 'error' => 'A valid collection name is required.']);
    }

    switch ($action) {
        case 'insert':
            $result = $db->insert($collection, $body['data'] ?? null);
            respond(201, ['success' => true, ...$result]);
        case 'search':
            $query = $body['query'] ?? [];
            if (!is_array($query)) respond(400, ['success' => false, 'error' => 'Query must be a JSON object.']);
            $result = $db->search($collection, $query, ['limit' => $body['limit'] ?? 100, 'offset' => $body['offset'] ?? 0]);
            respond(200, ['success' => true, ...$result]);
        case 'update':
            $query = $body['query'] ?? null;
            $data = $body['data'] ?? null;
            if (!is_array($query) || !is_array($data)) respond(400, ['success' => false, 'error' => 'Update requires query and data objects.']);
            $result = $db->update($collection, $query, $data);
            respond(200, ['success' => true, ...$result]);
        case 'delete':
            $query = $body['query'] ?? null;
            if (!is_array($query)) respond(400, ['success' => false, 'error' => 'Delete requires a query object.']);
            $result = $db->delete($collection, $query);
            respond(200, ['success' => true, ...$result]);
        default:
            respond(400, ['success' => false, 'error' => 'Invalid action. Use insert, search, update, delete, or health.']);
    }
} catch (InvalidArgumentException $e) {
    respond(400, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[JSONDB Lite PHP server] ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'Internal server error. Check PHP error logs.']);
}
