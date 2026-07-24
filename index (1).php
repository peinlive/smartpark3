<?php
// /modules/lectura_prueba/index.php
// SmartPark — Banco de pruebas del OCR OFFLINE (v2.0-onnx)
// NO toca la base de datos. NO usa internet una vez cargado el modelo.
// Objetivo: validar el pipeline en el celular real ANTES de integrarlo a /revistas.
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>SmartPark — Prueba OCR Offline</title>
<link rel="stylesheet" href="/assets/ocr/ocr.css">
</head>
<body>

<header class="hdr">
  <h1>OCR de placas · Offline</h1>
  <div id="estado" class="estado cargando">Cargando modelo…</div>
</header>

<main class="wrap">

  <section class="card">
    <label class="sw">
      <input type="checkbox" id="esMoto">
      <span>Es <b>moto</b> (formato ABC12D)</span>
    </label>

    <div class="btns">
      <button id="btnCam"  class="btn primary" disabled>📷 Tomar foto</button>
      <button id="btnFile" class="btn"         disabled>🖼️ Elegir imagen</button>
    </div>

    <input type="file" id="inpCam"  accept="image/*" capture="environment" hidden>
    <input type="file" id="inpFile" accept="image/*" hidden>

    <details class="det">
      <summary>Padrón de prueba (simula la BD del conjunto)</summary>
      <p class="hint">
        Una placa por línea. El OCR se contrasta contra esta lista, pero
        <b>ella nunca lo sobrescribe</b>: si hay duda, el sistema pregunta.
      </p>
      <textarea id="padron" rows="6" spellcheck="false"
                placeholder="PKK149&#10;WCN641&#10;ELL680"></textarea>
      <div class="btns">
        <button id="btnDemo" class="btn small">Cargar padrón demo</button>
        <span id="nPadron" class="hint">0 placas</span>
      </div>
    </details>
  </section>

  <section id="res" class="card oculto">
    <div id="semaforo" class="semaforo"></div>
    <img id="preview" class="preview" alt="">
    <div id="detalle" class="detalle"></div>
    <div id="opciones" class="opciones"></div>
    <div id="manual" class="manual oculto">
      <label for="inpPlaca">Escriba la placa:</label>
      <input type="text" id="inpPlaca" maxlength="6" autocapitalize="characters"
             autocomplete="off" spellcheck="false" placeholder="ABC123">
      <button id="btnOk" class="btn primary">Confirmar</button>
    </div>
  </section>

  <section class="card">
    <h2>Medición con ground truth</h2>
    <p class="hint">
      Suba varias fotos cuyo <b>nombre de archivo sea la placa real</b>
      (<code>PKK149.jpg</code>, <code>UYX23F.jpg</code>). Se mide la precisión de verdad.
    </p>
    <button id="btnBench" class="btn" disabled>📊 Correr medición</button>
    <input type="file" id="inpBench" accept="image/*" multiple hidden>
    <div id="prog" class="prog oculto"><div id="progBar"></div></div>
    <div id="tabla"></div>
    <div id="resumen"></div>
  </section>

</main>

<script src="/assets/ocr/vendor/ort/ort.min.js"></script>
<script src="/assets/ocr/sp_ocr.js"></script>
<script src="/assets/ocr/ocr_ui.js"></script>
</body>
</html>
