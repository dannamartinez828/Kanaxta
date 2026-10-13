<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

if (kx_admin_actual()) {
    kx_redirigir('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kx_csrf_verificar();
    $st = kx_db()->prepare('SELECT id, usuario, hash_contrasena FROM administrador WHERE usuario = ?');
    $st->execute([trim((string) ($_POST['usuario'] ?? ''))]);
    $a = $st->fetch();
    if ($a && password_verify((string) ($_POST['clave'] ?? ''), $a['hash_contrasena'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $a['id'];
        $_SESSION['admin_usuario'] = $a['usuario'];
        kx_redirigir('index.php');
    }
    sleep(1);   // frena intentos repetidos
    $error = 'Usuario o contraseña incorrectos.';
}

kx_encabezado('Ingresar');
if ($error) {
    echo '<p class="flash error">' . h($error) . '</p>';
}
echo '<form method="post" class="tarjeta">' . kx_csrf_campo() . '
  <label>Usuario <input name="usuario" required autocomplete="username"></label>
  <label>Contraseña <input type="password" name="clave" required autocomplete="current-password"></label>
  <button>Ingresar</button></form>';
kx_pie();
