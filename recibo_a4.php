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

    // --- INICIALIZACIÓN FPDF FORMATO A4 ---
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 10);

    // ---------------------------------------------------------
    // ENCABEZADO: COLUMNA IZQUIERDA (DATOS EMPRESA)
    // ---------------------------------------------------------
    $yInicial = $pdf->GetY();

    $pdf->SetXY(10, $yInicial);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->MultiCell(90, 4.5, utf8_decode($empresa['nombre_empresa']), 0, 'L');
    
    if (!empty($empresa['nombre_titular']) && $empresa['nombre_titular'] !== $empresa['nombre_empresa']) {
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->MultiCell(90, 4, utf8_decode($empresa['nombre_titular']), 0, 'L');
    }

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(25, 4, utf8_decode('Dirección:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(65, 4, utf8_decode($empresa['direccion']), 0, 'L');

    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(25, 4, utf8_decode('Régimen:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(65, 4, utf8_decode($empresa['tipo_contribuyente']), 0, 1, 'L');

    $yFinalIzquierda = $pdf->GetY();

    // ---------------------------------------------------------
    // ENCABEZADO: COLUMNA DERECHA (RECUADRO DE RECIBO DE VENTA)
    // ---------------------------------------------------------
    $pdf->SetXY(105, $yInicial);
    $numRecibo  = str_pad($venta['id'], 8, '0', STR_PAD_LEFT);
    $fechaVenta = !empty($venta['fecha']) ? date('d/m/Y H:i:s', strtotime($venta['fecha'])) : date('d/m/Y H:i:s');
    $formaPago  = !empty($venta['forma_pago']) ? $venta['forma_pago'] : 'EFECTIVO';

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(95, 5, 'R.U.C.: ' . $empresa['ruc'], 0, 1, 'L');
    
    $pdf->SetX(105);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(95, 8, utf8_decode('RECIBO DE VENTA'), 0, 1, 'L');

    $pdf->SetX(105);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(95, 5, utf8_decode('No. ') . $numRecibo, 0, 1, 'L');

    $pdf->SetX(105);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(30, 4.5, utf8_decode('FECHA / HORA:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(65, 4.5, $fechaVenta, 0, 1, 'L');

    $pdf->SetX(105);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(30, 4.5, utf8_decode('FORMA PAGO:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(65, 4.5, utf8_decode($formaPago), 0, 1, 'L');

    $yFinalDerecha = $pdf->GetY();
    $hCuadroDer = $yFinalDerecha - $yInicial + 3;

    // Dibujar borde al recuadro derecho
    $pdf->Rect(103, $yInicial - 1, 97, $hCuadroDer, 'D');

    // Posicionarse debajo del bloque superior
    $yPosLienzo = max($yFinalIzquierda, $yFinalDerecha) + 5;

    // ---------------------------------------------------------
    // DATOS DEL CLIENTE
    // ---------------------------------------------------------
    $pdf->SetXY(10, $yPosLienzo);
    $yStartCliente = $pdf->GetY();

    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(28, 4.5, utf8_decode('Cliente / Razón:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(102, 4.5, utf8_decode($clienteData['nombres']), 0, 0, 'L');

    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(20, 4.5, 'RUC / CI:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(40, 4.5, $clienteData['ci_ruc'], 0, 1, 'L');

    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(28, 4.5, utf8_decode('Dirección:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(162, 4.5, utf8_decode($clienteData['direccion'] ?: 'S/N'), 0, 1, 'L');

    $hCliente = $pdf->GetY() - $yStartCliente + 1;
    $pdf->Rect(10, $yStartCliente - 1, 190, $hCliente, 'D');

    $pdf->Ln(5);

    // ---------------------------------------------------------
    // TABLA DE DETALLES
    // ---------------------------------------------------------
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);

    // Encabezados de Columna
    $pdf->Cell(20, 6, 'Cant', 1, 0, 'C', true);
    $pdf->Cell(110, 6, utf8_decode('Descripción / Artículo'), 1, 0, 'L', true);
    $pdf->Cell(30, 6, 'Precio Unit.', 1, 0, 'R', true);
    $pdf->Cell(30, 6, 'Precio Total', 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 8);

    if (!empty($detalles)) {
        foreach ($detalles as $item) {
            $cantidad   = (float)($item['cantidad'] ?? 1);
            $nombre     = $item['nombre_articulo'] ?: 'PRODUCTO';
            $precioUnit = (float)($item['precio_unitario'] ?? 0.00);
            $precioTot  = (float)($item['precio_total'] ?? 0.00);

            $pdf->Cell(20, 5.5, number_format($cantidad, 2), 1, 0, 'C');
            $pdf->Cell(110, 5.5, utf8_decode($nombre), 1, 0, 'L');
            $pdf->Cell(30, 5.5, '$' . number_format($precioUnit, 2), 1, 0, 'R');
            $pdf->Cell(30, 5.5, '$' . number_format($precioTot, 2), 1, 1, 'R');
        }
    } else {
        $subtotalNum = (float)($venta['subtotal'] ?? 0.00);
        $pdf->Cell(20, 5.5, '1.00', 1, 0, 'C');
        $pdf->Cell(110, 5.5, utf8_decode('CONSUMO GENERAL'), 1, 0, 'L');
        $pdf->Cell(30, 5.5, '$' . number_format($subtotalNum, 2), 1, 0, 'R');
        $pdf->Cell(30, 5.5, '$' . number_format($subtotalNum, 2), 1, 1, 'R');
    }

    $pdf->Ln(4);

    // ---------------------------------------------------------
    // INFORMACIÓN ADICIONAL Y TOTALES
    // ---------------------------------------------------------
    $yInferior = $pdf->GetY();

    // BLOQUE IZQUIERDO: INFORMACIÓN ADICIONAL
    $pdf->SetXY(10, $yInferior);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(105, 5, utf8_decode('Información Adicional'), 1, 1, 'C', true);

    $yStartInfo = $pdf->GetY();
    $pdf->SetFont('Arial', 'B', 8);

    $textoObs = !empty($venta['observaciones']) ? $venta['observaciones'] : 'NINGUNA';
    $usuarioNombre = isset($_SESSION['usuario_nombre']) ? $_SESSION['usuario_nombre'] : 'DIEDAY SOFT';

    $pdf->Cell(25, 4.5, 'VENDEDOR:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(80, 4.5, utf8_decode($usuarioNombre), 0, 1, 'L');

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(25, 4.5, 'OBSERVACION:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(80, 4.5, utf8_decode($textoObs), 0, 'L');

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(25, 4.5, 'SISTEMA:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(80, 4.5, utf8_decode('Dieday Soft | ZOE Facturación'), 0, 1, 'L');

    $yEndInfo = $pdf->GetY();
    $pdf->Rect(10, $yStartInfo, 105, max(28, $yEndInfo - $yStartInfo + 2), 'D');

    // BLOQUE DERECHO: CUADRO DE TOTALES
    $pdf->SetXY(120, $yInferior);

    $subtotalTotal  = (float)($venta['subtotal'] ?? 0);
    $descuentoTotal = (float)($venta['descuento_total'] ?? 0);
    $totalFinal     = (float)($venta['total'] ?? 0);

    $renderTotalRow = function($label, $valor, $isBold = false) use ($pdf) {
        $pdf->SetX(120);
        $pdf->SetFont('Arial', $isBold ? 'B' : '', 8.5);
        $pdf->Cell(50, 5, utf8_decode($label), 1, 0, 'L');
        $pdf->Cell(30, 5, '$' . number_format($valor, 2), 1, 1, 'R');
    };

    $renderTotalRow('SUBTOTAL:', $subtotalTotal);

    if ($descuentoTotal > 0) {
        $renderTotalRow('DESCUENTO:', $descuentoTotal);
    }

    foreach ($agrupadoIva as $porcentaje => $montoIva) {
        $labelIva = 'IVA ' . number_format($porcentaje, 0) . '%:';
        $renderTotalRow($labelIva, $montoIva);
    }

    $renderTotalRow('TOTAL A PAGAR:', $totalFinal, true);

    $pdfBase64 = base64_encode($pdf->Output('S'));

    echo json_encode([
        'status'    => 'success',
        'pdf'       => $pdfBase64,
        'pdfBase64' => $pdfBase64
    ]);
    exit();

} catch (Throwable $e) {
    error_log("Error en recibo_a4.php: " . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
    exit();
}
?>