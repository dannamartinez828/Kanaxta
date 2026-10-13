<?php
/* Panel: estado de las extracciones y resumen del catálogo (RF-16). */
declare(strict_types=1);
require __DIR__ . '/_comun.php';
$admin = kx_requerir_login();
$pdo = kx_db();
$p = kx_parametros($pdo);

$resumen = $pdo->query(
    "SELECT c.nombre,
            (SELECT COUNT(*) FROM producto_cadena pc WHERE pc.cadena_id = c.id) AS fichas,
            (SELECT COUNT(*) FROM producto_cadena pc
               JOIN observacion_precio o ON o.id = (SELECT o2.id FROM observacion_precio o2 WHERE o2.producto_cadena_id = pc.id ORDER BY o2.fecha_hora DESC, o2.id DESC LIMIT 1)
              WHERE pc.cadena_id = c.id AND o.estado_vigencia = 'vigente') AS vigentes,
            (SELECT MAX(e.inicio) FROM ejecucion_extraccion e WHERE e.cadena_id = c.id) AS ultima
       FROM cadena c ORDER BY c.id"
)->fetchAll();
$refs = (int) $pdo->query('SELECT COUNT(*) FROM producto_referencia WHERE activo = 1')->fetchColumn();
$propuestas = (int) $pdo->query("SELECT COUNT(DISTINCT producto_referencia_id) FROM equivalencia WHERE estado_revision = 'propuesta' AND tipo <> 'pendiente'")->fetchColumn();
$ejecuciones = $pdo->query(
    'SELECT e.*, c.nombre AS cadena FROM ejecucion_extraccion e JOIN cadena c ON c.id = e.cadena_id ORDER BY e.inicio DESC, e.id DESC LIMIT 15'
)->fetchAll();

kx_encabezado('Panel', $admin);
echo '<section class="tarjetas">';
echo '<div class="tarjeta"><span class="dato">' . $refs . '</span>productos en la canasta de referencia</div>';
echo '<div class="tarjeta"><span class="dato">' . $propuestas . '</span>equivalencias por revisar <a href="equivalencias.php">Revisar</a></div>';
foreach ($resumen as $r) {
    echo '<div class="tarjeta"><span class="dato">' . (int) $r['vigentes'] . ' / ' . (int) $r['fichas'] . '</span>precios vigentes en ' . h($r['nombre'])
        . '<br><small>Última extracción: ' . h($r['ultima'] ?? 'nunca') . '</small></div>';
}
echo '</section>';
echo '<p class="nota">Un precio se considera vigente durante ' . (int) $p['horas_vigencia'] . ' horas. La tarea programada <code>privado/cron_vencidos.php</code> marca como no vigentes los precios más antiguos.</p>';

echo '<h2>Últimas ejecuciones del extractor</h2>';
if (!$ejecuciones) {
    echo '<p>Aún no hay ejecuciones registradas.</p>';
} else {
    echo '<div class="tabla"><table><thead><tr><th>Cadena</th><th>Inicio</th><th>Fin</th><th>Estado</th><th>Correctas</th><th>Con error</th><th>Mensaje</th></tr></thead><tbody>';
    foreach ($ejecuciones as $e) {
        echo '<tr><td>' . h($e['cadena']) . '</td><td>' . h($e['inicio']) . '</td><td>' . h($e['fin']) . '</td><td><span class="estado ' . h($e['estado']) . '">' . h($e['estado']) . '</span></td><td>'
            . (int) $e['fichas_ok'] . '</td><td>' . (int) $e['fichas_error'] . '</td><td>' . h(mb_strimwidth((string) $e['mensaje_error'], 0, 160, '…')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
kx_pie();
