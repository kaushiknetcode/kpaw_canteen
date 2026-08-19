<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/mailer.php';

if (empty($_SESSION['pending_verification'])) {
    header('Location: /auth/login.php');
    exit;
}
$user_type = $_SESSION['pending_verification']['user_type'];
$user_id   = (int) $_SESSION['pending_verification']['user_id'];
$table     = $user_type === 'employee' ? 'users' : 'guests';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } elseif (isset($_POST['resend'])) {
        $lastSent = $_SESSION['otp_last_sent'] ?? 0;
        if (time() - $lastSent < 60) {
            $errors[] = "Please wait a minute before requesting another code.";
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM otp_verifications
                 WHERE user_type = :t AND user_id = :id AND created_at >= (NOW() - INTERVAL 1 HOUR)"
            );
            $stmt->execute([':t' => $user_type, ':id' => $user_id]);

            if ((int) $stmt->fetchColumn() >= 5) {
                $errors[] = "Too many code requests. Please try again later.";
            } else {
                $stmt = $pdo->prepare("SELECT full_name, email FROM {$table} WHERE id = :id");
                $stmt->execute([':id' => $user_id]);
                $person = $stmt->fetch();

                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $pdo->prepare(
                    "INSERT INTO otp_verifications (user_type, user_id, otp_code, expires_at)
                     VALUES (:t, :id, :otp, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
                )->execute([':t' => $user_type, ':id' => $user_id, ':otp' => $otp]);

                send_otp_email($person['email'], $person['full_name'], $otp);
                $_SESSION['otp_last_sent'] = time();
                flash_set('success', 'A new code has been sent to your email.');
                header('Location: /auth/verify-otp.php');
                exit;
            }
        }
    } else {
        $otp_input = trim($_POST['otp'] ?? '');
        $stmt = $pdo->prepare(
            "SELECT id FROM otp_verifications
             WHERE user_type = :t AND user_id = :id AND otp_code = :otp
               AND verified_at IS NULL AND expires_at >= NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':t' => $user_type, ':id' => $user_id, ':otp' => $otp_input]);
        $row = $stmt->fetch();

        if (!$row) {
            $errors[] = "Invalid or expired code. Please try again or request a new one.";
        } else {
            $pdo->prepare("UPDATE otp_verifications SET verified_at = NOW() WHERE id = :id")
                ->execute([':id' => $row['id']]);
            $pdo->prepare("UPDATE {$table} SET email_verified_at = NOW() WHERE id = :id")
                ->execute([':id' => $user_id]);

            unset($_SESSION['pending_verification'], $_SESSION['otp_last_sent']);
            flash_set('success', 'Your account is verified! You can now log in.');
            header('Location: /auth/login.php');
            exit;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Verify Your Email</h5>
<p class="text-muted small">Enter the 6-digit code we emailed you.</p>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <input type="text" name="otp" class="form-control text-center" maxlength="6"
               pattern="[0-9]{6}" autocomplete="one-time-code" required autofocus>
    </div>
    <button type="submit" class="btn btn-primary w-100 mb-2">Verify</button>
</form>
<form method="post">
    <?= csrf_field() ?>
    <button type="submit" name="resend" value="1" class="btn btn-link w-100">Resend code</button>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
