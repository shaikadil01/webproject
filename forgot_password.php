<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    $email = $_POST['email'];
    
    $query = "SELECT id, username FROM users WHERE email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Generate reset token
        $reset_token = bin2hex(random_bytes(32));
        $reset_token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database
        $update_query = "UPDATE users SET reset_token = :reset_token, reset_token_expiry = :reset_token_expiry WHERE id = :id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':reset_token', $reset_token);
        $update_stmt->bindParam(':reset_token_expiry', $reset_token_expiry);
        $update_stmt->bindParam(':id', $user['id']);
        $update_stmt->execute();
        
        // Send reset email (in production, implement proper email sending)
        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $reset_token;
        $subject = "Password Reset - NeonBeats";
        $message = "Hello " . $user['username'] . ",<br><br>";
        $message .= "You requested a password reset. Click the link below to reset your password:<br>";
        $message .= "<a href='" . $reset_link . "'>Reset Password</a><br><br>";
        $message .= "This link will expire in 1 hour.<br><br>";
        $message .= "If you didn't request this, please ignore this email.";
        
        // In production, use PHPMailer or similar
        // sendEmail($email, $subject, $message);
        
        $success = "Password reset link has been sent to your email!";
    } else {
        $error = "No account found with that email address!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - NeonBeats</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Use the same styles as login.php but with neon-green theme */
        :root {
            --primary: #6c5ce7;
            --secondary: #a29bfe;
            --accent: #00cec9;
            --dark: #121212;
            --darker: #0a0a0a;
            --light: #f5f6fa;
            --neon-pink: #fd79a8;
            --neon-blue: #0984e3;
            --neon-green: #00b894;
            --neon-purple: #a29bfe;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--darker) 0%, var(--dark) 100%);
            color: var(--light);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .forgot-container {
            background: rgba(18, 18, 18, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid var(--neon-green);
            border-radius: 20px;
            padding: 40px;
            width: 400px;
            box-shadow: 0 0 30px rgba(0, 184, 148, 0.3);
            animation: glow 2s infinite alternate;
        }

        @keyframes glow {
            from {
                box-shadow: 0 0 20px rgba(0, 184, 148, 0.3);
            }
            to {
                box-shadow: 0 0 30px rgba(0, 184, 148, 0.6), 0 0 40px rgba(0, 206, 201, 0.4);
            }
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            font-size: 2.5rem;
            background: linear-gradient(45deg, var(--neon-green), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }

        .logo p {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--secondary);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--neon-green);
            border-radius: 10px;
            color: var(--light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 10px rgba(0, 206, 201, 0.5);
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(45deg, var(--neon-green), var(--accent));
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 184, 148, 0.4);
        }

        .links {
            text-align: center;
            margin-top: 20px;
        }

        .links a {
            color: var(--neon-blue);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .links a:hover {
            color: var(--neon-pink);
        }

        .error {
            background: rgba(253, 121, 168, 0.2);
            border: 1px solid var(--neon-pink);
            color: var(--neon-pink);
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .success {
            background: rgba(0, 184, 148, 0.2);
            border: 1px solid var(--neon-green);
            color: var(--neon-green);
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .floating-elements {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .floating-element {
            position: absolute;
            width: 50px;
            height: 50px;
            background: linear-gradient(45deg, var(--neon-green), var(--accent));
            border-radius: 50%;
            opacity: 0.1;
            animation: float 15s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(0) translateX(0) rotate(0deg);
            }
            100% {
                transform: translateY(-1000px) translateX(1000px) rotate(720deg);
            }
        }
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
            <p>Reset your password</p>
        </div>

        <?php if(isset($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <?php if(isset($success)): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                <input type="email" id="email" name="email" class="form-control" required>
            </div>

            <button type="submit" class="btn">Send Reset Link</button>
        </form>

        <div class="links">
            <p>Remember your password? <a href="login.php">Login here</a></p>
        </div>
    </div>
</body>
</html>