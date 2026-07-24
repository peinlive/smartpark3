/* /assets/js/sp_cola.js
 * SmartPark — Cola de escritura OFFLINE (v4b)
 *
 * PROBLEMA QUE RESUELVE:
 *   "Guardar y siguiente" hace fetch a api_guardar_paso. Sin señal, el fetch
 *   muere y el trabajo de la ronda SE PIERDE.
 *
 * SOLUCION:
 *   Todo guardado pasa por aqui. Si hay red -> va directo al servidor.
 *   Si NO hay red -> se encola en IndexedDB (con la foto como Blob) y se
 *   reintenta solo cuando vuelve la conexion.
 *
 * POR QUE ES SEGURO REINTENTAR:
 *   api_guardar_paso es IDEMPOTENTE: hace UPDATE si el par (revista,celda) ya
 *   existe, INSERT si no. Reenviar el mismo item dos veces no duplica nada.
 *
 * MODOS DE SINCRONIZACION (config del usuario):
 *   'auto'   -> sincroniza sola apenas hay red
 *   'manual' -> solo cuando el usuario pulsa el boton
 *   'wifi'   -> solo con WiFi (no gasta datos moviles)
 *
 * API:
 *   SPCola.init()
 *   SPCola.enviar(url, campos, opts)   -> Promise. Encola si no hay red.
 *   SPCola.sincronizar()               -> fuerza el envio de lo pendiente
 *   SPCola.pendientes()                -> cuantos items hay en cola
 *   SPCola.onCambio(cb)                -> notifica cambios (para la UI)
 *   SPCola.config({modo:'auto'|'manual'|'wifi'})
 */
window.SPCola = (function () {
  'use strict';

  var DB_NAME = 'smartpark_cola';
  var DB_VER  = 1;
  var STORE   = 'pendientes';
  var CFG_KEY = 'sp_cola_cfg';

  var db = null;
  var sincronizando = false;
  var listeners = [];
  var timer = null;

  /* ---------- configuracion ---------- */
  function cfg(nueva) {
    if (nueva) {
      try { localStorage.setItem(CFG_KEY, JSON.stringify(nueva)); } catch (e) {}
      return nueva;
    }
    try {
      var c = JSON.parse(localStorage.getItem(CFG_KEY) || '{}');
      return { modo: c.modo || 'auto' };
    } catch (e) {
      return { modo: 'auto' };
    }
  }

  /* ¿Puedo sincronizar ahora, segun el modo elegido? */
  function puedeSync() {
    if (!navigator.onLine) return false;
    var m = cfg().modo;
    if (m === 'manual') return false;
    if (m === 'wifi') {
      var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      // Si el navegador no expone el tipo de red, asumimos que SI (mejor sincronizar
      // que perder el trabajo). Solo bloqueamos si sabemos con certeza que es celular.
      if (c && c.type && c.type === 'cellular') return false;
    }
    return true;
  }

  /* ---------- IndexedDB ---------- */
  function abrir() {
    return new Promise(function (res, rej) {
      if (db) return res(db);
      var rq = indexedDB.open(DB_NAME, DB_VER);
      rq.onupgradeneeded = function (e) {
        var d = e.target.result;
        if (!d.objectStoreNames.contains(STORE)) {
          var s = d.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
          s.createIndex('creado', 'creado', { unique: false });
          s.createIndex('clave',  'clave',  { unique: false });
        }
      };
      rq.onsuccess = function (e) { db = e.target.result; res(db); };
      rq.onerror   = function (e) { rej(e.target.error); };
    });
  }

  function emitir() {
    pendientes().then(function (n) {
      listeners.forEach(function (f) {
        try { f({ pendientes: n, online: navigator.onLine, modo: cfg().modo }); } catch (e) {}
      });
    });
  }

  /* ---------- encolar ----------
     'clave' permite REEMPLAZAR un item previo del mismo destino.
     Ej: si la ronda corrige la celda C98102 tres veces sin señal, no queremos
     3 items en cola: queremos el ultimo. Igual el servidor es idempotente,
     pero asi la cola no crece al pedo y la sync es mas rapida. */
  function encolar(url, campos, clave) {
    return abrir().then(function (d) {
      return new Promise(function (res, rej) {
        var t = d.transaction(STORE, 'readwrite');
        var s = t.objectStore(STORE);

        var guardar = function () {
          var rq = s.add({
            url: url,
            campos: campos,
            clave: clave || null,
            creado: Date.now(),
            intentos: 0,
            error: null
          });
          rq.onsuccess = function () { res({ encolado: true, id: rq.result }); };
          rq.onerror   = function () { rej(rq.error); };
        };

        if (!clave) return guardar();

        // borrar el item anterior con la misma clave (si existe)
        var idx = s.index('clave');
        var cur = idx.openCursor(IDBKeyRange.only(clave));
        cur.onsuccess = function (e) {
          var c = e.target.result;
          if (c) { c.delete(); c.continue(); }
          else   { guardar(); }
        };
        cur.onerror = function () { guardar(); };
      });
    }).then(function (r) { emitir(); return r; });
  }

  function listar() {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var out = [];
        var rq = d.transaction(STORE, 'readonly').objectStore(STORE)
                  .index('creado').openCursor();   // FIFO: lo mas viejo primero
        rq.onsuccess = function (e) {
          var c = e.target.result;
          if (c) { out.push(c.value); c.continue(); }
          else res(out);
        };
        rq.onerror = function () { res([]); };
      });
    });
  }

  function borrar(id) {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var rq = d.transaction(STORE, 'readwrite').objectStore(STORE).delete(id);
        rq.onsuccess = function () { res(true); };
        rq.onerror   = function () { res(false); };
      });
    });
  }

  function marcarError(item, msg) {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var s = d.transaction(STORE, 'readwrite').objectStore(STORE);
        item.intentos = (item.intentos || 0) + 1;
        item.error = msg;
        s.put(item);
        res(true);
      });
    });
  }

  function pendientes() {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var rq = d.transaction(STORE, 'readonly').objectStore(STORE).count();
        rq.onsuccess = function () { res(rq.result); };
        rq.onerror   = function () { res(0); };
      });
    }).catch(function () { return 0; });
  }

  /* ---------- envio real al servidor ---------- */
  function postear(url, campos) {
    var fd = new FormData();
    Object.keys(campos).forEach(function (k) { fd.append(k, campos[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (txt) {
          var d = null;
          try { d = JSON.parse(txt); } catch (e) { d = { ok: false, error: 'Respuesta no-JSON' }; }
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return d;
        });
      });
  }

  /* ---------- API: enviar (con fallback a cola) ---------- */
  /**
   * @param url     endpoint (ej: window.API_GUARDAR)
   * @param campos  objeto plano {revista_id:1, celda_id:2, ...}
   * @param opts    { clave:'rev1-celda2', forzarCola:false }
   * @returns {Promise<{ok, encolado?, ...}>}
   */
  function enviar(url, campos, opts) {
    opts = opts || {};

    // Sin red -> directo a la cola. No perdemos nada.
    if (!navigator.onLine || opts.forzarCola) {
      return encolar(url, campos, opts.clave).then(function (r) {
        return { ok: true, encolado: true, id: r.id, offline: true };
      });
    }

    // Con red -> intentar directo. Si falla la RED, encolar.
    return postear(url, campos)
      .then(function (d) {
        // El servidor respondio. Si dice que no (duplicada, CSRF, validacion...),
        // NO encolamos: es un error de negocio, reintentar no lo arregla.
        return d;
      })
      .catch(function (e) {
        // Fallo de red / servidor caido -> a la cola
        console.warn('SPCola: fallo el envio directo, encolando.', e.message);
        return encolar(url, campos, opts.clave).then(function (r) {
          return { ok: true, encolado: true, id: r.id, offline: true, motivo: e.message };
        });
      });
  }

  /* ---------- sincronizacion ---------- */
  function sincronizar(forzar) {
    if (sincronizando) return Promise.resolve({ ok: false, motivo: 'ya en curso' });
    if (!forzar && !puedeSync()) {
      return Promise.resolve({ ok: false, motivo: 'modo ' + cfg().modo + ' o sin red' });
    }
    if (!navigator.onLine) return Promise.resolve({ ok: false, motivo: 'sin red' });

    sincronizando = true;
    var enviadas = 0, fallidas = 0;

    return listar().then(function (items) {
      if (!items.length) {
        sincronizando = false;
        return { ok: true, enviadas: 0, pendientes: 0 };
      }

      // Secuencial a proposito: en un cPanel compartido, 400 requests en
      // paralelo desde el celular es la forma mas rapida de que te tumben.
      var i = 0;
      function paso() {
        if (i >= items.length) return Promise.resolve();
        var it = items[i++];
        return postear(it.url, it.campos)
          .then(function (d) {
            if (d && d.ok === false && !d.duplicada) {
              // error de negocio: no sirve reintentar para siempre
              if ((it.intentos || 0) >= 3) {
                fallidas++;
                return borrar(it.id);       // se descarta tras 3 intentos
              }
              fallidas++;
              return marcarError(it, d.error || 'rechazado');
            }
            enviadas++;
            return borrar(it.id);
          })
          .catch(function (e) {
            fallidas++;
            return marcarError(it, e.message);
          })
          .then(function () {
            emitir();
            if (!navigator.onLine) return;    // se corto la red: parar
            return paso();
          });
      }

      return paso().then(function () {
        sincronizando = false;
        return pendientes().then(function (n) {
          emitir();
          return { ok: true, enviadas: enviadas, fallidas: fallidas, pendientes: n };
        });
      });
    }).catch(function (e) {
      sincronizando = false;
      return { ok: false, error: e.message };
    });
  }

  /* ---------- init ---------- */
  function init() {
    return abrir().then(function () {
      // reintento al volver la red
      window.addEventListener('online', function () {
        setTimeout(function () { sincronizar().catch(function () {}); }, 1500);
        emitir();
      });
      window.addEventListener('offline', emitir);

      // reintento periodico (por si 'online' no dispara, pasa en Android)
      if (timer) clearInterval(timer);
      timer = setInterval(function () {
        if (navigator.onLine) sincronizar().catch(function () {});
      }, 60000);

      // Background Sync del Service Worker
      if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', function (ev) {
          if (ev.data && ev.data.type === 'sync-now') {
            sincronizar().catch(function () {});
          }
        });
      }

      emitir();
      return true;
    });
  }

  function onCambio(f) { listeners.push(f); }

  return {
    init:        init,
    enviar:      enviar,
    sincronizar: sincronizar,
    pendientes:  pendientes,
    listar:      listar,
    onCambio:    onCambio,
    config:      cfg
  };
})();
