<?php
/**
 * Sistema de Boletos IMED - Dashboard Corrigido (Filtro por Subdomínio)
 * Arquivo: dashboard.php
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

// Busca dados do aluno ESPECÍFICO DO POLO ATUAL
$aluno = $alunoService->buscarAlunoPorCPFESubdomain($_SESSION['aluno_cpf'], $_SESSION['subdomain']);
if (!$aluno) {
    error_log("Dashboard: Aluno não encontrado no banco local para CPF: " . $_SESSION['aluno_cpf'] . " e subdomain: " . $_SESSION['subdomain']);
    session_destroy();
    header('Location: /login.php');
    exit;
}

error_log("Dashboard: Aluno encontrado - ID: {$aluno['id']}, Nome: {$aluno['nome']}, Subdomain: {$aluno['subdomain']}");

// Busca cursos do aluno APENAS DO POLO ATUAL
$cursos = $alunoService->buscarCursosAlunoPorSubdomain($aluno['id'], $_SESSION['subdomain']);
error_log("Dashboard: Cursos encontrados no subdomain {$_SESSION['subdomain']}: " . count($cursos));

// Debug detalhado dos cursos
foreach ($cursos as $curso) {
    error_log("Dashboard: Curso - ID: {$curso['id']}, Nome: {$curso['nome']}, Subdomain: {$curso['subdomain']}");
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
        
        // Atualiza status se vencido
        if ($boleto['esta_vencido'] && $boleto['status'] == 'pendente') {
            $boletoService->atualizarStatusVencido($boleto['id']);
            $boleto['status'] = 'vencido';
        }
        
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
    
    // Ordena boletos por vencimento (mais próximos primeiro)
    usort($cursoDados['boletos'], function($a, $b) {
        return strtotime($a['vencimento']) - strtotime($b['vencimento']);
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

// Busca próximos vencimentos APENAS DO POLO ATUAL
try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ? 
        AND c.subdomain = ?
        AND b.status IN ('pendente', 'vencido')
        ORDER BY 
            CASE WHEN b.vencimento < CURDATE() THEN 0 ELSE 1 END,
            b.vencimento ASC
        LIMIT 5
    ");
    $stmt->execute([$aluno['id'], $_SESSION['subdomain']]);
    $proximosVencimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Dashboard: Erro ao buscar próximos vencimentos: " . $e->getMessage());
    $proximosVencimentos = [];
}

// Configura polo
$configPolo = MoodleConfig::getConfig($_SESSION['subdomain']) ?: [];

// Log final do dashboard
error_log("Dashboard: Resumo final - Subdomain: {$_SESSION['subdomain']}, Cursos: " . count($dadosDashboard) . ", Boletos totais: " . $resumoGeral['total_boletos']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Boletos - <?= htmlspecialchars($aluno['nome']) ?> - <?= htmlspecialchars($configPolo['name'] ?? 'IMED') ?></title>
    
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
        
        .boleto-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid;
            transition: all 0.2s ease;
        }
        
        .boleto-card.pago { border-left-color: var(--success-color); }
        .boleto-card.pendente { border-left-color: var(--warning-color); }
        .boleto-card.vencido { border-left-color: var(--danger-color); }
        
        .boleto-card:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .boleto-numero {
            font-family: monospace;
            font-weight: bold;
            color: #495057;
        }
        
        .boleto-valor {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .boleto-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pago {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        
        .status-pendente {
            background: rgba(255,193,7,0.1);
            color: #856404;
        }
        
        .status-vencido {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        
        .sidebar-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .vencimento-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            border-left: 3px solid;
        }
        
        .vencimento-card.vencido { border-left-color: var(--danger-color); }
        .vencimento-card.hoje { border-left-color: var(--warning-color); }
        .vencimento-card.proximo { border-left-color: var(--info-color); }
        
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
        
        .btn-boleto {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .btn-download {
            background: var(--info-color);
            color: white;
        }
        
        .btn-download:hover {
            background: #138496;
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-codigo {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-codigo:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .polo-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/dashboard.php">
                <i class="fas fa-graduation-cap"></i> IMED Educação
                <span class="polo-badge">
                    <?= str_replace('.imepedu.com.br', '', $_SESSION['subdomain']) ?>
                </span>
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
                                    Polo: <?= htmlspecialchars($configPolo['name'] ?? str_replace('.imepedu.com.br', '', $_SESSION['subdomain'])) ?>
                                </small><br>
                                <small class="text-muted">
                                    Subdomain: <?= $_SESSION['subdomain'] ?>
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
            <h5><i class="fas fa-bug"></i> Informações de Debug - Filtro por Subdomínio</h5>
            <div class="row">
                <div class="col-md-6">
                    <strong>Sessão:</strong><br>
                    CPF: <?= $_SESSION['aluno_cpf'] ?><br>
                    Aluno ID: <?= $_SESSION['aluno_id'] ?? 'N/A' ?><br>
                    Subdomain da Sessão: <?= $_SESSION['subdomain'] ?? 'N/A' ?><br>
                    Login: <?= date('d/m/Y H:i:s', $_SESSION['login_time'] ?? time()) ?>
                </div>
                <div class="col-md-6">
                    <strong>Banco de Dados (Filtrado):</strong><br>
                    Aluno encontrado: <?= $aluno ? 'Sim' : 'Não' ?><br>
                    ID do aluno: <?= $aluno['id'] ?? 'N/A' ?><br>
                    Subdomain do aluno: <?= $aluno['subdomain'] ?? 'N/A' ?><br>
                    Cursos encontrados: <?= count($cursos) ?> (apenas do polo atual)<br>
                    Total boletos: <?= $resumoGeral['total_boletos'] ?>
                </div>
            </div>
            
            <?php if (!empty($cursos)): ?>
            <div class="mt-3">
                <h6 class="text-success">✓ Cursos do polo atual (<?= $_SESSION['subdomain'] ?>):</h6>
                <ul>
                    <?php foreach ($cursos as $curso): ?>
                        <li><?= htmlspecialchars($curso['nome']) ?> (ID: <?= $curso['id'] ?>, Subdomain: <?= $curso['subdomain'] ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div class="mt-3">
                <h6 class="text-warning">⚠ Nenhum curso encontrado para o polo atual</h6>
                <p><strong>Possíveis causas:</strong></p>
                <ul>
                    <li>Cursos estão em outro subdomínio</li>
                    <li>Matrículas inativas para este polo</li>
                    <li>Dados não sincronizados</li>
                </ul>
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
                    <small class="text-muted">
                        <i class="fas fa-filter"></i> 
                        Exibindo apenas cursos e boletos do polo: <strong><?= $_SESSION['subdomain'] ?></strong>
                    </small>
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
                        <h4>Nenhum curso encontrado neste polo</h4>
                        <p>Não foram encontrados cursos ativos para este aluno no polo <strong><?= $_SESSION['subdomain'] ?></strong>.</p>
                        <p class="text-muted">
                            <small>
                                O sistema agora filtra cursos por polo. Se você possui cursos em outros polos, 
                                faça login através do polo correspondente.
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
                                    <span class="me-3">
                                        <i class="fas fa-dollar-sign"></i> 
                                        R$ <?= number_format($cursoDados['resumo']['valor_total'], 2, ',', '.') ?>
                                    </span>
                                    <span class="me-3">
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <?= $cursoDados['curso']['subdomain'] ?>
                                    </span>
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
                                    <?php foreach ($cursoDados['boletos'] as $boleto): ?>
                                        <div class="boleto-card <?= $boleto['status'] ?>">
                                            <div class="row align-items-center">
                                                <div class="col-md-3">
                                                    <div class="boleto-numero">
                                                        #<?= $boleto['numero_boleto'] ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?= !empty($boleto['descricao']) ? htmlspecialchars($boleto['descricao']) : 'Mensalidade' ?>
                                                    </small>
                                                </div>
                                                
                                                <div class="col-md-2">
                                                    <div class="boleto-valor text-primary">
                                                        R$ <?= number_format($boleto['valor'], 2, ',', '.') ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-2">
                                                    <div class="text-muted">
                                                        <small>Vencimento</small>
                                                    </div>
                                                    <div class="fw-bold">
                                                        <?= date('d/m/Y', strtotime($boleto['vencimento'])) ?>
                                                    </div>
                                                    <?php if ($boleto['dias_vencimento'] < 0): ?>
                                                        <small class="text-danger">
                                                            <?= abs($boleto['dias_vencimento']) ?> dias em atraso
                                                        </small>
                                                    <?php elseif ($boleto['dias_vencimento'] <= 7 && $boleto['status'] == 'pendente'): ?>
                                                        <small class="text-warning">
                                                            Vence em <?= $boleto['dias_vencimento'] ?> dias
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="col-md-2">
                                                    <span class="boleto-status status-<?= $boleto['status'] ?>">
                                                        <?php
                                                        switch($boleto['status']) {
                                                            case 'pago':
                                                                echo '<i class="fas fa-check"></i> Pago';
                                                                break;
                                                            case 'pendente':
                                                                echo '<i class="fas fa-clock"></i> Pendente';
                                                                break;
                                                            case 'vencido':
                                                                echo '<i class="fas fa-exclamation-triangle"></i> Vencido';
                                                                break;
                                                            default:
                                                                echo ucfirst($boleto['status']);
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                                
                                                <div class="col-md-3 text-end">
                                                    <?php if ($boleto['status'] != 'pago'): ?>
                                                        <a href="javascript:void(0)" 
                                                           class="btn-boleto btn-download me-2"
                                                           onclick="downloadBoleto(<?= $boleto['id'] ?>)">
                                                            <i class="fas fa-download"></i> PDF
                                                        </a>
                                                        <a href="javascript:void(0)" 
                                                           class="btn-boleto btn-codigo"
                                                           onclick="mostrarCodigo(<?= $boleto['id'] ?>)">
                                                            <i class="fas fa-barcode"></i> Código
                                                        </a>
                                                    <?php else: ?>
                                                        <small class="text-success">
                                                            <i class="fas fa-check-circle"></i>
                                                            Pago em <?= date('d/m/Y', strtotime($boleto['data_pagamento'])) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Próximos Vencimentos -->
                <div class="sidebar-card fade-in">
                    <h5 class="mb-3">
                        <i class="fas fa-calendar-exclamation text-warning"></i> 
                        Próximos Vencimentos
                    </h5>
                    
                    <?php if (empty($proximosVencimentos)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-check-circle fa-2x mb-2"></i>
                            <p class="mb-0">Nenhum boleto pendente neste polo!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($proximosVencimentos as $boleto): ?>
                            <?php
                            $hoje = new DateTime();
                            $vencimento = new DateTime($boleto['vencimento']);
                            $diff = $hoje->diff($vencimento);
                            $dias = (int)$diff->format('%r%a');
                            
                            if ($dias < 0) {
                                $classe = 'vencido';
                                $texto = abs($dias) . ' dias em atraso';
                                $icone = 'fas fa-exclamation-triangle text-danger';
                            } elseif ($dias == 0) {
                                $classe = 'hoje';
                                $texto = 'Vence hoje!';
                                $icone = 'fas fa-exclamation-circle text-warning';
                            } else {
                                $classe = 'proximo';
                                $texto = 'Vence em ' . $dias . ' dias';
                                $icone = 'fas fa-clock text-info';
                            }
                            ?>
                            <div class="vencimento-card <?= $classe ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($boleto['curso_nome']) ?></div>
                                        <div class="text-muted small">
                                            R$ <?= number_format($boleto['valor'], 2, ',', '.') ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small">
                                            <i class="<?= $icone ?>"></i>
                                        </div>
                                        <div class="small fw-bold">
                                            <?= date('d/m', strtotime($boleto['vencimento'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="small mt-1 <?= $dias < 0 ? 'text-danger' : ($dias == 0 ? 'text-warning' : 'text-muted') ?>">
                                    <?= $texto ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Status do Sistema -->
                <div class="sidebar-card fade-in">
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
                    
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="fas fa-graduation-cap"></i> Cursos ativos neste polo: 
                            <span class="text-primary"><?= count($cursos) ?></span>
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="fas fa-map-marker-alt"></i> Polo atual: 
                            <span class="text-info"><?= htmlspecialchars($configPolo['name'] ?? $_SESSION['subdomain']) ?></span>
                        </small>
                    </div>
                    
                    <?php if ($resumoGeral['boletos_vencidos'] > 0): ?>
                    <div class="alert alert-danger py-2">
                        <small>
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Atenção:</strong> Você possui <?= $resumoGeral['boletos_vencidos'] ?> 
                            boleto(s) vencido(s) neste polo. Regularize sua situação o quanto antes.
                        </small>
                    </div>
                    <?php elseif ($resumoGeral['boletos_pendentes'] > 0): ?>
                    <div class="alert alert-warning py-2">
                        <small>
                            <i class="fas fa-info-circle"></i>
                            Você possui <?= $resumoGeral['boletos_pendentes'] ?> boleto(s) pendente(s) neste polo.
                        </small>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success py-2">
                        <small>
                            <i class="fas fa-check-circle"></i>
                            Parabéns! Todos os seus boletos deste polo estão em dia.
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Ações Rápidas -->
                <div class="sidebar-card fade-in">
                    <h5 class="mb-3">
                        <i class="fas fa-bolt text-primary"></i> 
                        Ações Rápidas
                    </h5>
                    
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary btn-sm" onclick="atualizarDados()">
                            <i class="fas fa-sync"></i> Sincronizar Dados
                        </button>
                        
                        <?php if ($resumoGeral['boletos_pendentes'] > 0): ?>
                        <button class="btn btn-outline-warning btn-sm" onclick="baixarTodosPendentes()">
                            <i class="fas fa-download"></i> Baixar Todos Pendentes
                        </button>
                        <?php endif; ?>
                        
                        <a href="mailto:<?= $configPolo['contact_email'] ?? 'suporte@imepedu.com.br' ?>" 
                           class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-envelope"></i> Contatar Suporte
                        </a>
                        
                        <button class="btn btn-outline-info btn-sm" onclick="mostrarOutrosPolos()">
                            <i class="fas fa-building"></i> Outros Polos
                        </button>
                    </div>
                </div>
                
                <!-- Informações do Polo -->
                <?php if (!empty($configPolo)): ?>
                <div class="sidebar-card fade-in">
                    <h5 class="mb-3">
                        <i class="fas fa-map-marker-alt text-info"></i> 
                        Informações do Polo
                    </h5>
                    
                    <div class="small text-muted">
                        <div class="mb-2">
                            <strong><?= htmlspecialchars($configPolo['name'] ?? '') ?></strong>
                        </div>
                        
                        <?php if (!empty($configPolo['contact_email'])): ?>
                        <div class="mb-1">
                            <i class="fas fa-envelope"></i> 
                            <a href="mailto:<?= htmlspecialchars($configPolo['contact_email']) ?>" 
                               class="text-decoration-none">
                                <?= htmlspecialchars($configPolo['contact_email']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($configPolo['phone'])): ?>
                        <div class="mb-1">
                            <i class="fas fa-phone"></i> 
                            <?= htmlspecialchars($configPolo['phone']) ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($configPolo['address'])): ?>
                        <div>
                            <i class="fas fa-map-marker-alt"></i> 
                            <?= htmlspecialchars($configPolo['address']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal para Código de Barras -->
    <div class="modal fade" id="codigoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-barcode"></i> Código de Barras
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="codigoConteudo">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="copiarCodigo()">
                        <i class="fas fa-copy"></i> Copiar Código
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Outros Polos -->
    <div class="modal fade" id="outrosPolosModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-building"></i> Acessar Outros Polos
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Para acessar boletos de outros polos, faça login através do polo correspondente:</p>
                    
                    <div class="list-group">
                        <a href="https://tucurui.imepedu.com.br/boletos" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Polo Tucuruí</h6>
                                <small>tucurui.imepedu.com.br</small>
                            </div>
                            <small>Acesse para ver boletos específicos do polo Tucuruí</small>
                        </a>
                        
                        <a href="https://breubranco.imepedu.com.br/boletos" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Polo Breu Branco</h6>
                                <small>breubranco.imepedu.com.br</small>
                            </div>
                            <small>Acesse para ver boletos específicos do polo Breu Branco</small>
                        </a>
                        
                        <a href="https://moju.imepedu.com.br/boletos" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Polo Moju</h6>
                                <small>moju.imepedu.com.br</small>
                            </div>
                            <small>Acesse para ver boletos específicos do polo Moju</small>
                        </a>
                        
                        <a href="https://igarape.imepedu.com.br/boletos" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Polo Igarapé-Miri</h6>
                                <small>igarape.imepedu.com.br</small>
                            </div>
                            <small>Acesse para ver boletos específicos do polo Igarapé-Miri</small>
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
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
        
        // Função para mostrar modal de outros polos
        function mostrarOutrosPolos() {
            const modal = new bootstrap.Modal(document.getElementById('outrosPolosModal'));
            modal.show();
        }
        
        // Função para atualizar dados (melhorada com filtro por subdomain)
        function atualizarDados() {
            if (confirm('Deseja sincronizar os dados com o sistema do Moodle para este polo? Isso pode levar alguns segundos.')) {
                showToast('Sincronizando dados do polo <?= $_SESSION['subdomain'] ?>...', 'info');
                
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
                        showToast('Dados atualizados com sucesso! Cursos encontrados para este polo: ' + (data.data?.cursos_encontrados || 0), 'success');
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
        
        // Função para download de boleto
        function downloadBoleto(boletoId) {
            showToast('Preparando download...', 'info');
            
            // Simula download (você deve implementar a geração real do PDF)
            setTimeout(() => {
                showToast('PDF do boleto será implementado em breve', 'warning');
            }, 1000);
        }
        
        // Função para mostrar código de barras
        function mostrarCodigo(boletoId) {
            const modal = new bootstrap.Modal(document.getElementById('codigoModal'));
            const conteudo = document.getElementById('codigoConteudo');
            
            conteudo.innerHTML = `
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Carregando...</span>
                </div>
            `;
            
            modal.show();
            
            // Simula busca do código (você deve implementar a busca real)
            setTimeout(() => {
                conteudo.innerHTML = `
                    <div class="mb-3">
                        <h6>Linha Digitável:</h6>
                        <div class="p-3 bg-light rounded font-monospace" id="linhaDigitavel">
                            00190.00009 00000.000000 00000.000000 0 00000000000000
                        </div>
                    </div>
                    <div>
                        <h6>Código de Barras:</h6>
                        <div class="p-3 bg-light rounded font-monospace small" id="codigoBarras">
                            00190000000000000000000000000000000000000000000
                        </div>
                    </div>
                `;
            }, 1000);
        }
        
        // Função para copiar código
        function copiarCodigo() {
            const linha = document.getElementById('linhaDigitavel');
            if (linha) {
                navigator.clipboard.writeText(linha.textContent.trim());
                showToast('Linha digitável copiada!', 'success');
            }
        }
        
        // Função para baixar todos os boletos pendentes
        function baixarTodosPendentes() {
            if (confirm('Deseja baixar todos os boletos pendentes deste polo?')) {
                showToast('Preparando download de todos os boletos do polo <?= $_SESSION['subdomain'] ?>...', 'info');
                
                // Implementar download em lote
                setTimeout(() => {
                    showToast('Download em lote será implementado em breve', 'warning');
                }, 1000);
            }
        }
        
        // Sistema de notificações toast
        function showToast(message, type = 'info') {
            // Remove toasts existentes
            const existingToasts = document.querySelectorAll('.toast-custom');
            existingToasts.forEach(toast => toast.remove());
            
            // Cria novo toast
            const toast = document.createElement('div');
            toast.className = `toast-custom alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideInRight 0.3s ease;';
            
            const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                        type === 'success' ? 'fa-check-circle' : 
                        type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${icon} me-2"></i>
                    <span>${message}</span>
                    <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // Remove automaticamente após 5 segundos
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 5000);
        }
        
        // Animações CSS adicionais
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
        `;
        document.head.appendChild(style);
        
        // Log de debug no console
        console.log('Dashboard carregado com filtro por subdomínio', {
            aluno_id: <?= $aluno['id'] ?? 'null' ?>,
            subdomain: '<?= $_SESSION['subdomain'] ?>',
            total_cursos: <?= count($cursos) ?>,
            total_boletos: <?= $resumoGeral['total_boletos'] ?>,
            boletos_pendentes: <?= $resumoGeral['boletos_pendentes'] ?>,
            boletos_vencidos: <?= $resumoGeral['boletos_vencidos'] ?>
        });
    </script>
</body>
</html>