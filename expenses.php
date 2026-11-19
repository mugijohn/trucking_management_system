<?php
require 'config.php';
require_login();
verify_csrf();
$error = '';

// Keep user-created categories in a separate lookup table.
db()->exec("CREATE TABLE IF NOT EXISTS expense_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$defaultCategories = ['Fuel','Others/Motive','Others/C/L','Truck/K','Savings','Others','Fuel Discount Refund','Repair','Maintenance','Tolls','Permits','Other'];
$seed = db()->prepare("INSERT IGNORE INTO expense_categories (name) VALUES (?)");
foreach ($defaultCategories as $defaultCategory) { $seed->execute([$defaultCategory]); }

// Add a custom category.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_category') {
    $newCategory = trim((string)($_POST['new_category'] ?? ''));
    if ($newCategory === '' || mb_strlen($newCategory) > 100) {
        $error = 'Enter a category name of 1 to 100 characters.';
    } else {
        try {
            db()->prepare("INSERT INTO expense_categories (name) VALUES (?)")->execute([$newCategory]);
            flash('success', 'Expense category added.');
            redirect('expenses.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { $error = 'That expense category already exists.'; }
            else { throw $e; }
        }
    }
}

// Save expense_type explicitly to the expenses.category field.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'add_category') {
    try {
        $category = trim((string)($_POST['category'] ?? ''));
        if ($category === '') { throw new RuntimeException('Please select an expense type/category.'); }
        $receipt = upload_file('receipt', ['pdf', 'jpg', 'jpeg', 'png'], 'receipts');
        $stmt = db()->prepare("INSERT INTO expenses
            (truck_id, driver_id, load_id, expense_date, category, description, amount, gallons, state_code, receipt_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            ($_POST['truck_id'] ?? '') !== '' ? $_POST['truck_id'] : null,
            ($_POST['driver_id'] ?? '') !== '' ? $_POST['driver_id'] : null,
            ($_POST['load_id'] ?? '') !== '' ? $_POST['load_id'] : null,
            $_POST['expense_date'] ?? date('Y-m-d'),
            $category,
            $_POST['description'] ?? '',
            $_POST['amount'] ?? 0,
            ($_POST['gallons'] ?? '') !== '' ? $_POST['gallons'] : 0,
            $_POST['state_code'] ?? '',
            $receipt
        ]);
        audit('create', 'expenses', (int)db()->lastInsertId());
        flash('success', 'Expense saved.');
        redirect('expenses.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['delete']) && current_user()['role'] === 'admin') {
    db()->prepare("DELETE FROM expenses WHERE id=?")->execute([(int)$_GET['delete']]);
    flash('success', 'Expense deleted.');
    redirect('expenses.php');
}

$trucks = db()->query("SELECT id,unit_no FROM trucks")->fetchAll();
$drivers = db()->query("SELECT id,first_name,last_name FROM drivers")->fetchAll();
$loads = db()->query("SELECT id,load_no FROM loads")->fetchAll();
$expenseCategories = db()->query("SELECT name FROM expense_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$rows = db()->query("SELECT e.*, t.unit_no, d.first_name, d.last_name
    FROM expenses e
    LEFT JOIN trucks t ON t.id=e.truck_id
    LEFT JOIN drivers d ON d.id=e.driver_id
    ORDER BY e.expense_date DESC, e.id DESC")->fetchAll();
$total = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();
$pageTitle = 'Fuel & Expenses';
$active = 'expenses';
require 'partials_header.php';
?>
<div class="page-head"><div><h1>Fuel & Expenses</h1><p>Record operating costs, fuel gallons and receipt evidence by truck or load.</p></div><button class="btn primary" type="button" onclick="document.getElementById('categoryModal').style.display='flex'">+ Add Category</button></div>
<div class="cards"><div class="stat"><div class="stat-top">All recorded expenses</div><div class="stat-value"><?=money($total)?></div></div></div>
<div class="form-card" style="margin-bottom:20px"><h3>Add expense / fuel receipt</h3><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="form-grid">
<div class="field"><label>Date *</label><input type="date" name="expense_date" required value="<?=date('Y-m-d')?>"></div><div class="field"><label>Expense Type / Category *</label><select name="category" required><option value="">Select category</option><?php foreach($expenseCategories as $categoryName):?><option value="<?=e($categoryName)?>"><?=e($categoryName)?></option><?php endforeach;?></select></div>
<div class="field"><label>Truck</label><select name="truck_id"><option value="">Select truck</option><?php foreach($trucks as $x):?><option value="<?=$x['id']?>"><?=e($x['unit_no'])?></option><?php endforeach;?></select></div><div class="field"><label>Driver</label><select name="driver_id"><option value="">Select driver</option><?php foreach($drivers as $x):?><option value="<?=$x['id']?>"><?=e($x['first_name'].' '.$x['last_name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Related load</label><select name="load_id"><option value="">None</option><?php foreach($loads as $x):?><option value="<?=$x['id']?>"><?=e($x['load_no'])?></option><?php endforeach;?></select></div><div class="field"><label>Amount (USD) *</label><input type="number" step=".01" min="0" name="amount" required></div>
<div class="field"><label>Fuel gallons</label><input type="number" step=".01" name="gallons" value="0"></div><div class="field"><label>State code (IFTA)</label><input name="state_code" maxlength="10" placeholder="e.g. TX"></div>
<div class="field"><label>Description</label><input name="description"></div><div class="field"><label>Receipt (PDF/JPG/PNG)</label><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png"></div></div><div class="form-actions"><button class="btn primary" type="submit">Save expense</button></div></form></div>
<div id="categoryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'"><div class="form-card" style="width:100%;max-width:420px;background:#fff"><div style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0">Create Expense Category</h3><button type="button" class="btn" onclick="document.getElementById('categoryModal').style.display='none'">×</button></div><form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_category"><div class="field"><label>Category name *</label><input name="new_category" maxlength="100" placeholder="e.g. Insurance" required></div><div class="form-actions"><button class="btn primary" type="submit">+ Save Category</button><button class="btn" type="button" onclick="document.getElementById('categoryModal').style.display='none'">Cancel</button></div></form></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Expense Type / Category</th><th>Truck</th><th>Driver</th><th>Gallons</th><th>Amount</th><th>Receipt</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['expense_date'])?></td><td><?=e(trim((string)($r['category'] ?? '')) !== '' ? $r['category'] : '—')?></td><td><?=e($r['unit_no']??'—')?></td><td><?=e(trim(($r['first_name']??'').' '.($r['last_name']??''))?:'—')?></td><td><?=number_format((float)$r['gallons'],2)?></td><td><?=money($r['amount'])?></td><td><?php if($r['receipt_path']):?><a target="_blank" href="<?=e($r['receipt_path'])?>">View receipt</a><?php else:?>—<?php endif;?></td><td><?php if(current_user()['role']==='admin'):?><a href="?delete=<?=$r['id']?>" onclick="return confirm('Delete?')">Delete</a><?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="8" class="empty">No expenses recorded.</td></tr><?php endif;?></tbody></table></div>
<?php require 'partials_footer.php'; ?>
