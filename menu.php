<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$nombre_usuario = $_SESSION['nombre'] ?? 'Usuario';
$apellido_usuario = $_SESSION['apellido'] ?? '';
$nombre_completo = strtoupper(trim($nombre_usuario . " " . $apellido_usuario));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; background: #f4f6f9; }
        .mobile-menu-btn { display: none; background: #5d75a4; color: white; padding: 15px 20px; cursor: pointer; font-size: 24px; position: fixed; top: 0; left: 0; width: 100%; z-index: 2000; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .navbar { background: #5d75a4; padding: 0 30px; height: 75px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 20px; position: relative; z-index: 1000; transition: all 0.3s ease; }
        .logo img { height: 55px; width: 55px; display: block; margin-right: 15px; border: 2px solid #ffffff; border-radius: 50%; object-fit: cover; background: rgba(255, 255, 255, 0.1); }
        .menu { list-style: none; display: flex; align-items: center; margin: 0; padding: 0; height: 100%; width: 100%; }
        .menu li { position: relative; height: 100%; display: flex; align-items: center; }
        .menu li a { display: flex; align-items: center; padding: 8px 18px; text-decoration: none; color: rgba(255, 255, 255, 0.9); font-size: 14px; font-weight: 500; transition: all 0.2s ease; white-space: nowrap; gap: 10px; }
        .menu li a:hover { background: rgba(255, 255, 255, 0.15); color: #ffffff; border-radius: 6px; }
        .submenu { display: none; position: absolute; top: 100%; left: 0; min-width: 240px; background: #ffffff; list-style: none; padding: 10px 0; margin: 0; box-shadow: 0 8px 25px rgba(0,0,0,0.15); border-radius: 8px; z-index: 1001; animation: fadeIn 0.2s ease; }
        .submenu li { width: 100%; height: auto; display: block; }
        .submenu li a { color: #444; padding: 12px 20px; width: 100%; justify-content: flex-start; }
        .submenu li a i { color: #5d75a4; font-size: 18px; width: 24px; text-align: center; }
        .submenu li a:hover { background: #f0f4f8; color: #5d75a4; border-radius: 0; }
        .dropdown-submenu:hover > .submenu-right { display: block; }
        .submenu-right { top: 0; left: 100%; margin-left: 2px; }
        .dropdown:hover > .submenu { display: block; }
        .user-info-item { margin-left: auto; }
        .user-info-item a:hover { background: none !important; cursor: default; }
        .separator { color: rgba(255,255,255,0.3); padding: 0 10px; font-size: 20px; font-weight: 100; }
        .arrow-right { margin-left: auto; font-size: 11px; opacity: 0.6; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 1150px) {
            .mobile-menu-btn { display: block; }
            .navbar { display: none; flex-direction: column; padding: 85px 20px 20px 20px; position: fixed; top: 0; left: 0; width: 280px; height: 100vh; overflow-y: auto; align-items: flex-start; }
            .navbar.active { display: flex; }
            .menu { flex-direction: column; height: auto; }
            .menu li { width: 100%; flex-direction: column; align-items: flex-start; height: auto; }
            .dropdown.open > .submenu, .dropdown-submenu.open > .submenu-right { display: block !important; position: static; }
            .submenu, .submenu-right { width: 100%; box-shadow: none; background: rgba(255,255,255,0.05); padding-left: 15px; }
            .submenu li a { color: white; }
            .submenu li a i { color: white; }
            .separator { display: none; }
            .user-info-item { margin-left: 0; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.1); }
            .logo img { margin-bottom: 20px; height: 45px; width: 45px; }
        }
    </style>
</head>
<body>

<div class="mobile-menu-btn" onclick="toggleMenu()">
    <i class="fas fa-bars"></i>
</div>

<nav class="navbar" id="navbar">
    <div class="logo">
        <img src="img/logo.png" alt="Logo">
    </div>

    <ul class="menu">
        <li><a href="/zoe_php/"><i class="fas fa-home"></i> Inicio</a></li>

        <li class="dropdown">
            <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-edit"></i> Registrar</a>
            <ul class="submenu">
                <li><a href="/zoe_php/articulos.php"><i class="fas fa-box"></i> Artículos</a></li>
                <li><a href="/zoe_php/clientes.php"><i class="fas fa-users"></i> Clientes</a></li>
                <li><a href="/zoe_php/proveedores.php"><i class="fas fa-truck"></i> Proveedores</a></li>
                
                <li class="dropdown-submenu">
                    <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-cash-register"></i> Caja <i class="fas fa-chevron-right arrow-right"></i></a>
                    <ul class="submenu submenu-right">
                        <li><a href="/zoe_php/apertura_caja"><i class="fas fa-door-open"></i> Apertura</a></li>
                        <li><a href="/zoe_php/cierre_caja"><i class="fas fa-door-closed"></i> Cierre</a></li>
                        <li><a href="/zoe_php/ingreso_efectivo"><i class="fas fa-arrow-down"></i> Ingreso</a></li>
                        <li><a href="/zoe_php/retiro_efectivo"><i class="fas fa-arrow-up"></i> Retiro</a></li>
                    </ul>
                </li>

                <li><a href="/zoe_php/categorias.php"><i class="fas fa-tags"></i> Categorías</a></li>
                <li><a href="/zoe_php/envases.php"><i class="fas fa-archive"></i> Envases</a></li>
                <!-- ITEM GASTOS AGREGADO -->
                <li><a href="/zoe_php/gastos.php"><i class="fas fa-file-invoice-dollar"></i> Gastos</a></li>
            </ul>
        </li>

        <li class="dropdown">
            <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-shopping-cart"></i> Compras</a>
            <ul class="submenu">
                <li class="dropdown-submenu">
                    <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-shopping-basket"></i> Compras <i class="fas fa-chevron-right arrow-right"></i></a>
                    <ul class="submenu submenu-right">
                        <li><a href="/zoe_php/registrar_compra.php"><i class="fas fa-plus"></i> Registrar Compra</a></li>
                        <li><a href="/zoe_php/compras/consultar"><i class="fas fa-search"></i> Consultar Ventas</a></li>
                        <li><a href="/zoe_php/compras/canceladas"><i class="fas fa-history"></i> Canceladas</a></li>
                    </ul>
                </li>
                <li><a href="/zoe_php/compras/devoluciones"><i class="fas fa-undo"></i> Devoluciones</a></li>
                <li><a href="/zoe_php/compras/pagos-proveedores"><i class="fas fa-money-bill-wave"></i> Pagos</a></li>
            </ul>
        </li>

        <!-- MENÚ VENTAS -->
        <li class="dropdown">
            <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-dollar-sign"></i> Ventas</a>
            <ul class="submenu">
                <li class="dropdown-submenu">
                    <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-file-invoice-dollar"></i> Facturas <i class="fas fa-chevron-right arrow-right"></i></a>
                    <ul class="submenu submenu-right">
                        <li><a href="/zoe_php/registrar_venta.php"><i class="fas fa-file-medical"></i> Registrar Venta</a></li>
                        <li><a href="/zoe_php/consultar_ventas.php"><i class="fas fa-search"></i> Consultar Ventas</a></li>
                        <li><a href="/zoe_php/ventas_eliminadas.php"><i class="fas fa-trash-alt"></i> Ventas Eliminadas</a></li>
                    </ul>
                </li>
                <li class="dropdown-submenu">
                    <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-calculator"></i> Cotizaciones <i class="fas fa-chevron-right arrow-right"></i></a>
                    <ul class="submenu submenu-right">
                        <li><a href="/zoe_php/cotizaciones/registrar"><i class="fas fa-file-signature"></i> Registrar</a></li>
                        <li><a href="/zoe_php/ventas/cotizaciones/consultar"><i class="fas fa-search"></i> Consultar</a></li>
                    </ul>
                </li>
                <li><a href="/zoe_php/ventas/pagos-clientes"><i class="fas fa-hand-holding-usd"></i> Pagos</a></li>
                <li><a href="/zoe_php/ventas_eliminadas.php"><i class="fas fa-trash-alt"></i> Ventas Eliminadas</a></li>
            </ul>
        </li>

        <li class="dropdown">
            <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-chart-line"></i> Reportes</a>
            <ul class="submenu">
                <li class="dropdown-submenu">
                    <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-chart-bar"></i> Ventas <i class="fas fa-chevron-right arrow-right"></i></a>
                    <ul class="submenu submenu-right">
                        <li><a href="/zoe_php/reportes/ventas"><i class="fas fa-list-ol"></i> Listado</a></li>
                        <li><a href="/zoe_php/reportes/ventas/credito"><i class="fas fa-credit-card"></i> Créditos</a></li>
                    </ul>
                </li>
                <li><a href="/zoe_php/reportes/clientes"><i class="fas fa-users"></i> Clientes</a></li>
            </ul>
        </li>

        <li class="dropdown">
            <a href="#" onclick="toggleSubmenu(event, this)"><i class="fas fa-cog"></i> Configuración</a>
            <ul class="submenu">
                <li><a href="/zoe_php/usuario-acceso.php"><i class="fas fa-user-lock"></i> Usuario</a></li>
                <li><a href="/zoe_php/empresa.php"><i class="fas fa-building"></i> Empresa</a></li>
                <li><a href="/zoe_php/iva.php"><i class="fas fa-percent"></i> IVA</a></li>
                <li><a href="/zoe_php/forma_pago_sri.php"><i class="fas fa-credit-card"></i> Formas de Pago SRI</a></li>
            </ul>
        </li>

        <li class="user-info-item">
            <a href="#"><i class="fas fa-user-circle"></i> <?= $nombre_completo ?></a>
        </li>
        <li class="separator">|</li>
        <li>
            <a href="/zoe_php/salir.php">
                <i class="fas fa-power-off" style="color: #ffcccc;"></i> Salir
            </a>
        </li>
    </ul>
</nav>

<script>
    function toggleMenu() {
        const navbar = document.getElementById('navbar');
        navbar.classList.toggle('active');
    }

    function toggleSubmenu(e, element) {
        if (window.innerWidth <= 1150) {
            if (element.nextElementSibling && element.nextElementSibling.classList.contains('submenu')) {
                e.preventDefault();
                element.parentElement.classList.toggle('open');
            }
        }
    }

    document.addEventListener('click', function(event) {
        const navbar = document.getElementById('navbar');
        const btn = document.querySelector('.mobile-menu-btn');
        if (!navbar.contains(event.target) && !btn.contains(event.target)) {
            navbar.classList.remove('active');
            document.querySelectorAll('.dropdown, .dropdown-submenu').forEach(el => {
                el.classList.remove('open');
            });
        }
    });
</script>
</body>
</html>