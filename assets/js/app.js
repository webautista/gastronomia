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
      celda.textContent = numFmt(cantidad) + ' ' + unidad;
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
})();
