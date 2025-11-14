<?php
class DBUsers {
  private static ?mysqli $conn = null;
  public static function conn(): mysqli {
    if (self::$conn === null) {
      $cfg = require __DIR__ . '/config.php';
      $db = $cfg['users_db'];
      $m = new mysqli($db['host'],$db['user'],$db['pass'],$db['name'],$db['port']);
      if ($m->connect_errno) {
        http_response_code(500);
        die(json_encode(['error' => 'Users DB connection failed', 'detail' => $m->connect_error]));
      }
      $m->set_charset($db['charset']);
      self::$conn = $m;
    }
    return self::$conn;
  }
}

