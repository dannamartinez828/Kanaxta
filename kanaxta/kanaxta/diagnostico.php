<?php
// Diagnóstico de Kanaxta: BORRAR después de usarlo
header('Content-Type: text/plain; charset=utf-8');
echo "PHP: " . PHP_VERSION . "\n";
echo "Extensión pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'sí' : 'NO') . "\n";
$c = require __DIR__ . '/privado/config.php';
if (!is_array($c)) { exit("config.php no devuelve la configuración (revise que empiece con <?php)\n"); }
echo "Base: {$c['db_nombre']} | Usuario: {$c['db_usuario']} | Servidor: {$c['db_host']}\n";
echo "Largo del token: " . strlen($c['token_ingesta']) . " caracteres\n";
try {
    $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_nombre']};charset=utf8mb4",
        $c['db_usuario'], $c['db_clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    echo "Conexión: OK\n";
    foreach (['cadena', 'producto_referencia', 'parametro_transporte'] as $t) {
        echo "$t: " . $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() . " filas\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}