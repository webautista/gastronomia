<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas', 'ver', $base);
$puedeEditar = can($usuarioActual, 'practicas', 'editar');

// Permisos independientes por pestaña (igual que en Eventos, ver
// eventos/detalle.php) — reemplaza la regla vieja de "solo ver+editar
// practicas ve Recetas/Lista de Compra/Gastos, solo ver entra a
// Estudiantes y pagos nada más", que estaba escrita directo aquí. Ahora
// cada pestaña se muestra u oculta según su propio módulo de permiso.
$puedeVerEstudiantesTab = can($usuarioActual, 'practicas_estudiantes', 'ver');
$puedeCrearEstudianteTab = can($usuarioActual, 'practicas_estudiantes', 'crear');
$puedeEditarEstudianteTab = can($usuarioActual, 'practicas_estudiantes', 'editar');
$puedeEliminarEstudianteTab = can($usuarioActual, 'practicas_estudiantes', 'eliminar');
// Aplicar el fondo del estudiante es un permiso aparte del pago directo de
// arriba (practicas_fondo, no practicas_estudiantes) — ver practicas/aplicar_fondo.php.
$puedeVerFondoTab = can($usuarioActual, 'practicas_fondo', 'ver');
$puedeVerRecetasTab = can($usuarioActual, 'practicas_recetas', 'ver');
$puedeCrearRecetaTab = can($usuarioActual, 'practicas_recetas', 'crear');
$puedeEditarRecetaTab = can($usuarioActual, 'practicas_recetas', 'editar');
$puedeEliminarRecetaTab = can($usuarioActual, 'practicas_recetas', 'eliminar');
$puedeVerCompras = can($usuarioActual, 'practicas_lista_compra', 'ver');
$puedeEditarCompras = can($usuarioActual, 'practicas_lista_compra', 'editar');
// Columna "Recetas" dentro de Lista de Compra (qué receta usa cada
// ingrediente): permiso aparte porque revela el menú aunque el rol no vea
// la pestaña Recetas — ver nota en db/schema.sql junto a este módulo.
$puedeVerRecetasEnCompras = can($usuarioActual, 'practicas_lista_compra_recetas', 'ver');
$puedeVerGastos = can($usuarioActual, 'practicas_gastos', 'ver');
$puedeCrearGasto = can($usuarioActual, 'practicas_gastos', 'crear');
$puedeEditarGasto = can($usuarioActual, 'practicas_gastos', 'editar');
$puedeEliminarGasto = can($usuarioActual, 'practicas_gastos', 'eliminar');
// Fotos de los trabajos de los chicos en esta práctica — visibles también
// para Padres (solo "ver"); solo el staff con "crear"/"eliminar" sube o
// quita fotos.
$puedeVerFotosTab = can($usuarioActual, 'practicas_fotos', 'ver');
$puedeCrearFotoTab = can($usuarioActual, 'practicas_fotos', 'crear');
$puedeEliminarFotoTab = can($usuarioActual, 'practicas_fotos', 'eliminar');
// Cerrar/reabrir el cierre financiero (sección 42): permiso aparte del
// "editar" general de la práctica — ver nota junto a "practicas_cierre" en
// db/schema.sql.
$puedeGestionarCierre = can($usuarioActual, 'practicas_cierre', 'editar');

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

// A diferencia de Eventos, una Práctica no tiene una pestaña "Resumen"
// siempre visible — si el rol no tiene "ver" en ninguna de las cuatro, no
// hay ninguna pestaña que mostrar (ver el aviso más abajo, junto a "tabs").
$tabsValidos = [];
if ($puedeVerEstudiantesTab) {
    $tabsValidos[] = 'estudiantes';
}
if ($puedeVerRecetasTab) {
    $tabsValidos[] = 'recetas';
}
if ($puedeVerCompras) {
    $tabsValidos[] = 'compras';
}
if ($puedeVerGastos) {
    $tabsValidos[] = 'gastos';
}
if ($puedeVerFotosTab) {
    $tabsValidos[] = 'fotos';
}
$tabPorDefecto = $tabsValidos[0] ?? '';
$tab = in_array($_GET['tab'] ?? '', $tabsValidos, true) ? $_GET['tab'] : $tabPorDefecto;

$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$id]);
$practica = $stmt->fetch();
if (!$practica) {
    flash('Esa práctica ya no existe.', 'error');
    redirect('index.php');
}

/* ---------------- Acciones POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $pdo = db();

    if ($accion === 'quitar_receta') {
        requirePermission($usuarioActual, 'practicas_recetas', 'eliminar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        if ($recetaId) {
            $pdo->prepare('DELETE FROM practica_receta WHERE practica_id=? AND receta_id=?')->execute([$id, $recetaId]);
        }
    } elseif ($accion === 'asignar_recetas') {
        requirePermission($usuarioActual, 'practicas_recetas', 'crear', $base);
        $ids = array_map('intval', $_POST['receta_ids'] ?? []);
        // Por defecto, una práctica prepara la tanda completa: las porciones
        // de prueba de la receta (si las tiene) o, si no, sus porciones base.
        $stmtPorciones = $pdo->prepare('SELECT * FROM recetas WHERE id = ?');
        $stmt = $pdo->prepare('INSERT IGNORE INTO practica_receta (practica_id, receta_id, porciones_necesarias) VALUES (?,?,?)');
        foreach ($ids as $rid) {
            if ($rid > 0) {
                $stmtPorciones->execute([$rid]);
                $recetaAsignada = $stmtPorciones->fetch() ?: [];
                $porcionesDefecto = max(1, porcionesReferencia($recetaAsignada, 'practica'));
                $stmt->execute([$id, $rid, $porcionesDefecto]);
            }
        }
    } elseif ($accion === 'actualizar_porciones') {
        requirePermission($usuarioActual, 'practicas_recetas', 'editar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        $porciones = intOrNull($_POST['porciones_necesarias'] ?? null);
        if ($recetaId && $porciones && $porciones > 0) {
            $pdo->prepare('UPDATE practica_receta SET porciones_necesarias=? WHERE practica_id=? AND receta_id=?')
                ->execute([$porciones, $id, $recetaId]);
        }
    } elseif ($accion === 'quitar_estudiante') {
        requirePermission($usuarioActual, 'practicas_estudiantes', 'eliminar', $base);
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        if ($estudianteId) {
            $pdo->prepare('DELETE FROM practica_estudiante WHERE practica_id=? AND estudiante_id=?')->execute([$id, $estudianteId]);
            // El responsable de la compra siempre es alguien de los asignados.
            if (liberarResponsableCompraSiEs($pdo, 'practica', $id, $estudianteId)) {
                flash('Quitaste al responsable de la compra de esta práctica: asigna a otro estudiante.', 'error');
            }
        }
    } elseif ($accion === 'asignar_responsable_compra') {
        // Quién va a hacer la compra (sección 45): se cambia desde la barra
        // de arriba; mismo permiso que gestionar la Lista de Compra.
        requirePermission($usuarioActual, 'practicas_lista_compra', 'editar', $base);
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        if (asignarResponsableCompra($pdo, 'practica', $id, $estudianteId)) {
            flash($estudianteId ? 'Responsable de la compra actualizado.' : 'Se quitó el responsable de la compra.');
        } else {
            flash('No se pudo asignar: el estudiante debe estar asignado a esta práctica (y la base de datos debe estar actualizada con setup.php).', 'error');
        }
    } elseif ($accion === 'asignar_estudiantes') {
        requirePermission($usuarioActual, 'practicas_estudiantes', 'crear', $base);
        $ids = array_map('intval', $_POST['estudiante_ids'] ?? []);
        $stmt = $pdo->prepare('INSERT IGNORE INTO practica_estudiante (practica_id, estudiante_id) VALUES (?,?)');
        foreach ($ids as $eid) {
            if ($eid > 0) {
                $stmt->execute([$id, $eid]);
            }
        }
    } elseif ($accion === 'quitar_gasto') {
        requirePermission($usuarioActual, 'practicas_gastos', 'eliminar', $base);
        $gastoId = intOrNull($_POST['gasto_id'] ?? null);
        if ($gastoId) {
            $stmtG = $pdo->prepare("SELECT estado FROM gastos WHERE id = ? AND practica_id = ? AND eliminado_en IS NULL");
            $stmtG->execute([$gastoId, $id]);
            $estadoGasto = $stmtG->fetchColumn();
            if ($estadoGasto === 'pagado' && ($usuarioActual['rol_nombre'] ?? '') !== 'Administrador') {
                flash('Un gasto ya pagado solo puede eliminarlo un Administrador.', 'error');
            } elseif ($estadoGasto) {
                $pdo->prepare('UPDATE gastos SET eliminado_en = NOW() WHERE id = ?')->execute([$gastoId]);
                flash('Gasto eliminado.');
            }
        }
    } elseif ($accion === 'confirmar_gasto') {
        requirePermission($usuarioActual, 'practicas_gastos', 'editar', $base);
        $gastoId = intOrNull($_POST['gasto_id'] ?? null);
        $montoConfirmado = isset($_POST['monto_confirmado']) ? (float) $_POST['monto_confirmado'] : 0;
        if ($gastoId && $montoConfirmado > 0) {
            $pdo->prepare("UPDATE gastos SET estado = 'confirmado', monto_confirmado = ? WHERE id = ? AND practica_id = ? AND estado = 'proyectado'")
                ->execute([$montoConfirmado, $gastoId, $id]);
            flash('Gasto confirmado.');
        }
    } elseif ($accion === 'subir_foto') {
        requirePermission($usuarioActual, 'practicas_fotos', 'crear', $base);
        $descripcionFoto = trim((string) ($_POST['descripcion'] ?? ''));
        if (!isset($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('Elige una foto para subir.', 'error');
        } elseif ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
            flash('No se pudo subir la foto. Intenta de nuevo.', 'error');
        } else {
            $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = @mime_content_type($_FILES['foto']['tmp_name']);
            if (!isset($tiposPermitidos[$mime])) {
                flash('La foto debe ser una imagen JPG, PNG o WEBP.', 'error');
            } elseif ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
                flash('La foto no puede pesar más de 5 MB.', 'error');
            } else {
                // Los trabajos de los chicos son contenido que también ven
                // los padres, así que se guardan en el almacenamiento
                // compartido fuera del repositorio (enlace `public/` en la
                // raíz del proyecto -> ../shared/public), igual que las
                // fotos de recetas y los banners de eventos — para que
                // sobrevivan a los despliegues. Carpeta `public/practicas/fotos`
                // (subcarpeta de `public/practicas`, ya creada por Eyaelkys).
                $directorioDestino = __DIR__ . '/../public/practicas/fotos';
                if (!is_dir($directorioDestino)) {
                    mkdir($directorioDestino, 0775, true);
                }
                $nombreArchivo = 'practica_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $tiposPermitidos[$mime];
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $directorioDestino . '/' . $nombreArchivo)) {
                    $pdo->prepare('INSERT INTO practica_fotos (practica_id, ruta, descripcion) VALUES (?,?,?)')
                        ->execute([$id, 'public/practicas/fotos/' . $nombreArchivo, $descripcionFoto !== '' ? $descripcionFoto : null]);
                    flash('Foto agregada.');
                } else {
                    flash('No se pudo guardar la foto en el servidor. Vuelve a intentarlo.', 'error');
                }
            }
        }
    } elseif ($accion === 'eliminar_foto') {
        requirePermission($usuarioActual, 'practicas_fotos', 'eliminar', $base);
        $fotoId = intOrNull($_POST['foto_id'] ?? null);
        if ($fotoId) {
            $stmtFoto = $pdo->prepare('SELECT ruta FROM practica_fotos WHERE id = ? AND practica_id = ?');
            $stmtFoto->execute([$fotoId, $id]);
            $rutaFoto = $stmtFoto->fetchColumn();
            if ($rutaFoto) {
                $pdo->prepare('DELETE FROM practica_fotos WHERE id = ?')->execute([$fotoId]);
                @unlink(__DIR__ . '/../' . $rutaFoto);
                flash('Foto eliminada.');
            }
        }
    } elseif ($accion === 'guardar_decision_compra') {
        requirePermission($usuarioActual, 'practicas_lista_compra', 'editar', $base);
        $catalogoId = intOrNull($_POST['catalogo_id'] ?? null);
        $modo = in_array($_POST['modo'] ?? '', ['paquete', 'exacto', 'ya_tiene'], true) ? $_POST['modo'] : 'paquete';
        $comprarPaquete = $modo === 'paquete' ? 1 : 0;
        $precioPaquete = isset($_POST['precio_paquete']) && $_POST['precio_paquete'] !== '' ? (float) $_POST['precio_paquete'] : null;
        if ($catalogoId && $precioPaquete !== null && $precioPaquete >= 0) {
            $pdo->prepare(
                'INSERT INTO compra_decisiones (entidad_tipo, entidad_id, ingrediente_catalogo_id, comprar_paquete, modo, precio_paquete)
                 VALUES (\'practica\', ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE comprar_paquete = VALUES(comprar_paquete), modo = VALUES(modo), precio_paquete = VALUES(precio_paquete)'
            )->execute([$id, $catalogoId, $comprarPaquete, $modo, $precioPaquete]);
        }
    } elseif ($accion === 'cerrar_cierre') {
        requirePermission($usuarioActual, 'practicas_cierre', 'editar', $base);
        cerrarCierreFinanciero($pdo, 'practica', $id, (int) $usuarioActual['id'], $usuarioActual['nombre']);
        flash('Cierre financiero cerrado. Los costos quedaron congelados; los pagos de estudiantes siguen en vivo.');
    } elseif ($accion === 'reabrir_cierre') {
        requirePermission($usuarioActual, 'practicas_cierre', 'editar', $base);
        reabrirCierreFinanciero($pdo, 'practica', $id);
        flash('Cierre financiero reabierto: los costos vuelven a calcularse en vivo.');
    }

    redirect('detalle.php?id=' . $id . '&tab=' . $tab);
}

// Estado del cierre financiero (sección 42): si existe un snapshot, el
// cierre está "cerrado" y lo que se comparte en reportes/cierre.php quedó
// congelado — pero esta pantalla (Recetas, Lista de Compra, Gastos) sigue
// mostrando todo en vivo como siempre, para que se pueda seguir trabajando;
// solo se usa aquí para el botón Cerrar/Reabrir y el aviso de abajo.
$cierreGuardado = obtenerCierreFinancieroGuardado(db(), 'practica', $id);

/* ---------------- Datos para mostrar ---------------- */
$stmt = db()->prepare(
    'SELECT pr.porciones_necesarias, r.*, cr.nombre AS categoria FROM practica_receta pr
     JOIN recetas r ON r.id = pr.receta_id
     JOIN categorias_receta cr ON cr.id = r.categoria_id
     WHERE pr.practica_id = ? ORDER BY r.nombre ASC'
);
$stmt->execute([$id]);
$recetasPractica = $stmt->fetchAll();

$fotosPractica = [];
if ($puedeVerFotosTab) {
    $stmt = db()->prepare('SELECT * FROM practica_fotos WHERE practica_id = ? ORDER BY creado_en DESC');
    $stmt->execute([$id]);
    $fotosPractica = $stmt->fetchAll();
}

foreach ($recetasPractica as &$rc) {
    $porcionesBase = porcionesReferencia($rc, 'practica');
    $rc['entidad_tipo'] = 'practica';
    $stmtIng = db()->prepare(
        'SELECT i.*, um.abreviatura AS unidad, um.es_entera AS unidad_entera FROM ingredientes i
         JOIN unidades_medida um ON um.id = i.unidad_id
         WHERE i.receta_id = ? ORDER BY i.orden ASC, i.id ASC'
    );
    $stmtIng->execute([$rc['id']]);
    $rc['ingredientes'] = $stmtIng->fetchAll();
    $rc['costo_total'] = 0;
    foreach ($rc['ingredientes'] as $ing) {
        if (!empty($ing['al_gusto'])) {
            continue;
        }
        $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
        $esEntera = (bool) ($ing['unidad_entera'] ?? false);
        $rc['costo_total'] += montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
    }
}
unset($rc);

// El costo que alimenta la tarjeta "Inversión" y la cuota por estudiante
// NO es la simple suma de los chips de arriba (cada uno calculado receta
// por receta, sin verlas juntas): es el mismo total consolidado y
// consciente de las decisiones de compra que se muestra en la pestaña
// Lista de Compra — para que la tarjeta de Inversión NUNCA diga un número
// distinto al que dice esa pestaña, se calcula UNA sola vez aquí (antes se
// llamaba a costoRecetasConsolidado() por separado para la tarjeta y de
// nuevo a listaCompraConsolidada() para la pestaña "Lista de Compra"; dos
// llamadas separadas para "lo mismo" es justo la clase de cosa que, ante
// cualquier diferencia sutil entre ambas llamadas, puede terminar
// mostrando dos números distintos para lo que se supone es un solo dato —
// a pedido de Eyaelkys, que notó justo esa discrepancia).
$recetasParaLista = array_map(fn($rc) => ['receta_id' => $rc['id'], 'porciones_necesarias' => $rc['porciones_necesarias'], 'entidad_tipo' => 'practica'], $recetasPractica);
$decisionesCompra = cargarDecisionesCompra(db(), 'practica', $id);
$consolidado = listaCompraConsolidada(db(), $recetasParaLista, $decisionesCompra);
$costoMateriales = $consolidado['total'];

// Acciones/cortes marcados por línea (igual que en eventos/detalle.php).
$accionesPorFila = [];
$idsFilasTodas = [];
foreach ($recetasPractica as $rc) {
    foreach ($rc['ingredientes'] as $ing) {
        $idsFilasTodas[] = (int) $ing['id'];
    }
}
if ($idsFilasTodas) {
    $in = implode(',', array_fill(0, count($idsFilasTodas), '?'));
    $stmtAcc = db()->prepare(
        "SELECT ia.receta_ingrediente_id, ac.nombre FROM ingrediente_accion ia
         JOIN acciones_ingrediente ac ON ac.id = ia.accion_id
         WHERE ia.receta_ingrediente_id IN ($in) ORDER BY ac.orden ASC, ac.nombre ASC"
    );
    $stmtAcc->execute($idsFilasTodas);
    foreach ($stmtAcc->fetchAll() as $fa) {
        $accionesPorFila[(int) $fa['receta_ingrediente_id']][] = $fa['nombre'];
    }
}

// Estudiantes asignados a esta práctica + su cuota (mismo patrón que
// eventos/detalle.php, con practica_estudiante en vez de evento_estudiante).
$stmt = db()->prepare(
    'SELECT pe.monto_pagado, pe.fecha_pago, est.*, ge.nombre AS grupo FROM practica_estudiante pe
     JOIN estudiantes est ON est.id = pe.estudiante_id
     LEFT JOIN grupos_estudiante ge ON ge.id = est.grupo_id
     WHERE pe.practica_id = ? ORDER BY est.nombre ASC'
);
$stmt->execute([$id]);
$estudiantesPractica = $stmt->fetchAll();
$responsableCompra = obtenerResponsableCompra(db(), 'practica', $id);
$cantidadEstudiantes = count($estudiantesPractica);

// Gastos de la práctica, mismo balde material/otros × proyectado/usado que
// un evento (ver includes/helpers.php, resumenGastosVinculo()).
$resumenGastos = resumenGastosVinculo(db(), 'practica_id', $id);
$materialUsado = $resumenGastos['material_usado'];
$otrosUsado = $resumenGastos['otros_usado'];
$totalProyectado = $resumenGastos['material_proyectado'] + $resumenGastos['otros_proyectado'];

$gastosPractica = [];
if ($puedeVerGastos) {
    $stmt = db()->prepare(
        "SELECT g.*, cg.nombre AS categoria FROM gastos g
         JOIN categorias_gasto cg ON cg.id = g.categoria_id
         WHERE g.practica_id = ? AND g.eliminado_en IS NULL
         ORDER BY FIELD(g.estado,'proyectado','confirmado','pagado'), g.fecha DESC, g.id DESC"
    );
    $stmt->execute([$id]);
    $gastosPractica = $stmt->fetchAll();
}

// Ver el mismo comentario en eventos/detalle.php: "confirmada" puede venir
// de un ajuste manual (practicas.cuota_confirmada_manual) en vez del
// cálculo automático, cuando el taller decide cobrar menos de lo que costó
// de verdad — ver practicas/cuota_editar.php.
$cuotaManualPractica = $practica['cuota_confirmada_manual'] !== null ? (float) $practica['cuota_confirmada_manual'] : null;
$cuotas = calcularCuotas($costoMateriales, $resumenGastos, $cantidadEstudiantes, $cuotaManualPractica);
$cuotaProyectada = $cuotas['proyectada'];
$cuotaConfirmada = $cuotas['confirmada'];
$cuotaSugerida = $cuotas['confirmada_sugerida'];
$cuotaAjustada = $cuotas['cuota_ajustada'];
$totalConfirmadoConMateriales = $cuotas['meta_recaudo'];
$totalProyeccionInversion = $cuotas['total_proyeccion'];

$recaudado = array_sum(array_column($estudiantesPractica, 'monto_pagado'));
$numPagados = count(array_filter($estudiantesPractica, fn($a) => (float) $a['monto_pagado'] >= $cuotaConfirmada - 0.005));
$pctPago = $totalConfirmadoConMateriales > 0 ? round($recaudado / $totalConfirmadoConMateriales * 100) : 0;

$pageTitle = $practica['nombre'];
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <b>' . e($practica['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($practica['nombre']) ?></h1>
    <div class="event-meta" style="margin-top:6px;">
      <span><?= icon('calendar') ?> <?= fmtDate($practica['fecha']) ?></span>
      <?php if ($practica['materia']): ?><span><?= icon('book') ?> <?= e($practica['materia']) ?></span><?php endif; ?>
      <?php if ($practica['maestro_responsable']): ?><span><?= icon('users') ?> <?= e($practica['maestro_responsable']) ?></span><?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php if ($puedeEditar): ?>
      <a class="btn btn-secondary" href="form.php?id=<?= (int) $practica['id'] ?>"><?= icon('edit') ?> Editar</a>
    <?php endif; ?>
    <?php if ($puedeGestionarCierre): ?>
      <?php if ($cierreGuardado): ?>
        <form method="post" data-confirm="¿Actualizar el cierre financiero con los costos actuales? Esto reemplaza el snapshot ya compartido.">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="accion" value="cerrar_cierre">
          <button class="btn btn-secondary" type="submit"><?= icon('check') ?> Actualizar cierre</button>
        </form>
        <form method="post" data-confirm="¿Reabrir el cierre financiero? Los costos volverán a calcularse en vivo hasta que se cierre de nuevo.">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="accion" value="reabrir_cierre">
          <button class="btn btn-secondary" type="submit"><?= icon('unlock') ?> Reabrir cierre</button>
        </form>
      <?php else: ?>
        <form method="post" data-confirm="¿Cerrar el cierre financiero de esta práctica? Los costos (recetas, gastos, cuotas) quedarán congelados tal como están ahora mismo; los pagos de los estudiantes seguirán actualizándose con normalidad.">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="accion" value="cerrar_cierre">
          <button class="btn btn-secondary" type="submit"><?= icon('lock') ?> Cerrar cierre financiero</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($cierreGuardado): ?>
  <div class="alert" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-secondary);margin-bottom:18px;">
    <?= icon('lock') ?> Cierre financiero <b>cerrado</b> el <?= fmtDate($cierreGuardado['cerrado_en']) ?> por <b><?= e($cierreGuardado['cerrado_por_nombre']) ?></b>. Los costos que se comparten en el reporte de cierre quedaron congelados: si editas recetas, ingredientes o gastos aquí, esos cambios no se reflejarán en ese reporte hasta que uses "Actualizar cierre". Los pagos de los estudiantes siguen en vivo.
  </div>
<?php endif; ?>

<?php if (trim((string) ($practica['notas'] ?? '')) !== ''): ?>
  <div class="alert" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-secondary);margin-bottom:18px;"><?= nl2br(e($practica['notas'])) ?></div>
<?php endif; ?>

<?php if ($puedeVerEstudiantesTab || $puedeVerCompras): ?>
  <div class="card card-pad no-print" style="margin-bottom:18px;display:flex;flex-wrap:wrap;align-items:center;gap:14px;">
    <span class="section-icon is-gold"><?= icon('basket') ?></span>
    <div style="flex:1;min-width:200px;">
      <div class="stat-label" style="margin:0;">Responsable de la compra</div>
      <?php if ($responsableCompra): ?>
        <div style="font-weight:600;"><?= e($responsableCompra['nombre']) ?><?php if (!empty($responsableCompra['grupo'])): ?> <span class="cell-muted" style="font-weight:400;">· <?= e($responsableCompra['grupo']) ?></span><?php endif; ?></div>
      <?php else: ?>
        <div class="cell-muted">Sin asignar</div>
      <?php endif; ?>
    </div>
    <?php if ($puedeEditarCompras): ?>
      <?php if ($estudiantesPractica): ?>
        <form method="post" style="display:flex;gap:8px;align-items:center;">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="accion" value="asignar_responsable_compra">
          <label class="cell-muted" for="responsable_compra" style="font-size:.85rem;">Asignar a</label>
          <select id="responsable_compra" name="estudiante_id" onchange="this.form.submit()">
            <option value="">— Sin asignar —</option>
            <?php foreach ($estudiantesPractica as $a): ?>
              <option value="<?= (int) $a['id'] ?>"<?= $responsableCompra && (int) $responsableCompra['id'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php else: ?>
        <span class="cell-muted" style="font-size:.85rem;">Asigna estudiantes a la práctica para elegir al responsable.</span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="summary-grid">
  <div class="stat-tile stat-tile--wine">
    <div class="stat-label">Inversión de la práctica</div>
    <div class="stat-value"><?= money($totalProyeccionInversion) ?></div>
    <?php if ($puedeVerGastos): ?>
      <div class="stat-hint" style="margin-top:8px;">Proyectado en recetas: <b class="mono"><?= money($costoMateriales) ?></b> · Gastado en materiales: <b class="mono"><?= money($materialUsado) ?></b><?php if ($otrosUsado > 0 || $totalProyectado > 0): ?> · Otros gastos: <b class="mono"><?= money($otrosUsado) ?></b><?php endif; ?></div>
      <?php if ($cuotas['material_excedido']): ?>
        <div class="alert alert-error" style="margin-top:10px;padding:10px 12px;font-size:.85rem;">
          <?= icon('alertTriangle') ?> El gasto en materiales superó lo proyectado en recetas por <b><?= money($cuotas['material_exceso']) ?></b>.
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="stat-hint">Según las recetas y porciones asignadas a esta práctica.</div>
    <?php endif; ?>
  </div>
  <div class="stat-tile stat-tile--gold">
    <div class="stat-label" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
      <span>Cuota y recaudo</span>
      <?php if ($puedeEditarGasto): ?>
        <a href="cuota_editar.php?id=<?= $id ?>" title="Ajustar la cuota confirmada" style="color:inherit;opacity:.75;display:inline-flex;"><?= icon('edit') ?></a>
      <?php endif; ?>
    </div>
    <?php if ($cantidadEstudiantes > 0): ?>
      <div class="meter-row" style="margin-top:8px;"><span class="mono"><?= money($recaudado) ?> recaudado</span><span><?= (int) $pctPago ?>%</span></div>
      <div class="meter <?= meterClase($pctPago) ?>"><span style="width:<?= min($pctPago, 100) ?>%"></span></div>
      <?php if ($cuotaAjustada): ?>
        <div class="stat-hint" style="margin-top:8px;">Cuota confirmada: <b class="mono"><?= money($cuotaConfirmada) ?></b> <span class="chip chip-muted" style="font-size:.7rem;">ajustada</span> · Cuota sugerida: <b class="mono"><?= money($cuotaSugerida) ?></b> por estudiante</div>
        <?php if (!empty($practica['cuota_confirmada_manual_nota'])): ?>
          <div class="stat-hint" style="margin-top:2px;font-style:italic;">“<?= e($practica['cuota_confirmada_manual_nota']) ?>”</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="stat-hint" style="margin-top:8px;">Cuota confirmada: <b class="mono"><?= money($cuotaConfirmada) ?></b> · Cuota proyectada: <b class="mono"><?= money($cuotaProyectada) ?></b> por estudiante</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="stat-value" style="font-size:1.05rem;">—</div>
      <div class="stat-hint">Asigna estudiantes a la práctica para calcular la cuota.</div>
    <?php endif; ?>
  </div>
  <div class="stat-tile stat-tile--sage">
    <div class="stat-label">Recetas asignadas</div>
    <div class="stat-value"><?= count($recetasPractica) ?></div>
    <div class="stat-hint">Ver la pestaña "Lista de Compra" para el consolidado de ingredientes.</div>
  </div>
</div>

<div class="tabs">
  <?php if ($puedeVerEstudiantesTab): ?>
    <a class="tab <?= $tab === 'estudiantes' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Estudiantes y pagos</a>
  <?php endif; ?>
  <?php if ($puedeVerRecetasTab): ?>
    <a class="tab <?= $tab === 'recetas' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=recetas">Recetas</a>
  <?php endif; ?>
  <?php if ($puedeVerCompras): ?>
    <a class="tab <?= $tab === 'compras' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=compras"><?= icon('clipboardList') ?> Lista de Compra</a>
  <?php endif; ?>
  <?php if ($puedeVerGastos): ?>
    <a class="tab <?= $tab === 'gastos' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=gastos">Gastos</a>
  <?php endif; ?>
  <?php if ($puedeVerFotosTab): ?>
    <a class="tab <?= $tab === 'fotos' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=fotos"><?= icon('camera') ?> Fotos</a>
  <?php endif; ?>
</div>

<?php if (!$tabsValidos): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin acceso</div>
    <div>Tu rol no tiene permiso para ver ninguna sección de esta práctica.</div>
  </div></div>
<?php endif; ?>

<?php if ($tab === 'recetas'): ?>
  <div class="toolbar no-print">
    <div class="cell-muted">Las cantidades se recalculan según las porciones que necesitas preparar.</div>
    <div class="row-actions">
      <?php if (count($recetasPractica) > 1): ?>
        <button class="btn btn-secondary btn-sm" type="button" data-role="toggle-todas-recetas" data-contenedor="lista-recetas-practica">Colapsar todo</button>
      <?php endif; ?>
      <?php if ($puedeCrearRecetaTab): ?>
        <a class="btn btn-secondary btn-sm" href="asignar_receta.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar receta</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$recetasPractica): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin recetas</div>
      <div>Agrega recetas del catálogo para calcular los ingredientes.</div>
    </div></div>
  <?php endif; ?>
  <?php $variantesCabecera = ['', 'is-practica', 'is-sage', 'is-wine-sage']; ?>
  <div data-role="lista-recetas-practica">
  <?php foreach ($recetasPractica as $rc):
    $porcionesBase = porcionesReferencia($rc, 'practica');
    $ingredientesReceta = $rc['ingredientes'];
    $costoTotal = $rc['costo_total'];
    $varianteCabecera = $variantesCabecera[(int) $rc['categoria_id'] % 4];
  ?>
    <div class="recipe-card" data-recipe-card data-porciones-base="<?= $porcionesBase ?>">
      <div class="recipe-card-head <?= $varianteCabecera ?>">
        <div class="dash-head-top">
          <div class="dash-head-id">
            <div class="dash-icon"><?= icon('whisk') ?></div>
            <div>
              <div class="dash-title"><?= e($rc['nombre']) ?></div>
              <div class="dash-meta">
                <span><?= e($rc['categoria']) ?></span>
                <span><?= e(etiquetaPorcionesReferencia($rc, 'practica')) ?><?= porcionesPruebaReceta($rc) > 0 ? ' · rinde ' . (int) $rc['porciones_base'] . ' ' . ((int) $rc['porciones_base'] === 1 ? 'plato' : 'platos') : '' ?></span>
              </div>
            </div>
          </div>
          <div class="dash-chips">
            <span class="chip chip-muted" data-role="costo-total-badge"><?= money($costoTotal) ?></span>
          </div>
        </div>
      </div>
      <div class="recipe-card-toolbar no-print">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
          <button class="icon-btn" type="button" data-role="recipe-collapse-toggle" title="Colapsar/expandir"><?= icon('chevronDown') ?></button>
          <?php if ($puedeEditarRecetaTab): ?>
            <form method="post" style="display:flex;align-items:center;gap:14px;">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="accion" value="actualizar_porciones">
              <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
              <div class="portion-control">
                <span>Porciones a preparar</span>
                <input type="number" min="1" name="porciones_necesarias" value="<?= (int) $rc['porciones_necesarias'] ?>" data-role="porciones-input">
              </div>
              <button class="btn btn-secondary btn-sm" type="submit">Actualizar</button>
            </form>
          <?php else: ?>
            <div class="stat-hint"><?= (int) $rc['porciones_necesarias'] ?> porciones a preparar</div>
          <?php endif; ?>
        </div>
        <?php if ($puedeEliminarRecetaTab): ?>
          <form method="post" data-confirm="¿Quitar la receta &quot;<?= e($rc['nombre']) ?>&quot; de esta práctica?">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="quitar_receta">
            <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
            <button class="icon-btn" type="submit" title="Quitar receta"><?= icon('trash') ?></button>
          </form>
        <?php endif; ?>
      </div>
      <div class="recipe-card-body">
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Ingrediente</th><th>Cantidad base</th><th>Cantidad necesaria</th><th>Costo est.</th></tr></thead>
        <tbody>
          <?php foreach ($ingredientesReceta as $ing):
            $esAlGusto = !empty($ing['al_gusto']);
            $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
            $esEntera = (bool) ($ing['unidad_entera'] ?? false);
            $costo = montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
          ?>
            <tr>
              <td class="cell-name">
                <?= e($ing['nombre']) ?>
                <?php if (!empty($ing['opcional'])): ?> <span class="chip chip-muted" style="font-size:.68rem;">Opcional</span><?php endif; ?>
                <?php if (!empty($ing['reemplazo'])): ?><div class="cell-muted" style="font-size:.78rem;">o <?= e($ing['reemplazo']) ?></div><?php endif; ?>
                <?php if (!empty($accionesPorFila[$ing['id']])): ?><div class="cell-muted" style="font-size:.78rem;"><?= e(implode(', ', $accionesPorFila[$ing['id']])) ?></div><?php endif; ?>
              </td>
              <td class="cell-muted mono"><?= $esAlGusto ? 'Al gusto' : numFmt($ing['cantidad']) . ' ' . e($ing['unidad']) . fraccionSufijo((float) $ing['cantidad']) ?></td>
              <td class="mono" data-role="cant" data-base="<?= e((string) $ing['cantidad']) ?>" data-unidad="<?= e($ing['unidad']) ?>" data-entera="<?= $esEntera ? '1' : '0' ?>" data-al-gusto="<?= $esAlGusto ? '1' : '0' ?>"><?= $esAlGusto ? 'Al gusto' : numFmt($cantidad) . ' ' . e($ing['unidad']) . fraccionSufijo($cantidad) ?></td>
              <td class="mono" data-role="costo" data-costo="<?= e((string) $ing['costo_unitario']) ?>"><?= $esAlGusto ? '—' : money($costo) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="3" style="text-align:right;font-weight:600;">Costo estimado de ingredientes</td><td class="mono" style="font-weight:600;" data-role="costo-total"><?= money($costoTotal) ?></td></tr>
        </tfoot>
      </table>
      </div>
      <?php if (trim((string) ($rc['preparacion'] ?? '')) !== ''): ?>
        <details class="prep-details">
          <summary><?= icon('book') ?> Ver preparación</summary>
          <div class="prep-text"><?= e($rc['preparacion']) ?></div>
        </details>
      <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

<?php elseif ($tab === 'compras'): ?>
  <div class="toolbar no-print">
    <div class="cell-muted">Ingredientes de todas las recetas de esta práctica, sumados y organizados para ir al súper.</div>
    <div class="row-actions">
      <button class="btn btn-secondary btn-sm" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir</button>
      <a class="btn btn-secondary btn-sm" href="lista_compra_txt.php?id=<?= $id ?>"><?= icon('download') ?> Descargar (.txt)</a>
    </div>
  </div>
  <div class="stat-tile stat-tile--gold" style="margin-bottom:16px;">
    <div class="stat-label"><?= icon('basket') ?> Costo estimado de la lista de compra</div>
    <div class="stat-value"><?= money($consolidado['total'] ?? 0) ?></div>
    <div class="stat-hint"><?= count($consolidado['lineas']) ?> ingrediente<?= count($consolidado['lineas']) === 1 ? '' : 's' ?> a comprar<?= $consolidado['al_gusto'] ? ' · ' . count($consolidado['al_gusto']) . ' al gusto' : '' ?></div>
    <div class="stat-hint" style="margin-top:4px;">Responsable de la compra: <b><?= $responsableCompra ? e($responsableCompra['nombre']) : 'sin asignar' ?></b></div>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Ingrediente</th><th>Cantidad</th><?php if ($puedeVerRecetasEnCompras): ?><th>Recetas</th><?php endif; ?><th>Costo est.</th></tr></thead>
      <tbody>
        <?php if (!$consolidado['lineas'] && !$consolidado['al_gusto']): ?>
          <tr><td colspan="<?= $puedeVerRecetasEnCompras ? 4 : 3 ?>" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay recetas asignadas a esta práctica.</td></tr>
        <?php endif; ?>
        <?php foreach ($consolidado['lineas'] as $l): ?>
          <tr>
            <td class="cell-name"><?= e($l['nombre']) ?></td>
            <td class="mono">
              <?= numFmt($l['cantidad']) ?> <?= e($l['unidad']) ?>
              <?php if (!empty($l['compra'])): $dc = $l['compra_decision']; $modo = $dc['modo'] ?? 'paquete'; ?>
                <div class="cell-muted" style="font-size:.78rem;font-weight:400;margin-top:4px;">
                  <?php $cantCompleta = $l['compra']['cantidad_completa'] ?? $l['compra']['cantidad']; ?>
                  <?php if ($modo === 'paquete'): ?>
                    comprar ≈ <?= numFmt($cantCompleta) ?> <?= e($l['compra']['unidad']) ?>
                  <?php elseif ($modo === 'exacto'): ?>
                    <span style="text-decoration:line-through;">comprar ≈ <?= numFmt($cantCompleta) ?> <?= e($l['compra']['unidad']) ?></span> · <span class="chip chip-warning" style="font-size:.65rem;">Solo lo necesario</span>
                  <?php else: ?>
                    <span style="text-decoration:line-through;">comprar ≈ <?= numFmt($cantCompleta) ?> <?= e($l['compra']['unidad']) ?></span> · <span class="chip chip-muted" style="font-size:.65rem;">Ya lo tienes</span>
                  <?php endif; ?>
                </div>
                <?php if ($puedeEditarCompras): ?>
                <form method="post" class="no-print" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-weight:400;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="guardar_decision_compra">
                  <input type="hidden" name="catalogo_id" value="<?= (int) $l['catalogo_id'] ?>">
                  <select name="modo" style="font-size:.72rem;">
                    <option value="paquete" <?= $modo === 'paquete' ? 'selected' : '' ?>>Comprar paquete completo</option>
                    <option value="exacto" <?= $modo === 'exacto' ? 'selected' : '' ?>>Comprar solo lo necesario</option>
                    <option value="ya_tiene" <?= $modo === 'ya_tiene' ? 'selected' : '' ?>>Ya lo tienes</option>
                  </select>
                  <span class="cell-muted" style="font-size:.72rem;">RD$</span>
                  <input type="number" name="precio_paquete" min="0" step="0.01" value="<?= e((string) $dc['precio_paquete']) ?>" style="width:74px;font-size:.78rem;" title="Precio del paquete (editable)">
                  <button class="btn btn-secondary btn-sm" type="submit" style="font-size:.72rem;padding:2px 8px;">Guardar</button>
                </form>
                <?php endif; ?>
              <?php elseif (!empty($l['cantidad_entera_a_comprar'])): ?>
                <div class="cell-muted" style="font-size:.78rem;font-weight:400;margin-top:4px;">
                  comprar <?= numFmt($l['cantidad_entera_a_comprar']) ?> <?= e($l['unidad']) ?>
                </div>
              <?php endif; ?>
            </td>
            <?php if ($puedeVerRecetasEnCompras): ?><td class="cell-muted" style="font-size:.82rem;"><?= e(implode(', ', $l['recetas'])) ?></td><?php endif; ?>
            <td class="mono">
              <?= money($l['monto']) ?>
              <?php if (!empty($l['compra_decision'])): $modoMonto = $l['compra_decision']['modo'] ?? 'paquete'; ?>
                <?php if ($modoMonto === 'ya_tiene'): ?>
                  <div style="margin-top:4px;"><span class="chip chip-muted" style="font-size:.65rem;">Ya lo tienes</span></div>
                <?php elseif ($modoMonto === 'exacto'): ?>
                  <div style="margin-top:4px;"><span class="chip chip-warning" style="font-size:.65rem;">Costo exacto</span></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($consolidado['al_gusto'] as $ag): ?>
          <tr>
            <td class="cell-name"><?= e($ag['nombre']) ?> <span class="chip chip-muted" style="font-size:.68rem;">Al gusto</span></td>
            <td class="cell-muted mono">—</td>
            <?php if ($puedeVerRecetasEnCompras): ?><td class="cell-muted" style="font-size:.82rem;"><?= e(implode(', ', $ag['recetas'])) ?></td><?php endif; ?>
            <td class="cell-muted mono">—</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($consolidado['lineas']): ?>
      <tfoot>
        <tr><td colspan="<?= $puedeVerRecetasEnCompras ? 3 : 2 ?>" style="text-align:right;font-weight:600;">Costo estimado total</td><td class="mono" style="font-weight:600;"><?= money($consolidado['total']) ?></td></tr>
      </tfoot>
      <?php endif; ?>
    </table>
    </div>
  </div>

<?php elseif ($tab === 'estudiantes'): ?>
  <div class="toolbar">
    <div class="cell-muted">
      <?= $numPagados ?> de <?= count($estudiantesPractica) ?> estudiantes al día · <span class="mono"><?= money($recaudado) ?></span> de <span class="mono"><?= money($totalConfirmadoConMateriales) ?></span> recaudado
      <?php if ($cantidadEstudiantes > 0): ?>
        · cuota confirmada: <span class="mono"><?= money($cuotaConfirmada) ?></span> c/u
      <?php endif; ?>
    </div>
    <?php if ($puedeCrearEstudianteTab): ?>
      <a class="btn btn-secondary btn-sm" href="asignar_estudiante.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar estudiante</a>
    <?php endif; ?>
  </div>
  <?php if (!$estudiantesPractica): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin estudiantes</div>
      <div>Aún no hay estudiantes asignados a esta práctica.</div>
    </div></div>
  <?php else: ?>
  <div class="event-grid">
    <?php foreach ($estudiantesPractica as $a):
      $montoPagado = (float) $a['monto_pagado'];
      $pendienteEstudiante = max(0, $cuotaConfirmada - $montoPagado);
      $alDia = $pendienteEstudiante <= 0.005;
      $pctPagoEst = $cuotaConfirmada > 0 ? round(min($montoPagado, $cuotaConfirmada) / $cuotaConfirmada * 100) : ($montoPagado > 0 ? 100 : 0);
    ?>
      <div class="dash-card">
        <div class="dash-card-head <?= $alDia ? 'is-sage' : '' ?>">
          <div class="dash-head-top">
            <div class="dash-head-id">
              <div class="child-avatar" style="width:40px;height:40px;font-size:.95rem;"><?= e(iniciales($a['nombre'])) ?></div>
              <div>
                <div class="dash-title"><?= e($a['nombre']) ?></div>
                <div class="dash-meta">
                  <span><?= icon('users') ?> <?= e($a['grupo'] ?? 'Sin grupo asignado') ?></span>
                </div>
              </div>
            </div>
            <div class="dash-chips">
              <?php if ($responsableCompra && (int) $responsableCompra['id'] === (int) $a['id']): ?>
                <span class="chip chip-muted" title="Responsable de hacer la compra"><?= icon('basket') ?> Compra</span>
              <?php endif; ?>
              <?php if ($alDia): ?>
                <span class="chip chip-muted"><?= icon('check') ?> Al día</span>
              <?php else: ?>
                <span class="chip chip-muted"><?= money($pendienteEstudiante) ?> pendiente</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="dash-body">
          <div class="person-meta">
            <span><?= icon('phone') ?> <?= $a['telefono'] ? e($a['telefono']) : 'Sin teléfono' ?></span>
          </div>
          <div>
            <div class="meter-row"><span>Pagado</span><span class="mono"><?= money($montoPagado) ?> / <?= money($cuotaConfirmada) ?></span></div>
            <div class="meter <?= meterClase($pctPagoEst) ?>"><span style="width:<?= min($pctPagoEst, 100) ?>%"></span></div>
            <?php if ($montoPagado > 0 && $a['fecha_pago']): ?>
              <div class="stat-hint" style="margin-top:6px;">Último pago: <?= fmtDate($a['fecha_pago']) ?></div>
            <?php endif; ?>
          </div>
          <div class="dash-footer">
            <?php if ($puedeVerEstudiantesTab): ?>
              <a class="btn btn-secondary btn-sm" href="pago_estudiante.php?id=<?= $id ?>&estudiante_id=<?= (int) $a['id'] ?>"><?= icon('receipt') ?> <?= $puedeEditarEstudianteTab ? 'Pagos' : 'Ver pagos' ?></a>
            <?php endif; ?>
            <?php if ($puedeVerFondoTab): ?>
              <a class="btn btn-secondary btn-sm" href="aplicar_fondo.php?id=<?= $id ?>&estudiante_id=<?= (int) $a['id'] ?>"><?= icon('wallet') ?> Fondo</a>
            <?php endif; ?>
            <div class="row-actions">
              <?php if ($puedeEliminarEstudianteTab): ?>
                <form method="post" data-confirm="¿Quitar a &quot;<?= e($a['nombre']) ?>&quot; de esta práctica?">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="quitar_estudiante">
                  <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                  <button class="icon-btn" type="submit" title="Quitar de la práctica"><?= icon('x') ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'gastos'): $esAdmin = ($usuarioActual['rol_nombre'] ?? '') === 'Administrador'; ?>
  <div class="card card-pad" style="margin-bottom:16px;">
    <div class="stat-hint">
      Proyectado en recetas: <b class="mono"><?= money($costoMateriales) ?></b>
      &nbsp;·&nbsp; Gastado en materiales: <b class="mono"><?= money($materialUsado) ?></b>
      &nbsp;·&nbsp; Otros gastos: <b class="mono"><?= money($otrosUsado) ?></b> (+ <?= money($totalProyectado) ?> proyectado sin confirmar)
      &nbsp;=&nbsp; Necesitarían en total ≈ <b class="mono"><?= money($totalProyeccionInversion) ?></b>
    </div>
    <?php if ($cuotas['material_excedido']): ?>
      <div class="alert alert-error" style="margin-top:10px;padding:10px 12px;font-size:.85rem;">
        <?= icon('alertTriangle') ?> El gasto en materiales superó lo proyectado en recetas por <b><?= money($cuotas['material_exceso']) ?></b>. Puede que haga falta ajustar la cuota o buscar más fondos.
      </div>
    <?php endif; ?>
  </div>
  <div class="toolbar">
    <div class="cell-muted"><?= count($gastosPractica) ?> partida<?= count($gastosPractica) === 1 ? '' : 's' ?> registrada<?= count($gastosPractica) === 1 ? '' : 's' ?></div>
    <?php if ($puedeCrearGasto): ?>
      <a class="btn btn-secondary btn-sm" href="gasto_form.php?practica_id=<?= $id ?>"><?= icon('plus') ?> Agregar partida</a>
    <?php endif; ?>
  </div>
  <?php if (!$gastosPractica): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin gastos</div>
      <div>Aún no hay gastos ni partidas proyectadas.</div>
    </div></div>
  <?php else: ?>
  <div class="event-grid">
    <?php foreach ($gastosPractica as $g):
      $varianteGasto = $g['estado'] === 'pagado' ? 'is-sage' : ($g['estado'] === 'confirmado' ? 'is-wine-sage' : '');
      $estadoLabel = $g['estado'] === 'proyectado' ? 'Proyectado' : ($g['estado'] === 'confirmado' ? 'Confirmado' : 'Pagado');
    ?>
      <div class="dash-card">
        <div class="dash-card-head <?= $varianteGasto ?>">
          <div class="dash-head-top">
            <div class="dash-head-id">
              <div class="dash-icon"><?= icon('receipt') ?></div>
              <div>
                <div class="dash-title"><?= e($g['descripcion']) ?></div>
                <div class="dash-meta">
                  <span><?= icon('calendar') ?> <?= fmtDate($g['fecha']) ?></span>
                  <span><?= e($g['categoria']) ?></span>
                </div>
              </div>
            </div>
            <div class="dash-chips">
              <span class="chip chip-muted"><?= $estadoLabel ?></span>
            </div>
          </div>
        </div>
        <div class="dash-body">
          <div class="dash-stats">
            <div class="dash-stat is-gold">
              <div class="dash-stat-label">Monto</div>
              <div class="dash-stat-value"><?= money(montoEfectivoGasto($g)) ?></div>
            </div>
            <?php if ($g['proveedor']): ?>
            <div class="dash-stat">
              <div class="dash-stat-label">Proveedor</div>
              <div class="dash-stat-value" style="font-size:.8rem;"><?= e($g['proveedor']) ?></div>
            </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($g['es_material_receta']) || ($g['estado'] === 'pagado' && $g['fecha_pago']) || ($g['estado'] === 'pagado' && !empty($g['factura']))): ?>
          <div class="cell-muted" style="font-size:.78rem;display:flex;flex-direction:column;gap:4px;">
            <?php if (!empty($g['es_material_receta'])): ?><span>Material de receta</span><?php endif; ?>
            <?php if ($g['estado'] === 'pagado' && $g['fecha_pago']): ?><span>Pagado: <?= fmtDate($g['fecha_pago']) ?></span><?php endif; ?>
            <?php if ($g['estado'] === 'pagado' && !empty($g['factura'])): ?>
              <a href="<?= e($base . '/' . $g['factura']) ?>" target="_blank" rel="noopener"><?= icon('receipt') ?> Ver factura</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="dash-footer">
            <?php if ($g['estado'] === 'proyectado' && $puedeEditarGasto): ?>
            <form method="post" data-confirm="¿Confirmar esta partida por el monto indicado?" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="accion" value="confirmar_gasto">
              <input type="hidden" name="gasto_id" value="<?= (int) $g['id'] ?>">
              <input type="number" name="monto_confirmado" min="0.01" step="0.01" value="<?= e((string) $g['monto']) ?>" style="width:90px;" title="Monto confirmado">
              <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Confirmar</button>
            </form>
            <?php endif; ?>
            <?php if ($g['estado'] === 'confirmado' && $puedeEditarGasto): ?>
              <a class="btn btn-primary btn-sm" href="gasto_pagar.php?id=<?= (int) $g['id'] ?>"><?= icon('receipt') ?> Marcar pagado</a>
            <?php endif; ?>
            <?php if ($g['estado'] === 'pagado' && $puedeEditarGasto): ?>
              <a class="btn btn-secondary btn-sm" href="gasto_editar.php?id=<?= (int) $g['id'] ?>"><?= icon('edit') ?> Editar</a>
            <?php endif; ?>
            <div class="row-actions">
              <?php if ($puedeEliminarGasto && ($g['estado'] !== 'pagado' || $esAdmin)): ?>
              <form method="post" data-confirm="<?= $g['estado'] === 'pagado' ? '¿Eliminar este gasto ya pagado? Es una acción de auditoría, solo un Administrador puede hacerla.' : '¿Eliminar esta partida?' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="quitar_gasto">
                <input type="hidden" name="gasto_id" value="<?= (int) $g['id'] ?>">
                <button class="icon-btn" type="submit"><?= icon('trash') ?></button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php elseif ($tab === 'fotos'): ?>
  <?php if ($puedeCrearFotoTab): ?>
  <div class="card card-pad no-print" style="margin-bottom:20px;">
    <div class="section-title-row"><span class="section-icon is-gold"><?= icon('camera') ?></span><h2 class="section-title">Agregar foto</h2></div>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="accion" value="subir_foto">
      <div class="field">
        <label>Foto (JPG, PNG o WEBP, máx. 5 MB)</label>
        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" required>
      </div>
      <div class="field">
        <label>Descripción (opcional)</label>
        <input type="text" name="descripcion" maxlength="255" placeholder="Ej. Pastel de zanahoria de Juan">
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Agregar foto</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!$fotosPractica): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin fotos todavía</div>
      <div>Las fotos de los trabajos de esta práctica aparecerán aquí.</div>
    </div></div>
  <?php else: ?>
  <div class="fotos-practica-grid">
    <?php foreach ($fotosPractica as $foto): ?>
      <div class="fotos-practica-card">
        <a href="<?= e($base . '/' . $foto['ruta']) ?>" target="_blank" rel="noopener">
          <img src="<?= e($base . '/' . $foto['ruta']) ?>" alt="<?= e($foto['descripcion'] ?: 'Foto del trabajo de un estudiante') ?>" loading="lazy">
        </a>
        <?php if ($puedeEliminarFotoTab): ?>
          <form method="post" class="fotos-practica-delete no-print" data-confirm="¿Eliminar esta foto?">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="eliminar_foto">
            <input type="hidden" name="foto_id" value="<?= (int) $foto['id'] ?>">
            <button class="icon-btn" type="submit" title="Eliminar foto"><?= icon('trash') ?></button>
          </form>
        <?php endif; ?>
        <?php if (!empty($foto['descripcion'])): ?>
          <div class="fotos-practica-caption"><?= e($foto['descripcion']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
