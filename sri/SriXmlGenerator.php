<?php
/**
 * Generador de XML para comprobantes electrónicos SRI
 * Archivo: sri/SriXmlGenerator.php
 */
class SriXmlGenerator {
    
    private $ambiente;
    private $rucEmisor;
    private $razonSocial;
    private $nombreComercial;
    private $tipoContribuyente;
    private $obligadoContabilidad;
    private $direccionEmisor;
    
    /**
     * Constructor
     */
    public function __construct($config) {
        $this->ambiente = $config['ambiente'] ?? 1;
        $this->rucEmisor = $config['ruc_emisor'] ?? '';
        $this->razonSocial = $config['razon_social'] ?? '';
        $this->nombreComercial = $config['nombre_comercial'] ?? '';
        $this->tipoContribuyente = $config['tipo_contribuyente'] ?? 'REGIMEN GENERAL';
        $this->obligadoContabilidad = $config['obligado_contabilidad'] ?? 'NO';
        $this->direccionEmisor = $config['direccion'] ?? '';
    }
    
    /**
     * Genera el XML para una factura electrónica
     */
    public function generarFactura($data) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        // Root: factura
        $factura = $xml->createElementNS('http://www.sri.gob.ec/sri/41', 'factura');
        $factura->setAttribute('id', 'comprobante');
        $factura->setAttribute('version', '2.0.0');
        $xml->appendChild($factura);
        
        // Información tributaria
        $infoTributaria = $xml->createElement('infoTributaria');
        $factura->appendChild($infoTributaria);
        
        $infoTributaria->appendChild($xml->createElement('ambiente', $this->ambiente));
        $infoTributaria->appendChild($xml->createElement('tipoEmision', '1'));
        $infoTributaria->appendChild($xml->createElement('razonSocial', $this->razonSocial));
        $infoTributaria->appendChild($xml->createElement('nombreComercial', $this->nombreComercial));
        $infoTributaria->appendChild($xml->createElement('ruc', $this->rucEmisor));
        $infoTributaria->appendChild($xml->createElement('claveAcceso', $data['clave_acceso']));
        $infoTributaria->appendChild($xml->createElement('codDoc', '01'));
        $infoTributaria->appendChild($xml->createElement('estab', $data['establecimiento']));
        $infoTributaria->appendChild($xml->createElement('ptoEmi', $data['punto_emision']));
        $infoTributaria->appendChild($xml->createElement('secuencial', $data['secuencia']));
        $infoTributaria->appendChild($xml->createElement('dirMatriz', $this->direccionEmisor));
        
        // Información de la factura
        $infoFactura = $xml->createElement('infoFactura');
        $factura->appendChild($infoFactura);
        
        $infoFactura->appendChild($xml->createElement('fechaEmision', $data['fecha_emision']));
        $infoFactura->appendChild($xml->createElement('dirEstablecimiento', $data['direccion_establecimiento'] ?? $this->direccionEmisor));
        
        // Tipo de identificación del comprador
        $tipoIdentificacion = $this->getTipoIdentificacionSRI($data['tipo_identificacion']);
        $infoFactura->appendChild($xml->createElement('tipoIdentificacionComprador', $tipoIdentificacion));
        $infoFactura->appendChild($xml->createElement('identificacionComprador', $data['identificacion_comprador']));
        $infoFactura->appendChild($xml->createElement('razonSocialComprador', $data['razon_social_comprador']));
        
        if (!empty($data['correo_comprador'])) {
            $infoFactura->appendChild($xml->createElement('correoComprador', $data['correo_comprador']));
        }
        
        $infoFactura->appendChild($xml->createElement('totalSinImpuestos', number_format($data['total_sin_impuestos'], 2, '.', '')));
        
        // Total descuentos
        $totalDescuento = $data['total_descuento'] ?? 0;
        if ($totalDescuento > 0) {
            $infoFactura->appendChild($xml->createElement('totalDescuento', number_format($totalDescuento, 2, '.', '')));
        }
        
        // Detalles de impuestos
        $totalConImpuestos = $xml->createElement('totalConImpuestos');
        $infoFactura->appendChild($totalConImpuestos);
        
        foreach ($data['impuestos'] as $impuesto) {
            $totalImpuesto = $xml->createElement('totalImpuesto');
            $totalConImpuestos->appendChild($totalImpuesto);
            
            $totalImpuesto->appendChild($xml->createElement('codigo', $impuesto['codigo'] ?? '2'));
            $totalImpuesto->appendChild($xml->createElement('codigoPorcentaje', $impuesto['codigo_porcentaje']));
            $totalImpuesto->appendChild($xml->createElement('tarifa', number_format($impuesto['tarifa'], 2, '.', '')));
            $totalImpuesto->appendChild($xml->createElement('baseImponible', number_format($impuesto['base_imponible'], 2, '.', '')));
            $totalImpuesto->appendChild($xml->createElement('valor', number_format($impuesto['valor'], 2, '.', '')));
        }
        
        $infoFactura->appendChild($xml->createElement('importeTotal', number_format($data['importe_total'], 2, '.', '')));
        $infoFactura->appendChild($xml->createElement('moneda', 'DOLAR'));
        
        // Pagos
        $pagos = $xml->createElement('pagos');
        $infoFactura->appendChild($pagos);
        
        $pago = $xml->createElement('pago');
        $pagos->appendChild($pago);
        
        $pago->appendChild($xml->createElement('formaPago', $data['forma_pago']));
        $pago->appendChild($xml->createElement('total', number_format($data['importe_total'], 2, '.', '')));
        $pago->appendChild($xml->createElement('plazo', '0'));
        $pago->appendChild($xml->createElement('unidadTiempo', ''));
        
        // Detalles de la factura (productos/servicios)
        $detalles = $xml->createElement('detalles');
        $factura->appendChild($detalles);
        
        foreach ($data['detalles'] as $detalle) {
            $detalleNode = $xml->createElement('detalle');
            $detalles->appendChild($detalleNode);
            
            $detalleNode->appendChild($xml->createElement('codigoPrincipal', $detalle['codigo_principal']));
            
            if (!empty($detalle['codigo_auxiliar'])) {
                $detalleNode->appendChild($xml->createElement('codigoAuxiliar', $detalle['codigo_auxiliar']));
            }
            
            $detalleNode->appendChild($xml->createElement('descripcion', $detalle['descripcion']));
            $detalleNode->appendChild($xml->createElement('cantidad', number_format($detalle['cantidad'], 2, '.', '')));
            $detalleNode->appendChild($xml->createElement('precioUnitario', number_format($detalle['precio_unitario'], 2, '.', '')));
            
            if (isset($detalle['descuento']) && $detalle['descuento'] > 0) {
                $detalleNode->appendChild($xml->createElement('descuento', number_format($detalle['descuento'], 2, '.', '')));
            }
            
            $detalleNode->appendChild($xml->createElement('precioTotalSinImpuesto', number_format($detalle['precio_total_sin_impuesto'], 2, '.', '')));
            
            // Impuestos del ítem
            $detalleImpuestos = $xml->createElement('impuestos');
            $detalleNode->appendChild($detalleImpuestos);
            
            $detalleImpuesto = $xml->createElement('impuesto');
            $detalleImpuestos->appendChild($detalleImpuesto);
            
            $detalleImpuesto->appendChild($xml->createElement('codigo', '2'));
            $detalleImpuesto->appendChild($xml->createElement('codigoPorcentaje', $detalle['codigo_porcentaje_iva']));
            $detalleImpuesto->appendChild($xml->createElement('tarifa', number_format($detalle['tarifa_iva'], 2, '.', '')));
            $detalleImpuesto->appendChild($xml->createElement('baseImponible', number_format($detalle['precio_total_sin_impuesto'], 2, '.', '')));
            $detalleImpuesto->appendChild($xml->createElement('valor', number_format($detalle['valor_iva'], 2, '.', '')));
        }
        
        // Información adicional
        if (!empty($data['info_adicional'])) {
            $infoAdicional = $xml->createElement('infoAdicional');
            $factura->appendChild($infoAdicional);
            
            foreach ($data['info_adicional'] as $campo) {
                $campoNode = $xml->createElement('campoAdicional', $campo['valor']);
                $campoNode->setAttribute('nombre', $campo['nombre']);
                $infoAdicional->appendChild($campoNode);
            }
        }
        
        return $xml->saveXML();
    }
    
    /**
     * Obtiene el código SRI para el tipo de identificación
     */
    private function getTipoIdentificacionSRI($tipo) {
        $tipos = [
            'CEDULA' => '05',
            'RUC' => '04',
            'PASAPORTE' => '06',
            'IDENTIFICACION_EXTRANJERA' => '07'
        ];
        return $tipos[$tipo] ?? '05';
    }
}
?>