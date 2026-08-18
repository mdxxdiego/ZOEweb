<?php
session_start();

if (file_exists(__DIR__ . '/config/config.php')) {
    require_once __DIR__ . '/config/config.php';
} else {
    die("Error crítico: El archivo de configuración no existe en " . __DIR__ . "/config/config.php");
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        try {
            if (isset($pdo)) {
                $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ? AND estado = 'activo' LIMIT 1");
                $stmt->execute([$username]);
                $usuario = $stmt->fetch();

                if ($usuario && password_verify($password, $usuario['password'])) {
                    $_SESSION['id'] = $usuario['id'];
                    $_SESSION['username'] = $usuario['username'];
                    $_SESSION['nombre'] = $usuario['nombre'];
                    $_SESSION['apellido'] = $usuario['apellido'];
                    $_SESSION['rol'] = $usuario['rol'];
                    
                    header("Location: index.php?view=dashboard");
                    exit;
                } else {
                    $error = "Usuario o contraseña incorrectos, o cuenta inactiva.";
                }
            } else {
                $error = "Error: La conexión a la base de datos no se inicializó.";
            }
        } catch (PDOException $e) {
            $error = "Error de base de datos: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, complete todos los campos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZOE | Iniciar Sesión</title>
    
    <link rel="icon" type="image/png" href="img/logo.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-bg: #5d75a4;
            --primary-dark: #4a5d85;
            --white: #ffffff;
            --transition-soft: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f4f6f9 0%, #e9ecef 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .login-header {
            background: var(--primary-bg);
            padding: 35px 20px;
            text-align: center;
            color: var(--white);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .logo-circle {
            width: 100px; 
            height: 100px;
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2px; 
            border: 1.5px solid rgba(255,255,255,0.5); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .logo-circle img {
            max-width: 96%;
            max-height: 96%;
            object-fit: contain;
        }

        .login-header p {
            margin: 0;
            opacity: 0.8;
            font-size: 12px;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .login-body {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #777;
            margin-bottom: 8px;
            text-transform: uppercase;
            padding-left: 2px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 15px;
            color: #adb5bd;
            transition: var(--transition-soft);
        }

        .form-control {
            width: 100%;
            padding: 13px 15px 13px 45px;
            border: 2px solid #f2f2f2;
            border-radius: 12px;
            font-size: 15px;
            transition: var(--transition-soft);
            background: #fafafa;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-bg);
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(93, 117, 164, 0.1);
        }

        .form-control:focus + i {
            color: var(--primary-bg);
        }

        .btn-login {
            width: 100%;
            padding: 15px;
            background: var(--primary-bg);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(93, 117, 164, 0.3);
        }

        .error-msg {
            background: #fff5f5;
            color: #e74c3c;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            border-left: 4px solid #e74c3c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-text {
            text-align: center;
            margin-top: 25px;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <div class="logo-circle">
                <img src="img/logo.png" alt="Logo ZOE">
            </div>
            <p>FACTURACIÓN ELECTRÓNICA</p>
        </div>

        <div class="login-body">
            <?php if ($error): ?>
                <div class="error-msg">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label>Usuario</label>
                    <div class="input-wrapper">
                        <input type="text" name="username" class="form-control" placeholder="Nombre de usuario" required autofocus>
                        <i class="fas fa-user"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Contraseña</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                        <i class="fas fa-lock"></i>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    INGRESAR <i class="fas fa-sign-in-alt"></i>
                </button>
            </form>
        </div>
    </div>
    
    <div class="footer-text">
        &copy; <?= date('Y') ?> | Powered by DieDay Soft.
    </div>
</div>

</body>
</html>