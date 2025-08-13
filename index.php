<?php
/**
 * Sistema de Boletos IMEPEDU - Página Principal CORRIGIDA
 * Arquivo: public/index.php
 * 
 * 🔧 CORREÇÃO: Erro Array to string conversion no log
 */

session_start();

// Inclui arquivos de configuração e classes
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
$sucesso = '';

// Processa login manual ou automático
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['cpf']) && isset($_GET['subdomain']))) {
    
    // Dados do formulário ou da URL (vindo do Moodle)
    $cpf = '';
    $subdomain = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Login manual via formulário
        $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');
        $subdomain = $_POST['subdomain'] ?? '';
    } else {
        // Login automático vindo do Moodle
        $cpf = preg_replace('/[^0-9]/', '', $_GET['cpf'] ?? '');
        $subdomain = $_GET['subdomain'] ?? '';
    }
    
    // Validações básicas
    if (empty($cpf) || strlen($cpf) != 11) {
        $erro = "CPF inválido. Deve conter 11 dígitos.";
    } elseif (empty($subdomain)) {
        $erro = "Polo não selecionado.";
    } else {
        try {
            error_log("🔐 Tentativa de login INDEX.PHP - CPF: {$cpf}, Subdomain: {$subdomain}");
            
            // Tenta conectar com a API do Moodle
            $moodleAPI = new MoodleAPI($subdomain);
            $dadosAluno = $moodleAPI->buscarAlunoPorCPF($cpf);
            
            if ($dadosAluno) {
                // Salva/atualiza dados do aluno no banco local
                $alunoService = new AlunoService();
                $alunoSalvo = $alunoService->salvarOuAtualizarAluno($dadosAluno);
                
                if (!$alunoSalvo || !isset($alunoSalvo['id'])) {
                    throw new Exception('Erro ao salvar dados do aluno no banco local');
                }
                
                $alunoId = $alunoSalvo['id'];
                
                // Cria sessão do usuário
                $_SESSION['aluno_cpf'] = $cpf;
                $_SESSION['aluno_id'] = $alunoId;
                $_SESSION['aluno_nome'] = $dadosAluno['nome'];
                $_SESSION['subdomain'] = $subdomain;
                $_SESSION['login_time'] = time();
                
                // 🔧 CORREÇÃO: Log SEM campo usuario_id problemático
                try {
                    $db = (new Database())->getConnection();
                    $stmt = $db->prepare("
                        INSERT INTO logs (tipo, descricao, ip_address, user_agent, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        'login_index',
                        "Login via index.php - Aluno ID: {$alunoId}, CPF: {$cpf}, Subdomain: {$subdomain}",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
                    ]);
                } catch (Exception $logError) {
                    error_log("⚠️ Erro ao registrar log (não crítico): " . $logError->getMessage());
                    // Não falha o login por causa do log
                }
                
                error_log("✅ Login INDEX.PHP realizado com sucesso - Aluno ID: {$alunoId}, Nome: {$dadosAluno['nome']}");
                
                // Redireciona para dashboard
                if (isset($_GET['cpf'])) {
                    // Se veio do Moodle, redireciona direto
                    header('Location: /dashboard.php');
                } else {
                    // Se foi login manual, mostra mensagem de sucesso primeiro
                    $sucesso = "Login realizado com sucesso! Redirecionando...";
                    echo "<script>setTimeout(() => { window.location.href = '/dashboard.php'; }, 2000);</script>";
                }
                exit;
                
            } else {
                $erro = "Aluno não encontrado no sistema do polo selecionado. Verifique o CPF e o polo.";
                error_log("❌ Login INDEX.PHP falhou - CPF não encontrado: {$cpf} no polo {$subdomain}");
            }
            
        } catch (Exception $e) {
            error_log("❌ Erro no login INDEX.PHP - CPF: {$cpf}, Subdomain: {$subdomain} - " . $e->getMessage());
            $erro = "Erro ao conectar com o sistema do polo. Tente novamente em alguns minutos.";
        }
    }
}

// Busca estatísticas gerais para exibir na página
try {
    $db = (new Database())->getConnection();
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
            SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as valor_total_pago
        FROM boletos
    ");
    $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $estatisticas = ['total_boletos' => 0, 'boletos_pagos' => 0, 'valor_total_pago' => 0];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Boletos - IMEPEDU Educação</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --accent-color: #00aa44;
            --light-bg: #f8f9fa;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .hero-section {
            padding: 80px 0;
            color: white;
            text-align: center;
        }
        
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 2rem;
            margin-top: 2rem;
        }
        
        .feature-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-card {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 1.5rem;
            color: white;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .btn-primary-custom {
            background: var(--primary-color);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .form-control {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0,102,204,0.25);
        }
        
        .footer {
            background: rgba(0,0,0,0.1);
            padding: 40px 0;
            margin-top: 50px;
            color: white;
        }
        
        .logo {
            font-size: 2.5rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        
        .cpf-mask {
            font-family: monospace;
        }
        
        @media (max-width: 768px) {
            .hero-section {
                padding: 40px 0;
            }
            
            .login-card {
                margin: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <a href="/" class="logo text-decoration-none">
                        <i class="fas fa-graduation-cap"></i> IMEPEDU Educação
                    </a>
                    <h1 class="display-4 mt-3 mb-4">Sistema de Boletos</h1>
                    <p class="lead mb-5">
                        Acesse seus boletos de forma rápida e segura. 
                        Organize suas mensalidades por curso e mantenha seus pagamentos em dia.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Login Section -->
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">
                <div class="login-card">
                    <div class="text-center mb-4">
                        <h3><i class="fas fa-sign-in-alt text-primary"></i> Acessar Sistema</h3>
                        <p class="text-muted">Digite seu CPF e selecione seu polo</p>
                    </div>
                    
                    <?php if ($erro): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($sucesso): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="loginForm">
                        <div class="mb-3">
                            <label for="cpf" class="form-label">
                                <i class="fas fa-id-card"></i> CPF:
                            </label>
                            <input type="text" 
                                   class="form-control cpf-mask" 
                                   id="cpf" 
                                   name="cpf" 
                                   placeholder="000.000.000-00"
                                   value="<?= htmlspecialchars($_POST['cpf'] ?? $_GET['cpf'] ?? '') ?>" 
                                   maxlength="14"
                                   required>
                            <div class="form-text">Digite apenas os números do seu CPF</div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="subdomain" class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Polo:
                            </label>
                            <select class="form-control" id="subdomain" name="subdomain" required>
                                <option value="">Selecione seu polo de estudo</option>
                                
                              <option value="tucurui.imepedu.com.br" 
                                        <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'tucurui.imepedu.com.br' ? 'selected' : '' ?>>
                                    <i class="fas fa-building"></i> Tucuruí
                                </option>
                               
                              
                                <option value="breubranco.imepedu.com.br"
                                        <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'breubranco.imepedu.com.br' ? 'selected' : '' ?>>
                                    <i class="fas fa-building"></i> Breu Branco
                                </option>
                             
                              
                                <option value="ava.imepedu.com.br"
                                        <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'ava.imepedu.com.br' ? 'selected' : '' ?>>
                                    <i class="fas fa-building"></i> AVA
                                </option>

                              <option value="igarape.imepedu.com.br"
                                        <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'igarape.imepedu.com.br' ? 'selected' : '' ?>>
                                    <i class="fas fa-building"></i> Igarapé-Miri
                              </option>
                              
                              <option value="repartimento.imepedu.com.br"
                                        <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'repartimento.imepedu.com.br' ? 'selected' : '' ?>>
                                    <i class="fas fa-building"></i> Novo Repartimento
                              </option>

                              <option value="bioquality.imepedu.com.br/ava"
                                      <?= ($_POST['subdomain'] ?? $_GET['subdomain'] ?? '') == 'bioquality.imepedu.com.br/ava' ? 'selected' : '' ?>>
                                      <i class="fas fa-building"></i> BioQuality
                              </option>
                              
                                <!-- Adicione outros polos aqui -->
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-custom w-100 mb-3">
                            <i class="fas fa-sign-in-alt"></i> Acessar Boletos
                        </button>
                        
                        <div class="text-center">
                            <small class="text-muted">
                                Problemas para acessar? 
                                <a href="mailto:suporte@imepedu.com.br" class="text-primary">
                                    Entre em contato
                                </a>
                            </small>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <div class="container mt-5">
        <div class="row">
            <div class="col-12 text-center text-white mb-4">
                <h2>Como Funciona</h2>
                <p class="lead">Simples, rápido e seguro</p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="feature-card text-center">
                    <i class="fas fa-user-check fa-3x text-primary mb-3"></i>
                    <h5>1. Faça Login</h5>
                    <p class="text-muted">
                        Use seu CPF e selecione seu polo. 
                        Se você já estiver logado no Moodle, o acesso é automático.
                    </p>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="feature-card text-center">
                    <i class="fas fa-list-alt fa-3x text-success mb-3"></i>
                    <h5>2. Visualize seus Boletos</h5>
                    <p class="text-muted">
                        Veja todos os seus boletos organizados por curso. 
                        Acompanhe vencimentos e status de pagamento.
                    </p>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="feature-card text-center">
                    <i class="fas fa-credit-card fa-3x text-warning mb-3"></i>
                    <h5>3. Realize o Pagamento</h5>
                    <p class="text-muted">
                        Baixe o PDF do boleto ou use o código de barras 
                        para pagar em qualquer banco ou lotérica.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Section -->
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center text-white">
                <div class="feature-card bg-light">
                    <h4 class="text-dark mb-3">
                        <i class="fas fa-shield-alt text-success"></i> 
                        Segurança e Privacidade
                    </h4>
                    <div class="row text-dark">
                        <div class="col-md-4">
                            <i class="fas fa-lock fa-2x text-primary mb-2"></i>
                            <h6>Conexão Segura</h6>
                            <small>Todas as informações são transmitidas com criptografia SSL</small>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-user-shield fa-2x text-primary mb-2"></i>
                            <h6>Dados Protegidos</h6>
                            <small>Seus dados pessoais são mantidos em total sigilo</small>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-server fa-2x text-primary mb-2"></i>
                            <h6>Backup Diário</h6>
                            <small>Sistema com backup automático e alta disponibilidade</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5>IMEPEDU Educação</h5>
                    <p class="mb-2">Transformando vidas através da educação</p>
                    <p class="mb-0">
                        <i class="fas fa-envelope"></i> contato@imepedu.com.br<br>
                        <i class="fas fa-phone"></i> (94) 1234-5678
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <h5>Nossos Polos</h5>
                    <p class="mb-0">
                        <i class="fas fa-map-marker-alt"></i> Tucuruí - PA<br>
                        <i class="fas fa-map-marker-alt"></i> Breu Branco - PA<br>
                        <i class="fas fa-map-marker-alt"></i> Igarapé-Miri - PA<br>
                        <i class="fas fa-map-marker-alt"></i> Novo Repartimento - PA<br>
                        <i class="fas fa-map-marker-alt"></i> BioQuality, Parauapebas - PA<br>
                        <i class="fas fa-map-marker-alt"></i> AVA - Ambiente Virtual de Aprendizagem
                    </p>
                </div>
            </div>
            <hr class="my-4">
            <div class="row">
                <div class="col-12 text-center">
                    <p class="mb-0">
                        &copy; <?= date('Y') ?> IMEPEDU Educação. Todos os direitos reservados. |
                        <a href="/admin/login.php" class="text-white">Área Administrativa</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Máscara para CPF
        document.getElementById('cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
        
        // Validação do formulário
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
            const subdomain = document.getElementById('subdomain').value;
            
            if (cpf.length !== 11) {
                e.preventDefault();
                alert('CPF deve conter 11 dígitos');
                return;
            }
            
            if (!subdomain) {
                e.preventDefault();
                alert('Selecione um polo');
                return;
            }
            
            // Valida CPF básico
            if (!validarCPF(cpf)) {
                e.preventDefault();
                alert('CPF inválido');
                return;
            }
            
            // Mostra loading
            const submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Conectando...';
            submitBtn.disabled = true;
        });
        
        // Função para validar CPF
        function validarCPF(cpf) {
            if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
                return false;
            }
            
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
            if (resto !== parseInt(cpf.charAt(10))) return false;
            
            return true;
        }
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (!alert.querySelector('.btn-close')) {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }
            });
        }, 5000);
        
        // Efeito de carregamento suave
        window.addEventListener('load', function() {
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.3s';
            setTimeout(() => {
                document.body.style.opacity = '1';
            }, 100);
        });
        
        // Se há parâmetros na URL (vindo do Moodle), submete automaticamente
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('cpf') && urlParams.get('subdomain')) {
            console.log('🔄 Login automático via Moodle detectado');
            // Pequeno delay para o usuário ver a página carregando
            setTimeout(() => {
                document.getElementById('loginForm').style.opacity = '0.5';
                const submitBtn = document.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Conectando automaticamente...';
                submitBtn.disabled = true;
            }, 500);
        }
        
        console.log('✅ Index.php carregado com correção do erro Array');
    </script>
</body>
</html>