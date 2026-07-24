<!-- ═══════════════════════════════════════════════════
     AGREGAR en includes/header.php
     JUSTO ANTES de </script> del bloque del head
     (donde están el SW y el botón Instalar)
     ═══════════════════════════════════════════════════ -->

<script>
// ── Monitor de sesiones: heartbeat cada 55 segundos ──
// Envía: página actual + coordenadas GPS (si el usuario las aceptó)
(function () {
    var PING_URL = <?= json_encode(url('/api/monitor_ping')) ?>;
    var _lat = null, _lng = null;

    // Solicitar ubicación GPS una vez (el usuario puede rechazarla)
    if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                _lat = pos.coords.latitude;
                _lng = pos.coords.longitude;
                enviarPing(); // enviar inmediatamente con coords
            },
            function() { /* rechazado o error — se sigue sin coords */ },
            { timeout: 8000, maximumAge: 60000 }
        );
    }

    function enviarPing() {
        var body = 'pagina=' + encodeURIComponent(window.location.pathname);
        if (_lat !== null) body += '&lat=' + _lat + '&lng=' + _lng;
        fetch(PING_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            keepalive: true  // sobrevive a cambios de página
        }).catch(function(){});
    }

    // Ping inicial
    enviarPing();

    // Ping periódico cada 55s
    setInterval(enviarPing, 55000);

    // Ping en cada cambio de página (SPA-style)
    window.addEventListener('popstate', enviarPing);
})();
</script>
