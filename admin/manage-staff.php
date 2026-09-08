<?php
/**
 * Staff account management — super_admin only. A FIXED roster, not an
 * open-ended list: exactly one Super Admin card, plus one card per
 * canteen for that canteen's receptionist. No generic "create new
 * account" tool — each canteen's card either shows its existing
 * receptionist (Edit only) or, if that slot hasn't been set up yet,
 * lets you set their credentials directly, still permanently tied to
 * that one canteen. Never more than one receptionist per canteen,
 * never a delete option — editing only ever changes email/password
 * on the existing row.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}
if ($_SESSION['admin_role'] !== 'super_admin') {
    http_response_code(403);
    die('Access denied.');
}

$canteens = $pdo->query("SELECT * FROM canteens WHERE is_active = 1 ORDER BY id")->fetchAll();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    if (isset($_POST['update_account'])) {
        // Edit an EXISTING account's email and/or password — never
        // touches role or canteen assignment, since those are fixed
        // for the life of the slot.
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter a valid email address.";
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            $error = "New password must be at least 8 characters — leave it blank to keep the current password unchanged.";
        } else {
            try {
                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE admins SET email = :email, password_hash = :hash WHERE id = :id");
                    $stmt->execute([':email' => $email, ':hash' => $hash, ':id' => $accountId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE admins SET email = :email WHERE id = :id");
                    $stmt->execute([':email' => $email, ':id' => $accountId]);
                }
                $success = "Account updated." . ($newPassword !== '' ? " New password set." : '');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = "That email is already used by another account.";
                } else {
                    error_log('KPAW staff update failed: ' . $e->getMessage());
                    $error = "Something went wrong. Please try again.";
                }
            }
        }
    } elseif (isset($_POST['setup_receptionist'])) {
        // Set up the receptionist for a specific canteen slot — the
        // canteen is fixed by which card's form was submitted, never
        // chosen freely, and this only ever runs once per canteen
        // (the form for an already-filled slot doesn't render at all).
        $canteenId = (int) ($_POST['canteen_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $canteenValid = false;
        foreach ($canteens as $c) {
            if ($c['id'] === $canteenId) { $canteenValid = true; break; }
        }

        $existsCheck = $pdo->prepare("SELECT 1 FROM admins WHERE role = 'receptionist' AND assigned_canteen_id = :cid");
        $existsCheck->execute([':cid' => $canteenId]);
        $alreadyExists = (bool) $existsCheck->fetchColumn();

        if (!$canteenValid) {
            $error = "Invalid canteen.";
        } elseif ($alreadyExists) {
            $error = "This canteen already has a receptionist — edit their existing account instead.";
        } elseif ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Name and a valid email are required.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare(
                    "INSERT INTO admins (name, email, password_hash, role, assigned_canteen_id)
                     VALUES (:name, :email, :hash, 'receptionist', :canteen_id)"
                );
                $stmt->execute([':name' => $name, ':email' => $email, ':hash' => $hash, ':canteen_id' => $canteenId]);
                $success = "Receptionist account created for {$name}.";
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = "That email is already in use.";
                } else {
                    error_log('KPAW receptionist create failed: ' . $e->getMessage());
                    $error = "Something went wrong. Please try again.";
                }
            }
        }
    }
}

$superAdmins = $pdo->query("SELECT * FROM admins WHERE role = 'super_admin' ORDER BY id")->fetchAll();

$receptionistByCanteen = [];
$recStmt = $pdo->prepare("SELECT * FROM admins WHERE role = 'receptionist' AND assigned_canteen_id = :cid LIMIT 1");
foreach ($canteens as $c) {
    $recStmt->execute([':cid' => $c['id']]);
    $receptionistByCanteen[$c['id']] = $recStmt->fetch() ?: null;
}

$adminContainerMaxWidth = 720;
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Manage Staff Accounts</h5>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<h6 class="text-muted small mb-2">SUPER ADMIN</h6>
<?php foreach ($superAdmins as $acc): ?>
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-bold"><?= htmlspecialchars($acc['name']) ?></span>
                    <span class="badge bg-primary ms-1">Super Admin</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary"
                        onclick="document.getElementById('edit-<?= (int) $acc['id'] ?>').classList.toggle('d-none')">
                    Edit
                </button>
            </div>
            <div class="small text-muted"><?= htmlspecialchars($acc['email']) ?></div>

            <form method="post" id="edit-<?= (int) $acc['id'] ?>" class="d-none mt-2 border-top pt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="account_id" value="<?= (int) $acc['id'] ?>">
                <label class="form-label small mb-1">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($acc['email']) ?>" class="form-control form-control-sm mb-2" required>
                <label class="form-label small mb-1">New password (leave blank to keep current)</label>
                <input type="text" name="new_password" class="form-control form-control-sm mb-2" placeholder="Min. 8 characters" minlength="8">
                <button type="submit" name="update_account" value="1" class="btn btn-primary btn-sm">Save</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<h6 class="text-muted small mb-2 mt-4">RECEPTIONISTS</h6>
<?php foreach ($canteens as $c):
    $rec = $receptionistByCanteen[$c['id']];
?>
    <div class="card mb-3">
        <div class="card-body py-2">
            <?php if ($rec): ?>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold"><?= htmlspecialchars($rec['name']) ?></span>
                        <span class="badge bg-secondary ms-1">Receptionist</span>
                        <span class="small text-muted">&middot; <?= htmlspecialchars($c['name']) ?></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="document.getElementById('edit-<?= (int) $rec['id'] ?>').classList.toggle('d-none')">
                        Edit
                    </button>
                </div>
                <div class="small text-muted"><?= htmlspecialchars($rec['email']) ?></div>

                <form method="post" id="edit-<?= (int) $rec['id'] ?>" class="d-none mt-2 border-top pt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account_id" value="<?= (int) $rec['id'] ?>">
                    <label class="form-label small mb-1">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($rec['email']) ?>" class="form-control form-control-sm mb-2" required>
                    <label class="form-label small mb-1">New password (leave blank to keep current)</label>
                    <input type="text" name="new_password" class="form-control form-control-sm mb-2" placeholder="Min. 8 characters" minlength="8">
                    <button type="submit" name="update_account" value="1" class="btn btn-primary btn-sm">Save</button>
                </form>
            <?php else: ?>
                <div class="fw-bold"><?= htmlspecialchars($c['name']) ?> <span class="small fw-normal text-muted">— not set up yet</span></div>
                <form method="post" class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="canteen_id" value="<?= (int) $c['id'] ?>">
                    <label class="form-label small mb-1">Name</label>
                    <input type="text" name="name" class="form-control form-control-sm mb-2" required>
                    <label class="form-label small mb-1">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm mb-2" required>
                    <label class="form-label small mb-1">Password</label>
                    <input type="text" name="password" class="form-control form-control-sm mb-2" placeholder="Min. 8 characters" minlength="8" required>
                    <button type="submit" name="setup_receptionist" value="1" class="btn btn-success btn-sm w-100">Set Up Receptionist</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>