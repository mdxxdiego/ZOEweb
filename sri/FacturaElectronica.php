<?php
/**
 * Clase principal de Facturación Electrónica
 * Archivo: sri/FacturaElectronica.php
 */
class FacturaElectronica {
    private $pdo;
    private $sriConfig;
    private $sriAuth;
    private $sriXml;
    private $sriSoap;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        try {
            $this->sriConfig = new SriConfig($pdo);
            $this->inicializarSri();
        } catch (Exception $e) {
            error_log("Error al inicializar SRI: " . $e->getMessage() . " - Se usará modo simulación.");
            // Inicializar con valores por defecto para que no falle
            $this->sriConfig = null;
            $this->sriAuth = null;
            $this->sriXml = null;
            $this->sriSoap = null;
        }
    }
    
    private function inicializarSri() {
        if (!$this->sriConfig) {
            return;
        }
        $config = [
            'ambiente' => $this->sriConfig->get('sri_ambiente', 1),
            'ruc_emisor' => $this->sriConfig->get('sri_emisor_ruc'),
            'razon_social' => $this->sriConfig->get('sri_emisor_razon_social'),
            'nombre_comercial' => $this->sriConfig->get('sri_emisor_nombre_comercial'),
            'tipo_contribuyente' => $this->sriConfig->get('sri_emisor_tipo_contribuyente'),
            'obligado_contabilidad' => $this->sriConfig->get('sri_emisor_obligado_contabilidad'),
            'direccion' => $this->sriConfig->get('sri_emisor_direccion')
        ];
        
        $certRuta = $this->sriConfig->getCertificadoRuta();
        $certPass = $this->sriConfig->getCertificadoPassword();
        
        // Verificar que el certificado existe, si no, se usará modo simulación
        if (!file_exists($certRuta)) {
            $certRuta = __DIR__ . '/../' . $certRuta;
            if (!file_exists($certRuta)) {
                error_log("Certificado no encontrado en: " . $certRuta . " - Se usará modo simulación.");
                $certRuta = null;
            }
        }
        
        if ($certRuta && file_exists($certRuta)) {
            try {
                $this->sriAuth = new SriAuth($certRuta, $certPass);
                $this->sriXml = new SriXmlGenerator($config);
                $this->sriSoap = new SriSoapClient($this->sriConfig->get('sri_ambiente', 1));
            } catch (Exception $e) {
                error_log("Error al inicializar SRI: " . $e->getMessage() . " - Modo simulación.");
                $this->sriAuth = null;
                $this->sriXml = null;
                $this->sriSoap = null;
            }
        } else {
            $this->sriAuth = null;
            $this->sriXml = null;
            $this->sriSoap = null;
        }
    }
    
    public function procesarFactura($ventaId) {
        try {
            // Se elimina $this->pdo->beginTransaction() para evitar conflictos con la transacción activa de venta.php[cite: 1, 9]
            
            $venta = $this->obtenerVenta($ventaId);
            if (!$venta) {
                throw new Exception("Venta no encontrada");
            }
            
            $secuencia = $this->obtenerSiguienteSecuencia('factura');
            $claveAcceso = $this->generarClaveAcceso($venta, $secuencia);
            $detalles = $this->obtenerDetallesVenta($ventaId);
            $datosFactura = $this->construirDatosFactura($venta, $detalles, $secuencia, $claveAcceso);
            
            // Si no hay SRI configurado, simular autorización
            if (!$this->sriXml || !$this->sriAuth) {
                return $this->simularAutorizacion($ventaId, $claveAcceso);
            }
            
            // Generar XML
            $xml = $this->sriXml->generarFactura($datosFactura);
            error_log("XML generado, tamaño: " . strlen($xml));
            
            // Firmar XML
            $xmlFirmado = $this->sriAuth->firmarXML($xml);
            error_log("XML firmado, tamaño: " . strlen($xmlFirmado));
            
            // Guardar XML firmado
            $logDir = __DIR__ . '/../logs/sri/xml_enviados/';
            if (!is_dir($logDir)) mkdir($logDir, 0777, true);
            $archivoXML = $logDir . date('Y-m-d') . '_' . $claveAcceso . '.xml';
            file_put_contents($archivoXML, $xmlFirmado);
            
            // Registrar comprobante
            $comprobanteId = $this->registrarComprobante($ventaId, $claveAcceso, $secuencia, $xmlFirmado);
            error_log("Comprobante registrado, ID: " . $comprobanteId);
            
            $ambiente = $this->sriConfig ? $this->sriConfig->get('sri_ambiente', 1) : 1;
            if ($ambiente == 1) {
                // MODO PRUEBAS: simular autorización
                return $this->simularAutorizacion($ventaId, $claveAcceso, $comprobanteId);
            }
            
            // MODO PRODUCCIÓN: enviar al SRI real
            $respuestaEnvio = $this->sriSoap->enviarComprobante($xmlFirmado);
            $this->actualizarEstadoComprobante($comprobanteId, $respuestaEnvio);
            
            if ($respuestaEnvio['status'] === 'recibida') {
                $resultado = $this->consultarAutorizacion($comprobanteId, $claveAcceso);
                // Control de commit delegado al script invocador (venta.php)[cite: 1, 9]
                return $resultado;
            } else {
                // Control de commit delegado al script invocador (venta.php)[cite: 1, 9]
                return ['status' => 'error', 'mensaje' => $respuestaEnvio['mensaje'] ?? 'Error en recepción'];
            }
            
        } catch (Exception $e) {
            // Control de rollback delegado al catch superior (venta.php)[cite: 1, 9]
            error_log("ERROR en procesarFactura: " . $e->getMessage());
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }
    
    private function simularAutorizacion($ventaId, $claveAcceso, $comprobanteId = null) {
        $numeroAutorizacion = 'SIM-' . date('YmdHis');
        $fechaAut = date('Y-m-d H:i:s');
        
        if ($comprobanteId) {
            $sql = "UPDATE sri_comprobantes SET estado_sri = 'autorizado', numero_autorizacion = ?, fecha_autorizacion = ?, mensaje_sri = 'Simulación SRI' WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$numeroAutorizacion, $fechaAut, $comprobanteId]);
        }
        
        $sql = "UPDATE ventas SET autorizacion_sri = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$numeroAutorizacion, $ventaId]);
        
        // Se elimina $this->pdo->commit() para mantener la transacción intacta[cite: 1]
        
        return [
            'status' => 'autorizado',
            'numero_autorizacion' => $numeroAutorizacion,
            'fecha_autorizacion' => $fechaAut,
            'mensaje' => 'Comprobante simulado (modo pruebas o sin certificado)'
        ];
    }
    
    private function generarClaveAcceso($venta, $secuencia) {
        $fecha = date('dmyHis');
        $tipoComprobante = '01';
        $ruc = $this->sriConfig ? $this->sriConfig->get('sri_emisor_ruc', '9999999999999') : '9999999999999';
        $ambiente = $this->sriConfig ? $this->sriConfig->get('sri_ambiente', 1) : 1;
        $establecimiento = str_pad($secuencia['establecimiento'], 3, '0', STR_PAD_LEFT);
        $puntoEmision = str_pad($secuencia['punto_emision'], 3, '0', STR_PAD_LEFT);
        $secuencial = str_pad($secuencia['secuencia'], 9, '0', STR_PAD_LEFT);
        $tipoEmision = '1';
        $clave = $fecha . $tipoComprobante . $ruc . $ambiente . $establecimiento . $puntoEmision . $secuencial . $tipoEmision;
        $digitoVerificador = $this->calcularDigitoVerificador($clave);
        return $clave . $digitoVerificador;
    }
    
    private function calcularDigitoVerificador($clave) {
        $coeficientes = [2,3,4,5,6,7,2,3,4,5,6,7,2,3,4,5,6,7,2,3,4,5,6,7,2,3,4,5,6,7,2,3,4,5,6,7,2,3,4,5,6,7,2,3,4,5,6,7,2];
        $suma = 0;
        for ($i = 0; $i < strlen($clave); $i++) {
            $suma += intval($clave[$i]) * $coeficientes[$i];
        }
        $digito = 11 - ($suma % 11);
        if ($digito === 11) return 0;
        if ($digito === 10) return 1;
        return $digito;
    }
    
    private function obtenerSiguienteSecuencia($tipo) {
        $stmt = $this->pdo->query("SELECT punto_venta FROM empresa LIMIT 1");
        $empresa = $stmt->fetch();
        $puntoVenta = $empresa['punto_venta'] ?? 1;
        $establecimiento = '001';
        $puntoEmision = str_pad($puntoVenta, 3, '0', STR_PAD_LEFT);
        
        $stmt = $this->pdo->prepare("SELECT secuencia_actual FROM sri_secuencias WHERE tipo_comprobante = ? AND establecimiento = ? AND punto_emision = ?");
        $stmt->execute([$tipo, $establecimiento, $puntoEmision]);
        $secuencia = $stmt->fetch();
        
        if (!$secuencia) {
            $stmt = $this->pdo->prepare("INSERT INTO sri_secuencias (tipo_comprobante, establecimiento, punto_emision, secuencia_actual) VALUES (?, ?, ?, 1)");
            $stmt->execute([$tipo, $establecimiento, $puntoEmision]);
            $numeroSecuencia = 1;
        } else {
            $numeroSecuencia = $secuencia['secuencia_actual'];
        }
        
        $stmt = $this->pdo->prepare("UPDATE sri_secuencias SET secuencia_actual = secuencia_actual + 1, updated_at = NOW() WHERE tipo_comprobante = ? AND establecimiento = ? AND punto_emision = ?");
        $stmt->execute([$tipo, $establecimiento, $puntoEmision]);
        
        return ['secuencia' => $numeroSecuencia, 'establecimiento' => $establecimiento, 'punto_emision' => $puntoEmision];
    }
    
    private function registrarComprobante($ventaId, $claveAcceso, $secuencia, $xmlFirmado) {
        $sql = "INSERT INTO sri_comprobantes (venta_id, tipo_comprobante, clave_acceso, establecimiento, punto_emision, secuencia, fecha_emision, xml_firmado, estado_sri)
                VALUES (?, 'factura', ?, ?, ?, ?, NOW(), ?, 'pendiente')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ventaId, $claveAcceso, $secuencia['establecimiento'], $secuencia['punto_emision'], str_pad($secuencia['secuencia'], 9, '0', STR_PAD_LEFT), $xmlFirmado]);
        return $this->pdo->lastInsertId();
    }
    
    private function actualizarEstadoComprobante($comprobanteId, $respuesta) {
        $estado = $respuesta['status'] === 'recibida' ? 'enviado' : 'error';
        $sql = "UPDATE sri_comprobantes SET estado_sri = ?, mensaje_sri = ?, fecha_envio = NOW() WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$estado, $respuesta['mensaje'] ?? '', $comprobanteId]);
    }
    
    private function consultarAutorizacion($comprobanteId, $claveAcceso) {
        try {
            $respuesta = $this->sriSoap->consultarAutorizacion($claveAcceso);
            $autorizado = $respuesta['status'] === 'AUTORIZADO';
            $sql = "UPDATE sri_comprobantes SET estado_sri = ?, mensaje_sri = ?, numero_autorizacion = ?, fecha_autorizacion = ? WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $autorizado ? 'autorizado' : 'rechazado',
                $respuesta['mensaje'] ?? '',
                $respuesta['numero_autorizacion'] ?? null,
                $respuesta['fecha_autorizacion'] ?? null,
                $comprobanteId
            ]);
            if ($autorizado) {
                $sql = "UPDATE ventas SET autorizacion_sri = ? WHERE id = (SELECT venta_id FROM sri_comprobantes WHERE id = ?)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$respuesta['numero_autorizacion'] ?? '', $comprobanteId]);
            }
            return [
                'status' => $autorizado ? 'autorizado' : 'rechazado',
                'numero_autorizacion' => $respuesta['numero_autorizacion'] ?? null,
                'fecha_autorizacion' => $respuesta['fecha_autorizacion'] ?? null,
                'mensaje' => $respuesta['mensaje'] ?? 'Sin mensaje'
            ];
        } catch (Exception $e) {
            error_log("Error consultarAutorizacion: " . $e->getMessage());
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }
    
    private function obtenerVenta($ventaId) {
        $sql = "SELECT * FROM ventas WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ventaId]);
        return $stmt->fetch();
    }
    
    private function obtenerDetallesVenta($ventaId) {
        $sql = "SELECT * FROM detalle_ventas WHERE venta_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ventaId]);
        return $stmt->fetchAll();
    }
    
    private function construirDatosFactura($venta, $detalles, $secuencia, $claveAcceso) {
        $datos = [
            'clave_acceso' => $claveAcceso,
            'establecimiento' => $secuencia['establecimiento'],
            'punto_emision' => $secuencia['punto_emision'],
            'secuencia' => str_pad($secuencia['secuencia'], 9, '0', STR_PAD_LEFT),
            'fecha_emision' => date('d/m/Y', strtotime($venta['fecha'])),
            'tipo_identificacion' => $venta['cliente_tipo_identificacion'] ?? 'CEDULA',
            'identificacion_comprador' => $venta['cliente_ci_ruc'] ?? '9999999999',
            'razon_social_comprador' => $venta['cliente_nombres'] ?? 'CONSUMIDOR FINAL',
            'correo_comprador' => $venta['cliente_correo'] ?? '',
            'direccion_establecimiento' => $this->sriConfig ? $this->sriConfig->get('sri_emisor_direccion') : 'Dirección',
            'forma_pago' => $this->getFormaPagoSRI($venta['forma_pago'] ?? 'EFECTIVO'),
            'total_sin_impuestos' => floatval($venta['subtotal']),
            'importe_total' => floatval($venta['total']),
            'impuestos' => [],
            'detalles' => [],
            'info_adicional' => [['nombre' => 'Observaciones', 'valor' => $venta['observaciones'] ?? '']]
        ];
        
        $impuestosPorcentajes = [];
        foreach ($detalles as $detalle) {
            $porcentaje = $detalle['porcentaje_iva'] ?? 0;
            if (!isset($impuestosPorcentajes[$porcentaje])) {
                $impuestosPorcentajes[$porcentaje] = ['base_imponible' => 0, 'valor' => 0];
            }
            $impuestosPorcentajes[$porcentaje]['base_imponible'] += floatval($detalle['subtotal']);
            $impuestosPorcentajes[$porcentaje]['valor'] += floatval($detalle['iva_valor']);
        }
        
        foreach ($impuestosPorcentajes as $porcentaje => $impuesto) {
            $datos['impuestos'][] = [
                'codigo' => '2',
                'codigo_porcentaje' => $this->getCodigoPorcentajeIVA($porcentaje),
                'tarifa' => $porcentaje,
                'base_imponible' => $impuesto['base_imponible'],
                'valor' => $impuesto['valor']
            ];
        }
        
        foreach ($detalles as $detalle) {
            $porcentajeIva = $detalle['porcentaje_iva'] ?? 0;
            $cantidad = floatval($detalle['cantidad']);
            $precioSinIva = floatval($detalle['subtotal']) / max($cantidad, 1);
            $datos['detalles'][] = [
                'codigo_principal' => $detalle['codigo_articulo'] ?? '000000',
                'descripcion' => $detalle['nombre_articulo'] ?? 'Producto',
                'cantidad' => $cantidad,
                'precio_unitario' => $precioSinIva,
                'descuento' => floatval($detalle['descuento_valor'] ?? 0),
                'precio_total_sin_impuesto' => floatval($detalle['subtotal']),
                'codigo_porcentaje_iva' => $this->getCodigoPorcentajeIVA($porcentajeIva),
                'tarifa_iva' => $porcentajeIva,
                'valor_iva' => floatval($detalle['iva_valor'])
            ];
        }
        return $datos;
    }
    
    private function getCodigoPorcentajeIVA($porcentaje) {
        $codigos = ['0' => '0', '12' => '2', '14' => '3', '15' => '4', '5' => '5', '8' => '8'];
        return $codigos[(string)$porcentaje] ?? '4';
    }
    
    private function getFormaPagoSRI($formaPago) {
        $formas = ['EFECTIVO' => '01', 'TARJETA DE CRÉDITO' => '05', 'TARJETA DE DÉBITO' => '02', 'TRANSFERENCIA' => '01', 'CHEQUE' => '04', 'OTROS' => '20'];
        return $formas[$formaPago] ?? '01';
    }
}
?>