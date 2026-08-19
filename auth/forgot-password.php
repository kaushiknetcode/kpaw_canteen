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
<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label">HRMS ID or Phone Number</label>
        <input type="text" name="identifier" class="form-control" required autofocus>
    </div>
    <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
</form>
<p class="text-center mt-3"><a href="/auth/login.php">Back to login</a></p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
