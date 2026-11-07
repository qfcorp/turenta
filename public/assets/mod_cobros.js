// TuRenta — módulo Cobros y portal del cliente. Todo es opcional: si no están los elementos, no hace nada.
document.addEventListener('DOMContentLoaded', () => {
    const aNumero = v => {
        v = String(v || '').trim().replace(/\s/g, '');
        if (v.includes(',') && v.includes('.')) v = v.replace(/\./g, '');
        const n = parseFloat(v.replace(',', '.'));
        return isNaN(n) ? 0 : n;
    };
    const dinero = n => '$' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    // Plan de pagos a mano: suma en vivo y agregar filas
    document.querySelectorAll('form.cb-plan-mano').forEach(form => {
        const total = parseFloat(form.dataset.total || '0');
        const suma = form.querySelector('.cb-suma');
        const filas = form.querySelector('.cb-plan-filas');
        const calcular = () => {
            let s = 0;
            form.querySelectorAll('.cb-monto-cuota').forEach(i => { s += aNumero(i.value); });
            s = Math.round(s * 100) / 100;
            const dif = Math.round((total - s) * 100) / 100;
            if (!suma) return;
            suma.classList.toggle('cb-bien', dif === 0);
            suma.classList.toggle('cb-mal', dif !== 0);
            suma.textContent = 'Suma: ' + dinero(s) + (dif === 0 ? ' ✓ igual al valor del evento' : (dif > 0 ? ' · faltan ' + dinero(dif) : ' · sobran ' + dinero(-dif)));
        };
        form.addEventListener('input', e => { if (e.target.classList.contains('cb-monto-cuota')) calcular(); });
        const agregar = form.querySelector('[data-cb-agregar-fila]');
        if (agregar && filas) {
            agregar.addEventListener('click', () => {
                const ultima = filas.lastElementChild;
                if (!ultima) return;
                const nueva = ultima.cloneNode(true);
                const n = filas.children.length;
                nueva.querySelectorAll('input').forEach(i => {
                    const base = i.id.replace(/\d+$/, '');
                    i.id = base + 'n' + n;
                    i.value = i.type === 'date' ? '' : (i.classList.contains('cb-monto-cuota') ? '' : 'Cuota ' + (n + 1));
                });
                nueva.querySelectorAll('label').forEach(l => { l.htmlFor = l.htmlFor.replace(/\d+$/, '') + 'n' + n; });
                filas.appendChild(nueva);
                nueva.querySelector('input')?.focus();
            });
        }
        calcular();
    });

    // Tablero de cobros: abrir/cerrar el pago rápido
    document.querySelectorAll('[data-cb-abrir]').forEach(b => {
        b.addEventListener('click', () => {
            const f = document.getElementById(b.dataset.cbAbrir);
            if (!f) return;
            f.hidden = !f.hidden;
            b.setAttribute('aria-expanded', String(!f.hidden));
            if (!f.hidden) f.querySelector('input[name=monto]')?.select();
        });
    });

    // Mensajes de hoy: al tocar "Enviar por WhatsApp", resalta el botón de marcar como enviado
    document.querySelectorAll('[data-cb-wa]').forEach(a => {
        a.addEventListener('click', () => {
            const li = a.closest('.cb-mensaje');
            const marcar = li && li.querySelector('form button[type=submit]');
            if (marcar) { marcar.classList.remove('boton-claro'); marcar.focus(); }
        });
    });

    // Portal: cuenta regresiva hasta el evento
    const cuenta = document.querySelector('[data-cb-cuenta]');
    if (cuenta) {
        const meta = new Date(cuenta.dataset.cbCuenta);
        const d = cuenta.querySelector('[data-cb-dias]'), h = cuenta.querySelector('[data-cb-horas]'), m = cuenta.querySelector('[data-cb-min]');
        const pintar = () => {
            const ms = meta - new Date();
            if (ms <= 0 || isNaN(ms) || !d) return;
            const min = Math.floor(ms / 60000);
            d.textContent = Math.floor(min / 1440);
            if (h) h.textContent = Math.floor((min % 1440) / 60);
            if (m) m.textContent = min % 60;
        };
        pintar();
        setInterval(pintar, 30000);
    }
});
