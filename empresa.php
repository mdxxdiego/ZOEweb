<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$posibles_rutas = [__DIR__ . '/config.php', __DIR__ . '/config/config.php'];
$config_encontrado = false;
foreach ($posibles_rutas as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $config_encontrado = true;
        break;
    }
}

if (!$config_encontrado) {
    die("Error: No se encontró config.php.");
}

try {
    if (!isset($pdo)) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    $mensaje_exito = null;
    $pestaña_activa = 'empresa';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // --- GUARDAR CONFIGURACIÓN EMPRESA ---
        if (isset($_POST['btn_actualizar_empresa'])) {
            $pestaña_activa = 'empresa';
            $id_empresa = $_POST['id_empresa'];
            
            $stmt_img = $pdo->prepare("SELECT imagen_nombre, ruta_imagen FROM empresa WHERE id_empresa = ?");
            $stmt_img->execute([$id_empresa]);
            $res_img = $stmt_img->fetch();

            $imagen_nombre = $res_img['imagen_nombre'] ?? '';
            $ruta_imagen = $res_img['ruta_imagen'] ?? '';

            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $directorio = 'empresa/'; 
                if (!is_dir($directorio)) { mkdir($directorio, 0777, true); }

                $extension = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
                $nuevo_nombre = 'logo_' . date('Ymd_His') . '.' . $extension;
                $ruta_destino = $directorio . $nuevo_nombre;

                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $ruta_destino)) {
                    $imagen_nombre = $nuevo_nombre;
                    $ruta_imagen = $ruta_destino;
                }
            }

            $sql = "UPDATE empresa SET 
                    nombre_titular = ?, 
                    nombre_empresa = ?, 
                    ruc = ?, 
                    tipo_contribuyente = ?, 
                    direccion = ?, 
                    localidad = ?, 
                    provincia = ?, 
                    telefono = ?, 
                    correo_zoe = ?, 
                    pass_correo = ?, 
                    imagen_nombre = ?, 
                    ruta_imagen = ?, 
                    obliga_contabilidad = ?, 
                    punto_venta = ?, 
                    inicio_actividades = ? 
                    WHERE id_empresa = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['nombre_titular'], 
                $_POST['nombre_empresa'], 
                $_POST['ruc'], 
                $_POST['tipo_contribuyente'],
                $_POST['direccion'], 
                $_POST['localidad'], 
                $_POST['provincia'], 
                $_POST['telefono'],
                $_POST['correo_zoe'], 
                $_POST['pass_correo'], 
                $imagen_nombre, 
                $ruta_imagen,
                $_POST['obliga_contabilidad'], 
                $_POST['punto_venta'], 
                $_POST['inicio_activities'], 
                $id_empresa
            ]);
            $mensaje_exito = "Configuración de la empresa guardada exitosamente.";
        }

        // --- GUARDAR CONFIGURACIÓN SRI Y FIRMA ELECTRONICA ---
        if (isset($_POST['btn_actualizar_sri'])) {
            $pestaña_activa = 'sri';
            
            // 1. Procesar campos del formulario SRI
            if (isset($_POST['sri_config']) && is_array($_POST['sri_config'])) {
                $stmt_sri_upd = $pdo->prepare("UPDATE sri_configuracion SET valor = ? WHERE clave = ?");
                foreach ($_POST['sri_config'] as $clave => $valor) {
                    $stmt_sri_upd->execute([$valor, $clave]);
                }
            }

            // 2. Procesar subida de archivo de firma .p12
            if (isset($_FILES['firma_file']) && $_FILES['firma_file']['error'] === UPLOAD_ERR_OK) {
                $dir_cert = 'config/certificados/';
                if (!is_dir($dir_cert)) {
                    mkdir($dir_cert, 0777, true);
                }

                $extension = strtolower(pathinfo($_FILES['firma_file']['name'], PATHINFO_EXTENSION));
                
                // Forzar que el archivo guardado se llame 'firma.p12'
                $nombre_firma = 'firma.p12';
                $ruta_completa_firma = $dir_cert . $nombre_firma;

                if (move_uploaded_file($_FILES['firma_file']['tmp_name'], $ruta_completa_firma)) {
                    // Actualizar la ruta en la tabla sri_configuracion
                    $stmt_firma = $pdo->prepare("UPDATE sri_configuracion SET valor = ? WHERE clave = 'ruta_firma_p12'");
                    $stmt_firma->execute([$ruta_completa_firma]);
                }
            }

            $mensaje_exito = "Configuración del SRI y Firma Electrónica actualizadas exitosamente.";
        }
    }

    // Obtener datos de la empresa
    $query = $pdo->query("SELECT * FROM empresa LIMIT 1");
    $empresa = $query->fetch() ?: [];

    // Obtener configuraciones del SRI
    $query_sri = $pdo->query("SELECT * FROM sri_configuracion ORDER BY id ASC");
    $sri_configs = $query_sri->fetchAll() ?: [];

    // Auto-población si la tabla sri_configuracion está vacía
    if (empty($sri_configs)) {
        $defaults = [
            ['clave' => 'ambiente', 'valor' => '1', 'descripcion' => '1 = Pruebas / 2 = Producción', 'categoria' => 'general', 'tipo_dato' => 'integer'],
            ['clave' => 'tipo_emision', 'valor' => '1', 'descripcion' => '1 = Emisión Normal', 'categoria' => 'general', 'tipo_dato' => 'integer'],
            ['clave' => 'ruta_firma_p12', 'valor' => 'config/certificados/firma.p12', 'descripcion' => 'Ruta del archivo de firma electrónica .p12', 'categoria' => 'firma', 'tipo_dato' => 'string'],
            ['clave' => 'clave_firma', 'valor' => '', 'descripcion' => 'Contraseña de la firma electrónica', 'categoria' => 'firma', 'tipo_dato' => 'password'],
            ['clave' => 'url_recepcion_pruebas', 'valor' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl', 'descripcion' => 'URL Recepción SRI (Pruebas)', 'categoria' => 'webservices', 'tipo_dato' => 'string'],
            ['clave' => 'url_autorizacion_pruebas', 'valor' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl', 'descripcion' => 'URL Autorización SRI (Pruebas)', 'categoria' => 'webservices', 'tipo_dato' => 'string'],
            ['clave' => 'url_recepcion_produccion', 'valor' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl', 'descripcion' => 'URL Recepción SRI (Producción)', 'categoria' => 'webservices', 'tipo_dato' => 'string'],
            ['clave' => 'url_autorizacion_produccion', 'valor' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl', 'descripcion' => 'URL Autorización SRI (Producción)', 'categoria' => 'webservices', 'tipo_dato' => 'string']
        ];

        $ins_sri = $pdo->prepare("INSERT INTO sri_configuracion (clave, valor, descripcion, categoria, tipo_dato) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaults as $def) {
            $ins_sri->execute([$def['clave'], $def['valor'], $def['descripcion'], $def['categoria'], $def['tipo_dato']]);
        }

        $query_sri = $pdo->query("SELECT * FROM sri_configuracion ORDER BY id ASC");
        $sri_configs = $query_sri->fetchAll();
    }

} catch (PDOException $e) {
    die("Error crítico: " . $e->getMessage());
}

if (file_exists('menu.php')) { include_once 'menu.php'; }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración | ZOE</title>
    <link rel="shortcut icon" href="img/logo.png" type="image/png">
    
    <style>
        :root { --azul-zoe: #3f5891; --verde-btn: #28a745; --bg-gris: #f4f6f9; --border: #e2e8f0; }
        body { background-color: var(--bg-gris); font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 0; }
        
        .main-card { 
            background: #fff; 
            max-width: 1150px; 
            margin: 20px auto; 
            border-radius: 12px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
            overflow: hidden; 
        }
        
        .card-header { background: var(--azul-zoe); color: white; padding: 25px 40px; font-weight: 600; font-size: 1.3rem; letter-spacing: 0.5px; }
        
        /* Navegación por Pestañas */
        .tabs-nav {
            display: flex;
            background: #e2e8f0;
            border-bottom: 2px solid var(--border);
        }
        .tab-btn {
            padding: 15px 30px;
            cursor: pointer;
            border: none;
            background: none;
            font-weight: 700;
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        .tab-btn.active {
            background: #ffffff;
            color: var(--azul-zoe);
            border-bottom: 3px solid var(--azul-zoe);
        }
        
        .tab-content { display: none; padding: 40px; }
        .tab-content.active { display: flex; gap: 50px; }
        .tab-content.block-active { display: block; }

        .form-body { display: flex; gap: 50px; width: 100%; }
        .inputs-area { flex: 2; display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .full { grid-column: span 2; }
        .logo-area { flex: 1; border-left: 1px solid var(--border); padding-left: 50px; text-align: center; }
        
        label { display: block; font-size: 11px; font-weight: 800; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.3px; }
        .form-control { width: 100%; padding: 12px 15px; border: 1.5px solid var(--border); border-radius: 8px; box-sizing: border-box; background: #fff; font-size: 14px; }
        
        .preview-box { width: 100%; height: 250px; border: 2px dashed #cbd5e1; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 25px; background: #f8fafc; overflow: hidden; }
        .preview-box img { max-width: 90%; max-height: 90%; object-fit: contain; }
        
        .btn-submit { background: var(--verde-btn); color: white; border: none; padding: 18px; width: 100%; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        .btn-submit:hover { background: #218838; }
        
        .success-msg { background: #dcfce7; color: #166534; padding: 18px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #bbf7d0; font-size: 14px; }
        .field-desc { font-size: 11px; color: #94a3b8; margin-top: 4px; display: block; }
        .file-status { display: inline-block; padding: 4px 8px; background: #e0f2fe; color: #0369a1; border-radius: 4px; font-size: 11px; font-weight: bold; margin-top: 5px; }
    </style>
</head>
<body>

<div class="main-card">
    <div class="card-header">CONFIGURACIÓN GENERAL DEL SISTEMA</div>
    
    <!-- Pestañas -->
    <div class="tabs-nav">
        <button class="tab-btn <?= $pestaña_activa === 'empresa' ? 'active' : '' ?>" onclick="switchTab('tab-empresa', this)">Empresa</button>
        <button class="tab-btn <?= $pestaña_activa === 'sri' ? 'active' : '' ?>" onclick="switchTab('tab-sri', this)">Configuración SRI</button>
    </div>

    <!-- Pestaña 1: Empresa -->
    <div id="tab-empresa" class="tab-content <?= $pestaña_activa === 'empresa' ? 'active' : '' ?>">
        <form method="POST" enctype="multipart/form-data" class="form-body">
            <input type="hidden" name="id_empresa" value="<?= htmlspecialchars($empresa['id_empresa'] ?? '1') ?>">

            <div class="inputs-area">
                <?php if ($mensaje_exito && $pestaña_activa === 'empresa'): ?>
                    <div class="success-msg full">✅ <?= $mensaje_exito ?></div>
                <?php endif; ?>

                <div>
                    <label>Nombre del Titular</label>
                    <input type="text" name="nombre_titular" class="form-control" value="<?= htmlspecialchars($empresa['nombre_titular'] ?? '') ?>">
                </div>
                <div>
                    <label>Razón Social / Empresa</label>
                    <input type="text" name="nombre_empresa" class="form-control" value="<?= htmlspecialchars($empresa['nombre_empresa'] ?? '') ?>">
                </div>
                <div>
                    <label>RUC / Identificación</label>
                    <input type="text" name="ruc" class="form-control" value="<?= htmlspecialchars($empresa['ruc'] ?? '') ?>">
                </div>
                
                <div>
                    <label>Tipo Contribuyente</label>
                    <select name="tipo_contribuyente" class="form-control">
                        <option value="">-- Seleccionar --</option>
                        <option value="REGIMEN NEGOCIO POPULAR" <?= ($empresa['tipo_contribuyente'] ?? '') == 'REGIMEN NEGOCIO POPULAR' ? 'selected' : '' ?>>REGIMEN NEGOCIO POPULAR</option>
                        <option value="REGIMEN GENERAL" <?= ($empresa['tipo_contribuyente'] ?? '') == 'REGIMEN GENERAL' ? 'selected' : '' ?>>REGIMEN GENERAL</option>
                        <option value="REGIMEN MICROEMPRESAS" <?= ($empresa['tipo_contribuyente'] ?? '') == 'REGIMEN MICROEMPRESAS' ? 'selected' : '' ?>>REGIMEN MICROEMPRESAS</option>
                        <option value="REGIMEN RIMPE EMPRENDEDOR" <?= ($empresa['tipo_contribuyente'] ?? '') == 'REGIMEN RIMPE EMPRENDEDOR' ? 'selected' : '' ?>>REGIMEN RIMPE EMPRENDEDOR</option>
                    </select>
                </div>

                <div class="full">
                    <label>Dirección Matriz</label>
                    <input type="text" name="direccion" class="form-control" value="<?= htmlspecialchars($empresa['direccion'] ?? '') ?>">
                </div>
                <div>
                    <label>Ciudad / Localidad</label>
                    <input type="text" name="localidad" class="form-control" value="<?= htmlspecialchars($empresa['localidad'] ?? '') ?>">
                </div>
                <div>
                    <label>Provincia</label>
                    <input type="text" name="provincia" class="form-control" value="<?= htmlspecialchars($empresa['provincia'] ?? '') ?>">
                </div>
                <div>
                    <label>Teléfono de Contacto</label>
                    <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($empresa['telefono'] ?? '') ?>">
                </div>
                <div>
                    <label>Obligado a llevar Contabilidad</label>
                    <select name="obliga_contabilidad" class="form-control">
                        <option value="NO" <?= ($empresa['obliga_contabilidad'] ?? '') == 'NO' ? 'selected' : '' ?>>NO</option>
                        <option value="SI" <?= ($empresa['obliga_contabilidad'] ?? '') == 'SI' ? 'selected' : '' ?>>SI</option>
                    </select>
                </div>
                
                <div>
                    <label>Correo Electrónico (ZOE)</label>
                    <input type="email" name="correo_zoe" class="form-control" value="<?= htmlspecialchars($empresa['correo_zoe'] ?? '') ?>">
                </div>
                <div>
                    <label>Password Correo (Seguridad)</label>
                    <input type="password" name="pass_correo" class="form-control" value="<?= htmlspecialchars($empresa['pass_correo'] ?? '') ?>">
                </div>

                <div>
                    <label>Punto de Venta (Emisión)</label>
                    <input type="number" name="punto_venta" class="form-control" value="<?= htmlspecialchars($empresa['punto_venta'] ?? '1') ?>">
                </div>
                <div>
                    <label>Inicio de Actividades</label>
                    <input type="date" name="inicio_activities" class="form-control" value="<?= htmlspecialchars($empresa['inicio_actividades'] ?? '') ?>">
                </div>
            </div>

            <div class="logo-area">
                <label>Vista previa del Logo</label>
                <div class="preview-box" id="logoPreview">
                    <?php 
                    $path = 'empresa/' . ($empresa['imagen_nombre'] ?? '');
                    if (!empty($empresa['imagen_nombre']) && file_exists($path)): 
                    ?>
                        <img src="<?= $path ?>?v=<?= time() ?>" alt="Logo Empresa">
                    <?php else: ?>
                        <span style="color:#94a3b8; font-size: 13px;">SIN LOGOTIPO CARGADO</span>
                    <?php endif; ?>
                </div>
                
                <label>Subir nuevo logo</label>
                <input type="file" name="logo_file" class="form-control" accept="image/*" onchange="loadFile(event)">
                
                <button type="submit" name="btn_actualizar_empresa" class="btn-submit" style="margin-top: 20px;">
                    ACTUALIZAR EMPRESA
                </button>
            </div>
        </form>
    </div>

    <!-- Pestaña 2: Formulario del SRI -->
    <div id="tab-sri" class="tab-content <?= $pestaña_activa === 'sri' ? 'block-active' : '' ?>">
        <form method="POST" enctype="multipart/form-data" style="width: 100%;">
            <?php if ($mensaje_exito && $pestaña_activa === 'sri'): ?>
                <div class="success-msg">✅ <?= $mensaje_exito ?></div>
            <?php endif; ?>

            <div class="inputs-area">
                <?php foreach ($sri_configs as $config): ?>
                    <div class="<?= ($config['tipo_dato'] === 'text' || strlen($config['valor']) > 50) ? 'full' : '' ?>">
                        <label><?= htmlspecialchars(str_replace('_', ' ', $config['clave'])) ?></label>
                        
                        <?php if ($config['clave'] === 'ruta_firma_p12'): ?>
                            <!-- Campo para Cargar/Subir Archivo Firma .p12 -->
                            <input type="file" name="firma_file" class="form-control" accept=".p12,.pfx">
                            <input type="hidden" name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" value="<?= htmlspecialchars($config['valor']) ?>">
                            <?php if (!empty($config['valor']) && file_exists($config['valor'])): ?>
                                <span class="file-status">✓ Archivo de firma guardado en: <?= htmlspecialchars($config['valor']) ?></span>
                            <?php else: ?>
                                <span class="field-desc">Ruta guardada: <?= htmlspecialchars($config['valor'] ?: 'Sin archivo cargado') ?></span>
                            <?php endif; ?>

                        <?php elseif ($config['clave'] === 'ambiente'): ?>
                            <select name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" class="form-control">
                                <option value="1" <?= $config['valor'] == '1' ? 'selected' : '' ?>>1 - PRUEBAS</option>
                                <option value="2" <?= $config['valor'] == '2' ? 'selected' : '' ?>>2 - PRODUCCIÓN</option>
                            </select>

                        <?php elseif ($config['clave'] === 'tipo_emision'): ?>
                            <select name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" class="form-control">
                                <option value="1" <?= $config['valor'] == '1' ? 'selected' : '' ?>>1 - EMISIÓN NORMAL</option>
                            </select>

                        <?php elseif ($config['tipo_dato'] === 'password'): ?>
                            <input type="password" name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" class="form-control" value="<?= htmlspecialchars($config['valor']) ?>">

                        <?php elseif ($config['tipo_dato'] === 'boolean'): ?>
                            <select name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" class="form-control">
                                <option value="1" <?= $config['valor'] == '1' ? 'selected' : '' ?>>SI / ACTIVADO</option>
                                <option value="0" <?= $config['valor'] == '0' ? 'selected' : '' ?>>NO / DESACTIVADO</option>
                            </select>

                        <?php elseif ($config['tipo_dato'] === 'integer'): ?>
                            <input type="number" name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" class="form-control" value="<?= htmlspecialchars($config['valor']) ?>">

                        <?php elseif ($config['tipo_dato'] === 'text'): ?>
                            <textarea name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" class="form-control" rows="3"><?= htmlspecialchars($config['valor']) ?></textarea>

                        <?php else: ?>
                            <input type="text" name="sri_config[<?= htmlspecialchars($config['clave']) ?>]" class="form-control" value="<?= htmlspecialchars($config['valor']) ?>">
                        <?php endif; ?>

                        <?php if (!empty($config['descripcion']) && $config['clave'] !== 'ruta_firma_p12'): ?>
                            <span class="field-desc"><?= htmlspecialchars($config['descripcion']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="full" style="margin-top: 20px;">
                    <button type="submit" name="btn_actualizar_sri" class="btn-submit">
                        GUARDAR CONFIGURACIÓN SRI
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    var loadFile = function(event) {
        var output = document.getElementById('logoPreview');
        var reader = new FileReader();
        reader.onload = function(){
            output.innerHTML = '<img src="' + reader.result + '">';
        };
        reader.readAsDataURL(event.target.files[0]);
    };

    function switchTab(tabId, element) {
        document.querySelectorAll('.tab-content').forEach(function(content) {
            content.classList.remove('active', 'block-active');
        });
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            btn.classList.remove('active');
        });

        var targetTab = document.getElementById(tabId);
        if (tabId === 'tab-sri') {
            targetTab.classList.add('block-active');
        } else {
            targetTab.classList.add('active');
        }
        element.classList.add('active');
    }
</script>

<?php if (file_exists('footer.php')) { include_once 'footer.php'; } ?>
</body>
</html>