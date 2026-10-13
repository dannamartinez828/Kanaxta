<?php
/* Parámetros de transporte, sedes y sitios web de las cadenas. */
declare(strict_types=1);
require __DIR__ . '/_comun.php';
$admin = kx_requerir_login();
$pdo = kx_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kx_csrf_verificar();
    $accion = (string) ($_POST['accion'] ?? '');
    if ($accion === 'parametros') {
        $up = $pdo->prepare('UPDATE parametro_transporte SET valor = ?, fuente = ?, fecha_vigencia = ? WHERE id = ?');
        foreach ((array) ($_POST['p'] ?? []) as $id => $v) {
            $valor = (float) str_replace(',', '.', (string) ($v['valor'] ?? '0'));
            $fecha = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($v['fecha'] ?? '')) ?: new DateTimeImmutable('today');
            if ($valor >= 0) {
                $up->execute([$valor, mb_substr(trim((string) ($v['fuente'] ?? '')), 0, 255), $fecha->format('Y-m-d'), (int) $id]);
            }
        }
        kx_flash('Parámetros actualizados.');
    } elseif ($accion === 'sede') {
        $lat = (float) str_replace(',', '.', (string) ($_POST['latitud'] ?? ''));
        $lon = (float) str_replace(',', '.', (string) ($_POST['longitud'] ?? ''));
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        // Bogotá está aproximadamente entre 4,4 y 4,9 de latitud y -74,3 y -73,9 de longitud
        if ($nombre === '' || $lat < 4.4 || $lat > 4.9 || $lon < -74.3 || $lon > -73.9) {
            kx_flash('Revise el nombre y las coordenadas (deben estar en Bogotá; use punto decimal, p. ej. 4.6600 y -74.0900).', 'error');
        } else {
            $pdo->prepare('INSERT INTO sede (cadena_id, nombre, direccion, localidad, latitud, longitud) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([(int) $_POST['cadena_id'], mb_substr($nombre, 0, 120), mb_substr(trim((string) $_POST['direccion']), 0, 200), mb_substr(trim((string) $_POST['localidad']), 0, 60), $lat, $lon]);
            kx_flash('Sede agregada.');
        }
    } elseif ($accion === 'sede_estado') {
        $pdo->prepare('UPDATE sede SET activa = 1 - activa WHERE id = ?')->execute([(int) $_POST['id']]);
        kx_flash('Estado de la sede actualizado.');
    } elseif ($accion === 'sitio') {
        $url = trim((string) ($_POST['sitio_web'] ?? ''));
        if (filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://')) {
            $pdo->prepare('UPDATE cadena SET sitio_web = ? WHERE id = ?')->execute([$url, (int) $_POST['id']]);
            kx_flash('Sitio web actualizado.');
        } else {
            kx_flash('El sitio web debe ser una URL que empiece por https://', 'error');
        }
    }
    kx_redirigir('parametros.php');
}

$params = $pdo->query('SELECT * FROM parametro_transporte ORDER BY modo, id')->fetchAll();
$cadenas = $pdo->query('SELECT * FROM cadena ORDER BY id')->fetchAll();
$sedes = $pdo->query('SELECT s.*, c.nombre AS cadena FROM sede s JOIN cadena c ON c.id = s.cadena_id ORDER BY c.id, s.nombre')->fetchAll();

kx_encabezado('Parámetros y sedes', $admin);

echo '<h2>Parámetros de transporte</h2><p class="nota">Registre siempre la fuente oficial y la fecha desde la que rige cada valor.</p>';
echo '<form method="post" class="tarjeta">' . kx_csrf_campo() . '<input type="hidden" name="accion" value="parametros"><div class="tabla"><table><thead><tr><th>Parámetro</th><th>Valor</th><th>Unidad</th><th>Fuente</th><th>Vigente desde</th></tr></thead><tbody>';
foreach ($params as $p) {
    $id = (int) $p['id'];
    echo '<tr><td>' . h($p['nombre']) . '<br><small>' . h($p['clave']) . '</small></td>'
        . '<td><input name="p[' . $id . '][valor]" value="' . h(kx_num($p['valor'])) . '" inputmode="decimal" size="8"></td>'
        . '<td>' . h($p['unidad']) . '</td>'
        . '<td><input name="p[' . $id . '][fuente]" value="' . h($p['fuente']) . '"></td>'
        . '<td><input type="date" name="p[' . $id . '][fecha]" value="' . h($p['fecha_vigencia']) . '"></td></tr>';
}
echo '</tbody></table></div><button>Guardar parámetros</button></form>';

echo '<h2>Sedes en el área de estudio</h2><p class="nota">Las coordenadas se obtienen en Google Maps: clic derecho sobre la sede → copiar las coordenadas (latitud, longitud).</p>';
if ($sedes) {
    echo '<div class="tabla"><table><thead><tr><th>Cadena</th><th>Sede</th><th>Dirección</th><th>Localidad</th><th>Coordenadas</th><th>Estado</th></tr></thead><tbody>';
    foreach ($sedes as $s) {
        echo '<tr><td>' . h($s['cadena']) . '</td><td>' . h($s['nombre']) . '</td><td>' . h($s['direccion']) . '</td><td>' . h($s['localidad']) . '</td><td>'
            . h($s['latitud'] . ', ' . $s['longitud']) . '</td><td><form method="post" class="enlinea">' . kx_csrf_campo()
            . '<input type="hidden" name="accion" value="sede_estado"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><button class="secundario">'
            . ($s['activa'] ? 'Activa · desactivar' : 'Inactiva · activar') . '</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
} else {
    echo '<p>Aún no hay sedes registradas.</p>';
}
echo '<form method="post" class="tarjeta">' . kx_csrf_campo() . '<input type="hidden" name="accion" value="sede"><div class="fila">'
    . '<label>Cadena <select name="cadena_id">';
foreach ($cadenas as $c) {
    echo '<option value="' . (int) $c['id'] . '">' . h($c['nombre']) . '</option>';
}
echo '</select></label><label>Nombre de la sede <input name="nombre" required></label><label>Dirección <input name="direccion" required></label>'
    . '<label>Localidad <input name="localidad" required></label><label>Latitud <input name="latitud" inputmode="decimal" placeholder="4.6600" required></label>'
    . '<label>Longitud <input name="longitud" inputmode="decimal" placeholder="-74.0900" required></label></div><button>Agregar sede</button></form>';

echo '<h2>Sitios web de las cadenas</h2><p class="nota">Las URL del catálogo deben pertenecer a estos dominios.</p>';
foreach ($cadenas as $c) {
    echo '<form method="post" class="tarjeta enlinea-form">' . kx_csrf_campo() . '<input type="hidden" name="accion" value="sitio"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
        . '<label>' . h($c['nombre']) . ' <input type="url" name="sitio_web" value="' . h($c['sitio_web']) . '" required></label><button>Guardar</button></form>';
}
kx_pie();
