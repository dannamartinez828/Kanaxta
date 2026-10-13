<?php
/* GET api/productos.php → catálogo de la canasta, sedes y parámetros públicos. */
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';
kx_manejar_errores_json();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    kx_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$pdo = kx_db();

$productos = [];
foreach ($pdo->query('SELECT id, nombre_generico, categoria, cantidad_ref, unidad FROM producto_referencia WHERE activo = 1 ORDER BY categoria, nombre_generico') as $r) {
    $productos[] = [
        'id'           => (int) $r['id'],
        'nombre'       => $r['nombre_generico'],
        'categoria'    => $r['categoria'],
        'presentacion' => kx_presentacion_texto((float) $r['cantidad_ref'], $r['unidad']),
    ];
}

$sedes = [];
foreach ($pdo->query('SELECT c.codigo AS cadena, s.nombre, s.direccion, s.localidad, s.latitud, s.longitud
                        FROM sede s JOIN cadena c ON c.id = s.cadena_id WHERE s.activa = 1 ORDER BY c.id, s.nombre') as $s) {
    $sedes[] = [
        'cadena'    => $s['cadena'],
        'nombre'    => $s['nombre'],
        'direccion' => $s['direccion'],
        'localidad' => $s['localidad'],
        'latitud'   => (float) $s['latitud'],
        'longitud'  => (float) $s['longitud'],
    ];
}

$cadenas = $pdo->query('SELECT codigo, nombre FROM cadena ORDER BY id')->fetchAll();

$ultima = [];
foreach ($pdo->query("SELECT c.codigo, MAX(o.fecha_hora) AS ultima
                        FROM observacion_precio o
                        JOIN producto_cadena pc ON pc.id = o.producto_cadena_id
                        JOIN cadena c ON c.id = pc.cadena_id
                       WHERE o.estado_vigencia <> 'no_disponible'
                    GROUP BY c.codigo") as $u) {
    $ultima[$u['codigo']] = $u['ultima'];
}

$p = kx_parametros($pdo);

kx_json([
    'ok'         => true,
    'productos'  => $productos,
    'cadenas'    => $cadenas,
    'sedes'      => $sedes,
    'ultima_actualizacion' => $ultima,
    'parametros' => [
        'factor_recorrido'     => $p['factor_recorrido'],
        'rendimiento_km_galon' => $p['rendimiento_km_galon'],
        'parqueadero'          => $p['parqueadero'],
        'tarifa_publico'       => $p['tarifa_publico'],
        'horas_vigencia'       => (int) $p['horas_vigencia'],
    ],
]);
