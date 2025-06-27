<?php
/**
 * Sistema de Boletos IMED - Dashboard do Aluno (Versão Corrigida)
 * Arquivo: dashboard_corrigido.php
 * 
 * Página principal onde o aluno visualiza seus boletos organizados por curso
 */

session_start();

// Habilita logs de debug
ini_set('log_errors', 1);
error_log("Dashboard: Iniciando para sessão: " . session_id());

// Verifica se usuário está logado
if (!isset($_SESSION['aluno_cpf'])) {
    error_log("Dashboard: Usuário não logado, redirecionando para login");
    header('Location: /login.php');
    exit;
}

// Inclui arquivos necessários
require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/AlunoService.php';
require_once 'src/BoletoService.php';

// Log dos dados da sessão
error_log("Dashboard: CPF da sessão: " . $_SESSION['aluno_cpf']);
error_log("Dashboard: Subdomain da sessão: " . ($_SESSION['subdomain'] ?? 'não definido'));

// Inicializa serviços
$alunoService = new AlunoService();
$boletoService = new BoletoService();

// Busca dados do aluno
$aluno = $alunoService->buscarAlunoPorCPF($_SESSION['aluno_cpf']);
if (!$aluno) {
    error_log("Dashboard: Aluno não encontrado no banco local para CPF: " . $_SESSION['aluno_cpf']);
    session_destroy();
    header('Location: /login.php');
    exit;
}

error_log("Dashboard: Aluno encontrado - ID: {$aluno['id']}, Nome: {$aluno['nome']}");

// Busca cursos do aluno
$cursos = $alunoService->buscarCursosAluno($aluno['id']);
error_log("Dashboard: Cursos encontrados: " . count($cursos));

// Debug detalhado dos cursos
if (empty($cursos)) {
    error_log("Dashboard: PROBLEMA - Nenhum curso encontrado!");
    
    // Verifica se há matrículas
    try {
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM matriculas WHERE aluno_id = ?");
        $stmt->execute([$aluno['id']]);
        $totalMatriculas = $stmt->fetch()['total'];
        error_log("Dashboard: Total de matrículas no banco: {$totalMatriculas}");
        
        // Busca todas as matrículas (incluindo inativas)
        $stmt = $db->prepare("
            SELECT m.*, c.nome as curso_nome, c.ativo as curso_ativo 
            FROM matriculas m 
            LEFT JOIN cursos c ON m.curso_id = c.id 
            WHERE m.aluno_id = ?
        ");
        $stmt->execute([$aluno['id']]);
        $todasMatriculas = $stmt->fetchAll();
        
        error_log("Dashboard: Detalhes das matrículas:");
        foreach ($todasMatriculas as $matricula) {
            error_log("Dashboard: - Matrícula ID: {$matricula['id']}, Status: {$matricula['status']}, Curso: " . ($matricula['curso_nome'] ?? 'CURSO NÃO ENCONTRADO') . ", Curso Ativo: " . ($matricula['curso_ativo'] ?? 'N/A'));
        }
        
    } catch (Exception $e) {
        error_log("Dashboard: Erro ao verificar matrículas: " . $e->getMessage());
    }
}

// Para cada curso, busca os boletos
$dadosDashboard = [];
$resumoGeral = [
    'total_boletos' => 0,
    'boletos_pagos' => 0,
    'boletos_pendentes' => 0,
    'boletos_vencidos' => 0,
    'valor_total' => 0,
    'valor_pago' => 0,
    'valor_pendente' => 0
];

foreach ($cursos as $curso) {
    error_log("Dashboard: Processando curso ID: {$curso['id']}, Nome: {$curso['nome']}");
    
    $boletos = $boletoService->buscarBoletosCurso($aluno['id'], $curso['id']);
    error_log("Dashboard: Boletos encontrados para curso {$curso['id']}: " . count($boletos));
    
    $cursoDados = [
        'curso' => $curso,
        'boletos' => [],
        'resumo' => [
            'total' => 0,
            'pagos' => 0,
            'pendentes' => 0,
            'vencidos' => 0,
            'valor_total' => 0,
            'valor_pendente' => 0
        ]
    ];
    
    foreach ($boletos as $boleto) {
        // Verifica se está vencido
        $hoje = new DateTime();
        $vencimento = new DateTime($boleto['vencimento']);
        $diasVencimento = $hoje->diff($vencimento)->format('%r%a');
        
        $boleto['dias_vencimento'] = (int)$diasVencimento;
        $boleto['esta_vencido'] = ($boleto['status'] == 'pendente' && $diasVencimento < 0);
        
        $cursoDados['boletos'][] = $boleto;
        
        // Atualiza contadores
        $cursoDados['resumo']['total']++;
        $cursoDados['resumo']['valor_total'] += $boleto['valor'];
        
        switch ($boleto['status']) {
            case 'pago':
                $cursoDados['resumo']['pagos']++;
                break;
            case 'pendente':
                $cursoDados['resumo']['pendentes']++;
                $cursoDados['resumo']['valor_pendente'] += $boleto['valor'];
                break;
            case 'vencido':
                $cursoDados['resumo']['vencidos']++;
                $cursoDados['resumo']['valor_pendente'] += $boleto['valor'];
                break;
        }
    }
    
    // Ordena boletos por vencimento (mais recentes primeiro)
    usort($cursoDados['boletos'], function($a, $b) {
        return strtotime($b['vencimento']) - strtotime($a['vencimento']);
    });
    
    $dadosDashboard[] = $cursoDados;
    
    // Atualiza resumo geral
    $resumoGeral['total_boletos'] += $cursoDados['resumo']['total'];
    $resumoGeral['boletos_pagos'] += $cursoDados['resumo']['pagos'];
    $resumoGeral['boletos_pendentes'] += $cursoDados['resumo']['pendentes'];
    $resumoGeral['boletos_vencidos'] += $cursoDados['resumo']['vencidos'];
    $resumoGeral['valor_total'] += $cursoDados['resumo']['valor_total'];
    $resumoGeral['valor_pendente'] += $cursoDados['resumo']['valor_pendente'];
}

$resumoGeral['valor_pago'] = $resumoGeral['valor_total'] - $resumoGeral['valor_pendente'];

// Busca próximos vencimentos
try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ? 
        AND b.status IN ('pendente', 'vencido')
        AND b.vencimento BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE() + INTERVAL 30 DAY
        ORDER BY b.vencimento ASC
        LIMIT 5
    ");
    $stmt->execute([$aluno['id']]);
    $proximosVencimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Dashboard: Erro ao buscar próximos vencimentos: " . $e->getMessage());
    $proximosVencimentos = [];
}

// Configura polo
$configPolo = MoodleConfig::getConfig($_SESSION['subdomain']) ?: [];

// Log final do dashboard
error_log("Dashboard: Resumo final - Cursos: " . count($dadosDashboard) . ", Boletos totais: " . $resumoGeral['total_boletos']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Boletos - <?= htmlspecialchars($aluno['nome']) ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Mesmo CSS do dashboard original -->
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 12px;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
        }
        
        .user-info {
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 8px 15px;
            margin-right: 10px;
        }
        
        .main-content {
            padding: 30px 0;
        }
        
        .dashboard-header {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-left: 4px solid;
            transition: transform 0.2s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.total { border-left-color: var(--info-color); }
        .stat-card.pagos { border-left-color: var(--success-color); }
        .stat-card.pendentes { border-left-color: var(--warning-color); }
        .stat-card.vencidos { border-left-color: var(--danger-color); }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .curso-card {
            background: white;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.2s ease;
        }
        
        .curso-card:hover {
            transform: translateY(-2px);
        }
        
        .curso-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 25px;
        }
        
        .curso-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        
        .curso-stats {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 8px;
        }
        
        .curso-body {
            padding: 25px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }
        
        .btn-debug {
            background: #6f42c1;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            margin: 5px;
        }
        
        .btn-debug:hover {
            background: #5a2d91;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/dashboard.php">
                <i class="fas fa-graduation-cap"></i> IMED Educação
            </a>
            
            <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
                <div class="user-info text-white">
                    <small>
                        <i class="fas fa-user"></i> 
                        <?= htmlspecialchars($aluno['nome']) ?>
                    </small>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" 
                            id="userDropdown" data-bs-toggle="dropdown">
                        <i class="fas fa-cog"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text">
                                <small class="text-muted">CPF: <?= $aluno['cpf'] ?></small><br>
                                <small class="text-muted">
                                    Polo: <?= str_replace('.imepedu.com.br', '', $_SESSION['subdomain']) ?>
                                </small>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="javascript:void(0)" onclick="atualizarDados()">
                                <i class="fas fa-sync"></i> Atualizar Dados
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="javascript:void(0)" onclick="mostrarDebug()">
                                <i class="fas fa-bug"></i> Debug
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/logout.php">
                                <i class="fas fa-sign-out-alt"></i> Sair
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container main-content">
        <!-- Debug Info (oculto por padrão) -->
        <div id="debugInfo" class="debug-info" style="display: none;">
            <h5><i class="fas fa-bug"></i> Informações de Debug</h5>
            <div class="row">
                <div class="col-md-6">
                    <strong>Sessão:</strong><br>
                    CPF: <?= $_SESSION['aluno_cpf'] ?><br>
                    Aluno ID: <?= $_SESSION['aluno_id'] ?? 'N/A' ?><br>
                    Subdomain: <?= $_SESSION['subdomain'] ?? 'N/A' ?><br>
                    Login: <?= date('d/m/Y H:i:s', $_SESSION['login_time'] ?? time()) ?>
                </div>
                <div class="col-md-6">
                    <strong>Banco de Dados:</strong><br>
                    Aluno encontrado: <?= $aluno ? 'Sim' : 'Não' ?><br>
                    ID do aluno: <?= $aluno['id'] ?? 'N/A' ?><br>
                    Cursos encontrados: <?= count($cursos) ?><br>
                    Total boletos: <?= $resumoGeral['total_boletos'] ?>
                </div>
            </div>
            
            <?php if (empty($cursos)): ?>
            <div class="mt-3">
                <h6 class="text-danger">⚠ Diagnóstico: Nenhum curso encontrado</h6>
                <p><strong>Possíveis causas:</strong></p>
                <ul>
                    <li>Dados não sincronizados com o Moodle</li>
                    <li>Matrículas com status 'inativa' no banco</li>
                    <li>Cursos com flag 'ativo' = false</li>
                    <li>Problema na API do Moodle</li>
                </ul>
                <button class="btn-debug" onclick="forcarSincronizacao()">
                    <i class="fas fa-sync"></i> Forçar Sincronização
                </button>
                <a href="/debug_completo.php" class="btn-debug" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Debug Completo
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Dashboard Header -->
        <div class="dashboard-header fade-in">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-2">
                        <i class="fas fa-file-invoice-dollar text-primary"></i> 
                        Meus Boletos
                    </h2>
                    <p class="text-muted mb-0">
                        Acompanhe seus boletos acadêmicos e mantenha seus pagamentos em dia
                        <?php if (!empty($configPolo['name'])): ?>
                            - <?= htmlspecialchars($configPolo['name']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <small class="text-muted">
                        <i class="fas fa-clock"></i> 
                        Último acesso: <?= date('d/m/Y H:i') ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Resumo Geral -->
        <div class="row mb-4 fade-in">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card total">
                    <div class="stat-number text-info"><?= $resumoGeral['total_boletos'] ?></div>
                    <div class="stat-label">Total de Boletos</div>
                    <small class="text-muted">
                        R$ <?= number_format($resumoGeral['valor_total'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card pagos">
                    <div class="stat-number text-success"><?= $resumoGeral['boletos_pagos'] ?></div>
                    <div class="stat-label">Boletos Pagos</div>
                    <small class="text-muted">
                        R$ <?= number_format($resumoGeral['valor_pago'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card pendentes">
                    <div class="stat-number text-warning"><?= $resumoGeral['boletos_pendentes'] ?></div>
                    <div class="stat-label">Pendentes</div>
                    <small class="text-muted">
                        R$ <?= number_format($resumoGeral['valor_pendente'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card vencidos">
                    <div class="stat-number text-danger"><?= $resumoGeral['boletos_vencidos'] ?></div>
                    <div class="stat-label">Vencidos</div>
                    <small class="text-muted">Requer atenção</small>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Coluna Principal - Boletos por Curso -->
            <div class="col-lg-8">
                <?php if (empty($dadosDashboard)): ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h4>Nenhum curso encontrado</h4>
                        <p>Não foram encontrados cursos ativos para este aluno.</p>
                        <p class="text-muted">
                            <small>
                                Verifique se você está matriculado em algum curso no seu polo 
                                ou tente atualizar os dados clicando no botão abaixo.
                            </small>
                        </p>
                        <button class="btn btn-primary" onclick="atualizarDados()">
                            <i class="fas fa-sync"></i> Atualizar Dados do Moodle
                        </button>
                        <button class="btn btn-secondary ms-2" onclick="mostrarDebug()">
                            <i class="fas fa-bug"></i> Informações de Debug
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($dadosDashboard as $index => $cursoDados): ?>
                        <!-- Conteúdo dos cursos igual ao dashboard original -->
                        <div class="curso-card fade-in" style="animation-delay: <?= $index * 0.1 ?>s">
                            <div class="curso-header">
                                <h3 class="curso-title">
                                    <i class="fas fa-book"></i> 
                                    <?= htmlspecialchars($cursoDados['curso']['nome']) ?>
                                </h3>
                                <div class="curso-stats">
                                    <span class="me-3">
                                        <i class="fas fa-file-invoice"></i> 
                                        <?= $cursoDados['resumo']['total'] ?> boletos
                                    </span>
                                    <span class="me-3">
                                        <i class="fas fa-check-circle"></i> 
                                        <?= $cursoDados['resumo']['pagos'] ?> pagos
                                    </span>
                                    <?php if ($cursoDados['resumo']['pendentes'] > 0): ?>
                                        <span class="me-3">
                                            <i class="fas fa-clock"></i> 
                                            <?= $cursoDados['resumo']['pendentes'] ?> pendentes
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="curso-body">
                                <?php if (empty($cursoDados['boletos'])): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-info-circle"></i>
                                        <h6>Nenhum boleto gerado ainda</h6>
                                        <p class="text-muted">Os boletos para este curso serão exibidos aqui quando gerados.</p>
                                    </div>
                                <?php else: ?>
                                    <!-- Lista de boletos igual ao dashboard original -->
                                    <p>Boletos seriam exibidos aqui...</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar igual ao dashboard original -->
            <div class="col-lg-4">
                <div class="sidebar-alerts fade-in">
                    <h5 class="mb-3">
                        <i class="fas fa-info-circle text-info"></i> 
                        Status do Sistema
                    </h5>
                    
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="fas fa-server"></i> Conexão com Moodle: 
                            <span class="text-success">Ativa</span>
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="fas fa-database"></i> Última sincronização: 
                            <?= date('d/m/Y H:i', strtotime($aluno['updated_at'] ?? 'now')) ?>
                        </small>
                    </div>
                    
                    <?php if (empty($cursos)): ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle"></i> Atenção</h6>
                        <p class="mb-2">Nenhum curso foi encontrado para seu usuário.</p>
                        <button class="btn btn-sm btn-warning" onclick="atualizarDados()">
                            Sincronizar Agora
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Função para mostrar/ocultar debug
        function mostrarDebug() {
            const debugInfo = document.getElementById('debugInfo');
            if (debugInfo.style.display === 'none') {
                debugInfo.style.display = 'block';
                debugInfo.scrollIntoView({ behavior: 'smooth' });
            } else {
                debugInfo.style.display = 'none';
            }
        }
        
        // Função para atualizar dados (melhorada)
        function atualizarDados() {
            if (confirm('Deseja sincronizar os dados com o sistema do Moodle? Isso pode levar alguns segundos.')) {
                showToast('Sincronizando dados...', 'info');
                
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sincronizando...';
                button.disabled = true;
                
                fetch('/api/atualizar_dados.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    
                    if (data.success) {
                        showToast('Dados atualizados com sucesso! Cursos encontrados: ' + (data.data?.cursos_encontrados || 0), 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showToast('Erro ao atualizar: ' + (data.message || 'Erro desconhecido'), 'error');
                        console.error('Erro detalhado:', data);
                    }
                })
                .catch(error => {
                    console.error('Erro na requisição:', error);
                    showToast('Erro de conexão. Verifique sua internet e tente novamente.', 'error');
                })
                .finally(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                });
            }
        }
        
        // Função para forçar sincronização
        function forcarSincronizacao() {
            showToast('Iniciando sincronização forçada...', 'info');
            atualizarDados();
        }
        
        // Sistema de toast melhorado
        function showToast(message, type = 'info') {
            // Remove toasts existentes
            const existingToasts = document.querySelectorAll('.toast-custom');
            existingToasts.forEach(toast => toast.remove());
            
            // Cria novo toast
            const toast = document.createElement('div');
            toast.className = `toast-custom alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            
            const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                        type === 'success' ? 'fa-check-circle' : 'fa-info-circle';
            
            toast.innerHTML = `
                <i class="fas ${icon}"></i> ${message}
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.remove()"></button>
            `;
            
            document.body.appendChild(toast);
            
            // Remove automaticamente após 5 segundos
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 5000);
        }
        
        // Log de debug no console
        console.log('Dashboard Debug Info:', {
            aluno_id: <?= $aluno['id'] ?? 'null' ?>,
            cpf: '<?= $_SESSION['aluno_cpf'] ?>',
            subdomain: '<?= $_SESSION['subdomain'] ?? '' ?>',
            cursos_encontrados: <?= count($cursos) ?>,
            total_boletos: <?= $resumoGeral['total_boletos'] ?>
        });
        
        // Verifica automaticamente se há problemas na inicialização
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (empty($cursos)): ?>
            console.warn('ATENÇÃO: Nenhum curso encontrado para este aluno!');
            <?php endif; ?>
        });
    </script>
</body>
</html>