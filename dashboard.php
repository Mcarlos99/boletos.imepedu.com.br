<?php
/**
 * Sistema de Boletos IMED - Dashboard Mobile-First Responsivo
 * Arquivo: dashboard.php
 * 
 * Versão otimizada para dispositivos móveis com agrupamento de boletos por período
 */

session_start();

// Habilita logs de debug
ini_set('log_errors', 1);
error_log("Dashboard Mobile: Iniciando para sessão: " . session_id());

// Verifica se usuário está logado
if (!isset($_SESSION['aluno_cpf'])) {
    error_log("Dashboard Mobile: Usuário não logado, redirecionando para login");
    header('Location: /login.php');
    exit;
}

// Inclui arquivos necessários
require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/AlunoService.php';
require_once 'src/BoletoService.php';

// Log dos dados da sessão
error_log("Dashboard Mobile: CPF: " . $_SESSION['aluno_cpf'] . ", Polo: " . ($_SESSION['subdomain'] ?? 'não definido'));

// Inicializa serviços
$alunoService = new AlunoService();
$boletoService = new BoletoService();

// Busca dados do aluno específico do polo atual
$aluno = $alunoService->buscarAlunoPorCPFESubdomain($_SESSION['aluno_cpf'], $_SESSION['subdomain']);
if (!$aluno) {
    error_log("Dashboard Mobile: Aluno não encontrado");
    session_destroy();
    header('Location: /login.php');
    exit;
}

error_log("Dashboard Mobile: Aluno encontrado - ID: {$aluno['id']}, Nome: {$aluno['nome']}");

// Busca cursos do aluno apenas do polo atual
$cursos = $alunoService->buscarCursosAlunoPorSubdomain($aluno['id'], $_SESSION['subdomain']);
error_log("Dashboard Mobile: Cursos encontrados: " . count($cursos));

// Busca TODOS os boletos do aluno no polo atual
$dadosBoletos = [];
$resumoGeral = [
    'total_boletos' => 0,
    'boletos_pagos' => 0,
    'boletos_pendentes' => 0,
    'boletos_vencidos' => 0,
    'valor_total' => 0,
    'valor_pago' => 0,
    'valor_pendente' => 0
];

try {
    $db = (new Database())->getConnection();
    
    // Busca todos os boletos do aluno no polo atual
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ? 
        AND c.subdomain = ?
        ORDER BY b.vencimento DESC, b.created_at DESC
    ");
    $stmt->execute([$aluno['id'], $_SESSION['subdomain']]);
    $todosBoletos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Dashboard Mobile: Total boletos encontrados: " . count($todosBoletos));
    
    if (!empty($todosBoletos)) {
        // Agrupa boletos por período para melhor visualização mobile
        $boletosPorPeriodo = [
            'vencidos' => [],
            'este_mes' => [],
            'proximo_mes' => [],
            'futuros' => [],
            'pagos_recentes' => []
        ];
        
        $hoje = new DateTime();
        $inicioMesAtual = new DateTime($hoje->format('Y-m-01'));
        $fimMesAtual = new DateTime($hoje->format('Y-m-t'));
        $inicioProximoMes = clone $fimMesAtual;
        $inicioProximoMes->modify('+1 day');
        $fimProximoMes = new DateTime($inicioProximoMes->format('Y-m-t'));
        
        foreach ($todosBoletos as $boleto) {
            $vencimento = new DateTime($boleto['vencimento']);
            $diasVencimento = $hoje->diff($vencimento)->format('%r%a');
            
            $boleto['dias_vencimento'] = (int)$diasVencimento;
            $boleto['esta_vencido'] = ($boleto['status'] == 'pendente' && $diasVencimento < 0);
            
            // Atualiza status se vencido
            if ($boleto['esta_vencido'] && $boleto['status'] == 'pendente') {
                $boletoService->atualizarStatusVencido($boleto['id']);
                $boleto['status'] = 'vencido';
            }
            
            // Agrupa por período
            if ($boleto['status'] == 'pago') {
                // Boletos pagos nos últimos 3 meses
                $dataLimite = clone $hoje;
                $dataLimite->modify('-3 months');
                
                if ($vencimento >= $dataLimite) {
                    $boletosPorPeriodo['pagos_recentes'][] = $boleto;
                }
            } elseif ($boleto['status'] == 'vencido' || $boleto['esta_vencido']) {
                $boletosPorPeriodo['vencidos'][] = $boleto;
            } elseif ($vencimento >= $inicioMesAtual && $vencimento <= $fimMesAtual) {
                $boletosPorPeriodo['este_mes'][] = $boleto;
            } elseif ($vencimento >= $inicioProximoMes && $vencimento <= $fimProximoMes) {
                $boletosPorPeriodo['proximo_mes'][] = $boleto;
            } else {
                $boletosPorPeriodo['futuros'][] = $boleto;
            }
            
            // Atualiza resumo geral
            $resumoGeral['total_boletos']++;
            $resumoGeral['valor_total'] += $boleto['valor'];
            
            switch ($boleto['status']) {
                case 'pago':
                    $resumoGeral['boletos_pagos']++;
                    $resumoGeral['valor_pago'] += $boleto['valor'];
                    break;
                case 'pendente':
                    $resumoGeral['boletos_pendentes']++;
                    $resumoGeral['valor_pendente'] += $boleto['valor'];
                    break;
                case 'vencido':
                    $resumoGeral['boletos_vencidos']++;
                    $resumoGeral['valor_pendente'] += $boleto['valor'];
                    break;
            }
        }
        
        $dadosBoletos = $boletosPorPeriodo;
        error_log("Dashboard Mobile: Agrupamento concluído");
    }
    
} catch (Exception $e) {
    error_log("Dashboard Mobile: ERRO ao buscar boletos: " . $e->getMessage());
    $dadosBoletos = [];
}

// Configura polo
$configPolo = MoodleConfig::getConfig($_SESSION['subdomain']) ?: [];

error_log("Dashboard Mobile: Resumo final - Polo: {$_SESSION['subdomain']}, Total: " . $resumoGeral['total_boletos']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Meus Boletos - <?= htmlspecialchars($aluno['nome']) ?></title>
    
    <!-- Meta tags para PWA -->
    <meta name="theme-color" content="#0066cc">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS Mobile-First -->
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --dark-color: #343a40;
            --light-color: #f8f9fa;
            
            /* Mobile spacing */
            --mobile-padding: 16px;
            --mobile-margin: 12px;
            --card-radius: 12px;
            --shadow-mobile: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background-color: var(--light-color);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Header mobile */
        .mobile-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 16px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-mobile);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            line-height: 1.2;
        }
        
        .user-details {
            font-size: 0.85rem;
            opacity: 0.9;
            margin: 2px 0 0 0;
        }
        
        .header-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-header {
            background: rgba(255,255,255,0.2);
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            color: white;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        
        .btn-header:hover, .btn-header:active {
            background: rgba(255,255,255,0.3);
            color: white;
            transform: scale(0.98);
        }
        
        /* Cards de resumo */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 16px;
            margin-bottom: 8px;
        }
        
        .summary-card {
            background: white;
            border-radius: var(--card-radius);
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-mobile);
            transition: transform 0.2s;
        }
        
        .summary-card:active {
            transform: scale(0.98);
        }
        
        .summary-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
            line-height: 1;
        }
        
        .summary-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        
        .summary-value {
            font-size: 0.75rem;
            color: #888;
        }
        
        .text-success { color: var(--success-color) !important; }
        .text-warning { color: #856404 !important; }
        .text-danger { color: var(--danger-color) !important; }
        .text-info { color: var(--info-color) !important; }
        
        /* Container principal */
        .main-container {
            padding: 0 16px 80px 16px;
        }
        
        /* Seções de boletos */
        .boletos-section {
            margin-bottom: 24px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 4px;
            margin-bottom: 12px;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-count {
            background: var(--primary-color);
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Cards de boletos */
        .boleto-card {
            background: white;
            border-radius: var(--card-radius);
            margin-bottom: 12px;
            box-shadow: var(--shadow-mobile);
            overflow: hidden;
            transition: all 0.2s;
            border-left: 4px solid;
        }
        
        .boleto-card:active {
            transform: scale(0.98);
        }
        
        .boleto-card.pendente { border-left-color: var(--warning-color); }
        .boleto-card.vencido { border-left-color: var(--danger-color); }
        .boleto-card.pago { border-left-color: var(--success-color); }
        
        .boleto-header {
            padding: 12px 16px 8px 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .boleto-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .boleto-numero {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--dark-color);
        }
        
        .boleto-valor {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .boleto-curso {
            font-size: 0.85rem;
            color: #666;
            line-height: 1.2;
        }
        
        .boleto-body {
            padding: 12px 16px;
        }
        
        .boleto-dates {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .vencimento-info {
            flex: 1;
        }
        
        .vencimento-data {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .vencimento-status {
            font-size: 0.75rem;
            margin-top: 2px;
        }
        
        .boleto-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        /* Ações dos boletos */
        .boleto-actions {
            display: flex;
            gap: 8px;
            padding: 0 16px 12px 16px;
        }
        
        .btn-action {
            flex: 1;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-action:active {
            transform: scale(0.95);
        }
        
        .btn-download {
            background: var(--info-color);
            color: white;
        }
        
        .btn-download:hover, .btn-download:active {
            background: #138496;
            color: white;
        }
        
        .btn-pix {
            background: #32BCAD;
            color: white;
        }
        
        .btn-pix:hover, .btn-pix:active {
            background: #2a9d91;
            color: white;
        }
        
        /* Estado vazio */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            color: #ddd;
        }
        
        .empty-state h4 {
            font-size: 1.1rem;
            margin-bottom: 8px;
            color: var(--dark-color);
        }
        
        .empty-state p {
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        /* Bottom Sheet Modal */
        .bottom-sheet {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-radius: 16px 16px 0 0;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
            transform: translateY(100%);
            transition: transform 0.3s ease;
            z-index: 2000;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .bottom-sheet.show {
            transform: translateY(0);
        }
        
        .bottom-sheet-header {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
            position: sticky;
            top: 0;
            background: white;
        }
        
        .bottom-sheet-handle {
            width: 40px;
            height: 4px;
            background: #ddd;
            border-radius: 2px;
            margin: 0 auto 12px auto;
        }
        
        .bottom-sheet-body {
            padding: 16px;
        }
        
        /* Overlay */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 56px;
            height: 56px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0,102,204,0.3);
            transition: all 0.2s;
            z-index: 1000;
        }
        
        .fab:hover, .fab:active {
            background: var(--secondary-color);
            color: white;
            transform: scale(0.95);
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 2001;
        }
        
        .toast-custom {
            background: white;
            border-radius: 8px;
            padding: 12px 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 200px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Tablet e Desktop ajustes */
        @media (min-width: 768px) {
            .main-container {
                max-width: 600px;
                margin: 0 auto;
                padding: 0 24px 80px 24px;
            }
            
            .summary-cards {
                grid-template-columns: repeat(4, 1fr);
                padding: 24px;
            }
            
            .mobile-header {
                padding: 20px 24px;
            }
            
            .fab {
                bottom: 30px;
                right: 30px;
            }
        }
        
        @media (min-width: 1024px) {
            .main-container {
                max-width: 800px;
            }
        }
        
        /* Melhorias de acessibilidade */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            :root {
                --light-color: #1a1a1a;
                --dark-color: #ffffff;
            }
            
            body {
                background-color: #1a1a1a;
                color: #ffffff;
            }
            
            .summary-card, .boleto-card, .bottom-sheet {
                background: #2d2d2d;
                color: #ffffff;
            }
            
            .boleto-header {
                border-bottom-color: #444;
            }
            
            .section-title {
                color: #ffffff;
            }
        }
        
        /* iOS Safari specific fixes */
        @supports (-webkit-touch-callout: none) {
            .mobile-header {
                padding-top: max(16px, env(safe-area-inset-top));
            }
            
            .main-container {
                padding-bottom: max(80px, env(safe-area-inset-bottom));
            }
        }
    </style>
</head>
<body>
    <!-- Header Mobile -->
    <header class="mobile-header">
        <div class="header-content">
            <div class="user-info">
                <h1 class="user-name"><?= htmlspecialchars($aluno['nome']) ?></h1>
                <div class="user-details">
                    <i class="fas fa-map-marker-alt"></i> 
                    <?= htmlspecialchars($configPolo['name'] ?? str_replace('.imepedu.com.br', '', $_SESSION['subdomain'])) ?>
                    <span class="ms-2">
                        <i class="fas fa-id-card"></i> 
                        <?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $aluno['cpf']) ?>
                    </span>
                </div>
            </div>
            <div class="header-actions">
                <button class="btn-header" onclick="atualizarDados()" id="btnSync">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <button class="btn-header" onclick="mostrarMenu()" id="btnMenu">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Cards de Resumo -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-number text-info"><?= $resumoGeral['total_boletos'] ?></div>
            <div class="summary-label">Total</div>
            <div class="summary-value">R$ <?= number_format($resumoGeral['valor_total'], 2, ',', '.') ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-number text-success"><?= $resumoGeral['boletos_pagos'] ?></div>
            <div class="summary-label">Pagos</div>
            <div class="summary-value">R$ <?= number_format($resumoGeral['valor_pago'], 2, ',', '.') ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-number text-warning"><?= $resumoGeral['boletos_pendentes'] ?></div>
            <div class="summary-label">Pendentes</div>
            <div class="summary-value">R$ <?= number_format($resumoGeral['valor_pendente'], 2, ',', '.') ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-number text-danger"><?= $resumoGeral['boletos_vencidos'] ?></div>
            <div class="summary-label">Vencidos</div>
            <div class="summary-value">Atenção</div>
        </div>
    </div>

    <!-- Container Principal -->
    <main class="main-container">
        
        <?php if (empty($dadosBoletos) || array_sum(array_map('count', $dadosBoletos)) === 0): ?>
            <!-- Estado Vazio -->
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h4>Nenhum boleto encontrado</h4>
                <p>Não foram encontrados boletos para este polo.<br>
                   Entre em contato com a secretaria se isso não estiver correto.</p>
                <button class="btn btn-primary mt-3" onclick="atualizarDados()">
                    <i class="fas fa-sync"></i> Atualizar Dados
                </button>
            </div>
        <?php else: ?>
            
            <!-- Boletos Vencidos -->
            <?php if (!empty($dadosBoletos['vencidos'])): ?>
            <section class="boletos-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-exclamation-triangle text-danger"></i>
                        Vencidos
                        <span class="section-count"><?= count($dadosBoletos['vencidos']) ?></span>
                    </h2>
                </div>
                
                <?php foreach ($dadosBoletos['vencidos'] as $boleto): ?>
                    <?= renderizarBoletoCard($boleto, 'vencido') ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
            
            <!-- Boletos deste mês -->
            <?php if (!empty($dadosBoletos['este_mes'])): ?>
            <section class="boletos-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-calendar-day text-warning"></i>
                        Este Mês
                        <span class="section-count"><?= count($dadosBoletos['este_mes']) ?></span>
                    </h2>
                </div>
                
                <?php foreach ($dadosBoletos['este_mes'] as $boleto): ?>
                    <?= renderizarBoletoCard($boleto, 'pendente') ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
            
            <!-- Próximo mês -->
            <?php if (!empty($dadosBoletos['proximo_mes'])): ?>
            <section class="boletos-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-calendar-plus text-info"></i>
                        Próximo Mês
                        <span class="section-count"><?= count($dadosBoletos['proximo_mes']) ?></span>
                    </h2>
                </div>
                
                <?php foreach ($dadosBoletos['proximo_mes'] as $boleto): ?>
                    <?= renderizarBoletoCard($boleto, 'pendente') ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
            
            <!-- Boletos futuros -->
            <?php if (!empty($dadosBoletos['futuros'])): ?>
            <section class="boletos-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-clock text-secondary"></i>
                        Futuros
                        <span class="section-count"><?= count($dadosBoletos['futuros']) ?></span>
                    </h2>
                </div>
                
                <?php foreach ($dadosBoletos['futuros'] as $boleto): ?>
                    <?= renderizarBoletoCard($boleto, 'pendente') ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
            
            <!-- Boletos pagos recentes -->
            <?php if (!empty($dadosBoletos['pagos_recentes'])): ?>
            <section class="boletos-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-check-circle text-success"></i>
                        Pagos Recentemente
                        <span class="section-count"><?= count($dadosBoletos['pagos_recentes']) ?></span>
                    </h2>
                </div>
                
                <?php foreach ($dadosBoletos['pagos_recentes'] as $boleto): ?>
                    <?= renderizarBoletoCard($boleto, 'pago') ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
            
        <?php endif; ?>
    </main>

    <!-- Floating Action Button -->
    <a href="javascript:void(0)" class="fab" onclick="mostrarAcoesRapidas()" title="Ações rápidas">
        <i class="fas fa-plus"></i>
    </a>

    <!-- Bottom Sheet - Menu -->
    <div class="overlay" id="menuOverlay" onclick="fecharMenu()"></div>
    <div class="bottom-sheet" id="menuSheet">
        <div class="bottom-sheet-header">
            <div class="bottom-sheet-handle"></div>
            <h3 class="mb-0">Menu</h3>
        </div>
        <div class="bottom-sheet-body">
            <div class="list-group list-group-flush">
                <button class="list-group-item list-group-item-action" onclick="atualizarDados()">
                    <i class="fas fa-sync text-primary me-3"></i>
                    Sincronizar Dados
                </button>
                <button class="list-group-item list-group-item-action" onclick="baixarTodosPendentes()">
                    <i class="fas fa-download text-info me-3"></i>
                    Baixar Todos Pendentes
                </button>
                <a href="mailto:<?= $configPolo['contact_email'] ?? 'suporte@imepedu.com.br' ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-envelope text-secondary me-3"></i>
                    Contatar Suporte
                </a>
                <button class="list-group-item list-group-item-action" onclick="mostrarOutrosPolos()">
                    <i class="fas fa-building text-warning me-3"></i>
                    Outros Polos
                </button>
                <button class="list-group-item list-group-item-action" onclick="mostrarInformacoes()">
                    <i class="fas fa-info-circle text-info me-3"></i>
                    Informações
                </button>
                <a href="/logout.php" class="list-group-item list-group-item-action text-danger">
                    <i class="fas fa-sign-out-alt me-3"></i>
                    Sair
                </a>
            </div>
        </div>
    </div>

    <!-- Bottom Sheet - Ações Rápidas -->
    <div class="overlay" id="acoesOverlay" onclick="fecharAcoes()"></div>
    <div class="bottom-sheet" id="acoesSheet">
        <div class="bottom-sheet-header">
            <div class="bottom-sheet-handle"></div>
            <h3 class="mb-0">Ações Rápidas</h3>
        </div>
        <div class="bottom-sheet-body">
            <div class="row g-3">
                <div class="col-6">
                    <button class="btn btn-outline-primary w-100" onclick="atualizarDados(); fecharAcoes();">
                        <i class="fas fa-sync d-block mb-2"></i>
                        <small>Atualizar</small>
                    </button>
                </div>
                <div class="col-6">
                    <button class="btn btn-outline-info w-100" onclick="baixarTodosPendentes(); fecharAcoes();">
                        <i class="fas fa-download d-block mb-2"></i>
                        <small>Baixar Todos</small>
                    </button>
                </div>
                <div class="col-6">
                    <a href="mailto:<?= $configPolo['contact_email'] ?? 'suporte@imepedu.com.br' ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-envelope d-block mb-2"></i>
                        <small>Suporte</small>
                    </a>
                </div>
                <div class="col-6">
                    <button class="btn btn-outline-warning w-100" onclick="compartilharApp(); fecharAcoes();">
                        <i class="fas fa-share d-block mb-2"></i>
                        <small>Compartilhar</small>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Sheet - Detalhes do Boleto -->
    <div class="overlay" id="boletoOverlay" onclick="fecharDetalhes()"></div>
    <div class="bottom-sheet" id="boletoSheet">
        <div class="bottom-sheet-header">
            <div class="bottom-sheet-handle"></div>
            <h3 class="mb-0">Detalhes do Boleto</h3>
        </div>
        <div class="bottom-sheet-body" id="boletoDetalhes">
            <div class="loading-spinner">
                <div class="spinner"></div>
                Carregando...
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Variáveis globais
        let isUpdating = false;
        let swipeStartY = 0;
        let swipeStartTime = 0;
        
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Dashboard Mobile inicializado');
            
            // Configura listeners para gestos
            setupSwipeGestures();
            
            // Configura PWA
            setupPWA();
            
            // Verifica se precisa atualizar automaticamente
            checkAutoUpdate();
        });
        
        // Configuração de gestos de deslizar
        function setupSwipeGestures() {
            document.addEventListener('touchstart', function(e) {
                swipeStartY = e.touches[0].clientY;
                swipeStartTime = Date.now();
            }, { passive: true });
            
            document.addEventListener('touchend', function(e) {
                const swipeEndY = e.changedTouches[0].clientY;
                const swipeTime = Date.now() - swipeStartTime;
                const swipeDistance = swipeStartY - swipeEndY;
                
                // Pull to refresh (deslizar para baixo no topo)
                if (window.scrollY === 0 && swipeDistance < -100 && swipeTime < 500) {
                    atualizarDados();
                }
            }, { passive: true });
        }
        
        // Configuração PWA
        function setupPWA() {
            // Service Worker
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js').catch(function(error) {
                    console.log('Service Worker registration failed:', error);
                });
            }
            
            // Add to Home Screen
            let deferredPrompt;
            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                deferredPrompt = e;
                
                // Mostra opção de instalar após 30 segundos
                setTimeout(() => {
                    if (deferredPrompt) {
                        showInstallPrompt();
                    }
                }, 30000);
            });
        }
        
        // Verifica se precisa atualizar automaticamente
        function checkAutoUpdate() {
            const lastUpdate = localStorage.getItem('lastUpdate');
            const now = Date.now();
            
            // Atualiza automaticamente se passou mais de 30 minutos
            if (!lastUpdate || (now - parseInt(lastUpdate)) > 30 * 60 * 1000) {
                setTimeout(() => {
                    atualizarDados(true); // true = silencioso
                }, 2000);
            }
        }
        
        // Funções de menu
        function mostrarMenu() {
            document.getElementById('menuOverlay').classList.add('show');
            document.getElementById('menuSheet').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function fecharMenu() {
            document.getElementById('menuOverlay').classList.remove('show');
            document.getElementById('menuSheet').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        function mostrarAcoesRapidas() {
            document.getElementById('acoesOverlay').classList.add('show');
            document.getElementById('acoesSheet').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function fecharAcoes() {
            document.getElementById('acoesOverlay').classList.remove('show');
            document.getElementById('acoesSheet').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Função de atualização de dados
        function atualizarDados(silencioso = false) {
            if (isUpdating) return;
            
            isUpdating = true;
            const btnSync = document.getElementById('btnSync');
            const originalIcon = btnSync.innerHTML;
            
            btnSync.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btnSync.disabled = true;
            
            if (!silencioso) {
                showToast('Sincronizando dados...', 'info');
            }
            
            fetch('/api/atualizar_dados.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    localStorage.setItem('lastUpdate', Date.now().toString());
                    
                    if (!silencioso) {
                        showToast('Dados atualizados!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    }
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('Erro de conexão', 'error');
            })
            .finally(() => {
                isUpdating = false;
                btnSync.innerHTML = originalIcon;
                btnSync.disabled = false;
            });
        }
        
        // Função para baixar boleto
        function downloadBoleto(boletoId) {
            showToast('Preparando download...', 'info');
            
            // Simula download - implementar com backend real
            setTimeout(() => {
                showToast('PDF será implementado em breve', 'warning');
            }, 1000);
        }
        
        // Função para mostrar código PIX
        function mostrarPix(boletoId) {
            showToast('Código PIX será implementado em breve', 'info');
        }
        
        // Função para mostrar detalhes do boleto
        function mostrarDetalhes(boletoId) {
            document.getElementById('boletoOverlay').classList.add('show');
            document.getElementById('boletoSheet').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            const detalhesDiv = document.getElementById('boletoDetalhes');
            detalhesDiv.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Carregando detalhes...
                </div>
            `;
            
            // Simula busca de detalhes
            setTimeout(() => {
                detalhesDiv.innerHTML = `
                    <div class="mb-3">
                        <h5>Informações do Boleto</h5>
                        <p><strong>Número:</strong> #${boletoId}</p>
                        <p><strong>Valor:</strong> R$ 150,00</p>
                        <p><strong>Vencimento:</strong> 15/01/2024</p>
                        <p><strong>Status:</strong> <span class="status-pendente">Pendente</span></p>
                    </div>
                    <div class="mb-3">
                        <h5>Informações do Curso</h5>
                        <p><strong>Curso:</strong> Técnico em Enfermagem</p>
                        <p><strong>Polo:</strong> ${document.querySelector('.user-details').textContent.split('•')[0].trim()}</p>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="downloadBoleto(${boletoId})">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                        <button class="btn btn-success" onclick="mostrarPix(${boletoId})">
                            <i class="fas fa-qrcode"></i> Código PIX
                        </button>
                    </div>
                `;
            }, 1000);
        }
        
        function fecharDetalhes() {
            document.getElementById('boletoOverlay').classList.remove('show');
            document.getElementById('boletoSheet').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Função para baixar todos os boletos pendentes
        function baixarTodosPendentes() {
            const pendentes = document.querySelectorAll('.boleto-card.pendente, .boleto-card.vencido').length;
            
            if (pendentes === 0) {
                showToast('Nenhum boleto pendente encontrado', 'info');
                return;
            }
            
            if (confirm(`Deseja baixar todos os ${pendentes} boletos pendentes?`)) {
                showToast('Preparando downloads...', 'info');
                
                setTimeout(() => {
                    showToast('Download em lote será implementado em breve', 'warning');
                }, 1000);
            }
        }
        
        // Função para mostrar outros polos
        function mostrarOutrosPolos() {
            const polos = [
                { nome: 'Tucuruí', url: 'https://tucurui.imepedu.com.br/boletos' },
                { nome: 'Breu Branco', url: 'https://breubranco.imepedu.com.br/boletos' },
                { nome: 'Moju', url: 'https://moju.imepedu.com.br/boletos' },
                { nome: 'Igarapé-Miri', url: 'https://igarape.imepedu.com.br/boletos' }
            ];
            
            let html = '<div class="list-group">';
            polos.forEach(polo => {
                html += `
                    <a href="${polo.url}" class="list-group-item list-group-item-action">
                        <i class="fas fa-building me-2"></i>
                        ${polo.nome}
                        <small class="d-block text-muted">Acesse boletos deste polo</small>
                    </a>
                `;
            });
            html += '</div>';
            
            document.getElementById('boletoDetalhes').innerHTML = html;
            document.getElementById('boletoOverlay').classList.add('show');
            document.getElementById('boletoSheet').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // Função para mostrar informações
        function mostrarInformacoes() {
            const info = `
                <div class="mb-3">
                    <h5>Sistema de Boletos IMED</h5>
                    <p>Versão: 2.0 Mobile</p>
                    <p>Última atualização: ${new Date().toLocaleDateString('pt-BR')}</p>
                </div>
                <div class="mb-3">
                    <h6>Funcionalidades:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i> Visualização de boletos</li>
                        <li><i class="fas fa-check text-success me-2"></i> Sincronização automática</li>
                        <li><i class="fas fa-check text-success me-2"></i> Interface mobile-first</li>
                        <li><i class="fas fa-clock text-warning me-2"></i> Download de PDFs</li>
                        <li><i class="fas fa-clock text-warning me-2"></i> Códigos PIX</li>
                    </ul>
                </div>
                <div class="text-center">
                    <small class="text-muted">IMED Educação © 2024</small>
                </div>
            `;
            
            document.getElementById('boletoDetalhes').innerHTML = info;
            document.getElementById('boletoOverlay').classList.add('show');
            document.getElementById('boletoSheet').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // Função para compartilhar app
        function compartilharApp() {
            if (navigator.share) {
                navigator.share({
                    title: 'Sistema de Boletos IMED',
                    text: 'Acesse seus boletos acadêmicos de forma fácil e rápida!',
                    url: window.location.href
                });
            } else {
                // Fallback para browsers que não suportam Web Share API
                const url = window.location.href;
                navigator.clipboard.writeText(url).then(() => {
                    showToast('Link copiado para área de transferência!', 'success');
                }).catch(() => {
                    showToast('Não foi possível compartilhar', 'error');
                });
            }
        }
        
        // Sistema de notificações toast
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = `toast-custom alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'}`;
            
            const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                        type === 'success' ? 'fa-check-circle' : 
                        type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            toast.innerHTML = `
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            `;
            
            container.appendChild(toast);
            
            // Remove automaticamente após 4 segundos
            setTimeout(() => {
                toast.style.animation = 'slideDown 0.3s ease reverse';
                setTimeout(() => {
                    if (toast.parentNode) {
                        container.removeChild(toast);
                    }
                }, 300);
            }, 4000);
        }
        
        // Função para mostrar prompt de instalação
        function showInstallPrompt() {
            if (localStorage.getItem('installPromptShown')) return;
            
            const installHtml = `
                <div class="text-center">
                    <i class="fas fa-mobile-alt fa-3x text-primary mb-3"></i>
                    <h5>Instalar App</h5>
                    <p>Adicione o Sistema de Boletos à sua tela inicial para acesso rápido!</p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="installApp()">
                            <i class="fas fa-download"></i> Instalar
                        </button>
                        <button class="btn btn-outline-secondary" onclick="dismissInstall()">
                            Agora não
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('boletoDetalhes').innerHTML = installHtml;
            document.getElementById('boletoOverlay').classList.add('show');
            document.getElementById('boletoSheet').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            localStorage.setItem('installPromptShown', 'true');
        }
        
        // Função para instalar app
        function installApp() {
            if (window.deferredPrompt) {
                window.deferredPrompt.prompt();
                window.deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        showToast('App instalado com sucesso!', 'success');
                    }
                    window.deferredPrompt = null;
                });
            }
            fecharDetalhes();
        }
        
        // Função para dispensar instalação
        function dismissInstall() {
            fecharDetalhes();
        }
        
        // Função utilitária para formatação de moeda
        function formatMoney(value) {
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            }).format(value);
        }
        
        // Função utilitária para formatação de data
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('pt-BR');
        }
        
        // Detecta se está offline
        window.addEventListener('online', function() {
            showToast('Conexão restaurada!', 'success');
            // Tenta sincronizar dados quando volta online
            setTimeout(() => atualizarDados(true), 1000);
        });
        
        window.addEventListener('offline', function() {
            showToast('Você está offline', 'warning');
        });
        
        // Log de debug
        console.log('Dashboard Mobile carregado', {
            aluno_id: <?= $aluno['id'] ?? 'null' ?>,
            subdomain: '<?= $_SESSION['subdomain'] ?>',
            total_boletos: <?= $resumoGeral['total_boletos'] ?>,
            user_agent: navigator.userAgent,
            screen_size: `${screen.width}x${screen.height}`,
            viewport_size: `${window.innerWidth}x${window.innerHeight}`,
            is_mobile: /Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
        });
    </script>
</body>
</html>

<?php
/**
 * Função para renderizar card de boleto
 */
function renderizarBoletoCard($boleto, $statusClass) {
    $vencimento = new DateTime($boleto['vencimento']);
    $hoje = new DateTime();
    $diasVencimento = $hoje->diff($vencimento)->format('%r%a');
    
    // Determina a mensagem de status do vencimento
    $statusVencimento = '';
    $statusClass2 = '';
    
    if ($boleto['status'] == 'pago') {
        $statusVencimento = 'Pago em ' . ($boleto['data_pagamento'] ? date('d/m/Y', strtotime($boleto['data_pagamento'])) : date('d/m/Y', strtotime($boleto['vencimento'])));
        $statusClass2 = 'text-success';
    } elseif ($diasVencimento < 0) {
        $statusVencimento = abs($diasVencimento) . ' dias em atraso';
        $statusClass2 = 'text-danger';
    } elseif ($diasVencimento == 0) {
        $statusVencimento = 'Vence hoje!';
        $statusClass2 = 'text-warning';
    } elseif ($diasVencimento <= 7) {
        $statusVencimento = 'Vence em ' . $diasVencimento . ' dias';
        $statusClass2 = 'text-warning';
    } else {
        $statusVencimento = 'Vence em ' . $diasVencimento . ' dias';
        $statusClass2 = 'text-muted';
    }
    
    $valorFormatado = 'R$ ' . number_format($boleto['valor'], 2, ',', '.');
    $dataVencimento = $vencimento->format('d/m/Y');
    
    ob_start();
    ?>
    <div class="boleto-card <?= $statusClass ?>" onclick="mostrarDetalhes(<?= $boleto['id'] ?>)">
        <div class="boleto-header">
            <div class="boleto-info">
                <div>
                    <div class="boleto-numero">#<?= htmlspecialchars($boleto['numero_boleto']) ?></div>
                    <div class="boleto-curso"><?= htmlspecialchars($boleto['curso_nome']) ?></div>
                </div>
                <div class="boleto-valor"><?= $valorFormatado ?></div>
            </div>
        </div>
        
        <div class="boleto-body">
            <div class="boleto-dates">
                <div class="vencimento-info">
                    <div class="vencimento-data">
                        <i class="fas fa-calendar"></i> <?= $dataVencimento ?>
                    </div>
                    <div class="vencimento-status <?= $statusClass2 ?>">
                        <?= $statusVencimento ?>
                    </div>
                </div>
                <div class="boleto-status status-<?= $boleto['status'] ?>">
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
                </div>
            </div>
        </div>
        
        <?php if ($boleto['status'] != 'pago'): ?>
        <div class="boleto-actions" onclick="event.stopPropagation();">
            <button class="btn-action btn-download" onclick="downloadBoleto(<?= $boleto['id'] ?>)">
                <i class="fas fa-download"></i> PDF
            </button>
            <button class="btn-action btn-pix" onclick="mostrarPix(<?= $boleto['id'] ?>)">
                <i class="fas fa-qrcode"></i> PIX
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
?>