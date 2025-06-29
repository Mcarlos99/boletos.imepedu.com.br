<?php
/**
 * Sistema de Boletos IMEPEDU - Página de Login
 * Arquivo: public/login.php
 * 
 * Este arquivo processa tanto login manual quanto automático do Moodle
 * Redireciona automaticamente para o dashboard após autenticação
 */

session_start();

// Inclui arquivos necessários
require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';
require_once 'src/AlunoService.php';

// Se usuário já está logado, redireciona para dashboard
if (isset($_SESSION['aluno_cpf'])) {
    header('Location: /dashboard.php');
    exit;
}

$erro = '';
$loading = false;

// Função para registrar log de tentativa de login
function registrarTentativaLogin($cpf, $subdomain, $sucesso, $erro = '') {
    try {
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, descricao, ip_address, user_agent, created_at) 
            VALUES ('login', ?, ?, ?, NOW())
        ");
        $descricao = $sucesso ? 
            "Login realizado - CPF: {$cpf}, Polo: {$subdomain}" : 
            "Falha no login - CPF: {$cpf}, Polo: {$subdomain}, Erro: {$erro}";
        
        $stmt->execute([
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}

// Função para validar CPF
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }
    return true;
}

// Processa tentativa de login
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['cpf']) && isset($_GET['subdomain']))) {
    $loading = true;
    
    // Obtém dados do formulário ou URL
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');
        $subdomain = trim($_POST['subdomain'] ?? '');
        $origem = 'manual';
    } else {
        $cpf = preg_replace('/[^0-9]/', '', $_GET['cpf'] ?? '');
        $subdomain = trim($_GET['subdomain'] ?? '');
        $origem = 'moodle';
    }
    
    // Validações
    if (empty($cpf)) {
        $erro = "CPF é obrigatório.";
    } elseif (!validarCPF($cpf)) {
        $erro = "CPF inválido.";
    } elseif (empty($subdomain)) {
        $erro = "Polo deve ser selecionado.";
    } else {
        try {
            // Verifica se o token existe para o subdomínio
            $token = MoodleConfig::getToken($subdomain);
            if (!$token) {
                $erro = "Polo não configurado no sistema.";
            } else {
                // Tenta conectar com a API do Moodle
                $moodleAPI = new MoodleAPI($subdomain);
                $dadosAluno = $moodleAPI->buscarAlunoPorCPF($cpf);
                
                if ($dadosAluno) {
                    // Salva/atualiza dados do aluno no banco local
                    $alunoService = new AlunoService();
                    $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAluno);
                    
                    // Verifica se aluno tem cursos ativos
                    $cursosAtivos = $alunoService->buscarCursosAluno($alunoId);
                    
                    if (empty($cursosAtivos)) {
                        $erro = "Nenhum curso ativo encontrado para este aluno.";
                        registrarTentativaLogin($cpf, $subdomain, false, "Sem cursos ativos");
                    } else {
                        // Cria sessão do usuário
                        $_SESSION['aluno_cpf'] = $cpf;
                        $_SESSION['aluno_id'] = $alunoId;
                        $_SESSION['aluno_nome'] = $dadosAluno['nome'];
                        $_SESSION['aluno_email'] = $dadosAluno['email'];
                        $_SESSION['subdomain'] = $subdomain;
                        $_SESSION['login_time'] = time();
                        $_SESSION['origem_login'] = $origem;
                        
                        // Atualiza último acesso do aluno
                        try {
                            $db = (new Database())->getConnection();
                            $stmt = $db->prepare("
                                UPDATE alunos 
                                SET ultimo_acesso = NOW() 
                                WHERE id = ?
                            ");
                            $stmt->execute([$alunoId]);
                        } catch (Exception $e) {
                            error_log("Erro ao atualizar último acesso: " . $e->getMessage());
                        }
                        
                        // Log de sucesso
                        registrarTentativaLogin($cpf, $subdomain, true);
                        
                        // Redireciona baseado na origem
                        if ($origem === 'moodle') {
                            // Se veio do Moodle, redireciona imediatamente
                            header('Location: /dashboard.php');
                            exit;
                        } else {
                            // Se foi login manual, mostra página de sucesso/redirecionamento
                            $loading = false;
                            echo "<script>
                                setTimeout(function() {
                                    window.location.href = '/dashboard.php';
                                }, 2000);
                            </script>";
                        }
                    }
                } else {
                    $erro = "Aluno não encontrado no sistema do polo selecionado. Verifique o CPF e tente novamente.";
                    registrarTentativaLogin($cpf, $subdomain, false, "Aluno não encontrado");
                }
            }
        } catch (Exception $e) {
            error_log("Erro no login - CPF: {$cpf}, Subdomain: {$subdomain} - " . $e->getMessage());
            $erro = "Erro temporário do sistema. Tente novamente em alguns instantes.";
            registrarTentativaLogin($cpf, $subdomain, false, $e->getMessage());
        }
    }
    
    $loading = false;
}

// Busca configuração do polo se disponível
$configPolo = [];
if (isset($subdomain) && !empty($subdomain)) {
    $configPolo = MoodleConfig::getConfig($subdomain);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Boletos IMEPEDU</title>
    
    <!-- Meta tags -->
    <meta name="description" content="Acesse seus boletos acadêmicos de forma segura">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
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
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .login-header h2 {
            margin: 0;
            font-weight: 600;
        }
        
        .login-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0,102,204,0.25);
        }
        
        .input-group-text {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px 0 0 10px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 15px;
            font-size: 16px;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,102,204,0.3);
            color: white;
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .alert {
            border-radius: 10px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-danger {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        
        .alert-success {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        
        .loading-spinner {
            display: none;
        }
        
        .loading .loading-spinner {
            display: inline-block;
        }
        
        .loading .btn-text {
            display: none;
        }
        
        .polo-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            font-size: 14px;
        }
        
        .back-link {
            color: white;
            text-decoration: none;
            position: fixed;
            top: 20px;
            left: 20px;
            padding: 10px 15px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            text-decoration: none;
        }
        
        @media (max-width: 576px) {
            .login-container {
                padding: 10px;
            }
            
            .login-header, .login-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Link de voltar -->
    <a href="/" class="back-link">
        <i class="fas fa-arrow-left"></i> Voltar
    </a>
    
    <div class="login-container">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <h2><i class="fas fa-graduation-cap"></i> IMEPEDU Educação</h2>
                <p>Sistema de Boletos</p>
            </div>
            
            <!-- Body -->
            <div class="login-body">
                <?php if ($erro): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <div><?= htmlspecialchars($erro) ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['aluno_nome']) && empty($erro)): ?>
                    <div class="alert alert-success d-flex align-items-center" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <div>
                            Login realizado com sucesso!<br>
                            <small>Redirecionando para seus boletos...</small>
                        </div>
                    </div>
                <?php else: ?>
                    <form method="POST" id="loginForm" <?= $loading ? 'class="loading"' : '' ?>>
                        <!-- CPF -->
                        <div class="form-group">
                            <label for="cpf" class="form-label">
                                <i class="fas fa-id-card text-primary"></i> CPF
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-user"></i>
                                </span>
                                <input type="text" 
                                       class="form-control" 
                                       id="cpf" 
                                       name="cpf" 
                                       placeholder="000.000.000-00"
                                       value="<?= htmlspecialchars($_POST['cpf'] ?? $_GET['cpf'] ?? '') ?>"
                                       maxlength="14"
                                       required
                                       autocomplete="off">
                            </div>
                            <small class="form-text text-muted">Digite apenas os números do seu CPF</small>
                        </div>
                        
                        <!-- Polo -->
                        <div class="form-group">
                            <label for="subdomain" class="form-label">
                                <i class="fas fa-map-marker-alt text-primary"></i> Polo de Estudo
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-building"></i>
                                </span>
                                <select class="form-control" id="subdomain" name="subdomain" required>
                                    <option value="">Selecione seu polo</option>
                                    <option value="tucurui.imepedu.com.br" 
                                            <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'tucurui.imepedu.com.br' ? 'selected' : '' ?>>
                                        Tucuruí
                                    </option>
                                    <option value="breubranco.imepedu.com.br"
                                            <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'breubranco.imepedu.com.br' ? 'selected' : '' ?>>
                                        Breu Branco
                                    </option>
                                    <option value="moju.imepedu.com.br"
                                            <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'moju.imepedu.com.br' ? 'selected' : '' ?>>
                                        Moju
                                    </option>
                                    <option value="igarape.imepedu.com.br"
                                            <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'igarape.imepedu.com.br' ? 'selected' : '' ?>>
                                        Igarapé-Miri
                                    </option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Botão de Login -->
                        <button type="submit" class="btn btn-login" id="submitBtn">
                            <span class="loading-spinner">
                                <i class="fas fa-spinner fa-spin"></i> Verificando...
                            </span>
                            <span class="btn-text">
                                <i class="fas fa-sign-in-alt"></i> Acessar Boletos
                            </span>
                        </button>
                        
                        <!-- Links úteis -->
                        <div class="text-center">
                            <small class="text-muted">
                                Problemas para acessar? 
                                <a href="mailto:suporte@imepedu.com.br" class="text-primary">
                                    <i class="fas fa-envelope"></i> Contate o suporte
                                </a>
                            </small>
                        </div>
                    </form>
                <?php endif; ?>
                
                <!-- Informações do Polo -->
                <?php if (!empty($configPolo)): ?>
                    <div class="polo-info">
                        <h6><i class="fas fa-info-circle text-primary"></i> Informações do Polo</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($configPolo['name'] ?? '') ?></strong></p>
                        <?php if (!empty($configPolo['contact_email'])): ?>
                            <p class="mb-1">
                                <i class="fas fa-envelope"></i> 
                                <?= htmlspecialchars($configPolo['contact_email']) ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($configPolo['phone'])): ?>
                            <p class="mb-0">
                                <i class="fas fa-phone"></i> 
                                <?= htmlspecialchars($configPolo['phone']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-4">
            <small class="text-white-50">
                <i class="fas fa-shield-alt"></i> Conexão segura e criptografada
            </small>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Máscara para CPF
        document.getElementById('cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                e.target.value = value;
            }
        });
        
        // Validação de CPF
        function validarCPF(cpf) {
            cpf = cpf.replace(/[^\d]+/g, '');
            if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
            
            let soma = 0;
            for (let i = 0; i < 9; i++) {
                soma += parseInt(cpf.charAt(i)) * (10 - i);
            }
            let resto = 11 - (soma % 11);
            if (resto === 10 || resto === 11) resto = 0;
            if (resto !== parseInt(cpf.charAt(9))) return false;
            
            soma = 0;
            for (let i = 0; i < 10; i++) {
                soma += parseInt(cpf.charAt(i)) * (11 - i);
            }
            resto = 11 - (soma % 11);
            if (resto === 10 || resto === 11) resto = 0;
            return resto === parseInt(cpf.charAt(10));
        }
        
        // Submissão do formulário
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const cpfInput = document.getElementById('cpf');
            const subdomainInput = document.getElementById('subdomain');
            const cpf = cpfInput.value.replace(/\D/g, '');
            
            // Validações
            if (!cpf || cpf.length !== 11) {
                e.preventDefault();
                alert('CPF deve conter 11 dígitos');
                cpfInput.focus();
                return;
            }
            
            if (!validarCPF(cpf)) {
                e.preventDefault();
                alert('CPF inválido');
                cpfInput.focus();
                return;
            }
            
            if (!subdomainInput.value) {
                e.preventDefault();
                alert('Selecione um polo');
                subdomainInput.focus();
                return;
            }
            
            // Ativa estado de loading
            this.classList.add('loading');
            document.getElementById('submitBtn').disabled = true;
        });
        
        // Auto-submit se dados vieram da URL (Moodle)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('cpf') && urlParams.get('subdomain')) {
            // Delay para mostrar a interface carregando
            setTimeout(() => {
                const form = document.getElementById('loginForm');
                if (form) {
                    form.classList.add('loading');
                    document.getElementById('submitBtn').disabled = true;
                    // form.submit(); // Comentado pois o PHP já processa GET
                }
            }, 1000);
        }
        
        // Remove alertas automaticamente após 5 segundos
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (alert.classList.contains('alert-danger')) {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }
            });
        }, 5000);
        
        // Foco automático no campo CPF se estiver vazio
        window.addEventListener('load', () => {
            const cpfField = document.getElementById('cpf');
            if (cpfField && !cpfField.value) {
                cpfField.focus();
            }
        });
    </script>
</body>
</html>