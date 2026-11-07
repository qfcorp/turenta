// TuRenta — portal del cliente, segunda parte (migración 070): guardado automático del formulario, cantidades de extras.
document.addEventListener('DOMContentLoaded', () => {
    const dinero = v => '$' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const numero = t => {
        t = String(t || '').trim().replace(/\s/g, '');
        if (t.includes(',') && t.includes('.')) t = t.replace(/\./g, '');
        const n = parseFloat(t.replace(',', '.'));
        return isNaN(n) ? 0 : n;
    };

    // 1) Formulario del evento: se guarda solo mientras el cliente escribe
    document.querySelectorAll('form[data-pt-autoguardar]').forEach(form => {
        const estado = form.querySelector('[data-pt-estado]');
        const barra = document.querySelector('[data-pt-barra]');
        const resp = document.querySelector('[data-pt-respondidas]');
        const total = document.querySelector('[data-pt-total]');
        let espera = null, pendiente = false, enviando = false;
        const mostrar = (texto, clase) => {
            if (!estado) return;
            estado.textContent = texto;
            estado.classList.remove('pt-ok', 'pt-mal');
            if (clase) estado.classList.add(clase);
        };
        const marcarRespondidas = () => {
            form.querySelectorAll('[data-pt-pregunta]').forEach(p => {
                const campos = [...p.querySelectorAll('input:not([type=hidden]), textarea, select')];
                const lleno = campos.some(c => (c.type === 'radio' || c.type === 'checkbox') ? (c.checked && c.value !== '') : c.value.trim() !== '');
                p.classList.toggle('pt-lista', lleno);
            });
        };
        const guardar = (alSalir = false) => {
            if (enviando && !alSalir) { pendiente = true; return; }
            const datos = new FormData(form);
            datos.set('accion', 'guardar');
            datos.set('ajax', '1');
            enviando = true; pendiente = false;
            mostrar('Guardando…');
            fetch(form.action, { method: 'POST', body: datos, credentials: 'same-origin', keepalive: alSalir })
                .then(r => r.json().catch(() => ({ ok: false, mensaje: 'No se pudo guardar. Revisa tu conexión.' })))
                .then(j => {
                    if (j.ok) {
                        const h = new Date();
                        mostrar('Guardado ✓ ' + String(h.getHours()).padStart(2, '0') + ':' + String(h.getMinutes()).padStart(2, '0'), 'pt-ok');
                        if (resp) resp.textContent = j.respondidas;
                        if (total) total.textContent = j.total;
                        if (barra) barra.style.width = j.pct + '%';
                        const pb = barra && barra.parentElement;
                        if (pb) pb.setAttribute('aria-valuenow', j.respondidas);
                    } else {
                        mostrar(j.mensaje || 'No se pudo guardar.', 'pt-mal');
                    }
                })
                .catch(() => mostrar('Sin conexión: tus cambios se guardan cuando vuelvas a tener internet.', 'pt-mal'))
                .finally(() => { enviando = false; if (pendiente) guardar(); });
        };
        const programar = demora => {
            marcarRespondidas();
            clearTimeout(espera);
            pendiente = true;
            mostrar('Cambios sin guardar…');
            espera = setTimeout(() => guardar(), demora);
        };
        form.addEventListener('input', ev => programar(ev.target.matches('textarea, input[type=text]') ? 1200 : 400));
        form.addEventListener('change', () => programar(300));
        form.addEventListener('submit', () => { clearTimeout(espera); pendiente = false; });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden' && pendiente) { clearTimeout(espera); guardar(true); }
        });
    });

    // 2) Extras: botones − / +, "para mis invitados" y total aproximado
    document.querySelectorAll('form[data-pt-extras]').forEach(form => {
        const totales = form.querySelectorAll('[data-pt-total]');
        const fijo = form.querySelector('[data-pt-fijo]');
        const recalcular = () => {
            let suma = 0;
            form.querySelectorAll('[data-pt-extra]').forEach(x => {
                const input = x.querySelector('[data-pt-cant]');
                if (!input) return;
                const cant = Math.max(0, numero(input.value));
                x.classList.toggle('pt-elegido', cant > 0);
                suma += cant * parseFloat(x.dataset.precio || '0');
            });
            totales.forEach(t => { t.textContent = dinero(Math.round(suma * 100) / 100); });
            if (fijo) fijo.hidden = suma <= 0;
        };
        form.addEventListener('click', ev => {
            const paso = ev.target.closest('[data-pt-paso]');
            const todos = ev.target.closest('[data-pt-todos]');
            if (!paso && !todos) return;
            const input = ev.target.closest('[data-pt-extra]').querySelector('[data-pt-cant]');
            if (paso) {
                const nuevo = Math.max(0, Math.floor(numero(input.value)) + parseInt(paso.dataset.ptPaso, 10));
                input.value = nuevo > 0 ? String(nuevo) : '';
            } else {
                input.value = todos.dataset.ptTodos;
            }
            recalcular();
        });
        form.addEventListener('input', recalcular);
        recalcular();
    });

    // 3) Pestaña "Formulario y extras": en el celular, que la pestaña activa se vea
    if (document.querySelector('.pt-indice')) {
        const activa = document.querySelector('.pestanas a.activa');
        if (activa && activa.parentElement) activa.parentElement.scrollLeft = activa.offsetLeft - 16;
    }

    // 4) "Ver más" (comentarios del reporte de satisfacción)
    document.querySelectorAll('[data-pt-ver-mas]').forEach(b => {
        b.addEventListener('click', () => {
            document.querySelectorAll(b.dataset.ptVerMas).forEach(el => { el.hidden = false; });
            b.remove();
        });
    });
});
