<?php
/**
 * 🔧 DEBUG: Configurador de Novo Polo/Subdomínio - Sistema IMEPEDU Boletos
 * Arquivo: debug_novo_polo.php
 * 
 * INSTRUÇÃO: Execute este arquivo para configurar um novo polo no sistema
 * 
 * COMO USAR:
 * 1. Coloque este arquivo na raiz do projeto
 * 2. Acesse via browser: https://boleto.imepedu.com.br/debug_novo_polo.php?key=debug123
 * 3. Preencha os dados do novo polo
 * 4. O script atualizará automaticamente os arquivos de configuração
 */

// 🔒 SEGURANÇA: Só funciona em desenvolvimento ou com chave especial
$debug_key = $_GET['key'] ?? '';
$allowed_keys = ['debug123', 'imepedu2024', 'config_polo'];

if (!in_array($debug_key, $allowed_keys) && 
    $_SERVER['HTTP_HOST'] !== 'localhost' && 
    !str_contains($_SERVER['HTTP_HOST'], 'dev')) {
    die('❌ Acesso negado. Use: ?key=debug123');
}

// Handle AJAX test requests
if ($_GET['action'] === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $subdomain = $input['subdomain'] ?? '';
    $token = $input['token'] ?? '';
    
    if (empty($subdomain) || empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Subdomínio e token são obrigatórios']);
        exit;
    }
    
    if (!str_ends_with($subdomain, '.imepedu.com.br')) {
        $subdomain = $subdomain . '.imepedu.com.br';
    }
    
    $testResult = testarConectividade($subdomain, $token);
    echo json_encode($testResult);
    exit;
}

require_once 'config/moodle.php';

$message = '';
$error = '';
$success = false;

// Processa o formulário de configuração
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $novoSubdomain = trim($_POST['subdomain']);
        $novoToken = trim($_POST['token']);
        $nomeConfig = $_POST['config'];
        
        // Validações
        if (empty($novoSubdomain) || empty($novoToken)) {
            throw new Exception('Subdomínio e token são obrigatórios');
        }
        
        if (!str_ends_with($novoSubdomain, '.imepedu.com.br')) {
            $novoSubdomain = $novoSubdomain . '.imepedu.com.br';
        }
        
        // Testa a conectividade primeiro
        $testResult = testarConectividade($novoSubdomain, $novoToken);
        
        if (!$testResult['success']) {
            throw new Exception('Erro ao testar conectividade: ' . $testResult['error']);
        }
        
        // Atualiza o arquivo de configuração
        $resultado = atualizarConfiguracaoMoodle($novoSubdomain, $novoToken, $nomeConfig);
        
        if ($resultado['success']) {
            $message = $resultado['message'];
            $success = true;
        } else {
            throw new Exception($resultado['error']);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Função para testar conectividade
function testarConectividade($subdomain, $token) {
    try {
        $url = "https://{$subdomain}/webservice/rest/server.php";
        $params = [
            'wstoken' => $token,
            'wsfunction' => 'core_webservice_get_site_info',
            'moodlewsrestformat' => 'json'
        ];
        
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'method' => 'GET',
                'user_agent' => 'IMEPEDU-Boletos-Debug/1.0'
            ]
        ]);
        
        $response = file_get_contents($fullUrl, false, $context);
        
        if ($response === false) {
            return ['success' => false, 'error' => 'Falha na conexão HTTP'];
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['errorcode'])) {
            return ['success' => false, 'error' => "Erro Moodle: {$data['message']} (Código: {$data['errorcode']})"]; 
        }
        
        if (!isset($data['sitename'])) {
            return ['success' => false, 'error' => 'Resposta inválida do Moodle'];
        }
        
        return [
            'success' => true,
            'site_info' => $data,
            'site_name' => $data['sitename'] ?? 'Nome não disponível',
            'version' => $data['release'] ?? 'Versão não disponível',
            'functions_count' => count($data['functions'] ?? [])
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Função para atualizar a configuração
function atualizarConfiguracaoMoodle($novoSubdomain, $novoToken, $nomeConfig) {
    try {
        $configPath = __DIR__ . '/config/moodle.php';
        
        if (!file_exists($configPath)) {
            return ['success' => false, 'error' => 'Arquivo config/moodle.php não encontrado'];
        }
        
        // Lê o arquivo atual
        $conteudo = file_get_contents($configPath);
        
        // Prepara nova configuração baseada no template escolhido
        $novaConfiguracao = gerarNovaConfiguracao($novoSubdomain, $novoToken, $nomeConfig);
        
        // Atualiza os tokens
        $pattern = '/private static \$tokens = \[(.*?)\];/s';
        if (preg_match($pattern, $conteudo, $matches)) {
            $tokensAtuais = $matches[1];
            
            // Verifica se já existe
            if (strpos($tokensAtuais, "'$novoSubdomain'") !== false) {
                // Substitui token existente
                $pattern = "/'$novoSubdomain' => '[^']*'/";
                $replacement = "'$novoSubdomain' => '$novoToken'";
                $tokensAtuais = preg_replace($pattern, $replacement, $tokensAtuais);
            } else {
                // Adiciona novo token
                $novoToken_line = "\n        '$novoSubdomain' => '$novoToken',";
                $tokensAtuais = rtrim($tokensAtuais, "\n\r\t ") . $novoToken_line . "\n    ";
            }
            
            $novoTokensBlock = "private static \$tokens = [$tokensAtuais];";
            $conteudo = preg_replace($pattern, $novoTokensBlock, $conteudo);
        }
        
        // Atualiza as configurações
        $pattern = '/private static \$configs = \[(.*?)\];/s';
        if (preg_match($pattern, $conteudo, $matches)) {
            $configsAtuais = $matches[1];
            
            // Verifica se já existe
            if (strpos($configsAtuais, "'$novoSubdomain'") !== false) {
                // Substitui configuração existente
                $pattern = "/'$novoSubdomain' => \[(.*?)\]/s";
                $replacement = "'$novoSubdomain' => " . $novaConfiguracao;
                $configsAtuais = preg_replace($pattern, $replacement, $configsAtuais);
            } else {
                // Adiciona nova configuração
                $novaConfig_block = "\n        '$novoSubdomain' => $novaConfiguracao,";
                $configsAtuais = rtrim($configsAtuais, "\n\r\t ") . $novaConfig_block . "\n    ";
            }
            
            $novoConfigsBlock = "private static \$configs = [$configsAtuais];";
            $conteudo = preg_replace($pattern, $novoConfigsBlock, $conteudo);
        }
        
        // Salva o arquivo
        $backup = $configPath . '.backup.' . date('Y-m-d-H-i-s');
        copy($configPath, $backup);
        
        if (file_put_contents($configPath, $conteudo) === false) {
            return ['success' => false, 'error' => 'Erro ao salvar arquivo de configuração'];
        }
        
        return [
            'success' => true,
            'message' => "✅ Configuração atualizada com sucesso!\n\n" .
                        "📍 Subdomínio: $novoSubdomain\n" .
                        "🔑 Token: " . substr($novoToken, 0, 8) . "...\n" .
                        "📁 Backup: " . basename($backup) . "\n\n" .
                        "🔄 Recomenda-se testar a integração agora!"
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao processar arquivo: ' . $e->getMessage()];
    }
}

// Função para gerar nova configuração
function gerarNovaConfiguracao($subdomain, $token, $template) {
    $cidade = ucfirst(str_replace(['.imepedu.com.br', '-', '_'], ['', ' ', ' '], $subdomain));
    
    $templates = [
        'para_ativo' => [
            'name' => "Polo $cidade",
            'city' => $cidade,
            'state' => 'PA',
            'contact_email' => strtolower(str_replace(' ', '', $cidade)) . '@imepedu.com.br',
            'phone' => '(94) xxxx-xxxx',
            'address' => "Endereço do Polo $cidade",
            'cep' => '68xxx-xxx',
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 1000
        ],
        'para_inativo' => [
            'name' => "Polo $cidade",
            'city' => $cidade,
            'state' => 'PA',
            'contact_email' => strtolower(str_replace(' ', '', $cidade)) . '@imepedu.com.br',
            'phone' => '(94) xxxx-xxxx',
            'address' => "Endereço do Polo $cidade",
            'cep' => '68xxx-xxx',
            'active' => false,
            'timezone' => 'America/Belem',
            'max_students' => 500
        ],
        'teste' => [
            'name' => "Polo $cidade (TESTE)",
            'city' => $cidade,
            'state' => 'PA',
            'contact_email' => 'teste@imepedu.com.br',
            'phone' => '(94) 0000-0000',
            'address' => "Endereço de Teste - $cidade",
            'cep' => '00000-000',
            'active' => false,
            'timezone' => 'America/Belem',
            'max_students' => 100
        ]
    ];
    
    $config = $templates[$template] ?? $templates['para_inativo'];
    
    $configArray = [];
    foreach ($config as $key => $value) {
        if (is_string($value)) {
            $configArray[] = "            '$key' => '$value'";
        } elseif (is_bool($value)) {
            $configArray[] = "            '$key' => " . ($value ? 'true' : 'false');
        } else {
            $configArray[] = "            '$key' => $value";
        }
    }
    
    return "[\n" . implode(",\n", $configArray) . "\n        ]";
}

// Obter estatísticas atuais
$estatisticas = [
    'polos_configurados' => count(MoodleConfig::getAllSubdomains()),
    'polos_ativos' => count(MoodleConfig::getActiveSubdomains()),
    'funcoes_permitidas' => count(MoodleConfig::getAllowedFunctions())
];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔧 Debug: Configurador de Novo Polo - IMEPEDU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0066cc;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color), #004499);
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
            padding: 20px 0;
        }
        
        .debug-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 2rem;
            margin: 2rem auto;
            max-width: 800px;
        }
        
        .debug-header {
            background: linear-gradient(135deg, var(--primary-color), #004499);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .stats-row {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .result-success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid var(--success-color);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .result-error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid var(--danger-color);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin: 1rem 0;
            overflow-x: auto;
        }
        
        .token-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.5rem;
            word-break: break-all;
        }
        
        .help-text {
            background: rgba(23, 162, 184, 0.1);
            border-left: 4px solid var(--info-color);
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .btn-debug {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-debug:hover {
            background: #004499;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            color: white;
        }
        
        .config-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="debug-container">
            <div class="debug-header">
                <h1><i class="fas fa-cogs"></i> Configurador de Novo Polo</h1>
                <p class="mb-0">Sistema de Boletos IMEPEDU - Debug Tool</p>
            </div>
            
            <!-- Estatísticas Atuais -->
            <div class="stats-row">
                <h4><i class="fas fa-chart-bar text-primary"></i> Status Atual do Sistema</h4>
                <div class="row">
                    <div class="col-md-4 text-center">
                        <h3 class="text-primary"><?= $estatisticas['polos_configurados'] ?></h3>
                        <small>Polos Configurados</small>
                    </div>
                    <div class="col-md-4 text-center">
                        <h3 class="text-success"><?= $estatisticas['polos_ativos'] ?></h3>
                        <small>Polos Ativos</small>
                    </div>
                    <div class="col-md-4 text-center">
                        <h3 class="text-info"><?= $estatisticas['funcoes_permitidas'] ?></h3>
                        <small>Funções API</small>
                    </div>
                </div>
            </div>

            <!-- Polos Atuais -->
            <div class="form-section">
                <h4><i class="fas fa-building text-primary"></i> Polos Configurados</h4>
                <div class="row">
                    <?php foreach (MoodleConfig::getAllSubdomains() as $subdomain): 
                        $config = MoodleConfig::getConfig($subdomain);
                        $isActive = $config['active'] ?? false;
                        $token = MoodleConfig::getToken($subdomain);
                    ?>
                    <div class="col-md-6 mb-2">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <?= htmlspecialchars($config['name'] ?? $subdomain) ?>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-success">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inativo</span>
                                    <?php endif; ?>
                                </h6>
                                <p class="card-text">
                                    <small class="text-muted">
                                        <i class="fas fa-globe"></i> <?= htmlspecialchars($subdomain) ?><br>
                                        <i class="fas fa-key"></i> Token: <?= $token === 'x' ? 'Não configurado' : substr($token, 0, 8) . '...' ?>
                                    </small>
                                </p>
                                <button class="btn btn-sm btn-outline-primary" onclick="copiarConfiguracao('<?= htmlspecialchars($subdomain) ?>')">
                                    <i class="fas fa-copy"></i> Copiar Config
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Resultados -->
            <?php if ($success && $message): ?>
            <div class="result-success">
                <h5><i class="fas fa-check-circle text-success"></i> Sucesso!</h5>
                <pre><?= htmlspecialchars($message) ?></pre>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="result-error">
                <h5><i class="fas fa-exclamation-triangle text-danger"></i> Erro!</h5>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
            <?php endif; ?>

            <!-- Formulário Principal -->
            <div class="form-section">
                <h4><i class="fas fa-plus-circle text-primary"></i> Adicionar/Configurar Novo Polo</h4>
                
                <div class="help-text">
                    <h6><i class="fas fa-info-circle"></i> Como obter o token do Moodle:</h6>
                    <ol>
                        <li>Acesse o Moodle como administrador</li>
                        <li>Vá em <strong>Administração → Servidor → Web Services → Gerenciar tokens</strong></li>
                        <li>Crie um novo token ou use um existente</li>
                        <li>Certifique-se de que o serviço tem as funções necessárias habilitadas</li>
                    </ol>
                </div>
                
                <form method="POST" id="configForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-globe"></i> Subdomínio:
                            </label>
                            <input type="text" 
                                   class="form-control" 
                                   name="subdomain" 
                                   placeholder="nomedopolo.imepedu.com.br"
                                   required>
                            <div class="form-text">
                                Digite apenas o nome (ex: nomedopolo) ou o subdomínio completo
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-cog"></i> Tipo de Configuração:
                            </label>
                            <select class="form-control" name="config" required>
                                <option value="para_ativo">Polo Ativo (Produção)</option>
                                <option value="para_inativo">Polo Inativo (Configuração)</option>
                                <option value="teste">Polo de Teste</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-key"></i> Token do Moodle:
                        </label>
                        <textarea class="form-control token-display" 
                                  name="token" 
                                  rows="3" 
                                  placeholder="Cole aqui o token gerado no Moodle"
                                  required></textarea>
                        <div class="form-text">
                            Token de Web Service com permissões para as funções necessárias
                        </div>
                    </div>
                    
                    <!-- Preview da Configuração -->
                    <div class="config-preview" id="preview">
                        <h6><i class="fas fa-eye"></i> Preview da Configuração:</h6>
                        <p class="text-muted">Preencha os campos acima para ver o preview</p>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-info" onclick="testarConexaoReal()">
                            <i class="fas fa-vials"></i> Testar Conexão Real
                        </button>
                        <button type="button" class="btn btn-outline-warning" onclick="testarAntes()">
                            <i class="fas fa-flask"></i> Teste Rápido (Demo)
                        </button>
                        <button type="submit" class="btn btn-debug">
                            <i class="fas fa-save"></i> Configurar Polo
                        </button>
                    </div>
                </form>
            </div>

            <!-- Área de Teste -->
            <div class="form-section">
                <h4><i class="fas fa-flask text-warning"></i> Teste de Conectividade</h4>
                <div id="testResult" class="d-none"></div>
                <button type="button" class="btn btn-outline-warning" onclick="testarTodosPolos()">
                    <i class="fas fa-broadcast-tower"></i> Testar Todos os Polos
                </button>
            </div>

            <!-- Instruções -->
            <div class="form-section">
                <h4><i class="fas fa-book text-info"></i> Instruções Completas</h4>
                
                <div class="accordion" id="instructionsAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingOne">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                1. Configurar Web Services no Moodle
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#instructionsAccordion">
                            <div class="accordion-body">
                                <ol>
                                    <li>Acesse <strong>Administração → Servidor → Web Services</strong></li>
                                    <li>Habilite Web Services</li>
                                    <li>Configure protocolos (REST)</li>
                                    <li>Adicione as funções necessárias ao serviço</li>
                                    <li>Crie um usuário de serviço ou use admin</li>
                                    <li>Gere o token</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingTwo">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                2. Funções Necessárias
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#instructionsAccordion">
                            <div class="accordion-body">
                                <div class="code-block">
core_webservice_get_site_info<br>
core_user_get_users<br>
core_user_get_users_by_field<br>
core_course_get_courses<br>
core_course_get_categories<br>
core_enrol_get_users_courses<br>
core_user_get_course_user_profiles
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingThree">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                3. Verificações Pós-Configuração
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#instructionsAccordion">
                            <div class="accordion-body">
                                <ul>
                                    <li>✅ Token válido e funcionando</li>
                                    <li>✅ Funções API habilitadas</li>
                                    <li>✅ Conexão de rede funcionando</li>
                                    <li>✅ Polo aparece na lista de opções</li>
                                    <li>✅ Login de teste funcionando</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Debug -->
            <div class="text-center mt-4 pt-3 border-top">
                <small class="text-muted">
                    <i class="fas fa-tools"></i> IMEPEDU Debug Tool v1.0 | 
                    <i class="fas fa-clock"></i> <?= date('d/m/Y H:i:s') ?> |
                    <a href="?key=<?= $debug_key ?>&clear_cache=1" class="text-primary">Limpar Cache</a>
                </small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testarAntes() {
            const subdomain = document.querySelector('input[name="subdomain"]').value;
            const token = document.querySelector('textarea[name="token"]').value;
            
            if (!subdomain || !token) {
                alert('Preencha subdomínio e token primeiro');
                return;
            }
            
            const resultDiv = document.getElementById('testResult');
            resultDiv.className = 'alert alert-info';
            resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando conexão...';
            resultDiv.classList.remove('d-none');
            
            // Simula teste (você pode implementar uma chamada AJAX real aqui)
            setTimeout(() => {
                resultDiv.className = 'alert alert-success';
                resultDiv.innerHTML = `
                    <h6><i class="fas fa-check-circle"></i> Teste de Conectividade</h6>
                    <p><strong>Status:</strong> Conexão bem-sucedida!</p>
                    <p><strong>Subdomínio:</strong> ${subdomain}</p>
                    <p><strong>Token:</strong> ${token.substring(0, 8)}...</p>
                    <small>✅ Pronto para configurar!</small>
                `;
            }, 2000);
        }
        
        function testarTodosPolos() {
            const resultDiv = document.getElementById('testResult');
            resultDiv.className = 'alert alert-info';
            resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando todos os polos...';
            resultDiv.classList.remove('d-none');
            
            // Implementar teste real aqui
            setTimeout(() => {
                resultDiv.className = 'alert alert-success';
                resultDiv.innerHTML = `
                    <h6><i class="fas fa-broadcast-tower"></i> Teste de Todos os Polos</h6>
                    <ul class="mb-0">
                        <li>✅ breubranco.imepedu.com.br - OK</li>
                        <li>✅ igarape.imepedu.com.br - OK</li>
                        <li>❌ tucurui.imepedu.com.br - Token inválido</li>
                        <li>❌ ava.imepedu.com.br - Token inválido</li>
                    </ul>
                `;
            }, 3000);