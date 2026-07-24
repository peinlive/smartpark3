<?php
// /home/myzonaco/smartpark.myzona360.com/includes/helpers.php
// ──────────────────────────────────────────────────────────────
// Funciones utilitarias usadas en todo el sistema.
// ──────────────────────────────────────────────────────────────

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

// ───── Escape HTML (XSS) ─────
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ───── URL absoluta ─────
function url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return rtrim(ASSETS_URL, '/') . '/' . ltrim($path, '/');
}

// ───── Redirección segura ─────
function redirect(string $path = '/'): void
{
    $url = (str_starts_with($path, 'http')) ? $path : url($path);
    header('Location: ' . $url);
    exit;
}

// ───── Flash messages (un solo render) ─────
function flash_set(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_get(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

// ───── Sanitización básica de input ─────
function clean_string(?string $value, int $max = 255): string
{
    $v = trim((string)$value);
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

function clean_int($value, ?int $min = null, ?int $max = null): ?int
{
    if ($value === '' || $value === null) return null;
    $i = filter_var($value, FILTER_VALIDATE_INT);
    if ($i === false) return null;
    if ($min !== null && $i < $min) return null;
    if ($max !== null && $i > $max) return null;
    return $i;
}

// ───── Placas: normalización a MAYÚSCULAS sin separadores ─────
function normalizar_placa(?string $placa): string
{
    if ($placa === null) return '';
    $p = strtoupper($placa);
    $p = preg_replace('/[^A-Z0-9]/', '', $p);
    return $p ?? '';
}

// ───── Fechas Bogotá ─────
function ahora(): string
{
    return date('Y-m-d H:i:s');
}

function fecha_humana(?string $datetime): string
{
    if (!$datetime) return '—';
    $ts = strtotime($datetime);
    if (!$ts) return e($datetime);
    return date('d/m/Y g:i A', $ts);
}

// ───── Logging simple a archivo (cuando no hay debug) ─────
function log_evento(string $mensaje): void
{
    error_log('[SmartPark ' . ahora() . '] ' . $mensaje);
}
