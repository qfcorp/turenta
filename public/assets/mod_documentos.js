// TuRenta — módulo documentos: catálogo, paquetes, editor de cotizaciones, plantillas de contrato y firma.
// Se carga en todas las páginas: cada parte revisa primero si sus elementos existen.
(() => {
    'use strict';

    /** "1.234,50" / "1234.50" / "" -> número (NaN si no es número) */
    const num = (v) => {
        let s = String(v ?? '').replace(/\s/g, '');
        if (s === '') return 0;
        if (s.includes(',') && s.includes('.')) s = s.replace(/\./g, '');
        s = s.replace(',', '.');
        const n = Number(s);
        return Number.isFinite(n) ? n : NaN;
    };
    const r2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
    /** Dinero al estilo ecuatoriano: $1.234,50 */
    const dinero = (n) => {
        const v = Math.abs(r2(n));
        const [ent, dec] = v.toFixed(2).split('.');
        return (n < 0 ? '−$' : '$') + ent.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + dec;
    };
    const pct = (n) => (Math.round(n * 10) / 10).toFixed(1).replace('.', ',') + ' %';

    /** Igual que Cotizaciones::calcularTotales (PHP). */
    const calcularTotales = (conIva, sinIva, descuento, ivaPct) => {
        const subtotal = r2(conIva + sinIva);
        descuento = Math.min(r2(descuento), subtotal);
        const descIva = subtotal > 0 ? r2(descuento * conIva / subtotal) : 0;
        const descCero = r2(descuento - descIva);
        const baseIva = r2(conIva - descIva);
        const baseCero = r2(sinIva - descCero);
        const iva = r2(baseIva * ivaPct / 100);
        return { subtotal, descuento, base_iva: baseIva, base_cero: baseCero, iva, total: r2(baseIva + baseCero + iva) };
    };

    document.addEventListener('DOMContentLoaded', () => {
        margenProducto();
        subirPreciosVista();
        editorPaquete();
        editorCotizacion();
        variablesPlantilla();
        firmaObligatoria();
    });

    // -----------------------------------------------------------------
    // Catálogo: margen en vivo al escribir precio y costo (solo dueño)
    // -----------------------------------------------------------------
    function margenProducto() {
        const precio = document.querySelector('[data-doc-precio]');
        const costo = document.querySelector('[data-doc-costo]');
        const salida = document.querySelector('[data-doc-margen]');
        if (!precio || !costo || !salida) return;
        const original = salida.textContent;
        const pintar = () => {
            const p = num(precio.value), c = num(costo.value);
            if (!(p > 0) || Number.isNaN(c) || costo.value.trim() === '') { salida.textContent = original; salida.classList.remove('doc-margen-bajo'); return; }
            const m = (p - c) * 100 / p;
            salida.textContent = 'Ganas ' + dinero(p - c) + ' por cada uno · margen ' + pct(m);
            salida.classList.toggle('doc-margen-bajo', m < 20);
        };
        precio.addEventListener('input', pintar);
        costo.addEventListener('input', pintar);
        pintar();
    }

    // -----------------------------------------------------------------
    // Catálogo: vista previa de "subir precios en bloque"
    // -----------------------------------------------------------------
    function subirPreciosVista() {
        const form = document.querySelector('[data-doc-bloque]');
        if (!form) return;
        let lista = [];
        try { lista = JSON.parse(form.dataset.docBloque || '[]'); } catch (e) { lista = []; }
        const inPct = form.querySelector('[data-doc-bloque-pct]');
        const sel = form.querySelector('[data-doc-bloque-cat]');
        const vista = form.querySelector('[data-doc-bloque-vista]');
        const pintar = () => {
            const p = num(inPct.value);
            const elegidos = lista.filter(x => !sel.value || x.c === sel.value);
            if (!p || Number.isNaN(p)) { vista.textContent = 'Escribe el porcentaje para ver cómo quedan los precios.'; return; }
            const ej = elegidos.slice(0, 3).map(x => `${x.n}: ${dinero(x.p)} → ${dinero(r2(x.p * (1 + p / 100)))}`);
            vista.textContent = `Cambian ${elegidos.length} ${elegidos.length === 1 ? 'producto' : 'productos'}. ` + (ej.length ? 'Ejemplo: ' + ej.join(' · ') : '');
        };
        inPct.addEventListener('input', pintar);
        sel.addEventListener('change', pintar);
        form.addEventListener('submit', ev => {
            const p = num(inPct.value);
            const elegidos = lista.filter(x => !sel.value || x.c === sel.value).length;
            const txt = `¿${p > 0 ? 'Subir' : 'Bajar'} ${Math.abs(p).toString().replace('.', ',')} % los precios de ${elegidos} ${elegidos === 1 ? 'producto' : 'productos'}`
                + `${sel.value ? ' de ' + sel.options[sel.selectedIndex].text.replace(/\s*\(\d+\)$/, '') : ' de todo el catálogo'}?`;
            if (!confirm(txt)) ev.preventDefault();
        });
    }

    // -----------------------------------------------------------------
    // Paquetes: filas de productos y simulador en vivo
    // -----------------------------------------------------------------
    function editorPaquete() {
        const form = document.querySelector('[data-doc-paquete]');
        if (!form) return;
        const cuerpo = form.querySelector('[data-doc-paq-filas]');
        const plantilla = form.querySelector('[data-doc-paq-plantilla]');
        const inN = form.querySelector('[data-doc-paq-n]');
        const inMin = form.querySelector('[data-doc-paq-min]');
        const resumen = form.querySelector('[data-doc-paq-resumen]');
        const ivaPct = num(form.dataset.iva);
        let siguiente = 1000;
        const pintar = () => {
            const n = Math.max(parseInt(inN.value, 10) || 0, parseInt(inMin.value, 10) || 0, 1);
            let conIva = 0, sinIva = 0;
            cuerpo.querySelectorAll('[data-doc-paq-fila]').forEach(f => {
                const sel = f.querySelector('[data-doc-paq-prod]');
                const op = sel.options[sel.selectedIndex];
                const celda = f.querySelector('[data-doc-paq-total]');
                if (!sel.value || !op) { celda.textContent = '—'; return; }
                const especial = f.querySelector('[data-doc-paq-precio]').value.trim();
                const precio = especial === '' ? num(op.dataset.precio) : num(especial);
                const cant = num(f.querySelector('[data-doc-paq-cant]').value) * (op.dataset.unidad === 'persona' ? n : 1);
                if (Number.isNaN(precio) || Number.isNaN(cant)) { celda.textContent = '?'; return; }
                const total = r2(r2(cant) * precio);
                celda.textContent = dinero(total);
                if (op.dataset.iva === '1') conIva += total; else sinIva += total;
            });
            const t = calcularTotales(conIva, sinIva, 0, ivaPct);
            resumen.textContent = t.subtotal > 0
                ? `${dinero(t.total)} con IVA · ${dinero(r2(t.total / n))} por persona` + (n > (parseInt(inN.value, 10) || 0) ? ` (calculado con el mínimo de ${n})` : '')
                : 'Elige los productos';
        };
        form.addEventListener('input', pintar);
        form.addEventListener('change', pintar);
        form.querySelector('[data-doc-agregar-fila]')?.addEventListener('click', () => {
            const html = plantilla.innerHTML.replaceAll('items[999]', `items[${siguiente++}]`);
            cuerpo.insertAdjacentHTML('beforeend', html);
            cuerpo.lastElementChild.querySelector('select')?.focus();
            pintar();
        });
        cuerpo.addEventListener('click', ev => {
            const b = ev.target.closest('[data-doc-quitar-fila]');
            if (!b) return;
            const fila = b.closest('[data-doc-paq-fila]');
            if (cuerpo.querySelectorAll('[data-doc-paq-fila]').length > 1) fila.remove();
            else { fila.querySelector('select').value = ''; fila.querySelectorAll('input').forEach(i => { i.value = i.matches('[data-doc-paq-cant]') ? '1' : ''; }); }
            pintar();
        });
        pintar();
    }

    // -----------------------------------------------------------------
    // Cotización: totales en vivo, buscador del catálogo, agregar/quitar/mover líneas
    // -----------------------------------------------------------------
    function editorCotizacion() {
        const form = document.querySelector('[data-doc-editor]');
        if (!form) return;
        const ivaPct = num(form.dataset.iva);
        const esDueno = form.dataset.dueno === '1';
        const caja = form.querySelector('[data-doc-lineas]');
        const plantilla = form.querySelector('[data-doc-plantilla-linea]');
        const inInv = form.querySelector('[data-doc-invitados]');
        const inDesc = form.querySelector('[data-doc-descuento]');
        const avisoCambios = form.querySelector('[data-doc-sin-guardar]');
        let invAnterior = parseInt(inInv.value, 10) || 0;
        let siguiente = 1;
        let cambios = false, enviando = false;

        const lineas = () => [...caja.querySelectorAll('[data-doc-linea]')];
        const marcar = () => { cambios = true; if (avisoCambios) avisoCambios.hidden = false; };
        const t = (k, v) => { const el = form.querySelector(`[data-doc-t="${k}"]`); if (el) el.textContent = v; };

        const recalcular = () => {
            let conIva = 0, sinIva = 0, costo = 0;
            lineas().forEach(l => {
                const desc = l.querySelector('[data-doc-desc]').value.trim();
                const cant = num(l.querySelector('[data-doc-cant]').value);
                const precio = num(l.querySelector('[data-doc-precio-l]').value);
                const celda = l.querySelector('[data-doc-total-l]');
                const malo = Number.isNaN(cant) || Number.isNaN(precio) || cant < 0 || precio < 0;
                l.classList.toggle('doc-linea-error', malo && desc !== '');
                l.classList.toggle('doc-linea-vacia', desc === '');
                if (malo) { celda.textContent = '?'; return; }
                const total = r2(r2(cant) * r2(precio));
                celda.textContent = dinero(total);
                if (desc === '') return;
                if (l.querySelector('[data-doc-iva]').checked) conIva += total; else sinIva += total;
                const c = l.querySelector('[data-doc-costo-l]');
                if (c) costo += r2(cant) * (num(c.value) || 0);
            });
            const descuento = Math.max(0, num(inDesc.value) || 0);
            const tot = calcularTotales(conIva, sinIva, descuento, ivaPct);
            const inv = Math.max(1, parseInt(inInv.value, 10) || 1);
            t('subtotal', dinero(tot.subtotal));
            t('descuento', '−' + dinero(tot.descuento));
            t('base_iva', dinero(tot.base_iva));
            t('base_cero', dinero(tot.base_cero));
            t('iva', dinero(tot.iva));
            t('total', dinero(tot.total));
            t('por_persona', dinero(r2(tot.total / inv)));
            if (esDueno) {
                const ganancia = r2(tot.subtotal - tot.descuento - r2(costo));
                const neto = tot.subtotal - tot.descuento;
                t('ganancia', dinero(ganancia));
                t('margen', neto > 0 ? pct(ganancia * 100 / neto) : '—');
                const g = form.querySelector('[data-doc-t="ganancia"]');
                if (g) g.classList.toggle('doc-negativo', ganancia < 0);
            }
            const bajo = descuento > tot.subtotal;
            inDesc.classList.toggle('doc-campo-error', bajo);
        };

        /** Siempre queda una línea libre vacía al final. */
        const asegurarLibre = () => {
            const todas = lineas();
            const ultima = todas[todas.length - 1];
            if (!ultima || ultima.querySelector('[data-doc-desc]').value.trim() !== '') nuevaLinea({});
        };
        const nuevaLinea = (datos, antesDe = null) => {
            const k = 'n' + Date.now().toString(36) + (siguiente++);
            const tmp = document.createElement('div');
            tmp.innerHTML = plantilla.innerHTML.replaceAll('__K__', k).trim();
            const l = tmp.firstElementChild;
            if (datos.producto_id) l.querySelector('input[name$="[producto_id]"]').value = datos.producto_id;
            if (datos.categoria) l.querySelector('.doc-linea-cat').value = datos.categoria;
            if (datos.descripcion) l.querySelector('[data-doc-desc]').value = datos.descripcion;
            if (datos.unidad) l.querySelector('[data-doc-unidad]').value = datos.unidad;
            if (datos.cantidad !== undefined) l.querySelector('[data-doc-cant]').value = String(datos.cantidad).replace('.', ',');
            if (datos.precio !== undefined) l.querySelector('[data-doc-precio-l]').value = Number(datos.precio).toFixed(2).replace('.', ',');
            if (datos.con_iva !== undefined) l.querySelector('[data-doc-iva]').checked = !!datos.con_iva;
            const c = l.querySelector('[data-doc-costo-l]');
            if (c && datos.costo !== undefined) c.value = Number(datos.costo).toFixed(2).replace('.', ',');
            if (antesDe) caja.insertBefore(l, antesDe); else caja.appendChild(l);
            return l;
        };

        // Quitar, subir y bajar sin recargar la página
        caja.addEventListener('click', ev => {
            const b = ev.target.closest('[data-doc-quitar],[data-doc-subir],[data-doc-bajar]');
            if (!b) return;
            ev.preventDefault();
            const l = b.closest('[data-doc-linea]');
            if (b.matches('[data-doc-quitar]')) {
                const d = l.querySelector('[data-doc-desc]').value.trim();
                if (d && !confirm(`¿Quitar «${d}» de la cotización?`)) return;
                l.remove();
            } else if (b.matches('[data-doc-subir]') && l.previousElementSibling) {
                caja.insertBefore(l, l.previousElementSibling);
            } else if (b.matches('[data-doc-bajar]') && l.nextElementSibling) {
                caja.insertBefore(l.nextElementSibling, l);
            }
            asegurarLibre();
            recalcular();
            marcar();
        });
        caja.addEventListener('input', ev => {
            if (ev.target.matches('[data-doc-desc]')) asegurarLibre();
        });

        // Invitados: las líneas "por persona" que tenían el número anterior se actualizan
        inInv.addEventListener('change', () => {
            const nuevo = parseInt(inInv.value, 10) || 0;
            if (nuevo > 0 && nuevo !== invAnterior) {
                lineas().forEach(l => {
                    const cant = l.querySelector('[data-doc-cant]');
                    if (l.querySelector('[data-doc-unidad]').value === 'persona' && Math.abs(num(cant.value) - invAnterior) < 0.001) cant.value = String(nuevo);
                });
                invAnterior = nuevo;
                recalcular();
            }
        });

        form.addEventListener('input', () => { recalcular(); marcar(); });
        form.addEventListener('change', () => { recalcular(); marcar(); });
        form.addEventListener('submit', () => { enviando = true; });
        window.addEventListener('beforeunload', ev => {
            if (cambios && !enviando) { ev.preventDefault(); ev.returnValue = ''; }
        });

        // Buscador del catálogo
        const buscador = form.querySelector('[data-doc-buscador]');
        const sinJs = form.querySelector('[data-doc-sin-js]');
        if (buscador) {
            let catalogo = [];
            try { catalogo = JSON.parse(buscador.dataset.catalogo || '[]'); } catch (e) { catalogo = []; }
            buscador.hidden = false;
            if (sinJs) { sinJs.hidden = true; sinJs.querySelectorAll('select').forEach(s => { s.disabled = true; }); }
            const q = buscador.querySelector('[data-doc-buscar]');
            const cat = buscador.querySelector('[data-doc-buscar-cat]');
            const res = buscador.querySelector('[data-doc-resultados]');
            const nombresCat = {};
            cat.querySelectorAll('option').forEach(o => { if (o.value) nombresCat[o.value] = o.textContent; });
            const unidades = { persona: 'por persona', evento: 'por evento', hora: 'por hora', unidad: 'por unidad' };
            const sinTildes = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
            const pintar = () => {
                const texto = sinTildes(q.value.trim());
                const lista = catalogo.filter(p => (!cat.value || p.categoria === cat.value)
                    && (!texto || sinTildes(p.nombre + ' ' + p.descripcion).includes(texto)));
                res.innerHTML = '';
                if (!texto && !cat.value) {
                    res.innerHTML = '<li class="doc-res-ayuda">Escribe o elige una categoría para ver los productos.</li>';
                    return;
                }
                if (!lista.length) { res.innerHTML = '<li class="doc-res-ayuda">No hay productos con esa búsqueda. Usa la línea libre de arriba.</li>'; return; }
                lista.slice(0, 30).forEach(p => {
                    const li = document.createElement('li');
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'doc-res-item';
                    b.innerHTML = '<span><strong></strong><small></small></span><span class="monto"></span>';
                    b.querySelector('strong').textContent = p.nombre;
                    b.querySelector('small').textContent = (nombresCat[p.categoria] || '') + ' · ' + (unidades[p.unidad] || '') + (p.con_iva ? '' : ' · 0 % IVA');
                    b.querySelector('.monto').textContent = '+ ' + dinero(p.precio);
                    b.addEventListener('click', () => {
                        const libre = lineas().find(l => l.querySelector('[data-doc-desc]').value.trim() === '') || null;
                        const inv = parseInt(inInv.value, 10) || 1;
                        const l = nuevaLinea({
                            producto_id: p.id, categoria: p.categoria, descripcion: p.nombre + (p.descripcion ? ' — ' + p.descripcion : ''),
                            unidad: p.unidad, cantidad: p.unidad === 'persona' ? inv : 1, precio: p.precio, con_iva: p.con_iva, costo: p.costo ?? 0,
                        }, libre);
                        l.classList.add('doc-recien');
                        setTimeout(() => l.classList.remove('doc-recien'), 1600);
                        asegurarLibre();
                        recalcular();
                        marcar();
                        b.querySelector('.monto').textContent = '✓ agregado';
                        setTimeout(() => { b.querySelector('.monto').textContent = '+ ' + dinero(p.precio); }, 1200);
                    });
                    li.appendChild(b);
                    res.appendChild(li);
                });
            };
            q.addEventListener('input', pintar);
            cat.addEventListener('change', pintar);
            // Que el buscador no envíe el formulario al presionar Enter
            q.addEventListener('keydown', ev => { if (ev.key === 'Enter') ev.preventDefault(); });
            pintar();
        }
        recalcular();
    }

    // -----------------------------------------------------------------
    // Plantillas de contrato: clic en una variable -> se inserta en el texto
    // -----------------------------------------------------------------
    function variablesPlantilla() {
        const texto = document.querySelector('[data-doc-plantilla-texto]');
        if (!texto) return;
        document.querySelectorAll('[data-doc-variable]').forEach(b => {
            b.addEventListener('click', () => {
                const v = b.dataset.docVariable;
                const ini = texto.selectionStart ?? texto.value.length;
                const fin = texto.selectionEnd ?? ini;
                texto.value = texto.value.slice(0, ini) + v + texto.value.slice(fin);
                texto.focus();
                texto.setSelectionRange(ini + v.length, ini + v.length);
            });
        });
    }

    // -----------------------------------------------------------------
    // Firma: no dejar enviar sin dibujar
    // -----------------------------------------------------------------
    function firmaObligatoria() {
        const form = document.querySelector('[data-doc-firmar]');
        if (!form) return;
        const campo = form.querySelector('#firma-dato');
        const falta = form.querySelector('[data-doc-firma-falta]');
        form.addEventListener('submit', ev => {
            if (campo && campo.value === '') {
                ev.preventDefault();
                if (falta) falta.hidden = false;
                form.querySelector('.firma-caja')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        form.querySelector('.firma-caja')?.addEventListener('pointerdown', () => { if (falta) falta.hidden = true; });
    }
})();
