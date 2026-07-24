// /home/myzonaco/smartpark.myzona360.com/assets/js/sp_ui_enhancements.js
// v3o: Botón flotante "ir arriba" + componente multi-select reutilizable.

(function () {
    'use strict';

    // ════════════════════════════════════════════════════════════
    //  BOTÓN FLOTANTE "IR ARRIBA"
    // ════════════════════════════════════════════════════════════
    function initBackToTop() {
        // Inyectar estilos
        var css = ''
            + '#sp-back-to-top{position:fixed;bottom:20px;right:20px;width:46px;height:46px;'
            + '  border-radius:50%;background:#1e6cff;color:#fff;border:none;font-size:22px;'
            + '  font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);'
            + '  display:none;align-items:center;justify-content:center;z-index:9998;'
            + '  transition:transform .15s ease,opacity .15s ease;opacity:.85;}'
            + '#sp-back-to-top:hover{opacity:1;transform:scale(1.08);}'
            + '@media (max-width:600px){#sp-back-to-top{bottom:80px;right:14px;width:42px;height:42px;}}';
        var style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);

        var btn = document.createElement('button');
        btn.id = 'sp-back-to-top';
        btn.type = 'button';
        btn.innerHTML = '↑';
        btn.title = 'Volver arriba';
        btn.setAttribute('aria-label', 'Volver arriba');
        document.body.appendChild(btn);

        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        function update() {
            btn.style.display = (window.scrollY > 300) ? 'flex' : 'none';
        }
        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    // ════════════════════════════════════════════════════════════
    //  COMPONENTE MULTI-SELECT (dropdown con checkboxes)
    //  HTML esperado:
    //    <div class="sp-multi" data-label="Tipo" data-all="Todos los tipos">
    //        <button type="button" class="sp-multi-btn"></button>
    //        <div class="sp-multi-panel">
    //            <label><input type="checkbox" name="tipo[]" value="comun"> 🌐 Común</label>
    //            <label><input type="checkbox" name="tipo[]" value="privada"> 🔒 Privada</label>
    //            ...
    //        </div>
    //    </div>
    // ════════════════════════════════════════════════════════════
    function initMultiFilters() {
        // Inyectar estilos
        var css = ''
            + '.sp-multi{position:relative;display:inline-block;min-width:170px;}'
            + '.sp-multi-btn{width:100%;padding:7px 30px 7px 12px;background:#fff;'
            + '  border:1px solid #d1d5db;border-radius:5px;cursor:pointer;text-align:left;'
            + '  font-size:13px;color:#374151;white-space:nowrap;overflow:hidden;'
            + '  text-overflow:ellipsis;position:relative;line-height:1.4;}'
            + '.sp-multi-btn:hover{border-color:#9ca3af;}'
            + '.sp-multi-btn:after{content:"▾";position:absolute;right:10px;top:50%;'
            + '  transform:translateY(-50%);color:#6b7280;font-size:11px;}'
            + '.sp-multi-btn.is-active{background:#eff6ff;border-color:#1e6cff;color:#1e3a8a;font-weight:600;}'
            + '.sp-multi-panel{position:absolute;top:100%;left:0;right:0;margin-top:4px;'
            + '  background:#fff;border:1px solid #d1d5db;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.12);'
            + '  z-index:50;max-height:280px;overflow-y:auto;padding:6px 0;display:none;min-width:230px;}'
            + '.sp-multi-panel.is-open{display:block;}'
            + '.sp-multi-panel label{display:flex;align-items:center;gap:8px;padding:7px 12px;'
            + '  cursor:pointer;font-size:13px;color:#374151;font-weight:normal;margin:0;}'
            + '.sp-multi-panel label:hover{background:#f3f4f6;}'
            + '.sp-multi-panel input[type=checkbox]{margin:0;width:auto;}'
            + '.sp-multi-actions{display:flex;gap:6px;padding:6px 10px;border-top:1px solid #e5e7eb;}'
            + '.sp-multi-actions button{flex:1;padding:5px;font-size:12px;border:1px solid #d1d5db;'
            + '  background:#fff;border-radius:4px;cursor:pointer;color:#374151;}'
            + '.sp-multi-actions button:hover{background:#f3f4f6;}';
        var style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);

        var multis = document.querySelectorAll('.sp-multi');
        multis.forEach(function (mb) {
            var btn   = mb.querySelector('.sp-multi-btn');
            var panel = mb.querySelector('.sp-multi-panel');
            if (!btn || !panel) return;

            var label  = mb.getAttribute('data-label') || 'Filtro';
            var labelAll = mb.getAttribute('data-all') || ('Todos: ' + label);
            var cbs    = panel.querySelectorAll('input[type="checkbox"]');

            // Agregar acciones rápidas: Todos / Ninguno
            if (!panel.querySelector('.sp-multi-actions')) {
                var actions = document.createElement('div');
                actions.className = 'sp-multi-actions';
                actions.innerHTML = '<button type="button" data-act="all">Todos</button>'
                                  + '<button type="button" data-act="none">Ninguno</button>';
                panel.appendChild(actions);
                actions.querySelector('[data-act=all]').addEventListener('click', function (e) {
                    e.stopPropagation();
                    cbs.forEach(function (c) { c.checked = true; });
                    updateLabel();
                });
                actions.querySelector('[data-act=none]').addEventListener('click', function (e) {
                    e.stopPropagation();
                    cbs.forEach(function (c) { c.checked = false; });
                    updateLabel();
                });
            }

            function updateLabel() {
                var selected = Array.prototype.filter.call(cbs, function (c) { return c.checked; });
                if (selected.length === 0 || selected.length === cbs.length) {
                    btn.textContent = labelAll;
                    btn.classList.remove('is-active');
                } else if (selected.length === 1) {
                    var txt = selected[0].parentNode.textContent.trim();
                    btn.textContent = label + ': ' + txt;
                    btn.classList.add('is-active');
                } else {
                    btn.textContent = label + ': ' + selected.length + ' seleccionados';
                    btn.classList.add('is-active');
                }
            }

            cbs.forEach(function (cb) {
                cb.addEventListener('change', updateLabel);
            });
            updateLabel();

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                // Cerrar otros panels abiertos
                document.querySelectorAll('.sp-multi-panel.is-open').forEach(function (p) {
                    if (p !== panel) p.classList.remove('is-open');
                });
                panel.classList.toggle('is-open');
            });

            panel.addEventListener('click', function (e) { e.stopPropagation(); });
        });

        // Cerrar todos los panels al click fuera
        document.addEventListener('click', function () {
            document.querySelectorAll('.sp-multi-panel.is-open').forEach(function (p) {
                p.classList.remove('is-open');
            });
        });
    }

    // ════════════════════════════════════════════════════════════
    //  Init
    // ════════════════════════════════════════════════════════════
    function init() {
        try { initBackToTop(); }     catch (e) { /* silencioso */ }
        try { initMultiFilters(); }  catch (e) { /* silencioso */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
