/* /assets/js/sp_lote.js
 * SmartPark — Cola de LOTE offline (v6)
 *
 * Permite CREAR cosas sin señal (no solo actualizar):
 *   - revistas nuevas
 *   - vehiculos / visitantes nuevos
 *   - pasos de revista con foto
 *   - novedades con fotos
 *   - cierre de revista
 *
 * COMO RESUELVE EL PROBLEMA DE LOS IDs:
 *   Sin señal no hay AUTO_INCREMENT. Entonces el celular genera IDs locales
 *   ("loc-a3f9c1") y los usa para relacionar:
 *       revista loc-a3f9  ->  celda C98102, celda C98103, ...
 *   Al sincronizar, /api/sync_lote crea los registros reales EN ORDEN y
 *   devuelve el mapeo { "loc-a3f9": 42 }. Todo queda enlazado correctamente.
 *
 * IDEMPOTENCIA: cada item lleva un uid unico. Si el lote se reenvia (se corto
 * la red, el usuario toco Sincronizar dos veces), el servidor lo ignora.
 * NUNCA se duplican registros.
 *
 * FOTOS: se comprimen a 1280px / q0.75 (~80 KB) ANTES de encolar.
 * 400 fotos = ~30 MB en IndexedDB. Con la calidad original (228 KB) serian
 * 89 MB y Chrome empieza a cortar escrituras.
 */
window.SPLote = (function () {
  'use strict';

  var DB   = 'smartpark_lote';
  var VER  = 1;
  var COLA = 'items';
  var LOC  = 'locales';        // revistas creadas offline, aun sin id real

  /* Compresion de la EVIDENCIA (no del OCR: el OCR usa la imagen grande) */
  var FOTO_MAXW = 1280;
  var FOTO_Q    = 0.75;

  var db = null, listeners = [];

  function uid() {
    return 'loc-' + Date.now().toString(36) + '-' +
           Math.random().toString(36).slice(2, 8);
  }

  function abrir() {
    return new Promise(function (res, rej) {
      if (db) return res(db);
      var rq = indexedDB.open(DB, VER);
      rq.onupgradeneeded = function (e) {
        var d = e.target.result;
        if (!d.objectStoreNames.contains(COLA)) {
          var s = d.createObjectStore(COLA, { keyPath: 'uid' });
          s.createIndex('creado', 'creado', { unique: false });
          s.createIndex('tipo',   'tipo',   { unique: false });
          s.createIndex('clave',  'clave',  { unique: false });
        }
        if (!d.objectStoreNames.contains(LOC)) {
          d.createObjectStore(LOC, { keyPath: 'id_local' });
        }
      };
      rq.onsuccess = function (e) { db = e.target.result; res(db); };
      rq.onerror   = function (e) { rej(e.target.error); };
    });
  }

  function emitir() {
    contar().then(function (n) {
      listeners.forEach(function (f) { try { f(n); } catch (e) {} });
    });
  }

  /* ═════════ COMPRESION DE FOTOS ═════════ */
  /* Estampa fecha y hora abajo a la derecha, igual que /revistas/ejecutar.
     La evidencia SIN timestamp no sirve para auditoria: no se puede probar
     cuando se tomo. */
  function estamparFecha(ctx, W, H) {
    var d = new Date();
    var p = function (n) { return n < 10 ? '0' + n : '' + n; };
    var txt = p(d.getDate()) + '/' + p(d.getMonth()+1) + '/' + d.getFullYear() + '  ' +
              p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());

    var fs  = Math.max(18, Math.floor(W * 0.032));
    var pad = Math.floor(fs * 0.4);
    var mar = Math.floor(fs * 0.5);

    ctx.font = 'bold ' + fs + 'px monospace';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'bottom';

    var tw = ctx.measureText(txt).width;
    ctx.fillStyle = 'rgba(0,0,0,0.65)';
    ctx.fillRect(W - tw - pad*2 - mar, H - fs - pad*2 - mar, tw + pad*2, fs + pad*2);
    ctx.fillStyle = '#ffdd00';
    ctx.fillText(txt, W - pad - mar, H - pad - mar);
  }

  /* Recibe un canvas o un File/Blob. Devuelve dataURL comprimido CON timestamp. */
  function comprimir(src) {
    return new Promise(function (res, rej) {
      function desdeImg(img) {
        var w = img.naturalWidth || img.width;
        var h = img.naturalHeight || img.height;
        var e = Math.min(1, FOTO_MAXW / Math.max(w, h));
        var c = document.createElement('canvas');
        c.width  = Math.round(w * e);
        c.height = Math.round(h * e);
        var cx = c.getContext('2d');
        cx.drawImage(img, 0, 0, c.width, c.height);
        estamparFecha(cx, c.width, c.height);      // <- evidencia con fecha y hora
        res(c.toDataURL('image/jpeg', FOTO_Q));
      }
      if (src instanceof HTMLCanvasElement) {
        var e2 = Math.min(1, FOTO_MAXW / Math.max(src.width, src.height));
        var c2 = document.createElement('canvas');
        c2.width  = Math.round(src.width * e2);
        c2.height = Math.round(src.height * e2);
        var x2 = c2.getContext('2d');
        x2.drawImage(src, 0, 0, c2.width, c2.height);
        estamparFecha(x2, c2.width, c2.height);
        return res(c2.toDataURL('image/jpeg', FOTO_Q));
      }
      if (typeof src === 'string' && src.indexOf('data:image/') === 0) {
        var im = new Image();
        im.onload = function () { desdeImg(im); };
        im.onerror = function () { rej(new Error('imagen inválida')); };
        im.src = src;
        return;
      }
      if (src instanceof Blob || src instanceof File) {
        var u = URL.createObjectURL(src);
        var i2 = new Image();
        i2.onload = function () { URL.revokeObjectURL(u); desdeImg(i2); };
        i2.onerror = function () { URL.revokeObjectURL(u); rej(new Error('no se pudo leer')); };
        i2.src = u;
        return;
      }
      rej(new Error('formato no soportado'));
    });
  }

  /* ═════════ ENCOLAR ═════════ */
  function encolar(item, clave) {
    item.uid    = item.uid || uid();
    item.creado = Date.now();
    item.clave  = clave || null;

    return abrir().then(function (d) {
      return new Promise(function (res, rej) {
        var t = d.transaction(COLA, 'readwrite');
        var s = t.objectStore(COLA);

        function meter() {
          var rq = s.put(item);
          rq.onsuccess = function () { res(item); };
          rq.onerror   = function () { rej(rq.error); };
        }

        // clave -> reemplaza el item anterior del mismo destino
        // (si corriges la misma celda 3 veces sin señal, queda la ULTIMA)
        if (!clave) return meter();
        var idx = s.index('clave');
        var cur = idx.openCursor(IDBKeyRange.only(clave));
        cur.onsuccess = function (e) {
          var c = e.target.result;
          if (c) { c.delete(); c.continue(); } else { meter(); }
        };
        cur.onerror = function () { meter(); };
      });
    }).then(function (r) { emitir(); return r; });
  }

  /* ═════════ API: CREAR COSAS OFFLINE ═════════ */

  /** Revista nueva. Devuelve el id LOCAL para usarlo en los pasos. */
  function revistaNueva(nivel, totalCeldas) {
    var idl = uid();
    return abrir().then(function (d) {
      return new Promise(function (res) {
        d.transaction(LOC, 'readwrite').objectStore(LOC).put({
          id_local: idl, nivel: nivel, creado: Date.now(),
          total_celdas: totalCeldas || 0, estado: 'en_curso'
        });
        res();
      });
    }).then(function () {
      return encolar({
        tipo: 'revista_nueva',
        id_local: idl,
        nivel: nivel,
        total_celdas: totalCeldas || 0,
        creado_en: fecha()
      });
    }).then(function () { return idl; });
  }

  /** Paso de revista (celda revisada). revistaId puede ser local o real. */
  function revistaPaso(revistaId, celdaId, estado, placa, fotoSrc, vehiculoId) {
    var p = fotoSrc ? comprimir(fotoSrc) : Promise.resolve(null);
    return p.then(function (foto) {
      return encolar({
        tipo: 'revista_paso',
        revista_id: revistaId,          // "loc-..." o numero
        celda_id: celdaId,
        estado: estado,
        placa: placa || '',
        vehiculo_id: vehiculoId || null,
        foto: foto,
        creado_en: fecha()
      }, 'paso-' + revistaId + '-' + celdaId);   // una entrada por celda
    });
  }

  /** Cerrar revista.
      BUG v6.8: antes solo encolaba el cierre, pero NO marcaba la revista
      local como terminada. Resultado: seguia apareciendo "en curso" en el
      celular hasta sincronizar. */
  /** Cerrar revista.
      celdasVacias: array opcional de celdaId que no fueron revisadas.
      Se encolan como 'vacia' antes del cierre (auto-marcado offline). */
  function revistaCerrar(revistaId, celdasVacias) {
    // 1) Encolar las celdas pendientes como vacías (secuencial para IDB)
    var ids = Array.isArray(celdasVacias) ? celdasVacias : [];
    var cadena = ids.reduce(function (prom, celdaId) {
      return prom.then(function () {
        return encolar({
          tipo:        'revista_paso',
          revista_id:  revistaId,
          celda_id:    celdaId,
          estado:      'vacia',
          placa:       '',
          vehiculo_id: null,
          foto:        null,
          creado_en:   fecha()
        }, 'paso-' + revistaId + '-' + celdaId);
      });
    }, Promise.resolve());

    // 2) Después encolar el cierre y marcar local como terminada
    return cadena.then(function () {
      return encolar({
        tipo:       'revista_cerrar',
        revista_id: revistaId,
        creado_en:  fecha()
      }, 'cerrar-' + revistaId);
    }).then(function (r) {
      return abrir().then(function (d) {
        return new Promise(function (res) {
          var s = d.transaction(LOC, 'readwrite').objectStore(LOC);
          var g = s.get(revistaId);
          g.onsuccess = function () {
            var v = g.result;
            if (v) { v.estado = 'terminada'; v.terminada = Date.now(); s.put(v); }
            res(r);
          };
          g.onerror = function () { res(r); };
        });
      });
    });
  }

  /** Recuperar los datos de un paso ya guardado (para volver a verlo).
      BUG v6.9: antes usaba idx.get(clave). El indice 'clave' NO es unico,
      y IDBIndex.get() sobre un indice no-unico puede no devolver nada.
      Resultado: al volver a una celda ya revisada, la foto no aparecia.
      Ahora se recorre con cursor, que si funciona con indices no-unicos. */
  function paso(revistaId, celdaId) {
    var clave = 'paso-' + revistaId + '-' + celdaId;
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var out = null;
        try {
          var idx = d.transaction(COLA, 'readonly').objectStore(COLA).index('clave');
          var rq = idx.openCursor(IDBKeyRange.only(clave));
          rq.onsuccess = function (e) {
            var c = e.target.result;
            if (c) { out = c.value; c.continue(); }   // quedarse con el ultimo
            else res(out);
          };
          rq.onerror = function () { res(null); };
        } catch (err) {
          // fallback: recorrer toda la cola
          listar().then(function (items) {
            var m = items.filter(function (i) { return i.clave === clave; });
            res(m.length ? m[m.length - 1] : null);
          }).catch(function () { res(null); });
        }
      });
    });
  }

  /** Vehiculo nuevo (residente). Devuelve id local. */
  /* v7.76: 'rol' opcional (propietario|inquilino) para que el servidor
     asigne el vehículo al residente correcto de ese apartamento. */
  function vehiculoNuevo(placa, tipo, apartamentoId, marca, color, rol) {
    var idl = uid();
    return encolar({
      tipo: 'vehiculo_nuevo',
      id_local: idl,
      placa: placa,
      tipo_vehiculo: tipo || 'carro',
      apartamento_id: apartamentoId || 0,
      marca: marca || '',
      color: color || '',
      rol: rol || '',
      creado_en: fecha()
    }).then(function () { return idl; });
  }

  /** Visitante nuevo. Devuelve id local. */
  function visitanteNuevo(placa, tipo, apartamentoId, nombre, parentesco) {
    var idl = uid();
    return encolar({
      tipo: 'visitante_nuevo',
      id_local: idl,
      placa: placa,
      tipo_vehiculo: tipo || 'carro',
      apartamento_id: apartamentoId || 0,
      nombre_visitante: nombre || '',
      parentesco: parentesco || '',
      creado_en: fecha()
    }).then(function () { return idl; });
  }

  /** Novedad con fotos. vehiculoId puede ser local o real. */
  function novedad(vehiculoId, placa, tipoObs, gravedad, descripcion, fotos) {
    fotos = fotos || [];
    return Promise.all(fotos.map(comprimir)).then(function (fs) {
      return encolar({
        tipo: 'novedad',
        vehiculo_id: vehiculoId || null,
        placa: placa || '',
        tipo_obs: tipoObs || 'otro',
        gravedad: gravedad || 'ninguna',   // v6.6: default informativa
        descripcion: descripcion || '',
        fotos: fs.filter(Boolean),
        creado_en: fecha()
      });
    });
  }

  function fecha() {
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + ' ' +
           p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  /* ═════════ REVISTAS LOCALES (aun sin id real) ═════════ */
  function revistasLocales() {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var out = [];
        var rq = d.transaction(LOC, 'readonly').objectStore(LOC).openCursor();
        rq.onsuccess = function (e) {
          var c = e.target.result;
          if (c) { out.push(c.value); c.continue(); } else res(out);
        };
        rq.onerror = function () { res([]); };
      });
    });
  }

  /* v7.58: cancelar una revista (encola cancelación + marca local cancelada) */
  function revistaCancelar(revistaId) {
    return encolar({
      tipo:       'revista_cancelar',
      revista_id: revistaId,
      creado_en:  fecha()
    }, 'cancel-' + revistaId).then(function () {
      // marcar la revista local como cancelada
      return abrir().then(function (d) {
        return new Promise(function (res) {
          var s = d.transaction(LOC, 'readwrite').objectStore(LOC);
          var g = s.get(revistaId);
          g.onsuccess = function () {
            var v = g.result;
            if (v) { v.estado = 'cancelada'; v.terminada = Date.now(); s.put(v); }
            res(true);
          };
          g.onerror = function () { res(false); };
        });
      });
    });
  }

  /* v7.78: ¿la placa YA tiene un registro pendiente de subir?
     Devuelve null, o {tipo, apto_id, rol} para avisar al rondero
     en vez de ofrecerle registrarla otra vez. */
  function registroPendientePlaca(placa) {
    var p = String(placa || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (!p) return Promise.resolve(null);
    return listar().then(function (items) {
      var hit = null;
      items.forEach(function (i) {
        if (i.tipo !== 'vehiculo_nuevo' && i.tipo !== 'visitante_nuevo') return;
        var ip = String(i.placa || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (ip === p) {
          hit = {
            tipo:    i.tipo,                    // vehiculo_nuevo | visitante_nuevo
            apto_id: i.apartamento_id || 0,
            rol:     i.rol || '',
            nombre:  i.nombre_visitante || ''
          };
        }
      });
      return hit;
    });
  }

  /* pasos ya encolados de una revista (para pintar el progreso offline) */
  function pasosDe(revistaId) {
    return listar().then(function (items) {
      return items.filter(function (i) {
        return i.tipo === 'revista_paso' && String(i.revista_id) === String(revistaId);
      });
    });
  }

  /* ═════════ LISTAR / CONTAR ═════════ */
  function listar() {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var out = [];
        var rq = d.transaction(COLA, 'readonly').objectStore(COLA)
                  .index('creado').openCursor();
        rq.onsuccess = function (e) {
          var c = e.target.result;
          if (c) { out.push(c.value); c.continue(); } else res(out);
        };
        rq.onerror = function () { res([]); };
      });
    });
  }

  function contar() {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var rq = d.transaction(COLA, 'readonly').objectStore(COLA).count();
        rq.onsuccess = function () { res(rq.result); };
        rq.onerror   = function () { res(0); };
      });
    }).catch(function () { return 0; });
  }

  function borrar(uids) {
    return abrir().then(function (d) {
      return new Promise(function (res) {
        var t = d.transaction(COLA, 'readwrite');
        var s = t.objectStore(COLA);
        uids.forEach(function (u) { s.delete(u); });
        t.oncomplete = function () { res(true); };
        t.onerror    = function () { res(false); };
      });
    });
  }

  /* v7.72 FIX CRÍTICO: solo borrar la revista local si YA NO le quedan
     pasos/cierres en la cola. Antes se borraba apenas el servidor la creaba,
     y si sus celdas fallaban al subir, la revista DESAPARECÍA del celular
     sin haber quedado completa en el servidor (se perdía el trabajo). */
  function limpiarLocales(mapa) {
    if (!mapa) return Promise.resolve();
    var ids = Object.keys(mapa);
    if (!ids.length) return Promise.resolve();

    return listar().then(function (items) {
      // ids locales que TODAVÍA tienen algo pendiente en la cola
      var conPendientes = {};
      items.forEach(function (i) {
        var rid = i.revista_id;
        if (rid != null) conPendientes[String(rid)] = true;
        if (i.tipo === 'revista_nueva' && i.id_local != null) {
          conPendientes[String(i.id_local)] = true;
        }
      });

      var borrables = ids.filter(function (idl) { return !conPendientes[String(idl)]; });
      if (!borrables.length) return;

      return abrir().then(function (d) {
        return new Promise(function (res) {
          var t = d.transaction(LOC, 'readwrite');
          var s = t.objectStore(LOC);
          borrables.forEach(function (idl) { s.delete(idl); });
          t.oncomplete = function () { res(); };
          t.onerror    = function () { res(); };
        });
      });
    });
  }

  /* ═════════ SINCRONIZAR (envia TODO el lote) ═════════ */

  /* Pide un CSRF FRESCO al servidor.
     El token que quedo en la pagina puede tener horas de viejo (la ronda
     baja al sotano, trabaja 1 hora, vuelve). Si la sesion de PHP caduco,
     ese token da "CSRF invalido" y el lote se queda atascado PARA SIEMPRE. */
  function tokenFresco(urlCsrf, fallback) {
    if (!urlCsrf) return Promise.resolve(fallback);
    return fetch(urlCsrf, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401) {
          var e = new Error('SESION_EXPIRADA');
          e.sesion = true;
          throw e;
        }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (d) {
        if (!d || !d.ok || !d.csrf) throw new Error('token inválido');
        return d.csrf;
      })
      .catch(function (e) {
        if (e.sesion) throw e;               // sesion caida -> propagar
        return fallback;                      // otro error -> usar el viejo
      });
  }

  function sincronizar(url, csrf, urlCsrf, onProgreso) {
    onProgreso = (typeof onProgreso === 'function') ? onProgreso : function(){};
    if (!navigator.onLine) {
      return Promise.reject(new Error('Sin señal'));
    }
    return tokenFresco(urlCsrf, csrf).then(function (tok) {
      csrf = tok;
      return listar();
    }).then(function (items) {
      if (!items.length) {
        return { ok: true, procesados: 0, pendientes: 0, mapa: {} };
      }

      // El lote puede pesar (fotos). Se envia en tandas para no reventar
      // el limite de POST del hosting (suele ser 8-64 MB).
      var LIMITE = 12;                 // items por tanda
      var tandas = [];
      for (var i = 0; i < items.length; i += LIMITE) {
        tandas.push(items.slice(i, i + LIMITE));
      }

      var totProc = 0, mapaTot = {}, errs = [];
      var k = 0;
      var totalTandas = tandas.length;
      var totalItems = items.length;
      onProgreso(0, totalItems, 0, totalTandas);

      function siguiente() {
        if (k >= tandas.length) return Promise.resolve();
        var tanda = tandas[k++];

        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('csrf_token', csrf);
        fd.append('lote', JSON.stringify(tanda));

        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.error === 'CSRF inválido') {
              var e = new Error('SESION_EXPIRADA');
              e.sesion = true;
              throw e;                        // no reintentar: hay que re-loguear
            }
            if (!d || !d.ok) throw new Error((d && d.error) || 'respuesta inválida');
            totProc += d.procesados || 0;
            Object.assign(mapaTot, d.mapa || {});
            if (d.errores && d.errores.length) errs = errs.concat(d.errores);
            // borrar de la cola lo que el servidor confirmo
            return borrar(d.hechos || []);
          })
          .then(function () {
            emitir();
            // progreso: cuántas tandas van (k) de totalTandas
            onProgreso(Math.min(k * LIMITE, totalItems), totalItems, k, totalTandas);
            if (!navigator.onLine) throw new Error('Se cortó la conexión');
            return siguiente();
          });
      }

      return siguiente()
        .then(function () { return limpiarLocales(mapaTot); })
        .then(function () { return contar(); })
        .then(function (n) {
          emitir();
          return { ok: true, procesados: totProc, pendientes: n,
                   mapa: mapaTot, errores: errs };
        });
    });
  }

  function init() { return abrir().then(function () { emitir(); return true; }); }
  function onCambio(f) { listeners.push(f); }

  return {
    init:            init,
    comprimir:       comprimir,
    revistaNueva:    revistaNueva,
    revistaPaso:     revistaPaso,
    revistaCerrar:   revistaCerrar,
    revistaCancelar: revistaCancelar,
    vehiculoNuevo:   vehiculoNuevo,
    visitanteNuevo:  visitanteNuevo,
    novedad:         novedad,
    revistasLocales: revistasLocales,
    pasosDe:         pasosDe,
    registroPendientePlaca: registroPendientePlaca,
    paso:            paso,
    listar:          listar,
    contar:          contar,
    sincronizar:     sincronizar,
    onCambio:        onCambio,
    FOTO_MAXW:       FOTO_MAXW,
    FOTO_Q:          FOTO_Q
  };
})();
