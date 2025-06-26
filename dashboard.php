<?php
/**
 * Sistema de Boletos IMED - Dashboard do Aluno
 * Arquivo: public/dashboard.php
 * 
 * Página principal onde o aluno visualiza seus boletos organizados por curso
 */

session_start();

// Verifica se usuário está logado
if (!isset($_SESSION['aluno_cpf'])) {
    header('Location: /login.php');
    exit;
}

// Inclui arquivos necessários
require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/AlunoService.php';
require_once 'src/BoletoService.php';

// Inicializa serviços
$alunoService = new AlunoService();
$boletoService = new BoletoService();

// Busca dados do aluno
$aluno = $alunoService->buscarAlunoPorCPF($_SESSION['aluno_cpf']);
if (!$aluno) {
    session_destroy();
    header('Location: /login.php');
    exit;
}

// Busca cursos do aluno
$cursos = $alunoService->buscarCursosAluno($aluno['id']);

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
    $boletos = $boletoService->buscarBoletosCurso($aluno['id'], $curso['id']);
    
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
    $proximosVencimentos = [];
}

// Configura polo
$configPolo = MoodleConfig::getConfig($_SESSION['subdomain']) ?: [];
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
        
        .boleto-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid;
            transition: all 0.2s ease;
        }
        
        .boleto-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .boleto-item.status-pago {
            border-left-color: var(--success-color);
            background: rgba(40,167,69,0.05);
        }
        
        .boleto-item.status-pendente {
            border-left-color: var(--warning-color);
            background: rgba(255,193,7,0.05);
        }
        
        .boleto-item.status-vencido {
            border-left-color: var(--danger-color);
            background: rgba(220,53,69,0.05);
        }
        
        .boleto-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .boleto-detalhes h6 {
            margin: 0 0 5px 0;
            font-weight: 600;
        }
        
        .boleto-valor {
            font-size: 1.4rem;
            font-weight: 700;
        }
        
        .boleto-vencimento {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .status-badge {
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-pago {
            background: var(--success-color);
            color: white;
        }
        
        .badge-pendente {
            background: var(--warning-color);
            color: #333;
        }
        
        .badge-vencido {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-custom {
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            transition: all 0.2s ease;
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
        
        .sidebar-alerts {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .boleto-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .main-content {
                padding: 20px 0;
            }
            
            .dashboard-header {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .curso-header, .curso-body {
                padding: 15px 20px;
            }
            
            .boleto-item {
                padding: 15px;
            }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
                        <i class="fas fa-file-invoice"></i>
                        <h4>Nenhum curso encontrado</h4>
                        <p>Não foram encontrados cursos ativos para este aluno.</p>
                        <button class="btn btn-primary" onclick="atualizarDados()">
                            <i class="fas fa-sync"></i> Atualizar Dados
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
                                    <?php if ($cursoDados['resumo']['vencidos'] > 0): ?>
                                        <span>
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            <?= $cursoDados['resumo']['vencidos'] ?> vencidos
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="curso-body">
                                <?php if (empty($cursoDados['boletos'])): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-check-circle"></i>
                                        <h6>Nenhum boleto encontrado</h6>
                                        <p class="text-muted">Este curso não possui boletos gerados ainda.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($cursoDados['boletos'] as $boleto): ?>
                                        <?php
                                        $statusClass = 'status-' . $boleto['status'];
                                        $badgeClass = 'badge-' . $boleto['status'];
                                        
                                        $vencimento = new DateTime($boleto['vencimento']);
                                        $hoje = new DateTime();
                                        $diasRestantes = $vencimento->diff($hoje)->format('%r%a');
                                        ?>
                                        
                                        <div class="boleto-item <?= $statusClass ?>">
                                            <div class="boleto-info">
                                                <div class="boleto-detalhes flex-grow-1">
                                                    <h6>
                                                        Boleto #<?= htmlspecialchars($boleto['numero_boleto']) ?>
                                                        <span class="status-badge <?= $badgeClass ?>">
                                                            <?= ucfirst($boleto['status']) ?>
                                                        </span>
                                                    </h6>
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="boleto-vencimento">
                                                                <i class="fas fa-calendar"></i>
                                                                <strong>Vencimento:</strong> 
                                                                <?= $vencimento->format('d/m/Y') ?>
                                                                
                                                                <?php if ($boleto['status'] != 'pago'): ?>
                                                                    <?php if ($diasRestantes > 0): ?>
                                                                        <span class="text-success">
                                                                            (<?= $diasRestantes ?> dias restantes)
                                                                        </span>
                                                                    <?php elseif ($diasRestantes == 0): ?>
                                                                        <span class="text-warning">
                                                                            <strong>(Vence hoje!)</strong>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="text-danger">
                                                                            <strong>(<?= abs($diasRestantes) ?> dias em atraso)</strong>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                <?php elseif ($boleto['data_pagamento']): ?>
                                                                    <span class="text-success">
                                                                        (Pago em <?= date('d/m/Y', strtotime($boleto['data_pagamento'])) ?>)
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                            
                                                            <?php if ($boleto['descricao']): ?>
                                                                <div class="mt-1">
                                                                    <small class="text-muted">
                                                                        <i class="fas fa-info-circle"></i>
                                                                        <?= htmlspecialchars($boleto['descricao']) ?>
                                                                    </small>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <div class="col-md-6 text-md-end">
                                                            <div class="boleto-valor text-primary">
                                                                R$ <?= number_format($boleto['valor'], 2, ',', '.') ?>
                                                            </div>
                                                            
                                                            <div class="mt-2">
                                                                <?php if ($boleto['status'] != 'pago'): ?>
                                                                    <a href="/boleto.php?id=<?= $boleto['id'] ?>" 
                                                                       class="btn btn-primary btn-custom btn-sm me-2">
                                                                        <i class="fas fa-eye"></i> Ver Boleto
                                                                    </a>
                                                                    <a href="/boleto.php?id=<?= $boleto['id'] ?>&download=1" 
                                                                       class="btn btn-outline-secondary btn-custom btn-sm">
                                                                        <i class="fas fa-download"></i> PDF
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-success">
                                                                        <i class="fas fa-check-circle fa-2x"></i>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
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
            
            <!-- Sidebar - Próximos Vencimentos -->
            <div class="col-lg-4">
                <div class="sidebar-alerts fade-in">
                    <h5 class="mb-3">
                        <i class="fas fa-bell text-warning"></i> 
                        Próximos Vencimentos
                    </h5>
                    
                    <?php if (empty($proximosVencimentos)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-check-circle fa-2x mb-2"></i>
                            <p class="mb-0">Nenhum vencimento próximo!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($proximosVencimentos as $boleto): ?>
                            <?php
                            $vencimento = new DateTime($boleto['vencimento']);
                            $hoje = new DateTime();
                            $diasRestantes = (int)$vencimento->diff($hoje)->format('%r%a');
                            
                            $alertClass = '';
                            if ($diasRestantes < 0) $alertClass = 'border-danger';
                            elseif ($diasRestantes <= 3) $alertClass = 'border-warning';
                            else $alertClass = 'border-info';
                            ?>
                            
                            <div class="border-start <?= $alertClass ?> ps-3 pb-3 mb-3">
                                <h6 class="mb-1"><?= htmlspecialchars($boleto['curso_nome']) ?></h6>
                                <div class="text-muted small">
                                    <div>
                                        <i class="fas fa-calendar"></i>
                                        <?= $vencimento->format('d/m/Y') ?>
                                        
                                        <?php if ($diasRestantes < 0): ?>
                                            <span class="text-danger ms-1">
                                                (<?= abs($diasRestantes) ?> dias atraso)
                                            </span>
                                        <?php elseif ($diasRestantes == 0): ?>
                                            <span class="text-warning ms-1">
                                                <strong>(Hoje!)</strong>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted ms-1">
                                                (<?= $diasRestantes ?> dias)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1">
                                        <i class="fas fa-dollar-sign"></i>
                                        R$ <?= number_format($boleto['valor'], 2, ',', '.') ?>
                                    </div>
                                </div>
                                <a href="/boleto.php?id=<?= $boleto['id'] ?>" 
                                   class="btn btn-outline-primary btn-sm mt-2">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Dicas e Ajuda -->
                <div class="sidebar-alerts fade-in">
                    <h5 class="mb-3">
                        <i class="fas fa-lightbulb text-warning"></i> 
                        Dicas Importantes
                    </h5>
                    
                    <div class="small text-muted">
                        <div class="mb-2">
                            <i class="fas fa-check text-success"></i>
                            Boletos podem ser pagos em qualquer banco ou lotérica
                        </div>
                        <div class="mb-2">
                            <i class="fas fa-clock text-warning"></i>
                            Pagamentos após vencimento podem ter multa e juros
                        </div>
                        <div class="mb-2">
                            <i class="fas fa-mobile-alt text-info"></i>
                            Use o app do seu banco para pagar pelo código de barras
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast de Notificação -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="liveToast" class="toast" role="alert">
            <div class="toast-header">
                <i class="fas fa-info-circle text-primary me-2"></i>
                <strong class="me-auto">Sistema de Boletos</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body" id="toastMessage">
                Operação realizada com sucesso!
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            // Verifica boletos vencendo
            verificarVencimentos();
        });
        
        // Função para atualizar dados
        function atualizarDados() {
            if (confirm('Deseja sincronizar os dados com o sistema do Moodle?')) {
                showToast('Atualizando dados...', 'info');
                
                fetch('/api/atualizar_dados.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Dados atualizados com sucesso!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast('Erro ao atualizar dados: ' + (data.message || 'Erro desconhecido'), 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                    console.error('Erro:', error);
                });
            }
        }
        
        // Função para verificar vencimentos
        function verificarVencimentos() {
            const hoje = new Date();
            const boletos = document.querySelectorAll('.boleto-item.status-pendente');
            let vencendoHoje = 0;
            let vencidos = 0;
            
            boletos.forEach(boleto => {
                const vencimentoText = boleto.querySelector('.boleto-vencimento').textContent;
                const vencimentoMatch = vencimentoText.match(/(\d{2}\/\d{2}\/\d{4})/);
                
                if (vencimentoMatch) {
                    const partesData = vencimentoMatch[1].split('/');
                    const vencimento = new Date(partesData[2], partesData[1] - 1, partesData[0]);
                    
                    const diffTime = vencimento - hoje;
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    
                    if (diffDays === 0) {
                        vencendoHoje++;
                    } else if (diffDays < 0) {
                        vencidos++;
                    }
                }
            });
            
            // Mostra notificações
            if (vencendoHoje > 0) {
                showToast(`Você tem ${vencendoHoje} boleto(s) vencendo hoje!`, 'warning');
            }
            
            if (vencidos > 0) {
                showToast(`Atenção: ${vencidos} boleto(s) vencido(s)!`, 'error');
            }
        }
        
        // Sistema de toast
        function showToast(message, type = 'info') {
            const toastElement = document.getElementById('liveToast');
            const toastMessage = document.getElementById('toastMessage');
            const toastHeader = toastElement.querySelector('.toast-header');
            
            toastMessage.textContent = message;
            
            // Remove classes anteriores
            toastElement.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
            
            // Adiciona classe baseada no tipo
            switch(type) {
                case 'success':
                    toastElement.classList.add('bg-success');
                    toastHeader.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i><strong class="me-auto">Sucesso</strong><button type="button" class="btn-close" data-bs-dismiss="toast"></button>';
                    break;
                case 'error':
                    toastElement.classList.add('bg-danger');
                    toastHeader.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-2"></i><strong class="me-auto">Erro</strong><button type="button" class="btn-close" data-bs-dismiss="toast"></button>';
                    break;
                case 'warning':
                    toastElement.classList.add('bg-warning');
                    toastHeader.innerHTML = '<i class="fas fa-exclamation-triangle text-warning me-2"></i><strong class="me-auto">Atenção</strong><button type="button" class="btn-close" data-bs-dismiss="toast"></button>';
                    break;
                default:
                    toastHeader.innerHTML = '<i class="fas fa-info-circle text-primary me-2"></i><strong class="me-auto">Informação</strong><button type="button" class="btn-close" data-bs-dismiss="toast"></button>';
            }
            
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
        }
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl + R para atualizar dados
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                atualizarDados();
            }
        });
        
        // Monitora conexão
        window.addEventListener('online', function() {
            showToast('Conexão restaurada', 'success');
        });
        
        window.addEventListener('offline', function() {
            showToast('Conexão perdida. Verifique sua internet.', 'error');
        });
    </script>
</body>
</html>