<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configPath = realpath(__DIR__ . '/config/config.php');
if ($configPath && file_exists($configPath)) {
    require_once $configPath;
} else {
    die("Error: No se encontró config.php en la ruta: " . __DIR__ . '/config/config.php');
}

$clientes = [];
$clientes_eliminados = [];
$error_db = null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'insert') {
            $sql = "INSERT INTO clientes (nombres, localidad, direccion, telefono, provincia, canton, ci_ruc, tipo_identificacion, correo, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                strtoupper($_POST['nombres']), strtoupper($_POST['localidad']), strtoupper($_POST['direccion']), 
                $_POST['telefono'], $_POST['provincia'], $_POST['canton'], 
                $_POST['ci_ruc'], $_POST['tipo_identificacion'], strtolower($_POST['correo'])
            ]);
        } elseif ($_POST['action'] === 'update') {
            $sql = "UPDATE clientes SET nombres = ?, localidad = ?, direccion = ?, telefono = ?, provincia = ?, canton = ?, ci_ruc = ?, tipo_identificacion = ?, correo = ? 
                    WHERE cod_cliente = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                strtoupper($_POST['nombres']), strtoupper($_POST['localidad']), strtoupper($_POST['direccion']), 
                $_POST['telefono'], $_POST['provincia'], $_POST['canton'], 
                $_POST['ci_ruc'], $_POST['tipo_identificacion'], strtolower($_POST['correo']),
                $_POST['cod_cliente']
            ]);
        } elseif ($_POST['action'] === 'delete') {
            $sql = "UPDATE clientes SET estado = 'INACTIVO' WHERE cod_cliente = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['cod_cliente']]);
        } elseif ($_POST['action'] === 'restore') {
            $sql = "UPDATE clientes SET estado = 'ACTIVO' WHERE cod_cliente = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['cod_cliente']]);
        } elseif ($_POST['action'] === 'perm_delete') {
            $sql = "DELETE FROM clientes WHERE cod_cliente = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['cod_cliente']]);
        } elseif ($_POST['action'] === 'empty_trash') {
            $sql = "DELETE FROM clientes WHERE estado = 'INACTIVO'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?msj=success");
        exit;
    }

    $query = $pdo->query("SELECT * FROM clientes WHERE estado = 'ACTIVO' OR estado IS NULL ORDER BY cod_cliente DESC");
    $clientes = $query->fetchAll();

    $query_del = $pdo->query("SELECT * FROM clientes WHERE estado = 'INACTIVO' ORDER BY cod_cliente DESC");
    $clientes_eliminados = $query_del->fetchAll();

} catch (PDOException $e) {
    $error_db = "Error de base de datos: " . $e->getMessage();
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
    <title>Gestión de Clientes | ZOE</title>
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
        .mayusculas { text-transform: uppercase; }
        .btn-custom { padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 11px; text-transform: uppercase; color: white; display: inline-flex; align-items: center; text-decoration: none; border: 0; }
        .btn-azul { background: var(--azul-header); }
        .btn-verde { background: var(--verde-exito); }
        .btn-rojo { background: var(--rojo-peligro); }
        .btn-info { background: var(--azul-info); }
        .table-responsive { width: 100%; overflow-x: auto; }
        table.full-width-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        .full-width-table th { 
            text-align: left; 
            background: var(--azul-header); /* Cambiado al color del encabezado */
            color: white; /* Texto en blanco para contraste */
            padding: 15px 12px; 
            border-bottom: 2px solid #2a4068; /* Borde más oscuro */
            font-size: 12px; 
            text-transform: uppercase;
        }
        .full-width-table td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); overflow-y: auto; }
        .modal-content { background: white; margin: 30px auto; width: 90%; max-width: 800px; border-radius: 8px; }
        .modal-header { background: var(--azul-header); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
        .modal-body { padding: 25px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 12px; color: #333; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; background: white; }
        .full-width { grid-column: span 2; }
        .loading-api { font-size: 11px; color: var(--azul-info); font-style: italic; display: none; margin-top: 5px; }
        @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } }
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
            <div class="card-full-header">Módulo de Clientes - Listado General</div>
            <div class="barra-superior">
                <div class="busqueda-container">
                    <input type="text" id="txtBuscar" class="input-custom" placeholder="Buscar por nombre o CI/RUC..." onkeyup="filtrarTabla()">
                    <button class="btn-custom btn-azul">Consultar</button>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn-custom btn-rojo" onclick="abrirModalPapelera()">
                        🗑️ Papelera (<?= count($clientes_eliminados) ?>)
                    </button>
                    <button class="btn-custom btn-verde" onclick="abrirModal('insert')">+ Nuevo Cliente</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="full-width-table" id="tablaClientes">
                    <thead>
                        <tr>
                            <th>CÓDIGO</th>
                            <th>IDENTIFICACIÓN</th>
                            <th>NOMBRES Y APELLIDOS</th>
                            <th>TELÉFONO</th>
                            <th>UBICACIÓN</th>
                            <th>CORREO</th>
                            <th style="text-align: center;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($clientes)): foreach ($clientes as $row): ?>
                        <tr>
                            <td><?= $row['cod_cliente'] ?></td>
                            <td><small><?= $row['tipo_identificacion'] ?>:</small><br><strong><?= htmlspecialchars($row['ci_ruc']) ?></strong></td>
                            <td class="mayusculas"><strong><?= htmlspecialchars($row['nombres']) ?></strong></td>
                            <td><?= htmlspecialchars($row['telefono']) ?></td>
                            <td class="mayusculas"><?= htmlspecialchars($row['provincia']) ?> - <?= htmlspecialchars($row['canton']) ?></td>
                            <td><?= htmlspecialchars($row['correo']) ?></td>
                            <td style="text-align: center;">
                                <button class="btn-custom btn-info" style="padding: 5px 10px;" onclick='abrirModal("view", <?= json_encode($row) ?>)'>Ver</button>
                                <button class="btn-custom" style="background: #ffc107; color: #333; padding: 5px 10px;" onclick='abrirModal("update", <?= json_encode($row) ?>)'>Editar</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Mover este cliente a la papelera?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="cod_cliente" value="<?= $row['cod_cliente'] ?>">
                                    <button type="submit" class="btn-custom btn-rojo" style="padding: 5px 10px;">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" style="text-align:center; padding: 60px; color: #aaa;">No hay clientes activos registrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL REGISTRO / EDICIÓN -->
<div id="modalCliente" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle">CLIENTE</span>
            <span style="cursor:pointer; font-size:24px;" onclick="cerrarModal()">&times;</span>
        </div>
        <form id="formCliente" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="action" value="insert">
                <input type="hidden" name="cod_cliente" id="cod_cliente">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>TIPO IDENTIFICACIÓN</label>
                        <select name="tipo_identificacion" id="tipo_identificacion" class="form-control" onchange="limpiarCamposIdentificacion()">
                            <option value="CEDULA">CÉDULA</option>
                            <option value="RUC">RUC</option>
                            <option value="PASAPORTE">PASAPORTE</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>CI / RUC</label>
                        <input type="text" name="ci_ruc" id="ci_ruc" class="form-control" required onblur="ejecutarConsulta()">
                        <span id="loaderApi" class="loading-api">Consultando base de datos oficial...</span>
                    </div>
                    <div class="form-group full-width">
                        <label>NOMBRES COMPLETOS / RAZÓN SOCIAL</label>
                        <input type="text" name="nombres" id="nombres" class="form-control mayusculas" required oninput="convertirMayus(this)">
                    </div>
                    <div class="form-group">
                        <label>PROVINCIA</label>
                        <select name="provincia" id="provincia" class="form-control" onchange="cargarCantones()">
                            <option value="">Seleccione Provincia...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>CANTÓN</label>
                        <select name="canton" id="canton" class="form-control">
                            <option value="">Seleccione Cantón...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>TELÉFONO</label>
                        <input type="text" name="telefono" id="telefono" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>CORREO ELECTRÓNICO</label>
                        <input type="email" name="correo" id="correo" class="form-control" style="text-transform: lowercase;">
                    </div>
                    <div class="form-group">
                        <label>LOCALIDAD / CIUDAD</label>
                        <input type="text" name="localidad" id="localidad" class="form-control mayusculas" oninput="convertirMayus(this)">
                    </div>
                    <div class="form-group">
                        <label>DIRECCIÓN EXACTA</label>
                        <input type="text" name="direccion" id="direccion" class="form-control mayusculas" oninput="convertirMayus(this)">
                    </div>
                </div>
            </div>
            <div style="padding: 20px; text-align: right; border-top: 1px solid #eee;">
                <button type="button" class="btn-custom" style="background:#888;" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" id="btnSubmit" class="btn-custom btn-verde">Guardar Cliente</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PAPELERA DE CLIENTES -->
<div id="modalPapelera" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header" style="background: var(--rojo-peligro);">
            <span>PAPELERA DE CLIENTES ELIMINADOS</span>
            <span style="cursor:pointer; font-size:24px;" onclick="cerrarModalPapelera()">&times;</span>
        </div>
        <div class="modal-body">
            
            <?php if (!empty($clientes_eliminados)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                    <span style="font-size: 13px; color: #666;">
                        Hay <strong><?= count($clientes_eliminados) ?></strong> clientes en estado inactivo.
                    </span>
                    <form method="POST" onsubmit="return confirm('¿ATENCIÓN: Está seguro de eliminar DEFINITIVAMENTE todos los clientes de la papelera? Esta acción no se puede deshacer.')">
                        <input type="hidden" name="action" value="empty_trash">
                        <button type="submit" class="btn-custom btn-rojo">
                            🗑️ Vaciar Papelera
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="full-width-table">
                    <thead>
                        <tr>
                            <th>CÓDIGO</th>
                            <th>IDENTIFICACIÓN</th>
                            <th>NOMBRES Y APELLIDOS</th>
                            <th>TELÉFONO</th>
                            <th style="text-align: center;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($clientes_eliminados)): foreach ($clientes_eliminados as $row_del): ?>
                        <tr>
                            <td><?= $row_del['cod_cliente'] ?></td>
                            <td><small><?= $row_del['tipo_identificacion'] ?>:</small><br><strong><?= htmlspecialchars($row_del['ci_ruc']) ?></strong></td>
                            <td class="mayusculas"><strong><?= htmlspecialchars($row_del['nombres']) ?></strong></td>
                            <td><?= htmlspecialchars($row_del['telefono']) ?></td>
                            <td style="text-align: center;">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Restaurar este cliente?')">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="cod_cliente" value="<?= $row_del['cod_cliente'] ?>">
                                    <button type="submit" class="btn-custom btn-verde" style="padding: 5px 10px;">Restaurar</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar DEFINITIVAMENTE a este cliente?')">
                                    <input type="hidden" name="action" value="perm_delete">
                                    <input type="hidden" name="cod_cliente" value="<?= $row_del['cod_cliente'] ?>">
                                    <button type="submit" class="btn-custom btn-rojo" style="padding: 5px 10px;">Eliminar Definitivo</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" style="text-align:center; padding: 40px; color: #aaa;">La papelera está vacía.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div style="padding: 15px 20px; text-align: right; border-top: 1px solid #eee;">
            <button type="button" class="btn-custom" style="background:#888;" onclick="cerrarModalPapelera()">Cerrar</button>
        </div>
    </div>
</div>

<script>
    const TOKEN_CEDULA = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJlbXByZXNhIjoiRGllZ28gRGFycXVlYSIsImlhdCI6MTc3NjM3MjMyMSwiZXhwIjoxODA3OTI5OTIxfQ.Bx-DR1bYmMrCHrILH-gE9f40IbsdAQ-9-ilodqcJV00";
    const TOKEN_RUC    = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJlbXByZXNhIjoiRGllZ28gRGFycXVlYSIsImlhdCI6MTc3NjM3Mjk5OSwiZXhwIjoxODA3OTMwNTk5fQ.65Wm3h5m7N_R_bJzL8jJ12O4OWWWGr-I2e6neDe8Ah0";

    function convertirMayus(e) {
        e.value = e.value.toUpperCase();
    }

    function limpiarCamposIdentificacion() {
        document.getElementById('ci_ruc').value = '';
        document.getElementById('nombres').value = '';
    }

    function ejecutarConsulta() {
        const tipo = document.getElementById('tipo_identificacion').value;
        const valor = document.getElementById('ci_ruc').value.trim();
        const loader = document.getElementById('loaderApi');

        if (valor === "") return;

        if (tipo === 'CEDULA' && valor.length === 10) {
            consultarData(`https://apicedula.socket-studio.com/consulta-cedula/consulta/${valor}`, TOKEN_CEDULA);
        } else if (tipo === 'RUC' && valor.length === 13) {
            consultarData(`https://apiruc.socket-studio.com/api/ruc/consulta/free/${valor}`, TOKEN_RUC);
        }
    }

    function consultarData(url, token) {
        const loader = document.getElementById('loaderApi');
        loader.style.display = 'block';

        fetch(url, {
            headers: { "Authorization": `Bearer ${token}` }
        })
        .then(r => r.json())
        .then(data => {
            loader.style.display = 'none';
            const nombre = data.nombres || data.razon_social || data.nombre_comercial || "";
            const provincia = data.provincia || "";
            const canton = data.ciudad || data.canton || "";
            const direccion = data.direccion || data.direccion_completa || "";

            if (nombre) document.getElementById('nombres').value = nombre.toUpperCase();
            if (provincia) {
                document.getElementById('provincia').value = provincia.toUpperCase();
                cargarCantones(canton.toUpperCase());
            }
            if (direccion) document.getElementById('direccion').value = direccion.toUpperCase();
        })
        .catch(e => {
            console.error("Error en consulta:", e);
            loader.style.display = 'none';
        });
    }

    const ecuadorData = {
        "AZUAY": ["CUENCA", "GUALACEO", "PAUTE", "SIGSIG", "CHORDELEG", "GIRON", "NABON", "SANTA ISABEL", "PUCARA", "CAMILO PONCE ENRIQUEZ"],
        "BOLIVAR": ["GUARANDA", "CHILLANES", "CHIMBO", "ECHEANDIA", "SAN MIGUEL", "CALUMA", "LAS NAVES"],
        "CAÑAR": ["AZOGUES", "BIBLIAN", "CAÑAR", "LA TRONCAL", "EL TAMBO", "DELEG", "SUSCAL"],
        "CARCHI": ["TULCAN", "BOLIVAR", "ESPEJO", "MIRA", "MONTUFAR", "HUACA"],
        "CHIMBORAZO": ["RIOBAMBA", "ALAUSI", "COLTA", "CHAMBO", "CHUNCHI", "GUAMOTE", "GUANO", "PALLATANGA", "PENIPE", "CUMANDA"],
        "COTOPAXI": ["LATACUNGA", "LA MANA", "PANGUA", "PUJILI", "SALCEDO", "SAQUISILI", "SIGCHOS"],
        "EL ORO": ["MACHALA", "ARENILLAS", "ATAHUALPA", "BALSAS", "CHILLA", "EL GUABO", "HUAQUILLAS", "MARCABELI", "PASAJE", "PIÑAS", "PORTOVELO", "SANTA ROSA", "ZARUMA", "LAS LAJAS"],
        "ESMERALDAS": ["ESMERALDAS", "ELOY ALFARO", "MUISNE", "QUININDE", "SAN LORENZO", "ATACAMES", "RIOVERDE", "LA CONCORDIA"],
        "GUAYAS": ["GUAYAQUIL", "ALFREDO BAQUERIZO MORENO", "BALAO", "BALZAR", "COLIMES", "DAULE", "DURAN", "EL EMPALME", "EL TRIUNFO", "MILAGRO", "NARANJAL", "NARANJITO", "PALESTINA", "PEDRO CARBO", "SAMBORONDON", "SANTA LUCIA", "SALITRE", "SAN JACINTO DE YAGUACHI", "PLAYAS", "SIMON BOLIVAR", "CORONEL MARCELINO MARIDUEÑA", "LOMAS DE SARGENTILLO", "NOBOL", "GENERAL ANTONIO ELIZALDE", "ISIDRO AYORA"],
        "IMBABURA": ["IBARRA", "ANTONIO ANTE", "COTACACHI", "OTAVALO", "PIMAMPIRO", "SAN MIGUEL DE URCUQUI"],
        "LOJA": ["LOJA", "CALVAS", "CATAMAYO", "CELICA", "CHAGUARPAMBA", "ESPINDOLA", "GONZANAMA", "MACARA", "PALTAS", "PUYANGO", "SARAGURO", "SOZORANGA", "ZAPOTILLO", "PINDAL", "QUILANGA", "OLMEDO"],
        "LOS RIOS": ["BABAHOYO", "BABA", "MONTALVO", "PUEBLOVIEJO", "QUEVEDO", "URDANETA", "VENTANAS", "VINCES", "PALENQUE", "BUENA FE", "VALENCIA", "MOCACHE", "QUINSALOMA"],
        "MANABI": ["PORTOVIEJO", "BOLIVAR", "CHONE", "EL CARMEN", "FLAVIO ALFARO", "JIPIJAPA", "JUNIN", "MANTA", "MONTECRISTI", "PAJAN", "PICHINCHA", "ROCAFUERTE", "SANTA ANA", "SUCRE", "TOSAGUA", "24 DE MAYO", "PEDERNALES", "OLMEDO", "PUERTO LOPEZ", "JAMA", "JARAMIJO", "SAN VICENTE"],
        "MORONA SANTIAGO": ["MACAS", "GUALAQUIZA", "LIMON INDANZA", "PALORA", "SANTIAGO", "SUCUA", "HUAMBOYA", "SAN JUAN BOSCO", "TAISHA", "LOGROÑO", "PABLO SEXTO", "TIWINTZA"],
        "NAPO": ["TENA", "ARCHIDONA", "EL CHACO", "QUIJOS", "CARLOS JULIO AROSEMENA TOLA"],
        "PASTAZA": ["PUYO", "MERA", "SANTA CLARA", "ARAJUNO"],
        "PICHINCHA": ["QUITO", "CAYAMBE", "MEJIA", "PEDRO MONCAYO", "RUMIÑAHUI", "SAN MIGUEL DE LOS BANCOS", "PEDRO VICENTE MALDONADO", "PUERTO QUITO"],
        "TUNGURAHUA": ["AMBATO", "BAÑOS DE AGUA SANTA", "CEVALLOS", "MOCHA", "PATATE", "QUERO", "SAN PEDRO DE PELILEO", "SANTIAGO DE PILLARO", "TISALEO"],
        "ZAMORA CHINCHIPE": ["ZAMORA", "CHINCHIPE", "NANGARITZA", "YACUAMBI", "YANTZAZA", "EL PANGUI", "CENTINELA DEL CONDOR", "PALANDA", "PAQUISHA"],
        "GALAPAGOS": ["SAN CRISTOBAL", "ISABELA", "SANTA CRUZ"],
        "SUCUMBIOS": ["NUEVA LOJA", "CASSCALES", "CUYABENO", "LAGO AGRIO", "PUTUMAYO", "SHUSHUFINDI", "SUCUMBIOS", "GONZALOP IZARRO"],
        "ORELLANA": ["FRANCISCO DE ORELLANA", "AGUARICO", "LA JOYA DE LOS SACHAS", "LORETO"],
        "SANTO DOMINGO DE LOS TSACHILAS": ["SANTO DOMINGO"],
        "SANTA ELENA": ["SANTA ELENA", "LA LIBERTAD", "SALINAS"]
    };

    function inicializarProvincias() {
        const selectProv = document.getElementById('provincia');
        selectProv.innerHTML = '<option value="">Seleccione Provincia...</option>';
        Object.keys(ecuadorData).sort().forEach(prov => {
            const opt = document.createElement('option');
            opt.value = prov;
            opt.text = prov;
            selectProv.add(opt);
        });
    }

    function cargarCantones(cantonSeleccionado = "") {
        const prov = document.getElementById('provincia').value;
        const selectCant = document.getElementById('canton');
        selectCant.innerHTML = '<option value="">Seleccione Cantón...</option>';
        if (prov && ecuadorData[prov]) {
            ecuadorData[prov].sort().forEach(canton => {
                const opt = document.createElement('option');
                opt.value = canton;
                opt.text = canton;
                if (canton === cantonSeleccionado) opt.selected = true;
                selectCant.add(opt);
            });
        }
    }

    function abrirModal(tipo, data = null) {
        inicializarProvincias();
        const modal = document.getElementById('modalCliente');
        const form = document.getElementById('formCliente');
        const title = document.getElementById('modalTitle');
        const btnSubmit = document.getElementById('btnSubmit');
        form.reset();
        document.querySelectorAll('.form-control').forEach(el => el.disabled = false);
        btnSubmit.style.display = 'inline-flex';

        if (tipo === 'insert') {
            title.innerText = 'REGISTRAR NUEVO CLIENTE';
            document.getElementById('action').value = 'insert';
        } else if (tipo === 'update' || tipo === 'view') {
            title.innerText = (tipo === 'update') ? 'EDITAR DATOS DE CLIENTE' : 'FICHA DEL CLIENTE';
            document.getElementById('action').value = 'update';
            document.getElementById('cod_cliente').value = data.cod_cliente;
            document.getElementById('tipo_identificacion').value = data.tipo_identificacion;
            document.getElementById('ci_ruc').value = data.ci_ruc;
            document.getElementById('nombres').value = data.nombres;
            document.getElementById('telefono').value = data.telefono;
            document.getElementById('correo').value = data.correo;
            document.getElementById('provincia').value = data.provincia;
            cargarCantones(data.canton);
            document.getElementById('localidad').value = data.localidad;
            document.getElementById('direccion').value = data.direccion;
            if (tipo === 'view') {
                document.querySelectorAll('.form-control').forEach(el => el.disabled = true);
                btnSubmit.style.display = 'none';
            }
        }
        modal.style.display = 'block';
    }

    function cerrarModal() { document.getElementById('modalCliente').style.display = 'none'; }
    function abrirModalPapelera() { document.getElementById('modalPapelera').style.display = 'block'; }
    function cerrarModalPapelera() { document.getElementById('modalPapelera').style.display = 'none'; }

    function filtrarTabla() {
        let input = document.getElementById("txtBuscar").value.toUpperCase();
        let rows = document.getElementById("tablaClientes").getElementsByTagName("tr");
        for (let i = 1; i < rows.length; i++) {
            rows[i].style.display = rows[i].innerText.toUpperCase().includes(input) ? "" : "none";
        }
    }

    window.onclick = function(event) { 
        if (event.target == document.getElementById('modalCliente')) cerrarModal(); 
        if (event.target == document.getElementById('modalPapelera')) cerrarModalPapelera(); 
    }
</script>

<?php if (file_exists('footer.php')) include_once 'footer.php'; ?>
</body>
</html>