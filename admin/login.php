<?php
/**
 * Sistema de Boletos IMEPEDU - Login Administrativo
 * Arquivo: admin/login.php
 */

session_start();

// Se já está logado, redireciona
if (isset($_SESSION['admin_id'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

require_once '../config/database.php';
require_once '../src/AdminService.php';


$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = $_POST['senha'] ?? '';
    
    if (empty($login) || empty($senha)) {
        $erro = 'Login e senha são obrigatórios';
    } else {
        try {
            $adminService = new AdminService();
            $admin = $adminService->autenticarAdmin($login, $senha);
            
            // Cria sessão
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_nome'] = $admin['nome'];
            $_SESSION['admin_nivel'] = $admin['nivel'];
            $_SESSION['admin_login_time'] = time();
            
            header('Location: /admin/dashboard.php');
            exit;
            
        } catch (Exception $e) {
            $erro = $e->getMessage();
            error_log("Erro no login admin: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Administrativo - Sistema de Boletos IMEPEDU</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #0066cc, #004499);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-header {
            background: linear-gradient(135deg, #0066cc, #004499);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #0066cc;
            box-shadow: 0 0 0 0.2rem rgba(0,102,204,0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #0066cc, #004499);
            border: none;
            border-radius: 10px;
            padding: 15px;
            font-size: 16px;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,102,204,0.3);
            color: white;
        }
        
        .alert {
            border-radius: 10px;
            margin-bottom: 20px;
            border: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h2><i class="fas fa-shield-alt"></i> Área Administrativa</h2>
                <p>Sistema de Boletos IMEPEDU</p>
            </div>
            
            <div class="login-body">
                <?php if ($erro): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="loginForm">
                    <div class="mb-3">
                        <label for="login" class="form-label">
                            <i class="fas fa-user"></i> Login
                        </label>
                        <input type="text" class="form-control" id="login" name="login" 
                               value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required>
                    </div>
                    
                    <div class="mb-4">
                        <label for="senha" class="form-label">
                            <i class="fas fa-lock"></i> Senha
                        </label>
                        <input type="password" class="form-control" id="senha" name="senha" required>
                    </div>
                    
                    <button type="submit" class="btn btn-login">
                        <i class="fas fa-sign-in-alt"></i> Entrar no Sistema
                    </button>
                    
                    <div class="text-center mt-3">
                        <small class="text-muted">
                         <!--     <strong>admin</strong> / Senha: <strong>admin123</strong> -->
                        </small>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <small class="text-white-50">
                <i class="fas fa-shield-alt"></i> Acesso restrito a administradores
            </small>
            <br>
            <a href="/" class="text-white-50 text-decoration-none">
                <i class="fas fa-arrow-left"></i> Voltar ao site
            </a>
        </div>
    </div>

    <script>
        // Foco automático no campo login
        document.getElementById('login').focus();
        
        // Validação do formulário
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const login = document.getElementById('login').value.trim();
            const senha = document.getElementById('senha').value.trim();
            
            if (!login || !senha) {
                e.preventDefault();
                alert('Preencha todos os campos');
                return;
            }
            
            // Mostra loading
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Entrando...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>