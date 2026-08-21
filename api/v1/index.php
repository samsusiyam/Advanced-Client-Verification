<?php

/**
 * REST API v1 entry point.
 * Supports: GET /verification, GET /client/{id}/verification,
 * POST /verification, POST /verification/{id}/approve, POST /verification/{id}/reject
 * Auth: Authorization: Bearer <token> (hashed, scoped, rate limited).
 */

require_once __DIR__ . '/../../app/Helpers/functions.php';

// External REST endpoint is hit directly by API clients, so WHMCS/Capsule must
// be bootstrapped. Scan upward for WHMCS init.php (layout-independent).
if (!defined('WHMCS')) {
    $dir = __DIR__;
    $bootstrap = null;
    for ($i = 0; $i < 8; $i++) {
        if (file_exists($dir . '/init.php')) {
            $bootstrap = $dir . '/init.php';
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    if ($bootstrap) {
        require_once $bootstrap;
    }
}

use ClientVerification\Api\TokenAuth;
use ClientVerification\Services\VerificationService;
use ClientVerification\Security\Sanitizer;

header('Content-Type: application/json');

function api_response($code, $data)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Extract bearer token.
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$authHeader && function_exists('getallheaders')) {
    foreach (getallheaders() ?: [] as $k => $v) {
        if (strtolower((string) $k) === 'authorization') {
            $authHeader = $v;
            break;
        }
    }
}
if (preg_match('/Bearer\s+(.+)/i', (string) $authHeader, $m)) {
    $bearer = trim($m[1]);
} else {
    $bearer = '';
}

$token = TokenAuth::authenticate($bearer);
if (!$token) {
    api_response(401, ['error' => 'unauthorized']);
}
if (TokenAuth::rateLimited($token)) {
    api_response(429, ['error' => 'rate_limited']);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
// path like modules/addons/clientverification/api/v1/... -> take last segments
$segments = array_values(array_filter(explode('/', $path)));
$apiIndex = array_search('v1', $segments);
$route = array_slice($segments, $apiIndex + 1);
$resource = $route[0] ?? '';

switch ($method . ':' . $resource) {
    case 'GET:verification':
        if (isset($route[1]) && $route[1] === 'client' && isset($route[2])) {
            $clientId = (int) $route[2];
            if (!TokenAuth::checkScope($token, 'read')) {
                api_response(403, ['error' => 'forbidden']);
            }
            $v = VerificationService::getActiveForClient($clientId);
            api_response(200, $v ? (array) $v : ['status' => 'none']);
        }
        if (isset($route[1]) && is_numeric($route[1])) {
            if (!TokenAuth::checkScope($token, 'read')) {
                api_response(403, ['error' => 'forbidden']);
            }
            $v = VerificationService::find((int) $route[1]);
            api_response(200, $v ? (array) $v : ['error' => 'not_found']);
        }
        api_response(400, ['error' => 'bad_request']);
        break;

    case 'POST:verification':
        if (!TokenAuth::checkScope($token, 'write')) {
            api_response(403, ['error' => 'forbidden']);
        }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $clientId = (int) ($body['client_id'] ?? 0);
        $mode = Sanitizer::cleanString($body['mode'] ?? 'hybrid', 20);
        $config = cv_get_config();
        $personal = $body['personal_data'] ?? [];
        $result = \ClientVerification\Services\HybridVerificationService::start($clientId, $mode, $personal, $config);
        api_response(201, $result);
        break;

    case 'POST:approve':
    case 'POST:reject':
        if (!TokenAuth::checkScope($token, 'write')) {
            api_response(403, ['error' => 'forbidden']);
        }
        $id = (int) ($route[1] ?? 0);
        $status = ($resource === 'approve') ? 'approved' : 'rejected';
        VerificationService::updateStatus($id, $status, -1, 'api_' . $resource);
        api_response(200, ['status' => $status]);
        break;

    default:
        api_response(404, ['error' => 'not_found']);
}
