<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/validate.php';

class PagosController {

  /**
   * GET /adeudos/:adeudoId/pagos
   */
  public static function index(int $adeudoId) {
    $db = DB::conn();

    // validar existencia del adeudo
    $st = $db->prepare("SELECT id, estado, deleted_at FROM adeudos WHERE id = ?");
    $st->bind_param('i', $adeudoId);
    $st->execute();
    $adeudo = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$adeudo) fail('Adeudo no encontrado', 404);

    $st = $db->prepare("
      SELECT id, adeudo_id, fecha_pago, monto, interes, capital, metodo, referencia, notas, created_at
      FROM pagos
      WHERE adeudo_id = ?
      ORDER BY fecha_pago DESC, id DESC
    ");
    $st->bind_param('i', $adeudoId);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    ok(['items' => $items, 'adeudo' => $adeudoId]);
  }

  /**
   * POST /adeudos/:adeudoId/pagos
   * body: { fecha_pago, monto, interes, metodo?, referencia?, notas? }
   * - capital se calcula como max(monto - interes, 0)
   * - descuenta capital de adeudos.monto_total (saldo)
   * - si saldo llega a 0 -> estado = 'liquidado'
   */
  public static function store(int $adeudoId) {
    $db = DB::conn();
    $b  = body_json();

    // 1) Validar adeudo
    $st = $db->prepare("SELECT id, monto_total, estado, deleted_at FROM adeudos WHERE id = ?");
    $st->bind_param('i', $adeudoId);
    $st->execute();
    $adeudo = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$adeudo) fail('Adeudo no encontrado', 404);
    if (!is_null($adeudo['deleted_at'])) fail('Adeudo cancelado; no admite pagos', 422);

    // 2) Leer payload
    $fecha_pago = req_str($b, 'fecha_pago') ?: date('Y-m-d H:i:s'); // admite date o datetime
    // Si viene solo "YYYY-MM-DD", complétalo con hora 00:00:00
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago)) $fecha_pago .= ' 00:00:00';

    $monto   = req_num($b, 'monto');
    $interes = req_num($b, 'interes') ?? 0;
    $metodo  = req_str($b, 'metodo') ?: 'efectivo';
    $ref     = isset($b['referencia']) ? (string)$b['referencia'] : '';
    $notas   = isset($b['notas']) ? (string)$b['notas'] : '';

    if ($monto === null || $monto <= 0) fail('monto debe ser > 0', 422);
    if ($interes < 0) fail('interes no puede ser negativo', 422);
    if ($interes > $monto) fail('interes no puede exceder el monto', 422);

    $capital = max(0, $monto - $interes);

    // No permitas pagar más capital del saldo
    $saldoActual = (float)$adeudo['monto_total'];
    if ($capital > $saldoActual) {
      // O bien: permitir y llevar saldo a 0; aquí lo capamos:
      $capital = $saldoActual;
      // y ajustamos monto/interes proporcional si quieres; para simplicidad, dejamos monto como viene.
    }

    // 3) Transacción
    $db->begin_transaction();
    try {
      // 3.1 Insert pago
      // 3.1 Insert pago
$st = $db->prepare("
  INSERT INTO pagos (
    adeudo_id,
    fecha_pago,
    monto,
    interes,
    metodo,
    referencia,
    notas,
    created_at
  )
  VALUES (?,?,?,?,?,?,?, NOW())
");
if (!$st) throw new Exception('SQL prepare INSERT pago: '.$db->error);

// tipos: i = int, s = string, d = double
$ok = $st->bind_param(
  'isddsss',
  $adeudoId,     // i
  $fecha_pago,   // s
  $monto,        // d
  $interes,      // d
  $metodo,       // s
  $ref,          // s
  $notas         // s
);
if (!$ok) throw new Exception('bind_param pago');
if (!$st->execute()) throw new Exception($st->error ?: 'exec INSERT pago');
$st->close();


      // 3.2 Descontar capital del saldo (adeudos.monto_total)
      $st = $db->prepare("
        UPDATE adeudos
        SET monto_total = GREATEST(0, monto_total - ?),
            estado = CASE WHEN (monto_total - ?) <= 0 THEN 'liquidado' ELSE estado END,
            updated_at = NOW()
        WHERE id = ?
      ");
      if (!$st) throw new Exception('SQL prepare UPDATE adeudos: '.$db->error);
      $ok = $st->bind_param('ddi', $capital, $capital, $adeudoId);
      if (!$ok) throw new Exception('bind_param update adeudo');
      if (!$st->execute()) throw new Exception($st->error ?: 'exec UPDATE adeudo');
      $st->close();

      $db->commit();
      ok(['message' => 'Pago registrado', 'adeudo_id' => $adeudoId]);
    } catch (Throwable $e) {
      $db->rollback();
      fail($e->getMessage(), 500);
    }
  }

  /**
   * DELETE /pagos/:id
   * - elimina el pago
   * - devuelve capital al saldo del adeudo
   * - si el adeudo estaba liquidado y vuelve a tener saldo > 0, cambia a 'activo'
   */
  public static function destroy(int $pagoId) {
    $db = DB::conn();

    // Traer pago
    $st = $db->prepare("SELECT id, adeudo_id, capital FROM pagos WHERE id = ?");
    $st->bind_param('i', $pagoId);
    $st->execute();
    $pago = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$pago) fail('Pago no encontrado', 404);

    $adeudoId = (int)$pago['adeudo_id'];
    $capital  = (float)$pago['capital'];

    $db->begin_transaction();
    try {
      // Eliminar pago
      $st = $db->prepare("DELETE FROM pagos WHERE id = ?");
      $st->bind_param('i', $pagoId);
      if (!$st->execute()) throw new Exception($st->error ?: 'exec DELETE pago');
      $st->close();

      // Reintegrar capital al saldo
      $st = $db->prepare("
        UPDATE adeudos
        SET monto_total = monto_total + ?,
            estado = CASE WHEN deleted_at IS NULL AND (monto_total + ?) > 0 AND estado = 'liquidado' THEN 'activo' ELSE estado END,
            updated_at = NOW()
        WHERE id = ?
      ");
      if (!$st) throw new Exception('SQL prepare UPDATE adeudo: '.$db->error);
      $ok = $st->bind_param('ddi', $capital, $capital, $adeudoId);
      if (!$ok) throw new Exception('bind_param reintegro');
      if (!$st->execute()) throw new Exception($st->error ?: 'exec reintegro');
      $st->close();

      $db->commit();
      ok(['message' => 'Pago eliminado', 'adeudo_id' => $adeudoId, 'reintegrado' => $capital]);
    } catch (Throwable $e) {
      $db->rollback();
      fail($e->getMessage(), 500);
    }
  }
}
