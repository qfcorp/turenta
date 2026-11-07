/* Módulos en el Panel Tukán: al encender un módulo se encienden sus dependencias; al apagar uno, los que dependen de él. */
(function () {
  'use strict';
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el.matches || !el.matches('input.interruptor[data-id]')) return;
    var form = el.form;
    if (!form) return;
    var todos = Array.prototype.slice.call(form.querySelectorAll('input.interruptor[data-id]'));
    var porId = {};
    todos.forEach(function (x) { porId[x.dataset.id] = x; });
    if (el.checked) {
      var pend = (el.dataset.depende || '').split(',').filter(Boolean);
      while (pend.length) {
        var d = porId[pend.shift()];
        if (d && !d.checked) { d.checked = true; pend = pend.concat((d.dataset.depende || '').split(',').filter(Boolean)); }
      }
    } else {
      var apagar = [el.dataset.id];
      while (apagar.length) {
        var id = apagar.shift();
        todos.forEach(function (x) {
          if (x.checked && (x.dataset.depende || '').split(',').indexOf(id) >= 0) { x.checked = false; apagar.push(x.dataset.id); }
        });
      }
    }
    todos.forEach(function (x) { var l = x.closest('label'); if (l) l.classList.toggle('on', x.checked); });
  });
})();
