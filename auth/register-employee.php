<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/mailer.php';

$errors = [];
$fieldErrors = []; // field_name => true, drives red border + inline message

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $hrms_id   = strtoupper(trim($_POST['hrms_id'] ?? ''));
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($full_name === '') {
            $errors[] = "Full name is required.";
            $fieldErrors['full_name'] = true;
        }
        if (!preg_match('/^[A-Z]{6}$/', $hrms_id)) {
            $errors[] = "HRMS ID must be exactly 6 letters (no numbers).";
            $fieldErrors['hrms_id'] = true;
        }
        if (!preg_match('/^[0-9]{10}$/', $phone)) {
            $errors[] = "Phone number must be 10 digits.";
            $fieldErrors['phone'] = true;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Enter a valid email address.";
            $fieldErrors['email'] = true;
        }
        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters.";
            $fieldErrors['password'] = true;
        }
        if ($password !== ($_POST['confirm_password'] ?? '')) {
            $errors[] = "Passwords do not match.";
            $fieldErrors['confirm_password'] = true;
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare(
                "SELECT id FROM users WHERE hrms_id = :hrms_id OR email = :email1 OR phone = :phone1
                 UNION
                 SELECT id FROM guests WHERE email = :email2 OR phone = :phone2"
            );
            $stmt->execute([
                ':hrms_id' => $hrms_id,
                ':email1'  => $email,
                ':phone1'  => $phone,
                ':email2'  => $email,
                ':phone2'  => $phone,
            ]);
            if ($stmt->fetch()) {
                $errors[] = "An account with this HRMS ID, email, or phone number already exists.";
                $fieldErrors['hrms_id'] = true;
                $fieldErrors['email'] = true;
                $fieldErrors['phone'] = true;
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        "INSERT INTO users (full_name, hrms_id, phone, email, password_hash)
                         VALUES (:full_name, :hrms_id, :phone, :email, :password_hash)"
                    );
                    $stmt->execute([
                        ':full_name'     => $full_name,
                        ':hrms_id'       => $hrms_id,
                        ':phone'         => $phone,
                        ':email'         => $email,
                        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ]);
                    $user_id = (int) $pdo->lastInsertId();

                    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $pdo->prepare(
                        "INSERT INTO otp_verifications (user_type, user_id, otp_code, expires_at)
                         VALUES ('employee', :user_id, :otp, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
                    )->execute([':user_id' => $user_id, ':otp' => $otp]);

                    $pdo->commit();

                    send_otp_email($email, $full_name, $otp);

                    $_SESSION['pending_verification'] = ['user_type' => 'employee', 'user_id' => $user_id];
                    header('Location: /auth/verify-otp.php');
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Employee registration failed: ' . $e->getMessage());
                    $errors[] = "Something went wrong. Please try again.";
                }
            }
        }
    }
}

// Small helper so every field's markup stays readable below —
// appends 'is-invalid' to whatever classes are already there.
function kpaw_field_class(array $fieldErrors, string $field, string $base): string
{
    return $base . (isset($fieldErrors[$field]) ? ' is-invalid' : '');
}

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Employee Registration</h5>
<?php if (!empty($errors) && empty($fieldErrors)): ?>
    <?php // Only show the top summary for errors that aren't tied to a
          // specific field (e.g. an expired CSRF token) — anything with
          // a field-level red border already has its own inline message,
          // so repeating it up here would just be noise. ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
<form method="post" novalidate id="kpawRegisterForm">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label">Full Name</label>
        <input type="text" name="full_name" class="<?= kpaw_field_class($fieldErrors, 'full_name', 'form-control') ?>" required
               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
        <div class="invalid-feedback">Full name is required.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">HRMS ID</label>
        <input type="text" name="hrms_id" id="hrms_id" class="<?= kpaw_field_class($fieldErrors, 'hrms_id', 'form-control text-uppercase') ?>" maxlength="6"
               pattern="[A-Za-z]{6}" inputmode="text" autocomplete="off" required
               value="<?= htmlspecialchars($_POST['hrms_id'] ?? '') ?>">
        <div class="form-text">Exactly 6 letters, e.g. ABCDEF — no numbers.</div>
        <div class="invalid-feedback">Must be exactly 6 letters, and not already registered.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Phone Number</label>
        <input type="tel" name="phone" id="reg_phone" class="<?= kpaw_field_class($fieldErrors, 'phone', 'form-control') ?>" maxlength="10"
               pattern="[0-9]{10}" inputmode="numeric" required
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        <div class="invalid-feedback">Must be exactly 10 digits, and not already registered.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="<?= kpaw_field_class($fieldErrors, 'email', 'form-control') ?>" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <div class="invalid-feedback">Enter a valid email, not already registered.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Password</label>
        <div class="kpaw-password-wrap">
            <input type="password" name="password" id="reg_password" class="<?= kpaw_field_class($fieldErrors, 'password', 'form-control') ?>" minlength="8" required>
            <button type="button" class="kpaw-password-toggle" data-target="reg_password" aria-label="Show password">Show</button>
        </div>
        <div class="form-text">At least 8 characters.</div>
        <div class="invalid-feedback">Must be at least 8 characters.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <div class="kpaw-password-wrap">
            <input type="password" name="confirm_password" id="reg_confirm_password" class="<?= kpaw_field_class($fieldErrors, 'confirm_password', 'form-control') ?>" minlength="8" required>
            <button type="button" class="kpaw-password-toggle" data-target="reg_confirm_password" aria-label="Show password">Show</button>
        </div>
        <div class="form-text text-danger d-none" id="reg_password_mismatch">Passwords do not match.</div>
        <div class="invalid-feedback">Passwords do not match.</div>
    </div>
    <button type="submit" class="btn btn-primary w-100">Register</button>
</form>
<p class="text-center mt-3"><a href="/auth/login.php">Already have an account? Log in</a></p>
<p class="text-center"><a href="/auth/register-guest.php">Registering as a guest instead?</a></p>

<script>
    document.getElementById('hrms_id').addEventListener('input', function (e) {
        e.target.value = e.target.value.replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 6);
    });

    document.getElementById('reg_phone').addEventListener('input', function (e) {
        e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 10);
    });

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

    // Scroll to the first red-bordered field on load, if the page reloaded
    // with a validation error — the top alert list can be off-screen on
    // mobile after someone's scrolled down while filling the form.
    var firstInvalid = document.querySelector('#kpawRegisterForm .is-invalid');
    if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>