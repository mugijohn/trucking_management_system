<?php
require 'config.php'; require_login();
$stats=[
['Fleet trucks',(int)db()->query("SELECT COUNT(*) FROM trucks")->fetchColumn(),'Registered units','▣'],
['Active drivers',(int)db()->query("SELECT COUNT(*) FROM drivers WHERE status IN ('Available','On Duty')")->fetchColumn(),'Available or on duty','♙'],
['Active loads',(int)db()->query("SELECT COUNT(*) FROM loads WHERE status IN ('Dispatched','In Transit')")->fetchColumn(),'Dispatched or in transit','⇢'],
['Revenue this month',(float)db()->query("SELECT COALESCE(SUM(rate),0) FROM loads WHERE status IN ('Delivered','Invoiced','Paid') AND MONTH(delivery_date)=MONTH(CURDATE()) AND YEAR(delivery_date)=YEAR(CURDATE())")->fetchColumn(),'Delivered load revenue','$'],
['Operating expenses',(float)db()->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())")->fetchColumn(),'Expenses this month','−'],
['Outstanding invoices',(float)db()->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status IN ('Sent','Overdue')")->fetchColumn(),'Awaiting broker payment','◷'],
];

// Loads that need accounts/dispatch follow-up.
// A driver is considered paid when a Paid payroll record covers the load's
// delivery date (or pickup date when delivery date is missing).
$driverPaidLoads = db()->query("
    SELECT l.id, l.load_no, l.broker, b.company_name,
           l.pickup_location, l.delivery_location, l.delivery_date, l.pickup_date,
           l.rate, l.status, CONCAT(d.first_name, ' ', d.last_name) AS driver_name,
           p.week_start, p.week_end, DATE_ADD(p.week_end, INTERVAL 3 DAY) AS payday
    FROM loads l
    INNER JOIN payroll p
      ON p.driver_id = l.driver_id
     AND p.status = 'Paid'
     AND COALESCE(l.delivery_date, l.pickup_date) BETWEEN p.week_start AND p.week_end
    LEFT JOIN brokers b ON b.id = l.broker_id
    LEFT JOIN drivers d ON d.id = l.driver_id
    WHERE l.status NOT IN ('Paid', 'Cancelled')
    ORDER BY payday DESC, l.delivery_date ASC, l.id DESC
")->fetchAll();

$overdueTransitLoads = db()->query("
    SELECT l.id, l.load_no, l.broker, b.company_name,
           l.pickup_location, l.delivery_location, l.delivery_date,
           l.rate, l.status, CONCAT(d.first_name, ' ', d.last_name) AS driver_name,
           DATEDIFF(CURDATE(), l.delivery_date) AS days_overdue
    FROM loads l
    LEFT JOIN brokers b ON b.id = l.broker_id
    LEFT JOIN drivers d ON d.id = l.driver_id
    WHERE l.status = 'In Transit'
      AND l.delivery_date IS NOT NULL
      AND l.delivery_date < CURDATE()
      AND l.status <> 'Paid'
    ORDER BY l.delivery_date ASC, l.id DESC
")->fetchAll();

$drivers=db()->query("SELECT d.id,d.first_name,d.last_name,COALESCE(SUM(p.gross_pay),0) gross,COALESCE(SUM(p.deductions),0) deductions,COALESCE(SUM(p.net_pay),0) net FROM drivers d LEFT JOIN payroll p ON p.driver_id=d.id GROUP BY d.id ORDER BY net DESC")->fetchAll();
$loads=db()->query("SELECT l.*,b.company_name,d.first_name,d.last_name FROM loads l LEFT JOIN brokers b ON b.id=l.broker_id LEFT JOIN drivers d ON d.id=l.driver_id ORDER BY l.created_at DESC LIMIT 8")->fetchAll();

// Truck earnings for the last 12 calendar months, based on delivered/invoiced/paid loads.
$chartStart = new DateTime('first day of this month');
$chartStart->modify('-11 months');
$chartEnd = (clone $chartStart)->modify('+12 months');
$monthKeys = [];
$monthLabels = [];
$monthCursor = clone $chartStart;
for ($i = 0; $i < 12; $i++) {
    $monthKeys[] = $monthCursor->format('Y-m');
    $monthLabels[] = $monthCursor->format('M Y');
    $monthCursor->modify('+1 month');
}
$truckRows = db()->query("SELECT id, unit_no, plate_no FROM trucks ORDER BY unit_no")->fetchAll();
$earningsStmt = db()->prepare("
    SELECT truck_id, DATE_FORMAT(delivery_date, '%Y-%m') AS month_key, SUM(rate) AS earnings
    FROM loads
    WHERE truck_id IS NOT NULL
      AND delivery_date >= ?
      AND delivery_date < ?
      AND status IN ('Delivered','Invoiced','Paid')
    GROUP BY truck_id, DATE_FORMAT(delivery_date, '%Y-%m')
");
$earningsStmt->execute([$chartStart->format('Y-m-d'), $chartEnd->format('Y-m-d')]);
$earningsRows = $earningsStmt->fetchAll();
$earningsByTruck = [];
foreach ($earningsRows as $earning) {
    $earningsByTruck[(int)$earning['truck_id']][$earning['month_key']] = (float)$earning['earnings'];
}
$truckColors = ['#2563eb','#e11d48','#16a34a','#9333ea','#ea580c','#0891b2','#ca8a04','#4f46e5','#db2777','#0d9488','#64748b','#65a30d'];
$truckChartDatasets = [];
foreach ($truckRows as $index => $truck) {
    $values = [];
    foreach ($monthKeys as $monthKey) {
        $values[] = round($earningsByTruck[(int)$truck['id']][$monthKey] ?? 0, 2);
    }
    $truckChartDatasets[] = [
        'label' => $truck['unit_no'] . ' (' . $truck['plate_no'] . ')',
        'data' => $values,
        'borderColor' => $truckColors[$index % count($truckColors)],
        'backgroundColor' => $truckColors[$index % count($truckColors)],
        'tension' => 0.3,
        'borderWidth' => 2.5,
        'pointRadius' => 3,
        'pointHoverRadius' => 6,
        'fill' => false
    ];
}

$grossEarningsStmt = db()->prepare("
    SELECT DATE_FORMAT(delivery_date, '%Y-%m') AS month_key, SUM(rate) AS gross_earnings
    FROM loads
    WHERE delivery_date >= ?
      AND delivery_date < ?
      AND status IN ('Delivered','Invoiced','Paid')
    GROUP BY DATE_FORMAT(delivery_date, '%Y-%m')
");
$grossEarningsStmt->execute([$chartStart->format('Y-m-d'), $chartEnd->format('Y-m-d')]);
$grossEarningsRows = $grossEarningsStmt->fetchAll();
$grossEarningsByMonth = [];
foreach ($grossEarningsRows as $grossRow) {
    $grossEarningsByMonth[$grossRow['month_key']] = (float)$grossRow['gross_earnings'];
}
$grossEarningsValues = [];
foreach ($monthKeys as $monthKey) {
    $grossEarningsValues[] = round($grossEarningsByMonth[$monthKey] ?? 0, 2);
}
// Dashboard net earnings always come from saved payroll records.
$net=(float)db()->query("SELECT COALESCE(SUM(net_pay),0) FROM payroll")->fetchColumn();
// Latest recorded system activity across all signed-in users.
$recentActivity = db()->query("
    SELECT a.id, a.action, a.entity, a.entity_id, a.created_at,
           COALESCE(u.name, 'System / deleted user') AS user_name
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 15
")->fetchAll();
$activityLabels = [
    'create' => 'Added', 'update' => 'Updated', 'delete' => 'Deleted',
    'login' => 'Logged in', 'logout' => 'Logged out',
    'mark_paid' => 'Marked paid', 'mark_unpaid' => 'Marked unpaid',
    'submit' => 'Submitted an action'
];
$entityLabels = [
    'users'=>'user account','trucks'=>'truck','drivers'=>'driver','loads'=>'load',
    'brokers'=>'broker','expenses'=>'expense','invoices'=>'invoice','payroll'=>'payroll',
    'dispatcher'=>'dispatcher payroll','documents'=>'document','compliance'=>'compliance record',
    'factoring'=>'factoring record','ifta'=>'IFTA record','summary'=>'payment record'
];
$pageTitle='Dashboard';$active='dashboard';require 'partials_header.php';
?>
<div class="page-head"><div><h1>Good day, <?=e(explode(' ',current_user()['name'])[0])?> 👋</h1><p>Here is what's happening across your fleet today.</p></div><a class="btn primary" href="loads.php?action=new">＋ Add new load</a></div>
<div class="cards"><?php foreach($stats as $i=>$s):?><div class="stat"><div class="stat-top"><span><?=e($s[0])?></span><span class="stat-icon"><?=$s[3]?></span></div><div class="stat-value"><?=$i>=3?money($s[1]):number_format($s[1])?></div><div class="stat-note"><?=e($s[2])?></div></div><?php endforeach;?></div>

<div class="panel" style="margin:22px 0">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <div><h3 style="margin:0 0 4px">Latest system activity</h3><p class="muted" style="margin:0">Recent additions, edits, deletions, sign-ins and other recorded actions</p></div>
        <span class="badge">Latest 15 events</span>
    </div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Activity</th><th>Section</th><th>Performed by</th><th>Date &amp; time</th></tr></thead>
        <tbody>
        <?php foreach ($recentActivity as $event):
            $actionText = $activityLabels[$event['action']] ?? ucwords(str_replace('_', ' ', (string)$event['action']));
            $entityText = $entityLabels[$event['entity']] ?? ucwords(str_replace('_', ' ', (string)$event['entity']));
            $activityText = in_array($event['action'], ['login','logout'], true) ? $actionText : $actionText . ' ' . $entityText;
            if ($event['entity_id'] && !in_array($event['action'], ['login','logout'], true)) $activityText .= ' #' . (int)$event['entity_id'];
        ?>
        <tr><td><strong><?=e($activityText)?></strong></td><td><?=e(ucwords(str_replace('_', ' ', (string)$event['entity'])))?></td><td><?=e($event['user_name'])?></td><td><?=e(date('M j, Y · g:i A', strtotime($event['created_at'])))?></td></tr>
        <?php endforeach; ?>
        <?php if (!$recentActivity): ?><tr><td colspan="4" class="empty">No activity has been recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="panel" style="margin:22px 0">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div>
            <h3 style="margin-bottom:5px">Truck earnings comparison</h3>
            <p class="muted" style="margin:0">Monthly load revenue by truck · last 12 months</p>
        </div>
        <span class="badge">12-month view</span>
    </div>
    <?php if (!$truckRows): ?>
        <p class="empty" style="padding:30px 10px">Add trucks to see the earnings comparison.</p>
    <?php else: ?>
        <div style="height:340px;position:relative;margin-top:18px">
            <canvas id="truckEarningsChart" aria-label="Line graph comparing monthly earnings for each truck over the last 12 months" role="img"></canvas>
        </div>
        <p class="muted" style="font-size:12px;margin:10px 0 0">Earnings include loads marked Delivered, Invoiced or Paid, grouped by delivery date. Each truck is shown in a different colour.</p>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    const canvas = document.getElementById('truckEarningsChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const labels = <?=json_encode($monthLabels, JSON_UNESCAPED_SLASHES)?>;
    const datasets = <?=json_encode($truckChartDatasets, JSON_UNESCAPED_SLASHES)?>;
    new Chart(canvas, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                tooltip: { callbacks: { label: context => `${context.dataset.label}: $${Number(context.parsed.y || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}` } }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Earnings ($)' },
                    ticks: { callback: value => '$' + Number(value).toLocaleString() }
                },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>
<div class="panel" style="margin:22px 0">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div>
            <h3 style="margin-bottom:5px">Total gross earnings trend</h3>
            <p class="muted" style="margin:0">Combined gross load revenue across all trucks · last 12 months</p>
        </div>
        <span class="badge">12-month view</span>
    </div>
    <div style="height:300px;position:relative;margin-top:18px">
        <canvas id="grossEarningsChart" aria-label="Line graph showing total gross earnings across all trucks over the last 12 months" role="img"></canvas>
    </div>
    <p class="muted" style="font-size:12px;margin:10px 0 0">Gross earnings are the total load rates for loads marked Delivered, Invoiced or Paid, grouped by delivery date, before expenses or driver deductions.</p>
</div>
<script>
(() => {
    const canvas = document.getElementById('grossEarningsChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const labels = <?=json_encode($monthLabels, JSON_UNESCAPED_SLASHES)?>;
    const values = <?=json_encode($grossEarningsValues, JSON_UNESCAPED_SLASHES)?>;
    new Chart(canvas, {
        type: 'line',
        data: { labels, datasets: [{
            label: 'Total gross earnings',
            data: values,
            borderColor: '#0f766e',
            backgroundColor: 'rgba(15,118,110,0.12)',
            borderWidth: 3,
            pointRadius: 4,
            pointHoverRadius: 7,
            tension: 0.35,
            fill: true
        }]},
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: { callbacks: { label: context => `Gross earnings: $${Number(context.parsed.y || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}` } }
            },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Gross earnings ($)' }, ticks: { callback: value => '$' + Number(value).toLocaleString() } },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>
<div class="panel" style="margin:22px 0;border:1px solid #f0b45a">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div>
            <h3 style="margin:0">ACTION NEEDED</h3>
            <p class="muted" style="margin:5px 0 0">Loads requiring payment or delivery follow-up</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <span class="badge" style="background:#fff1d6;color:#8a4b08"><?=count($driverPaidLoads)?> driver-paid / broker-unpaid</span>
            <span class="badge" style="background:#fee2e2;color:#991b1b"><?=count($overdueTransitLoads)?> overdue in transit</span>
        </div>
    </div>

    <h4 style="margin:16px 0 8px">Loads not paid by the broker — driver payroll paid Friday</h4>
    <p class="muted" style="font-size:12px;margin:0 0 10px">These loads fall within a driver payroll period marked Paid, but the load itself is not marked Paid.</p>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Load #</th><th>Broker</th><th>Driver</th><th>Route</th><th>Delivery date</th><th>Driver payday</th><th>Load amount</th><th>Load status</th></tr></thead>
        <tbody>
        <?php foreach ($driverPaidLoads as $item): ?>
        <tr>
            <td><a href="loads.php?action=edit&id=<?=(int)$item['id']?>"><?=e($item['load_no'])?></a></td>
            <td><?=e($item['company_name'] ?: $item['broker'] ?: '—')?></td>
            <td><?=e($item['driver_name'] ?: '—')?></td>
            <td><?=e($item['pickup_location'])?> → <?=e($item['delivery_location'])?></td>
            <td><?=e($item['delivery_date'] ?: '—')?></td>
            <td><?=e(date('M j, Y', strtotime($item['payday'])))?></td>
            <td><?=money($item['rate'])?></td>
            <td><span class="badge" style="background:#fff1d6;color:#8a4b08"><?=e($item['status'])?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$driverPaidLoads): ?><tr><td colspan="8" class="empty">No loads currently match this condition.</td></tr><?php endif; ?>
        </tbody>
    </table></div>

    <h4 style="margin:22px 0 8px">Overdue loads still marked In Transit</h4>
    <p class="muted" style="font-size:12px;margin:0 0 10px">Delivery dates have passed and the loads have not been marked Paid or delivered.</p>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Load #</th><th>Broker</th><th>Driver</th><th>Route</th><th>Expected delivery</th><th>Days overdue</th><th>Load amount</th></tr></thead>
        <tbody>
        <?php foreach ($overdueTransitLoads as $item): ?>
        <tr>
            <td><a href="loads.php?action=edit&id=<?=(int)$item['id']?>"><?=e($item['load_no'])?></a></td>
            <td><?=e($item['company_name'] ?: $item['broker'] ?: '—')?></td>
            <td><?=e($item['driver_name'] ?: '—')?></td>
            <td><?=e($item['pickup_location'])?> → <?=e($item['delivery_location'])?></td>
            <td><?=e($item['delivery_date'])?></td>
            <td><strong style="color:#b91c1c"><?=number_format((int)$item['days_overdue'])?> days</strong></td>
            <td><?=money($item['rate'])?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$overdueTransitLoads): ?><tr><td colspan="7" class="empty">No overdue loads are currently marked In Transit.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>


<div class="grid-2"><div class="panel"><h3>Company-wide driver net earnings</h3><div class="stat-top"><span>All recorded payroll periods</span><strong style="color:#54d7a7"><?=money($net)?></strong></div><div class="table-wrap" style="margin-top:14px"><table class="table"><thead><tr><th>Driver</th><th>Gross pay</th><th>Deductions</th><th>Net pay</th></tr></thead><tbody><?php foreach($drivers as $d):?><tr><td><?=e($d['first_name'].' '.$d['last_name'])?></td><td><?=money($d['gross'])?></td><td><?=money($d['deductions'])?></td><td><strong style="color:#54d7a7"><?=money($d['net'])?></strong></td></tr><?php endforeach;?><?php if(!$drivers):?><tr><td colspan="4" class="empty">Add drivers and payroll entries to see net earnings.</td></tr><?php endif;?></tbody></table></div></div>
<div class="panel"><h3>Financial snapshot · this month</h3><?php $rev=(float)$stats[3][1];$exp=(float)$stats[4][1];$profit=$rev-$exp;?><div class="stat-value"><?=money($profit)?></div><p class="muted">Estimated operating result before payroll, factoring and other unallocated costs.</p><div class="chart-bars"><?php foreach([['Revenue',$rev],['Expenses',$exp],['Net',$profit]] as $x):?><div class="bar-col"><div class="bar" style="height:<?=max(4,min(130,abs($x)/max(1,$rev,$exp)*130))?>px"></div><?=e($x[0])?></div><?php endforeach;?></div></div></div>
<div class="panel"><h3>Recent loads</h3><div class="table-wrap"><table class="table"><thead><tr><th>Load #</th><th>Broker</th><th>Driver</th><th>Route</th><th>Rate</th><th>Status</th></tr></thead><tbody><?php foreach($loads as $l):?><tr><td><a href="loads.php?action=edit&id=<?=$l['id']?>"><?=e($l['load_no'])?></a></td><td><?=e($l['company_name']??'—')?></td><td><?=e(trim(($l['first_name']??'').' '.($l['last_name']??''))?:'—')?></td><td><?=e($l['pickup_location'])?> → <?=e($l['delivery_location'])?></td><td><?=money($l['rate'])?></td><td><span class="badge"><?=e($l['status'])?></span></td></tr><?php endforeach;?><?php if(!$loads):?><tr><td colspan="6" class="empty">No loads have been created yet.</td></tr><?php endif;?></tbody></table></div></div>
<?php require 'partials_footer.php';?>