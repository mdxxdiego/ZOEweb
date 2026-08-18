# ZOE PHP

Conversión base del sistema Java ZOE a PHP nativo con PDO y arquitectura MVC simple.

## Módulos incluidos
- Login
- Dashboard / menú principal
- Clientes
- Proveedores
- Artículos
- Ventas con detalle
- Compras
- Créditos
- Caja

## Puesta en marcha
1. Crear base de datos ejecutando `sql/schema.sql`.
2. Editar `config/config.php` con tus credenciales MySQL.
3. Levantar con PHP:
   ```bash
   php -S localhost:8000 -t public
   ```
4. Ingresar con:
   - usuario: `admin`
   - clave: `admin123`

## Nota importante
Este proyecto replica la estructura funcional principal del sistema detectada en el RAR, pero no puede garantizar equivalencia 1:1 de toda la lógica Java original porque varios archivos fuente del RAR están comprimidos y no se pudieron leer directamente en este entorno.

## Siguiente paso recomendado
Si me compartes el proyecto descomprimido en ZIP o la carpeta `src` y la base SQL extraída, puedo hacer una migración mucho más exacta pantalla por pantalla.


## Nota para XAMPP/WAMP
Si abres el proyecto desde `http://localhost/zoe_php/public/`, deja en `config/config.php`:

```php
'base_url' => '/zoe_php/public',
```

Si montas un VirtualHost apuntando directamente a `public/`, usa:

```php
'base_url' => '',
```
