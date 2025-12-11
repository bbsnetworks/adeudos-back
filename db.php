<?php
class DB {
  // Conexión principal (adeudos)
  private static ?mysqli $conn = null;
  // Conexión a la BD de usuarios
  private static ?mysqli $usersConn = null;

  /**
   * Crea una conexión mysqli a partir de un bloque de config.
   */
  private static function makeConn(array $cfg): mysqli {
    $host = $cfg['host']; // asegúrate que NO lleve 'p:' ni cosas raras

    $mysqli = @new mysqli(
      $host,
      $cfg['user'],
      $cfg['pass'],
      $cfg['name'],
      $cfg['port'] ?? 3306
    );

    if ($mysqli->connect_errno) {
      http_response_code(500);
      echo json_encode([
        'error'  => 'DB connection failed',
        'detail' => $mysqli->connect_error,
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $mysqli->set_charset($cfg['charset'] ?? 'utf8mb4');
    return $mysqli;
  }

  /**
   * Conexión a la BD de adeudos (proyecto_adeudos)
   */
  public static function conn(): mysqli {
    if (self::$conn !== null && @self::$conn->ping()) {
      return self::$conn;
    }

    $cfg = require __DIR__ . '/config.php';
    $db  = $cfg['db'];          // <-- bloque 'db' de config.php

    self::$conn = self::makeConn($db);
    return self::$conn;
  }

  /**
   * Conexión a la BD de usuarios (sysbbs_parquer)
   */
  public static function usersConn(): mysqli {
    if (self::$usersConn !== null && @self::$usersConn->ping()) {
      return self::$usersConn;
    }

    $cfg = require __DIR__ . '/config.php';
    if (empty($cfg['users_db'])) {
      http_response_code(500);
      echo json_encode([
        'error' => 'users_db config missing',
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $dbUsers = $cfg['users_db'];  // <-- bloque 'users_db' de config.php
    self::$usersConn = self::makeConn($dbUsers);
    return self::$usersConn;
  }

  public static function close(): void {
    if (self::$conn) {
      @self::$conn->close();
      self::$conn = null;
    }
    if (self::$usersConn) {
      @self::$usersConn->close();
      self::$usersConn = null;
    }
  }
}

// Cerrar automáticamente al terminar cada request
register_shutdown_function(['DB', 'close']);
