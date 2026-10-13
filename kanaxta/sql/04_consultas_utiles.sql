-- =====================================================================
-- Kanaxta - CONSULTAS ÚTILES (revisar y mantener)
-- Pegue SOLO el bloque que necesite (cada bloque está separado por una línea
-- de ===). Los bloques de "MANTENIMIENTO" modifican o borran datos.
-- =====================================================================

-- ============ A. REVISAR QUE TODO QUEDÓ BIEN ============

-- A1. Cuántas filas tiene cada tabla importante
SELECT 'cadena' AS tabla, COUNT(*) AS filas FROM cadena
UNION ALL SELECT 'producto_referencia', COUNT(*) FROM producto_referencia
UNION ALL SELECT 'parametro_transporte', COUNT(*) FROM parametro_transporte
UNION ALL SELECT 'sede', COUNT(*) FROM sede
UNION ALL SELECT 'producto_cadena', COUNT(*) FROM producto_cadena
UNION ALL SELECT 'equivalencia', COUNT(*) FROM equivalencia
UNION ALL SELECT 'observacion_precio', COUNT(*) FROM observacion_precio
UNION ALL SELECT 'administrador', COUNT(*) FROM administrador;
-- Esperado después de los pasos 1 a 3: cadena 2, producto_referencia 12, parametro_transporte 9, sede 2 o más.

-- A2. Fichas encontradas por cadena
SELECT c.nombre AS cadena, pc.nombre_publicado, pc.marca, pc.cantidad, pc.unidad, pc.url
FROM producto_cadena pc JOIN cadena c ON c.id = pc.cadena_id
ORDER BY c.id, pc.nombre_publicado;

-- A3. Precios vigentes ahora (menos de 24 horas)
SELECT c.nombre AS cadena, pr.nombre_generico AS producto,
       COALESCE(o.precio_descuento, o.precio_normal) AS precio, o.fecha_hora
FROM observacion_precio o
JOIN producto_cadena pc ON pc.id = o.producto_cadena_id
JOIN cadena c ON c.id = pc.cadena_id
JOIN equivalencia e ON e.producto_cadena_id = pc.id
JOIN producto_referencia pr ON pr.id = e.producto_referencia_id
WHERE o.estado_vigencia = 'vigente'
ORDER BY pr.nombre_generico, c.id;

-- A4. Equivalencias por revisar (propuestas por el extractor)
SELECT pr.nombre_generico AS producto, c.nombre AS cadena, pc.nombre_publicado, e.tipo, e.estado_revision
FROM equivalencia e
JOIN producto_referencia pr ON pr.id = e.producto_referencia_id
JOIN producto_cadena pc ON pc.id = e.producto_cadena_id
JOIN cadena c ON c.id = pc.cadena_id
WHERE e.estado_revision = 'propuesta'
ORDER BY pr.nombre_generico, c.id;

-- A5. Últimas corridas del extractor
SELECT e.id, c.nombre AS cadena, e.inicio, e.estado, e.fichas_ok, e.fichas_error, e.mensaje_error
FROM ejecucion_extraccion e JOIN cadena c ON c.id = e.cadena_id
ORDER BY e.id DESC LIMIT 10;

-- ============ B. MANTENIMIENTO (modifica datos) ============

-- B1. DEMOSTRACIÓN: aprobar de una vez todas las propuestas.
--     Solo para una demo rápida; en uso real revise una por una en /admin/equivalencias.php
UPDATE equivalencia SET estado_revision = 'aprobada' WHERE estado_revision = 'propuesta';

-- B2. Hacer que el extractor busque de nuevo las fichas de UNA cadena
--     (cambie 'olimpica' por 'd1' si es esa). Borra fichas, precios y equivalencias de esa cadena.
DELETE FROM producto_cadena WHERE cadena_id = (SELECT id FROM cadena WHERE codigo = 'olimpica');

-- B3. Hacer que el extractor busque de nuevo la ficha de UN producto en UNA cadena
--     (cambie el nombre del producto y la cadena).
DELETE pc FROM producto_cadena pc
JOIN equivalencia e ON e.producto_cadena_id = pc.id
JOIN producto_referencia pr ON pr.id = e.producto_referencia_id
WHERE pr.nombre_generico = 'Panela'
  AND pc.cadena_id = (SELECT id FROM cadena WHERE codigo = 'olimpica');

-- B4. Borrar todos los precios y el historial de corridas (deja fichas y equivalencias)
DELETE FROM observacion_precio;
DELETE FROM ejecucion_extraccion;

-- B5. Cambiar las palabras de búsqueda de un producto
UPDATE producto_referencia
SET palabras_clave = 'huevo aa', palabras_excluir = 'codorniz chocolate pascua sorpresa'
WHERE nombre_generico = 'Huevos rojos AA';

-- B6. Cambiar el sitio web de una cadena
UPDATE cadena SET sitio_web = 'https://www.olimpica.com/' WHERE codigo = 'olimpica';

-- B7. Desactivar / activar un producto de la canasta (no se borra)
UPDATE producto_referencia SET activo = 0 WHERE nombre_generico = 'Panela';
-- UPDATE producto_referencia SET activo = 1 WHERE nombre_generico = 'Panela';

-- B8. Marcar como no vigente todo precio con más de 24 horas (lo mismo que hace la tarea programada)
UPDATE observacion_precio SET estado_vigencia = 'no_vigente'
WHERE estado_vigencia = 'vigente' AND fecha_hora < (NOW() - INTERVAL 24 HOUR);
