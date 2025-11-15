<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $query = "SELECT id, username, password FROM users WHERE username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid password!";
        }
    } else {
        $error = "User not found!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NeonBeats - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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

        .login-container {
            background: rgba(18, 18, 18, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid var(--neon-purple);
            border-radius: 20px;
            padding: 40px;
            width: 400px;
            box-shadow: 0 0 30px rgba(162, 155, 254, 0.3);
            animation: glow 2s infinite alternate;
        }

        @keyframes glow {
            from {
                box-shadow: 0 0 20px rgba(162, 155, 254, 0.3);
            }
            to {
                box-shadow: 0 0 30px rgba(162, 155, 254, 0.6), 0 0 40px rgba(108, 92, 231, 0.4);
            }
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            font-size: 2.5rem;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
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
            border: 1px solid var(--neon-purple);
            border-radius: 10px;
            color: var(--light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--neon-pink);
            box-shadow: 0 0 10px rgba(253, 121, 168, 0.5);
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
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
            box-shadow: 0 5px 15px rgba(253, 121, 168, 0.4);
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
            color: var(--neon-green);
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
            background: linear-gradient(45deg, var(--neon-pink), var(--neon-purple));
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

    <div class="login-container">
        <div class="logo">
            <h1><i class="fas fa-music"></i> NeonBeats</h1>
            <p>Your personal music streaming experience</p>
        </div>

        <?php if(isset($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username"><i class="fas fa-user"></i> Username</label>
                <input type="text" id="username" name="username" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <input type="password" id="password" name="password" class="form-control" required>
            </div>

            <button type="submit" class="btn">Login</button>
        </form>

        <div class="links">
            <p>Don't have an account? <a href="register.php">Register here</a></p>
        </div>
    </div>
</body>
</html>  