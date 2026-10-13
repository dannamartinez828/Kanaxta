<?php
/* POST api/comparar.php (JSON) → Pc, Ca y CTA por cadena. Ver kx_comparar(). */
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';
kx_manejar_errores_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kx_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$cuerpo = file_get_contents('php://input', false, null, 0, 20000);
$entrada = json_decode((string) $cuerpo, true);
if (!is_array($entrada)) {
    kx_json(['ok' => false, 'error' => 'Solicitud inválida.'], 400);
}

try {
    kx_json(kx_comparar(kx_db(), $entrada));
} catch (InvalidArgumentException $e) {
    kx_json(['ok' => false, 'error' => $e->getMessage()], 422);
}
