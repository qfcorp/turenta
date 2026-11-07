// TuRenta como app en el celular: guarda los archivos de diseño para abrir rápido
// y muestra un aviso amable si no hay internet. Las páginas siempre se piden al servidor
// (nunca se guardan datos de clientes en el celular).
const VERSION = 'turenta-v1';
const ARCHIVOS = ['/assets/icono-192.png', '/offline.html'];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(VERSION).then(c => c.addAll(ARCHIVOS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
    e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VERSION).map(k => caches.delete(k))))
        .then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
        return;
    }
    if (url.pathname.startsWith('/assets/')) {
        e.respondWith(caches.match(req).then(r => r || fetch(req).then(resp => {
            const copia = resp.clone();
            caches.open(VERSION).then(c => c.put(req, copia));
            return resp;
        })));
    }
});
