<?php
/*
 * api/descubrimiento.php (con cabecera X-Kanaxta-Token)
 *
 * GET  → lo que necesita el extractor para descubrir fichas por sí solo:
 *        { referencias: [{id, nombre, cantidad_ref, unidad}],
 *          cadenas:     [{codigo, sitio_web, cubiertas: [ids de referencia con URL]}],
 *          otras:       {id_referencia: [{cadena, marca, nombre}]} }
 * POST → registra las URL que el extractor encontró:
 *        { "cadena": "olimpica", "fichas": [{ "referencia_id": 1, "url": "https://..." }] }
 *        No cambia una referencia que ya tiene URL en esa cadena (decisión del
 *        administrador), salvo que se envíe "forzar": true.
 */
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';
kx_manejar_errores_json();
kx_verificar_token();

$pdo = kx_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $refs = $pdo->query(
        'SELECT id, nombre_generico AS nombre, cantidad_ref, unidad FROM producto_referencia WHERE activo = 1 ORDER BY id'
    )->fetchAll();
    foreach ($refs as &$r) {
        $r['id'] = (int) $r['id'];
        $r['cantidad_ref'] = (float) $r['cantidad_ref'];
    }
    unset($r);

    $cadenas = $pdo->query('SELECT id, codigo, sitio_web FROM cadena ORDER BY id')->fetchAll();
    $cubiertas = [];
    foreach ($pdo->query(
        'SELECT c.codigo, e.producto_referencia_id AS ref_id
           FROM equivalencia e
           JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
           JOIN cadena c ON c.id = pc.cadena_id
          WHERE e.estado_revision <> \'rechazada\''
    ) as $f) {
        $cubiertas[$f['codigo']][(int) $f['ref_id']] = true;
    }
    $salida = [];
    foreach ($cadenas as $c) {
        $salida[] = [
            'codigo'     => $c['codigo'],
            'sitio_web'  => $c['sitio_web'],
            'cubiertas'  => array_keys($cubiertas[$c['codigo']] ?? []),
        ];
    }

    // Marca y nombre que ya publica cada cadena, para elegir en la otra una ficha de la misma marca
    $otras = [];
    foreach ($pdo->query(
        'SELECT e.producto_referencia_id AS ref_id, c.codigo, pc.marca, pc.nombre_publicado
           FROM equivalencia e
           JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
           JOIN cadena c ON c.id = pc.cadena_id
          WHERE e.estado_revision <> \'rechazada\' AND (pc.marca IS NOT NULL OR pc.nombre_publicado IS NOT NULL)'
    ) as $f) {
        $otras[(int) $f['ref_id']][] = ['cadena' => $f['codigo'], 'marca' => $f['marca'], 'nombre' => $f['nombre_publicado']];
    }
    kx_json(['ok' => true, 'referencias' => $refs, 'cadenas' => $salida, 'otras' => (object) $otras]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kx_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$datos = json_decode((string) file_get_contents('php://input', false, null, 0, 200_000), true);
if (!is_array($datos)) {
    kx_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}
$st = $pdo->prepare('SELECT id, codigo, nombre, sitio_web FROM cadena WHERE codigo = ?');
$st->execute([(string) ($datos['cadena'] ?? '')]);
$cadena = $st->fetch();
if (!$cadena) {
    kx_json(['ok' => false, 'error' => 'Cadena desconocida.'], 422);
}
$forzar = ($datos['forzar'] ?? false) === true;

$cubiertas = $pdo->prepare(
    'SELECT DISTINCT e.producto_referencia_id
       FROM equivalencia e JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
      WHERE pc.cadena_id = ? AND e.estado_revision <> \'rechazada\''
);
$cubiertas->execute([$cadena['id']]);
$yaTienen = array_map('intval', $cubiertas->fetchAll(PDO::FETCH_COLUMN));
$existe = $pdo->prepare('SELECT COUNT(*) FROM producto_referencia WHERE id = ? AND activo = 1');

$registradas = [];
$omitidas = [];
$pdo->beginTransaction();
foreach (array_slice((array) ($datos['fichas'] ?? []), 0, 50) as $f) {
    $refId = (int) ($f['referencia_id'] ?? 0);
    $url = trim((string) ($f['url'] ?? ''));
    $existe->execute([$refId]);
    if ($refId <= 0 || (int) $existe->fetchColumn() === 0) {
        $omitidas[] = ['referencia_id' => $refId, 'motivo' => 'Referencia inexistente o inactiva.'];
        continue;
    }
    if (!$forzar && in_array($refId, $yaTienen, true)) {
        $omitidas[] = ['referencia_id' => $refId, 'motivo' => 'Ya tiene URL en esta cadena.'];
        continue;
    }
    $error = kx_guardar_url($pdo, $refId, $cadena, $url);
    if ($error !== null) {
        $omitidas[] = ['referencia_id' => $refId, 'motivo' => $error];
        continue;
    }
    kx_recalcular_equivalencias($pdo, $refId);
    $registradas[] = $refId;
}
$pdo->commit();

kx_json(['ok' => true, 'registradas' => $registradas, 'omitidas' => $omitidas]);
