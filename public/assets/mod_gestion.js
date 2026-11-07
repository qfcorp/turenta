// TuRenta — módulo de gestión. Todo es opcional: si los elementos no están en la página, no hace nada.
document.addEventListener('DOMContentLoaded', () => {
    // Compartir un enlace con el menú del celular: <button data-compartir="https://..." data-titulo="...">
    document.querySelectorAll('[data-compartir]').forEach(b => {
        if (!navigator.share) { b.hidden = true; return; }
        b.addEventListener('click', async () => {
            try { await navigator.share({ title: b.dataset.titulo || document.title, url: b.dataset.compartir }); } catch (e) { /* cancelado */ }
        });
    });

    // Seleccionar todo el texto al tocar un campo de enlace para copiar
    document.querySelectorAll('input[data-seleccionar]').forEach(i => {
        i.addEventListener('focus', () => i.select());
    });

    // Vista previa del logo antes de subirlo
    const logo = document.querySelector('#g-logo-archivo');
    const vista = document.querySelector('#g-logo-vista');
    if (logo && vista) {
        logo.addEventListener('change', () => {
            const f = logo.files && logo.files[0];
            if (!f || !f.type.startsWith('image/')) return;
            const img = document.createElement('img');
            img.className = 'g-logo';
            img.alt = 'Vista previa del logo';
            img.src = URL.createObjectURL(f);
            vista.replaceChildren(img);
        });
    }

    // Reportes: rango de fechas solo cuando se elige "Otras fechas"
    const rango = document.querySelector('#g-rango');
    document.querySelectorAll('[data-g-rango]').forEach(a => {
        a.addEventListener('click', ev => {
            if (!rango) return;
            ev.preventDefault();
            rango.hidden = !rango.hidden;
            if (!rango.hidden) rango.querySelector('input')?.focus();
        });
    });

    // Mostrar u ocultar una contraseña: <button data-ver-clave="idDelCampo">
    document.querySelectorAll('[data-ver-clave]').forEach(b => {
        const campo = document.getElementById(b.dataset.verClave);
        if (!campo) return;
        b.addEventListener('click', () => {
            const ver = campo.type === 'password';
            campo.type = ver ? 'text' : 'password';
            b.textContent = ver ? 'Ocultar' : 'Ver';
        });
    });
});
