<?php
require_once __DIR__ . '/lib/response.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = '/deudas-api';
if (strpos($uri, $base) === 0) $uri = substr($uri, strlen($base));
$uri = '/' . ltrim($uri, '/');
if ($uri === '/index.php') $uri = '/';

$segments = array_values(array_filter(explode('/', $uri)));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once __DIR__ . '/controllers/adeudos_controller.php';
require_once __DIR__ . '/controllers/pagos_controller.php';
require_once __DIR__ . '/controllers/auth_controller.php';

try {
  if (count($segments) === 0) {
    echo json_encode(['status' => 'ok', 'service' => 'deudas-api']);
    exit;
  }

  // AUTH
  if ($segments[0] === 'auth' && $method === 'POST' && ($segments[1] ?? '') === 'login') {
    AuthController::login(); exit;
  }

  // ADEUDOS
  if ($segments[0] === 'adeudos') {
    if ($method === 'GET'  && count($segments) === 1) { AdeudosController::index(); exit; }
    if ($method === 'POST' && count($segments) === 1) { AdeudosController::store(); exit; }
    if ($method === 'GET'  && count($segments) === 2) { AdeudosController::show((int)$segments[1]); exit; }
    if ($method === 'PUT'  && count($segments) === 2) { AdeudosController::update((int)$segments[1]); exit; }
    if ($method === 'DELETE' && count($segments) === 2) { AdeudosController::destroy((int)$segments[1]); exit; }

    // Subrecurso pagos del adeudo
    if (count($segments) === 3 && $segments[2] === 'pagos') {
      if ($method === 'GET')  { PagosController::index((int)$segments[1]); exit; }
      if ($method === 'POST') { PagosController::store((int)$segments[1]); exit; }
    }
  }

  // PAGOS (recurso directo)
  if ($segments[0] === 'pagos' && count($segments) === 2) {
    if ($method === 'DELETE') { PagosController::destroy((int)$segments[1]); exit; }
    // Si más adelante agregas update:
    // if ($method === 'PUT') { PagosController::update((int)$segments[1]); exit; }
  }

  echo json_encode(['error' => 'Ruta no encontrada: ' . ($base . $uri)]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error' => $e->getMessage(),
    'line'  => $e->getLine(),
    'file'  => basename($e->getFile())
  ]);
}
