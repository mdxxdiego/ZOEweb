<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$posibles_rutas = [
    __DIR__ . '/config.php',
    __DIR__ . '/config/config.php'
];

$config_encontrado = false;
foreach ($posibles_rutas as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $config_encontrado = true;
        break;
    }
}

if (!$config_encontrado) {
    die("Error: No se encontró config.php. Verifique que el archivo exista en la raíz o en la carpeta /config/.");
}

$formas_pago = [];
$error_db = null;

try {
    if (!isset($pdo)) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        // Convertimos a mayúsculas también en PHP por seguridad
        $nombre_mayus = strtoupper(trim($_POST['nombre']));

        if ($_POST['action'] === 'insert') {
            $stmtMax = $pdo->query("SELECT MAX(CAST(cod_pago AS UNSIGNED)) as ultimo FROM formas_pago_sri");
            $resMax = $stmtMax->fetch();
            $siguiente = ($resMax['ultimo'] ?? 0) + 1;
            $nuevo_cod = str_pad($siguiente, 2, "0", STR_PAD_LEFT); 

            $sql = "INSERT INTO formas_pago_sri (cod_pago, nombre, estado) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nuevo_cod, $nombre_mayus, $_POST['estado']]);

        } elseif ($_POST['action'] === 'update') {
            $sql = "UPDATE formas_pago_sri SET nombre = ?, estado = ? WHERE cod_pago = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_mayus, $_POST['estado'], $_POST['cod_pago_hidden']]);

        } elseif ($_POST['action'] === 'toggle_status') {
            $nuevo_estado = ($_POST['estado_actual'] === 'A') ? 'I' : 'A';
            $sql = "UPDATE formas_pago_sri SET estado = ? WHERE cod_pago = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nuevo_estado, $_POST['cod_pago']]);
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?msj=success");
        exit;
    }

    $query = $pdo->query("SELECT * FROM formas_pago_sri ORDER BY CAST(cod_pago AS UNSIGNED) ASC");
    $formas_pago = $query->fetchAll();

} catch (PDOException $e) {
    $error_db = "Error en la base de datos: " . $e->getMessage();
}

if (file_exists('menu.php')) {
    include_once 'menu.php';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión Formas de Pago SRI | ZOE</title>
    <style>
        :root {
            --azul-header: #3f5891;
            --fondo-gris: #f4f6f9;
            --verde-exito: #28a745;
            --azul-info: #17a2b8;
            --rojo-peligro: #dc3545;
        }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; background-color: var(--fondo-gris); font-family: 'Segoe UI', sans-serif; }
        .main-content { flex: 1 0 auto; }
        .full-wrapper { padding: 15px; box-sizing: border-box; max-width: 100%; }
        .card-full { background: #fff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; overflow: hidden; margin-bottom: 20px; }
        .card-full-header { background-color: var(--azul-header); color: white; padding: 15px 20px; font-weight: bold; font-size: 14px; text-transform: uppercase; }
        .barra-superior { display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #fff; border-bottom: 1px solid #eee; gap: 15px; flex-wrap: wrap; }
        .busqueda-container { flex: 1; display: flex; gap: 10px; min-width: 280px; }
        .input-custom { padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; width: 100%; }
        .btn-custom { padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 11px; text-transform: uppercase; color: white; display: inline-flex; align-items: center; text-decoration: none; border: 0; }
        .btn-azul { background: var(--azul-header); }
        .btn-verde { background: var(--verde-exito); }
        .btn-rojo { background: var(--rojo-peligro); }
        .btn-info { background: var(--azul-info); }
        .table-responsive { width: 100%; overflow-x: auto; }
        table.full-width-table { width: 100%; border-collapse: collapse; min-width: 900px; }
        .full-width-table th { text-align: left; background: #fafafa; padding: 15px 12px; border-bottom: 2px solid #eee; color: #555; font-size: 12px; }
        .full-width-table td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; }
        .status-activo { background: #e8f5e9; color: #2e7d32; }
        .status-inactivo { background: #ffebee; color: #c62828; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); overflow-y: auto; }
        .modal-content { background: white; margin: 30px auto; width: 90%; max-width: 500px; border-radius: 8px; }
        .modal-header { background: var(--azul-header); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
        .modal-body { padding: 25px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 12px; color: #333; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        .form-control:disabled { background-color: #e9ecef; color: #6c757d; cursor: not-allowed; }
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
            <div class="card-full-header">Módulo Formas de Pago SRI - Listado General</div>
            
            <div class="barra-superior">
                <div class="busqueda-container">
                    <input type="text" class="input-custom" placeholder="Buscar por nombre o código...">
                    <button class="btn-custom btn-azul">Consultar</button>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn-custom btn-verde" onclick="abrirModal('insert')">+ Nueva Forma de Pago</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="full-width-table">
                    <thead>
                        <tr>
                            <th>COD PAGO</th>
                            <th>NOMBRE FORMA DE PAGO</th>
                            <th>ESTADO</th>
                            <th style="text-align: center;">CAMBIAR ESTADO</th>
                            <th style="text-align: center;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($formas_pago)): ?>
                            <?php foreach ($formas_pago as $row): ?>
                            <tr>
                                <td><strong>#<?= htmlspecialchars($row['cod_pago']) ?></strong></td>
                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                <td>
                                    <span class="status-badge <?= ($row['estado'] === 'A') ? 'status-activo' : 'status-inactivo' ?>">
                                        <?= ($row['estado'] === 'A' ? 'ACTIVO' : 'INACTIVO') ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="cod_pago" value="<?= $row['cod_pago'] ?>">
                                        <input type="hidden" name="estado_actual" value="<?= $row['estado'] ?>">
                                        <button type="submit" class="btn-custom <?= ($row['estado'] === 'A') ? 'btn-rojo' : 'btn-verde' ?>" style="padding: 5px 10px;">
                                            <?= ($row['estado'] === 'A') ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn-custom btn-info" style="padding: 5px 10px;" 
                                            onclick='abrirModal("view", <?= json_encode($row) ?>)'>Ver</button>
                                    <button class="btn-custom" style="background: #ffc107; color: #333; padding: 5px 10px;"
                                            onclick='abrirModal("update", <?= json_encode($row) ?>)'>Editar</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding: 60px; color: #aaa;">No hay registros encontrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="modalFormas" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle">FORMA DE PAGO SRI</span>
            <span style="cursor:pointer; font-size:24px;" onclick="cerrarModal()">&times;</span>
        </div>
        <form id="formPago" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="action" value="insert">
                <input type="hidden" name="cod_pago_hidden" id="cod_pago_hidden">
                
                <div class="form-group">
                    <label>CÓDIGO (AUTO-GENERADO)</label>
                    <input type="text" id="cod_pago_visual" class="form-control" disabled placeholder="El sistema asignará un código">
                </div>

                <div class="form-group">
                    <label>NOMBRE DE LA FORMA DE PAGO</label>
                    <input type="text" name="nombre" id="nombre" class="form-control" required 
                           oninput="this.value = this.value.toUpperCase()">
                </div>

                <div class="form-group">
                    <label>ESTADO</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="A">ACTIVO</option>
                        <option value="I">INACTIVO</option>
                    </select>
                </div>
            </div>
            <div style="padding: 20px; text-align: right; border-top: 1px solid #eee;">
                <button type="button" class="btn-custom" style="background:#888;" onclick="cerrarModal()">Cerrar</button>
                <button type="submit" id="btnSubmit" class="btn-custom btn-verde">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function abrirModal(tipo, data = null) {
        const modal = document.getElementById('modalFormas');
        const form = document.getElementById('formPago');
        const title = document.getElementById('modalTitle');
        const btnSubmit = document.getElementById('btnSubmit');
        const codVisual = document.getElementById('cod_pago_visual');
        const inputNombre = document.getElementById('nombre');
        
        form.reset();
        document.querySelectorAll('.form-control').forEach(el => el.disabled = false);
        codVisual.disabled = true; 
        btnSubmit.style.display = 'inline-flex';

        if (tipo === 'insert') {
            title.innerText = 'NUEVA FORMA DE PAGO';
            document.getElementById('action').value = 'insert';
            codVisual.value = "NUEVO";
        } else if (tipo === 'update') {
            title.innerText = 'EDITAR FORMA DE PAGO';
            document.getElementById('action').value = 'update';
            document.getElementById('cod_pago_hidden').value = data.cod_pago;
            codVisual.value = data.cod_pago;
            inputNombre.value = data.nombre;
            document.getElementById('estado').value = data.estado;
        } else if (tipo === 'view') {
            title.innerText = 'DETALLES DE FORMA DE PAGO';
            codVisual.value = data.cod_pago;
            inputNombre.value = data.nombre;
            document.getElementById('estado').value = data.estado;
            document.querySelectorAll('.form-control').forEach(el => el.disabled = true);
            btnSubmit.style.display = 'none';
        }

        modal.style.display = 'block';

        // Foco automático en el nombre al abrir
        if (tipo !== 'view') {
            setTimeout(() => {
                inputNombre.focus();
            }, 100); 
        }
    }

    function cerrarModal() { document.getElementById('modalFormas').style.display = 'none'; }
    window.onclick = function(event) { if (event.target == document.getElementById('modalFormas')) cerrarModal(); }
</script>

<?php 
if (file_exists('footer.php')) {
    include_once 'footer.php';
}
?>
</body>
</html>