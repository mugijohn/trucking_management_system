<?php require_once __DIR__.'/config.php'; require_login(); $pageTitle=$pageTitle??'Dashboard'; $active=$active??''; $u=current_user(); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($pageTitle)?> · FleetFlow</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app">
<aside class="sidebar" id="sidebar"><div class="brand"><div class="brand-mark">F</div>FleetFlow</div><nav class="nav">
<div class="nav-label">OVERVIEW</div><a class="<?=$active==='dashboard'?'active':''?>" href="dashboard.php"><span class="ico">▦</span>Dashboard</a>
<div class="nav-label">OPERATIONS</div>
<?php foreach(['trucks'=>'Trucks','drivers'=>'Drivers','loads'=>'Loads','brokers'=>'Brokers'] as $k=>$v):?><a class="<?=$active===$k?'active':''?>" href="<?=$k?>.php"><span class="ico">◈</span><?=$v?></a><?php endforeach;?>
<div class="nav-label">FINANCIALS</div>
<a class="<?=$active==='summary'?'active':''?>" href="summary.php"><span class="ico">▤</span>Summary</a>
<?php foreach(['invoices'=>'Invoices & Payments','factoring'=>'Factoring','expenses'=>'Fuel & Expenses','payroll'=>'Driver Payroll','dispatcher'=>'Dispatcher Payroll','ifta'=>'IFTA Reporting','reports'=>'Profit & Loss'] as $k=>$v):?><a class="<?=$active===$k?'active':''?>" href="<?=$k?>.php"><span class="ico">◫</span><?=$v?></a><?php endforeach;?>
<div class="nav-label">SAFETY & ADMIN</div>
<?php foreach(['documents'=>'Load Documents','compliance'=>'Compliance','users'=>'User Accounts'] as $k=>$v):?><a class="<?=$active===$k?'active':''?>" href="<?=$k?>.php"><span class="ico">▤</span><?=$v?></a><?php endforeach;?>
</nav></aside><main class="main"><header class="topbar"><div style="display:flex;align-items:center;gap:12px"><button class="btn secondary mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button><div><strong><?=e($pageTitle)?></strong><br><small>Fleet operations workspace</small></div></div><div class="user-pill"><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div><div class="user-name"><?=e($u['name'])?><br><small><?=e(ucfirst($u['role']))?></small></div><a href="logout.php">Sign out</a></div></header><section class="content">
<?php if($f=get_flash()):?><div class="alert <?=e($f['type'])?>"><?=e($f['message'])?></div><?php endif;?>
