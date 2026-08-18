<?php
namespace App\Core;
class Controller {
    protected array $config;
    public function __construct(array $config) { $this->config = $config; }
    protected function render(string $view, array $data = []): void {
        extract($data);
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $viewFile = __DIR__ . '/../Views/' . $view . '.php';
        require $viewFile;
        require __DIR__ . '/../Views/layouts/footer.php';
    }
    protected function redirect(string $path): void {
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        header('Location: ' . $baseUrl . $path);
        exit;
    }
    protected function requireAuth(): void { if (empty($_SESSION['user'])) $this->redirect('/'); }
}
