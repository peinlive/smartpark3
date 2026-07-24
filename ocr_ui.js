/* /assets/ocr/ocr_ui.js
 * SmartPark — UI del banco de pruebas OCR
 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  var DEMO = [
    'PKK149','WCN641','ELL680','DFR282','DSR810','EGZ046','EHK053','ENM106',
    'ENV987','EOK394','FAX672','FGM560','FVN286','GEZ044','HPK913','IEY424',
    'JIU922','JQR106','JQT369','JZM122','KAP416','KIC036','KPT977','MNE948',
    'MUK656','QIX448','QOW985','RAM122','TFQ470','DES143','DMK840','BPW232',
    'UYX23F','NVZ61F','RGE94G','CGQ68E','JLB94F','MNS38C','TPE15F','HQL18E',
    'VUJ47E','UZH15F','EYE41D','CJN57H','LSW52F','RCE24F','EFG38I'
  ];

  /* ---------- padrón ---------- */
  function getPadron() {
    return ($('padron').value || '')
      .toUpperCase()
      .split(/[\s,;]+/)
      .map(function (s) { return s.replace(/[^A-Z0-9]/g, ''); })
      .filter(function (s) { return s.length === 6; });
  }
  function refrescarPadron() {
    $('nPadron').textContent = getPadron().length + ' placas';
  }

  /* ---------- estado ---------- */
  function estado(txt, clase) {
    var e = $('estado');
    e.textContent = txt;
    e.className = 'estado ' + (clase || '');
  }

  function habilitar(on) {
    ['btnCam','btnFile','btnBench'].forEach(function (id) { $(id).disabled = !on; });
  }

  /* ---------- carga del modelo ---------- */
  var t0 = performance.now();
  SPOCR.init({ base: '/assets/ocr/' })
    .then(function () {
      var s = ((performance.now() - t0) / 1000).toFixed(1);
      estado('Modelo listo (' + s + ' s) · funciona sin internet', 'ok');
      habilitar(true);
    })
    .catch(function (e) {
      estado('ERROR: ' + e.message, 'err');
      console.error(e);
    });

  /* ---------- lectura de una foto ---------- */
  function leerArchivo(file) {
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      $('res').classList.remove('oculto');
      $('preview').src = url;
      $('semaforo').className = 'semaforo';
      $('semaforo').textContent = 'Procesando…';
      $('detalle').textContent = '';
      $('opciones').innerHTML = '';
      $('manual').classList.add('oculto');

      SPOCR.leer(img, { moto: $('esMoto').checked, padron: getPadron() })
        .then(function (r) { pintar(r); })
        .catch(function (e) {
          $('semaforo').className = 'semaforo roja';
          $('semaforo').textContent = 'Error: ' + e.message;
        });
    };
    img.src = url;
  }

  /* ---------- pintar resultado ---------- */
  function pintar(r) {
    var sem = $('semaforo');
    var det = $('detalle');
    var ops = $('opciones');
    ops.innerHTML = '';

    det.innerHTML =
      '<span>candidatos: <b>' + (r.candidatos.join(', ') || '—') + '</b></span>' +
      '<span>confianza: <b>' + r.conf.toFixed(3) + '</b></span>' +
      '<span>cajas: <b>' + r.cajas + '</b></span>' +
      '<span>tiempo: <b>' + Math.round(r.ms) + ' ms</b></span>';

    if (r.zona === 'VERDE') {
      sem.className = 'semaforo verde';
      sem.textContent = '✅ ' + r.placa;
      var b = document.createElement('button');
      b.className = 'btn primary grande';
      b.textContent = 'Confirmar ' + r.placa;
      b.onclick = function () { confirmar(r.placa); };
      ops.appendChild(b);
      return;
    }

    if (r.zona === 'AMARILLA') {
      sem.className = 'semaforo amarilla';
      sem.textContent = '⚠️ Confirme la placa';
      r.sugerencias.forEach(function (s) {
        var b = document.createElement('button');
        b.className = 'btn opcion' + (s.nuevo ? ' nuevo' : '');
        b.innerHTML = '<b>' + s.placa + '</b>' +
                      (s.nuevo ? '<small>vehículo nuevo / visitante</small>'
                               : '<small>está en la BD</small>');
        b.onclick = function () { confirmar(s.placa, s.nuevo); };
        ops.appendChild(b);
      });
      var no = document.createElement('button');
      no.className = 'btn opcion otra';
      no.innerHTML = '<b>Ninguna</b><small>escribir a mano</small>';
      no.onclick = manual;
      ops.appendChild(no);
      return;
    }

    sem.className = 'semaforo roja';
    sem.textContent = '❌ No se pudo leer';
    manual(r.candidatos[0] || '');
  }

  function manual(pre) {
    $('manual').classList.remove('oculto');
    $('inpPlaca').value = (typeof pre === 'string' ? pre : '').substr(0, 6);
    $('inpPlaca').focus();
  }

  function confirmar(placa, nuevo) {
    var sem = $('semaforo');
    sem.className = 'semaforo verde';
    sem.textContent = '✅ ' + placa + (nuevo ? ' (nuevo)' : '');
    $('opciones').innerHTML = '';
    $('manual').classList.add('oculto');
  }

  /* ---------- benchmark con ground truth ---------- */
  function bench(files) {
    var lista = Array.prototype.slice.call(files);
    var padron = getPadron();
    var moto = $('esMoto').checked;
    var res = [];
    var i = 0;

    $('prog').classList.remove('oculto');
    $('tabla').innerHTML = '';
    $('resumen').innerHTML = '';

    function paso() {
      if (i >= lista.length) return terminar();
      var f = lista[i++];
      $('progBar').style.width = (100 * i / lista.length) + '%';

      var real = f.name.replace(/\.[^.]+$/, '').toUpperCase().replace(/[^A-Z0-9]/g, '');
      var url = URL.createObjectURL(f);
      var img = new Image();
      img.onload = function () {
        SPOCR.leer(img, { moto: moto, padron: padron }).then(function (r) {
          var sug = r.sugerencias.map(function (s) { return s.placa; });
          var ok = (r.zona === 'VERDE' && r.placa === real);
          var enOpc = (r.zona === 'AMARILLA' && sug.indexOf(real) >= 0);
          var falso = (r.zona === 'VERDE' && r.placa !== real);
          res.push({
            real: real, zona: r.zona, placa: r.placa || (r.candidatos[0] || '—'),
            conf: r.conf, ok: ok, enOpc: enOpc, falso: falso, ms: r.ms
          });
          URL.revokeObjectURL(url);
          paso();
        }).catch(function () { URL.revokeObjectURL(url); paso(); });
      };
      img.src = url;
    }

    function terminar() {
      $('prog').classList.add('oculto');
      var n = res.length;
      var verde = res.filter(function (r) { return r.zona === 'VERDE'; }).length;
      var okv   = res.filter(function (r) { return r.ok; }).length;
      var falso = res.filter(function (r) { return r.falso; }).length;
      var amar  = res.filter(function (r) { return r.zona === 'AMARILLA'; }).length;
      var amok  = res.filter(function (r) { return r.enOpc; }).length;
      var roja  = res.filter(function (r) { return r.zona === 'ROJA'; }).length;
      var sinTeclear = okv + amok;
      var ms = res.reduce(function (a, r) { return a + r.ms; }, 0) / Math.max(n, 1);

      var h = '<table class="tb"><tr><th>Real</th><th>Zona</th><th>Leído</th>' +
              '<th>Conf</th><th>ms</th></tr>';
      res.forEach(function (r) {
        var cls = r.falso ? 'malo' : (r.ok || r.enOpc ? 'bueno' : '');
        h += '<tr class="' + cls + '"><td>' + r.real + '</td><td>' + r.zona[0] +
             '</td><td>' + r.placa + '</td><td>' + r.conf.toFixed(3) +
             '</td><td>' + Math.round(r.ms) + '</td></tr>';
      });
      h += '</table>';
      $('tabla').innerHTML = h;

      $('resumen').innerHTML =
        '<div class="sum">' +
        '<div><b>' + n + '</b><small>fotos</small></div>' +
        '<div class="v"><b>' + verde + '</b><small>verde (' + okv + ' ok)</small></div>' +
        '<div class="a"><b>' + amar + '</b><small>amarilla (' + amok + ' acierta)</small></div>' +
        '<div class="r"><b>' + roja + '</b><small>roja</small></div>' +
        '</div>' +
        '<p class="big">Resuelto sin teclear: <b>' + sinTeclear + '/' + n + '</b> (' +
        Math.round(100 * sinTeclear / Math.max(n, 1)) + '%)</p>' +
        '<p class="big' + (falso ? ' malo' : '') + '">Falsos positivos: <b>' + falso +
        '</b> (' + Math.round(100 * falso / Math.max(n, 1)) + '%)</p>' +
        '<p class="hint">Tiempo medio: ' + Math.round(ms) + ' ms/foto</p>';
    }

    paso();
  }

  /* ---------- eventos ---------- */
  $('btnCam').onclick   = function () { $('inpCam').click(); };
  $('btnFile').onclick  = function () { $('inpFile').click(); };
  $('btnBench').onclick = function () { $('inpBench').click(); };

  $('inpCam').onchange   = function (e) { if (e.target.files[0]) leerArchivo(e.target.files[0]); };
  $('inpFile').onchange  = function (e) { if (e.target.files[0]) leerArchivo(e.target.files[0]); };
  $('inpBench').onchange = function (e) { if (e.target.files.length) bench(e.target.files); };

  $('btnDemo').onclick = function () {
    $('padron').value = DEMO.join('\n');
    refrescarPadron();
  };
  $('padron').oninput = refrescarPadron;

  $('inpPlaca').oninput = function (e) {
    e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
  };
  $('btnOk').onclick = function () {
    var v = $('inpPlaca').value;
    if (v.length === 6) confirmar(v, getPadron().indexOf(v) < 0);
  };

  refrescarPadron();
})();
