<?php
/*
 * Crear administrador o restablecer su contraseña.
 * Protegido con la clave maestra definida en privado/config.php ('clave_maestra').
 * - Si el usuario no existe, lo crea.
 * - Si ya existe, le asigna la nueva contraseña (útil si se olvidó).
 * Si 'clave_maestra' no está configurada (mínimo 16 caracteres), la página queda desactivada.
 */
declare(strict_types=1);
require __DIR__ . '/_comun.php';

$maestra = (string) (kx_config()['clave_maestra'] ?? '');
$activa = strlen($maestra) >= 16;
$error = '';

// Freno sencillo a intentos repetidos: máximo 5 intentos fallidos cada 15 minutos por sesión
$_SESSION['intentos_maestra'] = array_values(array_filter(
    $_SESSION['intentos_maestra'] ?? [],
    static fn($t) => $t > time() - 900
));
$bloqueado = count($_SESSION['intentos_maestra']) >= 5;

if ($activa && !$bloqueado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    kx_csrf_verificar();
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $clave = (string) ($_POST['clave'] ?? '');
    if (!hash_equals($maestra, (string) ($_POST['maestra'] ?? ''))) {
        $_SESSION['intentos_maestra'][] = time();
        error_log('[Kanaxta] Intento fallido de clave maestra desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        sleep(2);
        $error = 'La clave maestra no es correcta.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $usuario)) {
        $error = 'El usuario debe tener entre 3 y 60 caracteres (letras, números, punto, guion).';
    } elseif (strlen($clave) < 10) {
        $error = 'La contraseña debe tener al menos 10 caracteres.';
    } elseif ($clave !== (string) ($_POST['clave2'] ?? '')) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $pdo = kx_db();
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $existe = $pdo->prepare('SELECT id FROM administrador WHERE usuario = ?');
        $existe->execute([$usuario]);
        if ($existe->fetchColumn() !== false) {
            $pdo->prepare('UPDATE administrador SET hash_contrasena = ? WHERE usuario = ?')->execute([$hash, $usuario]);
            kx_flash('Contraseña de «' . $usuario . '» actualizada. Ya puede ingresar.');
        } else {
            $pdo->prepare('INSERT INTO administrador (usuario, hash_contrasena) VALUES (?, ?)')->execute([$usuario, $hash]);
            kx_flash('Administrador «' . $usuario . '» creado. Ya puede ingresar.');
        }
        $_SESSION['intentos_maestra'] = [];
        kx_redirigir('login.php');
    }
}

kx_encabezado('Crear administrador o cambiar contraseña');
if (!$activa) {
    echo '<p class="flash error">Página desactivada. Para usarla, agregue en <code>privado/config.php</code> la línea '
        . '<code>\'clave_maestra\' => \'...\'</code> con una clave de al menos 16 caracteres.</p>';
} elseif ($bloqueado) {
    echo '<p class="flash error">Demasiados intentos fallidos. Espere 15 minutos e intente de nuevo.</p>';
} else {
    if ($error) {
        echo '<p class="flash error">' . h($error) . '</p>';
    }
    echo '<p class="nota">Si el usuario no existe, se crea. Si ya existe, se le asigna la nueva contraseña.</p>';
    echo '<form method="post" class="tarjeta" autocomplete="off">' . kx_csrf_campo() . '
      <label>Clave maestra <input type="password" name="maestra" required autocomplete="off"></label>
      <label>Usuario <input name="usuario" required autocomplete="off"></label>
      <label>Contraseña nueva (mínimo 10 caracteres) <input type="password" name="clave" required minlength="10" autocomplete="new-password"></label>
      <label>Repita la contraseña <input type="password" name="clave2" required minlength="10" autocomplete="new-password"></label>
      <button>Guardar</button></form>';
}
kx_pie();
