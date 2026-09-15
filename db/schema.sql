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
    -- Si esta unidad se tiene que comprar completa aunque la receta pida una
    -- fracción (ej. la receta necesita media manzana o medio huevo, pero se
    -- compra una manzana o un huevo enteros). Se usa para calcular el monto
    -- real de compra por línea, redondeando la cantidad hacia arriba.
    es_entera TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_unidades_medida_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO unidades_medida (nombre, abreviatura, orden) VALUES
('Unidad','unid',10),('Gramo','g',20),('Kilogramo','kg',30),('Miligramo','mg',40),
('Libra','lb',50),('Onza','oz',60),('Litro','l',70),('Mililitro','ml',80),
('Taza','taza',90),('Cucharada','cda',100),('Cucharadita','cdta',110),('Pizca','pizca',120),
('Diente','diente',130),('Rama','rama',140),('Rebanada','rebanada',150),('Manojo','manojo',160),
('Lata','lata',170),('Paquete','paq',180);

-- El seed de "es_entera" (qué unidades se compran completas) lo hace
-- setup.php una sola vez, justo cuando agrega la columna (ver
-- migrarColumnasNuevas) — así corre tanto en una instalación nueva como en
-- una que ya existía, y nunca pisa un ajuste manual hecho después desde
-- Configuración en una corrida posterior de setup.php.

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

-- Categorías de ingrediente (para el catálogo maestro de ingredientes)
CREATE TABLE IF NOT EXISTS categorias_ingrediente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_categorias_ingrediente_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categorias_ingrediente (nombre, orden) VALUES
('Vegetal',10),('Vívere',20),('Fruta',30),('Cárnico',40),('Pescado y marisco',50),
('Lácteo y huevo',60),('Grano y cereal',70),('Legumbre',80),('Edulcorante',90),
('Condimento y especia',100),('Aceite y grasa',110),('Embutido',120),
('Enlatado y conserva',130),('Panadería',140),('Bebida para cocinar',150),
('Repostería',155),('Otro',160);

-- Catálogo maestro de ingredientes: nombre, categoría, ícono, unidad en que
-- se usa dentro de las recetas, y precio de referencia. "unidad_compra" +
-- "contenido_por_compra" separan cómo se COMPRA (ej. un cartón de 30
-- huevos, una libra de azúcar) de cómo se USA en la receta (ej. 1 huevo,
-- 200 gramos de azúcar): el costo por unidad de uso siempre se calcula
-- como precio_compra / contenido_por_compra, así que solo hay un precio
-- que mantener actualizado. Cuando se compra igual que se usa (la mayoría
-- de los casos: una libra de carne se usa en libras), unidad_compra_id
-- queda igual a unidad_id y contenido_por_compra en 1.
CREATE TABLE IF NOT EXISTS ingredientes_catalogo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    categoria_id INT UNSIGNED NOT NULL,
    icono VARCHAR(8) NULL,
    unidad_id INT UNSIGNED NOT NULL,
    unidad_compra_id INT UNSIGNED NULL,
    contenido_por_compra DECIMAL(10,3) NOT NULL DEFAULT 1,
    precio_compra DECIMAL(10,2) NOT NULL DEFAULT 0,
    nota_compra VARCHAR(150) NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ingredientes_catalogo_nombre (nombre),
    KEY idx_ingredientes_catalogo_categoria (categoria_id),
    CONSTRAINT fk_ingcat_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_ingrediente(id),
    CONSTRAINT fk_ingcat_unidad FOREIGN KEY (unidad_id) REFERENCES unidades_medida(id),
    CONSTRAINT fk_ingcat_unidad_compra FOREIGN KEY (unidad_compra_id) REFERENCES unidades_medida(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semilla inicial del catálogo de ingredientes, con precios de referencia
-- investigados en supermercados dominicanos (El Bravo, Supermercados
-- Nacional / comparador SupermercadosRD) en septiembre de 2026. Son
-- precios de referencia para estimar el costo de una receta, no precios
-- exactos del día — se editan libremente desde Ingredientes en la app.
INSERT IGNORE INTO ingredientes_catalogo (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra) VALUES
('Tomate', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🍅', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 47.00, NULL),
('Cebolla roja', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🧅', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 46.00, NULL),
('Cebolla blanca', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🧅', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 42.00, NULL),
('Zanahoria', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🥕', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 31.00, NULL),
('Ají morrón rojo', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🫑', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 74.00, NULL),
('Pimiento verde', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🫑', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 60.00, NULL),
('Ajo', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🧄', (SELECT id FROM unidades_medida WHERE nombre='Diente'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 4, 58.00, 'Paquete importado de 4 dientes'),
('Lechuga', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🥬', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 57.00, NULL),
('Repollo', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🥬', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 35.00, NULL),
('Pepino', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🥒', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 25.00, NULL),
('Habichuela verde (vainita)', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🫛', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 55.00, NULL),
('Auyama', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🎃', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 30.00, NULL),
('Cilantro ancho', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🌿', (SELECT id FROM unidades_medida WHERE nombre='Manojo'), (SELECT id FROM unidades_medida WHERE nombre='Manojo'), 1, 25.00, NULL),
('Perejil', (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'), '🌿', (SELECT id FROM unidades_medida WHERE nombre='Manojo'), (SELECT id FROM unidades_medida WHERE nombre='Manojo'), 1, 25.00, NULL),
('Plátano verde', (SELECT id FROM categorias_ingrediente WHERE nombre='Vívere'), '🍌', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 20.00, NULL),
('Plátano maduro', (SELECT id FROM categorias_ingrediente WHERE nombre='Vívere'), '🍌', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 20.00, NULL),
('Guineo', (SELECT id FROM categorias_ingrediente WHERE nombre='Vívere'), '🍌', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 19.00, NULL),
('Yuca', (SELECT id FROM categorias_ingrediente WHERE nombre='Vívere'), '🍠', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 30.00, NULL),
('Ñame', (SELECT id FROM categorias_ingrediente WHERE nombre='Vívere'), '🍠', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 40.00, NULL),
('Batata', (SELECT id FROM categorias_ingrediente WHERE nombre='Vívere'), '🍠', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 35.00, NULL),
('Papa', (SELECT id FROM categorias_ingrediente WHERE nombre='Vívere'), '🥔', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 38.00, NULL),
('Aguacate', (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'), '🥑', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 32.00, NULL),
('Limón verde', (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'), '🍋', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 68.00, NULL),
('Naranja', (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'), '🍊', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 75.00, NULL),
('Manzana', (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'), '🍎', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 40.00, NULL),
('Piña', (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'), '🍍', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 90.00, NULL),
('Fresa', (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'), '🍓', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 104.00, 'Paquete de 450 g'),
('Lechosa', (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'), '🥭', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 25.00, NULL),
('Pechuga de pollo', (SELECT id FROM categorias_ingrediente WHERE nombre='Cárnico'), '🍗', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 174.00, NULL),
('Pollo entero', (SELECT id FROM categorias_ingrediente WHERE nombre='Cárnico'), '🐔', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 95.00, NULL),
('Carne de res molida', (SELECT id FROM categorias_ingrediente WHERE nombre='Cárnico'), '🥩', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 180.00, NULL),
('Carne de cerdo', (SELECT id FROM categorias_ingrediente WHERE nombre='Cárnico'), '🐷', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 150.00, NULL),
('Tocineta', (SELECT id FROM categorias_ingrediente WHERE nombre='Cárnico'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 180.00, NULL),
('Filete de pescado', (SELECT id FROM categorias_ingrediente WHERE nombre='Pescado y marisco'), '🐟', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 150.00, NULL),
('Camarón', (SELECT id FROM categorias_ingrediente WHERE nombre='Pescado y marisco'), '🦐', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 280.00, NULL),
('Leche entera', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🥛', (SELECT id FROM unidades_medida WHERE nombre='Litro'), (SELECT id FROM unidades_medida WHERE nombre='Litro'), 1, 74.00, NULL),
('Queso mozzarella', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 235.00, NULL),
('Mantequilla', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧈', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 140.00, NULL),
('Huevo', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🥚', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 30, 194.95, 'Cartón de 30 unidades'),
('Yogurt natural', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🥛', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 60.00, NULL),
('Arroz', (SELECT id FROM categorias_ingrediente WHERE nombre='Grano y cereal'), '🍚', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 46.00, NULL),
('Harina de trigo', (SELECT id FROM categorias_ingrediente WHERE nombre='Grano y cereal'), '🌾', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 28.00, NULL),
('Avena', (SELECT id FROM categorias_ingrediente WHERE nombre='Grano y cereal'), '🌾', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 45.00, NULL),
('Pasta (espagueti)', (SELECT id FROM categorias_ingrediente WHERE nombre='Grano y cereal'), '🍝', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 65.00, 'Paquete de 454 g'),
('Habichuelas rojas', (SELECT id FROM categorias_ingrediente WHERE nombre='Legumbre'), '🫘', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 65.00, NULL),
('Garbanzos', (SELECT id FROM categorias_ingrediente WHERE nombre='Legumbre'), '🫘', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 70.00, NULL),
('Lentejas', (SELECT id FROM categorias_ingrediente WHERE nombre='Legumbre'), '🫘', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 60.00, NULL),
('Azúcar blanca', (SELECT id FROM categorias_ingrediente WHERE nombre='Edulcorante'), '🍬', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 35.00, NULL),
('Azúcar morena', (SELECT id FROM categorias_ingrediente WHERE nombre='Edulcorante'), '🍬', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 33.00, NULL),
('Miel de abeja', (SELECT id FROM categorias_ingrediente WHERE nombre='Edulcorante'), '🍯', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 180.00, 'Envase de 340 g'),
('Leche condensada', (SELECT id FROM categorias_ingrediente WHERE nombre='Edulcorante'), '🥫', (SELECT id FROM unidades_medida WHERE nombre='Lata'), (SELECT id FROM unidades_medida WHERE nombre='Lata'), 1, 110.00, NULL),
('Sal', (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'), '🧂', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 14.00, 'Paquete de 425 g'),
('Pimienta negra molida', (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'), '🧂', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 90.00, NULL),
('Orégano', (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'), '🌿', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 60.00, NULL),
('Comino', (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'), '🌿', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 70.00, NULL),
('Canela en polvo', (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'), '🟤', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 65.00, NULL),
('Vainilla líquida', (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'), '🧴', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 120.00, 'Frasco pequeño'),
('Levadura', (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'), '🍞', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 35.00, NULL),
('Aceite vegetal', (SELECT id FROM categorias_ingrediente WHERE nombre='Aceite y grasa'), '🫒', (SELECT id FROM unidades_medida WHERE nombre='Litro'), (SELECT id FROM unidades_medida WHERE nombre='Litro'), 1, 147.00, NULL),
('Aceite de oliva', (SELECT id FROM categorias_ingrediente WHERE nombre='Aceite y grasa'), '🫒', (SELECT id FROM unidades_medida WHERE nombre='Litro'), (SELECT id FROM unidades_medida WHERE nombre='Litro'), 1, 450.00, NULL),
('Jamón', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 180.00, NULL),
('Salami', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 150.00, NULL),
('Atún enlatado', (SELECT id FROM categorias_ingrediente WHERE nombre='Enlatado y conserva'), '🥫', (SELECT id FROM unidades_medida WHERE nombre='Lata'), (SELECT id FROM unidades_medida WHERE nombre='Lata'), 1, 85.00, NULL),
('Salsa de tomate', (SELECT id FROM categorias_ingrediente WHERE nombre='Enlatado y conserva'), '🥫', (SELECT id FROM unidades_medida WHERE nombre='Lata'), (SELECT id FROM unidades_medida WHERE nombre='Lata'), 1, 45.00, NULL),
('Maíz dulce enlatado', (SELECT id FROM categorias_ingrediente WHERE nombre='Enlatado y conserva'), '🌽', (SELECT id FROM unidades_medida WHERE nombre='Lata'), (SELECT id FROM unidades_medida WHERE nombre='Lata'), 1, 65.00, NULL),
('Pan de sándwich', (SELECT id FROM categorias_ingrediente WHERE nombre='Panadería'), '🍞', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 108.00, NULL),
('Pan de agua', (SELECT id FROM categorias_ingrediente WHERE nombre='Panadería'), '🥖', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 10.00, NULL),
('Leche de coco', (SELECT id FROM categorias_ingrediente WHERE nombre='Bebida para cocinar'), '🥥', (SELECT id FROM unidades_medida WHERE nombre='Lata'), (SELECT id FROM unidades_medida WHERE nombre='Lata'), 1, 95.00, NULL),
('Vino blanco para cocinar', (SELECT id FROM categorias_ingrediente WHERE nombre='Bebida para cocinar'), '🍷', (SELECT id FROM unidades_medida WHERE nombre='Unidad'), (SELECT id FROM unidades_medida WHERE nombre='Unidad'), 1, 350.00, NULL),
('Agua', (SELECT id FROM categorias_ingrediente WHERE nombre='Bebida para cocinar'), '💧', (SELECT id FROM unidades_medida WHERE nombre='Litro'), (SELECT id FROM unidades_medida WHERE nombre='Litro'), 1, 37.00, 'Botella de 1.5 L a RD$56');

-- Segunda tanda: más quesos, más embutidos/jamones y más ingredientes de
-- repostería, a pedido — mismos criterios (precios de referencia
-- investigados en supermercados dominicanos, septiembre de 2026).
INSERT IGNORE INTO ingredientes_catalogo (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra) VALUES
('Queso crema', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Onza'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 8, 289.00, 'Paquete de 8 oz (226.8 g), tipo Philadelphia'),
('Queso parmesano', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Onza'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 3, 139.00, 'Paquete de 3 oz (85 g) rallado'),
('Queso cheddar', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 220.00, NULL),
('Queso gouda', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 230.00, NULL),
('Queso ricotta', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 180.00, NULL),
('Queso mascarpone', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Onza'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 16, 350.00, 'Envase de 16 oz (454 g)'),
('Queso azul', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 320.00, NULL),
('Queso suizo', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🧀', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 240.00, NULL),
('Crema para batir', (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'), '🥛', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 2, 235.00, 'Envase de 2 lb (907 g)'),
('Jamón serrano', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Onza'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 2.8, 169.95, 'Paquete loncheado de 80 g'),
('Prosciutto', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Onza'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 2.8, 220.00, 'Paquete loncheado de 80 g aprox.'),
('Jamón de pavo', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 415.00, NULL),
('Chorizo', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 220.00, NULL),
('Pepperoni', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 280.00, NULL),
('Mortadela', (SELECT id FROM categorias_ingrediente WHERE nombre='Embutido'), '🥓', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 140.00, NULL),
('Chocolate oscuro para hornear', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍫', (SELECT id FROM unidades_medida WHERE nombre='Onza'), (SELECT id FROM unidades_medida WHERE nombre='Onza'), 1, 40.00, NULL),
('Chocolate blanco para hornear', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍫', (SELECT id FROM unidades_medida WHERE nombre='Onza'), (SELECT id FROM unidades_medida WHERE nombre='Onza'), 1, 45.00, NULL),
('Cocoa en polvo', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍫', (SELECT id FROM unidades_medida WHERE nombre='Gramo'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 400, 119.00, 'Paquete de 400 g'),
('Polvo de hornear', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🧁', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 35.00, 'Caja de 6 sobres (66 g)'),
('Bicarbonato de sodio', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🧁', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 40.00, NULL),
('Gelatina sin sabor', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🧁', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 50.00, NULL),
('Nueces', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🌰', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 380.00, NULL),
('Almendras', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🌰', (SELECT id FROM unidades_medida WHERE nombre='Libra'), (SELECT id FROM unidades_medida WHERE nombre='Libra'), 1, 420.00, NULL),
('Coco rallado', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🥥', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 90.00, 'Paquete de 250 g'),
('Chispas de chocolate', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍫', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 419.00, 'Bolsa de 326 g'),
('Mermelada', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍓', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 150.00, 'Frasco'),
('Dulce de leche', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍯', (SELECT id FROM unidades_medida WHERE nombre='Lata'), (SELECT id FROM unidades_medida WHERE nombre='Lata'), 1, 140.00, NULL),
('Colorante vegetal', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🎨', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 90.00, 'Set de colores básicos'),
('Galleta María', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍪', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 70.00, 'Para bases de cheesecake'),
('Cereza marrasquino', (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'), '🍒', (SELECT id FROM unidades_medida WHERE nombre='Paquete'), (SELECT id FROM unidades_medida WHERE nombre='Paquete'), 1, 180.00, 'Frasco, para decorar');

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
('ingredientes', 'Ingredientes (catálogo)', 55),
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
SELECT r.id, m.id, 0, 0, 0, 0 FROM roles r JOIN modulos m ON r.nombre = 'Padres' AND m.clave IN ('gastos','estudiantes','ingredientes','configuracion','usuarios');

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
    foto VARCHAR(255) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_recetas_nombre (nombre),
    CONSTRAINT fk_recetas_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_receta(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingredientes base de cada receta (cantidad para "porciones_base" porciones)
CREATE TABLE IF NOT EXISTS ingredientes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receta_id INT UNSIGNED NOT NULL,
    ingrediente_id INT UNSIGNED NULL,
    nombre VARCHAR(150) NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL DEFAULT 0,
    unidad_id INT UNSIGNED NOT NULL,
    costo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
    orden INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_ingredientes_receta FOREIGN KEY (receta_id)
        REFERENCES recetas(id) ON DELETE CASCADE,
    CONSTRAINT fk_ingredientes_unidad FOREIGN KEY (unidad_id) REFERENCES unidades_medida(id),
    CONSTRAINT fk_ingredientes_catalogo FOREIGN KEY (ingrediente_id) REFERENCES ingredientes_catalogo(id),
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
