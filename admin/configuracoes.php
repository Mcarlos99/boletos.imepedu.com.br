 
        
        // Toggle status do administrador
        function toggleStatusAdmin(adminId, statusAtual) {
            const novoStatus = statusAtual == 1 ? 0 : 1;
            const acao = novoStatus == 1 ? 'ativar' : 'desativar';
            
            if (confirm(`Tem certeza que deseja ${acao} este administrador?`)) {
                showToast(`${acao.charAt(0).toUpperCase() + acao.slice(1)}ando administrador...`, 'info');
                
                fetch('/admin/api/usuarios-admin.php?acao=toggle_status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        admin_id: adminId,
                        ativo: novoStatus
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(`Administrador ${acao}do com sucesso!`, 'success');
                        carregarListaAdmins();
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
            }<?php
/**
 * Sistema de Boletos IMEPEDU - Página de Configurações Administrativas
 * Arquivo: admin/configuracoes.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';

$adminService = new AdminService();
$admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$admin) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Processa salvamento de configurações
$mensagem = '';
$tipoMensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $acao = $_POST['acao'] ?? '';
        
        switch ($acao) {
            case 'salvar_geral':
                salvarConfiguracaoGeral($_POST);
                $mensagem = 'Configurações gerais salvas com sucesso!';
                $tipoMensagem = 'success';
                break;
                
                salvarConfiguracaoPolo($_POST);
                $mensagem = 'Configuração do polo salva com sucesso!';
                $tipoMensagem = 'success';
                break;
                
            case 'adicionar_admin':
                $resultado = adicionarNovoAdmin($_POST);
                $mensagem = $resultado['mensagem'];
                $tipoMensagem = $resultado['sucesso'] ? 'success' : 'danger';
                break;
                
            case 'alterar_senha':
                $resultado = alterarSenhaAdmin($_POST);
                $mensagem = $resultado['mensagem'];
                $tipoMensagem = $resultado['sucesso'] ? 'success' : 'danger';
                break;
                salvarConfiguracaoPolo($_POST);
                $mensagem = 'Configuração do polo salva com sucesso!';
                $tipoMensagem = 'success';
                break;
                
            case 'testar_conexao':
                $resultado = testarConexaoPolo($_POST['polo']);
                $mensagem = $resultado['sucesso'] ? 'Conexão testada com sucesso!' : 'Erro na conexão: ' . $resultado['erro'];
                $tipoMensagem = $resultado['sucesso'] ? 'success' : 'danger';
                break;
                
            case 'limpar_cache':
                limparCachesSistema();
                $mensagem = 'Caches do sistema limpos com sucesso!';
                $tipoMensagem = 'success';
                break;
                
            case 'limpar_logs':
                $removidos = limparLogsAntigos($_POST['dias'] ?? 30);
                $mensagem = "Logs antigos removidos: {$removidos} registros";
                $tipoMensagem = 'success';
                break;
        }
    } catch (Exception $e) {
        $mensagem = 'Erro: ' . $e->getMessage();
        $tipoMensagem = 'danger';
    }
}

// Carrega configurações atuais
$configuracoes = carregarConfiguracoes();
$polosDisponiveis = MoodleConfig::getAllSubdomains();
$estatisticasSistema = obterEstatisticasSistema();

/**
 * Funções auxiliares
 */

function salvarConfiguracaoGeral($dados) {
    $db = (new Database())->getConnection();
    
    $configuracoes = [
        'max_file_size' => intval($dados['max_file_size'] ?? 5),
        'timeout_api' => intval($dados['timeout_api'] ?? 30),
        'auto_backup' => isset($dados['auto_backup']) ? 1 : 0,
        'notificacoes_email' => isset($dados['notificacoes_email']) ? 1 : 0,
        'logs_detalhados' => isset($dados['logs_detalhados']) ? 1 : 0,
        'manutencao_automatica' => isset($dados['manutencao_automatica']) ? 1 : 0
    ];
    
    foreach ($configuracoes as $chave => $valor) {
        $stmt = $db->prepare("
            INSERT INTO configuracoes (chave, valor, updated_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_at = NOW()
        ");
        $stmt->execute([$chave, $valor]);
    }
}

function salvarConfiguracaoPolo($dados) {
    $polo = $dados['polo'];
    $token = $dados['token'];
    $ativo = isset($dados['ativo']) ? 1 : 0;
    
    // Aqui você atualizaria as configurações do polo
    // Por ora, vamos apenas validar
    if (empty($polo) || empty($token)) {
        throw new Exception('Polo e token são obrigatórios');
    }
    
    // TODO: Implementar salvamento real das configurações do polo
    // Isso envolveria modificar o arquivo de configuração ou banco
}

function testarConexaoPolo($polo) {
    try {
        require_once '../src/MoodleAPI.php';
        $moodleAPI = new MoodleAPI($polo);
        return $moodleAPI->testarConexao();
    } catch (Exception $e) {
        return ['sucesso' => false, 'erro' => $e->getMessage()];
    }
}

function limparCachesSistema() {
    // Limpa arquivos de cache temporários
    $cacheDir = __DIR__ . '/../cache/';
    
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    
    // Limpa cache do Moodle API se aplicável
    // TODO: Implementar limpeza específica
}

function limparLogsAntigos($dias = 30) {
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("
        DELETE FROM logs 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$dias]);
    
    return $stmt->rowCount();
}

function carregarConfiguracoes() {
    $db = (new Database())->getConnection();
    
    $stmt = $db->query("SELECT chave, valor FROM configuracoes");
    $configs = [];
    
    while ($row = $stmt->fetch()) {
        $configs[$row['chave']] = $row['valor'];
    }
    
    // Valores padrão
    $defaults = [
        'max_file_size' => 5,
        'timeout_api' => 30,
        'auto_backup' => 0,
        'notificacoes_email' => 1,
        'logs_detalhados' => 0,
        'manutencao_automatica' => 1
    ];
    
    return array_merge($defaults, $configs);
}

function obterEstatisticasSistema() {
    $db = (new Database())->getConnection();
    
    // Estatísticas do banco
    $stmt = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM boletos) as total_boletos,
            (SELECT COUNT(*) FROM alunos) as total_alunos,
            (SELECT COUNT(*) FROM cursos) as total_cursos,
            (SELECT COUNT(*) FROM logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as logs_24h,
            (SELECT COUNT(*) FROM logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as logs_7d
    ");
    $stats = $stmt->fetch();
    
    // Informações do sistema
    $stats['php_version'] = PHP_VERSION;
    $stats['memory_usage'] = memory_get_usage(true);
    $stats['memory_limit'] = ini_get('memory_limit');
    $stats['upload_max_filesize'] = ini_get('upload_max_filesize');
    $stats['post_max_size'] = ini_get('post_max_size');
    $stats['disk_free_space'] = disk_free_space(__DIR__);
    $stats['disk_total_space'] = disk_total_space(__DIR__);
    
    return $stats;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Administração IMEPEDU</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --sidebar-width: 260px;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color), var(--secondary-color));
            color: white;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar-brand {
            padding: 1.5rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-brand h4 {
            margin: 0;
            font-weight: 600;
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-item {
            margin: 0.25rem 0;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .config-section {
            border-left: 4px solid var(--primary-color);
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .config-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 12px 12px 0 0;
            margin: 0;
        }
        
        .config-body {
            padding: 1.5rem;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-online { background-color: #28a745; }
        .status-offline { background-color: #dc3545; }
        .status-warning { background-color: #ffc107; }
        
        .metric-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 1rem;
            transition: transform 0.2s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
        }
        
        .metric-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .metric-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .polo-status {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .polo-info {
            display: flex;
            align-items: center;
        }
        
        .progress-bar-custom {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--primary-color), #20c997);
            transition: width 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h4><i class="fas fa-graduation-cap"></i> IMEPEDU Admin</h4>
            <small>Sistema de Boletos</small>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-item">
                <a href="/admin/dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/boletos.php" class="nav-link">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Gerenciar Boletos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/upload-boletos.php" class="nav-link">
                    <i class="fas fa-upload"></i>
                    Upload de Boletos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/alunos.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    Alunos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/cursos.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Cursos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/relatorios.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    Relatórios
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/configuracoes.php" class="nav-link active">
                    <i class="fas fa-cog"></i>
                    Configurações
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/logs.php" class="nav-link">
                    <i class="fas fa-list-alt"></i>
                    Logs do Sistema
                </a>
            </div>
            
            <hr style="border-color: rgba(255,255,255,0.1); margin: 1rem 0;">
            
            <div class="nav-item">
                <a href="/admin/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Sair
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3>Configurações do Sistema</h3>
                <small class="text-muted">Gerencie as configurações gerais e dos polos Moodle</small>
            </div>
            <div>
                <button class="btn btn-primary" onclick="salvarTodasConfiguracoes()">
                    <i class="fas fa-save"></i> Salvar Todas
                </button>
                <button class="btn btn-outline-secondary" onclick="exportarConfiguracoes()">
                    <i class="fas fa-download"></i> Exportar
                </button>
            </div>
        </div>
        
        <!-- Mensagem de Feedback -->
        <?php if ($mensagem): ?>
        <div class="alert alert-<?= $tipoMensagem ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?= $tipoMensagem === 'success' ? 'check-circle' : ($tipoMensagem === 'danger' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
            <?= htmlspecialchars($mensagem) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Estatísticas do Sistema -->
        <div class="config-section">
            <h5 class="config-header">
                <i class="fas fa-chart-line"></i> Estatísticas do Sistema
            </h5>
            <div class="config-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number"><?= number_format($estatisticasSistema['total_boletos']) ?></div>
                            <div class="metric-label">Total Boletos</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number"><?= number_format($estatisticasSistema['total_alunos']) ?></div>
                            <div class="metric-label">Total Alunos</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number"><?= number_format($estatisticasSistema['total_cursos']) ?></div>
                            <div class="metric-label">Total Cursos</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number"><?= number_format($estatisticasSistema['logs_24h']) ?></div>
                            <div class="metric-label">Logs 24h</div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6 class="text-primary">Informações do Servidor</h6>
                        <ul class="list-unstyled">
                            <li><strong>PHP:</strong> <?= $estatisticasSistema['php_version'] ?></li>
                            <li><strong>Memória:</strong> <?= round($estatisticasSistema['memory_usage'] / 1024 / 1024, 2) ?>MB / <?= $estatisticasSistema['memory_limit'] ?></li>
                            <li><strong>Upload máximo:</strong> <?= $estatisticasSistema['upload_max_filesize'] ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary">Espaço em Disco</h6>
                        <?php 
                        $diskUsage = (($estatisticasSistema['disk_total_space'] - $estatisticasSistema['disk_free_space']) / $estatisticasSistema['disk_total_space']) * 100;
                        ?>
                        <div class="d-flex justify-content-between">
                            <small>Usado: <?= round($diskUsage, 1) ?>%</small>
                            <small>Livre: <?= round($estatisticasSistema['disk_free_space'] / 1024 / 1024 / 1024, 2) ?>GB</small>
                        </div>
                        <div class="progress-bar-custom">
                            <div class="progress-fill" style="width: <?= $diskUsage ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Configurações Gerais -->
        <div class="config-section">
            <h5 class="config-header">
                <i class="fas fa-sliders-h"></i> Configurações Gerais
            </h5>
            <div class="config-body">
                <form method="POST" id="configGeralForm">
                    <input type="hidden" name="acao" value="salvar_geral">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="max_file_size" class="form-label">Tamanho Máximo de Arquivo (MB)</label>
                                <input type="number" class="form-control" id="max_file_size" name="max_file_size" 
                                       value="<?= $configuracoes['max_file_size'] ?>" min="1" max="50">
                                <small class="form-text text-muted">Tamanho máximo para upload de PDFs</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="timeout_api" class="form-label">Timeout da API (segundos)</label>
                                <input type="number" class="form-control" id="timeout_api" name="timeout_api" 
                                       value="<?= $configuracoes['timeout_api'] ?>" min="10" max="120">
                                <small class="form-text text-muted">Tempo limite para conexões com o Moodle</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="auto_backup" name="auto_backup" 
                                       <?= $configuracoes['auto_backup'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="auto_backup">
                                    Backup Automático
                                </label>
                                <small class="form-text text-muted d-block">Realiza backup automático dos dados</small>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="notificacoes_email" name="notificacoes_email" 
                                       <?= $configuracoes['notificacoes_email'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="notificacoes_email">
                                    Notificações por Email
                                </label>
                                <small class="form-text text-muted d-block">Envia notificações importantes por email</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="logs_detalhados" name="logs_detalhados" 
                                       <?= $configuracoes['logs_detalhados'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="logs_detalhados">
                                    Logs Detalhados
                                </label>
                                <small class="form-text text-muted d-block">Registra informações detalhadas nos logs</small>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="manutencao_automatica" name="manutencao_automatica" 
                                       <?= $configuracoes['manutencao_automatica'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="manutencao_automatica">
                                    Manutenção Automática
                                </label>
                                <small class="form-text text-muted d-block">Executa tarefas de manutenção automaticamente</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Configurações Gerais
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Gerenciamento de Usuários Administrativos -->
        <div class="config-section">
            <h5 class="config-header">
                <i class="fas fa-users-cog"></i> Usuários Administrativos
            </h5>
            <div class="config-body">
                <!-- Adicionar Novo Administrador -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h6 class="text-primary mb-3">Adicionar Novo Administrador</h6>
                        <form id="novoAdminForm" class="row g-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="novo_nome" placeholder="Nome completo" required>
                            </div>
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="novo_login" placeholder="Login/Usuário" required>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="novo_nivel">
                                    <option value="admin">Administrador</option>
                                    <option value="operador">Operador</option>
                                    <option value="visualizador">Visualizador</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-success w-100" onclick="adicionarAdmin()">
                                    <i class="fas fa-plus"></i> Adicionar
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-primary mb-3">Alterar Minha Senha</h6>
                        <button class="btn btn-warning w-100" onclick="abrirModalSenha()">
                            <i class="fas fa-key"></i> Alterar Senha
                        </button>
                        <small class="form-text text-muted d-block mt-2">
                            Última alteração: <?= date('d/m/Y', strtotime($admin['created_at'] ?? 'now')) ?>
                        </small>
                    </div>
                </div>
                
                <!-- Lista de Administradores -->
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">Administradores Ativos</h6>
                        <div class="table-responsive">
                            <table class="table table-hover" id="tabelaAdmins">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Login</th>
                                        <th>Nível</th>
                                        <th>Último Acesso</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="listaAdmins">
                                    <!-- Será carregado via JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Informações de Níveis -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle"></i> Níveis de Acesso</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>Administrador:</strong> Acesso total ao sistema
                                </div>
                                <div class="col-md-4">
                                    <strong>Operador:</strong> Gerencia boletos e alunos
                                </div>
                                <div class="col-md-4">
                                    <strong>Visualizador:</strong> Apenas visualização e relatórios
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configurações dos Polos -->
        <div class="config-section">
            <h5 class="config-header">
                <i class="fas fa-map-marker-alt"></i> Configurações dos Polos Moodle
            </h5>
            <div class="config-body">
                <?php foreach ($polosDisponiveis as $polo): ?>
                    <?php 
                    $config = MoodleConfig::getConfig($polo); 
                    $validacao = MoodleConfig::validateConfig($polo);
                    $statusClass = $validacao['valid'] ? 'status-online' : 'status-offline';
                    ?>
                    
                    <div class="polo-status">
                        <div class="polo-info">
                            <span class="status-indicator <?= $statusClass ?>"></span>
                            <div>
                                <strong><?= htmlspecialchars($config['name'] ?? $polo) ?></strong>
                                <br><small class="text-muted"><?= $polo ?></small>
                                <?php if (!$validacao['valid']): ?>
                                    <br><small class="text-danger">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        <?= implode(', ', $validacao['errors']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button class="btn btn-outline-primary btn-sm" onclick="testarConexao('<?= $polo ?>')">
                                <i class="fas fa-wifi"></i> Testar
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="configurarPolo('<?= $polo ?>')">
                                <i class="fas fa-cog"></i> Configurar
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="mt-3">
                    <button class="btn btn-success" onclick="sincronizarTodosPolos()">
                        <i class="fas fa-sync-alt"></i> Sincronizar Todos os Polos
                    </button>
                    <button class="btn btn-info" onclick="verificarStatusPolos()">
                        <i class="fas fa-search"></i> Verificar Status
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Manutenção do Sistema -->
        <div class="config-section">
            <h5 class="config-header">
                <i class="fas fa-tools"></i> Manutenção do Sistema
            </h5>
            <div class="config-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">Limpeza de Dados</h6>
                        
                        <form method="POST" class="mb-3" onsubmit="return confirmarLimpezaLogs()">
                            <input type="hidden" name="acao" value="limpar_logs">
                            <div class="input-group">
                                <input type="number" class="form-control" name="dias" value="30" min="1" max="365">
                                <span class="input-group-text">dias</span>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-trash"></i> Limpar Logs
                                </button>
                            </div>
                            <small class="form-text text-muted">Remove logs mais antigos que X dias</small>
                        </form>
                        
                        <form method="POST" class="mb-3" onsubmit="return confirmarLimpezaCache()">
                            <input type="hidden" name="acao" value="limpar_cache">
                            <button type="submit" class="btn btn-info">
                                <i class="fas fa-broom"></i> Limpar Cache
                            </button>
                            <small class="form-text text-muted d-block">Remove arquivos temporários e cache</small>
                        </form>
                    </div>
                    
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">Backup e Exportação</h6>
                        
                        <button class="btn btn-success mb-2" onclick="gerarBackup()">
                            <i class="fas fa-download"></i> Gerar Backup Completo
                        </button>
                        <br>
                        
                        <button class="btn btn-outline-success" onclick="exportarDados()">
                            <i class="fas fa-file-export"></i> Exportar Dados
                        </button>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                Último backup: <?= date('d/m/Y H:i:s') ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="row">
                    <div class="col-md-12">
                        <h6 class="text-primary mb-3">Otimização do Sistema</h6>
                        
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-outline-primary" onclick="otimizarBanco()">
                                <i class="fas fa-database"></i> Otimizar Banco
                            </button>
                            
                            <button class="btn btn-outline-info" onclick="atualizarEstatisticas()">
                                <i class="fas fa-chart-bar"></i> Atualizar Estatísticas
                            </button>
                            
                            <button class="btn btn-outline-warning" onclick="verificarIntegridade()">
                                <i class="fas fa-shield-alt"></i> Verificar Integridade
                            </button>
                            
                            <button class="btn btn-outline-secondary" onclick="compactarArquivos()">
                                <i class="fas fa-compress"></i> Compactar Arquivos
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Logs do Sistema -->
        <div class="config-section">
            <h5 class="config-header">
                <i class="fas fa-list-alt"></i> Logs Recentes do Sistema
            </h5>
            <div class="config-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Tipo</th>
                                <th>Descrição</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody id="logsRecentes">
                            <!-- Logs serão carregados via JavaScript -->
                        </tbody>
                    </table>
                </div>
                
                <div class="text-center mt-3">
                    <a href="/admin/logs.php" class="btn btn-outline-primary">
                        <i class="fas fa-external-link-alt"></i> Ver Todos os Logs
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para Alterar Senha -->
    <div class="modal fade" id="alterarSenhaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Alterar Senha</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="alterarSenhaForm">
                        <div class="mb-3">
                            <label for="senha_atual" class="form-label">Senha Atual</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="senha_atual" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('senha_atual', 'toggleAtual')">
                                    <i class="fas fa-eye" id="toggleAtual"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="senha_nova" class="form-label">Nova Senha</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="senha_nova" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('senha_nova', 'toggleNova')">
                                    <i class="fas fa-eye" id="toggleNova"></i>
                                </button>
                            </div>
                            <small class="form-text text-muted">Mínimo 6 caracteres</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="senha_confirmar" class="form-label">Confirmar Nova Senha</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="senha_confirmar" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('senha_confirmar', 'toggleConfirmar')">
                                    <i class="fas fa-eye" id="toggleConfirmar"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div id="senhaStrength" class="progress" style="height: 6px;">
                                <div id="senhaStrengthBar" class="progress-bar" style="width: 0%"></div>
                            </div>
                            <small id="senhaStrengthText" class="form-text text-muted">Digite uma senha</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" onclick="salvarNovaSenha()">
                        <i class="fas fa-key"></i> Alterar Senha
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para Editar Administrador -->
    <div class="modal fade" id="editarAdminModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Administrador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editarAdminForm">
                        <input type="hidden" id="edit_admin_id">
                        
                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome Completo</label>
                            <input type="text" class="form-control" id="edit_nome" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_login" class="form-label">Login/Usuário</label>
                            <input type="text" class="form-control" id="edit_login" required>
                            <small class="form-text text-muted">O login não pode ser alterado</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nivel" class="form-label">Nível de Acesso</label>
                            <select class="form-select" id="edit_nivel">
                                <option value="admin">Administrador</option>
                                <option value="operador">Operador</option>
                                <option value="visualizador">Visualizador</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email (Opcional)</label>
                            <input type="email" class="form-control" id="edit_email">
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_ativo">
                            <label class="form-check-label" for="edit_ativo">
                                Usuário Ativo
                            </label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" onclick="resetarSenhaAdmin()" id="btnResetSenha">
                        <i class="fas fa-key"></i> Resetar Senha
                    </button>
                    <button type="button" class="btn btn-primary" onclick="salvarEdicaoAdmin()">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Configuração de Polo -->
    <div class="modal fade" id="configurarPoloModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Configurar Polo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="configPoloForm" method="POST">
                        <input type="hidden" name="acao" value="salvar_polo">
                        <input type="hidden" id="polo_config" name="polo">
                        
                        <div class="mb-3">
                            <label for="polo_nome" class="form-label">Nome do Polo</label>
                            <input type="text" class="form-control" id="polo_nome" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="polo_token" class="form-label">Token de Acesso</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="polo_token" name="token" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleTokenVisibility()">
                                    <i class="fas fa-eye" id="tokenToggleIcon"></i>
                                </button>
                            </div>
                            <small class="form-text text-muted">Token de acesso aos Web Services do Moodle</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="polo_email" class="form-label">Email de Contato</label>
                            <input type="email" class="form-control" id="polo_email" name="email">
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="polo_ativo" name="ativo">
                            <label class="form-check-label" for="polo_ativo">
                                Polo Ativo
                            </label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="salvarConfigPolo()">
                        <i class="fas fa-save"></i> Salvar Configuração
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Progresso -->
    <div class="modal fade" id="progressoModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Processando...</h5>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                    <div class="progress mb-3">
                        <div class="progress-bar" id="progressBar" style="width: 0%"></div>
                    </div>
                    <div id="progressoTexto" class="text-center">
                        Iniciando operação...
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Variáveis globais
        let operacaoAtiva = false;
        
        // ========== FUNÇÕES DE GERENCIAMENTO DE USUÁRIOS ==========
        
        // Adiciona novo administrador
        function adicionarAdmin() {
            const nome = document.getElementById('novo_nome').value.trim();
            const login = document.getElementById('novo_login').value.trim();
            const nivel = document.getElementById('novo_nivel').value;
            
            if (!nome || !login) {
                showToast('Nome e login são obrigatórios', 'error');
                return;
            }
            
            if (login.length < 3) {
                showToast('Login deve ter pelo menos 3 caracteres', 'error');
                return;
            }
            
            showToast('Adicionando novo administrador...', 'info');
            
            fetch('/admin/api/usuarios-admin.php?acao=adicionar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    nome: nome,
                    login: login,
                    nivel: nivel
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Administrador ${nome} adicionado! Senha temporária: ${data.senha_temporaria}`, 'success');
                    
                    // Limpa formulário
                    document.getElementById('novoAdminForm').reset();
                    
                    // Atualiza lista
                    carregarListaAdmins();
                    
                    // Mostra informações da senha temporária
                    setTimeout(() => {
                        alert(`IMPORTANTE: Anote a senha temporária para ${nome}:\n\nLogin: ${login}\nSenha: ${data.senha_temporaria}\n\nO usuário deve alterar a senha no primeiro acesso.`);
                    }, 1000);
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Erro de conexão ao adicionar administrador', 'error');
            });
        }
        
        // Abre modal para alterar senha
        function abrirModalSenha() {
            document.getElementById('alterarSenhaForm').reset();
            const modal = new bootstrap.Modal(document.getElementById('alterarSenhaModal'));
            modal.show();
            
            // Adiciona listener para verificar força da senha
            document.getElementById('senha_nova').addEventListener('input', verificarForcaSenha);
            document.getElementById('senha_confirmar').addEventListener('input', verificarConfirmacaoSenha);
        }
        
        // Verifica força da senha
        function verificarForcaSenha() {
            const senha = document.getElementById('senha_nova').value;
            const strengthBar = document.getElementById('senhaStrengthBar');
            const strengthText = document.getElementById('senhaStrengthText');
            
            let score = 0;
            let feedback = '';
            
            // Critérios de força
            if (senha.length >= 6) score += 20;
            if (senha.length >= 10) score += 20;
            if (/[a-z]/.test(senha)) score += 15;
            if (/[A-Z]/.test(senha)) score += 15;
            if (/[0-9]/.test(senha)) score += 15;
            if (/[^A-Za-z0-9]/.test(senha)) score += 15;
            
            // Define cor e texto baseado no score
            if (score < 30) {
                strengthBar.className = 'progress-bar bg-danger';
                feedback = 'Muito fraca';
            } else if (score < 60) {
                strengthBar.className = 'progress-bar bg-warning';
                feedback = 'Fraca';
            } else if (score < 80) {
                strengthBar.className = 'progress-bar bg-info';
                feedback = 'Boa';
            } else {
                strengthBar.className = 'progress-bar bg-success';
                feedback = 'Forte';
            }
            
            strengthBar.style.width = score + '%';
            strengthText.textContent = feedback;
        }
        
        // Verifica confirmação da senha
        function verificarConfirmacaoSenha() {
            const senha = document.getElementById('senha_nova').value;
            const confirmar = document.getElementById('senha_confirmar').value;
            const confirmInput = document.getElementById('senha_confirmar');
            
            if (confirmar && senha !== confirmar) {
                confirmInput.classList.add('is-invalid');
            } else {
                confirmInput.classList.remove('is-invalid');
            }
        }
        
        // Salva nova senha
        function salvarNovaSenha() {
            const senhaAtual = document.getElementById('senha_atual').value;
            const senhaNova = document.getElementById('senha_nova').value;
            const senhaConfirmar = document.getElementById('senha_confirmar').value;
            
            if (!senhaAtual || !senhaNova || !senhaConfirmar) {
                showToast('Todos os campos são obrigatórios', 'error');
                return;
            }
            
            if (senhaNova.length < 6) {
                showToast('Nova senha deve ter pelo menos 6 caracteres', 'error');
                return;
            }
            
            if (senhaNova !== senhaConfirmar) {
                showToast('Confirmação de senha não confere', 'error');
                return;
            }
            
            showToast('Alterando senha...', 'info');
            
            fetch('/admin/api/usuarios-admin.php?acao=alterar_senha', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    senha_atual: senhaAtual,
                    senha_nova: senhaNova
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Senha alterada com sucesso!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('alterarSenhaModal')).hide();
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Erro de conexão ao alterar senha', 'error');
            });
        }
        
        // Carrega lista de administradores
        function carregarListaAdmins() {
            //fetch('/admin/api/listar-admins.php')
            fetch('/admin/api/usuarios-admin.php?acao=listar')
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById('listaAdmins');
                
                if (data.success && data.admins.length > 0) {
                    tbody.innerHTML = data.admins.map(admin => `
                        <tr data-admin-id="${admin.id}">
                            <td>
                                <strong>${admin.nome}</strong>
                                ${admin.id == <?= $_SESSION['admin_id'] ?> ? '<span class="badge bg-primary ms-2">Você</span>' : ''}
                            </td>
                            <td>${admin.login}</td>
                            <td>
                                <span class="badge bg-${getNivelBadgeClass(admin.nivel)}">${getNivelLabel(admin.nivel)}</span>
                            </td>
                            <td>
                                ${admin.ultimo_acesso ? 
                                    new Date(admin.ultimo_acesso).toLocaleString('pt-BR') : 
                                    '<span class="text-muted">Nunca</span>'
                                }
                            </td>
                            <td>
                                <span class="badge bg-${admin.ativo == 1 ? 'success' : 'secondary'}">
                                    ${admin.ativo == 1 ? 'Ativo' : 'Inativo'}
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="editarAdmin(${admin.id})" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    ${admin.id != <?= $_SESSION['admin_id'] ?> ? `
                                        <button class="btn btn-outline-warning" onclick="resetarSenhaAdmin(${admin.id})" title="Resetar senha">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <button class="btn btn-outline-${admin.ativo == 1 ? 'secondary' : 'success'}" 
                                                onclick="toggleStatusAdmin(${admin.id}, ${admin.ativo})" 
                                                title="${admin.ativo == 1 ? 'Desativar' : 'Ativar'}">
                                            <i class="fas fa-${admin.ativo == 1 ? 'pause' : 'play'}"></i>
                                        </button>
                                    ` : ''}
                                </div>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Nenhum administrador encontrado</td></tr>';
                }
            })
            .catch(error => {
                document.getElementById('listaAdmins').innerHTML = 
                    '<tr><td colspan="6" class="text-center text-danger">Erro ao carregar administradores</td></tr>';
            });
        }
        
        // Edita administrador
        function editarAdmin(adminId) {
            //fetch(`/admin/api/obter-admin.php?id=${adminId}`)
            fetch(`/admin/api/usuarios-admin.php?acao=obter&id=${adminId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const admin = data.admin;
                    
                    document.getElementById('edit_admin_id').value = admin.id;
                    document.getElementById('edit_nome').value = admin.nome;
                    document.getElementById('edit_login').value = admin.login;
                    document.getElementById('edit_nivel').value = admin.nivel;
                    document.getElementById('edit_email').value = admin.email || '';
                    document.getElementById('edit_ativo').checked = admin.ativo == 1;
                    
                    // Desabilita login se for o próprio usuário
                    document.getElementById('edit_login').readOnly = (admin.id == <?= $_SESSION['admin_id'] ?>);
                    
                    const modal = new bootstrap.Modal(document.getElementById('editarAdminModal'));
                    modal.show();
                } else {
                    showToast('Erro ao carregar dados do administrador', 'error');
                }
            })
            .catch(error => {
                showToast('Erro de conexão', 'error');
            });
        }
        
        // Salva edição do administrador
        function salvarEdicaoAdmin() {
            const adminId = document.getElementById('edit_admin_id').value;
            const nome = document.getElementById('edit_nome').value.trim();
            const nivel = document.getElementById('edit_nivel').value;
            const email = document.getElementById('edit_email').value.trim();
            const ativo = document.getElementById('edit_ativo').checked ? 1 : 0;
            
            if (!nome) {
                showToast('Nome é obrigatório', 'error');
                return;
            }
            
            showToast('Salvando alterações...', 'info');
            
            //fetch('/admin/api/editar-admin.php', {
            fetch('/admin/api/usuarios-admin.php?acao=editar', {

                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    admin_id: adminId,
                    nome: nome,
                    nivel: nivel,
                    email: email,
                    ativo: ativo
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Administrador atualizado com sucesso!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editarAdminModal')).hide();
                    carregarListaAdmins();
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Erro de conexão', 'error');
            });
        }
        
        // Reseta senha do administrador
        function resetarSenhaAdmin(adminId = null) {
            const id = adminId || document.getElementById('edit_admin_id').value;
            
            if (confirm('Tem certeza que deseja resetar a senha deste administrador? Uma nova senha temporária será gerada.')) {
                showToast('Resetando senha...', 'info');
                
                //fetch('/admin/api/resetar-senha-admin.php', {
                fetch('/admin/api/usuarios-admin.php?acao=resetar_senha', {

                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        admin_id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Senha resetada com sucesso!', 'success');
                        
                        setTimeout(() => {
                            alert(`Nova senha temporária gerada:\n\nLogin: ${data.login}\nSenha: ${data.senha_temporaria}\n\nO usuário deve alterar a senha no próximo acesso.`);
                        }, 1000);
                        
                        if (document.getElementById('editarAdminModal').classList.contains('show')) {
                            bootstrap.Modal.getInstance(document.getElementById('editarAdminModal')).hide();
                        }
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        // Toggle status do administrador
        function toggleStatusAdmin(adminId, statusAtual) {
            const novoStatus = statusAtual == 1 ? 0 : 1;
            const acao = novoStatus == 1 ? 'ativar' : 'desativar';
            
            if (confirm(`Tem certeza que deseja ${acao} este administrador?`)) {
                showToast(`${acao.charAt(0).toUpperCase() + acao.slice(1)}ando administrador...`, 'info');
                
                //fetch('/admin/api/toggle-status-admin.php', {
                fetch('/admin/api/usuarios-admin.php?acao=toggle_status', {

                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        admin_id: adminId,
                        ativo: novoStatus
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(`Administrador ${acao}do com sucesso!`, 'success');
                        carregarListaAdmins();
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        // Obtém classe do badge por nível
        function getNivelBadgeClass(nivel) {
            const classes = {
                'admin': 'danger',
                'operador': 'warning',
                'visualizador': 'info'
            };
            return classes[nivel] || 'secondary';
        }
        
        // Obtém label do nível
        function getNivelLabel(nivel) {
            const labels = {
                'admin': 'Administrador',
                'operador': 'Operador',
                'visualizador': 'Visualizador'
            };
            return labels[nivel] || nivel;
        }
        
        // Toggle visibilidade da senha
        function togglePasswordVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // ========== FUNÇÕES DE CONFIGURAÇÃO ==========
        
        // Salva todas as configurações
        function salvarTodasConfiguracoes() {
            if (operacaoAtiva) {
                showToast('Operação já em andamento', 'warning');
                return;
            }
            
            if (confirm('Deseja salvar todas as configurações pendentes?')) {
                operacaoAtiva = true;
                showToast('Salvando configurações...', 'info');
                
                // Simula salvamento (implementar lógica real)
                setTimeout(() => {
                    showToast('Todas as configurações foram salvas!', 'success');
                    operacaoAtiva = false;
                }, 2000);
            }
        }
        
        // Exporta configurações
        function exportarConfiguracoes() {
            showToast('Preparando exportação...', 'info');
            
            // Simula exportação
            setTimeout(() => {
                const blob = new Blob([JSON.stringify({
                    timestamp: new Date().toISOString(),
                    configuracoes: 'dados_das_configuracoes'
                }, null, 2)], { type: 'application/json' });
                
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `configuracoes_${new Date().toISOString().split('T')[0]}.json`;
                link.click();
                
                showToast('Configurações exportadas!', 'success');
            }, 1000);
        }
        
        // ========== FUNÇÕES DOS POLOS ==========
        
        // Testa conexão com polo
        function testarConexao(polo) {
            if (operacaoAtiva) {
                showToast('Teste já em andamento', 'warning');
                return;
            }
            
            operacaoAtiva = true;
            showToast(`Testando conexão com ${polo}...`, 'info');
            
            fetch('/admin/api/testar-conexao-polo.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ polo: polo })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Conexão com ${polo} testada com sucesso!`, 'success');
                    atualizarStatusPolo(polo, 'online');
                } else {
                    showToast(`Erro na conexão com ${polo}: ${data.message}`, 'error');
                    atualizarStatusPolo(polo, 'offline');
                }
            })
            .catch(error => {
                showToast(`Erro ao testar conexão: ${error.message}`, 'error');
                atualizarStatusPolo(polo, 'offline');
            })
            .finally(() => {
                operacaoAtiva = false;
            });
        }
        
        // Configura polo
        function configurarPolo(polo) {
            document.getElementById('polo_config').value = polo;
            document.getElementById('polo_nome').value = polo;
            
            // Carrega configurações atuais do polo
            // TODO: Implementar carregamento via API
            
            const modal = new bootstrap.Modal(document.getElementById('configurarPoloModal'));
            modal.show();
        }
        
        // Salva configuração do polo
        function salvarConfigPolo() {
            const form = document.getElementById('configPoloForm');
            const formData = new FormData(form);
            
            showToast('Salvando configuração do polo...', 'info');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                showToast('Configuração do polo salva!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('configurarPoloModal')).hide();
                setTimeout(() => location.reload(), 1500);
            })
            .catch(error => {
                showToast('Erro ao salvar configuração', 'error');
            });
        }
        
        // Sincroniza todos os polos
        function sincronizarTodosPolos() {
            if (operacaoAtiva) {
                showToast('Sincronização já em andamento', 'warning');
                return;
            }
            
            if (confirm('Deseja sincronizar todos os polos? Esta operação pode demorar alguns minutos.')) {
                operacaoAtiva = true;
                mostrarProgresso('Sincronizando Polos');
                
                let progresso = 0;
                const interval = setInterval(() => {
                    progresso += Math.random() * 15;
                    if (progresso > 95) progresso = 95;
                    
                    atualizarProgresso(progresso, `Sincronizando polos... ${Math.round(progresso)}%`);
                }, 500);
                
                fetch('/admin/api/sincronizar-todos-polos.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    clearInterval(interval);
                    atualizarProgresso(100, 'Sincronização concluída!');
                    
                    setTimeout(() => {
                        esconderProgresso();
                        if (data.success) {
                            showToast(`Sincronização concluída! ${data.polos_sincronizados} polos atualizados.`, 'success');
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            showToast('Erro na sincronização: ' + data.message, 'error');
                        }
                    }, 1000);
                })
                .catch(error => {
                    clearInterval(interval);
                    esconderProgresso();
                    showToast('Erro de conexão na sincronização', 'error');
                })
                .finally(() => {
                    operacaoAtiva = false;
                });
            }
        }
        
        // Verifica status de todos os polos
        function verificarStatusPolos() {
            if (operacaoAtiva) return;
            
            operacaoAtiva = true;
            showToast('Verificando status dos polos...', 'info');
            
            fetch('/admin/api/verificar-status-polos.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    data.status.forEach(polo => {
                        atualizarStatusPolo(polo.subdomain, polo.online ? 'online' : 'offline');
                    });
                    showToast('Status dos polos atualizado!', 'success');
                } else {
                    showToast('Erro ao verificar status: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Erro de conexão', 'error');
            })
            .finally(() => {
                operacaoAtiva = false;
            });
        }
        
        // ========== FUNÇÕES DE MANUTENÇÃO ==========
        
        // Otimiza banco de dados
        function otimizarBanco() {
            if (confirm('Deseja otimizar o banco de dados? O sistema pode ficar lento durante a operação.')) {
                operacaoAtiva = true;
                mostrarProgresso('Otimizando Banco de Dados');
                
                fetch('/admin/api/otimizar-banco.php', {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    esconderProgresso();
                    if (data.success) {
                        showToast('Banco de dados otimizado com sucesso!', 'success');
                    } else {
                        showToast('Erro na otimização: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    esconderProgresso();
                    showToast('Erro ao otimizar banco', 'error');
                })
                .finally(() => {
                    operacaoAtiva = false;
                });
            }
        }
        
        // Atualiza estatísticas
        function atualizarEstatisticas() {
            showToast('Atualizando estatísticas...', 'info');
            
            fetch('/admin/api/atualizar-estatisticas.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Estatísticas atualizadas!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Erro ao atualizar estatísticas', 'error');
                }
            })
            .catch(error => {
                showToast('Erro de conexão', 'error');
            });
        }
        
        // Verifica integridade do sistema
        function verificarIntegridade() {
            if (operacaoAtiva) return;
            
            operacaoAtiva = true;
            mostrarProgresso('Verificando Integridade');
            
            fetch('/admin/api/verificar-integridade.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                esconderProgresso();
                if (data.success) {
                    const problemas = data.problemas || 0;
                    if (problemas === 0) {
                        showToast('Sistema íntegro! Nenhum problema encontrado.', 'success');
                    } else {
                        showToast(`Verificação concluída. ${problemas} problema(s) encontrado(s).`, 'warning');
                    }
                } else {
                    showToast('Erro na verificação: ' + data.message, 'error');
                }
            })
            .catch(error => {
                esconderProgresso();
                showToast('Erro ao verificar integridade', 'error');
            })
            .finally(() => {
                operacaoAtiva = false;
            });
        }
        
        // Compacta arquivos antigos
        function compactarArquivos() {
            if (confirm('Deseja compactar arquivos antigos para economizar espaço?')) {
                operacaoAtiva = true;
                mostrarProgresso('Compactando Arquivos');
                
                fetch('/admin/api/compactar-arquivos.php', {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    esconderProgresso();
                    if (data.success) {
                        showToast(`Arquivos compactados! ${data.economia}MB economizados.`, 'success');
                    } else {
                        showToast('Erro na compactação: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    esconderProgresso();
                    showToast('Erro ao compactar arquivos', 'error');
                })
                .finally(() => {
                    operacaoAtiva = false;
                });
            }
        }
        
        // Gera backup completo
        function gerarBackup() {
            if (operacaoAtiva) return;
            
            operacaoAtiva = true;
            mostrarProgresso('Gerando Backup');
            
            fetch('/admin/api/gerar-backup.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                esconderProgresso();
                if (data.success) {
                    showToast('Backup gerado com sucesso!', 'success');
                    
                    // Inicia download do backup
                    const link = document.createElement('a');
                    link.href = data.download_url;
                    link.download = data.filename;
                    link.click();
                } else {
                    showToast('Erro ao gerar backup: ' + data.message, 'error');
                }
            })
            .catch(error => {
                esconderProgresso();
                showToast('Erro ao gerar backup', 'error');
            })
            .finally(() => {
                operacaoAtiva = false;
            });
        }
        
        // Exporta dados
        function exportarDados() {
            showToast('Preparando exportação de dados...', 'info');
            
            fetch('/admin/api/exportar-dados.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    formato: 'csv',
                    incluir_logs: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Dados exportados!', 'success');
                    
                    const link = document.createElement('a');
                    link.href = data.download_url;
                    link.download = data.filename;
                    link.click();
                } else {
                    showToast('Erro na exportação: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Erro ao exportar dados', 'error');
            });
        }
        
        // ========== FUNÇÕES DE CONFIRMAÇÃO ==========
        
        function confirmarLimpezaLogs() {
            const dias = document.querySelector('input[name="dias"]').value;
            return confirm(`Tem certeza que deseja remover logs mais antigos que ${dias} dias? Esta ação não pode ser desfeita.`);
        }
        
        function confirmarLimpezaCache() {
            return confirm('Tem certeza que deseja limpar o cache? Isso pode tornar o sistema temporariamente mais lento.');
        }
        
        // ========== FUNÇÕES AUXILIARES ==========
        
        // Atualiza status visual do polo
        function atualizarStatusPolo(polo, status) {
            const statusIndicators = document.querySelectorAll('.status-indicator');
            statusIndicators.forEach(indicator => {
                const poloRow = indicator.closest('.polo-status');
                const poloText = poloRow.querySelector('small.text-muted').textContent;
                
                if (poloText === polo) {
                    indicator.className = `status-indicator status-${status}`;
                }
            });
        }
        
        // Toggle visibilidade do token
        function toggleTokenVisibility() {
            const tokenInput = document.getElementById('polo_token');
            const toggleIcon = document.getElementById('tokenToggleIcon');
            
            if (tokenInput.type === 'password') {
                tokenInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                tokenInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
        
        // Mostra modal de progresso
        function mostrarProgresso(titulo) {
            document.querySelector('#progressoModal .modal-title').textContent = titulo;
            const modal = new bootstrap.Modal(document.getElementById('progressoModal'));
            modal.show();
        }
        
        // Atualiza progresso
        function atualizarProgresso(percentual, texto) {
            document.getElementById('progressBar').style.width = percentual + '%';
            document.getElementById('progressoTexto').textContent = texto;
        }
        
        // Esconde modal de progresso
        function esconderProgresso() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('progressoModal'));
            if (modal) {
                modal.hide();
            }
        }
        
        // Carrega logs recentes
        function carregarLogsRecentes() {
            fetch('/admin/api/logs-recentes.php?limite=10')
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById('logsRecentes');
                
                if (data.success && data.logs.length > 0) {
                    tbody.innerHTML = data.logs.map(log => `
                        <tr>
                            <td>${new Date(log.created_at).toLocaleString('pt-BR')}</td>
                            <td><span class="badge bg-${getTipoBadgeClass(log.tipo)}">${log.tipo}</span></td>
                            <td>${log.descricao}</td>
                            <td>${log.ip_address || '-'}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Nenhum log recente encontrado</td></tr>';
                }
            })
            .catch(error => {
                document.getElementById('logsRecentes').innerHTML = 
                    '<tr><td colspan="4" class="text-center text-danger">Erro ao carregar logs</td></tr>';
            });
        }
        
        // Obtém classe do badge por tipo de log
        function getTipoBadgeClass(tipo) {
            const classes = {
                'upload_individual': 'primary',
                'upload_lote': 'info',
                'boleto_pago': 'success',
                'boleto_cancelado': 'warning',
                'login_admin': 'secondary',
                'erro': 'danger'
            };
            
            return classes[tipo] || 'secondary';
        }
        
        // Sistema de notificações
        function showToast(message, type = 'info') {
            const existingToasts = document.querySelectorAll('.toast-custom');
            existingToasts.forEach(toast => toast.remove());
            
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.className = 'toast-container';
                container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 2001;
                    min-width: 300px;
                    max-width: 90vw;
                `;
                document.body.appendChild(container);
            }
            
            const toast = document.createElement('div');
            toast.className = `toast-custom alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info'} position-relative`;
            toast.style.cssText = 'animation: slideInRight 0.3s ease; margin-bottom: 8px;';
            
            const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                        type === 'success' ? 'fa-check-circle' : 
                        type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${icon} me-2"></i>
                    <span class="flex-grow-1">${message}</span>
                    <button type="button" class="btn-close ms-2" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            container.removeChild(toast);
                        }
                    }, 300);
                }
            }, 5000);
        }
        
        // ========== INICIALIZAÇÃO ==========
        
        document.addEventListener('DOMContentLoaded', function() {
            // Carrega logs recentes
            carregarLogsRecentes();
            
            // Carrega lista de administradores
            carregarListaAdmins();
            
            // Atualiza logs a cada 30 segundos
            setInterval(carregarLogsRecentes, 30000);
            
            // Verifica status dos polos a cada 5 minutos
            setInterval(verificarStatusPolos, 300000);
            
            // Tooltip para elementos
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                new bootstrap.Tooltip(element);
            });
        });
        
        // Adiciona estilos para animações
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            
            .config-section {
                transition: all 0.3s ease;
            }
            
            .config-section:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
            }
            
            .metric-card {
                transition: all 0.2s ease;
                cursor: pointer;
            }
            
            .metric-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
            
            .polo-status {
                transition: all 0.2s ease;
            }
            
            .polo-status:hover {
                background-color: rgba(0,102,204,0.05);
                border-color: var(--primary-color);
            }
            
            .status-indicator {
                animation: pulse 2s infinite;
            }
            
            @keyframes pulse {
                0% { opacity: 1; }
                50% { opacity: 0.5; }
                100% { opacity: 1; }
            }
        `;
        document.head.appendChild(style);
        
        // Log de debug
        console.log('✅ Página de Configurações carregada!');
        console.log('Configurações:', {
            admin_id: <?= $_SESSION['admin_id'] ?>,
            total_polos: <?= count($polosDisponiveis) ?>,
            sistema_info: {
                php_version: '<?= $estatisticasSistema['php_version'] ?>',
                memory_usage: '<?= round($estatisticasSistema['memory_usage'] / 1024 / 1024, 2) ?>MB'
            }
        });
    </script>
</body>
</html>