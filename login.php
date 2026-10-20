<?php
require_once __DIR__ . '/auth.php';
if (currentAdmin($pdo)) {
    header('Location: admoonzz');
    exit;
}
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if ($email === '' || $password === '') {
        $error = 'Enter your email address and password.';
    } elseif (loginAdmin($pdo, $email, $password)) {
        header('Location: admoonzz');
        exit;
    } else {
        $error = 'Invalid email address or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ثورة الموتى - Bi Angelos Theatre</title>
    <link rel="icon" type="image/jpeg" href="logo.jpg">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #A1CAE3 0%, #FFFEFF 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            padding: 50px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(32, 127, 189, 0.3);
            max-width: 450px;
            width: 100%;
            text-align: center;
        }
        
        .logo {
            max-width: 180px;
            height: auto;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #207FBD;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #FC723E;
            font-size: 16px;
            margin-bottom: 35px;
            font-weight: 500;
        }
        
        .error-message {
            background: linear-gradient(135deg, #f44336 0%, #e57373 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(244, 67, 54, 0.3);
            animation: shake 0.5s ease;
        }

        .info-message {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            color: #e65100;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
            border: 2px solid #ffb74d;
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.15);
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .form-group {
            margin-bottom: 25px;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #207FBD;
            font-weight: 600;
            font-size: 15px;
        }
        
        .form-group input[type="email"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #A1CAE3;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
            font-family: inherit;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #207FBD;
            box-shadow: 0 0 0 3px rgba(32, 127, 189, 0.1);
        }
        
        .login-btn {
            width: 100%;
            background: linear-gradient(135deg, #207FBD 0%, #4a9ed1 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 15px rgba(32, 127, 189, 0.4);
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(32, 127, 189, 0.6);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        .info-text {
            margin-top: 25px;
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.15) 0%, rgba(76, 175, 80, 0.08) 100%);
            color: #4CAF50;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 20px;
            border: 2px solid rgba(76, 175, 80, 0.3);
        }
        
        .security-badge::before {
            font-size: 16px;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 40px 25px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .subtitle {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <img src="logo.jpg" alt="Bi Angelos Theatre" class="logo">
        
        <?php if ($error): ?>
            <div class="error-message" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group" id="emailGroup">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required autofocus 
                       autocomplete="username" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit" class="login-btn">Login</button>
        </form>
        
    </div>
    

    <script src="app.js?v=<?php echo filemtime(__DIR__ . '/app.js'); ?>"></script>
</body>
</html>
