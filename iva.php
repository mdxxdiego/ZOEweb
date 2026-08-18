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
        die("Error crítico: No se encontró config.php.");
    }
}

$iva_list = [];
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
            $sql = "INSERT INTO iva (iva, porcentaje_iva, estado, id_iva, tarifa) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['iva'], $_POST['porcentaje_iva'], $_POST['estado'], $_POST['id_iva'], $_POST['tarifa']]);
        } elseif ($_POST['action'] === 'update') {
            $sql = "UPDATE iva SET iva = ?, porcentaje_iva = ?, estado = ?, id_iva = ?, tarifa = ? WHERE cod_iva = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['iva'], $_POST['porcentaje_iva'], $_POST['estado'], $_POST['id_iva'], $_POST['tarifa'], $_POST['cod_iva']]);
        } elseif ($_POST['action'] === 'toggle_status') {
            $nuevo_estado = ($_POST['estado_actual'] === 'activo') ? 'inactivo' : 'activo';
            $sql = "UPDATE iva SET estado = ? WHERE cod_iva = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nuevo_estado, $_POST['id']]);
        } elseif ($_POST['action'] === 'delete') {
            $sql = "DELETE FROM iva WHERE cod_iva = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['id']]);
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?msj=success");
        exit;
    }

    if (!empty($search)) {
        $sql = "SELECT * FROM iva WHERE tarifa LIKE ? OR id_iva LIKE ? ORDER BY cod_iva DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$search%", "%$search%"]);
        $iva_list = $stmt->fetchAll();
    } else {
        $query = $pdo->query("SELECT * FROM iva ORDER BY cod_iva DESC");
        $iva_list = $query->fetchAll();
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
    <title>Gestión de IVA | ZOE</title>
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
        .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; text-transform: uppercase; min-width: 70px; text-align: center; }
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
            <div class="card-full-header">Configuración de Impuestos - Tasas de IVA</div>
            
            <div class="barra-superior">
                <form method="GET" class="busqueda-container">
                    <input type="text" name="search" class="input-custom" placeholder="Buscar por tarifa o ID IVA..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn-custom btn-azul">Consultar</button>
                    <?php if(!empty($search)): ?>
                        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn-custom" style="background:#6c757d;">Limpiar</a>
                    <?php endif; ?>
                </form>
                <div>
                    <button class="btn-custom btn-verde" onclick="abrirModal('insert')">+ Nueva Tasa IVA</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="full-width-table">
                    <thead>
                        <tr>
                            <th>CÓDIGO</th>
                            <th>TARIFA</th>
                            <th>VALOR IVA</th>
                            <th>PORCENTAJE</th>
                            <th>ID SRI</th>
                            <th>ESTADO</th>
                            <th style="text-align: center;">CAMBIO ESTADO</th>
                            <th style="text-align: center;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($iva_list)): ?>
                            <?php foreach ($iva_list as $row): ?>
                            <tr>
                                <td><?= $row['cod_iva'] ?></td>
                                <td><strong><?= htmlspecialchars($row['tarifa']) ?></strong></td>
                                <td><?= htmlspecialchars($row['iva']) ?></td>
                                <td><?= htmlspecialchars($row['porcentaje_iva']) ?>%</td>
                                <td><?= htmlspecialchars($row['id_iva']) ?></td>
                                <td>
                                    <span class="status-badge <?= ($row['estado'] === 'activo') ? 'status-activo' : 'status-inactivo' ?>">
                                        <?= htmlspecialchars($row['estado']) ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $row['cod_iva'] ?>">
                                        <input type="hidden" name="estado_actual" value="<?= $row['estado'] ?>">
                                        <button type="submit" class="btn-custom <?= ($row['estado'] === 'activo') ? 'btn-rojo' : 'btn-verde' ?>" style="padding: 5px 10px;">
                                            <?= ($row['estado'] === 'activo') ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn-custom btn-info" style="padding: 5px 10px;" onclick='abrirModal("view", <?= json_encode($row) ?>)'>Ver</button>
                                    <button class="btn-custom" style="background: #ffc107; color: #333; padding: 5px 10px;" onclick='abrirModal("update", <?= json_encode($row) ?>)'>Editar</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar registro?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $row['cod_iva'] ?>">
                                        <button type="submit" class="btn-custom btn-rojo" style="padding: 5px 10px;">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center; padding: 40px; color: #999;">No se encontraron registros de IVA.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="modalIVA" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle">IMPUESTO IVA</span>
            <span style="cursor:pointer; font-size:24px;" onclick="cerrarModal()">&times;</span>
        </div>
        <form id="formIVA" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="action" value="insert">
                <input type="hidden" name="cod_iva" id="cod_iva">
                

                <div class="form-grid">
                    <div class="form-group">
                        <label>TARIFA (Descripción)</label>
                        <input type="text" name="tarifa" id="tarifa" class="form-control" placeholder="Ej: IVA 15%" required>
                    </div>
                    <div class="form-group">
                        <label>VALOR IVA (Decimal)</label>
                        <input type="number" step="0.0001" name="iva" id="iva" class="form-control" placeholder="Ej: 0.1500" required>
                    </div>
                    <div class="form-group">
                        <label>PORCENTAJE (%)</label>
                        <input type="number" step="0.01" name="porcentaje_iva" id="porcentaje_iva" class="form-control" placeholder="Ej: 15" required>
                    </div>
                    <div class="form-group">
                        <label>ID IVA (Código SRI)</label>
                        <input type="text" name="id_iva" id="id_iva" class="form-control" required>
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
        const modal = document.getElementById('modalIVA');
        const form = document.getElementById('formIVA');
        const title = document.getElementById('modalTitle');
        const btnSubmit = document.getElementById('btnSubmit');
        
        form.reset();
        document.querySelectorAll('.form-control').forEach(el => el.disabled = false);
        btnSubmit.style.display = 'inline-flex';

        if (tipo === 'insert') {
            title.innerText = 'REGISTRAR NUEVA TASA IVA';
            document.getElementById('action').value = 'insert';
        } else {
            title.innerText = (tipo === 'update') ? 'EDITAR TASA IVA' : 'DETALLES DE IMPUESTO';
            document.getElementById('action').value = 'update';
            
            document.getElementById('cod_iva').value = data.cod_iva;
            document.getElementById('tarifa').value = data.tarifa;
            document.getElementById('iva').value = data.iva;
            document.getElementById('porcentaje_iva').value = data.porcentaje_iva;
            document.getElementById('id_iva').value = data.id_iva;
            document.getElementById('estado').value = data.estado;

            if (tipo === 'view') {
                document.querySelectorAll('.form-control').forEach(el => el.disabled = true);
                btnSubmit.style.display = 'none';
            }
        }
        modal.style.display = 'block';
    }

    function cerrarModal() { document.getElementById('modalIVA').style.display = 'none'; }
    window.onclick = function(e) { if (e.target == document.getElementById('modalIVA')) cerrarModal(); }
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