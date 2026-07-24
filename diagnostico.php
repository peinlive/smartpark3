<?php
// /modules/lectura_prueba/diagnostico.php
// Verifica que los archivos del OCR esten subidos, completos y se sirvan con el MIME correcto.
header('Content-Type: text/html; charset=utf-8');

$base = dirname(dirname(__DIR__));               // raiz del sitio
$archivos = [
  '/assets/ocr/sp_ocr.js'                          => ['min'=>10000],
  '/assets/ocr/ocr_ui.js'                          => ['min'=>5000],
  '/assets/ocr/ocr.css'                            => ['min'=>1000],
  '/assets/ocr/models/global_mobile_vit_v2_ocr.onnx'=> ['min'=>4900000,'magic'=>"\x08"],
  '/assets/ocr/vendor/ort/ort.min.js'              => ['min'=>500000],
  '/assets/ocr/vendor/ort/ort-wasm-simd.wasm'      => ['min'=>10000000,'magic'=>"\x00asm"],
  '/assets/ocr/vendor/ort/ort-wasm.wasm'           => ['min'=>9000000,'magic'=>"\x00asm"],
];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Diagnóstico OCR</title>
<style>
body{font:14px system-ui;background:#0f1115;color:#e6e8ec;padding:20px;max-width:900px;margin:0 auto}
table{width:100%;border-collapse:collapse;margin-top:16px;font-family:ui-monospace,monospace;font-size:13px}
th,td{text-align:left;padding:7px;border-bottom:1px solid #262b35}
th{color:#7d8798;font-size:11px}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#ffd44d}
h1{font-size:18px;margin-bottom:4px}
.hint{color:#7d8798;font-size:13px}
pre{background:#161a21;padding:12px;border-radius:8px;overflow:auto;font-size:12px}
</style></head><body>
<h1>Diagnóstico OCR — SmartPark</h1>
<p class="hint">Verifica que los archivos existan, pesen lo correcto y no sean HTML disfrazado.</p>
<table>
<tr><th>Archivo</th><th>Estado</th><th>Tamaño</th><th>Cabecera</th></tr>
<?php
$todoOk = true;
foreach ($archivos as $ruta => $chk) {
    $full = $base . $ruta;
    $existe = file_exists($full);
    $size = $existe ? filesize($full) : 0;
    $head = '';
    $okMagic = true;

    if ($existe) {
        $fh = fopen($full, 'rb');
        $raw = fread($fh, 8);
        fclose($fh);
        $head = bin2hex(substr($raw, 0, 4));
        // detectar HTML disfrazado (el error del OCR anterior)
        if (stripos($raw, '<!DO') === 0 || stripos($raw, '<htm') === 0 || substr($raw,0,1) === "\n") {
            $okMagic = false;
            $head .= ' ¡ES HTML!';
        }
        if (isset($chk['magic']) && strpos($raw, $chk['magic']) !== 0) {
            $okMagic = false;
        }
    }

    $okSize = $size >= $chk['min'];
    $ok = $existe && $okSize && $okMagic;
    if (!$ok) $todoOk = false;

    $cls = $ok ? 'ok' : 'err';
    $est = !$existe ? 'FALTA' : (!$okSize ? 'INCOMPLETO' : (!$okMagic ? 'CORRUPTO' : 'OK'));
    printf(
      '<tr><td>%s</td><td class="%s">%s</td><td>%s</td><td>%s</td></tr>',
      htmlspecialchars($ruta), $cls, $est,
      $existe ? number_format($size) . ' B' : '—',
      htmlspecialchars($head)
    );
}
?>
</table>

<h2 style="font-size:15px;margin-top:24px">MIME que envía el servidor</h2>
<p class="hint">El .wasm DEBE llegar como <code>application/wasm</code>. Si no, Chrome lo rechaza.</p>
<div id="mime"><em class="hint">probando…</em></div>

<script>
var pruebas = [
  ['/assets/ocr/vendor/ort/ort-wasm-simd.wasm', 'application/wasm'],
  ['/assets/ocr/models/global_mobile_vit_v2_ocr.onnx', null],
  ['/assets/ocr/vendor/ort/ort.min.js', 'javascript']
];
var out = '<table><tr><th>URL</th><th>HTTP</th><th>Content-Type</th><th>Bytes</th></tr>';
var i = 0;
(function paso(){
  if (i >= pruebas.length) { document.getElementById('mime').innerHTML = out + '</table>'; return; }
  var p = pruebas[i++];
  fetch(p[0], { method: 'HEAD' }).then(function(r){
    var ct = r.headers.get('content-type') || '(vacío)';
    var len = r.headers.get('content-length') || '?';
    var ok = !p[1] || ct.indexOf(p[1]) >= 0;
    out += '<tr><td>' + p[0].split('/').pop() + '</td>' +
           '<td class="' + (r.ok?'ok':'err') + '">' + r.status + '</td>' +
           '<td class="' + (ok?'ok':'warn') + '">' + ct + '</td>' +
           '<td>' + len + '</td></tr>';
    paso();
  }).catch(function(e){
    out += '<tr><td>' + p[0].split('/').pop() + '</td><td class="err">ERR</td><td colspan="2">' + e.message + '</td></tr>';
    paso();
  });
})();
</script>

<h2 style="font-size:15px;margin-top:24px">Soporte del navegador</h2>
<pre id="cap">…</pre>
<script>
var c = [];
c.push('WebAssembly: ' + (typeof WebAssembly !== 'undefined' ? 'SÍ' : 'NO'));
try {
  var simd = WebAssembly.validate(new Uint8Array([0,97,115,109,1,0,0,0,1,5,1,96,0,1,123,3,2,1,0,10,10,1,8,0,65,0,253,15,253,98,11]));
  c.push('SIMD: ' + (simd ? 'SÍ' : 'NO (usará ort-wasm.wasm)'));
} catch(e){ c.push('SIMD: NO'); }
c.push('SharedArrayBuffer: ' + (typeof SharedArrayBuffer !== 'undefined' ? 'SÍ' : 'NO (no hace falta)'));
c.push('crossOriginIsolated: ' + (typeof crossOriginIsolated !== 'undefined' ? crossOriginIsolated : 'n/d'));
c.push('UserAgent: ' + navigator.userAgent);
document.getElementById('cap').textContent = c.join('\n');
</script>
</body></html>
