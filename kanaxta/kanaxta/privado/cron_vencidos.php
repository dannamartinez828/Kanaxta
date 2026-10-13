<?php
/*
 * Tarea programada (Ferozo → Herramientas → Tareas programadas).
 * Marca como "no_vigente" los precios con más horas que el parámetro
 * horas_vigencia (24 por defecto), para que la web nunca presente como
 * vigente un precio viejo (requerimiento RF-05).
 *
 * Comando sugerido (cada hora, minuto 5):
 *   php "/home/USUARIO/RUTA/privado/cron_vencidos.php"
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo se ejecuta como tarea programada.');
}

require_once __DIR__ . '/funciones.php';

$pdo = kx_db();
$horas = (int) kx_parametros($pdo)['horas_vigencia'];
$st = $pdo->prepare(
    "UPDATE observacion_precio
        SET estado_vigencia = 'no_vigente'
      WHERE estado_vigencia = 'vigente' AND fecha_hora < (NOW() - INTERVAL ? HOUR)"
);
$st->execute([$horas]);
echo date('Y-m-d H:i:s') . " - precios marcados como no vigentes: " . $st->rowCount() . PHP_EOL;
