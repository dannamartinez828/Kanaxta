<?php
declare(strict_types=1);

/** Carga la configuración una sola vez y fija la zona horaria de Colombia. */
function kx_config(): array
{
    static $config = null;
    if ($config === null) {
        $archivo = __DIR__ . '/config.php';
        if (!is_file($archivo)) {
            http_response_code(500);
            exit('Falta privado/config.php. Copie config.ejemplo.php y complételo.');
        }
        $config = require $archivo;
        date_default_timezone_set($config['zona_horaria'] ?? 'America/Bogota');
    }
    return $config;
}

/** Conexión PDO a MySQL con la hora de Colombia (UTC-5). */
function kx_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = kx_config();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['db_host'], $c['db_nombre']);
    $pdo = new PDO($dsn, $c['db_usuario'], $c['db_clave'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '-05:00'");
    return $pdo;
}

/** Respuesta JSON y fin de la ejecución. */
function kx_json(array $datos, int $estado = 200): never
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Captura errores no controlados en los endpoints JSON sin exponer detalles. */
function kx_manejar_errores_json(): void
{
    set_exception_handler(static function (Throwable $e): void {
        error_log('[Kanaxta] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
        kx_json(['ok' => false, 'error' => 'Error interno del servidor.'], 500);
    });
}

/** Verifica el token del extractor (cabecera X-Kanaxta-Token). */
function kx_verificar_token(): void
{
    $esperado = (string) (kx_config()['token_ingesta'] ?? '');
    $recibido = (string) ($_SERVER['HTTP_X_KANAXTA_TOKEN'] ?? '');
    if (strlen($esperado) < 32 || str_starts_with($esperado, 'CAMBIE_')) {
        kx_json(['ok' => false, 'error' => 'Configure un token_ingesta de al menos 32 caracteres en config.php.'], 500);
    }
    if ($recibido === '' || !hash_equals($esperado, $recibido)) {
        kx_json(['ok' => false, 'error' => 'Token inválido.'], 401);
    }
}
