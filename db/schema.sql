-- Esquema de base de datos: Gestión de Eventos Gastronómicos
-- Proyecto: WebGastronomia (Fogón Eventos)
-- Motor: MySQL 5.7+ / MariaDB 10.3+
--
-- Diseño normalizado: todo campo de "opciones" que puede crecer con el
-- tiempo (categorías, estados, unidades, grupos, roles) vive en su propia
-- tabla catálogo y se referencia por llave foránea, nunca como texto libre
-- repetido. Esto evita inconsistencias (errores de tipeo, mayúsculas
-- distintas) y permite administrar esas listas desde la aplicación
-- (ver módulo Configuración) sin tocar código.
--
-- Nota para instalaciones que ya existían antes de este esquema: este
-- archivo solo CREA tablas nuevas (CREATE TABLE IF NOT EXISTS no modifica
-- una tabla existente). La migración de columnas nuevas / llaves foráneas
-- sobre datos que ya existían la hace setup.php (función migrarEsquema),
-- de forma segura y sin perder datos.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =======================================================================
-- CATÁLOGOS (parametrización — administrables desde Configuración)
-- =======================================================================

-- Unidades de medida (dropdown de ingredientes)
CREATE TABLE IF NOT EXISTS unidades_medida (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(40) NOT NULL,
    abreviatura VARCHAR(10) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_unidades_medida_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO unidades_medida (nombre, abreviatura, orden) VALUES
('Unidad','unid',10),('Gramo','g',20),('Kilogramo','kg',30),('Miligramo','mg',40),
('Libra','lb',50),('Onza','oz',60),('Litro','l',70),('Mililitro','ml',80),
('Taza','taza',90),('Cucharada','cda',100),('Cucharadita','cdta',110),('Pizca','pizca',120),
('Diente','diente',130),('Rama','rama',140),('Rebanada','rebanada',150),('Manojo','manojo',160),
('Lata','lata',170),('Paquete','paq',180);

-- Categorías de receta
CREATE TABLE IF NOT EXISTS categorias_receta (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_categorias_receta_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categorias_receta (nombre, orden) VALUES
('Plato fuerte',10),('Postre',20),('Aperitivo',30),('Panadería',40),('Bebida',50);

-- Categorías de gasto
CREATE TABLE IF NOT EXISTS categorias_gasto (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_categorias_gasto_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categorias_gasto (nombre, orden) VALUES
('Ingredientes',10),('Alquiler de espacio',20),('Transporte',30),('Decoración',40),
('Personal de apoyo',50),('Otro',60);

-- Estados de evento
CREATE TABLE IF NOT EXISTS estados_evento (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(40) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_estados_evento_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO estados_evento (nombre, orden) VALUES
('Planificado',10),('En curso',20),('Finalizado',30);

-- Grupos / clases de estudiantes (se alimenta también de lo que el
-- usuario escriba; ver módulo Configuración para administrarlo a mano)
CREATE TABLE IF NOT EXISTS grupos_estudiante (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_grupos_estudiante_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Módulos del sistema (pantallas/funcionalidades sobre las que se
-- otorgan permisos por rol)
CREATE TABLE IF NOT EXISTS modulos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(40) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_modulos_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO modulos (clave, nombre, orden) VALUES
('panel', 'Panel general', 10),
('eventos', 'Eventos', 20),
('gastos', 'Gastos de eventos', 30),
('estudiantes', 'Estudiantes', 40),
('recetas', 'Recetas', 50),
('configuracion', 'Configuración / catálogos', 60),
('usuarios', 'Usuarios y roles', 70);

-- =======================================================================
-- ROLES, PERMISOS Y USUARIOS
-- =======================================================================

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    descripcion VARCHAR(255) NULL,
    es_sistema TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (nombre, descripcion, es_sistema) VALUES
('Administrador', 'Acceso completo a todos los módulos.', 1),
('Padres', 'Acceso de solo lectura a eventos y recetas.', 0);

-- Permiso de un rol sobre un módulo: ver / crear / editar / eliminar
CREATE TABLE IF NOT EXISTS permisos_rol (
    rol_id INT UNSIGNED NOT NULL,
    modulo_id INT UNSIGNED NOT NULL,
    ver TINYINT(1) NOT NULL DEFAULT 0,
    crear TINYINT(1) NOT NULL DEFAULT 0,
    editar TINYINT(1) NOT NULL DEFAULT 0,
    eliminar TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (rol_id, modulo_id),
    CONSTRAINT fk_pr_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_modulo FOREIGN KEY (modulo_id) REFERENCES modulos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Administrador: todo permitido en todos los módulos
INSERT IGNORE INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT r.id, m.id, 1, 1, 1, 1 FROM roles r JOIN modulos m ON r.nombre = 'Administrador';

-- Padres: solo ver eventos y recetas (incluye el estado de pagos dentro
-- del detalle del evento), nada de gastos, estudiantes, configuración ni
-- usuarios, y ninguna acción de crear/editar/eliminar.
INSERT IGNORE INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT r.id, m.id, 1, 0, 0, 0 FROM roles r JOIN modulos m ON r.nombre = 'Padres' AND m.clave IN ('panel','eventos','recetas');
INSERT IGNORE INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT r.id, m.id, 0, 0, 0, 0 FROM roles r JOIN modulos m ON r.nombre = 'Padres' AND m.clave IN ('gastos','estudiantes','configuracion','usuarios');

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    usuario VARCHAR(60) NOT NULL,
    email VARCHAR(150) NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol_id INT UNSIGNED NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_usuario (usuario),
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Estudiantes (lista maestra, reutilizable en cualquier evento)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS estudiantes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    grupo_id INT UNSIGNED NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_estudiantes_nombre (nombre),
    CONSTRAINT fk_estudiantes_grupo FOREIGN KEY (grupo_id) REFERENCES grupos_estudiante(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Recetas (catálogo maestro)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recetas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    categoria_id INT UNSIGNED NOT NULL,
    porciones_base INT UNSIGNED NOT NULL DEFAULT 1,
    preparacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_recetas_nombre (nombre),
    CONSTRAINT fk_recetas_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_receta(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingredientes base de cada receta (cantidad para "porciones_base" porciones)
CREATE TABLE IF NOT EXISTS ingredientes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receta_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL DEFAULT 0,
    unidad_id INT UNSIGNED NOT NULL,
    costo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_ingredientes_receta FOREIGN KEY (receta_id)
        REFERENCES recetas(id) ON DELETE CASCADE,
    CONSTRAINT fk_ingredientes_unidad FOREIGN KEY (unidad_id) REFERENCES unidades_medida(id),
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
    estado_id INT UNSIGNED NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_eventos_fecha (fecha),
    KEY idx_eventos_estado (estado_id),
    CONSTRAINT fk_eventos_estado FOREIGN KEY (estado_id) REFERENCES estados_evento(id)
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
    categoria_id INT UNSIGNED NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    proveedor VARCHAR(150) NULL,
    monto DECIMAL(10,2) NOT NULL DEFAULT 0,
    fecha DATE NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gastos_evento FOREIGN KEY (evento_id)
        REFERENCES eventos(id) ON DELETE CASCADE,
    CONSTRAINT fk_gastos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_gasto(id),
    KEY idx_gastos_evento (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
