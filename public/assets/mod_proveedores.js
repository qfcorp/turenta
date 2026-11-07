// TuRenta — Proveedores y compras (060): agregar líneas a una compra y ver el total mientras se escribe.
document.addEventListener('DOMContentLoaded', () => {
    const numero = (v) => {
        v = String(v || '').trim().replace(/\s/g, '');
        if (v === '') return 0;
        if (v.includes(',') && v.includes('.')) v = v.replace(/\./g, '');
        else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, '');
        const n = parseFloat(v.replace(',', '.'));
        return isNaN(n) ? 0 : n;
    };
    const dinero = (n) => '$' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    document.querySelectorAll('form.pv-form-compra').forEach(form => {
        const caja = form.querySelector('.pv-items');
        if (!caja) return;
        const iva = numero(form.dataset.iva || '15');
        const totalEl = caja.querySelector('[data-pv-total]');
        const calcular = () => {
            let sub = 0, base = 0;
            caja.querySelectorAll('.pv-item').forEach(f => {
                const c = f.querySelector('input[name$="[cantidad]"]');
                const p = f.querySelector('input[name$="[precio_unitario]"]');
                const s = f.querySelector('select[name$="[con_iva]"]');
                const cant = c && c.value.trim() !== '' ? numero(c.value) : (p && p.value.trim() !== '' ? 1 : 0);
                const linea = Math.round(cant * numero(p ? p.value : 0) * 100) / 100;
                sub += linea;
                if (!s || s.value === '1') base += linea;
            });
            const impuesto = Math.round(base * iva) / 100;
            if (totalEl) {
                totalEl.hidden = sub <= 0;
                totalEl.textContent = 'Subtotal ' + dinero(sub) + ' · IVA ' + dinero(impuesto) + ' · Total ' + dinero(sub + impuesto);
            }
        };
        caja.addEventListener('input', calcular);
        caja.addEventListener('change', calcular);
        const boton = caja.querySelector('[data-pv-agregar-item]');
        if (boton) {
            boton.addEventListener('click', () => {
                const filas = caja.querySelectorAll('.pv-item');
                const ultima = filas[filas.length - 1];
                if (!ultima) return;
                const nueva = ultima.cloneNode(true);
                const n = filas.length;
                nueva.querySelectorAll('input, select').forEach(el => {
                    el.name = el.name.replace(/items\[\d+\]/, 'items[' + n + ']');
                    if (el.tagName === 'INPUT') el.value = '';
                    else el.selectedIndex = 0;
                    el.removeAttribute('id');
                });
                ultima.after(nueva);
                const primero = nueva.querySelector('input');
                if (primero) primero.focus();
            });
        }
        calcular();
    });
});

// Cómo se calcula el puntaje (091): suma de los pesos y vista previa en vivo (sin guardar)
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[data-pv-puntaje]');
    if (!form) return;
    const suma = form.querySelector('[data-pv-suma]');
    const vista = document.querySelector('[data-pv-vista]');
    const error = document.querySelector('[data-pv-error]');
    const pesos = ['peso_calificacion', 'peso_puntualidad', 'peso_cumplimiento', 'peso_incidencias'];
    let espera = null, pedido = 0;
    const sumar = () => {
        const t = pesos.reduce((a, n) => a + (parseInt(form.elements[n].value, 10) || 0), 0);
        suma.textContent = t === 100 ? '✓ Suman 100 %' : 'Suman ' + t + ' %: deben sumar 100 %';
        suma.classList.toggle('pv-suma-ok', t === 100);
        suma.classList.toggle('pv-suma-mal', t !== 100);
        return t;
    };
    const previa = async () => {
        if (!vista) return;
        const n = ++pedido;
        const datos = new FormData(form);
        datos.set('accion', 'vista_previa');
        try {
            const r = await fetch(form.action + '&json=1', { method: 'POST', body: datos, credentials: 'same-origin' });
            const j = await r.json();
            if (n !== pedido) return;   // llegó una respuesta vieja
            if (j.ok) { vista.innerHTML = j.html; error.hidden = true; }
            else { error.textContent = j.error || 'Revisa los valores.'; error.hidden = false; }
        } catch (e) { /* sin conexión: queda el botón "Ver cómo quedaría" */ }
    };
    form.addEventListener('input', () => {
        sumar();
        clearTimeout(espera);
        espera = setTimeout(previa, 450);
    });
    form.addEventListener('change', () => { clearTimeout(espera); espera = setTimeout(previa, 150); });
    sumar();
});
