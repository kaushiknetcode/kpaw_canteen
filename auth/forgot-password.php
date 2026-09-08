<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/mailer.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $user = null;
        $user_type = null;

        if (preg_match('/^[A-Za-z0-9]{6}$/', $identifier)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE hrms_id = :id");
            $stmt->execute([':id' => strtoupper($identifier)]);
            $user = $stmt->fetch();
            $user_type = 'employee';
        } elseif (preg_match('/^[0-9]{10}$/', $identifier)) {
            $stmt = $pdo->prepare("SELECT * FROM guests WHERE phone = :phone");
            $stmt->execute([':phone' => $identifier]);
            $user = $stmt->fetch();
            $user_type = 'guest';
        }

        // Same message whether or not the account exists — don't leak
        // which HRMS IDs / phone numbers are registered.
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO password_resets (user_type, user_id, token, expires_at)
                 VALUES (:t, :id, :token, DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
            )->execute([':t' => $user_type, ':id' => $user['id'], ':token' => $token]);

            $link = "https://" . $_SERVER['HTTP_HOST'] . "/auth/reset-password.php?token=" . $token;
            send_password_reset_email($user['email'], $user['full_name'], $link);
        }

        flash_set('success', "If that account exists, a reset link has been sent to its email.");
        header('Location: /auth/forgot-password.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Forgot Password</h5>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="kpaw-tabs mb-3" role="tablist">
    <button type="button" class="kpaw-tab kpaw-tab--active" id="kpawTabEmployee" role="tab" aria-selected="true">Employee</button>
    <button type="button" class="kpaw-tab" id="kpawTabGuest" role="tab" aria-selected="false">Guest</button>
</div>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" id="kpawIdLabel">HRMS ID</label>
        <input type="text" name="identifier" id="kpawIdentifier" class="form-control text-uppercase"
               maxlength="6" pattern="[A-Za-z]{6}" inputmode="text" autocomplete="off" required autofocus>
        <div class="form-text" id="kpawIdHint">Exactly 6 letters, e.g. ABCDEF</div>
    </div>
    <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
</form>
<p class="text-center mt-3"><a href="/auth/login.php">Back to login</a></p>

<script>
    // Same shared field, same server-side auto-detect logic underneath —
    // tabs only change what JS allows typing here, nothing else.
    var kpawIdentifier = document.getElementById('kpawIdentifier');
    var kpawIdLabel = document.getElementById('kpawIdLabel');
    var kpawIdHint = document.getElementById('kpawIdHint');
    var kpawTabEmployee = document.getElementById('kpawTabEmployee');
    var kpawTabGuest = document.getElementById('kpawTabGuest');

    function kpawSetTab(mode) {
        kpawIdentifier.value = '';
        if (mode === 'guest') {
            kpawTabGuest.classList.add('kpaw-tab--active');
            kpawTabGuest.setAttribute('aria-selected', 'true');
            kpawTabEmployee.classList.remove('kpaw-tab--active');
            kpawTabEmployee.setAttribute('aria-selected', 'false');
            kpawIdentifier.classList.remove('text-uppercase');
            kpawIdentifier.maxLength = 10;
            kpawIdentifier.pattern = '[0-9]{10}';
            kpawIdentifier.inputMode = 'numeric';
            kpawIdLabel.textContent = 'Phone Number';
            kpawIdHint.textContent = 'Exactly 10 digits';
        } else {
            kpawTabEmployee.classList.add('kpaw-tab--active');
            kpawTabEmployee.setAttribute('aria-selected', 'true');
            kpawTabGuest.classList.remove('kpaw-tab--active');
            kpawTabGuest.setAttribute('aria-selected', 'false');
            kpawIdentifier.classList.add('text-uppercase');
            kpawIdentifier.maxLength = 6;
            kpawIdentifier.pattern = '[A-Za-z]{6}';
            kpawIdentifier.inputMode = 'text';
            kpawIdLabel.textContent = 'HRMS ID';
            kpawIdHint.textContent = 'Exactly 6 letters, e.g. ABCDEF';
        }
        kpawIdentifier.dataset.mode = mode;
    }
    kpawTabEmployee.addEventListener('click', function () { kpawSetTab('employee'); });
    kpawTabGuest.addEventListener('click', function () { kpawSetTab('guest'); });
    kpawSetTab('employee');

    kpawIdentifier.addEventListener('input', function (e) {
        if (kpawIdentifier.dataset.mode === 'guest') {
            e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 10);
        } else {
            e.target.value = e.target.value.replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 6);
        }
    });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>