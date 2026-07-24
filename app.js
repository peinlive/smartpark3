// /home/myzonaco/smartpark.myzona360.com/assets/js/app.js
// JS mínimo: toggle del menú lateral en móvil.

(function () {
    'use strict';

    var btn     = document.getElementById('btnMenu');
    var sidebar = document.getElementById('sidebar');

    if (btn && sidebar) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('is-open');
        });

        // Cerrar al click fuera (en móvil)
        document.addEventListener('click', function (e) {
            if (!sidebar.classList.contains('is-open')) return;
            if (sidebar.contains(e.target) || btn.contains(e.target)) return;
            sidebar.classList.remove('is-open');
        });
    }
})();
