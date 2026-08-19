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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
        if ($password !== $confirm) $errors[] = "Passwords do not match.";

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

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Reset Password</h5>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <div class="mb-3">
        <label class="form-label">New Password</label>
        <input type="password" name="password" class="form-control" minlength="8" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Confirm New Password</label>
        <input type="password" name="password_confirm" class="form-control" minlength="8" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Reset Password</button>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
