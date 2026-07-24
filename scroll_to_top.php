<?php
// /home/myzonaco/smartpark.myzona360.com/includes/scroll_to_top.php
// v1.0 (3AN): Botón flotante "ir arriba" universal.
//
// Cómo incluirlo:
//   Opción A (todas las páginas de una): edita /includes/footer.php y agrega
//   esta línea antes de </body>:
//
//       <?php include INCLUDES_PATH . '/scroll_to_top.php'; ?>
//
//   Opción B (una página específica): incluye este archivo al final de
//   cualquier módulo que quieras.
//
// El botón:
// - Aparece solo cuando se scrollea más de 200 px
// - Al hacer clic sube suavemente al top
// - No interfiere con nada (position fixed, z-index alto)
// - Se oculta al imprimir
if (!defined('SMARTPARK_BOOT')) return;
?>
<button type="button" id="btnIrArriba" aria-label="Ir arriba" title="Ir arriba">↑</button>
<style>
#btnIrArriba{
    position:fixed;
    right:20px;
    bottom:24px;
    width:46px;
    height:46px;
    border-radius:50%;
    background:#1e40af;
    color:#fff;
    border:none;
    font-size:22px;
    font-weight:700;
    cursor:pointer;
    box-shadow:0 4px 14px rgba(30,64,175,.35);
    opacity:0;
    transform:translateY(20px);
    pointer-events:none;
    transition:opacity .18s, transform .18s, background .15s;
    z-index:9998;
    display:flex;
    align-items:center;
    justify-content:center;
    line-height:1;
    padding:0;
}
#btnIrArriba:hover{ background:#1e3a8a; }
#btnIrArriba.visible{ opacity:.9; transform:translateY(0); pointer-events:auto; }
#btnIrArriba.visible:hover{ opacity:1; }
@media print { #btnIrArriba{ display:none !important; } }
@media (max-width:600px){
    #btnIrArriba{ right:14px; bottom:16px; width:42px; height:42px; font-size:20px; }
}
</style>
<script>
(function(){
    var b = document.getElementById('btnIrArriba');
    if (!b) return;
    var visible = false;
    function checkScroll(){
        var y = window.scrollY || document.documentElement.scrollTop || 0;
        var deberia = y > 200;
        if (deberia !== visible) {
            visible = deberia;
            b.classList.toggle('visible', visible);
        }
    }
    // Scroll throttled con requestAnimationFrame
    var pendiente = false;
    window.addEventListener('scroll', function(){
        if (pendiente) return;
        pendiente = true;
        requestAnimationFrame(function(){ checkScroll(); pendiente = false; });
    }, { passive: true });

    b.addEventListener('click', function(){
        try {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch(e){
            // Fallback para navegadores viejos
            window.scrollTo(0, 0);
        }
    });

    // Verificar al cargar por si ya viene scrolleado
    checkScroll();
})();
</script>
