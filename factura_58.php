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

    // ============================================================
    // OBTENER INFORMACIÓN SRI DEL COMPROBANTE
    // ============================================================
    $sriInfo = null;
    $stmtSri = $pdo->prepare("SELECT * FROM sri_comprobantes WHERE venta_id = :venta_id ORDER BY id DESC LIMIT 1");
    $stmtSri->execute(['venta_id' => $ventaId]);
    $sriInfo = $stmtSri->fetch();

    // Si no hay comprobante SRI, verificar si la venta es factura y obtener autorización de la venta
    if (!$sriInfo) {
        $stmtVentaCheck = $pdo->prepare("SELECT tipo_documento, autorizacion_sri FROM ventas WHERE id = :id");
        $stmtVentaCheck->execute(['id' => $ventaId]);
        $ventaCheck = $stmtVentaCheck->fetch();
        if ($ventaCheck && $ventaCheck['tipo_documento'] === 'factura' && !empty($ventaCheck['autorizacion_sri'])) {
            $sriInfo = [
                'numero_autorizacion' => $ventaCheck['autorizacion_sri'],
                'clave_acceso' => null,
                'estado_sri' => 'autorizado',
                'mensaje_sri' => null,
                'fecha_autorizacion' => null,
                'tipo_comprobante' => 'factura'
            ];
        }
    }
    // ============================================================

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
        $stmtCliente = $pdo->prepare("SELECT nombres, ci_ruc, direccion, tipo_identificacion FROM clientes WHERE ci_ruc = :ci_ruc LIMIT 1");
        $stmtCliente->execute(['ci_ruc' => $venta['cliente_ci_ruc']]);
        $clienteData = $stmtCliente->fetch();
    }

    if (!$clienteData) {
        $clienteData = [
            'nombres'   => $venta['cliente_nombres'] ?? 'CONSUMIDOR FINAL',
            'ci_ruc'    => $venta['cliente_ci_ruc'] ?? '9999999999999',
            'direccion' => $venta['cliente_direccion'] ?? 'S/N',
            'tipo_identificacion' => $venta['cliente_tipo_identificacion'] ?? 'CEDULA'
        ];
    }

    $stmtDetalle = $pdo->prepare("SELECT cantidad, nombre_articulo, precio_unitario, precio_total, subtotal, porcentaje_iva, iva_valor FROM detalle_ventas WHERE venta_id = :venta_id");
    $stmtDetalle->execute(['venta_id' => $ventaId]);
    $detalles = $stmtDetalle->fetchAll();

    // ============================================================
    // DETERMINAR TIPO DE DOCUMENTO
    // ============================================================
    $tipoDocumento = isset($venta['tipo_documento']) ? strtoupper($venta['tipo_documento']) : 'RECIBO';
    $esFactura = ($tipoDocumento === 'FACTURA');
    $tieneSRI = $esFactura && $sriInfo;
    $sriAutorizado = $tieneSRI && ($sriInfo['estado_sri'] === 'autorizado' || !empty($sriInfo['numero_autorizacion']));
    // ============================================================

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

    // ============================================================
    // CALCULAR LÍNEAS PARA INFORMACIÓN SRI
    // ============================================================
    $lineasSRI = 0;
    if ($tieneSRI) {
        // Clave de acceso (2 líneas si es larga)
        $claveAcceso = $sriInfo['clave_acceso'] ?? '';
        if (!empty($claveAcceso)) {
            $lineasSRI += 2; // Título + clave (puede ser 2 líneas)
            if (strlen($claveAcceso) > 25) {
                $lineasSRI += 1; // Línea extra para clave larga
            }
        }
        // Número de autorización
        if (!empty($sriInfo['numero_autorizacion'])) {
            $lineasSRI += 2; // Título + número
        }
        // Fecha de autorización
        if (!empty($sriInfo['fecha_autorizacion'])) {
            $lineasSRI += 1;
        }
        // Estado del comprobante
        if (!$sriAutorizado) {
            $lineasSRI += 2; // Título + estado/mensaje
        }
        // Línea separadora
        $lineasSRI += 1;
    }
    // ============================================================

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

    // ============================================================
    // CALCULAR ALTURA TOTAL DEL TICKET
    // ============================================================
    $alturaEncabezado = 18;
    if (!empty($empresa['nombre_titular']) && $empresa['nombre_titular'] !== $empresa['nombre_empresa']) {
        $alturaEncabezado += 3;
    }
    // Añadir espacio para SRI en el encabezado si es factura
    if ($tieneSRI) {
        $alturaEncabezado += 2;
    }

    $alturaVenta = 19;
    
    $cantProductos = count($detalles) > 0 ? count($detalles) : 1;
    $alturaDetalles = 5 + ($cantProductos * 3);

    $cantTarifasIva = count($agrupadoIva);
    $lineasTotales  = 2 + $cantTarifasIva;
    if ((float)($venta['descuento_total'] ?? 0) > 0) $lineasTotales++;
    
    $alturaTotales = $lineasTotales * 3;

    $alturaPie = 2 + 3 + ($lineasObsCount * 3) + 2 + 3;

    // Añadir espacio para información SRI
    $alturaSRI = $lineasSRI * 3.5;

    $alturaTotal = $margenSuperior + $alturaEncabezado + $alturaSRI + $alturaVenta + $alturaDetalles + $alturaTotales + $alturaPie + $margenInferior;
    // ============================================================

    $pdf = new FPDF('P', 'mm', array($anchoTicket, $alturaTotal));
    $pdf->SetMargins($margen, $margenSuperior, $margen); 
    $pdf->AddPage();
    $pdf->SetY($margenSuperior);
    $pdf->SetAutoPageBreak(false);

    // ============================================================
    // ENCABEZADO - EMPRESA
    // ============================================================
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

    // ============================================================
    // INFORMACIÓN SRI (FACTURA ELECTRÓNICA)
    // ============================================================
    if ($tieneSRI) {
        // Título del documento
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($anchoUtil, 4, 'FACTURA ELECTRONICA', 0, 1, 'C');
        
        // Número de documento
        $numDoc = !empty($venta['numero_documento']) ? $venta['numero_documento'] : 'FACT-' . str_pad($venta['id'], 6, '0', STR_PAD_LEFT);
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->Cell($anchoUtil, 3, 'N°: ' . $numDoc, 0, 1, 'C');

        // Clave de acceso
        $claveAcceso = $sriInfo['clave_acceso'] ?? '';
        if (!empty($claveAcceso)) {
            $pdf->SetX($margen);
            $pdf->SetFont('Arial', 'B', 5);
            $pdf->Cell($anchoUtil, 2.5, 'CLAVE DE ACCESO:', 0, 1, 'C');
            $pdf->SetX($margen);
            $pdf->SetFont('Arial', '', 5);
            // Dividir clave en 2 líneas si es muy larga
            if (strlen($claveAcceso) > 25) {
                $pdf->Cell($anchoUtil, 2.5, substr($claveAcceso, 0, 25), 0, 1, 'C');
                $pdf->SetX($margen);
                $pdf->Cell($anchoUtil, 2.5, substr($claveAcceso, 25), 0, 1, 'C');
            } else {
                $pdf->Cell($anchoUtil, 2.5, $claveAcceso, 0, 1, 'C');
            }
        }

        // Número de autorización
        if (!empty($sriInfo['numero_autorizacion'])) {
            $pdf->SetX($margen);
            $pdf->SetFont('Arial', 'B', 5);
            $pdf->Cell($anchoUtil, 2.5, 'N° AUTORIZACION:', 0, 1, 'C');
            $pdf->SetX($margen);
            $pdf->SetFont('Arial', '', 5);
            $pdf->Cell($anchoUtil, 2.5, $sriInfo['numero_autorizacion'], 0, 1, 'C');
        }

        // Fecha de autorización
        if (!empty($sriInfo['fecha_autorizacion'])) {
            $pdf->SetX($margen);
            $pdf->SetFont('Arial', '', 5);
            $fechaAut = date('d/m/Y H:i:s', strtotime($sriInfo['fecha_autorizacion']));
            $pdf->Cell($anchoUtil, 2.5, 'FECHA AUT.: ' . $fechaAut, 0, 1, 'C');
        }

        // Estado del comprobante
        if (!$sriAutorizado) {
            $pdf->SetX($margen);
            $pdf->SetFont('Arial', 'B', 5);
            $estado = $sriInfo['estado_sri'] ?? 'pendiente';
            $mensaje = $sriInfo['mensaje_sri'] ?? '';
            $color = '';
            
            if ($estado === 'autorizado') {
                $color = 'VERDE';
            } elseif ($estado === 'rechazado') {
                $color = 'ROJO';
            } else {
                $color = 'AMARILLO';
            }
            
            $pdf->Cell($anchoUtil, 2.5, 'ESTADO SRI: ' . strtoupper($estado), 0, 1, 'C');
            if (!empty($mensaje)) {
                $pdf->SetX($margen);
                $pdf->SetFont('Arial', 'I', 4.5);
                $pdf->Cell($anchoUtil, 2.5, substr($mensaje, 0, 40), 0, 1, 'C');
            }
        }

        // Línea separadora
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', '', 6);
        $pdf->Cell($anchoUtil, 2, '-------------------------------------------------------', 0, 1, 'C');
    } else {
        // Si es recibo, mostrar título RECIBO
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($anchoUtil, 4, 'RECIBO DE VENTA', 0, 1, 'C');
        
        $numDoc = !empty($venta['numero_documento']) ? $venta['numero_documento'] : 'REC-' . str_pad($venta['id'], 6, '0', STR_PAD_LEFT);
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->Cell($anchoUtil, 3, 'N°: ' . $numDoc, 0, 1, 'C');
    }
    // ============================================================

    // ============================================================
    // DATOS DE LA VENTA
    // ============================================================
    $numRecibo  = str_pad($venta['id'], 6, '0', STR_PAD_LEFT);
    $fechaVenta = !empty($venta['fecha']) ? date('d/m/Y h:i:s a', strtotime($venta['fecha'])) : date('d/m/Y h:i:s a');
    $formaPago  = !empty($venta['forma_pago']) ? $venta['forma_pago'] : 'EFECTIVO';

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, 'FECHA: ' . $fechaVenta, 0, 1, 'L');
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, utf8_decode('PAGO: ' . $formaPago), 0, 1, 'L');
    
    $nombreCliente = utf8_decode(substr($clienteData['nombres'], 0, 23));
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, 'CLIENTE: ' . $nombreCliente, 0, 1, 'L');
    
    $tipoIdent = $clienteData['tipo_identificacion'] ?? 'CEDULA';
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, $tipoIdent . ': ' . $clienteData['ci_ruc'], 0, 1, 'L');
    
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 3, utf8_decode('DOMICILIO: ' . ($clienteData['direccion'] ? substr($clienteData['direccion'], 0, 23) : 'S/N')), 0, 1, 'L');
    
    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 2, '-------------------------------------------------------', 0, 1, 'C');

    // ============================================================
    // DETALLE DE PRODUCTOS
    // ============================================================
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetX($margen);
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
            
            $pdf->SetX($margen);
            $pdf->Cell(6, 3, number_format($cantidad, 1), 0, 0, 'L');
            $pdf->Cell(25, 3, $nombreCorto, 0, 0, 'L');
            $pdf->Cell(10, 3, number_format($precioUnit, 2), 0, 0, 'R');
            $pdf->Cell(11, 3, number_format($precioTot, 2), 0, 1, 'R');
        }
    } else {
        $subtotalNum = (float)($venta['subtotal'] ?? 0.00);
        $pdf->SetX($margen);
        $pdf->Cell(6, 3, '1.0', 0, 0, 'L');
        $pdf->Cell(25, 3, utf8_decode('CONSUMO GENERAL'), 0, 0, 'L');
        $pdf->Cell(10, 3, number_format($subtotalNum, 2), 0, 0, 'R');
        $pdf->Cell(11, 3, number_format($subtotalNum, 2), 0, 1, 'R');
    }

    $pdf->SetX($margen);
    $pdf->Cell($anchoUtil, 2, '-------------------------------------------------------', 0, 1, 'C');

    // ============================================================
    // TOTALES
    // ============================================================
    $pdf->SetFont('Arial', '', 7);

    $subtotalTotal  = (float)($venta['subtotal'] ?? 0);
    $descuentoTotal = (float)($venta['descuento_total'] ?? 0);
    $totalFinal     = (float)($venta['total'] ?? 0);

    foreach ($agrupadoIva as $porcentaje => $montoIva) {
        $labelIva = 'IVA TARIFA ' . number_format($porcentaje, 2) . '%:';
        $pdf->SetX($margen);
        $pdf->Cell(33, 3, $labelIva, 0, 0, 'R');
        $pdf->Cell(19, 3, '$' . number_format($montoIva, 2), 0, 1, 'R');
    }

    $pdf->SetX($margen);
    $pdf->Cell(33, 3, 'SUBTOTAL:', 0, 0, 'R');
    $pdf->Cell(19, 3, '$' . number_format($subtotalTotal, 2), 0, 1, 'R');

    if ($descuentoTotal > 0) {
        $pdf->SetX($margen);
        $pdf->Cell(33, 3, 'DESCUENTO:', 0, 0, 'R');
        $pdf->Cell(19, 3, '$' . number_format($descuentoTotal, 2), 0, 1, 'R');
    }

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX($margen);
    $pdf->Cell(33, 3.5, 'TOTAL:', 0, 0, 'R');
    $pdf->Cell(19, 3.5, '$' . number_format($totalFinal, 2), 0, 1, 'R');

    // ============================================================
    // PIE DE PÁGINA
    // ============================================================
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

    // ============================================================
    // MENSAJE SRI PARA FACTURAS AUTORIZADAS
    // ============================================================
    if ($sriAutorizado) {
        $pdf->Ln(1);
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', 'I', 4.5);
        $pdf->Cell($anchoUtil, 2.5, 'COMPROBANTE AUTORIZADO POR EL SRI', 0, 1, 'C');
        $pdf->SetX($margen);
        $pdf->Cell($anchoUtil, 2.5, 'Verifique en: www.sri.gob.ec', 0, 1, 'C');
    } elseif ($tieneSRI && !$sriAutorizado) {
        $pdf->Ln(1);
        $pdf->SetX($margen);
        $pdf->SetFont('Arial', 'B', 4.5);
        $estado = $sriInfo['estado_sri'] ?? 'pendiente';
        if ($estado === 'pendiente') {
            $pdf->Cell($anchoUtil, 2.5, 'COMPROBANTE EN PROCESO DE AUTORIZACION', 0, 1, 'C');
        } elseif ($estado === 'rechazado') {
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell($anchoUtil, 2.5, 'COMPROBANTE RECHAZADO POR EL SRI', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        } else {
            $pdf->Cell($anchoUtil, 2.5, 'COMPROBANTE PENDIENTE DE AUTORIZACION', 0, 1, 'C');
        }
    }
    // ============================================================

    $pdfBase64 = base64_encode($pdf->Output('S'));

    echo json_encode([
        'status'    => 'success',
        'pdf'       => $pdfBase64,
        'pdfBase64' => $pdfBase64,
        'sri_info'  => $sriInfo,
        'es_factura' => $esFactura,
        'tiene_sri' => $tieneSRI
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