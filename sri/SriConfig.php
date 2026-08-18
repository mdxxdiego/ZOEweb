<?php
/**
 * Clase de configuración SRI desde base de datos
 * Archivo: sri/SriConfig.php
 */
class SriConfig {
    private $pdo;
    private $configCache = [];
    private $tabla = 'sri_configuracion';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->cargarConfiguracionDesdeBD();
    }
    
    private function cargarConfiguracionDesdeBD() {
        try {
            $stmt = $this->pdo->query("SELECT clave, valor, tipo_dato FROM {$this->tabla}");
            $configs = $stmt->fetchAll();
            
            if (empty($configs)) {
                // No hay configuración en BD → usar valores predeterminados (modo pruebas)
                error_log("ADVERTENCIA: Tabla 'sri_configuracion' vacía. Usando valores por defecto (AMBIENTE PRUEBAS).");
                $this->configCache = $this->getDefaultConfig();
                return;
            }
            
            foreach ($configs as $config) {
                $this->configCache[$config['clave']] = $this->convertirTipo($config['valor'], $config['tipo_dato']);
            }
            error_log("Configuraciones SRI cargadas exitosamente desde BD: " . count($configs) . " registros");
            
        } catch (Exception $e) {
            error_log("ERROR al cargar configuración SRI: " . $e->getMessage() . " - Usando valores por defecto.");
            $this->configCache = $this->getDefaultConfig();
        }
    }
    
    private function getDefaultConfig() {
        return [
            'sri_ambiente' => 1, // Pruebas
            'sri_emisor_ruc' => '9999999999999',
            'sri_emisor_razon_social' => 'EMPRESA DE PRUEBA',
            'sri_emisor_nombre_comercial' => 'EMPRESA PRUEBA',
            'sri_emisor_tipo_contribuyente' => 'REGIMEN GENERAL',
            'sri_emisor_obligado_contabilidad' => 'NO',
            'sri_emisor_direccion' => 'Dirección de prueba',
            'sri_emisor_telefono' => '0000000000',
            'sri_emisor_correo' => 'prueba@empresa.com',
            'sri_certificado_ruta' => __DIR__ . '/../certificados/firma.p12',
            'sri_certificado_password' => '12345678',
            'sri_url_recepcion_pruebas' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl',
            'sri_url_recepcion_produccion' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantes?wsdl',
            'sri_url_autorizacion_pruebas' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
            'sri_url_autorizacion_produccion' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantes?wsdl'
        ];
    }
    
    private function convertirTipo($valor, $tipo) {
        switch ($tipo) {
            case 'integer': return intval($valor);
            case 'boolean': return strtoupper($valor) === 'SI' || strtoupper($valor) === 'TRUE' || $valor === '1';
            case 'json': return json_decode($valor, true);
            default: return $valor;
        }
    }
    
    public function get($clave, $default = null) {
        return $this->configCache[$clave] ?? $default;
    }
    
    public function getEmisorConfig() {
        return [
            'ruc' => $this->get('sri_emisor_ruc'),
            'razon_social' => $this->get('sri_emisor_razon_social'),
            'nombre_comercial' => $this->get('sri_emisor_nombre_comercial'),
            'tipo_contribuyente' => $this->get('sri_emisor_tipo_contribuyente'),
            'obligado_contabilidad' => $this->get('sri_emisor_obligado_contabilidad'),
            'direccion' => $this->get('sri_emisor_direccion'),
            'telefono' => $this->get('sri_emisor_telefono'),
            'correo' => $this->get('sri_emisor_correo')
        ];
    }
    
    public function getUrlRecepcion() {
        $ambiente = $this->get('sri_ambiente', 1);
        if ($ambiente == 1) return $this->get('sri_url_recepcion_pruebas');
        return $this->get('sri_url_recepcion_produccion');
    }
    
    public function getUrlAutorizacion() {
        $ambiente = $this->get('sri_ambiente', 1);
        if ($ambiente == 1) return $this->get('sri_url_autorizacion_pruebas');
        return $this->get('sri_url_autorizacion_produccion');
    }
    
    public function getCertificadoPassword() {
        return $this->get('sri_certificado_password');
    }
    
    public function getCertificadoRuta() {
        return $this->get('sri_certificado_ruta');
    }
    
    public function getAmbiente() {
        return $this->get('sri_ambiente', 1);
    }
    
    public function isModoPruebas() {
        return $this->get('sri_ambiente', 1) == 1;
    }
    
    public function isModoProduccion() {
        return $this->get('sri_ambiente', 1) == 2;
    }
}
?>