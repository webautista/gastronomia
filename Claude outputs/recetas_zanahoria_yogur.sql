-- =====================================================================
-- Fogón Eventos — Agregar 2 recetas nuevas + equivalencias de catálogo
--   1) Pastel de zanahoria con harina de almendras
--   2) Yogur con granola y frutas
--
-- CÓMO USARLO:
--   phpMyAdmin -> elige tu base de datos -> pestaña "SQL" -> pega todo
--   este archivo -> Continuar. Es seguro ejecutarlo más de una vez: cada
--   bloque revisa primero si ya existe antes de insertar, así que no
--   duplica nada si lo corres por error dos veces.
--
--   Corre este script en CADA base de datos donde quieras que aparezcan
--   las recetas (tu MySQL local de XAMPP y, cuando quieras, la de
--   producción en Hostinger) — son bases de datos separadas y una no se
--   entera de lo que pasa en la otra.
--
-- QUÉ HACE:
--   - Crea el ingrediente "Harina de almendras" en el catálogo (no existía).
--   - Le agrega a "Zanahoria", "Azúcar morena", "Mango" y "Yogurt natural
--     (envase grande)" la densidad/peso que le faltaba para poder usarse
--     en "taza" en estas recetas (si ya la tenían cargada, no se toca).
--   - Crea las 2 recetas con todos sus ingredientes, cantidades y pasos
--     de preparación, tal como me los diste.
--
-- Precios y equivalencias con fuente (ver nota_compra de cada uno):
--   harina de almendras, azúcar morena, zanahoria rallada, mango y
--   yogurt son ESTIMADOS de referencias públicas (supermercadosrd.com y
--   tablas de conversión de cocina) — revísalos y ajústalos si tu
--   proveedor real tiene otro precio, igual que ajustaste la piña.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) Catálogo: nuevo ingrediente "Harina de almendras"
-- ---------------------------------------------------------------------
INSERT INTO ingredientes_catalogo
  (nombre, categoria_id, unidad_id, unidad_compra_id, contenido_por_compra, densidad_g_ml, precio_compra, nota_compra, orden, activo)
SELECT
  'Harina de almendras',
  (SELECT id FROM categorias_ingrediente WHERE nombre = 'Grano y cereal'),
  (SELECT id FROM unidades_medida WHERE nombre = 'Libra'),
  (SELECT id FROM unidades_medida WHERE nombre = 'Libra'),
  1.000,
  0.4000,
  525.99,
  'Harina de Almendra Carrefour 125 g, RD$144.95 (supermercadosrd.com) equivale a ~RD$525.99/lb; 96 g/taza (gramspercup.com)',
  0, 1
WHERE NOT EXISTS (SELECT 1 FROM ingredientes_catalogo WHERE nombre = 'Harina de almendras');

-- ---------------------------------------------------------------------
-- 2) Catálogo: completar equivalencias que faltaban (solo si están vacías)
-- ---------------------------------------------------------------------

-- Zanahoria rallada: 110 g/taza (referencia nutricional estándar)
UPDATE ingredientes_catalogo
   SET densidad_g_ml = 0.4583,
       nota_compra = COALESCE(NULLIF(nota_compra, ''), 'Rallada: ~110 g/taza (estándar nutricional tipo USDA/Nutritionix)')
 WHERE nombre = 'Zanahoria' AND densidad_g_ml IS NULL;

-- Azúcar morena compactada: 200 g/taza (medidasrecetascocina.com)
UPDATE ingredientes_catalogo
   SET densidad_g_ml = 0.8333,
       nota_compra = COALESCE(NULLIF(nota_compra, ''), 'Compactada: ~200 g/taza (medidasrecetascocina.com)')
 WHERE nombre = 'Azúcar morena' AND densidad_g_ml IS NULL;

-- Mango en cubos: 165 g/taza (USDA) + peso aprox. de 1 unidad entera: 250 g
UPDATE ingredientes_catalogo
   SET densidad_g_ml = 0.6875,
       peso_unidad_g = 250.00,
       nota_compra = 'RD$34/unidad (supermercadosrd.com); en cubos 165 g/taza (USDA); peso aprox. 1 unidad: 250 g (var. común)'
 WHERE nombre = 'Mango' AND densidad_g_ml IS NULL AND peso_unidad_g IS NULL;

-- Yogurt natural (envase grande): densidad 1.03 g/mL (estándar de yogur entero)
UPDATE ingredientes_catalogo
   SET densidad_g_ml = 1.0300,
       nota_compra = 'Se usa por peso. Yogurt Litro Deliciel RD$150.00/L (deliciel.com.do); 1030 g/L; densidad 1.03 g/mL'
 WHERE nombre = 'Yogurt natural (envase grande)' AND densidad_g_ml IS NULL;

-- ---------------------------------------------------------------------
-- 3) Receta: Pastel de zanahoria con harina de almendras (10 porciones)
-- ---------------------------------------------------------------------
INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion, foto)
SELECT
  'Pastel de zanahoria con harina de almendras',
  'Molde redondo de 22 cm. Horneado: 40–50 minutos.',
  (SELECT id FROM categorias_receta WHERE nombre = 'Postre'),
  10,
  '1. Precalienta el horno a 175 °C (350 °F). Engrasa el molde y cubre el fondo con papel para hornear.
2. En un recipiente, mezcla la harina de almendras, el polvo de hornear, el bicarbonato, la canela, la nuez moscada y la sal.
3. En otro recipiente, bate los huevos con el azúcar durante 2–3 minutos. Agrega el aceite y la vainilla.
4. Incorpora los ingredientes secos a la mezcla líquida y remueve suavemente hasta integrarlos.
5. Agrega la zanahoria rallada, las nueces y las pasas. Mezcla con una espátula. La masa quedará más húmeda y densa que una preparada con harina de trigo.
6. Vierte la mezcla en el molde y nivela la superficie.
7. Hornea durante 40–50 minutos. Comprueba la cocción introduciendo un palillo en el centro; debe salir sin masa cruda, aunque puede presentar algunas migas húmedas.
8. Déjalo enfriar en el molde durante 15 minutos. Luego desmolda cuidadosamente y espera a que se enfríe por completo antes de decorar.',
  NULL
WHERE NOT EXISTS (SELECT 1 FROM recetas WHERE nombre = 'Pastel de zanahoria con harina de almendras');

INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, al_gusto, opcional, orden)
SELECT r.id, c.id, v.nombre, v.cantidad,
       (SELECT id FROM unidades_medida WHERE nombre = v.unidad), v.costo, 0, v.opcional, v.orden
FROM (SELECT 'Pastel de zanahoria con harina de almendras' AS receta) r0
JOIN recetas r ON r.nombre = r0.receta
JOIN (
  SELECT 'Harina de almendras' AS nombre, 3.00 AS cantidad, 'Taza' AS unidad, 111.32 AS costo, 0 AS opcional, 10 AS orden
  UNION ALL SELECT 'Zanahoria', 2.00, 'Taza', 7.52, 0, 20
  UNION ALL SELECT 'Huevo', 4.00, 'Unidad', 6.50, 0, 30
  UNION ALL SELECT 'Azúcar morena', 0.75, 'Taza', 14.55, 0, 40
  UNION ALL SELECT 'Aceite vegetal', 0.50, 'Taza', 35.28, 0, 50
  UNION ALL SELECT 'Extracto de vainilla', 1.00, 'Cucharadita', 44.99, 0, 60
  UNION ALL SELECT 'Polvo de hornear', 2.00, 'Cucharadita', 2.50, 0, 70
  UNION ALL SELECT 'Bicarbonato de sodio', 0.50, 'Cucharadita', 1.04, 0, 80
  UNION ALL SELECT 'Canela en polvo', 2.00, 'Cucharadita', 3.80, 0, 90
  UNION ALL SELECT 'Nuez moscada molida', 0.25, 'Cucharadita', 6.56, 0, 100
  UNION ALL SELECT 'Sal', 0.50, 'Cucharadita', 0.20, 0, 110
  UNION ALL SELECT 'Nueces', 0.50, 'Taza', 96.03, 1, 120
  UNION ALL SELECT 'Pasas', 0.33, 'Taza', 71.90, 1, 130
) v ON 1=1
JOIN ingredientes_catalogo c ON c.nombre = v.nombre
WHERE NOT EXISTS (
  SELECT 1 FROM ingredientes i WHERE i.receta_id = r.id AND i.nombre = v.nombre
);

-- Acciones de preparación (rallado / picado) para esta receta
INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id)
SELECT i.id, a.id
FROM ingredientes i
JOIN recetas r ON r.id = i.receta_id AND r.nombre = 'Pastel de zanahoria con harina de almendras'
JOIN (
  SELECT 'Zanahoria' AS ingrediente, 'Rallado' AS accion
  UNION ALL SELECT 'Nueces', 'Picado'
) v ON v.ingrediente = i.nombre
JOIN acciones_ingrediente a ON a.nombre = v.accion
WHERE NOT EXISTS (SELECT 1 FROM ingrediente_accion ia WHERE ia.receta_ingrediente_id = i.id AND ia.accion_id = a.id);

-- ---------------------------------------------------------------------
-- 4) Receta: Yogur con granola y frutas (6 porciones)
-- ---------------------------------------------------------------------
INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion, foto)
SELECT
  'Yogur con granola y frutas',
  'Tiempo de preparación: 10 minutos.',
  (SELECT id FROM categorias_receta WHERE nombre = 'Postre'),
  6,
  '1. Lava y corta todas las frutas.
2. Coloca una porción de yogur en cada vaso o recipiente.
3. Agrega una capa de granola.
4. Incorpora las rodajas de banana, las fresas y el mango.
5. Añade otra pequeña capa de yogur y granola.
6. Decora con miel, nueces o almendras y una pizca de canela.
7. Sirve inmediatamente para mantener la granola crujiente.

Recomendación: Si vas a prepararlo con anticipación, conserva la granola separada y agrégala justo antes de servir.',
  NULL
WHERE NOT EXISTS (SELECT 1 FROM recetas WHERE nombre = 'Yogur con granola y frutas');

INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, al_gusto, opcional, orden)
SELECT r.id, c.id, v.nombre, v.cantidad,
       (SELECT id FROM unidades_medida WHERE nombre = v.unidad), v.costo, v.al_gusto, v.opcional, v.orden
FROM (SELECT 'Yogur con granola y frutas' AS receta) r0
JOIN recetas r ON r.nombre = r0.receta
JOIN (
  SELECT 'Yogurt natural (envase grande)' AS nombre, 6.00 AS cantidad, 'Taza' AS unidad, 36.00 AS costo, 0 AS al_gusto, 0 AS opcional, 10 AS orden
  UNION ALL SELECT 'Granola', 2.00, 'Taza', 44.18, 0, 0, 20
  UNION ALL SELECT 'Guineo', 2.00, 'Unidad', 3.80, 0, 0, 30
  UNION ALL SELECT 'Fresa', 1.00, 'Taza', 55.96, 0, 0, 40
  UNION ALL SELECT 'Mango', 1.00, 'Taza', 22.44, 0, 0, 50
  UNION ALL SELECT 'Miel de abeja', 6.00, 'Cucharada', 11.82, 0, 0, 60
  UNION ALL SELECT 'Nueces', 0.50, 'Taza', 96.03, 0, 1, 70
  UNION ALL SELECT 'Canela en polvo', 0.00, 'Cucharadita', 3.80, 1, 1, 80
) v ON 1=1
JOIN ingredientes_catalogo c ON c.nombre = v.nombre
WHERE NOT EXISTS (
  SELECT 1 FROM ingredientes i WHERE i.receta_id = r.id AND i.nombre = v.nombre
);

-- Acciones de preparación (rodajas / trozos / cubos / picado) para esta receta
INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id)
SELECT i.id, a.id
FROM ingredientes i
JOIN recetas r ON r.id = i.receta_id AND r.nombre = 'Yogur con granola y frutas'
JOIN (
  SELECT 'Guineo' AS ingrediente, 'Cortado en rodajas' AS accion
  UNION ALL SELECT 'Fresa', 'Cortado en trozos'
  UNION ALL SELECT 'Mango', 'Cortado en cubos'
  UNION ALL SELECT 'Nueces', 'Picado'
) v ON v.ingrediente = i.nombre
JOIN acciones_ingrediente a ON a.nombre = v.accion
WHERE NOT EXISTS (SELECT 1 FROM ingrediente_accion ia WHERE ia.receta_ingrediente_id = i.id AND ia.accion_id = a.id);

-- =====================================================================
-- Fin del script.
-- =====================================================================
