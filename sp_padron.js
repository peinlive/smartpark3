/* /assets/js/sp_padron.js
 * SmartPark — Padron de vehiculos OFFLINE (v4a)
 *
 * PROBLEMA QUE RESUELVE:
 *   verificarPlaca() en /revistas/ejecutar hace fetch a api_placa_lookup.
 *   Sin señal ese fetch muere y la ronda no puede verificar NADA.
 *
 * SOLUCION:
 *   Se descarga el padron completo del conjunto a IndexedDB cuando hay red,
 *   y la busqueda se hace SIEMPRE contra la copia local. El servidor queda
 *   como refuerzo opcional, no como dependencia.
 *
 * API:
 *   SPPadron.init()                  -> abre la BD
 *   SPPadron.sincronizar()           -> baja el padron (requiere red)
 *   SPPadron.buscar('PKK149')        -> {encontrada, placa, tipo, apto, vehiculo_id}
 *   SPPadron.placas()                -> ['PKK149','WCN641',...]  (para el OCR)
 *   SPPadron.sugerir('PKK')          -> autocompletado por prefijo
 *   SPPadron.estado()                -> {total, version, edadHoras}
 */
window.SPPadron = (function () {
  'use strict';

  var DB_NAME = 'smartpark_padron';
  var DB_VER  = 2;   // v4b: +ep (estado_pago) +m (meses_mora) → fuerza recrear el store
  var STORE   = 'vehiculos';
  var META    = 'meta';

  var db = null;
  var cachePlacas = null;   // array en memoria, para el OCR (evita leer IDB en cada foto)

  function abrir() {
    return new Promise(function (res, rej) {
      if (db) return res(db);
      var rq = indexedDB.open(DB_NAME, DB_VER);
      rq.onupgradeneeded = function (e) {
        var d = e.target.result;
        if (!d.objectStoreNames.contains(STORE)) {
          var s = d.createObjectStore(STORE, { keyPath: 'p' });   // p = placa
          s.createIndex('apto', 'a', { unique: false });
        }
        if (!d.objectStoreNames.contains(META)) {
          d.createObjectStore(META, { keyPath: 'k' });
        }
      };
      rq.onsuccess = function (e) { db = e.target.result; res(db); };
      rq.onerror   = function (e) { rej(e.target.error); };
    });
  }

  function init() { return abrir().then(function () { return true; }); }

  /* ---------- descarga del padron (necesita red) ---------- */
  function sincronizar(url) {
    url = url || (window.API_PADRON || '/revistas/api_padron');
    if (!navigator.onLine) {
      return Promise.reject(new Error('Sin conexion: no se puede sincronizar el padron'));
    }
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (d) {
        if (!d || !d.ok || !Array.isArray(d.vehiculos)) {
          throw new Error('Respuesta invalida del padron');
        }
        return abrir().then(function (dbi) {
          return new Promise(function (res, rej) {
            var t = dbi.transaction([STORE, META], 'readwrite');
            var s = t.objectStore(STORE);
            s.clear();                                   // reemplazo completo
            d.vehiculos.forEach(function (v) {
              if (v && v.p) s.put(v);
            });
            t.objectStore(META).put({
              k: 'info',
              version: d.version || Date.now(),
              total: d.vehiculos.length,
              bajado: Date.now()
            });
            t.oncomplete = function () {
              cachePlacas = null;                        // invalidar cache en memoria
              res({ ok: true, total: d.vehiculos.length });
            };
            t.onerror = function (e) { rej(e.target.error); };
          });
        });
      });
  }

  /* ---------- busqueda LOCAL (funciona sin red) ---------- */
  function buscar(placa) {
    placa = (placa || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (!placa) return Promise.resolve({ encontrada: false });
    return abrir().then(function (dbi) {
      return new Promise(function (res) {
        var rq = dbi.transaction(STORE, 'readonly').objectStore(STORE).get(placa);
        rq.onsuccess = function () {
          var v = rq.result;
          if (!v) return res({ encontrada: false, placa: placa });
          res({
            encontrada:  true,
            placa:       v.p,
            tipo:        v.t || '',
            apto:        v.a || '',
            vehiculo_id: v.i || 0,
            visitante:   !!v.v,
            estado_pago: v.ep || 'al_dia',   // v4b: morosidad
            meses_mora:  v.m  || 0,           // v4b: meses en mora
            _local:      true
          });
        };
        rq.onerror = function () { res({ encontrada: false, placa: placa }); };
      });
    });
  }

  /* ---------- lista de placas (la consume el OCR como padron) ---------- */
  function placas() {
    if (cachePlacas) return Promise.resolve(cachePlacas);
    return abrir().then(function (dbi) {
      return new Promise(function (res) {
        var out = [];
        var rq = dbi.transaction(STORE, 'readonly').objectStore(STORE).openCursor();
        rq.onsuccess = function (e) {
          var c = e.target.result;
          if (c) { out.push(c.key); c.continue(); }
          else   { cachePlacas = out; res(out); }
        };
        rq.onerror = function () { res([]); };
      });
    });
  }

  /* ---------- autocompletado por prefijo (para el teclado manual) ---------- */
  function sugerir(prefijo, limite) {
    prefijo = (prefijo || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    limite  = limite || 6;
    if (prefijo.length < 2) return Promise.resolve([]);
    return placas().then(function (todas) {
      var out = [];
      for (var i = 0; i < todas.length && out.length < limite; i++) {
        if (todas[i].indexOf(prefijo) === 0) out.push(todas[i]);
      }
      return out;
    });
  }

  /* ---------- estado del padron ---------- */
  function estado() {
    return abrir().then(function (dbi) {
      return new Promise(function (res) {
        var rq = dbi.transaction(META, 'readonly').objectStore(META).get('info');
        rq.onsuccess = function () {
          var m = rq.result;
          if (!m) return res({ total: 0, version: 0, edadHoras: null, vacio: true });
          res({
            total:     m.total || 0,
            version:   m.version || 0,
            bajado:    m.bajado || 0,
            edadHoras: m.bajado ? Math.round((Date.now() - m.bajado) / 36e5) : null,
            vacio:     !m.total
          });
        };
        rq.onerror = function () { res({ total: 0, vacio: true }); };
      });
    });
  }

  /* ---------- sincronizacion automatica y silenciosa ----------
     Baja el padron si esta vacio o si tiene mas de N horas.
     NUNCA rompe la pagina: si falla, se sigue con lo que haya en cache. */
  function autoSync(horasMax) {
    horasMax = horasMax || 12;
    return estado().then(function (e) {
      var viejo = e.vacio || (e.edadHoras !== null && e.edadHoras >= horasMax);
      if (!viejo || !navigator.onLine) return e;
      return sincronizar()
        .then(function () { return estado(); })
        .catch(function () { return e; });     // sin red o error -> seguimos igual
    });
  }

  return {
    init:        init,
    sincronizar: sincronizar,
    autoSync:    autoSync,
    buscar:      buscar,
    placas:      placas,
    sugerir:     sugerir,
    estado:      estado
  };
})();
