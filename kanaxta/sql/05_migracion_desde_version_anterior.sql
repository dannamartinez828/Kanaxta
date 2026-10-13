-- =====================================================================
-- Kanaxta - MIGRACIÓN (solo si usted ya tenía la base de la versión anterior
-- con Éxito/Ara y quiere pasarla a Olímpica sin empezar de cero).
-- Si arma el proyecto desde cero NO use este archivo.
-- Ejecutar UNA SOLA VEZ, con la base "kanaxta" seleccionada.
-- =====================================================================
SET NAMES utf8mb4;

START TRANSACTION;

-- 1) La cadena 1 pasa a ser Olímpica.
UPDATE cadena
   SET codigo = 'olimpica', nombre = 'Olímpica', sitio_web = 'https://www.olimpica.com/'
 WHERE id = 1;

-- 2) Se borran las fichas y sedes de la cadena anterior (sus URL y direcciones
--    no sirven para Olímpica). Los datos de D1 NO se tocan.
DELETE FROM producto_cadena WHERE cadena_id = 1;
DELETE FROM sede WHERE cadena_id = 1;

-- 3) Columnas de palabras clave (si ya existen, este paso da error: ignórelo).
ALTER TABLE producto_referencia
  ADD COLUMN palabras_clave   VARCHAR(200) NULL AFTER unidad,
  ADD COLUMN palabras_excluir VARCHAR(200) NULL AFTER palabras_clave;

COMMIT;

-- 4) Después pegue 02_datos.sql: no duplica nada y no llena las palabras
--    de los productos que ya existían, así que ejecute además estas líneas:
UPDATE producto_referencia SET palabras_clave = 'arroz',                palabras_excluir = 'integral leche sushi galleta snack cereal ensalada chocolate bebida harina pollo atun' WHERE nombre_generico = 'Arroz blanco';
UPDATE producto_referencia SET palabras_clave = 'lenteja',              palabras_excluir = 'sopa crema galleta snack harina' WHERE nombre_generico = 'Lentejas';
UPDATE producto_referencia SET palabras_clave = 'espagueti|spaghetti|espaguetti', palabras_excluir = 'salsa sopa instantaneo instantanea' WHERE nombre_generico = 'Pasta tipo espagueti';
UPDATE producto_referencia SET palabras_clave = 'aceite',               palabras_excluir = 'oliva coco aguacate esencial spray motor lubricante bebe cabello corporal ajonjoli' WHERE nombre_generico = 'Aceite vegetal';
UPDATE producto_referencia SET palabras_clave = 'azucar',               palabras_excluir = 'sin moreno morena panela light stevia impalpable pulverizada sobres cubos galleta gaseosa bebida cereal' WHERE nombre_generico = 'Azúcar blanca';
UPDATE producto_referencia SET palabras_clave = 'panela',               palabras_excluir = 'bebida aromatica jugo galleta polvo' WHERE nombre_generico = 'Panela';
UPDATE producto_referencia SET palabras_clave = 'cafe molido',          palabras_excluir = 'soluble instantaneo capsula capsulas grano granulado capuchino latte moka bebida galleta liquido helado' WHERE nombre_generico = 'Café molido';
UPDATE producto_referencia SET palabras_clave = 'leche entera',         palabras_excluir = 'condensada polvo deslactosada descremada semidescremada achocolatada chocolate crema avena coco almendra soya arroz galleta bebida' WHERE nombre_generico = 'Leche entera larga vida';
UPDATE producto_referencia SET palabras_clave = 'huevo',                palabras_excluir = 'codorniz chocolate pascua sorpresa' WHERE nombre_generico = 'Huevos rojos AA';
UPDATE producto_referencia SET palabras_clave = 'atun',                 palabras_excluir = 'ensalada pasta galleta mascota gato perro snack sandwich' WHERE nombre_generico = 'Atún en lata';
UPDATE producto_referencia SET palabras_clave = 'papel higienico',      palabras_excluir = 'toalla servilleta cocina' WHERE nombre_generico = 'Papel higiénico';
UPDATE producto_referencia SET palabras_clave = 'detergente polvo',     palabras_excluir = 'liquido lavaplatos loza vajilla gel capsulas suavizante limpiador pisos' WHERE nombre_generico = 'Detergente en polvo para ropa';
