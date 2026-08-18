<?php
$configPath = realpath(__DIR__ . '/config/config.php');
if ($configPath && file_exists($configPath)) {
    require_once $configPath;
} else {
    die("Error: No se encontró config.php en la ruta: " . __DIR__ . '/config/config.php');
}

$articulos = [];
$articulos_eliminados = [];
$categorias = []; 
$envases = [];
$ivas = []; 
$proveedores = []; 
$error_db = null;

$por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) { $pagina_actual = 1; }

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        if ($_POST['action'] === 'delete' && isset($_POST['id_articulo'])) {
            $stmt = $pdo->prepare("UPDATE articulos SET estado = 'INACTIVO' WHERE id = ?");
            $stmt->execute([$_POST['id_articulo']]);
            header("Location: articulos.php?pagina=" . $pagina_actual . "&msj=deleted");
            exit;
        }

        if ($_POST['action'] === 'restore' && isset($_POST['id_articulo'])) {
            $stmt = $pdo->prepare("UPDATE articulos SET estado = 'ACTIVO' WHERE id = ?");
            $stmt->execute([$_POST['id_articulo']]);
            header("Location: articulos.php?pagina=" . $pagina_actual . "&msj=restored");
            exit;
        }

        if ($_POST['action'] === 'perm_delete' && isset($_POST['id_articulo'])) {
            $stmt = $pdo->prepare("DELETE FROM articulos WHERE id = ?");
            $stmt->execute([$_POST['id_articulo']]);
            header("Location: articulos.php?pagina=" . $pagina_actual . "&msj=perm_deleted");
            exit;
        }

        if ($_POST['action'] === 'empty_trash') {
            $stmt = $pdo->prepare("DELETE FROM articulos WHERE estado = 'INACTIVO'");
            $stmt->execute();
            header("Location: articulos.php?pagina=" . $pagina_actual . "&msj=trash_emptied");
            exit;
        }

        $action = $_POST['action'];
        $id = $_POST['id_articulo'] ?? null;

        $params = [
            $_POST['nombre'], $_POST['cod_articulo'], $_POST['categoria'],
            $_POST['lote'] ?? '', $_POST['envase'] ?? 'UNIDAD', $_POST['cantidad'] ?? 0, 
            $_POST['proveedor'], $_POST['unidades_caja'] ?? 1, $_POST['precio_unitario'] ?? 0, 
            $_POST['compra_sin_iva'] ?? 0, $_POST['compra_con_iva'] ?? 0, $_POST['marca'] ?? '', 
            $_POST['porcentaje_iva'] ?? 0, $_POST['venta_sin_iva'] ?? 0, $_POST['venta_con_iva'] ?? 0,
            $_POST['precio2'] ?? 0, $_POST['precio3'] ?? 0, $_POST['precio4'] ?? 0, 
            $_POST['vencimiento'] ?: null
        ];

        if ($action === 'insert') {
            $sql = "INSERT INTO articulos (nombre, cod_articulo, categoria, lote, envase, cantidad, proveedor, unidades_caja, precio_unidad, compra_sin_iva, compra_con_iva, marca, porcentaje_iva, venta_sin_iva, venta_con_iva, precio2, precio3, precio4, vencimiento, ultima_actualizacion, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'ACTIVO')";
        } else if ($action === 'update') {
            $sql = "UPDATE articulos SET nombre=?, cod_articulo=?, categoria=?, lote=?, envase=?, cantidad=?, proveedor=?, unidades_caja=?, precio_unidad=?, compra_sin_iva=?, compra_con_iva=?, marca=?, porcentaje_iva=?, venta_sin_iva=?, venta_con_iva=?, precio2=?, precio3=?, precio4=?, vencimiento=?, ultima_actualizacion=NOW() WHERE id=?";
            $params[] = $id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        header("Location: articulos.php?pagina=" . $pagina_actual . "&msj=success");
        exit;
    }

    $total_articulos = $pdo->query("SELECT COUNT(*) FROM articulos WHERE estado != 'INACTIVO' OR estado IS NULL")->fetchColumn();
    $total_paginas = ceil($total_articulos / $por_pagina);

    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
    }

    $offset = ($pagina_actual - 1) * $por_pagina;

    $stmt = $pdo->prepare("SELECT * FROM articulos WHERE estado != 'INACTIVO' OR estado IS NULL ORDER BY id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $articulos = $stmt->fetchAll();

    $articulos_eliminados = $pdo->query("SELECT * FROM articulos WHERE estado = 'INACTIVO' ORDER BY id DESC")->fetchAll();

    $categorias = $pdo->query("SELECT nombre FROM categoria WHERE estado IN ('ACTIVO', 'Activo') ORDER BY nombre ASC")->fetchAll();
    $envases = $pdo->query("SELECT nombre FROM envases WHERE estado IN ('ACTIVO', 'Activo') ORDER BY nombre ASC")->fetchAll();
    $ivas = $pdo->query("SELECT tarifa, porcentaje_iva FROM iva WHERE estado IN ('ACTIVO', 'Activo') ORDER BY tarifa ASC")->fetchAll();
    $proveedores = $pdo->query("SELECT empresa, ci_ruc FROM proveedor WHERE estado IN ('ACTIVO', 'Activo') ORDER BY id_proveedor ASC")->fetchAll();

} catch (PDOException $e) {
    $error_db = "Error de base de datos: " . $e->getMessage();
}

if (file_exists('menu.php')) include_once 'menu.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Artículos | ZOE</title>
    <style>
        :root { --azul-header: #3f5891; --fondo-gris: #f4f6f9; --verde-exito: #28a745; --azul-info: #17a2b8; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; background-color: var(--fondo-gris); font-family: 'Segoe UI', sans-serif; }
        .main-content { flex: 1 0 auto; }
        .full-wrapper { padding: 15px; }
        .card-full { background: #fff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; }
        .card-full-header { background-color: var(--azul-header); color: white; padding: 15px 20px; font-weight: bold; }
        .barra-superior { display: flex; justify-content: space-between; padding: 20px; border-bottom: 1px solid #eee; gap: 15px; }
        .input-custom { padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 100%; }
        .btn-custom { padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; color: white; text-transform: uppercase; font-size: 13px; }
        .btn-azul { background: var(--azul-header); }
        .btn-verde { background: var(--verde-exito); }
        .btn-rojo { background: #dc3545; }
        
        .btn-accion { padding: 5px 10px; border: none; border-radius: 4px; color: white; font-weight: bold; cursor: pointer; font-size: 11px; text-decoration: none; display: inline-block; }
        .btn-ver { background-color: #17a2b8; }
        .btn-editar { background-color: #ffc107; color: #000; }
        .btn-eliminar { background-color: #dc3545; }
        .btn-restaurar { background-color: #28a745; }
        .contenedor-acciones { display: flex; gap: 5px; justify-content: center; }

        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th { 
            text-align: left; 
            background: var(--azul-header); /* Cambiado al color del encabezado */
            color: white; /* Texto en blanco para contraste */
            padding: 15px; 
            font-size: 11px; 
            border-bottom: 2px solid #2a4068; 
            text-transform: uppercase; 
        }
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 12px; }
        
        .paginacion { display: flex; justify-content: center; align-items: center; gap: 5px; padding: 15px; background: #fff; border-top: 1px solid #eee; }
        .paginacion a, .paginacion span { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; color: var(--azul-header); font-weight: bold; border-radius: 4px; font-size: 12px; }
        .paginacion a:hover { background-color: var(--azul-header); color: white; }
        .paginacion .activa { background-color: var(--azul-header); color: white; border-color: var(--azul-header); }
        .paginacion .deshabilitado { color: #ccc; border-color: #eee; cursor: not-allowed; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); overflow-y: auto; }
        .modal-content { background: white; margin: 20px auto; width: 95%; max-width: 1000px; border-radius: 8px; }
        .modal-header { background: var(--azul-header); color: white; padding: 15px; display: flex; justify-content: space-between; }
        .modal-body { padding: 20px; }
        .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        .form-group label { display: block; font-size: 11px; font-weight: bold; margin-bottom: 5px; color: #555; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .form-control[readonly] { background-color: #e9ecef; cursor: not-allowed; font-weight: bold; color: #2c3e50; }
        .span-2 { grid-column: span 2; }
        .opciones-visibilidad { grid-column: span 4; display: flex; flex-wrap: wrap; gap: 20px; background: #f0f2f5; padding: 10px; border-radius: 5px; font-size: 12px; font-weight: bold; }
        .campo-opcional { display: none; }
        .precio-extra { display: none; }
        .btn-mas-precios { background: #6c757d; color: white; border-radius: 50%; width: 30px; height: 30px; border: none; cursor: pointer; margin-top: 18px; }
        .grupo-empaque { grid-column: span 4; display: none; grid-template-columns: repeat(3, 1fr); gap: 15px; background: #fffdf0; padding: 15px; border: 1px dashed #decb81; border-radius: 5px; }
        .header-field { display: flex; justify-content: space-between; align-items: center; }
        .check-mins-inline { font-size: 10px; color: #3f5891; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>

<div class="main-content">
    <div class="full-wrapper">
        <div class="card-full">
            <div class="card-full-header">Módulo de Artículos</div>
            <div class="barra-superior">
                <div style="flex:1; display:flex; gap:10px;">
                    <input type="text" class="input-custom" placeholder="Buscar artículo...">
                    <button class="btn-custom btn-azul">Consultar</button>
                </div>
                <div style="display:flex; gap:10px;">
                    <button class="btn-custom btn-rojo" onclick="abrirModalPapelera()">
                        🗑️ Papelera (<?= count($articulos_eliminados) ?>)
                    </button>
                    <button class="btn-custom btn-verde" onclick="abrirModal('nuevo')">+ Nuevo Artículo</button>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>CÓDIGO</th>
                            <th>NOMBRE</th>
                            <th>CATEGORÍA</th>
                            <th>PROVEEDOR</th>
                            <th>STOCK</th>
                            <th>P. VENTA</th>
                            <th style="text-align: center;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($articulos)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:20px; color:#777;">No hay artículos registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($articulos as $row): ?>
                            <tr id="fila-<?= $row['id'] ?>" data-info='<?= json_encode($row, JSON_HEX_APOS) ?>'>
                                <td><?= $row['id'] ?></td>
                                <td><strong><?= htmlspecialchars($row['cod_articulo']) ?></strong></td>
                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                <td><?= htmlspecialchars($row['categoria']) ?></td>
                                <td><?= htmlspecialchars($row['proveedor']) ?></td>
                                <td><?= $row['cantidad'] ?></td>
                                <td>$<?= number_format($row['venta_con_iva'], 2) ?></td>
                                <td>
                                    <div class="contenedor-acciones">
                                        <button class="btn-accion btn-ver" onclick="verArticulo(<?= $row['id'] ?>)">VER</button>
                                        <button class="btn-accion btn-editar" onclick="editarArticulo(<?= $row['id'] ?>)">EDITAR</button>
                                        <button class="btn-accion btn-eliminar" onclick="eliminarArticulo(<?= $row['id'] ?>)">ELIMINAR</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_paginas > 1): ?>
            <div class="paginacion">
                <?php if ($pagina_actual > 1): ?>
                    <a href="articulos.php?pagina=<?= $pagina_actual - 1 ?>">&laquo; Anterior</a>
                <?php else: ?>
                    <span class="deshabilitado">&laquo; Anterior</span>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                    <?php if ($i == $pagina_actual): ?>
                        <span class="activa"><?= $i ?></span>
                    <?php else: ?>
                        <a href="articulos.php?pagina=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="articulos.php?pagina=<?= $pagina_actual + 1 ?>">Siguiente &raquo;</a>
                <?php else: ?>
                    <span class="deshabilitado">Siguiente &raquo;</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<div id="modalArticulo" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle">REGISTRO DE ARTÍCULO</span>
            <span style="cursor:pointer; font-size:24px;" onclick="cerrarModal()">&times;</span>
        </div>
        <form method="POST" id="formArticulo">
            <div class="modal-body">
                <input type="hidden" name="action" id="form_action" value="insert">
                <input type="hidden" name="id_articulo" id="id_articulo">

                <div class="form-grid">
                    <div class="opciones-visibilidad">
                        <label><input type="checkbox" id="chk_marca" onclick="toggleCampo('div_marca', this)"> Mostrar Marca</label>
                        <label><input type="checkbox" id="chk_lote" onclick="toggleCampo('div_lote', this)"> Mostrar Lote</label>
                        <label><input type="checkbox" id="chk_vencimiento" onclick="toggleCampo('div_vencimiento', this)"> Mostrar Vencimiento</label>
                        <label style="color: #d9534f;"><input type="checkbox" id="chk_empaque" onclick="toggleCampo('div_grupo_empaque', this)"> Mostrar Datos de Empaque</label>
                    </div>

                    <div class="form-group span-2">
                        <div class="header-field">
                            <label>NOMBRE / DESCRIPCIÓN (MAYÚSCULAS)</label>
                            <label class="check-mins-inline">
                                <input type="checkbox" id="checkMins" onclick="controlMayusculas()"> Desactivar Mayúsculas
                            </label>
                        </div>
                        <input type="text" name="nombre" id="inputNombre" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>CÓDIGO ARTÍCULO</label>
                        <input type="text" name="cod_articulo" id="input_cod" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>CATEGORÍA</label>
                        <select name="categoria" id="input_cat" class="form-control">
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['nombre']) ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="div_grupo_empaque" class="grupo-empaque">
                        <div class="form-group">
                            <label>ENVASE</label>
                            <select name="envase" id="input_envase" class="form-control">
                                <?php foreach ($envases as $env): 
                                    $sel = (strtoupper($env['nombre']) == 'UNIDAD') ? 'selected' : '';
                                ?>
                                    <option value="<?= htmlspecialchars($env['nombre']) ?>" <?= $sel ?>><?= htmlspecialchars($env['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>UNIDADES POR CAJA / ENVASE</label>
                            <input type="number" name="unidades_caja" id="input_unidades" class="form-control" value="1">
                        </div>
                        <div class="form-group">
                            <label>PRECIO UNITARIO</label>
                            <input type="number" step="0.01" name="precio_unitario" id="input_precio_u" class="form-control" value="0.00">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>CANTIDAD (STOCK)</label>
                        <input type="number" name="cantidad" id="input_stock" class="form-control" value="0">
                    </div>

                    <div class="form-group">
                        <label>PROVEEDOR</label>
                        <select name="proveedor" id="input_prov" class="form-control" required>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= htmlspecialchars($prov['empresa']) ?>">
                                    <?= htmlspecialchars($prov['empresa']) ?> (<?= htmlspecialchars($prov['ci_ruc']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group campo-opcional" id="div_marca">
                        <label>MARCA</label>
                        <input type="text" name="marca" id="input_marca" class="form-control">
                    </div>
                    <div class="form-group campo-opcional" id="div_lote">
                        <label>LOTE</label>
                        <input type="text" name="lote" id="input_lote" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>COMPRA SIN IVA (AUTO)</label>
                        <input type="number" step="0.000001" name="compra_sin_iva" id="compra_sin_iva" class="form-control" value="0.00" readonly>
                    </div>
                    <div class="form-group">
                        <label>COMPRA CON IVA</label>
                        <input type="number" step="0.01" name="compra_con_iva" id="compra_con_iva" class="form-control" value="0.00" oninput="calcularPrecios()">
                    </div>

                    <div class="form-group">
                        <label>% IVA</label>
                        <select name="porcentaje_iva" id="porcentaje_iva" class="form-control" onchange="calcularPrecios()">
                            <?php foreach ($ivas as $iva_row): ?>
                                <option value="<?= htmlspecialchars($iva_row['porcentaje_iva']) ?>">
                                    <?= htmlspecialchars($iva_row['tarifa']) ?> (<?= (float)$iva_row['porcentaje_iva'] ?>%)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>VENTA SIN IVA (AUTO)</label>
                        <input type="number" step="0.000001" name="venta_sin_iva" id="venta_sin_iva" class="form-control" value="0.00" readonly>
                    </div>

                    <div class="form-group">
                        <label>VENTA CON IVA</label>
                        <input type="number" step="0.01" name="venta_con_iva" id="venta_con_iva" class="form-control" value="0.00" oninput="calcularPrecios()">
                    </div>
                    
                    <div class="form-group" style="display:flex; align-items:center; justify-content:center;">
                        <button type="button" id="btnPlus" class="btn-mas-precios" onclick="agregarPrecio()">+</button>
                    </div>

                    <div class="form-group precio-extra" id="div_precio2"><label>PRECIO 2</label><input type="number" step="0.01" name="precio2" id="input_p2" class="form-control" value="0.00"></div>
                    <div class="form-group precio-extra" id="div_precio3"><label>PRECIO 3</label><input type="number" step="0.01" name="precio3" id="input_p3" class="form-control" value="0.00"></div>
                    <div class="form-group precio-extra" id="div_precio4"><label>PRECIO 4</label><input type="number" step="0.01" name="precio4" id="input_p4" class="form-control" value="0.00"></div>

                    <div class="form-group campo-opcional" id="div_vencimiento">
                        <label>FECHA VENCIMIENTO</label>
                        <input type="date" name="vencimiento" id="input_vence" class="form-control">
                    </div>
                </div>
            </div>
            <div id="modalFooter" style="padding: 20px; text-align: right; border-top: 1px solid #eee;">
                <button type="button" class="btn-custom" style="background:#888;" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" id="btnGuardar" class="btn-custom btn-verde">Guardar Artículo</button>
            </div>
        </form>
    </div>
</div>

<div id="modalPapelera" class="modal">
    <div class="modal-content">
        <div class="modal-header" style="background: #dc3545;">
            <span>PAPELERA DE ARTÍCULOS ELIMINADOS</span>
            <span style="cursor:pointer; font-size:24px;" onclick="cerrarModalPapelera()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>CÓDIGO</th>
                            <th>NOMBRE</th>
                            <th>CATEGORÍA</th>
                            <th>PROVEEDOR</th>
                            <th>P. VENTA</th>
                            <th style="text-align: center;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($articulos_eliminados)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px; font-weight: bold; color: #777;">
                                    No hay artículos en la papelera.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($articulos_eliminados as $row_del): ?>
                            <tr>
                                <td><?= $row_del['id'] ?></td>
                                <td><strong><?= htmlspecialchars($row_del['cod_articulo']) ?></strong></td>
                                <td><?= htmlspecialchars($row_del['nombre']) ?></td>
                                <td><?= htmlspecialchars($row_del['categoria']) ?></td>
                                <td><?= htmlspecialchars($row_del['proveedor']) ?></td>
                                <td>$<?= number_format($row_del['venta_con_iva'], 2) ?></td>
                                <td>
                                    <div class="contenedor-acciones">
                                        <button class="btn-accion btn-restaurar" onclick="restaurarArticulo(<?= $row_del['id'] ?>)">RESTAURAR</button>
                                        <button class="btn-accion btn-eliminar" onclick="eliminarDefinitivo(<?= $row_del['id'] ?>)">ELIMINAR DEFINITIVO</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div style="padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #eee;">
            <div>
                <?php if (!empty($articulos_eliminados)): ?>
                    <button type="button" class="btn-custom btn-rojo" onclick="vaciarPapelera()">
                        🗑️ Vaciar Papelera
                    </button>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-custom" style="background:#888;" onclick="cerrarModalPapelera()">Cerrar</button>
        </div>
    </div>
</div>

<form id="formDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id_articulo" id="id_delete">
</form>

<form id="formRestore" method="POST" style="display:none;">
    <input type="hidden" name="action" value="restore">
    <input type="hidden" name="id_articulo" id="id_restore">
</form>

<form id="formPermDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="perm_delete">
    <input type="hidden" name="id_articulo" id="id_perm_delete">
</form>

<form id="formEmptyTrash" method="POST" style="display:none;">
    <input type="hidden" name="action" value="empty_trash">
</form>

<script>
    let contadorPrecios = 2;
    const inputNombre = document.getElementById('inputNombre');
    const checkMins = document.getElementById('checkMins');

    function calcularPrecios() {
        const porcentajeIva = parseFloat(document.getElementById('porcentaje_iva').value) || 0;
        const factor = 1 + (porcentajeIva / 100);
        const compraConIva = parseFloat(document.getElementById('compra_con_iva').value) || 0;
        document.getElementById('compra_sin_iva').value = (compraConIva > 0) ? (compraConIva / factor).toFixed(6) : "0.000000";
        const ventaConIva = parseFloat(document.getElementById('venta_con_iva').value) || 0;
        document.getElementById('venta_sin_iva').value = (ventaConIva > 0) ? (ventaConIva / factor).toFixed(6) : "0.000000";
    }

    inputNombre.addEventListener('input', function() {
        if (!checkMins.checked) { this.value = this.value.toUpperCase(); }
    });

    function controlMayusculas() {
        if (!checkMins.checked) { inputNombre.value = inputNombre.value.toUpperCase(); }
    }

    function cerrarModal() { 
        document.getElementById('modalArticulo').style.display = 'none'; 
        document.getElementById('formArticulo').reset();
        
        const opcionales = ['div_marca', 'div_lote', 'div_vencimiento', 'div_grupo_empaque'];
        opcionales.forEach(id => document.getElementById(id).style.display = 'none');
        
        for(let i=2; i<=4; i++) document.getElementById('div_precio'+i).style.display = 'none';
        document.getElementById('btnPlus').style.display = 'inline-block';
        contadorPrecios = 2;

        const fields = document.getElementById('formArticulo').querySelectorAll('input, select');
        fields.forEach(f => f.disabled = false);
        document.getElementById('btnGuardar').style.display = 'inline-block';
    }
    
    function toggleCampo(id, checkbox) { 
        let element = document.getElementById(id);
        element.style.display = checkbox.checked ? (id === 'div_grupo_empaque' ? 'grid' : 'block') : 'none';
    }

    function agregarPrecio() {
        if (contadorPrecios <= 4) {
            document.getElementById('div_precio' + contadorPrecios).style.display = 'block';
            contadorPrecios++;
        }
        if (contadorPrecios > 4) document.getElementById('btnPlus').style.display = 'none';
    }

    function abrirModal(modo) {
        const modal = document.getElementById('modalArticulo');
        const title = document.getElementById('modalTitle');
        const actionInput = document.getElementById('form_action');
        
        if(modo === 'nuevo') {
            title.innerText = "REGISTRO DE ARTÍCULO";
            actionInput.value = "insert";
            document.getElementById('id_articulo').value = "";
        }
        modal.style.display = 'block';
    }

    function llenarFormulario(id) {
        const data = JSON.parse(document.getElementById('fila-' + id).getAttribute('data-info'));
        
        document.getElementById('id_articulo').value = data.id;
        document.getElementById('inputNombre').value = data.nombre;
        document.getElementById('input_cod').value = data.cod_articulo;
        document.getElementById('input_cat').value = data.categoria;
        document.getElementById('input_stock').value = data.cantidad;
        document.getElementById('input_prov').value = data.proveedor;
        document.getElementById('compra_con_iva').value = data.compra_con_iva;
        document.getElementById('venta_con_iva').value = data.venta_con_iva;

        const selectIva = document.getElementById('porcentaje_iva');
        selectIva.value = parseFloat(data.porcentaje_iva);
        
        if(data.marca) { document.getElementById('chk_marca').checked = true; toggleCampo('div_marca', {checked:true}); document.getElementById('input_marca').value = data.marca; }
        if(data.lote) { document.getElementById('chk_lote').checked = true; toggleCampo('div_lote', {checked:true}); document.getElementById('input_lote').value = data.lote; }
        if(data.vencimiento) { document.getElementById('chk_vencimiento').checked = true; toggleCampo('div_vencimiento', {checked:true}); document.getElementById('input_vence').value = data.vencimiento; }
        
        if(parseFloat(data.unidades_caja) > 1 || data.envase !== 'UNIDAD') {
            document.getElementById('chk_empaque').checked = true; toggleCampo('div_grupo_empaque', {checked:true});
            document.getElementById('input_envase').value = data.envase;
            document.getElementById('input_unidades').value = data.unidades_caja;
            document.getElementById('input_precio_u').value = data.precio_unidad;
        }

        if(parseFloat(data.precio2) > 0) { agregarPrecio(); document.getElementById('input_p2').value = data.precio2; }
        if(parseFloat(data.precio3) > 0) { agregarPrecio(); document.getElementById('input_p3').value = data.precio3; }
        if(parseFloat(data.precio4) > 0) { agregarPrecio(); document.getElementById('input_p4').value = data.precio4; }

        calcularPrecios();
    }

    function verArticulo(id) {
        abrirModal('ver');
        document.getElementById('modalTitle').innerText = "VISTA DE ARTÍCULO";
        llenarFormulario(id);
        const fields = document.getElementById('formArticulo').querySelectorAll('input, select');
        fields.forEach(f => f.disabled = true);
        document.getElementById('btnGuardar').style.display = 'none';
    }

    function editarArticulo(id) {
        abrirModal('editar');
        document.getElementById('modalTitle').innerText = "EDICIÓN DE ARTÍCULO";
        document.getElementById('form_action').value = "update";
        llenarFormulario(id);
    }

    function abrirModalPapelera() {
        document.getElementById('modalPapelera').style.display = 'block';
    }

    function cerrarModalPapelera() {
        document.getElementById('modalPapelera').style.display = 'none';
    }

    function eliminarArticulo(id) {
        if(confirm("¿Estás seguro de mover este artículo a la papelera?")) {
            document.getElementById('id_delete').value = id;
            document.getElementById('formDelete').submit();
        }
    }

    function restaurarArticulo(id) {
        if(confirm("¿Deseas restaurar este artículo a la lista activa?")) {
            document.getElementById('id_restore').value = id;
            document.getElementById('formRestore').submit();
        }
    }

    function eliminarDefinitivo(id) {
        if(confirm("¿Estás seguro de eliminar PERMANENTEMENTE este artículo? Esta acción no se puede deshacer.")) {
            document.getElementById('id_perm_delete').value = id;
            document.getElementById('formPermDelete').submit();
        }
    }

    function vaciarPapelera() {
        if(confirm("¿ATENCIÓN: Estás seguro de VACIAR TODA LA PAPELERA?\n\nEsta acción eliminará PERMANENTEMENTE todos los artículos inactivos y no se podrá deshacer.")) {
            document.getElementById('formEmptyTrash').submit();
        }
    }
</script>

<?php if (file_exists('footer.php')) include_once 'footer.php'; ?>
</body>
</html>