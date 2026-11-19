<?php
/**
 * Administrator library settings.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'admin');
$settings = get_settings($pdo);
$values = [
    'library_name' => (string) $settings['library_name'],
    'loan_period_days' => (string) $settings['loan_period_days'],
    'fine_per_day' => (string) $settings['fine_per_day'],
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $input = [
        'library_name' => (string) ($_POST['library_name'] ?? ''),
        'loan_period_days' => (string) ($_POST['loan_period_days'] ?? ''),
        'fine_per_day' => (string) ($_POST['fine_per_day'] ?? ''),
    ];
    $validation = reporting_validate_settings($input);
    $errors = $validation['errors'];
    $values = array_merge($values, $validation['values']);

    if ($errors === []) {
        if (!reporting_update_settings($pdo, $validation, (int) $user['id'])) {
            $errors['form'] = 'Settings could not be saved.';
        } else {
            $settings = get_settings($pdo);
            $values = [
                'library_name' => (string) $settings['library_name'],
                'loan_period_days' => (string) $settings['loan_period_days'],
                'fine_per_day' => (string) $settings['fine_per_day'],
            ];
            flash('success', 'Library settings updated.');
            redirect('admin/settings.php');
        }
    }
}

$pageTitle = 'Library settings';
$activeNav = 'settings';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Library settings</h2><p>Approved settings only. Historical fines and due dates are not rewritten.</p></div></div>

<form method="post" action="<?= e(url('admin/settings-action.php')) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field field--full"><label for="library_name">Library name</label><input id="library_name" name="library_name" maxlength="150" value="<?= e($values['library_name']) ?>" required <?= isset($errors['library_name']) ? 'aria-invalid="true"' : '' ?>><?php if (isset($errors['library_name'])) { ?><p class="field__error"><?= e($errors['library_name']) ?></p><?php } ?></div>
        <div class="field"><label for="loan_period_days">Loan period days</label><input id="loan_period_days" name="loan_period_days" type="number" min="1" max="90" value="<?= e($values['loan_period_days']) ?>" required <?= isset($errors['loan_period_days']) ? 'aria-invalid="true"' : '' ?>><?php if (isset($errors['loan_period_days'])) { ?><p class="field__error"><?= e($errors['loan_period_days']) ?></p><?php } ?></div>
        <div class="field"><label for="fine_per_day">Daily fine rate</label><input id="fine_per_day" name="fine_per_day" type="number" min="0" step="0.01" value="<?= e($values['fine_per_day']) ?>" required <?= isset($errors['fine_per_day']) ? 'aria-invalid="true"' : '' ?>><?php if (isset($errors['fine_per_day'])) { ?><p class="field__error"><?= e($errors['fine_per_day']) ?></p><?php } ?></div>
    </div>
    <div class="form-actions"><button class="btn btn--primary" type="submit">Save settings</button><a class="btn btn--ghost" href="<?= e(url('admin/reports.php')) ?>">Back</a></div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
