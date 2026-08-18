<?php
// 1. Iniciar sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Inclusión del archivo de configuración
$ruta_config = __DIR__ . '/config/config.php';
if (file_exists($ruta_config)) {
    require_once($ruta_config);
} else {
    die("Error: No se encontró el archivo de configuración en 'config/config.php'");
}

// Verificar conexión PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Error: No se estableció la conexión con la base de datos.");
}

// 2.1 Procesar Acción de Eliminar Venta con Resguardo en 'ventas_eliminadas'
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
    $id_eliminar = filter_input(INPUT_POST, 'id_venta', FILTER_VALIDATE_INT);
    $motivo_eliminacion = trim($_POST['motivo_eliminacion'] ?? '');

    if ($id_eliminar && !empty($motivo_eliminacion)) {
        try {
            $pdo->beginTransaction();

            // A. Obtener todos los datos de la venta original antes de borrarla
            $stmtGet = $pdo->prepare("SELECT * FROM ventas WHERE id = ?");
            $stmtGet->execute([$id_eliminar]);
            $venta = $stmtGet->fetch(PDO::FETCH_ASSOC);

            if ($venta) {
                // B. Obtener ID del usuario activo que realiza la eliminación
                $usuario_elimino_id = $_SESSION['usuario_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['user_id'] ?? null;

                // C. Insertar los datos en la tabla 'ventas_eliminadas'
                $sqlInsert = "INSERT INTO ventas_eliminadas (
                    venta_id_original, fecha_venta, tipo_documento, numero_documento,
                    cliente_nombres, cliente_ci_ruc, forma_pago, subtotal, iva_total,
                    total, usuario_id, motivo_eliminacion, usuario_elimino_id, fecha_eliminacion
                ) VALUES (
                    :venta_id_original, :fecha_venta, :tipo_documento, :numero_documento,
                    :cliente_nombres, :cliente_ci_ruc, :forma_pago, :subtotal, :iva_total,
                    :total, :usuario_id, :motivo_eliminacion, :usuario_elimino_id, NOW()
                )";

                $stmtIns = $pdo->prepare($sqlInsert);
                $stmtIns->execute([
                    ':venta_id_original'  => $venta['id'],
                    ':fecha_venta'       => $venta['fecha'] ?? null,
                    ':tipo_documento'    => $venta['tipo_documento'] ?? null,
                    ':numero_documento'  => $venta['numero_documento'] ?? ($venta['num_documento'] ?? null),
                    ':cliente_nombres'   => $venta['cliente_nombres'] ?? null,
                    ':cliente_ci_ruc'    => $venta['cliente_ci_ruc'] ?? null,
                    ':forma_pago'        => $venta['forma_pago'] ?? null,
                    ':subtotal'          => $venta['subtotal'] ?? 0.00,
                    ':iva_total'         => $venta['iva_total'] ?? ($venta['iva'] ?? 0.00),
                    ':total'             => $venta['total'] ?? 0.00,
                    ':usuario_id'        => $venta['usuario_id'] ?? null,
                    ':motivo_eliminacion'=> $motivo_eliminacion,
                    ':usuario_elimino_id'=> $usuario_elimino_id
                ]);

                // D. (Opcional) Eliminar detalles de la venta si existen
                // $stmtDet = $pdo->prepare("DELETE FROM detalle_ventas WHERE venta_id = ?");
                // $stmtDet->execute([$id_eliminar]);

                // E. Eliminar el registro original de la tabla 'ventas'
                $stmtDel = $pdo->prepare("DELETE FROM ventas WHERE id = ?");
                $stmtDel->execute([$id_eliminar]);

                $pdo->commit();
                header("Location: consultar_ventas.php?msg=eliminado");
                exit;
            } else {
                throw new Exception("La venta especificada no existe.");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_eliminar = "Error al eliminar la venta: " . $e->getMessage();
        }
    } else {
        $error_eliminar = "Debe proporcionar un motivo válido para la eliminación.";
    }
}

// 3. Procesar Filtros de Búsqueda y Paginación
$busqueda     = $_GET['busqueda'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin    = $_GET['fecha_fin'] ?? '';

// Configuración de Paginación
$limite = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $limite;

// Construcción de la consulta con filtros
$where_sql = " WHERE 1=1";
$params = [];

if (!empty($busqueda)) {
    $where_sql .= " AND (v.id LIKE :b_id OR v.cliente_nombres LIKE :b_nombre OR v.cliente_ci_ruc LIKE :b_ruc)";
    $params['b_id']     = "%$busqueda%";
    $params['b_nombre'] = "%$busqueda%";
    $params['b_ruc']    = "%$busqueda%";
}

if (!empty($fecha_inicio)) {
    $where_sql .= " AND DATE(v.fecha) >= :fecha_inicio";
    $params['fecha_inicio'] = $fecha_inicio;
}

if (!empty($fecha_fin)) {
    $where_sql .= " AND DATE(v.fecha) <= :fecha_fin";
    $params['fecha_fin'] = $fecha_fin;
}

// 3.1 Contar total de registros para calcular total de páginas
$sql_count = "SELECT COUNT(*) FROM ventas v" . $where_sql;
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $limite);

// 3.2 Obtener los registros paginados
$sql = "SELECT v.id, v.fecha, v.tipo_documento, v.cliente_ci_ruc, v.cliente_nombres, v.forma_pago, v.subtotal, v.descuento_total, v.total, v.observaciones 
        FROM ventas v 
        " . $where_sql . " 
        ORDER BY v.id DESC 
        LIMIT :limite OFFSET :offset";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getUrlPaginacion($pagina, $busqueda, $fecha_inicio, $fecha_fin) {
    $params = ['pagina' => $pagina];
    if (!empty($busqueda)) $params['busqueda'] = $busqueda;
    if (!empty($fecha_inicio)) $params['fecha_inicio'] = $fecha_inicio;
    if (!empty($fecha_fin)) $params['fecha_fin'] = $fecha_fin;
    return 'consultar_ventas.php?' . http_build_query($params);
}

// 4. Inclusión del Menú Principal
$ruta_menu = __DIR__ . '/menu.php';
if (file_exists($ruta_menu)) {
    require_once($ruta_menu);
} else {
    die("Error: No se encontró el archivo 'menu.php'");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Ventas - ZOE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container-main {
            max-width: 1300px;
            margin: 30px auto;
            padding: 25px;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .header-title {
            font-size: 22px;
            color: #333;
            margin-bottom: 20px;
            border-bottom: 2px solid #5d75a4;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
            min-width: 180px;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }
        .form-group input {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        }
        .btn-filter {
            background: #5d75a4;
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-filter:hover { background: #4a5f87; }
        .btn-clear {
            background: #94a3b8;
            color: #fff;
            border: none;
            padding: 9px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .table-custom th {
            background: #5d75a4;
            color: #ffffff;
            text-align: left;
            padding: 12px;
            font-weight: 600;
        }
        .table-custom td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }
        .table-custom tr:hover { background: #f1f5f9; }
        
        /* BOTONES DE ACCIONES */
        .actions-group {
            display: flex;
            gap: 6px;
            justify-content: center;
        }
        .btn-action {
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            color: #fff;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-print { background: #10b981; }
        .btn-print:hover { background: #059669; }
        .btn-delete { background: #ef4444; }
        .btn-delete:hover { background: #dc2626; }

        .badge-pago {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            background: #e2e8f0;
            color: #334155;
        }
        .badge-doc {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .alert-msg {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        /* ESTILOS DE PAGINACIÓN */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pagination-info {
            font-size: 13px;
            color: #64748b;
        }
        .pagination-list {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0;
            gap: 5px;
        }
        .pagination-item a, .pagination-item span {
            display: inline-block;
            padding: 6px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            color: #334155;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .pagination-item a:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        .pagination-item.active span {
            background: #5d75a4;
            color: #ffffff;
            border-color: #5d75a4;
        }
        .pagination-item.disabled span {
            color: #cbd5e1;
            border-color: #e2e8f0;
            cursor: not-allowed;
        }

        /* ESTILOS DE LOS MODALES */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 3000;
            justify-content: center;
            align-items: center;
        }
        .modal-container {
            background: #ffffff;
            width: 90%;
            max-width: 580px;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            position: relative;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            color: #334155;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: #94a3b8;
            cursor: pointer;
        }
        .modal-close:hover { color: #334155; }
        .modal-options {
            display: flex;
            gap: 12px;
            margin-bottom: 10px;
        }
        .option-card {
            flex: 1;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 18px 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .option-card i {
            font-size: 28px;
            color: #5d75a4;
            margin-bottom: 8px;
            display: block;
        }
        .option-card span {
            font-weight: 600;
            font-size: 13px;
            color: #334155;
            display: block;
        }
        .option-card:hover {
            border-color: #5d75a4;
            background: #f0f4f8;
            transform: translateY(-2px);
        }

        /* ESTILOS ESPECÍFICOS FORMULARIO MODAL ELIMINACIÓN */
        .form-group-modal {
            margin-bottom: 15px;
        }
        .form-group-modal label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-group-modal textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
            resize: vertical;
            box-sizing: border-box;
        }
        .form-group-modal textarea:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .btn-modal-cancel {
            background: #94a3b8;
            color: #fff;
            border: none;
            padding: 9px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-modal-delete {
            background: #ef4444;
            color: #fff;
            border: none;
            padding: 9px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-modal-delete:hover { background: #dc2626; }
    </style>
</head>
<body>

<div class="container-main">
    <div class="header-title">
        <i class="fas fa-file-invoice-dollar"></i> Consulta de Ventas
    </div>

    <!-- Alertas de éxito / error -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
        <div class="alert-msg alert-success">
            <i class="fas fa-check-circle"></i> La venta ha sido eliminada y registrada en el historial correctamente.
        </div>
    <?php endif; ?>

    <?php if (!empty($error_eliminar)): ?>
        <div class="alert-msg alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_eliminar) ?>
        </div>
    <?php endif; ?>

    <!-- Filtros de búsqueda -->
    <div class="filter-card">
        <form method="GET" action="consultar_ventas.php" class="filter-form">
            <div class="form-group">
                <label>Buscar (ID / Cliente / RUC):</label>
                <input type="text" name="busqueda" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Escriba aquí...">
            </div>
            <div class="form-group">
                <label>Fecha Desde:</label>
                <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
            </div>
            <div class="form-group">
                <label>Fecha Hasta:</label>
                <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filtrar</button>
            <a href="consultar_ventas.php" class="btn-clear"><i class="fas fa-undo"></i> Limpiar</a>
        </form>
    </div>

    <!-- Tabla de resultados -->
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>No. Recibo</th>
                    <th>Fecha / Hora</th>
                    <th>Tipo Doc.</th>
                    <th>RUC / CI</th>
                    <th>Cliente</th>
                    <th>Forma Pago</th>
                    <th>Total</th>
                    <th style="text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($ventas)): ?>
                    <?php foreach ($ventas as $v): ?>
                        <tr>
                            <td><strong><?= str_pad($v['id'], 8, '0', STR_PAD_LEFT) ?></strong></td>
                            <td><?= date('d/m/Y H:i', strtotime($v['fecha'])) ?></td>
                            <td>
                                <span class="badge-doc">
                                    <?= htmlspecialchars(!empty($v['tipo_documento']) ? $v['tipo_documento'] : 'FACTURA') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($v['cliente_ci_ruc'] ?: 'N/A') ?></td>
                            <td><?= htmlspecialchars($v['cliente_nombres'] ?: 'CONSUMIDOR FINAL') ?></td>
                            <td><span class="badge-pago"><?= htmlspecialchars($v['forma_pago'] ?: 'EFECTIVO') ?></span></td>
                            <td><strong>$<?= number_format((float)$v['total'], 2) ?></strong></td>
                            <td style="text-align: center;">
                                <div class="actions-group">
                                    <button type="button" class="btn-action btn-print" title="Imprimir" onclick="abrirModalImpresion(<?= $v['id'] ?>)">
                                        <i class="fas fa-print"></i> Imprimir
                                    </button>
                                    <button type="button" class="btn-action btn-delete" title="Eliminar Venta" onclick="abrirModalEliminar(<?= $v['id'] ?>)">
                                        <i class="fas fa-trash-alt"></i> Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 20px; color: #64748b;">
                            No se encontraron registros de ventas.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- CONTROLES DE PAGINACIÓN -->
    <?php if ($total_paginas > 0): ?>
    <div class="pagination-container">
        <div class="pagination-info">
            Mostrando <strong><?= min($offset + 1, $total_registros) ?></strong> a <strong><?= min($offset + $limite, $total_registros) ?></strong> de <strong><?= $total_registros ?></strong> resultados
        </div>
        
        <?php if ($total_paginas > 1): ?>
        <ul class="pagination-list">
            <!-- Botón Anterior -->
            <li class="pagination-item <?= ($pagina_actual <= 1) ? 'disabled' : '' ?>">
                <?php if ($pagina_actual > 1): ?>
                    <a href="<?= getUrlPaginacion($pagina_actual - 1, $busqueda, $fecha_inicio, $fecha_fin) ?>">&laquo; Anterior</a>
                <?php else: ?>
                    <span>&laquo; Anterior</span>
                <?php endif; ?>
            </li>

            <!-- Páginas Numeradas -->
            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                <li class="pagination-item <?= ($i == $pagina_actual) ? 'active' : '' ?>">
                    <?php if ($i == $pagina_actual): ?>
                        <span><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= getUrlPaginacion($i, $busqueda, $fecha_inicio, $fecha_fin) ?>"><?= $i ?></a>
                    <?php endif; ?>
                </li>
            <?php endfor; ?>

            <!-- Botón Siguiente -->
            <li class="pagination-item <?= ($pagina_actual >= $total_paginas) ? 'disabled' : '' ?>">
                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="<?= getUrlPaginacion($pagina_actual + 1, $busqueda, $fecha_inicio, $fecha_fin) ?>">Siguiente &raquo;</a>
                <?php else: ?>
                    <span>Siguiente &raquo;</span>
                <?php endif; ?>
            </li>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- FORMULARIO OCULTO PARA ELIMINAR -->
<form id="formEliminarVenta" method="POST" action="consultar_ventas.php" style="display: none;">
    <input type="hidden" name="accion" value="eliminar">
    <input type="hidden" name="id_venta" id="inputEliminarId">
    <input type="hidden" name="motivo_eliminacion" id="inputMotivoEliminar">
</form>

<!-- MODAL MOTIVO DE ELIMINACIÓN -->
<div class="modal-overlay" id="modalEliminar">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> Eliminar Venta <span id="lblVentaId"></span></h3>
            <button type="button" class="modal-close" onclick="cerrarModalEliminar()">&times;</button>
        </div>
        <form onsubmit="procesarEliminacion(event)">
            <p style="font-size: 14px; color: #64748b; margin-bottom: 15px;">
                Esta acción moverá los datos de la venta a la tabla de eliminados y la quitará del sistema.
            </p>
            <div class="form-group-modal">
                <label for="txtMotivoEliminacion">Motivo de la eliminación <span style="color: #ef4444;">*</span></label>
                <textarea id="txtMotivoEliminacion" rows="4" placeholder="Escriba la razón detallada por la cual desea eliminar esta venta..." required></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="cerrarModalEliminar()">Cancelar</button>
                <button type="submit" class="btn-modal-delete">
                    <i class="fas fa-trash-alt"></i> Confirmar Eliminación
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL SELECCIÓN TAMAÑO IMPRESIÓN -->
<div class="modal-overlay" id="modalImpresion">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-print"></i> Seleccionar Formato de Impresión</h3>
            <button type="button" class="modal-close" onclick="cerrarModalImpresion()">&times;</button>
        </div>
        <div class="modal-options">
            <div class="option-card" onclick="procesarImpresion('ticket58')">
                <i class="fas fa-receipt"></i>
                <span>Ticket (58mm)</span>
            </div>
            <div class="option-card" onclick="procesarImpresion('ticket80')">
                <i class="fas fa-receipt"></i>
                <span>Ticket (80mm)</span>
            </div>
            <div class="option-card" onclick="procesarImpresion('a4')">
                <i class="fas fa-file-pdf"></i>
                <span>Hoja A4</span>
            </div>
        </div>
    </div>
</div>

<script>
let ventaSeleccionadaId = null;

// LÓGICA MODAL DE IMPRESIÓN
function abrirModalImpresion(ventaId) {
    ventaSeleccionadaId = ventaId;
    document.getElementById('modalImpresion').style.display = 'flex';
}

function cerrarModalImpresion() {
    ventaSeleccionadaId = null;
    document.getElementById('modalImpresion').style.display = 'none';
}

function procesarImpresion(tipo) {
    if (!ventaSeleccionadaId) return;

    let endpoint = '';
    if (tipo === 'ticket58') {
        endpoint = 'recibo_58.php';
    } else if (tipo === 'ticket80') {
        endpoint = 'recibo_80.php';
    } else {
        endpoint = 'recibo_a4.php';
    }

    fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'venta_id=' + ventaSeleccionadaId
    })
    .then(async response => {
        const textResponse = await response.text();
        
        if (!response.ok) {
            throw new Error(`Error en el servidor (${response.status}): ${textResponse || 'Archivo no encontrado'}`);
        }

        try {
            return JSON.parse(textResponse);
        } catch (e) {
            throw new Error('Respuesta inválida del servidor:\n' + textResponse.substring(0, 300));
        }
    })
    .then(data => {
        if (data.status === 'success' && data.pdfBase64) {
            let byteCharacters = atob(data.pdfBase64);
            let byteNumbers = new Array(byteCharacters.length);
            for (let i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            let byteArray = new Uint8Array(byteNumbers);
            let file = new Blob([byteArray], { type: 'application/pdf' });
            let fileURL = URL.createObjectURL(file);
            window.open(fileURL, '_blank');
            cerrarModalImpresion();
        } else {
            alert('Error al generar comprobante: ' + (data.message || data.mensaje || 'Error desconocido'));
        }
    })
    .catch(error => {
        console.error('Error detallado:', error);
        alert(error.message);
    });
}

// LÓGICA MODAL DE ELIMINACIÓN
function abrirModalEliminar(ventaId) {
    ventaSeleccionadaId = ventaId;
    document.getElementById('lblVentaId').innerText = 'No. ' + String(ventaId).padStart(8, '0');
    document.getElementById('inputEliminarId').value = ventaId;
    document.getElementById('txtMotivoEliminacion').value = '';
    document.getElementById('modalEliminar').style.display = 'flex';
}

function cerrarModalEliminar() {
    ventaSeleccionadaId = null;
    document.getElementById('modalEliminar').style.display = 'none';
}

function procesarEliminacion(e) {
    e.preventDefault();
    const motivo = document.getElementById('txtMotivoEliminacion').value.trim();
    if (!motivo) {
        alert('Por favor ingrese un motivo para eliminar la venta.');
        return;
    }
    
    document.getElementById('inputMotivoEliminar').value = motivo;
    document.getElementById('formEliminarVenta').submit();
}

// Cerrar modales al hacer clic fuera del contenido
window.addEventListener('click', function(e) {
    let modalImp = document.getElementById('modalImpresion');
    let modalDel = document.getElementById('modalEliminar');
    if (e.target === modalImp) {
        cerrarModalImpresion();
    }
    if (e.target === modalDel) {
        cerrarModalEliminar();
    }
});
</script>

<?php
// 5. Inclusión del Pie de Página
$ruta_footer = __DIR__ . '/footer.php';
if (file_exists($ruta_footer)) {
    require_once($ruta_footer);
}
?>
</body>
</html>