-- =====================================================================
-- Kanaxta - ARCHIVO 1: ESTRUCTURA (tablas) - MySQL 8 / MariaDB 10.6+
-- Antes de pegarlo: en phpMyAdmin haga clic en la base "kanaxta" (columna
-- izquierda) y luego en la pestaña SQL.
-- Es seguro ejecutarlo más de una vez (usa IF NOT EXISTS).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '-05:00';

CREATE TABLE IF NOT EXISTS cadena (
  id         TINYINT UNSIGNED NOT NULL,
  codigo     VARCHAR(20)  NOT NULL,
  nombre     VARCHAR(60)  NOT NULL,
  sitio_web  VARCHAR(200) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cadena_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sede (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cadena_id  TINYINT UNSIGNED NOT NULL,
  nombre     VARCHAR(120) NOT NULL,
  direccion  VARCHAR(200) NOT NULL,
  localidad  VARCHAR(60)  NOT NULL,
  latitud    DECIMAL(9,6) NOT NULL,
  longitud   DECIMAL(9,6) NOT NULL,
  activa     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_sede_cadena (cadena_id),
  CONSTRAINT fk_sede_cadena FOREIGN KEY (cadena_id) REFERENCES cadena (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Producto genérico de la canasta de referencia.
-- unidad: g (peso), ml (volumen) o und (unidades, rollos, etc.)
-- palabras_clave / palabras_excluir: las usa el extractor para encontrar solo
-- la ficha de cada producto (con | se dan alternativas: espagueti|spaghetti).
CREATE TABLE IF NOT EXISTS producto_referencia (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre_generico  VARCHAR(120) NOT NULL,
  categoria        VARCHAR(60)  NOT NULL,
  cantidad_ref     DECIMAL(10,2) NOT NULL,
  unidad           ENUM('g','ml','und') NOT NULL,
  palabras_clave   VARCHAR(200) NULL,
  palabras_excluir VARCHAR(200) NULL,
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ref_nombre (nombre_generico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Producto tal como lo publica cada cadena (una URL por ficha).
CREATE TABLE IF NOT EXISTS producto_cadena (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cadena_id        TINYINT UNSIGNED NOT NULL,
  url              VARCHAR(500) NOT NULL,
  nombre_publicado VARCHAR(255) NULL,
  marca            VARCHAR(120) NULL,
  cantidad         DECIMAL(10,2) NULL,
  unidad           ENUM('g','ml','und') NULL,
  actualizado_en   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_producto_cadena_url (url(255)),
  KEY ix_pc_cadena (cadena_id),
  CONSTRAINT fk_pc_cadena FOREIGN KEY (cadena_id) REFERENCES cadena (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de cada corrida del extractor.
CREATE TABLE IF NOT EXISTS ejecucion_extraccion (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cadena_id     TINYINT UNSIGNED NOT NULL,
  inicio        DATETIME NOT NULL,
  fin           DATETIME NULL,
  estado        ENUM('ok','parcial','error','bloqueado') NOT NULL,
  fichas_ok     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  fichas_error  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mensaje_error TEXT NULL,
  PRIMARY KEY (id),
  KEY ix_ejec_cadena_inicio (cadena_id, inicio),
  CONSTRAINT fk_ejec_cadena FOREIGN KEY (cadena_id) REFERENCES cadena (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Precio obtenido en una extracción (con fecha y estado de vigencia).
CREATE TABLE IF NOT EXISTS observacion_precio (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_cadena_id INT UNSIGNED NOT NULL,
  ejecucion_id       INT UNSIGNED NOT NULL,
  precio_normal      DECIMAL(12,2) NULL,
  precio_descuento   DECIMAL(12,2) NULL,
  fecha_hora         DATETIME NOT NULL,
  estado_vigencia    ENUM('vigente','no_vigente','no_disponible') NOT NULL,
  detalle            VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_obs_producto_fecha (producto_cadena_id, fecha_hora),
  KEY ix_obs_estado_fecha (estado_vigencia, fecha_hora),
  CONSTRAINT fk_obs_pc FOREIGN KEY (producto_cadena_id) REFERENCES producto_cadena (id) ON DELETE CASCADE,
  CONSTRAINT fk_obs_ejec FOREIGN KEY (ejecucion_id) REFERENCES ejecucion_extraccion (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS administrador (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario         VARCHAR(60)  NOT NULL,
  hash_contrasena VARCHAR(255) NOT NULL,
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relación entre un producto de referencia y un producto de cadena.
-- tipo: exacta | alternativa | pendiente (aún no se puede clasificar)
CREATE TABLE IF NOT EXISTS equivalencia (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_referencia_id INT UNSIGNED NOT NULL,
  producto_cadena_id     INT UNSIGNED NOT NULL,
  tipo                   ENUM('exacta','alternativa','pendiente') NOT NULL DEFAULT 'pendiente',
  estado_revision        ENUM('propuesta','aprobada','rechazada') NOT NULL DEFAULT 'propuesta',
  administrador_id       INT UNSIGNED NULL,
  fecha_revision         DATETIME NULL,
  nota                   VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_equivalencia (producto_referencia_id, producto_cadena_id),
  KEY ix_eq_pc (producto_cadena_id),
  CONSTRAINT fk_eq_ref FOREIGN KEY (producto_referencia_id) REFERENCES producto_referencia (id) ON DELETE CASCADE,
  CONSTRAINT fk_eq_pc  FOREIGN KEY (producto_cadena_id) REFERENCES producto_cadena (id) ON DELETE CASCADE,
  CONSTRAINT fk_eq_admin FOREIGN KEY (administrador_id) REFERENCES administrador (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifas y valores usados para estimar el costo de acceso.
CREATE TABLE IF NOT EXISTS parametro_transporte (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave          VARCHAR(40)  NOT NULL,
  modo           VARCHAR(20)  NOT NULL,
  nombre         VARCHAR(120) NOT NULL,
  valor          DECIMAL(12,2) NOT NULL,
  unidad         VARCHAR(30)  NOT NULL,
  fuente         VARCHAR(255) NOT NULL,
  fecha_vigencia DATE NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_param_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
