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

  // SELECT sin usar dia_pago como fecha
  $sql = "SELECT
            a.id, a.deudor_nombre, a.concepto,
            a.monto_total, a.deuda_inicial, a.monto_pagado_inicial,
            a.fecha_inicio, a.fecha_fin,
            a.dias_muestra, a.dia_pago,
            a.tasa_interes_anual, a.interes_tipo, a.periodicidad_pago,
            a.estado, a.notas, a.created_at, a.updated_at, a.deleted_at,
            a.tipo_calculo, a.numero_pagos, a.tasa_iva_interes,
            IFNULL(p.capital_pagado, 0)   AS capital_pagado,
            IFNULL(p.interes_generado, 0) AS interes_generado,
            (a.monto_total - (a.monto_pagado_inicial + IFNULL(p.capital_pagado,0))) AS saldo_pendiente,
            lp.ultimo_pago,
            0         AS vencido_calc,      -- ya no calculamos aquí
            a.estado  AS estado_calculado   -- el front usa esto
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

  // Filtro por estado
  if ($estado === 'cancelado') {
    // cancelados = los que tienen deleted_at
    $sql .= " AND a.deleted_at IS NOT NULL";
  } elseif ($estado !== '') {
    // cualquier otro estado: solo activos (sin deleted_at) con ese estado
    $sql   .= " AND a.deleted_at IS NULL AND a.estado = ?";
    $types .= 's';
    $params[] = $estado;
  } else {
    // estado = "" -> todos (incluye cancelados y activos)
  }

  // Búsqueda por texto
  if ($q !== '') {
    $sql   .= " AND (a.deudor_nombre LIKE CONCAT('%', ?, '%')
                 OR  a.concepto      LIKE CONCAT('%', ?, '%'))";
    $types   .= 'ss';
    $params[] = $q;
    $params[] = $q;
  }

  $sql   .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
  $types .= 'ii';
  $params[] = $limit;
  $params[] = $offset;

  $st = $db->prepare($sql);
  if (!$st) fail('SQL prepare error: '.$db->error, 500);

  if ($types) {
    $st->bind_param($types, ...$params);
  }

  if (!$st->execute()) {
    fail($st->error ?: 'SQL exec error', 500);
  }

  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  ok(['items' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

/** GET /adeudos/:id */
public static function show($id) {
  $db = DB::conn();

  // Traer adeudo + saldos calculados (sin usar la vista)
  $sql = "SELECT
            a.*,
            IFNULL(p.capital_pagado, 0)   AS capital_pagado,
            IFNULL(p.interes_generado, 0) AS interes_generado,
            (a.monto_total - (a.monto_pagado_inicial + IFNULL(p.capital_pagado,0))) AS saldo_pendiente,
            lp.ultimo_pago
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
          WHERE a.id = ?";

  $st = $db->prepare($sql);
  if (!$st) fail('SQL prepare error: '.$db->error, 500);
  $st->bind_param('i', $id);
  $st->execute();
  $res = $st->get_result();
  $adeudo = $res->fetch_assoc();
  $st->close();

  if (!$adeudo) fail('Adeudo no encontrado', 404);

  // pagos de ese adeudo
  $st = $db->prepare("SELECT
                        id, fecha_pago, monto, interes,
                        (monto - interes) AS capital,
                        metodo, referencia, notas, created_at
                      FROM pagos
                      WHERE adeudo_id = ?
                      ORDER BY fecha_pago DESC");
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

  // Básicos
  $deudor      = req_str($b, 'deudor_nombre');
  $concepto    = isset($b['concepto']) ? (string)$b['concepto'] : '';
  $monto_total = req_num($b, 'monto_total');
  $monto_ini   = req_num($b, 'monto_pagado_inicial') ?? 0;

  $fecha_inicio = req_str($b, 'fecha_inicio'); // obligatoria
  $fecha_fin    = (isset($b['fecha_fin']) && $b['fecha_fin'] !== '')
    ? (string)$b['fecha_fin']
    : null; // opcional

  // === NUEVOS CAMPOS: dias_muestra (0–31) y dia_pago (1–31 TINYINT) ===

  // dias_muestra puede ser null o 0–31
  $dias_muestra = (array_key_exists('dias_muestra', $b) && $b['dias_muestra'] !== '' && $b['dias_muestra'] !== null)
    ? (int)$b['dias_muestra']
    : null;

  if (!is_null($dias_muestra) && ($dias_muestra < 0 || $dias_muestra > 31)) {
    fail("dias_muestra debe estar entre 0 y 31", 422);
  }

  // dia_pago ahora es TINYINT (1–31) o NULL
  $dia_pago = (array_key_exists('dia_pago', $b) && $b['dia_pago'] !== '' && $b['dia_pago'] !== null)
    ? (int)$b['dia_pago']
    : null;

  if (!is_null($dia_pago) && ($dia_pago < 1 || $dia_pago > 31)) {
    fail("dia_pago debe estar entre 1 y 31 (día del mes)", 422);
  }

  // Interés y configuración
  $tasa         = req_num($b, 'tasa_interes_anual') ?? 0;
  $tipo         = req_str($b, 'interes_tipo') ?: 'simple';
  $periodicidad = req_str($b, 'periodicidad_pago') ?: 'mensual';
  $estado       = req_str($b, 'estado') ?: 'activo';
  $notas        = isset($b['notas']) ? (string)$b['notas'] : '';

  if (!$deudor)              fail('El campo deudor_nombre es obligatorio', 422);
  if ($monto_total === null) fail('El campo monto_total es obligatorio', 422);
  if (!$fecha_inicio)        fail('El campo fecha_inicio es obligatorio', 422);

  // Normalizaciones de enums
  $tipo = in_array($tipo, ['simple','compuesto']) ? $tipo : 'simple';

  $pp = strtolower((string)$periodicidad);
  if ($pp === 'unico') $pp = 'único';
  $periodicidad = in_array($pp, ['semanal','quincenal','mensual','bimestral','trimestral','único'])
    ? $pp
    : 'mensual';

  $estado = in_array(strtolower((string)$estado), ['activo','liquidado','vencido','cancelado'])
    ? strtolower((string)$estado)
    : 'activo';

  // Validaciones de fechas
  if ($fecha_fin && strtotime($fecha_inicio) > strtotime($fecha_fin)) {
    fail('fecha_inicio no puede ser mayor que fecha_fin', 422);
  }

  // === Deuda inicial (capital base del crédito) ===
  // Si no te mandan nada, la dejamos igual a monto_total
 // === Deuda inicial (capital) ===
// Si el cliente no manda 'deuda_inicial', usamos monto_total.
// Este valor NO se debe modificar con los pagos (es el crédito original).
if (array_key_exists('deuda_inicial', $b)) {
    // viene en el payload
    $deuda_inicial = ($b['deuda_inicial'] === '' || $b['deuda_inicial'] === null)
        ? $monto_total
        : (float)$b['deuda_inicial'];
} else {
    // no viene: por defecto es el monto_total
    $deuda_inicial = $monto_total;
}


  // === Config de cálculo ===
  $tipo_calculo = isset($b['tipo_calculo']) ? (string)$b['tipo_calculo'] : 'sin_interes';
  $numero_pagos = (array_key_exists('numero_pagos', $b) && $b['numero_pagos'] !== '' && $b['numero_pagos'] !== null)
    ? (int)$b['numero_pagos']
    : null;

  $tasa_iva = (array_key_exists('tasa_iva_interes', $b) && $b['tasa_iva_interes'] !== '' && $b['tasa_iva_interes'] !== null)
    ? (float)$b['tasa_iva_interes']
    : null;

  // Normalizar tipo_calculo
  $tipo_calculo = in_array($tipo_calculo, ['sin_interes','interes_fijo','manual'])
    ? $tipo_calculo
    : 'sin_interes';

  // Validaciones específicas
  if (in_array($tipo_calculo, ['sin_interes','interes_fijo']) &&
      (!$numero_pagos || $numero_pagos <= 0)) {
    fail('numero_pagos es obligatorio y debe ser mayor a 0 para este tipo de cálculo', 422);
  }

  if ($tasa < 0) {
    fail('tasa_interes_anual no puede ser negativa', 422);
  }

  if ($tasa_iva !== null && $tasa_iva < 0) {
    fail('tasa_iva_interes no puede ser negativa', 422);
  }

  // Default 16% cuando haya interes_fijo y no manden IVA
  if ($tasa_iva === null && $tipo_calculo === 'interes_fijo') {
    $tasa_iva = 16.00;
  }

  // === 2) INSERT ===
  $sql = "INSERT INTO adeudos (
            deudor_nombre, concepto,
            monto_total, deuda_inicial, monto_pagado_inicial,
            fecha_inicio, fecha_fin, dias_muestra, dia_pago,
            tasa_interes_anual, interes_tipo, periodicidad_pago,
            estado, notas,
            tipo_calculo, numero_pagos, tasa_iva_interes
          ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

  $st = $db->prepare($sql);
  if (!$st) fail('Error al preparar INSERT: '.$db->error, 500);

  // Tipos:
  // s  deudor_nombre
  // s  concepto
  // d  monto_total
  // d  deuda_inicial
  // d  monto_pagado_inicial
  // s  fecha_inicio
  // s  fecha_fin (puede ser null)
  // i  dias_muestra (puede ser null)
  // i  dia_pago (1–31 o null)
  // d  tasa_interes_anual
  // s  interes_tipo
  // s  periodicidad_pago
  // s  estado
  // s  notas
  // s  tipo_calculo
  // i  numero_pagos (puede ser null)
  // d  tasa_iva_interes (puede ser null)
  $types = 'ssdddssiidsssssid';

  $ok = $st->bind_param(
    $types,
    $deudor,           // s
    $concepto,         // s
    $monto_total,      // d
    $deuda_inicial,    // d
    $monto_ini,        // d
    $fecha_inicio,     // s
    $fecha_fin,        // s (o null)
    $dias_muestra,     // i (o null)
    $dia_pago,         // i (o null)
    $tasa,             // d
    $tipo,             // s
    $periodicidad,     // s
    $estado,           // s
    $notas,            // s
    $tipo_calculo,     // s
    $numero_pagos,     // i (o null)
    $tasa_iva          // d (o null)
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

  /** PUT /adeudos/:id */
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

  // 2) Campos editables (NO tocamos deuda_inicial)
  $fields = [
    'deudor_nombre',
    'concepto',
    'monto_total',
    'monto_pagado_inicial',
    'fecha_inicio',
    'fecha_fin',
    'dias_muestra',
    'dia_pago',              // ahora TINYINT
    'tasa_interes_anual',
    'interes_tipo',
    'periodicidad_pago',
    'estado',
    'notas',
    'tipo_calculo',
    'numero_pagos',
    'tasa_iva_interes',
  ];

  // 3) Merge body + registro actual
  $data = [];
  foreach ($fields as $f) {
    $data[$f] = array_key_exists($f, $b) ? $b[$f] : $cur[$f];
  }

  // 4) Normalizar numéricos básicos
  foreach (['monto_total','monto_pagado_inicial','tasa_interes_anual','tasa_iva_interes'] as $k) {
    $data[$k] = ($data[$k] === '' || $data[$k] === null) ? 0 : (float)$data[$k];
  }

  // Fechas base
  $data['fecha_inicio'] = $data['fecha_inicio'] ?: $cur['fecha_inicio'];
  $data['fecha_fin']    = ($data['fecha_fin'] === '' ? null : $data['fecha_fin']);

  // dias_muestra: null o 0–31
  if (array_key_exists('dias_muestra', $b)) {
    $data['dias_muestra'] = ($b['dias_muestra'] === '' || $b['dias_muestra'] === null)
      ? null
      : (int)$b['dias_muestra'];
  } else {
    $data['dias_muestra'] = $cur['dias_muestra'];
  }

  if (!is_null($data['dias_muestra']) &&
      ($data['dias_muestra'] < 0 || $data['dias_muestra'] > 31)) {
    fail("dias_muestra debe estar entre 0 y 31", 422);
  }

  // dia_pago: ahora TINYINT(1–31) o NULL
  if (array_key_exists('dia_pago', $b)) {
    $data['dia_pago'] = ($b['dia_pago'] === '' || $b['dia_pago'] === null)
      ? null
      : (int)$b['dia_pago'];
  } else {
    $data['dia_pago'] = $cur['dia_pago'];
  }

  if (!is_null($data['dia_pago']) &&
      ($data['dia_pago'] < 1 || $data['dia_pago'] > 31)) {
    fail("dia_pago debe estar entre 1 y 31 (día del mes)", 422);
  }

  // enums: interes_tipo, periodicidad, estado
  $it = strtolower((string)$data['interes_tipo']);
  $data['interes_tipo'] = in_array($it, ['simple','compuesto']) ? $it : 'simple';

  $pp = strtolower((string)$data['periodicidad_pago']);
  if ($pp === 'unico') $pp = 'único';
  $validP = ['semanal','quincenal','mensual','bimestral','trimestral','único'];
  $data['periodicidad_pago'] = in_array($pp, $validP) ? $pp : 'mensual';

  $stt = strtolower((string)$data['estado']);
  $data['estado'] = in_array($stt, ['activo','liquidado','vencido','cancelado']) ? $stt : 'activo';

  // tipo_calculo
  $tc = (string)$data['tipo_calculo'];
  $data['tipo_calculo'] = in_array($tc, ['sin_interes','interes_fijo','manual'])
    ? $tc
    : ($cur['tipo_calculo'] ?? 'sin_interes');

  // numero_pagos (nullable)
  if (array_key_exists('numero_pagos', $b)) {
    $data['numero_pagos'] = ($b['numero_pagos'] === '' || $b['numero_pagos'] === null)
      ? null
      : (int)$b['numero_pagos'];
  } else {
    $data['numero_pagos'] = $cur['numero_pagos'];
  }

  // Validación de numero_pagos para tipos que lo requieren
  if (in_array($data['tipo_calculo'], ['sin_interes','interes_fijo']) &&
      (!$data['numero_pagos'] || $data['numero_pagos'] <= 0)) {
    fail('numero_pagos es obligatorio y debe ser mayor a 0 para este tipo de cálculo', 422);
  }

  // tasa_iva_interes: permitir null y default 16 en interes_fijo
  if (!array_key_exists('tasa_iva_interes', $b) && $cur['tasa_iva_interes'] !== null) {
    $data['tasa_iva_interes'] = (float)$cur['tasa_iva_interes'];
  }

  if ($data['tasa_interes_anual'] < 0) {
    fail('tasa_interes_anual no puede ser negativa', 422);
  }

  if (!is_null($data['tasa_iva_interes']) && $data['tasa_iva_interes'] < 0) {
    fail('tasa_iva_interes no puede ser negativa', 422);
  }

  if ($data['tasa_iva_interes'] === null && $data['tipo_calculo'] === 'interes_fijo') {
    $data['tasa_iva_interes'] = 16.00;
  }

  // Validación fechas
  if ($data['fecha_fin'] && $data['fecha_inicio'] &&
      strtotime($data['fecha_inicio']) > strtotime($data['fecha_fin'])) {
    fail('fecha_inicio no puede ser mayor que fecha_fin', 422);
  }

  // 5) UPDATE
  $sql = "UPDATE adeudos SET
            deudor_nombre       = ?,
            concepto            = ?,
            monto_total         = ?,
            monto_pagado_inicial= ?,
            fecha_inicio        = ?,
            fecha_fin           = NULLIF(?, ''),
            dias_muestra        = ?,
            dia_pago            = ?,
            tasa_interes_anual  = ?,
            interes_tipo        = ?,
            periodicidad_pago   = ?,
            estado              = ?,
            notas               = ?,
            tipo_calculo        = ?,
            numero_pagos        = ?,
            tasa_iva_interes    = ?,
            updated_at          = NOW()
          WHERE id = ?";

  $st = $db->prepare($sql);
  if (!$st) fail('Error al preparar UPDATE: '.$db->error, 500);

  // Tipos:
  // s s d d s s i i d s s s s s i d i
  $ok = $st->bind_param(
    'ssddssiidsssssidi',
    $data['deudor_nombre'],        // s
    $data['concepto'],             // s
    $data['monto_total'],          // d
    $data['monto_pagado_inicial'], // d
    $data['fecha_inicio'],         // s
    $data['fecha_fin'],            // s (puede ser null)
    $data['dias_muestra'],         // i (puede ser null)
    $data['dia_pago'],             // i (puede ser null)
    $data['tasa_interes_anual'],   // d
    $data['interes_tipo'],         // s
    $data['periodicidad_pago'],    // s
    $data['estado'],               // s
    $data['notas'],                // s
    $data['tipo_calculo'],         // s
    $data['numero_pagos'],         // i (puede ser null)
    $data['tasa_iva_interes'],     // d (puede ser null)
    $id                            // i
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
