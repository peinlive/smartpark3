/* /assets/js/sp_shell.js
 * SmartPark — Shell OFFLINE (v5)
 * Navegacion entre modulos SIN servidor. Todo sale de IndexedDB.
 * Un solo boton "Sincronizar": baja la BD y sube lo pendiente.
 */
(function () {
  'use strict';

  // v7.49: versión visible del JS offline (para saber si el celular
  // tomó la actualización). Subir este número en cada cambio de sp_shell.js.
  var SP_SHELL_VER = 'v7.87';
  window.SP_SHELL_VER = SP_SHELL_VER;

  // OJO: $ tiene que ser GLOBAL. Los onclick="$('inp-cam').click()" del HTML
  // se ejecutan en el scope global, no dentro de este IIFE. Sin window.$, los
  // botones de foto no hacian NADA (fallaban en silencio).
  var $ = function (id) { return document.getElementById(id); };
  window.$ = $;
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }
  function norm(s) {
    return (s||'').toString().toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  }

  /* ═════════ NAVEGACION (sin recargar, sin servidor) ═════════ */
  Array.prototype.forEach.call(document.querySelectorAll('.nav button'), function (b) {
    b.onclick = function () {
      var s = this.getAttribute('data-s');
      Array.prototype.forEach.call(document.querySelectorAll('.nav button'), function (x) {
        x.classList.toggle('act', x === b);
      });
      Array.prototype.forEach.call(document.querySelectorAll('.sec'), function (x) {
        x.classList.toggle('act', x.id === 's-' + s);
      });
      window.scrollTo(0, 0);
      if (s === 'revistas')  tabRevistas(REV_TAB);
      if (s === 'novedades') verNovedades();
    };
  });

  /* ═════════ ESTADO DE RED ═════════ */
  function pintarRed() {
    var n = $('net');
    var on = navigator.onLine;
    if (on) { n.className = 'net on';  n.textContent = '● En línea'; }
    else    { n.className = 'net off'; n.textContent = '📶 Sin señal'; }

    // El boton "App en linea" NO funciona sin señal (es server-rendered).
    // Sin este aviso, la ronda lo toca en el sotano y ve una pagina en blanco.
    var b = $('btnOnline');
    if (b) {
      b.style.opacity = on ? '1' : '.45';
      b.title = on ? 'Ir a la app completa' : 'Necesita señal';
    }
    var av = $('aviso-online');
    if (av) av.style.display = on ? 'none' : 'block';
  }

  // Interceptar el click: si no hay señal, avisar en vez de dejar
  // que quede una pagina en blanco.
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href*="dashboard"]');
    if (!a) return;
    if (!navigator.onLine) {
      e.preventDefault();
      alert('📶 Sin señal.\n\nLa app en línea necesita conexión.\n' +
            'Seguí trabajando acá; al recuperar señal pulsá Sincronizar.');
    }
  });
  window.addEventListener('online',  pintarRed);
  window.addEventListener('offline', pintarRed);

  /* v7.55: barra de progreso de sincronización en pantalla */
  function progresoMostrar() {
    var ov = document.getElementById('sp-prog-ov');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'sp-prog-ov';
      ov.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(17,24,39,.72);' +
                         'display:flex;align-items:center;justify-content:center;padding:24px';
      ov.innerHTML =
        '<div style="background:#fff;border-radius:14px;padding:22px 20px;max-width:340px;width:100%;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.3)">' +
          '<div style="font-size:34px;margin-bottom:6px">📤</div>' +
          '<div style="font-size:17px;font-weight:800;color:#111827;margin-bottom:4px">Subiendo datos…</div>' +
          '<div id="sp-prog-txt" style="font-size:13px;color:#6b7280;margin-bottom:12px">Preparando…</div>' +
          '<div style="height:14px;background:#e5e7eb;border-radius:8px;overflow:hidden">' +
            '<div id="sp-prog-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#1e6cff,#3b82f6);' +
            'border-radius:8px;transition:width .3s"></div>' +
          '</div>' +
          '<div id="sp-prog-pct" style="font-size:20px;font-weight:800;color:#1e6cff;margin-top:10px">0%</div>' +
          '<div style="font-size:11.5px;color:#92400e;background:#fef3c7;border-radius:7px;padding:7px 9px;margin-top:12px">' +
            '⚠️ No cierres ni salgas de esta pantalla hasta que termine.</div>' +
        '</div>';
      document.body.appendChild(ov);
    }
    ov.style.display = 'flex';
  }
  function progresoActualizar(hechas, total, tanda, totalTandas) {
    var pct = total > 0 ? Math.min(100, Math.round((hechas / total) * 100)) : 0;
    var bar = document.getElementById('sp-prog-bar');
    var txt = document.getElementById('sp-prog-txt');
    var pc  = document.getElementById('sp-prog-pct');
    if (bar) bar.style.width = pct + '%';
    if (pc)  pc.textContent = pct + '%';
    if (txt) txt.textContent = 'Tanda ' + (tanda || 0) + ' de ' + (totalTandas || 0) +
                               ' · ' + Math.min(hechas, total) + '/' + total + ' registros';
  }
  function progresoOcultar() {
    var ov = document.getElementById('sp-prog-ov');
    if (ov) ov.style.display = 'none';
  }

  /* ═════════ SINCRONIZAR (baja BD + sube pendientes) ═════════ */
  window.sincronizar = function () {
    if (!navigator.onLine) {
      alert('📶 Sin señal.\n\nConéctate a una red y vuelve a pulsar Sincronizar.');
      return;
    }
    var b = $('btnSync');
    b.disabled = true; b.textContent = '⏳ Sincronizando...';

    var subidas = 0, pend = 0, errs = [];

    // v7.55: barra de progreso en pantalla mientras sube (para no salir)
    progresoMostrar();

    // 1) SUBIR el lote. Se pide un CSRF FRESCO primero: el token de la pagina
    //    puede tener horas (la ronda trabaja 1h en el sotano) y la sesion de
    //    PHP pudo caducar -> "CSRF invalido" y el lote quedaba atascado.
    SPLote.sincronizar(window.API_SYNC_LOTE, window.CSRF, window.API_CSRF,
      function (hechas, total, tanda, totalTandas) {
        progresoActualizar(hechas, total, tanda, totalTandas);
      })
      .then(function (r) {
        subidas = (r && r.procesados) || 0;
        pend    = (r && r.pendientes) || 0;
        errs    = (r && r.errores) || [];
        // 2) BAJAR la BD actualizada
        return SPSnap.sincronizar();
      })
      .then(function (r) {
        var t = (r && r.totales) || {};
        pintarEstado(); pintarCola();

        var msg = '✅ Sincronizado\n\n' +
                  '⬆ Subidos: ' + subidas + ' registro(s)' +
                  (pend ? '\n⚠ Quedan pendientes: ' + pend +
                          '\n   NO borres datos del navegador:' +
                          '\n   se suben al volver a Sincronizar.' : '') + '\n\n' +
                  '⬇ Descargado:\n' +
                  '   ' + (t.vehiculos||0) + ' vehículos\n' +
                  '   ' + (t.apartamentos||0) + ' apartamentos\n' +
                  '   ' + (t.residentes||0) + ' residentes\n' +
                  '   ' + (t.celdas||0) + ' celdas\n' +
                  '   ' + (t.revistas||0) + ' revistas';

        // Mostrar el ERROR REAL del servidor, no solo "N con error".
        // Sin esto no hay forma de saber que fallo.
        if (errs.length) {
          msg += '\n\n❌ ERRORES (' + errs.length + '):\n';
          errs.slice(0, 5).forEach(function (e) {
            msg += '• ' + (e.error || JSON.stringify(e)) + '\n';
          });
          if (errs.length > 5) msg += '• … y ' + (errs.length - 5) + ' más\n';
          console.error('SPLote errores:', errs);
        }
        alert(msg);
      })
      .catch(function (e) {
        if (e && (e.sesion || e.message === 'SESION_EXPIRADA')) {
          // Nada se perdio: el lote SIGUE en el celular. Solo hay que re-loguear.
          if (confirm('🔒 La sesión expiró.\n\n' +
                      'Tu trabajo NO se perdió: sigue guardado en el celular.\n\n' +
                      '¿Iniciar sesión de nuevo para poder subirlo?')) {
            location.href = '/login?next=' + encodeURIComponent('/offline');
          }
          return;
        }
        alert('❌ Error al sincronizar:\n' + (e.message || e) +
              '\n\nTu trabajo sigue guardado en el celular.\n' +
              'Revisa la conexión y vuelve a intentar.');
      })
      .then(function () {
        b.disabled = false; b.textContent = '🔄 Sincronizar';
        progresoOcultar();
      });
  };
  $('btnSync').onclick = window.sincronizar;

  function pintarEstado() {
    SPSnap.estado().then(function (e) {
      if (e.vacio) {
        $('estado-tot').innerHTML =
          '<b style="color:#991b1b">⚠ No hay datos en el celular.</b><br>' +
          'Conéctate a WiFi y pulsa <b>Sincronizar</b>.';
        return;
      }
      var t = e.totales || {};
      $('estado-tot').innerHTML =
        '<b>' + (t.vehiculos||0) + '</b> vehículos · ' +
        '<b>' + (t.apartamentos||0) + '</b> apartamentos · ' +
        '<b>' + (t.residentes||0) + '</b> residentes<br>' +
        '<b>' + (t.celdas||0) + '</b> celdas · ' +
        '<b>' + (t.revistas||0) + '</b> revistas · ' +
        '<b>' + (t.observaciones||0) + '</b> novedades' +
        (e.edadHoras !== null
          ? '<br><span style="color:' + (e.edadHoras >= 24 ? '#92400e' : '#6b7280') + '">' +
            'Última sincronización: hace ' + e.edadHoras + ' h</span>'
          : '') +
        '<br><span style="display:inline-block;margin-top:4px;font-size:11px;color:#3730a3;' +
        'background:#eef2ff;border:1px solid #c7d2fe;border-radius:5px;padding:1px 7px;font-weight:700">' +
        'App offline: ' + SP_SHELL_VER + '</span>';
    });
  }

  /* Ultimo recurso: borra la BD local y la vuelve a bajar.
     Sirve cuando IndexedDB quedo con una estructura vieja
     ("object store not found") y ni recargando se arregla. */
  window.repararDatos = function () {
    if (!confirm('Esto BORRA los datos guardados en el celular y los vuelve a bajar.\n\n' +
                 '⚠️ Si tienes registros SIN SUBIR, primero pulsa Sincronizar.\n\n' +
                 '¿Continuar?')) return;
    if (!navigator.onLine) {
      alert('📶 Necesitas señal para volver a bajar los datos.');
      return;
    }
    SPSnap.resetear()
      .then(function () { return SPSnap.init(); })
      .then(function () { return SPSnap.sincronizar(); })
      .then(function () {
        pintarEstado();
        alert('✅ Datos reparados y descargados.');
        location.reload();
      })
      .catch(function (e) {
        alert('Error al reparar: ' + (e.message || e) +
              '\n\nProbá: F12 → Application → Storage → Clear site data');
      });
  };

  function pintarCola() {
    SPLote.contar().then(function (n) {
      var c = $('cola');
      if (!n) { c.style.display = 'none'; return; }
      c.style.display = 'block';
      c.textContent = '⏳ ' + n + ' sin subir';
    });
  }

  /* ═════════ CONSULTA ═════════ */
  /* 'prestamo_gratis' se muestra como "Autorizado" (el enum en BD no cambia) */
  var ASIG = { uso_propio:'🏠 Uso propio', prestamo_gratis:'🤝 Autorizado', alquiler:'💰 Alquiler' };

  /* enum real de celdas.tipo */
  var TIPO_CELDA = {
    comun:              { ic: '',   txt: 'Común' },
    privada:            { ic: '',   txt: 'Privada' },
    moto_comun:         { ic: '🏍️', txt: 'Moto' },
    libre:              { ic: '🅿️', txt: 'Libre' },
    movilidad_reducida: { ic: '♿', txt: 'Movilidad reducida' }
  };
  function iconoCelda(t) { return (TIPO_CELDA[t] && TIPO_CELDA[t].ic) || ''; }
  function textoCelda(t) { return (TIPO_CELDA[t] && TIPO_CELDA[t].txt) || (t || ''); }

  /* Celdas atadas a un apartamento.
     Se cruza por ID (dueno_id / uso_id), no por nombre: los nombres de apto
     pueden repetirse entre torres y el cruce por string da falsos positivos. */
  function celdasDeApto(aptoId) {
    if (!aptoId) return Promise.resolve([]);
    return SPSnap.todos('celdas').then(function (cs) {
      return cs.filter(function (c) {
        return c.dueno_id === aptoId || c.uso_id === aptoId;
      });
    });
  }

  /* HTML de las celdas del apto: dice CUAL le corresponde y a que titulo */
  function celdasHTML(cs, aptoId) {
    if (!cs.length) {
      return '<tr><th>Celda</th><td class="muted">Sin celda asignada</td></tr>';
    }
    var h = '';
    cs.forEach(function (c) {
      var etiq = ASIG[c.asig] || '';
      // v7.61: SIEMPRE el propietario y, si existe, el autorizado (los dos)
      var rel, linea2 = '';
      if (c.dueno_id === aptoId) {
        rel = '<span class="pill ok">propia</span>';
        if (c.uso_id && c.uso_id !== aptoId) {
          linea2 = '<br><span class="pill pend">' + (etiq || 'autorizado') +
                   ' → la usa ' + esc(c.usuario) + '</span>';
        }
      } else if (c.uso_id === aptoId) {
        rel = '<span class="pill vis">' + (etiq || 'usa') + '</span>';
        if (c.dueno) {
          linea2 = '<br><span class="muted">🏠 Propietario: <b>' + esc(c.dueno) + '</b></span>';
        }
      } else {
        rel = '<span class="pill ok">propia</span>';
      }
      var mov = (c.tipo === 'movilidad_reducida')
        ? ' <span class="pill" style="background:#ede9fe;color:#5b21b6">♿</span>' : '';
      h += '<tr><th>Celda</th><td><b style="font-size:15px">' +
           (iconoCelda(c.tipo) || '🅿️') + ' ' + esc(c.celda) + '</b> ' + rel + mov + linea2 +
           (c.valor ? '<br><span class="muted">$' + c.valor.toLocaleString('es-CO') + '/mes</span>' : '') +
           '</td></tr>';
    });
    return h;
  }

  /* Cuartos útiles del apto (espejo de celdas) */
  function cuartosDeApto(aptoId) {
    if (!aptoId) return Promise.resolve([]);
    return SPSnap.todos('cuartos').then(function (cs) {
      return cs.filter(function (c) {
        return c.dueno_id === aptoId || c.uso_id === aptoId;
      });
    }).catch(function(){ return []; });
  }

  function cuartosHTML(cs, aptoId) {
    if (!cs.length) return '';  // sin cuartos: no mostramos fila
    var h = '';
    cs.forEach(function (c) {
      var etiq = ASIG[c.asig] || '';
      // v7.61: SIEMPRE el propietario y, si existe, el autorizado (los dos)
      var rel, linea2 = '';
      if (c.dueno_id === aptoId) {
        rel = '<span class="pill ok">propio</span>';
        if (c.uso_id && c.uso_id !== aptoId) {
          linea2 = '<br><span class="pill pend">' + (etiq || 'autorizado') +
                   ' → lo usa ' + esc(c.usuario) + '</span>';
        }
      } else if (c.uso_id === aptoId) {
        rel = '<span class="pill vis">' + (etiq || 'usa') + '</span>';
        if (c.dueno) {
          linea2 = '<br><span class="muted">🏠 Propietario: <b>' + esc(c.dueno) + '</b></span>';
        }
      } else {
        rel = '<span class="pill ok">propio</span>';
      }
      h += '<tr><th>Cuarto</th><td><b style="font-size:15px">📦 ' + esc(c.cuarto) + '</b> ' + rel + linea2 +
           (c.valor ? '<br><span class="muted">$' + c.valor.toLocaleString('es-CO') + '/mes</span>' : '') +
           '</td></tr>';
    });
    return h;
  }

  function pillsApto(x) {
    var h = '';
    if (x.moroso && x.moroso !== 'al_dia')
      h += '<span class="pill" style="background:#dc2626;color:#fff;font-weight:800">🔴 MOROSO</span> ';
    if (x.bloqueo) h += '<span class="pill blo">🚫 Comunes</span> ';
    if (!h) h = '<span class="pill ok">✓ Al día</span>';
    return h;
  }
  function tablaVeh(vs) {
    if (!vs || !vs.length) return '<p class="vacio">Sin vehículos</p>';
    var h = '<table><tr><th>Placa</th><th>Tipo</th><th>Marca/Color</th><th>Apto</th></tr>';
    vs.forEach(function (v) {
      h += '<tr class="clic" onclick="fichaVeh(' + v.id + ')">' +
           '<td><span class="plc" style="font-size:15px">' + esc(v.placa) + '</span>' +
           (v.archivado ? ' <span class="pill arc">arch.</span>' : '') + '</td>' +
           '<td>' + (v.tipo === 'moto' ? '🏍️' : '🚗') + '</td>' +
           '<td>' + esc(v.marca) + ' ' + esc(v.color) + '</td>' +
           '<td>' + esc(v.apto) + '</td></tr>';
    });
    return h + '</table>';
  }

  window.bPlaca = function () {
    var q = $('q-placa').value;
    var R = $('r-consulta');
    R.innerHTML = '<div class="card"><p class="vacio">⏳</p></div>';
    SPSnap.buscarPlaca(q).then(function (d) {
      if (!d.vehiculos.length && !d.visitantes.length) {
        R.innerHTML = '<div class="card"><p class="vacio">No se encontró "' + esc(q) + '"</p>' +
          '<button class="btn w blk" onclick="irNovedad(\'' + esc(norm(q)) + '\')">📝 Registrar novedad</button></div>';
        return;
      }
      var h = '';
      d.vehiculos.forEach(function (v) { h += fichaVehHTML(v); });
      var pend = d.vehiculos.slice();
      d.visitantes.forEach(function (v) {
        h += '<div class="card"><h2>🚙 ' + esc(v.placa) + ' <span class="pill vis">VISITANTE</span></h2>' +
             '<table class="ficha">' +
             '<tr><th>Apto</th><td><b>' + esc(v.apto) + '</b> · Torre ' + esc(v.torre) + '</td></tr>' +
             (v.nombre ? '<tr><th>Nombre</th><td>' + esc(v.nombre) +
                (v.parentesco ? ' (' + esc(v.parentesco) + ')' : '') + '</td></tr>' : '') +
             '<tr><th>Visitas</th><td>' + (v.visitas||0) +
             (v.recurrente ? ' · <span class="pill vis">recurrente</span>' : '') + '</td></tr>' +
             (v.ultima ? '<tr><th>Última</th><td>' + esc(v.ultima) + '</td></tr>' : '') +
             '</table>' +
             '<button class="btn w sm" style="margin-top:9px" onclick="irNovedad(\'' + esc(v.placa) + '\')">📝 Novedad</button></div>';
      });
      R.innerHTML = h;
      pend.forEach(function (v) {
        pintarCeldas(v.apto_id, v.id);
        marcarNovedades(v.id);
      });
    });
  };

  function fichaVehHTML(v) {
    return '<div class="card"><h2>🚗 <span class="plc">' + esc(v.placa) + '</span>' +
      (v.archivado ? ' <span class="pill arc">archivado</span>' : '') + ' ' + pillsApto(v) + '</h2>' +
      '<table class="ficha">' +
      '<tr><th>Apto</th><td><b>' + esc(v.apto) + '</b> · Torre ' + esc(v.torre) +
      (v.piso ? ' · Piso ' + esc(v.piso) : '') + '</td></tr>' +
      '<tbody id="cel-' + v.id + '"><tr><th>Celda</th><td class="muted">…</td></tr></tbody>' +
      '<tbody id="cua-' + v.id + '"></tbody>' +
      '<tr><th>Vehículo</th><td>' + esc(v.marca) + ' ' + esc(v.color) + ' · ' + esc(v.tipo) + '</td></tr>' +
      (v.res_nom ? '<tr><th>Residente</th><td>' + esc(v.res_nom) +
         (v.res_cel ? '<br>📞 ' + esc(v.res_cel) : '') +
         (v.res_tipo ? ' · ' + esc(v.res_tipo) : '') + '</td></tr>' : '') +
      (v.obs ? '<tr><th>Obs.</th><td>' + esc(v.obs) + '</td></tr>' : '') +
      '</table>' +
      '<div class="row" style="margin-top:9px">' +
      '<button class="btn w sm" onclick="irNovedad(\'' + esc(v.placa) + '\',' + v.id + ')">📝 Novedad</button>' +
      '<button class="btn sm" id="hb-' + v.id + '" onclick="verHistorial(' + v.id + ')">📜 Historial</button>' +
      '</div><div id="hist-' + v.id + '"></div></div>';
  }

  /* Marca en el boton cuantas novedades tiene ese vehiculo.
     Antes no habia forma de saber si una placa ya tenia registros
     sin tocar "Historial" una por una. */
  function marcarNovedades(vehId) {
    SPSnap.obsDeVehiculo(vehId).then(function (os) {
      var b = $('hb-' + vehId);
      if (!b) return;
      if (!os.length) {
        b.textContent = '📜 Sin novedades';
        b.style.opacity = '.6';
      } else {
        b.innerHTML = '⚠️ <b>' + os.length + '</b> novedad' + (os.length > 1 ? 'es' : '');
        b.style.background = '#fef3c7';
        b.style.color = '#92400e';
        b.style.fontWeight = '700';
      }
    });
  }

  /* rellena la fila de celda una vez pintada la ficha */
  function pintarCeldas(aptoId, vehId) {
    celdasDeApto(aptoId).then(function (cs) {
      var el = $('cel-' + vehId);
      if (el) el.innerHTML = celdasHTML(cs, aptoId);
    });
    cuartosDeApto(aptoId).then(function (qs) {
      var elq = $('cua-' + vehId);
      if (elq) elq.innerHTML = cuartosHTML(qs, aptoId);
    });
  }

  window.fichaVeh = function (id) {
    SPSnap.todos('vehiculos').then(function (vs) {
      var v = vs.filter(function (x) { return x.id === id; })[0];
      if (!v) return;
      $('r-consulta').innerHTML = fichaVehHTML(v);
      pintarCeldas(v.apto_id, v.id);
      marcarNovedades(v.id);
    });
  };

  /* colores por gravedad. Valores REALES del enum: ninguna|leve|media|grave */
  var GRAV = {
    grave:   '#fee2e2|#991b1b',
    media:   '#fef3c7|#92400e',
    leve:    '#dcfce7|#166534',
    ninguna: '#f3f4f6|#6b7280'    // informativa: gris, no es una falta
  };

  window.verHistorial = function (vehId) {
    SPSnap.obsDeVehiculo(vehId).then(function (os) {
      var el = $('hist-' + vehId);
      if (!el) return;
      if (!os.length) {
        el.innerHTML = '<p class="vacio">Sin novedades registradas</p>';
        return;
      }
      var h = '<table style="margin-top:8px">' +
              '<tr><th>Fecha</th><th>Tipo</th><th>Descripción</th></tr>';
      os.forEach(function (o) {
        var g = (GRAV[o.gravedad] || '#f3f4f6|#6b7280').split('|');
        h += '<tr><td>' + esc((o.creado||'').substr(0,10)) + '<br>' +
             '<span class="muted">' + esc((o.creado||'').substr(11,5)) + '</span></td>' +
             '<td>' + esc(o.tipo) +
             (o.gravedad ? '<br><span class="pill" style="background:' + g[0] +
                           ';color:' + g[1] + '">' + esc(o.gravedad) + '</span>' : '') +
             '</td>' +
             '<td>' + esc(o.desc) +
             (o.usuario ? '<br><span class="muted">— ' + esc(o.usuario) + '</span>' : '') +
             '</td></tr>';
      });
      el.innerHTML = h + '</table>';
    });
  };

  window.bApto = function () {
    var R = $('r-consulta');
    SPSnap.buscarApto($('q-apto').value).then(function (rows) {
      if (!rows.length) { R.innerHTML = '<div class="card"><p class="vacio">Sin resultados</p></div>'; return; }
      var h = '';
      rows.forEach(function (x) {
        var a = x.apto;
        h += '<div class="card"><h2>🏠 Apto ' + esc(a.apto) + ' · Torre ' + esc(a.torre) +
             ' ' + pillsApto(a) + '</h2>';
        h += '<table class="ficha" id="cela-' + a.id + '">' +
             '<tr><th>Celda</th><td class="muted">…</td></tr></table>';
        h += '<table class="ficha" id="cuar-' + a.id + '"></table>';
        if (a.prop_nom)
          h += '<p class="muted"><b>Propietario:</b> ' + esc(a.prop_nom) +
               (a.prop_cel ? ' · 📞 ' + esc(a.prop_cel) : '') + '</p>';
        h += bannerVacio(x.residentes);
        if (x.residentes.length) {
          h += '<table><tr><th>Residente</th><th>Tipo</th><th>Celular</th></tr>';
          x.residentes.forEach(function (r) {
            h += '<tr><td>' + esc(r.nombre) + '</td><td>' + esc(r.tipo) + '</td>' +
                 '<td>' + esc(r.celular) + '</td></tr>';
          });
          h += '</table>';
        }
        h += tablaVeh(x.vehiculos);
        if (x.visitantes.length)
          h += '<p class="muted" style="margin-top:8px"><b>Visitantes:</b></p>' + tablaVeh(x.visitantes);
        h += '</div>';
      });
      R.innerHTML = h;
      rows.forEach(function (x) {
        celdasDeApto(x.apto.id).then(function (cs) {
          var el = $('cela-' + x.apto.id);
          if (el) el.innerHTML = celdasHTML(cs, x.apto.id);
        });
        cuartosDeApto(x.apto.id).then(function (qs) {
          var elq = $('cuar-' + x.apto.id);
          if (elq) elq.innerHTML = cuartosHTML(qs, x.apto.id);
        });
      });
    });
  };

  window.bRes = function () {
    var R = $('r-consulta');
    SPSnap.buscarResidente($('q-res').value).then(function (rows) {
      if (!rows.length) { R.innerHTML = '<div class="card"><p class="vacio">Sin resultados</p></div>'; return; }
      var h = '';
      rows.forEach(function (x) {
        var r = x.residente;
        h += '<div class="card"><h2>👤 ' + esc(r.nombre) +
             (r.activo ? '' : ' <span class="pill arc">inactivo</span>') + '</h2><table class="ficha">' +
             '<tr><th>Apto</th><td><b>' + esc(r.apto) + '</b> · Torre ' + esc(r.torre) + '</td></tr>' +
             '<tr><th>Tipo</th><td>' + esc(r.tipo) + '</td></tr>' +
             (r.celular ? '<tr><th>Celular</th><td>📞 ' + esc(r.celular) + '</td></tr>' : '') +
             '</table>' + tablaVeh(x.vehiculos) + '</div>';
      });
      R.innerHTML = h;
    });
  };

  /* v7.43: banner de apto vacío (nadie vive) para offline */
  function bannerVacio(residentes) {
    residentes = residentes || [];
    // ¿algún residente vive en el apto?
    var vive = residentes.some(function (r) { return r.vive === 1 || r.vive === true; });
    if (vive) return '';
    // Si hay residentes pero NINGUNO trae el campo 'vive' definido, el snapshot
    // es viejo (sin ese dato): no marcamos vacío para evitar falsos positivos.
    var hayDato = residentes.length === 0 ||
                  residentes.some(function (r) { return r.vive === 0 || r.vive === 1 ||
                                                       r.vive === true || r.vive === false; });
    if (!hayDato) return '';
    return '<div style="margin:8px 0;padding:10px 12px;background:#fff7ed;border:2px solid #f59e0b;' +
           'border-radius:9px;display:flex;align-items:center;gap:8px">' +
           '<span style="font-size:22px">🏚️</span>' +
           '<div><b style="color:#b45309">APARTAMENTO VACÍO</b>' +
           '<br><span class="muted" style="color:#92400e">Nadie vive aquí (sin residentes viviendo).</span></div></div>';
  }

  /* v7.33: apto clickeable → abre la búsqueda por apto */
  /* v7.35: badge de morosidad (rojo si moroso, verde si al día) */
  function badgeMoroso(numApto) {
    if (!numApto) return '';
    var estado = MOROSOS[String(numApto)] || '';
    if (estado === 'moroso') {
      return ' <span class="pill" style="background:#fee2e2;color:#991b1b;font-weight:700">🔴 MOROSO</span>';
    }
    if (estado === 'al_dia') {
      return ' <span class="pill" style="background:#dcfce7;color:#166534">✅ Al día</span>';
    }
    return '';
  }

  function aptoLink(apto) {
    return '<b><a href="#" onclick="verAptoDesdeNov(\'' + esc(apto) + '\');return false;" ' +
           'style="color:#1e40af;text-decoration:underline">' + esc(apto) + '</a></b>';
  }

  function celdaHTML(x) {
    var c = x.celda;
    return '<div class="card"><h2>' + (iconoCelda(c.tipo) || '🅿️') + ' ' + esc(c.celda) +
      (c.tipo === 'movilidad_reducida'
         ? ' <span class="pill" style="background:#ede9fe;color:#5b21b6">♿ Mov. reducida</span>'
         : '') +
      (ASIG[c.asig] ? ' <span class="pill vis">' + ASIG[c.asig] + '</span>' : '') + '</h2>' +
      '<table class="ficha">' +
      (c.tipo && c.tipo !== 'comun'
         ? '<tr><th>Tipo</th><td>' + esc(textoCelda(c.tipo)) + '</td></tr>' : '') +
      '<tr><th>Apto dueño</th><td>' + (c.dueno ? aptoLink(c.dueno) : '—') + '</td></tr>' +
      (c.usuario && c.usuario !== c.dueno
         ? '<tr><th>Apto usuario</th><td>' + aptoLink(c.usuario) + '</td></tr>' : '') +
      (c.valor ? '<tr><th>Valor</th><td>$' + c.valor.toLocaleString('es-CO') + '</td></tr>' : '') +
      (c.obs ? '<tr><th>Obs.</th><td>' + esc(c.obs) + '</td></tr>' : '') +
      '</table>' + tablaVeh(x.vehiculos) + '</div>';
  }

  window.bCelda = function () {
    SPSnap.buscarCelda($('q-celda').value).then(function (rows) {
      $('r-consulta').innerHTML = rows.length
        ? rows.map(celdaHTML).join('')
        : '<div class="card"><p class="vacio">Sin resultados</p></div>';
    });
  };

  /* CUARTO ÚTIL: render de un resultado (espejo de celdaHTML) */
  function cuartoHTML(x) {
    var c = x.cuarto;
    return '<div class="card"><h2>📦 ' + esc(c.cuarto) +
      (ASIG[c.asig] ? ' <span class="pill vis">' + ASIG[c.asig] + '</span>' : '') + '</h2>' +
      '<table class="ficha">' +
      '<tr><th>Apto dueño</th><td>' + (c.dueno ? aptoLink(c.dueno) : '—') + '</td></tr>' +
      (c.usuario && c.usuario !== c.dueno
         ? '<tr><th>Apto usuario</th><td>' + aptoLink(c.usuario) + '</td></tr>' : '') +
      (c.valor ? '<tr><th>Valor</th><td>$' + c.valor.toLocaleString('es-CO') + '</td></tr>' : '') +
      (c.obs ? '<tr><th>Obs.</th><td>' + esc(c.obs) + '</td></tr>' : '') +
      '</table>' + tablaVeh(x.vehiculos) + '</div>';
  }

  window.bCuarto = function () {
    SPSnap.buscarCuarto($('q-cuarto').value).then(function (rows) {
      $('r-consulta').innerHTML = rows.length
        ? rows.map(cuartoHTML).join('')
        : '<div class="card"><p class="vacio">Sin resultados</p></div>';
    });
  };

  /* ═════════ VEHICULOS ═════════ */
  window.bVeh = function () {
    var q = norm($('q-veh').value).trim();
    var R = $('r-vehiculos');
    if (!q) { R.innerHTML = '<div class="card"><p class="vacio">Escribe algo para buscar</p></div>'; return; }
    SPSnap.todos('vehiculos').then(function (vs) {
      var r = vs.filter(function (v) {
        return norm(v.placa).indexOf(q) >= 0 || norm(v.marca).indexOf(q) >= 0 ||
               norm(v.apto).indexOf(q) === 0;
      }).slice(0, 40);
      R.innerHTML = r.length
        ? '<div class="card"><h2>' + r.length + ' resultado(s)</h2>' + tablaVeh(r) + '</div>'
        : '<div class="card"><p class="vacio">Sin resultados</p></div>';
    });
  };

  /* ═════════ RESIDENTES ═════════ */

  /* tabla comun de residentes */
  function tablaRes(r) {
    var h = '<table><tr><th>Nombre</th><th>Apto</th><th>Tipo</th><th>Celular</th></tr>';
    r.forEach(function (x) {
      h += '<tr><td>' + esc(x.nombre) +
           (x.activo ? '' : ' <span class="pill arc">inactivo</span>') + '</td>' +
           '<td><b>' + esc(x.apto) + '</b><br><span class="muted">T' + esc(x.torre) + '</span></td>' +
           '<td>' + esc(x.tipo) + '</td>' +
           '<td>' + (x.celular ? '📞 ' + esc(x.celular) : '<span class="muted">—</span>') + '</td></tr>';
    });
    return h + '</table>';
  }

  /* 1) por NOMBRE o CELULAR (busqueda generalizada) */
  window.bResi = function () {
    var q = norm($('q-resi').value).trim();
    var R = $('r-residentes');
    if (q.length < 2) {
      R.innerHTML = '<div class="card"><p class="vacio">Escribe al menos 2 caracteres</p></div>';
      return;
    }
    var soloNum = q.replace(/\D/g, '');
    SPSnap.mem().then(function (m) {
      var r = m.residentes.filter(function (x) {
        var porNombre = norm(x.nombre).indexOf(q) >= 0;
        // el celular solo se compara si el usuario escribio digitos
        var porCel = soloNum.length >= 3 &&
                     (x.celular || '').replace(/\D/g, '').indexOf(soloNum) >= 0;
        return porNombre || porCel;
      }).slice(0, 60);
      if (!r.length) {
        R.innerHTML = '<div class="card"><p class="vacio">Sin resultados para "' +
                      esc($('q-resi').value) + '"</p></div>';
        return;
      }
      R.innerHTML = '<div class="card"><h2>👤 ' + r.length + ' residente(s)</h2>' +
                    tablaRes(r) + '</div>';
    });
  };

  /* 2) por APARTAMENTO (exacto/prefijo) — muestra el apto completo:
        residentes + vehiculos + celda + morosidad */
  window.bResiApto = function () {
    var q = norm($('q-resi-apto').value).trim();
    var R = $('r-residentes');
    if (!q) {
      R.innerHTML = '<div class="card"><p class="vacio">Escribe el número del apartamento</p></div>';
      return;
    }
    SPSnap.buscarApto(q).then(function (rows) {
      if (!rows.length) {
        R.innerHTML = '<div class="card"><p class="vacio">No existe el apartamento "' +
                      esc($('q-resi-apto').value) + '"</p></div>';
        return;
      }
      var h = '';
      rows.forEach(function (x) {
        var a = x.apto;
        h += '<div class="card"><h2>🏠 Apto ' + esc(a.apto) + ' · Torre ' + esc(a.torre) +
             ' ' + pillsApto(a) + '</h2>' +
             '<table class="ficha" id="celr-' + a.id + '">' +
             '<tr><th>Celda</th><td class="muted">…</td></tr></table>';
        if (a.prop_nom) {
          h += '<p class="muted"><b>Propietario:</b> ' + esc(a.prop_nom) +
               (a.prop_cel ? ' · 📞 ' + esc(a.prop_cel) : '') + '</p>';
        }
        h += bannerVacio(x.residentes);
        h += x.residentes.length
               ? tablaRes(x.residentes)
               : '<p class="vacio">Sin residentes activos</p>';
        h += tablaVeh(x.vehiculos);
        h += '</div>';
      });
      R.innerHTML = h;
      rows.forEach(function (x) {
        celdasDeApto(x.apto.id).then(function (cs) {
          var el = $('celr-' + x.apto.id);
          if (el) el.innerHTML = celdasHTML(cs, x.apto.id);
        });
      });
    });
  };

  /* ═════════ PARQUEADERO ═════════ */
  window.bPark = function () {
    SPSnap.buscarCelda($('q-park').value).then(function (rows) {
      $('r-parqueadero').innerHTML = rows.length
        ? rows.map(celdaHTML).join('')
        : '<div class="card"><p class="vacio">Sin resultados</p></div>';
    });
  };

  /* ═════════ REVISTAS ═════════ */
  var REV_TAB = 'curso';

  window.tabRevistas = function (t) {
    REV_TAB = t;
    Array.prototype.forEach.call(document.querySelectorAll('#rev-tabs button'), function (b) {
      var act = b.getAttribute('data-rt') === t;
      b.className = 'btn sm' + (act ? ' p' : '');
    });
    verRevistas();
  };

  function verRevistas() {
    Promise.all([SPSnap.revistas(), SPLote.revistasLocales(), SPSnap.todos('niveles')])
    .then(function (r) {
      var srv = r[0], loc = r[1], niv = r[2];
      var R = $('r-revistas');

      // ── TERMINADAS: solo historial, no se ejecutan ──
      if (REV_TAB === 'hechas') {
        var hs = srv.filter(function (x) { return x.estado !== 'en_curso'; });
        if (!hs.length) {
          R.innerHTML = '<div class="card"><p class="vacio">No hay revistas terminadas.<br>' +
                        'Pulsá <b>Sincronizar</b> para traerlas.</p></div>';
          return;
        }
        var hh = '<div class="card"><h2>✅ Revistas terminadas</h2><table>' +
                 '<tr><th>Nivel</th><th>Fecha</th><th>Resultado</th></tr>';
        hs.forEach(function (x) {
          hh += '<tr><td><b>' + esc(x.nombre) + '</b><br>' +
                '<span class="muted">' + esc(x.usuario || '') + '</span></td>' +
                '<td>' + esc((x.creado||'').substr(0,10)) + '<br>' +
                '<span class="muted">' + esc((x.creado||'').substr(11,5)) + '</span></td>' +
                '<td><b>' + x.revisadas + '</b> celdas<br><span class="muted">' +
                x.ocupadas + ' ocup · ' + x.vacias + ' vacías</span></td></tr>';
        });
        R.innerHTML = hh + '</table></div>';
        return;
      }

      // --- crear revista nueva (funciona SIN señal) ---
      var h = '<div class="card"><h2>➕ Nueva revista</h2>';
      if (!niv.length) {
        h += '<p class="vacio">Sin niveles. Pulsa <b>Sincronizar</b>.</p>';
      } else {
        h += '<select id="niv-sel">';
        niv.forEach(function (n) {
          h += '<option value="' + esc(n.codigo) + '" data-t="' + n.total + '">' +
               esc(n.codigo) + ' · ' + esc(n.nombre) + ' (' + n.total + ' celdas)</option>';
        });
        h += '</select><button class="btn g blk" onclick="crearRevista()">➕ Iniciar revista</button>' +
             '<p class="muted" style="margin-top:6px">Se crea en el celular. Sube al Sincronizar.</p>';
      }
      h += '</div>';

      // --- revistas creadas offline (aun sin subir) ---
      if (loc.length) {
        // BUG v6.8: al terminar una revista offline seguia apareciendo "en curso".
        // Ahora revistaCerrar() marca la local como terminada y aca se distingue.
        var enCurso   = loc.filter(function (l) { return l.estado !== 'terminada'; });
        var terminadas= loc.filter(function (l) { return l.estado === 'terminada'; });

        if (REV_TAB === 'curso' && enCurso.length) {
          h += '<div class="card"><h2>📴 Creadas sin señal</h2><table>' +
               '<tr><th>Nivel</th><th>Progreso</th><th></th></tr>';
          enCurso.forEach(function (l) {
            h += '<tr class="clic" onclick="ejecutar(\'' + l.id_local + '\',\'' + esc(l.nivel) + '\')">' +
                 '<td><b>' + esc(l.nivel) + '</b> <span class="pill pend">sin subir</span></td>' +
                 '<td id="pl-' + l.id_local + '" class="muted">…</td>' +
                 '<td>▶</td></tr>';
          });
          h += '</table></div>';
        }
        if (terminadas.length) {
          h += '<div class="card"><h2>✅ Terminadas sin subir</h2><table>' +
               '<tr><th>Nivel</th><th>Celdas</th><th></th></tr>';
          terminadas.forEach(function (l) {
            h += '<tr class="clic" onclick="ejecutar(\'' + l.id_local + '\',\'' + esc(l.nivel) + '\')">' +
                 '<td><b>' + esc(l.nivel) + '</b> ' +
                 '<span class="pill ok">terminada</span> ' +
                 '<span class="pill pend">sin subir</span></td>' +
                 '<td id="pl-' + l.id_local + '" class="muted">…</td>' +
                 '<td>👁</td></tr>';
          });
          h += '</table>' +
               '<p class="muted" style="margin-top:7px">Pulsá <b>Sincronizar</b> para subirlas.</p></div>';
        }
      }

      // --- revistas EN CURSO del servidor ---
      var enc = srv.filter(function (x) { return x.estado === 'en_curso'; });
      if (enc.length) {
        h += '<div class="card"><h2>📋 En curso</h2><table>' +
             '<tr><th>Nivel</th><th>Progreso</th><th></th></tr>';
        enc.forEach(function (x) {
          h += '<tr class="clic" onclick="ejecutar(' + x.id + ',\'' + esc(x.nombre) + '\')">' +
               '<td><b>' + esc(x.nombre) + '</b><br><span class="muted">' +
               esc((x.creado||'').substr(0,10)) + '</span></td>' +
               '<td>' + x.revisadas + ' rev.<br><span class="muted">' +
               x.ocupadas + ' oc · ' + x.vacias + ' vac</span></td><td>▶</td></tr>';
        });
        h += '</table></div>';
      }
      R.innerHTML = h;

      // pintar progreso de las locales
      loc.forEach(function (l) {
        SPLote.pasosDe(l.id_local).then(function (ps) {
          var e = $('pl-' + l.id_local);
          if (e) e.textContent = ps.length + ' / ' + (l.total_celdas || '?') + ' celdas';
        });
      });
    });
  }

  window.crearRevista = function () {
    var sel = $('niv-sel');
    if (!sel) return;
    var cod = sel.value;
    var tot = parseInt(sel.options[sel.selectedIndex].getAttribute('data-t'), 10) || 0;
    if (!confirm('¿Iniciar revista en el nivel ' + cod + '?\n(' + tot + ' celdas)')) return;
    SPLote.revistaNueva(cod, tot).then(function (idl) {
      alert('✅ Revista iniciada en el celular.\nSe subirá al pulsar Sincronizar.');
      verRevistas();
      ejecutar(idl, cod);
    });
  };

  /* ═════════ EJECUTAR REVISTA (celda por celda, sin señal) ═════════ */
  var EJ = null;   // {revistaId, nivel, celdas[], idx, foto}
  var MOROSOS = {}; // numero_apto -> 'moroso'|'al_dia' (v7.36)

  window.ejecutar = function (revistaId, nivelCodigo) {
    Promise.all([SPSnap.todos('celdas'), SPSnap.todos('niveles'), SPLote.pasosDe(revistaId), SPSnap.todos('apartamentos')])
    .then(function (r) {
      var celdas = r[0], niv = r[1], pasos = r[2], aptos = r[3] || [];
      // v7.36: mapa numero_apto -> estado morosidad (usa el store que YA funciona)
      MOROSOS = {};
      aptos.forEach(function (a) { if (a.apto) MOROSOS[String(a.apto)] = a.moroso || ''; });
      var n = niv.filter(function (x) { return x.codigo === nivelCodigo; })[0];
      var cs = n ? celdas.filter(function (c) { return c.nivel_id === n.id; }) : [];
      if (!cs.length) {
        alert('No hay celdas de ese nivel en el celular.\nPulsa Sincronizar.');
        return;
      }
      var hechos = {};
      pasos.forEach(function (p) { hechos[p.celda_id] = p; });
      // v7.56: orden natural de menor a mayor por el NÚMERO de la celda
      // (C99035 antes que C99109), como en parqueadero. Respaldo: orden y nombre.
      function numCelda(c) {
        var m = String(c.celda || '').match(/(\d+)/);
        return m ? parseInt(m[1], 10) : 0;
      }
      cs.sort(function (a, b) {
        var na = numCelda(a), nb = numCelda(b);
        if (na !== nb) return na - nb;
        if ((a.orden||0) !== (b.orden||0)) return (a.orden||0) - (b.orden||0);
        return String(a.celda||'').localeCompare(String(b.celda||''));
      });

      EJ = { revistaId: revistaId, nivel: nivelCodigo, celdas: cs, hechos: hechos, idx: 0, foto: null };

      // v7.72: el aviso de "datos viejos" solo debe salir si el SNAPSHOT es
      // realmente viejo (las celdas no traen ni siquiera el CAMPO dueno).
      // Antes salía también cuando el nivel simplemente NO tiene aptos
      // asignados (ej. P4 con celdas comunes), que es un caso normal.
      var tieneCampo = cs.some(function (c) {
        return Object.prototype.hasOwnProperty.call(c, 'dueno')
            || Object.prototype.hasOwnProperty.call(c, 'dueno_id');
      });
      var conApto = cs.filter(function (c) { return c.dueno || c.usuario; }).length;
      if (!tieneCampo && !conApto && navigator.onLine) {
        if (confirm('⚠️ Los datos de las celdas están desactualizados.\n\n' +
                    'No se ve el apartamento de cada celda.\n\n' +
                    '¿Sincronizar ahora para actualizarlos?')) {
          SPSnap.sincronizar().then(function () {
            alert('✅ Datos actualizados. Abrí la revista de nuevo.');
            tabRevistas('curso');
          }).catch(function (e) { alert('Error: ' + e.message); });
          return;
        }
      }

      // primera celda pendiente
      for (var i = 0; i < cs.length; i++) {
        if (!hechos[cs[i].id]) { EJ.idx = i; break; }
      }
      pintarEjecucion();
    });
  };

  function pintarEjecucion() {
    if (!EJ) return;
    var c = EJ.celdas[EJ.idx];
    var hechas = Object.keys(EJ.hechos).length;
    var h =
      '<div class="card">' +
      '<button class="back" onclick="salirEjecucion()">← Volver a revistas</button>' +
      '<h2>' + (iconoCelda(c.tipo) || '🅿️') + ' ' + esc(c.celda) +
      ' <span class="muted">· ' + esc(EJ.nivel) + '</span>' +
      (c.tipo === 'movilidad_reducida'
         ? ' <span class="pill" style="background:#ede9fe;color:#5b21b6">♿ Mov. reducida</span>'
         : (c.tipo && c.tipo !== 'comun'
              ? ' <span class="pill vis">' + esc(textoCelda(c.tipo)) + '</span>' : '')) +
      '</h2>' +
      '<p class="muted">Celda ' + (EJ.idx+1) + ' de ' + EJ.celdas.length +
      ' · <b>' + hechas + '</b> revisadas · <b>' + (EJ.celdas.length - hechas) + '</b> pendientes</p>' +
      (c.dueno || c.usuario
         ? '<div style="margin:6px 0;padding:7px 10px;background:#f3f4f6;border-radius:7px;' +
           'font-size:13.5px">' +
           '🏠 Apto dueño: <b>' + esc(c.dueno || '—') + '</b>' + badgeMoroso(c.dueno) +
           (c.usuario && c.usuario !== c.dueno
              ? ' · <span class="pill vis">la usa ' + esc(c.usuario) + '</span>' + badgeMoroso(c.usuario) : '') +
           '</div>'
         : '<p class="muted" style="font-size:12px">Sin apto asignado</p>') +
      '<div id="ej-foto" style="margin:9px 0"></div>' +
      '<div class="row">' +
        '<button class="btn p" onclick="$(\'ej-cam\').click()">📷 Foto</button>' +
      '</div>' +
      '<input type="file" id="ej-cam" accept="image/*" capture="environment" hidden>' +
      '<div id="ej-ocr" style="margin-top:8px"></div>' +
      '<div style="display:flex;gap:6px;margin-top:8px;align-items:center">' +
        '<input type="text" id="ej-placa" class="placa" placeholder="ABC123" maxlength="10" ' +
               'style="flex:1;margin:0">' +
        '<button class="btn p" onclick="validarPlaca()" style="white-space:nowrap">🔍 Validar</button>' +
      '</div>' +
      '<div id="ej-info"></div>' +
      '<div class="row" style="margin-top:9px">' +
        '<button class="btn g" onclick="guardarCelda(\'ocupada\')">✅ Ocupada</button>' +
        '<button class="btn" onclick="guardarCelda(\'vacia\')" style="background:#fbbf24">⭕ Vacía</button>' +
      '</div>' +
      '<div class="row" style="margin-top:6px">' +
        '<button class="btn sm" onclick="mover(-1)">← Anterior</button>' +
        '<button class="btn sm" onclick="mover(1)">Siguiente →</button>' +
        '<button class="btn w sm" onclick="terminarRevista()">🏁 Terminar</button>' +
      '</div>' +
      '<div class="row" style="margin-top:6px">' +
        '<button class="btn sm" style="background:#dc2626;color:#fff" onclick="cancelarRevista()">⏹️ Cancelar revista</button>' +
      '</div></div>' +
      gridCeldas();
    $('r-revistas').innerHTML = h;

    $('ej-cam').onchange = function (e) {
      var f = e.target.files && e.target.files[0];
      e.target.value = '';          // v7.74: limpiar SIEMPRE, evita doble disparo
      if (f) fotoCelda(f);
    };

    // Recuperar lo que ya se guardo en esta celda: placa Y FOTO.
    // BUG v6.8: antes solo se restauraba la placa. Si volvias a una celda
    // para verificar que la foto correspondia a la placa, no la veias.
    SPLote.paso(EJ.revistaId, c.id).then(function (p) {
      if (!p) return;
      if (p.placa) {
        $('ej-placa').value = p.placa;
        infoPlaca(p.placa);
      }
      if (p.foto) {
        $('ej-foto').innerHTML =
          '<img src="' + p.foto + '" onclick="zoomFoto(this.src)" ' +
          'style="max-width:100%;max-height:220px;border-radius:8px;cursor:zoom-in">' +
          '<p class="muted" style="margin-top:4px">📷 Foto guardada · toca para ampliar</p>';
      }
    });
  }

  /* visor de foto a pantalla completa */
  window.zoomFoto = function (src) {
    var d = document.getElementById('sp-zoom');
    if (!d) {
      d = document.createElement('div');
      d.id = 'sp-zoom';
      d.style.cssText = 'position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.93);' +
                        'cursor:zoom-out;display:flex;align-items:center;justify-content:center';
      d.onclick = function () { d.style.display = 'none'; };
      d.innerHTML = '<img style="max-width:96vw;max-height:96vh;border-radius:6px">';
      document.body.appendChild(d);
    }
    d.querySelector('img').src = src;
    d.style.display = 'flex';
  };

  /* Grilla de TODAS las celdas, como en la version online.
     rojas = pendientes · verdes = ocupadas · amarillas = vacias
     Se puede tocar cualquiera para saltar directo a ella. */
  function gridCeldas() {
    if (!EJ) return '';
    var h = '<div class="card"><h2>Todas las celdas</h2>' +
      '<p class="muted" style="margin-bottom:8px">' +
      '<span style="color:#dc2626">■</span> pendientes · ' +
      '<span style="color:#166534">■</span> ocupadas · ' +
      '<span style="color:#92400e">■</span> vacías · ' +
      '<span style="color:#5b21b6">♿</span> movilidad reducida · ' +
      '🏠 apto · 📷 con foto</p>' +
      '<div style="display:flex;flex-wrap:wrap;gap:5px">';

    EJ.celdas.forEach(function (c, i) {
      var p = EJ.hechos[c.id];
      var bg = '#fee2e2', fg = '#991b1b', bd = '#fecaca';       // pendiente
      if (p && p.estado === 'ocupada') { bg = '#dcfce7'; fg = '#166534'; bd = '#86efac'; }
      else if (p && p.estado === 'vacia') { bg = '#fef3c7'; fg = '#92400e'; bd = '#fcd34d'; }
      var act = (i === EJ.idx);
      var mov = (c.tipo === 'movilidad_reducida');
      // apto al que pertenece la celda (dueño, o quien la usa).
      // Si viene vacio, el snapshot local es VIEJO (se bajo antes de que
      // api_snapshot incluyera dueno/usuario) -> hay que resincronizar.
      var apto = c.usuario || c.dueno || '';
      h += '<button onclick="saltarCelda(' + i + ')" title="' + esc(textoCelda(c.tipo)) + '" ' +
           'style="padding:6px 7px;border-radius:6px;font-size:11px;cursor:pointer;' +
           'font-family:ui-monospace,monospace;font-weight:600;min-width:76px;text-align:center;' +
           'background:' + bg + ';color:' + fg + ';' +
           'border:' + (act ? '2px solid #1e6cff' : '1px solid ' + bd) + '">' +
           (mov ? '♿' : (iconoCelda(c.tipo) || '')) + esc(c.celda) +
           (apto ? '<br><span style="font-size:9px;opacity:.7;font-weight:400">🏠' +
                   esc(apto) + '</span>' : '') +
           (p && p.placa
              ? '<br><span style="font-size:9.5px;opacity:.9">' + esc(p.placa) + '</span>' +
                (p.foto ? ' 📷' : '')
              : (p && p.estado === 'vacia'
                   ? '<br><span style="font-size:9px;opacity:.7">vacía</span>' : '')) +
           '</button>';
    });
    return h + '</div></div>';
  }

  window.saltarCelda = function (i) {
    if (!EJ || i < 0 || i >= EJ.celdas.length) return;
    // v7.87: mismo cuidado que en mover(): no perder la foto en silencio
    if (EJ.foto && !confirm('⚠️ Tomaste una foto y NO guardaste esta celda.\n\n' +
                            'Si cambiás de celda, la foto SE PIERDE.\n\n' +
                            'Aceptar = cambiar igual (pierde la foto)\n' +
                            'Cancelar = quedarme y guardar')) return;
    EJ.idx = i;
    EJ.foto = null;
    pintarEjecucion();
    window.scrollTo(0, 0);
  };

  var _fotoEnCurso = false;   // v7.74: evita procesar la MISMA foto dos veces
  var _selloHecho  = false;   // v7.74: un solo sello por foto tomada
  var _selloProm   = null;    // v7.75: promesa del sello (por si guardan rápido)

  function fotoCelda(file) {
    if (_fotoEnCurso) return;   // ya se está procesando una: ignorar el duplicado
    _fotoEnCurso = true;
    _selloHecho  = false;       // foto NUEVA: se permite un sello
    _selloProm   = null;
    setTimeout(function () { _fotoEnCurso = false; }, 1500);  // se libera solo

    // v7.75: la foto queda ATADA a la celda en la que se tomó.
    // Si el rondero avanza antes de que termine el sellado, el resultado
    // se DESCARTA (antes se pintaba sobre la celda siguiente).
    var celdaFoto = (EJ && EJ.celdas[EJ.idx]) ? EJ.celdas[EJ.idx].id : null;

    var url = URL.createObjectURL(file);
    $('ej-foto').innerHTML = '<img src="' + url + '" style="max-width:100%;max-height:200px;border-radius:8px">';
    $('ej-ocr').innerHTML = '<p class="muted">⏳ Leyendo placa…</p>';
    var img = new Image();
    img.onload = function () {
      // La foto ORIGINAL alimenta el OCR (img).
      // v7.84 FIX: NO se estampa acá. La fecha/hora la pone sp_lote.js al
      // comprimir la foto para encolarla (amarillo sobre negro, abajo a la
      // derecha). Antes se sellaba en LOS DOS lados y quedaba doble marca.
      EJ.foto = file;
      _selloProm = null;
      if (typeof SPOCR === 'undefined' || !SPOCR.listo()) {
        $('ej-ocr').innerHTML = '<p class="muted">OCR no disponible. Escribe la placa.</p>';
        return;
      }
      SPSnap.placas()
        .then(function (p) { return SPOCR.leer(img, { padron: p, moto: false }); })
        .then(function (r) {
          if (r.zona === 'VERDE' && r.placa) {
            $('ej-placa').value = r.placa;
            $('ej-ocr').innerHTML = '<p style="color:#166534"><b>✅ ' + esc(r.placa) + '</b></p>';
            infoPlaca(r.placa);
            return;
          }
          if (r.zona === 'AMARILLA' && r.sugerencias.length) {
            var h = '<p style="color:#92400e">⚠️ Confirma:</p><div class="row">';
            r.sugerencias.forEach(function (s) {
              var pl = (typeof s === 'string') ? s : s.placa;
              var nv = (typeof s === 'object') && s.nuevo;
              h += '<button class="btn sm" onclick="ponerPlaca(\'' + esc(pl) + '\')" ' +
                   'style="border:2px solid ' + (nv ? '#1e6cff' : '#d1d5db') + '"><b>' +
                   esc(pl) + '</b></button>';
            });
            $('ej-ocr').innerHTML = h + '</div>';
            return;
          }
          $('ej-ocr').innerHTML = '<p style="color:#991b1b">❌ No se leyó. Escribe la placa.</p>';
        })
        .catch(function () {
          $('ej-ocr').innerHTML = '<p class="muted">Error de OCR. Escribe la placa.</p>';
        });
    };
    img.src = url;
  }

  window.ponerPlaca = function (p) {
    $('ej-placa').value = p;
    $('ej-ocr').innerHTML = '<p style="color:#166534"><b>✅ ' + esc(p) + '</b></p>';
    infoPlaca(p);
  };

  /* Validar la placa escrita a mano contra el padron local.
     Antes no habia forma de saber si la placa que digitabas estaba
     registrada ni de quien era. */
  window.validarPlaca = function () {
    var p = norm($('ej-placa').value).replace(/[^A-Z0-9]/g, '');
    if (!p) { $('ej-info').innerHTML = '<p class="muted">Escribe una placa</p>'; return; }
    $('ej-info').innerHTML = '<p class="muted">⏳ Buscando…</p>';
    infoPlaca(p);
  };

  function infoPlaca(p) {
    SPSnap.buscarPlaca(p).then(function (d) {
      var v = d.vehiculos[0];
      var vis = d.visitantes[0];
      var box = $('ej-info');
      if (!box) return;

      if (v) {
        // ¿que celda le corresponde? Sirve para detectar mal parqueo al toque.
        celdasDeApto(v.apto_id).then(function (cs) {
          var cel = cs.length
            ? cs.map(function (c) { return c.celda; }).join(', ')
            : 'sin celda asignada';
          var aqui = EJ ? EJ.celdas[EJ.idx].celda : '';
          var okCelda = cs.some(function (c) { return c.celda === aqui; });

          // Badge de morosidad
          var ep = v.moroso || '';
          var mm = v.meses  || 0;
          var moraBadge;
          if (ep && ep !== 'al_dia' && ep !== '') {
            var mesesTxt = mm > 0 ? ' · ' + mm + ' mes' + (mm > 1 ? 'es' : '') : '';
            moraBadge = '<div style="margin:5px 0 2px;display:inline-flex;align-items:center;'
                      + 'gap:5px;background:#fef2f2;border:2px solid #f87171;border-radius:7px;'
                      + 'padding:5px 13px;font-weight:700;font-size:13px;color:#991b1b">'
                      + '🔴 MOROSO' + mesesTxt + '</div>';
          } else {
            moraBadge = '<div style="margin:5px 0 2px;display:inline-flex;align-items:center;'
                      + 'gap:5px;background:#f0fdf4;border:2px solid #86efac;border-radius:7px;'
                      + 'padding:5px 13px;font-weight:700;font-size:13px;color:#166534">'
                      + '🟢 AL DÍA</div>';
          }

          // v7.81: si el vehículo está ARCHIVADO, avisarlo claramente
          // (así la ronda no lo registra de nuevo creando un duplicado).
          var arch = !!v.archivado;
          box.innerHTML =
            '<div style="margin-top:7px;padding:9px 11px;border-radius:8px;' +
            (arch ? 'background:#fff7ed;border:2px solid #f59e0b;'
                  : 'background:#dcfce7;border:1px solid #86efac;') +
            'font-size:13.5px">' +
            (arch
               ? '<b style="color:#b45309">📁 Registrado pero ARCHIVADO</b>' +
                 '<br><span class="muted" style="color:#92400e">Ya existe en la base. ' +
                 'No lo registres de nuevo: pedí que lo restauren.</span><br>'
               : '<b style="color:#166534">✅ Registrado</b><br>') +
            'Apto <b>' + esc(v.apto) + '</b>' +
            (v.torre ? ' · Torre ' + esc(v.torre) : '') +
            (v.res_nom ? '<br>👤 ' + esc(v.res_nom) : '') +
            (v.marca || v.color ? '<br><span class="muted">' + esc(v.marca) + ' ' +
                                  esc(v.color) + '</span>' : '') +
            '<br>🅿️ Su celda: <b>' + esc(cel) + '</b>' +
            (aqui && cs.length
               ? (okCelda
                    ? ' <span class="pill ok">está en la suya</span>'
                    : ' <span class="pill mor">⚠️ NO es su celda</span>')
               : '') +
            '<br>' + moraBadge +
            '</div>';
        });
        return;
      }

      if (vis) {
        box.innerHTML =
          '<div style="margin-top:7px;padding:9px 11px;border-radius:8px;' +
          'background:#dbeafe;border:1px solid #93c5fd;font-size:13.5px">' +
          '<b style="color:#1e40af">🚙 VISITANTE</b><br>' +
          'Visita el apto <b>' + esc(vis.apto) + '</b>' +
          (vis.nombre ? '<br>👤 ' + esc(vis.nombre) : '') +
          (vis.visitas ? '<br><span class="muted">' + vis.visitas + ' visitas</span>' : '') +
          '</div>';
        return;
      }

      // v7.78: antes de ofrecer registrarla, revisar si YA hay una orden
      // de registro pendiente de subir para esta placa.
      SPLote.registroPendientePlaca(p).then(function (pend) {
        if (pend) {
          // Ya está registrada (sin subir): mostrar A DÓNDE va, no los botones
          SPSnap.todos('apartamentos').then(function (as) {
            var ap = as.filter(function (x) { return x.id === pend.apto_id; })[0];
            var aptoTxt = ap ? ap.apto : ('#' + pend.apto_id);
            var comoTxt = (pend.tipo === 'visitante_nuevo')
                            ? '👥 visitante del apto ' + esc(aptoTxt)
                            : ((pend.rol === 'inquilino' ? '🏠 inquilino' : '🔑 propietario') +
                               ' del apto ' + esc(aptoTxt));
            box.innerHTML =
              '<div style="margin-top:7px;padding:9px 11px;border-radius:8px;' +
              'background:#dcfce7;border:1px solid #86efac;font-size:13.5px">' +
              '<b style="color:#166534">✅ Ya la registraste</b>' +
              ' <span class="pill pend">sin subir</span><br>' +
              '<span style="color:#166534">Quedará como <b>' + comoTxt + '</b></span><br>' +
              '<span class="muted" style="font-size:12px">Sube al Sincronizar. ' +
              'No hace falta registrarla otra vez.</span></div>';
          });
          return;
        }
        // No hay registro pendiente: ofrecer las 3 opciones
        box.innerHTML =
          '<div style="margin-top:7px;padding:9px 11px;border-radius:8px;' +
          'background:#fef3c7;border:1px solid #fcd34d;font-size:13.5px">' +
          '<b style="color:#92400e">⚠️ NO registrada</b> en la base de datos<br>' +
          '<div style="margin-top:7px;display:flex;gap:5px;flex-wrap:wrap">' +
            '<button class="btn sm" style="background:#1e6cff;color:#fff" ' +
                    'onclick="nuevoVehiculoRol(\'' + esc(p) + '\',\'propietario\')">🔑 Propietario</button>' +
            '<button class="btn sm" style="background:#0e7490;color:#fff" ' +
                    'onclick="nuevoVehiculoRol(\'' + esc(p) + '\',\'inquilino\')">🏠 Inquilino</button>' +
            '<button class="btn sm" style="background:#7c3aed;color:#fff" ' +
                    'onclick="nuevoVisitante(\'' + esc(p) + '\')">👥 Visitante</button>' +
          '</div></div>';
      });
    });
  }

  /* v7.76: registrar el vehículo como PROPIETARIO o INQUILINO del apto.
     Muestra confirmación clara de a qué apartamento quedó asignado. */
  window.nuevoVehiculoRol = function (placa, rol) {
    // v7.78: evitar registrar DOS veces la misma placa
    SPLote.registroPendientePlaca(placa).then(function (pend) {
      if (pend && !confirm('⚠️ Esta placa YA tiene un registro pendiente de subir.\n\n' +
                           '¿Registrarla de nuevo igual? (puede duplicar)')) return;
      _nuevoVehiculoRolSeguir(placa, rol);
    });
  };

  function _nuevoVehiculoRolSeguir(placa, rol) {
    var etiqueta = (rol === 'propietario') ? 'PROPIETARIO' : 'INQUILINO';
    var apto = prompt('Placa ' + placa + '\n\nRegistrar como ' + etiqueta +
                      '\n\n¿De qué apartamento?');
    if (!apto) return;
    SPSnap.todos('apartamentos').then(function (as) {
      var a = as.filter(function (x) { return norm(x.apto) === norm(apto); })[0];
      if (!a) { alert('❌ Apartamento no encontrado: ' + apto); return; }

      var esMoto = confirm('¿Es MOTO?\n\nAceptar = moto\nCancelar = carro');
      var tipoV  = esMoto ? 'moto' : 'carro';

      if (!confirm('Confirmá el registro:\n\n' +
                   'Placa: ' + placa + '\n' +
                   'Tipo: ' + (esMoto ? 'moto' : 'carro') + '\n' +
                   'Apartamento: ' + a.apto + '\n' +
                   'Como: ' + etiqueta + '\n\n¿Guardar?')) return;

      SPLote.vehiculoNuevo(placa, tipoV, a.id, '', '', rol).then(function () {
        alert('✅ Vehículo registrado en el celular.\n\n' +
              'Placa ' + placa + ' → Apto ' + a.apto + ' (' + etiqueta + ')\n\n' +
              'Sube al Sincronizar.');
        $('ej-info').innerHTML =
          '<p style="color:#166534;font-size:13px">✅ ' + esc(placa) +
          ' → Apto <b>' + esc(a.apto) + '</b> · ' + etiqueta.toLowerCase() +
          ' <span class="pill pend">sin subir</span></p>';
        pintarCola();
      }).catch(function (e) {
        alert('Error al registrar: ' + (e && e.message ? e.message : e));
      });
    });
  }

  window.nuevoVisitante = function (placa) {
    var apto = prompt('Placa ' + placa + '\n\n¿A qué apartamento visita?');
    if (!apto) return;
    SPSnap.todos('apartamentos').then(function (as) {
      var a = as.filter(function (x) { return norm(x.apto) === norm(apto); })[0];
      if (!a) { alert('Apartamento no encontrado: ' + apto); return; }
      var nom = prompt('Nombre del visitante (opcional):') || '';
      SPLote.visitanteNuevo(placa, 'carro', a.id, nom, '').then(function () {
        alert('✅ Visitante registrado en el celular.\n\n' +
              'Placa ' + placa + ' → visita al Apto ' + a.apto + '\n\n' +
              'Sube al Sincronizar.');
        $('ej-info').innerHTML = '<p style="color:#166534;font-size:13px">✅ ' + esc(placa) +
                                 ' → Visitante del Apto <b>' + esc(a.apto) + '</b>' +
                                 ' <span class="pill pend">sin subir</span></p>';
        pintarCola();
      });
    });
  };

  // v7.87: la función estamparFoto se ELIMINÓ.
  // El sello lo pone sp_lote.js al comprimir (una sola vez).

  window.guardarCelda = function (estado) {
    if (!EJ) return;
    var c = EJ.celdas[EJ.idx];
    var placa = norm($('ej-placa').value).replace(/[^A-Z0-9]/g, '');
    if (estado === 'ocupada' && !placa) {
      if (!confirm('Sin placa. ¿Guardar como OCUPADA igualmente?')) return;
    }
    // v7.53: evitar la MISMA placa en dos celdas distintas de esta revista
    if (estado === 'ocupada' && placa) {
      var dupCeldaId = null;
      for (var k in EJ.hechos) {
        var h = EJ.hechos[k];
        if (h && h.placa && h.celda_id !== c.id &&
            norm(h.placa).replace(/[^A-Z0-9]/g, '') === placa) {
          dupCeldaId = h.celda_id; break;
        }
      }
      if (dupCeldaId) {
        var celdaDup = EJ.celdas.filter(function (x) { return x.id === dupCeldaId; })[0];
        var nomDup = celdaDup ? celdaDup.celda : ('#' + dupCeldaId);
        if (!confirm('⚠️ La placa ' + placa + ' ya está registrada en la celda ' + nomDup +
                     ' de esta revista.\n\n¿Registrarla igual acá? (puede ser un error)')) {
          return;
        }
      }
    }
    // v7.75: si el sello todavía se está aplicando, esperarlo un instante
    // para no guardar la foto sin marca. Si ya terminó, sigue de una.
    var _esperaSello = (estado !== 'vacia' && _selloProm) ? _selloProm : Promise.resolve();

    _esperaSello.then(function () {
    return SPLote.revistaPaso(EJ.revistaId, c.id, estado, placa,
                       (estado === 'vacia' ? null : EJ.foto), null)
      .then(function () {
        EJ.hechos[c.id] = { celda_id: c.id, placa: placa, estado: estado, foto: !!EJ.foto };
        EJ.foto = null;
        pintarCola();
        // avanzar a la siguiente pendiente
        var sig = -1;
        for (var i = EJ.idx + 1; i < EJ.celdas.length; i++) {
          if (!EJ.hechos[EJ.celdas[i].id]) { sig = i; break; }
        }
        if (sig >= 0) { EJ.idx = sig; pintarEjecucion(); }
        else {
          alert('✅ Todas las celdas revisadas.\nPuedes terminar la revista.');
          pintarEjecucion();
        }
      });
    });
  };

  window.mover = function (d) {
    if (!EJ) return;
    var n = EJ.idx + d;
    if (n < 0 || n >= EJ.celdas.length) return;
    // v7.87: NO perder la foto en silencio. Si hay una foto tomada y todavía
    // no se guardó la celda, avisar antes de descartarla.
    if (EJ.foto && !confirm('⚠️ Tomaste una foto y NO guardaste esta celda.\n\n' +
                            'Si avanzás, la foto SE PIERDE.\n\n' +
                            'Aceptar = avanzar igual (pierde la foto)\n' +
                            'Cancelar = quedarme y guardar')) return;
    EJ.idx = n; EJ.foto = null;
    pintarEjecucion();
  };

  window.terminarRevista = function () {
    if (!EJ) return;
    var f = Object.keys(EJ.hechos).length, t = EJ.celdas.length;
    if (f < t && !confirm('Faltan ' + (t - f) + ' celdas.\n¿Terminar igualmente?\n\nLas celdas sin revisar se marcarán automáticamente como VACÍAS.')) return;

    var revId = EJ.revistaId;
    // Celdas que el rondero NO revisó
    var pendientes = EJ.celdas.filter(function (c) { return !EJ.hechos[c.id]; });
    var autoV = pendientes.length;

    // v7.54: AUTO-MARCADO ANTES de cerrar. Cada celda vacía se encola como un
    // paso NORMAL (igual que si el rondero la marcara vacía) y se registra en
    // EJ.hechos. Recién cuando TODAS están encoladas, se hace el cierre.
    // Esto evita el timing asíncrono que dejaba vacías sin subir.
    var cadena = pendientes.reduce(function (prom, c) {
      return prom.then(function () {
        return SPLote.revistaPaso(revId, c.id, 'vacia', '', null, null).then(function () {
          EJ.hechos[c.id] = { celda_id: c.id, placa: '', estado: 'vacia', foto: false };
        });
      });
    }, Promise.resolve());

    cadena.then(function () {
      // ahora sí, cerrar (sin más vacías: ya están todas encoladas arriba)
      return SPLote.revistaCerrar(revId, []);
    }).then(function () {
      return SPLote.pasosDe(revId);
    }).then(function (enCola) {
      var msg = '🏁 Revista terminada.\n\n' + t + ' celdas en total.';
      msg += '\n✅ ' + f + ' revisadas manualmente.';
      if (autoV > 0) msg += '\n⬜ ' + autoV + ' marcadas automáticamente como vacías.';
      msg += '\n📦 ' + enCola.length + ' pasos en cola para subir.';
      if (enCola.length < t) {
        msg += '\n⚠️ OJO: deberían ser ' + t + '. Avisá si el número es menor.';
      }
      msg += '\n\nPulsá Sincronizar para subirla.';
      alert(msg);
      EJ = null;
      pintarCola();
      tabRevistas('curso');
    }).catch(function (e) {
      alert('Error al terminar: ' + (e && e.message ? e.message : e));
    });
  };

  /* v7.58: cancelar la revista en curso (marca cancelada y sube al sincronizar) */
  window.cancelarRevista = function () {
    if (!EJ) return;
    if (!confirm('¿CANCELAR esta revista?\n\nQuedará marcada como CANCELADA (no terminada). Esto no se puede deshacer.')) return;
    var revId = EJ.revistaId;
    SPLote.revistaCancelar(revId).then(function () {
      alert('⏹️ Revista cancelada.\n\nPulsá Sincronizar para subir el cambio.');
      EJ = null;
      pintarCola();
      tabRevistas('curso');
    }).catch(function (e) {
      alert('Error al cancelar: ' + (e && e.message ? e.message : e));
    });
  };

  window.salirEjecucion = function () { EJ = null; verRevistas(); };

  /* ═════════ NOVEDADES ═════════ */
  window.irNovedad = function (placa, vehId) {
    document.querySelector('.nav button[data-s="novedades"]').click();
    $('nov-placa').value = placa || '';
    $('nov-desc').focus();
    $('nov-desc').setAttribute('data-veh', vehId || '');
  };

  var NOV_FOTOS = [];

  window.addFotoNovedad = function (file) {
    if (NOV_FOTOS.length >= 4) { alert('Máximo 4 fotos'); return; }
    NOV_FOTOS.push(file);
    var box = $('nov-fotos');
    var u = URL.createObjectURL(file);
    var d = document.createElement('div');
    d.style.cssText = 'position:relative;display:inline-block;margin:4px';
    d.innerHTML = '<img src="' + u + '" style="width:74px;height:74px;object-fit:cover;border-radius:7px">' +
                  '<button style="position:absolute;top:-6px;right:-6px;width:22px;height:22px;' +
                  'border-radius:50%;border:0;background:#ef4444;color:#fff;cursor:pointer;' +
                  'font-size:13px;line-height:1">×</button>';
    d.querySelector('button').onclick = function () {
      var i = NOV_FOTOS.indexOf(file);
      if (i >= 0) NOV_FOTOS.splice(i, 1);
      URL.revokeObjectURL(u);
      d.remove();
    };
    box.appendChild(d);
  };

  window.guardarNovedad = function () {
    var placa = norm($('nov-placa').value).replace(/[^A-Z0-9]/g, '');
    var desc  = $('nov-desc').value.trim();
    var tipo  = $('nov-tipo').value;
    var grav  = $('nov-grav') ? $('nov-grav').value : 'ninguna';
    var vehId = $('nov-desc').getAttribute('data-veh') || null;

    if (!placa) { alert('Escribe la placa'); return; }
    if (!desc)  { alert('Escribe la descripción'); return; }

    var btn = $('nov-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Guardando…'; }

    // SPLote comprime las fotos a 1280px/q0.75 (~80 KB) antes de encolarlas
    SPLote.novedad(vehId, placa, tipo, grav, desc, NOV_FOTOS)
      .then(function () {
        alert('📥 Novedad guardada en el celular' +
              (NOV_FOTOS.length ? ' con ' + NOV_FOTOS.length + ' foto(s)' : '') +
              '.\nSe subirá al pulsar Sincronizar.');
        $('nov-placa').value = '';
        $('nov-desc').value = '';
        $('nov-desc').removeAttribute('data-veh');
        $('nov-fotos').innerHTML = '';
        NOV_FOTOS = [];
        pintarCola();
      })
      .catch(function (e) { alert('Error: ' + (e.message || e)); })
      .then(function () {
        if (btn) { btn.disabled = false; btn.textContent = '💾 Guardar novedad'; }
      });
  };

  function verNovedades() {
    var f = norm(($('q-nov') && $('q-nov').value) || '').replace(/[^A-Z0-9]/g, '');

    Promise.all([
      SPSnap.todos('observaciones'),
      SPLote.listar(),          // las que aun no subieron
      SPSnap.todos('vehiculos') // para resolver el apto de las pendientes
    ]).then(function (r) {
      var os   = r[0];
      var pend = r[1].filter(function (i) { return i.tipo === 'novedad'; });
      var vhs  = r[2];
      var R    = $('r-novedades');

      // las pendientes no traen apto: se resuelve por la placa
      var aptoDe = {};
      vhs.forEach(function (v) { if (v.placa) aptoDe[v.placa] = v.apto; });
      pend.forEach(function (p) { p._apto = aptoDe[p.placa] || ''; });

      // El filtro busca en PLACA y en APTO a la vez. Escribis "916" y salen
      // las novedades de ese apartamento; escribis "NPU" y salen las de esa placa.
      if (f) {
        var coincide = function (placa, apto) {
          return (placa || '').indexOf(f) >= 0 || norm(apto || '').indexOf(f) === 0;
        };
        os   = os.filter(function (o) { return coincide(o.placa, o.apto); });
        pend = pend.filter(function (p) { return coincide(p.placa, p._apto); });
      }

      var h = '';

      // pendientes de subir (primero, para que se vean)
      if (pend.length) {
        h += '<p class="muted" style="margin:8px 0 4px"><b>⏳ Sin subir (' +
             pend.length + ')</b></p><table>' +
             '<tr><th>Placa</th><th>Apto</th><th>Tipo</th><th>Descripción</th><th>Fotos</th></tr>';
        pend.forEach(function (p) {
          h += '<tr style="background:#fffbeb">' +
               '<td><span class="plc">' + esc(p.placa) + '</span><br>' +
               '<span class="pill pend">sin subir</span></td>' +
               '<td><b>' + (p._apto ? esc(p._apto) : '—') + '</b></td>' +
               '<td>' + esc(p.tipo_obs || '') + '</td>' +
               '<td>' + esc(p.descripcion || '') + '</td>' +
               '<td>' + ((p.fotos && p.fotos.length) ? '📷 ' + p.fotos.length : '—') + '</td></tr>';
        });
        h += '</table>';
      }

      if (!os.length && !pend.length) {
        R.innerHTML = '<p class="vacio">' +
          (f ? 'Sin novedades para "' + esc(f) + '"' : 'Sin novedades') + '</p>';
        return;
      }

      if (os.length) {
        h += '<p class="muted" style="margin:12px 0 4px"><b>Registradas (' +
             os.length + ')</b></p>' +
             '<table><tr><th>Fecha</th><th>Placa</th><th>Apto</th>' +
             '<th>Tipo</th><th>Descripción</th></tr>';
        os.slice(0, 100).forEach(function (o) {
          var g = (GRAV[o.gravedad] || '').split('|');
          h += '<tr><td>' + esc((o.creado||'').substr(0,10)) + '<br>' +
               '<span class="muted">' + esc((o.creado||'').substr(11,5)) + '</span></td>' +
               '<td><span class="plc">' + esc(o.placa) + '</span></td>' +
               '<td>' + (o.apto
                  ? '<b style="cursor:pointer;color:#1e6cff" ' +
                    'onclick="verAptoDesdeNov(\'' + esc(o.apto) + '\')">' + esc(o.apto) + '</b>'
                  : '<span class="muted">—</span>') + '</td>' +
               '<td>' + esc(o.tipo) +
               (o.gravedad && g.length === 2
                  ? '<br><span class="pill" style="background:' + g[0] + ';color:' + g[1] + '">' +
                    esc(o.gravedad) + '</span>' : '') +
               '</td>' +
               '<td>' + esc(o.desc) +
               (o.usuario ? '<br><span class="muted">— ' + esc(o.usuario) + '</span>' : '') +
               '</td></tr>';
        });
        h += '</table>';
        if (os.length > 100) h += '<p class="muted">Mostrando 100 de ' + os.length + '</p>';
      }
      R.innerHTML = h;
    });
  }

  /* Tocar el apto en el historial -> lleva a la ficha completa del apartamento */
  window.verAptoDesdeNov = function (apto) {
    document.querySelector('.nav button[data-s="consulta"]').click();
    $('q-apto').value = apto;
    window.bApto();
  };

  /* ═════════ OCR ═════════ */
  $('inp-cam').onchange  = function (e) {
    if (e.target.files[0]) leerFoto(e.target.files[0]);
    e.target.value = '';           // permite volver a elegir la MISMA foto
  };
  $('inp-file').onchange = function (e) {
    if (e.target.files[0]) leerFoto(e.target.files[0]);
    e.target.value = '';
  };

  function leerFoto(file) {
    var box = $('ocr-res');

    // Mostrar SIEMPRE la foto, aunque el OCR no este listo.
    // Antes, si el modelo aun cargaba, el boton "no hacia nada": ni preview.
    var prev = URL.createObjectURL(file);
    box.innerHTML = '<img src="' + prev + '" style="max-width:100%;max-height:220px;' +
                    'border-radius:8px;display:block;margin-bottom:8px">' +
                    '<p class="muted" id="ocr-msg">⏳ Leyendo placa...</p>';

    if (typeof SPOCR === 'undefined' || typeof ort === 'undefined') {
      $('ocr-msg').innerHTML =
        '<span style="color:#991b1b">El motor OCR no cargó.</span> Escribe la placa abajo.';
      $('q-placa').focus();
      return;
    }

    // Si el modelo todavia esta descargando/inicializando, ESPERARLO.
    var listo = SPOCR.listo()
      ? Promise.resolve()
      : SPOCR.init({ base: window.OCR_BASE });

    listo.then(function () { correrOcr(file); })
         .catch(function (e) {
           $('ocr-msg').innerHTML =
             '<span style="color:#991b1b">OCR no disponible.</span> Escribe la placa abajo.';
           console.warn('OCR:', e);
         });
  }

  function correrOcr(file) {
    var box = $('ocr-res');
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      SPSnap.placas()
        .then(function (p) { return SPOCR.leer(img, { padron: p, moto: $('es-moto').checked }); })
        .then(function (r) {
          URL.revokeObjectURL(url);
          var msg = $('ocr-msg');
          if (r.zona === 'VERDE' && r.placa) {
            if (msg) msg.innerHTML = '<span style="color:#166534;font-size:17px"><b>✅ ' +
                                     esc(r.placa) + '</b></span>';
            $('q-placa').value = r.placa;
            window.bPlaca();
            return;
          }
          if (r.zona === 'AMARILLA' && r.sugerencias.length) {
            var h = '<span style="color:#92400e"><b>⚠️ Confirma la placa:</b></span>' +
                    '<div class="row" style="margin-top:6px">';
            r.sugerencias.forEach(function (s) {
              var pl = (typeof s === 'string') ? s : s.placa;
              var nv = (typeof s === 'object') && s.nuevo;
              h += '<button class="btn" onclick="usarPlaca(\'' + esc(pl) + '\')" ' +
                   'style="border:2px solid ' + (nv ? '#1e6cff' : '#d1d5db') + '">' +
                   '<b>' + esc(pl) + '</b><br><span class="muted">' +
                   (nv ? 'nuevo' : 'en la BD') + '</span></button>';
            });
            if (msg) msg.innerHTML = h + '</div>';
            return;
          }
          if (msg) msg.innerHTML = '<span style="color:#991b1b">❌ No se pudo leer.</span> ' +
                                   'Escribe la placa abajo.';
          $('q-placa').focus();
        })
        .catch(function (e) {
          URL.revokeObjectURL(url);
          var m = $('ocr-msg');
          if (m) m.innerHTML = '<span style="color:#991b1b">Error: ' + esc(e.message) + '</span>';
        });
    };
    img.src = url;
  }

  window.usarPlaca = function (p) {
    $('q-placa').value = p;
    var m = $('ocr-msg');
    if (m) m.innerHTML = '<span style="color:#166534;font-size:17px"><b>✅ ' + esc(p) + '</b></span>';
    window.bPlaca();
  };

  /* Enter en los inputs */
  var mapa = { 'q-placa': bPlaca, 'q-apto': bApto, 'q-res': bRes, 'q-celda': bCelda,
               'q-veh': bVeh, 'q-resi': bResi, 'q-resi-apto': bResiApto, 'q-park': bPark };
  Object.keys(mapa).forEach(function (id) {
    var el = $(id);
    if (el) el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); mapa[id](); }
    });
  });

  /* tabs de revistas */
  Array.prototype.forEach.call(document.querySelectorAll('#rev-tabs button'), function (b) {
    b.onclick = function () { tabRevistas(this.getAttribute('data-rt')); };
  });

  /* filtro del historial de novedades */
  if ($('q-nov')) {
    $('q-nov').oninput = function () { verNovedades(); };
  }

  /* ═════════ INIT ═════════ */
  pintarRed();

  SPSnap.init()
    .then(function () { pintarEstado(); })
    .catch(function (e) { console.warn('Snapshot:', e); pintarEstado(); });

  SPLote.init()
    .then(function () {
      pintarCola();
      SPLote.onCambio(pintarCola);
    })
    .catch(function (e) { console.warn('Lote:', e); });

  function estadoOcr(txt, color) {
    var e = $('ocr-estado');
    if (e) { e.textContent = txt; e.style.color = color || '#6b7280'; }
  }

  if (typeof SPOCR === 'undefined' || typeof ort === 'undefined') {
    estadoOcr('❌ El motor OCR no cargó (falta ort.min.js o sp_ocr.js)', '#991b1b');
  } else {
    estadoOcr('⏳ Cargando motor OCR (16 MB, solo la primera vez)…');
    SPOCR.init({ base: window.OCR_BASE })
      .then(function () {
        estadoOcr('✅ OCR listo · funciona sin internet', '#166534');
        console.log('OCR listo');
      })
      .catch(function (e) {
        estadoOcr('❌ OCR no disponible: ' + e.message, '#991b1b');
        console.warn('OCR no disponible:', e.message);
      });
  }

  // Service Worker (para que la pagina quede cacheada)
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(function () {});
  }
})();
