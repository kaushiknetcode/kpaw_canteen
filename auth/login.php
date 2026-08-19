<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/rate_limit.php';

// Already logged in? Don't show the login form again.
if (!empty($_SESSION['user_id'])) {
    header('Location: /app/dashboard.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } else {
        $login_id = trim($_POST['login_id'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        if (is_rate_limited($pdo, $login_id)) {
            $errors[] = "Too many failed attempts for this account. Please try again in 15 minutes.";
        } else {
            $user = null;
            $user_type = null;
            $table = null;

            if (preg_match('/^[A-Za-z0-9]{6}$/', $login_id)) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE hrms_id = :id");
                $stmt->execute([':id' => strtoupper($login_id)]);
                $user = $stmt->fetch();
                $user_type = 'employee';
                $table = 'users';
            } elseif (preg_match('/^[0-9]{10}$/', $login_id)) {
                $stmt = $pdo->prepare("SELECT * FROM guests WHERE phone = :phone");
                $stmt->execute([':phone' => $login_id]);
                $user = $stmt->fetch();
                $user_type = 'guest';
                $table = 'guests';
            }

            if (!$user || !password_verify($password, $user['password_hash'])) {
                record_login_attempt($pdo, $login_id, false);
                $errors[] = "Incorrect login ID or password.";
            } elseif (!$user['email_verified_at']) {
                $errors[] = "Please verify your email before logging in.";
            } else {
                record_login_attempt($pdo, $login_id, true);
                session_regenerate_id(true);

                $_SESSION['user_type'] = $user_type;
                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['full_name'] = $user['full_name'];

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $pdo->prepare("UPDATE {$table} SET remember_token = :t WHERE id = :id")
                        ->execute([':t' => $token, ':id' => $user['id']]);
                    setcookie('remember_token', $user_type . ':' . $user['id'] . ':' . $token, [
                        'expires'  => time() + 30 * 24 * 60 * 60,
                        'path'     => '/',
                        'secure'   => true,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                header('Location: /app/dashboard.php');
                exit;
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Log In</h5>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label">HRMS ID (employees) or Phone Number (guests)</label>
        <input type="text" name="login_id" class="form-control" required autofocus>
    </div>
    <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
    </div>
    <div class="form-check mb-3">
        <input type="checkbox" name="remember" value="1" class="form-check-input" id="remember">
        <label class="form-check-label" for="remember">Remember me for 30 days</label>
    </div>
    <button type="submit" class="btn btn-primary w-100">Log In</button>
</form>
<p class="text-center mt-3"><a href="/auth/forgot-password.php">Forgot password?</a></p>
<p class="text-center">
    New here?
    <a href="/auth/register-employee.php">Employee registration</a>
    &middot;
    <a href="/auth/register-guest.php">Guest registration</a>
</p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
