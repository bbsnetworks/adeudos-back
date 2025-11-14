<?php
require_once __DIR__ . '/../db_users.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/jwt.php';

class AuthController {
  // POST /auth/login  { username, password }
 public static function login() {
  $b = body_json();
  $username = req_str($b, 'username');
  $password = req_str($b, 'password');
  if (!$username || !$password) fail('Username y password requeridos', 422);

  $db = DBUsers::conn();
  $st = $db->prepare("SELECT iduser, nombre, password, tipo FROM users WHERE nombre = ? LIMIT 1");
  $st->bind_param('s', $username);
  $st->execute();
  $res = $st->get_result();
  $u = $res->fetch_assoc();
  $st->close();

  if (!$u) fail('Credenciales inválidas', 401);

  $stored = trim((string)$u['password']);
  $input  = trim((string)$password);

  $ok = false;

  // 1) bcrypt
  if (str_starts_with($stored, '$2y$')) {
    $ok = password_verify($input, $stored);
  }
  // 2) SHA-1 hex (40 chars)
  else if (preg_match('/^[a-f0-9]{40}$/i', $stored)) {
    // comparar en minúsculas para evitar diferencias de case
    $ok = hash_equals(strtolower($stored), sha1($input));
  }
  // 3) fallback (texto plano, no recomendado)
  else {
    $ok = hash_equals($stored, $input);
  }

  if (!$ok) fail('Credenciales inválidas', 401);

  // emitir JWT
  $cfg = require __DIR__ . '/../config.php';
  $ttl = (int)$cfg['app']['jwt_ttl_minutes'];
  $payload = [
    'sub'  => (int)$u['iduser'],
    'name' => $u['nombre'],
    'role' => $u['tipo'],
    'iat'  => time(),
    'exp'  => time() + $ttl * 60,
    'iss'  => 'deudas-api',
  ];
  $token = jwt_sign($payload, $cfg['app']['jwt_secret']);

  ok(['token' => $token, 'user' => [
    'id' => (int)$u['iduser'],
    'name' => $u['nombre'],
    'role' => $u['tipo'],
  ]]);
}

}
