<?php
/* GET api/objetivos.php (con token) → fichas que debe visitar el extractor. */
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';
kx_manejar_errores_json();
kx_verificar_token();

$pdo = kx_db();
$filas = $pdo->query(
    "SELECT DISTINCT pc.id AS producto_cadena_id, c.codigo AS cadena, pc.url, pr.nombre_generico AS referencia
       FROM equivalencia e
       JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
       JOIN cadena c ON c.id = pc.cadena_id
       JOIN producto_referencia pr ON pr.id = e.producto_referencia_id
      WHERE e.estado_revision <> 'rechazada' AND pr.activo = 1
   ORDER BY cadena, referencia"
)->fetchAll();

foreach ($filas as &$f) {
    $f['producto_cadena_id'] = (int) $f['producto_cadena_id'];
}
unset($f);

kx_json(['ok' => true, 'objetivos' => $filas]);
