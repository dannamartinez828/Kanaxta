-- =====================================================================
-- Kanaxta - ARCHIVO 2: DATOS (cadenas, canasta, tarifas y sedes)
-- Ejecutar DESPUÉS de 01_estructura.sql, con la base "kanaxta" seleccionada.
-- >>> ANTES de pegarlo, cambie los datos marcados CAMBIE en la sección de SEDES (al final) <<<
-- Las cadenas, productos y tarifas usan INSERT IGNORE (no se duplican).
-- Las SEDES no: si repite este archivo, antes ejecute  DELETE FROM sede;
-- Las URL de las fichas NO se cargan: el extractor las busca solo.
-- =====================================================================

SET NAMES utf8mb4;

-- Las dos cadenas que se comparan.
INSERT IGNORE INTO cadena (id, codigo, nombre, sitio_web) VALUES
  (1, 'olimpica', 'Olímpica', 'https://www.olimpica.com/'),
  (2, 'd1',       'D1',       'https://d1.com.co/');

-- Canasta de referencia (12 productos) con las palabras que usa el extractor:
--   palabras_clave   → TODAS deben aparecer en la ficha (con | se dan alternativas)
--   palabras_excluir → si aparece ALGUNA, la ficha se descarta
-- Se pueden cambiar luego en  /admin/catalogo.php
INSERT IGNORE INTO producto_referencia
  (nombre_generico, categoria, cantidad_ref, unidad, palabras_clave, palabras_excluir) VALUES
  ('Arroz blanco',                  'Granos y cereales', 1000, 'g',
     'arroz',
     'integral leche sushi galleta snack cereal ensalada chocolate bebida harina pollo atun'),
  ('Lentejas',                      'Granos y cereales',  500, 'g',
     'lenteja',
     'sopa crema galleta snack harina'),
  ('Pasta tipo espagueti',          'Granos y cereales',  500, 'g',
     'espagueti|spaghetti|espaguetti',
     'salsa sopa instantaneo instantanea'),
  ('Aceite vegetal',                'Aceites y grasas',  1000, 'ml',
     'aceite',
     'oliva coco aguacate esencial spray motor lubricante bebe cabello corporal ajonjoli'),
  ('Azúcar blanca',                 'Endulzantes',       1000, 'g',
     'azucar',
     'sin moreno morena panela light stevia impalpable pulverizada sobres cubos galleta gaseosa bebida cereal'),
  ('Panela',                        'Endulzantes',        500, 'g',
     'panela',
     'bebida aromatica jugo galleta polvo'),
  ('Café molido',                   'Bebidas',            250, 'g',
     'cafe molido',
     'soluble instantaneo capsula capsulas grano granulado capuchino latte moka bebida galleta liquido helado'),
  ('Leche entera larga vida',       'Lácteos',           1000, 'ml',
     'leche entera',
     'condensada polvo deslactosada descremada semidescremada achocolatada chocolate crema avena coco almendra soya arroz galleta bebida'),
  ('Huevos rojos AA',               'Proteína',            30, 'und',
     'huevo',
     'codorniz chocolate pascua sorpresa'),
  ('Atún en lata',                  'Proteína',           170, 'g',
     'atun',
     'ensalada pasta galleta mascota gato perro snack sandwich'),
  ('Papel higiénico',               'Aseo personal',       12, 'und',
     'papel higienico',
     'toalla servilleta cocina'),
  ('Detergente en polvo para ropa', 'Aseo del hogar',    1000, 'g',
     'detergente polvo',
     'liquido lavaplatos loza vajilla gel capsulas suavizante limpiador pisos');

-- Parámetros de transporte (Tabla 5 del anteproyecto). Actualícelos desde
-- /admin/parametros.php cuando cambien las tarifas oficiales.
INSERT IGNORE INTO parametro_transporte (clave, modo, nombre, valor, unidad, fuente, fecha_vigencia) VALUES
  ('tarifa_publico',       'publico', 'Tarifa por pasaje TransMilenio / SITP',            3550, 'COP por pasaje',
   'TransMilenio S. A. - tarifa unificada desde el 14 de enero de 2026', '2026-01-14'),
  ('precio_galon',         'carro',   'Precio del galón de gasolina corriente en Bogotá', 16377, 'COP por galón',
   'Precio de referencia del Gobierno nacional desde el 1 de octubre de 2026', '2026-10-01'),
  ('rendimiento_km_galon', 'carro',   'Rendimiento de referencia del vehículo',             35, 'km por galón',
   'Valor de referencia configurable (el usuario puede declarar el suyo)', '2026-10-01'),
  ('parqueadero',          'carro',   'Costo de parqueadero por visita',                     0, 'COP por visita',
   'Valor de referencia configurable (el usuario puede declarar el suyo)', '2026-10-01'),
  ('taxi_minima',          'taxi',    'Carrera mínima de taxi',                           8000, 'COP',
   'Decreto Distrital 042 de 2026 (sin factor de excelencia)', '2026-02-12'),
  ('taxi_arranque',        'taxi',    'Banderazo (arranque) de taxi',                     4500, 'COP',
   'Decreto Distrital 042 de 2026 (sin factor de excelencia)', '2026-02-12'),
  ('taxi_km',              'taxi',    'Valor estimado por kilómetro en taxi',              1625, 'COP por km',
   'Decreto Distrital 042 de 2026, tabla de equivalencias (sin factor de excelencia)', '2026-02-12'),
  ('factor_recorrido',     'general', 'Factor de recorrido (línea recta a ruta vial)',      1.3, 'factor',
   'Supuesto del proyecto; se calibra con rutas reales', '2026-10-01'),
  ('horas_vigencia',       'general', 'Horas durante las que un precio se considera vigente', 24, 'horas',
   'Criterio del proyecto (numeral 9.10)', '2026-10-01');

-- =====================================================================
-- SEDES (tiendas sobre la Av. Carrera 68, Bogotá)  ->  MODIFIQUE las líneas CAMBIE
--
-- Cómo sacar latitud y longitud:
--   1. Abra Google Maps y busque la tienda.
--   2. Clic derecho sobre el punto de la tienda -> clic en los números
--      (ej. 4.668300, -74.083400) para copiarlos.
--   3. El primer número es la latitud y el segundo la longitud.
-- cadena_id: 1 = Olímpica, 2 = D1
-- Las sedes también se pueden agregar desde /admin/parametros.php
-- =====================================================================
INSERT INTO sede (cadena_id, nombre, direccion, localidad, latitud, longitud) VALUES
  -- Olímpica
  (1, 'Olímpica Metrópolis',   'CAMBIE: dirección de la sede (Av. Cra. 68 # ...)', 'CAMBIE: localidad', 4.668300, -74.083400),
  -- D1
  (2, 'Tienda D1 Ferias',      'CAMBIE: dirección de la sede (Av. Cra. 68 # ...)', 'CAMBIE: localidad', 4.680000, -74.083000);

-- Para agregar otra sede copie una línea, cambie los datos y use coma entre líneas
-- (la última línea de la lista termina en punto y coma).
