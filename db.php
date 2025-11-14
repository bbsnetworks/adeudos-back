<?php
class DB {
  private static ?mysqli $conn = null;

  public static function conn(): mysqli {
    if (self::$conn !== null && @self::$conn->ping()) {
      return self::$conn;
    }

    $cfg = require __DIR__ . '/config.php';
    $db  = $cfg['db'];

    // Asegúrate de que $db['host'] NO tenga prefijo "p:" (persistente)
    // Ejemplo correcto: '127.0.0.1' o 'localhost'
    $host = $db['host'];

    $mysqli = @new mysqli($host, $db['user'], $db['pass'], $db['name'], $db['port']);
    if ($mysqli->connect_errno) {
      http_response_code(500);
      // No uses die() sin cerrar recursos; regresamos un JSON limpio
      echo json_encode([
        'error'  => 'DB connection failed',
        'detail' => $mysqli->connect_error
      ]);
      exit;
    }
    $mysqli->set_charset($db['charset'] ?? 'utf8mb4');
    self::$conn = $mysqli;
    return self::$conn;
  }

  public static function close(): void {
    if (self::$conn) {
      @self::$conn->close();
      self::$conn = null;
    }
  }
}

// Cerrar automáticamente al terminar cada request
register_shutdown_function(['DB', 'close']);
