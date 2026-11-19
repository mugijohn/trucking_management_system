<?php
require 'config.php';
require_login();

$s = db()->prepare("SELECT p.*, d.first_name, d.last_name, d.email, d.insurance_amount
                    FROM payroll p JOIN drivers d ON d.id=p.driver_id WHERE p.id=?");
$s->execute([(int)($_GET['id'] ?? 0)]);
$p = $s->fetch();
if (!$p) { http_response_code(404); exit('Paystub not found'); }

$loadsStmt = db()->prepare("
    SELECT l.load_no, COALESCE(NULLIF(b.company_name, ''), NULLIF(l.broker, ''), '—') AS broker_name,
           l.pickup_location, l.delivery_location,
           l.delivery_date, l.pickup_date, l.rate, t.unit_no
    FROM loads l
    LEFT JOIN brokers b ON b.id = l.broker_id
    LEFT JOIN trucks t ON t.id=l.truck_id
    WHERE l.driver_id=? AND COALESCE(l.delivery_date,l.pickup_date) BETWEEN ? AND ?
      AND COALESCE(l.status, '') <> 'Cancelled'
    ORDER BY COALESCE(l.delivery_date,l.pickup_date), l.id
");
$loadsStmt->execute([$p['driver_id'], $p['week_start'], $p['week_end']]);
$loads = $loadsStmt->fetchAll();

$unitList = implode(', ', array_unique(array_filter(array_column($loads, 'unit_no'))));
$truckIds = array_values(array_unique(array_filter(array_column($loads, 'unit_no'))));
$truckDbIds = [];
if ($truckIds) {
    $marks = implode(',', array_fill(0, count($truckIds), '?'));
    $ts = db()->prepare("SELECT id FROM trucks WHERE unit_no IN ($marks)");
    $ts->execute($truckIds);
    $truckDbIds = $ts->fetchAll(PDO::FETCH_COLUMN);
}

/*
 * Include expenses assigned directly to this driver as well as expenses
 * recorded against any truck used on the driver's loads in this pay period.
 * This prevents driver-linked expenses from being omitted when no truck is set.
 */
$expenses = [];
$expenseWhere = "e.driver_id = ?";
$expenseParams = [$p['driver_id']];
if ($truckDbIds) {
    $marks = implode(',', array_fill(0, count($truckDbIds), '?'));
    $expenseWhere = "(e.driver_id = ? OR e.truck_id IN ($marks))";
    $expenseParams = array_merge($expenseParams, $truckDbIds);
}
$stateColumn = db()->query("SHOW COLUMNS FROM expenses LIKE 'state_code'")->fetch();
$stateSelect = $stateColumn ? "e.state_code" : "'' AS state_code";
$es = db()->prepare("SELECT e.expense_date, e.category, e.description, e.amount,
                            $stateSelect, e.vendor, t.unit_no
                     FROM expenses e LEFT JOIN trucks t ON t.id=e.truck_id
                     WHERE $expenseWhere AND e.expense_date BETWEEN ? AND ?
                     ORDER BY e.expense_date, e.id");
$es->execute(array_merge($expenseParams, [$p['week_start'], $p['week_end']]));
$expenses = $es->fetchAll();

/*
 * Every category entered in Fuel & Expenses is reflected on the paystub.
 * Recognized statement categories retain their familiar labels. Any new or
 * custom category is added as its own deduction line instead of disappearing.
 */
$categoryAliases = [
    'fuel' => ['label' => 'DIESEL', 'type' => 'deduction'],
    'diesel' => ['label' => 'DIESEL', 'type' => 'deduction'],
    'insurance' => ['label' => 'INSURANCE', 'type' => 'deduction'],
    'others/motive' => ['label' => 'OTHERS/MOTIVE', 'type' => 'deduction'],
    'other/motive' => ['label' => 'OTHERS/MOTIVE', 'type' => 'deduction'],
    'motive' => ['label' => 'OTHERS/MOTIVE', 'type' => 'deduction'],
    'others/c/l' => ['label' => 'OTHERS/C/L', 'type' => 'deduction'],
    'other/c/l' => ['label' => 'OTHERS/C/L', 'type' => 'deduction'],
    'c/l' => ['label' => 'OTHERS/C/L', 'type' => 'deduction'],
    'truck/k' => ['label' => 'TRUCK/K', 'type' => 'deduction'],
    'truck k' => ['label' => 'TRUCK/K', 'type' => 'deduction'],
    'savings' => ['label' => 'SAVINGS', 'type' => 'credit'],
    'others' => ['label' => 'OTHERS', 'type' => 'credit'],
    'other' => ['label' => 'OTHERS', 'type' => 'credit'],
    'fuel discount refund' => ['label' => 'FUEL DISCOUNT REFUND', 'type' => 'credit'],
    'others 2' => ['label' => 'OTHERS 2', 'type' => 'credit'],
    'other 2' => ['label' => 'OTHERS 2', 'type' => 'credit'],
    'other adjustment' => ['label' => 'OTHERS 2', 'type' => 'credit'],
];
$categoryTotals = [];
$fuelRows = [];
foreach ($expenses as $expense) {
    $rawCategory = trim((string)($expense['category'] ?? ''));
    $normalized = strtolower(preg_replace('/\s+/', ' ', $rawCategory));
    if ($normalized === '') $normalized = 'other';

    $definition = $categoryAliases[$normalized] ?? [
        'label' => strtoupper($rawCategory !== '' ? $rawCategory : 'OTHER'),
        'type' => 'deduction'
    ];
    $label = $definition['label'];
    if (!isset($categoryTotals[$label])) {
        $categoryTotals[$label] = ['amount' => 0.0, 'type' => $definition['type']];
    }
    $categoryTotals[$label]['amount'] += (float)$expense['amount'];

    if ($label === 'DIESEL') $fuelRows[] = $expense;
}
$grossLoads = array_sum(array_map(fn($x) => (float)$x['rate'], $loads));
$dispatchRate = 0.12;
$dispatchAmount = $grossLoads * $dispatchRate;

$deductionCategories = [];
$creditCategories = [];
foreach ($categoryTotals as $label => $data) {
    if ($data['type'] === 'credit') $creditCategories[$label] = $data['amount'];
    else $deductionCategories[$label] = $data['amount'];
}
// Fixed insurance is configured per driver and deducted once on every weekly paystub.
$weeklyInsurance = max(0.0, (float)($p['insurance_amount'] ?? 0));
if ($weeklyInsurance > 0) {
    $deductionCategories['INSURANCE'] = ($deductionCategories['INSURANCE'] ?? 0) + $weeklyInsurance;
}
$totalExpenses = $dispatchAmount + array_sum($deductionCategories);
$totalCredits = array_sum($creditCategories);
$totalNet = $grossLoads - $totalExpenses + $totalCredits;

$fmtDate = fn($v) => $v ? date('n/j/Y', strtotime($v)) : '';
$ordinal = function($n) {
    $n = (int)$n; $suffix = 'th';
    if ($n % 100 < 11 || $n % 100 > 13) {
        if ($n % 10 === 1) $suffix = 'st';
        elseif ($n % 10 === 2) $suffix = 'nd';
        elseif ($n % 10 === 3) $suffix = 'rd';
    }
    return $n . $suffix;
};
$fmtPeriod = function($start, $end) use ($ordinal) {
    $a = strtotime($start); $b = strtotime($end);
    return date('M ', $a) . $ordinal(date('j', $a)) . ' - ' . $ordinal(date('j', $b));
};
$periodEndDate = new DateTimeImmutable($p['week_end']);
$payDate = $periodEndDate->modify('+3 days')->format('Y-m-d');
$money = fn($v) => '$' . number_format((float)$v, 2);
$truck = $unitList ?: '—';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Paystub - <?=e($p['first_name'].' '.$p['last_name'])?></title>
<style>
:root{--ink:#20252b;--muted:#68757c;--line:#dce8eb;--soft:#f2f8fa;--navy:#087f9f;--accent:#159bb5;--green:#087f9f}
*{box-sizing:border-box}
body{margin:0;background:#edf4f6;color:var(--ink);font:12px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Arial,sans-serif}
.sheet{width:8.5in;min-height:11in;margin:24px auto;background:#fff;padding:.42in .48in .38in;border:1px solid #dce8eb;border-radius:14px;box-shadow:0 18px 50px rgba(18,73,87,.10)}
.company{text-align:center;color:var(--muted);font-size:10px;line-height:1.55;font-weight:550;margin:0 auto 22px;max-width:360px}
.company .company-logo{display:block;width:156px;height:118px;object-fit:contain;object-position:center;margin:0 auto 7px}
.to{position:relative;background:linear-gradient(110deg,#eef8fa,#f9fcfd);border:1px solid #d5e6ea;border-left:4px solid var(--accent);border-radius:10px;padding:14px 16px;margin-bottom:18px;line-height:1.55}
.to .label{display:block;margin-bottom:4px;color:var(--muted);font-size:9px;font-weight:800;letter-spacing:.13em}
.to strong{font-size:14px;color:var(--navy);letter-spacing:.02em}
.period{margin-top:9px;display:flex;gap:28px;color:#46556b;font-size:11px;font-weight:600}
.tables{display:grid;grid-template-columns:68% 32%;gap:14px;align-items:start}
table{border-collapse:separate;border-spacing:0;width:100%;table-layout:fixed}
.loads,.fuel{border:1px solid var(--line);border-radius:9px;overflow:hidden;background:#fff}
th,td{padding:7px 6px;text-align:left;vertical-align:middle;white-space:nowrap}
th{font-weight:750;color:#53627a;background:#eff7f9;font-size:9px;letter-spacing:.065em}
.loads thead tr:first-child th{border-bottom:0;padding-bottom:2px;background:#eff7f9}
.loads thead tr:last-child th{padding-top:5px;border-bottom:1px solid var(--line);background:#eff7f9}
.loads tbody td{padding-top:8px;padding-bottom:8px;border-bottom:1px solid #edf0f5;font-size:10px}
.loads tbody tr:last-child td{border-bottom:0}
.loads .broker{width:19%}.loads .loadno{width:12%}.loads .place{width:18%}.loads .amount{width:13%;text-align:right}
.fuel th{border-bottom:1px solid var(--line)}
.fuel th:first-child{border-radius:8px 0 0 0}
.fuel td{padding:8px 6px;border-bottom:1px solid #edf0f5;font-size:10px}
.fuel td:last-child,.fuel th:last-child{text-align:right}
.summary{margin-top:15px;width:100%;table-layout:auto;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff}
.summary td{padding:6px 12px;border-bottom:1px solid #edf0f5;font-size:11px}
.summary td:first-child{color:#526078;font-weight:600}
.summary td:last-child{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:650;color:#24344d}
.summary tr:last-child td{border-bottom:0}
.summary .rule td{border-top:1px solid #cfd7e3;padding-top:8px;font-weight:800;color:var(--navy)}
.summary .totalnet td{border-top:2px solid var(--navy);background:#e8f6f8;color:#075d70;font-weight:850;font-size:13px;padding-top:10px;padding-bottom:10px}
.load-total td{font-weight:800;border-top:1px solid #d6deea;padding-top:9px;color:var(--navy);background:#f1f8f9}
.load-total td:last-child{text-align:right}
.actions{width:8.5in;margin:12px auto;text-align:right}
button{background:var(--navy);color:#fff;border:0;border-radius:7px;padding:10px 16px;cursor:pointer;font:650 12px inherit;box-shadow:0 4px 12px rgba(20,45,78,.16)}
button:hover{background:#086b85}
@media print{
 body{background:#fff}.sheet{margin:0;width:100%;min-height:0;padding:.28in .38in;border:0;border-radius:0;box-shadow:none}
 .actions{display:none}@page{size:letter portrait;margin:0}
}
@media(max-width:900px){.sheet{width:100%;min-height:0;margin:0;padding:20px 12px;overflow-x:auto;border-radius:0}.tables{min-width:850px}.actions{width:100%;padding:0 12px}}
</style>
</head>
<body>
<div class="sheet">
  <div class="company">
    <img class="company-logo" src="assets/alphanak_logo.jpeg" alt="Alphanak Logistics LLC logo">
    3325 GROVE PARK TER NW<br>
    ACWORTH, GA 30101<br>
    Tel:404-423-8515<br>
    Tel:770-881-5707
  </div>

  <div class="to">
    <span class="label">TO</span>
    <strong><?=e(trim($p['first_name'].' '.$p['last_name']))?></strong><br>
    COMPANY: POMILLY TRANSPORT LLC<br>
    TRUCK # <?=e($truck)?><br>
    <div class="period">P/PERIOD - <?=e($fmtPeriod($p['week_start'],$p['week_end']))?><br>
    P/DATE - <?=e(date('M - ',strtotime($payDate)).$ordinal(date('j',strtotime($payDate))))?></div>
  </div>

  <div class="tables">
    <table class="loads">
      <thead>
        <tr><th></th><th></th><th></th><th colspan="2" style="text-align:center">City, State</th><th></th></tr>
        <tr>
          <th>D/Date</th><th class="broker">BROKER</th><th class="loadno">LOAD#</th>
          <th class="place">SHIPPER</th><th class="place">CONSIGNEE</th><th class="amount">AMOUNT</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($loads as $load): ?>
        <tr>
          <td><?=e($fmtDate($load['delivery_date'] ?: $load['pickup_date']))?></td>
          <td><?=e($load['broker_name'] ?: '—')?></td>
          <td><?=e($load['load_no'])?></td>
          <td><?=e($load['pickup_location'])?></td>
          <td><?=e($load['delivery_location'])?></td>
          <td class="amount"><?=e($money($load['rate']))?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$loads): ?><tr><td colspan="6">No loads for this pay period</td></tr><?php endif; ?>
      <tr class="load-total"><td colspan="5">TOTAL GROSS</td><td class="amount"><?=e($money($grossLoads))?></td></tr>
      <tr><td colspan="5">DISPATCH <span style="margin-left:24px">0.12</span></td><td></td></tr>
      </tbody>
    </table>

    <table class="fuel">
      <thead><tr><th colspan="3" style="text-align:center">FUEL</th></tr>
      <tr><th>DATE</th><th>STATE</th><th>AMOUNT</th></tr></thead>
      <tbody>
      <?php foreach ($fuelRows as $fuel): ?>
        <tr><td><?=e($fmtDate($fuel['expense_date']))?></td><td><?=e($fuel['state_code'] ?? '')?></td><td><?=e($money($fuel['amount']))?></td></tr>
      <?php endforeach; ?>
      <?php if (!$fuelRows): ?><tr><td colspan="3">&nbsp;</td></tr><?php endif; ?>
      <tr><td></td><td></td><td><?=e($money($deductionCategories['DIESEL'] ?? 0))?></td></tr>
      </tbody>
    </table>
  </div>

  <table class="summary">
    <tr><td style="width:75%">DISPATCH (12%)</td><td><?=e($money($dispatchAmount))?></td></tr>
    <?php foreach ($deductionCategories as $categoryLabel => $categoryAmount): ?>
      <tr><td><?=e($categoryLabel)?></td><td><?=e($money($categoryAmount))?></td></tr>
    <?php endforeach; ?>
    <tr class="rule"><td>TOTAL EXP</td><td><?=e($money($totalExpenses))?></td></tr>
    <?php foreach ($creditCategories as $categoryLabel => $categoryAmount): ?>
      <tr><td><?=e($categoryLabel)?></td><td><?=e($money($categoryAmount))?></td></tr>
    <?php endforeach; ?>
    <tr class="totalnet"><td>TOTAL NET</td><td><?=e($money($totalNet))?></td></tr>
  </table>
  <?php if (!empty($p['notes'])): ?><div style="margin-top:12px"><?=nl2br(e($p['notes']))?></div><?php endif; ?>
</div>
<div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div>
</body>
</html>
