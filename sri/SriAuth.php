<?php
require_once __DIR__ . '/exceptions/SriException.php';

/**
 * Clase para manejar la autenticación y firmado de comprobantes
 */
class SriAuth {
    private $certificadoRuta;
    private $certificadoPassword;
    private $certificado;
    private $privateKey;
    private $sriDirectorios;
    
    public function __construct($certificadoRuta = null, $certificadoPassword = null) {
        // Cargar SriDirectorios si existe
        $this->cargarSriDirectorios();
        
        if ($certificadoRuta === null && $this->sriDirectorios) {
            $this->certificadoRuta = $this->sriDirectorios->getCertificadoRuta();
        } else {
            $this->certificadoRuta = $certificadoRuta;
        }
        $this->certificadoPassword = $certificadoPassword;
        
        // Intentar cargar certificado, pero no lanzar excepción si falla
        try {
            $this->cargarCertificado();
        } catch (SriException $e) {
            error_log("ADVERTENCIA: No se pudo cargar el certificado: " . $e->getMessage());
            // Dejar las propiedades vacías, se usará modo simulación en FacturaElectronica
        }
    }
    
    private function cargarSriDirectorios() {
        $directoriosFile = __DIR__ . '/SriDirectorios.php';
        if (file_exists($directoriosFile)) {
            require_once $directoriosFile;
            if (class_exists('SriDirectorios')) {
                $this->sriDirectorios = new SriDirectorios();
            }
        }
    }
    
    private function cargarCertificado() {
        if (empty($this->certificadoRuta) || !file_exists($this->certificadoRuta)) {
            throw new SriException("Certificado no encontrado en: " . $this->certificadoRuta);
        }
        $certificado = file_get_contents($this->certificadoRuta);
        if (!$certificado) {
            throw new SriException("No se pudo leer el certificado");
        }
        if (empty($this->certificadoPassword)) {
            throw new SriException("Contraseña del certificado no especificada");
        }
        if (!openssl_pkcs12_read($certificado, $certs, $this->certificadoPassword)) {
            throw new SriException("Contraseña incorrecta o certificado inválido");
        }
        if (!isset($certs['cert']) || !isset($certs['pkey'])) {
            throw new SriException("El certificado no contiene clave privada");
        }
        $this->certificado = $certs['cert'];
        $this->privateKey = $certs['pkey'];
        $this->validarVigencia();
    }
    
    private function validarVigencia() {
        $certInfo = openssl_x509_parse($this->certificado);
        $fechaActual = time();
        $fechaInicio = $certInfo['validFrom_time_t'] ?? 0;
        $fechaFin = $certInfo['validTo_time_t'] ?? 0;
        if ($fechaActual < $fechaInicio || $fechaActual > $fechaFin) {
            throw new SriException("Certificado no vigente");
        }
    }
    
    public function getCertificadoPEM() {
        return $this->certificado;
    }
    
    public function getPrivateKeyPEM() {
        return $this->privateKey;
    }
    
    public function getCertificadoRuta() {
        return $this->certificadoRuta;
    }
    
    public function firmarXML($xmlDocument) {
        // Si no hay certificado, lanzar excepción (será capturada)
        if (!$this->certificado || !$this->privateKey) {
            throw new SriException("Certificado no disponible para firmar");
        }
        
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if (!$dom->loadXML($xmlDocument)) {
            throw new SriException("Error al cargar XML para firmar");
        }
        
        $root = $dom->documentElement;
        if (!$root) {
            throw new SriException("XML sin elemento raíz");
        }
        
        // Crear Signature (simplificado para este ejemplo, pero funcional)
        $signature = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
        $root->appendChild($signature);
        $signedInfo = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignedInfo');
        $signature->appendChild($signedInfo);
        $canonicalizationMethod = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($canonicalizationMethod);
        $signatureMethod = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfo->appendChild($signatureMethod);
        $reference = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Reference');
        $reference->setAttribute('URI', '');
        $signedInfo->appendChild($reference);
        $transforms = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Transforms');
        $reference->appendChild($transforms);
        $transform = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Transform');
        $transform->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($transform);
        $digestMethod = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $reference->appendChild($digestMethod);
        
        // Calcular hash del contenido (sin la firma)
        $xmlWithoutSignature = clone $dom;
        $sigElements = $xmlWithoutSignature->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature');
        if ($sigElements->length > 0) {
            $sigElements->item(0)->parentNode->removeChild($sigElements->item(0));
        }
        $contentToSign = $xmlWithoutSignature->C14N(true, false);
        $digestValue = base64_encode(sha1($contentToSign, true));
        $digestValueElement = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue', $digestValue);
        $reference->appendChild($digestValueElement);
        
        // Firmar
        $signedInfoCanonical = $signedInfo->C14N(true, false);
        if (!openssl_sign($signedInfoCanonical, $signatureValue, $this->privateKey, OPENSSL_ALGO_SHA1)) {
            throw new SriException("Error al firmar");
        }
        $signatureValueElement = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureValue', base64_encode($signatureValue));
        $signature->appendChild($signatureValueElement);
        
        $keyInfo = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:KeyInfo');
        $signature->appendChild($keyInfo);
        $x509Data = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Data');
        $keyInfo->appendChild($x509Data);
        $certPEM = openssl_x509_export($this->certificado, $certOut);
        $certPEM = str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n"], '', $certPEM);
        $x509Certificate = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Certificate', $certPEM);
        $x509Data->appendChild($x509Certificate);
        
        return $dom->saveXML();
    }
}
?>