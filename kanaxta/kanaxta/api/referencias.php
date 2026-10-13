<?php
/*
 * GET api/referencias.php (con token)
 * Canasta activa con sus palabras clave, las cadenas y, por cada producto, qué
 * fichas ya existen (o fueron rechazadas) en cada cadena. El extractor lo usa
 * para saber qué fichas debe buscar solo.
 */
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';
kx_manejar_errores_json();
kx_verificar_token();

$pdo = kx_db();
$cadenas = $pdo->query('SELECT codigo, nombre, sitio_web FROM cadena ORDER BY id')->fetchAll();

$refs = [];
foreach ($pdo->query(
    'SELECT id, nombre_generico, categoria, cantidad_ref, unidad, palabras_clave, palabras_excluir
       FROM producto_referencia WHERE activo = 1 ORDER BY id'
) as $r) {
    $refs[(int) $r['id']] = [
        'id'               => (int) $r['id'],
        'nombre'           => $r['nombre_generico'],
        'cantidad_ref'     => (float) $r['cantidad_ref'],
        'unidad'           => $r['unidad'],
        'palabras_clave'   => $r['palabras_clave'] ?? '',
        'palabras_excluir' => $r['palabras_excluir'] ?? '',
        'fichas'           => new stdClass(),   // cadena => {url, marca, nombre}
        'rechazadas'       => new stdClass(),   // cadena => [url, ...]
    ];
}

$fichas = [];
$rechazadas = [];
foreach ($pdo->query(
    'SELECT e.producto_referencia_id AS ref_id, e.estado_revision, c.codigo, pc.url, pc.marca, pc.nombre_publicado
       FROM equivalencia e
       JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
       JOIN cadena c ON c.id = pc.cadena_id
   ORDER BY e.id'
) as $f) {
    $id = (int) $f['ref_id'];
    if (!isset($refs[$id])) {
        continue;
    }
    if ($f['estado_revision'] === 'rechazada') {
        $rechazadas[$id][$f['codigo']][] = $f['url'];
    } else {
        $fichas[$id][$f['codigo']] ??= ['url' => $f['url'], 'marca' => $f['marca'], 'nombre' => $f['nombre_publicado']];
    }
}
foreach ($refs as $id => &$r) {
    if (isset($fichas[$id])) {
        $r['fichas'] = $fichas[$id];
    }
    if (isset($rechazadas[$id])) {
        $r['rechazadas'] = $rechazadas[$id];
    }
}
unset($r);

kx_json(['ok' => true, 'cadenas' => $cadenas, 'referencias' => array_values($refs)]);
