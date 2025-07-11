<?php
/**
 * CORREÇÃO: login.php - Remove erro de Array no usuario_id
 */

session_start();

// Se já está logado, redireciona
if (isset($_SESSION['aluno_cpf'])) {
    header('Location: /dashboard.php');
    exit;
}

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/AlunoService.php';

$erro = '';
$sucesso = '';
$isProcessingLogin = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isProcessingLogin = true;
    
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');
    $subdomain = $_POST['subdomain'] ?? '';
    
    if (empty($cpf) || empty($subdomain)) {
        $erro = 'CPF e Polo são obrigatórios';
    } elseif (strlen($cpf) !== 11) {
        $erro = 'CPF deve conter 11 dígitos';
    } else {
        try {
            error_log("🔐 Tentativa de login - CPF: {$cpf}, Subdomain: {$subdomain}");
            
            $alunoService = new AlunoService();
            
            // 1. Busca aluno localmente primeiro
            $aluno = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $subdomain);
            
            // 2. Se não encontrou localmente, busca no Moodle
            if (!$aluno) {
                error_log("👤 Aluno não encontrado localmente, buscando no Moodle...");
                
                try {
                    require_once 'src/MoodleAPI.php';
                    $moodleAPI = new MoodleAPI($subdomain);
                    $dadosMoodle = $moodleAPI->buscarAlunoPorCPF($cpf);
                    
                    if ($dadosMoodle) {
                        error_log("✅ Aluno encontrado no Moodle: " . $dadosMoodle['nome']);
                        
                        // Salva aluno na base local
                        $aluno = $alunoService->salvarOuAtualizarAluno($dadosMoodle);
                        
                        if (!$aluno) {
                            throw new Exception('Erro ao salvar dados do aluno');
                        }
                    }
                } catch (Exception $moodleError) {
                    error_log("⚠️ Erro no Moodle: " . $moodleError->getMessage());
                    $erro = 'Erro ao conectar com o sistema acadêmico. Tente novamente.';
                }
            }
            
            if ($aluno) {
                // 3. Login bem-sucedido - configura sessão
                $_SESSION['aluno_id'] = $aluno['id'];
                $_SESSION['aluno_cpf'] = $aluno['cpf'];
                $_SESSION['aluno_nome'] = $aluno['nome'];
                $_SESSION['subdomain'] = $aluno['subdomain'];
                $_SESSION['login_time'] = time();
                
                // 4. Atualiza último acesso (SEM registrar em logs por enquanto)
                try {
                    $alunoService->atualizarUltimoAcesso($aluno['id']);
                } catch (Exception $updateError) {
                    error_log("⚠️ Erro ao atualizar último acesso: " . $updateError->getMessage());
                    // Não falha o login por causa disso
                }
                
                // 5. 🔧 CORREÇÃO: Log simples SEM campo usuario_id problemático
                try {
                    $db = (new Database())->getConnection();
                    $stmt = $db->prepare("
                        INSERT INTO logs (tipo, descricao, ip_address, user_agent, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        'login_success',
                        "Login realizado - Aluno ID: {$aluno['id']}, CPF: {$cpf}, Polo: {$subdomain}",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
                    ]);
                } catch (Exception $logError) {
                    error_log("⚠️ Erro ao registrar log (não crítico): " . $logError->getMessage());
                    // Não falha o login por causa do log
                }
                
                error_log("✅ Login realizado com sucesso - Aluno ID: {$aluno['id']}, Nome: {$aluno['nome']}");
                
                // 6. Redireciona para dashboard
                header('Location: /dashboard.php');
                exit;
            } else {
                $erro = 'CPF não encontrado neste polo. Verifique os dados ou entre em contato com a secretaria.';
                error_log("❌ Login falhou - CPF não encontrado: {$cpf} no polo {$subdomain}");
            }
            
        } catch (Exception $e) {
            error_log("❌ Erro no login - CPF: {$cpf}, Subdomain: {$subdomain} - " . $e->getMessage());
            $erro = 'Erro interno. Tente novamente em alguns instantes.';
        }
    }
    
    $isProcessingLogin = false;
}

// Lista de polos disponíveis
$polosDisponiveis = MoodleConfig::getActiveSubdomains();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Boletos IMEPEDU</title>
    
    <meta name="theme-color" content="#0066cc">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <link rel="manifest" href="/manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            text-align: center;
            padding: 2rem;
        }
        
        .login-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .login-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .form-floating {
            margin-bottom: 1rem;
        }
        
        .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 0.75rem;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 102, 204, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 102, 204, 0.3);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            transform: none;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
        }
        
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 1rem;
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .footer-links {
            text-align: center;
            padding: 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .footer-links a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .footer-links a:hover {
            text-decoration: underline;
        }
        
        .cpf-input {
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 10px;
            }
            
            .login-header {
                padding: 1.5rem;
            }
            
            .login-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1><i class="fas fa-graduation-cap me-2"></i>IMEPEDU</h1>
                <p>Sistema de Boletos Acadêmicos</p>
            </div>
            
            <div class="login-body">
                <?php if ($erro): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($sucesso): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($sucesso) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="loginForm">
                    <div class="form-floating">
                        <input type="text" 
                               class="form-control cpf-input" 
                               id="cpf" 
                               name="cpf" 
                               placeholder="Digite seu CPF"
                               maxlength="14"
                               required
                               value="<?= htmlspecialchars($_POST['cpf'] ?? '') ?>">
                        <label for="cpf">CPF</label>
                    </div>
                    
                    <div class="form-floating">
                        <select class="form-select" id="subdomain" name="subdomain" required>
                            <option value="">Selecione seu polo</option>
                            <?php foreach ($polosDisponiveis as $polo): ?>
                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                <option value="<?= $polo ?>" <?= ($_POST['subdomain'] ?? '') == $polo ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="subdomain">Polo de Ensino</label>
                    </div>
                    
                    <button type="submit" class="btn btn-login" id="btnLogin" <?= $isProcessingLogin ? 'disabled' : '' ?>>
                        <span id="btnText">
                            <?= $isProcessingLogin ? 'Verificando...' : 'Acessar Sistema' ?>
                        </span>
                        <span id="btnSpinner" class="spinner ms-2" style="<?= $isProcessingLogin ? '' : 'display:none;' ?>"></span>
                    </button>
                </form>
                
                <div class="loading-spinner" id="loadingSpinner">
                    <div class="spinner"></div>
                    <p class="mt-2">Verificando dados...</p>
                </div>
            </div>
            
            <div class="footer-links">
                <a href="mailto:suporte@imepedu.com.br">
                    <i class="fas fa-envelope me-1"></i>
                    Suporte Técnico
                </a>
            </div>
        </div>
    </div>
    
    <script>
        // Máscara para CPF
        document.getElementById('cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
        
        // Controle do formulário
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('btnLogin');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');
            
            btn.disabled = true;
            btnText.textContent = 'Verificando...';
            btnSpinner.style.display = 'inline-block';
        });
        
        // Auto-focus no CPF
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('cpf').focus();
        });
    </script>
</body>
</html>