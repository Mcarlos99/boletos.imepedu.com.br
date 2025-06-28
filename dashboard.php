<?php
/**
 * Sistema de Boletos IMED - Dashboard Mobile-First ATUALIZADO
 * Arquivo: dashboard.php
 * 
 * Versão com integração completa às APIs de download e PIX
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
error_log("Dashboard: CPF: " . $_SESSION['aluno_cpf'] . ", Polo: " . ($_SESSION['subdomain'] ?? 'não definido'));

// Inicializa serviços
$alunoService = new AlunoService();
$boletoService = new BoletoService();

// Busca dados do aluno específico do polo atual
$aluno = $alunoService->buscarAlunoPorCPFESubdomain($_SESSION['aluno_cpf'], $_SESSION['subdomain']);
if (!$aluno) {
    error_log("Dashboard: Aluno não encontrado");
    session_destroy();
    header('Location: /login.php');
    exit;
}

error_log("Dashboard: Aluno encontrado - ID: {$aluno['id']}, Nome: {$aluno['nome']}");

// Busca cursos do aluno apenas do polo atual
$cursos = $alunoService->buscarCursosAlunoPorSubdomain($aluno['id'], $_SESSION['subdomain']);
error_log("Dashboard: Cursos encontrados: " . count($cursos));

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
    
    error_log("Dashboard: Total boletos encontrados: " . count($todosBoletos));
    
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
        error_log("Dashboard: Agrupamento concluído");
    }
    
} catch (Exception $e) {
    error_log("Dashboard: ERRO ao buscar boletos: " . $e->getMessage());
    $dadosBoletos = [];
}

// Configura polo
$configPolo = MoodleConfig::getConfig($_SESSION['subdomain']) ?: [];

error_log("Dashboard: Resumo final - Polo: {$_SESSION['subdomain']}, Total: " . $resumoGeral['total_boletos']);
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
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json">
    
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
            --pix-color: #32BCAD;
            
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
            cursor: pointer;
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
            cursor: pointer;
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
            background: var(--pix-color);
            color: white;
        }
        
        .btn-pix:hover, .btn-pix:active {
            background: #2a9d91;
            color: white;
        }
        
        .btn-disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
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
        
        /* Modal Bottom Sheet */
        .modal-bottom {
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
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-bottom.show {
            transform: translateY(0);
        }
        
        .modal-header-custom {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
        }
        
        .modal-handle {
            width: 40px;
            height: 4px;
            background: #ddd;
            border-radius: 2px;
            margin: 0 auto 12px auto;
        }
        
        .modal-body-custom {
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
        
        /* PIX Modal específico */
        .pix-container {
            text-align: center;
            padding: 20px 0;
        }
        
        .pix-qr-code {
            max-width: 280px;
            height: auto;
            margin: 20px auto;
            border: 4px solid #f0f0f0;
            border-radius: 12px;
        }
        
        .pix-code-input {
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
            word-break: break-all;
            line-height: 1.4;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px;
            margin: 16px 0;
        }
        
        .pix-instructions {
            background: #e7f3ff;
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
            text-align: left;
        }
        
        .pix-instructions h6 {
            color: var(--primary-color);
            margin-bottom: 12px;
        }
        
        .pix-instructions ol {
            margin: 0;
            padding-left: 20px;
        }
        
        .pix-instructions li {
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 20px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .spinner {
            width: 20px;
            height: 20px;
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
            min-width: 280px;
            max-width: 90vw;
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
            animation: slideDown 0.3s ease;
        }
        
        .toast-success { border-left: 4px solid var(--success-color); }
        .toast-error { border-left: 4px solid var(--danger-color); }
        .toast-warning { border-left: 4px solid var(--warning-color); }
        .toast-info { border-left: 4px solid var(--info-color); }
        
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            border: none;
        }
        
        .fab:hover, .fab:active {
            background: var(--secondary-color);
            color: white;
            transform: scale(0.95);
        }
        
        /* Responsividade */
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
        }
        
        @media (min-width: 1024px) {
            .main-container {
                max-width: 800px;
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
            
            .summary-card, .boleto-card, .modal-bottom {
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
    <button class="fab" onclick="mostrarAcoesRapidas()" title="Ações rápidas">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal - Menu Principal -->
    <div class="overlay" id="menuOverlay" onclick="fecharMenu()"></div>
    <div class="modal-bottom" id="menuModal">
        <div class="modal-header-custom">
            <div class="modal-handle"></div>
            <h3 class="mb-0">Menu</h3>
        </div>
        <div class="modal-body-custom">
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

    <!-- Modal - Ações Rápidas -->
    <div class="overlay" id="acoesOverlay" onclick="fecharAcoes()"></div>
    <div class="modal-bottom" id="acoesModal">
        <div class="modal-header-custom">
            <div class="modal-handle"></div>
            <h3 class="mb-0">Ações Rápidas</h3>
        </div>
        <div class="modal-body-custom">
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

    <!-- Modal - Detalhes do Boleto -->
    <div class="overlay" id="boletoOverlay" onclick="fecharDetalhes()"></div>
    <div class="modal-bottom" id="boletoModal">
        <div class="modal-header-custom">
            <div class="modal-handle"></div>
            <h3 class="mb-0">Detalhes do Boleto</h3>
        </div>
        <div class="modal-body-custom" id="boletoDetalhes">
            <div class="loading-spinner">
                <div class="spinner"></div>
                Carregando...
            </div>
        </div>
    </div>

    <!-- Modal - PIX -->
    <div class="overlay" id="pixOverlay" onclick="fecharPix()"></div>
    <div class="modal-bottom" id="pixModal">
        <div class="modal-header-custom">
            <div class="modal-handle"></div>
            <h3 class="mb-0">
                <i class="fas fa-qrcode text-success me-2"></i>
                Pagamento PIX
            </h3>
        </div>
        <div class="modal-body-custom" id="pixConteudo">
            <div class="loading-spinner">
                <div class="spinner"></div>
                Gerando código PIX...
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
        let currentBoletoId = null;
        
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Dashboard Mobile inicializado');
            
            // Registra Service Worker para PWA
            registerServiceWorker();
            
            // Configura listeners para gestos
            setupSwipeGestures();
            
            // Verifica se precisa atualizar automaticamente
            checkAutoUpdate();
            
            // Configura listeners de conectividade
            setupConnectivityListeners();
        });
        
        // Registra Service Worker
        function registerServiceWorker() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js')
                    .then(registration => {
                        console.log('Service Worker registrado:', registration);
                        
                        // Escuta mensagens do Service Worker
                        navigator.serviceWorker.addEventListener('message', event => {
                            if (event.data.type === 'SW_ACTIVATED') {
                                showToast(event.data.message, 'info');
                            }
                        });
                    })
                    .catch(error => {
                        console.log('Erro ao registrar Service Worker:', error);
                    });
            }
        }
        
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
        
        // Configuração de listeners de conectividade
        function setupConnectivityListeners() {
            window.addEventListener('online', function() {
                showToast('Conexão restaurada!', 'success');
                setTimeout(() => atualizarDados(true), 1000);
            });
            
            window.addEventListener('offline', function() {
                showToast('Você está offline', 'warning');
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
        
        // ========== FUNÇÕES DE MENU ==========
        
        function mostrarMenu() {
            document.getElementById('menuOverlay').classList.add('show');
            document.getElementById('menuModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function fecharMenu() {
            document.getElementById('menuOverlay').classList.remove('show');
            document.getElementById('menuModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        function mostrarAcoesRapidas() {
            document.getElementById('acoesOverlay').classList.add('show');
            document.getElementById('acoesModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function fecharAcoes() {
            document.getElementById('acoesOverlay').classList.remove('show');
            document.getElementById('acoesModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // ========== FUNÇÕES DE BOLETOS ==========
        
        // Download de boleto com integração à API real
        function downloadBoleto(boletoId) {
            console.log('Iniciando download do boleto:', boletoId);
            
            showToast('Preparando download...', 'info');
            
            // Cria link temporário para download
            const link = document.createElement('a');
            link.href = `/api/download-boleto.php?id=${boletoId}`;
            link.download = `Boleto_${boletoId}.pdf`;
            link.target = '_blank';
            
            // Simula clique para iniciar download
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Feedback para o usuário
            setTimeout(() => {
                showToast('Download iniciado!', 'success');
            }, 500);
            
            // Log de analytics
            logUserAction('download_boleto', { boleto_id: boletoId });
        }
        
        // Gerar PIX com integração à API real
        function mostrarPix(boletoId) {
            console.log('Gerando PIX para boleto:', boletoId);
            
            currentBoletoId = boletoId;
            
            // Mostra modal
            document.getElementById('pixOverlay').classList.add('show');
            document.getElementById('pixModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Reset conteúdo
            document.getElementById('pixConteudo').innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Gerando código PIX...
                </div>
            `;
            
            // Chama API para gerar PIX
            fetch('/api/gerar-pix.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    boleto_id: boletoId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    exibirPixGerado(data);
                    showToast('Código PIX gerado!', 'success');
                } else {
                    exibirErroPix(data.message);
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erro ao gerar PIX:', error);
                exibirErroPix('Erro de conexão. Tente novamente.');
                showToast('Erro de conexão', 'error');
            });
            
            // Log de analytics
            logUserAction('gerar_pix', { boleto_id: boletoId });
        }
        
        // Exibe PIX gerado com sucesso
        function exibirPixGerado(data) {
            const { boleto, pix, instrucoes } = data;
            
            const html = `
                <div class="pix-container">
                    <div class="mb-3">
                        <h5 class="text-primary">
                            <i class="fas fa-receipt me-2"></i>
                            Boleto #${boleto.numero}
                        </h5>
                        <p class="mb-1"><strong>Valor:</strong> ${boleto.valor_formatado}</p>
                        <p class="mb-1"><strong>Vencimento:</strong> ${boleto.vencimento_formatado}</p>
                        <p class="mb-0"><strong>Beneficiário:</strong> ${pix.beneficiario}</p>
                    </div>
                    
                    <div class="text-center mb-4">
                        <img src="${pix.qr_code_base64}" alt="QR Code PIX" class="pix-qr-code">
                        <p class="mt-2 text-muted">
                            <i class="fas fa-mobile-alt me-1"></i>
                            Escaneie com o app do seu banco
                        </p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-copy me-1"></i>
                            PIX Copia e Cola:
                        </label>
                        <div class="pix-code-input" id="pixCodigoTexto">
                            ${pix.pix_copia_cola}
                        </div>
                        <button class="btn btn-outline-primary btn-sm w-100" onclick="copiarCodigoPix()">
                            <i class="fas fa-copy me-1"></i>
                            Copiar Código PIX
                        </button>
                    </div>
                    
                    <div class="pix-instructions">
                        <h6><i class="fas fa-info-circle me-1"></i> Como pagar:</h6>
                        <ol>
                            ${instrucoes.como_pagar.map(passo => `<li>${passo}</li>`).join('')}
                        </ol>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>
                                <strong>Válido até:</strong> ${pix.validade_formatada}
                            </small>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button class="btn btn-success" onclick="compartilharPix()">
                            <i class="fas fa-share me-1"></i>
                            Compartilhar PIX
                        </button>
                        <button class="btn btn-outline-secondary" onclick="fecharPix()">
                            Fechar
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('pixConteudo').innerHTML = html;
        }
        
        // Exibe erro na geração do PIX
        function exibirErroPix(mensagem) {
            const html = `
                <div class="text-center p-4">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5>Erro ao Gerar PIX</h5>
                    <p class="text-muted mb-4">${mensagem}</p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="mostrarPix(${currentBoletoId})">
                            <i class="fas fa-redo me-1"></i>
                            Tentar Novamente
                        </button>
                        <button class="btn btn-outline-secondary" onclick="fecharPix()">
                            Fechar
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('pixConteudo').innerHTML = html;
        }
        
        // Copia código PIX para área de transferência
        function copiarCodigoPix() {
            const codigo = document.getElementById('pixCodigoTexto').textContent.trim();
            
            navigator.clipboard.writeText(codigo).then(() => {
                showToast('Código PIX copiado!', 'success');
                
                // Visual feedback
                const btn = event.target;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Copiado!';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
                
            }).catch(() => {
                // Fallback para navegadores mais antigos
                const textArea = document.createElement('textarea');
                textArea.value = codigo;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                showToast('Código PIX copiado!', 'success');
            });
        }
        
        // Compartilha PIX
        function compartilharPix() {
            if (navigator.share) {
                const codigo = document.getElementById('pixCodigoTexto').textContent.trim();
                
                navigator.share({
                    title: 'Pagamento PIX - IMED Boletos',
                    text: `Código PIX para pagamento:\n\n${codigo}`,
                }).catch(error => {
                    console.log('Erro ao compartilhar:', error);
                });
            } else {
                copiarCodigoPix();
                showToast('Código copiado! Cole onde desejar compartilhar.', 'info');
            }
        }
        
        // Fecha modal PIX
        function fecharPix() {
            document.getElementById('pixOverlay').classList.remove('show');
            document.getElementById('pixModal').classList.remove('show');
            document.body.style.overflow = '';
            currentBoletoId = null;
        }
        
        // ========== OUTRAS FUNÇÕES ==========
        
        // Mostrar detalhes do boleto
        function mostrarDetalhes(boletoId) {
            document.getElementById('boletoOverlay').classList.add('show');
            document.getElementById('boletoModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            const detalhesDiv = document.getElementById('boletoDetalhes');
            detalhesDiv.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Carregando detalhes...
                </div>
            `;
            
            // Busca detalhes via API
            fetch(`/api/boleto-detalhes.php?id=${boletoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        exibirDetalhesBoleto(data.boleto);
                    } else {
                        exibirErroDetalhes(data.message);
                    }
                })
                .catch(error => {
                    console.error('Erro ao buscar detalhes:', error);
                    exibirErroDetalhes('Erro de conexão');
                });
        }
        
        // Exibe detalhes do boleto
        function exibirDetalhesBoleto(boleto) {
            const html = `
                <div class="mb-3">
                    <h5>Informações do Boleto</h5>
                    <p><strong>Número:</strong> #${boleto.numero_boleto}</p>
                    <p><strong>Valor:</strong> R$ ${parseFloat(boleto.valor).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</p>
                    <p><strong>Vencimento:</strong> ${new Date(boleto.vencimento).toLocaleDateString('pt-BR')}</p>
                    <p><strong>Status:</strong> <span class="status-${boleto.status}">${boleto.status.charAt(0).toUpperCase() + boleto.status.slice(1)}</span></p>
                    ${boleto.descricao ? `<p><strong>Descrição:</strong> ${boleto.descricao}</p>` : ''}
                </div>
                <div class="mb-3">
                    <h5>Informações do Curso</h5>
                    <p><strong>Curso:</strong> ${boleto.curso_nome}</p>
                    <p><strong>Polo:</strong> ${boleto.subdomain.replace('.imepedu.com.br', '')}</p>
                </div>
                <div class="d-grid gap-2">
                    ${boleto.status !== 'pago' ? `
                        <button class="btn btn-primary" onclick="downloadBoleto(${boleto.id}); fecharDetalhes();">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                        <button class="btn btn-success" onclick="mostrarPix(${boleto.id}); fecharDetalhes();">
                            <i class="fas fa-qrcode"></i> Código PIX
                        </button>
                    ` : `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            Este boleto já foi pago!
                        </div>
                    `}
                    <button class="btn btn-outline-secondary" onclick="fecharDetalhes()">
                        Fechar
                    </button>
                </div>
            `;
            
            document.getElementById('boletoDetalhes').innerHTML = html;
        }
        
        // Exibe erro nos detalhes
        function exibirErroDetalhes(mensagem) {
            const html = `
                <div class="text-center p-4">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-3"></i>
                    <p>${mensagem}</p>
                    <button class="btn btn-outline-secondary" onclick="fecharDetalhes()">
                        Fechar
                    </button>
                </div>
            `;
            
            document.getElementById('boletoDetalhes').innerHTML = html;
        }
        
        function fecharDetalhes() {
            document.getElementById('boletoOverlay').classList.remove('show');
            document.getElementById('boletoModal').classList.remove('show');
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
        
        // Função para baixar todos os boletos pendentes
        function baixarTodosPendentes() {
            const pendentes = document.querySelectorAll('.boleto-card.pendente, .boleto-card.vencido').length;
            
            if (pendentes === 0) {
                showToast('Nenhum boleto pendente encontrado', 'info');
                return;
            }
            
            if (confirm(`Deseja baixar todos os ${pendentes} boletos pendentes?`)) {
                showToast('Preparando downloads...', 'info');
                
                // Coleta IDs dos boletos pendentes
                const boletos = [];
                document.querySelectorAll('.boleto-card.pendente, .boleto-card.vencido').forEach(card => {
                    const downloadBtn = card.querySelector('[onclick*="downloadBoleto"]');
                    if (downloadBtn) {
                        const match = downloadBtn.getAttribute('onclick').match(/downloadBoleto\((\d+)\)/);
                        if (match) {
                            boletos.push(match[1]);
                        }
                    }
                });
                
                // Inicia downloads com delay para não sobrecarregar
                boletos.forEach((boletoId, index) => {
                    setTimeout(() => {
                        downloadBoleto(boletoId);
                    }, index * 1000); // 1 segundo entre downloads
                });
                
                showToast(`Iniciando download de ${boletos.length} boletos...`, 'success');
            }
        }
        
        // Outras funções auxiliares
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
                    <a href="${polo.url}" class="list-group-item list-group-item-action" target="_blank">
                        <i class="fas fa-building me-2"></i>
                        ${polo.nome}
                        <small class="d-block text-muted">Acesse boletos deste polo</small>
                    </a>
                `;
            });
            html += '</div>';
            
            document.getElementById('boletoDetalhes').innerHTML = html;
            document.getElementById('boletoOverlay').classList.add('show');
            document.getElementById('boletoModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function mostrarInformacoes() {
            const info = `
                <div class="mb-3">
                    <h5>Sistema de Boletos IMED</h5>
                    <p>Versão: 2.1 PWA</p>
                    <p>Última atualização: ${new Date().toLocaleDateString('pt-BR')}</p>
                </div>
                <div class="mb-3">
                    <h6>Funcionalidades:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i> Visualização de boletos</li>
                        <li><i class="fas fa-check text-success me-2"></i> Download de PDFs</li>
                        <li><i class="fas fa-check text-success me-2"></i> Códigos PIX</li>
                        <li><i class="fas fa-check text-success me-2"></i> Sincronização automática</li>
                        <li><i class="fas fa-check text-success me-2"></i> Interface mobile-first</li>
                        <li><i class="fas fa-check text-success me-2"></i> Funcionamento offline</li>
                    </ul>
                </div>
                <div class="text-center">
                    <small class="text-muted">IMED Educação © 2024</small>
                </div>
            `;
            
            document.getElementById('boletoDetalhes').innerHTML = info;
            document.getElementById('boletoOverlay').classList.add('show');
            document.getElementById('boletoModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function compartilharApp() {
            if (navigator.share) {
                navigator.share({
                    title: 'Sistema de Boletos IMED',
                    text: 'Acesse seus boletos acadêmicos de forma fácil e rápida!',
                    url: window.location.href
                });
            } else {
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
            
            // Remove toasts existentes do mesmo tipo
            const existingToasts = container.querySelectorAll(`.toast-${type}`);
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `toast-custom toast-${type}`;
            
            const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                        type === 'success' ? 'fa-check-circle' : 
                        type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            toast.innerHTML = `
                <i class="fas ${icon}"></i>
                <span>${message}</span>
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.remove()"></button>
            `;
            
            container.appendChild(toast);
            
            // Remove automaticamente após 5 segundos
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.animation = 'slideDown 0.3s ease reverse';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            container.removeChild(toast);
                        }
                    }, 300);
                }
            }, 5000);
        }
        
        // Log de analytics/eventos do usuário
        function logUserAction(action, data = {}) {
            try {
                // Envia para analytics se configurado
                if (typeof gtag !== 'undefined') {
                    gtag('event', action, data);
                }
                
                // Log local para debug
                console.log('User Action:', action, data);
                
                // Armazena localmente para sync posterior
                const actions = JSON.parse(localStorage.getItem('userActions') || '[]');
                actions.push({
                    action,
                    data,
                    timestamp: Date.now(),
                    url: window.location.href
                });
                
                // Mantém apenas últimas 50 ações
                if (actions.length > 50) {
                    actions.splice(0, actions.length - 50);
                }
                
                localStorage.setItem('userActions', JSON.stringify(actions));
                
            } catch (error) {
                console.error('Erro ao registrar ação:', error);
            }
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
        
        // Debug e monitoramento
        function logPerformance() {
            if (performance.mark && performance.measure) {
                performance.mark('dashboard-loaded');
                
                window.addEventListener('load', () => {
                    performance.mark('dashboard-complete');
                    performance.measure('dashboard-load-time', 'dashboard-loaded', 'dashboard-complete');
                    
                    const measure = performance.getEntriesByName('dashboard-load-time')[0];
                    console.log('Dashboard load time:', measure.duration + 'ms');
                });
            }
        }
        
        // Inicializa monitoramento de performance
        logPerformance();
        
        // Log de debug final
        console.log('Dashboard Mobile carregado', {
            aluno_id: <?= $aluno['id'] ?? 'null' ?>,
            subdomain: '<?= $_SESSION['subdomain'] ?>',
            total_boletos: <?= $resumoGeral['total_boletos'] ?>,
            user_agent: navigator.userAgent,
            screen_size: `${screen.width}x${screen.height}`,
            viewport_size: `${window.innerWidth}x${window.innerHeight}`,
            is_mobile: /Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent),
            is_pwa: window.matchMedia('(display-mode: standalone)').matches,
            online: navigator.onLine,
            sw_supported: 'serviceWorker' in navigator,
            push_supported: 'PushManager' in window
        });



/**
 * CORREÇÕES JAVASCRIPT PARA DASHBOARD.PHP
 * Adicione este código no final do script do dashboard.php para corrigir erros
 */

// 1. CORREÇÃO: Global error handler
window.addEventListener('error', function(event) {
    console.error('JavaScript Error:', {
        message: event.message,
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
        error: event.error
    });
    
    // Log para analytics se disponível
    if (typeof logUserAction === 'function') {
        logUserAction('javascript_error', {
            message: event.message,
            filename: event.filename,
            line: event.lineno
        });
    }
});

// 2. CORREÇÃO: Promise rejection handler
window.addEventListener('unhandledrejection', function(event) {
    console.error('Unhandled Promise Rejection:', event.reason);
    
    // Evita que apareça no console como erro não tratado
    event.preventDefault();
    
    // Log para analytics
    if (typeof logUserAction === 'function') {
        logUserAction('promise_rejection', {
            reason: event.reason?.toString() || 'Unknown reason'
        });
    }
});

// 3. CORREÇÃO: Valida se todas as funções essenciais existem
(function validateEssentialFunctions() {
    const requiredFunctions = [
        'downloadBoleto',
        'mostrarPix', 
        'atualizarDados',
        'showToast',
        'mostrarDetalhes'
    ];
    
    const missingFunctions = requiredFunctions.filter(func => typeof window[func] !== 'function');
    
    if (missingFunctions.length > 0) {
        console.warn('Funções faltando:', missingFunctions);
        
        // Cria funções stub para evitar erros
        missingFunctions.forEach(funcName => {
            window[funcName] = function() {
                console.warn(`Função ${funcName} não implementada`);
                showToast?.(`Função ${funcName} temporariamente indisponível`, 'warning');
            };
        });
    }
})();

// 4. CORREÇÃO: Wrapper seguro para fetch
const originalFetch = window.fetch;
window.fetch = function safeFetch(url, options = {}) {
    // Timeout padrão de 10 segundos
    const timeoutPromise = new Promise((_, reject) => {
        setTimeout(() => reject(new Error('Request timeout')), 10000);
    });
    
    // Adiciona headers padrão
    const defaultOptions = {
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...options.headers
        },
        ...options
    };
    
    return Promise.race([
        originalFetch(url, defaultOptions),
        timeoutPromise
    ]).catch(error => {
        console.error('Fetch error:', url, error);
        
        // Se for erro de rede, tenta cache se disponível
        if (error.message.includes('Failed to fetch') || error.message.includes('timeout')) {
            if ('caches' in window) {
                return caches.match(url).then(cachedResponse => {
                    if (cachedResponse) {
                        console.log('Usando resposta em cache para:', url);
                        return cachedResponse;
                    }
                    throw error;
                });
            }
        }
        
        throw error;
    });
};

// 5. CORREÇÃO: Melhora a função showToast para ser mais robusta
if (typeof showToast !== 'function') {
    window.showToast = function(message, type = 'info') {
        console.log(`Toast (${type}):`, message);
        
        // Remove toasts existentes do mesmo tipo
        const existingToasts = document.querySelectorAll(`.toast-${type}`);
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
                left: 50%;
                transform: translateX(-50%);
                z-index: 2001;
                min-width: 280px;
                max-width: 90vw;
            `;
            document.body.appendChild(container);
        }
        
        const toast = document.createElement('div');
        toast.className = `toast-custom toast-${type}`;
        toast.style.cssText = `
            background: white;
            border-radius: 8px;
            padding: 12px 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideDown 0.3s ease;
            border-left: 4px solid var(--${type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info'}-color, #007bff);
        `;
        
        const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                    type === 'success' ? 'fa-check-circle' : 
                    type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
        
        toast.innerHTML = `
            <i class="fas ${icon}" style="color: var(--${type === 'error' ? 'danger' : type}-color, #007bff);"></i>
            <span style="flex: 1;">${message}</span>
            <button type="button" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #999;" onclick="this.parentElement.remove()">×</button>
        `;
        
        container.appendChild(toast);
        
        // Remove automaticamente após 5 segundos
        setTimeout(() => {
            if (toast.parentNode) {
                toast.style.animation = 'slideDown 0.3s ease reverse';
                setTimeout(() => {
                    if (toast.parentNode) {
                        container.removeChild(toast);
                    }
                }, 300);
            }
        }, 5000);
    };
}

// 6. CORREÇÃO: Adiciona styles CSS necessários se não existirem
(function addRequiredStyles() {
    const styleId = 'dashboard-fixes-styles';
    if (document.getElementById(styleId)) return;
    
    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .toast-container {
            position: fixed !important;
            top: 20px !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            z-index: 2001 !important;
        }
        
        .toast-custom {
            animation: slideDown 0.3s ease !important;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .loading-spinner {
            background: white;
            padding: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    `;
    document.head.appendChild(style);
})();

// 7. CORREÇÃO: Função de loading global
window.showLoading = function(message = 'Carregando...') {
    const existingLoading = document.getElementById('globalLoading');
    if (existingLoading) return;
    
    const overlay = document.createElement('div');
    overlay.id = 'globalLoading';
    overlay.className = 'loading-overlay';
    overlay.innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(overlay);
};

window.hideLoading = function() {
    const loading = document.getElementById('globalLoading');
    if (loading) {
        loading.remove();
    }
};

// 8. CORREÇÃO: Melhora o registro do Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js')
        .then(registration => {
            console.log('Service Worker registrado com sucesso:', registration);
            
            // Escuta atualizações
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                if (newWorker) {
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            showToast('Nova versão disponível! Atualize a página.', 'info');
                        }
                    });
                }
            });
        })
        .catch(error => {
            console.log('Erro ao registrar Service Worker:', error);
        });
        
    // Escuta mensagens do Service Worker
    navigator.serviceWorker.addEventListener('message', event => {
        const data = event.data;
        
        if (data.type === 'SW_ACTIVATED') {
            showToast(data.message, 'info');
        } else if (data.type === 'SYNC_REQUEST') {
            // Executa sincronização quando solicitada pelo SW
            if (typeof atualizarDados === 'function') {
                atualizarDados(true); // Sincronização silenciosa
            }
        }
    });
}

// 9. CORREÇÃO: Valida elementos DOM essenciais
document.addEventListener('DOMContentLoaded', function() {
    const requiredElements = [
        'toastContainer',
        'debugResultados',
        'pixOverlay',
        'boletoOverlay'
    ];
    
    requiredElements.forEach(elementId => {
        if (!document.getElementById(elementId)) {
            console.warn(`Elemento necessário não encontrado: ${elementId}`);
            
            // Cria elemento se for crítico
            if (elementId === 'toastContainer') {
                const container = document.createElement('div');
                container.id = elementId;
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
        }
    });
});

// 10. CORREÇÃO: Rate limiting para evitar spam de requisições
const rateLimiter = {
    requests: new Map(),
    
    canMakeRequest(key, limit = 5, window = 60000) { // 5 requests per minute
        const now = Date.now();
        const requests = this.requests.get(key) || [];
        
        // Remove requests antigas
        const validRequests = requests.filter(time => now - time < window);
        
        if (validRequests.length >= limit) {
            return false;
        }
        
        validRequests.push(now);
        this.requests.set(key, validRequests);
        return true;
    }
};

// 11. CORREÇÃO: Wrapper para funções de API com rate limiting
const originalDownloadBoleto = window.downloadBoleto;
if (typeof originalDownloadBoleto === 'function') {
    window.downloadBoleto = function(boletoId) {
        if (!rateLimiter.canMakeRequest(`download_${boletoId}`, 3, 30000)) {
            showToast('Muitas tentativas de download. Aguarde um momento.', 'warning');
            return;
        }
        
        return originalDownloadBoleto.call(this, boletoId);
    };
}

const originalMostrarPix = window.mostrarPix;
if (typeof originalMostrarPix === 'function') {
    window.mostrarPix = function(boletoId) {
        if (!rateLimiter.canMakeRequest(`pix_${boletoId}`, 3, 60000)) {
            showToast('Aguarde antes de gerar outro código PIX.', 'warning');
            return;
        }
        
        return originalMostrarPix.call(this, boletoId);
    };
}

const originalAtualizarDados = window.atualizarDados;
if (typeof originalAtualizarDados === 'function') {
    window.atualizarDados = function(silencioso = false) {
        if (!rateLimiter.canMakeRequest('atualizar_dados', 2, 30000)) {
            if (!silencioso) {
                showToast('Aguarde antes de sincronizar novamente.', 'warning');
            }
            return;
        }
        
        return originalAtualizarDados.call(this, silencioso);
    };
}

// 12. CORREÇÃO: Detecta e corrige problemas comuns de CSS
(function fixCommonCSSIssues() {
    // Corrige z-index conflicts
    const highZIndexElements = document.querySelectorAll('[style*="z-index"]');
    highZIndexElements.forEach(el => {
        const zIndex = parseInt(el.style.zIndex);
        if (zIndex > 2000) {
            console.warn('Elemento com z-index muito alto detectado:', el);
        }
    });
    
    // Corrige overflow hidden em body
    if (document.body.style.overflow === 'hidden' && !document.querySelector('.modal.show')) {
        console.warn('Body com overflow hidden sem modal visível');
        document.body.style.overflow = '';
    }
})();

// 13. CORREÇÃO: Detecta problemas de performance
(function performanceMonitoring() {
    if (!('performance' in window)) return;
    
    // Monitora tempo de carregamento da página
    window.addEventListener('load', () => {
        setTimeout(() => {
            const navigation = performance.getEntriesByType('navigation')[0];
            const loadTime = navigation.loadEventEnd - navigation.fetchStart;
            
            if (loadTime > 5000) { // Mais de 5 segundos
                console.warn('Página carregou lentamente:', loadTime + 'ms');
                logUserAction?.('slow_page_load', { load_time: loadTime });
            }
            
            console.log('Performance timing:', {
                'DNS': navigation.domainLookupEnd - navigation.domainLookupStart,
                'TCP': navigation.connectEnd - navigation.connectStart,
                'Request': navigation.responseStart - navigation.requestStart,
                'Response': navigation.responseEnd - navigation.responseStart,
                'DOM': navigation.domContentLoadedEventEnd - navigation.domContentLoadedEventStart,
                'Total': loadTime
            });
        }, 0);
    });
    
    // Monitora memory usage se disponível
    if ('memory' in performance) {
        setInterval(() => {
            const memory = performance.memory;
            const usedMB = Math.round(memory.usedJSHeapSize / 1048576);
            const limitMB = Math.round(memory.jsHeapSizeLimit / 1048576);
            
            if (usedMB > limitMB * 0.8) { // Mais de 80% da memória
                console.warn('Alto uso de memória:', usedMB + 'MB/' + limitMB + 'MB');
            }
        }, 30000); // Check a cada 30 segundos
    }
})();

// 14. CORREÇÃO: Fallback para localStorage se não disponível
if (!window.localStorage) {
    console.warn('localStorage não disponível, usando fallback');
    window.localStorage = {
        storage: {},
        getItem: function(key) {
            return this.storage[key] || null;
        },
        setItem: function(key, value) {
            this.storage[key] = String(value);
        },
        removeItem: function(key) {
            delete this.storage[key];
        },
        clear: function() {
            this.storage = {};
        }
    };
}

// 15. CORREÇÃO: Intercepta e melhora console.error para debugging
const originalConsoleError = console.error;
console.error = function(...args) {
    // Chama o console.error original
    originalConsoleError.apply(console, args);
    
    // Log adicional para debugging
    const errorInfo = {
        timestamp: new Date().toISOString(),
        url: window.location.href,
        userAgent: navigator.userAgent,
        args: args.map(arg => {
            if (arg instanceof Error) {
                return {
                    name: arg.name,
                    message: arg.message,
                    stack: arg.stack
                };
            }
            return arg;
        })
    };
    
    // Salva no localStorage para debug posterior
    try {
        const errors = JSON.parse(localStorage.getItem('dashboard_errors') || '[]');
        errors.push(errorInfo);
        
        // Mantém apenas os últimos 10 erros
        if (errors.length > 10) {
            errors.splice(0, errors.length - 10);
        }
        
        localStorage.setItem('dashboard_errors', JSON.stringify(errors));
    } catch (e) {
        // Ignora se localStorage falhar
    }
};

// 16. CORREÇÃO: Adiciona função de debug para desenvolvedores
window.debugDashboard = function() {
    console.group('🔧 Dashboard Debug Info');
    
    console.log('📊 Estatísticas:', {
        boletos_na_pagina: document.querySelectorAll('.boleto-card').length,
        modals_abertos: document.querySelectorAll('.modal.show').length,
        toasts_ativos: document.querySelectorAll('.toast-custom').length,
        memoria_usada: performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) + 'MB' : 'N/A'
    });
    
    console.log('🌐 Service Worker:', {
        suportado: 'serviceWorker' in navigator,
        controlador: navigator.serviceWorker?.controller?.state || 'none',
        registro: navigator.serviceWorker?.ready || 'pending'
    });
    
    console.log('💾 Storage:', {
        localStorage_disponivel: !!window.localStorage,
        sessionStorage_disponivel: !!window.sessionStorage,
        erros_salvos: JSON.parse(localStorage.getItem('dashboard_errors') || '[]').length
    });
    
    console.log('🔄 Rate Limiter:', {
        requests_ativas: Array.from(rateLimiter.requests.entries()).map(([key, requests]) => ({
            key,
            count: requests.length
        }))
    });
    
    console.groupEnd();
    
    return {
        clearErrors: () => localStorage.removeItem('dashboard_errors'),
        clearRateLimit: () => rateLimiter.requests.clear(),
        showErrors: () => JSON.parse(localStorage.getItem('dashboard_errors') || '[]'),
        forceSync: () => atualizarDados?.(false)
    };
};

// 17. CORREÇÃO: Auto-fix para problemas comuns
(function autoFix() {
    // Fix 1: Remove eventos duplicados
    const cleanupDuplicateEvents = () => {
        document.querySelectorAll('[onclick]').forEach(el => {
            const onclick = el.getAttribute('onclick');
            if (onclick && onclick.includes('undefined')) {
                console.warn('Removendo onclick inválido:', el);
                el.removeAttribute('onclick');
            }
        });
    };
    
    // Fix 2: Corrige botões sem função
    const fixBrokenButtons = () => {
        document.querySelectorAll('button[onclick], a[onclick]').forEach(el => {
            const onclick = el.getAttribute('onclick');
            if (onclick) {
                try {
                    // Testa se a função existe
                    const funcName = onclick.split('(')[0];
                    if (typeof window[funcName] !== 'function') {
                        console.warn('Função não existe:', funcName, 'no elemento:', el);
                        el.style.opacity = '0.5';
                        el.title = 'Função temporariamente indisponível';
                    }
                } catch (e) {
                    console.warn('Erro ao validar onclick:', onclick);
                }
            }
        });
    };
    
    // Fix 3: Adiciona loading state para botões importantes
    const addLoadingStates = () => {
        document.querySelectorAll('.btn-action, .btn-upload, .btn-sync').forEach(btn => {
            if (!btn.dataset.loadingEnhanced) {
                btn.dataset.loadingEnhanced = 'true';
                
                const originalClick = btn.onclick;
                if (originalClick) {
                    btn.onclick = function(e) {
                        if (this.disabled) return false;
                        
                        this.disabled = true;
                        this.style.opacity = '0.6';
                        
                        setTimeout(() => {
                            this.disabled = false;
                            this.style.opacity = '';
                        }, 2000);
                        
                        return originalClick.call(this, e);
                    };
                }
            }
        });
    };
    
    // Executa fixes
    cleanupDuplicateEvents();
    fixBrokenButtons();
    addLoadingStates();
    
    // Re-executa fixes periodicamente
    setInterval(() => {
        cleanupDuplicateEvents();
        fixBrokenButtons();
        addLoadingStates();
    }, 30000);
})();

// 18. CORREÇÃO: Adiciona teclas de atalho para debug
document.addEventListener('keydown', function(e) {
    // Ctrl+Shift+D para debug
    if (e.ctrlKey && e.shiftKey && e.key === 'D') {
        e.preventDefault();
        debugDashboard();
    }
    
    // Ctrl+Shift+R para forçar sync
    if (e.ctrlKey && e.shiftKey && e.key === 'R') {
        e.preventDefault();
        if (typeof atualizarDados === 'function') {
            atualizarDados(false);
        }
    }
    
    // Ctrl+Shift+C para limpar cache
    if (e.ctrlKey && e.shiftKey && e.key === 'C') {
        e.preventDefault();
        if ('caches' in window) {
            caches.keys().then(names => {
                names.forEach(name => caches.delete(name));
                showToast('Cache limpo! Recarregue a página.', 'success');
            });
        }
    }
});

// 19. FINAL: Log de inicialização
console.log('🚀 Dashboard Debug & Fixes carregado');
console.log('📋 Comandos disponíveis:');
console.log('  - debugDashboard(): Informações de debug');
console.log('  - Ctrl+Shift+D: Debug rápido');
console.log('  - Ctrl+Shift+R: Forçar sincronização');
console.log('  - Ctrl+Shift+C: Limpar cache');

// Marca como carregado
window.dashboardFixesLoaded = true;


        
    </script>
</body>
</html>

<?php
/**
 * Função para renderizar card de boleto com integração completa
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
    
    // Verifica se tem arquivo PDF
    $temPDF = !empty($boleto['arquivo_pdf']);
    
    ob_start();
    ?>
    <div class="boleto-card <?= $statusClass ?>" onclick="mostrarDetalhes(<?= $boleto['id'] ?>)">
        <div class="boleto-header">
            <div class="boleto-info">
                <div>
                    <div class="boleto-numero">
                        #<?= htmlspecialchars($boleto['numero_boleto']) ?>
                        <?php if ($temPDF): ?>
                            <i class="fas fa-file-pdf text-danger ms-1" title="PDF disponível"></i>
                        <?php endif; ?>
                    </div>
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
            <?php if ($temPDF): ?>
                <button class="btn-action btn-download" onclick="downloadBoleto(<?= $boleto['id'] ?>)" title="Download PDF">
                    <i class="fas fa-download"></i> PDF
                </button>
            <?php else: ?>
                <button class="btn-action btn-disabled" disabled title="PDF não disponível">
                    <i class="fas fa-file-pdf"></i> N/D
                </button>
            <?php endif; ?>
            
            <button class="btn-action btn-pix" onclick="mostrarPix(<?= $boleto['id'] ?>)" title="Gerar código PIX">
                <i class="fas fa-qrcode"></i> PIX
            </button>
        </div>
        <?php elseif ($temPDF): ?>
        <div class="boleto-actions" onclick="event.stopPropagation();">
            <button class="btn-action btn-download" onclick="downloadBoleto(<?= $boleto['id'] ?>)" title="Download comprovante">
                <i class="fas fa-download"></i> Comprovante
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
?>