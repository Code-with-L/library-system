<?php
/**
 * Per-user TOTP setup: enable, disable and recovery codes.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_login($pdo);
$pageTitle = 'Two-factor authentication';
$activeNav = 'profile';

if (!MFA_ENABLED) {
    require __DIR__ . '/../../includes/header.php';
    ?>
    <div class="page-intro"><div><h2>Two-factor authentication</h2><p>Multi-factor authentication is not enabled on this installation.</p></div></div>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

$row = mfa_row($pdo, (int) $user['id']);
$enabled = $row !== null && (int) $row['enabled'] === 1;
$secret = $row !== null ? (string) $row['secret'] : '';
$newCodes = [];

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['mfa_action'] ?? '');

    if ($action === 'start') {
        $secret = mfa_setup_start($pdo, (int) $user['id']);
        flash('info', 'Enter the current code from your authenticator app to finish setup.');
    } elseif ($action === 'enable') {
        $code = (string) ($_POST['code'] ?? '');
        if (mfa_enable($pdo, (int) $user['id'], $code)) {
            $newCodes = mfa_generate_recovery_codes($pdo, (int) $user['id']);
            log_activity($pdo, (int) $user['id'], 'mfa_enabled', 'user', (int) $user['id'],
                'Two-factor authentication enabled.');
            flash('success', 'Two-factor authentication is now enabled.');
        } else {
            flash('error', 'That code was not accepted. Try again with a fresh code.');
        }
    } elseif ($action === 'disable') {
        mfa_disable($pdo, (int) $user['id']);
        log_activity($pdo, (int) $user['id'], 'mfa_disabled', 'user', (int) $user['id'],
            'Two-factor authentication disabled.');
        flash('success', 'Two-factor authentication has been disabled.');
        redirect('account/mfa.php');
    } elseif ($action === 'codes') {
        $newCodes = mfa_generate_recovery_codes($pdo, (int) $user['id']);
        flash('success', 'New recovery codes generated. Store them safely.');
    }
    $row = mfa_row($pdo, (int) $user['id']);
    $enabled = $row !== null && (int) $row['enabled'] === 1;
    $secret = $row !== null ? (string) $row['secret'] : '';
}

$issuer = (string) get_settings($pdo)['library_name'];
$otpauth = $secret !== ''
    ? 'otpauth://totp/' . rawurlencode($issuer . ':' . (string) $user['email'])
      . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer)
    : '';

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro">
    <div>
        <h2>Two-factor authentication</h2>
        <p>Add a second factor to protect your account. Works with any TOTP authenticator app.</p>
    </div>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('account/profile.php')) ?>">Back to profile</a>
</div>

<div class="form-card">
<section class="card">
    <div class="card__header"><h3>Status</h3></div>
    <div class="card__body">
        <p><span class="badge <?= $enabled ? 'badge--success' : 'badge--muted' ?>"><?= $enabled ? 'Enabled' : 'Not enabled' ?></span></p>
        <?php if ($enabled): ?>
            <form method="post" action="<?= e(url('account/mfa.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="mfa_action" value="disable">
                <div class="form-actions">
                    <button class="btn btn--danger" type="submit" data-confirm="Disable two-factor authentication?">Disable two-factor authentication</button>
                </div>
            </form>
        <?php else: ?>
            <form method="post" action="<?= e(url('account/mfa.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="mfa_action" value="start">
                <button class="btn btn--primary" type="submit"><?= $secret === '' ? 'Start setup' : 'Show setup key again' ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if (!$enabled && $secret !== ''): ?>
<section class="card mt-2">
    <div class="card__header"><h3>Connect your authenticator</h3></div>
    <div class="card__body">
        <p class="small muted">Add this key manually in your authenticator app, or paste the setup link into its import field.</p>
        <p class="mono"><?= e(chunk_split($secret, 4, ' ')) ?></p>
        <p class="small"><a href="<?= e($otpauth) ?>">Open authenticator setup link</a></p>
        <form method="post" action="<?= e(url('account/mfa.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="mfa_action" value="enable">
            <div class="field">
                <label for="code">6-digit code from app</label>
                <input type="text" id="code" name="code" inputmode="numeric" required maxlength="6" autocomplete="one-time-code">
            </div>
            <div class="form-actions"><button class="btn btn--primary" type="submit">Enable two-factor authentication</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if ($newCodes !== []): ?>
<section class="card mt-2">
    <div class="card__header"><h3>Save your recovery codes</h3></div>
    <div class="card__body">
        <p class="small muted">Each code works once. Store them somewhere safe — they are not shown again.</p>
        <ul class="mono">
            <?php foreach ($newCodes as $code): ?><li><?= e($code) ?></li><?php endforeach; ?>
        </ul>
    </div>
</section>
<?php elseif ($enabled): ?>
<section class="card mt-2">
    <div class="card__body">
        <form method="post" action="<?= e(url('account/mfa.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mfa_action" value="codes">
            <button class="btn btn--ghost" type="submit">Generate new recovery codes</button>
        </form>
    </div>
</section>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
