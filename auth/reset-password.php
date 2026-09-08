<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';

$stmt = $pdo->prepare(
    "SELECT * FROM password_resets WHERE token = :token AND used_at IS NULL AND expires_at >= NOW()"
);
$stmt->execute([':token' => $token]);
$reset = $stmt->fetch();

if (!$reset) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">This reset link is invalid or has expired.</div>';
    echo '<p class="text-center"><a href="/auth/forgot-password.php">Request a new link</a></p>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$errors = [];
$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters.";
            $fieldErrors['password'] = true;
        }
        if ($password !== $confirm) {
            $errors[] = "Passwords do not match.";
            $fieldErrors['password_confirm'] = true;
        }

        if (empty($errors)) {
            $table = $reset['user_type'] === 'employee' ? 'users' : 'guests';
            $pdo->prepare("UPDATE {$table} SET password_hash = :h WHERE id = :id")
                ->execute([':h' => password_hash($password, PASSWORD_BCRYPT), ':id' => $reset['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")
                ->execute([':id' => $reset['id']]);

            flash_set('success', 'Your password has been reset. Please log in.');
            header('Location: /auth/login.php');
            exit;
        }
    }
}

function kpaw_field_class(array $fieldErrors, string $field, string $base): string
{
    return $base . (isset($fieldErrors[$field]) ? ' is-invalid' : '');
}

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Reset Password</h5>
<?php if (!empty($errors) && empty($fieldErrors)): ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
<form method="post" novalidate id="kpawResetForm">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <div class="mb-3">
        <label class="form-label">New Password</label>
        <div class="kpaw-password-wrap">
            <input type="password" name="password" id="reg_password" class="<?= kpaw_field_class($fieldErrors, 'password', 'form-control') ?>" minlength="8" required>
            <button type="button" class="kpaw-password-toggle" data-target="reg_password" aria-label="Show password">Show</button>
        </div>
        <div class="form-text">At least 8 characters.</div>
        <div class="invalid-feedback">Must be at least 8 characters.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Confirm New Password</label>
        <div class="kpaw-password-wrap">
            <input type="password" name="password_confirm" id="reg_confirm_password" class="<?= kpaw_field_class($fieldErrors, 'password_confirm', 'form-control') ?>" minlength="8" required>
            <button type="button" class="kpaw-password-toggle" data-target="reg_confirm_password" aria-label="Show password">Show</button>
        </div>
        <div class="form-text text-danger d-none" id="reg_password_mismatch">Passwords do not match.</div>
        <div class="invalid-feedback">Passwords do not match.</div>
    </div>
    <button type="submit" class="btn btn-primary w-100">Reset Password</button>
</form>

<script>
    document.querySelectorAll('.kpaw-password-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = document.getElementById(this.dataset.target);
            var showing = field.type === 'text';
            field.type = showing ? 'password' : 'text';
            this.textContent = showing ? 'Show' : 'Hide';
            this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        });
    });

    var pw = document.getElementById('reg_password');
    var confirmPw = document.getElementById('reg_confirm_password');
    var mismatchMsg = document.getElementById('reg_password_mismatch');
    function checkPasswordsMatch() {
        var mismatch = confirmPw.value.length > 0 && pw.value !== confirmPw.value;
        mismatchMsg.classList.toggle('d-none', !mismatch);
    }
    pw.addEventListener('input', checkPasswordsMatch);
    confirmPw.addEventListener('input', checkPasswordsMatch);

    var firstInvalid = document.querySelector('#kpawResetForm .is-invalid');
    if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>