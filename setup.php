<?php
require 'config.php';
$msg=''; $error='';
try {
    $server = new PDO('mysql:host='.DB_HOST.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->exec("USE `".DB_NAME."`");
    $sql = file_get_contents(__DIR__.'/database.sql');
    $server->exec($sql);
    $count=(int)$server->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count===0) {
        $s=$server->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,?,?)");
        $s->execute(['System Administrator','admin@fleetflow.com',password_hash('ChangeMe123!',PASSWORD_DEFAULT),'Admin','Active']);
    }
    $msg='Database and tables are ready. Sign in with admin@fleetflow.com and ChangeMe123! Change this password immediately.';
} catch(Throwable $e) { $error=$e->getMessage(); }
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>FleetFlow setup</title></head><body class="login-body"><div class="login-card"><div class="brand-mark">F</div><h1>System setup</h1><?php if($msg):?><div class="alert success"><?=e($msg)?></div><a class="btn primary full" href="login.php">Go to login</a><?php else:?><div class="alert error"><?=e($error)?></div><p>Check your MySQL credentials in config.php and try again.</p><a href="setup.php" class="btn secondary full">Retry setup</a><?php endif;?></div></body></html>