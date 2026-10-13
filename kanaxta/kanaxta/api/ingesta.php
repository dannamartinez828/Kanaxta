<?php
/*
 * POST api/ingesta.php (JSON, con cabecera X-Kanaxta-Token)
 * Recibe el resultado de una corrida del extractor para una cadena:
 * {
 *   "cadena": "ara", "inicio": "2026-10-12T06:00:00-05:00", "fin": "...",
 *   "estado": "ok|parcial|error|bloqueado", "mensaje": "",
 *   "fichas": [{ "producto_cadena_id": 1, "nombre": "...", "marca": "...",
 *                "cantidad": 1000, "unidad": "g", "precio_normal": 5200,
 *                "precio_descuento": null, "fecha_hora": "...",
 *                "estado": "vigente|no_disponible", "detalle": "" }]
 * }
 */
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';
kx_manejar_errores_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kx_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}
kx_verificar_token();

$datos = json_decode((string) file_get_contents('php://input', false, null, 0, 1_000_000), true);
if (!is_array($datos)) {
    kx_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

/** Convierte una fecha ISO 8601 a hora de Bogotá (Y-m-d H:i:s). */
function kx_fecha(?string $valor): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($valor))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d H:i:s');
    } catch (Exception) {
        return null;
    }
}

function kx_precio(mixed $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 100_000_000) {
        return null;
    }
    return round((float) $v, 2);
}

$pdo = kx_db();
$cad = $pdo->prepare('SELECT id FROM cadena WHERE codigo = ?');
$cad->execute([(string) ($datos['cadena'] ?? '')]);
$cadenaId = $cad->fetchColumn();
if ($cadenaId === false) {
    kx_json(['ok' => false, 'error' => 'Cadena desconocida.'], 422);
}

$estado = in_array($datos['estado'] ?? '', ['ok', 'parcial', 'error', 'bloqueado'], true) ? $datos['estado'] : 'error';
$inicio = kx_fecha($datos['inicio'] ?? null) ?? date('Y-m-d H:i:s');
$fin = kx_fecha($datos['fin'] ?? null) ?? date('Y-m-d H:i:s');
$fichas = array_slice((array) ($datos['fichas'] ?? []), 0, 300);

$validos = $pdo->prepare('SELECT id FROM producto_cadena WHERE cadena_id = ?');
$validos->execute([$cadenaId]);
$idsValidos = array_map('intval', $validos->fetchAll(PDO::FETCH_COLUMN));

$pdo->beginTransaction();
$ej = $pdo->prepare('INSERT INTO ejecucion_extraccion (cadena_id, inicio, fin, estado, mensaje_error) VALUES (?, ?, ?, ?, ?)');
$ej->execute([$cadenaId, $inicio, $fin, $estado, mb_substr((string) ($datos['mensaje'] ?? ''), 0, 2000) ?: null]);
$ejecucionId = (int) $pdo->lastInsertId();

$updProducto = $pdo->prepare(
    'UPDATE producto_cadena
        SET nombre_publicado = COALESCE(?, nombre_publicado), marca = COALESCE(?, marca),
            cantidad = COALESCE(?, cantidad), unidad = COALESCE(?, unidad), actualizado_en = ?
      WHERE id = ?'
);
$insObs = $pdo->prepare(
    'INSERT INTO observacion_precio (producto_cadena_id, ejecucion_id, precio_normal, precio_descuento, fecha_hora, estado_vigencia, detalle)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$ok = 0;
$errores = 0;
$rechazadas = [];
$tocados = [];
foreach ($fichas as $i => $f) {
    $pcId = (int) ($f['producto_cadena_id'] ?? 0);
    if (!in_array($pcId, $idsValidos, true)) {
        $rechazadas[] = $i;
        continue;
    }
    $fecha = kx_fecha($f['fecha_hora'] ?? null) ?? $fin;
    $normal = kx_precio($f['precio_normal'] ?? null);
    $desc = kx_precio($f['precio_descuento'] ?? null);
    if ($normal !== null && $desc !== null && $desc >= $normal) {
        $desc = null;   // un "descuento" que no es menor no se considera descuento
    }
    $hayPrecio = $normal !== null || $desc !== null;
    $estadoFicha = (($f['estado'] ?? '') === 'vigente' && $hayPrecio) ? 'vigente' : 'no_disponible';
    $unidad = in_array($f['unidad'] ?? null, ['g', 'ml', 'und'], true) ? $f['unidad'] : null;
    $cantidad = is_numeric($f['cantidad'] ?? null) && (float) $f['cantidad'] > 0 ? (float) $f['cantidad'] : null;

    $updProducto->execute([
        isset($f['nombre']) && $f['nombre'] !== '' ? mb_substr((string) $f['nombre'], 0, 255) : null,
        isset($f['marca']) && $f['marca'] !== '' ? mb_substr((string) $f['marca'], 0, 120) : null,
        $unidad !== null ? $cantidad : null,
        $cantidad !== null ? $unidad : null,
        $fecha,
        $pcId,
    ]);
    $insObs->execute([$pcId, $ejecucionId, $normal, $desc, $fecha, $estadoFicha, mb_substr((string) ($f['detalle'] ?? ''), 0, 255) ?: null]);
    $estadoFicha === 'vigente' ? $ok++ : $errores++;
    $tocados[$pcId] = true;
}

$pdo->prepare('UPDATE ejecucion_extraccion SET fichas_ok = ?, fichas_error = ? WHERE id = ?')
    ->execute([$ok, $errores + count($rechazadas), $ejecucionId]);

// Recalcula la propuesta de equivalencia de los productos de referencia afectados
if ($tocados) {
    $marc = implode(',', array_fill(0, count($tocados), '?'));
    $refs = $pdo->prepare("SELECT DISTINCT producto_referencia_id FROM equivalencia WHERE producto_cadena_id IN ($marc)");
    $refs->execute(array_keys($tocados));
    foreach ($refs->fetchAll(PDO::FETCH_COLUMN) as $refId) {
        kx_recalcular_equivalencias($pdo, (int) $refId);
    }
}
$pdo->commit();

kx_json([
    'ok'           => true,
    'ejecucion_id' => $ejecucionId,
    'vigentes'     => $ok,
    'no_disponibles' => $errores,
    'rechazadas'   => $rechazadas,
]);
