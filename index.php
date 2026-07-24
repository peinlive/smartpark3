<?php
// /home/myzonaco/smartpark.myzona360.com/modules/administracion/index.php
// v1.0 (3AI): Landing de administración del sistema.
//   Solo super_admin y admin.
//   Agrupa: Configuración WhatsApp portería, Auditoría, Usuarios, Conjuntos,
//           Torres/Apartamentos (placeholder), Importaciones, Reportes.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

$u = auth_user();
$esSuperAdmin = auth_has_role('super_admin');

$_pageTitle = 'Administración';
include INCLUDES_PATH . '/header.php';
?>

<style>
.admin-head{background:linear-gradient(135deg,#0f172a,#334155);color:#fff;border-radius:10px;padding:20px 24px;margin-top:12px;}
.admin-head h1{margin:0;font-size:22px;}
.admin-head p{margin:6px 0 0;font-size:13px;opacity:.9;}

.admin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin:16px 0;}
.admin-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;text-decoration:none;color:inherit;transition:all .15s;display:flex;flex-direction:column;gap:10px;box-shadow:0 1px 3px rgba(0,0,0,.03);}
.admin-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);border-color:#94a3b8;}
.admin-card .icon{font-size:32px;line-height:1;}
.admin-card h3{margin:0;font-size:15px;color:#111827;}
.admin-card p{margin:0;font-size:12px;color:#6b7280;line-height:1.5;}
.admin-card .tag{align-self:flex-start;background:#f1f5f9;color:#475569;font-size:10px;padding:2px 8px;border-radius:8px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;}
.admin-card.super-only{border-left:4px solid #7c3aed;}
.admin-card.super-only .tag{background:#f5f3ff;color:#7c3aed;}
</style>

<div class="admin-head">
    <h1>🛠️ Panel de administración</h1>
    <p>Configuración del sistema, gestión de usuarios, auditoría y reportes globales.</p>
</div>

<div class="admin-grid">

    <!-- v3AK: Módulo Configuración (agrupa todas las configuraciones) -->
    <a class="admin-card" href="<?= url('/configuracion') ?>">
        <div class="icon">⚙️</div>
        <span class="tag">Configuración</span>
        <h3>Configuración del sistema</h3>
        <p>WhatsApp portería, torres, conjuntos y todas las opciones de configuración en un solo lugar.</p>
    </a>

    <!-- Usuarios -->
    <a class="admin-card" href="<?= url('/usuarios') ?>">
        <div class="icon">👥</div>
        <span class="tag">Gestión</span>
        <h3>Usuarios</h3>
        <p>Crear, editar y desactivar usuarios del sistema. Asignar roles.</p>
    </a>

    <!-- Auditoría -->
    <?php if ($esSuperAdmin): ?>
    <a class="admin-card super-only" href="<?= url('/auditoria') ?>">
        <div class="icon">🗃️</div>
        <span class="tag">Solo super_admin</span>
        <h3>Auditoría del sistema</h3>
        <p>Registro histórico de acciones: quién hizo qué y cuándo. Con filtros y exportación.</p>
    </a>
    <?php endif; ?>

    <!-- Conjuntos y Torres/Apartamentos ahora viven en /configuracion (v3AK) -->

    <!-- Importaciones -->
    <a class="admin-card" href="<?= url('/importaciones') ?>">
        <div class="icon">📥</div>
        <span class="tag">Datos</span>
        <h3>Importaciones</h3>
        <p>Cargar residentes, vehículos y apartamentos desde Excel/CSV.</p>
    </a>

    <!-- Dashboard -->
    <a class="admin-card" href="<?= url('/reportes/dashboard') ?>">
        <div class="icon">📊</div>
        <span class="tag">Reportes</span>
        <h3>Dashboard ejecutivo</h3>
        <p>KPIs, gráficas y panel general del conjunto en una sola pantalla.</p>
    </a>

    <!-- Reportes -->
    <a class="admin-card" href="<?= url('/reportes/alquileres') ?>">
        <div class="icon">💰</div>
        <span class="tag">Reportes</span>
        <h3>Alquileres y morosidad</h3>
        <p>Estado de pagos, morosos, pagos recibidos y bloqueos de comunes.</p>
    </a>

</div>

<div style="margin-top:20px;padding:12px 16px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;color:#6b7280">
    💡 <strong>Nota:</strong> los accesos aquí también están disponibles desde el menú lateral.
    Este panel agrupa la configuración administrativa para acceso rápido desde un solo lugar.
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
