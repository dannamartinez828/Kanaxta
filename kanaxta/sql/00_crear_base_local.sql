-- =====================================================================
-- Kanaxta - PASO 0 (solo para instalación LOCAL con XAMPP)
-- Dónde pegarlo: http://localhost/phpmyadmin → pestaña SQL → Continuar
-- En DonWeb/Ferozo la base ya se crea desde el panel: salte este archivo.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS kanaxta
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- OPCIONAL (recomendado si el equipo se comparte): usuario propio de la base.
-- Cambie CAMBIE_ESTA_CLAVE y use el mismo valor en privado/config.php (db_clave).
-- Si prefiere el usuario "root" sin clave de XAMPP, no ejecute estas 3 líneas.
CREATE USER IF NOT EXISTS 'kanaxta_user'@'localhost' IDENTIFIED BY 'CAMBIE_ESTA_CLAVE';
GRANT ALL PRIVILEGES ON kanaxta.* TO 'kanaxta_user'@'localhost';
FLUSH PRIVILEGES;
