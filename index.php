<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['nombre'])) {
    header("Location: /zoe_php/login.php");
    exit();
}

include 'menu.php'; 
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ZOE - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #5d75a4;
            --bg-color: #f4f6f9;
            --text-dark: #334155;
            --white: #ffffff;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-dark);
            margin: 0;
            padding: 0;
        }

        .container {
            padding: 40px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-section {
            margin-bottom: 30px;
        }

        .welcome-section h1 {
            font-size: 28px;
            color: var(--primary-color);
        }

        .welcome-section p {
            color: #64748b;
            font-size: 16px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-bottom: 4px solid transparent;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0,0,0,0.1);
            border-bottom: 4px solid var(--primary-color);
        }

        .card i {
            font-size: 40px;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .card h3 {
            margin: 10px 0 5px 0;
            font-size: 18px;
        }

        .card p {
            font-size: 14px;
            color: #94a3b8;
        }

        @media (max-width: 768px) {
            .container { padding-top: 100px; } 
        }
    </style>
</head>
<body>

<div class="container">
    <header class="welcome-section">
        <h1>Bienvenido, <?php echo htmlspecialchars($nombre_completo); ?></h1>
        <p>Selecciona una opción del menú o usa los accesos rápidos a continuación:</p>
    </header>

    <main class="dashboard-grid">
        <a href="/zoe_php/registrar_venta.php" class="card">
            <i class="fas fa-file-invoice-dollar"></i>
            <h3>Nueva Venta</h3>
            <p>Registrar factura de cliente</p>
        </a>

        <a href="/zoe_php/articulos.php" class="card">
            <i class="fas fa-box"></i>
            <h3>Inventario</h3>
            <p>Gestionar productos y stock</p>
        </a>

        <a href="/zoe_php/apertura_caja" class="card">
            <i class="fas fa-cash-register"></i>
            <h3>Movimientos de Caja</h3>
            <p>Apertura, cierres y flujo</p>
        </a>

        <a href="/zoe_php/reportes/ventas" class="card">
            <i class="fas fa-chart-line"></i>
            <h3>Reportes</h3>
            <p>Ver estadísticas y resultados</p>
        </a>
    </main>
</div>

<?php 

include 'footer.php'; 
?>

</body>
</html>