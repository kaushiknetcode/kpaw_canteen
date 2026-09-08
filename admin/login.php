<?php
/**
 * Admin / receptionist login. Deliberately a SEPARATE session namespace
 * from employee/guest auth (`$_SESSION['admin_id']`, not `user_id`) —
 * an admin session and an employee session are never the same thing,
 * even if somehow logged in on the same browser. No "remember me" here
 * on purpose: these accounts control financial approvals, so shorter-
 * lived sessions are the safer default. Phase 7 will add a mandatory
 * 5-minute idle auto-logout specifically for receptionist sessions.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = "Your session expired — please try again.";
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (is_rate_limited($pdo, $email)) {
            $errors[] = "Too many failed attempts for this account. Please try again in 15 minutes.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                record_login_attempt($pdo, $email, false);
                $errors[] = "Incorrect email or password.";
            } else {
                record_login_attempt($pdo, $email, true);
                session_regenerate_id(true);

                $_SESSION['admin_id']          = (int) $admin['id'];
                $_SESSION['admin_name']        = $admin['name'];
                $_SESSION['admin_role']        = $admin['role'];
                $_SESSION['admin_canteen_id']  = $admin['assigned_canteen_id']; // null for super_admin

                $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = :id")
                    ->execute([':id' => $admin['id']]);

                // Receptionists go straight to their scoped counter screen
                // once Phase 7 builds it; super_admins land on the admin
                // dashboard. Both destinations are temporary stubs for now
                // — this step is only proving login itself works.
                if ($admin['role'] === 'receptionist') {
                    header('Location: /counter/dashboard.php');
                } else {
                    header('Location: /admin/dashboard.php');
                }
                exit;
            }
        }
    }
}

require_once __DIR__ . '/../includes/admin-header.php';
?>
<div class="card">
    <div class="card-body p-4">
        <h5 class="mb-3">Admin / Receptionist Login</h5>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="kpaw-password-wrap">
                    <input type="password" name="password" id="admin_password" class="form-control" required>
                    <button type="button" class="kpaw-password-toggle" data-target="admin_password" aria-label="Show password">Show</button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Log In</button>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.kpaw-password-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = document.getElementById(this.dataset.target);
            var showing = field.type === 'text';
            field.type = showing ? 'password' : 'text';
            this.textContent = showing ? 'Show' : 'Hide';
        });
    });
</script>
<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>