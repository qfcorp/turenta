// TuRenta — módulo Día del evento e invitados. Se carga en todas las páginas: cada parte revisa que sus elementos existan.
(() => {
    const listo = f => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', f) : f());

    listo(() => {
        // ------------------------------------------------------------
        // Invitación: "Sí, voy" muestra cuántos van y la comida; "No podré" lo oculta
        // ------------------------------------------------------------
        document.querySelectorAll('[data-dia-asiste]').forEach(caja => {
            const form = caja.closest('form');
            const extra = form && form.querySelector('.dia-si-asiste');
            if (!extra) return;
            const aplicar = () => {
                const si = form.querySelector('input[name=respuesta][value=si]');
                extra.hidden = !(si && si.checked);
            };
            form.querySelectorAll('input[name=respuesta]').forEach(r => r.addEventListener('change', aplicar));
            aplicar();
        });

        // ------------------------------------------------------------
        // Asignar personal: al elegir la persona, sugiere su rol y su pago
        // ------------------------------------------------------------
        const persona = document.querySelector('[data-dia-persona]');
        if (persona && persona.form) {
            persona.addEventListener('change', () => {
                const op = persona.selectedOptions[0];
                const pago = persona.form.querySelector('[name=pago]');
                if (op && op.dataset.pago && pago) pago.placeholder = 'Su tarifa: ' + Number(op.dataset.pago).toFixed(2).replace('.', ',');
            });
        }

        // ------------------------------------------------------------
        // Mobiliario: marca en rojo si pides más de lo libre ese día
        // ------------------------------------------------------------
        document.querySelectorAll('input.dia-cant[data-libres]').forEach(inp => {
            const revisar = () => inp.classList.toggle('dia-pasa', Number(inp.value || 0) > Number(inp.dataset.libres));
            inp.addEventListener('input', revisar);
            revisar();
        });

        // ------------------------------------------------------------
        // Invitaciones: al tocar "Invitar" (se abre WhatsApp en otra pestaña) recarga para marcarlo enviado
        // ------------------------------------------------------------
        document.querySelectorAll('form[data-dia-enviado]').forEach(f => {
            f.addEventListener('submit', () => setTimeout(() => location.reload(), 1500));
        });

        // ------------------------------------------------------------
        // Modo puerta: busca mientras escribe y actualiza el contador cada 15 s
        // ------------------------------------------------------------
        const puerta = document.querySelector('[data-dia-puerta]');
        if (puerta) {
            const base = puerta.dataset.diaPuerta;
            const ing = puerta.querySelector('[data-dia-ingresaron]');
            const esp = puerta.querySelector('[data-dia-esperados]');
            const lista = puerta.querySelector('[data-dia-resultados]');
            const plantilla = puerta.querySelector('[data-dia-plantilla]');
            const buscador = puerta.querySelector('[data-dia-buscar] input[name=q]');
            const contadores = d => { if (ing) ing.textContent = d.ingresaron; if (esp) esp.textContent = d.esperados; };
            const pintar = (resultados, q) => {
                if (!lista || !plantilla) return;
                lista.innerHTML = '';
                if (!resultados.length) {
                    const li = document.createElement('li');
                    li.className = 'vacio';
                    li.textContent = 'No hay nadie con ese nombre en la lista. Revisa cómo se escribe o busca por el apellido.';
                    lista.appendChild(li);
                    return;
                }
                resultados.forEach(x => {
                    const li = plantilla.content.firstElementChild.cloneNode(true);
                    const faltan = x.pases - x.ingresaron;
                    li.querySelector('strong').textContent = x.nombre;
                    const partes = [];
                    if (x.mesa) partes.push(x.mesa);
                    partes.push(x.pases + (x.pases === 1 ? ' pase' : ' pases'));
                    if (x.estado === 'no_asiste') partes.push('dijo que no venía');
                    else if (x.estado === 'pendiente') partes.push('no confirmó');
                    if (x.ingresaron) partes.push('entraron ' + x.ingresaron);
                    li.querySelector('small').textContent = partes.join(' · ');
                    const form = li.querySelector('form');
                    if (faltan <= 0) {
                        li.classList.add('dia-ya-entro');
                        const ok = document.createElement('span');
                        ok.className = 'dia-check'; ok.textContent = '✓'; ok.setAttribute('aria-label', 'Ya entró completo');
                        form.replaceWith(ok);
                    } else {
                        form.querySelector('[name=invitado_id]').value = x.id;
                        form.querySelector('[name=q]').value = q;
                        const sel = form.querySelector('select[name=cuantos]');
                        if (sel) {
                            sel.id = 'c' + x.id;
                            const lab = form.querySelector('label'); if (lab) lab.htmlFor = sel.id;
                            sel.innerHTML = '';
                            if (faltan > 1) {
                                for (let k = faltan; k >= 1; k--) { const o = document.createElement('option'); o.value = k; o.textContent = k; sel.appendChild(o); }
                            } else {
                                const h = document.createElement('input'); h.type = 'hidden'; h.name = 'cuantos'; h.value = '1';
                                if (lab) lab.remove();
                                sel.replaceWith(h);
                            }
                        }
                    }
                    lista.appendChild(li);
                });
            };
            let espera = null, ultimo = buscador ? buscador.value.trim() : '';
            if (buscador) {
                buscador.addEventListener('input', () => {
                    clearTimeout(espera);
                    const q = buscador.value.trim();
                    if (q === ultimo) return;
                    espera = setTimeout(async () => {
                        ultimo = q;
                        if (q.length < 2) { if (lista) lista.innerHTML = ''; return; }
                        try {
                            const r = await fetch(base + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                            const d = await r.json();
                            contadores(d);
                            if (d.resultados) pintar(d.resultados, q);
                        } catch (e) { /* sin conexión: queda el botón Buscar */ }
                    }, 250);
                });
            }
            setInterval(async () => {
                if (document.hidden) return;
                try {
                    const r = await fetch(base, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    contadores(await r.json());
                } catch (e) { /* sin conexión */ }
            }, 15000);
        }

        // ------------------------------------------------------------
        // Cronograma en vivo: reloj y recarga sola cada 20 s si alguien avanzó
        // ------------------------------------------------------------
        const vivo = document.querySelector('[data-dia-vivo]');
        if (vivo) {
            const reloj = vivo.querySelector('[data-dia-reloj]');
            const estado = vivo.querySelector('[data-dia-estado]');
            if (reloj) setInterval(() => {
                const d = new Date();
                reloj.textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            }, 10000);
            setInterval(async () => {
                if (document.hidden) return;
                try {
                    const r = await fetch(vivo.dataset.diaVivo, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    const d = await r.json();
                    if (d.version && d.version !== vivo.dataset.version) { location.reload(); return; }
                    if (estado) estado.textContent = 'Actualizado a las ' + d.hora + '. Se actualiza solo cada 20 segundos.';
                } catch (e) {
                    if (estado) estado.textContent = 'Sin conexión. Se vuelve a intentar en 20 segundos.';
                }
            }, 20000);
        }
    });
})();
