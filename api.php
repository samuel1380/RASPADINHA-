<?php
// api.php
require_once 'db.php';
require_once 'jwt.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");

$requestUri = explode('?', $_SERVER['REQUEST_URI'], 2);
$path = $requestUri[0];

// Remove base path if deployed in a subfolder, e.g. /php_backend/api.php
$basePath = '/api'; // Assuming Nginx routes /api to this file
// if not routed by nginx, we might need a strpos check
if (strpos($path, '/api') !== 0) {
    if (strpos($path, '/php_backend/api.php') === 0) {
        $path = str_replace('/php_backend/api.php', '/api', $path);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$jwtSecret = getenv('JWT_SECRET') ?: 'secret';

function getAuthUser($jwtSecret) {
    $authHeader = '';
    
    // Method 1: apache_request_headers (most common)
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    
    // Method 2: $_SERVER (set by .htaccess RewriteRule)
    if (!$authHeader && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    
    // Method 3: REDIRECT_ prefix (when Apache uses internal redirect)
    if (!$authHeader && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    
    // Method 4: getallheaders() fallback
    if (!$authHeader && function_exists('getallheaders')) {
        $allHeaders = getallheaders();
        $authHeader = $allHeaders['Authorization'] ?? $allHeaders['authorization'] ?? '';
    }
    
    if (!$authHeader) return null;
    
    $token = str_replace('Bearer ', '', $authHeader);
    return JWT::decode($token, $jwtSecret);
}

// Router
if ($path === '/api/auth/register' && $method === 'POST') {
    require 'routes/auth.php';
    register($pdo, $body, $jwtSecret);
} 
elseif ($path === '/api/auth/login' && $method === 'POST') {
    require 'routes/auth.php';
    login($pdo, $body, $jwtSecret);
}
elseif ($path === '/api/user/me' && $method === 'GET') {
    require 'routes/user.php';
    getUserMe($pdo, getAuthUser($jwtSecret));
}
elseif ($path === '/api/game/start' && $method === 'POST') {
    require 'routes/game.php';
    startGame($pdo, $body, getAuthUser($jwtSecret));
}
elseif ($path === '/api/game/end' && $method === 'POST') {
    require 'routes/game.php';
    endGame($pdo, $body, getAuthUser($jwtSecret));
}
elseif ($path === '/api/transaction/create' && $method === 'POST') {
    require 'routes/game.php';
    createTransaction($pdo, $body, getAuthUser($jwtSecret));
}
elseif ($path === '/api/affiliates/stats' && $method === 'GET') {
    require 'routes/user.php';
    getAffiliatesStats($pdo, getAuthUser($jwtSecret));
}
elseif ($path === '/api/config' && $method === 'GET') {
    require 'routes/admin.php';
    getPublicConfig($pdo);
}
elseif ($path === '/api/admin/login' && $method === 'POST') {
    require 'routes/admin.php';
    adminLogin($body, $jwtSecret);
}
elseif ($path === '/api/admin/config' && $method === 'GET') {
    require 'routes/admin.php';
    getAdminConfig($pdo, getAuthUser($jwtSecret));
}
elseif ($path === '/api/admin/config' && $method === 'POST') {
    require 'routes/admin.php';
    saveAdminConfig($pdo, $body, getAuthUser($jwtSecret));
}
elseif ($path === '/api/admin/users' && $method === 'GET') {
    require 'routes/admin.php';
    getAdminUsers($pdo, getAuthUser($jwtSecret));
}
elseif ($path === '/api/admin/stats' && $method === 'GET') {
    require 'routes/admin.php';
    getAdminStats($pdo, getAuthUser($jwtSecret));
}
elseif (preg_match('/^\/api\/admin\/users\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    require 'routes/admin.php';
    updateUser($pdo, $body, getAuthUser($jwtSecret), $matches[1]);
}
elseif (preg_match('/^\/api\/admin\/users\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    require 'routes/admin.php';
    deleteUser($pdo, getAuthUser($jwtSecret), $matches[1]);
}
elseif ($path === '/api/deposit' && $method === 'POST') {
    require 'routes/finance.php';
    createDeposit($pdo, $body, getAuthUser($jwtSecret));
}
elseif ($path === '/api/deposit/confirm' && $method === 'POST') {
    require 'routes/finance.php';
    confirmDeposit($pdo, $body, getAuthUser($jwtSecret));
}
elseif (preg_match('/^\/api\/deposit\/status\/(.+)$/', $path, $matches) && $method === 'GET') {
    require 'routes/finance.php';
    checkDepositStatus($pdo, $matches[1], getAuthUser($jwtSecret));
}
elseif ($path === '/api/withdraw' && $method === 'POST') {
    require 'routes/finance.php';
    requestWithdraw($pdo, $body, getAuthUser($jwtSecret));
}
elseif ($path === '/api/callback' && $method === 'POST') {
    require 'routes/finance.php';
    pagVivaCallback($pdo);
}
elseif ($path === '/api/withdraw-callback' && $method === 'POST') {
    require 'routes/finance.php';
    withdrawCallback($pdo, $body);
}
elseif ($path === '/api/affiliates/claim' && $method === 'POST') {
    require 'routes/user.php';
    claimAffiliateEarnings($pdo, getAuthUser($jwtSecret));
}
elseif ($path === '/api/user/cpf' && $method === 'PUT') {
    require 'routes/user.php';
    updateUserCpf($pdo, $body, getAuthUser($jwtSecret));
}
elseif (preg_match('/^\/api\/referral\/(.+)$/', $path, $matches) && $method === 'GET') {
    require 'routes/user.php';
    getReferralInfo($matches[1]);
}
else {
    http_response_code(404);
    echo json_encode(['error' => 'Route not found or method not allowed']);
}
?>
