// TuRenta — comportamientos pequeños, sin librerías. Cada módulo puede tener su propio mod_*.js.
document.addEventListener('DOMContentLoaded', () => {
    // Confirmación antes de acciones que no se pueden deshacer
    document.querySelectorAll('[data-confirmar]').forEach(el => {
        el.addEventListener('click', ev => {
            if (!confirm(el.dataset.confirmar)) ev.preventDefault();
        });
    });

    // Copiar un enlace al portapapeles: <button data-copiar="https://...">
    document.querySelectorAll('[data-copiar]').forEach(b => {
        b.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(b.dataset.copiar);
                const antes = b.textContent;
                b.textContent = '¡Copiado!';
                setTimeout(() => { b.textContent = antes; }, 1600);
            } catch (e) { prompt('Copia este enlace:', b.dataset.copiar); }
        });
    });

    // Enviar el formulario al cambiar un filtro: <select data-autoenviar>
    document.querySelectorAll('[data-autoenviar]').forEach(el => {
        el.addEventListener('change', () => el.form && el.form.submit());
    });

    // Mostrar/ocultar bloques según una opción: <select data-mostrar="clase"> + <div data-si="valor" class="clase">
    document.querySelectorAll('[data-mostrar]').forEach(sel => {
        const aplicar = () => {
            let valor = sel.type === 'checkbox' ? (sel.checked ? '1' : '0') : sel.value;
            if (sel.type === 'radio') {
                const marcado = (sel.form || document).querySelector('input[name="' + sel.name + '"]:checked');
                valor = marcado ? marcado.value : '';
            }
            document.querySelectorAll('.' + sel.dataset.mostrar).forEach(b => {
                b.hidden = !(b.dataset.si || '').split(',').includes(valor);
            });
        };
        sel.addEventListener('change', aplicar);
        aplicar();
    });

    // Firma con el dedo: <canvas class="firma-caja" data-firma="idDelCampoOculto">
    document.querySelectorAll('canvas[data-firma]').forEach(lienzo => {
        const campo = document.getElementById(lienzo.dataset.firma);
        const ctx = lienzo.getContext('2d');
        let dibujando = false, ultimo = null, tieneTinta = false;
        const ajustar = () => {
            const r = lienzo.getBoundingClientRect();
            const escala = window.devicePixelRatio || 1;
            lienzo.width = r.width * escala; lienzo.height = r.height * escala;
            ctx.setTransform(escala, 0, 0, escala, 0, 0);
            ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#141032';
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, r.width, r.height);
            tieneTinta = false; if (campo) campo.value = '';
        };
        ajustar();
        window.addEventListener('resize', () => { if (!tieneTinta) ajustar(); });
        const punto = e => { const r = lienzo.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
        lienzo.addEventListener('pointerdown', e => { dibujando = true; ultimo = punto(e); lienzo.setPointerCapture(e.pointerId); });
        lienzo.addEventListener('pointermove', e => {
            if (!dibujando) return;
            const p = punto(e);
            ctx.beginPath(); ctx.moveTo(ultimo.x, ultimo.y); ctx.lineTo(p.x, p.y); ctx.stroke();
            ultimo = p; tieneTinta = true;
        });
        const terminar = () => { if (dibujando && tieneTinta && campo) campo.value = lienzo.toDataURL('image/png'); dibujando = false; };
        lienzo.addEventListener('pointerup', terminar);
        lienzo.addEventListener('pointerleave', terminar);
        document.querySelectorAll('[data-borrar-firma="' + lienzo.dataset.firma + '"]').forEach(b => b.addEventListener('click', ajustar));
    });

    // Búsqueda rápida (eventos y clientes) en la barra lateral
    const buscador = document.querySelector('#buscar-rapido');
    if (buscador) {
        const lista = document.querySelector('#buscar-resultados');
        let espera = null;
        buscador.addEventListener('input', () => {
            clearTimeout(espera);
            const q = buscador.value.trim();
            if (q.length < 2) { lista.hidden = true; lista.innerHTML = ''; return; }
            espera = setTimeout(async () => {
                try {
                    const r = await fetch('/?r=buscar&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                    const d = await r.json();
                    lista.innerHTML = '';
                    (d.resultados || []).forEach(x => {
                        const a = document.createElement('a');
                        a.href = x.enlace; a.innerHTML = '<strong></strong><small></small>';
                        a.querySelector('strong').textContent = x.titulo;
                        a.querySelector('small').textContent = x.detalle;
                        lista.appendChild(a);
                    });
                    if (!lista.children.length) lista.innerHTML = '<span class="suave">Sin resultados</span>';
                    lista.hidden = false;
                } catch (e) { lista.hidden = true; }
            }, 250);
        });
    }

    // Panel Tukán: registrar pago — muestra el precio normal y el dominio solo en el anual
    const formPago = document.querySelector('#form-pago');
    if (formPago) {
        let cot = {};
        try { cot = JSON.parse(formPago.dataset.cotizaciones || '{}'); } catch (e) { /* nada */ }
        const plan = formPago.querySelector('#plan'), periodo = formPago.querySelector('#periodo');
        const monto = formPago.querySelector('#monto'), ayuda = formPago.querySelector('#monto-ayuda');
        const dominio = formPago.querySelector('#campo-dominio');
        const actualizar = () => {
            const c = cot[plan.value]?.[periodo.value];
            if (c) {
                monto.placeholder = Number(c.monto).toFixed(2);
                ayuda.textContent = `Vacío = precio normal ($${Number(c.monto).toFixed(2).replace('.', ',')} por ${c.meses_recibidos} ${c.meses_recibidos === 1 ? 'mes' : 'meses'}).`;
            }
            dominio.hidden = periodo.value !== 'anual';
        };
        plan.addEventListener('change', actualizar);
        periodo.addEventListener('change', actualizar);
        actualizar();
    }
});

// ---------------------------------------------------------------
// App en el celular (se instala desde el navegador, sin tiendas)
// ---------------------------------------------------------------
if ('serviceWorker' in navigator && document.body.classList.contains('con-sesion')) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}
(() => {
    const caja = document.querySelector('[data-instalar-app]');
    if (!caja) return;
    const enApp = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (enApp) { caja.hidden = true; return; }
    const boton = caja.querySelector('[data-instalar]');
    let pedido = null;
    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault(); pedido = e; caja.hidden = false; if (boton) boton.hidden = false;
    });
    boton?.addEventListener('click', async () => {
        if (!pedido) return;
        pedido.prompt();
        const r = await pedido.userChoice;
        if (r.outcome === 'accepted') caja.hidden = true;
        pedido = null;
    });
    window.addEventListener('appinstalled', () => { caja.hidden = true; });
})();
