<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/validate.php';


class AdeudosController {
/** GET /adeudos */
public static function index() {
  $db = DB::conn();

  $q      = $_GET['q']      ?? '';
  $estado = $_GET['estado'] ?? '';
  $limit  = max(1, (int)($_GET['limit']  ?? 20));
  $offset = max(0, (int)($_GET['offset'] ?? 0));

  $params = [];
  $types  = '';

  $sql = "SELECT
            a.id, a.deudor_nombre, a.concepto,
            a.monto_total, a.deuda_inicial, a.monto_pagado_inicial,
            a.fecha_inicio, a.fecha_fin,
            a.mostrar_desde, a.mostrar_hasta,
            a.tasa_interes_anual, a.interes_tipo, a.periodicidad_pago,
            a.estado, a.notas, a.created_at, a.updated_at, a.deleted_at,
            IFNULL(p.capital_pagado, 0)   AS capital_pagado,
            IFNULL(p.interes_generado, 0) AS interes_generado,
            (a.monto_total - (a.monto_pagado_inicial + IFNULL(p.capital_pagado,0))) AS saldo_pendiente,
            lp.ultimo_pago,
            -- vencido por ventana de días (solo mensual, no cancelados, con ventana definida)
            CASE
              WHEN a.periodicidad_pago <> 'mensual' THEN 0
              WHEN a.deleted_at IS NOT NULL THEN 0
              WHEN a.mostrar_desde IS NULL OR a.mostrar_hasta IS NULL THEN 0
              WHEN (MONTH(lp.ultimo_pago) = MONTH(CURDATE()) AND YEAR(lp.ultimo_pago) = YEAR(CURDATE())) THEN 0
              WHEN DAY(CURDATE()) > a.mostrar_hasta THEN 1
              ELSE 0
            END AS vencido_calc,
            -- estado calculado para UI
            CASE
              WHEN a.estado = 'cancelado' THEN 'cancelado'
              WHEN a.estado = 'liquidado' THEN 'liquidado'
              WHEN (
                CASE
                  WHEN a.periodicidad_pago <> 'mensual' THEN 0
                  WHEN a.deleted_at IS NOT NULL THEN 0
                  WHEN a.mostrar_desde IS NULL OR a.mostrar_hasta IS NULL THEN 0
                  WHEN (MONTH(lp.ultimo_pago) = MONTH(CURDATE()) AND YEAR(lp.ultimo_pago) = YEAR(CURDATE())) THEN 0
                  WHEN DAY(CURDATE()) > a.mostrar_hasta THEN 1
                  ELSE 0
                END
              ) = 1 THEN 'vencido'
              ELSE a.estado
            END AS estado_calculado
          FROM adeudos a
          LEFT JOIN (
            SELECT
              adeudo_id,
              SUM(monto - interes) AS capital_pagado,
              SUM(interes)         AS interes_generado
            FROM pagos
            GROUP BY adeudo_id
          ) p ON p.adeudo_id = a.id
          LEFT JOIN (
            SELECT adeudo_id, MAX(fecha_pago) AS ultimo_pago
            FROM pagos
            GROUP BY adeudo_id
          ) lp ON lp.adeudo_id = a.id
          WHERE 1=1";

  if ($estado === 'cancelado') {
    $sql .= " AND a.deleted_at IS NOT NULL";
  } else if ($estado !== '') {
    $sql   .= " AND a.deleted_at IS NULL AND (
                  a.estado = ? OR
                  (
                    ? = 'vencido' AND
                    CASE
                      WHEN a.periodicidad_pago <> 'mensual' THEN 0
                      WHEN a.mostrar_desde IS NULL OR a.mostrar_hasta IS NULL THEN 0
                      WHEN (MONTH(lp.ultimo_pago) = MONTH(CURDATE()) AND YEAR(lp.ultimo_pago) = YEAR(CURDATE())) THEN 0
                      WHEN DAY(CURDATE()) > a.mostrar_hasta THEN 1
                      ELSE 0
                    END = 1
                  )
               )";
    $types   .= 'ss';
    $params[] = $estado;
    $params[] = $estado;
  } else {
    // estado = "" -> todos (incluye cancelados y activos)
  }

  if ($q !== '') {
    $sql   .= " AND (a.deudor_nombre LIKE CONCAT('%', ?, '%')
                 OR  a.concepto      LIKE CONCAT('%', ?, '%'))";
    $types .= 'ss';
    $params[] = $q; $params[] = $q;
  }

  $sql   .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
  $types .= 'ii';
  $params[] = $limit; $params[] = $offset;

  $st = $db->prepare($sql);
  if (!$st) fail('SQL prepare error: '.$db->error, 500);
  if ($types) $st->bind_param($types, ...$params);
  if (!$st->execute()) fail($st->error ?: 'SQL exec error', 500);

  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  ok(['items' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

/** GET /adeudos/:id */
public static function show($id) {
$db = DB::conn();
$st = $db->prepare("SELECT * FROM v_adeudos_saldos WHERE id = ?");
$st->bind_param('i', $id);
$st->execute();
$res = $st->get_result();
$adeudo = $res->fetch_assoc();
$st->close();
if (!$adeudo) fail('Adeudo no encontrado', 404);


// pagos
$st = $db->prepare("SELECT id, fecha_pago, monto, interes, (monto - interes) AS capital, metodo, referencia, notas, created_at
FROM pagos WHERE adeudo_id = ? ORDER BY fecha_pago DESC");
$st->bind_param('i', $id);
$st->execute();
$pagos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();


ok(['adeudo' => $adeudo, 'pagos' => $pagos]);
}


/** POST /adeudos */
public static function store() {
  $db = DB::conn();
  $b  = body_json();

  // === 1) Leer y validar payload ===
  $deudor          = req_str($b, 'deudor_nombre');
  $concepto        = req_str($b, 'concepto') ?? '';
  $monto_total     = req_num($b, 'monto_total');
  $monto_ini       = req_num($b, 'monto_pagado_inicial') ?? 0;

  $fecha_inicio    = req_str($b, 'fecha_inicio');          // obligatoria
  $fecha_fin       = isset($b['fecha_fin']) ? (string)$b['fecha_fin'] : null; // opcional

  $mostrar_desde = array_key_exists('mostrar_desde', $b) && $b['mostrar_desde'] !== '' ? (int)$b['mostrar_desde'] : null;
  $mostrar_hasta = array_key_exists('mostrar_hasta', $b) && $b['mostrar_hasta'] !== '' ? (int)$b['mostrar_hasta'] : null;

  foreach (['mostrar_desde','mostrar_hasta'] as $k) {
    if (!is_null($$k) && ($$k < 1 || $$k > 31)) fail("$k debe estar entre 1 y 31", 422);
  }
  if (!is_null($mostrar_desde) && !is_null($mostrar_hasta) && $mostrar_desde > $mostrar_hasta) {
    fail('mostrar_desde no puede ser mayor que mostrar_hasta', 422);
  }

  $tasa            = req_num($b, 'tasa_interes_anual') ?? 0;
  $tipo            = req_str($b, 'interes_tipo') ?: 'simple';
  $periodicidad    = req_str($b, 'periodicidad_pago') ?: 'mensual';
  $estado          = req_str($b, 'estado') ?: 'activo';
  $notas           = isset($b['notas']) ? (string)$b['notas'] : '';

  if (!$deudor)        fail('El campo deudor_nombre es obligatorio', 422);
  if ($monto_total === null) fail('El campo monto_total es obligatorio', 422);
  if (!$fecha_inicio)  fail('El campo fecha_inicio es obligatorio', 422);

  // Normalizaciones
  $tipo         = in_array($tipo, ['simple','compuesto']) ? $tipo : 'simple';
  $pp           = strtolower((string)$periodicidad);
  if ($pp === 'unico') $pp = 'único';
  $periodicidad = in_array($pp, ['semanal','quincenal','mensual','bimestral','trimestral','único']) ? $pp : 'mensual';
  $estado       = in_array(strtolower((string)$estado), ['activo','liquidado','vencido','cancelado']) ? strtolower((string)$estado) : 'activo';

  // Validaciones de fechas
  if ($fecha_fin && strtotime($fecha_inicio) > strtotime($fecha_fin)) {
    fail('fecha_inicio no puede ser mayor que fecha_fin', 422);
  }

  // === 2) INSERT ===
  $sql = "INSERT INTO adeudos (
  deudor_nombre, concepto,
  monto_total, deuda_inicial, monto_pagado_inicial,
  fecha_inicio, fecha_fin, mostrar_desde, mostrar_hasta,
  tasa_interes_anual, interes_tipo, periodicidad_pago,
  estado, notas
) VALUES (?,?,?,?,?, ?,?,?, ?, ?,?,?, ?, ?)";

  $st = $db->prepare($sql);
  if (!$st) fail('Error al preparar INSERT: '.$db->error, 500);

  // Tipos: s=string, d=double
$types = 'ssdddssiidssss';

$ok = $st->bind_param(
  $types,
  $deudor,           // s
  $concepto,         // s
  $monto_total,      // d
  $monto_total,      // d (deuda_inicial = monto_total)
  $monto_ini,        // d
  $fecha_inicio,     // s
  $fecha_fin,        // s (o null)
  $mostrar_desde,    // i (o null)
  $mostrar_hasta,    // i (o null)
  $tasa,             // d
  $tipo,             // s
  $periodicidad,     // s
  $estado,           // s
  $notas             // s
);

  if (!$ok) fail('Error al enlazar parámetros', 500);

  if (!$st->execute()) {
    $err = $st->error ?: 'Fallo al insertar';
    $st->close();
    fail($err, 500);
  }
  $newId = $db->insert_id;
  $st->close();

  ok(['message' => 'Adeudo creado', 'id' => $newId], 201);
}

// en adeudos_controller.php
/** PUT /adeudos/:id */
public static function update($id) {
  $db = DB::conn();
  $b  = body_json();

  // 1) Traer registro actual
  $st = $db->prepare("SELECT * FROM adeudos WHERE id = ?");
  $st->bind_param('i', $id);
  $st->execute();
  $cur = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$cur) fail('Adeudo no encontrado', 404);

  // 2) Whitelist + merge (campos editables)
  $fields = [
    'deudor_nombre',
    'concepto',
    'monto_total',
    'monto_pagado_inicial',
    'fecha_inicio',
    'fecha_fin',
    'mostrar_desde',       // NUEVO
    'mostrar_hasta',       // NUEVO
    'tasa_interes_anual',
    'interes_tipo',
    'periodicidad_pago',
    'estado',
    'notas'
  ];
  $data = [];
  foreach ($fields as $f) {
    $data[$f] = array_key_exists($f, $b) ? $b[$f] : $cur[$f];
  }

  // 3) Normalizaciones / validaciones suaves
  foreach (['monto_total','monto_pagado_inicial','tasa_interes_anual'] as $k) {
    $data[$k] = ($data[$k] === '' || $data[$k] === null) ? 0 : (float)$data[$k];
  }

  // Fechas
  $data['fecha_inicio']  = $data['fecha_inicio'] ?: $cur['fecha_inicio'];
  $data['fecha_fin']     = isset($data['fecha_fin']) ? (string)$data['fecha_fin'] : '';
  $data['mostrar_desde'] = array_key_exists('mostrar_desde', $b) ? ($b['mostrar_desde'] === '' ? null : (int)$b['mostrar_desde']) : $cur['mostrar_desde'];
$data['mostrar_hasta'] = array_key_exists('mostrar_hasta', $b) ? ($b['mostrar_hasta'] === '' ? null : (int)$b['mostrar_hasta']) : $cur['mostrar_hasta'];

foreach (['mostrar_desde','mostrar_hasta'] as $k) {
  if (!is_null($data[$k]) && ($data[$k] < 1 || $data[$k] > 31)) fail("$k debe estar entre 1 y 31", 422);
}
if (!is_null($data['mostrar_desde']) && !is_null($data['mostrar_hasta']) && $data['mostrar_desde'] > $data['mostrar_hasta']) {
  fail('mostrar_desde no puede ser mayor que mostrar_hasta', 422);
}


  // enums
  $it = strtolower((string)$data['interes_tipo']);
  $data['interes_tipo'] = in_array($it, ['simple','compuesto']) ? $it : 'simple';

  $pp = strtolower((string)$data['periodicidad_pago']);
  if ($pp === 'unico') $pp = 'único';
  $validP = ['semanal','quincenal','mensual','bimestral','trimestral','único'];
  $data['periodicidad_pago'] = in_array($pp, $validP) ? $pp : 'mensual';

  $stt = strtolower((string)$data['estado']);
  $data['estado'] = in_array($stt, ['activo','liquidado','vencido','cancelado']) ? $stt : 'activo';

  // Validaciones de orden de fechas (si vienen)
  if ($data['fecha_fin'] && $data['fecha_inicio'] && strtotime($data['fecha_inicio']) > strtotime($data['fecha_fin'])) {
    fail('fecha_inicio no puede ser mayor que fecha_fin', 422);
  }

  // 4) UPDATE
  $sql = "UPDATE adeudos SET
            deudor_nombre = ?,
            concepto = ?,
            monto_total = ?,
            monto_pagado_inicial = ?,
            fecha_inicio = ?,
            fecha_fin = NULLIF(?, ''),         -- '' -> NULL
            mostrar_desde = ?,     
            mostrar_hasta = ?,     
             -- '' -> NULL
            tasa_interes_anual = ?,
            interes_tipo = ?,
            periodicidad_pago = ?,
            estado = ?,
            notas = ?,
            updated_at = NOW()
          WHERE id = ?";

  $st = $db->prepare($sql);
  if (!$st) fail('Error al preparar UPDATE: '.$db->error, 500);

  $ok = $st->bind_param(
  'ssddssiidssssi',
  $data['deudor_nombre'],     // s
  $data['concepto'],          // s
  $data['monto_total'],       // d
  $data['monto_pagado_inicial'], // d
  $data['fecha_inicio'],      // s
  $data['fecha_fin'],         // s
  $data['mostrar_desde'],     // i
  $data['mostrar_hasta'],     // i
  $data['tasa_interes_anual'],// d
  $data['interes_tipo'],      // s
  $data['periodicidad_pago'], // s
  $data['estado'],            // s
  $data['notas'],             // s
  $id                         // i
);
  if (!$ok) fail('Error al enlazar parámetros', 500);

  if (!$st->execute()) {
    $err = $st->error ?: 'Fallo al actualizar';
    $st->close();
    fail($err, 500);
  }
  $st->close();

  ok(['message' => 'Adeudo actualizado']);
}
/** DELETE /adeudos/:id */
/** DELETE /adeudos/:id */
public static function destroy($id) {
  $db = DB::conn();

  // --- Obtener la base de datos activa ---
  $dbNameRes = $db->query("SELECT DATABASE() AS db");
  $dbName = $dbNameRes ? ($dbNameRes->fetch_assoc()['db'] ?? '') : '';

  // --- Fila antes ---
  $st = $db->prepare("SELECT id, estado, deleted_at FROM adeudos WHERE id=?");
  $st->bind_param('i', $id);
  $st->execute();
  $before = $st->get_result()->fetch_assoc();
  $st->close();

  // --- UPDATE (soft delete) ---
  $st = $db->prepare("UPDATE adeudos 
                      SET estado='cancelado', deleted_at=NOW(), updated_at=NOW()
                      WHERE id=?");
  $st->bind_param('i', $id);
  $ok = $st->execute();
  $affected = $st->affected_rows;
  $err = $st->error;
  $st->close();

  // --- Fila después ---
  $st = $db->prepare("SELECT id, estado, deleted_at FROM adeudos WHERE id=?");
  $st->bind_param('i', $id);
  $st->execute();
  $after = $st->get_result()->fetch_assoc();
  $st->close();

  ok([
    'db' => $dbName,
    'id' => $id,
    'before' => $before,
    'after' => $after,
    'affected_rows' => $affected,
    'mysqli_error' => $err,
  ]);
}





}