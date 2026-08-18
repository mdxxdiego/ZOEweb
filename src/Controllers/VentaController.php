<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Models\Venta;
use App\Models\Cliente;
use App\Models\Articulo;
class VentaController extends Controller {
    public function index(): void {
        $this->requireAuth();
        $clientes = (new Cliente($this->config))->all();
        $articulos = (new Articulo($this->config))->all();
        $this->render('ventas/index', compact('clientes', 'articulos'));
    }
    public function store(): void {
        $this->requireAuth();
        $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
        (new Venta($this->config))->createWithItems([
            'cliente_id' => $_POST['cliente_id'],
            'numero_factura' => $_POST['numero_factura'],
            'fecha' => $_POST['fecha'],
            'subtotal' => $_POST['subtotal'],
            'impuesto' => $_POST['impuesto'],
            'total' => $_POST['total'],
            'estado' => 'emitida',
        ], $items);
        $this->redirect('/ventas');
    }
}
