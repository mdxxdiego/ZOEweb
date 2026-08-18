<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configPath = __DIR__ . '/../config/config.php';

if (file_exists($configPath)) {
    require_once $configPath;
} else {
    $configPath = __DIR__ . '/config/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        die("Error crítico: No se encontró config.php. Verifique que el archivo existe en: " . htmlspecialchars($configPath));
    }
}

$usuarios = [];
$error_db = null;
$search = isset($_GET['search']) ? $_GET['search'] : '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'insert') {
            $pass_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql = "INSERT INTO usuarios (username, password, nombre, apellido, estado, rol) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['username'], $pass_hash, $_POST['nombre'], $_POST['apellido'], $_POST['estado'], $_POST['rol']]);
        } elseif ($_POST['action'] === 'update') {
            if (!empty($_POST['password'])) {
                $pass_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $sql = "UPDATE usuarios SET username = ?, password = ?, nombre = ?, apellido = ?, estado = ?, rol = ? WHERE id = ?";
                $params = [$_POST['username'], $pass_hash, $_POST['nombre'], $_POST['apellido'], $_POST['estado'], $_POST['rol'], $_POST['id']];
            } else {
                $sql = "UPDATE usuarios SET username = ?, nombre = ?, apellido = ?, estado = ?, rol = ? WHERE id = ?";
                $params = [$_POST['username'], $_POST['nombre'], $_POST['apellido'], $_POST['estado'], $_POST['rol'], $_POST['id']];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } elseif ($_POST['action'] === 'toggle_status') {
            $nuevo_estado = ($_POST['estado_actual'] === 'activo') ? 'inactivo' : 'activo';
            $sql = "UPDATE usuarios SET estado = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nuevo_estado, $_POST['id']]);
        } elseif ($_POST['action'] === 'delete') {
            $sql = "DELETE FROM usuarios WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['id']]);
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?msj=success");
        exit;
    }

    if (!empty($search)) {
        $sql = "SELECT * FROM usuarios WHERE username LIKE ? OR nombre LIKE ? OR apellido LIKE ? ORDER BY id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$search%", "%$search%", "%$search%"]);
        $usuarios = $stmt->fetchAll();
    } else {
        $query = $pdo->query("SELECT * FROM usuarios ORDER BY id DESC");
        $usuarios = $query->fetchAll();
    }
} catch (PDOException $e) {
    $error_db = "Error de base de datos: " . $e->getMessage();
}

if (file_exists('../menu.php')) {
    include_once '../menu.php';
} elseif (file_exists('menu.php')) {
    include_once 'menu.php';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios | ZOE</title>
    
    <link rel="shortcut icon" href="img/logo.png" type="image/png">

    <style>
        :root {
            --azul-header: #3f5891;
            --fondo-gris: #f4f6f9;
            --verde-exito: #28a745;
            --azul-info: #17a2b8;
            --rojo-peligro: #dc3545;
        }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; background-color: var(--fondo-gris); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content { flex: 1 0 auto; padding-bottom: 40px; }
        .full-wrapper { padding: 15px; box-sizing: border-box; max-width: 100%; }
        
        .card-full { background: #fff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; overflow: hidden; margin-bottom: 20px; }
        .card-full-header { background-color: var(--azul-header); color: white; padding: 15px 20px; font-weight: bold; font-size: 14px; text-transform: uppercase; }
        
        .barra-superior { display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #fff; border-bottom: 1px solid #eee; gap: 15px; flex-wrap: wrap; }
        .busqueda-container { flex: 1; display: flex; gap: 10px; min-width: 280px; }
        
        .input-custom { padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; width: 100%; }
        .btn-custom { padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 11px; text-transform: uppercase; color: white; display: inline-flex; align-items: center; text-decoration: none; }
        
        .btn-azul { background: var(--azul-header); }
        .btn-verde { background: var(--verde-exito); }
        .btn-rojo { background: var(--rojo-peligro); }
        .btn-info { background: var(--azul-info); }

        .table-responsive { width: 100%; overflow-x: auto; }
        table.full-width-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        .full-width-table th { text-align: left; background: #fafafa; padding: 15px 12px; border-bottom: 2px solid #eee; color: #555; font-size: 12px; }
        .full-width-table td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }

        .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; text-transform: uppercase; }
        .status-activo { background: #e8f5e9; color: #2e7d32; }
        .status-inactivo { background: #ffebee; color: #c62828; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); overflow-y: auto; }
        .modal-content { background: white; margin: 30px auto; width: 90%; max-width: 650px; border-radius: 8px; animation: slideDown 0.3s ease-out; }
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .modal-header { background: var(--azul-header); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
        .modal-body { padding: 25px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 12px; color: #333; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }

        @media (max-width: 600px) { .barra-superior { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>

<div class="main-content">
    <div class="full-wrapper">
        <?php if ($error_db): ?>
            <div style="background:#f8d7da; color:#721c24; padding:15px; border-radius:5px; margin-bottom:20px; border:1px solid #f5c6cb;">
                <strong>Error:</strong> <?= $error_db ?>
            </div>
        <?php endif; ?>

        <div class="card-full">
            <div class="card-full-header">Módulo de Usuarios - Listado General</div>
            
            <div class="barra-superior">
                <form method="GET" class="busqueda-container">
                    <input type="text" name="search" class="input-custom" placeholder="Buscar por username o nombre..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn-custom btn-azul">Consultar</button>
                    <?php if(!empty($search)): ?>
                        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn-custom" style="background:#6c757d;">Limpiar</a>
                    <?php endif; ?>
                </form>
                <div>
                    <button class="btn-custom btn-verde" onclick="abrirModal('insert')">+ Nuevo Usuario</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="full-width-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>USERNAME</th>
                            <th>NOMBRE COMPLETO</th>
                            <th>ROL</th>
                            <th>ESTADO</th>
                            <th style="text-align: center;">CAMBIO ESTADO</th>
                            <th style="text-align: center;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($usuarios)): ?>
                            <?php foreach ($usuarios as $row): ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                                <td><?= htmlspecialchars($row['nombre'] . " " . $row['apellido']) ?></td>
                                <td><?= htmlspecialchars($row['rol']) ?></td>
                                <td>
                                    <span class="status-badge <?= ($row['estado'] === 'activo') ? 'status-activo' : 'status-inactivo' ?>">
                                        <?= htmlspecialchars($row['estado']) ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="estado_actual" value="<?= $row['estado'] ?>">
                                        <button type="submit" class="btn-custom <?= ($row['estado'] === 'activo') ? 'btn-rojo' : 'btn-verde' ?>" style="padding: 5px 10px;">
                                            <?= ($row['estado'] === 'activo') ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn-custom btn-info" style="padding: 5px 10px;" onclick='abrirModal("view", <?= json_encode($row) ?>)'>Ver</button>
                                    <button class="btn-custom" style="background: #ffc107; color: #333; padding: 5px 10px;" onclick='abrirModal("update", <?= json_encode($row) ?>)'>Editar</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar usuario?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn-custom btn-rojo" style="padding: 5px 10px;">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align:center; padding: 40px; color: #999;">No se encontraron registros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="modalUser" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle">USUARIO</span>
            <span style="cursor:pointer; font-size:24px;" onclick="cerrarModal()">&times;</span>
        </div>
        <form id="formUser" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="action" value="insert">
                <input type="hidden" name="id" id="id_user">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>USERNAME</label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>PASSWORD <small id="pass_note"></small></label>
                        <input type="password" name="password" id="password" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>NOMBRE</label>
                        <input type="text" name="nombre" id="nombre" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>APELLIDO</label>
                        <input type="text" name="apellido" id="apellido" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>ROL</label>
                        <select name="rol" id="rol" class="form-control" required>
                            <option value="" disabled selected>Seleccionar...</option>
                            <option value="Sadministrador">Sadministrador</option>
                            <option value="Administrador">Administrador</option>
                            <option value="Vendedor">Vendedor</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ESTADO</label>
                        <select name="estado" id="estado" class="form-control">
                            <option value="activo">ACTIVO</option>
                            <option value="inactivo">INACTIVO</option>
                        </select>
                    </div>
                </div>
            </div>
            <div style="padding: 20px; text-align: right; border-top: 1px solid #eee;">
                <button type="button" class="btn-custom" style="background:#888;" onclick="cerrarModal()">Cerrar</button>
                <button type="submit" id="btnSubmit" class="btn-custom btn-verde">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    function abrirModal(tipo, data = null) {
        const modal = document.getElementById('modalUser');
        const form = document.getElementById('formUser');
        const title = document.getElementById('modalTitle');
        const btnSubmit = document.getElementById('btnSubmit');
        const passNote = document.getElementById('pass_note');
        const passInput = document.getElementById('password');
        
        form.reset();
        document.querySelectorAll('.form-control').forEach(el => el.disabled = false);
        btnSubmit.style.display = 'inline-flex';
        passNote.innerText = "";
        passInput.required = true;

        if (tipo === 'insert') {
            title.innerText = 'REGISTRAR NUEVO USUARIO';
            document.getElementById('action').value = 'insert';
        } else {
            title.innerText = (tipo === 'update') ? 'EDITAR USUARIO' : 'VISTA DETALLADA';
            document.getElementById('action').value = 'update';
            passInput.required = false;
            passNote.innerText = "(Llenar solo para cambiar)";
            
            document.getElementById('id_user').value = data.id;
            document.getElementById('username').value = data.username;
            document.getElementById('nombre').value = data.nombre;
            document.getElementById('apellido').value = data.apellido;
            document.getElementById('rol').value = data.rol;
            document.getElementById('estado').value = data.estado;

            if (tipo === 'view') {
                document.querySelectorAll('.form-control').forEach(el => el.disabled = true);
                btnSubmit.style.display = 'none';
            }
        }
        modal.style.display = 'block';
    }

    function cerrarModal() { document.getElementById('modalUser').style.display = 'none'; }
    window.onclick = function(e) { if (e.target == document.getElementById('modalUser')) cerrarModal(); }
</script>

<?php 
if (file_exists('../footer.php')) {
    include_once '../footer.php';
} elseif (file_exists('footer.php')) {
    include_once 'footer.php';
}
?>
</body>
</html>