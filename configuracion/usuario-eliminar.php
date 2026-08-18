<?php
session_start();

// Validar que el ID exista
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: usuario-acceso.php");
    exit;
}

$configPath = realpath(__DIR__ . '/../../config/config.php');
$settings = require $configPath;

try {
    $dbConfig = $settings['db'];
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']};port={$dbConfig['port']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $id = (int)$_GET['id'];

    // Ejecutar eliminación
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);

    // Redirigir con mensaje de éxito
    header("Location: usuario-acceso.php?msj=deleted");
    exit;

} catch (PDOException $e) {
    die("Error al eliminar el usuario: " . $e->getMessage());
}