// /home/myzonaco/smartpark.myzona360.com/public/js/apto_popover.js
// v3BH: helper JS que muestra un popover flotante con info del apto.
//   Intercepta clics/hover en <a class="apto-link" data-apto="1502">
//   y llama a /consultas/api_apto_info?apto=1502 para mostrar detalles.
//   Reutilizable en TODAS las pantallas: solo hay que agregar la clase
//   "apto-link" y el atributo data-apto="XXX" al elemento.

(function(){
    'use strict';
    if (window.__SP_APTO_POPOVER_INIT) return;
    window.__SP_APTO_POPOVER_INIT = true;

    // Endpoint (ajustar si tu router lo tiene en otra ruta)
    var API_URL = '/consultas/api_apto_info';
    // Cache en memoria (para no recargar el mismo apto varias veces en la sesión)
    var cache = {};
    // Popover activo actualmente
    var $pop = null;
    var $arrow = null;

    // ── CSS del popover ──
    var css = document.createElement('style');
    css.textContent = ''
        + '.sp-apto-pop{position:absolute;z-index:9999;background:#fff;border:1px solid #d1d5db;'
        + '  border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:14px 16px;'
        + '  min-width:280px;max-width:360px;font-size:13px;color:#111827;line-height:1.5;'
        + '  animation:sp-pop-in .12s ease-out;}'
        + '@keyframes sp-pop-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}'
        + '.sp-apto-pop__title{font-weight:700;font-size:15px;color:#1e40af;margin-bottom:6px;'
        + '  border-bottom:1px solid #e5e7eb;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center}'
        + '.sp-apto-pop__close{background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer;'
        + '  padding:0 4px;line-height:1}'
        + '.sp-apto-pop__close:hover{color:#dc2626}'
        + '.sp-apto-pop__section{margin-top:8px}'
        + '.sp-apto-pop__section h4{margin:0 0 4px;font-size:11px;text-transform:uppercase;'
        + '  color:#6b7280;font-weight:600;letter-spacing:.3px}'
        + '.sp-apto-pop__badge{display:inline-block;padding:2px 8px;border-radius:8px;'
        + '  font-size:10px;font-weight:600;margin-right:4px;margin-bottom:2px}'
        + '.sp-apto-pop__badge--moroso{background:#fef3c7;color:#92400e}'
        + '.sp-apto-pop__badge--bloqueado{background:#fee2e2;color:#991b1b}'
        + '.sp-apto-pop__badge--al-dia{background:#dcfce7;color:#166534}'
        + '.sp-apto-pop__badge--tipo{background:#eff6ff;color:#1e40af}'
        + '.sp-apto-pop__item{padding:3px 0;font-size:12px}'
        + '.sp-apto-pop__loading,.sp-apto-pop__error{padding:12px;text-align:center;color:#6b7280}'
        + '.sp-apto-pop__error{color:#dc2626}'
        + '.sp-apto-pop__link{color:#1e40af;text-decoration:none;font-weight:600}'
        + '.sp-apto-pop__link:hover{text-decoration:underline}'
        + '.sp-apto-pop__actions{margin-top:10px;border-top:1px solid #e5e7eb;padding-top:8px;'
        + '  display:flex;gap:6px;flex-wrap:wrap}'
        + '.sp-apto-pop__actions a{padding:5px 10px;background:#eff6ff;color:#1e40af;'
        + '  border-radius:6px;text-decoration:none;font-size:11px;font-weight:600}'
        + '.sp-apto-pop__actions a:hover{background:#dbeafe}';
    document.head.appendChild(css);

    function esc(s){
        return String(s == null ? '' : s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function cerrar(){
        if ($pop) { $pop.remove(); $pop = null; }
    }

    function posicionar(anchor){
        if (!$pop || !anchor) return;
        var r = anchor.getBoundingClientRect();
        var top = window.scrollY + r.bottom + 6;
        var left = window.scrollX + r.left;
        // Evitar que se salga por la derecha
        var maxLeft = window.scrollX + window.innerWidth - 380;
        if (left > maxLeft) left = maxLeft;
        if (left < 10) left = 10;
        $pop.style.top = top + 'px';
        $pop.style.left = left + 'px';
    }

    function crearPopover(anchor, aptoNum){
        cerrar();
        $pop = document.createElement('div');
        $pop.className = 'sp-apto-pop';
        $pop.innerHTML = '<div class="sp-apto-pop__loading">⏳ Cargando apto ' + esc(aptoNum) + '...</div>';
        document.body.appendChild($pop);
        posicionar(anchor);
    }

    function renderPopover(data, aptoNum){
        if (!$pop) return;
        if (!data.ok) {
            $pop.innerHTML = '<div class="sp-apto-pop__title">'
                + '<span>🏠 Apto ' + esc(aptoNum) + '</span>'
                + '<button class="sp-apto-pop__close" type="button">×</button></div>'
                + '<div class="sp-apto-pop__error">⚠️ ' + esc(data.error || 'Error') + '</div>';
            $pop.querySelector('.sp-apto-pop__close').onclick = cerrar;
            return;
        }

        var badges = '';
        if (data.estado_morosidad === 'moroso') {
            badges += '<span class="sp-apto-pop__badge sp-apto-pop__badge--moroso">⚠️ MOROSO';
            if (data.meses_mora > 0) badges += ' ' + data.meses_mora + ' meses';
            badges += '</span>';
        } else {
            badges += '<span class="sp-apto-pop__badge sp-apto-pop__badge--al-dia">✓ Al día</span>';
        }
        if (data.bloqueo_comunes) {
            badges += '<span class="sp-apto-pop__badge sp-apto-pop__badge--bloqueado">⛔ Sin comunes</span>';
        }

        var html = ''
            + '<div class="sp-apto-pop__title">'
            + '<span>🏠 Apto ' + esc(data.apto) + ' · T' + data.torre;
        if (data.piso) html += ' P' + data.piso;
        html += '</span><button class="sp-apto-pop__close" type="button">×</button></div>';

        html += '<div class="sp-apto-pop__section">' + badges + '</div>';

        // Propietario
        if (data.propietario) {
            html += '<div class="sp-apto-pop__section">'
                + '<h4>🏘️ Propietario</h4>'
                + '<div class="sp-apto-pop__item">' + esc(data.propietario);
            if (data.propietario_celular) {
                html += ' · <a class="sp-apto-pop__link" href="tel:' + esc(data.propietario_celular) + '">📞 ' + esc(data.propietario_celular) + '</a>';
            }
            html += '</div></div>';
        }

        // Residentes
        if (data.residentes && data.residentes.length > 0) {
            html += '<div class="sp-apto-pop__section">'
                + '<h4>👥 Residentes (' + data.residentes.length + ')</h4>';
            data.residentes.forEach(function(r){
                html += '<div class="sp-apto-pop__item">'
                     + '<span class="sp-apto-pop__badge sp-apto-pop__badge--tipo">' + esc((r.tipo||'').toUpperCase()) + '</span>'
                     + esc(r.nombre);
                if (r.celular) html += ' · ' + esc(r.celular);
                html += '</div>';
            });
            html += '</div>';
        }

        // Vehículos
        if (data.vehiculos && data.vehiculos.length > 0) {
            html += '<div class="sp-apto-pop__section">'
                + '<h4>🚗 Vehículos (' + data.vehiculos.length + ')</h4>';
            data.vehiculos.forEach(function(v){
                var emo = v.tipo === 'moto' ? '🏍️' : '🚗';
                html += '<div class="sp-apto-pop__item">'
                     + emo + ' <strong>' + esc(v.placa) + '</strong>';
                if (v.marca) html += ' · ' + esc(v.marca);
                if (v.color) html += ' ' + esc(v.color);
                html += '</div>';
            });
            html += '</div>';
        }

        // Celdas dueño
        if (data.celdas_dueno && data.celdas_dueno.length > 0) {
            html += '<div class="sp-apto-pop__section">'
                + '<h4>🅿️ Celdas propias (' + data.celdas_dueno.length + ')</h4>';
            data.celdas_dueno.forEach(function(c){
                var apCel = { uso_propio:'Uso propio', prestamo_gratis:'🤝 Autorizado', alquiler:'💰 Alquiler' };
                html += '<div class="sp-apto-pop__item">'
                     + '<strong>' + esc(c.codigo) + '</strong>';
                if (c.nivel_codigo) html += ' <small>(' + esc(c.nivel_codigo) + ')</small>';
                if (c.apto_usuario) html += ' <small style="color:#1e40af;font-weight:600">· ' + (apCel[c.tipo_asig] || 'Autorizado') + ' → Usa ' + esc(c.apto_usuario) + '</small>';
                html += '</div>';
            });
            html += '</div>';
        }

        // Celdas usadas (autorizadas por otro apto)
        if (data.celdas_usadas && data.celdas_usadas.length > 0) {
            html += '<div class="sp-apto-pop__section">'
                + '<h4>🔑 Celdas autorizadas (' + data.celdas_usadas.length + ')</h4>';
            data.celdas_usadas.forEach(function(c){
                var tMap = {
                    'uso_propio':      '✅',
                    'prestamo_gratis': '🤝',
                    'alquiler':        '💰'
                };
                html += '<div class="sp-apto-pop__item">'
                     + (tMap[c.tipo_asig] || '📌') + ' <strong>' + esc(c.codigo) + '</strong>';
                if (c.apto_dueno) html += ' <small>(dueño: ' + esc(c.apto_dueno) + ')</small>';
                html += '</div>';
            });
            html += '</div>';
        }

        // Cuartos útiles propios
        if (data.cuartos_dueno && data.cuartos_dueno.length > 0) {
            html += '<div class="sp-apto-pop__section">'
                + '<h4>📦 Cuartos útiles propios (' + data.cuartos_dueno.length + ')</h4>';
            data.cuartos_dueno.forEach(function(c){
                var apCu = { uso_propio:'Uso propio', prestamo_gratis:'🤝 Autorizado', alquiler:'💰 Alquiler' };
                html += '<div class="sp-apto-pop__item">'
                     + '<strong>📦 ' + esc(c.codigo) + '</strong>';
                if (c.nivel_codigo) html += ' <small>(' + esc(c.nivel_codigo) + ')</small>';
                if (c.apto_usuario) html += ' <small style="color:#1e40af;font-weight:600">· ' + (apCu[c.tipo_asig] || 'Autorizado') + ' → Usa ' + esc(c.apto_usuario) + '</small>';
                html += '</div>';
            });
            html += '</div>';
        }

        // Cuartos útiles usados (autorizados por otro apto)
        if (data.cuartos_usados && data.cuartos_usados.length > 0) {
            html += '<div class="sp-apto-pop__section">'
                + '<h4>📦 Cuartos autorizados (' + data.cuartos_usados.length + ')</h4>';
            data.cuartos_usados.forEach(function(c){
                var tMapQ = { 'uso_propio':'✅', 'prestamo_gratis':'🤝', 'alquiler':'💰' };
                html += '<div class="sp-apto-pop__item">'
                     + (tMapQ[c.tipo_asig] || '📌') + ' <strong>📦 ' + esc(c.codigo) + '</strong>';
                if (c.apto_dueno) html += ' <small>(dueño: ' + esc(c.apto_dueno) + ')</small>';
                html += '</div>';
            });
            html += '</div>';
        }

        // Acciones
        html += '<div class="sp-apto-pop__actions">'
             + '<a href="/consultas?apto=' + encodeURIComponent(data.apto) + '">🔍 Consulta rápida</a>'
             + '<a href="/residentes?apto=' + encodeURIComponent(data.apto) + '&vista=activos" style="margin-left:8px">👤 Gestionar residentes</a>'
             + '</div>';

        $pop.innerHTML = html;
        $pop.querySelector('.sp-apto-pop__close').onclick = cerrar;
    }

    function cargarApto(anchor, aptoNum){
        crearPopover(anchor, aptoNum);

        // Cache
        if (cache[aptoNum]) {
            renderPopover(cache[aptoNum], aptoNum);
            return;
        }

        fetch(API_URL + '?apto=' + encodeURIComponent(aptoNum), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data){
                cache[aptoNum] = data;
                renderPopover(data, aptoNum);
            })
            .catch(function(err){
                renderPopover({ok:false, error: err.message || 'Error de red'}, aptoNum);
            });
    }

    // ── Delegación de eventos ──
    // Ctrl+clic (o command+clic en Mac) → sigue el link normal (a /consultas)
    // Clic normal → abre popover
    document.addEventListener('click', function(e){
        var link = e.target.closest('.apto-link');
        if (!link) {
            // Cerrar si hicieron clic fuera del popover
            if ($pop && !e.target.closest('.sp-apto-pop')) cerrar();
            return;
        }
        // Si ctrl/cmd/meta → dejar que el link vaya a su href
        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
        // Si middle-click → dejar (abrir en nueva pestaña)
        if (e.which === 2 || e.button === 1) return;
        e.preventDefault();
        var apto = link.getAttribute('data-apto') || link.textContent.trim();
        cargarApto(link, apto);
    });

    // Cerrar con Escape
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && $pop) cerrar();
    });

    // Reposicionar en scroll (opcional, sencillo)
    window.addEventListener('scroll', function(){
        if ($pop) cerrar();
    }, { passive: true });
})();
