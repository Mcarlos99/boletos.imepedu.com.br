<?php
/**
 * Sistema de Boletos IMEPEDU - Dashboard com Desconto PIX Personalizado
 * Arquivo: dashboard.php - PARTE 1: HTML Head e Header
 */

session_start();
ini_set('log_errors', 1);
error_log("Dashboard: Iniciando para sessão: " . session_id());

if (!isset($_SESSION['aluno_cpf'])) {
    error_log("Dashboard: Usuário não logado, redirecionando para login");
    header('Location: /login.php');
    exit;
}

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/AlunoService.php';
require_once 'src/BoletoService.php';

error_log("Dashboard: CPF: " . $_SESSION['aluno_cpf'] . ", Polo: " . ($_SESSION['subdomain'] ?? 'não definido'));

$alunoService = new AlunoService();
$boletoService = new BoletoService();

$aluno = $alunoService->buscarAlunoPorCPFESubdomain($_SESSION['aluno_cpf'], $_SESSION['subdomain']);
if (!$aluno) {
    error_log("Dashboard: Aluno não encontrado");
    session_destroy();
    header('Location: /login.php');
    exit;
}

error_log("Dashboard: Aluno encontrado - ID: {$aluno['id']}, Nome: {$aluno['nome']}");

// 🔧 ADICIONE AQUI - CORREÇÃO DO ÚLTIMO ACESSO
try {
    // 1. Atualiza último acesso na tabela alunos
    $alunoService->atualizarUltimoAcesso($aluno['id']);
    
    // 2. Registra log de acesso ao dashboard
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("
        INSERT INTO logs (tipo, descricao, ip_address, user_agent, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        'dashboard_access',
        "Acesso ao dashboard - Aluno ID: {$aluno['id']}, Nome: {$aluno['nome']}",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
    ]);
    
    error_log("✅ Dashboard: Último acesso atualizado para aluno ID: {$aluno['id']} - {$aluno['nome']}");
    
} catch (Exception $e) {
    error_log("⚠️ Dashboard: Erro ao atualizar último acesso: " . $e->getMessage());
    // Não falha o dashboard por causa disso
}
// 🔧 FIM DA CORREÇÃO

$cursos = $alunoService->buscarCursosAlunoPorSubdomain($aluno['id'], $_SESSION['subdomain']);
error_log("Dashboard: Cursos encontrados: " . count($cursos));

$dadosBoletos = [];
$resumoGeral = [
    'total_boletos' => 0,
    'boletos_pagos' => 0,
    'boletos_pendentes' => 0,
    'boletos_vencidos' => 0,
    'valor_total' => 0,
    'valor_pago' => 0,
    'valor_pendente' => 0,
    'economia_potencial_pix' => 0,
    'boletos_com_desconto' => 0
];

// [AQUI VEM O CÓDIGO PHP DE PROCESSAMENTO DOS BOLETOS - será mantido como está]

try {
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain,
               b.pix_desconto_disponivel,
               b.pix_desconto_usado,
               b.pix_valor_desconto,
               b.pix_valor_minimo
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ? 
        AND c.subdomain = ?
        ORDER BY b.vencimento ASC, b.created_at DESC
    ");
    $stmt->execute([$aluno['id'], $_SESSION['subdomain']]);
    $todosBoletos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Dashboard: Total boletos encontrados: " . count($todosBoletos));
    
    if (!empty($todosBoletos)) {
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
            
            // Calcula economia potencial PIX personalizada
            $boleto['economia_pix'] = 0;
            $boleto['pode_usar_desconto'] = false;
            $boleto['valor_final_pix'] = $boleto['valor'];

            // 🔧 CORREÇÃO: Verificação mais precisa do vencimento
            $dataVencimento = new DateTime($boleto['vencimento']);
            $agora = new DateTime();

            // Define o final do dia de vencimento (23:59:59)
            $fimDiaVencimento = clone $dataVencimento;
            $fimDiaVencimento->setTime(23, 59, 59);

            // Só considera vencido DEPOIS do final do dia de vencimento
            $venceuCompletamente = ($agora > $fimDiaVencimento);

            if ($boleto['pix_desconto_disponivel'] && 
                !$boleto['pix_desconto_usado'] && 
                $boleto['status'] !== 'pago' && 
                !$venceuCompletamente && 
                $boleto['pix_valor_desconto'] > 0) {
                
                $valorMinimo = $boleto['pix_valor_minimo'] ?? 0;
                if ($valorMinimo == 0 || $boleto['valor'] >= $valorMinimo) {
                    $valorDesconto = (float)$boleto['pix_valor_desconto'];
                    
                    $valorFinal = $boleto['valor'] - $valorDesconto;
                    if ($valorFinal < 10.00) {
                        $valorDesconto = $boleto['valor'] - 10.00;
                        $valorFinal = 10.00;
                    }
                    
                    if ($valorDesconto > 0) {
                        $boleto['economia_pix'] = $valorDesconto;
                        $boleto['valor_final_pix'] = $valorFinal;
                        $boleto['pode_usar_desconto'] = true;
                        $resumoGeral['economia_potencial_pix'] += $valorDesconto;
                        $resumoGeral['boletos_com_desconto']++;
                    }
                }
            }
            
            if ($boleto['esta_vencido'] && $boleto['status'] == 'pendente') {
                $boletoService->atualizarStatusVencido($boleto['id']);
                $boleto['status'] = 'vencido';
            }
            
            if ($boleto['status'] == 'pago') {
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

$configPolo = MoodleConfig::getConfig($_SESSION['subdomain']) ?: [];
error_log("Dashboard: Resumo final - Polo: {$_SESSION['subdomain']}, Total: " . $resumoGeral['total_boletos']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Meus Boletos - <?= htmlspecialchars($aluno['nome']) ?></title>
    
    <meta name="theme-color" content="#0066cc">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    
    <link rel="manifest" href="/manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
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
            --pix-discount-color: #28a745;
            --mobile-padding: 16px;
            --mobile-margin: 12px;
            --card-radius: 12px;
            --shadow-mobile: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        * { box-sizing: border-box; }
        
        body {
            background-color: var(--light-color);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }
        
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
        
        .user-info { flex: 1; }
        
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
        
        /* Novo botão para documentos no header */
        .btn-documentos {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            padding: 6px 12px;
            color: white;
            font-size: 0.8rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .btn-documentos:hover, .btn-documentos:active {
            background: rgba(255,255,255,0.3);
            color: white;
            transform: scale(0.98);
        }
        
        .header-quick-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        
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
        
        .summary-card:active { transform: scale(0.98); }
        
        .summary-card.pix-discount {
            background: linear-gradient(135deg, #e8f5e8, #f0f8f0);
            border: 2px solid var(--pix-discount-color);
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
        .text-pix { color: var(--pix-discount-color) !important; }
        
        .pix-economy-banner {
            background: linear-gradient(135deg, var(--pix-discount-color), #1e7e34);
            color: white;
            margin: 0 16px 16px 16px;
            padding: 16px;
            border-radius: var(--card-radius);
            text-align: center;
            box-shadow: var(--shadow-mobile);
        }
        
        .pix-economy-banner h6 {
            margin: 0 0 8px 0;
            font-weight: 600;
        }
        
        .pix-economy-banner .economy-amount {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 8px 0;
        }
        
        .pix-economy-banner small { opacity: 0.9; }
        
        .main-container { padding: 0 16px 80px 16px; }
        
        .boletos-section { margin-bottom: 24px; }
        
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

        /* Estilos para documentos */
        .documentos-progress {
            background: white;
            border-radius: var(--card-radius);
            padding: 12px 16px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-mobile);
            display: none;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .progress-label {
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.85rem;
        }
        
        .progress-value {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 0.85rem;
        }
        
        .progress-custom {
            height: 6px;
            background-color: #e9ecef;
            border-radius: 3px;
            margin-bottom: 8px;
        }
        
        .progress-bar {
            height: 100%;
            background-color: var(--success-color);
            border-radius: 3px;
            transition: width 0.3s;
        }
        
        .progress-details {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
        }
        
        /* Estilos dos boletos */
        .boleto-card {
            background: white;
            border-radius: var(--card-radius);
            margin-bottom: 12px;
            box-shadow: var(--shadow-mobile);
            overflow: hidden;
            transition: all 0.2s;
            border-left: 4px solid;
            position: relative;
        }
        
        .boleto-card:active { transform: scale(0.98); }
        
        .boleto-card.pendente { border-left-color: var(--warning-color); }
        .boleto-card.vencido { border-left-color: var(--danger-color); }
        .boleto-card.pago { border-left-color: var(--success-color); }
        
        .boleto-card.com-desconto-pix::after {
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--pix-discount-color);
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 4px 6px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
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
        
        .boleto-valor-original {
            text-decoration: line-through;
            color: #999;
            font-size: 0.9rem;
            margin-right: 8px;
        }
        
        .boleto-valor-desconto {
            color: var(--pix-discount-color);
            font-weight: 700;
        }
        
        .boleto-curso {
            font-size: 0.85rem;
            color: #666;
            line-height: 1.2;
        }
        
        .boleto-body { padding: 12px 16px; }
        
        .boleto-dates {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .vencimento-info { flex: 1; }
        
        .vencimento-data {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .vencimento-status {
            font-size: 0.75rem;
            margin-top: 2px;
        }
        
        .desconto-info {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid var(--pix-discount-color);
            border-radius: 6px;
            padding: 8px;
            margin: 8px 0;
            font-size: 0.8rem;
        }
        
        .desconto-info .desconto-valor {
            font-weight: 700;
            color: var(--pix-discount-color);
        }
        
        .desconto-info .valor-minimo {
            font-size: 0.75rem;
            color: #666;
            margin-top: 4px;
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
        
        .btn-action:active { transform: scale(0.95); }
        
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
            position: relative;
        }
        
        .btn-pix.com-desconto {
            background: var(--pix-discount-color);
        }
        
        .btn-pix:hover, .btn-pix:active {
            background: #2a9d91;
            color: white;
        }
        
        .btn-pix.com-desconto:hover, .btn-pix.com-desconto:active {
            background: #1e7e34;
            color: white;
        }
        
        .btn-disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
        }
        
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
            
            .mobile-header { padding: 20px 24px; }
            
            .pix-economy-banner { margin: 0 24px 16px 24px; }
        }
        
        @media (min-width: 1024px) {
            .main-container { max-width: 800px; }
        }
    </style>
</head>
<body>
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
        
        <!-- Nova seção para ações rápidas -->
        <div class="header-quick-actions">
            <button class="btn-documentos" onclick="mostrarDocumentos()" id="btnDocumentos">
                <i class="fas fa-folder-open"></i>
                <span>Meus Documentos</span>
                <span class="badge bg-danger ms-1" id="documentosCount" style="display: none;">0</span>
            </button>
        </div>
        
        <!-- Progress bar para documentos (aparece quando carregado) -->
        <div class="documentos-progress" id="documentosProgress">
            <div class="progress-info">
                <span class="progress-label">Documentos Enviados</span>
                <span class="progress-value" id="progressText">0 de 0</span>
            </div>
            <div class="progress-custom">
                <div class="progress-bar" id="progressBar" style="width: 0%"></div>
            </div>
            <div class="progress-details">
                <small class="text-success" id="aprovadosText">0 aprovados</small>
                <small class="text-warning" id="pendentesText">0 pendentes</small>
                <small class="text-danger" id="rejeitadosText">0 rejeitados</small>
            </div>
        </div>
    </header>

    <!-- PARTE 2 -->
     <!-- Resumo/Estatísticas dos Boletos -->
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
        
        <div class="summary-card pix-discount">
            <div class="summary-number text-pix"><?= $resumoGeral['boletos_com_desconto'] ?></div>
            <div class="summary-label">Com Desconto PIX</div>
            <div class="summary-value">Economia: R$ <?= number_format($resumoGeral['economia_potencial_pix'], 2, ',', '.') ?></div>
        </div>
    </div>

    <!-- Banner de Economia PIX -->
    <?php if ($resumoGeral['economia_potencial_pix'] > 0): ?>
    <div class="pix-economy-banner">
        <h6><i class="fas fa-gift me-2"></i>Economia PIX Personalizada Disponível!</h6>
        <div class="economy-amount">R$ <?= number_format($resumoGeral['economia_potencial_pix'], 2, ',', '.') ?></div>
        <small>Pague <?= $resumoGeral['boletos_com_desconto'] ?> boleto(s) via PIX e economize com desconto personalizado!</small>
    </div>
    <?php endif; ?>

    <!-- Conteúdo Principal dos Boletos -->
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
            
            <!-- Seção: Boletos Vencidos -->
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
            
            <!-- Seção: Boletos Este Mês -->
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
            
            <!-- Seção: Boletos Próximo Mês -->
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
            
            <!-- Seção: Boletos Futuros -->
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
            
            <!-- Seção: Boletos Pagos Recentemente -->
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

    <!-- Botão Flutuante de Ações -->
    <button class="fab" onclick="mostrarAcoesRapidas()" title="Ações rápidas">
        <i class="fas fa-plus"></i>
    </button>
    <!-- PARTE 3 -->
     <!-- Overlay para Modais -->
    <div class="overlay" id="modalOverlay" onclick="fecharTodosModais()"></div>

    <!-- Modal: Menu Principal -->
    <div class="modal-bottom" id="menuModal">
        <div class="modal-header-custom">
            <div class="modal-handle"></div>
            <h3 class="mb-0">Menu</h3>
        </div>
        <div class="modal-body-custom">
            <div class="list-group list-group-flush">
                <button class="list-group-item list-group-item-action" onclick="atualizarDados(); fecharMenu();">
                    <i class="fas fa-sync text-primary me-3"></i>
                    Sincronizar Dados
                </button>
                <button class="list-group-item list-group-item-action" onclick="mostrarDocumentos(); fecharMenu();">
                    <i class="fas fa-folder-open text-info me-3"></i>
                    Meus Documentos
                    <span class="badge bg-danger ms-auto" id="menuDocumentosCount" style="display: none;">0</span>
                </button>
                <button class="list-group-item list-group-item-action" onclick="baixarTodosPendentes(); fecharMenu();">
                    <i class="fas fa-download text-info me-3"></i>
                    Baixar Todos Pendentes
                </button>
                <a href="mailto:<?= $configPolo['contact_email'] ?? 'suporte@imepedu.com.br' ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-envelope text-secondary me-3"></i>
                    Contatar Suporte
                </a>
                <button class="list-group-item list-group-item-action" onclick="mostrarInformacoes(); fecharMenu();">
                    <i class="fas fa-info-circle text-info me-3"></i>
                    Informações
                </button>
                <button class="list-group-item list-group-item-action text-danger" onclick="logoutLimpo(); fecharMenu();">
                    <i class="fas fa-sign-out-alt me-3"></i>
                    Sair
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Ações Rápidas -->
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
                    <button class="btn btn-outline-info w-100" onclick="mostrarDocumentos(); fecharAcoes();">
                        <i class="fas fa-folder-open d-block mb-2"></i>
                        <small>Documentos</small>
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
                <div class="col-6">
                    <button class="btn btn-outline-danger w-100" onclick="logoutLimpo(); fecharAcoes();">
                        <i class="fas fa-sign-out-alt d-block mb-2"></i>
                        <small>Sair</small>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Detalhes do Boleto -->
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

    <!-- Modal: Pagamento PIX -->
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
                Gerando código PIX com desconto personalizado...
            </div>
        </div>
    </div>

    <!-- Modal: Meus Documentos -->
    <div class="modal-bottom" id="documentosModal">
        <div class="modal-header-custom">
            <div class="modal-handle"></div>
            <h3 class="mb-0">
                <i class="fas fa-folder-open text-info me-2"></i>
                Meus Documentos
            </h3>
            <div class="mt-2">
                <button class="btn btn-sm btn-outline-primary" onclick="atualizarDocumentos()" id="btnSyncDocsModal">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
            </div>
        </div>
        <div class="modal-body-custom" id="documentosConteudo">
            <div class="loading-spinner">
                <div class="spinner"></div>
                Carregando documentos...
            </div>
        </div>
    </div>

    <!-- Container para Toast/Notificações -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Input File Oculto para Upload de Documentos -->
    <input type="file" id="uploadDocumentoInput" style="display: none;" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">

    <!-- Estilos Adicionais para Modais -->
    <style>
        /* Estilos base dos modais */
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
        
        .modal-bottom.show { transform: translateY(0); }
        
        .modal-header-custom {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
            border-radius: 16px 16px 0 0;
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
            padding-bottom: 32px;
        }
        
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
        
        /* Estilos para conteúdo PIX */
        .pix-container {
            text-align: center;
            padding: 20px 0;
        }
        
        .pix-discount-highlight {
            background: linear-gradient(135deg, #e8f5e8, #f0f8f0);
            border: 2px solid var(--pix-discount-color);
            border-radius: 12px;
            padding: 16px;
            margin: 16px 0;
            text-align: center;
        }
        
        .pix-discount-highlight .discount-amount {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--pix-discount-color);
            margin: 8px 0;
        }
        
        .pix-discount-highlight .original-amount {
            text-decoration: line-through;
            color: #999;
            font-size: 1rem;
            margin-right: 8px;
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
            text-align: left;
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
        
        /* Estilos para documentos no modal */
        .documentos-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 16px;
        }
        
        @media (min-width: 768px) {
            .documentos-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .documento-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: var(--card-radius);
            padding: 16px;
            transition: all 0.2s;
            border-left: 4px solid;
            position: relative;
        }
        
        .documento-card:active {
            transform: scale(0.98);
        }
        
        .documento-card.obrigatorio {
            border-left-color: var(--danger-color);
        }
        
        .documento-card.opcional {
            border-left-color: var(--info-color);
        }
        
        .documento-card.enviado {
            border-left-color: var(--warning-color);
        }
        
        .documento-card.aprovado {
            border-left-color: var(--success-color);
        }
        
        .documento-card.rejeitado {
            border-left-color: var(--danger-color);
            background: #fdf2f2;
        }
        
        .documento-header {
            display: flex;
            justify-content: flex-start;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .documento-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 1.2rem;
            color: white;
            flex-shrink: 0;
        }
        
        .documento-info {
            flex: 1;
            min-width: 0;
        }
        
        .documento-nome {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 2px;
            font-size: 0.9rem;
        }
        
        .documento-descricao {
            color: #666;
            font-size: 0.8rem;
            line-height: 1.3;
            margin-bottom: 4px;
        }
        
        .documento-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        
        .status-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-faltando {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        
        .status-enviado {
            background: rgba(255,193,7,0.1);
            color: #856404;
        }
        
        .status-aprovado {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        
        .status-rejeitado {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        
        .documento-acoes {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }
        
        .btn-upload {
            flex: 1;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-upload:hover {
            background: var(--secondary-color);
        }
        
        .btn-upload.substituir {
            background: var(--warning-color);
            color: #856404;
        }
        
        .btn-download-doc {
            background: var(--info-color);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-download-doc:hover {
            background: #138496;
        }
        
        .documento-meta {
            font-size: 0.75rem;
            color: #888;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
        }
        
        .documento-observacoes {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 8px;
            margin-top: 8px;
            font-size: 0.8rem;
            color: #856404;
        }
        
        /* Spinner de loading */
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
        
        /* Toast/Notificações */
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
        
        .btn-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.6;
        }
        
        .btn-close:hover {
            opacity: 1;
        }
    </style>
    <!-- PARTE 4 -->
     <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ===================================
        // VARIÁVEIS GLOBAIS
        // ===================================
        let isUpdating = false;
        let swipeStartY = 0;
        let swipeStartTime = 0;
        let currentBoletoId = null;
        let documentosData = null;
        
        // ===================================
        // INICIALIZAÇÃO
        // ===================================
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Dashboard Mobile com PIX personalizado inicializado');
            registerServiceWorker();
            setupSwipeGestures();
            checkAutoUpdate();
            setupConnectivityListeners();
            carregarDocumentos(); // Carrega documentos na inicialização
        });
        
        // ===================================
        // SERVICE WORKER
        // ===================================
        function registerServiceWorker() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js')
                    .then(registration => {
                        console.log('Service Worker registrado:', registration);
                        navigator.serviceWorker.addEventListener('message', event => {
                            if (event.data.type === 'SW_UPDATED') {
                                showToast(event.data.message, 'info');
                            }
                        });
                    })
                    .catch(error => {
                        console.log('Erro ao registrar Service Worker:', error);
                    });
            }
        }
        
        // ===================================
        // GESTOS E CONECTIVIDADE
        // ===================================
        function setupSwipeGestures() {
            document.addEventListener('touchstart', function(e) {
                swipeStartY = e.touches[0].clientY;
                swipeStartTime = Date.now();
            }, { passive: true });
            
            document.addEventListener('touchend', function(e) {
                const swipeEndY = e.changedTouches[0].clientY;
                const swipeTime = Date.now() - swipeStartTime;
                const swipeDistance = swipeStartY - swipeEndY;
                
                if (window.scrollY === 0 && swipeDistance < -100 && swipeTime < 500) {
                    atualizarDados();
                }
            }, { passive: true });
        }
        
        function setupConnectivityListeners() {
            window.addEventListener('online', function() {
                showToast('Conexão restaurada!', 'success');
                setTimeout(() => {
                    forcarLimpezaCache().then(() => {
                        atualizarDados(true);
                    });
                }, 1000);
            });
            
            window.addEventListener('offline', function() {
                showToast('Você está offline', 'warning');
            });
        }
        
        // Listener para detectar focus na janela
        window.addEventListener('focus', function() {
            const lastUpdate = localStorage.getItem('lastUpdate');
            const now = Date.now();
            
            if (!lastUpdate || (now - parseInt(lastUpdate)) > 2 * 60 * 1000) {
                console.log('👁️ Janela focada - verificando atualizações...');
                setTimeout(() => atualizarDados(true), 500);
            }
        });
        
        function checkAutoUpdate() {
            const lastUpdate = localStorage.getItem('lastUpdate');
            const now = Date.now();
            
            if (!lastUpdate || (now - parseInt(lastUpdate)) > 30 * 60 * 1000) {
                setTimeout(() => {
                    atualizarDados(true);
                }, 2000);
            }
        }
        
        // ===================================
        // GERENCIAMENTO DE MODAIS
        // ===================================
        function mostrarMenu() {
            document.getElementById('modalOverlay').classList.add('show');
            document.getElementById('menuModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function fecharMenu() {
            document.getElementById('menuModal').classList.remove('show');
            setTimeout(() => {
                document.getElementById('modalOverlay').classList.remove('show');
                document.body.style.overflow = '';
            }, 100);
        }
        
        function mostrarAcoesRapidas() {
            document.getElementById('modalOverlay').classList.add('show');
            document.getElementById('acoesModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function fecharAcoes() {
            document.getElementById('acoesModal').classList.remove('show');
            setTimeout(() => {
                document.getElementById('modalOverlay').classList.remove('show');
                document.body.style.overflow = '';
            }, 100);
        }
        
        function fecharTodosModais() {
            // Fecha todos os modais
            document.querySelectorAll('.modal-bottom').forEach(modal => {
                modal.classList.remove('show');
            });
            
            setTimeout(() => {
                document.getElementById('modalOverlay').classList.remove('show');
                document.body.style.overflow = '';
            }, 100);
            
            // Reset de variáveis
            currentBoletoId = null;
        }
        
        // ===================================
        // FUNCIONALIDADES DE BOLETOS
        // ===================================
        function downloadBoleto(boletoId) {
            console.log('Iniciando download do boleto:', boletoId);
            showToast('Preparando download...', 'info');
            
            const link = document.createElement('a');
            link.href = `/api/download-boleto.php?id=${boletoId}`;
            link.download = `Boleto_${boletoId}.pdf`;
            link.target = '_blank';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            setTimeout(() => {
                showToast('Download iniciado!', 'success');
            }, 500);
        }
        
        function mostrarPix(boletoId) {
            console.log('Gerando PIX personalizado para boleto:', boletoId);
            
            currentBoletoId = boletoId;
            
            document.getElementById('modalOverlay').classList.add('show');
            document.getElementById('pixModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            document.getElementById('pixConteudo').innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Gerando código PIX com desconto personalizado...
                </div>
            `;
            
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
                    showToast('Código PIX personalizado gerado!', 'success');
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
        }
        
        function exibirPixGerado(data) {
            const { boleto, desconto, pix, instrucoes } = data;
            
            let descontoHtml = '';
            if (desconto.tem_desconto) {
                descontoHtml = `
                    <div class="pix-discount-highlight">
                        <h6><i class="fas fa-gift me-2"></i>Desconto PIX Personalizado Aplicado!</h6>
                        <div>
                            <span class="original-amount">R$ ${boleto.valor_original_formatado.replace('R$ ', '')}</span>
                            <span class="discount-amount">R$ ${boleto.valor_final_formatado.replace('R$ ', '')}</span>
                        </div>
                        <small class="text-success">
                            <i class="fas fa-check-circle me-1"></i>
                            ${desconto.economia}
                        </small>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Desconto personalizado definido pela administração
                            </small>
                        </div>
                    </div>
                `;
            }
            
            const html = `
                <div class="pix-container">
                    <div class="mb-3">
                        <h5 class="text-primary">
                            <i class="fas fa-receipt me-2"></i>
                            Boleto #${boleto.numero}
                        </h5>
                        <p class="mb-1"><strong>Aluno:</strong> ${boleto.aluno_nome}</p>
                        <p class="mb-1"><strong>Curso:</strong> ${boleto.curso_nome}</p>
                        <p class="mb-0"><strong>Vencimento:</strong> ${boleto.vencimento_formatado}</p>
                    </div>
                    
                    ${descontoHtml}
                    
                    <div class="text-center mb-4">
                        <img src="${pix.qr_code_base64}" alt="QR Code PIX" class="pix-qr-code">
                        <p class="mt-2 text-muted">
                            <i class="fas fa-mobile-alt me-1"></i>
                            Escaneie com o app do seu banco
                        </p>
                        ${desconto.tem_desconto ? 
                            `<p class="text-success"><strong>Valor final a pagar: ${boleto.valor_final_formatado}</strong></p>` : 
                            `<p class="text-primary"><strong>Valor a pagar: ${boleto.valor_final_formatado}</strong></p>`
                        }
                        ${desconto.tem_desconto ? 
                            `<p class="text-muted"><small>Desconto válido até o vencimento</small></p>` : ''
                        }
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
                            ${desconto.tem_desconto ? 
                                `<br><small class="text-success">
                                    <i class="fas fa-gift me-1"></i>
                                    <strong>Desconto válido:</strong> até o vencimento do boleto
                                </small>` : ''
                            }
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button class="btn ${desconto.tem_desconto ? 'btn-success' : 'btn-primary'}" onclick="compartilharPix()">
                            <i class="fas fa-share me-1"></i>
                            Compartilhar PIX ${desconto.tem_desconto ? 'com Desconto Personalizado' : ''}
                        </button>
                        <button class="btn btn-outline-secondary" onclick="fecharTodosModais()">
                            Fechar
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('pixConteudo').innerHTML = html;
        }
        
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
                        <button class="btn btn-outline-secondary" onclick="fecharTodosModais()">
                            Fechar
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('pixConteudo').innerHTML = html;
        }
        
        function copiarCodigoPix() {
            const codigo = document.getElementById('pixCodigoTexto').textContent.trim();
            
            navigator.clipboard.writeText(codigo).then(() => {
                showToast('Código PIX copiado!', 'success');
                
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
                const textArea = document.createElement('textarea');
                textArea.value = codigo;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                showToast('Código PIX copiado!', 'success');
            });
        }
        
        function compartilharPix() {
            if (navigator.share) {
                const codigo = document.getElementById('pixCodigoTexto').textContent.trim();
                
                navigator.share({
                    title: 'Pagamento PIX - IMEPEDU Boletos',
                    text: `Código PIX para pagamento com desconto personalizado:\n\n${codigo}`,
                }).catch(error => {
                    console.log('Erro ao compartilhar:', error);
                });
            } else {
                copiarCodigoPix();
                showToast('Código copiado! Cole onde desejar compartilhar.', 'info');
            }
        }
        
        function mostrarDetalhes(boletoId) {
            document.getElementById('modalOverlay').classList.add('show');
            document.getElementById('boletoModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            const detalhesDiv = document.getElementById('boletoDetalhes');
            detalhesDiv.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Carregando detalhes...
                </div>
            `;
            
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
                        <button class="btn btn-primary" onclick="downloadBoleto(${boleto.id}); fecharTodosModais();">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                        <button class="btn btn-success" onclick="mostrarPix(${boleto.id});">
                            <i class="fas fa-qrcode"></i> Código PIX
                        </button>
                    ` : `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            Este boleto já foi pago!
                        </div>
                    `}
                    <button class="btn btn-outline-secondary" onclick="fecharTodosModais()">
                        Fechar
                    </button>
                </div>
            `;
            
            document.getElementById('boletoDetalhes').innerHTML = html;
        }
        
        function exibirErroDetalhes(mensagem) {
            const html = `
                <div class="text-center p-4">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-3"></i>
                    <p>${mensagem}</p>
                    <button class="btn btn-outline-secondary" onclick="fecharTodosModais()">
                        Fechar
                    </button>
                </div>
            `;
            
            document.getElementById('boletoDetalhes').innerHTML = html;
        }
        
        // ===================================
        // SINCRONIZAÇÃO DE DADOS
        // ===================================
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
            
            const timestamp = Date.now();
            const random = Math.random().toString(36).substring(7);
            const forceRefresh = `force_refresh=1&t=${timestamp}&r=${random}&v=2.3.1`;
            
            const headers = {
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Force-Refresh': timestamp,
                'X-SW-Bypass': 'true'
            };
            
            const apiUrl = `/api/atualizar_dados.php?${forceRefresh}`;
            
            console.log('🔄 Iniciando sincronização forçada:', apiUrl);
            
            // Limpa cache antes da requisição
            if ('caches' in window) {
                caches.keys().then(cacheNames => {
                    const deletePromises = cacheNames
                        .filter(name => name.includes('atualizar') || name.includes('data'))
                        .map(name => {
                            console.log('🗑️ Removendo cache:', name);
                            return caches.delete(name);
                        });
                    return Promise.all(deletePromises);
                }).catch(err => {
                    console.log('⚠️ Erro ao limpar cache:', err);
                });
            }
            
            // Notifica Service Worker para bypass
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: 'BYPASS_CACHE',
                    url: apiUrl,
                    timestamp: timestamp
                });
            }
            
            fetch(apiUrl, {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'follow'
            })
            .then(response => {
                console.log('📡 Resposta recebida:', response.status, response.headers.get('date'));
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                return response.json();
            })
            .then(data => {
                console.log('📊 Dados recebidos:', data);
                
                if (data.success) {
                    // Limpa localStorage relacionado
                    try {
                        const keysToRemove = [];
                        for (let i = 0; i < localStorage.length; i++) {
                            const key = localStorage.key(i);
                            if (key && (key.includes('aluno') || key.includes('curso') || key.includes('boleto'))) {
                                keysToRemove.push(key);
                            }
                        }
                        keysToRemove.forEach(key => localStorage.removeItem(key));
                        console.log('🧹 LocalStorage limpo:', keysToRemove.length, 'itens removidos');
                    } catch (e) {
                        console.log('⚠️ Erro ao limpar localStorage:', e);
                    }
                    
                    // Atualiza timestamp de última atualização
                    localStorage.setItem('lastUpdate', timestamp.toString());
                    localStorage.setItem('lastUpdateSuccess', 'true');
                    
                    if (!silencioso) {
                        showToast('Dados atualizados com sucesso!', 'success');
                        
                        // Recarrega página com cache-busting
                        setTimeout(() => {
                            const reloadUrl = window.location.pathname + `?updated=${timestamp}&reload=1`;
                            console.log('🔄 Recarregando página:', reloadUrl);
                            window.location.replace(reloadUrl);
                        }, 1500);
                    } else {
                        console.log('✅ Sincronização silenciosa concluída');
                        // Atualiza documentos após sincronização silenciosa
                        setTimeout(() => {
                            carregarDocumentos();
                        }, 500);
                    }
                } else {
                    throw new Error(data.message || 'Erro desconhecido na sincronização');
                }
            })
            .catch(error => {
                console.error('❌ Erro na sincronização:', error);
                
                let errorMessage = 'Erro de conexão';
                
                if (error.message.includes('HTTP 304')) {
                    errorMessage = 'Dados já estão atualizados';
                } else if (error.message.includes('HTTP 401')) {
                    errorMessage = 'Sessão expirada. Faça login novamente';
                } else if (error.message.includes('HTTP 500')) {
                    errorMessage = 'Erro interno do servidor';
                } else if (error.message.includes('Failed to fetch')) {
                    errorMessage = 'Problema de conexão. Verifique sua internet';
                } else if (error.message) {
                    errorMessage = error.message;
                }
                
                showToast('Erro: ' + errorMessage, 'error');
                
                // Se for erro de sessão, redireciona para login
                if (error.message.includes('401') || error.message.includes('não autenticado')) {
                    setTimeout(() => {
                        window.location.href = '/login.php';
                    }, 2000);
                }
            })
            .finally(() => {
                isUpdating = false;
                btnSync.innerHTML = originalIcon;
                btnSync.disabled = false;
                
                console.log('🏁 Sincronização finalizada');
            });
        }
        
        // Função para forçar limpeza de cache
        function forcarLimpezaCache() {
            console.log('🧹 Forçando limpeza completa de cache...');
            
            return Promise.all([
                // Limpa cache do navegador
                'caches' in window ? caches.keys().then(names => 
                    Promise.all(names.map(name => caches.delete(name)))
                ) : Promise.resolve(),
                
                // Limpa localStorage
                new Promise(resolve => {
                    try {
                        localStorage.clear();
                        resolve();
                    } catch (e) {
                        resolve();
                    }
                }),
                
                // Limpa sessionStorage
                new Promise(resolve => {
                    try {
                        sessionStorage.clear();
                        resolve();
                    } catch (e) {
                        resolve();
                    }
                })
            ]).then(() => {
                console.log('✅ Cache limpo completamente');
            }).catch(err => {
                console.log('⚠️ Erro na limpeza:', err);
            });
        }
        
        // ===================================
        // UTILIDADES
        // ===================================
        function baixarTodosPendentes() {
            const pendentes = document.querySelectorAll('.boleto-card.pendente, .boleto-card.vencido').length;
            
            if (pendentes === 0) {
                showToast('Nenhum boleto pendente encontrado', 'info');
                return;
            }
            
            if (confirm(`Deseja baixar todos os ${pendentes} boletos pendentes?`)) {
                showToast('Preparando downloads...', 'info');
                
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
                
                boletos.forEach((boletoId, index) => {
                    setTimeout(() => {
                        downloadBoleto(boletoId);
                    }, index * 1000);
                });
                
                showToast(`Iniciando download de ${boletos.length} boletos...`, 'success');
            }
        }
        
        function mostrarInformacoes() {
            const info = `
                <div class="mb-3">
                    <h5>Sistema de Boletos IMEPEDU</h5>
                    <p>Versão: 2.3</p>
                    <p>Última atualização: ${new Date().toLocaleDateString('pt-BR')}</p>
                </div>
                <div class="mb-3">
                    <h6>Funcionalidades:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i> Visualização de boletos</li>
                        <li><i class="fas fa-check text-success me-2"></i> Download de PDFs</li>
                        <li><i class="fas fa-check text-success me-2"></i> Códigos PIX</li>
                        <li><i class="fas fa-check text-success me-2"></i> Desconto PIX personalizado</li>
                        <li><i class="fas fa-check text-success me-2"></i> Gestão de documentos</li>
                        <li><i class="fas fa-check text-success me-2"></i> Sincronização automática</li>
                        <li><i class="fas fa-check text-success me-2"></i> Interface mobile-first</li>
                    </ul>
                </div>
                <div class="text-center">
                    <small class="text-muted">IMEPEDU Educação © 2024</small>
                </div>
            `;
            
            mostrarModal('Informações do Sistema', info);
        }
        
        function mostrarModal(titulo, conteudo) {
            document.getElementById('pixConteudo').innerHTML = `
                <div class="mb-3">
                    <h5>${titulo}</h5>
                </div>
                ${conteudo}
                <div class="text-center mt-3">
                    <button class="btn btn-outline-secondary" onclick="fecharTodosModais()">
                        Fechar
                    </button>
                </div>
            `;
            
            document.getElementById('modalOverlay').classList.add('show');
            document.getElementById('pixModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function compartilharApp() {
            if (navigator.share) {
                navigator.share({
                    title: 'Sistema de Boletos IMEPEDU',
                    text: 'Acesse seus boletos acadêmicos com desconto PIX personalizado!',
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
        
        function logoutLimpo() {
            console.log('🚪 Iniciando logout limpo...');
            showToast('Fazendo logout...', 'info');
            
            const executarLogout = async () => {
                try {
                    console.log('🗑️ Limpando dados locais...');
                    
                    if (typeof localStorage !== 'undefined') {
                        localStorage.clear();
                    }
                    if (typeof sessionStorage !== 'undefined') {
                        sessionStorage.clear();
                    }
                    
                    if ('serviceWorker' in navigator) {
                        try {
                            const registration = await navigator.serviceWorker.getRegistration();
                            if (registration && registration.active) {
                                console.log('📨 Enviando comando de logout para SW...');
                                registration.active.postMessage({
                                    type: 'FORCE_LOGOUT',
                                    timestamp: Date.now()
                                });
                                
                                await new Promise(resolve => setTimeout(resolve, 500));
                            }
                        } catch (swError) {
                            console.log('⚠️ Erro ao comunicar com SW:', swError);
                        }
                    }
                    
                    const logoutUrl = `/logout.php?t=${Date.now()}&pwa=1`;
                    console.log('🏠 Redirecionando para:', logoutUrl);
                    
                    window.location.replace(logoutUrl);
                    
                } catch (error) {
                    console.error('❌ Erro no logout:', error);
                    window.location.replace(`/logout.php?t=${Date.now()}&fallback=1`);
                }
            };
            
            setTimeout(executarLogout, 300);
        }
        
        // ===================================
        // SISTEMA DE NOTIFICAÇÕES (TOAST)
        // ===================================
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            
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
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.remove()">×</button>
            `;
            
            container.appendChild(toast);
            
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
        
        // ===================================
        // DEBUG E LOGS
        // ===================================
        function debugSincronizacao() {
            console.log('🔧 DEBUG: Testando sincronização...');
            console.log('📊 Estado atual:', {
                isUpdating: isUpdating,
                lastUpdate: localStorage.getItem('lastUpdate'),
                userAgent: navigator.userAgent,
                online: navigator.onLine,
                serviceWorkerController: !!navigator.serviceWorker?.controller
            });
            
            forcarLimpezaCache().then(() => {
                console.log('🔄 Iniciando teste de sincronização...');
                atualizarDados(false);
            });
        }
        
        console.log('✅ Dashboard com Sistema de Desconto PIX Personalizado carregado!');
        console.log('🆕 Funcionalidades implementadas:');
        console.log('   - Desconto PIX 100% configurável pelo administrador');
        console.log('   - Valor do desconto definido no momento do upload');
        console.log('   - Funciona em qualquer polo sem configuração prévia');
        console.log('   - Interface otimizada para móvel');
        console.log('   - Controle total do admin sobre os descontos');
        console.log('   - Gestão completa de documentos integrada');
        /* PARTE 5 */
        // ===================================
        // GESTÃO DE DOCUMENTOS
        // ===================================
        
        /**
         * Carrega documentos do aluno
         */
        function carregarDocumentos() {
            fetch('/api/documentos-aluno.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        documentosData = data;
                        atualizarStatusDocumentosHeader(data.status);
                        atualizarContadorDocumentos(data);
                    } else {
                        console.error('Erro ao carregar documentos:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar documentos:', error);
                });
        }
        
        /**
         * Atualiza status dos documentos no header
         */
        function atualizarStatusDocumentosHeader(status) {
            const progressDiv = document.getElementById('documentosProgress');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const aprovadosText = document.getElementById('aprovadosText');
            const pendentesText = document.getElementById('pendentesText');
            const rejeitadosText = document.getElementById('rejeitadosText');
            
            if (status && status.total_tipos > 0) {
                progressDiv.style.display = 'block';
                
                progressBar.style.width = status.percentual_completo + '%';
                progressText.textContent = `${status.obrigatorios_enviados} de ${status.total_obrigatorios}`;
                aprovadosText.textContent = `${status.aprovados} aprovados`;
                pendentesText.textContent = `${status.pendentes} pendentes`;
                rejeitadosText.textContent = `${status.rejeitados} rejeitados`;
            } else {
                progressDiv.style.display = 'none';
            }
        }
        
        /**
         * Atualiza contadores de documentos
         */
        function atualizarContadorDocumentos(data) {
            const contador = document.getElementById('documentosCount');
            const contadorMenu = document.getElementById('menuDocumentosCount');
            
            if (data.status) {
                const pendentes = data.status.total_obrigatorios - data.status.obrigatorios_enviados;
                
                if (pendentes > 0) {
                    contador.textContent = pendentes;
                    contador.style.display = 'inline';
                    contadorMenu.textContent = pendentes;
                    contadorMenu.style.display = 'inline';
                } else {
                    contador.style.display = 'none';
                    contadorMenu.style.display = 'none';
                }
            }
        }
        
        /**
         * Mostra modal de documentos
         */
        function mostrarDocumentos() {
            document.getElementById('modalOverlay').classList.add('show');
            document.getElementById('documentosModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            if (documentosData) {
                exibirDocumentosNoModal(documentosData);
            } else {
                document.getElementById('documentosConteudo').innerHTML = `
                    <div class="loading-spinner">
                        <div class="spinner"></div>
                        Carregando documentos...
                    </div>
                `;
                carregarDocumentos();
            }
        }
        
        /**
         * Exibe os documentos no modal
         */
        function exibirDocumentosNoModal(data) {
            const container = document.getElementById('documentosConteudo');
            const { documentos, documentos_faltando, tipos_documentos } = data;
            
            // Combina documentos enviados e faltando
            const todosDocumentos = { ...documentos_faltando, ...documentos };
            
            let html = '<div class="documentos-grid">';
            
            for (const [tipo, info] of Object.entries(todosDocumentos)) {
                const documento = documentos[tipo];
                const tipoInfo = tipos_documentos[tipo];
                
                if (!tipoInfo) continue;
                
                const enviado = !!documento;
                const obrigatorio = tipoInfo.obrigatorio;
                
                let statusClass = 'faltando';
                let statusText = 'Não enviado';
                let statusIcon = 'fas fa-times';
                let acoes = '';
                
                if (enviado) {
                    statusClass = documento.status;
                    statusIcon = documento.status === 'aprovado' ? 'fas fa-check' : 
                               documento.status === 'rejeitado' ? 'fas fa-times' : 'fas fa-clock';
                    statusText = documento.status === 'aprovado' ? 'Aprovado' :
                               documento.status === 'rejeitado' ? 'Rejeitado' : 'Em análise';
                    
                    acoes = `
                        <button class="btn-download-doc" onclick="downloadDocumento(${documento.id})" title="Download">
                            <i class="fas fa-download"></i>
                        </button>
                        <button class="btn-upload substituir" onclick="iniciarUpload('${tipo}')">
                            <i class="fas fa-upload me-1"></i> Substituir
                        </button>
                    `;
                } else {
                    acoes = `
                        <button class="btn-upload" onclick="iniciarUpload('${tipo}')">
                            <i class="fas fa-upload me-1"></i> ${obrigatorio ? 'Enviar (Obrigatório)' : 'Enviar (Opcional)'}
                        </button>
                    `;
                }
                
                const iconColor = enviado ? 
                    (documento.status === 'aprovado' ? '#28a745' : 
                     documento.status === 'rejeitado' ? '#dc3545' : '#ffc107') :
                    (obrigatorio ? '#dc3545' : '#17a2b8');
                
                html += `
                    <div class="documento-card ${obrigatorio ? 'obrigatorio' : 'opcional'} ${statusClass}">
                        <div class="documento-header">
                            <div class="documento-icon" style="background-color: ${iconColor}">
                                <i class="${tipoInfo.icone}"></i>
                            </div>
                            <div class="documento-info">
                                <div class="documento-nome">${tipoInfo.nome}</div>
                                <div class="documento-descricao">${tipoInfo.descricao}</div>
                            </div>
                        </div>
                        
                        <div class="documento-status">
                            <span class="status-badge status-${statusClass}">
                                <i class="${statusIcon} me-1"></i>${statusText}
                            </span>
                            ${obrigatorio ? '<span class="badge bg-danger">Obrigatório</span>' : '<span class="badge bg-info">Opcional</span>'}
                        </div>
                        
                        ${enviado ? `
                            <div class="documento-meta">
                                <span>Enviado: ${new Date(documento.data_upload).toLocaleDateString('pt-BR')}</span>
                                <span>${documento.tamanho_formatado}</span>
                            </div>
                            ${documento.observacoes ? `
                                <div class="documento-observacoes">
                                    <strong>Observações:</strong> ${documento.observacoes}
                                </div>
                            ` : ''}
                        ` : ''}
                        
                        <div class="documento-acoes">
                            ${acoes}
                        </div>
                    </div>
                `;
            }
            
            html += '</div>';
            
            if (Object.keys(todosDocumentos).length === 0) {
                html = `
                    <div class="text-center py-4">
                        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                        <h5>Nenhum documento configurado</h5>
                        <p class="text-muted">Não há tipos de documentos configurados para este curso.</p>
                    </div>
                `;
            }
            
            container.innerHTML = html;
        }
        
        /**
         * Inicia processo de upload
         */
        function iniciarUpload(tipoDocumento) {
            const input = document.getElementById('uploadDocumentoInput');
            
            // Remove listeners anteriores
            input.onchange = null;
            
            // Configura novo listener
            input.onchange = function(e) {
                const arquivo = e.target.files[0];
                if (arquivo) {
                    uploadDocumento(tipoDocumento, arquivo);
                }
                // Reset do input
                e.target.value = '';
            };
            
            // Abre seletor de arquivo
            input.click();
        }
        
        /**
         * Faz upload do documento
         */
        function uploadDocumento(tipoDocumento, arquivo) {
            const tipoInfo = documentosData?.tipos_documentos[tipoDocumento];
            
            if (!tipoInfo) {
                showToast('Tipo de documento não encontrado', 'error');
                return;
            }
            
            // Validações básicas
            if (arquivo.size > 5 * 1024 * 1024) {
                showToast('Arquivo muito grande. Máximo: 5MB', 'error');
                return;
            }
            
            const tiposPermitidos = [
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 
                'application/pdf', 'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            
            if (!tiposPermitidos.includes(arquivo.type)) {
                showToast('Tipo de arquivo não permitido', 'error');
                return;
            }
            
            // Validação específica para foto
            if (tipoDocumento === 'foto_3x4' && !arquivo.type.startsWith('image/')) {
                showToast('Foto deve ser uma imagem (JPG, PNG)', 'error');
                return;
            }
            
            showToast('Enviando documento...', 'info');
            
            const formData = new FormData();
            formData.append('arquivo', arquivo);
            formData.append('tipo_documento', tipoDocumento);
            
            fetch('/api/upload-documento.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`${tipoInfo.nome} enviado com sucesso!`, 'success');
                    
                    // Recarrega documentos
                    setTimeout(() => {
                        carregarDocumentos();
                        
                        // Atualiza modal se estiver aberto
                        if (document.getElementById('documentosModal').classList.contains('show')) {
                            setTimeout(() => {
                                if (documentosData) {
                                    exibirDocumentosNoModal(documentosData);
                                }
                            }, 500);
                        }
                    }, 1000);
                    
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erro no upload:', error);
                showToast('Erro de conexão', 'error');
            });
        }
        
        /**
         * Download de documento
         */
        function downloadDocumento(documentoId) {
            showToast('Preparando download...', 'info');
            
            const link = document.createElement('a');
            link.href = `/api/download-documento.php?id=${documentoId}`;
            link.target = '_blank';
            link.click();
            
            setTimeout(() => {
                showToast('Download iniciado!', 'success');
            }, 500);
        }
        
        /**
         * Atualiza lista de documentos
         */
        function atualizarDocumentos() {
            const btn = document.getElementById('btnSyncDocsModal');
            const originalIcon = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
            
            carregarDocumentos();
            
            setTimeout(() => {
                btn.innerHTML = originalIcon;
                btn.disabled = false;
                
                // Atualiza modal se estiver aberto
                if (document.getElementById('documentosModal').classList.contains('show')) {
                    if (documentosData) {
                        exibirDocumentosNoModal(documentosData);
                    }
                }
            }, 2000);
        }
        
        // ===================================
        // INICIALIZAÇÃO FINAL
        // ===================================
        
        // Carrega documentos quando a página carrega
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                carregarDocumentos();
            }, 1000);
        });

        // Detector de problemas de cache e ERR_FAILED
class CacheManager {
    constructor() {
        this.errorCount = 0;
        this.maxErrors = 3;
        this.lastCleanup = localStorage.getItem('lastCacheCleanup') || 0;
        this.cleanupInterval = 24 * 60 * 60 * 1000; // 24 horas
        
        this.init();
    }
    
    init() {
        console.log('🛡️ CacheManager iniciado');
        
        // Monitora erros
        this.setupErrorMonitoring();
        
        // Verifica se precisa limpar cache
        this.checkAutoCleanup();
        
        // Monitora visibilidade da página
        this.setupVisibilityChange();
        
        // Monitora mudanças de conectividade
        this.setupConnectivityMonitoring();
    }
    
    setupErrorMonitoring() {
        // Intercepta erros de rede
        const originalFetch = window.fetch;
        window.fetch = async (...args) => {
            try {
                const response = await originalFetch(...args);
                
                // Se conseguiu fazer a requisição, reseta contador
                if (response.ok) {
                    this.errorCount = 0;
                }
                
                return response;
            } catch (error) {
                console.warn('🔥 Fetch error detectado:', error);
                this.handleNetworkError(error);
                throw error;
            }
        };
        
        // Monitora erros globais
        window.addEventListener('error', (event) => {
            if (event.message && event.message.includes('ERR_FAILED')) {
                console.error('🚨 ERR_FAILED detectado!');
                this.handleCriticalError();
            }
        });
        
        // Monitora promises rejeitadas
        window.addEventListener('unhandledrejection', (event) => {
            if (event.reason && 
                (event.reason.includes('ERR_FAILED') || 
                 event.reason.includes('Failed to fetch'))) {
                console.error('🚨 Network promise rejection:', event.reason);
                this.handleNetworkError(event.reason);
            }
        });
    }
    
    setupVisibilityChange() {
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                console.log('👁️ Página visível novamente');
                
                // Se ficou muito tempo em background, limpa cache
                const hiddenTime = Date.now() - (this.lastHiddenTime || Date.now());
                if (hiddenTime > 30 * 60 * 1000) { // 30 minutos
                    console.log('⏰ App ficou muito tempo em background, limpando cache...');
                    this.performEmergencyCleanup();
                }
            } else {
                this.lastHiddenTime = Date.now();
                console.log('👁️ Página oculta');
            }
        });
    }
    
    setupConnectivityMonitoring() {
        window.addEventListener('online', () => {
            console.log('🌐 Conexão restaurada');
            // Quando volta online, limpa cache potencialmente corrompido
            setTimeout(() => {
                this.cleanCorruptedCache();
            }, 2000);
        });
        
        window.addEventListener('offline', () => {
            console.log('📵 Conexão perdida');
        });
    }
    
    handleNetworkError(error) {
        this.errorCount++;
        console.warn(`🔥 Erro de rede ${this.errorCount}/${this.maxErrors}:`, error);
        
        if (this.errorCount >= this.maxErrors) {
            console.error('🚨 Muitos erros de rede - executando limpeza de emergência');
            this.performEmergencyCleanup();
        }
    }
    
    handleCriticalError() {
        console.error('🚨 Erro crítico detectado - limpeza imediata');
        this.performEmergencyCleanup();
    }
    
    async performEmergencyCleanup() {
        try {
            console.log('🧨 LIMPEZA DE EMERGÊNCIA INICIADA');
            
            // 1. Notifica Service Worker
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: 'CLEAR_ALL_CACHE',
                    emergency: true,
                    timestamp: Date.now()
                });
            }
            
            // 2. Limpa localStorage
            this.clearLocalStorage();
            
            // 3. Limpa sessionStorage
            this.clearSessionStorage();
            
            // 4. Limpa cache do browser
            await this.clearBrowserCache();
            
            // 5. Marca timestamp da limpeza
            localStorage.setItem('lastCacheCleanup', Date.now().toString());
            localStorage.setItem('emergencyCleanupCount', 
                (parseInt(localStorage.getItem('emergencyCleanupCount') || '0') + 1).toString()
            );
            
            // 6. Reset contador de erros
            this.errorCount = 0;
            
            console.log('✅ Limpeza de emergência concluída');
            
            // 7. Mostra notificação para o usuário
            if (typeof showToast === 'function') {
                showToast('Cache limpo por problemas de conectividade', 'info');
            }
            
        } catch (error) {
            console.error('❌ Erro na limpeza de emergência:', error);
        }
    }
    
    checkAutoCleanup() {
        const now = Date.now();
        const lastCleanup = parseInt(this.lastCleanup);
        
        if (now - lastCleanup > this.cleanupInterval) {
            console.log('🕐 Limpeza automática programada');
            setTimeout(() => {
                this.performScheduledCleanup();
            }, 5000); // Aguarda 5s após carregamento
        }
    }
    
    async performScheduledCleanup() {
        try {
            console.log('🧹 Limpeza programada iniciada');
            
            await this.cleanCorruptedCache();
            
            localStorage.setItem('lastCacheCleanup', Date.now().toString());
            
            console.log('✅ Limpeza programada concluída');
            
        } catch (error) {
            console.error('❌ Erro na limpeza programada:', error);
        }
    }
    
    clearLocalStorage() {
        try {
            const keysToKeep = [
                'lastCacheCleanup',
                'emergencyCleanupCount',
                'userPreferences'
            ];
            
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && !keysToKeep.includes(key)) {
                    keysToRemove.push(key);
                }
            }
            
            keysToRemove.forEach(key => {
                localStorage.removeItem(key);
            });
            
            console.log('🧹 localStorage limpo:', keysToRemove.length, 'itens removidos');
            
        } catch (error) {
            console.error('❌ Erro ao limpar localStorage:', error);
        }
    }
    
    clearSessionStorage() {
        try {
            sessionStorage.clear();
            console.log('🧹 sessionStorage limpo');
        } catch (error) {
            console.error('❌ Erro ao limpar sessionStorage:', error);
        }
    }
    
    async clearBrowserCache() {
        try {
            if ('caches' in window) {
                const cacheNames = await caches.keys();
                
                const deletePromises = cacheNames.map(async (cacheName) => {
                    try {
                        await caches.delete(cacheName);
                        console.log('🗑️ Cache removido:', cacheName);
                    } catch (error) {
                        console.warn('⚠️ Erro ao remover cache:', cacheName, error);
                    }
                });
                
                await Promise.all(deletePromises);
                console.log('✅ Cache do browser limpo');
            }
        } catch (error) {
            console.error('❌ Erro ao limpar cache do browser:', error);
        }
    }
    
    async cleanCorruptedCache() {
        try {
            if (!('caches' in window)) return;
            
            console.log('🔍 Verificando cache corrompido...');
            
            const cacheNames = await caches.keys();
            let corruptedCount = 0;
            
            for (const cacheName of cacheNames) {
                try {
                    const cache = await caches.open(cacheName);
                    const requests = await cache.keys();
                    
                    for (const request of requests) {
                        try {
                            const response = await cache.match(request);
                            
                            // Verifica se resposta é válida
                            if (!response || !response.ok) {
                                await cache.delete(request);
                                corruptedCount++;
                                console.log('🗑️ Cache corrompido removido:', request.url);
                            }
                        } catch (error) {
                            await cache.delete(request);
                            corruptedCount++;
                            console.log('🗑️ Cache inválido removido:', request.url);
                        }
                    }
                } catch (error) {
                    // Cache corrompido - remove completamente
                    await caches.delete(cacheName);
                    corruptedCount++;
                    console.log('🗑️ Cache corrompido removido:', cacheName);
                }
            }
            
            if (corruptedCount > 0) {
                console.log(`✅ Limpeza de cache corrompido: ${corruptedCount} itens removidos`);
            } else {
                console.log('✅ Cache verificado - nenhum item corrompido');
            }
            
        } catch (error) {
            console.error('❌ Erro ao verificar cache corrompido:', error);
        }
    }
    
    // Método para forçar logout limpo
    async forceCleanLogout() {
        console.log('🚪 Forçando logout limpo...');
        
        try {
            // 1. Notifica Service Worker
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: 'FORCE_LOGOUT',
                    timestamp: Date.now()
                });
            }
            
            // 2. Limpeza completa
            await this.performEmergencyCleanup();
            
            // 3. Aguarda um pouco
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 4. Redireciona com cache busting
            const logoutUrl = `/logout.php?t=${Date.now()}&clean=1&emergency=1`;
            window.location.replace(logoutUrl);
            
        } catch (error) {
            console.error('❌ Erro no logout limpo:', error);
            // Fallback
            window.location.replace(`/logout.php?t=${Date.now()}&fallback=1`);
        }
    }
    
    // Método para diagnosticar problemas
    async diagnoseIssues() {
        const diagnosis = {
            timestamp: new Date().toISOString(),
            errorCount: this.errorCount,
            lastCleanup: this.lastCleanup,
            emergencyCleanups: localStorage.getItem('emergencyCleanupCount') || '0',
            online: navigator.onLine,
            serviceWorker: {
                supported: 'serviceWorker' in navigator,
                registered: !!navigator.serviceWorker.controller,
                registrations: 0
            },
            cache: {
                supported: 'caches' in window,
                names: []
            },
            storage: {
                localStorage: this.getStorageInfo('localStorage'),
                sessionStorage: this.getStorageInfo('sessionStorage')
            }
        };
        
        // Verifica Service Worker
        if ('serviceWorker' in navigator) {
            try {
                const registrations = await navigator.serviceWorker.getRegistrations();
                diagnosis.serviceWorker.registrations = registrations.length;
            } catch (error) {
                diagnosis.serviceWorker.error = error.message;
            }
        }
        
        // Verifica Cache
        if ('caches' in window) {
            try {
                diagnosis.cache.names = await caches.keys();
            } catch (error) {
                diagnosis.cache.error = error.message;
            }
        }
        
        console.log('🔍 Diagnóstico do sistema:', diagnosis);
        return diagnosis;
    }
    
    getStorageInfo(storageType) {
        try {
            const storage = window[storageType];
            return {
                available: true,
                length: storage.length,
                keys: Object.keys(storage)
            };
        } catch (error) {
            return {
                available: false,
                error: error.message
            };
        }
    }
    
    // Método para teste de conectividade
    async testConnectivity() {
        const tests = [
            { name: 'Dashboard', url: '/dashboard.php' },
            { name: 'API Status', url: '/api/status.php' },
            { name: 'Login Page', url: '/login.php' }
        ];
        
        const results = {};
        
        for (const test of tests) {
            try {
                const startTime = Date.now();
                const response = await fetch(test.url + `?test=1&t=${Date.now()}`, {
                    method: 'HEAD',
                    cache: 'no-cache'
                });
                const endTime = Date.now();
                
                results[test.name] = {
                    success: response.ok,
                    status: response.status,
                    time: endTime - startTime,
                    url: test.url
                };
                
            } catch (error) {
                results[test.name] = {
                    success: false,
                    error: error.message,
                    url: test.url
                };
            }
        }
        
        console.log('🌐 Teste de conectividade:', results);
        return results;
    }
}

// Função para integrar com o sistema existente
function initCacheManager() {
    // Cria instância global
    window.cacheManager = new CacheManager();
    
    // Adiciona ao logout existente
    const originalLogout = window.logoutLimpo;
    if (originalLogout) {
        window.logoutLimpo = function() {
            console.log('🔄 Logout com limpeza de cache...');
            window.cacheManager.forceCleanLogout();
        };
    }
    
    // Adiciona comando para limpeza manual
    window.clearCacheEmergency = function() {
        console.log('🧨 Limpeza manual solicitada');
        window.cacheManager.performEmergencyCleanup();
    };
    
    // Adiciona comando para diagnóstico
    window.diagnoseCacheIssues = function() {
        return window.cacheManager.diagnoseIssues();
    };
    
    // Adiciona teste de conectividade
    window.testConnectivity = function() {
        return window.cacheManager.testConnectivity();
    };
    
    console.log('🛡️ CacheManager integrado ao sistema');
    console.log('💡 Comandos disponíveis:');
    console.log('   - clearCacheEmergency() : Limpeza manual');
    console.log('   - diagnoseCacheIssues() : Diagnóstico');
    console.log('   - testConnectivity() : Teste de rede');
}

// Auto-inicialização
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCacheManager);
} else {
    initCacheManager();
}

// Listener para Service Worker messages
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', event => {
        const data = event.data;
        
        if (data && data.type === 'LOGOUT_CLEANUP_COMPLETE') {
            console.log('✅ Service Worker confirmou limpeza de logout');
        }
        
        if (data && data.type === 'SW_UPDATED') {
            console.log('🔄 Service Worker atualizado:', data.message);
            
            // Se houve atualização, pode ser boa ideia limpar cache antigo
            setTimeout(() => {
                window.cacheManager?.cleanCorruptedCache();
            }, 2000);
        }
    });
}

console.log('🛡️ Sistema de proteção contra ERR_FAILED carregado');
    </script>
</body>
</html>

<?php
/**
 * ===================================
 * FUNÇÃO PHP PARA RENDERIZAR BOLETOS
 * ===================================
 */

/**
 * Função para renderizar card de boleto com informações de desconto PIX personalizado
 */
function renderizarBoletoCard($boleto, $statusClass) {
    $vencimento = new DateTime($boleto['vencimento']);
    $hoje = new DateTime();
    $diasVencimento = $hoje->diff($vencimento)->format('%r%a');
    
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
    
    $temPDF = !empty($boleto['arquivo_pdf']);
    
    // 🔧 CORREÇÃO: Verifica desconto PIX com validação de vencimento corrigida
    $temDescontoPix = false;
    $economiaTexto = '';
    $cardExtraClass = '';
    $botaoPixClass = 'btn-pix';
    $valorExibicao = $valorFormatado;
    
    // Verificação corrigida - considera desconto válido até o final do dia de vencimento
    $agora = new DateTime();
    $fimDiaVencimento = clone $vencimento;
    $fimDiaVencimento->setTime(23, 59, 59);
    $descontoVencido = ($agora > $fimDiaVencimento);
    
    // 🔧 NOVA LÓGICA: Usa dados já calculados no loop principal OU recalcula se necessário
    if (isset($boleto['pode_usar_desconto']) && $boleto['pode_usar_desconto']) {
        $temDescontoPix = true;
    } else {
        // Recalcula se não foi definido (fallback)
        $temDescontoPix = (
            ($boleto['pix_desconto_disponivel'] ?? false) &&
            !($boleto['pix_desconto_usado'] ?? false) &&
            $boleto['status'] !== 'pago' &&
            !$descontoVencido &&
            ($boleto['pix_valor_desconto'] ?? 0) > 0
        );
        
        if ($temDescontoPix) {
            $valorMinimo = $boleto['pix_valor_minimo'] ?? 0;
            if ($valorMinimo > 0 && $boleto['valor'] < $valorMinimo) {
                $temDescontoPix = false;
            }
        }
    }
    
    if ($temDescontoPix) {
        // Usa economia já calculada OU calcula
        $economia = $boleto['economia_pix'] ?? (float)($boleto['pix_valor_desconto'] ?? 0);
        $valorFinal = $boleto['valor_final_pix'] ?? ($boleto['valor'] - $economia);
        
        // Garante valor mínimo de R$ 10,00
        if ($valorFinal < 10.00) {
            $economia = $boleto['valor'] - 10.00;
            $valorFinal = 10.00;
        }
        
        if ($economia > 0) {
            $economiaTexto = 'Economia: R$ ' . number_format($economia, 2, ',', '.');
            $cardExtraClass = ' com-desconto-pix';
            $botaoPixClass = 'btn-pix com-desconto';
            
            // Mostra valor original e valor com desconto
            $valorOriginal = 'R$ ' . number_format($boleto['valor'], 2, ',', '.');
            $valorComDesconto = 'R$ ' . number_format($valorFinal, 2, ',', '.');
            $valorExibicao = '<span class="boleto-valor-original">' . $valorOriginal . '</span>' . 
                            '<span class="boleto-valor-desconto">' . $valorComDesconto . '</span>';
        } else {
            $temDescontoPix = false; // Se economia é 0, não tem desconto válido
        }
    }
    
    ob_start();
    ?>
    <div class="boleto-card <?= $statusClass . $cardExtraClass ?>">
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
                <div class="boleto-valor"><?= $valorExibicao ?></div>
            </div>
        </div>
        
        <div class="boleto-body">
            <?php if ($temDescontoPix): ?>
            <div class="desconto-info">
                <i class="fas fa-gift text-success me-1"></i>
                <strong>Desconto PIX Personalizado Disponível!</strong><br>
                <span class="desconto-valor"><?= $economiaTexto ?></span>
                <?php if (($boleto['pix_valor_minimo'] ?? 0) > 0): ?>
                    <div class="valor-minimo">
                        <i class="fas fa-info-circle me-1"></i>
                        Valor mínimo: R$ <?= number_format($boleto['pix_valor_minimo'], 2, ',', '.') ?>
                    </div>
                <?php endif; ?>
                <div class="valor-minimo">
                    <i class="fas fa-clock me-1"></i>
                    <?php if ($diasVencimento == 0): ?>
                        <span class="text-warning">⚡ Último dia para usar o desconto!</span>
                    <?php elseif ($diasVencimento > 0): ?>
                        Válido por mais <?= $diasVencimento ?> dia(s)
                    <?php else: ?>
                        Desconto expirado
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
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
            
            <button class="btn-action <?= $botaoPixClass ?>" onclick="mostrarPix(<?= $boleto['id'] ?>)" 
                    title="<?= $temDescontoPix ? 'Gerar PIX com desconto personalizado' : 'Gerar código PIX' ?>">
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