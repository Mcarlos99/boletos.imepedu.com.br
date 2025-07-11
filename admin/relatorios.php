<?php
/**
 * Sistema de Boletos IMEPEDU - Página de Relatórios Administrativos
 * Arquivo: admin/relatorios.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';
require_once 'includes/verificar-permissao.php';



$adminService = new AdminService();
$admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$admin) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Processa filtros
$filtros = [];
$periodo = $_GET['periodo'] ?? 'mes_atual';

if (!empty($_GET['polo'])) $filtros['polo'] = $_GET['polo'];
if (!empty($_GET['data_inicio'])) $filtros['data_inicio'] = $_GET['data_inicio'];
if (!empty($_GET['data_fim'])) $filtros['data_fim'] = $_GET['data_fim'];

// Define período baseado na seleção
switch ($periodo) {
    case 'hoje':
        $filtros['data_inicio'] = date('Y-m-d');
        $filtros['data_fim'] = date('Y-m-d');
        break;
    case 'semana_atual':
        $filtros['data_inicio'] = date('Y-m-d', strtotime('monday this week'));
        $filtros['data_fim'] = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'mes_atual':
        $filtros['data_inicio'] = date('Y-m-01');
        $filtros['data_fim'] = date('Y-m-t');
        break;
    case 'trimestre_atual':
        $mes_atual = date('n');
        $trimestre = ceil($mes_atual / 3);
        $mes_inicio = (($trimestre - 1) * 3) + 1;
        $filtros['data_inicio'] = date('Y-' . str_pad($mes_inicio, 2, '0', STR_PAD_LEFT) . '-01');
        $filtros['data_fim'] = date('Y-m-t', strtotime(date('Y-' . str_pad($mes_inicio + 2, 2, '0', STR_PAD_LEFT) . '-01')));
        break;
    case 'ano_atual':
        $filtros['data_inicio'] = date('Y-01-01');
        $filtros['data_fim'] = date('Y-12-31');
        break;
}

// Busca estatísticas
$estatisticas = obterEstatisticasRelatorio($filtros);
$polosAtivos = MoodleConfig::getActiveSubdomains();

/**
 * Função para obter estatísticas do relatório
 */
function obterEstatisticasRelatorio($filtros) {
    $db = (new Database())->getConnection();
    
    $where = ['1=1'];
    $params = [];
    
    // Aplica filtros
    if (!empty($filtros['polo'])) {
        $where[] = "c.subdomain = ?";
        $params[] = $filtros['polo'];
    }
    
    if (!empty($filtros['data_inicio'])) {
        $where[] = "DATE(b.created_at) >= ?";
        $params[] = $filtros['data_inicio'];
    }
    
    if (!empty($filtros['data_fim'])) {
        $where[] = "DATE(b.created_at) <= ?";
        $params[] = $filtros['data_fim'];
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Estatísticas gerais
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
            COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as boletos_pendentes,
            COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as boletos_vencidos,
            COUNT(CASE WHEN b.status = 'cancelado' THEN 1 END) as boletos_cancelados,
            COALESCE(SUM(b.valor), 0) as valor_total,
            COALESCE(SUM(CASE WHEN b.status = 'pago' THEN COALESCE(b.valor_pago, b.valor) ELSE 0 END), 0) as valor_arrecadado,
            COALESCE(SUM(CASE WHEN b.status IN ('pendente', 'vencido') THEN b.valor ELSE 0 END), 0) as valor_pendente,
            COUNT(DISTINCT b.aluno_id) as total_alunos,
            COUNT(DISTINCT b.curso_id) as total_cursos
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE {$whereClause}
    ");
    $stmt->execute($params);
    $gerais = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Por polo
    $stmt = $db->prepare("
        SELECT 
            c.subdomain,
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
            COALESCE(SUM(b.valor), 0) as valor_total,
            COALESCE(SUM(CASE WHEN b.status = 'pago' THEN COALESCE(b.valor_pago, b.valor) ELSE 0 END), 0) as valor_arrecadado,
            COUNT(DISTINCT b.aluno_id) as total_alunos
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE {$whereClause}
        GROUP BY c.subdomain
        ORDER BY valor_arrecadado DESC
    ");
    $stmt->execute($params);
    $porPolo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Por mês (últimos 12 meses)
    $stmt = $db->prepare("
        SELECT 
            YEAR(b.created_at) as ano,
            MONTH(b.created_at) as mes,
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
            COALESCE(SUM(b.valor), 0) as valor_total,
            COALESCE(SUM(CASE WHEN b.status = 'pago' THEN COALESCE(b.valor_pago, b.valor) ELSE 0 END), 0) as valor_arrecadado
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        " . (!empty($filtros['polo']) ? " AND c.subdomain = ?" : "") . "
        GROUP BY YEAR(b.created_at), MONTH(b.created_at)
        ORDER BY ano DESC, mes DESC
        LIMIT 12
    ");
    
    $paramsEvolucao = [];
    if (!empty($filtros['polo'])) {
        $paramsEvolucao[] = $filtros['polo'];
    }
    $stmt->execute($paramsEvolucao);
    $evolucaoMensal = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top cursos
    $stmt = $db->prepare("
        SELECT 
            c.nome as curso_nome,
            c.subdomain,
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
            COALESCE(SUM(b.valor), 0) as valor_total,
            COALESCE(SUM(CASE WHEN b.status = 'pago' THEN COALESCE(b.valor_pago, b.valor) ELSE 0 END), 0) as valor_arrecadado
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE {$whereClause}
        GROUP BY c.id, c.nome, c.subdomain
        ORDER BY valor_arrecadado DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    $topCursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Status breakdown por dia (últimos 30 dias)
    $stmt = $db->prepare("
        SELECT 
            DATE(b.created_at) as data,
            COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as pagos,
            COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as pendentes,
            COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as vencidos,
            COUNT(CASE WHEN b.status = 'cancelado' THEN 1 END) as cancelados
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        " . (!empty($filtros['polo']) ? " AND c.subdomain = ?" : "") . "
        GROUP BY DATE(b.created_at)
        ORDER BY data DESC
        LIMIT 30
    ");
    
    $stmt->execute($paramsEvolucao);
    $statusDiario = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'gerais' => $gerais,
        'por_polo' => $porPolo,
        'evolucao_mensal' => $evolucaoMensal,
        'top_cursos' => $topCursos,
        'status_diario' => $statusDiario
    ];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - Administração IMEPEDU</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    
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
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-left: 4px solid;
            transition: transform 0.2s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.total { border-left-color: #17a2b8; }
        .stat-card.pagos { border-left-color: #28a745; }
        .stat-card.pendentes { border-left-color: #ffc107; }
        .stat-card.arrecadado { border-left-color: #20c997; }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 1rem 0;
        }
        
        .table-container {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .badge-status {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pago { background: rgba(40,167,69,0.1); color: #28a745; }
        .badge-pendente { background: rgba(255,193,7,0.1); color: #856404; }
        .badge-vencido { background: rgba(220,53,69,0.1); color: #dc3545; }
        
        .progress-bar-container {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 0.5rem;
        }
        
        .progress-bar {
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
    			<a href="/admin/documentos.php" class="nav-link">
        			<i class="fas fa-folder-open"></i>
        			Documentos
    			</a>
		</div>
            <div class="nav-item">
                <a href="/admin/cursos.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Cursos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/relatorios.php" class="nav-link active">
                    <i class="fas fa-chart-bar"></i>
                    Relatórios
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/configuracoes.php" class="nav-link">
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
                <h3>Relatórios e Análises</h3>
                <small class="text-muted">Dados do período: 
                    <?= !empty($filtros['data_inicio']) ? date('d/m/Y', strtotime($filtros['data_inicio'])) : 'Início' ?> 
                    até 
                    <?= !empty($filtros['data_fim']) ? date('d/m/Y', strtotime($filtros['data_fim'])) : 'Fim' ?>
                </small>
            </div>
            <div>
                <button class="btn btn-primary" onclick="exportarRelatorio()">
                    <i class="fas fa-download"></i> Exportar PDF
                </button>
                <button class="btn btn-outline-secondary" onclick="exportarExcel()">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i class="fas fa-filter"></i> Filtros do Relatório
                </h6>
                
                <form method="GET" class="row g-3" id="filtrosForm">
                    <div class="col-md-3">
                        <label class="form-label">Período</label>
                        <select name="periodo" class="form-select" onchange="atualizarDatas(this.value)">
                            <option value="mes_atual" <?= $periodo == 'mes_atual' ? 'selected' : '' ?>>Mês Atual</option>
                            <option value="hoje" <?= $periodo == 'hoje' ? 'selected' : '' ?>>Hoje</option>
                            <option value="semana_atual" <?= $periodo == 'semana_atual' ? 'selected' : '' ?>>Semana Atual</option>
                            <option value="trimestre_atual" <?= $periodo == 'trimestre_atual' ? 'selected' : '' ?>>Trimestre Atual</option>
                            <option value="ano_atual" <?= $periodo == 'ano_atual' ? 'selected' : '' ?>>Ano Atual</option>
                            <option value="personalizado" <?= $periodo == 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?= $_GET['data_inicio'] ?? '' ?>" id="dataInicio">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?= $_GET['data_fim'] ?? '' ?>" id="dataFim">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Polo</label>
                        <select name="polo" class="form-select">
                            <option value="">Todos os polos</option>
                            <?php foreach ($polosAtivos as $polo): ?>
                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                <option value="<?= $polo ?>" <?= ($_GET['polo'] ?? '') == $polo ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-sync-alt"></i> Atualizar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Estatísticas Principais -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card total">
                    <div class="stat-number text-info"><?= number_format($estatisticas['gerais']['total_boletos']) ?></div>
                    <div class="stat-label">Total de Boletos</div>
                    <small class="text-muted">
                        R$ <?= number_format($estatisticas['gerais']['valor_total'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card pagos">
                    <div class="stat-number text-success"><?= number_format($estatisticas['gerais']['boletos_pagos']) ?></div>
                    <div class="stat-label">Boletos Pagos</div>
                    <small class="text-muted">
                        <?= $estatisticas['gerais']['total_boletos'] > 0 ? round(($estatisticas['gerais']['boletos_pagos'] / $estatisticas['gerais']['total_boletos']) * 100, 1) : 0 ?>% do total
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card pendentes">
                    <div class="stat-number text-warning"><?= number_format($estatisticas['gerais']['boletos_pendentes'] + $estatisticas['gerais']['boletos_vencidos']) ?></div>
                    <div class="stat-label">Pendentes + Vencidos</div>
                    <small class="text-muted">
                        R$ <?= number_format($estatisticas['gerais']['valor_pendente'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card arrecadado">
                    <div class="stat-number text-success">R$ <?= number_format($estatisticas['gerais']['valor_arrecadado'] / 1000, 0) ?>K</div>
                    <div class="stat-label">Valor Arrecadado</div>
                    <small class="text-muted">
                        <?= $estatisticas['gerais']['valor_total'] > 0 ? round(($estatisticas['gerais']['valor_arrecadado'] / $estatisticas['gerais']['valor_total']) * 100, 1) : 0 ?>% do total
                    </small>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Gráfico de Evolução Mensal -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line"></i>
                            Evolução Mensal
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="evolucaoChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Gráfico de Status -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie"></i>
                            Status dos Boletos
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Performance por Polo -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-map-marker-alt"></i>
                            Performance por Polo
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-container">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Polo</th>
                                        <th>Boletos</th>
                                        <th>Pagos</th>
                                        <th>Arrecadado</th>
                                        <th>Taxa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estatisticas['por_polo'] as $polo): ?>
                                        <?php 
                                        $config = MoodleConfig::getConfig($polo['subdomain']);
                                        $taxa = $polo['total_boletos'] > 0 ? ($polo['boletos_pagos'] / $polo['total_boletos']) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($config['name'] ?? $polo['subdomain']) ?></strong>
                                                <br><small class="text-muted"><?= $polo['total_alunos'] ?> alunos</small>
                                            </td>
                                            <td><?= number_format($polo['total_boletos']) ?></td>
                                            <td>
                                                <span class="badge bg-success"><?= number_format($polo['boletos_pagos']) ?></span>
                                            </td>
                                            <td>
                                                <strong>R$ <?= number_format($polo['valor_arrecadado'], 2, ',', '.') ?></strong>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2"><?= round($taxa, 1) ?>%</span>
                                                    <div class="progress-bar-container flex-grow-1">
                                                        <div class="progress-bar" style="width: <?= $taxa ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Cursos -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-trophy"></i>
                            Top 10 Cursos por Arrecadação
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-container">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Curso</th>
                                        <th>Boletos</th>
                                        <th>Arrecadado</th>
                                        <th>Taxa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($estatisticas['top_cursos'], 0, 10) as $index => $curso): ?>
                                        <?php 
                                        $taxa = $curso['total_boletos'] > 0 ? ($curso['boletos_pagos'] / $curso['total_boletos']) * 100 : 0;
                                        $config = MoodleConfig::getConfig($curso['subdomain']);
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="badge bg-primary me-2"><?= $index + 1 ?>º</span>
                                                    <div>
                                                        <strong><?= htmlspecialchars(substr($curso['curso_nome'], 0, 30)) ?><?= strlen($curso['curso_nome']) > 30 ? '...' : '' ?></strong>
                                                        <br><small class="text-muted"><?= htmlspecialchars($config['name'] ?? $curso['subdomain']) ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= number_format($curso['total_boletos']) ?></td>
                                            <td>
                                                <strong>R$ <?= number_format($curso['valor_arrecadado'], 2, ',', '.') ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $taxa >= 80 ? 'success' : ($taxa >= 60 ? 'warning' : 'danger') ?>">
                                                    <?= round($taxa, 1) ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Status Diário -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-area"></i>
                            Evolução Diária de Status (Últimos 30 dias)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 400px;">
                            <canvas id="statusDiarioChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Resumo Executivo -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-file-alt"></i>
                            Resumo Executivo
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary">📊 Indicadores Principais</h6>
                                <ul class="list-unstyled">
                                    <li><strong>Taxa de Pagamento:</strong> 
                                        <?php 
                                        $taxaPagamento = $estatisticas['gerais']['total_boletos'] > 0 ? 
                                            round(($estatisticas['gerais']['boletos_pagos'] / $estatisticas['gerais']['total_boletos']) * 100, 1) : 0;
                                        ?>
                                        <span class="badge bg-<?= $taxaPagamento >= 80 ? 'success' : ($taxaPagamento >= 60 ? 'warning' : 'danger') ?>">
                                            <?= $taxaPagamento ?>%
                                        </span>
                                    </li>
                                    <li><strong>Ticket Médio:</strong> 
                                        R$ <?= $estatisticas['gerais']['total_boletos'] > 0 ? 
                                            number_format($estatisticas['gerais']['valor_total'] / $estatisticas['gerais']['total_boletos'], 2, ',', '.') : '0,00' ?>
                                    </li>
                                    <li><strong>Alunos Ativos:</strong> <?= number_format($estatisticas['gerais']['total_alunos']) ?></li>
                                    <li><strong>Cursos com Boletos:</strong> <?= number_format($estatisticas['gerais']['total_cursos']) ?></li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">🎯 Análise de Performance</h6>
                                <ul class="list-unstyled">
                                    <?php 
                                    $melhorPolo = !empty($estatisticas['por_polo']) ? $estatisticas['por_polo'][0] : null;
                                    $melhorCurso = !empty($estatisticas['top_cursos']) ? $estatisticas['top_cursos'][0] : null;
                                    ?>
                                    <?php if ($melhorPolo): ?>
                                        <li><strong>Melhor Polo:</strong> 
                                            <?php $config = MoodleConfig::getConfig($melhorPolo['subdomain']); ?>
                                            <?= htmlspecialchars($config['name'] ?? $melhorPolo['subdomain']) ?>
                                            (R$ <?= number_format($melhorPolo['valor_arrecadado'], 2, ',', '.') ?>)
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($melhorCurso): ?>
                                        <li><strong>Curso Destaque:</strong> 
                                            <?= htmlspecialchars(substr($melhorCurso['curso_nome'], 0, 40)) ?>
                                            (R$ <?= number_format($melhorCurso['valor_arrecadado'], 2, ',', '.') ?>)
                                        </li>
                                    <?php endif; ?>
                                    <li><strong>Valor Pendente:</strong> 
                                        <span class="text-warning">
                                            R$ <?= number_format($estatisticas['gerais']['valor_pendente'], 2, ',', '.') ?>
                                        </span>
                                    </li>
                                    <li><strong>Eficiência de Cobrança:</strong> 
                                        <?php 
                                        $eficiencia = $estatisticas['gerais']['valor_total'] > 0 ? 
                                            round(($estatisticas['gerais']['valor_arrecadado'] / $estatisticas['gerais']['valor_total']) * 100, 1) : 0;
                                        ?>
                                        <span class="badge bg-<?= $eficiencia >= 80 ? 'success' : ($eficiencia >= 60 ? 'warning' : 'danger') ?>">
                                            <?= $eficiencia ?>%
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        
                        <?php if ($taxaPagamento < 70): ?>
                        <div class="alert alert-warning mt-3">
                            <h6><i class="fas fa-exclamation-triangle"></i> Atenção Necessária</h6>
                            <p class="mb-0">A taxa de pagamento está abaixo do ideal (<?= $taxaPagamento ?>%). 
                            Considere revisar estratégias de cobrança e acompanhamento de boletos vencidos.</p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($estatisticas['gerais']['boletos_vencidos'] > 0): ?>
                        <div class="alert alert-danger mt-3">
                            <h6><i class="fas fa-exclamation-circle"></i> Ação Urgente</h6>
                            <p class="mb-0">Existem <?= number_format($estatisticas['gerais']['boletos_vencidos']) ?> boletos vencidos 
                            que precisam de atenção imediata para recuperação de receita.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Dados para os gráficos
        const dadosEvolucao = <?= json_encode(array_reverse($estatisticas['evolucao_mensal'])) ?>;
        const dadosStatus = {
            pago: <?= $estatisticas['gerais']['boletos_pagos'] ?>,
            pendente: <?= $estatisticas['gerais']['boletos_pendentes'] ?>,
            vencido: <?= $estatisticas['gerais']['boletos_vencidos'] ?>,
            cancelado: <?= $estatisticas['gerais']['boletos_cancelados'] ?>
        };
        const dadosStatusDiario = <?= json_encode(array_reverse($estatisticas['status_diario'])) ?>;
        
        // Configurações dos gráficos
        Chart.defaults.font.family = 'Segoe UI';
        Chart.defaults.color = '#6c757d';
        
        // Gráfico de Evolução Mensal
        const ctxEvolucao = document.getElementById('evolucaoChart').getContext('2d');
        new Chart(ctxEvolucao, {
            type: 'line',
            data: {
                labels: dadosEvolucao.map(item => {
                    const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 
                                  'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
                    return meses[item.mes - 1] + '/' + item.ano.toString().substr(2);
                }),
                datasets: [{
                    label: 'Valor Arrecadado',
                    data: dadosEvolucao.map(item => item.valor_arrecadado),
                    borderColor: '#0066cc',
                    backgroundColor: 'rgba(0, 102, 204, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Boletos Pagos',
                    data: dadosEvolucao.map(item => item.boletos_pagos),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: false,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                return 'R$ ' + value.toLocaleString('pt-BR');
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 0) {
                                    return 'Arrecadado: R$ ' + context.parsed.y.toLocaleString('pt-BR');
                                } else {
                                    return 'Boletos Pagos: ' + context.parsed.y;
                                }
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Status (Pizza)
        const ctxStatus = document.getElementById('statusChart').getContext('2d');
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['Pago', 'Pendente', 'Vencido', 'Cancelado'],
                datasets: [{
                    data: [dadosStatus.pago, dadosStatus.pendente, dadosStatus.vencido, dadosStatus.cancelado],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Status Diário
        const ctxStatusDiario = document.getElementById('statusDiarioChart').getContext('2d');
        new Chart(ctxStatusDiario, {
            type: 'bar',
            data: {
                labels: dadosStatusDiario.map(item => {
                    const data = new Date(item.data);
                    return data.getDate() + '/' + (data.getMonth() + 1);
                }),
                datasets: [{
                    label: 'Pagos',
                    data: dadosStatusDiario.map(item => item.pagos),
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    stack: 'stack1'
                }, {
                    label: 'Pendentes',
                    data: dadosStatusDiario.map(item => item.pendentes),
                    backgroundColor: 'rgba(255, 193, 7, 0.8)',
                    stack: 'stack1'
                }, {
                    label: 'Vencidos',
                    data: dadosStatusDiario.map(item => item.vencidos),
                    backgroundColor: 'rgba(220, 53, 69, 0.8)',
                    stack: 'stack1'
                }, {
                    label: 'Cancelados',
                    data: dadosStatusDiario.map(item => item.cancelados),
                    backgroundColor: 'rgba(108, 117, 125, 0.8)',
                    stack: 'stack1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
        
        // Função para atualizar datas baseado no período
        function atualizarDatas(periodo) {
            const dataInicio = document.getElementById('dataInicio');
            const dataFim = document.getElementById('dataFim');
            const hoje = new Date();
            
            switch (periodo) {
                case 'hoje':
                    const hojeStr = hoje.toISOString().split('T')[0];
                    dataInicio.value = hojeStr;
                    dataFim.value = hojeStr;
                    break;
                case 'semana_atual':
                    const segundaFeira = new Date(hoje.setDate(hoje.getDate() - hoje.getDay() + 1));
                    const domingo = new Date(hoje.setDate(hoje.getDate() - hoje.getDay() + 7));
                    dataInicio.value = segundaFeira.toISOString().split('T')[0];
                    dataFim.value = domingo.toISOString().split('T')[0];
                    break;
                case 'mes_atual':
                    const primeiroDia = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
                    const ultimoDia = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
                    dataInicio.value = primeiroDia.toISOString().split('T')[0];
                    dataFim.value = ultimoDia.toISOString().split('T')[0];
                    break;
                case 'ano_atual':
                    dataInicio.value = hoje.getFullYear() + '-01-01';
                    dataFim.value = hoje.getFullYear() + '-12-31';
                    break;
                case 'personalizado':
                    // Deixa as datas como estão para edição manual
                    break;
                default:
                    // Mês atual por padrão
                    const primeiroDiaPadrao = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
                    const ultimoDiaPadrao = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
                    dataInicio.value = primeiroDiaPadrao.toISOString().split('T')[0];
                    dataFim.value = ultimoDiaPadrao.toISOString().split('T')[0];
            }
        }
        
        // Funções de exportação
        function exportarRelatorio() {
            showToast('Gerando relatório PDF...', 'info');
            
            // Implementar exportação PDF
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'pdf');
            
            const link = document.createElement('a');
            link.href = `/admin/api/exportar-relatorio.php?${params.toString()}`;
            link.download = `relatorio_${new Date().toISOString().split('T')[0]}.pdf`;
            link.click();
            
            setTimeout(() => {
                showToast('Relatório PDF gerado!', 'success');
            }, 2000);
        }
        
        function exportarExcel() {
            showToast('Gerando planilha Excel...', 'info');
            
            // Implementar exportação Excel
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            
            const link = document.createElement('a');
            link.href = `/admin/api/exportar-relatorio.php?${params.toString()}`;
            link.download = `relatorio_${new Date().toISOString().split('T')[0]}.xlsx`;
            link.click();
            
            setTimeout(() => {
                showToast('Planilha Excel gerada!', 'success');
            }, 2000);
        }
        
        // Sistema de notificações
        function showToast(message, type = 'info') {
            const existingToasts = document.querySelectorAll('.toast-custom');
            existingToasts.forEach(toast => toast.remove());
            
            // Cria container se não existir
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
            
            // Remove automaticamente após 5 segundos
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
        
        // Auto-submit do formulário quando mudamos os filtros
        document.querySelectorAll('#filtrosForm select[name="polo"]').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filtrosForm').submit();
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
            
            .chart-container canvas {
                max-height: 100% !important;
            }
            
            .stat-card {
                transition: all 0.3s ease;
            }
            
            .stat-card:hover .stat-number {
                transform: scale(1.05);
            }
        `;
        document.head.appendChild(style);
        
        // Log de debug
        console.log('📊 Página de Relatórios carregada!');
        console.log('Dados:', {
            periodo: '<?= $periodo ?>',
            filtros: <?= json_encode($filtros) ?>,
            estatisticas: {
                total_boletos: <?= $estatisticas['gerais']['total_boletos'] ?>,
                valor_total: <?= $estatisticas['gerais']['valor_total'] ?>,
                taxa_pagamento: <?= $taxaPagamento ?>
            }
        });
    </script>
</body>
</html>