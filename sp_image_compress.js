// /home/myzonaco/smartpark.myzona360.com/assets/js/sp_image_compress.js
// v3l: compresión de imágenes en el cliente ANTES de enviar al servidor.
//      Auto-detecta cualquier <input type="file"> con imagen y comprime al hacer submit.
//      No requiere modificar ningún formulario PHP.
//      Si la compresión falla, se envía la imagen original (fallback seguro).
//
// Configuración: ancho/alto máx 1600px · calidad JPEG 0.82 · no comprime si pesa < 100KB.
// Resultado típico: foto de 3-5 MB → 200-400 KB (80-90% menos).
//
// Opt-out: agregar atributo data-no-compress en un <input type="file"> para saltarlo.
// Uso manual (p.ej. en /consultas con fetch):
//      const fileComprimido = await window.SP_compressImage(file);
//      // luego enviar fileComprimido via FormData

(function () {
    'use strict';

    var CONFIG = {
        maxWidth:    1600,
        maxHeight:   1600,
        quality:     0.82,
        minSizeKB:   100,    // por debajo de esto NO se comprime (ya es liviano)
        debug:       false
    };

    function log() {
        if (!CONFIG.debug) return;
        try { console.log.apply(console, ['[sp-compress]'].concat([].slice.call(arguments))); } catch (e) {}
    }

    // ─── Núcleo: comprimir un File de imagen → Promise<File> ───
    function compressImage(file, opts) {
        opts = opts || {};
        var maxW    = opts.maxWidth   || CONFIG.maxWidth;
        var maxH    = opts.maxHeight  || CONFIG.maxHeight;
        var quality = opts.quality    || CONFIG.quality;
        var minKB   = opts.minSizeKB  || CONFIG.minSizeKB;

        return new Promise(function (resolve) {
            // Si no es imagen → devolver tal cual
            if (!file || !file.type || file.type.indexOf('image/') !== 0) {
                resolve(file); return;
            }
            // Si pesa poco → no comprimir (ahorra CPU)
            if (file.size < minKB * 1024) {
                log('skip (small):', file.name, file.size);
                resolve(file); return;
            }
            // GIF animado: no comprimir (perdería animación)
            if (file.type === 'image/gif') {
                log('skip (gif):', file.name);
                resolve(file); return;
            }

            var reader = new FileReader();
            reader.onerror = function () { log('reader error, fallback original'); resolve(file); };
            reader.onload = function (e) {
                var img = new Image();
                img.onerror = function () { log('image error, fallback original'); resolve(file); };
                img.onload = function () {
                    try {
                        var w = img.naturalWidth  || img.width;
                        var h = img.naturalHeight || img.height;
                        if (!w || !h) { resolve(file); return; }

                        // Redimensionar manteniendo aspect ratio
                        if (w > maxW || h > maxH) {
                            var ratio = Math.min(maxW / w, maxH / h);
                            w = Math.round(w * ratio);
                            h = Math.round(h * ratio);
                        }

                        var canvas = document.createElement('canvas');
                        canvas.width  = w;
                        canvas.height = h;
                        var ctx = canvas.getContext('2d');
                        // Fondo blanco (para PNG con transparencia que vamos a guardar como JPEG)
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, w, h);
                        ctx.drawImage(img, 0, 0, w, h);

                        if (!canvas.toBlob) { log('no toBlob support'); resolve(file); return; }

                        canvas.toBlob(function (blob) {
                            if (!blob) { log('toBlob null'); resolve(file); return; }
                            // Si el resultado pesa más que el original (raro), devolver original
                            if (blob.size >= file.size) {
                                log('compressed >= original, keep original:', file.name);
                                resolve(file); return;
                            }
                            // Construir nuevo File con extensión .jpg
                            var newName = (file.name || 'foto')
                                .replace(/\.(png|jpe?g|webp|bmp|gif|heic|heif)$/i, '') + '.jpg';
                            var newFile;
                            try {
                                newFile = new File([blob], newName, {
                                    type: 'image/jpeg',
                                    lastModified: Date.now()
                                });
                            } catch (errFile) {
                                // Algunos navegadores viejos no soportan new File(). Usar Blob con name forzado.
                                blob.name = newName;
                                blob.lastModifiedDate = new Date();
                                newFile = blob;
                            }
                            log('compressed:', file.name, file.size, '→', newFile.size);
                            resolve(newFile);
                        }, 'image/jpeg', quality);
                    } catch (err) {
                        log('exception, fallback original:', err);
                        resolve(file);
                    }
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    // ─── Reemplazar archivos de un <input type="file"> con versiones comprimidas ───
    function compressInputFiles(input) {
        if (!input || !input.files || input.files.length === 0) return Promise.resolve();
        if (input.hasAttribute('data-no-compress')) { log('skip (data-no-compress)'); return Promise.resolve(); }
        if (input.hasAttribute('data-compressed'))  { log('skip (ya procesado)');     return Promise.resolve(); }

        var files = [];
        for (var i = 0; i < input.files.length; i++) files.push(input.files[i]);

        return Promise.all(files.map(function (f) { return compressImage(f); }))
            .then(function (results) {
                // Reemplazar input.files mediante DataTransfer
                if (typeof DataTransfer === 'undefined') {
                    log('no DataTransfer support, dejando archivos originales');
                    return;
                }
                try {
                    var dt = new DataTransfer();
                    results.forEach(function (f) { dt.items.add(f); });
                    input.files = dt.files;
                    input.setAttribute('data-compressed', '1');
                } catch (e) {
                    log('error reemplazando input.files:', e);
                }
            });
    }

    // ─── Interceptar submit del form ───
    function attachToForm(form) {
        if (form.hasAttribute('data-sp-compress-attached')) return;
        form.setAttribute('data-sp-compress-attached', '1');

        form.addEventListener('submit', function (e) {
            var inputs = form.querySelectorAll('input[type="file"]');
            var pending = [];

            for (var i = 0; i < inputs.length; i++) {
                var input = inputs[i];
                if (!input.files || input.files.length === 0) continue;
                if (input.hasAttribute('data-no-compress')) continue;
                if (input.hasAttribute('data-compressed'))  continue;

                // ¿Tiene al menos un archivo imagen?
                var hayImagen = false;
                for (var j = 0; j < input.files.length; j++) {
                    var t = input.files[j].type || '';
                    if (t.indexOf('image/') === 0) { hayImagen = true; break; }
                }
                if (!hayImagen) continue;

                pending.push(compressInputFiles(input));
            }

            if (pending.length === 0) return; // nada que comprimir, dejar submit normal

            // Hay imágenes pendientes: pausar submit, comprimir, reenviar
            e.preventDefault();

            // Indicador visual en el botón submit
            var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            var oldText = null, oldDisabled = false;
            if (submitBtn) {
                oldDisabled = submitBtn.disabled;
                if (submitBtn.tagName === 'BUTTON') {
                    oldText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '⏳ Comprimiendo foto...';
                } else {
                    oldText = submitBtn.value;
                    submitBtn.value = 'Comprimiendo foto...';
                }
                submitBtn.disabled = true;
            }

            Promise.all(pending).then(function () {
                // Re-enviar el form. form.submit() programático NO dispara evento submit (no hay loop)
                if (submitBtn) submitBtn.disabled = oldDisabled;
                form.submit();
            }).catch(function (err) {
                log('error global, restaurando:', err);
                if (submitBtn) {
                    submitBtn.disabled = oldDisabled;
                    if (oldText !== null) {
                        if (submitBtn.tagName === 'BUTTON') submitBtn.innerHTML = oldText;
                        else submitBtn.value = oldText;
                    }
                }
                alert('No se pudo procesar la imagen. Intenta de nuevo o usa una foto más pequeña.');
            });
        }, false);
    }

    // ─── Init: buscar todos los forms con input file y adjuntar listener ───
    function init() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            var f = forms[i];
            if (f.querySelector('input[type="file"]')) {
                attachToForm(f);
            }
        }
    }

    // Exponer helpers para uso manual (consultas via fetch, rondas via IndexedDB, etc.)
    window.SP_compressImage      = compressImage;
    window.SP_compressInputFiles = compressInputFiles;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
