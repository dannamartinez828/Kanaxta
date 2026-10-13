<?php
/* Revisión de equivalencias propuestas por el sistema (RF-15). */
declare(strict_types=1);
require __DIR__ . '/_comun.php';
$admin = kx_requerir_login();
$pdo = kx_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kx_csrf_verificar();
    $refId = (int) ($_POST['ref'] ?? 0);
    $accion = (string) ($_POST['accion'] ?? '');
    if (in_array($accion, ['exacta', 'alternativa'], true)) {
        $pdo->prepare("UPDATE equivalencia SET tipo = ?, estado_revision = 'aprobada', administrador_id = ?, fecha_revision = NOW(),
                              nota = CONCAT('Aprobada por el administrador como ', ?)
                        WHERE producto_referencia_id = ? AND estado_revision <> 'rechazada'")
            ->execute([$accion, $admin['id'], $accion === 'exacta' ? 'coincidencia exacta.' : 'alternativa similar.', $refId]);
        kx_flash('Equivalencia aprobada.');
    } elseif ($accion === 'rechazar') {
        $pdo->prepare("UPDATE equivalencia SET estado_revision = 'rechazada', administrador_id = ?, fecha_revision = NOW(),
                              nota = 'Producto rechazado por el administrador: no corresponde al producto de referencia.'
                        WHERE id = ? AND producto_referencia_id = ?")
            ->execute([$admin['id'], (int) ($_POST['eq'] ?? 0), $refId]);
        kx_flash('Producto rechazado. Registre otra URL en el catálogo si es necesario.');
    } elseif ($accion === 'reabrir') {
        $pdo->prepare("UPDATE equivalencia SET estado_revision = 'propuesta', administrador_id = NULL, fecha_revision = NULL WHERE producto_referencia_id = ?")
            ->execute([$refId]);
        kx_recalcular_equivalencias($pdo, $refId);
        kx_flash('La equivalencia volvió a estado propuesto.');
    }
    kx_redirigir('equivalencias.php#ref' . $refId);
}

$cadenas = $pdo->query('SELECT id, codigo, nombre FROM cadena ORDER BY id')->fetchAll();
$refs = $pdo->query('SELECT id, nombre_generico, cantidad_ref, unidad FROM producto_referencia WHERE activo = 1 ORDER BY categoria, nombre_generico')->fetchAll();
$filas = $pdo->query(
    "SELECT e.id, e.producto_referencia_id, e.tipo, e.estado_revision, e.nota, e.fecha_revision, c.codigo,
            pc.nombre_publicado, pc.marca, pc.cantidad, pc.unidad, pc.url,
            o.precio_normal, o.precio_descuento, o.fecha_hora, o.estado_vigencia
       FROM equivalencia e
       JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
       JOIN cadena c ON c.id = pc.cadena_id
  LEFT JOIN observacion_precio o ON o.id = (SELECT o2.id FROM observacion_precio o2 WHERE o2.producto_cadena_id = pc.id ORDER BY o2.fecha_hora DESC, o2.id DESC LIMIT 1)
   ORDER BY e.id"
)->fetchAll();
$porRef = [];
foreach ($filas as $f) {
    $porRef[$f['producto_referencia_id']][$f['codigo']][] = $f;
}

$etiquetas = ['exacta' => 'Coincidencia exacta', 'alternativa' => 'Alternativa similar', 'pendiente' => 'Pendiente'];

kx_encabezado('Equivalencias', $admin);
echo '<p class="nota">El sistema propone <strong>coincidencia exacta</strong> cuando ambos productos tienen la misma marca y su presentación está dentro del ±10 % de la referencia; en otro caso propone <strong>alternativa similar</strong>. Confirme o corrija cada propuesta. Rechace un producto si la URL no corresponde.</p>';

foreach ($refs as $r) {
    $grupo = $porRef[$r['id']] ?? [];
    $primera = null;
    foreach ($grupo as $lista) {
        foreach ($lista as $f) {
            if ($f['estado_revision'] !== 'rechazada') {
                $primera ??= $f;
            }
        }
    }
    echo '<section class="tarjeta" id="ref' . (int) $r['id'] . '"><h2>' . h($r['nombre_generico']) . ' <small>(' . h(kx_presentacion_texto((float) $r['cantidad_ref'], $r['unidad'])) . ')</small></h2>';
    if ($primera) {
        echo '<p><span class="estado ' . h($primera['tipo']) . '">' . h($etiquetas[$primera['tipo']]) . '</span> · '
            . h($primera['estado_revision']) . ' · <small>' . h($primera['nota']) . '</small></p>';
    } else {
        echo '<p>Sin productos registrados. Agregue las URL en el <a href="catalogo.php#ref' . (int) $r['id'] . '">catálogo</a>.</p>';
    }
    echo '<div class="columnas">';
    foreach ($cadenas as $c) {
        echo '<div><h3>' . h($c['nombre']) . '</h3>';
        foreach ($grupo[$c['codigo']] ?? [] as $f) {
            $precio = $f['precio_descuento'] ?? $f['precio_normal'];
            echo '<div class="ficha' . ($f['estado_revision'] === 'rechazada' ? ' rechazada' : '') . '">'
                . '<a href="' . h($f['url']) . '" target="_blank" rel="noopener noreferrer">' . h($f['nombre_publicado'] ?? 'Sin extraer todavía') . '</a>'
                . '<br>Marca: ' . h($f['marca'] ?? '—') . ' · Presentación: ' . h(kx_presentacion_texto($f['cantidad'] !== null ? (float) $f['cantidad'] : null, $f['unidad']))
                . '<br>Precio: ' . ($precio !== null ? h(kx_cop((float) $precio)) : '—') . ' · ' . h($f['estado_vigencia'] ?? 'sin datos') . ' · ' . h($f['fecha_hora'] ?? '');
            if ($f['estado_revision'] === 'rechazada') {
                echo '<br><em>Rechazado</em>';
            } else {
                echo '<form method="post" class="enlinea">' . kx_csrf_campo() . '<input type="hidden" name="ref" value="' . (int) $r['id'] . '"><input type="hidden" name="eq" value="' . (int) $f['id'] . '"><button name="accion" value="rechazar" class="secundario">Rechazar este producto</button></form>';
            }
            echo '</div>';
        }
        if (empty($grupo[$c['codigo']])) {
            echo '<p class="vacio">Sin URL registrada.</p>';
        }
        echo '</div>';
    }
    echo '</div>';
    if ($grupo) {
        echo '<form method="post" class="acciones">' . kx_csrf_campo() . '<input type="hidden" name="ref" value="' . (int) $r['id'] . '">'
            . '<button name="accion" value="exacta">Confirmar coincidencia exacta</button>'
            . '<button name="accion" value="alternativa">Confirmar alternativa similar</button>'
            . '<button name="accion" value="reabrir" class="secundario">Volver a propuesta automática</button></form>';
    }
    echo '</section>';
}
kx_pie();
