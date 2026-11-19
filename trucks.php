
<?php
require 'config.php';
require_login();
verify_csrf();

$title = "Trucks";
$table = "trucks";

$fields = [
    ["unit_no", "Unit number", "text", 1],
    ["plate_no", "Plate number", "text", 1],
    ["make", "Make", "text", 0],
    ["model", "Model", "text", 0],
    ["year", "Year", "number", 0],
    ["trailer_no", "Trailer number", "text", 0],
    ["current_mileage", "Current mileage", "number", 0],
    ["status", "Status", "select:Available,On Load,Maintenance,Inactive", 0],
    ["notes", "Notes", "textarea", 0]
];

// Columns displayed in the trucks table
$cols = [
    'unit_no',
    'plate_no',
    'make',
    'model',
    'year',
    'trailer_no',
    'current_mileage',
    'status'
];

// Column headings
$labels = [
    'unit_no' => 'Unit Number',
    'plate_no' => 'Plate Number',
    'make' => 'Make',
    'model' => 'Model',
    'year' => 'Year',
    'trailer_no' => 'Trailer Number',
    'current_mileage' => 'Current Mileage',
    'status' => 'Status'
];

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$error = '';

if (isset($_GET['delete']) && current_user()['role'] === 'admin') {
    $deleteId = (int)$_GET['delete'];

    $s = db()->prepare("DELETE FROM `$table` WHERE id = ?");
    $s->execute([$deleteId]);

    audit('delete', $table, $deleteId);
    flash('success', 'Truck record deleted.');
    redirect($table . '.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [];

        foreach ($fields as $f) {
            $key = $f[0];
            $type = $f[2];

            if (
                in_array($type, ['textarea', 'text', 'email', 'date', 'number'])
                || str_starts_with($type, 'select:')
            ) {
                $value = trim((string)($_POST[$key] ?? ''));

                $data[$key] = $value === '' ? null : $value;
            }

            if ($f[3] && empty($data[$key])) {
                throw new RuntimeException($f[1] . ' is required.');
            }
        }

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

        flash('success', 'Truck saved successfully.');
        redirect($table . '.php');

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$record = [];

if ($action === 'edit' && $id) {
    $s = db()->prepare("SELECT * FROM `$table` WHERE id = ?");
    $s->execute([$id]);
    $record = $s->fetch() ?: [];
}

$rows = db()->query(
    "SELECT * FROM `$table` ORDER BY id DESC LIMIT 500"
)->fetchAll();

$pageTitle = $title;
$active = $table;

require 'partials_header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($title) ?></h1>
        <p>Manage your trucks and fleet records.</p>
    </div>

    <a class="btn primary" href="trucks.php?action=new">
        ＋ Add Truck
    </a>
</div>

<?php if ($action === 'new' || $action === 'edit'): ?>

<div class="form-card">

    <h3><?= $action === 'edit' ? 'Edit Truck' : 'New Truck' ?></h3>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">

        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

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

                <?php elseif (str_starts_with($type, 'select:')): ?>

                    <select name="<?= e($key) ?>">
                        <?php foreach (explode(',', substr($type, 7)) as $opt): ?>
                            <option value="<?= e($opt) ?>"
                                <?= $value === $opt ? 'selected' : '' ?>>
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
                        <?= $type === 'number' ? 'min="0" step="1"' : '' ?>
                    >

                <?php endif; ?>

            </div>

            <?php endforeach; ?>

        </div>

        <div class="form-actions">

            <button class="btn primary">Save Truck</button>

            <a class="btn secondary" href="trucks.php">
                Cancel
            </a>

        </div>

    </form>

</div>

<?php else: ?>

<div class="toolbar">
    <input data-search="#records" placeholder="Search trucks...">
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

                    <td><?= e($r[$c] ?? '—') ?></td>

                <?php endforeach; ?>

                <td>

                    <a class="btn secondary small"
                       href="trucks.php?action=edit&id=<?= (int)$r['id'] ?>">
                        Edit
                    </a>

                    <?php if (current_user()['role'] === 'admin'): ?>

                        <a class="btn danger small"
                           onclick="return confirm('Delete this truck?')"
                           href="trucks.php?delete=<?= (int)$r['id'] ?>">
                            Delete
                        </a>

                    <?php endif; ?>

                </td>

            </tr>

            <?php endforeach; ?>

            <?php if (!$rows): ?>

                <tr>
                    <td colspan="<?= count($cols) + 1 ?>" class="empty">
                        No trucks found.
                    </td>
                </tr>

            <?php endif; ?>

        </tbody>

    </table>

</div>

<?php endif; ?>

<?php require 'partials_footer.php'; ?>

