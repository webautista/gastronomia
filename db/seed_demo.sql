-- Datos de ejemplo (opcional) para probar la aplicación con algo de contenido.
-- Son los mismos datos usados en el prototipo de navegación.
-- Ejecutar DESPUÉS de schema.sql. Seguro de correr varias veces (limpia antes de insertar).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE gastos;
TRUNCATE TABLE evento_receta;
TRUNCATE TABLE evento_estudiante;
TRUNCATE TABLE ingredientes;
TRUNCATE TABLE eventos;
TRUNCATE TABLE recetas;
TRUNCATE TABLE estudiantes;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO estudiantes (id, nombre, telefono, email, grupo) VALUES
(1,  'Ana María Ureña',       '809-555-0142', 'ana.urena@correo.com',      'Repostería Avanzada'),
(2,  'Carlos Manuel Peña',    '829-555-0187', 'carlos.pena@correo.com',    'Cocina Internacional'),
(3,  'Yesenia Ramírez',       '849-555-0123', 'yesenia.ramirez@correo.com','Repostería Avanzada'),
(4,  'Luis Alberto Feliz',    '809-555-0298', 'luis.feliz@correo.com',     'Panadería Artesanal'),
(5,  'Rosa Emilia Contreras', '829-555-0341', 'rosa.contreras@correo.com', 'Cocina Internacional'),
(6,  'Miguel Ángel Rosario',  '809-555-0456', 'miguel.rosario@correo.com', 'Panadería Artesanal'),
(7,  'Carmen Julia Disla',    '849-555-0512', 'carmen.disla@correo.com',   'Repostería Avanzada'),
(8,  'Franklin José Batista', '829-555-0623', 'franklin.batista@correo.com','Cocina Internacional'),
(9,  'Yolanda Beatriz Núñez', '809-555-0734', 'yolanda.nunez@correo.com',  'Panadería Artesanal'),
(10, 'Rafael Antonio Cabrera','849-555-0845', 'rafael.cabrera@correo.com', 'Cocina Internacional');

INSERT INTO recetas (id, nombre, categoria, porciones_base) VALUES
(1, 'Mousse de Chocolate', 'Postre', 10),
(2, 'Pollo al Curry con Arroz Basmati', 'Plato fuerte', 20),
(3, 'Pan Baguette Artesanal', 'Panadería', 12),
(4, 'Canapés de Salmón Ahumado', 'Aperitivo', 25);

INSERT INTO ingredientes (receta_id, nombre, cantidad, unidad, costo_unitario, orden) VALUES
(1, 'Chocolate oscuro 70%', 400, 'g', 0.45, 1),
(1, 'Crema de leche', 300, 'ml', 0.12, 2),
(1, 'Huevos', 4, 'unid', 8, 3),
(1, 'Azúcar', 80, 'g', 0.03, 4),
(1, 'Mantequilla', 50, 'g', 0.30, 5),
(2, 'Pechuga de pollo', 2000, 'g', 0.28, 1),
(2, 'Leche de coco', 500, 'ml', 0.15, 2),
(2, 'Curry en polvo', 40, 'g', 0.60, 3),
(2, 'Cebolla', 300, 'g', 0.05, 4),
(2, 'Arroz basmati', 1500, 'g', 0.10, 5),
(3, 'Harina de trigo', 1000, 'g', 0.04, 1),
(3, 'Agua', 650, 'ml', 0.001, 2),
(3, 'Levadura fresca', 20, 'g', 0.50, 3),
(3, 'Sal', 18, 'g', 0.02, 4),
(4, 'Pan baguette', 1, 'unid', 60, 1),
(4, 'Salmón ahumado', 200, 'g', 1.10, 2),
(4, 'Queso crema', 150, 'g', 0.35, 3),
(4, 'Eneldo fresco', 10, 'g', 1.20, 4);

INSERT INTO eventos (id, nombre, fecha, lugar, presupuesto, cuota, porciones, estado) VALUES
(1, 'Buffet de Fin de Curso — Cocina Internacional', '2026-10-18', 'Salón Los Almendros', 45000, 1200, 120, 'Planificado'),
(2, 'Taller de Repostería Francesa', '2026-09-05', 'Laboratorio de Pastelería 2', 28000, 900, 80, 'Finalizado'),
(3, 'Noche de Panadería Artesanal', '2026-11-02', 'Patio Central', 32000, 1000, 100, 'Planificado');

INSERT INTO evento_estudiante (evento_id, estudiante_id, pagado, fecha_pago) VALUES
(1, 2,  1, '2026-09-02'),
(1, 5,  1, '2026-09-04'),
(1, 8,  0, NULL),
(1, 10, 1, '2026-09-10'),
(1, 3,  0, NULL),
(1, 1,  1, '2026-09-11'),
(2, 1,  1, '2026-08-20'),
(2, 3,  1, '2026-08-21'),
(2, 7,  1, '2026-08-25'),
(3, 4,  0, NULL),
(3, 6,  0, NULL),
(3, 9,  1, '2026-09-13');

INSERT INTO evento_receta (evento_id, receta_id, porciones_necesarias) VALUES
(1, 2, 120),
(1, 4, 150),
(2, 1, 80),
(3, 3, 100);

INSERT INTO gastos (evento_id, categoria, descripcion, proveedor, monto, fecha) VALUES
(1, 'Ingredientes', 'Compra de proteínas y vegetales', 'Mercado La Sirena', 18500, '2026-09-08'),
(1, 'Alquiler de espacio', 'Renta de salón + mobiliario', 'Salón Los Almendros', 8000, '2026-09-05'),
(1, 'Decoración', 'Centros de mesa y mantelería', 'Detalles Criollos', 3200, '2026-09-09'),
(1, 'Personal de apoyo', 'Meseros y limpieza (4 personas)', 'Staff externo', 4500, '2026-09-12'),
(2, 'Ingredientes', 'Chocolate, lácteos y huevos', 'Distribuidora Ferretti', 9200, '2026-08-28'),
(2, 'Alquiler de espacio', 'Uso de laboratorio', 'Instituto', 2000, '2026-08-30'),
(3, 'Ingredientes', 'Harinas y levaduras', 'Molinos del Cibao', 5400, '2026-09-10');
