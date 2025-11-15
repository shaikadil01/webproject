<?php
include 'config.php';

if (!isset($_GET['token'])) {
    header("Location: login.php");
    exit();
}

$token = $_GET['token'];
$database = new Database();
$db = $database->getConnection();

// Check if token is valid
$query = "SELECT id, reset_token_expiry FROM users WHERE reset_token = :token";
$stmt = $db->prepare($query);
$stmt->bindParam(':token', $token);
$stmt->execute();

if ($stmt->rowCount() == 0) {
    $error = "Invalid reset token!";
} else {
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if token is expired
    if (strtotime($user['reset_token_expiry']) < time()) {
        $error = "Reset token has expired!";
    } else {
        // Process password reset
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $new_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            $update_query = "UPDATE users SET password = :password, reset_token = NULL, reset_token_expiry = NULL WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':password', $new_password);
            $update_stmt->bindParam(':id', $user['id']);
            
            if ($update_stmt->execute()) {
                $success = "Password reset successfully! You can now login with your new password.";
            } else {
                $error = "Error resetting password!";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - NeonBeats</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Use the same styles as forgot_password.php */
        <?php include 'forgot_password.php'; ?>
    </style>
</head>
<body>
    <div class="floating-elements">
        <?php for($i=0; $i<15; $i++): ?>
            <div class="floating-element" style="
                left: <?= rand(0, 100) ?>%; 
                top: <?= rand(0, 100) ?>%; 
                width: <?= rand(20, 80) ?>px; 
                height: <?= rand(20, 80) ?>px;
                animation-duration: <?= rand(10, 30) ?>s;
                animation-delay: <?= rand(0, 10) ?>s;
            "></div>
        <?php endfor; ?>
    </div>

    <div class="forgot-container">
        <div class="logo">
            <h1><i class="fas fa-music"></i> NeonBeats</h1>
            <p>Set new password</p>
        </div>

        <?php if(isset($error)): ?>
            <div class="error"><?= $error ?></div>
            <div class="links">
                <p><a href="forgot_password.php">Request new reset link</a></p>
            </div>
        <?php elseif(isset($success)): ?>
            <div class="success"><?= $success ?></div>
            <div class="links">
                <p><a href="login.php">Login with new password</a></p>
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> New Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-lock"></i> Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>

                <button type="submit" class="btn">Reset Password</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Password confirmation validation
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>