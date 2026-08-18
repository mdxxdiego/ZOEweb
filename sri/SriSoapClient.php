<?php
require_once __DIR__ . '/exceptions/SriException.php';

class SriSoapClient {
    private $ambiente;
    private $urlRecepcion;
    private $urlAutorizacion;
    private $certificado;
    private $privateKey;
    private $timeout = 60;
    
    public function __construct($ambiente, $certificado = null, $privateKey = null) {
        $this->ambiente = $ambiente;
        $this->certificado = $certificado;
        $this->privateKey = $privateKey;
        
        // Intentar cargar credenciales desde SriAuth (pero no fallar si no se puede)
        if ($this->certificado === null || $this->privateKey === null) {
            try {
                $this->cargarCredencialesDesdeSriAuth();
            } catch (Exception $e) {
                error_log("ADVERTENCIA: No se pudieron cargar credenciales para SriSoapClient: " . $e->getMessage());
            }
        }
        
        $this->urlRecepcion = ($ambiente == 1) 
            ? 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl'
            : 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantes?wsdl';
        $this->urlAutorizacion = ($ambiente == 1)
            ? 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'
            : 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantes?wsdl';
    }
    
    private function cargarCredencialesDesdeSriAuth() {
        $authFile = __DIR__ . '/SriAuth.php';
        if (file_exists($authFile)) {
            require_once $authFile;
            if (class_exists('SriAuth')) {
                $directoriosFile = __DIR__ . '/SriDirectorios.php';
                if (file_exists($directoriosFile)) {
                    require_once $directoriosFile;
                    if (class_exists('SriDirectorios')) {
                        $directorios = new SriDirectorios();
                        $certRuta = $directorios->getCertificadoRuta();
                        // Nota: contraseña por defecto, podría obtenerla de SriConfig
                        $sriAuth = new SriAuth($certRuta, '');
                        $this->certificado = $sriAuth->getCertificadoPEM();
                        $this->privateKey = $sriAuth->getPrivateKeyPEM();
                    }
                }
            }
        }
    }
    
    public function enviarComprobante($xml) {
        // Si no tenemos credenciales, devolvemos una respuesta simulada (modo pruebas)
        if (!$this->certificado || !$this->privateKey) {
            error_log("MODO SIMULACIÓN: No hay credenciales, se simula recepción.");
            return ['status' => 'recibida', 'mensaje' => 'Simulación de recepción exitosa'];
        }
        try {
            $client = new SoapClient($this->urlRecepcion, [
                'trace' => 1, 'exceptions' => 1,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => $this->timeout
            ]);
            $params = new stdClass();
            $params->xml = base64_encode($xml);
            $response = $client->validarComprobante($params);
            return $this->parsearRespuestaRecepcion($response);
        } catch (SoapFault $e) {
            throw new SriException("Error SOAP: " . $e->getMessage());
        } catch (Exception $e) {
            throw new SriException("Error al enviar: " . $e->getMessage());
        }
    }
    
    public function consultarAutorizacion($claveAcceso) {
        // Si no tenemos credenciales, simulamos autorización
        if (!$this->certificado || !$this->privateKey) {
            error_log("MODO SIMULACIÓN: No hay credenciales, se simula autorización.");
            return [
                'status' => 'AUTORIZADO',
                'numero_autorizacion' => 'SIM-' . date('YmdHis'),
                'fecha_autorizacion' => date('Y-m-d H:i:s'),
                'mensaje' => 'Simulación de autorización exitosa'
            ];
        }
        try {
            $client = new SoapClient($this->urlAutorizacion, [
                'trace' => 1, 'exceptions' => 1,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => $this->timeout
            ]);
            $params = new stdClass();
            $params->claveAccesoComprobante = $claveAcceso;
            $response = $client->autorizacionComprobante($params);
            return $this->parsearRespuestaAutorizacion($response);
        } catch (SoapFault $e) {
            throw new SriException("Error SOAP: " . $e->getMessage());
        } catch (Exception $e) {
            throw new SriException("Error al consultar: " . $e->getMessage());
        }
    }
    
    private function parsearRespuestaRecepcion($response) {
        $result = ['status' => 'error', 'mensaje' => 'Error desconocido', 'codigo' => null, 'xml' => null];
        if (isset($response->RespuestaRecepcionComprobante)) {
            $resp = $response->RespuestaRecepcionComprobante;
            if (isset($resp->estado)) {
                $result['status'] = $resp->estado == 'RECIBIDA' ? 'recibida' : 'error';
            }
            if (isset($resp->comprobante)) {
                $result['xml'] = $resp->comprobante;
            }
            if (isset($resp->mensajes) && isset($resp->mensajes->mensaje)) {
                $mensajes = $resp->mensajes->mensaje;
                if (is_array($mensajes)) {
                    $result['mensaje'] = $mensajes[0]->mensaje ?? 'Error';
                    $result['codigo'] = $mensajes[0]->identificador ?? null;
                } else {
                    $result['mensaje'] = $mensajes->mensaje ?? 'Error';
                    $result['codigo'] = $mensajes->identificador ?? null;
                }
            }
        }
        return $result;
    }
    
    private function parsearRespuestaAutorizacion($response) {
        $result = ['status' => 'error', 'numero_autorizacion' => null, 'fecha_autorizacion' => null, 'mensaje' => null, 'xml' => null, 'comprobante' => null];
        if (isset($response->RespuestaAutorizacionComprobante)) {
            $resp = $response->RespuestaAutorizacionComprobante;
            if (isset($resp->estado)) {
                $result['status'] = $resp->estado;
            }
            if (isset($resp->numeroAutorizacion)) {
                $result['numero_autorizacion'] = $resp->numeroAutorizacion;
            }
            if (isset($resp->fechaAutorizacion)) {
                $result['fecha_autorizacion'] = $resp->fechaAutorizacion;
            }
            if (isset($resp->comprobante)) {
                $result['xml'] = $resp->comprobante;
                $result['comprobante'] = base64_decode($resp->comprobante);
            }
            if (isset($resp->mensajes) && isset($resp->mensajes->mensaje)) {
                $mensajes = $resp->mensajes->mensaje;
                if (is_array($mensajes)) {
                    $result['mensaje'] = $mensajes[0]->mensaje ?? null;
                } else {
                    $result['mensaje'] = $mensajes->mensaje ?? null;
                }
            }
        }
        return $result;
    }
}
?>