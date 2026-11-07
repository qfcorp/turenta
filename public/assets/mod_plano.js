// TuRenta — Plano visual del salón: editor con SVG, sin librerías.
// Arrastrar con el dedo o el mouse (pointer events), pellizcar o rueda para acercar, cuadrícula de 0,5 m,
// girar, duplicar, borrar, deshacer y guardado automático (POST con CSRF; el servidor revisa todo otra vez).
// Se carga en todas las páginas: solo hace algo si existe [data-pl-editor].
(() => {
    'use strict';
    const listo = f => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', f) : f());
    const NS = 'http://www.w3.org/2000/svg';
    const MESAS = ['redonda', 'rectangular', 'imperial'];
    const PASO = 0.5;          // cuadrícula para mover (metros)
    const MARGEN = 1.2;        // aire alrededor del salón (metros)
    const MAX_ELEMENTOS = 300;
    const MAX_DESHACER = 60;

    // ---------------------------------------------------------------- utilidades
    const esMesa = t => MESAS.includes(t);
    const redondear = (v, d = 2) => Math.round(v * 10 ** d) / 10 ** d;
    const ajustarPaso = v => Math.round(v / PASO) * PASO;
    const m = v => String(redondear(v, 2)).replace('.', ',');
    const numero = v => { const n = parseFloat(String(v).replace(',', '.').replace(/[^\d.\-]/g, '')); return Number.isFinite(n) ? n : null; };
    const corta = etq => { const r = /^mesa\s+(\d{1,3})$/i.exec((etq || '').trim()); return r ? r[1] : (etq || '').trim(); };
    const el$ = (tag, attrs = {}, padre = null) => {
        const n = document.createElementNS(NS, tag);
        for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
        if (padre) padre.appendChild(n);
        return n;
    };
    const h = (tag, attrs = {}, texto = null) => {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') n.className = v; else if (k.startsWith('data-') || k.startsWith('aria-') || ['type', 'id', 'for', 'href', 'inputmode', 'maxlength', 'min', 'max', 'value', 'placeholder', 'title'].includes(k)) n.setAttribute(k, v);
            else n[k] = v;
        }
        if (texto !== null) n.textContent = texto;
        return n;
    };
    /** Texto: en mesas siempre derecho; en lo demás a lo largo del elemento, nunca de cabeza (igual que Planos::giroTexto). */
    function giroTexto(tipo, rot) {
        rot = ((rot % 360) + 360) % 360;
        if (esMesa(tipo)) return rot ? -rot : 0;
        return rot > 90 && rot <= 270 ? 180 : 0;
    }
    /** Sillas alrededor de la mesa (igual que Planos::sillas en PHP). */
    function sillas(tipo, an, al, puestos) {
        const res = [];
        if (puestos < 1 || puestos > 24) return res;
        if (tipo === 'redonda') {
            const r = an / 2 + 0.28;
            for (let i = 0; i < puestos; i++) { const a = -Math.PI / 2 + 2 * Math.PI * i / puestos; res.push([r * Math.cos(a), r * Math.sin(a)]); }
            return res;
        }
        if (al <= 0.65) { for (let i = 0; i < puestos; i++) res.push([-an / 2 + an * (i + 0.5) / puestos, al / 2 + 0.28]); return res; }
        const arriba = Math.ceil(puestos / 2), abajo = puestos - arriba;
        [[arriba, -al / 2 - 0.28], [abajo, al / 2 + 0.28]].forEach(([n, y]) => { for (let i = 0; i < n; i++) res.push([-an / 2 + an * (i + 0.5) / n, y]); });
        return res;
    }

    function iniciar(raiz) {
        let ini;
        try { ini = JSON.parse(raiz.querySelector('[data-pl-json]').textContent); } catch (e) { return; }
        const cfg = ini.config, T = cfg.tipos, C = cfg.colores;
        const lienzo = raiz.querySelector('[data-pl-lienzo]');
        const panel = raiz.querySelector('[data-pl-panel]');
        const flotante = raiz.querySelector('[data-pl-flotante]');
        const estadoTxt = raiz.querySelector('[data-pl-estado]');
        const mensajes = raiz.querySelector('[data-pl-mensajes]');
        const btnDeshacer = raiz.querySelector('[data-pl-accion="deshacer"]');
        let clave = 0;
        const conClave = el => Object.assign({ etq: '', puestos: null, mesa_id: null, rot: 0 }, el, { k: ++clave });
        const st = {
            plano: ini.datos.plano, els: ini.datos.elementos.map(conClave), sinUbicar: ini.datos.sin_ubicar || [], resumen: ini.datos.resumen || {},
            sel: null, deshacer: [], sucio: false, guardando: false, cambiosDurante: false, conflicto: false, vb: null,
        };
        const A = st.plano.ancho, L = st.plano.largo;
        const esEvento = st.plano.tipo === 'evento';
        const editable = !!cfg.editable;

        // ------------------------------------------------------------ dibujo base
        const svg = el$('svg', { class: 'pl-svg', role: 'application', 'aria-label': 'Plano del salón: ' + st.plano.nombre, preserveAspectRatio: 'xMidYMid meet' });
        const defs = el$('defs', {}, svg);
        const pat = el$('pattern', { id: 'pl-pista-ed', width: 1, height: 1, patternUnits: 'userSpaceOnUse' }, defs);
        el$('rect', { width: 1, height: 1, fill: '#EFE4CF' }, pat);
        el$('rect', { width: 0.5, height: 0.5, fill: '#E4D4B4' }, pat);
        el$('rect', { x: 0.5, y: 0.5, width: 0.5, height: 0.5, fill: '#E4D4B4' }, pat);
        const fondo = el$('g', { class: 'pl-fondo' }, svg);
        el$('rect', { x: -0.06, y: -0.06, width: A + 0.12, height: L + 0.12, fill: '#FBF9F8', stroke: '#4A1E2A', 'stroke-width': 0.12, 'data-fondo': 1 }, fondo);
        const rejilla = el$('g', { 'pointer-events': 'none' }, fondo);
        for (let x = 1; x < A; x++) el$('line', { x1: x, y1: 0, x2: x, y2: L, stroke: x % 5 ? '#EFE8E9' : '#E0D3D6', 'stroke-width': x % 5 ? 0.025 : 0.045 }, rejilla);
        for (let y = 1; y < L; y++) el$('line', { x1: 0, y1: y, x2: A, y2: y, stroke: y % 5 ? '#EFE8E9' : '#E0D3D6', 'stroke-width': y % 5 ? 0.025 : 0.045 }, rejilla);
        const cota = (x, y, txt, rot) => { const t = el$('text', { x, y, 'text-anchor': 'middle', 'font-size': 0.42, fill: '#8C8286', 'font-family': 'DM Sans, Arial, sans-serif', 'pointer-events': 'none' }, fondo); if (rot) t.setAttribute('transform', `rotate(${rot} ${x} ${y})`); t.textContent = txt; };
        cota(A / 2, -0.35, m(A) + ' m', 0);
        cota(-0.35, L / 2, m(L) + ' m', -90);
        const capa = el$('g', { class: 'pl-capa' }, svg);
        lienzo.appendChild(svg);

        // ------------------------------------------------------------ vista (zoom y movimiento)
        const ajustar = () => { st.vb = { x: -MARGEN, y: -MARGEN, w: A + 2 * MARGEN, h: L + 2 * MARGEN }; aplicarVista(); };
        const aplicarVista = () => svg.setAttribute('viewBox', `${redondear(st.vb.x, 3)} ${redondear(st.vb.y, 3)} ${redondear(st.vb.w, 3)} ${redondear(st.vb.h, 3)}`);
        /** Metros por pixel en pantalla (con preserveAspectRatio "meet"). */
        const escala = () => { const r = svg.getBoundingClientRect(); return Math.max(st.vb.w / Math.max(1, r.width), st.vb.h / Math.max(1, r.height)); };
        const aSvg = (cx, cy) => { const p = svg.createSVGPoint(); p.x = cx; p.y = cy; const mtx = svg.getScreenCTM(); return mtx ? p.matrixTransform(mtx.inverse()) : { x: 0, y: 0 }; };
        function zoom(f, cx, cy) {
            const maxW = (A + 2 * MARGEN) * 2.5, minW = 3;
            const nw = Math.min(maxW, Math.max(minW, st.vb.w / f));
            const g = st.vb.w / nw;
            if (cx === undefined) { cx = st.vb.x + st.vb.w / 2; cy = st.vb.y + st.vb.h / 2; }
            st.vb = { x: cx - (cx - st.vb.x) / g, y: cy - (cy - st.vb.y) / g, w: nw, h: st.vb.h / g };
            aplicarVista();
        }

        // ------------------------------------------------------------ estado de las mesas y colores
        function estadoMesa(el) {
            if (!esEvento) return 'mesa';
            const o = el.ocupados || 0, c = el.puestos || 0;
            return o <= 0 ? 'vacia' : (o > c ? 'pasa' : (o === c ? 'llena' : 'parcial'));
        }
        const ESTADOS_TXT = { vacia: 'Vacía', parcial: 'Con lugar', llena: 'Llena', pasa: 'Se pasa' };
        /** Medidas que ocupa en el piso (si está girada 90°, se cruzan). */
        function caja(el, extra = 0) {
            const r = ((el.rot % 180) + 180) % 180;
            let w = el.an, hh = el.al;
            if (r === 90) [w, hh] = [hh, w];
            else if (r !== 0) { const a = r * Math.PI / 180; w = Math.abs(el.an * Math.cos(a)) + Math.abs(el.al * Math.sin(a)); hh = Math.abs(el.an * Math.sin(a)) + Math.abs(el.al * Math.cos(a)); }
            const s = esMesa(el.tipo) && el.puestos <= 24 ? 0.5 : 0;
            return { w: w + 2 * (s + extra), h: hh + 2 * (s + extra) };
        }
        /** Mantiene el elemento dentro del salón. */
        function dentro(el) {
            const c = caja(el);
            el.x = c.w >= A ? A / 2 : Math.min(A - c.w / 2, Math.max(c.w / 2, el.x));
            el.y = c.h >= L ? L / 2 : Math.min(L - c.h / 2, Math.max(c.h / 2, el.y));
            el.x = redondear(Math.min(A, Math.max(0, el.x)), 2);
            el.y = redondear(Math.min(L, Math.max(0, el.y)), 2);
        }

        // ------------------------------------------------------------ dibujar elementos
        function dibujarElemento(el) {
            const g = el$('g', { class: 'pl-el' + (st.sel === el ? ' pl-sel' : ''), 'data-k': el.k });
            mover(g, el);
            const mesa = esMesa(el.tipo);
            const est = mesa ? estadoMesa(el) : el.tipo;
            const [rell, borde, tinta] = C[est] || C.mesa;
            const an = el.an, al = el.al;
            // zona para tocar (un poco más grande que el elemento)
            const cj = { w: an + (mesa ? 1 : 0.3), h: al + (mesa ? 1 : 0.3) };
            el$('rect', { x: -cj.w / 2, y: -cj.h / 2, width: cj.w, height: cj.h, fill: 'transparent', class: 'pl-toque' }, g);
            if (mesa) sillas(el.tipo, an, al, el.puestos || 0).forEach(([x, y]) => el$('circle', { cx: redondear(x, 3), cy: redondear(y, 3), r: 0.2, fill: '#fff', stroke: '#B9AEB1', 'stroke-width': 0.04 }, g));
            if (el.tipo === 'redonda') el$('circle', { r: an / 2, fill: rell, stroke: borde, 'stroke-width': 0.07, class: 'pl-forma' }, g);
            else if (el.tipo !== 'texto') el$('rect', { x: -an / 2, y: -al / 2, width: an, height: al, rx: el.tipo === 'columna' ? 0 : 0.08, fill: el.tipo === 'pista' ? 'url(#pl-pista-ed)' : rell, stroke: borde, 'stroke-width': 0.06, class: 'pl-forma' }, g);
            const texto = mesa ? corta(el.etq) : (el.etq || (el.tipo === 'columna' ? '' : T[el.tipo].corto));
            if (texto) {
                const lado = el.tipo === 'texto' ? al : Math.min(an, al);
                let tam = Math.max(0.22, Math.min(mesa ? 0.62 : 0.55, lado * (mesa ? 0.36 : 0.42)));
                if (!mesa && el.tipo !== 'texto') tam = Math.min(tam, an / Math.max(1, texto.length) * 1.7);
                let txt = texto;
                if (mesa) { if (txt.length > 14) txt = txt.slice(0, 13).trimEnd() + '…'; tam = Math.max(0.16, Math.min(tam, an * 0.95 / Math.max(1, txt.length * 0.56))); }
                if (el.tipo === 'texto') tam = Math.min(0.9, Math.max(0.3, al * 0.6));
                const conCuenta = mesa && esEvento;
                const t = el$('text', { x: 0, y: redondear(conCuenta ? -tam * 0.15 : tam * 0.35, 3), 'text-anchor': 'middle', 'font-size': redondear(tam, 3), 'font-weight': 700, fill: tinta, 'font-family': 'DM Sans, Arial, sans-serif', 'pointer-events': 'none' }, g);
                const giro = giroTexto(el.tipo, el.rot || 0);
                if (giro) t.setAttribute('transform', `rotate(${giro})`);
                t.textContent = txt.length > 40 ? txt.slice(0, 39) + '…' : txt;
                if (conCuenta) {
                    const t2 = el$('text', { x: 0, y: redondear(tam * 0.95, 3), 'text-anchor': 'middle', 'font-size': redondear(tam * 0.62, 3), fill: tinta, 'font-family': 'DM Sans, Arial, sans-serif', 'pointer-events': 'none' }, g);
                    if (giro) t2.setAttribute('transform', `rotate(${giro})`);
                    t2.textContent = (el.ocupados || 0) + '/' + (el.puestos || 0);
                }
            }
            if (st.sel === el) {
                const s = { w: an + (mesa && el.puestos <= 24 ? 1.05 : 0.35), h: al + (mesa && el.puestos <= 24 ? 1.05 : 0.35) };
                const marco = { fill: 'none', stroke: '#D4A857', 'stroke-width': 0.09, 'stroke-dasharray': '0.25 0.15', class: 'pl-marco', 'pointer-events': 'none' };
                if (el.tipo === 'redonda') el$('circle', Object.assign({ r: s.w / 2 }, marco), g);
                else el$('rect', Object.assign({ x: -s.w / 2, y: -s.h / 2, width: s.w, height: s.h, rx: 0.15 }, marco), g);
            }
            return g;
        }
        const mover = (g, el) => g.setAttribute('transform', `translate(${redondear(el.x, 3)} ${redondear(el.y, 3)})` + (el.rot ? ` rotate(${el.rot})` : ''));
        function dibujar() {
            capa.textContent = '';
            // lo grande (pista, tarima) abajo; las mesas y el texto encima
            const orden = el => (['pista', 'tarima', 'banos'].includes(el.tipo) ? 0 : (el.tipo === 'texto' ? 3 : (esMesa(el.tipo) ? 2 : 1)));
            st.els.slice().sort((a, b) => orden(a) - orden(b)).forEach(el => capa.appendChild(dibujarElemento(el)));
            const s = st.sel;
            if (s && flotante) {
                flotante.hidden = false;
                flotante.querySelector('[data-pl-flotante-nombre]').textContent = (esMesa(s.tipo) ? (s.etq || T[s.tipo].corto) : (s.etq || T[s.tipo].nombre));
            } else if (flotante) flotante.hidden = true;
            if (btnDeshacer) btnDeshacer.disabled = !st.deshacer.length;
            resumen();
        }

        // ------------------------------------------------------------ cambios, deshacer y guardado
        const foto = () => JSON.stringify(st.els);
        function recordar(antes) {
            st.deshacer.push(antes ?? foto());
            if (st.deshacer.length > MAX_DESHACER) st.deshacer.shift();
        }
        function deshacer() {
            if (!st.deshacer.length) return;
            const k = st.sel ? st.sel.k : null;
            st.els = JSON.parse(st.deshacer.pop());
            st.sel = st.els.find(e => e.k === k) || null;
            cambio(true);
        }
        let reloj = null;
        function cambio(conPanel = true) {
            if (!editable) return;
            st.sucio = true;
            if (st.guardando) st.cambiosDurante = true;
            estado('Cambios sin guardar…', 'pendiente');
            clearTimeout(reloj);
            if (!st.conflicto) reloj = setTimeout(guardar, 900);
            dibujar();
            if (conPanel) dibujarPanel();
        }
        function estado(txt, tipo) {
            if (!estadoTxt) return;
            estadoTxt.textContent = txt;
            estadoTxt.className = 'pl-estado' + (tipo ? ' pl-estado-' + tipo : '');
        }
        function mensaje(txt, tipo = 'aviso', boton = null) {
            if (!mensajes) return;
            const p = h('p', { class: 'pl-mensaje pl-mensaje-' + tipo, role: tipo === 'error' ? 'alert' : 'status' });
            p.appendChild(h('span', {}, txt));
            if (boton) p.appendChild(boton);
            const x = h('button', { type: 'button', class: 'pl-mensaje-cerrar', 'aria-label': 'Cerrar' }, '×');
            x.addEventListener('click', () => p.remove());
            p.appendChild(x);
            mensajes.appendChild(p);
            if (tipo !== 'error') setTimeout(() => p.remove(), 9000);
        }
        const limpio = el => ({ id: el.id || null, tipo: el.tipo, x: el.x, y: el.y, an: el.an, al: el.al, rot: el.rot || 0, etq: el.etq || '', puestos: esMesa(el.tipo) ? el.puestos : null, mesa_id: el.mesa_id || null });
        async function guardar() {
            clearTimeout(reloj);
            if (!editable || st.guardando || !st.sucio || st.conflicto) return;
            st.guardando = true; st.cambiosDurante = false; st.sucio = false;
            const enviados = st.els.slice();
            const cuerpo = new URLSearchParams({ accion: 'guardar', csrf: cfg.csrf, version: String(st.plano.version), elementos: JSON.stringify(enviados.map(limpio)) });
            estado('Guardando…', 'guardando');
            let reintentar = false;
            try {
                const r = await fetch(cfg.guardar, { method: 'POST', body: cuerpo, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                let d = null;
                try { d = await r.json(); } catch (e) { d = null; }
                if (!d) throw new Error('red');
                if (!d.ok) {
                    st.sucio = true;
                    if (d.conflicto) {
                        st.conflicto = true;
                        const b = h('button', { type: 'button', class: 'boton boton-chico' }, 'Recargar');
                        b.addEventListener('click', () => { st.sucio = false; location.reload(); });
                        mensaje(d.error, 'error', b);
                    } else mensaje(d.error || 'No se pudo guardar.', 'error');
                    estado('No se guardó', 'error');
                    return;
                }
                st.plano.version = d.plano.version;
                const mapa = {};
                d.elementos.forEach((de, i) => {
                    const el = enviados[i];
                    if (!el) return;
                    el.id = de.id; el.mesa_id = de.mesa_id;
                    if ('ocupados' in de) { el.ocupados = de.ocupados; el.invitados = de.invitados; }
                    if (!st.cambiosDurante) { el.etq = de.etq; el.puestos = de.puestos; }
                    mapa[el.k] = { id: de.id, mesa_id: de.mesa_id };
                });
                // Las copias para deshacer también conocen los números nuevos
                st.deshacer = st.deshacer.map(s => JSON.stringify(JSON.parse(s).map(e => (mapa[e.k] ? Object.assign(e, mapa[e.k]) : e))));
                st.sinUbicar = d.sin_ubicar || [];
                st.resumen = d.resumen || st.resumen;
                (d.avisos || []).forEach(a => mensaje(a, 'aviso'));
                estado(st.sucio ? 'Cambios sin guardar…' : 'Guardado ✓', st.sucio ? 'pendiente' : 'ok');
                dibujar();
                if (!panelOcupado()) dibujarPanel();
            } catch (e) {
                st.sucio = true; reintentar = true;
                estado('Sin conexión: no se guardó', 'error');
                mensaje('No se pudo guardar (revisa el internet). Lo intento otra vez en unos segundos.', 'error');
            } finally {
                st.guardando = false;
                if (st.sucio && !st.conflicto && (st.cambiosDurante || reintentar)) { clearTimeout(reloj); reloj = setTimeout(guardar, reintentar ? 5000 : 600); }
            }
        }
        const panelOcupado = () => panel.contains(document.activeElement) && /^(INPUT|TEXTAREA)$/.test(document.activeElement.tagName);
        window.addEventListener('beforeunload', e => { if (editable && (st.sucio || st.guardando) && !st.conflicto) { e.preventDefault(); e.returnValue = ''; } });

        // ------------------------------------------------------------ agregar, duplicar, borrar, girar
        function siguienteMesa() {
            let max = 0;
            st.els.concat(st.sinUbicar).forEach(e => { const r = /^mesa\s+(\d{1,4})$/i.exec((e.etq || '').trim()); if (r) max = Math.max(max, +r[1]); });
            return 'Mesa ' + (max + 1);
        }
        function choca(el, otro) {
            const a = caja(el, 0.15), b = caja(otro, 0.15);
            return Math.abs(el.x - otro.x) * 2 < a.w + b.w && Math.abs(el.y - otro.y) * 2 < a.h + b.h;
        }
        /** Busca un lugar libre cerca de (cx, cy), en espiral. */
        function lugarLibre(el, cx, cy) {
            for (let r = 0; r <= 40; r++) {
                const pasos = r === 0 ? 1 : 8 * r;
                for (let i = 0; i < pasos; i++) {
                    const a = 2 * Math.PI * i / pasos;
                    el.x = ajustarPaso(cx + Math.cos(a) * r); el.y = ajustarPaso(cy + Math.sin(a) * r);
                    const c = caja(el);
                    if (el.x - c.w / 2 < 0 || el.y - c.h / 2 < 0 || el.x + c.w / 2 > A || el.y + c.h / 2 > L) continue;
                    if (!st.els.some(o => o !== el && choca(el, o))) return;
                }
            }
            el.x = cx; el.y = cy; dentro(el);
        }
        function centroVista() {
            const cx = Math.min(A, Math.max(0, st.vb.x + st.vb.w / 2)), cy = Math.min(L, Math.max(0, st.vb.y + st.vb.h / 2));
            return [ajustarPaso(cx), ajustarPaso(cy)];
        }
        function agregar(tipo) {
            if (st.els.length >= MAX_ELEMENTOS) { mensaje('Un plano puede tener hasta ' + MAX_ELEMENTOS + ' elementos.', 'error'); return; }
            const t = T[tipo];
            const el = conClave({ id: null, tipo, x: 0, y: 0, an: Math.min(t.an, A), al: Math.min(t.al, L), rot: 0, etq: '', puestos: t.puestos || null, mesa_id: null, ocupados: 0, invitados: [] });
            if (esMesa(tipo)) el.etq = tipo === 'imperial' && !st.els.some(e => /honor/i.test(e.etq || '')) ? 'Mesa de honor' : siguienteMesa();
            if (tipo === 'texto') el.etq = 'Texto';
            recordar();
            const [cx, cy] = centroVista();
            lugarLibre(el, cx, cy);
            st.els.push(el);
            st.sel = el;
            cambio();
        }
        function ponerMesa(mu, cx, cy) {
            const tipo = mu.puestos > 12 ? 'imperial' : 'redonda';
            const t = T[tipo];
            const el = conClave({ id: null, tipo, x: 0, y: 0, an: t.an, al: t.al, rot: 0, etq: mu.etq, puestos: mu.puestos, mesa_id: mu.mesa_id, ocupados: mu.ocupados, invitados: mu.invitados || [] });
            lugarLibre(el, cx, cy);
            st.els.push(el);
            st.sinUbicar = st.sinUbicar.filter(x => x.mesa_id !== mu.mesa_id);
            return el;
        }
        function duplicar() {
            const s = st.sel;
            if (!s) return;
            if (st.els.length >= MAX_ELEMENTOS) { mensaje('Un plano puede tener hasta ' + MAX_ELEMENTOS + ' elementos.', 'error'); return; }
            recordar();
            const el = conClave(Object.assign({}, s, { id: null, mesa_id: null, ocupados: 0, invitados: [] }));
            if (esMesa(el.tipo)) el.etq = siguienteMesa();
            lugarLibre(el, s.x + caja(s).w, s.y);
            st.els.push(el);
            st.sel = el;
            cambio();
        }
        function borrar() {
            const s = st.sel;
            if (!s) return;
            if (esEvento && esMesa(s.tipo) && (s.ocupados || 0) > 0
                && !confirm(`${s.etq} tiene ${s.ocupados} ${s.ocupados === 1 ? 'persona sentada' : 'personas sentadas'}. Si la quitas del plano, la mesa y sus invitados siguen en la lista (en "Mesas sin ubicar"). ¿Quitarla del plano?`)) return;
            recordar();
            st.els = st.els.filter(e => e !== s);
            st.sel = null;
            cambio();
        }
        function girar(g) {
            const s = st.sel;
            if (!s) return;
            recordar();
            s.rot = ((s.rot || 0) + g + 360) % 360;
            dentro(s);
            cambio();
        }

        // ------------------------------------------------------------ panel lateral
        function campoNumero(etq, valor, alCambiar, op = {}) {
            const id = 'pl-c-' + Math.random().toString(36).slice(2, 8);
            const caja = h('div', { class: 'campo pl-campo' });
            caja.appendChild(h('label', { for: id }, etq));
            const fila = h('div', { class: 'pl-num' });
            const inp = h('input', { type: 'text', id, inputmode: op.entero ? 'numeric' : 'decimal', value: op.entero ? String(valor) : m(valor) });
            const aplicar = v => { const n = numero(v); if (n === null) { inp.value = op.entero ? String(valor) : m(valor); return; } alCambiar(n); };
            inp.addEventListener('change', () => aplicar(inp.value));
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
            if (op.paso) {
                const menos = h('button', { type: 'button', class: 'pl-btn pl-btn-icono', 'aria-label': 'Menos ' + etq.toLowerCase() }, '−');
                const mas = h('button', { type: 'button', class: 'pl-btn pl-btn-icono', 'aria-label': 'Más ' + etq.toLowerCase() }, '+');
                menos.addEventListener('click', () => aplicar(String((numero(inp.value) ?? valor) - op.paso)));
                mas.addEventListener('click', () => aplicar(String((numero(inp.value) ?? valor) + op.paso)));
                fila.append(menos, inp, mas);
            } else fila.appendChild(inp);
            if (op.unidad) fila.appendChild(h('span', { class: 'pl-unidad' }, op.unidad));
            caja.appendChild(fila);
            return caja;
        }
        function listaInvitados(el) {
            const caja = h('div', { class: 'pl-sentados' });
            const est = estadoMesa(el);
            caja.appendChild(h('p', { class: 'pl-chip pl-chip-' + est }, `${el.ocupados || 0} de ${el.puestos} sentados · ${ESTADOS_TXT[est]}`));
            const lista = el.invitados || [];
            if (!el.mesa_id) caja.appendChild(h('p', { class: 'suave' }, 'Mesa nueva: al guardarse, aparece en invitados para sentar a la gente.'));
            else if (!lista.length) caja.appendChild(h('p', { class: 'suave' }, 'Nadie sentado todavía.'));
            else {
                const ul = h('ul', { class: 'pl-lista-inv' });
                lista.forEach(i => { const li = h('li', {}, i.n); if (i.c > 1) li.appendChild(h('small', {}, ' (' + i.c + ')')); if (i.p) li.appendChild(h('small', { class: 'suave', title: 'Todavía no confirma' }, ' · por confirmar')); ul.appendChild(li); });
                caja.appendChild(ul);
            }
            if (cfg.invitados) caja.appendChild(h('a', { href: cfg.invitados, class: 'pl-enlace' }, 'Sentar invitados →'));
            return caja;
        }
        function dibujarPanel() {
            panel.textContent = '';
            const s = st.sel;
            if (s) {
                const t = T[s.tipo];
                const cab = h('div', { class: 'pl-panel-cab' });
                cab.appendChild(h('h2', {}, esMesa(s.tipo) ? (s.etq || t.nombre) : (s.etq || t.nombre)));
                cab.appendChild(h('span', { class: 'suave' }, t.nombre));
                panel.appendChild(cab);
                if (editable) {
                    const id = 'pl-etq';
                    const c = h('div', { class: 'campo pl-campo' });
                    c.appendChild(h('label', { for: id }, esMesa(s.tipo) ? 'Nombre de la mesa' : (s.tipo === 'texto' ? 'Texto' : 'Nombre (opcional)')));
                    const inp = h('input', { type: 'text', id, maxlength: '60', value: s.etq || '', placeholder: t.corto });
                    let antes = null;
                    inp.addEventListener('focus', () => { antes = foto(); });
                    inp.addEventListener('input', () => { s.etq = inp.value; dibujar(); });
                    inp.addEventListener('change', () => { if (antes !== null) recordar(antes); antes = null; s.etq = inp.value.trim(); cambio(false); });
                    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
                    c.appendChild(inp);
                    panel.appendChild(c);
                    const fila = h('div', { class: 'pl-panel-fila' });
                    if (esMesa(s.tipo)) fila.appendChild(campoNumero('Puestos', s.puestos, n => { recordar(); s.puestos = Math.max(1, Math.min(100, Math.round(n))); dentro(s); cambio(); }, { entero: true, paso: 1 }));
                    const maxLado = Math.min(50, Math.max(A, L));
                    const medida = n => Math.max(0.2, Math.min(maxLado, redondear(n, 2)));
                    if (s.tipo === 'redonda') fila.appendChild(campoNumero('Diámetro', s.an, n => { recordar(); s.an = s.al = medida(n); dentro(s); cambio(); }, { unidad: 'm' }));
                    else {
                        fila.appendChild(campoNumero('Ancho', s.an, n => { recordar(); s.an = medida(n); dentro(s); cambio(); }, { unidad: 'm' }));
                        fila.appendChild(campoNumero('Fondo', s.al, n => { recordar(); s.al = medida(n); dentro(s); cambio(); }, { unidad: 'm' }));
                    }
                    panel.appendChild(fila);
                    const acc = h('div', { class: 'pl-panel-acciones' });
                    const b = (txt, f, clase = 'boton boton-claro boton-chico') => { const x = h('button', { type: 'button', class: clase }, txt); x.addEventListener('click', f); acc.appendChild(x); };
                    b('⟲ Girar', () => girar(-45));
                    b('Girar ⟳', () => girar(45));
                    b('Duplicar', duplicar);
                    b('Borrar', borrar, 'boton boton-peligro boton-chico');
                    panel.appendChild(acc);
                    panel.appendChild(h('p', { class: 'suave pl-ayuda' }, `Girado ${s.rot || 0}° · ${m(s.an)} × ${m(s.al)} m. Arrástralo para moverlo.`));
                } else {
                    const d = h('p', { class: 'suave' }, `${m(s.an)} × ${m(s.al)} m` + (esMesa(s.tipo) ? ` · ${s.puestos} puestos` : ''));
                    panel.appendChild(d);
                }
                if (esEvento && esMesa(s.tipo)) panel.appendChild(listaInvitados(s));
                return;
            }
            // Nada elegido: ayuda, mesas sin ubicar y colores
            const ayuda = h('div', { class: 'pl-panel-ayuda' });
            ayuda.appendChild(h('h2', {}, editable ? 'Cómo se usa' : 'Plano'));
            ayuda.appendChild(h('p', {}, editable
                ? 'Toca “Agregar” para poner mesas, pista, tarima y más. Arrastra cada cosa con el dedo o el mouse; tócala para cambiarle el nombre, los puestos o girarla. Se guarda solo.'
                : 'Toca una mesa para ver quiénes están sentados. Pellizca o usa los botones para acercar.'));
            panel.appendChild(ayuda);
            if (esEvento && st.sinUbicar.length) {
                const caja = h('div', { class: 'pl-sin-ubicar' });
                caja.appendChild(h('h3', {}, `Mesas sin ubicar (${st.sinUbicar.length})`));
                caja.appendChild(h('p', { class: 'suave' }, 'Están en invitados pero todavía no en el plano.'));
                const ul = h('ul', {});
                st.sinUbicar.forEach(mu => {
                    const li = h('li', {});
                    li.appendChild(h('span', {}, `${mu.etq} · ${mu.ocupados}/${mu.puestos}`));
                    if (editable) {
                        const b = h('button', { type: 'button', class: 'boton boton-claro boton-chico' }, 'Poner');
                        b.addEventListener('click', () => { recordar(); const [cx, cy] = centroVista(); st.sel = ponerMesa(mu, cx, cy); cambio(); });
                        li.appendChild(b);
                    }
                    ul.appendChild(li);
                });
                caja.appendChild(ul);
                if (editable && st.sinUbicar.length > 1) {
                    const b = h('button', { type: 'button', class: 'boton boton-chico boton-ancho' }, 'Ubicar todas');
                    b.addEventListener('click', () => { recordar(); st.sinUbicar.slice().forEach(mu => ponerMesa(mu, 2, 2)); st.sel = null; cambio(); });
                    caja.appendChild(b);
                }
                panel.appendChild(caja);
            }
            if (esEvento) {
                const ley = h('div', { class: 'pl-leyenda', 'aria-label': 'Colores de las mesas' });
                ['vacia', 'parcial', 'llena', 'pasa'].forEach(k => ley.appendChild(h('span', { class: 'pl-chip pl-chip-' + k }, ESTADOS_TXT[k])));
                panel.appendChild(ley);
            } else {
                const r = h('p', { class: 'suave' });
                const mesas = st.els.filter(e => esMesa(e.tipo));
                r.textContent = `${mesas.length} mesas · ${mesas.reduce((a, e) => a + (e.puestos || 0), 0)} puestos · ${st.els.length} elementos`;
                panel.appendChild(r);
            }
        }
        function resumen() {
            const caja = document.querySelector('[data-pl-resumen]');
            if (!caja || !esEvento) return;
            const mesas = st.els.filter(e => esMesa(e.tipo));
            const puestos = mesas.reduce((a, e) => a + (e.puestos || 0), 0);
            const pon = (k, v) => { const n = caja.querySelector(`[data-pl-r="${k}"]`); if (n) n.textContent = v; };
            pon('puestos', puestos); pon('mesas', mesas.length);
            pon('confirmados', st.resumen.confirmados ?? 0); pon('esperados', st.resumen.esperados ?? 0); pon('sin_mesa', st.resumen.sin_mesa ?? 0);
            const sm = caja.querySelector('[data-pl-r-caja="sin_mesa"]');
            if (sm) sm.classList.toggle('pl-r-alerta', (st.resumen.sin_mesa ?? 0) > 0);
            const bal = document.querySelector('[data-pl-balance]');
            if (bal) {
                const esp = st.resumen.esperados ?? 0;
                const dif = puestos - esp;
                bal.className = 'pl-balance ' + (dif < 0 ? 'pl-balance-falta' : 'suave');
                bal.textContent = !esp ? 'Todavía no hay invitados en la lista.' : (dif < 0 ? `Faltan ${-dif} puestos para los ${esp} invitados esperados.` : (dif === 0 ? 'Los puestos alcanzan justo para los invitados esperados.' : `Sobran ${dif} puestos para los ${esp} invitados esperados.`));
            }
        }
        function elegir(el) {
            if (st.sel === el) return;
            st.sel = el;
            dibujar();
            dibujarPanel();
        }

        // ------------------------------------------------------------ dedo y mouse
        const punteros = new Map();
        let gesto = null;   // {modo: 'mover'|'paneo'|'pellizco', ...}
        svg.addEventListener('pointerdown', e => {
            if (e.button !== undefined && e.button > 0) return;
            punteros.set(e.pointerId, { x: e.clientX, y: e.clientY });
            try { svg.setPointerCapture(e.pointerId); } catch (x) { /* nada */ }
            if (punteros.size === 2) {
                if (gesto && gesto.modo === 'mover' && gesto.movio) { Object.assign(gesto.el, gesto.origen); mover(gesto.nodo, gesto.el); }
                const [p1, p2] = [...punteros.values()];
                const c = aSvg((p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
                gesto = { modo: 'pellizco', dist: Math.hypot(p1.x - p2.x, p1.y - p2.y), vb: Object.assign({}, st.vb), centro: c, cpx: { x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 } };
                return;
            }
            if (punteros.size > 2) return;
            const nodo = e.target.closest && e.target.closest('.pl-el');
            const el = nodo ? st.els.find(x => x.k === +nodo.dataset.k) : null;
            if (el) {
                e.preventDefault();
                elegir(el);
                const nuevoNodo = capa.querySelector(`[data-k="${el.k}"]`);
                const p = aSvg(e.clientX, e.clientY);
                gesto = editable ? { modo: 'mover', el, nodo: nuevoNodo, inicio: p, ix: e.clientX, iy: e.clientY, origen: { x: el.x, y: el.y }, antes: foto(), movio: false } : { modo: 'paneo', ix: e.clientX, iy: e.clientY, vb: Object.assign({}, st.vb), movio: false, tocado: el };
            } else {
                gesto = { modo: 'paneo', ix: e.clientX, iy: e.clientY, vb: Object.assign({}, st.vb), movio: false };
            }
        });
        svg.addEventListener('pointermove', e => {
            if (!punteros.has(e.pointerId)) return;
            punteros.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (!gesto) return;
            if (gesto.modo === 'pellizco' && punteros.size >= 2) {
                const [p1, p2] = [...punteros.values()];
                const d = Math.hypot(p1.x - p2.x, p1.y - p2.y);
                const f = d / Math.max(10, gesto.dist);
                st.vb = Object.assign({}, gesto.vb);
                zoom(f, gesto.centro.x, gesto.centro.y);
                // y se mueve con los dedos
                const s = escala();
                st.vb.x -= ((p1.x + p2.x) / 2 - gesto.cpx.x) * s;
                st.vb.y -= ((p1.y + p2.y) / 2 - gesto.cpx.y) * s;
                aplicarVista();
                return;
            }
            const dx = e.clientX - gesto.ix, dy = e.clientY - gesto.iy;
            if (!gesto.movio && Math.hypot(dx, dy) < 4) return;
            gesto.movio = true;
            if (gesto.modo === 'mover') {
                const p = aSvg(e.clientX, e.clientY);
                const el = gesto.el;
                el.x = ajustarPaso(gesto.origen.x + (p.x - gesto.inicio.x));
                el.y = ajustarPaso(gesto.origen.y + (p.y - gesto.inicio.y));
                dentro(el);
                mover(gesto.nodo, el);
                lienzo.classList.add('pl-moviendo');
            } else if (gesto.modo === 'paneo') {
                const s = escala();
                st.vb.x = gesto.vb.x - dx * s;
                st.vb.y = gesto.vb.y - dy * s;
                aplicarVista();
                lienzo.classList.add('pl-paneando');
            }
        });
        const soltar = e => {
            if (!punteros.has(e.pointerId)) return;
            punteros.delete(e.pointerId);
            if (!gesto) return;
            if (gesto.modo === 'pellizco') { if (punteros.size === 0) gesto = null; return; }
            if (gesto.modo === 'mover' && gesto.movio && (gesto.el.x !== gesto.origen.x || gesto.el.y !== gesto.origen.y)) {
                recordar(gesto.antes);
                cambio(false);
            } else if (gesto.modo === 'paneo' && !gesto.movio) {
                if (gesto.tocado) elegir(gesto.tocado); else if (st.sel) { st.sel = null; dibujar(); dibujarPanel(); }
            }
            lienzo.classList.remove('pl-moviendo', 'pl-paneando');
            gesto = null;
        };
        svg.addEventListener('pointerup', soltar);
        svg.addEventListener('pointercancel', soltar);
        svg.addEventListener('wheel', e => {
            e.preventDefault();
            const p = aSvg(e.clientX, e.clientY);
            zoom(e.deltaY < 0 ? 1.15 : 1 / 1.15, p.x, p.y);
        }, { passive: false });

        // ------------------------------------------------------------ botones y teclado
        raiz.addEventListener('click', e => {
            const b = e.target.closest('[data-pl-accion], [data-pl-agregar]');
            if (!b || !raiz.contains(b)) return;
            if (b.dataset.plAgregar) { agregar(b.dataset.plAgregar); return; }
            switch (b.dataset.plAccion) {
                case 'deshacer': deshacer(); break;
                case 'acercar': zoom(1.3); break;
                case 'alejar': zoom(1 / 1.3); break;
                case 'ajustar': ajustar(); break;
                case 'guardar': if (!st.sucio) { st.sucio = true; } guardar(); break;
                case 'girar-izq': girar(-45); break;
                case 'girar-der': girar(45); break;
                case 'duplicar': duplicar(); break;
                case 'borrar': borrar(); break;
                case 'soltar': st.sel = null; dibujar(); dibujarPanel(); break;
            }
        });
        document.addEventListener('keydown', e => {
            const a = document.activeElement;
            if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return;
            if (editable && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); deshacer(); return; }
            if (!st.sel) return;
            if (e.key === 'Escape') { st.sel = null; dibujar(); dibujarPanel(); return; }
            if (!editable) return;
            if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); borrar(); return; }
            const mov = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
            if (mov) {
                e.preventDefault();
                recordar();
                const p = e.shiftKey ? 0.1 : PASO;
                st.sel.x = redondear(st.sel.x + mov[0] * p); st.sel.y = redondear(st.sel.y + mov[1] * p);
                dentro(st.sel);
                cambio(false);
            }
        });

        // ------------------------------------------------------------ arranque
        ajustar();
        dibujar();
        dibujarPanel();
        raiz.classList.add('pl-listo');
        raiz.plano = { estado: st, guardar };   // para las pruebas en el navegador
    }

    listo(() => document.querySelectorAll('[data-pl-editor]').forEach(iniciar));
})();
