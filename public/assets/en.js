// TuRenta — páginas del cliente en inglés (reserva en línea y portal). Sin librerías.
(function () {
  'use strict';

  var form = document.querySelector('[data-reserva]');
  if (form) iniciarReserva(form);

  // Cuenta regresiva del portal: data-cuenta="2026-12-13T15:00:00"
  var cuenta = document.querySelector('[data-cuenta]');
  if (cuenta) {
    var meta = new Date(cuenta.getAttribute('data-cuenta'));
    var pon = function (sel, v) { var el = cuenta.querySelector(sel); if (el) el.textContent = v < 10 ? '0' + v : String(v); };
    var tic = function () {
      var s = Math.max(0, Math.floor((meta - new Date()) / 1000));
      pon('[data-d]', Math.floor(s / 86400)); pon('[data-h]', Math.floor(s % 86400 / 3600));
      pon('[data-m]', Math.floor(s % 3600 / 60)); pon('[data-s]', s % 60);
    };
    tic(); setInterval(tic, 1000);
  }

  function iniciarReserva(form) {
    var fecha = form.querySelector('#fecha');
    var lista = form.querySelector('[data-horas-lista]');
    var hora = form.querySelector('[data-hora]');
    var urlHoras = form.getAttribute('data-horas');
    var ninos = form.querySelector('#ninos');
    var dinero = function (v) { return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var paquete = function () { return form.querySelector('input[name="paquete"]:checked'); };
    var pedido = 0;

    function texto(msg) {
      lista.innerHTML = '';
      var p = document.createElement('p');
      p.className = 'en-hint';
      p.textContent = msg;
      lista.appendChild(p);
    }

    function resumen() {
      var p = paquete();
      var nom = form.querySelector('[data-res-nombre]');
      var cuando = form.querySelector('[data-res-cuando]');
      var total = form.querySelector('[data-res-total]');
      var dep = form.querySelector('[data-res-deposito]');
      if (!p) return;
      var precio = parseFloat(p.getAttribute('data-precio')) || 0;
      var pct = parseFloat(dep.getAttribute('data-pct')) || 0;
      nom.textContent = p.getAttribute('data-nombre');
      total.textContent = dinero(precio);
      dep.textContent = dinero(Math.round(precio * pct) / 100);
      var elegida = form.querySelector('input[name="hora_elegida"]:checked');
      if (fecha.value && elegida) {
        var d = new Date(fecha.value + 'T12:00:00');
        cuando.textContent = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) + ' · ' + elegida.getAttribute('data-texto');
      } else {
        cuando.textContent = 'Pick a date and time';
      }
      var max = parseInt(p.getAttribute('data-max'), 10) || 0;
      if (ninos) { if (max > 0) ninos.max = String(max); else ninos.removeAttribute('max'); }
    }

    function cargarHoras() {
      var p = paquete();
      if (!p || !fecha.value) { texto('Pick a date to see open times.'); resumen(); return; }
      var id = ++pedido;
      texto('Checking open times…');
      fetch(urlHoras + '&p=' + encodeURIComponent(p.value) + '&fecha=' + encodeURIComponent(fecha.value), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (id !== pedido) return;
          if (!d.horas || !d.horas.length) {
            hora.value = '';
            texto('No open times that day for this party. Please try another date.');
            resumen();
            return;
          }
          lista.innerHTML = '';
          var grupo = document.createElement('div');
          grupo.className = 'en-slots';
          grupo.setAttribute('role', 'radiogroup');
          grupo.setAttribute('aria-label', 'Open start times');
          var sigue = false;
          d.horas.forEach(function (h) {
            var lab = document.createElement('label');
            lab.className = 'en-slot';
            var inp = document.createElement('input');
            inp.type = 'radio';
            inp.name = 'hora_elegida';
            inp.value = h.hora;
            inp.setAttribute('data-texto', h.texto);
            if (h.hora === hora.value) { inp.checked = true; sigue = true; }
            inp.addEventListener('change', function () { hora.value = h.hora; resumen(); });
            var span = document.createElement('span');
            span.textContent = h.texto;
            lab.appendChild(inp);
            lab.appendChild(span);
            grupo.appendChild(lab);
          });
          if (!sigue) hora.value = '';
          lista.appendChild(grupo);
          resumen();
        })
        .catch(function () { if (id === pedido) texto('We could not load times. Check your connection and try again.'); });
    }

    form.addEventListener('change', function (ev) {
      if (ev.target.name === 'paquete' || ev.target === fecha) cargarHoras();
    });
    form.addEventListener('submit', function (ev) {
      if (!hora.value) {
        ev.preventDefault();
        texto(fecha.value ? 'Please pick a start time.' : 'Please pick a date and a start time.');
        lista.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
    cargarHoras();
  }
})();
