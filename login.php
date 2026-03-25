<?php
session_start();
require_once 'db.php';

if (isset($_COOKIE['user_email']) && isset($_COOKIE['user_token'])) {
    $email = $_COOKIE['user_email'];
    $token = $_COOKIE['user_token'];
    $hashedToken = hash('sha256', $email . 'bi_angelos_secret_salt');
    if ($token === $hashedToken) {
        $stmt = $pdo->prepare("SELECT email, role FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            header("Location: index.php");
            exit;
        }
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } else {
        $stmt = $pdo->prepare("SELECT email, role FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $controllerName = isset($_POST['controller_name']) ? $_POST['controller_name'] : '';
            if ($user['role'] === 'Controller' && empty($controllerName)) {
                $error = 'Please select who you are (Controller Name).';
            } else {
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                if ($user['role'] === 'Controller') {
                    $_SESSION['controller_name'] = $controllerName;
                }
                
                $token = hash('sha256', $email . 'bi_angelos_secret_salt');
                setcookie('user_email', $email, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                setcookie('user_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                if ($user['role'] === 'Controller') {
                    setcookie('controller_name', $controllerName, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                }
                
                header("Location: index.php");
                exit;
            }
        } else {
            $error = 'Invalid email address.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Bi Angelos Theatre</title>
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
        
        .form-group input[type="email"] {
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
            <?php $isInfoError = strpos($error, 'Controller Name') !== false; ?>
            <div class="<?php echo $isInfoError ? 'info-message' : 'error-message'; ?>">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group" id="emailGroup">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required autofocus 
                       placeholder="...." value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <div class="form-group" id="controllerNameGroup" style="display: none;">
                <label>Who are you? (Controllers only)</label>
                <div style="display: flex; gap: 15px; margin-top: 10px; flex-wrap: wrap;">
                    <label style="font-weight: normal; cursor: pointer;"><input type="radio" name="controller_name" value="Tota"> Tota</label>
                    <label style="font-weight: normal; cursor: pointer;"><input type="radio" name="controller_name" value="Karim"> Karim</label>
                    <label style="font-weight: normal; cursor: pointer;"><input type="radio" name="controller_name" value="Jolie"> Jolie</label>
                    <label style="font-weight: normal; cursor: pointer;"><input type="radio" name="controller_name" value="Yousif"> Yousif</label>
                </div>
            </div>
            
            <button type="submit" class="login-btn">Login</button>
        </form>
        
    </div>
    
    <script>
        const emailInput = document.getElementById('email');
        const controllerGroup = document.getElementById('controllerNameGroup');
        let checkTimeout = null;
        let clickingInGroup = false;

        // Prevent blur from hiding the group when user clicks a radio button
        controllerGroup.addEventListener('mousedown', function() {
            clickingInGroup = true;
        });
        controllerGroup.addEventListener('mouseup', function() {
            clickingInGroup = false;
        });

        function checkRole(email) {
            if (!email) {
                controllerGroup.style.display = 'none';
                return;
            }

            const formData = new FormData();
            formData.append('email', email);

            fetch('check_role.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.role === 'Controller') {
                    controllerGroup.style.display = 'block';
                    document.getElementById('emailGroup').style.display = 'none';
                } else {
                    controllerGroup.style.display = 'none';
                    document.getElementById('emailGroup').style.display = 'block';
                    document.querySelectorAll('input[name="controller_name"]').forEach(r => r.checked = false);
                }
            })
            .catch(() => {
                controllerGroup.style.display = 'none';
            });
        }

        // Check on input with debounce
        emailInput.addEventListener('input', function() {
            clearTimeout(checkTimeout);
            checkTimeout = setTimeout(() => checkRole(this.value.trim()), 400);
        });

        // Check on blur, but skip if the user is clicking within the controller group
        emailInput.addEventListener('blur', function() {
            if (clickingInGroup) return;
            clearTimeout(checkTimeout);
            checkRole(this.value.trim());
        });

        <?php if (!empty($error) && strpos($error, 'Controller Name') !== false): ?>
        // Server indicated this is a Controller that still needs to pick a name
        controllerGroup.style.display = 'block';
        document.getElementById('emailGroup').style.display = 'none';
        <?php endif; ?>
    </script>
</body>
</html>