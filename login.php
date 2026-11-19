<?php
require 'config.php';
if (current_user()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $s = db()->prepare('SELECT id,name,email,password_hash,role,status FROM users WHERE email=? LIMIT 1');
    $s->execute([trim($_POST['email'] ?? '')]); $u = $s->fetch();
    if ($u && $u['status']==='Active' && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        // Keep the stored database role as-is; current_user() normalizes it.
        unset($u['password_hash']);
        $_SESSION['user'] = $u;
        audit('login', 'users', (int)$u['id']);
        redirect('dashboard.php');
    }
    $error = 'Invalid email or password.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · FleetFlow</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="login-body"><div class="login-card"><div class="brand-mark">F</div><p class="eyebrow">FLEET OPERATIONS PLATFORM</p><h1>Welcome back</h1><p class="muted">Sign in to manage your trucking operation.</p>
<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Email address</label><input type="email" name="email" required autocomplete="username" placeholder="admin@fleetflow.com"><label>Password</label><input type="password" name="password" required autocomplete="current-password" placeholder="Your password"><button class="btn primary full">Sign in</button></form>
<div class="login-foot">First time? Run <a href="setup.php">system setup</a>.</div></div></body></html>