<?php
date_default_timezone_set('America/Guayaquil');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'fpdf/fpdf.php';

$configPath = realpath(__DIR__ . '/config/config.php');
if ($configPath && file_exists($configPath)) {
    require_once $configPath;
} else {
    $configPath = realpath(__DIR__ . '/../config/config.php');
    if ($configPath && file_exists($configPath)) {
        require_once $configPath;
    }
}

if (!isset($_SESSION['tamano_impresion_default'])) {
    $_SESSION['tamano_impresion_default'] = '58mm';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_config_impresion') {
    header('Content-Type: application/json');
    $tamano = $_POST['tamano'] ?? '58mm';
    $_SESSION['tamano_impresion_default'] = $tamano;
    echo json_encode(['status' => 'success', 'tamano' => $tamano]);
    exit;
}

$error_db = null;
$articulos = [];
$formas_pago_sri = [];
$datos_empresa = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    $stmt = $pdo->query("SELECT * FROM empresa LIMIT 1");
    $datos_empresa = $stmt->fetch();
    
    if (!$datos_empresa) {
        throw new Exception("No se encontró configuración de la empresa en la base de datos. Por favor, registre los datos en la tabla 'empresa'.");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_cliente_local') {
        header('Content-Type: application/json');
        try {
            $stmt = $pdo->prepare("INSERT INTO clientes (nombres, direccion, telefono, ci_ruc, tipo_identificacion, correo, localidad, provincia, canton) VALUES (?, ?, ?, ?, ?, ?, '', '', '')");
            $stmt->execute([
                $_POST['nombres'],
                $_POST['direccion'],
                $_POST['telefono'],
                $_POST['ci_ruc'],
                $_POST['tipo_identificacion'],
                $_POST['correo']
            ]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_venta') {
        if (ob_get_level()) ob_clean(); // ← Limpia cualquier salida anterior o warnings
        header('Content-Type: application/json');
        try {
            $pdo->beginTransaction();
            
            $cliente = json_decode($_POST['cliente'], true);
            $venta = json_decode($_POST['venta'], true);
            $detalle = json_decode($_POST['detalle'], true);
            
            $subtotal = 0;
            $iva_total = 0;
            $total = 0;
            $detalleCalculado = [];
            
            foreach ($detalle as $item) {
                $precioConIva = $item['precioConIva'];
                $cantidad = $item['cantidad'];
                $porcIva = $item['porcIva'];
                
                $subtotalItem = ($precioConIva * $cantidad) / (1 + ($porcIva / 100));
                $ivaItem = $subtotalItem * ($porcIva / 100);
                
                $subtotal += $subtotalItem;
                $iva_total += $ivaItem;
                $total += $precioConIva * $cantidad;
                
                $detalleCalculado[] = [
                    'item' => $item,
                    'subtotalItem' => $subtotalItem,
                    'ivaItem' => $ivaItem,
                    'totalItem' => $precioConIva * $cantidad
                ];
            }
            
            $descuento_total = 0;
            if ($venta['descuento_general'] > 0) {
                $descuento_total = $total * ($venta['descuento_general'] / 100);
                $total -= $descuento_total;
                $subtotal -= $subtotal * ($venta['descuento_general'] / 100);
            }
            
            $usuario_id = isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : null;
            $tipo_documento = $venta['tipo_documento'];

            if ($tipo_documento === 'factura') {
                if (trim($cliente['nombres']) === 'CONSUMIDOR FINAL') {
                    $cliente['direccion'] = 'Sin dirección registrada';
                    $cliente['correo'] = 'sin-correo@consumidor.final';
                }
                if (empty(trim($cliente['correo']))) {
                    $cliente['correo'] = 'sin-correo@empresa.com';
                }
                if (empty(trim($cliente['direccion']))) {
                    $cliente['direccion'] = 'Dirección no especificada';
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO ventas (
                    fecha,
                    tipo_documento, 
                    forma_pago, 
                    observaciones, 
                    cliente_ci_ruc, 
                    cliente_nombres, 
                    cliente_direccion, 
                    cliente_telefono, 
                    cliente_correo, 
                    cliente_tipo_identificacion,
                    subtotal, 
                    iva_total, 
                    descuento_total, 
                    total,
                    usuario_id,
                    estado
                ) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completada')
            ");
            
            $stmt->execute([
                $tipo_documento,
                $venta['forma_pago'],
                $venta['observaciones'],
                $cliente['ci_ruc'],
                $cliente['nombres'],
                $cliente['direccion'],
                $cliente['telefono'],
                $cliente['correo'],
                $cliente['tipo_identificacion'],
                $subtotal,
                $iva_total,
                $descuento_total,
                $total,
                $usuario_id
            ]);
            
            $ventaId = $pdo->lastInsertId();
            
            foreach ($detalleCalculado as $itemData) {
            $item = $itemData['item'];
            $precioConIva = $item['precioConIva'];
            $cantidad = $item['cantidad'];
            $porcIva = $item['porcIva'];
            $descuentoPorc = $item['descuentoPorc'] ?? 0; // ← Asegurar que esté definido
            $articuloId = $item['id'];
    
            $subtotalItem = $itemData['subtotalItem'];
            $ivaItem = $itemData['ivaItem'];
            $descuentoValor = $subtotalItem * ($descuentoPorc / 100);
                
                $stmt = $pdo->prepare("
                    INSERT INTO detalle_ventas (
                        venta_id,
                        articulo_id,
                        codigo_articulo,
                        nombre_articulo,
                        cantidad,
                        precio_unitario,
                        precio_total,
                        porcentaje_iva,
                        iva_valor,
                        descuento_porcentaje,
                        descuento_valor,
                        subtotal,
                        tipo_venta,
                        unidades_caja
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $ventaId,
                    $articuloId,
                    $item['codigo'],
                    $item['nombre'],
                    $cantidad,
                    $item['precioConIva'],
                    $precioConIva * $cantidad,
                    $porcIva,
                    $ivaItem,
                    $descuentoPorc,
                    $descuentoValor,
                    $subtotalItem,
                    $item['tipoVenta'] ?? 'unidad',
                    $item['unidadesCaja'] ?? 0
                ]);
                
                $stmt = $pdo->prepare("UPDATE articulos SET cantidad = cantidad - ? WHERE id = ? AND cantidad >= ?");
                $stmt->execute([$cantidad, $articuloId, $cantidad]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception("Stock insuficiente para el artículo: " . $item['nombre']);
                }
            }
            
            $tipoDoc = strtoupper($tipo_documento);
            $fechaDoc = date('Ymd');
            $secuencial = str_pad($ventaId, 9, '0', STR_PAD_LEFT);
            $numero_documento = $tipoDoc . '-' . $fechaDoc . '-' . $secuencial;
            $factura_electronica = null;
            $sriInfo = null;

            if ($tipo_documento === 'factura') {
                if (!class_exists('SriException')) {
                    class SriException extends Exception {}
                }

                try {
                    require_once __DIR__ . '/sri/exceptions/SriException.php';
                    require_once __DIR__ . '/sri/SriConfig.php';
                    require_once __DIR__ . '/sri/SriAuth.php';
                    require_once __DIR__ . '/sri/SriXmlGenerator.php';
                    require_once __DIR__ . '/sri/SriSoapClient.php';
                    require_once __DIR__ . '/sri/FacturaElectronica.php';

                    if (!class_exists('FacturaElectronica')) {
                        throw new Exception("La clase FacturaElectronica no se pudo cargar.");
                    }

                    $facturaElectronica = new FacturaElectronica($pdo);
                    $resultado = $facturaElectronica->procesarFactura($ventaId);
                    $factura_electronica = $resultado;

                    $stmtSri = $pdo->prepare("SELECT * FROM sri_comprobantes WHERE venta_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtSri->execute([$ventaId]);
                    $sriInfo = $stmtSri->fetch();

                    if (isset($factura_electronica['numero_autorizacion']) && !empty($factura_electronica['numero_autorizacion'])) {
                        $numero_documento = $factura_electronica['numero_autorizacion'];
                    }

                } catch (\Throwable $e) {
                    $errorMsg = $e->getMessage();
                    $factura_electronica = [
                        'status' => 'error',
                        'mensaje' => 'Error en facturación electrónica: ' . $errorMsg,
                        'numero_autorizacion' => null
                    ];

                    try {
                        $stmtError = $pdo->prepare("
                            INSERT INTO sri_comprobantes (
                                venta_id, 
                                tipo, 
                                estado, 
                                mensaje, 
                                fecha_creacion
                            ) VALUES (?, 'factura', 'error', ?, NOW())
                        ");
                        $stmtError->execute([$ventaId, $errorMsg]);
                    } catch (\Exception $e2) {
                        }

                    error_log("Error en facturación electrónica: " . $errorMsg);
                    error_log("Stack trace: " . $e->getTraceAsString());
                }

                if ($factura_electronica === null) {
                    $factura_electronica = [
                        'status' => 'error',
                        'mensaje' => 'Facturación electrónica no disponible',
                        'numero_autorizacion' => null
                    ];
                }
            }
            $stmt = $pdo->prepare("UPDATE ventas SET numero_documento = ? WHERE id = ?");
            $stmt->execute([$numero_documento, $ventaId]);
            
            $pdo->commit();
            
            $response_data = [
                'status' => 'success',
                'message' => 'Venta guardada exitosamente',
                'numero_documento' => $numero_documento,
                'venta_id' => $ventaId,
                'cliente' => $cliente,
                'venta' => $venta,
                'detalle' => $detalle,
                'subtotal' => $subtotal,
                'iva_total' => $iva_total,
                'descuento_total' => $descuento_total,
                'total' => $total,
                'fecha' => date('d/m/Y H:i:s')
            ];
            
            if ($factura_electronica !== null) {
                $response_data['factura_electronica'] = $factura_electronica;
            }
            
            if ($sriInfo !== null) {
                $response_data['sri_info'] = $sriInfo;
            }
            
            $response_data['es_factura'] = ($tipo_documento === 'factura');
            
            echo json_encode($response_data);
            
        } catch (\Throwable $e) {
        $pdo->rollBack();
        if (ob_get_level()) ob_clean(); 
            echo json_encode([
            'status' => 'error', 
            'message' => 'Error al guardar la venta: ' . $e->getMessage()
            ]);
        }
    exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'generar_recibo') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        try {
            $tamano_impresion = $_POST['tamano_impresion'] ?? $_SESSION['tamano_impresion_default'] ?? '58mm';
            
            $datos = json_decode($_POST['datos'], true);
            if (!isset($datos['venta_id'])) {
                throw new Exception("ID de venta no proporcionado");
            }
            
            $venta_id = intval($datos['venta_id']);
            
            $stmt = $pdo->prepare("SELECT * FROM ventas WHERE id = ?");
            $stmt->execute([$venta_id]);
            $venta = $stmt->fetch();
            
            if (!$venta) {
                throw new Exception("Venta no encontrada con ID: " . $venta_id);
            }
            
            $sriInfo = null;
            $esFactura = ($venta['tipo_documento'] === 'factura');
            
            if ($esFactura) {
                $stmtSri = $pdo->prepare("SELECT * FROM sri_comprobantes WHERE venta_id = ? ORDER BY id DESC LIMIT 1");
                $stmtSri->execute([$venta_id]);
                $sriInfo = $stmtSri->fetch();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM detalle_ventas WHERE venta_id = ?");
            $stmt->execute([$venta_id]);
            $detalle = $stmt->fetchAll();
            
            $usuario_nombre = isset($_SESSION['usuario_nombre']) ? $_SESSION['usuario_nombre'] : 'DIEDAY SOFT';
            
            if ($esFactura) {
                if ($tamano_impresion === 'A4') {
                    $reciboPath = __DIR__ . '/factura_a4.php';
                } elseif ($tamano_impresion === '80mm') {
                    $reciboPath = __DIR__ . '/factura_80.php';
                } else {
                    $reciboPath = __DIR__ . '/factura_58.php';
                }
            } else {
                if ($tamano_impresion === 'A4') {
                    $reciboPath = __DIR__ . '/recibo_a4.php';
                } elseif ($tamano_impresion === '80mm') {
                    $reciboPath = __DIR__ . '/recibo_80.php';
                } else {
                    $reciboPath = __DIR__ . '/recibo_58.php';
                }
            }
            
            if (!file_exists($reciboPath)) {
                throw new Exception("No se encontró el archivo: " . $reciboPath);
            }
            
            $stmtEmpresa = $pdo->query("SELECT * FROM empresa LIMIT 1");
            $empresa = $stmtEmpresa->fetch();
            if (!$empresa) {
                throw new Exception("No se encontró configuración de la empresa en la base de datos.");
            }
            
            require_once $reciboPath;
            
            if ($esFactura) {
                if ($tamano_impresion === 'A4') {
                    if (!class_exists('FacturaA4')) {
                        throw new Exception("La clase FacturaA4 no está definida en factura_a4.php");
                    }
                    $recibo = new FacturaA4($empresa, $venta, $detalle, $usuario_nombre, $sriInfo);
                } elseif ($tamano_impresion === '80mm') {
                    if (!class_exists('Factura80mm')) {
                        throw new Exception("La clase Factura80mm no está definida en factura_80.php");
                    }
                    $recibo = new Factura80mm($empresa, $venta, $detalle, $usuario_nombre, $sriInfo);
                } else {
                    if (!class_exists('Factura58mm')) {
                        throw new Exception("La clase Factura58mm no está definida en factura_58.php");
                    }
                    $recibo = new Factura58mm($empresa, $venta, $detalle, $usuario_nombre, $sriInfo);
                }
            } else {
                if ($tamano_impresion === 'A4') {
                    if (!class_exists('ReciboA4')) {
                        throw new Exception("La clase ReciboA4 no está definida en recibo_a4.php");
                    }
                    $recibo = new ReciboA4($empresa, $venta, $detalle, $usuario_nombre);
                } elseif ($tamano_impresion === '80mm') {
                    if (!class_exists('Recibo80mm')) {
                        throw new Exception("La clase Recibo80mm no está definida en recibo_80.php");
                    }
                    $recibo = new Recibo80mm($empresa, $venta, $detalle, $usuario_nombre);
                } else {
                    if (!class_exists('Recibo58mm')) {
                        throw new Exception("La clase Recibo58mm no está definida en recibo_58.php");
                    }
                    $recibo = new Recibo58mm($empresa, $venta, $detalle, $usuario_nombre);
                }
            }
            
            $pdfContent = $recibo->generar();
            
            if (empty($pdfContent)) {
                throw new Exception("El contenido del PDF está vacío");
            }
            
            $nombreArchivo = ($esFactura ? 'Factura_' : 'Recibo_') . $venta['numero_documento'] . '_' . $tamano_impresion . '.pdf';
            
            echo json_encode([
                'status' => 'success',
                'pdf' => base64_encode($pdfContent),
                'nombre_archivo' => $nombreArchivo,
                'tamano' => $tamano_impresion,
                'es_factura' => $esFactura,
                'sri_info' => $sriInfo
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Error al generar documento: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    $articulos = $pdo->query("SELECT id, cod_articulo, nombre, venta_con_iva, porcentaje_iva, cantidad, envase, unidades_caja, precio_unidad, precio2, precio3, precio4 FROM articulos WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll();
    
    $formas_pago_sri = $pdo->query("SELECT nombre, cod_pago FROM formas_pago_sri ORDER BY CAST(cod_pago AS UNSIGNED) ASC")->fetchAll();

} catch (PDOException $e) {
    $error_db = "Error de base de datos: " . $e->getMessage();
} catch (Exception $e) {
    $error_db = $e->getMessage();
}

if (isset($_GET['buscar_cliente_local'])) {
    header('Content-Type: application/json');
    $identificacion = trim($_GET['buscar_cliente_local']);
    
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE ci_ruc = ? LIMIT 1");
    $stmt->execute([$identificacion]);
    $cliente = $stmt->fetch();
    echo json_encode($cliente ? $cliente : null);
    exit;
}

$tamano_default = $_SESSION['tamano_impresion_default'] ?? '58mm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Factura | ZOE</title>
    <style>
        :root { 
            --azul-header: #3f5891; 
            --fondo-gris: #f4f6f9; 
            --verde-exito: #28a745; 
            --rojo-peligro: #dc3545;
            --gris-limpiar: #6c757d;
            --dorado: #ffc107;
            --naranja: #fd7e14;
            --morado: #6f42c1;
        }
        body { background-color: var(--fondo-gris); font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; }
        .full-wrapper { padding: 15px; }
        .card-full { background: #fff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 20px; }
        .card-full-header { background-color: var(--azul-header); color: white; padding: 12px 20px; font-weight: bold; text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px; display: flex; justify-content: space-between; align-items: center; }
        .card-full-header .btn-config { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s; }
        .card-full-header .btn-config:hover { background: rgba(255,255,255,0.3); }
        .factura-container { display: grid; grid-template-columns: 1fr 350px; gap: 20px; }
        @media (max-width: 900px) { .factura-container { grid-template-columns: 1fr; } }
        
        .doc-selector-container {
            background: #fff;
            padding: 10px 15px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid #eee;
            border-radius: 5px;
        }
        .doc-label { font-size: 13px; font-weight: 800; color: var(--azul-header); text-transform: uppercase; }
        .switch-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .switch-label { font-size: 10px; font-weight: bold; color: var(--azul-header); }
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 20px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 20px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px; width: 14px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--azul-header); }
        input:checked + .slider:before { transform: translateX(24px); }

        .seccion-datos { padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .seccion-articulos { padding: 20px; }
        .buscador-caja { display: flex; gap: 10px; margin-bottom: 20px; background: #fff; border-radius: 5px; flex-wrap: wrap; }
        
        .table-detalle { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table-detalle th { background: #fafafa; padding: 12px; font-size: 11px; color: #555; border-bottom: 2px solid #eee; text-align: left; text-transform: uppercase; }
        .table-detalle td { padding: 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        
        .form-group label { display: block; font-size: 11px; font-weight: bold; margin-bottom: 5px; color: #555; text-transform: uppercase; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        
        .caja-totales { padding: 20px; background: #343a40; color: white; border-radius: 8px; }
        .total-grande { font-size: 28px; font-weight: bold; color: var(--dorado); text-align: center; margin: 10px 0; }
        
        .btn-custom { padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; color: white; text-transform: uppercase; font-size: 12px; }
        .btn-verde { background: var(--verde-exito); width: 100%; margin-top: 15px; height: 45px; font-size: 14px; }
        .btn-azul { background: var(--azul-header); }
        .btn-limpiar { background: var(--gris-limpiar); width: 100%; margin-top: 10px; }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; text-align: center; max-width: 450px; width: 90%; }
        .modal-content.config-modal { max-width: 550px; text-align: left; }
        .modal-content.config-modal h3 { text-align: center; color: var(--azul-header); margin-top: 0; }
        .modal-content.config-modal .form-group { margin-bottom: 15px; }
        .modal-content.config-modal .form-group label { font-weight: bold; display: block; margin-bottom: 5px; color: #333; text-transform: none; }
        .modal-content.config-modal .form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .modal-content.config-modal .btn-group { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .modal-content.config-modal .btn-group .btn-custom { padding: 8px 25px; }
        .modal-content.config-modal .tamano-opciones { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 12px; 
            margin: 15px 0; 
        }
        .modal-content.config-modal .tamano-opcion { 
            padding: 15px 10px; 
            border: 2px solid #ddd; 
            border-radius: 8px; 
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        .modal-content.config-modal .tamano-opcion:hover { 
            border-color: var(--azul-header); 
            background: #f0f4ff;
            transform: scale(1.02);
        }
        .modal-content.config-modal .tamano-opcion.seleccionado { 
            border-color: var(--azul-header); 
            background: #e8edf9;
            box-shadow: 0 0 0 3px rgba(63, 88, 145, 0.2);
        }
        .modal-content.config-modal .tamano-opcion .icono { font-size: 32px; display: block; margin-bottom: 5px; }
        .modal-content.config-modal .tamano-opcion .label { font-weight: bold; font-size: 14px; display: block; }
        .modal-content.config-modal .tamano-opcion .desc { font-size: 11px; color: #666; display: block; }
        .tamano-actual { font-size: 12px; color: #999; margin-top: 5px; text-align: center; }
        .tamano-actual strong { color: var(--azul-header); }
        #loaderApi { display: none; color: var(--azul-header); font-size: 11px; font-weight: bold; }
        .fila-total { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px; }

        .editable-precio { cursor: pointer; color: #007bff; font-weight: bold; }
        .editable-precio:hover { text-decoration: underline; }
        .input-inline-edit { width: 80px; padding: 4px; border: 1px solid var(--azul-header); border-radius: 3px; font-weight: bold; }
        .input-desc-tabla { width: 60px; padding: 4px; border: 1px solid #ccc; border-radius: 3px; text-align: center; }
        
        .tipo-venta-selector {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        .tipo-venta-btn {
            flex: 1;
            padding: 5px;
            background: #f0f0f0;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            transition: all 0.3s;
        }
        .tipo-venta-btn.active {
            background: var(--azul-header);
            color: white;
            border-color: var(--azul-header);
        }
        .tipo-venta-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        .precio-info {
            font-size: 10px;
            color: #666;
            margin-top: 3px;
        }
        .oculto {
            display: none;
        }

        .precio-adicional-selector {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .precio-adicional-btn {
            background: #f0f0f0;
            border: 2px solid #ddd;
            border-radius: 5px;
            padding: 8px 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
            min-width: 80px;
        }
        .precio-adicional-btn:hover {
            background: #e0e0e0;
        }
        .precio-adicional-btn.active {
            background: var(--azul-header);
            border-color: var(--azul-header);
            color: white;
        }
        .precio-adicional-btn .precio-label {
            font-size: 10px;
            font-weight: bold;
            display: block;
        }
        .precio-adicional-btn .precio-valor {
            font-size: 14px;
            font-weight: bold;
        }

        .checkbox-consumidor {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 10px;
            background: #f8f0ff;
            border-radius: 5px;
            border: 1px solid #6f42c1;
        }
        .checkbox-consumidor label {
            font-size: 13px;
            font-weight: bold;
            color: #6f42c1;
            cursor: pointer;
            text-transform: uppercase;
        }
        .checkbox-consumidor input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .btn-imprimir {
            background: var(--naranja);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin: 10px 5px;
            transition: all 0.3s;
        }
        .btn-imprimir:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .btn-continuar {
            background: var(--verde-exito);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin: 10px 5px;
            transition: all 0.3s;
        }
        .btn-continuar:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .modal-exito .modal-content {
            max-width: 500px;
        }
        .modal-exito .modal-body {
            padding: 20px 0;
        }
        .modal-exito .icono-exito {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .modal-exito .btn-group {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .btn-imprimir:focus, .btn-continuar:focus {
            outline: 2px solid #333;
            outline-offset: 2px;
        }

        .btn-config-modal {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            padding: 4px 14px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .btn-config-modal:hover {
            background: rgba(255,255,255,0.25);
            transform: scale(1.02);
        }
        .btn-config-modal .tamano-actual-header {
            font-weight: bold;
        }

        .badge-58mm {
            background: var(--verde-exito);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 5px;
        }
        .badge-80mm {
            background: #17a2b8;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 5px;
        }
        .badge-A4 {
            background: var(--morado);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 5px;
        }
    </style>
</head>
<body>

<?php if (file_exists('menu.php')) include_once 'menu.php'; ?>

<div id="modal_error" class="modal-overlay">
    <div class="modal-content">
        <h3 id="modal_title" style="color: var(--rojo-peligro);">¡Atención!</h3>
        <p id="modal_msg" style="margin-bottom: 20px;"></p>
        <button id="btn_modal_aceptar" class="btn-custom btn-azul" onclick="cerrarModal()">ACEPTAR</button>
    </div>
</div>

<div id="modal_config_impresion" class="modal-overlay">
    <div class="modal-content config-modal">
        <h3>⚙️ Configuración de Impresión</h3>
        <p style="text-align: center; color: #666; margin-bottom: 15px; font-size: 13px;">
            Selecciona el tamaño de papel para tus documentos
        </p>
        
        <div class="tamano-opciones">
            <div class="tamano-opcion <?= $tamano_default == '58mm' ? 'seleccionado' : '' ?>" 
                 onclick="seleccionarTamanoModal('58mm')" 
                 id="opcion_58mm">
                <span class="icono">📄</span>
                <span class="label">58 mm</span>
                <span class="desc">Ticket estándar</span>
            </div>
            <div class="tamano-opcion <?= $tamano_default == '80mm' ? 'seleccionado' : '' ?>" 
                 onclick="seleccionarTamanoModal('80mm')" 
                 id="opcion_80mm">
                <span class="icono">📃</span>
                <span class="label">80 mm</span>
                <span class="desc">Ticket ancho</span>
            </div>
            <div class="tamano-opcion <?= $tamano_default == 'A4' ? 'seleccionado' : '' ?>" 
                 onclick="seleccionarTamanoModal('A4')" 
                 id="opcion_A4">
                <span class="icono">📑</span>
                <span class="label">A4</span>
                <span class="desc">Factura / Carta</span>
            </div>
        </div>
        
        <div class="tamano-actual">
            Tamaño actual: <strong id="tamano_actual_modal"><?= $tamano_default ?></strong>
        </div>
        
        <div class="btn-group">
            <button class="btn-custom" style="background: var(--gris-limpiar);" onclick="cerrarModalConfiguracion()">CANCELAR</button>
            <button class="btn-custom btn-azul" onclick="guardarConfiguracionModal()">GUARDAR</button>
        </div>
    </div>
</div>

<div id="modal_exito" class="modal-overlay modal-exito" style="display: none;">
    <div class="modal-content">
        <div class="icono-exito">✅</div>
        <h3 id="exito_title" style="color: var(--verde-exito); margin: 0 0 10px 0;">¡Venta guardada exitosamente!</h3>
        <div class="modal-body" id="exito_body">
            <p><strong>Documento:</strong> <span id="exito_documento"></span></p>
            <p><strong>ID Venta:</strong> #<span id="exito_venta_id"></span></p>
            <p><strong>Total:</strong> $<span id="exito_total"></span></p>
            <p><strong>Cliente:</strong> <span id="exito_cliente"></span></p>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">
                📄 Documento <span id="exito_tamano">58mm</span> 
                <span id="exito_badge" class="badge-58mm">OPTIMIZADO</span>
            </p>
        </div>
        <div class="btn-group">
            <button class="btn-imprimir" id="btn_imprimir" onclick="imprimirRecibo()">🖨️ Imprimir Recibo (Enter)</button>
            <button class="btn-continuar" onclick="continuarSinImprimir()">➡️ Continuar</button>
        </div>
        <div id="loader_pdf" style="display: none; margin-top: 10px; color: var(--azul-header);">
            Generando recibo...
        </div>
    </div>
</div>

<div class="full-wrapper">
    <div class="factura-container">
        <div class="left-column">
            <div class="card-full">
                <div class="card-full-header">
                    Datos del Cliente
                    <span style="font-size: 11px; font-weight: normal;">
                        <span id="badge_tamano_header" class="badge-58mm"><?= $tamano_default ?></span>
                    </span>
                </div>
                <div class="seccion-datos">
                    <div class="form-group">
                        <label>Tipo Identificación</label>
                        <select id="tipo_identificacion" class="form-control" onchange="limpiarCamposIdentificacion()">
                            <option value="CEDULA">CÉDULA</option>
                            <option value="RUC">RUC</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Identificación (Enter para buscar)</label>
                        <input type="text" id="ci_ruc" class="form-control" onkeypress="if(event.key === 'Enter') buscarClienteCompleto()">
                        <span id="loaderApi">Consultando...</span>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nombres / Razón Social</label>
                        <input type="text" id="nombres" class="form-control" oninput="convertirMayus(this)">
                    </div>
                    <div class="form-group">
                        <label>Correo</label>
                        <input type="email" id="correo" class="form-control" onkeypress="if(event.key === 'Enter') saltarAlSiguiente('direccion')">
                    </div>
                    <div class="form-group">
                        <label>Dirección</label>
                        <input type="text" id="direccion" class="form-control" oninput="convertirMayus(this)" onkeypress="if(event.key === 'Enter') saltarAlSiguiente('telefono')">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" id="telefono" class="form-control" onkeypress="if(event.key === 'Enter') manejarGuardadoYEnfoque()">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <div class="checkbox-consumidor">
                            <input type="checkbox" id="chk_consumidor_final" onchange="toggleConsumidorFinal()">
                            <label for="chk_consumidor_final">Facturar a CONSUMIDOR FINAL</label>
                        </div>
                    </div>
                </div>

                <div class="card-full-header">Selección de Productos</div>
                <div class="seccion-articulos">
                    <div class="buscador-caja">
                        <div style="flex: 0.5; min-width: 120px;" class="form-group">
                            <label>Código Art.</label>
                            <input type="text" id="input_cod_manual" class="form-control" placeholder="Cód." oninput="buscarPorCodigo()" onkeypress="if(event.key === 'Enter') validarYEnfocar('codigo')">
                        </div>
                        <div style="flex: 1.5; min-width: 200px;" class="form-group">
                            <label>Nombre del Producto</label>
                            <input list="lista_articulos" id="input_nombre_buscar" class="form-control" placeholder="Buscar producto..." oninput="buscarPorNombre()" onkeypress="if(event.key === 'Enter') validarYEnfocar('nombre')">
                            <datalist id="lista_articulos">
                                <?php foreach($articulos as $art): ?>
                                    <option value="<?= htmlspecialchars($art['nombre']) ?>">Código: <?= htmlspecialchars($art['cod_articulo']) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div style="flex: 0.4; min-width: 80px;" class="form-group">
                            <label>Cant.</label>
                            <input type="number" id="input_cant" class="form-control" value="1" min="1" onkeypress="if(event.key === 'Enter') agregarItem()">
                        </div>
                        <div style="flex: 0.5; min-width: 100px;" class="form-group" id="grupo_tipo_venta">
                            <label>Tipo Venta</label>
                            <div class="tipo-venta-selector">
                                <div id="tipo_venta_unidad" class="tipo-venta-btn active" onclick="cambiarTipoVenta('unidad')">Unidad</div>
                                <div id="tipo_venta_caja" class="tipo-venta-btn" onclick="cambiarTipoVenta('caja')">Caja</div>
                            </div>
                            <div id="precio_info" class="precio-info"></div>
                        </div>
                        <div style="display: flex; align-items: flex-end; padding-bottom: 1px;">
                            <button class="btn-custom btn-azul" onclick="agregarItem()">AGREGAR</button>
                        </div>
                    </div>

                    <div id="selector_precios_adicionales" style="display: none; margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-radius: 5px;">
                        <label style="font-size: 11px; font-weight: bold; color: #555; text-transform: uppercase; display: block; margin-bottom: 8px;">Seleccionar Precio:</label>
                        <div id="precios_adicionales_container" class="precio-adicional-selector"></div>
                    </div>

                    <input type="hidden" id="temp_id">
                    <input type="hidden" id="temp_precio">
                    <input type="hidden" id="temp_iva">
                    <input type="hidden" id="temp_nombre">
                    <input type="hidden" id="temp_codigo">
                    <input type="hidden" id="temp_envase">
                    <input type="hidden" id="temp_unidades_caja">
                    <input type="hidden" id="temp_precio_unidad">
                    <input type="hidden" id="temp_precio2">
                    <input type="hidden" id="temp_precio3">
                    <input type="hidden" id="temp_precio4">

                    <table class="table-detalle" id="tabla_detalle">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th>Cant.</th>
                                <th>P. Unit</th>
                                <th>Desc %</th>
                                <th>Iva</th>
                                <th>Subtotal</th>
                                <th style="text-align: center;">Quitar</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="right-column">
            <div class="card-full">
                <div class="card-full-header">
                    Resumen de Venta
                    <button class="btn-config-modal" onclick="abrirModalConfiguracion()">
                        ⚙️ <span class="tamano-actual-header" id="btn_tamano_actual"><?= $tamano_default ?></span>
                    </button>
                </div>
                <div class="caja-totales">
                    <div id="contenedor_desglose_resumen"></div>
                    <div class="fila-total">
                        <span>Desc. General %</span>
                        <input type="number" id="desc_general" value="0" min="0" max="100" class="form-control" style="width: 70px; height: 25px; text-align: center; font-size: 12px;" oninput="renderTabla()">
                    </div>
                    <hr style="border: 0; border-top: 1px solid #555; margin: 10px 0;">
                    <div class="fila-total">
                        <span style="font-weight: bold;">TOTAL IVA</span>
                        <span id="res_ivaVal">$ 0.00</span>
                    </div>
                    <div class="total-grande" id="res_total">$ 0.00</div>
                </div>
            </div>

            <div class="card-full" style="padding: 15px;">
                <div class="doc-selector-container">
                    <span class="doc-label">Documento:</span>
                    <div class="switch-container">
                        <span class="switch-label">RECIBO</span>
                        <label class="switch">
                            <input type="checkbox" id="tipo_doc_switch" onchange="cambiarTipoDocumento()">
                            <span class="slider"></span>
                        </label>
                        <span class="switch-label">FACTURA</span>
                    </div>
                    <input type="hidden" id="tipo_documento" value="recibo">
                </div>

                <div class="form-group">
                    <label>Forma de Pago</label>
                    <select class="form-control" id="forma_pago" style="height: 40px; font-weight: bold;">
                    </select>
                </div>
                <div class="form-group" style="margin-top: 15px;">
                    <label>Observaciones / Nota</label>
                    <textarea class="form-control" rows="3" id="observaciones" placeholder="Escriba aquí cualquier detalle adicional..."></textarea>
                </div>

                <button class="btn-custom btn-verde" onclick="procesarVenta()">FACTURAR</button>
                <button class="btn-custom btn-limpiar" onclick="limpiarTodoFormulario()">LIMPIAR TODO</button>
            </div>
        </div>
    </div>
</div>

<script>
    const TOKEN_CEDULA = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJlbXByZXNhIjoiRGllRGF5IFNvZnQuIiwiaWF0IjoxNzgwMDA0ODQ2LCJleHAiOjE4MTE1NjI0NDZ9.sCDGgh0cjrfm5UukWe63bKK95bLY4HMx6I3fsDjvIpQ";
    const TOKEN_RUC    = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJlbXByZXNhIjoiRGllZ28gRGFycXVlYSIsImlhdCI6MTc3NjM3Mjk5OSwiZXhwIjoxODA3OTMwNTk5fQ.65Wm3h5m7N_R_bJzL8jJ12O4OWWWGr-I2e6neDe8Ah0";
    
    const baseArticulos = <?php echo json_encode($articulos); ?>;
    const formasPagoSRI = <?php echo json_encode($formas_pago_sri); ?>;
    let tamanoImpresionActual = '<?= $tamano_default ?>';
    let tamanoSeleccionadoModal = '<?= $tamano_default ?>';
    
    let detalleFactura = [];
    let esClienteNuevo = false;
    let tipoVentaSeleccionado = 'unidad';
    let articuloSeleccionado = null;
    let articuloTieneCaja = false;
    let precioSeleccionado = null;
    let preciosAdicionales = [];
    let ultimaVentaData = null;

    window.onload = function() {
        const inputCi = document.getElementById('ci_ruc');
        inputCi.value = "9999999999";
        inputCi.focus();
        buscarClienteCompleto();
        cambiarTipoDocumento(); 
        document.getElementById('grupo_tipo_venta').style.display = 'none';
        actualizarBadgesTamano();
        
        document.addEventListener('keydown', function(e) {
            const modalError = document.getElementById('modal_error');
            if (modalError.style.display === 'flex' && e.key === 'Enter') {
                e.preventDefault();
                cerrarModal();
            }
            
            const modalExito = document.getElementById('modal_exito');
            if (modalExito.style.display === 'flex' && e.key === 'Enter') {
                e.preventDefault();
                const btnImprimir = document.getElementById('btn_imprimir');
                if (btnImprimir && !btnImprimir.disabled) {
                    btnImprimir.click();
                }
            }
            
            if (e.key === 'Escape') {
                const modalConfig = document.getElementById('modal_config_impresion');
                if (modalConfig.style.display === 'flex') {
                    cerrarModalConfiguracion();
                    e.preventDefault();
                }
            }
        });
    };

    function actualizarBadgesTamano() {
        const tamano = tamanoImpresionActual;
        const badgeHeader = document.getElementById('badge_tamano_header');
        const btnTamano = document.getElementById('btn_tamano_actual');
        const modalTamano = document.getElementById('tamano_actual_modal');
        
        badgeHeader.textContent = tamano;
        btnTamano.textContent = tamano;
        if (modalTamano) modalTamano.textContent = tamano;
        
        badgeHeader.className = '';
        if (tamano === '58mm') {
            badgeHeader.className = 'badge-58mm';
        } else if (tamano === '80mm') {
            badgeHeader.className = 'badge-80mm';
        } else if (tamano === 'A4') {
            badgeHeader.className = 'badge-A4';
        }
    }

    function abrirModalConfiguracion() {
        tamanoSeleccionadoModal = tamanoImpresionActual;
        document.getElementById('tamano_actual_modal').textContent = tamanoImpresionActual;
        
        document.querySelectorAll('.tamano-opcion').forEach(el => el.classList.remove('seleccionado'));
        if (tamanoSeleccionadoModal === '58mm') {
            document.getElementById('opcion_58mm').classList.add('seleccionado');
        } else if (tamanoSeleccionadoModal === '80mm') {
            document.getElementById('opcion_80mm').classList.add('seleccionado');
        } else if (tamanoSeleccionadoModal === 'A4') {
            document.getElementById('opcion_A4').classList.add('seleccionado');
        }
        
        document.getElementById('modal_config_impresion').style.display = 'flex';
    }

    function cerrarModalConfiguracion() {
        document.getElementById('modal_config_impresion').style.display = 'none';
    }

    function seleccionarTamanoModal(tamano) {
        tamanoSeleccionadoModal = tamano;
        document.querySelectorAll('.tamano-opcion').forEach(el => el.classList.remove('seleccionado'));
        if (tamano === '58mm') {
            document.getElementById('opcion_58mm').classList.add('seleccionado');
        } else if (tamano === '80mm') {
            document.getElementById('opcion_80mm').classList.add('seleccionado');
        } else if (tamano === 'A4') {
            document.getElementById('opcion_A4').classList.add('seleccionado');
        }
        document.getElementById('tamano_actual_modal').textContent = tamano;
    }

    function guardarConfiguracionModal() {
        tamanoImpresionActual = tamanoSeleccionadoModal;
        actualizarBadgesTamano();
        
        guardarConfiguracionImpresion();
        
        cerrarModalConfiguracion();
        
        if (document.getElementById('modal_exito').style.display === 'flex') {
            document.getElementById('exito_tamano').textContent = tamanoImpresionActual;
            const badgeExito = document.getElementById('exito_badge');
            if (tamanoImpresionActual === '58mm') {
                badgeExito.className = 'badge-58mm';
                badgeExito.textContent = 'TICKET';
            } else if (tamanoImpresionActual === '80mm') {
                badgeExito.className = 'badge-80mm';
                badgeExito.textContent = 'TICKET ANCHO';
            } else if (tamanoImpresionActual === 'A4') {
                badgeExito.className = 'badge-A4';
                badgeExito.textContent = 'FACTURA';
            }
        }
        
        mostrarModal('✅ Configuración guardada: ' + tamanoImpresionActual);
        setTimeout(() => cerrarModal(), 1500);
    }

    function guardarConfiguracionImpresion() {
        const formData = new FormData();
        formData.append('accion', 'guardar_config_impresion');
        formData.append('tamano', tamanoImpresionActual);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .catch(error => {
            console.error('Error al guardar configuración:', error);
        });
    }

    function toggleConsumidorFinal() {
        const chk = document.getElementById('chk_consumidor_final');
        const ciRuc = document.getElementById('ci_ruc');
        const nombres = document.getElementById('nombres');
        const correo = document.getElementById('correo');
        const direccion = document.getElementById('direccion');
        const telefono = document.getElementById('telefono');
        const tipoIdent = document.getElementById('tipo_identificacion');

        if (chk.checked) {
            const datosOriginales = {
                ci_ruc: ciRuc.value,
                nombres: nombres.value,
                correo: correo.value,
                direccion: direccion.value,
                telefono: telefono.value,
                tipo_identificacion: tipoIdent.value
            };
            chk.dataset.originalCi = datosOriginales.ci_ruc;
            chk.dataset.originalNombres = datosOriginales.nombres;
            chk.dataset.originalCorreo = datosOriginales.correo;
            chk.dataset.originalDireccion = datosOriginales.direccion;
            chk.dataset.originalTelefono = datosOriginales.telefono;
            chk.dataset.originalTipo = datosOriginales.tipo_identificacion;

            ciRuc.value = "9999999999";
            nombres.value = "CONSUMIDOR FINAL";
            correo.value = "";
            direccion.value = "";
            telefono.value = "";
            tipoIdent.value = "CEDULA";
            esClienteNuevo = false;
            
            ciRuc.disabled = true;
            nombres.disabled = true;
            correo.disabled = true;
            direccion.disabled = true;
            telefono.disabled = true;
            tipoIdent.disabled = true;
            
        } else {
            if (chk.dataset.originalCi) {
                document.getElementById('ci_ruc').value = chk.dataset.originalCi;
                document.getElementById('nombres').value = chk.dataset.originalNombres;
                document.getElementById('correo').value = chk.dataset.originalCorreo || "";
                document.getElementById('direccion').value = chk.dataset.originalDireccion || "";
                document.getElementById('telefono').value = chk.dataset.originalTelefono || "";
                document.getElementById('tipo_identificacion').value = chk.dataset.originalTipo || "CEDULA";
            }
            
            document.getElementById('ci_ruc').disabled = false;
            document.getElementById('nombres').disabled = false;
            document.getElementById('correo').disabled = false;
            document.getElementById('direccion').disabled = false;
            document.getElementById('telefono').disabled = false;
            document.getElementById('tipo_identificacion').disabled = false;
        }
    }

    function cambiarTipoVenta(tipo) {
        if (!articuloTieneCaja) return;
        
        tipoVentaSeleccionado = tipo;
        
        document.getElementById('tipo_venta_unidad').classList.remove('active');
        document.getElementById('tipo_venta_caja').classList.remove('active');
        document.getElementById(`tipo_venta_${tipo}`).classList.add('active');
        
        actualizarInfoPrecios();
        
        if (articuloSeleccionado) {
            actualizarPrecioTemporal();
        }
    }
    
    function actualizarInfoPrecios() {
        const precioInfoDiv = document.getElementById('precio_info');
        if (!articuloSeleccionado || !articuloTieneCaja) {
            precioInfoDiv.innerHTML = '';
            return;
        }
        
        const unidadesCaja = articuloSeleccionado.unidades_caja || 0;
        const precioUnidad = articuloSeleccionado.precio_unidad || 0;
        const precioCaja = articuloSeleccionado.venta_con_iva || 0;
        
        if (tipoVentaSeleccionado === 'unidad') {
            precioInfoDiv.innerHTML = `Precio unidad: $${parseFloat(precioUnidad).toFixed(2)}`;
        } else {
            if (unidadesCaja > 0) {
                precioInfoDiv.innerHTML = `Precio caja (${unidadesCaja} unds): $${parseFloat(precioCaja).toFixed(2)}`;
            } else {
                precioInfoDiv.innerHTML = `Este producto no tiene unidades por caja configuradas`;
            }
        }
    }
    
    function actualizarPrecioTemporal() {
        if (!articuloSeleccionado) return;
        
        let precio = 0;
        if (tipoVentaSeleccionado === 'unidad') {
            precio = precioSeleccionado || (articuloSeleccionado.precio_unidad && articuloSeleccionado.precio_unidad > 0 ? 
                     parseFloat(articuloSeleccionado.precio_unidad) : 
                     parseFloat(articuloSeleccionado.venta_con_iva));
        } else {
            precio = parseFloat(articuloSeleccionado.venta_con_iva);
        }
        
        document.getElementById('temp_precio').value = precio;
        
        document.getElementById('temp_unidades_caja').value = articuloSeleccionado.unidades_caja || 0;
        document.getElementById('temp_precio_unidad').value = articuloSeleccionado.precio_unidad || 0;
        document.getElementById('temp_precio2').value = articuloSeleccionado.precio2 || 0;
        document.getElementById('temp_precio3').value = articuloSeleccionado.precio3 || 0;
        document.getElementById('temp_precio4').value = articuloSeleccionado.precio4 || 0;
    }

    function mostrarSelectorPreciosAdicionales() {
        if (!articuloSeleccionado) return;
        
        preciosAdicionales = [];
        const selectorDiv = document.getElementById('selector_precios_adicionales');
        const container = document.getElementById('precios_adicionales_container');
        
        container.innerHTML = '';
        
        if (articuloSeleccionado.venta_con_iva && parseFloat(articuloSeleccionado.venta_con_iva) > 0) {
            preciosAdicionales.push({
                tipo: 'venta_con_iva',
                label: 'Precio Venta',
                valor: parseFloat(articuloSeleccionado.venta_con_iva),
                esDefecto: true
            });
        }
        
        if (articuloSeleccionado.precio_unidad && parseFloat(articuloSeleccionado.precio_unidad) > 0) {
            const valorUnidad = parseFloat(articuloSeleccionado.precio_unidad);
            const valorVenta = parseFloat(articuloSeleccionado.venta_con_iva) || 0;
            if (valorUnidad !== valorVenta) {
                preciosAdicionales.push({
                    tipo: 'unidad',
                    label: 'Precio Unidad',
                    valor: valorUnidad,
                    esDefecto: false
                });
            }
        }
        
        if (articuloSeleccionado.precio2 && parseFloat(articuloSeleccionado.precio2) > 0) {
            preciosAdicionales.push({
                tipo: 'precio2',
                label: 'Precio 2',
                valor: parseFloat(articuloSeleccionado.precio2),
                esDefecto: false
            });
        }
        
        if (articuloSeleccionado.precio3 && parseFloat(articuloSeleccionado.precio3) > 0) {
            preciosAdicionales.push({
                tipo: 'precio3',
                label: 'Precio 3',
                valor: parseFloat(articuloSeleccionado.precio3),
                esDefecto: false
            });
        }
        
        if (articuloSeleccionado.precio4 && parseFloat(articuloSeleccionado.precio4) > 0) {
            preciosAdicionales.push({
                tipo: 'precio4',
                label: 'Precio 4',
                valor: parseFloat(articuloSeleccionado.precio4),
                esDefecto: false
            });
        }
        
        if (preciosAdicionales.length > 1) {
            selectorDiv.style.display = 'block';
            
            preciosAdicionales.forEach((precio, index) => {
                const btn = document.createElement('div');
                btn.className = 'precio-adicional-btn';
                if (precio.esDefecto === true) {
                    btn.classList.add('active');
                    precioSeleccionado = precio.valor;
                    document.getElementById('temp_precio').value = precioSeleccionado;
                }
                btn.innerHTML = `
                    <span class="precio-label">${precio.label}</span>
                    <span class="precio-valor">$${precio.valor.toFixed(2)}</span>
                `;
                btn.onclick = () => seleccionarPrecioAdicional(precio.tipo, precio.valor, btn);
                container.appendChild(btn);
            });
            
            if (!precioSeleccionado && preciosAdicionales.length > 0) {
                precioSeleccionado = preciosAdicionales[0].valor;
                document.getElementById('temp_precio').value = precioSeleccionado;
                const firstBtn = container.querySelector('.precio-adicional-btn');
                if (firstBtn) firstBtn.classList.add('active');
            }
        } else if (preciosAdicionales.length === 1) {
            selectorDiv.style.display = 'none';
            precioSeleccionado = preciosAdicionales[0].valor;
            document.getElementById('temp_precio').value = precioSeleccionado;
        } else {
            selectorDiv.style.display = 'none';
            if (articuloSeleccionado.venta_con_iva) {
                precioSeleccionado = parseFloat(articuloSeleccionado.venta_con_iva);
                document.getElementById('temp_precio').value = precioSeleccionado;
            }
        }
    }
    
    function seleccionarPrecioAdicional(tipo, valor, elemento) {
        precioSeleccionado = valor;
        document.getElementById('temp_precio').value = valor;
        
        const buttons = document.querySelectorAll('.precio-adicional-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        
        if (elemento) elemento.classList.add('active');
    }

    function cambiarTipoDocumento() {
        const isFactura = document.getElementById('tipo_doc_switch').checked;
        const inputTipo = document.getElementById('tipo_documento');
        const selectPago = document.getElementById('forma_pago');
        
        inputTipo.value = isFactura ? 'factura' : 'recibo';
        const tipo = inputTipo.value;
        
        selectPago.innerHTML = "";

        if (tipo === 'recibo') {
            const opciones = ["EFECTIVO", "TRANSFERENCIA", "TARJETA DE DÉBITO", "TARJETA DE CRÉDITO"];
            opciones.forEach(opt => {
                let o = document.createElement("option");
                o.value = opt;
                o.text = opt;
                selectPago.appendChild(o);
            });
        } else {
            formasPagoSRI.forEach(fp => {
                let o = document.createElement("option");
                o.value = fp.nombre;
                o.text = fp.nombre;
                selectPago.appendChild(o);
            });
        }
    }

    function convertirMayus(e) { e.value = e.value.toUpperCase(); }

    function limpiarCamposIdentificacion() {
        if (document.getElementById('chk_consumidor_final').checked) return;
        
        document.getElementById('ci_ruc').value = '';
        document.getElementById('nombres').value = '';
        document.getElementById('correo').value = '';
        document.getElementById('direccion').value = '';
        document.getElementById('telefono').value = '';
        esClienteNuevo = false;
    }

    function limpiarTodoFormulario() {
        const chk = document.getElementById('chk_consumidor_final');
        if (chk.checked) {
            chk.checked = false;
            toggleConsumidorFinal();
        }
        
        document.getElementById('tipo_identificacion').value = 'CEDULA';
        document.getElementById('ci_ruc').value = '9999999999';
        document.getElementById('nombres').value = '';
        document.getElementById('correo').value = '';
        document.getElementById('direccion').value = '';
        document.getElementById('telefono').value = '';
        esClienteNuevo = false;
        
        document.getElementById('input_cod_manual').value = '';
        document.getElementById('input_nombre_buscar').value = '';
        document.getElementById('input_cant').value = '1';
        document.getElementById('desc_general').value = '0';
        limpiarTemporales();

        detalleFactura = [];
        renderTabla();

        document.getElementById('tipo_doc_switch').checked = false;
        cambiarTipoDocumento();
        document.getElementById('observaciones').value = '';

        buscarClienteCompleto();
        
        tipoVentaSeleccionado = 'unidad';
        articuloSeleccionado = null;
        articuloTieneCaja = false;
        precioSeleccionado = null;
        document.getElementById('precio_info').innerHTML = '';
        document.getElementById('grupo_tipo_venta').style.display = 'none';
        document.getElementById('selector_precios_adicionales').style.display = 'none';
    }

    function saltarAlSiguiente(proximoId) {
        const campo = document.getElementById(proximoId);
        if(campo) {
            campo.focus();
            if(campo.tagName === 'INPUT') campo.select();
        }
    }

    async function buscarClienteCompleto() {
        const valor = document.getElementById('ci_ruc').value.trim();
        if (valor === "") return;
        
        if (document.getElementById('chk_consumidor_final').checked) {
            document.getElementById('loaderApi').style.display = 'none';
            return;
        }
        
        const loader = document.getElementById('loaderApi');
        loader.style.display = 'block';
        esClienteNuevo = false;

        try {
            const respLocal = await fetch(`?buscar_cliente_local=${valor}`);
            const dataLocal = await respLocal.json();
            if (dataLocal) {
                document.getElementById('nombres').value = dataLocal.nombres;
                document.getElementById('correo').value = dataLocal.correo;
                document.getElementById('direccion').value = dataLocal.direccion;
                document.getElementById('telefono').value = dataLocal.telefono;
                loader.style.display = 'none';
                
                if(valor === "9999999999") {
                    document.getElementById('input_cod_manual').focus();
                } else {
                    saltarAlSiguiente('correo');
                }
            } else {
                esClienteNuevo = true;
                ejecutarConsultaApiExterna(valor);
            }
        } catch (error) {
            console.error("Error:", error);
            loader.style.display = 'none';
        }
    }

    async function manejarGuardadoYEnfoque() {
        if (document.getElementById('chk_consumidor_final').checked) {
            saltarAlSiguiente('input_cod_manual');
            return;
        }
        
        if (esClienteNuevo) {
            const formData = new FormData();
            formData.append('accion', 'guardar_cliente_local');
            formData.append('ci_ruc', document.getElementById('ci_ruc').value);
            formData.append('tipo_identificacion', document.getElementById('tipo_identificacion').value);
            formData.append('nombres', document.getElementById('nombres').value);
            formData.append('correo', document.getElementById('correo').value);
            formData.append('direccion', document.getElementById('direccion').value);
            formData.append('telefono', document.getElementById('telefono').value);

            try {
                await fetch('', { method: 'POST', body: formData });
                esClienteNuevo = false;
            } catch (e) { console.error("Error al guardar:", e); }
        }
        saltarAlSiguiente('input_cod_manual');
    }

    function ejecutarConsultaApiExterna(valor) {
        if(valor === "9999999999") {
            document.getElementById('loaderApi').style.display = 'none';
            document.getElementById('nombres').value = "CONSUMIDOR FINAL";
            esClienteNuevo = false;
            document.getElementById('input_cod_manual').focus();
            return;
        }

        const tipo = document.getElementById('tipo_identificacion').value;
        const loader = document.getElementById('loaderApi');
        
        if (tipo === 'CEDULA' && valor.length === 10) {
            consultarFirmaApi(`https://apicedula.socket-studio.com/consulta-cedula/consulta/${valor}`, TOKEN_CEDULA);
        } else if (tipo === 'RUC' && valor.length === 13) {
            consultarFirmaApi(`https://apiruc.socket-studio.com/api/ruc/consulta/free/${valor}`, TOKEN_RUC);
        } else {
            loader.style.display = 'none';
            mostrarModal("Por favor, verifique la identificación ya que no existe.");
        }
    }

    async function consultarFirmaApi(url, token) {
        try {
            const response = await fetch(url, { headers: { "Authorization": "Bearer " + token } });
            const res = await response.json();
            document.getElementById('loaderApi').style.display = 'none';
            
            if (res.nombres || res.razon_social || (res.data && (res.data.nombres || res.data.razon_social))) {
                const data = res.data ? res.data : res;
                document.getElementById('nombres').value = (data.nombres || data.razon_social || data.nombre_completo || "").toUpperCase();
                document.getElementById('direccion').value = (data.direccion || "").toUpperCase();
                saltarAlSiguiente('correo');
            } else {
                mostrarModal("Por favor, verifique la identificación ya que no existe.");
            }
        } catch (error) {
            document.getElementById('loaderApi').style.display = 'none';
            mostrarModal("Verifique y vuelva a intentar");
        }
    }

    function buscarPorNombre() {
        const bus = document.getElementById('input_nombre_buscar').value.trim().toLowerCase();
        limpiarTemporales();
        const art = baseArticulos.find(a => a.nombre.toLowerCase() === bus);
        if (art) { 
            cargarTemporales(art); 
            document.getElementById('input_cod_manual').value = art.cod_articulo;
        } else {
            document.getElementById('grupo_tipo_venta').style.display = 'none';
            articuloTieneCaja = false;
            document.getElementById('selector_precios_adicionales').style.display = 'none';
        }
    }

    function buscarPorCodigo() {
        const cod = document.getElementById('input_cod_manual').value.trim().toLowerCase();
        limpiarTemporales();
        const art = baseArticulos.find(a => a.cod_articulo.toLowerCase() === cod);
        if (art) { 
            cargarTemporales(art); 
            document.getElementById('input_nombre_buscar').value = art.nombre;
        } else {
            document.getElementById('grupo_tipo_venta').style.display = 'none';
            articuloTieneCaja = false;
            document.getElementById('selector_precios_adicionales').style.display = 'none';
        }
    }

    function cargarTemporales(art) {
        articuloSeleccionado = art;
        document.getElementById('temp_id').value = art.id;
        document.getElementById('temp_codigo').value = art.cod_articulo;
        document.getElementById('temp_nombre').value = art.nombre;
        document.getElementById('temp_iva').value = art.porcentaje_iva;
        document.getElementById('temp_envase').value = art.envase || '';
        
        const envase = (art.envase || '').toUpperCase();
        articuloTieneCaja = (envase === 'CAJA' || envase === 'CAJAS');
        
        document.getElementById('temp_unidades_caja').value = art.unidades_caja || 0;
        document.getElementById('temp_precio_unidad').value = art.precio_unidad || 0;
        document.getElementById('temp_precio2').value = art.precio2 || 0;
        document.getElementById('temp_precio3').value = art.precio3 || 0;
        document.getElementById('temp_precio4').value = art.precio4 || 0;
        
        mostrarSelectorPreciosAdicionales();
        
        const grupoTipoVenta = document.getElementById('grupo_tipo_venta');
        if (articuloTieneCaja && art.unidades_caja > 0 && art.precio_unidad > 0) {
            grupoTipoVenta.style.display = 'block';
            tipoVentaSeleccionado = 'unidad';
            document.getElementById('tipo_venta_unidad').classList.add('active');
            document.getElementById('tipo_venta_caja').classList.remove('active');
            actualizarPrecioTemporal();
            actualizarInfoPrecios();
        } else {
            grupoTipoVenta.style.display = 'none';
            if (precioSeleccionado) {
                document.getElementById('temp_precio').value = precioSeleccionado;
            } else {
                document.getElementById('temp_precio').value = art.venta_con_iva;
            }
        }
    }

    function limpiarTemporales() { 
        document.getElementById('temp_id').value = "";
        articuloSeleccionado = null;
        articuloTieneCaja = false;
        precioSeleccionado = null;
        document.getElementById('precio_info').innerHTML = '';
        document.getElementById('grupo_tipo_venta').style.display = 'none';
        document.getElementById('selector_precios_adicionales').style.display = 'none';
    }

    function validarYEnfocar(tipo) {
        if (document.getElementById('temp_id').value !== "") {
            const inputCant = document.getElementById('input_cant');
            inputCant.focus();
            inputCant.select(); 
        } else {
            mostrarModal("El código de artículo ingresado no existe.");
        }
    }

    function mostrarModal(m) { 
        document.getElementById('modal_msg').innerText = m; 
        document.getElementById('modal_error').style.display = 'flex'; 
    }
    
    function cerrarModal() { 
        document.getElementById('modal_error').style.display = 'none'; 
        const inputCod = document.getElementById('input_cod_manual');
        const inputCi = document.getElementById('ci_ruc');
        
        if (document.getElementById('modal_msg').innerText.includes("artículo")) {
            inputCod.focus();
            inputCod.select();
        } else {
            inputCi.focus();
            inputCi.select();
        }
    }

    function agregarItem() {
        const id = document.getElementById('temp_id').value;
        const cant = parseFloat(document.getElementById('input_cant').value);
        const unidadesCaja = parseInt(document.getElementById('temp_unidades_caja').value) || 0;
        
        if (!id || cant <= 0) return;
        
        let precioUnitario = parseFloat(document.getElementById('temp_precio').value);
        let tipoVenta = 'unidad';
        let cantidadReal = cant;
        let nombreMostrado = document.getElementById('temp_nombre').value;
        let precioPorUnidadReal = precioUnitario;
        
        if (articuloTieneCaja && tipoVentaSeleccionado === 'caja' && unidadesCaja > 0) {
            tipoVenta = 'caja';
            cantidadReal = cant * unidadesCaja;
            precioPorUnidadReal = precioUnitario / unidadesCaja;
            nombreMostrado += ` (Caja de ${unidadesCaja} unds)`;
        }
        
        const it = {
            id: id,
            codigo: document.getElementById('temp_codigo').value,
            nombre: nombreMostrado,
            cantidad: cantidadReal,
            cantidadMostrada: cant,
            precioConIva: precioPorUnidadReal,
            precioOriginal: precioUnitario,
            porcIva: parseFloat(document.getElementById('temp_iva').value),
            descuentoPorc: 0,
            tipoVenta: tipoVenta,
            unidadesCaja: unidadesCaja,
            tieneCaja: articuloTieneCaja,
            precioUnidad: parseFloat(document.getElementById('temp_precio_unidad').value) || 0,
            precio2: parseFloat(document.getElementById('temp_precio2').value) || 0,
            precio3: parseFloat(document.getElementById('temp_precio3').value) || 0,
            precio4: parseFloat(document.getElementById('temp_precio4').value) || 0,
            precioSeleccionado: precioSeleccionado
        };
        
        detalleFactura.push(it);
        renderTabla();
        
        document.getElementById('input_cod_manual').value = "";
        document.getElementById('input_nombre_buscar').value = "";
        document.getElementById('input_cant').value = "1";
        limpiarTemporales();
        document.getElementById('input_cod_manual').focus();
    }

    function actualizarDescuentoItem(idx, input) {
        let val = parseFloat(input.value);
        if (isNaN(val) || val < 0) val = 0;
        if (val > 100) val = 100;
        detalleFactura[idx].descuentoPorc = val;
        renderTabla();
    }

    function editarPrecio(idx, elemento) {
        const valorActual = detalleFactura[idx].precioConIva.toFixed(2);
        elemento.ondblclick = null; 
        
        const input = document.createElement("input");
        input.type = "number";
        input.step = "0.01";
        input.className = "input-inline-edit";
        input.value = valorActual;

        elemento.innerHTML = "";
        elemento.appendChild(input);
        input.focus();
        input.select();

        const guardar = () => {
            const nuevoPrecio = parseFloat(input.value);
            if (!isNaN(nuevoPrecio) && nuevoPrecio > 0) {
                detalleFactura[idx].precioConIva = nuevoPrecio;
            }
            renderTabla();
        };

        input.onblur = guardar;
        input.onkeydown = (e) => {
            if (e.key === "Enter") guardar();
            if (e.key === "Escape") renderTabla();
        };
    }

    function renderTabla() {
        const tbody = document.querySelector('#tabla_detalle tbody');
        const contenedorResumen = document.getElementById('contenedor_desglose_resumen');
        const descGralPorc = parseFloat(document.getElementById('desc_general').value) || 0;
        
        tbody.innerHTML = "";
        contenedorResumen.innerHTML = "";

        let gruposIva = {}; 
        let totalFinalFactura = 0;

        detalleFactura.forEach((it, idx) => {
            let precioTrasDescInd = it.precioConIva * (1 - (it.descuentoPorc / 100));
            let precioFinalConIva = precioTrasDescInd * (1 - (descGralPorc / 100));
            
            let totalItemConIva = precioFinalConIva * it.cantidad;
            let subtotalItemSinIva = totalItemConIva / (1 + (it.porcIva / 100));
            let ivaItem = totalItemConIva - subtotalItemSinIva;

            const p = it.porcIva;
            if (!gruposIva[p]) {
                gruposIva[p] = { base: 0, iva: 0 };
            }
            
            gruposIva[p].base += subtotalItemSinIva;
            gruposIva[p].iva += ivaItem;
            totalFinalFactura += totalItemConIva;

            let cantidadMostrada = it.cantidadMostrada || it.cantidad;
            let tipoVentaStr = '';
            if (it.tieneCaja && it.tipoVenta === 'caja') {
                tipoVentaStr = ` (${cantidadMostrada} caja${cantidadMostrada !== 1 ? 's' : ''} x ${it.unidadesCaja} unds)`;
            }
            
            tbody.innerHTML += `
                <tr>
                    <td>${it.codigo}</td>
                    <td>${it.nombre}${tipoVentaStr}<br><small style="color:#666;">Total: ${it.cantidad} unds</small></td>
                    <td>${it.cantidad}</td>
                    <td class="editable-precio" ondblclick="editarPrecio(${idx}, this)">$${it.precioConIva.toFixed(2)}</td>
                    <td>
                        <input type="number" class="input-desc-tabla" value="${it.descuentoPorc}" onchange="actualizarDescuentoItem(${idx}, this)">
                    </td>
                    <td>${it.porcIva}%</td>
                    <td>$${totalItemConIva.toFixed(2)}</td>
                    <td style="text-align:center">
                        <button style="background:var(--rojo-peligro); color:white; border:none; border-radius:3px; cursor:pointer; padding:2px 8px;" onclick="eliminarFila(${idx})">X</button>
                    </td>
                </tr>`;
        });

        let vIvaTotalFactura = 0;
        const porcentajes = Object.keys(gruposIva).sort((a, b) => a - b);
        
        porcentajes.forEach(p => {
            const datos = gruposIva[p];
            vIvaTotalFactura += datos.iva;
            
            contenedorResumen.innerHTML += `
                <div class="fila-total">
                    <span>Subtotal ${p}%</span>
                    <span>$ ${datos.base.toFixed(2)}</span>
                </div>`;
            
            contenedorResumen.innerHTML += `
                <div class="fila-total" style="font-size: 12px; color: #ccc; margin-bottom: 10px;">
                    <span style="padding-left: 10px;">> IVA ${p}%</span>
                    <span>$ ${datos.iva.toFixed(2)}</span>
                </div>`;
        });

        document.getElementById('res_ivaVal').innerText = `$ ${vIvaTotalFactura.toFixed(2)}`;
        document.getElementById('res_total').innerText = `$ ${totalFinalFactura.toFixed(2)}`;
    }

    function eliminarFila(idx) {
        detalleFactura.splice(idx, 1);
        renderTabla();
    }

    function procesarVenta() {
        if (detalleFactura.length === 0) {
            mostrarModal("No hay productos cargados.");
            return;
        }
        
        const clienteData = {
            ci_ruc: document.getElementById('ci_ruc').value,
            nombres: document.getElementById('nombres').value,
            direccion: document.getElementById('direccion').value,
            telefono: document.getElementById('telefono').value,
            correo: document.getElementById('correo').value,
            tipo_identificacion: document.getElementById('tipo_identificacion').value
        };
        
        const ventaData = {
            tipo_documento: document.getElementById('tipo_documento').value,
            forma_pago: document.getElementById('forma_pago').value,
            observaciones: document.getElementById('observaciones').value,
            descuento_general: parseFloat(document.getElementById('desc_general').value) || 0
        };
        
        const formData = new FormData();
        formData.append('accion', 'guardar_venta');
        formData.append('cliente', JSON.stringify(clienteData));
        formData.append('venta', JSON.stringify(ventaData));
        formData.append('detalle', JSON.stringify(detalleFactura));
        
        const btnFacturar = document.querySelector('.btn-verde');
        const textoOriginal = btnFacturar.textContent;
        btnFacturar.textContent = 'PROCESANDO...';
        btnFacturar.disabled = true;
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            btnFacturar.textContent = textoOriginal;
            btnFacturar.disabled = false;
            
            if (data.status === 'success') {
                ultimaVentaData = data;
                
                if (data.es_factura && data.sri_info) {
                    ultimaVentaData.sri_info = data.sri_info;
                }
                
                mostrarModalExito(data);
            } else {
                mostrarModal("❌ Error al guardar la venta: " + data.message);
            }
        })
        .catch(error => {
            btnFacturar.textContent = textoOriginal;
            btnFacturar.disabled = false;
            mostrarModal("❌ Error de conexión: " + error.message);
        });
    }

    function mostrarModalExito(data) {
        document.getElementById('exito_documento').textContent = data.numero_documento;
        document.getElementById('exito_venta_id').textContent = data.venta_id;
        document.getElementById('exito_total').textContent = data.total.toFixed(2);
        document.getElementById('exito_cliente').textContent = data.cliente.nombres;
        document.getElementById('exito_tamano').textContent = tamanoImpresionActual;
        
        const badgeExito = document.getElementById('exito_badge');
        if (tamanoImpresionActual === '58mm') {
            badgeExito.className = 'badge-58mm';
            badgeExito.textContent = 'TICKET';
        } else if (tamanoImpresionActual === '80mm') {
            badgeExito.className = 'badge-80mm';
            badgeExito.textContent = 'TICKET ANCHO';
        } else if (tamanoImpresionActual === 'A4') {
            badgeExito.className = 'badge-A4';
            badgeExito.textContent = 'FACTURA';
        }
        
        document.getElementById('modal_exito').style.display = 'flex';
        document.getElementById('btn_imprimir').focus();
    }

    function cerrarModalExito() {
        document.getElementById('modal_exito').style.display = 'none';
        document.getElementById('loader_pdf').style.display = 'none';
    }

    function imprimirRecibo() {
        if (!ultimaVentaData) {
            alert('No hay datos de venta para imprimir');
            return;
        }
        
        const loader = document.getElementById('loader_pdf');
        loader.style.display = 'block';
        
        const btnImprimir = document.getElementById('btn_imprimir');
        btnImprimir.disabled = true;
        btnImprimir.textContent = '⏳ Generando...';
        
        const formData = new FormData();
        formData.append('accion', 'generar_recibo');
        formData.append('tamano_impresion', tamanoImpresionActual);
        formData.append('datos', JSON.stringify({
            venta_id: ultimaVentaData.venta_id
        }));
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            loader.style.display = 'none';
            btnImprimir.disabled = false;
            btnImprimir.textContent = '🖨️ Imprimir Recibo (Enter)';
            
            if (data.status === 'success') {
                const byteCharacters = atob(data.pdf);
                const byteNumbers = new Array(byteCharacters.length);
                for (let i = 0; i < byteCharacters.length; i++) {
                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                }
                const byteArray = new Uint8Array(byteNumbers);
                const blob = new Blob([byteArray], { type: 'application/pdf' });
                
                const pdfUrl = URL.createObjectURL(blob);
                const ventanaImpresion = window.open(pdfUrl, '_blank');
                if (ventanaImpresion) {
                    ventanaImpresion.onload = function() {
                        ventanaImpresion.print();
                    };
                    setTimeout(function() {
                        try {
                            ventanaImpresion.print();
                        } catch(e) {}
                    }, 3000);
                } else {
                    alert('No se pudo abrir la ventana de impresión. Permita ventanas emergentes.');
                }
                
                cerrarModalExito();
                limpiarTodoFormulario();
                document.getElementById('ci_ruc').focus();
            } else {
                alert('❌ Error al generar recibo: ' + data.message);
            }
        })
        .catch(error => {
            loader.style.display = 'none';
            btnImprimir.disabled = false;
            btnImprimir.textContent = '🖨️ Imprimir Recibo (Enter)';
            alert('❌ Error al generar recibo: ' + error.message);
        });
    }

    function continuarSinImprimir() {
        cerrarModalExito();
        limpiarTodoFormulario();
        document.getElementById('ci_ruc').focus();
    }
</script>
<?php if (file_exists('footer.php')) include_once 'footer.php'; ?>
</body>
</html>