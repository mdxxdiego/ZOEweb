<?php
ini_set('display_errors', 0);
error_reporting(0);

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $ruta_config = __DIR__ . '/config/config.php';
    if (!file_exists($ruta_config)) {
        $ruta_config = __DIR__ . '/../config/config.php';
        if (!file_exists($ruta_config)) {
            throw new Exception("No se encontró el archivo de configuración config.php");
        }
    }
    require_once($ruta_config);

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("La conexión a la base de datos (\$pdo) no se estableció correctamente en config.php");
    }

    $ruta_fpdf = 'fpdf/fpdf.php';
    if (!file_exists($ruta_fpdf)) {
        $ruta_fpdf = __DIR__ . '/fpdf/fpdf.php';
        if (!file_exists($ruta_fpdf)) {
            echo json_encode(['status' => 'error', 'mensaje' => "No se encontró FPDF"]);
            exit();
        }
    }
    require_once($ruta_fpdf);

    $ventaId = $_POST['venta_id'] ?? $_GET['venta_id'] ?? null;
    if (!$ventaId) {
        $datos = json_decode($_POST['datos'] ?? '{}', true);
        $ventaId = $datos['venta_id'] ?? null;
    }

    if (!$ventaId) {
        $stmtUltimaVenta = $pdo->query("SELECT id FROM ventas ORDER BY id DESC LIMIT 1");
        $ultimaVenta = $stmtUltimaVenta->fetch();
        if ($ultimaVenta) {
            $ventaId = $ultimaVenta['id'];
        } else {
            throw new Exception("No se encontró ninguna venta");
        }
    }

    $stmtEmpresa = $pdo->query("SELECT nombre_empresa, nombre_titular, ruc, tipo_contribuyente, direccion, localidad FROM empresa LIMIT 1");
    $empresa = $stmtEmpresa->fetch();

    $stmtVenta = $pdo->prepare("SELECT * FROM ventas WHERE id = :id");
    $stmtVenta->execute(['id' => $ventaId]);
    $venta = $stmtVenta->fetch();

    if (!$venta) {
        throw new Exception("Venta no encontrada con ID: $ventaId");
    }

    $clienteData = null;
    if (!empty($venta['cliente_ci_ruc'])) {
        $stmtCliente = $pdo->prepare("SELECT nombres, ci_ruc, direccion FROM clientes WHERE ci_ruc = :ci_ruc LIMIT 1");
        $stmtCliente->execute(['ci_ruc' => $venta['cliente_ci_ruc']]);
        $clienteData = $stmtCliente->fetch();
    }

    if (!$clienteData) {
        $clienteData = [
            'nombres'   => $venta['cliente_nombres'] ?? 'CONSUMIDOR FINAL',
            'ci_ruc'    => $venta['cliente_ci_ruc'] ?? '9999999999999',
            'direccion' => $venta['cliente_direccion'] ?? 'S/N'
        ];
    }

    $stmtDetalle = $pdo->prepare("SELECT cantidad, nombre_articulo, precio_unitario, precio_total, subtotal, porcentaje_iva, iva_valor FROM detalle_ventas WHERE venta_id = :venta_id");
    $stmtDetalle->execute(['venta_id' => $ventaId]);
    $detalles = $stmtDetalle->fetchAll();

    $anchoTicket = 56;
    $margen = 2;
    $anchoUtil = $anchoTicket - ($margen * 2); 

    $agrupadoIva = [];
    
    foreach ($detalles as $det) {
        $porc  = isset($det['porcentaje_iva']) ? (float)$det['porcentaje_iva'] : 0;
        $valIva = isset($det['iva_valor']) ? (float)$det['iva_valor'] : ((float)($det['subtotal'] ?? 0) * ($porc / 100));

        if (!isset($agrupadoIva[$porc])) {
            $agrupadoIva[$porc] = 0;
        }
        $agrupadoIva[$porc] += $valIva;
    }

    ksort($agrupadoIva);

    $textoObservacion = !empty($venta['observaciones']) ? trim($venta['observaciones']) : '';
    $lineasObsCount = 1;

    if (!empty($textoObservacion)) {
        $pdfMedidor = new FPDF('P', 'mm', array(56, 200));
        $pdfMedidor->AddPage();
        $pdfMedidor->SetFont('Arial', '', 7);
        
        $textoFinal = utf8_decode('OBSERVACION: ' . $textoObservacion);
        $lineas = explode("\n", $textoFinal);
        $lineasObsCount = 0;
        foreach ($lineas as $l) {
            $anchoTexto = $pdfMedidor->GetStringWidth($l);
            $lineasEnLinea = ceil($anchoTexto / ($anchoUtil - 1));
            $lineasObsCount += max(1, $lineasEnLinea);
        }
    }

    $margenSuperior = 4;
    $margenInferior = 4;

    $alturaEncabezado = 18;
    if (!empty($empresa['nombre_titular']) && $empresa['nombre_titular'] !== $empresa['nombre_empresa']) {
        $alturaEncabezado += 3;
    }

    $alturaVenta = 19;
    
    $cantProductos = count($detalles) > 0 ? count($detalles) : 1;
    $alturaDetalles = 5 + ($cantProductos * 3);

    $cantTarifasIva = count($agrupadoIva);
    $lineasTotales  = 2 + $cantTarifasIva;
    if ((float)($venta['descuento_total'] ?? 0) > 0) $lineasTotales++;
    
    $alturaTotales = $lineasTotales * 3;

    $alturaPie = 2 + 3 + ($lineasObsCount * 3) + 2 + 3;

    $alturaTotal = $margenSuperior + $alturaEncabezado + $alturaVenta + $alturaDetalles + $alturaTotales + $alturaPie + $margenInferior;

    $pdf = new FPDF('P', 'mm', array($anchoTicket, $alturaTotal));
    $pdf->SetMargins($margen, $margenSuperior, $margen); 
    $pdf->AddPage();
    $pdf->SetY($margenSuperior);
    $pdf->SetAutoPageBreak(false);

    $pdf->SetX($margen); 
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell($anchoUtil, 4, utf8_decode($empresa['nombre_empresa']), 0, 1, 'C');
    
    if (!empty($empresa['nombre_titular']) && $empresa['nombre_titular'] !== $empresa['nombre_empresa']) {
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell($anchoUtil, 3, utf8_decode($empresa['nombre_titular']), 0, 1, 'C');
    }

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, utf8_decode($empresa['direccion']), 0, 1, 'C');
    
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, 'RUC: ' . $empresa['ruc'], 0, 1, 'C');
    
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, utf8_decode('RÉGIMEN: ' . $empresa['tipo_contribuyente']), 0, 1, 'C');
    
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 2, '-------------------------------------------------------', 0, 1, 'C');

    $numRecibo  = str_pad($venta['id'], 6, '0', STR_PAD_LEFT);
    $fechaVenta = !empty($venta['fecha']) ? date('d/m/Y h:i:s a', strtotime($venta['fecha'])) : date('d/m/Y h:i:s a');
    $formaPago  = !empty($venta['forma_pago']) ? $venta['forma_pago'] : 'EFECTIVO';

    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell($anchoUtil, 3, 'RECIBO N: ' . $numRecibo, 0, 1, 'L');
    $pdf->Cell($anchoUtil, 3, 'FECHA: ' . $fechaVenta, 0, 1, 'L');
    $pdf->Cell($anchoUtil, 3, utf8_decode('COND VENTA: ' . $formaPago), 0, 1, 'L');
    
    $nombreCliente = utf8_decode(substr($clienteData['nombres'], 0, 23));
    $pdf->Cell($anchoUtil, 3, 'CLIENTE: ' . $nombreCliente, 0, 1, 'L');
    $pdf->Cell($anchoUtil, 3, 'RUC/CI: ' . $clienteData['ci_ruc'], 0, 1, 'L');
    $pdf->Cell($anchoUtil, 3, utf8_decode('DOMICILIO: ' . ($clienteData['direccion'] ? substr($clienteData['direccion'], 0, 23) : 'S/N')), 0, 1, 'L');
    $pdf->Cell($anchoUtil, 2, '-------------------------------------------------------', 0, 1, 'C');

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(6, 3, 'CAN', 0, 0, 'L');
    $pdf->Cell(25, 3, 'DESCRIPCION', 0, 0, 'L');
    $pdf->Cell(10, 3, 'UNIT', 0, 0, 'R');
    $pdf->Cell(11, 3, 'TOTAL', 0, 1, 'R');

    $pdf->SetFont('Arial', '', 6.5);

    if (!empty($detalles)) {
        foreach ($detalles as $item) {
            $cantidad   = (float)($item['cantidad'] ?? 1);
            $nombre     = $item['nombre_articulo'] ?: 'PRODUCTO';
            $precioUnit = (float)($item['precio_unitario'] ?? 0.00);
            $precioTot  = (float)($item['precio_total'] ?? 0.00);

            $nombreCorto = utf8_decode(substr($nombre, 0, 18));
            
            $pdf->Cell(6, 3, number_format($cantidad, 1), 0, 0, 'L');
            $pdf->Cell(25, 3, $nombreCorto, 0, 0, 'L');
            $pdf->Cell(10, 3, number_format($precioUnit, 2), 0, 0, 'R');
            $pdf->Cell(11, 3, number_format($precioTot, 2), 0, 1, 'R');
        }
    } else {
        $subtotalNum = (float)($venta['subtotal'] ?? 0.00);
        $pdf->Cell(6, 3, '1.0', 0, 0, 'L');
        $pdf->Cell(25, 3, utf8_decode('CONSUMO GENERAL'), 0, 0, 'L');
        $pdf->Cell(10, 3, number_format($subtotalNum, 2), 0, 0, 'R');
        $pdf->Cell(11, 3, number_format($subtotalNum, 2), 0, 1, 'R');
    }

    $pdf->Cell($anchoUtil, 2, '-------------------------------------------------------', 0, 1, 'C');

    $pdf->SetFont('Arial', '', 7);

    $subtotalTotal  = (float)($venta['subtotal'] ?? 0);
    $descuentoTotal = (float)($venta['descuento_total'] ?? 0);
    $totalFinal     = (float)($venta['total'] ?? 0);

    foreach ($agrupadoIva as $porcentaje => $montoIva) {
        $labelIva = 'IVA TARIFA ' . number_format($porcentaje, 2) . '%:';
        $pdf->Cell(33, 3, $labelIva, 0, 0, 'R');
        $pdf->Cell(19, 3, '$' . number_format($montoIva, 2), 0, 1, 'R');
    }

    $pdf->Cell(33, 3, 'SUBTOTAL:', 0, 0, 'R');
    $pdf->Cell(19, 3, '$' . number_format($subtotalTotal, 2), 0, 1, 'R');

    if ($descuentoTotal > 0) {
        $pdf->Cell(33, 3, 'DESCUENTO:', 0, 0, 'R');
        $pdf->Cell(19, 3, '$' . number_format($descuentoTotal, 2), 0, 1, 'R');
    }

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(33, 3.5, 'TOTAL:', 0, 0, 'R');
    $pdf->Cell(19, 3.5, '$' . number_format($totalFinal, 2), 0, 1, 'R');

    $pdf->Ln(1);
    $pdf->SetFont('Arial', '', 7);
    
    $pdf->SetX($margen);
    $usuarioNombre = isset($_SESSION['usuario_nombre']) ? $_SESSION['usuario_nombre'] : 'DIEDAY SOFT';
    $pdf->Cell($anchoUtil, 3, 'VENDEDOR: ' . $usuarioNombre, 0, 1, 'L');
    
    $pdf->SetX($margen);
    if (!empty($textoObservacion)) {
        $pdf->MultiCell($anchoUtil, 3, utf8_decode('OBSERVACION: ' . $textoObservacion), 0, 'L');
    } else {
        $pdf->Cell($anchoUtil, 3, 'OBSERVACION: ------', 0, 1, 'L');
    }
    
    $pdf->Ln(1);
    $pdf->SetFont('Arial', 'B', 7);
    
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, '- Dieday Soft. | 1721928461001 | ZOE -', 0, 1, 'C');

    $pdfBase64 = base64_encode($pdf->Output('S'));

    echo json_encode([
        'status'    => 'success',
        'pdf'       => $pdfBase64,
        'pdfBase64' => $pdfBase64
    ]);
    exit();

} catch (Throwable $e) {
    error_log("Error en recibo_56.php: " . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
    exit();
}
?>