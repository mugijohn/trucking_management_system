
<?php
require 'config.php';
require_login();
verify_csrf();

$title = "Drivers";
$table = "drivers";

// Driver-specific fixed insurance deduction (weekly amount).
$insuranceColumn = db()->query("SHOW COLUMNS FROM drivers LIKE 'insurance_amount'")->fetch();
if (!$insuranceColumn) {
    db()->exec("ALTER TABLE drivers ADD COLUMN insurance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00");
}


$fields = [
    ["first_name", "First name", "text", 1],
    ["last_name", "Last name", "text", 1],
    ["email", "Email", "email", 0],
    ["phone", "Phone", "text", 0],
    ["truck_id", "Assigned truck", "truck", 0],
    ["pay_method", "Pay method", "select:Percentage,Per Mile,Fixed Per Load,Weekly Salary", 0],
    ["pay_rate", "Pay rate / %", "number", 0],
    ["insurance_amount", "Weekly insurance amount (USD)", "number", 0],
    ["status", "Status", "select:Available,On Duty,Off Duty,Inactive", 0]
];

// Automatically generate table columns and labels
$cols = array_column($fields, 0);
$labels = [];

foreach ($fields as $f) {
    $labels[$f[0]] = $f[1];
}

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
            "SELECT id, CONCAT(first_name,' ',last_name) AS label FROM drivers ORDER BY first_name"
        )->fetchAll();
    }

    if ($type === 'broker') {
        return db()->query(
            "SELECT id, company_name AS label FROM brokers ORDER BY company_name"
        )->fetchAll();
    }

    return [];
}

// Handle deletion
if (isset($_GET['delete']) && current_user()['role'] === 'admin') {
    $deleteId = (int)$_GET['delete'];

    $s = db()->prepare("DELETE FROM `$table` WHERE id = ?");
    $s->execute([$deleteId]);

    audit('delete', $table, $deleteId);
    flash('success', 'Driver record deleted successfully.');

    redirect($table . '.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [];

        foreach ($fields as $f) {
            $key = $f[0];
            $type = $f[2];

            if (
                $type === 'textarea' ||
                $type === 'text' ||
                $type === 'email' ||
                $type === 'date' ||
                $type === 'number' ||
                str_starts_with($type, 'select:') ||
                in_array($type, ['truck', 'driver', 'broker'])
            ) {
                $data[$key] = ($_POST[$key] ?? '') === ''
                    ? null
                    : trim((string)$_POST[$key]);
            }

            if ($key === 'pay_rate' && $data[$key] === null) {
                $data[$key] = 0;
            }

            if ($f[3] && empty($data[$key])) {
                throw new RuntimeException($f[1] . ' is required.');
            }
        }

        // Save changes
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

        flash('success', 'Driver saved successfully.');
        redirect($table . '.php');

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

// Load all drivers with assigned truck details
$rows = db()->query("
    SELECT d.*, t.unit_no AS truck_unit
    FROM drivers d
    LEFT JOIN trucks t ON d.truck_id = t.id
    ORDER BY d.id DESC
    LIMIT 500
")->fetchAll();

$pageTitle = $title;
$active = $table;

require 'partials_header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($title) ?></h1>
        <p>Manage your drivers records.</p>
    </div>

    <a class="btn primary" href="drivers.php?action=new">
        ＋ Add Driver
    </a>
</div>

<?php if ($action === 'new' || $action === 'edit'): ?>

<div class="form-card">

    <h3>
        <?= $action === 'edit' ? 'Edit Driver' : 'New Driver' ?>
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

        <p class="muted">Enter this driver’s fixed weekly insurance deduction. It will be deducted automatically from each weekly paystub.</p>       <div class="form-grid">

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

                <?php else: ?>

                    <input
                        type="<?= e($type) ?>"
                        name="<?= e($key) ?>"
                        value="<?= e($value) ?>"
                        <?= $f[3] ? 'required' : '' ?>
                        <?= $type === 'number' ? 'step="0.01" min="0"' : '' ?>
                    >

                <?php endif; ?>

            </div>

            <?php endforeach; ?>

        </div>

        <div class="form-actions">

            <button type="submit" class="btn primary">
                Save Driver
            </button>

            <a href="drivers.php" class="btn secondary">
                Cancel
            </a>

        </div>

    </form>

</div>

<?php else: ?>

<div class="toolbar">
    <input
        data-search="#records"
        placeholder="Search drivers..."
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

                            <?php elseif ($c === 'pay_rate'): ?>

                                <?= e($r[$c] ?? '0') ?>

                            <?php else: ?>

                                <?= e($r[$c] ?? '—') ?>

                            <?php endif; ?>

                        </td>

                    <?php endforeach; ?>

                    <td>

                        <a
                            class="btn secondary small"
                            href="drivers.php?action=edit&id=<?= (int)$r['id'] ?>"
                        >
                            Edit
                        </a>

                        <?php if (current_user()['role'] === 'admin'): ?>

                            <a
                                class="btn danger small"
                                onclick="return confirm('Are you sure you want to delete this driver?')"
                                href="drivers.php?delete=<?= (int)$r['id'] ?>"
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
                        No drivers found.
                    </td>
                </tr>

            <?php endif; ?>

        </tbody>

    </table>

</div>

<?php endif; ?>

<?php require 'partials_footer.php'; ?>
