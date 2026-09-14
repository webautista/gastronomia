-- Esquema de base de datos: Gestión de Eventos Gastronómicos
-- Proyecto: WebGastronomia (Fogón Eventos)
-- Motor: MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Estudiantes (lista maestra, reutilizable en cualquier evento)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS estudiantes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    grupo VARCHAR(100) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_estudiantes_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Recetas (catálogo maestro)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recetas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    categoria VARCHAR(60) NOT NULL DEFAULT 'Plato fuerte',
    porciones_base INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_recetas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingredientes base de cada receta (cantidad para "porciones_base" porciones)
CREATE TABLE IF NOT EXISTS ingredientes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receta_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL DEFAULT 0,
    unidad VARCHAR(20) NOT NULL DEFAULT 'unid',
    costo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_ingredientes_receta FOREIGN KEY (receta_id)
        REFERENCES recetas(id) ON DELETE CASCADE,
    KEY idx_ingredientes_receta (receta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Eventos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eventos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    fecha DATE NOT NULL,
    lugar VARCHAR(150) NULL,
    presupuesto DECIMAL(10,2) NOT NULL DEFAULT 0,
    cuota DECIMAL(10,2) NOT NULL DEFAULT 0,
    porciones INT UNSIGNED NOT NULL DEFAULT 0,
    estado ENUM('Planificado','En curso','Finalizado') NOT NULL DEFAULT 'Planificado',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_eventos_fecha (fecha),
    KEY idx_eventos_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estudiantes asignados a un evento + control de pago de la cuota
CREATE TABLE IF NOT EXISTS evento_estudiante (
    evento_id INT UNSIGNED NOT NULL,
    estudiante_id INT UNSIGNED NOT NULL,
    pagado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_pago DATE NULL,
    asignado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (evento_id, estudiante_id),
    CONSTRAINT fk_ee_evento FOREIGN KEY (evento_id)
        REFERENCES eventos(id) ON DELETE CASCADE,
    CONSTRAINT fk_ee_estudiante FOREIGN KEY (estudiante_id)
        REFERENCES estudiantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recetas asignadas a un evento, con las porciones que hay que preparar
-- (las cantidades de ingredientes se calculan en la aplicación como
--  cantidad_base * porciones_necesarias / receta.porciones_base)
CREATE TABLE IF NOT EXISTS evento_receta (
    evento_id INT UNSIGNED NOT NULL,
    receta_id INT UNSIGNED NOT NULL,
    porciones_necesarias INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (evento_id, receta_id),
    CONSTRAINT fk_er_evento FOREIGN KEY (evento_id)
        REFERENCES eventos(id) ON DELETE CASCADE,
    CONSTRAINT fk_er_receta FOREIGN KEY (receta_id)
        REFERENCES recetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gastos asociados a un evento
CREATE TABLE IF NOT EXISTS gastos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    evento_id INT UNSIGNED NOT NULL,
    categoria VARCHAR(60) NOT NULL DEFAULT 'Otro',
    descripcion VARCHAR(255) NOT NULL,
    proveedor VARCHAR(150) NULL,
    monto DECIMAL(10,2) NOT NULL DEFAULT 0,
    fecha DATE NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gastos_evento FOREIGN KEY (evento_id)
        REFERENCES eventos(id) ON DELETE CASCADE,
    KEY idx_gastos_evento (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
