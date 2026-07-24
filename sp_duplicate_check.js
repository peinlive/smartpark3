// /home/myzonaco/smartpark.myzona360.com/assets/js/sp_duplicate_check.js
// v3m.1: BLOQUEO ABSOLUTO en /residentes/crear y /vehiculos/crear.
//        Si hay duplicado detectado, NO permite enviar el formulario.
//        Desactiva el botón Guardar hasta que el usuario cambie el campo.
//        Si el endpoint AJAX falla por cualquier motivo, el form sigue
//        funcionando normal (no bloquea).

(function () {
    'use strict';

    var path = window.location.pathname;
    var IS_RES = (path === '/residentes/crear');
    var IS_VEH = (path === '/vehiculos/crear');
    if (!IS_RES && !IS_VEH) return;

    var ENDPOINT = '/api/check_duplicate';

    // ─── Estilos ───
    var css = ''
        + '.sp-dup-warning { margin-top: 8px; padding: 12px 14px;'
        + '  background: #fee2e2; border: 1px solid #dc2626; border-radius: 6px;'
        + '  color: #7f1d1d; font-size: 14px; line-height: 1.45; }'
        + '.sp-dup-warning strong { color: #991b1b; }'
        + '.sp-dup-warning ul { margin: 8px 0 0 0; padding-left: 20px; }'
        + '.sp-dup-warning li { margin: 4px 0; }'
        + '.sp-dup-warning a { color: #1e6cff; font-weight: 600; text-decoration: none; }'
        + '.sp-dup-warning a:hover { text-decoration: underline; }'
        + '.sp-dup-warning .sp-dup-block-msg { display: block; margin-top: 10px; padding-top: 8px;'
        + '  border-top: 1px solid #fca5a5; font-weight: 600; color: #991b1b; }'
        + '.sp-dup-pill { display: inline-block; padding: 1px 6px; background: #6b7280; color: white;'
        + '  border-radius: 999px; font-size: 11px; margin-left: 4px; }'
        + '.sp-dup-disabled { opacity: 0.5; cursor: not-allowed !important; }';
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function debounce(fn, wait) {
        var t = null;
        return function () {
            var self = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    }

    function clearWarning(input) {
        if (!input.parentNode) return;
        var sib = input.parentNode.querySelectorAll('.sp-dup-warning');
        sib.forEach(function (n) { n.remove(); });
    }

    function showWarning(input, html) {
        clearWarning(input);
        var div = document.createElement('div');
        div.className = 'sp-dup-warning';
        div.innerHTML = html;
        var anchor = input.closest('label') || input;
        anchor.parentNode.insertBefore(div, anchor.nextSibling);
    }

    function setSubmitDisabled(form, disabled, motivo) {
        var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        btns.forEach(function (b) {
            b.disabled = !!disabled;
            if (disabled) {
                b.classList.add('sp-dup-disabled');
                if (!b.dataset.spOrigTitle) b.dataset.spOrigTitle = b.title || '';
                b.title = motivo || 'No se puede crear: existe un duplicado';
            } else {
                b.classList.remove('sp-dup-disabled');
                if (b.dataset.spOrigTitle !== undefined) b.title = b.dataset.spOrigTitle;
            }
        });
    }

    function renderResidenteMatches(matches) {
        var html = '🚫 <strong>BLOQUEADO: ya existe(n) ' + matches.length + ' residente(s) con ese celular:</strong><ul>';
        matches.forEach(function (m) {
            html += '<li><strong>' + escapeHtml(m.nombre) + '</strong>';
            if (m.celular) html += ' · ' + escapeHtml(m.celular);
            html += ' · Apto <strong>' + escapeHtml(m.apto) + '</strong> (T' + m.torre + ')';
            if (m.archivado) {
                html += ' <span class="sp-dup-pill">📁 Archivado</span>';
            } else {
                if (m.url_editar) html += ' · <a href="' + m.url_editar + '">✏️ Editar este</a>';
                if (m.url_ver)    html += ' · <a href="' + m.url_ver + '">👁 Ver</a>';
            }
            html += '</li>';
        });
        html += '<span class="sp-dup-block-msg">No se permite crear un residente con un celular ya existente. Edita el registro de arriba, o cambia el celular para continuar.</span>';
        return html;
    }

    function renderVehiculoMatches(matches) {
        var html = '🚫 <strong>BLOQUEADO: la placa ya está registrada en el conjunto:</strong><ul>';
        matches.forEach(function (m) {
            var icon = m.tipo === 'moto' ? '🏍️' : '🚗';
            html += '<li>' + icon + ' <strong>' + escapeHtml(m.placa) + '</strong>';
            html += ' · ' + (m.origen === 'visitante' ? '👋 Visitante' : '🏠 Residente');
            if (m.nombre) html += ' · ' + escapeHtml(m.nombre);
            html += ' · Apto <strong>' + escapeHtml(m.apto) + '</strong> (T' + m.torre + ')';
            if (m.archivado) {
                html += ' <span class="sp-dup-pill">📁 Archivado</span>';
            } else {
                if (m.url_editar) html += ' · <a href="' + m.url_editar + '">✏️ Editar este</a>';
                if (m.url_ver)    html += ' · <a href="' + m.url_ver + '">👁 Ver</a>';
            }
            html += '</li>';
        });
        html += '<span class="sp-dup-block-msg">No se permite duplicar una placa. Edita el registro de arriba, o cambia la placa para continuar.</span>';
        return html;
    }

    function fetchCheck(qs) {
        return fetch(ENDPOINT + qs, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
    }

    // ────────────────────────────────────────────────────────────
    //  /residentes/crear
    // ────────────────────────────────────────────────────────────
    function initResidente() {
        var celular    = document.querySelector('input[name="celular"]');
        var aptoSelect = document.querySelector('select[name="apartamento_id"]');
        var nombre     = document.querySelector('input[name="nombre"]');
        var form       = celular ? celular.form : (nombre ? nombre.form : null);
        if (!form) return;

        var state = { hasDuplicate: false };

        function check() {
            var cel = celular ? (celular.value || '').replace(/[^0-9]/g, '') : '';
            var nom = nombre  ? (nombre.value || '').trim() : '';
            var aptoId = aptoSelect ? aptoSelect.value : '';

            // Reset si no hay datos suficientes
            if (cel.length < 7 && (nom === '' || !aptoId)) {
                if (celular) clearWarning(celular);
                state.hasDuplicate = false;
                setSubmitDisabled(form, false);
                return;
            }

            var qs = '?tipo=residente';
            if (cel.length >= 7) qs += '&celular=' + encodeURIComponent(cel);
            if (nom !== '')      qs += '&nombre='  + encodeURIComponent(nom);
            if (aptoId)          qs += '&apto_id=' + encodeURIComponent(aptoId);

            fetchCheck(qs)
                .then(function (data) {
                    if (data && data.exists && data.matches && data.matches.length > 0) {
                        // Filtrar archivados: si el match está archivado, NO bloquea (puede crear el reemplazo)
                        var activos = data.matches.filter(function (m) { return !m.archivado; });
                        if (activos.length === 0) {
                            // Solo hay matches archivados → mostrar info pero NO bloquear
                            if (celular) clearWarning(celular);
                            state.hasDuplicate = false;
                            setSubmitDisabled(form, false);
                            return;
                        }
                        var anchor = celular || nombre;
                        showWarning(anchor, renderResidenteMatches(activos));
                        state.hasDuplicate = true;
                        setSubmitDisabled(form, true, 'No se puede crear: ya existe un residente con ese celular o nombre.');
                    } else {
                        if (celular) clearWarning(celular);
                        state.hasDuplicate = false;
                        setSubmitDisabled(form, false);
                    }
                })
                .catch(function () {
                    // Si la consulta falla, NO bloqueamos (permitir crear)
                    if (celular) clearWarning(celular);
                    state.hasDuplicate = false;
                    setSubmitDisabled(form, false);
                });
        }

        var checkDebounced = debounce(check, 600);
        if (celular) {
            celular.addEventListener('blur',  check);
            celular.addEventListener('input', checkDebounced);
        }
        if (nombre) {
            nombre.addEventListener('blur',  check);
            nombre.addEventListener('input', checkDebounced);
        }
        if (aptoSelect) aptoSelect.addEventListener('change', check);

        // Submit handler: bloqueo absoluto
        form.addEventListener('submit', function (e) {
            if (state.hasDuplicate) {
                e.preventDefault();
                e.stopPropagation();
                alert('🚫 No se puede crear el residente:\n\nYa existe alguien con ese celular en el conjunto.\n\nEdita el registro existente o cambia el celular para continuar.');
                return false;
            }
        }, true); // useCapture=true para correr antes que otros handlers
    }

    // ────────────────────────────────────────────────────────────
    //  /vehiculos/crear
    // ────────────────────────────────────────────────────────────
    function initVehiculo() {
        var placa = document.querySelector('input[name="placa"]');
        var form  = placa ? placa.form : null;
        if (!placa || !form) return;

        var state = { hasDuplicate: false };

        function check() {
            var pl = (placa.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (pl.length < 4) {
                clearWarning(placa);
                state.hasDuplicate = false;
                setSubmitDisabled(form, false);
                return;
            }

            fetchCheck('?tipo=vehiculo&placa=' + encodeURIComponent(pl))
                .then(function (data) {
                    if (data && data.exists && data.matches && data.matches.length > 0) {
                        var activos = data.matches.filter(function (m) { return !m.archivado; });
                        if (activos.length === 0) {
                            clearWarning(placa);
                            state.hasDuplicate = false;
                            setSubmitDisabled(form, false);
                            return;
                        }
                        showWarning(placa, renderVehiculoMatches(activos));
                        state.hasDuplicate = true;
                        setSubmitDisabled(form, true, 'No se puede crear: la placa ya está registrada.');
                    } else {
                        clearWarning(placa);
                        state.hasDuplicate = false;
                        setSubmitDisabled(form, false);
                    }
                })
                .catch(function () {
                    clearWarning(placa);
                    state.hasDuplicate = false;
                    setSubmitDisabled(form, false);
                });
        }

        var checkDebounced = debounce(check, 600);
        placa.addEventListener('blur',  check);
        placa.addEventListener('input', checkDebounced);

        form.addEventListener('submit', function (e) {
            if (state.hasDuplicate) {
                e.preventDefault();
                e.stopPropagation();
                alert('🚫 No se puede crear el vehículo:\n\nLa placa ya está registrada en el conjunto.\n\nEdita el registro existente o cambia la placa para continuar.');
                return false;
            }
        }, true);
    }

    function init() {
        if (IS_RES) initResidente();
        if (IS_VEH) initVehiculo();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
