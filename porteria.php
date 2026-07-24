<?php
// /home/myzonaco/smartpark.myzona360.com/modules/configuracion/porteria.php
// v3.0 (3AL): PÁGINA INFORMATIVA.
//   Antes se configuraba un número fijo de portería. Ahora ya no es necesario:
//   al compartir por WhatsApp, el propio WhatsApp del usuario pide el destinatario.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

$_pageTitle = 'WhatsApp — simplificado';
include INCLUDES_PATH . '/header.php';
?>

<style>
.dep-head{background:linear-gradient(135deg,#166534,#059669);color:#fff;border-radius:10px;padding:20px 24px;margin-top:12px;}
.dep-head h1{margin:0;font-size:22px;}
.dep-head p{margin:6px 0 0;font-size:13px;opacity:.9;}

.dep-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px 26px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.03);}
.dep-card h3{margin:0 0 10px;font-size:15px;color:#111827;padding-bottom:6px;border-bottom:2px solid #f3f4f6;}
.dep-info{background:#dcfce7;border-left:4px solid #16a34a;padding:12px 16px;border-radius:6px;color:#166534;font-size:13px;line-height:1.6;}
.dep-step{display:flex;gap:12px;align-items:flex-start;margin:10px 0;}
.dep-step .num{background:#166534;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;}
.dep-step .txt{flex:1;font-size:13px;color:#374151;line-height:1.5;}
</style>

<div class="dep-head">
    <h1>📱 Compartir por WhatsApp</h1>
    <p>Simplificamos la forma de enviar novedades y reportes. Ya no necesitas configurar un número fijo.</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/configuracion') ?>">← Módulo Configuración</a>
    <a class="btn" href="<?= url('/consultas') ?>">🔍 Ir a consulta rápida</a>
</div>

<div class="dep-card">
    <h3>✅ Ya no requiere configuración</h3>
    <div class="dep-info">
        <strong>¿Cómo funciona ahora?</strong><br>
        Cuando compartas una novedad o un reporte desde SmartPark, se abrirá tu
        WhatsApp y podrás elegir a <strong>cualquier contacto</strong>: portería,
        administrador, supervisor, un vecino o cualquier otro. Sin límites.
    </div>

    <h4 style="margin:20px 0 8px;font-size:14px;color:#166534">Paso a paso</h4>

    <div class="dep-step">
        <div class="num">1</div>
        <div class="txt"><strong>Consulta un vehículo</strong> en la consulta rápida o abre una novedad existente.</div>
    </div>
    <div class="dep-step">
        <div class="num">2</div>
        <div class="txt">Haz clic en <strong>📱 Compartir por WhatsApp</strong>. En móvil aparecerá el selector nativo (WhatsApp, Telegram, Email, etc.). En escritorio se abrirá WhatsApp Web.</div>
    </div>
    <div class="dep-step">
        <div class="num">3</div>
        <div class="txt"><strong>Elige el destinatario</strong> desde tus contactos: portería, admin, supervisor, o quien necesites en cada caso.</div>
    </div>
    <div class="dep-step">
        <div class="num">4</div>
        <div class="txt">El mensaje ya viene <strong>pre-llenado</strong> con placa, apto, residente, novedad y foto. Solo confirmas envío.</div>
    </div>
</div>

<div class="dep-card">
    <h3>ℹ️ ¿Qué pasó con el número fijo?</h3>
    <p style="font-size:13px;color:#6b7280;line-height:1.6">
        Antes teníamos un campo para fijar el número de portería, pero eso limitaba
        las opciones: solo podías enviar a UN número (o elegir de una lista corta).
        <br><br>
        Con el nuevo enfoque puedes enviar a <strong>cualquier contacto de tu WhatsApp</strong>,
        que es lo natural cuando cada situación requiere un destinatario distinto
        (portería vs. admin vs. supervisor vs. residente).
        <br><br>
        Si tenías un número guardado, simplemente ya no se usa. No necesitas hacer nada.
    </p>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
