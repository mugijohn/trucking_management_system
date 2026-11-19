<?php
require_once __DIR__ . '/config.php';
require_login();
$pageTitle = 'Weekly Financial Summary';
$active = 'summary';

function summary_valid_period(string $start, string $end): bool {
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    return $a && $b && $a->format('Y-m-d') === $start && $b->format('Y-m-d') === $end
        && (int)$a->format('N') === 3 && (int)$b->format('N') === 2 && $a->diff($b)->days === 6;
}
function summary_money(float $amount): string { return '$' . number_format($amount, 2); }
function summary_normalize(string $value): string { return strtolower(trim(preg_replace('/\s+/', ' ', $value))); }

// Persist driver settlement status separately from payroll calculations.
try {
    db()->exec("CREATE TABLE IF NOT EXISTS driver_weekly_payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        week_start DATE NOT NULL,
        week_end DATE NOT NULL,
        status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
        paid_by INT NULL,
        paid_at DATETIME NULL,
        UNIQUE KEY uq_driver_week (driver_id, week_start, week_end),
        INDEX idx_driver_week_status (week_start, week_end, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* A readable error is shown if persistence is unavailable. */ }
try {
    db()->exec("CREATE TABLE IF NOT EXISTS dispatcher_weekly_payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        dispatcher_id INT NOT NULL,
        week_start DATE NOT NULL,
        week_end DATE NOT NULL,
        status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
        paid_by INT NULL,
        paid_at DATETIME NULL,
        UNIQUE KEY uq_dispatcher_week (dispatcher_id, week_start, week_end),
        INDEX idx_dispatcher_week_status (week_start, week_end, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* A readable error is shown if persistence is unavailable. */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispatcher_payment_action'])) {
    verify_csrf();
    $action = (string)$_POST['dispatcher_payment_action'];
    $dispatcherId = (int)($_POST['dispatcher_id'] ?? 0);
    $postStart = trim((string)($_POST['week_start'] ?? ''));
    $postEnd = trim((string)($_POST['week_end'] ?? ''));
    if ($dispatcherId < 1 || !summary_valid_period($postStart, $postEnd) || !in_array($action, ['mark_paid','mark_unpaid'], true)) {
        flash('error', 'Invalid dispatcher payment update.');
    } else {
        try {
            if ($action === 'mark_paid') {
                $stmt = db()->prepare("INSERT INTO dispatcher_weekly_payments (dispatcher_id,week_start,week_end,status,paid_by,paid_at) VALUES (?,?,?,'paid',?,NOW()) ON DUPLICATE KEY UPDATE status='paid',paid_by=VALUES(paid_by),paid_at=NOW()");
                $stmt->execute([$dispatcherId,$postStart,$postEnd,(int)(current_user()['id'] ?? 0)]);
                audit('mark_paid', 'dispatcher_weekly_payment', $dispatcherId);
                flash('success', 'Dispatcher payment marked as PAID.');
            } else {
                $stmt = db()->prepare("INSERT INTO dispatcher_weekly_payments (dispatcher_id,week_start,week_end,status,paid_by,paid_at) VALUES (?,?,?,'unpaid',NULL,NULL) ON DUPLICATE KEY UPDATE status='unpaid',paid_by=NULL,paid_at=NULL");
                $stmt->execute([$dispatcherId,$postStart,$postEnd]);
                audit('mark_unpaid', 'dispatcher_weekly_payment', $dispatcherId);
                flash('success', 'Dispatcher payment status changed to UNPAID.');
            }
        } catch (Throwable $e) { flash('error', 'Could not update dispatcher payment status: '.$e->getMessage()); }
    }
    redirect('summary.php?week_start='.urlencode($postStart).'&week_end='.urlencode($postEnd));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['driver_payment_action'])) {
    verify_csrf();
    $action = (string)$_POST['driver_payment_action'];
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $postStart = trim((string)($_POST['week_start'] ?? ''));
    $postEnd = trim((string)($_POST['week_end'] ?? ''));
    if ($driverId < 1 || !summary_valid_period($postStart, $postEnd) || !in_array($action, ['mark_paid','mark_unpaid'], true)) {
        flash('error', 'Invalid driver payment update.');
    } else {
        try {
            if ($action === 'mark_paid') {
                $stmt = db()->prepare("INSERT INTO driver_weekly_payments (driver_id,week_start,week_end,status,paid_by,paid_at) VALUES (?,?,?,'paid',?,NOW()) ON DUPLICATE KEY UPDATE status='paid',paid_by=VALUES(paid_by),paid_at=NOW()");
                $stmt->execute([$driverId,$postStart,$postEnd,(int)(current_user()['id'] ?? 0)]);
                audit('mark_paid', 'driver_weekly_payment', $driverId);
                flash('success', 'Driver payment marked as PAID.');
            } else {
                $stmt = db()->prepare("INSERT INTO driver_weekly_payments (driver_id,week_start,week_end,status,paid_by,paid_at) VALUES (?,?,?,'unpaid',NULL,NULL) ON DUPLICATE KEY UPDATE status='unpaid',paid_by=NULL,paid_at=NULL");
                $stmt->execute([$driverId,$postStart,$postEnd]);
                audit('mark_unpaid', 'driver_weekly_payment', $driverId);
                flash('success', 'Driver payment status changed to UNPAID.');
            }
        } catch (Throwable $e) { flash('error', 'Could not update payment status: '.$e->getMessage()); }
    }
    redirect('summary.php?week_start='.urlencode($postStart).'&week_end='.urlencode($postEnd));
}

$today = new DateTimeImmutable('today');
$defaultEnd = $today->modify('tuesday this week');
$defaultStart = $defaultEnd->modify('-6 days');
$weekStart = trim((string)($_GET['week_start'] ?? $defaultStart->format('Y-m-d')));
$weekEnd = trim((string)($_GET['week_end'] ?? $defaultEnd->format('Y-m-d')));
$error = '';
$driverRows = $dispatcherRows = $insuranceRows = $loanRows = $savingRows = $otherRows = [];
$driverTotal = $dispatcherTotal = $insuranceTotal = $loanTotal = $savingTotal = $otherTotal = $grandTotal = 0.0;

if (!summary_valid_period($weekStart, $weekEnd)) {
    $error = 'Select a valid Wednesday through Tuesday payroll period (7 days).';
} else {
    try {
        // Only drivers with a payroll record or eligible loads in this selected week are included in net dispatch.
        $driversStmt = db()->prepare("SELECT DISTINCT d.id, d.first_name, d.last_name, d.insurance_amount, d.pay_method, d.pay_rate
            FROM drivers d
            LEFT JOIN payroll p ON p.driver_id=d.id AND p.week_start=? AND p.week_end=?
            LEFT JOIN loads l ON l.driver_id=d.id AND l.status <> 'Cancelled' AND COALESCE(l.delivery_date,l.pickup_date) BETWEEN ? AND ?
            WHERE p.id IS NOT NULL OR l.id IS NOT NULL
            ORDER BY d.first_name,d.last_name");
        $driversStmt->execute([$weekStart,$weekEnd,$weekStart,$weekEnd]);
        $drivers = $driversStmt->fetchAll();
        $paymentStatusStmt = db()->prepare("SELECT driver_id,status,paid_by,paid_at FROM driver_weekly_payments WHERE week_start=? AND week_end=?");
        $paymentStatusStmt->execute([$weekStart,$weekEnd]);
        $paymentStatuses = [];
        foreach ($paymentStatusStmt->fetchAll() as $paymentStatus) $paymentStatuses[(int)$paymentStatus['driver_id']] = $paymentStatus;

        $payrollStmt = db()->prepare("SELECT gross_pay,deductions,net_pay FROM payroll WHERE driver_id=? AND week_start=? AND week_end=? ORDER BY id DESC LIMIT 1");
        $loadStmt = db()->prepare("SELECT l.id,l.rate,l.truck_id,l.load_no FROM loads l
            WHERE l.driver_id=? AND COALESCE(l.status,'') <> 'Cancelled'
            AND COALESCE(l.delivery_date,l.pickup_date) BETWEEN ? AND ? ORDER BY l.id");
        $expenseStmt = db()->prepare("SELECT e.id,e.category,e.amount,e.truck_id,e.driver_id,e.description
            FROM expenses e WHERE e.expense_date BETWEEN ? AND ?
            AND (e.driver_id=? OR e.truck_id IN (SELECT DISTINCT l.truck_id FROM loads l
                WHERE l.driver_id=? AND l.truck_id IS NOT NULL AND COALESCE(l.status,'') <> 'Cancelled'
                AND COALESCE(l.delivery_date,l.pickup_date) BETWEEN ? AND ?))
            ORDER BY e.id");

        $loanKeywords = ['others/c/l','other/c/l','c/l','credit card','credit cards','loan','c/ loan','advance','advances'];
        $savingKeywords = ['saving','savings'];
        $insuranceTotal = 0.0;
        $seenInsurance = [];
        foreach ($drivers as $driver) {
            $id = (int)$driver['id'];
            $loadStmt->execute([$id,$weekStart,$weekEnd]);
            $loads = $loadStmt->fetchAll();
            $gross = array_sum(array_map(static fn($r)=>(float)$r['rate'],$loads));
            $payrollStmt->execute([$id,$weekStart,$weekEnd]);
            $payrollRecord = $payrollStmt->fetch() ?: null;
            // A weekly-salary driver is paid the saved weekly salary, even when no loads were assigned.
            if (($driver['pay_method'] ?? '') === 'Weekly Salary' && $payrollRecord) $gross = (float)$payrollRecord['gross_pay'];
            $manualDeduction = (float)($payrollRecord['deductions'] ?? 0);
            $dispatchFee = $gross * 0.12; // same dispatch charge used by the driver paystub
            $truckIds = array_values(array_unique(array_filter(array_map(static fn($r)=>(int)$r['truck_id'],$loads))));
            $expenseStmt->execute([$weekStart,$weekEnd,$id,$id,$weekStart,$weekEnd]);
            $expenses = $expenseStmt->fetchAll();
            $deductions = 0.0; $credits = 0.0;
            foreach ($expenses as $expense) {
                $category = summary_normalize((string)$expense['category']);
                $amount = (float)$expense['amount'];
                if (in_array($category,$savingKeywords,true)) {
                    // Savings are withheld from the driver's weekly take-home and reported separately.
                    $deductions += $amount;
                } elseif (in_array($category,['others','other','fuel discount refund','others 2','other 2','other adjustment'],true)) {
                    $credits += $amount;
                } else {
                    $deductions += $amount;
                    if (in_array($category,$loanKeywords,true)) {
                        $loanRows['driver:'.$id] = ['name'=>$driver['first_name'].' '.$driver['last_name'],'amount'=>($loanRows['driver:'.$id]['amount']??0)+$amount];
                    } elseif (!in_array($category,['insurance'],true)) {
                        $key = 'driver:'.$id.':'.$category;
                        $otherRows[$key] = ['name'=>$driver['first_name'].' '.$driver['last_name'].' — '.($expense['category'] ?: 'Other'),'amount'=>($otherRows[$key]['amount']??0)+$amount];
                    }
                }
                if (in_array($category,$savingKeywords,true)) {
                    $key='driver:'.$id;
                    $savingRows[$key]=['name'=>$driver['first_name'].' '.$driver['last_name'],'amount'=>($savingRows[$key]['amount']??0)+$amount];
                }
            }
            $insurance = max(0.0,(float)($driver['insurance_amount']??0));
            if ($insurance > 0) {
                $insuranceRows[$id]=['name'=>$driver['first_name'].' '.$driver['last_name'],'amount'=>$insurance];
                $insuranceTotal += $insurance;
                $seenInsurance[$id]=true;
            }
            // Driver net mirrors the paystub: load gross less dispatch, expenses and weekly driver insurance, plus paystub credits.
            // Use the saved payroll result as the single source of truth for this driver/week.
            $net = $payrollRecord !== null ? round((float)$payrollRecord['net_pay'], 2) : 0.00;
            $paymentStatus = $paymentStatuses[$id] ?? ['status'=>'unpaid','paid_at'=>null,'paid_by'=>null];
            $driverRows[]=['driver_id'=>$id,'name'=>$driver['first_name'].' '.$driver['last_name'],'gross'=>$gross,'dispatch_fee'=>$dispatchFee,'deductions'=>$deductions + $manualDeduction,'insurance'=>$insurance,'net'=>$net,'load_count'=>count($loads),'payment_status'=>$paymentStatus['status'],'paid_at'=>$paymentStatus['paid_at']];
        }
        $driverTotal = array_sum(array_column($driverRows,'net'));

        // Weekly dispatcher net comes from the generated dispatcher paystubs for this exact period.
        $dispatcherExists = db()->query("SHOW TABLES LIKE 'dispatcher_payroll'")->fetchColumn();
        if ($dispatcherExists) {
            $dp = db()->prepare("SELECT p.dispatcher_id,p.net_pay,p.advances,p.savings,d.name FROM dispatcher_payroll p
                JOIN dispatchers d ON d.id=p.dispatcher_id WHERE p.week_start=? AND p.week_end=? ORDER BY d.name");
            $dp->execute([$weekStart,$weekEnd]);
            $dispatcherStatusStmt = db()->prepare("SELECT dispatcher_id,status,paid_at FROM dispatcher_weekly_payments WHERE week_start=? AND week_end=?");
            $dispatcherStatusStmt->execute([$weekStart,$weekEnd]);
            $dispatcherStatuses = [];
            foreach ($dispatcherStatusStmt->fetchAll() as $st) $dispatcherStatuses[(int)$st['dispatcher_id']] = $st;
            foreach ($dp->fetchAll() as $r) {
                $dispatcherStatus = $dispatcherStatuses[(int)$r['dispatcher_id']] ?? ['status'=>'unpaid','paid_at'=>null];
                $dispatcherRows[]=['dispatcher_id'=>(int)$r['dispatcher_id'],'name'=>$r['name'],'amount'=>(float)$r['net_pay'],'payment_status'=>$dispatcherStatus['status'],'paid_at'=>$dispatcherStatus['paid_at']];
                if ((float)$r['advances'] > 0) $loanRows['dispatcher:'.$r['name']]=['name'=>$r['name'].' — company loan/advance','amount'=>(float)$r['advances']];
                if ((float)$r['savings'] > 0) $savingRows['dispatcher:'.$r['name']]=['name'=>$r['name'],'amount'=>(float)$r['savings']];
            }
        }
        $dispatcherTotal=array_sum(array_column($dispatcherRows,'amount'));
        // Insurance is sourced directly from Drivers and shown once for each driver in the weekly payroll population.
        $insuranceTotal=array_sum(array_column($insuranceRows,'amount'));
        $loanTotal=array_sum(array_column($loanRows,'amount'));
        $savingTotal=array_sum(array_column($savingRows,'amount'));
        $otherTotal=array_sum(array_column($otherRows,'amount'));
        // Net payable excludes deductions and withholdings already reflected in net pay.\n        $grandTotal=round($driverTotal+$dispatcherTotal,2);
    } catch (Throwable $e) {
        $error = 'Unable to build the weekly summary: '.$e->getMessage();
    }
}

$fmtPeriod = date('M j, Y',strtotime($weekStart)).' – '.date('M j, Y',strtotime($weekEnd));
require __DIR__ . '/partials_header.php';
?>
<style>
.summary-sheet{max-width:1100px;margin:0 auto;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:24px}
.summary-company{text-align:center;line-height:1.55;margin-bottom:18px}.summary-company h2{font-size:20px;margin:0}
.summary-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;color:var(--muted);font-weight:600;margin:16px 0}
.summary-section{margin-top:22px}.summary-section h3{font-size:13px;letter-spacing:.7px;margin:0 0 8px;color:#8fbaff}
.summary-table{width:100%;border-collapse:collapse}.summary-table th,.summary-table td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left}
.summary-table th{font-size:11px;color:var(--muted);text-transform:uppercase}.summary-table td.amount,.summary-table th.amount{text-align:right;font-variant-numeric:tabular-nums}
.summary-table tfoot td{font-weight:800;border-top:2px solid #54708f}.summary-grand{display:flex;justify-content:flex-end;align-items:center;gap:24px;margin-top:22px;padding:18px;background:#132b49;border-radius:10px;font-weight:800;color:white}.summary-grand strong{font-size:25px;color:#72b7ff}
.summary-filter{display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-bottom:18px}.summary-filter .field{min-width:180px}
.payment-cell{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}.payment-status{display:inline-flex;align-items:center;padding:4px 9px;border-radius:20px;font-size:11px;font-weight:800}.payment-status.paid{background:#dcfce7;color:#166534}.payment-status.unpaid{background:#fef3c7;color:#92400e}.payment-action{border:0;border-radius:6px;padding:6px 9px;font-size:11px;font-weight:700;cursor:pointer}.payment-action.mark-paid{background:#15803d;color:#fff}.payment-action.mark-unpaid{background:#e5e7eb;color:#374151}
@media print{body{background:#fff!important;color:#111!important;font-size:11px}.sidebar,.topbar,.page-head,.no-print,.alert{display:none!important}.main{margin:0!important;width:100%!important}.content{padding:0!important;max-width:none!important}.summary-sheet{max-width:none;border:0;border-radius:0;padding:0;background:#fff;color:#111}.summary-company,.summary-meta,.summary-table td,.summary-table th,.summary-section h3{color:#111!important}.summary-table th,.summary-table td{border-bottom:1px solid #bbb;padding:6px 8px}.summary-table tfoot td{border-top:2px solid #333}.summary-grand{background:#eee!important;color:#111!important;border:1px solid #777}.summary-grand strong{color:#111!important}@page{size:A4 portrait;margin:12mm}}
</style>
<div class="page-head no-print"><div><h1>Weekly Financial Summary</h1><p>Net driver and dispatcher settlements, weekly insurance, loans and savings.</p></div><button class="btn primary" onclick="window.print()">🖨 Print Summary</button></div>
<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<form class="form-card summary-filter no-print" method="get">
 <div class="field"><label>Payroll week starts (Wednesday)</label><input type="date" name="week_start" value="<?=e($weekStart)?>" required></div>
 <div class="field"><label>Payroll week ends (Tuesday)</label><input type="date" name="week_end" value="<?=e($weekEnd)?>" required></div>
 <button class="btn primary" type="submit">Load weekly summary</button>
</form>
<?php if(!$error): ?>
<div class="summary-sheet">
 <div class="summary-company"><h2>ALPHANAK LOGISTICS LLC</h2>3325 GROVE PARK TER<br>ACWORTH, GA 30101<br>Tel: 404-423-8515 &nbsp; | &nbsp; Tel: 770-881-5707</div>
 <div class="summary-meta"><span>TO: DISPATCH</span><span>PAY PERIOD: <?=e($fmtPeriod)?></span><span>PAY DATE: <?=e(date('M j, Y',strtotime($weekEnd.' +3 days')))?></span></div>
 <?php
 $sections=[
  ['DISPATCH SUMMARY — DRIVER NET PAY',$driverRows,'net','Driver','net'],
  ['DISPATCH PAYMENT — DISPATCHER NET PAY',$dispatcherRows,'amount','Dispatcher','amount'],
  ['INSURANCE — ALL PAYROLL DRIVERS',$insuranceRows,'amount','Driver','amount'],
  ['CREDIT CARD / LOANS',$loanRows,'amount','Driver / Dispatcher','amount'],
  ['SAVINGS',$savingRows,'amount','Driver / Dispatcher','amount'],
  ['OTHERS — OTHER WEEKLY DEDUCTIONS',$otherRows,'amount','Driver / Category','amount']
 ];
 foreach($sections as [$heading,$rows,$valueKey,$label,$unused]):
   $sectionSum=0.0;
   foreach($rows as $row) $sectionSum+=(float)$row[$valueKey];
 ?>
 <section class="summary-section"><h3><?=e($heading)?></h3><table class="summary-table"><thead><tr><th><?=e($label)?></th><th class="amount">Amount (USD)</th><?php if(in_array($heading,['DISPATCH SUMMARY — DRIVER NET PAY','DISPATCH PAYMENT — DISPATCHER NET PAY'],true)): ?><th class="amount no-print">Payment status / action</th><?php endif; ?></tr></thead><tbody>
 <?php foreach($rows as $row): ?><tr><td><?=e($row['name'])?><?php if(isset($row['load_count'])): ?> <small class="muted">(<?= (int)$row['load_count']?> loads)</small><?php endif; ?></td><td class="amount"><?=summary_money((float)$row[$valueKey])?></td><?php if(in_array($heading,['DISPATCH SUMMARY — DRIVER NET PAY','DISPATCH PAYMENT — DISPATCHER NET PAY'],true)): ?><td class="amount no-print"><div class="payment-cell"><span class="payment-status <?=e($row['payment_status'])?>"><?=strtoupper(e($row['payment_status']))?></span><?php if($row['payment_status']==='paid'): ?><small class="muted"><?=e($row['paid_at'] ? date('M j, g:i a',strtotime($row['paid_at'])) : '')?></small><form method="post" onsubmit="return confirm('Change this payment back to UNPAID?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="<?= $heading==='DISPATCH SUMMARY — DRIVER NET PAY' ? 'driver_id' : 'dispatcher_id' ?>" value="<?=(int)($row['driver_id'] ?? $row['dispatcher_id'])?>"><input type="hidden" name="week_start" value="<?=e($weekStart)?>"><input type="hidden" name="week_end" value="<?=e($weekEnd)?>"><button class="payment-action mark-unpaid" name="<?= $heading==='DISPATCH SUMMARY — DRIVER NET PAY' ? 'driver_payment_action' : 'dispatcher_payment_action' ?>" value="mark_unpaid">Undo</button></form><?php else: ?><form method="post" onsubmit="return confirm('Confirm that you have sent <?=e(summary_money((float)$row[$valueKey]))?> to <?=e($row['name'])?>?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="<?= $heading==='DISPATCH SUMMARY — DRIVER NET PAY' ? 'driver_id' : 'dispatcher_id' ?>" value="<?=(int)($row['driver_id'] ?? $row['dispatcher_id'])?>"><input type="hidden" name="week_start" value="<?=e($weekStart)?>"><input type="hidden" name="week_end" value="<?=e($weekEnd)?>"><button class="payment-action mark-paid" name="<?= $heading==='DISPATCH SUMMARY — DRIVER NET PAY' ? 'driver_payment_action' : 'dispatcher_payment_action' ?>" value="mark_paid">Mark as Paid</button></form><?php endif; ?></div></td><?php endif; ?></tr><?php endforeach; ?>
 <?php if(!$rows): ?><tr><td colspan="<?= in_array($heading,['DISPATCH SUMMARY — DRIVER NET PAY','DISPATCH PAYMENT — DISPATCHER NET PAY'],true) ? '3' : '2' ?>" class="muted">No records for this payroll week.</td></tr><?php endif; ?>
 </tbody><tfoot><tr><td>TOTAL</td><td class="amount"><?=summary_money($sectionSum)?></td></tr></tfoot></table></section>
 <?php endforeach; ?>
 <div class="summary-grand"><span>GRAND TOTAL</span><strong><?=summary_money($grandTotal)?></strong></div>
 <p class="muted" style="font-size:11px;margin-top:14px">Weekly report: <?=e($fmtPeriod)?>. Driver net is calculated from weekly load gross less dispatch charge, weekly recorded deductions (including loans and savings), payroll-entered deductions and fixed driver insurance, plus recorded paystub credits. Dispatcher net is taken from weekly dispatcher paystubs.</p>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials_footer.php'; ?>
