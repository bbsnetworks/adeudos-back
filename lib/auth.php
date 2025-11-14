<?php
require_once __DIR__ . '/jwt.php';
function auth_require(): array {
  $cfg = require __DIR__ . '/../config.php';
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!$hdr || stripos($hdr, 'Bearer ') !== 0) {
    http_response_code(401); echo json_encode(['error' => 'No token']); exit;
  }
  $jwt = trim(substr($hdr, 7));
  $payload = jwt_verify($jwt, $cfg['app']['jwt_secret']);
  if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Token inválido']); exit; }
  return $payload; // contiene sub, name, role
}
