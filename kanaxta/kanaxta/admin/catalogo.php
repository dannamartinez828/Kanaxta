<?php
/* Catálogo de referencia y URL de cada producto en Ara y D1 (RF-14). */
declare(strict_types=1);
require __DIR__ . '/_comun.php';
$admin = kx_requerir_login();
$pdo = kx_db();
$cadenas = $pdo->query('SELECT id, codigo, nombre, sitio_web FROM cadena ORDER BY id')->fetchAll();

/** Dominio registrable: www.aratiendas.com → aratiendas.com; tienda.d1.com.co → d1.com.co. */
function kx_dominio_base(string $host): string
{
    $partes = explode('.', strtolower($host));
    $n = count($partes);
    if ($n >= 3 && strlen($partes[$n - 1]) === 2 && in_array($partes[$n - 2], ['com', 'net', 'org', 'gov', 'edu', 'co'], true)) {
        return implode('.', array_slice($partes, -3));
    }
    return implode('.', array_slice($partes, -2));
}

/** Crea o cambia la URL de un producto de referencia en una cadena. */
function kx_guardar_url(PDO $pdo, int $refId, array $cadena, string $url): ?string
{
    $url = trim($url);
    $actual = $pdo->prepare(
        'SELECT e.id AS eq_id, pc.id AS pc_id, pc.url FROM equivalencia e
           JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
          WHERE e.producto_referencia_id = ? AND pc.cadena_id = ? ORDER BY e.id LIMIT 1'
    );
    $actual->execute([$refId, $cadena['id']]);
    $fila = $actual->fetch();

    if ($url === '') {
        if ($fila) {
            $pdo->prepare('DELETE FROM equivalencia WHERE id = ?')->execute([$fila['eq_id']]);
        }
        return null;
    }
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    $dominioBase = kx_dominio_base(parse_url($cadena['sitio_web'], PHP_URL_HOST) ?: '');   // p. ej. aratiendas.com o d1.com.co
    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
        return 'La URL de ' . $cadena['nombre'] . ' no es válida (debe empezar por https://).';
    }
    if ($dominioBase === '' || ($host !== $dominioBase && !str_ends_with($host, '.' . $dominioBase))) {
        return 'La URL de ' . $cadena['nombre'] . ' debe pertenecer al dominio ' . $dominioBase . '.';
    }
    if ($fila && $fila['url'] === $url) {
        return null;
    }
    // Busca o crea el producto de cadena para esa URL
    $pc = $pdo->prepare('SELECT id FROM producto_cadena WHERE url = ?');
    $pc->execute([$url]);
    $pcId = $pc->fetchColumn();
    if ($pcId === false) {
        $pdo->prepare('INSERT INTO producto_cadena (cadena_id, url) VALUES (?, ?)')->execute([$cadena['id'], $url]);
        $pcId = (int) $pdo->lastInsertId();
    }
    if ($fila) {
        $pdo->prepare('DELETE FROM equivalencia WHERE id = ?')->execute([$fila['eq_id']]);
    }
    $pdo->prepare("INSERT IGNORE INTO equivalencia (producto_referencia_id, producto_cadena_id, tipo, estado_revision) VALUES (?, ?, 'pendiente', 'propuesta')")
        ->execute([$refId, $pcId]);
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kx_csrf_verificar();
    $accion = $_POST['accion'] ?? '';
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $categoria = trim((string) ($_POST['categoria'] ?? ''));
    $cantidad = (float) str_replace(',', '.', (string) ($_POST['cantidad_ref'] ?? '0'));
    $unidad = in_array($_POST['unidad'] ?? '', ['g', 'ml', 'und'], true) ? $_POST['unidad'] : null;
    $valido = $nombre !== '' && $categoria !== '' && $cantidad > 0 && $unidad !== null;

    if (($accion === 'crear' || $accion === 'guardar') && !$valido) {
        kx_flash('Complete nombre, categoría, cantidad (mayor que 0) y unidad.', 'error');
        kx_redirigir('catalogo.php');
    }
    $dup = $pdo->prepare('SELECT COUNT(*) FROM producto_referencia WHERE nombre_generico = ? AND id <> ?');
    $dup->execute([$nombre, $accion === 'guardar' ? (int) ($_POST['id'] ?? 0) : 0]);
    if ((int) $dup->fetchColumn() > 0) {
        kx_flash('Ya existe un producto con el nombre «' . $nombre . '».', 'error');
        kx_redirigir('catalogo.php');
    }
    $pdo->beginTransaction();
    if ($accion === 'crear') {
        $pdo->prepare('INSERT INTO producto_referencia (nombre_generico, categoria, cantidad_ref, unidad) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($nombre, 0, 120), mb_substr($categoria, 0, 60), $cantidad, $unidad]);
        $refId = (int) $pdo->lastInsertId();
    } elseif ($accion === 'guardar') {
        $refId = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE producto_referencia SET nombre_generico = ?, categoria = ?, cantidad_ref = ?, unidad = ?, activo = ? WHERE id = ?')
            ->execute([mb_substr($nombre, 0, 120), mb_substr($categoria, 0, 60), $cantidad, $unidad, isset($_POST['activo']) ? 1 : 0, $refId]);
    } else {
        $pdo->rollBack();
        kx_redirigir('catalogo.php');
    }
    $errores = [];
    foreach ($cadenas as $c) {
        $e = kx_guardar_url($pdo, $refId, $c, (string) ($_POST['url_' . $c['codigo']] ?? ''));
        if ($e) {
            $errores[] = $e;
        }
    }
    kx_recalcular_equivalencias($pdo, $refId);
    $pdo->commit();
    kx_flash($errores ? implode(' ', $errores) : 'Producto guardado.', $errores ? 'error' : 'ok');
    kx_redirigir('catalogo.php#ref' . $refId);
}

$productos = $pdo->query('SELECT * FROM producto_referencia ORDER BY activo DESC, categoria, nombre_generico')->fetchAll();
$urls = [];
foreach ($pdo->query('SELECT e.producto_referencia_id, c.codigo, pc.url FROM equivalencia e JOIN producto_cadena pc ON pc.id = e.producto_cadena_id JOIN cadena c ON c.id = pc.cadena_id ORDER BY e.id') as $u) {
    $urls[$u['producto_referencia_id']][$u['codigo']] ??= $u['url'];
}

function kx_select_unidad(string $actual): string
{
    $o = '';
    foreach (['g' => 'g (peso)', 'ml' => 'ml (volumen)', 'und' => 'unidades'] as $v => $t) {
        $o .= '<option value="' . $v . '"' . ($v === $actual ? ' selected' : '') . '>' . $t . '</option>';
    }
    return '<select name="unidad">' . $o . '</select>';
}

kx_encabezado('Catálogo de referencia', $admin);
echo '<p class="nota">Para cada producto pegue la URL de su ficha en el sitio web de cada cadena. La presentación se expresa en gramos, mililitros o unidades (1 kg = 1000 g; 1 L = 1000 ml). La canasta del estudio tiene de 10 a 12 productos activos.</p>';

foreach ($productos as $r) {
    echo '<form method="post" class="tarjeta producto" id="ref' . (int) $r['id'] . '">' . kx_csrf_campo()
        . '<input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
        . '<div class="fila"><label>Nombre <input name="nombre" value="' . h($r['nombre_generico']) . '" required></label>'
        . '<label>Categoría <input name="categoria" value="' . h($r['categoria']) . '" required></label>'
        . '<label>Cantidad <input name="cantidad_ref" value="' . h(kx_num($r['cantidad_ref'])) . '" inputmode="decimal" required></label>'
        . '<label>Unidad ' . kx_select_unidad($r['unidad']) . '</label>'
        . '<label class="check"><input type="checkbox" name="activo"' . ($r['activo'] ? ' checked' : '') . '> Activo</label></div>';
    foreach ($cadenas as $c) {
        echo '<label>URL en ' . h($c['nombre']) . ' <input type="url" name="url_' . h($c['codigo']) . '" value="' . h($urls[$r['id']][$c['codigo']] ?? '') . '" placeholder="' . h($c['sitio_web']) . '…"></label>';
    }
    echo '<button>Guardar</button></form>';
}

echo '<h2>Agregar producto</h2><form method="post" class="tarjeta producto">' . kx_csrf_campo() . '<input type="hidden" name="accion" value="crear">'
    . '<div class="fila"><label>Nombre <input name="nombre" required></label><label>Categoría <input name="categoria" required></label>'
    . '<label>Cantidad <input name="cantidad_ref" inputmode="decimal" required></label><label>Unidad ' . kx_select_unidad('g') . '</label></div>';
foreach ($cadenas as $c) {
    echo '<label>URL en ' . h($c['nombre']) . ' <input type="url" name="url_' . h($c['codigo']) . '"></label>';
}
echo '<button>Agregar</button></form>';
kx_pie();
