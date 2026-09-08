<?php
/**
 * ONE-TIME-USE UTILITY — generates a bcrypt hash for a password you type in.
 *
 * HOW TO USE:
 * 1. Upload this file to public_html/admin/generate-hash.php
 * 2. Visit https://coral-marten-740711.hostingersite.com/admin/generate-hash.php
 * 3. Type your new password, submit, copy the hash shown
 * 4. Paste that hash into the SQL template below and run it in phpMyAdmin
 * 5. DELETE THIS FILE FROM THE SERVER IMMEDIATELY AFTER — do not leave it
 *    sitting on a live, public server. Anyone who finds this URL can
 *    generate hashes freely; it has no login check of its own.
 */

$hash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['password'])) {
    $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
}
?>
<!DOCTYPE html>
<html>
<head><title>Password Hash Generator — delete after use</title></head>
<body style="font-family: sans-serif; max-width: 500px; margin: 40px auto;">
    <h3>Generate a password hash</h3>
    <p style="color: #B3261E;"><strong>Delete this file from the server as soon as you're done.</strong></p>
    <form method="post">
        <input type="text" name="password" placeholder="Type the new password" style="width: 100%; padding: 8px;" required>
        <button type="submit" style="margin-top: 10px; padding: 8px 16px;">Generate Hash</button>
    </form>
    <?php if ($hash): ?>
        <p><strong>Hash:</strong></p>
        <textarea style="width: 100%; height: 80px;" readonly><?= htmlspecialchars($hash) ?></textarea>
        <p>Paste this into the SQL template, then run it in phpMyAdmin.</p>
    <?php endif; ?>
</body>
</html>