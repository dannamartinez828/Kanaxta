<?php
/*
 * POST api/descubrir.php (JSON, con cabecera X-Kanaxta-Token)
 * { "cadena": "olimpica", "referencia_id": 1, "url": "https://www.olimpica.com/.../p" }
 * Registra la ficha que el extractor encontró solo. Queda como equivalencia
 * "pendiente / propuesta": el administrador la revisa en admin/equivalencias.php.
 * No pisa una ficha existente (ni una puesta a mano ni una ya propuesta).
 */
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';
kx_manejar_errores_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kx_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}
kx_verificar_token();

$d = json_decode((string) file_get_contents('php://input', false, null, 0, 100_000), true);
if (!is_array($d)) {
    kx_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

/** Dominio registrable: www.olimpica.com → olimpica.com; www.d1.com.co → d1.com.co. */
function kx_dominio_base_api(string $host): string
{
    $p = explode('.', strtolower($host));
    $n = count($p);
    if ($n >= 3 && strlen($p[$n - 1]) === 2 && in_array($p[$n - 2], ['com', 'net', 'org', 'gov', 'edu', 'co'], true)) {
        return implode('.', array_slice($p, -3));
    }
    return implode('.', array_slice($p, -2));
}

$pdo = kx_db();
$cad = $pdo->prepare('SELECT id, sitio_web FROM cadena WHERE codigo = ?');
$cad->execute([(string) ($d['cadena'] ?? '')]);
$cadena = $cad->fetch();
if (!$cadena) {
    kx_json(['ok' => false, 'error' => 'Cadena desconocida.'], 422);
}

$refId = (int) ($d['referencia_id'] ?? 0);
$ref = $pdo->prepare('SELECT COUNT(*) FROM producto_referencia WHERE id = ? AND activo = 1');
$ref->execute([$refId]);
if ((int) $ref->fetchColumn() === 0) {
    kx_json(['ok' => false, 'error' => 'Producto de referencia desconocido.'], 422);
}

$url = trim((string) ($d['url'] ?? ''));
$host = strtolower((string) parse_url($url, PHP_URL_HOST));
$base = kx_dominio_base_api((string) parse_url((string) $cadena['sitio_web'], PHP_URL_HOST));
if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') || strlen($url) > 500
    || $base === '' || ($host !== $base && !str_ends_with($host, '.' . $base))) {
    kx_json(['ok' => false, 'error' => 'La URL no es válida o no pertenece al dominio de la cadena.'], 422);
}

// ¿Ya hay ficha (no rechazada) de esta cadena para este producto?
$ya = $pdo->prepare(
    "SELECT COUNT(*) FROM equivalencia e JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
      WHERE e.producto_referencia_id = ? AND pc.cadena_id = ? AND e.estado_revision <> 'rechazada'"
);
$ya->execute([$refId, $cadena['id']]);
if ((int) $ya->fetchColumn() > 0) {
    kx_json(['ok' => true, 'registrada' => false, 'motivo' => 'Ya existe una ficha de esa cadena para el producto.']);
}

$pdo->beginTransaction();
$pc = $pdo->prepare('SELECT id FROM producto_cadena WHERE url = ?');
$pc->execute([$url]);
$pcId = $pc->fetchColumn();
if ($pcId === false) {
    $pdo->prepare('INSERT INTO producto_cadena (cadena_id, url) VALUES (?, ?)')->execute([$cadena['id'], $url]);
    $pcId = (int) $pdo->lastInsertId();
}
$pdo->prepare(
    "INSERT IGNORE INTO equivalencia (producto_referencia_id, producto_cadena_id, tipo, estado_revision, nota)
     VALUES (?, ?, 'pendiente', 'propuesta', 'Ficha encontrada automáticamente por el extractor; pendiente de revisión.')"
)->execute([$refId, (int) $pcId]);
kx_recalcular_equivalencias($pdo, $refId);
$pdo->commit();

kx_json(['ok' => true, 'registrada' => true, 'producto_cadena_id' => (int) $pcId]);
