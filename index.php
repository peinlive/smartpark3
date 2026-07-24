<?php
// /home/myzonaco/smartpark.myzona360.com/modules/configuracion/index.php
// v1.0 (3AK): Landing del módulo Configuración.
//   Agrupa TODA la configuración del sistema.
//   Solo super_admin y admin.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esSuperAdmin = auth_has_role('super_admin');

// Estado de cada configuración (para mostrar en las tarjetas)
$baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';

// ── Config portería ──
$porteriaOk = false;
$porteriaNum = '';
$porteriaAdicionales = 0;
$archPorteria = $baseUploads . '/config/porteria_' . $conjuntoId . '.json';
if (is_file($archPorteria)) {
    $cfg = json_decode(@file_get_contents($archPorteria), true);
    if (is_array($cfg) && !empty($cfg['numero_principal'])) {
        $porteriaOk = true;
        $porteriaNum = $cfg['numero_principal'];
        $porteriaAdicionales = count($cfg['numeros_adicionales'] ?? []);
    }
}

$_pageTitle = 'Configuración';
include INCLUDES_PATH . '/header.php';
?>

<style>
.cfg-head{background:linear-gradient(135deg,#166534,#059669);color:#fff;border-radius:10px;padding:20px 24px;margin-top:12px;}
.cfg-head h1{margin:0;font-size:22px;}
.cfg-head p{margin:6px 0 0;font-size:13px;opacity:.9;}

.cfg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin:16px 0;}
.cfg-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;text-decoration:none;color:inherit;transition:all .15s;display:flex;flex-direction:column;gap:8px;box-shadow:0 1px 3px rgba(0,0,0,.03);position:relative;overflow:hidden;}
.cfg-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);border-color:#059669;}
.cfg-card .icon{font-size:32px;line-height:1;}
.cfg-card h3{margin:0;font-size:15px;color:#111827;}
.cfg-card p{margin:0;font-size:12px;color:#6b7280;line-height:1.5;}
.cfg-card .estado{display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:3px 10px;border-radius:10px;font-weight:600;align-self:flex-start;margin-top:4px;}
.cfg-card .estado.ok{background:#dcfce7;color:#166534;}
.cfg-card .estado.warn{background:#fef3c7;color:#92400e;}
.cfg-card .estado.info{background:#dbeafe;color:#1e40af;}
.cfg-card .tag{position:absolute;top:12px;right:12px;background:#f5f3ff;color:#7c3aed;font-size:9px;padding:2px 6px;border-radius:6px;font-weight:700;letter-spacing:.3px;}
.cfg-card.soon{opacity:.55;pointer-events:none;background:#fafafa;}
.cfg-card.soon .tag{background:#f3f4f6;color:#6b7280;}
</style>

<div class="cfg-head">
    <h1>⚙️ Configuración del sistema</h1>
    <p>Ajustes generales del conjunto. Todo lo que se configura una sola vez o de forma esporádica vive aquí.</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/administracion') ?>">← Volver a administración</a>
    <a class="btn" href="<?= url('/consultas') ?>">🔍 Ir a consulta rápida</a>
</div>

<div class="cfg-grid">

    <!-- v3AL: Configuración de portería descontinuada.
         Al compartir por WhatsApp ahora se abre el WhatsApp del usuario,
         que elige el destinatario en cada envío. -->
    <div class="cfg-card soon" style="pointer-events:auto;opacity:1">
        <span class="tag" style="background:#dcfce7;color:#166534">✓ SIMPLIFICADO</span>
        <div class="icon">📱</div>
        <h3>Compartir por WhatsApp</h3>
        <p>Ya no requiere configuración. Al compartir una novedad o reporte, WhatsApp te preguntará a quién enviar (portería, admin, supervisor, etc.).</p>
        <span class="estado ok">✓ Sin configuración necesaria</span>
    </div>

    <!-- Torres y apartamentos (placeholder — apunta al módulo si existe) -->
    <a class="cfg-card" href="<?= url('/apartamentos') ?>">
        <div class="icon">🏢</div>
        <h3>Torres y apartamentos</h3>
        <p>Configurar la estructura del conjunto: torres, pisos, apartamentos.</p>
        <span class="estado info">Estructural</span>
    </a>

    <!-- Conjuntos (super_admin) -->
    <?php if ($esSuperAdmin): ?>
    <a class="cfg-card" href="<?= url('/conjuntos') ?>">
        <span class="tag">SUPER</span>
        <div class="icon">🏬</div>
        <h3>Conjuntos residenciales</h3>
        <p>Administrar conjuntos del sistema (multi-tenancy).</p>
        <span class="estado info">Multi-tenant</span>
    </a>
    <?php endif; ?>

    <!-- v7.3: Copias de seguridad (super_admin) -->
    <?php if ($esSuperAdmin): ?>
    <a class="cfg-card" href="<?= url('/configuracion/backups') ?>">
        <span class="tag">SUPER</span>
        <div class="icon">💾</div>
        <h3>Copias de seguridad</h3>
        <p>Backup de la base de datos: manual, automático por cron, descargar y restaurar.</p>
        <span class="estado info">Base de datos</span>
    </a>
    <?php endif; ?>

    <!-- v7.0: Importar residentes desde Google Contacts -->
    <?php if (auth_has_role('super_admin','admin')): ?>
    <a class="cfg-card" href="<?= url('/importaciones/contactos') ?>">
        <div class="icon">📇</div>
        <h3>Importar residentes</h3>
        <p>Sincronizar desde los contactos de Google. Preview antes de aplicar.</p>
        <span class="estado info">Contactos</span>
    </a>
    <?php endif; ?>

    <!-- Placeholders para futuras configuraciones -->
    <div class="cfg-card soon">
        <span class="tag">PRÓXIMAMENTE</span>
        <div class="icon">🔔</div>
        <h3>Notificaciones</h3>
        <p>Correos y alertas automáticas: morosidad, vencimientos, revistas.</p>
        <span class="estado info">Planeado</span>
    </div>

    <div class="cfg-card soon">
        <span class="tag">PRÓXIMAMENTE</span>
        <div class="icon">💰</div>
        <h3>Alquileres y morosidad</h3>
        <p>Reglas de cobro, días de gracia, monto por meses, bloqueo automático.</p>
        <span class="estado info">Planeado</span>
    </div>

    <div class="cfg-card soon">
        <span class="tag">PRÓXIMAMENTE</span>
        <div class="icon">🎨</div>
        <h3>Personalización visual</h3>
        <p>Logo del conjunto, colores del tema, encabezado de reportes.</p>
        <span class="estado info">Planeado</span>
    </div>

</div>

<div style="margin-top:20px;padding:12px 16px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;color:#6b7280">
    💡 <strong>Nota:</strong> este módulo agrupa configuraciones. Para la gestión operativa
    (usuarios, importaciones, auditoría), usa el
    <a href="<?= url('/administracion') ?>" style="color:#1e40af;font-weight:600">panel de administración</a>.
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
