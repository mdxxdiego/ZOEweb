<?php
namespace App\Controllers;
use App\Core\Controller;
class CrudController extends Controller {
    private function modelFor(string $path): object {
        $map = [
            'clientes' => \App\Models\Cliente::class,
            'proveedores' => \App\Models\Proveedor::class,
            'articulos' => \App\Models\Articulo::class,
            'compras' => \App\Models\Compra::class,
            'creditos' => \App\Models\Credito::class,
        ];
        return new $map[$path]($this->config);
    }
    public function index(): void {
        $this->requireAuth();
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $items = $this->modelFor($path)->all();
        $this->render($path . '/index', ['items' => $items]);
    }
    public function store(): void {
        $this->requireAuth();
        $path = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0];
        $this->modelFor($path)->create($_POST);
        $this->redirect('/' . $path);
    }
    public function delete(): void {
        $this->requireAuth();
        $path = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0];
        $this->modelFor($path)->delete((int)$_POST['id']);
        $this->redirect('/' . $path);
    }
}
