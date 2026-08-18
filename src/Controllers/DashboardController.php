<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Core\Database;
class DashboardController extends Controller {
    public function index(): void {
        $this->requireAuth();
        $pdo = Database::connection($this->config);
        $stats = [
            'clientes' => $pdo->query('SELECT COUNT(*) total FROM clientes')->fetch()['total'] ?? 0,
            'proveedores' => $pdo->query('SELECT COUNT(*) total FROM proveedores')->fetch()['total'] ?? 0,
            'articulos' => $pdo->query('SELECT COUNT(*) total FROM articulos')->fetch()['total'] ?? 0,
            'ventas' => $pdo->query('SELECT COUNT(*) total FROM ventas')->fetch()['total'] ?? 0,
        ];
        $this->render('dashboard/index', ['stats' => $stats]);
    }
}
