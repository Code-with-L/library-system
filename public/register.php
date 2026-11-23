<?php
/**
 * Student self-registration.
 *
 * Only the student role can be self-assigned here. Staff and administrator
 * accounts must be created by an administrator.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/throttles.php';
require_guest($pdo);

$errors = [];
$values = [
    'full_name'      => '',
    'email'          => '',
    'student_number' => '',
    'phone'          => '',
    'department'     => '',
];

if (is_post()) {
    verify_csrf();

    // Durable, DB-backed registration throttle. No schema change: it reuses
    // login_throttles with a namespaced, hashed key. Local dev is exempt.
    $signupBlocked = false;
    try {
        $signupBlocked = signup_throttle_state($pdo)['blocked'];
    } catch (PDOException $exception) {
        error_log('Signup throttle state unavailable: ' . $exception->getMessage());
    }
    if ($signupBlocked) {
        $errors['form'] = 'Too many registration attempts. Please wait one hour before trying again.';
    } else {
        try {
            signup_throttle_record($pdo);
        } catch (PDOException $exception) {
            error_log('Signup throttle record failed: ' . $exception->getMessage());
        }
    }

    $input = [
        'full_name'      => $_POST['full_name'] ?? '',
        'email'          => $_POST['email'] ?? '',
        'role'           => 'student',
        'status'         => 'active',
        'student_number' => $_POST['student_number'] ?? '',
        'phone'          => $_POST['phone'] ?? '',
        'department'     => $_POST['department'] ?? '',
        'password'       => $_POST['password'] ?? '',
        'password_confirm' => $_POST['password_confirm'] ?? '',
    ];

    $result = validate_user_management_input($pdo, $input, null, true);
    $errors = $result['errors'];
    $values = [
        'full_name'      => (string) ($_POST['full_name'] ?? ''),
        'email'          => (string) ($_POST['email'] ?? ''),
        'student_number' => (string) ($_POST['student_number'] ?? ''),
        'phone'          => (string) ($_POST['phone'] ?? ''),
        'department'     => (string) ($_POST['department'] ?? ''),
    ];

    if ($errors === [] && !$signupBlocked) {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO users
                    (role_id, full_name, email, password_hash, student_number,
                     phone, department, status)
                 VALUES
                    (:role_id, :full_name, :email, :password_hash, :student_number,
                     :phone, :department, :status)'
            );
            $statement->execute([
                'role_id'        => role_id_for($pdo, 'student'),
                'full_name'      => $result['values']['full_name'],
                'email'          => $result['values']['email'],
                'password_hash'  => password_hash((string) $result['password'], PASSWORD_DEFAULT),
                'student_number' => $result['values']['student_number'],
                'phone'          => $result['values']['phone'],
                'department'     => $result['values']['department'],
                'status'         => 'active',
            ]);

            $newUserId = (int) $pdo->lastInsertId();

            if (email_verification_required()) {
                email_verification_send($pdo, $newUserId, $result['values']['email']);
                if (isset($_SESSION['dev_verification_link'])) {
                    flash('info', 'Local verification link: ' . $_SESSION['dev_verification_link']);
                    unset($_SESSION['dev_verification_link']);
                }
            }

            log_activity(
                $pdo,
                $newUserId,
                'register',
                'user',
                $newUserId,
                'Student account self-registered.'
            );

            flash('success', 'Your student account has been created. You can now sign in.');
            redirect('login.php');
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($exception->getCode() === '23000' || $driverCode === 1062) {
                $errors['form'] = 'That email address or registration number is already registered.';
            } else {
                throw $exception;
            }
        }
    }
}

$pageTitle = 'Create a student account';
$forceGuest = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap auth-wrap--register">
    <section class="auth-intro">
        <span class="hero__eyebrow">Student registration</span>
        <h1>Join the campus library.</h1>
        <p>
            Your registration number links your library account to your borrowing record.
            Use an email address you can access regularly.
        </p>
        <div class="auth-aside">
            <strong>Before you register</strong>
            <ul>
                <li>Use your official registration number.</li>
                <li>Passwords need at least 8 characters, including a letter and a number.</li>
                <li>Staff accounts cannot be created from this page.</li>
            </ul>
        </div>
    </section>

    <section class="auth-card auth-card--register" aria-labelledby="register-title">
        <h2 id="register-title">Create your account</h2>
        <p class="auth-card__lead">All fields marked required must be completed.</p>

        <?php if (!empty($errors['form'])): ?>
            <div class="alert alert--error" role="alert"><p><?= e($errors['form']) ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('register.php')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" maxlength="120"
                       value="<?= e($values['full_name']) ?>" autocomplete="name" required
                       <?= isset($errors['full_name']) ? 'aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['full_name'])): ?><p class="field__error"><?= e($errors['full_name']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" maxlength="190"
                       value="<?= e($values['email']) ?>" autocomplete="email" required
                       <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['email'])): ?><p class="field__error"><?= e($errors['email']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="student_number">Registration number</label>
                <input type="text" id="student_number" name="student_number" maxlength="20"
                       placeholder="SIT/B/01-02392/2024" pattern="[A-Z]{3}/[BDCG]/[0-9]{2}-[0-9]{5}/[0-9]{4}"
                       title="MMUST format: XXX/Y/01-00000/YYYY, uppercase letters only"
                       value="<?= e($values['student_number']) ?>" required
                       <?= isset($errors['student_number']) ? 'aria-invalid="true"' : '' ?>>
                <p class="field__hint">Format: <strong>PROGRAMME/LEVEL/INTAKE-SERIAL/YEAR</strong>, e.g. <strong>SIT/B/01-02392/2024</strong>. Uppercase only &mdash; level: B Bachelor's, D Diploma, C Certificate, G Postgraduate.</p>
                <?php if (isset($errors['student_number'])): ?><p class="field__error"><?= e($errors['student_number']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="department">School / Department <span class="muted">(optional)</span></label>
                <select id="department" name="department" <?= isset($errors['department']) ? 'aria-invalid="true"' : '' ?>>
                    <?= mmust_school_options((string) $values['department']) ?>
                </select>
                <?php if (isset($errors['department'])): ?><p class="field__error"><?= e($errors['department']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="phone">Phone number <span class="muted">(optional)</span></label>
                <input type="text" id="phone" name="phone" maxlength="30"
                       value="<?= e($values['phone']) ?>" autocomplete="tel"
                       <?= isset($errors['phone']) ? 'aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['phone'])): ?><p class="field__error"><?= e($errors['phone']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" maxlength="72"
                       autocomplete="new-password" required
                       <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>>
                <p class="field__hint">At least 8 characters, including a letter and a number.</p>
                <?php if (isset($errors['password'])): ?><p class="field__error"><?= e($errors['password']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="password_confirm">Confirm password</label>
                <input type="password" id="password_confirm" name="password_confirm" maxlength="72"
                       autocomplete="new-password" required
                       <?= isset($errors['password_confirm']) ? 'aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['password_confirm'])): ?><p class="field__error"><?= e($errors['password_confirm']) ?></p><?php endif; ?>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary btn--block" type="submit">Create account</button>
            </div>
        </form>

        <div class="auth-meta">
            <p>Already registered? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
