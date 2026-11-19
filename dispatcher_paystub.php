<?php
require 'config.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT p.*, d.name AS dispatcher_name FROM dispatcher_payroll p JOIN dispatchers d ON d.id=p.dispatcher_id WHERE p.id=? LIMIT 1');
$q->execute([$id]);
$p = $q->fetch();
if (!$p) { http_response_code(404); exit('Dispatcher paystub not found.'); }
$loads = [];
if (($p['pay_type'] ?? 'Commission') !== 'Fixed Pay') {
$lq = db()->prepare("SELECT COALESCE(l.delivery_date,l.pickup_date) AS dispatch_date, COALESCE(NULLIF(t.unit_no,''),'—') AS truck_no,
    CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,'')) AS driver_name,
    COALESCE(NULLIF(b.company_name,''),NULLIF(l.broker,''),'—') AS company_name, l.rate
    FROM loads l LEFT JOIN drivers d ON d.id=l.driver_id LEFT JOIN trucks t ON t.id=l.truck_id LEFT JOIN brokers b ON b.id=l.broker_id
    WHERE l.status <> 'Cancelled' AND COALESCE(l.delivery_date,l.pickup_date) BETWEEN ? AND ?
    ORDER BY COALESCE(l.delivery_date,l.pickup_date), t.unit_no, d.first_name, l.id");
$lq->execute([$p['week_start'],$p['week_end']]);
$loads = $lq->fetchAll();
}
$money = static fn($v) => '$'.number_format((float)$v,2);
$fmtDate = static fn($v) => $v ? date('n/j/Y',strtotime($v)) : '—';
$ordinal = static function($n) { $n=(int)$n; $s='th'; if ($n%100<11 || $n%100>13) { if ($n%10===1)$s='st'; elseif($n%10===2)$s='nd'; elseif($n%10===3)$s='rd'; } return $n.$s; };
$period = ($p['pay_frequency'] ?? 'Weekly') === 'Monthly' ? date('F Y', strtotime($p['week_start'])) : date('M ',strtotime($p['week_start'])).$ordinal(date('j',strtotime($p['week_start']))).' - '.$ordinal(date('j',strtotime($p['week_end'])));
$ratePercent = number_format((float)$p['commission_rate']*100,3);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dispatcher Paystub - <?=e($p['dispatcher_name'])?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#edf2f5;color:#202833;font:13px/1.45 Arial,sans-serif}.sheet{width:8.5in;min-height:11in;margin:24px auto;padding:.48in .55in;background:#fff;border:1px solid #dbe3e9;box-shadow:0 12px 36px #20304018}.company{text-align:center;font-size:11px;color:#526170;line-height:1.6;margin-bottom:24px}.company img{display:block;width:145px;height:105px;object-fit:contain;margin:0 auto 8px}.to{background:#f1f7f9;border-left:4px solid #087f9f;padding:14px 16px;margin-bottom:20px}.label{font-size:10px;font-weight:bold;letter-spacing:2px;color:#687783}.to strong{display:block;font-size:17px;color:#087f9f;margin:4px 0}.period{display:flex;gap:28px;margin-top:10px;font-weight:bold;color:#465465}.loads{width:100%;border-collapse:collapse}.loads th{background:#eaf3f6;color:#41566a;font-size:10px;text-align:left;padding:10px 8px;border-bottom:1px solid #d6e0e5}.loads td{padding:10px 8px;border-bottom:1px solid #e9eef1}.num{text-align:right;white-space:nowrap}.total td{font-weight:bold;background:#f4f8fa;border-top:2px solid #cbd8df}.summary{width:100%;margin-top:22px;border-collapse:collapse}.summary td{padding:9px 12px;border-bottom:1px solid #e7edf0}.summary td:last-child{text-align:right;font-weight:bold}.summary .grand td{font-size:16px;font-weight:bold;background:#e9f5f7;color:#075e72;border-top:2px solid #087f9f}.actions{width:8.5in;margin:12px auto;text-align:right}.actions button{background:#087f9f;color:white;border:0;border-radius:6px;padding:10px 18px;cursor:pointer}@media print{body{background:white}.sheet{margin:0;width:100%;min-height:0;border:0;box-shadow:none}.actions{display:none}@page{size:letter portrait;margin:0}}@media(max-width:850px){.sheet{width:100%;margin:0;padding:18px;overflow-x:auto}.actions{width:100%;padding:0 18px}}
</style></head><body><div class="sheet">
<div class="company"><img src="assets/alphanak_logo.jpeg" alt="Alphanak Logistics LLC"><strong>ALPHANAK LOGISTICS LLC</strong><br>3325 GROVE PARK TER NW<br>ACWORTH, GA 30101<br>Tel: 404-423-8515<br>Tel: 770-881-5707</div>
<div class="to"><span class="label">TO</span><strong><?=e($p['dispatcher_name'])?></strong><?=e(strtoupper($p['staff_type'] ?? 'DISPATCHER'))?> · <?=e(strtoupper($p['pay_type'] ?? 'Commission'))?><div class="period"><span>P/PERIOD - <?=e($period)?></span><span>P/DATE - <?=e(date('M - ',strtotime($p['pay_date'])).$ordinal(date('j',strtotime($p['pay_date']))) )?></span></div></div>
<?php if (($p['pay_type'] ?? 'Commission') !== 'Fixed Pay'): ?><table class="loads"><thead><tr><th>D/DATE</th><th>TRUCK #</th><th>DRIVER NAME</th><th>COMPANY</th><th class="num">AMOUNT</th></tr></thead><tbody>
<?php foreach($loads as $load): ?><tr><td><?=e($fmtDate($load['dispatch_date']))?></td><td><?=e($load['truck_no'])?></td><td><?=e(trim($load['driver_name']) ?: '—')?></td><td><?=e($load['company_name'])?></td><td class="num"><?=e($money($load['rate']))?></td></tr><?php endforeach; ?>
<?php if(!$loads): ?><tr><td colspan="5">No loads found for this pay period.</td></tr><?php endif; ?>
<tr class="total"><td colspan="4">TOTAL GROSS</td><td class="num"><?=e($money($p['total_gross']))?></td></tr></tbody></table><?php endif; ?>
<table class="summary"><tbody><tr><td><?=e(($p['pay_type'] ?? 'Commission') === 'Fixed Pay' ? 'FIXED '.strtoupper($p['pay_frequency'] ?? 'WEEKLY').' PAY' : 'COMMISSION ('.$ratePercent.'%)')?></td><td><?=e($money($p['commission_amount']))?></td></tr><tr><td>ADV / PAYROLL</td><td><?=e($money($p['advances']))?></td></tr><tr><td>OTHERS / SAVING</td><td><?=e($money($p['savings']))?></td></tr><tr class="grand"><td>TOTAL TAKE-HOME</td><td><?=e($money($p['net_pay']))?></td></tr></tbody></table>
</div><div class="actions"><button onclick="window.print()">Print / Save PDF</button></div></body></html>
