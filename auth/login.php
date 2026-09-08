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

require_once __DIR__ . '/../includes/header-branded.php';
?>
<div class="card">
    <div class="card-body p-4">
        <h5 class="mb-3">Log In</h5>
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
                <label class="form-label" id="kpawLoginLabel">HRMS ID</label>
                <input type="text" name="login_id" id="kpawLoginId" class="form-control text-uppercase"
                       maxlength="6" pattern="[A-Za-z]{6}" inputmode="text" autocomplete="off" required autofocus>
                <div class="form-text" id="kpawLoginHint">Exactly 6 letters, e.g. ABCDEF</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="kpaw-password-wrap">
                    <input type="password" name="password" id="kpawPassword" class="form-control" required>
                    <button type="button" class="kpaw-password-toggle" id="kpawPasswordToggle" aria-label="Show password">Show</button>
                </div>
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="remember" value="1" class="form-check-input" id="remember">
                <label class="form-check-label" for="remember">Remember me for 30 days</label>
            </div>
            <button type="submit" class="btn btn-primary w-100">Log In</button>
        </form>

        <a href="/auth/forgot-password.php" class="btn btn-outline-primary w-100 mt-3">Forgot password?</a>

        <div class="d-flex gap-2 mt-2 justify-content-center">
            <a href="/auth/register-employee.php" class="kpaw-btn-pill">Employee registration</a>
            <a href="/auth/register-guest.php" class="kpaw-btn-pill">Guest registration</a>
        </div>
    </div>
</div>

<script>
    // Tabs only change what this ONE shared field accepts — the actual
    // <input name="login_id"> never changes, so the server's existing
    // detection logic (6 letters -> employee, 10 digits -> guest) keeps
    // working exactly as before, completely untouched.
    var kpawLoginId = document.getElementById('kpawLoginId');
    var kpawLabel = document.getElementById('kpawLoginLabel');
    var kpawHint = document.getElementById('kpawLoginHint');
    var kpawTabEmployee = document.getElementById('kpawTabEmployee');
    var kpawTabGuest = document.getElementById('kpawTabGuest');

    function kpawSetTab(mode) {
        kpawLoginId.value = '';
        if (mode === 'guest') {
            kpawTabGuest.classList.add('kpaw-tab--active');
            kpawTabGuest.setAttribute('aria-selected', 'true');
            kpawTabEmployee.classList.remove('kpaw-tab--active');
            kpawTabEmployee.setAttribute('aria-selected', 'false');
            kpawLoginId.classList.remove('text-uppercase');
            kpawLoginId.maxLength = 10;
            kpawLoginId.pattern = '[0-9]{10}';
            kpawLoginId.inputMode = 'numeric';
            kpawLabel.textContent = 'Phone Number';
            kpawHint.textContent = 'Exactly 10 digits';
        } else {
            kpawTabEmployee.classList.add('kpaw-tab--active');
            kpawTabEmployee.setAttribute('aria-selected', 'true');
            kpawTabGuest.classList.remove('kpaw-tab--active');
            kpawTabGuest.setAttribute('aria-selected', 'false');
            kpawLoginId.classList.add('text-uppercase');
            kpawLoginId.maxLength = 6;
            kpawLoginId.pattern = '[A-Za-z]{6}';
            kpawLoginId.inputMode = 'text';
            kpawLabel.textContent = 'HRMS ID';
            kpawHint.textContent = 'Exactly 6 letters, e.g. ABCDEF';
        }
        kpawLoginId.dataset.mode = mode;
    }
    kpawTabEmployee.addEventListener('click', function () { kpawSetTab('employee'); });
    kpawTabGuest.addEventListener('click', function () { kpawSetTab('guest'); });
    kpawSetTab('employee'); // default tab

    kpawLoginId.addEventListener('input', function (e) {
        if (kpawLoginId.dataset.mode === 'guest') {
            e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 10);
        } else {
            e.target.value = e.target.value.replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 6);
        }
    });

    document.getElementById('kpawPasswordToggle').addEventListener('click', function () {
        var pw = document.getElementById('kpawPassword');
        var showing = pw.type === 'text';
        pw.type = showing ? 'password' : 'text';
        this.textContent = showing ? 'Show' : 'Hide';
        this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>