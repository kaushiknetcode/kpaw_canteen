<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/mailer.php';

$errors = [];
$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($full_name === '') {
            $errors[] = "Full name is required.";
            $fieldErrors['full_name'] = true;
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
                "SELECT id FROM guests WHERE phone = :phone1 OR email = :email1
                 UNION
                 SELECT id FROM users WHERE email = :email2 OR phone = :phone2"
            );
            $stmt->execute([
                ':phone1' => $phone,
                ':email1' => $email,
                ':email2' => $email,
                ':phone2' => $phone,
            ]);
            if ($stmt->fetch()) {
                $errors[] = "An account with this phone number or email already exists.";
                $fieldErrors['phone'] = true;
                $fieldErrors['email'] = true;
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        "INSERT INTO guests (full_name, phone, email, password_hash)
                         VALUES (:full_name, :phone, :email, :password_hash)"
                    );
                    $stmt->execute([
                        ':full_name'     => $full_name,
                        ':phone'         => $phone,
                        ':email'         => $email,
                        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ]);
                    $guest_id = (int) $pdo->lastInsertId();

                    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $pdo->prepare(
                        "INSERT INTO otp_verifications (user_type, user_id, otp_code, expires_at)
                         VALUES ('guest', :user_id, :otp, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
                    )->execute([':user_id' => $guest_id, ':otp' => $otp]);

                    $pdo->commit();

                    send_otp_email($email, $full_name, $otp);

                    $_SESSION['pending_verification'] = ['user_type' => 'guest', 'user_id' => $guest_id];
                    header('Location: /auth/verify-otp.php');
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Guest registration failed: ' . $e->getMessage());
                    $errors[] = "Something went wrong. Please try again.";
                }
            }
        }
    }
}

function kpaw_field_class(array $fieldErrors, string $field, string $base): string
{
    return $base . (isset($fieldErrors[$field]) ? ' is-invalid' : '');
}

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Guest Registration</h5>
<?php if (!empty($errors) && empty($fieldErrors)): ?>
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
        <label class="form-label">Phone Number</label>
        <input type="tel" name="phone" id="reg_phone" class="<?= kpaw_field_class($fieldErrors, 'phone', 'form-control') ?>" maxlength="10"
               pattern="[0-9]{10}" inputmode="numeric" required
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        <div class="form-text">This will be your login ID.</div>
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
<p class="text-center"><a href="/auth/register-employee.php">Registering as an employee instead?</a></p>

<script>
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

    var firstInvalid = document.querySelector('#kpawRegisterForm .is-invalid');
    if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>