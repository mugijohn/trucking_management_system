<?php
require 'config.php';
require_login();
verify_csrf();

$error = '';
$rateDefault = 0.035; // 0.035 multiplier = 3.5%, matching the supplied dispatcher paystub.

function ensure_dispatcher_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS dispatchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        commission_rate DECIMAL(8,5) NOT NULL DEFAULT 0.03500,
        staff_type VARCHAR(20) NOT NULL DEFAULT 'Dispatcher',
        pay_type VARCHAR(20) NOT NULL DEFAULT 'Commission',
        pay_frequency VARCHAR(20) NOT NULL DEFAULT 'Weekly',
        fixed_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS dispatcher_payroll (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dispatcher_id INT NOT NULL,
        week_start DATE NOT NULL,
        week_end DATE NOT NULL,
        pay_date DATE NOT NULL,
        total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
        commission_rate DECIMAL(8,5) NOT NULL DEFAULT 0.03500,
        commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        pay_type VARCHAR(20) NOT NULL DEFAULT 'Commission',
        pay_frequency VARCHAR(20) NOT NULL DEFAULT 'Weekly',
        fixed_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        advances DECIMAL(12,2) NOT NULL DEFAULT 0,
        savings DECIMAL(12,2) NOT NULL DEFAULT 0,
        net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('Draft','Approved','Paid') NOT NULL DEFAULT 'Draft',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY dispatcher_period (dispatcher_id, week_start, week_end),
        CONSTRAINT fk_dispatcher_payroll_dispatcher FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upgrade existing installations without requiring a manual database reset.
    $columns = [
        'dispatchers' => [
            'staff_type' => "VARCHAR(20) NOT NULL DEFAULT 'Dispatcher'",
            'pay_type' => "VARCHAR(20) NOT NULL DEFAULT 'Commission'",
            'pay_frequency' => "VARCHAR(20) NOT NULL DEFAULT 'Weekly'",
            'fixed_pay' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        ],
        'dispatcher_payroll' => [
            'pay_type' => "VARCHAR(20) NOT NULL DEFAULT 'Commission'",
            'pay_frequency' => "VARCHAR(20) NOT NULL DEFAULT 'Weekly'",
            'fixed_pay' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        ],
    ];
    foreach ($columns as $table => $defs) {
        foreach ($defs as $column => $definition) {
            $check = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute([$table, $column]);
            if (!(int)$check->fetchColumn()) db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

function dispatcher_period_valid(string $start, string $end, string $frequency): bool {
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$a || !$b || $a->format('Y-m-d') !== $start || $b->format('Y-m-d') !== $end) return false;
    if ($frequency === 'Weekly') return (int)$a->format('N') === 3 && (int)$b->format('N') === 2 && $a->diff($b)->days === 6;
    return $frequency === 'Monthly' && $a->format('d') === '01' && $b->format('Y-m') === $a->format('Y-m') && $b->format('d') === $b->format('t');
}

function dispatcher_last_friday(DateTimeImmutable $date): DateTimeImmutable {
    $last = $date->modify('last day of this month');
    return $last->modify('last friday');
}

function dispatcher_create_paystub(array $staff, string $start, string $end, string $payDate, float $advances = 0, float $savings = 0): ?int {
    $loads = db()->prepare("SELECT l.rate FROM loads l WHERE l.status <> 'Cancelled' AND COALESCE(l.delivery_date,l.pickup_date) BETWEEN ? AND ?");
    $loads->execute([$start, $end]);
    $loadRows = $loads->fetchAll();
    $gross = round(array_sum(array_map(static fn($row) => (float)$row['rate'], $loadRows)), 2);
    $payType = $staff['pay_type'] ?? 'Commission';
    $rate = (float)$staff['commission_rate'];
    $fixedPay = (float)($staff['fixed_pay'] ?? 0);
    if ($payType === 'Commission' && !$loadRows) return null;
    $earnings = $payType === 'Fixed Pay' ? $fixedPay : round($gross * $rate, 2);
    $net = round($earnings - $advances - $savings, 2);
    if ($net < 0) throw new RuntimeException("Advances and savings cannot exceed the staff member's pay.");
    $save = db()->prepare("INSERT INTO dispatcher_payroll
        (dispatcher_id, week_start, week_end, pay_date, total_gross, commission_rate, commission_amount, pay_type, pay_frequency, fixed_pay, advances, savings, net_pay, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft')
        ON DUPLICATE KEY UPDATE pay_date=VALUES(pay_date), total_gross=VALUES(total_gross), commission_rate=VALUES(commission_rate),
        commission_amount=VALUES(commission_amount), pay_type=VALUES(pay_type), pay_frequency=VALUES(pay_frequency), fixed_pay=VALUES(fixed_pay), advances=VALUES(advances), savings=VALUES(savings), net_pay=VALUES(net_pay)");
    $save->execute([(int)$staff['id'], $start, $end, $payDate, $gross, $rate, $earnings, $payType, $staff['pay_frequency'] ?? 'Weekly', $fixedPay, $advances, $savings, $net]);
    $q = db()->prepare('SELECT id FROM dispatcher_payroll WHERE dispatcher_id=? AND week_start=? AND week_end=?');
    $q->execute([(int)$staff['id'], $start, $end]);
    return (int)$q->fetchColumn();
}

try { ensure_dispatcher_schema(); } catch (Throwable $e) { $error = 'Dispatcher setup failed: ' . $e->getMessage(); }

$today = new DateTimeImmutable('today');
$periodEnd = $today->modify('tuesday this week');
$defaultStart = $periodEnd->modify('-6 days')->format('Y-m-d');
$defaultEnd = $periodEnd->format('Y-m-d');

// Idempotent automatic scheduling: each visit catches up any due weekly/monthly stubs.
if (!$error) {
    try {
        $staffRows = db()->query('SELECT * FROM dispatchers WHERE active=1')->fetchAll();
        foreach ($staffRows as $staff) {
            $frequency = $staff['pay_frequency'] ?? 'Weekly';
            if ($frequency === 'Monthly') {
                $month = new DateTimeImmutable('first day of this month');
                $payDay = dispatcher_last_friday($month);
                if ($today >= $payDay) {
                    $start = $month->format('Y-m-01'); $end = $month->modify('last day of this month')->format('Y-m-d');
                    dispatcher_create_paystub($staff, $start, $end, $payDay->format('Y-m-d'));
                }
            } else {
                $endDate = $today->modify('tuesday this week');
                $startDate = $endDate->modify('-6 days');
                $payDay = $endDate->modify('+3 days');
                if ($today >= $payDay) dispatcher_create_paystub($staff, $startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $payDay->format('Y-m-d'));
            }
        }
    } catch (Throwable $e) { $error = 'Automatic payroll scheduling failed: ' . $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_dispatcher') {
            $name = trim($_POST['name'] ?? '');
            $staffType = $_POST['staff_type'] ?? 'Dispatcher';
            $payType = $_POST['pay_type'] ?? 'Commission';
            $frequency = $_POST['pay_frequency'] ?? 'Weekly';
            $rate = filter_var($_POST['commission_rate'] ?? '0.035', FILTER_VALIDATE_FLOAT);
            $fixedPay = filter_var($_POST['fixed_pay'] ?? '0', FILTER_VALIDATE_FLOAT);
            if ($name === '') throw new RuntimeException('Enter the staff member name.');
            if (!in_array($staffType, ['Dispatcher', 'Accountant'], true)) throw new RuntimeException('Select Dispatcher or Accountant.');
            if (!in_array($payType, ['Fixed Pay', 'Commission'], true)) throw new RuntimeException('Select Fixed Pay or Commission.');
            if (!in_array($frequency, ['Weekly', 'Monthly'], true)) throw new RuntimeException('Select Weekly or Monthly pay frequency.');
            if ($rate === false || $rate < 0 || $rate > 1) throw new RuntimeException('Commission rate must be between 0 and 1 (for example, 0.035 = 3.5%).');
            if ($fixedPay === false || $fixedPay < 0) throw new RuntimeException('Fixed pay must be a valid non-negative amount.');
            db()->prepare('INSERT INTO dispatchers (name, commission_rate, staff_type, pay_type, pay_frequency, fixed_pay) VALUES (?, ?, ?, ?, ?, ?)')->execute([$name, $rate, $staffType, $payType, $frequency, $fixedPay]);
            flash('success', $staffType . ' added with ' . $payType . '.');
            redirect('dispatcher.php');
        }
        if ($action === 'generate') {
            $dispatcherId = (int)($_POST['dispatcher_id'] ?? 0);
            $frequency = $_POST['pay_frequency'] ?? 'Weekly';
            $start = trim($_POST['week_start'] ?? '');
            $end = trim($_POST['week_end'] ?? '');
            $advances = filter_var($_POST['advances'] ?? '0', FILTER_VALIDATE_FLOAT);
            $savings = filter_var($_POST['savings'] ?? '0', FILTER_VALIDATE_FLOAT);
            if ($dispatcherId < 1) throw new RuntimeException('Select a staff member.');
            if (!in_array($frequency, ['Weekly','Monthly'], true) || !dispatcher_period_valid($start, $end, $frequency)) throw new RuntimeException($frequency === 'Monthly' ? 'Monthly pay period must run from the 1st through the last day of the month.' : 'Weekly pay period must run Wednesday through Tuesday.');
            if ($advances === false || $advances < 0 || $savings === false || $savings < 0) throw new RuntimeException('Advances and savings must be valid non-negative amounts.');
            $ds = db()->prepare('SELECT * FROM dispatchers WHERE id = ? AND active = 1');
            $ds->execute([$dispatcherId]); $dispatcher = $ds->fetch();
            if (!$dispatcher) throw new RuntimeException('The selected staff member was not found or is inactive.');
            if (($dispatcher['pay_frequency'] ?? 'Weekly') !== $frequency) throw new RuntimeException('The selected staff member has a different pay frequency.');
            $payDate = $frequency === 'Monthly' ? dispatcher_last_friday(new DateTimeImmutable($start))->format('Y-m-d') : (new DateTimeImmutable($end))->modify('+3 days')->format('Y-m-d');
            $stubId = dispatcher_create_paystub($dispatcher, $start, $end, $payDate, (float)$advances, (float)$savings);
            if (!$stubId) throw new RuntimeException('No eligible loads were found. No commission paystub was created.');
            flash('success', 'Staff paystub generated.');
            redirect('dispatcher_paystub.php?id=' . $stubId);
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$dispatchers = [];
$paystubs = [];
if (!$error) {
    try {
        $dispatchers = db()->query('SELECT * FROM dispatchers WHERE active=1 ORDER BY name')->fetchAll();
        $paystubs = db()->query('SELECT p.*, d.name AS dispatcher_name, d.staff_type FROM dispatcher_payroll p JOIN dispatchers d ON d.id=p.dispatcher_id ORDER BY p.week_start DESC, p.id DESC')->fetchAll();
    } catch (Throwable $e) { $error = 'Unable to load dispatcher records: ' . $e->getMessage(); }
}
$pageTitle = 'Dispatcher Payroll';
$active = 'dispatcher';
require 'partials_header.php';
?>
<div class="page-head"><div><h1>Staff Payroll</h1><p>Manage dispatchers and accountants with either fixed weekly pay or commission-based pay.</p></div></div>
<?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<div class="form-card" style="margin-bottom:20px">
  <h3>Add staff member</h3>
  <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_dispatcher">
    <div class="form-grid">
      <div class="field"><label>Full name *</label><input name="name" required placeholder="Full name"></div>
      <div class="field"><label>Staff position *</label><select name="staff_type" required><option value="Dispatcher">Dispatcher</option><option value="Accountant">Accountant</option></select></div>
      <div class="field"><label>Payment method *</label><select name="pay_type" id="staff_pay_type" required><option value="Commission">Commission</option><option value="Fixed Pay">Fixed Pay</option></select></div>
      <div class="field"><label>Pay frequency *</label><select name="pay_frequency" required><option value="Weekly">Weekly</option><option value="Monthly">Monthly</option></select></div>
      <div class="field" id="commission_field"><label>Commission rate (multiplier)</label><input type="number" name="commission_rate" min="0" max="1" step="0.00001" value="0.035"><small class="muted">0.035 = 3.5% of eligible load gross.</small></div>
      <div class="field" id="fixed_pay_field"><label>Fixed pay per pay period (USD)</label><input type="number" name="fixed_pay" min="0" step="0.01" value="0.00"></div>
    </div>
    <div class="form-actions"><button class="btn primary" type="submit">Add staff member</button></div>
  </form>
  <script>
  (function(){const select=document.getElementById('staff_pay_type');const commission=document.getElementById('commission_field');const fixed=document.getElementById('fixed_pay_field');function update(){commission.style.display=select.value==='Commission'?'':'none';fixed.style.display=select.value==='Fixed Pay'?'':'none';}select.addEventListener('change',update);update();})();
  </script>
</div>
<div class="form-card" style="margin-bottom:20px">
  <h3>Generate staff paystub</h3>
  <?php if (!$dispatchers): ?><div class="alert">Add a staff member above before generating a paystub.</div><?php endif; ?>
  <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="generate">
    <div class="form-grid">
      <div class="field"><label>Staff member *</label><select name="dispatcher_id" required><?php foreach ($dispatchers as $d): ?><option value="<?=(int)$d['id']?>"><?=e($d['name'])?> · <?=e($d['staff_type'] ?? 'Dispatcher')?> · <?=e($d['pay_type'] ?? 'Commission')?><?php if (($d['pay_type'] ?? 'Commission') === 'Commission'): ?> (<?=e(number_format((float)$d['commission_rate']*100,3))?>%)<?php else: ?> (<?=money($d['fixed_pay'] ?? 0)?>/week)<?php endif; ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Pay frequency</label><select name="pay_frequency" id="stub_frequency"><option value="Weekly">Weekly</option><option value="Monthly">Monthly</option></select></div>
      <div class="field"><label>Period starts</label><input type="date" name="week_start" id="stub_start" value="<?=e($defaultStart)?>" required></div>
      <div class="field"><label>Period ends</label><input type="date" name="week_end" id="stub_end" value="<?=e($defaultEnd)?>" required></div>
      <div class="field"><label>Advances / payroll (USD)</label><input type="number" name="advances" min="0" step="0.01" value="0"></div>
      <div class="field"><label>Others / savings (USD)</label><input type="number" name="savings" min="0" step="0.01" value="0"></div>
    </div>
    <div class="form-actions"><button class="btn primary" type="submit" <?=!$dispatchers?'disabled':''?>>Calculate &amp; generate staff paystub</button></div>
  </form>
  <script>
  (function(){const f=document.getElementById('stub_frequency'),a=document.getElementById('stub_start'),b=document.getElementById('stub_end');if(!f)return;function setDates(){const d=new Date();if(f.value==='Monthly'){const y=d.getFullYear(),m=d.getMonth();a.value=new Date(y,m,1).toISOString().slice(0,10);b.value=new Date(y,m+1,0).toISOString().slice(0,10);}else{const x=new Date();const day=x.getDay();const diff=(day+4)%7;x.setDate(x.getDate()-diff);const wed=new Date(x);const tue=new Date(x);tue.setDate(wed.getDate()+6);a.value=wed.toISOString().slice(0,10);b.value=tue.toISOString().slice(0,10);}}f.addEventListener('change',setDates);})();
  </script>
  <p class="muted">Weekly periods run Wednesday–Tuesday and are paid Friday. Monthly periods run from the 1st through month-end and are paid on the month’s last Friday. Paystubs are automatically generated when due. Fixed-pay stubs exclude loads.</p>
</div>
<div class="table-wrap"><table class="table"><thead><tr><th>Staff member</th><th>Pay frequency</th><th>Pay period</th><th>Payment date</th><th>Total drivers' gross</th><th>Pay type</th><th>Earnings</th><th>Take-home</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($paystubs as $p): ?><tr><td><?=e($p['dispatcher_name'])?> <small class="muted">(<?=e($p['staff_type'] ?? '')?>)</small></td><td><?=e($p['pay_frequency'] ?? 'Weekly')?></td><td><?=e($p['week_start'].' – '.$p['week_end'])?></td><td><?=e(date('M j, Y',strtotime($p['pay_date'])))?></td><td><?=($p['pay_type'] ?? 'Commission') === 'Fixed Pay' ? '—' : money($p['total_gross'])?></td><td><?=e($p['pay_type'] ?? 'Commission')?><?php if (($p['pay_type'] ?? 'Commission') === 'Commission'): ?> · <?=e(number_format((float)$p['commission_rate']*100,3))?>%<?php endif; ?></td><td><?=money($p['commission_amount'])?></td><td><strong><?=money($p['net_pay'])?></strong></td><td><span class="badge"><?=e($p['status'])?></span></td><td><a class="btn secondary small" href="dispatcher_paystub.php?id=<?=(int)$p['id']?>" target="_blank">Print</a></td></tr><?php endforeach; ?>
<?php if (!$paystubs): ?><tr><td colspan="10" class="empty">No staff paystubs generated.</td></tr><?php endif; ?>
</tbody></table></div>
<?php require 'partials_footer.php'; ?>
