<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Core\Database;
class AuthController extends Controller {
    public function loginForm(): void { $this->render('auth/login'); }
    public function login(): void {
        $pdo = Database::connection($this->config);
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE username = ? LIMIT 1');
        $stmt->execute([$_POST['username'] ?? '']);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
            $_SESSION['user'] = $user;
            $this->redirect('/dashboard');
        }
        $this->render('auth/login', ['error' => 'Usuario o clave inválidos']);
    }
    public function logout(): void { session_destroy(); $this->redirect('/'); }
}
