<?php
declare(strict_types=1);
require_once __DIR__ . '/../privado/funciones.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('kanaxta_admin');
    session_start();
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kx_admin_actual(): ?array
{
    return isset($_SESSION['admin_id']) ? ['id' => (int) $_SESSION['admin_id'], 'usuario' => (string) $_SESSION['admin_usuario']] : null;
}

function kx_requerir_login(): array
{
    $a = kx_admin_actual();
    if ($a === null) {
        header('Location: login.php');
        exit;
    }
    return $a;
}

function kx_csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function kx_csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h(kx_csrf()) . '">';
}

function kx_csrf_verificar(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Solicitud inválida (CSRF). Vuelva a cargar la página.');
    }
}

function kx_flash(?string $mensaje = null, string $tipo = 'ok'): ?array
{
    if ($mensaje !== null) {
        $_SESSION['flash'] = ['m' => $mensaje, 't' => $tipo];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function kx_redirigir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function kx_encabezado(string $titulo, ?array $admin = null): void
{
    $actual = basename($_SERVER['SCRIPT_NAME']);
    $menu = ['index.php' => 'Panel', 'catalogo.php' => 'Catálogo', 'equivalencias.php' => 'Equivalencias', 'precios.php' => 'Precios manuales', 'parametros.php' => 'Parámetros y sedes'];
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($titulo) . ' · Kanaxta admin</title><link rel="stylesheet" href="admin.css"></head><body>';
    echo '<header class="barra"><strong>Kanaxta · administración</strong>';
    if ($admin) {
        echo '<nav>';
        foreach ($menu as $archivo => $texto) {
            echo '<a href="' . $archivo . '"' . ($archivo === $actual ? ' aria-current="page"' : '') . '>' . h($texto) . '</a>';
        }
        echo '<a href="salir.php">Salir (' . h($admin['usuario']) . ')</a></nav>';
    }
    echo '</header><main><h1>' . h($titulo) . '</h1>';
    if ($f = kx_flash()) {
        echo '<p class="flash ' . h($f['t']) . '">' . h($f['m']) . '</p>';
    }
}

function kx_pie(): void
{
    echo '</main></body></html>';
}
