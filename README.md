# gastronomia

Sistema de gestión de eventos gastronómicos: presupuestos, cuotas,
estudiantes, recetas con ingredientes calculados por porción, control de
pagos y gastos por evento.

PHP + MySQL. Sin frameworks ni dependencias externas.

## Requisitos

- PHP 8.0 o superior con la extensión PDO MySQL.
- Una base de datos MySQL/MariaDB.

## Configuración inicial

1. Copia `config.sample.php` como `config.php` (este último no se sube a
   git — ver `.gitignore`) y completa los datos de tu base de datos.
2. Importa el esquema:
   ```
   mysql -u tu_usuario -p tu_base < db/schema.sql
   ```
3. (Opcional) Carga datos de ejemplo para probar la aplicación con algo
   de contenido:
   ```
   mysql -u tu_usuario -p tu_base < db/seed_demo.sql
   ```
4. Abre `index.php` en tu navegador (en XAMPP:
   `http://localhost/gastronomia/`).

## Desarrollo local contra la base de Hostinger

`config.php` detecta automáticamente si la app corre en `localhost` /
`127.0.0.1` (XAMPP) o en el servidor de producción, y ajusta el host de
MySQL en consecuencia. Para que la conexión remota funcione desde tu
computadora necesitas autorizar tu IP en hPanel → Bases de datos →
Acceso remoto a MySQL.

## Estructura del proyecto

```
config.php / config.sample.php   Conexión a la base de datos
db/schema.sql                    Esquema completo (tablas y relaciones)
db/seed_demo.sql                 Datos de ejemplo opcionales
includes/                        Conexión PDO, helpers, layout, iconos
assets/                          CSS y JS de la aplicación
index.php                        Panel general (dashboard)
eventos/                         CRUD de eventos + detalle con pestañas
                                  (resumen, estudiantes y pagos, recetas
                                  e ingredientes, gastos)
estudiantes/                     CRUD de estudiantes (lista maestra
                                  reutilizable entre eventos)
recetas/                         CRUD de recetas e ingredientes
```

## Módulos

- **Eventos**: nombre, fecha, lugar, presupuesto, cuota por estudiante,
  porciones a preparar y estado.
- **Estudiantes**: lista única reutilizable en cualquier evento.
- **Recetas**: catálogo con ingredientes base; al asignar una receta a
  un evento se indican las porciones a preparar y las cantidades de
  cada ingrediente se recalculan automáticamente
  (`cantidad_base × porciones_evento ÷ porciones_base`).
- **Pagos**: por cada estudiante asignado a un evento se registra si
  pagó su cuota y la fecha de pago.
- **Gastos**: gastos por categoría asociados a un evento, comparados
  contra el presupuesto.
