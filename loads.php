
<?php
require 'config.php';
require_login();
verify_csrf();

$title = "Loads";
$table = "loads";

$fields = [
    ["load_no", "Load number", "text", 1],
    ["broker_id", "Broker", "broker", 0],
    ["truck_id", "Truck", "truck", 0],
    ["driver_id", "Driver", "driver", 0],
    ["pickup_location", "Pickup location", "text", 0],
    ["delivery_location", "Delivery location", "text", 0],
    ["pickup_date", "Pickup date", "date", 0],
    ["delivery_date", "Delivery date", "date", 0],
    ["rate", "Load rate (USD)", "number", 1],
    ["miles", "Miles", "number", 0],
    ["status", "Status", "select:Booked,Dispatched,In Transit,Delivered,Invoiced,Paid,Cancelled", 0],
    ["factoring_status", "Factoring status", "select:Not Factored,Submitted,Funded,Reserve Released", 0],
    ["notes", "Notes", "textarea", 0]
];

// Automatically generate columns and labels
$cols = array_column($fields, 0);
$labels = [];

foreach ($fields as $f) {
    $labels[$f[0]] = $f[1];
}

// Calculated display column: load revenue divided by loaded miles.
// This is not stored in the database, so it always reflects the current rate and miles.
$ratePerMileColumn = 'rate_per_mile';
$labels[$ratePerMileColumn] = 'Rate per Mile';
$milesColumnPosition = array_search('miles', $cols, true);
array_splice($cols, $milesColumnPosition === false ? count($cols) : $milesColumnPosition + 1, 0, [$ratePerMileColumn]);

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$error = '';

// Dropdown options
function options_for($type) {

    if ($type === 'truck') {
        return db()->query(
            "SELECT id, unit_no AS label FROM trucks ORDER BY unit_no"
        )->fetchAll();
    }

    if ($type === 'driver') {
        return db()->query(
            "SELECT id, CONCAT(first_name,' ',last_name) AS label
             FROM drivers ORDER BY first_name"
        )->fetchAll();
    }

    if ($type === 'broker') {
        return db()->query(
            "SELECT id, company_name AS label
             FROM brokers ORDER BY company_name"
        )->fetchAll();
    }

    return [];
}

// Delete load
if (isset($_GET['delete']) && current_user()['role'] === 'admin') {

    $deleteId = (int)$_GET['delete'];

    $s = db()->prepare("DELETE FROM `$table` WHERE id = ?");
    $s->execute([$deleteId]);

    audit('delete', $table, $deleteId);
    flash('success', 'Load record deleted successfully.');

    redirect($table . '.php');
}

// Save load
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'upload_documents') {
    try {
        if (!$id) throw new RuntimeException('Save the load before uploading documents.');
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $uploadDir = __DIR__ . '/uploads/load_documents';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create the document upload folder.');
        }
        $saved = 0;
        foreach (['Rate Confirmation', 'BOL', 'Lumper Receipt'] as $docType) {
            $files = $_FILES['documents'] ?? null;
            if (!$files || !isset($files['name'][$docType])) continue;
            foreach ($files['name'][$docType] as $index => $originalName) {
                if ($originalName === '') continue;
                $errorCode = $files['error'][$docType][$index] ?? UPLOAD_ERR_NO_FILE;
                if ($errorCode !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed for ' . $docType . '.');
                $size = (int)$files['size'][$docType][$index];
                if ($size > 15 * 1024 * 1024) throw new RuntimeException('Each document must be 15MB or smaller.');
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions, true)) throw new RuntimeException('Unsupported file type for ' . $docType . '. Use PDF, JPG, PNG, DOC or DOCX.');
                $tmp = $files['tmp_name'][$docType][$index];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if (!in_array($mime, $allowedMimes, true)) throw new RuntimeException('The selected file type is not valid.');
                $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($tmp, $uploadDir . '/' . $storedName)) throw new RuntimeException('Could not save uploaded document.');
                $relativePath = 'uploads/load_documents/' . $storedName;
                $stmt = db()->prepare('INSERT INTO load_documents (load_id, document_type, file_path, original_name) VALUES (?, ?, ?, ?)');
                $stmt->execute([$id, $docType, $relativePath, basename($originalName)]);
                $saved++;
            }
        }
        flash('success', $saved ? $saved . ' document(s) uploaded successfully.' : 'Choose at least one document to upload.');
        redirect('loads.php?action=edit&id=' . $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $action = 'edit';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') !== 'upload_documents') {

    try {

        $data = [];

        foreach ($fields as $f) {

            $key = $f[0];
            $type = $f[2];

            if (
                in_array($type, [
                    'textarea',
                    'text',
                    'email',
                    'date',
                    'number',
                    'truck',
                    'driver',
                    'broker'
                ]) ||
                str_starts_with($type, 'select:')
            ) {

                $data[$key] = ($_POST[$key] ?? '') === ''
                    ? null
                    : trim((string)$_POST[$key]);
            }

            if ($f[3] && empty($data[$key])) {
                throw new RuntimeException($f[1] . ' is required.');
            }
        }

        // Update existing load
        if ($id) {

            $set = implode(',', array_map(
                fn($k) => "`$k` = ?",
                array_keys($data)
            ));

            $s = db()->prepare(
                "UPDATE `$table` SET $set WHERE id = ?"
            );

            $s->execute([...array_values($data), $id]);

            audit('update', $table, $id);

        } else {

            // Create new load
            $keys = array_keys($data);

            $s = db()->prepare(
                "INSERT INTO `$table` (`" .
                implode('`,`', $keys) .
                "`) VALUES (" .
                implode(',', array_fill(0, count($keys), '?')) .
                ")"
            );

            $s->execute(array_values($data));

            $id = (int)db()->lastInsertId();

            audit('create', $table, $id);
        }

        flash('success', 'Load saved successfully.');
        redirect($table . '.php?action=edit&id=' . $id);

    } catch (Throwable $e) {

        $error = $e->getMessage();
    }
}

// Load record for editing
$record = [];

if ($action === 'edit' && $id) {

    $s = db()->prepare("SELECT * FROM `$table` WHERE id = ?");
    $s->execute([$id]);

    $record = $s->fetch() ?: [];
}

$loadDocuments = [];
if ($action === 'edit' && $id) {
    $docQuery = db()->prepare('SELECT * FROM load_documents WHERE load_id = ? ORDER BY uploaded_at DESC, id DESC');
    $docQuery->execute([$id]);
    $loadDocuments = $docQuery->fetchAll();
}

// Default weekly cycle: Wednesday through the following Tuesday (7 days).
$today = new DateTimeImmutable('today');
$periodEndDefault = $today->modify('tuesday this week');
$periodStartDefault = $periodEndDefault->modify('-6 days');
$filterDriver = (int)($_GET['driver_id'] ?? 0);
$filterStart = trim($_GET['period_start'] ?? $periodStartDefault->format('Y-m-d'));
$filterEnd = trim($_GET['period_end'] ?? $periodEndDefault->format('Y-m-d'));
$filterError = '';

$startObj = DateTimeImmutable::createFromFormat('!Y-m-d', $filterStart);
$endObj = DateTimeImmutable::createFromFormat('!Y-m-d', $filterEnd);
if (!$startObj || !$endObj || $startObj->format('Y-m-d') !== $filterStart || $endObj->format('Y-m-d') !== $filterEnd) {
    $filterError = 'Please select valid start and end dates.';
} elseif ($startObj > $endObj) {
    $filterError = 'The start date cannot be later than the end date.';
}

$drivers = db()->query("SELECT id, CONCAT(first_name, ' ', last_name) AS driver_name FROM drivers ORDER BY first_name, last_name")->fetchAll();

// Payroll uses delivery date, falling back to pickup date, to assign loads to a week.
$loadSql = "
    SELECT l.*, b.company_name AS broker_name, t.unit_no AS truck_unit,
           CONCAT(d.first_name, ' ', d.last_name) AS driver_name
    FROM loads l
    LEFT JOIN brokers b ON l.broker_id = b.id
    LEFT JOIN trucks t ON l.truck_id = t.id
    LEFT JOIN drivers d ON l.driver_id = d.id
";
$params = [];
$where = [];
if ($filterError) {
    $where[] = "1 = 0";
} else {
    $where[] = "COALESCE(l.delivery_date, l.pickup_date) BETWEEN ? AND ?";
    $params[] = $filterStart;
    $params[] = $filterEnd;
}
if ($filterDriver > 0) {
    $where[] = "l.driver_id = ?";
    $params[] = $filterDriver;
}
if ($where) {
    $loadSql .= " WHERE " . implode(" AND ", $where);
}
$loadSql .= " ORDER BY COALESCE(l.delivery_date, l.pickup_date) DESC, l.id DESC";
if ($action !== 'print') {
    $loadSql .= " LIMIT 1000";
}
$loadQuery = db()->prepare($loadSql);
$loadQuery->execute($params);
$rows = $loadQuery->fetchAll();

$filterTotals = ['count' => count($rows), 'rate' => 0.0, 'miles' => 0.0];
foreach ($rows as $loadRow) {
    if (($loadRow['status'] ?? '') !== 'Cancelled') {
        $filterTotals['rate'] += (float)($loadRow['rate'] ?? 0);
        $filterTotals['miles'] += (float)($loadRow['miles'] ?? 0);
    }
}


// Dedicated print view: group the selected date range into Wednesday–Tuesday
// payroll weeks. Gross is the sum of non-cancelled load rates.
if ($action === 'print') {
    if ($filterError) {
        http_response_code(400);
        exit(e($filterError));
    }

    $groupedLoads = [];
    $weeklyGross = [];
    $overallGross = 0.0;
    foreach ($rows as $printLoad) {
        $loadDate = $printLoad['delivery_date'] ?: $printLoad['pickup_date'];
        if (!$loadDate) continue;
        $dateObject = new DateTimeImmutable($loadDate);
        $daysSinceWednesday = ((int)$dateObject->format('N') + 4) % 7;
        $weekStart = $dateObject->modify('-' . $daysSinceWednesday . ' days');
        $weekEnd = $weekStart->modify('+6 days');
        $weekKey = $weekStart->format('Y-m-d');
        if (!isset($groupedLoads[$weekKey])) {
            $groupedLoads[$weekKey] = [
                'start' => $weekStart,
                'end' => $weekEnd,
                'loads' => []
            ];
            $weeklyGross[$weekKey] = 0.0;
        }
        $groupedLoads[$weekKey]['loads'][] = $printLoad;
        if (($printLoad['status'] ?? '') !== 'Cancelled') {
            $rate = (float)($printLoad['rate'] ?? 0);
            $weeklyGross[$weekKey] += $rate;
            $overallGross += $rate;
        }
    }
    ksort($groupedLoads);
    $selectedDriverName = 'All drivers';
    if ($filterDriver > 0) {
        foreach ($drivers as $driverItem) {
            if ((int)$driverItem['id'] === $filterDriver) {
                $selectedDriverName = $driverItem['driver_name'];
                break;
            }
        }
    }
    ?>
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Print Loads</title>
    <style>
      :root{--navy:#102b4e;--navy-deep:#0a1d35;--blue:#1c4775;--ink:#172b43;--muted:#64748b;--line:#dbe3ed;--paper:#fff;--wash:#f3f6fa}
      *{box-sizing:border-box}
      body{font:14px/1.45 Arial,Helvetica,sans-serif;color:var(--ink);background:#eef2f7;margin:0;padding:30px}
      .report-shell{max-width:1440px;margin:0 auto;background:var(--paper);padding:28px;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 12px 35px rgba(15,35,60,.09)}
      .report-header{display:flex;align-items:center;gap:22px;background:linear-gradient(120deg,var(--navy-deep),var(--navy) 68%,#1d4773);color:#fff;padding:24px 28px;border-radius:11px}
      .logo-wrap{flex:0 0 112px;width:112px;height:112px;display:flex;align-items:center;justify-content:center;background:#fff;border-radius:9px;padding:7px}
      .company-logo{display:block;width:100%;height:100%;object-fit:contain}
      .brand-copy{min-width:0}.company-name{font-size:13px;letter-spacing:2.5px;text-transform:uppercase;color:#b8cce2;font-weight:700;margin:0 0 5px}
      h1{font-size:29px;line-height:1.15;letter-spacing:.2px;margin:0 0 7px;color:#fff}.header-subtitle{margin:0;color:#d7e3f1;font-size:13px}
      .toolbar{display:flex;gap:10px;margin:18px 0}
      button{padding:10px 17px;border:0;border-radius:7px;background:var(--navy);color:#fff;font-weight:700;cursor:pointer;transition:background .15s}
      button:hover{background:#1e4d7e}.button-save{background:#167044}.button-save:hover{background:#105a36}.button-light{background:#e8eef5;color:var(--navy)}.button-light:hover{background:#d8e2ee}
      .report-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:20px 0 12px}
      .meta-card{padding:13px 16px;background:var(--wash);border:1px solid #e4eaf1;border-radius:8px}
      .meta-label{display:block;color:var(--muted);font-size:10px;letter-spacing:1.2px;text-transform:uppercase;font-weight:700;margin-bottom:4px}
      .meta-value{font-size:15px;font-weight:700;color:var(--navy)}
      .report-note{color:var(--muted);font-size:12px;margin:12px 2px 20px}
      .week{margin:25px 0 28px;break-inside:avoid;page-break-inside:avoid}
      .week h2{font-size:16px;color:var(--navy);margin:0;padding:0 0 9px;border-bottom:2px solid var(--navy);display:flex;align-items:center;gap:8px}
      .week h2:before{content:"";display:inline-block;width:4px;height:18px;border-radius:4px;background:#3c83bd}
      .table-wrap{width:100%;overflow-x:auto}
      table{width:100%;border-collapse:separate;border-spacing:0;margin-top:11px;font-size:12px}
      th,td{padding:10px 9px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
      th{background:var(--navy);color:#fff;font-size:10px;letter-spacing:.55px;text-transform:uppercase;font-weight:700;white-space:nowrap}
      th:first-child{border-top-left-radius:6px}th:last-child{border-top-right-radius:6px;border-right:0}
      td:first-child{border-left:1px solid var(--line)}td:last-child{border-right:1px solid var(--line)}
      tbody tr:nth-child(even){background:#f6f8fb}tbody tr:hover{background:#edf3f9}
      .money{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
      .week-total{font-weight:700;color:var(--navy);text-align:right;margin-top:10px;padding:11px 14px;background:#edf3f9;border-right:4px solid #315f8c;border-radius:5px}
      .grand-total{font-size:18px;font-weight:700;color:#fff;background:linear-gradient(110deg,var(--navy-deep),var(--navy));padding:17px 20px;text-align:right;border-radius:8px;margin-top:28px;font-variant-numeric:tabular-nums}
      .cancelled{color:#8b929d}.cancelled td{text-decoration-color:#aab3bf}
      .payment-badge{display:inline-block;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
      .payment-paid{background:#dcfce7;color:#166534}
      .payment-not-paid{background:#fef08a;color:#422006}
      .empty-state{padding:24px;text-align:center;background:var(--wash);border:1px dashed #cbd5e1;border-radius:8px;color:var(--muted)}
      .report-footer{margin-top:26px;padding-top:12px;border-top:1px solid var(--line);font-size:10px;color:#8290a3;text-align:center}
      @media(max-width:760px){body{padding:10px}.report-shell{padding:14px;border-radius:8px}.report-header{padding:17px;gap:14px}.logo-wrap{flex-basis:76px;width:76px;height:76px}h1{font-size:22px}.report-meta{grid-template-columns:1fr}.table-wrap{overflow-x:auto}table{min-width:1120px}}
      /* Print layout is tuned for A4 landscape so all load columns fit on the page. */
      @page{size:A4 landscape;margin:8mm}
      @media print{
        html,body{width:100%;min-width:0;background:#fff;padding:0;margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        body{font-size:9px;line-height:1.25}
        .report-shell{width:100%;max-width:none;margin:0;padding:0;border:0;border-radius:0;box-shadow:none;overflow:visible}
        .toolbar{display:none}
        .report-header{padding:10px 14px;gap:12px;border-radius:0;break-inside:avoid;page-break-inside:avoid}
        .logo-wrap{flex:0 0 58px;width:58px;height:58px;padding:4px}
        .company-name{font-size:9px;letter-spacing:1.4px;margin-bottom:2px}
        h1{font-size:19px;margin-bottom:3px}
        .header-subtitle{font-size:9px}
        .report-meta{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin:9px 0 6px}
        .meta-card{padding:6px 9px;border-radius:4px}
        .meta-label{font-size:7px;margin-bottom:2px}
        .meta-value{font-size:9px;overflow-wrap:anywhere}
        .report-note{font-size:8px;margin:6px 1px 10px}
        .week{margin:12px 0 14px;break-inside:auto;page-break-inside:auto}
        .week h2{font-size:10px;padding-bottom:5px;break-after:avoid-page;page-break-after:avoid}
        .week h2:before{height:12px;width:3px}
        .table-wrap{width:100%;overflow:visible}
        table{width:100%;min-width:0;max-width:100%;table-layout:fixed;border-collapse:collapse;border-spacing:0;margin-top:6px;font-size:7.5px;page-break-inside:auto}
        thead{display:table-header-group}
        tfoot{display:table-footer-group}
        tr{break-inside:avoid;page-break-inside:avoid}
        th,td{padding:4px 3px;font-size:7.5px;line-height:1.2;white-space:normal;overflow-wrap:anywhere;word-break:normal}
        th{font-size:6.8px;letter-spacing:.15px}
        th:first-child,th:last-child{border-radius:0}
        .money{text-align:right;white-space:normal}
        .payment-badge{font-size:7px;padding:2px 4px;white-space:normal}
        .week-total{font-size:9px;padding:6px 8px;margin-top:6px;break-inside:avoid;page-break-inside:avoid}
        .grand-total{font-size:12px;padding:9px 12px;margin-top:14px;break-inside:avoid;page-break-inside:avoid}
        .report-footer{font-size:7px;margin-top:10px;padding-top:6px}
      }
    </style></head><body>
    <main class="report-shell">
      <div class="toolbar"><button onclick="window.print()">Print loads</button><button class="button-light" onclick="window.close()">Close</button></div>
      <header class="report-header">
        <div class="logo-wrap"><img class="company-logo" src="assets/alphanak_logo.jpeg" alt="ALPHANAK Logistics LLC logo"></div>
        <div class="brand-copy"><p class="company-name">ALPHANAK Logistics LLC</p><h1>Loads Report</h1><p class="header-subtitle">Weekly load activity and gross summary</p></div>
      </header>
      <section class="report-meta" aria-label="Report details">
        <div class="meta-card"><span class="meta-label">Driver</span><span class="meta-value"><?= e($selectedDriverName) ?></span></div>
        <div class="meta-card"><span class="meta-label">Report date range</span><span class="meta-value"><?= e(date('M j, Y', strtotime($filterStart))) ?> – <?= e(date('M j, Y', strtotime($filterEnd))) ?></span></div>
      </section>
      <p class="report-note">Payroll periods run Wednesday through the following Tuesday. Gross excludes cancelled loads and is calculated from each load's rate.</p>
    <?php if (!$groupedLoads): ?><div class="empty-state">No loads found for the selected date range.</div><?php endif; ?>
    <?php foreach ($groupedLoads as $week): $weekKey = $week['start']->format('Y-m-d'); ?>
      <section class="week">
        <h2>Payroll week: <?= e($week['start']->format('M j, Y')) ?> – <?= e($week['end']->format('M j, Y')) ?></h2>
        <div class="table-wrap"><table><thead><tr><th>Load #</th><th>Driver</th><th>Broker</th><th>Pickup</th><th>Pickup date</th><th>Delivery</th><th>Delivery date</th><th>Payment</th><th class="money">Load gross</th><th class="money">Rate per Mile</th></tr></thead><tbody>
        <?php foreach ($week['loads'] as $item): ?>
          <tr class="<?= ($item['status'] ?? '') === 'Cancelled' ? 'cancelled' : '' ?>">
            <td><?= e($item['load_no'] ?? '') ?></td><td><?= e($item['driver_name'] ?? 'Not assigned') ?></td>
            <td><?= e($item['broker_name'] ?? 'Not assigned') ?></td>
            <td><?= e($item['pickup_location'] ?? '') ?></td>
            <td><?= !empty($item['pickup_date']) ? e(date('M j, Y', strtotime($item['pickup_date']))) : '—' ?></td>
            <td><?= e($item['delivery_location'] ?? '') ?></td>
            <td><?= !empty($item['delivery_date']) ? e(date('M j, Y', strtotime($item['delivery_date']))) : '—' ?></td>
            <td><?php if (($item['status'] ?? '') === 'Paid'): ?><span class="payment-badge payment-paid">Paid</span><?php else: ?><span class="payment-badge payment-not-paid">Not Paid</span><?php endif; ?></td>
            <td class="money"><?= money(($item['status'] ?? '') === 'Cancelled' ? 0 : ($item['rate'] ?? 0)) ?></td>
            <td class="money"><?= (float)($item['miles'] ?? 0) > 0 ? '$' . number_format((float)$item['rate'] / (float)$item['miles'], 2) . '/mi' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="week-total">Weekly gross: <?= money($weeklyGross[$weekKey]) ?></div>
      </section>
    <?php endforeach; ?>
      <div class="grand-total">Total gross for selected date range: <?= money($overallGross) ?></div>
      <footer class="report-footer">ALPHANAK Logistics LLC · Loads Report</footer>
    </main>
    </body></html>
    <?php
    exit;
}

$pageTitle = $title;
$active = $table;

require 'partials_header.php';
?>

<div class="page-head">

    <div>
        <h1><?= e($title) ?></h1>
        <p>Manage loads and filter by any driver and any date range. Payroll week is available through Current Week.</p>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="button" class="btn primary" id="saveLoadsPdf" data-report-url="loads.php?action=print&amp;driver_id=<?= $filterDriver ?>&amp;period_start=<?= e($filterStart) ?>&amp;period_end=<?= e($filterEnd) ?>" title="Download the Loads report directly as a PDF">
            Save as PDF
        </button>
        <a class="btn secondary" target="_blank" href="loads.php?action=print&amp;driver_id=<?= $filterDriver ?>&amp;period_start=<?= e($filterStart) ?>&amp;period_end=<?= e($filterEnd) ?>">
            Print Loads
        </a>
        <a class="btn primary" href="loads.php?action=new">
            ＋ Add Load
        </a>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js" referrerpolicy="no-referrer"></script>
<script>
(function () {
    const button = document.getElementById('saveLoadsPdf');
    if (!button) return;

    button.addEventListener('click', async function () {
        const originalText = button.textContent.trim();
        button.disabled = true;
        button.textContent = 'Preparing PDF…';

        let frame;
        try {
            if (typeof html2pdf === 'undefined') {
                throw new Error('The PDF generator could not be loaded. Check your internet connection and try again.');
            }

            const reportUrl = button.dataset.reportUrl.replaceAll('&amp;', '&');
            const response = await fetch(reportUrl, { credentials: 'same-origin' });
            if (!response.ok) throw new Error('Could not load the Loads report.');
            const reportHtml = await response.text();

            frame = document.createElement('iframe');
            frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1123px;height:794px;border:0;visibility:hidden;';
            document.body.appendChild(frame);

            const doc = frame.contentDocument;

            // Resolve report assets (especially the company logo) from the real Loads page.
            const base = doc.createElement('base');
            base.href = new URL('.', window.location.href).href;
            doc.head.appendChild(base);

            doc.open();
            doc.write(reportHtml);
            doc.close();

            // Apply the complete A4 print stylesheet to the PDF capture.
            // Use balanced-brace scanning so nested CSS blocks are not truncated.
            const reportStyle = Array.from(doc.querySelectorAll('style')).find(style => style.textContent.includes('@media print'));
            if (reportStyle) {
                const css = reportStyle.textContent;
                const mediaStart = css.indexOf('@media print');
                const openBrace = css.indexOf('{', mediaStart);
                if (openBrace !== -1) {
                    let depth = 1;
                    let cursor = openBrace + 1;
                    while (cursor < css.length && depth > 0) {
                        if (css[cursor] === '{') depth++;
                        else if (css[cursor] === '}') depth--;
                        cursor++;
                    }
                    if (depth === 0) {
                        const printStyle = doc.createElement('style');
                        printStyle.textContent = css.slice(openBrace + 1, cursor - 1);
                        doc.head.appendChild(printStyle);
                    }
                }
            }

            // Keep the capture viewport at A4 landscape proportions and wait
            // for the logo and other report images before generating the PDF.
            doc.documentElement.style.width = '1123px';
            doc.body.style.width = '1123px';

            await new Promise((resolve, reject) => {
                const start = Date.now();
                const check = () => {
                    const images = Array.from(doc.images);
                    const ready = doc.readyState === 'complete' && images.every(img => img.complete);
                    if (ready) return resolve();
                    if (Date.now() - start > 10000) return reject(new Error('The report is taking too long to load.'));
                    setTimeout(check, 100);
                };
                check();
            });

            const report = doc.querySelector('.report-shell');
            if (!report) throw new Error('The report content was not found.');

            const driver = <?= json_encode(preg_replace('/[^A-Za-z0-9_-]+/', '_', $selectedDriverName ?? 'All_drivers')) ?>;
            const dateFrom = <?= json_encode($filterStart) ?>;
            const dateTo = <?= json_encode($filterEnd) ?>;
            const filename = 'Loads_' + driver + '_' + dateFrom + '_to_' + dateTo + '.pdf';

            await html2pdf().set({
                margin: [8, 8, 8, 8],
                filename: filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff', windowWidth: 1123 },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
                pagebreak: { mode: ['css', 'legacy'], avoid: ['tr'] }
            }).from(report).save();
        } catch (error) {
            alert(error.message || 'Unable to create the PDF. Please try again.');
        } finally {
            if (frame) frame.remove();
            button.disabled = false;
            button.textContent = originalText;
        }
    });
})();
</script>

<?php if ($action === 'new' || $action === 'edit'): ?>

<div class="form-card">

    <h3>
        <?= $action === 'edit' ? 'Edit Load' : 'New Load' ?>
    </h3>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?= e(csrf_token()) ?>"
        >

        <div class="form-grid">

            <?php foreach ($fields as $f):

                $key = $f[0];
                $type = $f[2];
                $value = $record[$key] ?? '';

            ?>

            <div class="field <?= $type === 'textarea' ? 'full' : '' ?>">

                <label>
                    <?= e($f[1]) ?>
                    <?= $f[3] ? ' *' : '' ?>
                </label>

                <?php if ($type === 'textarea'): ?>

                    <textarea name="<?= e($key) ?>"><?= e($value) ?></textarea>

                <?php elseif (in_array($type, ['truck', 'driver', 'broker'])): ?>

                    <select name="<?= e($key) ?>">

                        <option value="">Not assigned</option>

                        <?php foreach (options_for($type) as $o): ?>

                            <option
                                value="<?= (int)$o['id'] ?>"
                                <?= $value == $o['id'] ? 'selected' : '' ?>
                            >
                                <?= e($o['label']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                <?php elseif (str_starts_with($type, 'select:')): ?>

                    <select name="<?= e($key) ?>">

                        <?php foreach (explode(',', substr($type, 7)) as $opt): ?>

                            <option
                                value="<?= e($opt) ?>"
                                <?= $value === $opt ? 'selected' : '' ?>
                            >
                                <?= e($opt) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                <?php elseif ($key === 'miles'): ?>

                    <input
                        type="number"
                        name="miles"
                        id="loadMiles"
                        value="<?= e($value) ?>"
                        step="0.1"
                        min="0"
                        placeholder="Calculated from locations"
                    >
                    <small class="field-hint">Automatically calculated from the pickup and delivery driving route. You can still adjust it manually.</small>

                <?php else: ?>

                    <input
                        type="<?= e($type) ?>"
                        name="<?= e($key) ?>"
                        value="<?= e($value) ?>"
                        <?= $f[3] ? 'required' : '' ?>
                        <?= $type === 'number' ? 'step="0.01" min="0"' : '' ?>
                        <?= $key === 'pickup_location' ? 'id="pickupLocation"' : '' ?>
                        <?= $key === 'delivery_location' ? 'id="deliveryLocation"' : '' ?>
                    >

                <?php endif; ?>

            </div>

            <?php endforeach; ?>

        </div>

        <div id="routeDistanceStatus" class="field-hint" role="status" aria-live="polite" style="margin:12px 0"></div>
        <div class="form-actions">
            <button type="button" class="btn secondary" id="calculateRouteMiles">Calculate miles</button>

            <button type="submit" class="btn primary">
                Save Load
            </button>

            <a href="loads.php" class="btn secondary">
                Cancel
            </a>

        </div>

    </form>

</div>

<?php if ($action === 'edit' && $id): ?>
<div class="form-card" style="margin-top:18px">
    <h3>Load Documents</h3>
    <p class="muted">Upload the paperwork for this load. You can add multiple files in each category. Accepted formats: PDF, JPG, PNG, DOC and DOCX (maximum 15MB per file).</p>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="upload_documents">
        <div class="form-grid">
            <?php foreach (['Rate Confirmation' => 'Rate Confirmation', 'BOL' => 'Bill of Lading (BOL)', 'Lumper Receipt' => 'Lumper Receipts'] as $docKey => $docLabel): ?>
                <div class="field">
                    <label><?= e($docLabel) ?></label>
                    <input type="file" name="documents[<?= e($docKey) ?>][]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn primary">Upload Documents</button>
        </div>
    </form>
    <h3 style="margin-top:22px">Uploaded Files</h3>
    <?php if ($loadDocuments): ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Document type</th><th>File name</th><th>Uploaded</th><th>Open</th></tr></thead>
                <tbody>
                <?php foreach ($loadDocuments as $doc): ?>
                    <tr>
                        <td><?= e($doc['document_type']) ?></td>
                        <td><?= e($doc['original_name'] ?: basename($doc['file_path'])) ?></td>
                        <td><?= e(date('M j, Y g:i A', strtotime($doc['uploaded_at']))) ?></td>
                        <td><a class="btn secondary small" href="view_load_document.php?id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener">View / Download</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="muted">No documents have been uploaded for this load yet.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
    const pickup = document.getElementById('pickupLocation');
    const delivery = document.getElementById('deliveryLocation');
    const miles = document.getElementById('loadMiles');
    const button = document.getElementById('calculateRouteMiles');
    const status = document.getElementById('routeDistanceStatus');
    if (!pickup || !delivery || !miles || !button || !status) return;

    let requestInProgress = false;
    let lastCalculatedPair = '';

    async function calculateMiles(force = false) {
        const from = pickup.value.trim();
        const to = delivery.value.trim();
        if (!from || !to) {
            status.textContent = 'Enter both pickup and delivery locations to calculate driving miles.';
            return;
        }
        const pair = from + '|' + to;
        if (requestInProgress || (!force && pair === lastCalculatedPair)) return;

        requestInProgress = true;
        button.disabled = true;
        button.textContent = 'Calculating…';
        status.textContent = 'Finding locations and calculating the driving route…';
        status.style.color = '';

        try {
            const response = await fetch('route_distance.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': <?= json_encode(csrf_token()) ?>
                },
                body: JSON.stringify({ pickup: from, delivery: to })
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Could not calculate the route.');
            miles.value = Number(result.miles).toFixed(1);
            miles.dispatchEvent(new Event('input', { bubbles: true }));
            miles.dispatchEvent(new Event('change', { bubbles: true }));
            lastCalculatedPair = pair;
            status.textContent = 'Driving distance calculated: ' + Number(result.miles).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' miles. Check the locations and adjust the miles if needed.';
            status.style.color = '#16803c';
        } catch (error) {
            status.textContent = error.message + ' Include city, state, or ZIP/postal code for more accurate matching.';
            status.style.color = '#b42318';
        } finally {
            requestInProgress = false;
            button.disabled = false;
            button.textContent = 'Calculate miles';
        }
    }

    button.addEventListener('click', () => calculateMiles(true));
    delivery.addEventListener('blur', () => calculateMiles(false));
    pickup.addEventListener('blur', () => calculateMiles(false));
    pickup.addEventListener('change', () => { lastCalculatedPair = ''; if (delivery.value.trim()) calculateMiles(true); });
    delivery.addEventListener('change', () => { lastCalculatedPair = ''; if (pickup.value.trim()) calculateMiles(true); });
})();
</script>

<?php else: ?>

<div class="form-card" style="margin-bottom:18px">
    <h3>Filter loads by driver and pay period</h3>
    <p class="muted">Choose a driver and any date range to view loads for the dates you select. Use Current Week to return to the standard payroll period (Wednesday through the following Tuesday).</p>
    <?php if ($filterError): ?>
        <div class="alert error"><?= e($filterError) ?></div>
    <?php endif; ?>
    <form method="get">
        <div class="form-grid">
            <div class="field">
                <label>Driver</label>
                <select name="driver_id">
                    <option value="0">All drivers</option>
                    <?php foreach ($drivers as $driverOption): ?>
                        <option value="<?= (int)$driverOption['id'] ?>" <?= $filterDriver === (int)$driverOption['id'] ? 'selected' : '' ?>>
                            <?= e($driverOption['driver_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Start date</label>
                <input type="date" name="period_start" value="<?= e($filterStart) ?>" required>
            </div>
            <div class="field">
                <label>End date</label>
                <input type="date" name="period_end" value="<?= e($filterEnd) ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn primary">Apply Filter</button>
            <a href="loads.php" class="btn secondary">Current Week</a>
        </div>
    </form>
</div>

<p class="muted">Showing loads dated <?= e(date('M j, Y', strtotime($filterStart))) ?> through <?= e(date('M j, Y', strtotime($filterEnd))) ?>. Loads are matched using delivery date, or pickup date when delivery date is not set.</p>

<div class="stats-grid" style="margin-bottom:18px">
    <div class="stat-card"><span>Loads in period</span><strong><?= (int)$filterTotals['count'] ?></strong></div>
    <div class="stat-card"><span>Eligible load revenue (excluding cancelled)</span><strong><?= money($filterTotals['rate']) ?></strong></div>
    <div class="stat-card"><span>Eligible miles (excluding cancelled)</span><strong><?= number_format($filterTotals['miles'], 1) ?></strong></div>
</div>

<div class="toolbar">
    <input
        data-search="#records"
        placeholder="Search loads in this period..."
    >
</div>

<div class="table-wrap">

    <table class="table" id="records">

        <thead>

            <tr>

                <?php foreach ($cols as $c): ?>

                    <th><?= e($labels[$c] ?? $c) ?></th>

                <?php endforeach; ?>

                <th>Actions</th>

            </tr>

        </thead>

        <tbody>

            <?php foreach ($rows as $r): ?>

                <tr>

                    <?php foreach ($cols as $c): ?>

                        <td>

                            <?php if ($c === 'truck_id'): ?>

                                <?= e($r['truck_unit'] ?? 'Not assigned') ?>

                            <?php elseif ($c === 'driver_id'): ?>

                                <?= e($r['driver_name'] ?? 'Not assigned') ?>

                            <?php elseif ($c === 'broker_id'): ?>

                                <?= e($r['broker_name'] ?? 'Not assigned') ?>

                            <?php elseif ($c === 'rate'): ?>

                                <?= money($r['rate'] ?? 0) ?>

                            <?php elseif ($c === 'rate_per_mile'): ?>

                                <?= (float)($r['miles'] ?? 0) > 0
                                    ? '$' . number_format((float)$r['rate'] / (float)$r['miles'], 2) . '/mi'
                                    : '—' ?>

                            <?php else: ?>

                                <?= e($r[$c] ?? '—') ?>

                            <?php endif; ?>

                        </td>

                    <?php endforeach; ?>

                    <td>

                        <a
                            class="btn secondary small"
                            href="loads.php?action=edit&id=<?= (int)$r['id'] ?>"
                        >
                            Edit
                        </a>

                        <?php if (current_user()['role'] === 'admin'): ?>

                            <a
                                class="btn danger small"
                                onclick="return confirm('Are you sure you want to delete this load?')"
                                href="loads.php?delete=<?= (int)$r['id'] ?>"
                            >
                                Delete
                            </a>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            <?php if (!$rows): ?>

                <tr>
                    <td colspan="<?= count($cols) + 1 ?>" class="empty">
                        No loads found.
                    </td>
                </tr>

            <?php endif; ?>

        </tbody>

    </table>

</div>

<?php endif; ?>

<?php require 'partials_footer.php'; ?>
