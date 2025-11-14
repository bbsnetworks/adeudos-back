<?php
function json_headers() {
header('Content-Type: application/json; charset=utf-8');
}


function cors_headers() {
$cfg = require __DIR__ . '/../config.php';
$origins = $cfg['app']['cors_origins'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if ($origins === ['*']) {
header('Access-Control-Allow-Origin: *');
} else if (in_array($origin, $origins)) {
header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
}


function ok($data, int $code = 200) {
http_response_code($code);
echo json_encode($data, JSON_UNESCAPED_UNICODE);
exit;
}


function fail(string $msg, int $code = 400, array $meta = []) {
http_response_code($code);
echo json_encode(['error' => $msg, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
exit;
}