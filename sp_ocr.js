/* /assets/ocr/sp_ocr.js
 * SmartPark — Motor OCR de placas OFFLINE  (v2.0-onnx)
 *
 * Pipeline validado empiricamente sobre 46 fotos reales con ground truth:
 *    AUTOS: 94% resuelto sin teclear  |  MOTOS: 57%  |  FALSOS POSITIVOS: 0%
 *
 * Etapas: 1) Deteccion de placa (color + geometria + textura de texto) — Canvas puro
 *         2) Recorte
 *         3) OCR con modelo ONNX DEDICADO a placas (fast-plate-ocr, 5 MB)
 *         4) Semaforo VERDE / AMARILLA / ROJA contra padron local (IndexedDB)
 *
 * REGLA DE ORO: el OCR manda. La BD solo CONFIRMA, nunca sobrescribe.
 *               Si hay dos placas compatibles => NO es un match, es una PREGUNTA.
 *               El sistema NUNCA inventa una placa que el operario no aprobo.
 */
(function (global) {
  'use strict';

  var ALPHABET  = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_';
  var SLOTS     = 9, NCH = 37;      // salida del modelo: 9 x 37 = 333
  var IMG_H     = 70, IMG_W = 140;  // input del modelo
  var MAXDIM    = 1600;             // canvas grande: de aqui sale el recorte para el OCR
  var DETDIM    = 1000;             // el DETECTOR trabaja mas chico: 2.5x mas rapido
  var CONF_MIN  = 0.055;            // medido: separa lectura buena de basura
  var CONF_VERDE= 0.065;            // umbral para autocompletar

  var session = null, ready = false, cargando = null;

  /* ---------- confusiones opticas: cuestan 0.4 en vez de 1.0 ---------- */
  var CONFUS = {};
  [['O','0'],['D','O'],['D','0'],['I','1'],['I','T'],['E','F'],['E','T'],['Z','T'],
   ['Z','2'],['S','5'],['B','8'],['G','6'],['Q','O'],['Q','0'],['D','N'],['U','V'],
   ['J','I'],['L','I'],['C','G'],['7','T'],['4','A'],['G','S'],['X','Y'],['D','L'],
   ['C','O'],['W','V'],['R','P'],['M','N'],['4','6'],['9','4'],['2','7'],['1','7']
  ].forEach(function (p) { CONFUS[p[0]+p[1]] = 1; CONFUS[p[1]+p[0]] = 1; });

  function costo(a, b) {
    if (!a || !b || a.length !== b.length) return 9;
    var c = 0;
    for (var i = 0; i < a.length; i++) {
      if (a[i] === b[i]) continue;
      c += CONFUS[a[i]+b[i]] ? 0.4 : 1.0;
    }
    return c;
  }

  /* ---------- formato colombiano: ABC123 (auto) / ABC12D (moto) ---------- */
  function validoFormato(t, moto) {
    if (!t || t.length !== 6) return false;
    if (!/^[A-Z]{3}$/.test(t.substr(0,3))) return false;
    if (!/^[0-9]{2}$/.test(t.substr(3,2))) return false;
    return moto ? /^[A-Z]$/.test(t[5]) : /^[0-9]$/.test(t[5]);
  }

  /* ================= CARGA DEL MODELO ================= */
  function init(opts) {
    opts = opts || {};
    if (ready)    return Promise.resolve(true);
    if (cargando) return cargando;

    var base = opts.base || '/assets/ocr/';
    if (typeof ort === 'undefined') {
      return Promise.reject(new Error('ONNX Runtime Web no cargado (falta vendor/ort/ort.min.js)'));
    }
    /* Rutas EXPLICITAS de cada .wasm.
       Sin esto, ORT intenta un import dinamico de un .mjs y falla en cPanel
       (el servidor no manda el MIME correcto y Chrome rechaza el modulo). */
    ort.env.wasm.wasmPaths = {
      'ort-wasm.wasm':      base + 'vendor/ort/ort-wasm.wasm',
      'ort-wasm-simd.wasm': base + 'vendor/ort/ort-wasm-simd.wasm'
    };
    ort.env.wasm.numThreads = 1;      // 1 hilo => NO usa .mjs ni exige COOP/COEP
    ort.env.wasm.proxy      = false;  // sin worker proxy
    ort.env.wasm.simd       = true;   // si el device no soporta, cae a ort-wasm.wasm
    ort.env.logLevel        = 'error';

    cargando = ort.InferenceSession.create(
      base + 'models/global_mobile_vit_v2_ocr.onnx',
      { executionProviders: ['wasm'], graphOptimizationLevel: 'all' }
    ).then(function (s) {
      session = s; ready = true; cargando = null;
      return true;
    }).catch(function (e) {
      /* reintento sin SIMD, por si el dispositivo no la soporta */
      ort.env.wasm.simd = false;
      return ort.InferenceSession.create(
        base + 'models/global_mobile_vit_v2_ocr.onnx',
        { executionProviders: ['wasm'], graphOptimizationLevel: 'basic' }
      ).then(function (s) {
        session = s; ready = true; cargando = null;
        return true;
      }).catch(function (e2) {
        cargando = null;
        throw new Error('No se pudo cargar el modelo ONNX: ' + (e2.message || e.message));
      });
    });
    return cargando;
  }

  /* ================= UTILIDADES ================= */
  function aCanvas(src, maxdim) {
    maxdim = maxdim || MAXDIM;
    var w = src.naturalWidth || src.videoWidth || src.width;
    var h = src.naturalHeight || src.videoHeight || src.height;
    var e = Math.min(1, maxdim / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width  = Math.round(w * e);
    c.height = Math.round(h * e);
    c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
    return c;
  }

  /* ================= 1) DETECCION ================= */
  /* 3 mascaras: amarillo estricto, amarillo laxo (sombra nocturna), claro (placas BLANCAS) */
  function mascaras(data, n) {
    var ye = new Uint8Array(n), yl = new Uint8Array(n), wh = new Uint8Array(n);
    for (var i = 0, p = 0; i < n; i++, p += 4) {
      var r = data[p], g = data[p+1], b = data[p+2];
      var mx = r > g ? (r > b ? r : b) : (g > b ? g : b);
      var mn = r < g ? (r < b ? r : b) : (g < b ? g : b);
      var dd = mx - mn;
      var v  = mx;
      var s  = mx === 0 ? 0 : (dd / mx) * 255;
      var hh = 0;
      if (dd !== 0) {
        if (mx === r)      hh = 60 * (((g - b) / dd) % 6);
        else if (mx === g) hh = 60 * (((b - r) / dd) + 2);
        else               hh = 60 * (((r - g) / dd) + 4);
        if (hh < 0) hh += 360;
      }
      var h2 = hh / 2;                                   // escala OpenCV (0-180)
      if (h2 >= 18 && h2 <= 40 && s >= 90 && v >= 60) ye[i] = 1;
      if (h2 >= 14 && h2 <= 45 && s >= 45 && v >= 35) yl[i] = 1;
      if (s <= 80 && v >= 120)                        wh[i] = 1;
    }
    return [ [ye, 3.0], [yl, 1.8], [wh, 1.0] ];
  }

  /* Morfologia separable con VENTANA DESLIZANTE: O(1) por pixel.
     Sin esto, un kernel 35x15 hace 35+15 comparaciones por pixel y en un
     celular con 400 fotos se vuelve inviable. Aqui se mantiene un contador
     de pixeles activos en la ventana y solo se actualiza al entrar/salir. */
  function dil(m, W, H, kx, ky) {
    var t = new Uint8Array(W*H), o = new Uint8Array(W*H);
    var hx = kx>>1, hy = ky>>1, x, y, cnt, i;
    for (y = 0; y < H; y++) {
      var fila = y*W;
      cnt = 0;
      for (i = 0; i <= hx && i < W; i++) if (m[fila+i]) cnt++;
      for (x = 0; x < W; x++) {
        t[fila+x] = cnt > 0 ? 1 : 0;
        var sale = x - hx, entra = x + hx + 1;
        if (sale >= 0 && m[fila+sale]) cnt--;
        if (entra < W && m[fila+entra]) cnt++;
      }
    }
    for (x = 0; x < W; x++) {
      cnt = 0;
      for (i = 0; i <= hy && i < H; i++) if (t[i*W+x]) cnt++;
      for (y = 0; y < H; y++) {
        o[y*W+x] = cnt > 0 ? 1 : 0;
        var s2 = y - hy, e2 = y + hy + 1;
        if (s2 >= 0 && t[s2*W+x]) cnt--;
        if (e2 < H && t[e2*W+x]) cnt++;
      }
    }
    return o;
  }
  function ero(m, W, H, kx, ky) {
    var t = new Uint8Array(W*H), o = new Uint8Array(W*H);
    var hx = kx>>1, hy = ky>>1, x, y, cnt, i, ancho, alto;
    for (y = 0; y < H; y++) {
      var fila = y*W;
      cnt = 0;
      for (i = 0; i <= hx && i < W; i++) if (m[fila+i]) cnt++;
      for (x = 0; x < W; x++) {
        ancho = Math.min(x+hx, W-1) - Math.max(x-hx, 0) + 1;
        t[fila+x] = (cnt === ancho && x-hx >= 0 && x+hx < W) ? 1 : 0;
        var sale = x - hx, entra = x + hx + 1;
        if (sale >= 0 && m[fila+sale]) cnt--;
        if (entra < W && m[fila+entra]) cnt++;
      }
    }
    for (x = 0; x < W; x++) {
      cnt = 0;
      for (i = 0; i <= hy && i < H; i++) if (t[i*W+x]) cnt++;
      for (y = 0; y < H; y++) {
        alto = Math.min(y+hy, H-1) - Math.max(y-hy, 0) + 1;
        o[y*W+x] = (cnt === alto && y-hy >= 0 && y+hy < H) ? 1 : 0;
        var s2 = y - hy, e2 = y + hy + 1;
        if (s2 >= 0 && t[s2*W+x]) cnt--;
        if (e2 < H && t[e2*W+x]) cnt++;
      }
    }
    return o;
  }
  function cerrar(m,W,H,kx,ky){ return ero(dil(m,W,H,kx,ky),W,H,kx,ky); }
  function abrir (m,W,H,kx,ky){ return dil(ero(m,W,H,kx,ky),W,H,kx,ky); }

  /* componentes conexas (stack iterativo, sin recursion) */
  function componentes(m, W, H) {
    var lab = new Uint8Array(W*H), out = [], stack = new Int32Array(W*H);
    for (var s0 = 0; s0 < W*H; s0++) {
      if (!m[s0] || lab[s0]) continue;
      var sp = 0; stack[sp++] = s0; lab[s0] = 1;
      var minx = W, miny = H, maxx = 0, maxy = 0, area = 0;
      while (sp > 0) {
        var q  = stack[--sp];
        var qx = q % W, qy = (q / W) | 0;
        area++;
        if (qx < minx) minx = qx;
        if (qx > maxx) maxx = qx;
        if (qy < miny) miny = qy;
        if (qy > maxy) maxy = qy;
        for (var dy = -1; dy <= 1; dy++) for (var dx = -1; dx <= 1; dx++) {
          var nx = qx+dx, ny = qy+dy;
          if (nx < 0 || ny < 0 || nx >= W || ny >= H) continue;
          var ni = ny*W + nx;
          if (m[ni] && !lab[ni]) { lab[ni] = 1; stack[sp++] = ni; }
        }
      }
      out.push({ x:minx, y:miny, w:maxx-minx+1, h:maxy-miny+1, area:area });
    }
    return out;
  }

  /* densidad de bordes: la placa TIENE texto adentro. Descarta pared/ladrillo/reflejo. */
  function bordes(gray, W, bx, by, bw, bh) {
    var cnt = 0, tot = 0;
    for (var y = by+1; y < by+bh-1; y++) {
      for (var x = bx+1; x < bx+bw-1; x++) {
        var i  = y*W + x;
        var gx = gray[i+1] - gray[i-1]; if (gx < 0) gx = -gx;
        var gy = gray[i+W] - gray[i-W]; if (gy < 0) gy = -gy;
        if (gx + gy > 60) cnt++;
        tot++;
      }
    }
    return tot ? cnt/tot : 0;
  }

  function detectar(cv, maxCajas) {
    var W = cv.width, H = cv.height, n = W*H, area = n;
    var ctx  = cv.getContext('2d', { willReadFrequently: true });
    var data = ctx.getImageData(0, 0, W, H).data;

    var gray = new Uint8Array(n);
    for (var i = 0, p = 0; i < n; i++, p += 4) {
      gray[i] = (data[p]*0.299 + data[p+1]*0.587 + data[p+2]*0.114) | 0;
    }

    var fuentes = mascaras(data, n);
    var kernels = [[5,5],[15,7],[25,11],[35,15]];  // 4 dilataciones: unen placas partidas
    var cajas = [];

    for (var f = 0; f < fuentes.length; f++) {
      for (var k = 0; k < kernels.length; k++) {
        var m = cerrar(fuentes[f][0], W, H, kernels[k][0], kernels[k][1]);
        m = abrir(m, W, H, 3, 3);
        var comps = componentes(m, W, H);
        for (var c = 0; c < comps.length; c++) {
          var b = comps[c];
          if (b.w < 30 || b.h < 12) continue;
          var ar = b.w / b.h;
          if (ar < 1.0 || ar > 4.5) continue;            // placa: nunca mas alta que ancha
          var frac = (b.w * b.h) / area;
          if (frac < 0.0003 || frac > 0.08) continue;    // MATA la mancha gigante (pared)
          var fill = b.area / (b.w * b.h);
          if (fill < 0.40) continue;                     // debe ser rectangulo solido
          var ed = bordes(gray, W, b.x, b.y, b.w, b.h);
          if (ed < 0.03) continue;                       // EXIGE textura de texto

          /* --- descartar el TIMESTAMP de la camara ---
             Es una caja OSCURA con texto claro, pegada al borde inferior.
             La placa real es BRILLANTE (fondo amarillo/blanco). */
          var brillo = 0, np2 = 0;
          for (var yy = b.y; yy < b.y + b.h; yy += 2) {
            for (var xx = b.x; xx < b.x + b.w; xx += 2) {
              brillo += gray[yy*W + xx]; np2++;
            }
          }
          brillo = np2 ? brillo/np2 : 0;
          var cyRel = (b.y + b.h/2) / H;
          if (cyRel > 0.93 && brillo < 130) continue;    // reloj pegado abajo
          if (brillo < 70) continue;                     // caja demasiado oscura: no es placa

          b.score = b.w * b.h * fill * fuentes[f][1] * (1 + 3.0*ed) * (1 + brillo/255);
          cajas.push(b);
        }
      }
    }
    cajas.sort(function (a,b) { return b.score - a.score; });

    var out = [], usados = [];
    for (var j = 0; j < cajas.length && out.length < (maxCajas||18); j++) {
      var b2 = cajas[j];
      var cx = b2.x + b2.w/2, cy = b2.y + b2.h/2, dup = false;
      for (var u = 0; u < usados.length; u++) {
        if (Math.abs(cx-usados[u][0]) < usados[u][2]*0.35 &&
            Math.abs(cy-usados[u][1]) < usados[u][3]*0.35) { dup = true; break; }
      }
      if (dup) continue;
      usados.push([cx, cy, b2.w, b2.h]);
      out.push(b2);
    }
    return out;
  }

  /* ================= 2) RECORTE ================= */
  function recortar(cv, b, pad) {
    pad = pad || 0;
    var px = b.w*pad, py = b.h*pad;
    var x = Math.max(0, b.x - px), y = Math.max(0, b.y - py);
    var w = Math.min(cv.width  - x, b.w + 2*px);
    var h = Math.min(cv.height - y, b.h + 2*py);
    var o = document.createElement('canvas');
    o.width = IMG_W; o.height = IMG_H;
    o.getContext('2d').drawImage(cv, x, y, w, h, 0, 0, IMG_W, IMG_H);
    return o;
  }

  /* ================= 3) OCR (ONNX) ================= */
  function ocrCanvas(cn) {
    var ctx = cn.getContext('2d', { willReadFrequently: true });
    var im  = ctx.getImageData(0, 0, IMG_W, IMG_H).data;
    var buf = new Uint8Array(IMG_H*IMG_W);
    for (var i = 0, p = 0; i < IMG_H*IMG_W; i++, p += 4) {
      buf[i] = (im[p]*0.299 + im[p+1]*0.587 + im[p+2]*0.114) | 0;
    }
    var feeds = {};
    feeds[session.inputNames[0]] = new ort.Tensor('uint8', buf, [1, IMG_H, IMG_W, 1]);

    return session.run(feeds).then(function (r) {
      var out = r[session.outputNames[0]].data;   // 333 floats
      var txt = '', sum = 0, cnt = 0;
      for (var s = 0; s < SLOTS; s++) {
        var off = s*NCH, mx = -Infinity, j;
        for (j = 0; j < NCH; j++) if (out[off+j] > mx) mx = out[off+j];
        var tot = 0, bi = 0, bv = -1;
        for (j = 0; j < NCH; j++) {
          var e = Math.exp(out[off+j] - mx);
          tot += e;
          if (e > bv) { bv = e; bi = j; }
        }
        var prob = bv / tot;
        var ch = ALPHABET[bi];
        if (ch === '_') continue;
        txt += ch; sum += prob; cnt++;
      }
      return { texto: txt, conf: cnt ? sum/cnt : 0 };
    });
  }

  /* ================= 4) SEMAFORO ================= */
  /* Si hay ambiguedad REAL => se PREGUNTA. Nunca se adivina.
     Pero si la lectura es exacta, de alta confianza y esta en la BD,
     no tiene sentido molestar al operario: va a VERDE. */
  function decidir(ranked, conf, padron, moto) {
    if (!ranked.length || conf < CONF_MIN) {
      return { zona:'ROJA', sugerencias:[], placa:null };
    }
    var top   = ranked[0];
    var fmt   = validoFormato(top, moto);
    var enBD  = padron.indexOf(top) >= 0;

    /* vecinas del padron confundibles con la lectura */
    var comp  = padron.filter(function (p) { return p !== top && costo(top,p) <= 1.3; })
                      .sort(function (a,b) { return costo(top,a) - costo(top,b); });

    /* --- VERDE ---
       Condiciones: formato valido + confianza alta + la lectura EXACTA esta en la BD.
       Una "vecina" solo bloquea el verde si es MUY confundible (costo < 0.9),
       es decir, si difiere en una sola confusion optica (O/0, Q/O, I/1...).
       Si difiere en un caracter REAL (costo >= 1.0), no hay ambiguedad:
       el OCR leyo lo que leyo y coincide exacto con una placa del padron. */
    if (enBD && fmt && conf >= CONF_VERDE) {
      var ambigua = comp.some(function (p) { return costo(top,p) < 0.9; });
      if (!ambigua) return { zona:'VERDE', sugerencias:[top], placa:top };
    }

    /* --- AMARILLA: mostrar opciones. NUNCA elegir sola. --- */
    var sug = [];
    if (enBD) sug.push({ placa: top, nuevo: false });
    comp.slice(0,3).forEach(function (p) {
      if (!sug.some(function (s) { return s.placa === p; })) sug.push({ placa:p, nuevo:false });
    });
    ranked.slice(1).forEach(function (c) {
      if (padron.indexOf(c) >= 0 && !sug.some(function (s) { return s.placa === c; })) {
        sug.push({ placa:c, nuevo:false });
      }
    });
    /* SIEMPRE ofrecer la lectura cruda como vehiculo NUEVO (visitante) */
    if (fmt && !sug.some(function (s) { return s.placa === top; })) {
      sug.push({ placa: top, nuevo: true });
    }

    if (!sug.length) return { zona:'ROJA', sugerencias:[], placa:null };
    return { zona:'AMARILLA', sugerencias: sug.slice(0,4), placa:null };
  }

  /* ================= API PUBLICA ================= */
  /**
   * Lee la placa de una imagen.
   * @param {HTMLImageElement|HTMLCanvasElement|HTMLVideoElement} src
   * @param {Object} opts  { moto:Boolean, padron:[String], maxCajas:Number }
   * @returns {Promise<{zona,placa,sugerencias,conf,candidatos,cajas,ms}>}
   */
  function leer(src, opts) {
    opts = opts || {};
    var moto   = !!opts.moto;
    var padron = opts.padron || [];
    var t0     = performance.now();

    if (!ready) return Promise.reject(new Error('Modelo no inicializado — llame a SPOCR.init()'));

    var cv    = aCanvas(src, MAXDIM);          // canvas grande (para el recorte del OCR)
    var cvDet = aCanvas(src, DETDIM);          // canvas chico (para detectar: mucho mas rapido)
    var esc   = cv.width / cvDet.width;        // factor para mapear cajas de uno a otro
    var cajasDet = detectar(cvDet, opts.maxCajas || 18);
    var cajas = cajasDet.map(function (b) {
      return { x: b.x*esc, y: b.y*esc, w: b.w*esc, h: b.h*esc, area: b.area*esc*esc };
    });

    if (!cajas.length) {
      return Promise.resolve({
        zona:'ROJA', placa:null, sugerencias:[], conf:0,
        candidatos:[], cajas:0, ms: performance.now()-t0
      });
    }

    /* OCR sobre cada caja con 2 paddings; gana el de mayor score */
    var tareas = [];
    cajas.forEach(function (b) {
      tareas.push({ b:b, pad:0.0 });
      tareas.push({ b:b, pad:0.10 });
    });

    var votos = {}, idx = 0;
    function paso() {
      if (idx >= tareas.length) return Promise.resolve();
      var t = tareas[idx++];
      return ocrCanvas(recortar(cv, t.b, t.pad)).then(function (r) {
        if (r.texto) {
          var sc = r.conf * (validoFormato(r.texto, moto) ? 3.0 : 1.0);
          if (!votos[r.texto] || sc > votos[r.texto].score) {
            votos[r.texto] = { score:sc, conf:r.conf };
          }
        }
        return paso();
      });
    }

    return paso().then(function () {
      var ranked = Object.keys(votos)
        .sort(function (a,b) { return votos[b].score - votos[a].score; })
        .slice(0,3);
      var conf = ranked.length ? votos[ranked[0]].conf : 0;
      var res  = decidir(ranked, conf, padron, moto);
      res.conf       = conf;
      res.candidatos = ranked;
      res.cajas      = cajas.length;
      res.ms         = performance.now() - t0;
      return res;
    });
  }

  global.SPOCR = {
    init: init,
    leer: leer,
    listo: function () { return ready; },
    validoFormato: validoFormato,
    costo: costo,
    CONF_MIN: CONF_MIN,
    CONF_VERDE: CONF_VERDE,
    version: '2.0-onnx'
  };
})(window);
