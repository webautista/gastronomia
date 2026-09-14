-- Datos de ejemplo (opcional) para probar la aplicación con algo de contenido.
-- Son los mismos datos usados en el prototipo de navegación.
-- Ejecutar DESPUÉS de schema.sql. Seguro de correr varias veces (limpia antes de insertar).
-- No toca catálogos, roles, permisos ni usuarios — solo los datos de negocio.

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

INSERT INTO grupos_estudiante (nombre, orden) VALUES
('Repostería Avanzada', 10), ('Cocina Internacional', 20), ('Panadería Artesanal', 30)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO estudiantes (id, nombre, telefono, email, grupo_id) VALUES
(1,  'Ana María Ureña',       '809-555-0142', 'ana.urena@correo.com',      (SELECT id FROM grupos_estudiante WHERE nombre = 'Repostería Avanzada')),
(2,  'Carlos Manuel Peña',    '829-555-0187', 'carlos.pena@correo.com',    (SELECT id FROM grupos_estudiante WHERE nombre = 'Cocina Internacional')),
(3,  'Yesenia Ramírez',       '849-555-0123', 'yesenia.ramirez@correo.com',(SELECT id FROM grupos_estudiante WHERE nombre = 'Repostería Avanzada')),
(4,  'Luis Alberto Feliz',    '809-555-0298', 'luis.feliz@correo.com',     (SELECT id FROM grupos_estudiante WHERE nombre = 'Panadería Artesanal')),
(5,  'Rosa Emilia Contreras', '829-555-0341', 'rosa.contreras@correo.com', (SELECT id FROM grupos_estudiante WHERE nombre = 'Cocina Internacional')),
(6,  'Miguel Ángel Rosario',  '809-555-0456', 'miguel.rosario@correo.com', (SELECT id FROM grupos_estudiante WHERE nombre = 'Panadería Artesanal')),
(7,  'Carmen Julia Disla',    '849-555-0512', 'carmen.disla@correo.com',   (SELECT id FROM grupos_estudiante WHERE nombre = 'Repostería Avanzada')),
(8,  'Franklin José Batista', '829-555-0623', 'franklin.batista@correo.com',(SELECT id FROM grupos_estudiante WHERE nombre = 'Cocina Internacional')),
(9,  'Yolanda Beatriz Núñez', '809-555-0734', 'yolanda.nunez@correo.com',  (SELECT id FROM grupos_estudiante WHERE nombre = 'Panadería Artesanal')),
(10, 'Rafael Antonio Cabrera','849-555-0845', 'rafael.cabrera@correo.com', (SELECT id FROM grupos_estudiante WHERE nombre = 'Cocina Internacional'));

INSERT INTO recetas (id, nombre, categoria_id, porciones_base, preparacion) VALUES
(1, 'Mousse de Chocolate', (SELECT id FROM categorias_receta WHERE nombre='Postre'), 10, '1. Derretir el chocolate a baño María.\n2. Batir la crema de leche a punto de nieve suave.\n3. Separar las claras de las yemas y batir las claras a punto de nieve.\n4. Incorporar las yemas y el azúcar al chocolate derretido.\n5. Añadir la crema batida con movimientos envolventes.\n6. Incorporar las claras con cuidado para no bajar el aire.\n7. Refrigerar mínimo 4 horas antes de servir.'),
(2, 'Pollo al Curry con Arroz Basmati', (SELECT id FROM categorias_receta WHERE nombre='Plato fuerte'), 20, '1. Cortar el pollo en cubos y sellar en sartén caliente.\n2. Sofreír la cebolla hasta transparentar.\n3. Agregar el curry en polvo y cocinar 1 minuto para liberar aroma.\n4. Incorporar la leche de coco y cocinar a fuego medio 20 minutos.\n5. Cocinar el arroz basmati aparte según indicaciones del paquete.\n6. Servir el pollo sobre el arroz.'),
(3, 'Pan Baguette Artesanal', (SELECT id FROM categorias_receta WHERE nombre='Panadería'), 12, '1. Disolver la levadura en el agua tibia.\n2. Mezclar con la harina y la sal hasta formar una masa homogénea.\n3. Amasar 10 minutos hasta que quede elástica.\n4. Dejar reposar tapada 1 hora o hasta doblar su tamaño.\n5. Dividir, formar los baguettes y dejar fermentar 30 minutos más.\n6. Hornear a 220°C por 20-25 minutos con vapor.'),
(4, 'Canapés de Salmón Ahumado', (SELECT id FROM categorias_receta WHERE nombre='Aperitivo'), 25, '1. Cortar el pan baguette en rebanadas finas y tostar ligeramente.\n2. Untar cada rebanada con queso crema.\n3. Colocar una porción de salmón ahumado encima.\n4. Decorar con eneldo fresco picado.\n5. Servir frío.');

INSERT INTO ingredientes (receta_id, nombre, cantidad, unidad_id, costo_unitario, orden) VALUES
(1, 'Chocolate oscuro 70%', 400, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.45, 1),
(1, 'Crema de leche', 300, (SELECT id FROM unidades_medida WHERE abreviatura='ml'), 0.12, 2),
(1, 'Huevos', 4, (SELECT id FROM unidades_medida WHERE abreviatura='unid'), 8, 3),
(1, 'Azúcar', 80, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.03, 4),
(1, 'Mantequilla', 50, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.30, 5),
(2, 'Pechuga de pollo', 2000, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.28, 1),
(2, 'Leche de coco', 500, (SELECT id FROM unidades_medida WHERE abreviatura='ml'), 0.15, 2),
(2, 'Curry en polvo', 40, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.60, 3),
(2, 'Cebolla', 300, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.05, 4),
(2, 'Arroz basmati', 1500, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.10, 5),
(3, 'Harina de trigo', 1000, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.04, 1),
(3, 'Agua', 650, (SELECT id FROM unidades_medida WHERE abreviatura='ml'), 0.001, 2),
(3, 'Levadura fresca', 20, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.50, 3),
(3, 'Sal', 18, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.02, 4),
(4, 'Pan baguette', 1, (SELECT id FROM unidades_medida WHERE abreviatura='unid'), 60, 1),
(4, 'Salmón ahumado', 200, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 1.10, 2),
(4, 'Queso crema', 150, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 0.35, 3),
(4, 'Eneldo fresco', 10, (SELECT id FROM unidades_medida WHERE abreviatura='g'), 1.20, 4);

INSERT INTO eventos (id, nombre, fecha, lugar, presupuesto, cuota, porciones, estado_id) VALUES
(1, 'Buffet de Fin de Curso — Cocina Internacional', '2026-10-18', 'Salón Los Almendros', 45000, 1200, 120, (SELECT id FROM estados_evento WHERE nombre='Planificado')),
(2, 'Taller de Repostería Francesa', '2026-09-05', 'Laboratorio de Pastelería 2', 28000, 900, 80, (SELECT id FROM estados_evento WHERE nombre='Finalizado')),
(3, 'Noche de Panadería Artesanal', '2026-11-02', 'Patio Central', 32000, 1000, 100, (SELECT id FROM estados_evento WHERE nombre='Planificado'));

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

INSERT INTO gastos (evento_id, categoria_id, descripcion, proveedor, monto, fecha) VALUES
(1, (SELECT id FROM categorias_gasto WHERE nombre='Ingredientes'), 'Compra de proteínas y vegetales', 'Mercado La Sirena', 18500, '2026-09-08'),
(1, (SELECT id FROM categorias_gasto WHERE nombre='Alquiler de espacio'), 'Renta de salón + mobiliario', 'Salón Los Almendros', 8000, '2026-09-05'),
(1, (SELECT id FROM categorias_gasto WHERE nombre='Decoración'), 'Centros de mesa y mantelería', 'Detalles Criollos', 3200, '2026-09-09'),
(1, (SELECT id FROM categorias_gasto WHERE nombre='Personal de apoyo'), 'Meseros y limpieza (4 personas)', 'Staff externo', 4500, '2026-09-12'),
(2, (SELECT id FROM categorias_gasto WHERE nombre='Ingredientes'), 'Chocolate, lácteos y huevos', 'Distribuidora Ferretti', 9200, '2026-08-28'),
(2, (SELECT id FROM categorias_gasto WHERE nombre='Alquiler de espacio'), 'Uso de laboratorio', 'Instituto', 2000, '2026-08-30'),
(3, (SELECT id FROM categorias_gasto WHERE nombre='Ingredientes'), 'Harinas y levaduras', 'Molinos del Cibao', 5400, '2026-09-10');
