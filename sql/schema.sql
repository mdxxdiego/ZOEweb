CREATE DATABASE IF NOT EXISTS zoe_php CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE zoe_php;

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  rol VARCHAR(50) NOT NULL DEFAULT 'admin'
);

CREATE TABLE clientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  documento VARCHAR(30),
  telefono VARCHAR(30),
  email VARCHAR(120),
  direccion VARCHAR(255),
  saldo DECIMAL(12,2) NOT NULL DEFAULT 0
);

CREATE TABLE proveedores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  ruc VARCHAR(30),
  telefono VARCHAR(30),
  email VARCHAR(120),
  direccion VARCHAR(255),
  saldo DECIMAL(12,2) NOT NULL DEFAULT 0
);

CREATE TABLE articulos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(50) NOT NULL UNIQUE,
  nombre VARCHAR(150) NOT NULL,
  categoria VARCHAR(100),
  precio_compra DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_venta DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock DECIMAL(12,2) NOT NULL DEFAULT 0
);

CREATE TABLE compras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proveedor_id INT NOT NULL,
  numero_documento VARCHAR(50) NOT NULL,
  fecha DATE NOT NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado VARCHAR(30) NOT NULL,
  FOREIGN KEY (proveedor_id) REFERENCES proveedores(id)
);

CREATE TABLE ventas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  numero_factura VARCHAR(50) NOT NULL,
  fecha DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  impuesto DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado VARCHAR(30) NOT NULL,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id)
);

CREATE TABLE venta_detalles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  venta_id INT NOT NULL,
  articulo_id INT NOT NULL,
  cantidad DECIMAL(12,2) NOT NULL,
  precio DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (venta_id) REFERENCES ventas(id),
  FOREIGN KEY (articulo_id) REFERENCES articulos(id)
);

CREATE TABLE creditos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,
  referencia_id INT NULL,
  tercero VARCHAR(150) NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  saldo DECIMAL(12,2) NOT NULL,
  fecha_vencimiento DATE NULL,
  estado VARCHAR(30) NOT NULL
);

CREATE TABLE caja_movimientos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,
  descripcion VARCHAR(255),
  monto DECIMAL(12,2) NOT NULL,
  fecha DATETIME NOT NULL
);

INSERT INTO usuarios (username, password, nombre, rol) VALUES
('admin', '$2y$10$ZxZ92qBI5m2bduCMsZ/HseTkctNk6heAQ7EzxKQEiC5EvhczFMMzC', 'Administrador', 'admin');
