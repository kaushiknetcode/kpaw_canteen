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
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($full_name === '') $errors[] = "Full name is required.";
        if (!preg_match('/^[0-9]{10}$/', $phone)) $errors[] = "Phone number must be 10 digits.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Enter a valid email address.";
        if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";

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

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Guest Registration</h5>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label">Full Name</label>
        <input type="text" name="full_name" class="form-control" required
               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">Phone Number</label>
        <input type="tel" name="phone" class="form-control" pattern="[0-9]{10}" required
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        <div class="form-text">This will be your login ID.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" minlength="8" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Register</button>
</form>
<p class="text-center mt-3"><a href="/auth/login.php">Already have an account? Log in</a></p>
<p class="text-center"><a href="/auth/register-employee.php">Registering as an employee instead?</a></p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
