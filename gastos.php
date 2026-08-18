<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aMayusculas($texto) {
    if (empty($texto)) return '';
    if (function_exists('mb_toupper')) {
        return mb_toupper($texto, 'UTF-8');
    }
    return strtoupper($texto);
}

$ruta_config = __DIR__ . '/config/config.php';
if (file_exists($ruta_config)) {
    require_once($ruta_config);
} else {
    die("Error: No se encontró el archivo de configuración en 'config/config.php'");
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Error: No se estableció la conexión con la base de datos.");
}

$error_guardar = '';
$error_eliminar = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_guardar'])) {
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    $descripcion = aMayusculas(trim($_POST['descripcion'] ?? ''));
    $monto       = floatval($_POST['monto'] ?? 0);
    $estado      = 'ACTIVO';

    if (!empty($descripcion) && $monto > 0) {
        try {
            $sql_insert = "INSERT INTO gastos (fecha, descripcion, monto, estado) VALUES (:fecha, :descripcion, :monto, :estado)";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute([
                ':fecha'       => $fecha,
                ':descripcion' => $descripcion,
                ':monto'       => $monto,
                ':estado'      => $estado
            ]);

            header("Location: gastos.php?msg=guardado");
            exit;
        } catch (PDOException $e) {
            $error_guardar = "Error al guardar el gasto: " . $e->getMessage();
        }
    } else {
        $error_guardar = "Por favor, ingrese una descripción válida y un monto mayor a 0.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['cod_gasto'])) {
    $cod_eliminar = (int)$_POST['cod_gasto'];
    try {
        $sql_delete = "UPDATE gastos SET estado = 'INACTIVO' WHERE cod_gasto = :cod";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([':cod' => $cod_eliminar]);

        header("Location: gastos.php?msg=eliminado");
        exit;
    } catch (PDOException $e) {
        $error_eliminar = "Error al inactivar el gasto: " . $e->getMessage();
    }
}

if (isset($_GET['accion']) && $_GET['accion'] === 'eliminar' && !empty($_GET['cod_gasto'])) {
    $cod_eliminar = (int)$_GET['cod_gasto'];
    try {
        $sql_delete = "UPDATE gastos SET estado = 'INACTIVO' WHERE cod_gasto = :cod";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([':cod' => $cod_eliminar]);

        header("Location: gastos.php?msg=eliminado");
        exit;
    } catch (PDOException $e) {
        $error_eliminar = "Error al inactivar el gasto: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'perm_delete' && !empty($_POST['cod_gasto'])) {
    $cod_perm_delete = (int)$_POST['cod_gasto'];
    try {
        $sql_perm_delete = "DELETE FROM gastos WHERE cod_gasto = :cod";
        $stmt_perm_delete = $pdo->prepare($sql_perm_delete);
        $stmt_perm_delete->execute([':cod' => $cod_perm_delete]);

        header("Location: gastos.php?msg=eliminado_perm");
        exit;
    } catch (PDOException $e) {
        $error_eliminar = "Error al eliminar definitivamente el gasto: " . $e->getMessage();
    }
}

if (isset($_GET['accion']) && $_GET['accion'] === 'eliminar_todos_inactivos') {
    try {
        $sql_delete_all = "DELETE FROM gastos WHERE estado = 'INACTIVO'";
        $stmt_delete_all = $pdo->prepare($sql_delete_all);
        $stmt_delete_all->execute();

        header("Location: gastos.php?msg=eliminados_todos");
        exit;
    } catch (PDOException $e) {
        $error_eliminar = "Error al eliminar permanentemente los gastos inactivos: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore' && !empty($_POST['cod_gasto'])) {
    $cod_reactivar = (int)$_POST['cod_gasto'];
    try {
        $sql_reactivar = "UPDATE gastos SET estado = 'ACTIVO' WHERE cod_gasto = :cod";
        $stmt_reactivar = $pdo->prepare($sql_reactivar);
        $stmt_reactivar->execute([':cod' => $cod_reactivar]);

        header("Location: gastos.php?msg=reactivado");
        exit;
    } catch (PDOException $e) {
        $error_eliminar = "Error al reactivar el gasto: " . $e->getMessage();
    }
}

$busqueda     = aMayusculas($_GET['busqueda'] ?? '');
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin    = $_GET['fecha_fin'] ?? '';

$limite = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $limite;

$where_sql = " WHERE (g.estado = 'ACTIVO' OR g.estado IS NULL OR g.estado = '')";
$params = [];

if (!empty($busqueda)) {
    $where_sql .= " AND (g.cod_gasto LIKE :b_id OR g.descripcion LIKE :b_desc)";
    $params['b_id']   = "%$busqueda%";
    $params['b_desc'] = "%$busqueda%";
}

if (!empty($fecha_inicio)) {
    $where_sql .= " AND g.fecha >= :fecha_inicio";
    $params['fecha_inicio'] = $fecha_inicio;
}

if (!empty($fecha_fin)) {
    $where_sql .= " AND g.fecha <= :fecha_fin";
    $params['fecha_fin'] = $fecha_fin;
}

$sql_count = "SELECT COUNT(*) FROM gastos g" . $where_sql;
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $limite);

$sql = "SELECT g.* 
        FROM gastos g 
        " . $where_sql . " 
        ORDER BY g.cod_gasto DESC 
        LIMIT :limite OFFSET :offset";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql_inactivos = "SELECT * FROM gastos WHERE estado = 'INACTIVO' ORDER BY cod_gasto DESC";
$stmt_inactivos = $pdo->query($sql_inactivos);
$gastos_inactivos = $stmt_inactivos ? $stmt_inactivos->fetchAll(PDO::FETCH_ASSOC) : [];

function getUrlPaginacion($pagina, $busqueda, $fecha_inicio, $fecha_fin) {
    $params = ['pagina' => $pagina];
    if (!empty($busqueda)) $params['busqueda'] = $busqueda;
    if (!empty($fecha_inicio)) $params['fecha_inicio'] = $fecha_inicio;
    if (!empty($fecha_fin)) $params['fecha_fin'] = $fecha_fin;
    return 'gastos.php?' . http_build_query($params);
}

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
    <title>Gestión de Gastos | ZOE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --azul-header: #3f5891;
            --fondo-gris: #f4f6f9;
            --verde-exito: #28a745;
            --azul-info: #17a2b8;
            --rojo-peligro: #dc3545;
            --amarillo-editar: #ffc107;
        }

        * { 
            box-sizing: border-box; 
            margin: 0;
            padding: 0;
        }
        
        html, body { 
            height: 100%; 
            margin: 0; 
            padding: 0;
            background-color: var(--fondo-gris); 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .main-content { 
            flex: 1 0 auto; 
            padding: 20px;
            margin: 0;
            width: 100%;
            max-width: 100%;
        }

        .full-wrapper { 
            padding: 0;
            margin: 0 auto;
            width: 100%;
            max-width: 100%;
        }

        .card-full { 
            background: #fff; 
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            width: 100%; 
            overflow: hidden; 
            margin: 0;
            border: 1px solid #e8ecf1;
        }

        .card-full-header { 
            background-color: var(--azul-header); 
            color: white; 
            padding: 15px 25px; 
            font-weight: bold; 
            font-size: 14px; 
            text-transform: uppercase;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-full-header i {
            margin-right: 8px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .barra-superior { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 20px 25px; 
            background: #fff; 
            border-bottom: 1px solid #eee; 
            gap: 15px; 
            flex-wrap: wrap; 
        }

        .busqueda-container { 
            flex: 1; 
            display: flex; 
            gap: 10px; 
            min-width: 280px; 
        }

        .input-custom { 
            padding: 10px 15px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            font-size: 14px; 
            width: 100%;
            outline: none;
            transition: border-color 0.3s;
        }

        .input-custom:focus {
            border-color: var(--azul-header);
            box-shadow: 0 0 0 3px rgba(63, 88, 145, 0.1);
        }

        .input-custom.mayusculas { text-transform: uppercase; }

        .btn-custom { 
            padding: 10px 18px; 
            border: 2px solid transparent;
            border-radius: 4px; 
            font-weight: bold; 
            cursor: pointer; 
            font-size: 11px; 
            text-transform: uppercase; 
            color: white; 
            display: inline-flex; 
            align-items: center; 
            text-decoration: none; 
            gap: 6px;
            transition: all 0.3s ease;
        }

        .btn-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .btn-custom:active {
            transform: translateY(0);
        }

        .btn-azul { 
            background: var(--azul-header); 
            border-color: var(--azul-header);
        }
        .btn-azul:hover { background: #2f4578; border-color: #2f4578; }

        .btn-verde { 
            background: var(--verde-exito); 
            border-color: var(--verde-exito);
        }
        .btn-verde:hover { background: #1e7e34; border-color: #1e7e34; }

        .btn-rojo { 
            background: var(--rojo-peligro); 
            border-color: var(--rojo-peligro);
        }
        .btn-rojo:hover { background: #bd2130; border-color: #bd2130; }

        .btn-info { 
            background: var(--azul-info); 
            border-color: var(--azul-info);
        }
        .btn-info:hover { background: #117a8b; border-color: #117a8b; }

        .btn-amarillo { 
            background: var(--amarillo-editar); 
            color: #333;
            border-color: var(--amarillo-editar);
        }
        .btn-amarillo:hover { background: #e0a800; border-color: #e0a800; }

        .btn-gris {
            background: #888;
            color: white;
            border-color: #888;
        }
        .btn-gris:hover { background: #666; border-color: #666; }

        .table-responsive { 
            width: 100%; 
            overflow-x: auto; 
            padding: 0 5px;
        }

        table.full-width-table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 800px; 
        }

        .full-width-table th { 
            text-align: left; 
            background: var(--azul-header); 
            color: white;
            padding: 15px 15px; 
            border-bottom: 2px solid #eee; 
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .full-width-table td { 
            padding: 12px 15px; 
            border-bottom: 1px solid #f0f0f0; 
            font-size: 13px; 
            color: #333;
        }

        .full-width-table tbody tr { 
            transition: background 0.2s;
        }

        .full-width-table tbody tr:hover { 
            background: #f8f9fa; 
        }

        .full-width-table tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }

        .full-width-table tbody tr:nth-child(even):hover {
            background-color: #f1f3f5;
        }

        .full-width-table tbody tr:last-child td {
            border-bottom: none;
        }

        .mayusculas { text-transform: uppercase; }

        .badge-activo {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            text-transform: uppercase;
        }

        .badge-inactivo {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            text-transform: uppercase;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 12px 20px;
            border-radius: 4px;
            margin: 10px 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 12px 20px;
            border-radius: 4px;
            margin: 10px 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px 25px;
            margin: 0;
            border-left: none;
            border-right: none;
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
            min-width: 160px;
        }

        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-group input[type="text"], 
        .form-group input[type="date"],
        .form-group input[type="number"],
        .form-group textarea {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--azul-header);
            box-shadow: 0 0 0 3px rgba(63, 88, 145, 0.1);
        }

        .btn-filter {
            background: var(--azul-header);
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
            font-size: 13px;
            text-transform: uppercase;
            border: 2px solid var(--azul-header);
        }
        .btn-filter:hover { background: #2f4578; border-color: #2f4578; }

        .btn-clear {
            background: #94a3b8;
            color: #fff;
            border: none;
            padding: 9px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            border: 2px solid #94a3b8;
        }
        .btn-clear:hover { background: #64748b; border-color: #64748b; }

        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 25px;
            border-top: 1px solid #eee;
            flex-wrap: wrap;
            gap: 10px;
            background: #fafafa;
        }

        .pagination-info { 
            font-size: 13px; 
            color: #666; 
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
            border: 1px solid #ddd;
            border-radius: 4px;
            color: #333;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .pagination-item a:hover { 
            background: var(--azul-header); 
            color: white; 
            border-color: var(--azul-header);
        }

        .pagination-item.active span { 
            background: var(--azul-header); 
            color: #ffffff; 
            border-color: var(--azul-header); 
        }

        .pagination-item.disabled span { 
            color: #ccc; 
            border-color: #eee; 
            cursor: not-allowed; 
        }

        .modal { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.6); 
            overflow-y: auto; 
        }

        .modal-content { 
            background: white; 
            margin: 30px auto; 
            width: 90%; 
            max-width: 800px; 
            border-radius: 8px; 
            animation: modalFadeIn 0.25s ease-out;
            border: 1px solid #e8ecf1;
        }

        .modal-header { 
            background: var(--azul-header); 
            color: white; 
            padding: 15px 20px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-radius: 8px 8px 0 0; 
        }

        .modal-header-rojo {
            background: var(--rojo-peligro);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: white;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
            line-height: 1;
        }

        .modal-close:hover {
            opacity: 1;
        }

        .modal-body { 
            padding: 25px; 
        }

        .form-grid { 
            display: grid; 
            grid-template-columns: repeat(2, 1fr); 
            gap: 15px; 
        }

        .form-grid .form-group {
            min-width: unset;
        }

        .form-group label.required::after {
            content: " *";
            color: var(--rojo-peligro);
        }

        .full-width { grid-column: span 2; }

        .modal-footer {
            padding: 15px 20px;
            text-align: right;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }

        .detail-item {
            background: #f8fafc;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .detail-item label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 3px;
            letter-spacing: 0.3px;
        }

        .detail-item span {
            font-size: 14px;
            color: #1e293b;
            font-weight: 600;
            text-transform: uppercase;
        }

        .detail-full { grid-column: span 2; }

        .reason-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #334155;
            padding: 12px;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.4;
            font-weight: 500;
            text-transform: uppercase;
        }

        .footer-full {
            background: var(--azul-header);
            color: white;
            padding: 15px 25px;
            font-size: 12px;
            text-align: center;
            border-top: 1px solid rgba(255,255,255,0.15);
            flex-shrink: 0;
            width: 100%;
            margin-top: 20px;
        }

        .footer-full a {
            color: #ffc107;
            text-decoration: none;
        }

        .footer-full a:hover {
            text-decoration: underline;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: span 1; }
            .barra-superior { flex-direction: column; align-items: stretch; }
            .busqueda-container { min-width: unset; }
            .header-actions { width: 100%; justify-content: stretch; }
            .header-actions .btn-custom { flex: 1; justify-content: center; }
            .filter-form { flex-direction: column; }
            .filter-form .form-group { min-width: unset; }
            .pagination-container { flex-direction: column; text-align: center; }
            .detail-grid { grid-template-columns: 1fr; }
            .detail-full { grid-column: span 1; }
            .modal-content { margin: 15px auto; width: 95%; }
            .card-full-header { 
                font-size: 12px; 
                flex-direction: column; 
                text-align: center; 
            }
            .main-content { padding: 10px; }
        }

        @media (max-width: 480px) {
            .card-full-header { font-size: 11px; }
            .full-width-table th, 
            .full-width-table td { 
                padding: 8px 10px; 
                font-size: 11px; 
            }
            .btn-custom { 
                padding: 8px 12px; 
                font-size: 10px; 
            }
            .barra-superior { padding: 15px; }
            .filter-card { padding: 15px; }
            .pagination-container { padding: 15px; }
            .alert-success,
            .alert-error { margin: 10px 15px; padding: 10px 15px; }
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="full-wrapper">

        <div class="card-full">
            <div class="card-full-header">
                <span>
                    <i class="fas fa-file-invoice-dollar"></i> 
                    Módulo de Gestión de Gastos
                </span>
                <div class="header-actions">
                    <button type="button" class="btn-custom btn-rojo" onclick="abrirModalInactivos()">
                        <i class="fas fa-trash-alt"></i> Papelera (<?= count($gastos_inactivos) ?>)
                    </button>
                    <button type="button" class="btn-custom btn-verde" onclick="abrirModalNuevo()">
                        <i class="fas fa-plus-circle"></i> Nuevo Gasto
                    </button>
                </div>
            </div>

            <div class="filter-card">
                <form method="GET" action="gastos.php" class="filter-form">
                    <div class="form-group">
                        <label>Buscar (Código / Descripción)</label>
                        <input type="text" name="busqueda" class="input-custom mayusculas" value="<?= htmlspecialchars($busqueda) ?>" placeholder="ESCRIBA AQUÍ...">
                    </div>
                    <div class="form-group">
                        <label>Desde</label>
                        <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
                    </div>
                    <div class="form-group">
                        <label>Hasta</label>
                        <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
                    </div>
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="gastos.php" class="btn-clear"><i class="fas fa-undo"></i> Limpiar</a>
                </form>
            </div>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'guardado'): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> El nuevo gasto se ha registrado correctamente.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> El gasto ha sido marcado como INACTIVO y retirado de la lista.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado_perm'): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> El gasto ha sido eliminado definitivamente de la base de datos.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminados_todos'): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> Todos los gastos de la papelera han sido eliminados permanentemente.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'reactivado'): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> El gasto ha sido reactivado correctamente.
                </div>
            <?php endif; ?>

            <?php if (!empty($error_guardar)): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_guardar) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_eliminar)): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_eliminar) ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="full-width-table" id="tablaGastos">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Fecha</th>
                            <th>Descripción</th>
                            <th>Monto</th>
                            <th>Estado</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($gastos)): ?>
                            <?php foreach ($gastos as $g): ?>
                                <tr>
                                    <td><strong><?= str_pad($g['cod_gasto'], 6, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><?= !empty($g['fecha']) ? date('d/m/Y', strtotime($g['fecha'])) : 'N/A' ?></td>
                                    <td class="mayusculas"><?= htmlspecialchars(aMayusculas($g['descripcion'] ?: 'Sin descripción')) ?></td>
                                    <td><strong>$<?= number_format((float)$g['monto'], 2) ?></strong></td>
                                    <td>
                                        <span class="badge-activo"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i> ACTIVO</span>
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="display: flex; gap: 6px; justify-content: center; flex-wrap: wrap;">
                                            <button type="button" class="btn-custom btn-info" style="padding: 5px 10px; font-size: 10px;" onclick='verDetalle(<?= htmlspecialchars(json_encode($g), ENT_QUOTES, 'UTF-8') ?>)'>
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('¿Mover este gasto a la papelera?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="cod_gasto" value="<?= $g['cod_gasto'] ?>">
                                                <button type="submit" class="btn-custom btn-rojo" style="padding: 5px 10px; font-size: 10px;">
                                                    <i class="fas fa-trash-alt"></i> Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 60px; color: #aaa;">
                                    <i class="fas fa-inbox" style="font-size: 30px; display: block; margin-bottom: 10px;"></i>
                                    No hay gastos activos registrados.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_paginas > 0): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Mostrando <strong><?= min($offset + 1, $total_registros) ?></strong> a <strong><?= min($offset + $limite, $total_registros) ?></strong> de <strong><?= $total_registros ?></strong> resultados
                </div>
                
                <?php if ($total_paginas > 1): ?>
                <ul class="pagination-list">
                    <li class="pagination-item <?= ($pagina_actual <= 1) ? 'disabled' : '' ?>">
                        <?php if ($pagina_actual > 1): ?>
                            <a href="<?= getUrlPaginacion($pagina_actual - 1, $busqueda, $fecha_inicio, $fecha_fin) ?>"><i class="fas fa-chevron-left"></i> Anterior</a>
                        <?php else: ?>
                            <span><i class="fas fa-chevron-left"></i> Anterior</span>
                        <?php endif; ?>
                    </li>

                    <?php 
                    $rango_inicio = max(1, $pagina_actual - 2);
                    $rango_fin = min($total_paginas, $pagina_actual + 2);
                    
                    if ($rango_inicio > 1): ?>
                        <li class="pagination-item"><a href="<?= getUrlPaginacion(1, $busqueda, $fecha_inicio, $fecha_fin) ?>">1</a></li>
                        <?php if ($rango_inicio > 2): ?>
                            <li class="pagination-item disabled"><span>...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $rango_inicio; $i <= $rango_fin; $i++): ?>
                        <li class="pagination-item <?= ($i == $pagina_actual) ? 'active' : '' ?>">
                            <?php if ($i == $pagina_actual): ?>
                                <span><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= getUrlPaginacion($i, $busqueda, $fecha_inicio, $fecha_fin) ?>"><?= $i ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endfor; ?>

                    <?php if ($rango_fin < $total_paginas): ?>
                        <?php if ($rango_fin < $total_paginas - 1): ?>
                            <li class="pagination-item disabled"><span>...</span></li>
                        <?php endif; ?>
                        <li class="pagination-item"><a href="<?= getUrlPaginacion($total_paginas, $busqueda, $fecha_inicio, $fecha_fin) ?>"><?= $total_paginas ?></a></li>
                    <?php endif; ?>

                    <li class="pagination-item <?= ($pagina_actual >= $total_paginas) ? 'disabled' : '' ?>">
                        <?php if ($pagina_actual < $total_paginas): ?>
                            <a href="<?= getUrlPaginacion($pagina_actual + 1, $busqueda, $fecha_inicio, $fecha_fin) ?>">Siguiente <i class="fas fa-chevron-right"></i></a>
                        <?php else: ?>
                            <span>Siguiente <i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </li>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<div id="modalNuevoGasto" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Registrar Nuevo Gasto</h3>
            <button type="button" class="modal-close" onclick="cerrarModalNuevo()">&times;</button>
        </div>
        <form method="POST" action="gastos.php">
            <input type="hidden" name="accion_guardar" value="1">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="required">Fecha</label>
                        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Monto ($)</label>
                        <input type="number" step="0.01" min="0.01" name="monto" placeholder="0.00" required>
                    </div>
                    <div class="form-group full-width">
                        <label class="required">Descripción / Detalle</label>
                        <textarea name="descripcion" rows="3" placeholder="ESCRIBA LA DESCRIPCIÓN DEL GASTO..." required style="resize: vertical; text-transform: uppercase;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-custom btn-gris" onclick="cerrarModalNuevo()">Cancelar</button>
                <button type="submit" class="btn-custom btn-verde"><i class="fas fa-save"></i> Guardar Gasto</button>
            </div>
        </form>
    </div>
</div>

<div id="modalInactivos" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header modal-header-rojo">
            <h3><i class="fas fa-trash-alt"></i> Papelera de Gastos Eliminados</h3>
            <button type="button" class="modal-close" onclick="cerrarModalInactivos()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($gastos_inactivos)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                    <span style="font-size: 13px; color: #666;">
                        Hay <strong><?= count($gastos_inactivos) ?></strong> gastos en estado inactivo.
                    </span>
                    <button type="button" class="btn-custom btn-rojo" onclick="confirmarEliminarTodosInactivos()">
                        <i class="fas fa-trash-alt"></i> Vaciar Papelera
                    </button>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="full-width-table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Fecha</th>
                            <th>Descripción</th>
                            <th>Monto</th>
                            <th>Estado</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($gastos_inactivos)): ?>
                            <?php foreach ($gastos_inactivos as $gi): ?>
                                <tr>
                                    <td><strong><?= str_pad($gi['cod_gasto'], 6, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><?= !empty($gi['fecha']) ? date('d/m/Y', strtotime($gi['fecha'])) : 'N/A' ?></td>
                                    <td class="mayusculas"><?= htmlspecialchars(aMayusculas($gi['descripcion'] ?: 'Sin descripción')) ?></td>
                                    <td><strong>$<?= number_format((float)$gi['monto'], 2) ?></strong></td>
                                    <td><span class="badge-inactivo"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i> INACTIVO</span></td>
                                    <td style="text-align: center;">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Restaurar este gasto?')">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="cod_gasto" value="<?= $gi['cod_gasto'] ?>">
                                            <button type="submit" class="btn-custom btn-verde" style="padding: 5px 10px; font-size: 10px;">
                                                <i class="fas fa-undo"></i> Reactivar
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar DEFINITIVAMENTE este gasto?')">
                                            <input type="hidden" name="action" value="perm_delete">
                                            <input type="hidden" name="cod_gasto" value="<?= $gi['cod_gasto'] ?>">
                                            <button type="submit" class="btn-custom btn-rojo" style="padding: 5px 10px; font-size: 10px;">
                                                <i class="fas fa-trash-alt"></i> Eliminar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 40px; color: #aaa;">
                                    <i class="fas fa-trash-restore" style="font-size: 30px; display: block; margin-bottom: 10px;"></i>
                                    La papelera está vacía.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-custom btn-gris" onclick="cerrarModalInactivos()">Cerrar</button>
        </div>
    </div>
</div>

<div id="modalDetalle" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle"></i> Detalle del Gasto</h3>
            <button type="button" class="modal-close" onclick="cerrarModalDetalle()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Código Gasto</label>
                    <span id="det_cod_gasto">-</span>
                </div>
                <div class="detail-item">
                    <label>Fecha</label>
                    <span id="det_fecha">-</span>
                </div>
                <div class="detail-item">
                    <label>Monto</label>
                    <span id="det_monto" style="color: #dc2626; font-size: 16px;">-</span>
                </div>
                <div class="detail-item">
                    <label>Estado</label>
                    <span id="det_estado">-</span>
                </div>
                <div class="detail-item detail-full">
                    <label>Descripción del Gasto</label>
                    <div class="reason-box" id="det_descripcion">Sin descripción</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-custom btn-gris" onclick="cerrarModalDetalle()">Cerrar</button>
        </div>
    </div>
</div>

<script>
    function filtrarTabla() {
        let input = document.getElementById("txtBuscar");
        if (!input) return;
        let filter = input.value.toUpperCase();
        let rows = document.getElementById("tablaGastos").getElementsByTagName("tr");
        for (let i = 1; i < rows.length; i++) {
            let text = rows[i].innerText.toUpperCase();
            rows[i].style.display = text.includes(filter) ? "" : "none";
        }
    }

    document.addEventListener('input', function(e) {
        if (e.target.tagName === 'TEXTAREA' || 
            (e.target.tagName === 'INPUT' && e.target.type === 'text')) {
            e.target.value = e.target.value.toUpperCase();
        }
    });

    function abrirModalNuevo() {
        document.getElementById('modalNuevoGasto').style.display = 'block';
    }
    function cerrarModalNuevo() {
        document.getElementById('modalNuevoGasto').style.display = 'none';
    }

    function abrirModalInactivos() {
        document.getElementById('modalInactivos').style.display = 'block';
    }
    function cerrarModalInactivos() {
        document.getElementById('modalInactivos').style.display = 'none';
    }

    function verDetalle(data) {
        document.getElementById('det_cod_gasto').innerText = String(data.cod_gasto).padStart(6, '0');
        document.getElementById('det_fecha').innerText = data.fecha ? formatearFecha(data.fecha) : 'N/A';
        
        let monto = parseFloat(data.monto || 0).toFixed(2);
        document.getElementById('det_monto').innerText = '$' + monto;
        
        let estado = data.estado ? data.estado.toUpperCase() : 'ACTIVO';
        let badgeColor = estado === 'ACTIVO' ? 'var(--verde-exito)' : 'var(--rojo-peligro)';
        document.getElementById('det_estado').innerHTML = `<span style="color: ${badgeColor}; font-weight: 700;">${estado}</span>`;
        
        document.getElementById('det_descripcion').innerText = (data.descripcion || 'Sin descripción').toUpperCase();

        document.getElementById('modalDetalle').style.display = 'block';
    }
    function cerrarModalDetalle() {
        document.getElementById('modalDetalle').style.display = 'none';
    }

    function confirmarEliminacion(codGasto) {
        let codigoFormateado = String(codGasto).padStart(6, '0');
        if (confirm(`¿Estás seguro de inhabilitar el gasto #${codigoFormateado}?\n\nEl registro pasará a estado INACTIVO y se retirará de la lista principal.`)) {
            window.location.href = `gastos.php?accion=eliminar&cod_gasto=${codGasto}`;
        }
    }

    function confirmarEliminarTodosInactivos() {
        if (confirm("⚠️ ¿ATENCIÓN: Estás seguro de eliminar PERMANENTEMENTE todos los gastos inactivos?\n\nEsta acción borrará de la base de datos todos los registros inactivos y no se podrá deshacer.")) {
            window.location.href = "gastos.php?accion=eliminar_todos_inactivos";
        }
    }

    function confirmarReactivacion(codGasto) {
        let codigoFormateado = String(codGasto).padStart(6, '0');
        if (confirm(`¿Deseas reactivar el gasto #${codigoFormateado}?\n\nVolverá a estar visible en la tabla principal.`)) {
            window.location.href = `gastos.php?accion=reactivar&cod_gasto=${codGasto}`;
        }
    }

    function formatearFecha(fechaStr) {
        let d = new Date(fechaStr + 'T00:00:00');
        if (isNaN(d.getTime())) return fechaStr;
        let day = String(d.getDate()).padStart(2, '0');
        let month = String(d.getMonth() + 1).padStart(2, '0');
        let year = d.getFullYear();
        return `${day}/${month}/${year}`;
    }

    window.addEventListener('click', function(e) {
        let modalDet = document.getElementById('modalDetalle');
        let modalNvo = document.getElementById('modalNuevoGasto');
        let modalIna = document.getElementById('modalInactivos');
        if (e.target === modalDet) cerrarModalDetalle();
        if (e.target === modalNvo) cerrarModalNuevo();
        if (e.target === modalIna) cerrarModalInactivos();
    });
</script>

<?php
$ruta_footer = __DIR__ . '/footer.php';
if (file_exists($ruta_footer)) {
    require_once($ruta_footer);
}
?>
</body>
</html>