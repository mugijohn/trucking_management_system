
<?php
require 'config.php';
require_login();
verify_csrf();

$title = "Brokers";
$table = "brokers";

$fields = [
    ["company_name", "Company name", "text", 1],
    ["contact_name", "Contact person", "text", 0],
    ["email", "Email", "email", 0],
    ["phone", "Phone", "text", 0],
    ["payment_terms", "Payment terms (days)", "number", 0],
    ["address", "Address", "textarea", 0]
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

// Delete broker
if (isset($_GET['delete']) && current_user()['role'] === 'admin') {

    $deleteId = (int)$_GET['delete'];

    $s = db()->prepare("DELETE FROM `$table` WHERE id = ?");
    $s->execute([$deleteId]);

    audit('delete', $table, $deleteId);

    flash('success', 'Broker record deleted successfully.');

    redirect($table . '.php');
}

// Save broker
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

        // Update existing broker
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

            // Create new broker
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

        flash('success', 'Broker saved successfully.');

        redirect($table . '.php');

    } catch (Throwable $e) {

        $error = $e->getMessage();
    }
}

// Load broker for editing
$record = [];

if ($action === 'edit' && $id) {

    $s = db()->prepare("SELECT * FROM `$table` WHERE id = ?");
    $s->execute([$id]);

    $record = $s->fetch() ?: [];
}

// Load brokers
$rows = db()->query("
    SELECT *
    FROM brokers
    ORDER BY id DESC
    LIMIT 500
")->fetchAll();

$pageTitle = $title;
$active = $table;

require 'partials_header.php';
?>

<div class="page-head">

    <div>
        <h1><?= e($title) ?></h1>
        <p>Manage your broker records.</p>
    </div>

    <a class="btn primary" href="brokers.php?action=new">
        ＋ Add Broker
    </a>

</div>

<?php if ($action === 'new' || $action === 'edit'): ?>

<div class="form-card">

    <h3>
        <?= $action === 'edit' ? 'Edit Broker' : 'New Broker' ?>
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

                <?php else: ?>

                    <input
                        type="<?= e($type) ?>"
                        name="<?= e($key) ?>"
                        value="<?= e($value) ?>"
                        <?= $f[3] ? 'required' : '' ?>
                        <?= $type === 'number' ? 'step="1" min="0"' : '' ?>
                    >

                <?php endif; ?>

            </div>

            <?php endforeach; ?>

        </div>

        <div class="form-actions">

            <button type="submit" class="btn primary">
                Save Broker
            </button>

            <a href="brokers.php" class="btn secondary">
                Cancel
            </a>

        </div>

    </form>

</div>

<?php else: ?>

<div class="toolbar">

    <input
        data-search="#records"
        placeholder="Search brokers..."
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

                            <?php if ($c === 'payment_terms'): ?>

                                <?= e($r[$c] ?? '—') ?>

                            <?php else: ?>

                                <?= e($r[$c] ?? '—') ?>

                            <?php endif; ?>

                        </td>

                    <?php endforeach; ?>

                    <td>

                        <a
                            class="btn secondary small"
                            href="brokers.php?action=edit&id=<?= (int)$r['id'] ?>"
                        >
                            Edit
                        </a>

                        <?php if (current_user()['role'] === 'admin'): ?>

                            <a
                                class="btn danger small"
                                onclick="return confirm('Are you sure you want to delete this broker?')"
                                href="brokers.php?delete=<?= (int)$r['id'] ?>"
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
                        No brokers found.
                    </td>
                </tr>

            <?php endif; ?>

        </tbody>

    </table>

</div>

<?php endif; ?>

<?php require 'partials_footer.php'; ?>
