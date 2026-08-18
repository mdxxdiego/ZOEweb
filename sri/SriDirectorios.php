<?php
/**
 * Clase para manejar la estructura de directorios SRI
 * Archivo: sri/SriDirectorios.php
 */
class SriDirectorios {
    
    private $baseDir;
    private $xmlEnviadosDir;
    private $xmlRecibidosDir;
    private $firmasDir;
    private $logsDir;
    
    public function __construct() {
        $this->baseDir = $_SERVER['DOCUMENT_ROOT'] . '/DOCUMENTOSRI/';
        $this->xmlEnviadosDir = $this->baseDir . 'XML/ENVIADOS/';
        $this->xmlRecibidosDir = $this->baseDir . 'XML/RECIBIDOS/';
        $this->firmasDir = $this->baseDir . 'FIRMAS/';
        $this->logsDir = $this->baseDir . 'LOGS/';
        
        $this->crearDirectorios();
    }
    
    /**
     * Crea todos los directorios necesarios
     */
    private function crearDirectorios() {
        $dirs = [
            $this->baseDir,
            $this->xmlEnviadosDir,
            $this->xmlRecibidosDir,
            $this->firmasDir,
            $this->logsDir
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }
    
    /**
     * Obtiene la ruta completa para un XML enviado según estructura SRI
     */
    public function getRutaXMLEnviado($claveAcceso, $ruc, $establecimiento, $puntoEmision, $secuencia) {
        $anio = substr($claveAcceso, 0, 2);
        $mes = substr($claveAcceso, 2, 2);
        $dia = substr($claveAcceso, 4, 2);
        
        $rutaFecha = $this->xmlEnviadosDir . $anio . '/' . $mes . '/' . $dia . '/';
        if (!is_dir($rutaFecha)) {
            mkdir($rutaFecha, 0777, true);
        }
        
        $nombreArchivo = $ruc . '_' . $establecimiento . '_' . $puntoEmision . '_' . $secuencia . '.xml';
        
        return $rutaFecha . $nombreArchivo;
    }
    
    /**
     * Obtiene la ruta completa para un XML recibido (respuesta del SRI)
     */
    public function getRutaXMLRecibido($claveAcceso, $ruc, $establecimiento, $puntoEmision, $secuencia) {
        $anio = substr($claveAcceso, 0, 2);
        $mes = substr($claveAcceso, 2, 2);
        $dia = substr($claveAcceso, 4, 2);
        
        $rutaFecha = $this->xmlRecibidosDir . $anio . '/' . $mes . '/' . $dia . '/';
        if (!is_dir($rutaFecha)) {
            mkdir($rutaFecha, 0777, true);
        }
        
        $nombreArchivo = $ruc . '_' . $establecimiento . '_' . $puntoEmision . '_' . $secuencia . '_RESP.xml';
        
        return $rutaFecha . $nombreArchivo;
    }
    
    /**
     * Guarda un XML en la estructura de carpetas SRI
     */
    public function guardarXML($xml, $claveAcceso, $datos, $tipo = 'enviado') {
        $ruc = $datos['ruc'] ?? '';
        $establecimiento = $datos['establecimiento'] ?? '';
        $puntoEmision = $datos['punto_emision'] ?? '';
        $secuencia = $datos['secuencia'] ?? '';
        
        if ($tipo === 'enviado') {
            $ruta = $this->getRutaXMLEnviado($claveAcceso, $ruc, $establecimiento, $puntoEmision, $secuencia);
        } else {
            $ruta = $this->getRutaXMLRecibido($claveAcceso, $ruc, $establecimiento, $puntoEmision, $secuencia);
        }
        
        file_put_contents($ruta, $xml);
        $this->registrarLog($claveAcceso, $ruta, $tipo);
        
        return $ruta;
    }
    
    /**
     * Registra en el log de SRI
     */
    private function registrarLog($claveAcceso, $ruta, $tipo) {
        $logFile = $this->logsDir . 'sri_' . date('Y-m-d') . '.log';
        $fecha = date('Y-m-d H:i:s');
        $mensaje = "[$fecha] $tipo - Clave: $claveAcceso - Archivo: $ruta" . PHP_EOL;
        file_put_contents($logFile, $mensaje, FILE_APPEND);
    }
    
    /**
     * Obtiene el directorio de firmas
     */
    public function getFirmasDir() {
        return $this->firmasDir;
    }
    
    /**
     * Obtiene la ruta del certificado
     */
    public function getCertificadoRuta() {
        return $this->firmasDir . 'firma.p12';
    }
    
    /**
     * Obtiene el directorio de logs
     */
    public function getLogsDir() {
        return $this->logsDir;
    }
}
?>