/*
 * /home/myzonaco/smartpark.myzona360.com/assets/js/sp_offline.js
 *
 * SmartPark Offline Library
 *   - IndexedDB para guardar lecturas pendientes (con fotos como Blob)
 *   - Cola de sincronización al endpoint /api/lecturas_batch
 *   - Detección online/offline
 *
 * Uso:
 *   await SPOffline.init();
 *   await SPOffline.guardar(lectura);
 *   await SPOffline.sincronizar();
 *   SPOffline.onCambio(function(stats){ ... });
 */

window.SPOffline = (function () {
    var DB_NAME = 'smartpark_offline';
    var DB_VERSION = 1;
    var STORE = 'lecturas_pendientes';
    var db = null;
    var listeners = [];

    function abrirDB() {
        return new Promise(function (resolve, reject) {
            if (db) return resolve(db);
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function (e) {
                var d = e.target.result;
                if (!d.objectStoreNames.contains(STORE)) {
                    var s = d.createObjectStore(STORE, { keyPath: 'local_id', autoIncrement: true });
                    s.createIndex('estado', 'estado', { unique: false });
                    s.createIndex('revista_id', 'revista_id', { unique: false });
                }
            };
            req.onsuccess = function (e) { db = e.target.result; resolve(db); };
            req.onerror = function (e) { reject(e.target.error); };
        });
    }

    function tx(mode) {
        return abrirDB().then(function (d) {
            return d.transaction([STORE], mode).objectStore(STORE);
        });
    }

    async function init() {
        await abrirDB();
        // Registrar service worker
        if ('serviceWorker' in navigator) {
            try {
                var reg = await navigator.serviceWorker.register('/sw.js');
                navigator.serviceWorker.addEventListener('message', function (event) {
                    if (event.data && event.data.type === 'sync-now') {
                        sincronizar().catch(function(){});
                    }
                });
            } catch (e) {
                console.warn('SW no registrado:', e);
            }
        }
        // Auto-sync cuando vuelve la conexión
        window.addEventListener('online', function () { sincronizar().catch(function(){}); });
        // Auto-sync cada minuto si hay online
        setInterval(function () {
            if (navigator.onLine) sincronizar().catch(function(){});
        }, 60000);
        emitirCambio();
    }

    async function guardar(lectura) {
        // lectura: { revista_id, celda, placa, foto_blob, celda_vacia, placa_manual, fuente, nivel, observaciones }
        var store = await tx('readwrite');
        var registro = Object.assign({}, lectura, {
            estado: 'pendiente',
            creado_en: new Date().toISOString(),
            intentos: 0,
        });
        return new Promise(function (resolve, reject) {
            var req = store.add(registro);
            req.onsuccess = function () {
                emitirCambio();
                // Intentar sync inmediato si hay conexión
                if (navigator.onLine) sincronizar().catch(function(){});
                resolve(req.result);
            };
            req.onerror = function () { reject(req.error); };
        });
    }

    async function listar(estado) {
        var store = await tx('readonly');
        return new Promise(function (resolve, reject) {
            var resultado = [];
            var req = store.openCursor();
            req.onsuccess = function (e) {
                var cursor = e.target.result;
                if (cursor) {
                    if (!estado || cursor.value.estado === estado) resultado.push(cursor.value);
                    cursor.continue();
                } else {
                    resolve(resultado);
                }
            };
            req.onerror = function () { reject(req.error); };
        });
    }

    async function eliminar(localId) {
        var store = await tx('readwrite');
        return new Promise(function (resolve, reject) {
            var req = store.delete(localId);
            req.onsuccess = function () { emitirCambio(); resolve(); };
            req.onerror = function () { reject(req.error); };
        });
    }

    async function actualizar(localId, cambios) {
        var store = await tx('readwrite');
        return new Promise(function (resolve, reject) {
            var get = store.get(localId);
            get.onsuccess = function () {
                var reg = get.result;
                if (!reg) return resolve();
                Object.assign(reg, cambios);
                var put = store.put(reg);
                put.onsuccess = function () { emitirCambio(); resolve(); };
                put.onerror = function () { reject(put.error); };
            };
            get.onerror = function () { reject(get.error); };
        });
    }

    var syncEnCurso = false;
    async function sincronizar() {
        if (syncEnCurso || !navigator.onLine) return { ok: false, motivo: 'sin conexión o sync en curso' };
        syncEnCurso = true;
        try {
            var pendientes = await listar('pendiente');
            if (pendientes.length === 0) { syncEnCurso = false; emitirCambio(); return { ok: true, sincronizadas: 0 }; }

            // Obtener CSRF token actual
            var csrfToken = obtenerCsrf();
            var sincronizadas = 0;
            var errores = 0;

            // Subir una por una (más seguro con fotos grandes)
            for (var i = 0; i < pendientes.length; i++) {
                var p = pendientes[i];
                try {
                    var fd = new FormData();
                    fd.append('csrf_token', csrfToken);
                    fd.append('_csrf', csrfToken);
                    fd.append('local_id', p.local_id);
                    fd.append('revista_id', p.revista_id || '');
                    fd.append('celda', p.celda || '');
                    fd.append('placa', p.placa || '');
                    fd.append('celda_vacia', p.celda_vacia ? 1 : 0);
                    fd.append('placa_manual', p.placa_manual ? 1 : 0);
                    fd.append('fuente', p.fuente || 'revista');
                    fd.append('nivel', p.nivel || '');
                    fd.append('observaciones', p.observaciones || '');
                    fd.append('creado_en_local', p.creado_en || '');
                    if (p.foto_blob) {
                        fd.append('foto', p.foto_blob, 'celda_' + p.celda + '.jpg');
                    }

                    var res = await fetch('/api/lecturas_batch', {
                        method: 'POST', body: fd, credentials: 'same-origin',
                        headers: { 'X-CSRF-Token': csrfToken }
                    });
                    var txt = await res.text();
                    var data;
                    try { data = JSON.parse(txt); } catch (e) { data = { ok: false, error: txt.substring(0, 100) }; }

                    if (data.ok) {
                        await eliminar(p.local_id);
                        sincronizadas++;
                    } else {
                        await actualizar(p.local_id, {
                            intentos: (p.intentos || 0) + 1,
                            ultimo_error: data.error || 'desconocido'
                        });
                        errores++;
                    }
                } catch (e) {
                    await actualizar(p.local_id, {
                        intentos: (p.intentos || 0) + 1,
                        ultimo_error: String(e.message || e)
                    });
                    errores++;
                }
            }
            emitirCambio();
            return { ok: true, sincronizadas: sincronizadas, errores: errores, total: pendientes.length };
        } finally {
            syncEnCurso = false;
        }
    }

    function obtenerCsrf() {
        var inp = document.querySelector(
            '#csrfHolder input[type="hidden"], ' +
            'input[name="_csrf"], input[name="csrf_token"], input[name="_token"], ' +
            'input[name^="csrf"]'
        );
        return inp ? inp.value : '';
    }

    async function stats() {
        var pendientes = await listar('pendiente');
        return {
            pendientes: pendientes.length,
            online: navigator.onLine,
            con_errores: pendientes.filter(function (p) { return (p.intentos || 0) > 0; }).length,
        };
    }

    function emitirCambio() {
        stats().then(function (s) {
            listeners.forEach(function (fn) { try { fn(s); } catch (e) {} });
        });
    }

    function onCambio(fn) {
        listeners.push(fn);
        // Emitir estado actual
        stats().then(fn);
    }

    return {
        init: init,
        guardar: guardar,
        listar: listar,
        sincronizar: sincronizar,
        eliminar: eliminar,
        stats: stats,
        onCambio: onCambio,
    };
})();
