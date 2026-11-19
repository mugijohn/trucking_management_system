<?php
require 'config.php';
require_login();
verify_csrf();

$error = '';
$warning = '';

/** Ensure older installations have the driver fields used by Drivers and Payroll. */
function ensure_payroll_driver_schema(): void {
    $columns = [
        'truck_id' => "ALTER TABLE drivers ADD COLUMN truck_id INT(11) NULL AFTER phone",
        'pay_method' => "ALTER TABLE drivers ADD COLUMN pay_method ENUM('Percentage','Per Mile','Fixed Per Load','Weekly Salary') NOT NULL DEFAULT 'Fixed Per Load' AFTER truck_id",
        'pay_rate' => "ALTER TABLE drivers ADD COLUMN pay_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pay_method",
    ];

    foreach ($columns as $column => $sql) {
        $check = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!(int)$check->fetchColumn()) {
            db()->exec($sql);
        }
    }
}

/**
 * Payroll cycle: Wednesday through the following Tuesday (7 calendar days).
 * Payday is the Friday immediately after the period ends.
 */
function payroll_schedule_for_date(?DateTimeImmutable $today = null): array {
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    // Tuesday of the current calendar week; this keeps the cycle stable all week.
    $end = $today->modify('tuesday this week');
    $start = $end->modify('-6 days');
    $payday = $end->modify('+3 days');
    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'payday' => $payday->format('Y-m-d'),
    ];
}

function payroll_friday_for_period_end(string $periodEnd): string {
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
    if (!$end || $end->format('Y-m-d') !== $periodEnd) {
        throw new RuntimeException('Invalid pay period end date.');
    }
    if ((int)$end->format('N') !== 2) {
        throw new RuntimeException('The pay period must end on a Tuesday.');
    }
    return $end->modify('+3 days')->format('Y-m-d');
}

try {
    ensure_payroll_driver_schema();
} catch (Throwable $e) {
    $error = 'Payroll database setup failed: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    try {
        $start = trim($_POST['week_start'] ?? '');
        $end = trim($_POST['week_end'] ?? '');
        $driverId = (int)($_POST['driver_id'] ?? 0);
        $deductions = filter_var($_POST['deductions'] ?? '0', FILTER_VALIDATE_FLOAT);

        $startDate = DateTime::createFromFormat('!Y-m-d', $start);
        $endDate = DateTime::createFromFormat('!Y-m-d', $end);
        if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
            throw new RuntimeException('Enter valid payroll week start and end dates.');
        }
        $startObject = new DateTimeImmutable($start);
        $endObject = new DateTimeImmutable($end);
        if ((int)$startObject->format('N') !== 3 ||
            (int)$endObject->format('N') !== 2 ||
            $startObject->diff($endObject)->days !== 6) {
            throw new RuntimeException('The pay period must run for 7 days: Wednesday through the following Tuesday.');
        }
        $payday = payroll_friday_for_period_end($end);
        if ($driverId < 1) {
            throw new RuntimeException('Select a valid driver.');
        }
        if ($deductions === false || $deductions < 0) {
            throw new RuntimeException('Deductions must be a valid non-negative amount.');
        }

        $driverQuery = db()->prepare('SELECT id, first_name, last_name, pay_method, pay_rate FROM drivers WHERE id = ? LIMIT 1');
        $driverQuery->execute([$driverId]);
        $driver = $driverQuery->fetch(PDO::FETCH_ASSOC);
        if (!$driver) {
            throw new RuntimeException('The selected driver was not found.');
        }

        $method = trim((string)($driver['pay_method'] ?? ''));
        $rate = (float)($driver['pay_rate'] ?? 0);
        $allowedMethods = ['Percentage', 'Per Mile', 'Fixed Per Load', 'Weekly Salary'];
        if (!in_array($method, $allowedMethods, true)) {
            throw new RuntimeException('This driver has an invalid or missing pay method. Open Drivers, select a valid pay method, and save the driver.');
        }
        if (!is_finite($rate) || $rate <= 0) {
            throw new RuntimeException('This driver has no valid pay rate. Set a pay rate greater than zero in the Drivers page.');
        }

        // Weekly salary is independent of load activity. Load-based methods include
        // every assigned, non-cancelled load whose delivery date (or pickup date
        // when delivery date is NULL) falls inside the selected payroll period.
        if ($method === 'Weekly Salary') {
            $gross = $rate;
            $loadCount = null;
        } else {
            $loadQuery = db()->prepare("SELECT COUNT(*) AS load_count, COALESCE(SUM(rate), 0) AS revenue, COALESCE(SUM(miles), 0) AS miles FROM loads WHERE driver_id = ? AND status <> 'Cancelled' AND COALESCE(delivery_date, pickup_date) BETWEEN ? AND ?");
            $loadQuery->execute([$driverId, $start, $end]);
            $loadData = $loadQuery->fetch(PDO::FETCH_ASSOC) ?: [];
            $loadCount = (int)($loadData['load_count'] ?? 0);

            if ($loadCount === 0) {
                throw new RuntimeException('No eligible loads were found for this driver in the selected week. Check the driver assignment and the load pickup/delivery dates. No paystub was created.');
            }

            switch ($method) {
                case 'Percentage':
                    $gross = (float)$loadData['revenue'] * $rate / 100;
                    break;
                case 'Per Mile':
                    $gross = (float)$loadData['miles'] * $rate;
                    break;
                case 'Fixed Per Load':
                    $gross = $loadCount * $rate;
                    break;
                default:
                    throw new RuntimeException('Unsupported driver pay method.');
            }
        }

        $gross = round((float)$gross, 2);
        if (!is_finite($gross) || $gross <= 0) {
            throw new RuntimeException('The calculated gross pay is zero or invalid. Check the driver rate and the assigned load data. No paystub was created.');
        }

        $net = round($gross - (float)$deductions, 2);
        if ($net < 0) {
            throw new RuntimeException('Deductions cannot exceed gross pay.');
        }

        $paymentMethod = trim($_POST['payment_method'] ?? 'Direct Deposit');
        $validPaymentMethods = ['Direct Deposit', 'Check', 'Cash', 'Other'];
        if (!in_array($paymentMethod, $validPaymentMethods, true)) {
            throw new RuntimeException('Select a valid payment method.');
        }
        $status = $_POST['status'] ?? 'Draft';
        if (!in_array($status, ['Draft', 'Approved', 'Paid'], true)) {
            $status = 'Draft';
        }
        $notes = trim($_POST['notes'] ?? '');
        if ($method !== 'Weekly Salary') {
            $notes = trim($notes . ' | ' . $loadCount . ' assigned load(s) included; delivery date used, pickup date used when delivery date is missing.');
            $notes = ltrim($notes, ' |');
        }

        $save = db()->prepare("INSERT INTO payroll (driver_id, week_start, week_end, gross_pay, deductions, net_pay, payment_method, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE gross_pay = VALUES(gross_pay), deductions = VALUES(deductions), net_pay = VALUES(net_pay), payment_method = VALUES(payment_method), status = VALUES(status), notes = VALUES(notes)");
        $save->execute([$driverId, $start, $end, $gross, $deductions, $net, $paymentMethod, $status, $notes]);

        flash('success', 'Weekly paystub generated. Gross pay: ' . money($gross) . ($loadCount === null ? ' (weekly salary).' : ' (' . $loadCount . ' assigned load(s)).'));
        redirect('payroll.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_paystub') {
    try {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Your session has expired. Refresh the page and try again.');
        }
        $payrollId = (int)($_POST['payroll_id'] ?? 0);
        if ($payrollId < 1) throw new RuntimeException('Invalid paystub selected.');

        $recipientQuery = db()->prepare("
            SELECT p.id, d.first_name, d.last_name, d.email
            FROM payroll p JOIN drivers d ON d.id = p.driver_id
            WHERE p.id = ? LIMIT 1
        ");
        $recipientQuery->execute([$payrollId]);
        $recipient = $recipientQuery->fetch(PDO::FETCH_ASSOC);
        if (!$recipient) throw new RuntimeException('The paystub or driver could not be found.');
        $email = trim((string)($recipient['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('This driver does not have a valid email address. Update the driver email in Drivers first.');
        }

        // Render the exact paystub page as an HTML email, rather than sending a login-only link.
        $oldGet = $_GET;
        $_GET['id'] = (string)$payrollId;
        ob_start();
        require __DIR__ . '/paystub.php';
        $paystubHtml = ob_get_clean();
        $_GET = $oldGet;

        if (!$paystubHtml) throw new RuntimeException('Could not generate the paystub email content.');
        $logoPath = __DIR__ . '/assets/alphanak_logo.jpeg';
        if (is_file($logoPath)) {
            $logoData = base64_encode((string)file_get_contents($logoPath));
            $paystubHtml = str_replace('src="assets/alphanak_logo.jpeg"', 'src="data:image/jpeg;base64,' . $logoData . '"', $paystubHtml);
        }
        // Remove the print-only action area from the emailed copy.
        $paystubHtml = preg_replace('/<div class="actions">.*?<\\/div>/s', '', $paystubHtml);

        $subject = 'Your paystub - ' . $recipient['first_name'] . ' ' . $recipient['last_name'];
        $fromHost = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $from = 'payroll@' . ($fromHost ?: 'localhost');
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: FleetFlow Payroll <{$from}>\r\n";
        if (!mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $paystubHtml, $headers)) {
            throw new RuntimeException('The email could not be sent. Check that your web server email (PHP mail) is configured.');
        }
        flash('success', 'Paystub emailed to ' . $email . '.');
        redirect('payroll.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['status'], $_GET['id']) && strtolower(current_user()['role'] ?? '') === 'admin') {
    $newStatus = $_GET['status'];
    if (in_array($newStatus, ['Draft', 'Approved', 'Paid'], true)) {
        db()->prepare('UPDATE payroll SET status = ? WHERE id = ?')->execute([$newStatus, (int)$_GET['id']]);
        flash('success', 'Payroll status updated.');
    }
    redirect('payroll.php');
}

$drivers = [];
$rows = [];
if (!$error || str_starts_with($error, 'No eligible loads')) {
    try {
        $drivers = db()->query('SELECT id, first_name, last_name, pay_method, pay_rate FROM drivers ORDER BY first_name, last_name')->fetchAll(PDO::FETCH_ASSOC);
        $rows = db()->query('SELECT p.*, d.first_name, d.last_name FROM payroll p JOIN drivers d ON d.id = p.driver_id ORDER BY p.week_start DESC, p.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $payrollTotals = db()->query('SELECT COALESCE(SUM(gross_pay),0) gross_total, COALESCE(SUM(deductions),0) deductions_total, COALESCE(SUM(net_pay),0) net_total FROM payroll')->fetch(PDO::FETCH_ASSOC) ?: ['gross_total'=>0,'deductions_total'=>0,'net_total'=>0];
    } catch (Throwable $e) {
        $error = $error ?: 'Unable to load payroll records: ' . $e->getMessage();
    }
}

$pageTitle = 'Driver Payroll';
$active = 'payroll';
require 'partials_header.php';
?>
<?php $schedule = payroll_schedule_for_date(); ?>
<div class="page-head"><div><h1>Driver Payroll</h1><p>Weekly payroll runs Wednesday through Tuesday, with payment every Friday.</p></div></div>
<div class="form-card" style="margin-bottom:20px">
    <h3>Automatic weekly payroll schedule</h3>
    <div class="form-grid">
        <div class="field"><label>Pay period starts</label><input type="text" value="<?=e(date('l, M j, Y', strtotime($schedule['start'])))?>" readonly></div>
        <div class="field"><label>Pay period ends</label><input type="text" value="<?=e(date('l, M j, Y', strtotime($schedule['end'])))?>" readonly></div>
        <div class="field"><label>Payment date</label><input type="text" value="<?=e(date('l, M j, Y', strtotime($schedule['payday'])))?>" readonly></div>
    </div>
    <p class="muted">Pay frequency: Weekly · 7-day period · Wednesday to Tuesday · Paid Friday.</p>
</div>
<div class="form-card" style="margin-bottom:20px">
    <h3>Generate weekly paystub</h3>
    <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
    <?php if (!$drivers && !$error): ?><div class="alert">No drivers found. Add a driver before generating payroll.</div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <div class="form-grid">
            <div class="field"><label>Driver *</label><select name="driver_id" required><?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"><?=e($d['first_name'].' '.$d['last_name'].' · '.($d['pay_method'] ?: 'Pay method missing').' · '.money($d['pay_rate']))?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Payment method</label><select name="payment_method"><option>Direct Deposit</option><option>Check</option><option>Cash</option><option>Other</option></select></div>
            <div class="field"><label>Pay period starts (Wednesday)</label><input type="date" name="week_start" required value="<?=e($schedule['start'])?>" readonly></div>
            <div class="field"><label>Pay period ends (Tuesday)</label><input type="date" name="week_end" required value="<?=e($schedule['end'])?>" readonly></div>
            <div class="field"><label>Payment date (Friday)</label><input type="text" value="<?=e($schedule['payday'])?>" readonly></div>
            <div class="field"><label>Other deductions (USD)</label><input type="number" min="0" step=".01" name="deductions" value="0"></div>
            <div class="field"><label>Notes</label><input name="notes"></div>
        </div>
        <div class="form-actions"><button class="btn primary" type="submit" <?= !$drivers ? 'disabled' : '' ?>>Calculate &amp; generate paystub</button></div>
    </form>
    <p class="muted">Pay period is automatically set to Wednesday–Tuesday (7 days); payday is the following Friday. Fixed Per Load = number of assigned, non-cancelled loads in the selected period × the driver's rate. Delivery date is used; pickup date is the fallback when delivery date is blank. Weekly Salary is calculated separately and does not require loads.</p>
</div>
<div class="table-wrap"><table class="table"><thead><tr><th>Driver</th><th>Pay period</th><th>Payday</th><th>Gross</th><th>Deductions</th><th>Net pay</th><th>Method</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?=e($r['first_name'].' '.$r['last_name'])?></td><td><?=e($r['week_start'].' – '.$r['week_end'])?></td><td><?=e(date('M j, Y', strtotime($r['week_end'].' +3 days')))?></td><td><?=money($r['gross_pay'])?></td><td><?=money($r['deductions'])?></td><td><strong style="color:#55d8a6"><?=money($r['net_pay'])?></strong></td><td><?=e($r['payment_method'])?></td><td><span class="badge"><?=e($r['status'])?></span></td><td>
    <a class="btn secondary small" href="paystub.php?id=<?=(int)$r['id']?>" target="_blank">Print</a>
    <form method="post" style="display:inline" onsubmit="return confirm('Email this paystub directly to <?=e($r['first_name'].' '.$r['last_name'])?>?');">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="email_paystub">
        <input type="hidden" name="payroll_id" value="<?=(int)$r['id']?>">
        <button class="btn primary small" type="submit">Email</button>
    </form>
    <?php if (strtolower(current_user()['role'] ?? '') === 'admin' && $r['status'] !== 'Paid'): ?><a href="?id=<?=(int)$r['id']?>&amp;status=Paid">Mark paid</a><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="empty">No paystubs generated.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="panel" style="margin-top:18px">
    <h3>Saved payroll totals · all periods</h3>
    <div class="grid-3">
        <div><span class="muted">Total gross pay</span><div class="stat-value"><?=money($payrollTotals['gross_total'])?></div></div>
        <div><span class="muted">Total deductions</span><div class="stat-value"><?=money($payrollTotals['deductions_total'])?></div></div>
        <div><span class="muted">Total net pay</span><div class="stat-value" style="color:#55d8a6"><?=money($payrollTotals['net_total'])?></div></div>
    </div>
</div>
<?php require 'partials_footer.php'; ?>
