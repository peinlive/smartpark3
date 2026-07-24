<?php
// /home/myzonaco/smartpark.myzona360.com/api/monitor_cerrar.php
// v1.0 — Marca una sesión como inactiva desde el Monitor (solo super_admin).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

header('Content-Type: application/json; charset=utf-8');
auth_require_role('super_admin');

$csrfOk = false;
$tok = $_POST['_csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
try {
    if (function_exists('csrf_validate'))  $csrfOk = csrf_validate($tok);
    else { $csrfOk = !empty($tok) && hash_equals($_SESSION['_csrf'] ?? '', $tok); }
} catch (\Throwable $e) {}
if (!$csrfOk) { echo json_encode(['ok' => false, 'error' => 'Token inválido']); exit; }

$pdo       = db();
$sesId     = $_POST['session_id'] ?? '';
$usuarioId = (int)($_POST['usuario_id'] ?? 0);

if (!$sesId && !$usuarioId) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros requeridos']); exit;
}

try {
    if ($sesId) {
        // Cerrar sesión específica
        $pdo->prepare("UPDATE monitor_sesiones SET activa = 0 WHERE session_id = :s")
            ->execute([':s' => $sesId]);
        // Eliminar el archivo de sesión PHP para forzar logout real
        $sesDir = ini_get('session.save_path') ?: (BASE_PATH . '/storage/sessions');
        $file = rtrim($sesDir, '/') . '/sess_' . preg_replace('/[^a-zA-Z0-9]/', '', $sesId);
        if (is_file($file)) @unlink($file);
    } else {
        // Cerrar TODAS las sesiones de un usuario
        $rows = $pdo->prepare("SELECT session_id FROM monitor_sesiones WHERE usuario_id = :u AND activa = 1");
        $rows->execute([':u' => $usuarioId]);
        $sesDir = ini_get('session.save_path') ?: (BASE_PATH . '/storage/sessions');
        foreach ($rows->fetchAll() as $r) {
            $file = rtrim($sesDir, '/') . '/sess_' . preg_replace('/[^a-zA-Z0-9]/', '', $r['session_id']);
            if (is_file($file)) @unlink($file);
        }
        $pdo->prepare("UPDATE monitor_sesiones SET activa = 0 WHERE usuario_id = :u")
            ->execute([':u' => $usuarioId]);
    }
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Error']);
}
