// TuRenta — módulo de ventas: formulario de evento (choques en vivo, capacidad, buscador de clientes)
// y "¿Está libre tu fecha?" de la página pública. No falla si los elementos no existen.
document.addEventListener('DOMContentLoaded', () => {
    const crear = (etiqueta, clase, texto) => {
        const n = document.createElement(etiqueta);
        if (clase) n.className = clase;
        if (texto !== undefined) n.textContent = texto;
        return n;
    };

    // ------------------------------------------------------------------
    // Formulario de evento
    // ------------------------------------------------------------------
    const form = document.querySelector('#form-evento');
    if (form) {
        // Cliente nuevo / existente
        const radios = form.querySelectorAll('input[name="modo_cliente"]');
        const cajas = form.querySelectorAll('.caja-cliente');
        const aplicarModo = () => {
            const marcado = form.querySelector('input[name="modo_cliente"]:checked');
            const modo = marcado ? marcado.value : 'nuevo';
            cajas.forEach(c => {
                const ver = c.dataset.si === modo;
                c.hidden = !ver;
                c.querySelectorAll('input, select').forEach(i => { i.disabled = !ver; });
            });
        };
        radios.forEach(r => r.addEventListener('change', aplicarModo));
        aplicarModo();

        // Buscador de clientes: filtra las opciones del select
        const buscar = form.querySelector('.js-filtrar-clientes');
        if (buscar) {
            const sel = form.querySelector('#' + buscar.dataset.select);
            buscar.addEventListener('input', () => {
                const q = buscar.value.trim().toLowerCase();
                let primero = null;
                Array.from(sel.options).forEach(o => {
                    const ver = !q || (o.dataset.buscar || '').includes(q);
                    o.hidden = !ver;
                    if (ver && !primero) primero = o;
                });
                if (q && primero && (sel.selectedIndex < 0 || sel.options[sel.selectedIndex].hidden)) primero.selected = true;
            });
        }

        // Capacidad
        const invitados = form.querySelector('#invitados');
        const montaje = form.querySelector('#montaje');
        const avisoCap = form.querySelector('#aviso-capacidad');
        const casillas = () => Array.from(form.querySelectorAll('input[name="espacios[]"]'));
        const revisarCapacidad = () => {
            if (!avisoCap || !invitados) return;
            const elegidos = casillas().filter(c => c.checked);
            const coctel = montaje && montaje.value === 'coctel';
            const cap = elegidos.reduce((s, c) => s + Number(coctel ? c.dataset.coctel : c.dataset.banquete || 0), 0);
            const n = Number(invitados.value || 0);
            if (elegidos.length && cap > 0 && n > cap) {
                avisoCap.textContent = `Ojo: ${n} invitados superan la capacidad de ${cap} ${coctel ? 'de pie' : 'sentados'} de lo que elegiste. Agrega otro espacio o revisa el número.`;
                avisoCap.hidden = false;
            } else {
                avisoCap.hidden = true;
            }
        };

        // Choques en vivo
        const caja = form.querySelector('#choques-vivo');
        const fecha = form.querySelector('#fecha');
        const hi = form.querySelector('#hora_inicio');
        const hf = form.querySelector('#hora_fin');
        let espera = null, ultimo = '';
        const revisarChoques = () => {
            clearTimeout(espera);
            espera = setTimeout(async () => {
                if (!caja || !fecha || !fecha.value) return;
                const elegidos = casillas().filter(c => c.checked).map(c => c.value);
                if (!elegidos.length) { caja.innerHTML = ''; return; }
                const p = new URLSearchParams({ fecha: fecha.value, hora_inicio: hi ? hi.value : '', hora_fin: hf ? hf.value : '' });
                elegidos.forEach(id => p.append('espacios[]', id));
                if (form.dataset.excluir) p.append('excluir', form.dataset.excluir);
                const clave = p.toString();
                if (clave === ultimo) return;
                ultimo = clave;
                try {
                    const r = await fetch(form.dataset.disponible + '&' + clave, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (!r.ok) return;
                    const d = await r.json();
                    caja.innerHTML = '';
                    const choques = d.choques || [];
                    if (!choques.length) {
                        caja.appendChild(crear('p', 'aviso choque-libre', '✓ Libre: no hay otros eventos en ese espacio a esa hora.'));
                        return;
                    }
                    const av = crear('div', 'aviso ' + (d.bloqueado ? 'aviso-error' : 'aviso-alerta'));
                    av.setAttribute('role', 'status');
                    choques.forEach(c => {
                        const fila = crear('div');
                        fila.appendChild(document.createTextNode(c.texto + ' '));
                        if (c.enlace) {
                            const a = crear('a', '', 'Ver');
                            a.href = c.enlace; a.target = '_blank'; a.rel = 'noopener';
                            fila.appendChild(a);
                        }
                        av.appendChild(fila);
                    });
                    if (d.bloqueado) av.appendChild(crear('div', '', 'Elige otra fecha, otra hora u otro espacio.'));
                    else av.appendChild(crear('div', '', 'Se puede guardar: el primero que pague la reserva se queda con la fecha.'));
                    caja.appendChild(av);
                } catch (e) { /* sin conexión: no se muestra nada */ }
            }, 300);
        };
        [fecha, hi, hf].forEach(el => el && el.addEventListener('change', revisarChoques));
        casillas().forEach(c => c.addEventListener('change', () => { revisarChoques(); revisarCapacidad(); }));
        if (invitados) invitados.addEventListener('input', revisarCapacidad);
        if (montaje) montaje.addEventListener('change', revisarCapacidad);
        revisarCapacidad();
        if (caja && fecha && fecha.value && !caja.children.length) revisarChoques();
    }

    // ------------------------------------------------------------------
    // Página pública: ¿Está libre tu fecha?
    // ------------------------------------------------------------------
    const libre = document.querySelector('.sitio-libre[data-disponible]');
    if (libre) {
        const entrada = libre.querySelector('#fecha-libre');
        const boton = libre.querySelector('#ver-libre');
        const res = libre.querySelector('#resultado-libre');
        const fechaForm = document.querySelector('#c-fecha');
        const textos = { libre: 'Libre', consultar: 'Por confirmar', ocupado: 'Ocupada' };
        const ayudas = {
            libre: 'Está disponible. ¡Pide información antes de que la separen!',
            consultar: 'Otra persona preguntó por esta fecha, pero aún no la separa. Escríbenos pronto.',
            ocupado: 'Esta fecha ya no está disponible en este espacio.',
        };
        const ver = async () => {
            if (!entrada.value) { res.textContent = 'Elige una fecha.'; return; }
            res.textContent = 'Buscando…';
            try {
                const r = await fetch(libre.dataset.disponible + '&fecha=' + encodeURIComponent(entrada.value), { headers: { Accept: 'application/json' } });
                const d = await r.json();
                res.innerHTML = '';
                if (d.error) { res.appendChild(crear('p', 'aviso aviso-error', d.error)); return; }
                const lista = crear('ul');
                let hayLibre = false;
                (d.espacios || []).forEach(x => {
                    const li = crear('li', 'r-' + x.estado);
                    const izq = crear('span');
                    izq.appendChild(crear('strong', '', x.espacio));
                    izq.appendChild(crear('small', '', (x.capacidad ? 'Hasta ' + x.capacidad + ' sentados · ' : '') + ayudas[x.estado]));
                    li.appendChild(izq);
                    li.appendChild(crear('span', 'r-estado', textos[x.estado] || x.estado));
                    lista.appendChild(li);
                    if (x.estado !== 'ocupado') hayLibre = true;
                });
                res.appendChild(lista);
                if (hayLibre) {
                    const nota = crear('p', 'r-nota');
                    const a = crear('a', 'boton boton-chico', 'Pedir información para esta fecha');
                    a.href = '#consulta';
                    a.addEventListener('click', () => { if (fechaForm) fechaForm.value = entrada.value; });
                    nota.appendChild(a);
                    res.appendChild(nota);
                } else {
                    res.appendChild(crear('p', 'r-nota suave', 'Prueba con otra fecha o escríbenos: te ayudamos a encontrar un día libre.'));
                }
            } catch (e) {
                res.textContent = 'No pudimos revisar la fecha. Intenta otra vez o escríbenos.';
            }
        };
        if (boton) boton.addEventListener('click', ver);
        if (entrada) {
            entrada.addEventListener('change', ver);
            if (entrada.value) ver();
        }
    }
});
