<?php
// Ajusta a tu entorno local
return [
  // BD de adeudos
  'db' => [
    'host'    => '192.168.99.253',
    'user'    => 'adminbbs',
    'pass'    => 'Admin_Pinck',
    'name'    => 'proyecto_adeudos',
    'port'    => 3306,
    'charset' => 'utf8mb4',
  ],

  // BD de usuarios (otra base)
  'users_db' => [
    'host'    => '192.168.99.253',
    'user'    => 'adminbbs',
    'pass'    => 'Admin_Pinck',
    'name'    => 'sysbbs_parquer',   // <--- TU DB de usuarios
    'port'    => 3306,
    'charset' => 'utf8mb4',
  ],

  'app' => [
    'base_path'    => '/deudas-api',           // <--- que coincida con la carpeta real
    'debug'        => true,
    'cors_origins' => ['*'],                   // en prod, restringe
    'jwt_secret'   => 'cambia-esta-clave-super-segura-32chars',
    'jwt_ttl_minutes' => 120,
  ],
];
