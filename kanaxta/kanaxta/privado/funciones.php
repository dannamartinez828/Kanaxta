<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const KX_TOLERANCIA_PRESENTACION = 0.10;   // ±10 % (numeral 9.9)
const KX_MODOS = ['pie', 'publico', 'carro', 'taxi'];
const KX_PREFIJO_MANUAL = 'Registro manual';   // marca de los precios digitados en el administrador

/* ------------------------------------------------------------------ utilidades */

/** Texto en minúsculas, sin tildes ni signos, para comparar marcas. */
function kx_normalizar_texto(?string $texto): string
{
    $t = mb_strtolower(trim((string) $texto), 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $t = preg_replace('/[^a-z0-9]+/u', ' ', $t) ?? '';
    return trim($t);
}

function kx_presentacion_texto(?float $cantidad, ?string $unidad): string
{
    if ($cantidad === null || $unidad === null) {
        return 'sin dato';
    }
    if ($unidad === 'g' && $cantidad >= 1000) {
        return rtrim(rtrim(number_format($cantidad / 1000, 2, ',', '.'), '0'), ',') . ' kg';
    }
    if ($unidad === 'ml' && $cantidad >= 1000) {
        return rtrim(rtrim(number_format($cantidad / 1000, 2, ',', '.'), '0'), ',') . ' L';
    }
    $n = rtrim(rtrim(number_format($cantidad, 2, ',', '.'), '0'), ',');
    return $n . ' ' . ($unidad === 'und' ? 'und.' : $unidad);
}

/** ¿La presentación difiere como máximo un 10 % de la de referencia y usa la misma unidad? */
function kx_presentacion_comparable(?float $cantidad, ?string $unidad, float $cantidadRef, string $unidadRef): bool
{
    if ($cantidad === null || $unidad === null || $cantidad <= 0 || $unidad !== $unidadRef) {
        return false;
    }
    return abs($cantidad - $cantidadRef) / $cantidadRef <= KX_TOLERANCIA_PRESENTACION + 1e-9;
}

/** Parámetros de transporte como arreglo clave => valor (float). */
function kx_parametros(PDO $pdo): array
{
    $p = [];
    foreach ($pdo->query('SELECT clave, valor FROM parametro_transporte') as $fila) {
        $p[$fila['clave']] = (float) $fila['valor'];
    }
    return $p + [
        'tarifa_publico' => 0.0, 'precio_galon' => 0.0, 'rendimiento_km_galon' => 35.0, 'parqueadero' => 0.0,
        'taxi_minima' => 0.0, 'taxi_arranque' => 0.0, 'taxi_km' => 0.0, 'factor_recorrido' => 1.3,
        'horas_vigencia' => 24.0,
    ];
}

/* ------------------------------------------------------------------ emparejamiento */

/**
 * Propone el tipo de equivalencia de un producto de referencia comparando el
 * producto de Ara con el de D1: coincidencia exacta si tienen la misma marca
 * y ambas presentaciones están dentro del ±10 % de la referencia; en otro caso,
 * alternativa similar. Las equivalencias aprobadas o rechazadas por el
 * administrador no se modifican.
 */
function kx_recalcular_equivalencias(PDO $pdo, int $referenciaId): void
{
    $st = $pdo->prepare(
        'SELECT e.id, e.estado_revision, pc.cadena_id, pc.marca, pc.nombre_publicado, pc.cantidad, pc.unidad,
                pr.cantidad_ref, pr.unidad AS unidad_ref
           FROM equivalencia e
           JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
           JOIN producto_referencia pr ON pr.id = e.producto_referencia_id
          WHERE e.producto_referencia_id = ? AND e.estado_revision <> \'rechazada\'
          ORDER BY e.id'
    );
    $st->execute([$referenciaId]);
    $filas = $st->fetchAll();

    $porCadena = [];
    foreach ($filas as $f) {
        $porCadena[$f['cadena_id']] ??= $f;   // un producto por cadena
    }
    $ids = array_map(static fn($f) => (int) $f['id'], $filas);
    if (!$ids) {
        return;
    }

    $conDatos = array_filter($porCadena, static fn($f) => $f['marca'] !== null || $f['cantidad'] !== null);
    if (count($porCadena) < 2 || count($conDatos) < 2) {
        $tipo = 'pendiente';
        $nota = 'Falta información de alguna de las dos cadenas.';
    } else {
        $mismaMarca = kx_misma_marca($porCadena);
        $presentacionOk = true;
        foreach ($porCadena as $f) {
            $presentacionOk = $presentacionOk && kx_presentacion_comparable(
                $f['cantidad'] !== null ? (float) $f['cantidad'] : null,
                $f['unidad'],
                (float) $f['cantidad_ref'],
                $f['unidad_ref']
            );
        }
        $tipo = ($mismaMarca && $presentacionOk) ? 'exacta' : 'alternativa';
        $motivos = [];
        if (!$mismaMarca) {
            $motivos[] = 'marca distinta o sin marca';
        }
        if (!$presentacionOk) {
            $motivos[] = 'presentación fuera del ±10 % de la referencia';
        }
        $nota = $motivos ? 'Propuesta automática: ' . implode('; ', $motivos) . '.' : 'Propuesta automática: misma marca y presentación comparable.';
    }

    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $up = $pdo->prepare("UPDATE equivalencia SET tipo = ?, nota = ? WHERE estado_revision = 'propuesta' AND id IN ($marcadores)");
    $up->execute(array_merge([$tipo, $nota], $ids));
}

/**
 * ¿Los productos son de la misma marca? Si una cadena no publica la marca
 * (frecuente en D1), se acepta cuando la marca conocida aparece como palabra
 * completa en el nombre publicado del otro producto.
 */
function kx_misma_marca(array $productos): bool
{
    $conocidas = [];
    foreach ($productos as $f) {
        $m = kx_normalizar_texto($f['marca'] ?? null);
        if ($m !== '') {
            $conocidas[$m] = true;
        }
    }
    if (count($conocidas) !== 1) {
        return false;   // ninguna marca conocida, o marcas distintas
    }
    $marca = array_key_first($conocidas);
    foreach ($productos as $f) {
        if (kx_normalizar_texto($f['marca'] ?? null) === '' &&
            !str_contains(' ' . kx_normalizar_texto($f['nombre_publicado'] ?? null) . ' ', ' ' . $marca . ' ')) {
            return false;
        }
    }
    return true;
}

/* ------------------------------------------------------------------ costo de acceso */

/**
 * Costo de acceso de ida y regreso (Tabla 5 del anteproyecto).
 * $d = distancia de un trayecto en km (ya ajustada por el factor de recorrido).
 */
function kx_costo_acceso(string $modo, ?float $d, array $p, array $opciones = []): array
{
    switch ($modo) {
        case 'pie':
            return ['valor' => 0.0, 'detalle' => 'A pie o en bicicleta: sin costo.'];
        case 'publico':
            $n = max(1, (int) ($opciones['personas'] ?? 1));
            $valor = 2 * $p['tarifa_publico'] * $n;
            return ['valor' => $valor, 'detalle' => sprintf('2 × %s × %d persona(s)', kx_cop($p['tarifa_publico']), $n)];
        case 'carro':
            if ($d === null) {
                return ['valor' => null, 'detalle' => 'Falta la distancia a la sede.'];
            }
            $r = (float) ($opciones['rendimiento'] ?? 0) > 0 ? (float) $opciones['rendimiento'] : $p['rendimiento_km_galon'];
            $pk = isset($opciones['parqueadero']) && $opciones['parqueadero'] !== '' ? max(0.0, (float) $opciones['parqueadero']) : $p['parqueadero'];
            $valor = (2 * $d / $r) * $p['precio_galon'] + $pk;
            return ['valor' => $valor, 'detalle' => sprintf('(2 × %s km ÷ %s km/gal) × %s + parqueadero %s', kx_km($d), kx_num($r), kx_cop($p['precio_galon']), kx_cop($pk))];
        case 'taxi':
            if ($d === null) {
                return ['valor' => null, 'detalle' => 'Falta la distancia a la sede.'];
            }
            $trayecto = max($p['taxi_minima'], $p['taxi_arranque'] + $p['taxi_km'] * $d);
            return ['valor' => 2 * $trayecto, 'detalle' => sprintf('2 × máx(%s; %s + %s × %s km)', kx_cop($p['taxi_minima']), kx_cop($p['taxi_arranque']), kx_cop($p['taxi_km']), kx_km($d))];
    }
    throw new InvalidArgumentException('Modo de desplazamiento no válido.');
}

/** Número sin ceros decimales sobrantes: 3550.00 → 3550; 1.30 → 1.3; 0.00 → 0. */
function kx_num(mixed $v): string
{
    $f = (float) $v;
    return $f == floor($f) ? (string) (int) $f : rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

function kx_km(float $d): string
{
    return number_format($d, 1, ',', '.');
}

function kx_cop(float $v): string
{
    return '$' . number_format($v, 0, ',', '.');
}

/* ------------------------------------------------------------------ comparación */

/**
 * Compara la canasta entre Ara y D1 y calcula Pc, Ca y CTA por cadena.
 *
 * $entrada = [
 *   'items'      => [['referencia_id' => 1, 'cantidad' => 1], ...],
 *   'aceptadas'  => [3, 7],                 // referencias cuya alternativa similar aceptó el usuario
 *   'modo'       => 'pie|publico|carro|taxi',
 *   'distancias' => ['ara' => 2.4, 'd1' => 1.1],   // km de un trayecto
 *   'personas'   => 1, 'rendimiento' => 35, 'parqueadero' => 0,
 * ]
 */
function kx_comparar(PDO $pdo, array $entrada): array
{
    $items = [];
    foreach ((array) ($entrada['items'] ?? []) as $it) {
        $id = (int) ($it['referencia_id'] ?? 0);
        $cant = (int) ($it['cantidad'] ?? 1);
        if ($id > 0 && $cant >= 1 && $cant <= 50) {
            $items[$id] = ($items[$id] ?? 0) + $cant;
        }
    }
    if (!$items || count($items) > 12) {
        throw new InvalidArgumentException('La canasta debe tener entre 1 y 12 productos.');
    }
    $aceptadas = array_map('intval', (array) ($entrada['aceptadas'] ?? []));
    $modo = (string) ($entrada['modo'] ?? 'pie');
    if (!in_array($modo, KX_MODOS, true)) {
        throw new InvalidArgumentException('Modo de desplazamiento no válido.');
    }

    $p = kx_parametros($pdo);
    $limiteVigencia = (new DateTimeImmutable('now'))->modify(sprintf('-%d hours', (int) $p['horas_vigencia']));

    $cadenas = $pdo->query('SELECT id, codigo, nombre FROM cadena ORDER BY id')->fetchAll();

    $ids = array_keys($items);
    $marc = implode(',', array_fill(0, count($ids), '?'));
    $refs = $pdo->prepare("SELECT id, nombre_generico, categoria, cantidad_ref, unidad FROM producto_referencia WHERE id IN ($marc) AND activo = 1");
    $refs->execute($ids);
    $referencias = [];
    foreach ($refs as $r) {
        $referencias[(int) $r['id']] = $r;
    }

    $st = $pdo->prepare(
        "SELECT e.producto_referencia_id AS ref_id, e.tipo, e.estado_revision, c.codigo AS cadena,
                pc.nombre_publicado, pc.marca, pc.cantidad, pc.unidad, pc.url,
                o.precio_normal, o.precio_descuento, o.fecha_hora, o.estado_vigencia, o.detalle
           FROM equivalencia e
           JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
           JOIN cadena c ON c.id = pc.cadena_id
      LEFT JOIN observacion_precio o ON o.id = (
                SELECT o2.id FROM observacion_precio o2
                 WHERE o2.producto_cadena_id = pc.id
              ORDER BY o2.fecha_hora DESC, o2.id DESC LIMIT 1)
          WHERE e.estado_revision <> 'rechazada' AND e.producto_referencia_id IN ($marc)
       ORDER BY FIELD(e.tipo, 'exacta', 'alternativa', 'pendiente'), e.id"
    );
    $st->execute($ids);
    $datos = [];
    foreach ($st as $f) {
        $datos[(int) $f['ref_id']][$f['cadena']] ??= $f;   // prioriza la coincidencia exacta
    }

    $totales = [];
    foreach ($cadenas as $c) {
        $totales[$c['codigo']] = ['nombre' => $c['nombre'], 'pc' => 0.0];
    }
    $resultadoItems = [];
    $comparables = 0;
    $advertencias = [];
    $manuales = [];

    foreach ($items as $refId => $cantidad) {
        if (!isset($referencias[$refId])) {
            continue;
        }
        $ref = $referencias[$refId];
        $cantRef = (float) $ref['cantidad_ref'];
        $item = [
            'referencia_id' => $refId,
            'nombre'        => $ref['nombre_generico'],
            'presentacion'  => kx_presentacion_texto($cantRef, $ref['unidad']),
            'cantidad'      => $cantidad,
            'cadenas'       => [],
        ];
        $todasVigentes = true;
        $tipos = [];
        foreach ($cadenas as $c) {
            $f = $datos[$refId][$c['codigo']] ?? null;
            if ($f === null) {
                $item['cadenas'][$c['codigo']] = null;
                $todasVigentes = false;
                continue;
            }
            $tipos[] = $f['tipo'];
            $precioUsado = $f['precio_descuento'] !== null ? (float) $f['precio_descuento'] : ($f['precio_normal'] !== null ? (float) $f['precio_normal'] : null);
            $fecha = $f['fecha_hora'] !== null ? new DateTimeImmutable($f['fecha_hora']) : null;
            $vigente = $precioUsado !== null && $f['estado_vigencia'] === 'vigente' && $fecha !== null && $fecha >= $limiteVigencia;
            $cant = $f['cantidad'] !== null ? (float) $f['cantidad'] : null;
            $ajustado = $precioUsado;
            $normalizado = false;
            if ($precioUsado !== null && $cant !== null && $cant > 0 && $f['unidad'] === $ref['unidad'] && abs($cant - $cantRef) > 1e-9) {
                $ajustado = $precioUsado / $cant * $cantRef;   // precio por unidad de medida × presentación de referencia
                $normalizado = true;
            }
            $todasVigentes = $todasVigentes && $vigente;
            $item['cadenas'][$c['codigo']] = [
                'nombre_publicado' => $f['nombre_publicado'],
                'marca'            => $f['marca'],
                'presentacion'     => kx_presentacion_texto($cant, $f['unidad']),
                'precio_normal'    => $f['precio_normal'] !== null ? (float) $f['precio_normal'] : null,
                'precio_descuento' => $f['precio_descuento'] !== null ? (float) $f['precio_descuento'] : null,
                'precio_usado'     => $precioUsado,
                'precio_ajustado'  => $ajustado !== null ? round($ajustado) : null,
                'normalizado'      => $normalizado,
                'subtotal'         => $ajustado !== null ? round($ajustado * $cantidad) : null,
                'fecha_hora'       => $f['fecha_hora'],
                'vigente'          => $vigente,
                'manual'           => str_starts_with((string) $f['detalle'], KX_PREFIJO_MANUAL),
                'url'              => $f['url'],
            ];
        }

        $faltaCadena = in_array(null, $item['cadenas'], true);
        if ($faltaCadena) {
            $item['tipo'] = 'sin_coincidencia';
        } elseif (in_array('pendiente', $tipos, true)) {
            $item['tipo'] = 'pendiente';
        } elseif (count(array_unique($tipos)) === 1 && $tipos[0] === 'exacta') {
            $item['tipo'] = 'exacta';
        } else {
            $item['tipo'] = 'alternativa';
        }

        if ($faltaCadena) {
            $item['estado'] = 'excluido';
            $item['motivo'] = 'No hay un producto registrado en alguna de las dos cadenas.';
        } elseif (!$todasVigentes) {
            $item['estado'] = 'excluido';
            $item['motivo'] = 'Precio no vigente o no disponible en alguna cadena.';
        } elseif ($item['tipo'] === 'pendiente') {
            $item['estado'] = 'excluido';
            $item['motivo'] = 'Equivalencia pendiente de clasificar.';
        } elseif ($item['tipo'] === 'alternativa' && !in_array($refId, $aceptadas, true)) {
            $item['estado'] = 'por_aceptar';
            $item['motivo'] = 'Alternativa similar: se incluye solo si usted la acepta.';
        } else {
            $item['estado'] = 'comparable';
            $item['motivo'] = $item['tipo'] === 'exacta' ? 'Coincidencia exacta.' : 'Alternativa similar aceptada.';
            $comparables++;
            foreach ($cadenas as $c) {
                $totales[$c['codigo']]['pc'] += (float) $item['cadenas'][$c['codigo']]['subtotal'];
                if ($item['cadenas'][$c['codigo']]['manual']) {
                    $manuales[$c['nombre']] = true;
                }
            }
        }
        $resultadoItems[] = $item;
    }

    // Costo de acceso y CTA por cadena
    $distancias = (array) ($entrada['distancias'] ?? []);
    foreach ($totales as $codigo => &$t) {
        $d = isset($distancias[$codigo]) && $distancias[$codigo] !== '' && $distancias[$codigo] !== null
            ? max(0.0, (float) $distancias[$codigo]) : null;
        $ca = kx_costo_acceso($modo, $d, $p, $entrada);
        $t['pc'] = round($t['pc']);
        $t['ca'] = $ca['valor'] !== null ? round($ca['valor']) : null;
        $t['ca_detalle'] = $ca['detalle'];
        $t['distancia_km'] = $d;
        $t['cta'] = ($comparables > 0 && $t['ca'] !== null) ? $t['pc'] + $t['ca'] : null;
        if ($ca['valor'] === null) {
            $advertencias[] = sprintf('No se pudo estimar el costo de acceso a %s: %s', $t['nombre'], $ca['detalle']);
        }
    }
    unset($t);

    $ganadorPrecio = $comparables > 0 ? kx_menor($totales, 'pc') : null;
    $ganadorCta = $comparables > 0 ? kx_menor($totales, 'cta') : null;
    if ($comparables === 0) {
        $advertencias[] = 'Ningún producto de la canasta se puede comparar todavía entre las dos cadenas.';
    }
    if ($manuales) {
        $advertencias[] = 'Algunos precios de ' . implode(' y ', array_keys($manuales)) . ' se registraron manualmente a partir de la consulta del sitio web oficial, porque la extracción automática no fue posible.';
    }
    if (count($resultadoItems) - $comparables > 0) {
        $advertencias[] = sprintf('%d producto(s) quedaron por fuera del total; revise el motivo en cada fila.', count($resultadoItems) - $comparables);
    }

    return [
        'ok'               => true,
        'generado_en'      => date('Y-m-d H:i:s'),
        'modo'             => $modo,
        'items'            => $resultadoItems,
        'totales'          => $totales,
        'cobertura'        => ['comparables' => $comparables, 'total' => count($resultadoItems)],
        'ganador_precio'   => $ganadorPrecio,
        'ganador_cta'      => $ganadorCta,
        'cambia_decision'  => $ganadorPrecio !== null && $ganadorCta !== null && $ganadorPrecio !== 'empate' && $ganadorCta !== 'empate' && $ganadorPrecio !== $ganadorCta,
        'advertencias'     => $advertencias,
        'aviso'            => 'Precios publicados en los sitios web oficiales en la fecha indicada; pueden variar. Los costos de acceso son estimaciones. Verifique el precio final en el establecimiento.',
    ];
}

/** Código de la cadena con el menor valor en $campo, 'empate' o null. */
function kx_menor(array $totales, string $campo): ?string
{
    $validos = array_filter($totales, static fn($t) => $t[$campo] !== null);
    if (count($validos) < 2) {
        return null;
    }
    $valores = array_column($validos, $campo);
    $min = min($valores);
    $ganadores = array_keys(array_filter($validos, static fn($t) => abs($t[$campo] - $min) < 0.5));
    return count($ganadores) > 1 ? 'empate' : $ganadores[0];
}
