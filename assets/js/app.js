(function () {
  "use strict";

  /* Menú lateral en móvil */
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('overlay');
  var hamburger = document.getElementById('hamburgerBtn');
  if (hamburger && sidebar && overlay) {
    hamburger.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      overlay.classList.toggle('show');
    });
    overlay.addEventListener('click', function () {
      sidebar.classList.remove('open');
      overlay.classList.remove('show');
    });
  }

  /* Confirmación antes de acciones destructivas (eliminar). */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

  /* Vista previa en vivo de las cantidades de ingredientes al cambiar las
     porciones a preparar. El valor real solo se guarda al enviar el
     formulario (botón "Actualizar cantidades"); esto es solo para que el
     usuario vea el efecto antes de guardar. */
  function numFmt(valor) {
    var n = Math.round(valor * 100) / 100;
    return n.toLocaleString('es-DO', { maximumFractionDigits: 2 });
  }

  // Espejo de fraccionCantidad()/fraccionSufijo() en includes/helpers.php:
  // busca la fracción de cocina más cercana (medios, tercios, cuartos,
  // octavos) a la parte decimal, para mostrarla junto al número mientras se
  // previsualiza el cambio de porciones (antes de guardar).
  var FRACCIONES_COMUNES = [
    [1, 8, 0.125], [1, 4, 0.25], [1, 3, 1 / 3], [3, 8, 0.375],
    [1, 2, 0.5], [5, 8, 0.625], [2, 3, 2 / 3], [3, 4, 0.75], [7, 8, 0.875]
  ];
  var TOLERANCIA_FRACCION = 0.008;

  function fraccionSufijo(valor) {
    if (!(valor > 0)) return '';
    var entero = Math.floor(valor + 0.0001);
    var resto = valor - entero;
    for (var i = 0; i < FRACCIONES_COMUNES.length; i++) {
      var num = FRACCIONES_COMUNES[i][0], den = FRACCIONES_COMUNES[i][1], exacto = FRACCIONES_COMUNES[i][2];
      if (Math.abs(resto - exacto) <= TOLERANCIA_FRACCION) {
        var texto = num + '/' + den;
        return entero > 0 ? ' (' + entero + ' ' + texto + ')' : ' (' + texto + ')';
      }
    }
    return '';
  }

  // Igual que cantidadDeCompra()/montoLineaReceta() en includes/helpers.php:
  // si la unidad se compra completa (data-entera="1", ej. Unidad, Lata), una
  // cantidad fraccionaria redondea hacia arriba porque no se puede comprar
  // esa fracción (media manzana igual cuenta como una manzana comprada).
  function cantidadDeCompra(cantidad, esEntera) {
    if (!(cantidad > 0)) return 0;
    return esEntera ? Math.ceil(cantidad - 0.0000001) : cantidad;
  }

  document.addEventListener('input', function (e) {
    var input = e.target.closest('[data-role="porciones-input"]');
    if (!input) return;
    var card = input.closest('[data-recipe-card]');
    if (!card) return;
    var porcionesBase = parseFloat(card.getAttribute('data-porciones-base')) || 1;
    var nuevo = parseFloat(input.value);
    if (!nuevo || nuevo <= 0) return;
    var factor = nuevo / porcionesBase;
    var total = 0;
    card.querySelectorAll('[data-role="cant"]').forEach(function (celda) {
      // "Al gusto": no hay cantidad medible que escalar, así que se deja el
      // texto tal cual y no entra en el total (igual que costoTotalReceta()
      // en includes/helpers.php).
      if (celda.getAttribute('data-al-gusto') === '1') {
        return;
      }
      var base = parseFloat(celda.getAttribute('data-base')) || 0;
      var unidad = celda.getAttribute('data-unidad') || '';
      var esEntera = celda.getAttribute('data-entera') === '1';
      var cantidad = base * factor;
      celda.textContent = numFmt(cantidad) + ' ' + unidad + fraccionSufijo(cantidad);
      var costoCelda = celda.closest('tr').querySelector('[data-role="costo"]');
      if (costoCelda) {
        var costoUnit = parseFloat(costoCelda.getAttribute('data-costo')) || 0;
        var costo = cantidadDeCompra(cantidad, esEntera) * costoUnit;
        costoCelda.textContent = 'RD$ ' + Math.round(costo).toLocaleString('es-DO');
        total += costo;
      }
    });
    var totalCelda = card.querySelector('[data-role="costo-total"]');
    if (totalCelda) totalCelda.textContent = 'RD$ ' + Math.round(total).toLocaleString('es-DO');
  });

  /* Colapsar/expandir una tarjeta de receta (eventos y prácticas): oculta
     la tabla de ingredientes y la preparación, dejando solo el nombre, las
     porciones y el costo total a la vista — útil para tener un overview
     rápido cuando hay varias recetas asignadas. Un botón aparte por tarjeta
     y otro de "Colapsar todo / Expandir todo" para todas a la vez. */
  document.addEventListener('click', function (e) {
    var toggleUna = e.target.closest('[data-role="recipe-collapse-toggle"]');
    if (toggleUna) {
      var card = toggleUna.closest('[data-recipe-card]');
      if (card) card.classList.toggle('collapsed');
      return;
    }

    var toggleTodas = e.target.closest('[data-role="toggle-todas-recetas"]');
    if (toggleTodas) {
      var contenedorId = toggleTodas.getAttribute('data-contenedor');
      var contenedor = contenedorId ? document.querySelector('[data-role="' + contenedorId + '"]') : document;
      if (!contenedor) return;
      var tarjetas = contenedor.querySelectorAll('[data-recipe-card]');
      var hayAlgunaExpandida = Array.prototype.some.call(tarjetas, function (t) {
        return !t.classList.contains('collapsed');
      });
      tarjetas.forEach(function (t) {
        t.classList.toggle('collapsed', hayAlgunaExpandida);
      });
      toggleTodas.textContent = hayAlgunaExpandida ? 'Expandir todo' : 'Colapsar todo';
    }
  });

  /* Filtro por texto y categoría en el selector de "Agregar receta" (Eventos
     y Prácticas): la lista de recetas disponibles ya viene completa en la
     página, así que filtra en el navegador sin recargar — así una receta ya
     marcada no se pierde mientras se sigue buscando otras. */
  function normalizarTextoFiltro(s) {
    return (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }

  function aplicarFiltroRecetas(origen) {
    var wrap = origen.closest('[data-role="filtro-recetas-wrap"]');
    if (!wrap) return;
    var buscarInput = wrap.querySelector('[data-role="filtro-recetas-buscar"]');
    var catSelect = wrap.querySelector('[data-role="filtro-recetas-categoria"]');
    var texto = normalizarTextoFiltro(buscarInput ? buscarInput.value : '');
    var catId = catSelect ? catSelect.value : '';
    var filas = wrap.querySelectorAll('.check-row');
    var visibles = 0;
    filas.forEach(function (fila) {
      var etiqueta = normalizarTextoFiltro((fila.getAttribute('data-nombre') || '') + ' ' + (fila.getAttribute('data-categoria') || ''));
      var coincideTexto = !texto || etiqueta.indexOf(texto) !== -1;
      var coincideCat = !catId || fila.getAttribute('data-cat') === catId;
      var visible = coincideTexto && coincideCat;
      fila.style.display = visible ? '' : 'none';
      if (visible) visibles++;
    });
    var vacio = wrap.querySelector('[data-role="filtro-recetas-vacio"]');
    if (vacio) vacio.style.display = visibles === 0 ? '' : 'none';
  }

  document.addEventListener('input', function (e) {
    if (e.target.matches('[data-role="filtro-recetas-buscar"]')) {
      aplicarFiltroRecetas(e.target);
    }
  });
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-role="filtro-recetas-categoria"]')) {
      aplicarFiltroRecetas(e.target);
    }
  });
})();
