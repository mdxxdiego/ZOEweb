<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Models\Caja;
class CajaController extends Controller {
    public function index(): void {
        $this->requireAuth();
        $caja = new Caja($this->config);
        $this->render('caja/index', ['items' => $caja->all(), 'saldo' => $caja->saldoActual()]);
    }
    public function apertura(): void { $this->mov('apertura'); }
    public function cierre(): void { $this->mov('cierre'); }
    public function movimiento(): void { $this->mov($_POST['tipo'] ?? 'ingreso'); }
    private function mov(string $tipo): void {
        $this->requireAuth();
        (new Caja($this->config))->create([
            'tipo' => $tipo,
            'descripcion' => $_POST['descripcion'] ?? ucfirst($tipo),
            'monto' => $_POST['monto'] ?? 0,
            'fecha' => date('Y-m-d H:i:s'),
        ]);
        $this->redirect('/caja');
    }
}
