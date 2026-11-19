<?php
declare(strict_types=1);
session_start();

const DB_HOST = 'localhost';
const DB_NAME = 'trucking_management';
const DB_USER = 'root';
const DB_PASS = '';
const APP_NAME = 'FleetFlow';
const BASE_URL = '';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value): string { return '$' . number_format((float)$value, 2); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function flash(string $type, string $message): void { $_SESSION['flash'] = compact('type','message'); }
function get_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid session token. Refresh and try again.');
    }
}
function current_user(): ?array {
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) return null;
    // Normalize database roles (for example, 'Admin') for consistent checks.
    $user['role'] = strtolower(trim((string)($user['role'] ?? '')));
    return $user;
}
function require_login(): void { if (!current_user()) redirect('login.php'); }
function require_admin(): void {
    require_login();
    if ((current_user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }
}
function audit(string $action, string $entity, ?int $entityId = null): void {
    if (!current_user()) return;
    $s = db()->prepare('INSERT INTO audit_logs(user_id,action,entity,entity_id) VALUES(?,?,?,?)');
    $s->execute([current_user()['id'],$action,$entity,$entityId]);
    // Prevent the request-level safety net from recording the same change twice.
    $GLOBALS['fleetflow_audit_recorded'] = true;
}

// Record authenticated form submissions throughout the application, including
// modules that do not yet call audit() explicitly. Explicit CRUD audit calls
// take precedence, so a successful action is not duplicated.
register_shutdown_function(static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !current_user()
        || !empty($GLOBALS['fleetflow_audit_recorded'])) return;
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'system'));
    if (in_array($page, ['login.php', 'logout.php'], true)) return;
    $entity = preg_replace('/\.php$/', '', $page) ?: 'system';
    $action = 'submit';
    $requestedAction = strtolower(trim((string)($_POST['action'] ?? '')));
    if (in_array($requestedAction, ['create','new','add'], true)) $action = 'create';
    elseif (in_array($requestedAction, ['update','edit','save'], true)) $action = 'update';
    elseif (in_array($requestedAction, ['delete','remove'], true)) $action = 'delete';
    $id = filter_var($_POST['id'] ?? $_POST['delete_id'] ?? null, FILTER_VALIDATE_INT);
    try { audit($action, $entity, $id !== false ? $id : null); } catch (Throwable $e) { error_log('Audit logging failed: ' . $e->getMessage()); }
});
function upload_file(string $field, array $allowed, string $folder): ?string {
    if (empty($_FILES[$field]['name'])) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed.');
    if ($_FILES[$field]['size'] > 15 * 1024 * 1024) throw new RuntimeException('File exceeds 15MB.');
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('File type not allowed.');
    $dir = __DIR__ . '/uploads/' . $folder;
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save uploaded file.');
    return 'uploads/' . $folder . '/' . $name;
}
