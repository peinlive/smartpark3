/* /assets/js/sp_snapshot.js
 * SmartPark — Snapshot completo del conjunto en IndexedDB (v4d)
 *
 * Permite que /consultas funcione SIN SEÑAL. Cachea:
 *   vehiculos, visitantes, apartamentos, residentes, celdas
 *
 * Y replica las 4 busquedas de /consultas contra la copia local:
 *   buscarPlaca()      buscarApto()      buscarResidente()      buscarCelda()
 *
 * NO reemplaza a /consultas. Es la fuente de datos de /consultas_offline,
 * un modulo aparte. /consultas sigue intacto.
 */
window.SPSnap = (function () {
  'use strict';

  var DB_NAME = 'smartpark_snapshot';
  // v2: se agregaron observaciones, revistas y niveles.
  // SUBIR LA VERSION ES OBLIGATORIO: si no, el navegador no dispara
  // onupgradeneeded, los stores nuevos NO se crean, y cualquier
  // transaction() sobre ellos revienta con
  //   "One of the specified object stores was not found"
  var DB_VER  = 3;   // v7.28: +store 'cuartos'
  var STORES  = ['vehiculos', 'visitantes', 'apartamentos', 'residentes', 'celdas',
                 'observaciones', 'revistas', 'niveles', 'cuartos'];
  var META    = 'meta';

  var db = null;
  var mem = null;          // copia en RAM: las busquedas son instantaneas

  function abrir() {
    return new Promise(function (res, rej) {
      if (db) return res(db);
      var rq = indexedDB.open(DB_NAME, DB_VER);
      rq.onupgradeneeded = function (e) {
        var d = e.target.result;
        // Crea solo los stores que faltan. Los que ya existen (con sus datos)
        // se dejan intactos.
        STORES.forEach(function (s) {
          if (!d.objectStoreNames.contains(s)) {
            d.createObjectStore(s, { keyPath: 'id' });
          }
        });
        if (!d.objectStoreNames.contains(META)) {
          d.createObjectStore(META, { keyPath: 'k' });
        }
      };
      rq.onsuccess = function (e) { db = e.target.result; res(db); };
      rq.onerror   = function (e) { rej(e.target.error); };
    });
  }

  function init() { return abrir().then(function () { return cargarMem(); }); }

  /* ---------- descarga (necesita red) ---------- */
  function sincronizar(url) {
    url = url || window.API_SNAPSHOT || '/consultas/api_snapshot';
    if (!navigator.onLine) {
      return Promise.reject(new Error('Sin conexión: no se puede sincronizar'));
    }
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (d) {
        if (!d || !d.ok) throw new Error('Respuesta inválida');
        return abrir().then(function (dbi) {
          return new Promise(function (res, rej) {
            // Solo los stores que realmente existan en esta BD.
            var vivos = STORES.filter(function (s) {
              return dbi.objectStoreNames.contains(s);
            });
            if (!dbi.objectStoreNames.contains(META)) {
              return rej(new Error('IndexedDB desactualizada. Recarga con Ctrl+Shift+R.'));
            }
            var t = dbi.transaction(vivos.concat([META]), 'readwrite');
            vivos.forEach(function (s) {
              var os = t.objectStore(s);
              os.clear();
              (d[s] || []).forEach(function (row) {
                if (row && row.id != null) os.put(row);
              });
            });
            t.objectStore(META).put({
              k: 'info',
              version: d.version || Date.now(),
              bajado: Date.now(),
              totales: d.totales || {},
              avisos: d.avisos || []
            });
            t.oncomplete = function () {
              mem = null;
              cargarMem().then(function () { res({ ok: true, totales: d.totales }); });
            };
            t.onerror = function (e) { rej(e.target.error); };
          });
        });
      });
  }

  /* ---------- cargar todo a memoria (busquedas instantaneas) ---------- */
  function cargarMem() {
    if (mem) return Promise.resolve(mem);
    return abrir().then(function (dbi) {
      var acc = {};
      return Promise.all(STORES.map(function (s) {
        return new Promise(function (res) {
          // Si el store no existe (BD vieja), devolvemos vacio en vez de
          // tirar la excepcion que rompia toda la pagina.
          if (!dbi.objectStoreNames.contains(s)) { acc[s] = []; return res(); }
          var out = [];
          try {
            var rq = dbi.transaction(s, 'readonly').objectStore(s).openCursor();
            rq.onsuccess = function (e) {
              var c = e.target.result;
              if (c) { out.push(c.value); c.continue(); }
              else { acc[s] = out; res(); }
            };
            rq.onerror = function () { acc[s] = []; res(); };
          } catch (err) {
            acc[s] = []; res();
          }
        });
      })).then(function () { mem = acc; return acc; });
    });
  }

  function norm(s) {
    return (s || '').toString().toUpperCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '');   // quita tildes
  }

  /* ═══════════ LAS 4 BUSQUEDAS (identicas a /consultas) ═══════════ */

  /* 1) por PLACA — coincidencia parcial, min 2 caracteres */
  function buscarPlaca(q) {
    q = norm(q).replace(/[^A-Z0-9]/g, '');
    if (q.length < 2) return Promise.resolve({ vehiculos: [], visitantes: [] });
    return cargarMem().then(function (m) {
      var veh = m.vehiculos.filter(function (v) {
        return v.placa && v.placa.indexOf(q) >= 0;
      }).sort(function (a, b) {
        if (a.archivado !== b.archivado) return a.archivado - b.archivado;  // activos primero
        return a.placa < b.placa ? -1 : 1;
      }).slice(0, 20).map(function (v) {
        // v7.29: adjuntar celdas y cuartos del apto del vehículo
        v._celdas  = (m.celdas  || []).filter(function (c) { return c.dueno_id === v.apto_id || c.uso_id === v.apto_id; });
        v._cuartos = (m.cuartos || []).filter(function (c) { return c.dueno_id === v.apto_id || c.uso_id === v.apto_id; });
        return v;
      });

      var vis = m.visitantes.filter(function (v) {
        return v.placa && v.placa.indexOf(q) >= 0;
      }).sort(function (a, b) {
        if (a.archivado !== b.archivado) return a.archivado - b.archivado;
        return (b.ultima || '') < (a.ultima || '') ? -1 : 1;
      }).slice(0, 20);

      return { vehiculos: veh, visitantes: vis };
    });
  }

  /* 2) por APARTAMENTO — prefijo. Devuelve el apto + sus residentes + sus vehiculos */
  function buscarApto(q) {
    q = norm(q).trim();
    if (!q) return Promise.resolve([]);
    return cargarMem().then(function (m) {
      var aptos = m.apartamentos.filter(function (a) {
        return norm(a.apto).indexOf(q) === 0;
      }).slice(0, 20);

      return aptos.map(function (a) {
        return {
          apto: a,
          residentes: m.residentes.filter(function (r) { return r.apto_id === a.id && r.activo; }),
          vehiculos:  m.vehiculos.filter(function (v) { return v.apto_id === a.id && !v.archivado; }),
          visitantes: m.visitantes.filter(function (v) { return v.apto_id === a.id && !v.archivado; }),
          // v7.28: celdas y cuartos del apto (dueño o usuario)
          celdas:  (m.celdas  || []).filter(function (c) { return c.dueno_id === a.id || c.uso_id === a.id; }),
          cuartos: (m.cuartos || []).filter(function (c) { return c.dueno_id === a.id || c.uso_id === a.id; })
        };
      });
    });
  }

  /* 3) por RESIDENTE — nombre o celular */
  function buscarResidente(q) {
    q = norm(q).trim();
    if (q.length < 2) return Promise.resolve([]);
    // v7.42: por PALABRAS. "juan gomez" encuentra "Juan Jose Gomez"
    var palabras = q.split(/\s+/).filter(function (p) { return p; });
    var soloNum = q.replace(/\D/g, '');
    return cargarMem().then(function (m) {
      return m.residentes.filter(function (r) {
        var nom = norm(r.nombre);
        var todas = palabras.every(function (p) { return nom.indexOf(p) >= 0; });
        var porCel = soloNum && (r.celular || '').indexOf(soloNum) >= 0;
        return todas || porCel;
      }).slice(0, 20).map(function (r) {
        return {
          residente: r,
          vehiculos: m.vehiculos.filter(function (v) {
            return v.apto_id === r.apto_id && !v.archivado;
          })
        };
      });
    });
  }

  /* 4) por CELDA — nombre de la celda */
  function buscarCelda(q) {
    q = norm(q).trim();
    if (!q) return Promise.resolve([]);
    return cargarMem().then(function (m) {
      return m.celdas.filter(function (c) {
        return norm(c.celda).indexOf(q) >= 0;
      }).slice(0, 20).map(function (c) {
        // vehiculos del apto que USA la celda (o del dueño si no hay asignación)
        var aptoUso = c.usuario || c.dueno;
        var apto = m.apartamentos.filter(function (a) { return a.apto === aptoUso; })[0];
        return {
          celda: c,
          apto: apto || null,
          vehiculos: apto ? m.vehiculos.filter(function (v) {
            return v.apto_id === apto.id && !v.archivado;
          }) : []
        };
      });
    });
  }

  /* 4b) por CUARTO ÚTIL — código del cuarto (espejo de buscarCelda) */
  function buscarCuarto(q) {
    q = norm(q).trim();
    if (!q) return Promise.resolve([]);
    return cargarMem().then(function (m) {
      return (m.cuartos || []).filter(function (c) {
        return norm(c.cuarto).indexOf(q) >= 0;
      }).slice(0, 20).map(function (c) {
        var aptoUso = c.usuario || c.dueno;
        var apto = m.apartamentos.filter(function (a) { return a.apto === aptoUso; })[0];
        return {
          cuarto: c,
          apto: apto || null,
          vehiculos: apto ? m.vehiculos.filter(function (v) {
            return v.apto_id === apto.id && !v.archivado;
          }) : []
        };
      });
    });
  }

  /* ---------- listados completos (para los modulos) ---------- */
  function todos(store, filtro) {
    return cargarMem().then(function (m) {
      var l = m[store] || [];
      return filtro ? l.filter(filtro) : l;
    });
  }

  /* Borra la BD entera. Ultimo recurso si quedo en un estado raro. */
  function resetear() {
    return new Promise(function (res) {
      if (db) { try { db.close(); } catch (e) {} db = null; }
      mem = null;
      var rq = indexedDB.deleteDatabase(DB_NAME);
      rq.onsuccess = function () { res(true); };
      rq.onerror   = function () { res(false); };
      rq.onblocked = function () { res(false); };
    });
  }

  /* observaciones de un vehiculo (historial) */
  function obsDeVehiculo(vehId) {
    return cargarMem().then(function (m) {
      return (m.observaciones || []).filter(function (o) { return o.veh_id === vehId; });
    });
  }

  /* revistas: solo las en curso son ejecutables offline */
  function revistas(estado) {
    return cargarMem().then(function (m) {
      var l = m.revistas || [];
      return estado ? l.filter(function (r) { return r.estado === estado; }) : l;
    });
  }

  /* celdas de un nivel (para ejecutar una revista) */
  function celdasDeNivel(nivelId) {
    return cargarMem().then(function (m) {
      return (m.celdas || []);   // el snapshot trae todas; el nivel se filtra en la UI
    });
  }

  /* ---------- lista de placas para el OCR ---------- */
  function placas() {
    return cargarMem().then(function (m) {
      var out = [];
      m.vehiculos.forEach(function (v) { if (v.placa && !v.archivado) out.push(v.placa); });
      m.visitantes.forEach(function (v) { if (v.placa && !v.archivado) out.push(v.placa); });
      return out;
    });
  }

  /* ---------- estado ---------- */
  function estado() {
    return abrir().then(function (dbi) {
      return new Promise(function (res) {
        var rq = dbi.transaction(META, 'readonly').objectStore(META).get('info');
        rq.onsuccess = function () {
          var i = rq.result;
          if (!i) return res({ vacio: true, totales: {} });
          res({
            vacio: false,
            version: i.version,
            bajado: i.bajado,
            edadHoras: i.bajado ? Math.round((Date.now() - i.bajado) / 36e5) : null,
            totales: i.totales || {},
            avisos: i.avisos || []
          });
        };
        rq.onerror = function () { res({ vacio: true, totales: {} }); };
      });
    });
  }

  function autoSync(horasMax) {
    horasMax = horasMax || 12;
    return estado().then(function (e) {
      var viejo = e.vacio || (e.edadHoras !== null && e.edadHoras >= horasMax);
      if (!viejo || !navigator.onLine) return e;
      return sincronizar().then(function () { return estado(); }).catch(function () { return e; });
    });
  }

  return {
    init:            init,
    sincronizar:     sincronizar,
    autoSync:        autoSync,
    estado:          estado,
    placas:          placas,
    buscarPlaca:     buscarPlaca,
    buscarApto:      buscarApto,
    buscarResidente: buscarResidente,
    buscarCelda:     buscarCelda,
    buscarCuarto:    buscarCuarto,
    todos:           todos,
    resetear:        resetear,
    obsDeVehiculo:   obsDeVehiculo,
    revistas:        revistas,
    celdasDeNivel:   celdasDeNivel,
    mem:             function () { return cargarMem(); }
  };
})();
