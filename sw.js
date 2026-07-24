// /home/myzonaco/smartpark.myzona360.com/sw.js
// SmartPark Service Worker — modo offline-first para revistas.
// v4a: precachea el modulo de REVISTAS y el motor OCR (antes solo cacheaba /rondas).

const CACHE_NAME = 'smartpark-v44';       // v44: refresca sp_lote (sello)
const CACHE_OCR  = 'smartpark-ocr-v1';     // cache aparte: el modelo pesa ~16 MB

// Archivos criticos para que la revista abra SIN señal.
// v5: el SHELL OFFLINE es lo primero que se cachea. Con esto solo, la ronda
// ya puede trabajar: consulta, vehiculos, residentes, parqueadero, novedades.
const PRECACHE = [
    '/offline',                            // ← v5: EL SHELL (la app completa)
    '/assets/js/sp_shell.js?v=787',        // ← v7.51: logica del shell (con version)
    '/assets/js/sp_snapshot.js?v=757',           // datos locales
    '/assets/js/sp_cola.js',               // cola de subida
    '/assets/js/sp_lote.js?v=787',               // v6: crear revistas/visitantes/novedades offline
    '/assets/ocr/sp_ocr.js',               // motor OCR
    '/assets/ocr/vendor/ort/ort.min.js',
    '/',
    '/revistas',
    '/assets/css/app.css',
    '/assets/js/sp_offline.js',
    '/assets/js/sp_padron.js',
    '/consultas_offline',
];

// Pesados: se cachean al instalar, pero si fallan NO rompen la instalacion.
// (Sin estos, el OCR local no arranca y se cae al servidor: degrada, no muere.)
const PRECACHE_OCR = [
    '/assets/ocr/models/global_mobile_vit_v2_ocr.onnx',   // 5 MB
    '/assets/ocr/vendor/ort/ort-wasm-simd.wasm',          // 10.5 MB
    '/assets/ocr/vendor/ort/ort-wasm.wasm',               // 9.7 MB (fallback sin SIMD)
];

self.addEventListener('install', function (event) {
    self.skipWaiting();
    event.waitUntil(
        Promise.all([
            caches.open(CACHE_NAME).then(function (cache) {
                return cache.addAll(PRECACHE).catch(function () {
                    return Promise.resolve();   // si alguno falla, no rompemos
                });
            }),
            // el OCR se baja en segundo plano; uno a uno para que un fallo no tumbe al resto
            caches.open(CACHE_OCR).then(function (cache) {
                return Promise.all(PRECACHE_OCR.map(function (u) {
                    return cache.add(u).catch(function () { return null; });
                }));
            })
        ])
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (k) { return k !== CACHE_NAME && k !== CACHE_OCR; })
                    .map(function (k) { return caches.delete(k); })
            );
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var url = new URL(event.request.url);

    // No interceptar APIs de escritura — deben fallar limpio y encolarse en IndexedDB.
    if (url.pathname.startsWith('/api/')) {
        return;
    }

    // ── v4a: modelo y runtime OCR -> CACHE FIRST agresivo ──
    // Son inmutables y pesan 16 MB: jamas revalidar contra la red.
    if (url.pathname.indexOf('/assets/ocr/') === 0) {
        event.respondWith(
            caches.match(event.request).then(function (hit) {
                if (hit) return hit;
                return fetch(event.request).then(function (res) {
                    if (res.ok) {
                        var copy = res.clone();
                        var store = /\.(onnx|wasm)$/.test(url.pathname) ? CACHE_OCR : CACHE_NAME;
                        caches.open(store).then(function (c) {
                            c.put(event.request, copy).catch(function(){});
                        });
                    }
                    return res;
                });
            })
        );
        return;
    }

    // Para todo lo demás: cache first, network fallback
    if (event.request.method === 'GET') {
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                if (cached) {
                    // Refresh en background
                    fetch(event.request).then(function (res) {
                        if (res.ok) {
                            caches.open(CACHE_NAME).then(function (c) {
                                c.put(event.request, res.clone()).catch(function(){});
                            });
                        }
                    }).catch(function(){});
                    return cached;
                }
                return fetch(event.request).then(function (res) {
                    if (res.ok && url.origin === location.origin) {
                        var copy = res.clone();
                        caches.open(CACHE_NAME).then(function (c) {
                            c.put(event.request, copy).catch(function(){});
                        });
                    }
                    return res;
                }).catch(function () {
                    // Offline y no en cache - mostrar mensaje
                    if (event.request.destination === 'document') {
                        return new Response(
                            '<!DOCTYPE html><meta charset="UTF-8"><title>Sin conexión</title>' +
                            '<body style="font-family:system-ui;text-align:center;padding:40px;background:#0f172a;color:#fff">' +
                            '<h1>📶 Sin conexión</h1>' +
                            '<p>Esta página no está en caché. Conéctate a WiFi para cargarla.</p>' +
                            '<p><a href="/revistas" style="color:#60a5fa">← Volver a Revistas</a></p>' +
                            '</body>',
                            { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 200 }
                        );
                    }
                    return new Response('Offline', { status: 503 });
                });
            })
        );
    }
});

// Background Sync (cuando el navegador detecta que vuelve la conexión)
self.addEventListener('sync', function (event) {
    if (event.tag === 'sp-sync-lecturas') {
        event.waitUntil(
            self.clients.matchAll().then(function (clients) {
                clients.forEach(function (c) {
                    c.postMessage({ type: 'sync-now' });
                });
            })
        );
    }
});

// Mensajes desde la página
self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'skip-waiting') {
        self.skipWaiting();
    }
    // v4a: permite forzar la descarga del modelo OCR desde la pagina
    if (event.data && event.data.type === 'precache-ocr') {
        caches.open(CACHE_OCR).then(function (c) {
            return Promise.all(PRECACHE_OCR.map(function (u) {
                return c.add(u).catch(function () { return null; });
            }));
        }).then(function () {
            self.clients.matchAll().then(function (cs) {
                cs.forEach(function (c) { c.postMessage({ type: 'ocr-cached' }); });
            });
        });
    }
});
