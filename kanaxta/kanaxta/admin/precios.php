<?php
/*
 * Registro manual de precios.
 * Se usa cuando la extracción automática no es posible (por ejemplo, si un
 * sitio bloquea el acceso automatizado). Cada precio queda marcado como
 * "Registro manual", con fecha, hora y usuario, y la página pública lo indica.
 * Aplica la misma regla de vigencia que los precios automáticos (24 h).
 */
declare(strict_types=1);
require __DIR__ . '/_comun.php';
$admin = kx_requerir_login();
$pdo = kx_db();
$p = kx_parametros($pdo);

$cadenas = $pdo->query('SELECT id, codigo, nombre FROM cadena ORDER BY id')->fetchAll();
$codigos = array_column($cadenas, 'codigo');
$cadenaSel = in_array($_GET['cadena'] ?? '', $codigos, true) ? $_GET['cadena'] : ($codigos[0] ?? 'ara');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kx_csrf_verificar();
    $pcId = (int) ($_POST['pc'] ?? 0);
    $volver = 'precios.php?cadena=' . urlencode((string) ($_POST['cadena'] ?? $cadenaSel)) . '#pc' . $pcId;

    $pc = $pdo->prepare('SELECT pc.id, pc.cadena_id FROM producto_cadena pc
                          WHERE pc.id = ? AND EXISTS (SELECT 1 FROM equivalencia e WHERE e.producto_cadena_id = pc.id)');
    $pc->execute([$pcId]);
    $fila = $pc->fetch();
    $normal = (float) str_replace(['.', ','], ['', '.'], trim((string) ($_POST['precio_normal'] ?? '')));
    $descTxt = trim((string) ($_POST['precio_descuento'] ?? ''));
    $desc = $descTxt === '' ? null : (float) str_replace(['.', ','], ['', '.'], $descTxt);
    $fecha = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($_POST['fecha'] ?? ''));
    $cantidad = trim((string) ($_POST['cantidad'] ?? '')) === '' ? null : (float) str_replace(',', '.', (string) $_POST['cantidad']);
    $unidad = $cantidad === null ? null : (in_array($_POST['unidad'] ?? '', ['g', 'ml', 'und'], true) ? $_POST['unidad'] : null);

    $error = null;
    if (!$fila) {
        $error = 'Producto no encontrado.';
    } elseif ($normal <= 0 || $normal > 100_000_000) {
        $error = 'Escriba el precio normal (solo números, por ejemplo 6750).';
    } elseif ($desc !== null && ($desc <= 0 || $desc >= $normal)) {
        $error = 'El precio con descuento debe ser menor que el precio normal (o déjelo vacío).';
    } elseif (!$fecha) {
        $error = 'Indique la fecha y hora en que consultó el precio.';
    } elseif ($fecha > new DateTimeImmutable('+5 minutes')) {
        $error = 'La fecha de consulta no puede estar en el futuro.';
    } elseif (($cantidad === null) !== ($unidad === null) || ($cantidad !== null && $cantidad <= 0)) {
        $error = 'Para la presentación indique cantidad y unidad, o deje la cantidad vacía.';
    }
    if ($error) {
        kx_flash($error, 'error');
        kx_redirigir($volver);
    }

    $f = $fecha->format('Y-m-d H:i:s');
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO ejecucion_extraccion (cadena_id, inicio, fin, estado, fichas_ok, mensaje_error) VALUES (?, ?, ?, 'ok', 1, ?)")
        ->execute([$fila['cadena_id'], $f, $f, KX_PREFIJO_MANUAL . ' de precios por ' . $admin['usuario'] . '.']);
    $ejecucionId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE producto_cadena
                      SET nombre_publicado = COALESCE(NULLIF(?, \'\'), nombre_publicado),
                          marca = COALESCE(NULLIF(?, \'\'), marca),
                          cantidad = COALESCE(?, cantidad), unidad = COALESCE(?, unidad), actualizado_en = ?
                    WHERE id = ?')
        ->execute([mb_substr(trim((string) ($_POST['nombre'] ?? '')), 0, 255), mb_substr(trim((string) ($_POST['marca'] ?? '')), 0, 120), $cantidad, $unidad, $f, $pcId]);
    $pdo->prepare("INSERT INTO observacion_precio (producto_cadena_id, ejecucion_id, precio_normal, precio_descuento, fecha_hora, estado_vigencia, detalle)
                   VALUES (?, ?, ?, ?, ?, 'vigente', ?)")
        ->execute([$pcId, $ejecucionId, $normal, $desc, $f, KX_PREFIJO_MANUAL . ' por ' . $admin['usuario'] . ': consulta del sitio web oficial.']);
    $refs = $pdo->prepare('SELECT DISTINCT producto_referencia_id FROM equivalencia WHERE producto_cadena_id = ?');
    $refs->execute([$pcId]);
    foreach ($refs->fetchAll(PDO::FETCH_COLUMN) as $refId) {
        kx_recalcular_equivalencias($pdo, (int) $refId);
    }
    $pdo->commit();

    $limite = (new DateTimeImmutable('now'))->modify(sprintf('-%d hours', (int) $p['horas_vigencia']));
    kx_flash($fecha < $limite
        ? 'Precio registrado, pero la fecha de consulta tiene más de ' . (int) $p['horas_vigencia'] . ' horas: se mostrará como no vigente.'
        : 'Precio registrado como registro manual.', $fecha < $limite ? 'error' : 'ok');
    kx_redirigir($volver);
}

$st = $pdo->prepare(
    "SELECT pr.id AS ref_id, pr.nombre_generico, pr.cantidad_ref, pr.unidad AS unidad_ref,
            pc.id AS pc_id, pc.url, pc.nombre_publicado, pc.marca, pc.cantidad, pc.unidad,
            o.precio_normal, o.precio_descuento, o.fecha_hora, o.estado_vigencia, o.detalle
       FROM producto_referencia pr
       JOIN equivalencia e ON e.producto_referencia_id = pr.id AND e.estado_revision <> 'rechazada'
       JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
       JOIN cadena c ON c.id = pc.cadena_id AND c.codigo = ?
  LEFT JOIN observacion_precio o ON o.id = (SELECT o2.id FROM observacion_precio o2 WHERE o2.producto_cadena_id = pc.id ORDER BY o2.fecha_hora DESC, o2.id DESC LIMIT 1)
      WHERE pr.activo = 1
   ORDER BY pr.categoria, pr.nombre_generico"
);
$st->execute([$cadenaSel]);
$filas = $st->fetchAll();
$ahora = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i');

kx_encabezado('Precios manuales', $admin);
echo '<p class="nota">Úselo solo cuando la extracción automática no sea posible (por ejemplo, si el sitio bloquea el acceso automatizado). '
    . 'Abra la ficha del producto en el sitio oficial, copie el precio que ve y regístrelo aquí con la fecha y hora de la consulta. '
    . 'En la página pública el precio aparece marcado como <strong>Registro manual</strong> y vence a las ' . (int) $p['horas_vigencia'] . ' horas, igual que los automáticos.</p>';
echo '<p class="acciones">';
foreach ($cadenas as $c) {
    echo '<a class="boton-enlace' . ($c['codigo'] === $cadenaSel ? ' activo' : '') . '" href="precios.php?cadena=' . h($c['codigo']) . '">' . h($c['nombre']) . '</a>';
}
echo '</p>';

if (!$filas) {
    echo '<p>No hay productos con URL registrada en esta cadena. Agréguelas en el <a href="catalogo.php">catálogo</a>.</p>';
}
foreach ($filas as $r) {
    $esManual = str_starts_with((string) $r['detalle'], KX_PREFIJO_MANUAL);
    $precio = $r['precio_descuento'] ?? $r['precio_normal'];
    $unidadSel = $r['unidad'] ?? $r['unidad_ref'];
    echo '<form method="post" class="tarjeta" id="pc' . (int) $r['pc_id'] . '">' . kx_csrf_campo()
        . '<input type="hidden" name="pc" value="' . (int) $r['pc_id'] . '"><input type="hidden" name="cadena" value="' . h($cadenaSel) . '">'
        . '<h2>' . h($r['nombre_generico']) . ' <small>(' . h(kx_presentacion_texto((float) $r['cantidad_ref'], $r['unidad_ref'])) . ')</small></h2>'
        . '<p><a href="' . h($r['url']) . '" target="_blank" rel="noopener noreferrer">Abrir la ficha en el sitio oficial ↗</a><br><small>Último dato: '
        . ($precio !== null ? h(kx_cop((float) $precio)) . ' · ' . h($r['fecha_hora']) . ' · ' . h($r['estado_vigencia']) . ($esManual ? ' · registro manual' : ' · automático') : 'sin datos')
        . '</small></p><div class="fila">'
        . '<label>Precio normal (COP) <input name="precio_normal" inputmode="numeric" required placeholder="6750"></label>'
        . '<label>Precio con descuento (opcional) <input name="precio_descuento" inputmode="numeric"></label>'
        . '<label>Fecha y hora de la consulta <input type="datetime-local" name="fecha" value="' . h($ahora) . '" required></label></div>'
        . '<div class="fila"><label>Nombre publicado <input name="nombre" value="' . h($r['nombre_publicado'] ?? '') . '" placeholder="Como aparece en el sitio"></label>'
        . '<label>Marca <input name="marca" value="' . h($r['marca'] ?? '') . '"></label>'
        . '<label>Cantidad <input name="cantidad" value="' . h($r['cantidad'] !== null ? kx_num($r['cantidad']) : '') . '" inputmode="decimal" placeholder="900"></label>'
        . '<label>Unidad <select name="unidad">';
    foreach (['g' => 'g', 'ml' => 'ml', 'und' => 'unidades'] as $v => $t) {
        echo '<option value="' . $v . '"' . ($v === $unidadSel ? ' selected' : '') . '>' . $t . '</option>';
    }
    echo '</select></label></div><button>Registrar precio</button></form>';
}
kx_pie();
