<?php
/**
 * Sistema de Boletos IMEPEDU - Upload Completo com Parcelas PIX
 * Arquivo: admin/upload-boletos.php
 * Versão: 2.0 - Com funcionalidade de Parcelas PIX
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';
require_once '../src/BoletoUploadService.php';
require_once 'includes/verificar-permissao.php';

$adminService = new AdminService();
$uploadService = new BoletoUploadService();
$admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$admin) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

$sucesso = '';
$erro = '';

// Processamento das requisições POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['acao']) && $_POST['acao'] === 'upload_individual') {
            $resultado = $uploadService->processarUploadIndividual($_POST, $_FILES);
            $sucesso = $resultado['message'];
            
        } elseif (isset($_POST['acao']) && $_POST['acao'] === 'upload_lote') {
            $resultado = $uploadService->processarUploadLote($_POST, $_FILES);
            $sucesso = "Upload em lote processado! {$resultado['sucessos']} boletos enviados, {$resultado['erros']} erros.";
            
        } elseif (isset($_POST['acao']) && $_POST['acao'] === 'upload_multiplo_aluno') {
            $resultado = $uploadService->processarUploadMultiploAluno($_POST, $_FILES);
            $sucesso = "Upload múltiplo processado! {$resultado['sucessos']} boletos enviados para {$resultado['aluno_nome']}, {$resultado['erros']} erros.";
            
        } elseif (isset($_POST['acao']) && $_POST['acao'] === 'gerar_parcelas_pix') {
            // 🆕 FUNCIONALIDADE ATUALIZADA: Parcelas PIX com controle individual
            $resultado = $uploadService->gerarParcelasPix($_POST);
            
            // Monta mensagem detalhada com informações das parcelas individuais
            $mensagemDetalhada = "<strong>Parcelas PIX personalizadas geradas com sucesso!</strong><br>";
            $mensagemDetalhada .= "• <strong>{$resultado['parcelas_geradas']} parcelas</strong> criadas para <strong>{$resultado['aluno_nome']}</strong><br>";
            $mensagemDetalhada .= "• Valor total: <strong>R$ " . number_format($resultado['valor_total'], 2, ',', '.') . "</strong><br>";
            
            if ($resultado['parcelas_com_pix'] > 0) {
                $mensagemDetalhada .= "• Parcelas com desconto PIX: <strong>{$resultado['parcelas_com_pix']}</strong><br>";
                $mensagemDetalhada .= "• Economia total possível: <strong>R$ " . number_format($resultado['economia_total'], 2, ',', '.') . "</strong><br>";
            } else {
                $mensagemDetalhada .= "• <strong>Nenhuma parcela configurada com desconto PIX</strong><br>";
            }
            
            if ($resultado['parcelas_com_erro'] > 0) {
                $mensagemDetalhada .= "• <span class='text-warning'>{$resultado['parcelas_com_erro']} parcela(s) com erro</span><br>";
                
                // Detalha os erros se houver
                if (!empty($resultado['detalhes_erros'])) {
                    $mensagemDetalhada .= "<br><strong>Erros encontrados:</strong><br>";
                    foreach ($resultado['detalhes_erros'] as $erro) {
                        $mensagemDetalhada .= "• Parcela {$erro['parcela']}: {$erro['erro']}<br>";
                    }
                }
            }
            
            // Adiciona resumo das parcelas geradas
            if (!empty($resultado['detalhes_parcelas'])) {
                $mensagemDetalhada .= "<br><details><summary><strong>Ver detalhes das parcelas geradas</strong></summary>";
                $mensagemDetalhada .= "<div style='margin-top: 10px; font-size: 0.9em;'>";
                
                foreach ($resultado['detalhes_parcelas'] as $parcela) {
                    $mensagemDetalhada .= "📅 {$parcela['vencimento']} - {$parcela['descricao']} - ";
                    $mensagemDetalhada .= "R$ " . number_format($parcela['valor_original'], 2, ',', '.');
                    
                    if ($parcela['tem_desconto_pix'] && $parcela['valor_desconto'] > 0) {
                        $mensagemDetalhada .= " (PIX: R$ " . number_format($parcela['valor_final_pix'], 2, ',', '.') . ")";
                    }
                    $mensagemDetalhada .= "<br>";
                }
                
                $mensagemDetalhada .= "</div></details>";
            }
            
            $sucesso = $mensagemDetalhada;
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
        
        // Log mais detalhado para debug
        error_log("ERRO no upload de boletos: " . $e->getMessage());
        error_log("POST data: " . print_r($_POST, true));
        
        // Se for erro de parcelas individuais, adiciona contexto
        if (isset($_POST['acao']) && $_POST['acao'] === 'gerar_parcelas_pix') {
            if (isset($_POST['parcelas_individuais'])) {
                $parcelas = json_decode($_POST['parcelas_individuais'], true);
                $quantidadeParcelas = is_array($parcelas) ? count($parcelas) : 0;
                error_log("Tentativa de gerar {$quantidadeParcelas} parcelas individuais falhou");
            }
        }
    }
}

// Dados para os formulários
$polosAtivos = MoodleConfig::getActiveSubdomains();
$cursosDisponiveis = $adminService->buscarTodosCursos();
$alunosRecentes = $adminService->buscarAlunosRecentes(20);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload de Boletos - Administração IMEPEDU</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Dropzone CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/dropzone.min.css" rel="stylesheet">

    <!-- PARTE 2 -->

    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --pix-color: #32BCAD;
            --pix-discount-color: #28a745;
            --sidebar-width: 260px;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* ========== SIDEBAR ========== */
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
        
        /* ========== MAIN CONTENT ========== */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .topbar {
            background: white;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* ========== CARDS ========== */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: var(--primary-color);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            padding: 1rem 1.5rem;
            border: none;
        }
        
        /* ========== UPLOAD ZONES ========== */
        .upload-zone {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 3rem 2rem;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: var(--primary-color);
            background: rgba(0,102,204,0.05);
        }
        
        .upload-zone i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
        
        .upload-zone:hover i,
        .upload-zone.dragover i {
            color: var(--primary-color);
        }
        
        .upload-zone-multiple {
            border: 2px dashed #28a745;
            background: rgba(40,167,69,0.05);
        }
        
        .upload-zone-multiple:hover {
            border-color: #1e7e34;
            background: rgba(40,167,69,0.1);
        }
        
        /* ========== PIX DESCONTO SECTIONS ========== */
        .pix-desconto-section {
            background: linear-gradient(135deg, #e8f5e8, #f0f8f0);
            border: 2px solid var(--pix-discount-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            transition: all 0.3s ease;
        }
        
        .pix-desconto-section.collapsed {
            background: #f8f9fa;
            border-color: #dee2e6;
            padding: 1rem 1.5rem;
        }
        
        .pix-desconto-title {
            color: var(--pix-discount-color);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .pix-desconto-controls {
            display: none;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .pix-desconto-controls.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        .pix-desconto-info {
            background: rgba(40, 167, 69, 0.1);
            border-radius: 6px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* ========== PARCELAS PIX (NOVA FUNCIONALIDADE) ========== */
        .parcelas-pix-form {
            background: linear-gradient(135deg, #e8f5e8, #f0f8f0);
            border: 2px solid var(--pix-color);
            border-radius: 12px;
            padding: 2rem;
            margin: 1.5rem 0;
            position: relative;
        }
        
        .parcelas-pix-form::before {
            content: "✨ NOVO";
            position: absolute;
            top: -8px;
            left: 20px;
            background: var(--pix-color);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .preview-parcelas-table {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        
        .preview-parcelas {
            animation: slideInDown 0.3s ease;
        }
        
        .valor-com-desconto {
            color: var(--pix-discount-color) !important;
            font-weight: bold;
        }
        
        .economia-total {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid var(--pix-discount-color);
            padding: 10px 15px;
            border-radius: 0 8px 8px 0;
        }
        
        /* ========== FORM SECTIONS ========== */
        .form-section {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        /* ========== BUTTONS ========== */
        .btn-upload {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40,167,69,0.3);
            color: white;
        }
        
        .btn-upload-multiple {
            background: linear-gradient(135deg, var(--info-color), #138496);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-upload-multiple:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23,162,184,0.3);
            color: white;
        }
        
        .btn-gerar-parcelas {
            background: linear-gradient(135deg, var(--pix-color), #28a745);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-gerar-parcelas:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(50, 188, 173, 0.3);
            color: white;
        }
        
        .btn-gerar-parcelas:disabled {
            background: #6c757d;
            transform: none;
            box-shadow: none;
        }
        
        /* ========== FILE PREVIEW ========== */
        .file-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .file-item:last-child {
            border-bottom: none;
        }
        
        .file-info {
            display: flex;
            align-items: center;
        }
        
        .file-icon {
            width: 40px;
            height: 40px;
            background: var(--danger-color);
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 1rem;
        }
        
        .file-list {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .file-list-item {
            display: block;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .file-list-item.valid {
            border-left: 4px solid #28a745;
        }
        
        .file-list-item.invalid {
            border-left: 4px solid #dc3545;
            background: rgba(220,53,69,0.05);
        }
        
        /* ========== USER INFO ========== */
        .user-info {
            display: flex;
            align-items: center;
            margin-left: auto;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 10px;
        }
        
        /* ========== TABS ========== */
        .tab-content {
            margin-top: 2rem;
        }
        
        .nav-tabs .nav-link {
            border-radius: 10px 10px 0 0;
            border: none;
            color: #6c757d;
            font-weight: 600;
        }
        
        .nav-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        /* ========== FORM CONTROLS ========== */
        .input-group-text {
            background: white;
            border-right: none;
            color: var(--primary-color);
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 102, 204, 0.25);
        }
        
        .is-invalid {
            border-color: #dc3545 !important;
        }
        
        /* ========== ALERTS ========== */
        .alert-pix {
            background: linear-gradient(135deg, #e8f5e8, #f0f8f0);
            border: 1px solid var(--pix-discount-color);
            color: #155724;
        }
        
        /* ========== ANIMATIONS ========== */
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        .pix-config-row {
            animation: slideDown 0.3s ease;
        }
        
        /* ========== RESPONSIVE ========== */
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
            
            .parcelas-pix-form {
                padding: 1rem;
                margin: 1rem 0;
            }
            
            .preview-parcelas-table {
                font-size: 0.85rem;
            }
            
            .btn-gerar-parcelas {
                width: 100%;
                margin-top: 1rem;
            }
        }
        
        /* ========== TOAST CONTAINER ========== */
        .toast-container {
            position: fixed !important;
            top: 20px !important;
            right: 20px !important;
            z-index: 2001 !important;
            min-width: 350px !important;
            max-width: 90vw !important;
        }
    </style>
</head>
<body>

<!-- PARTE 3 -->
<!-- ========== SIDEBAR ========== -->
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
                <a href="/admin/upload-boletos.php" class="nav-link active">
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
    
    <!-- ========== MAIN CONTENT ========== -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div>
                <h3 class="mb-0">Upload de Boletos</h3>
                <small class="text-muted">Envie arquivos PDF de boletos com desconto PIX personalizado ou gere parcelas automaticamente</small>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <?= strtoupper(substr($admin['nome'], 0, 1)) ?>
                </div>
                <div>
                    <div class="fw-bold"><?= htmlspecialchars($admin['nome']) ?></div>
                    <small class="text-muted"><?= htmlspecialchars($admin['nivel']) ?></small>
                </div>
            </div>
        </div>
        
        <!-- ========== ALERTAS ========== -->
        <?php if ($sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= $sucesso ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- ========== CARD PRINCIPAL ========== -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-upload"></i>
                    Upload de Boletos PDF e Geração de Parcelas PIX
                </h5>
            </div>

            <div class="card-body">
                <!-- Tabs de Navegação -->
                <ul class="nav nav-tabs" id="uploadTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="individual-tab" data-bs-toggle="tab" 
                                data-bs-target="#individual" type="button" role="tab">
                            <i class="fas fa-file"></i> Upload Individual
                        </button>
                    </li>
                    
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="multiplo-aluno-tab" data-bs-toggle="tab" 
                                data-bs-target="#multiplo-aluno" type="button" role="tab">
                            <i class="fas fa-user-plus"></i> Múltiplos para Um Aluno
                            <span class="badge bg-success ms-1">NOVO</span>
                        </button>
                    </li>
                    
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="lote-tab" data-bs-toggle="tab" 
                                data-bs-target="#lote" type="button" role="tab">
                            <i class="fas fa-files"></i> Upload em Lote
                        </button>
                    </li>
                    
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="instrucoes-tab" data-bs-toggle="tab" 
                                data-bs-target="#instrucoes" type="button" role="tab">
                            <i class="fas fa-info-circle"></i> Instruções
                        </button>
                    </li>
                </ul>
                
                <!-- Início do Conteúdo das Tabs -->
                <div class="tab-content" id="uploadTabContent">

                <!-- PARTE 4 -->

                <!-- ========== ABA 1: UPLOAD INDIVIDUAL ========== -->
                <div class="tab-pane fade show active" id="individual" role="tabpanel">
                        <form method="POST" enctype="multipart/form-data" id="uploadIndividualForm">
                            <input type="hidden" name="acao" value="upload_individual">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="polo" class="form-label">
                                            <i class="fas fa-map-marker-alt"></i> Polo
                                        </label>
                                        <select class="form-select" id="polo" name="polo" required>
                                            <option value="">Selecione o polo</option>
                                            <?php foreach ($polosAtivos as $polo): ?>
                                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                                <option value="<?= $polo ?>">
                                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="curso" class="form-label">
                                            <i class="fas fa-book"></i> Curso
                                        </label>
                                        <select class="form-select" id="curso" name="curso_id" required>
                                            <option value="">Primeiro selecione o polo</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="aluno_cpf" class="form-label">
                                            <i class="fas fa-user"></i> CPF do Aluno
                                        </label>
                                        <input type="text" class="form-control" id="aluno_cpf" name="aluno_cpf" 
                                               placeholder="000.000.000-00" maxlength="14" required>
                                        <div class="form-text">Digite o CPF do aluno destinatário do boleto</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="valor" class="form-label">
                                            <i class="fas fa-dollar-sign"></i> Valor
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor" name="valor" 
                                                   step="0.01" min="0" placeholder="0,00" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="vencimento" class="form-label">
                                            <i class="fas fa-calendar"></i> Data de Vencimento
                                        </label>
                                        <input type="date" class="form-control" id="vencimento" name="vencimento" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="numero_boleto" class="form-label">
                                            <i class="fas fa-barcode"></i> Número do Boleto
                                        </label>
                                        <input type="text" class="form-control" id="numero_boleto" name="numero_boleto" 
                                               placeholder="Ex: 202412150001" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="descricao" class="form-label">
                                    <i class="fas fa-comment"></i> Descrição (Opcional)
                                </label>
                                <input type="text" class="form-control" id="descricao" name="descricao" 
                                       placeholder="Ex: Mensalidade Janeiro 2024">
                            </div>
                            
                            <!-- Seção de Desconto PIX Individual -->
                            <div class="pix-desconto-section" id="pixDescontoSection">
                                <div class="pix-desconto-title">
                                    <i class="fas fa-qrcode"></i> 
                                    Configuração de Desconto PIX
                                </div>
                                
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="pix_desconto_disponivel" 
                                           name="pix_desconto_disponivel" value="1" onchange="togglePixDesconto()">
                                    <label class="form-check-label" for="pix_desconto_disponivel">
                                        <strong>Aplicar Desconto no PIX</strong>
                                    </label>
                                </div>
                                
                                <div class="pix-desconto-controls" id="pixDescontoControls">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label for="valor_desconto_pix" class="form-label">
                                                <i class="fas fa-percentage"></i> Valor do Desconto
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">R$</span>
                                                <input type="number" class="form-control" id="valor_desconto_pix" 
                                                       name="valor_desconto_pix" step="0.01" min="0" 
                                                       placeholder="Ex: 50,00">
                                            </div>
                                            <small class="form-text text-muted">Valor fixo de desconto para pagamento via PIX</small>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="valor_minimo_desconto" class="form-label">
                                                <i class="fas fa-calculator"></i> Valor Mínimo (Opcional)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">R$</span>
                                                <input type="number" class="form-control" id="valor_minimo_desconto" 
                                                       name="valor_minimo_desconto" step="0.01" min="0" 
                                                       placeholder="Ex: 100,00">
                                            </div>
                                            <small class="form-text text-muted">Valor mínimo do boleto para aplicar desconto</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="pix-desconto-info">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Importante:</strong> O desconto PIX estará disponível apenas até a data de vencimento do boleto. 
                                    Após o vencimento, o aluno pagará o valor integral.
                                </div>
                            </div>
                            
                            <!-- Upload Zone Individual -->
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-file-pdf"></i> Arquivo PDF do Boleto
                                </label>
                                <div class="upload-zone" id="uploadZone">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <h5>Clique ou arraste o arquivo PDF aqui</h5>
                                    <p class="text-muted">Apenas arquivos PDF são aceitos (máximo 5MB)</p>
                                    <input type="file" id="arquivo_pdf" name="arquivo_pdf" 
                                           accept=".pdf" hidden required>
                                </div>
                                <div id="filePreview" class="file-preview" style="display: none;"></div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-upload">
                                    <i class="fas fa-upload"></i> Enviar Boleto
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- ========== ABA 2: MÚLTIPLOS PARA UM ALUNO (COM PARCELAS PIX) ========== -->
                    <!-- ========== ABA 2: MÚLTIPLOS PARA UM ALUNO (COM CONTROLE INDIVIDUAL) ========== -->
                    <div class="tab-pane fade" id="multiplo-aluno" role="tabpanel">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Opções para Múltiplos Boletos:</strong> Gere parcelas com controle individual de valores e descontos PIX ou envie vários arquivos PDF para o mesmo aluno.
                        </div>
                        
                        <!-- 🆕 NOVA FUNCIONALIDADE: GERAR PARCELAS PIX COM CONTROLE INDIVIDUAL -->
                        <div class="section-title">
                            <i class="fas fa-qrcode"></i> Opção 1: Gerar Parcelas PIX com Controle Individual
                        </div>

                        <form method="POST" id="gerarParcelasPixForm" class="parcelas-pix-form">
                            <input type="hidden" name="acao" value="gerar_parcelas_pix">
                            
                            <!-- Dados do Aluno -->
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="polo_parcelas" class="form-label">
                                            <i class="fas fa-map-marker-alt"></i> Polo
                                        </label>
                                        <select class="form-select" id="polo_parcelas" name="polo" required>
                                            <option value="">Selecione o polo</option>
                                            <?php foreach ($polosAtivos as $polo): ?>
                                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                                <option value="<?= $polo ?>">
                                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="curso_parcelas" class="form-label">
                                            <i class="fas fa-book"></i> Curso
                                        </label>
                                        <select class="form-select" id="curso_parcelas" name="curso_id" required>
                                            <option value="">Primeiro selecione o polo</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="aluno_cpf_parcelas" class="form-label">
                                            <i class="fas fa-user"></i> CPF do Aluno
                                        </label>
                                        <input type="text" class="form-control" id="aluno_cpf_parcelas" name="aluno_cpf" 
                                               placeholder="000.000.000-00" maxlength="14" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Configuração Inicial das Parcelas -->
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="quantidade_parcelas" class="form-label">
                                            <i class="fas fa-calculator"></i> Quantidade de Parcelas
                                        </label>
                                        <select class="form-select" id="quantidade_parcelas" name="quantidade_parcelas" required>
                                            <option value="">Selecione</option>
                                            <?php for($i = 1; $i <= 32; $i++): ?>
                                                <option value="<?= $i ?>"><?= $i ?>x</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="primeira_parcela" class="form-label">
                                            <i class="fas fa-calendar-alt"></i> Primeira Parcela
                                        </label>
                                        <input type="date" class="form-control" id="primeira_parcela" name="primeira_parcela" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="descricao_parcelas" class="form-label">
                                            <i class="fas fa-tag"></i> Descrição Base
                                        </label>
                                        <input type="text" class="form-control" id="descricao_parcelas" name="descricao_base" 
                                               placeholder="Ex: Mensalidade" required>
                                        <small class="form-text text-muted">Será: "Mensalidade 01/12", etc.</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-magic"></i> Gerar Parcelas
                                        </label>
                                        <button type="button" class="btn btn-outline-primary w-100" onclick="gerarListaParcelas()">
                                            <i class="fas fa-plus"></i> Criar Lista de Parcelas
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Lista de Parcelas Individuais -->
                            <div id="listaParcelasIndividuais" class="mt-4" style="display: none;">
                                <h6><i class="fas fa-list"></i> Configure Cada Parcela Individualmente:</h6>
                                
                                <!-- Ferramentas de Controle -->
                                <div class="row mt-3 mb-3">
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="aplicarValorGlobalParcelas()">
                                            <i class="fas fa-dollar-sign"></i> Aplicar Valor Global
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="aplicarDescontoGlobalParcelas()">
                                            <i class="fas fa-qrcode"></i> Aplicar Desconto Global
                                        </button>
                                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="calcularValoresTotais()">
                                            <i class="fas fa-calculator"></i> Calcular Totais
                                        </button>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <div class="badge bg-info fs-6" id="resumoTotalParcelas">
                                            Total: R$ 0,00 | Com PIX: R$ 0,00 | Economia: R$ 0,00
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Campos Globais Rápidos -->
                                <div class="row mb-3 p-3 bg-light rounded">
                                    <div class="col-md-3">
                                        <label for="valor_global_parcelas" class="form-label small">Valor Global</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor_global_parcelas" 
                                                   step="0.01" min="0" placeholder="Ex: 150.00">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="desconto_global_parcelas" class="form-label small">Desconto PIX Global</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="desconto_global_parcelas" 
                                                   step="0.01" min="0" placeholder="Ex: 25.00">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="minimo_global_parcelas" class="form-label small">Valor Mínimo Global</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="minimo_global_parcelas" 
                                                   step="0.01" min="0" placeholder="Ex: 50.00">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">PIX Global</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="pix_global_parcelas">
                                            <label class="form-check-label small" for="pix_global_parcelas">
                                                Habilitar PIX em todas
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Tabela de Parcelas -->
                                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-dark sticky-top">
                                            <tr>
                                                <th width="8%">#</th>
                                                <th width="20%">Descrição</th>
                                                <th width="12%">Vencimento</th>
                                                <th width="15%">Valor (R$)</th>
                                                <th width="10%">PIX</th>
                                                <th width="15%">Desconto (R$)</th>
                                                <th width="12%">Mín. (R$)</th>
                                                <th width="8%">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tabelaParcelasIndividuais">
                                            <!-- Parcelas geradas dinamicamente -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Resumo Final -->
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <div class="card border-success">
                                            <div class="card-body text-center p-2">
                                                <h6 class="card-title text-success mb-1">
                                                    <i class="fas fa-calculator"></i> Valor Total
                                                </h6>
                                                <h5 class="mb-0" id="valorTotalFinal">R$ 0,00</h5>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-info">
                                            <div class="card-body text-center p-2">
                                                <h6 class="card-title text-info mb-1">
                                                    <i class="fas fa-qrcode"></i> Total com PIX
                                                </h6>
                                                <h5 class="mb-0" id="valorPixFinal">R$ 0,00</h5>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card border-warning">
                                            <div class="card-body text-center p-2">
                                                <h6 class="card-title text-warning mb-1">
                                                    <i class="fas fa-piggy-bank"></i> Economia Total
                                                </h6>
                                                <h5 class="mb-0" id="economiaFinal">R$ 0,00</h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-gerar-parcelas" id="btnGerarParcelasIndividuais" disabled>
                                    <i class="fas fa-qrcode"></i> Gerar Parcelas PIX Personalizadas
                                </button>
                            </div>
                        </form>
                        
                        <hr class="my-4">
                        
                        <!-- Upload Múltiplo Tradicional (com PDFs) -->
                        <div class="section-title">
                            <i class="fas fa-files"></i> Opção 2: Enviar Múltiplos Arquivos PDF
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="uploadMultiploAlunoForm">
                            <input type="hidden" name="acao" value="upload_multiplo_aluno">
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="polo_multiplo" class="form-label">
                                            <i class="fas fa-map-marker-alt"></i> Polo
                                        </label>
                                        <select class="form-select" id="polo_multiplo" name="polo" required>
                                            <option value="">Selecione o polo</option>
                                            <?php foreach ($polosAtivos as $polo): ?>
                                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                                <option value="<?= $polo ?>">
                                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="curso_multiplo" class="form-label">
                                            <i class="fas fa-book"></i> Curso
                                        </label>
                                        <select class="form-select" id="curso_multiplo" name="curso_id" required>
                                            <option value="">Primeiro selecione o polo</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="aluno_cpf_multiplo" class="form-label">
                                            <i class="fas fa-user"></i> CPF do Aluno
                                        </label>
                                        <input type="text" class="form-control" id="aluno_cpf_multiplo" name="aluno_cpf" 
                                               placeholder="000.000.000-00" maxlength="14" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Upload Zone Múltiplo -->
                            <div class="mb-3">
                                <div class="upload-zone upload-zone-multiple" id="uploadZoneMultiplo">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <h5>Arraste múltiplos arquivos PDF aqui ou clique para selecionar</h5>
                                    <p class="text-muted">
                                        Selecione todos os PDFs que deseja enviar para este aluno<br>
                                        Máximo 5MB por arquivo • Apenas arquivos PDF
                                    </p>
                                    <input type="file" id="arquivos_multiplos" name="arquivos_multiplos[]" 
                                           accept=".pdf" multiple hidden required>
                                </div>
                            </div>
                            
                            <div id="fileListMultiplo" class="file-list" style="display: none;">
                                <h6><i class="fas fa-list"></i> Arquivos Selecionados:</h6>
                                <div id="fileListContent"></div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="aplicarValoresGlobais()">
                                            <i class="fas fa-magic"></i> Aplicar Valores Globais
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="gerarNumerosSequenciais()">
                                            <i class="fas fa-sort-numeric-up"></i> Números Sequenciais
                                        </button>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="limparArquivosMultiplo()">
                                            <i class="fas fa-times"></i> Limpar Todos
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Configurações Globais para PDF -->
                            <div class="section-title mt-4">
                                <i class="fas fa-cogs"></i> Configurações Globais (Opcional)
                            </div>
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="valor_global" class="form-label">
                                            <i class="fas fa-dollar-sign"></i> Valor Padrão
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor_global" 
                                                   step="0.01" min="0" placeholder="Ex: 150.00">
                                        </div>
                                        <small class="form-text text-muted">Será aplicado a todos os boletos</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="vencimento_global" class="form-label">
                                            <i class="fas fa-calendar"></i> Vencimento Base
                                        </label>
                                        <input type="date" class="form-control" id="vencimento_global">
                                        <small class="form-text text-muted">Data base para sequência mensal</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="descricao_global" class="form-label">
                                            <i class="fas fa-comment"></i> Descrição Padrão
                                        </label>
                                        <input type="text" class="form-control" id="descricao_global" 
                                               placeholder="Ex: Mensalidade">
                                        <small class="form-text text-muted">Será usada para todos os boletos</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-qrcode"></i> Desconto PIX Global
                                        </label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="pix_desconto_global" 
                                                   onchange="togglePixDescontoGlobal()">
                                            <label class="form-check-label" for="pix_desconto_global">
                                                Aplicar desconto a todos
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">Desconto PIX para todos os boletos</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-upload-multiple" id="btnEnviarMultiplo" disabled>
                                    <i class="fas fa-user-plus"></i> Enviar Boletos para o Aluno
                                </button>
                            </div>
                        </form>
                    </div>                          



                    
                    <!-- ========== ABA 3: UPLOAD EM LOTE ========== -->
                    <div class="tab-pane fade" id="lote" role="tabpanel">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Upload em Lote:</strong> Você pode enviar múltiplos arquivos PDF ao mesmo tempo. 
                            Certifique-se de que os nomes dos arquivos sigam o padrão: <code>CPF_NUMEROBANTO.pdf</code>
                            <br><small>Exemplo: <code>12345678901_202412150001.pdf</code></small>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="uploadLoteForm">
                            <input type="hidden" name="acao" value="upload_lote">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="polo_lote" class="form-label">
                                            <i class="fas fa-map-marker-alt"></i> Polo
                                        </label>
                                        <select class="form-select" id="polo_lote" name="polo" required>
                                            <option value="">Selecione o polo</option>
                                            <?php foreach ($polosAtivos as $polo): ?>
                                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                                <option value="<?= $polo ?>">
                                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="curso_lote" class="form-label">
                                            <i class="fas fa-book"></i> Curso
                                        </label>
                                        <select class="form-select" id="curso_lote" name="curso_id" required>
                                            <option value="">Primeiro selecione o polo</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="valor_lote" class="form-label">
                                            <i class="fas fa-dollar-sign"></i> Valor Padrão
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor_lote" name="valor" 
                                                   step="0.01" min="0" placeholder="0,00" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="vencimento_lote" class="form-label">
                                            <i class="fas fa-calendar"></i> Data de Vencimento
                                        </label>
                                        <input type="date" class="form-control" id="vencimento_lote" name="vencimento" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="descricao_lote" class="form-label">
                                            <i class="fas fa-comment"></i> Descrição
                                        </label>
                                        <input type="text" class="form-control" id="descricao_lote" name="descricao" 
                                               placeholder="Ex: Mensalidade Janeiro 2024">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-qrcode"></i> Desconto PIX
                                        </label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="pix_desconto_lote" 
                                                   name="pix_desconto_global" value="1" onchange="togglePixDescontoLote()">
                                            <label class="form-check-label" for="pix_desconto_lote">
                                                Aplicar a todos
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">Desconto PIX para todos os boletos do lote</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Configurações de Desconto para Lote -->
                            <div id="pixDescontoLoteControls" class="pix-desconto-section collapsed" style="display: none;">
                                <div class="pix-desconto-title">
                                    <i class="fas fa-qrcode"></i> 
                                    Configuração de Desconto PIX para o Lote
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="valor_desconto_lote" class="form-label">
                                            <i class="fas fa-percentage"></i> Valor do Desconto
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor_desconto_lote" 
                                                   name="valor_desconto_lote" step="0.01" min="0" placeholder="Ex: 50,00">
                                        </div>
                                        <small class="form-text text-muted">Valor fixo aplicado a todos os boletos do lote</small>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="valor_minimo_lote" class="form-label">
                                            <i class="fas fa-calculator"></i> Valor Mínimo (Opcional)
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor_minimo_lote" 
                                                   name="valor_minimo_lote" step="0.01" min="0" placeholder="Ex: 100,00">
                                        </div>
                                        <small class="form-text text-muted">Valor mínimo para aplicar desconto</small>
                                    </div>
                                </div>
                                
                                <div class="alert alert-pix mt-3">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Desconto em Lote:</strong> Todos os boletos deste lote terão o mesmo desconto PIX. 
                                    O desconto estará disponível até a data de vencimento de cada boleto.
                                </div>
                            </div>
                            
                            <div id="dropzoneLote" class="dropzone">
                                <div class="dz-message">
                                    <i class="fas fa-cloud-upload-alt fa-3x mb-3"></i>
                                    <h5>Arraste múltiplos arquivos PDF aqui ou clique para selecionar</h5>
                                    <p class="text-muted">
                                        Nomeie os arquivos como: <code>CPF_NUMEROBANTO.pdf</code><br>
                                        Máximo 5MB por arquivo
                                    </p>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-upload">
                                    <i class="fas fa-upload"></i> Processar Upload em Lote
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- ========== ABA 4: INSTRUÇÕES ========== -->
                    <div class="tab-pane fade" id="instrucoes" role="tabpanel">
                        <div class="row">
                            <div class="col-md-4">
                                <h5 class="section-title">
                                    <i class="fas fa-file"></i> Upload Individual
                                </h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Selecione o polo e curso do aluno
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Digite o CPF do aluno destinatário
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Informe valor e data de vencimento
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Configure desconto PIX personalizado
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Arquivo PDF máximo de 5MB
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="col-md-4">
                                <h5 class="section-title">
                                    <i class="fas fa-qrcode text-success"></i> Parcelas PIX Automáticas
                                </h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <i class="fas fa-star text-warning"></i>
                                        <strong>NOVA FUNCIONALIDADE!</strong>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Gere 2 a 12 parcelas automaticamente
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Apenas PIX - sem arquivo PDF necessário
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Desconto PIX configurável por parcela
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Preview das parcelas antes de gerar
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Numeração e datas automáticas
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="col-md-4">
                                <h5 class="section-title">
                                    <i class="fas fa-files"></i> Upload em Lote
                                </h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Nomeie arquivos como: <code>CPF_NUMEROBANTO.pdf</code>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Desconto PIX global para todo o lote
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Todos boletos terão mesmo valor e vencimento
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Sistema valida se aluno existe no curso
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Processamento automático em segundo plano
                                    </li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <h5 class="section-title">
                                <i class="fas fa-qrcode text-success"></i> Sistema de Desconto PIX Personalizado
                            </h5>
                            
                            <div class="alert alert-success">
                                <h6><i class="fas fa-gift"></i> Como Funciona o Desconto PIX:</h6>
                                <ul class="mb-0">
                                    <li><strong>Personalizado:</strong> Você define o valor exato do desconto para cada boleto</li>
                                    <li><strong>Disponibilidade:</strong> Desconto disponível apenas até a data de vencimento</li>
                                    <li><strong>Flexibilidade:</strong> Funciona em qualquer polo sem configuração prévia</li>
                                    <li><strong>Controle Individual:</strong> Você decide quais boletos têm desconto</li>
                                    <li><strong>Valor Mínimo:</strong> Opcional - defina valor mínimo para aplicar desconto</li>
                                    <li><strong>Uso Único:</strong> Cada boleto pode usar o desconto apenas uma vez</li>
                                    <li><strong>Aplicação Automática:</strong> Sistema calcula automaticamente no PIX</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <h5 class="section-title">
                                <i class="fas fa-magic text-info"></i> Exemplo Prático - Parcelas PIX
                            </h5>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-lightbulb"></i> Caso de Uso Real:</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>📋 Entrada:</strong>
                                        <ul class="mt-2">
                                            <li>Aluno: João Silva</li>
                                            <li>CPF: 123.456.789-00</li>
                                            <li>Curso: Engenharia Civil</li>
                                            <li>6 parcelas de R$ 150,00</li>
                                            <li>Total: R$ 900,00</li>
                                            <li>Primeira parcela: 15/08/2025</li>
                                            <li>Desconto PIX: R$ 25,00 por parcela</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>✅ Resultado:</strong>
                                        <ul class="mt-2">
                                            <li>📅 15/08/2025 - Mensalidade 01/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                            <li>📅 15/09/2025 - Mensalidade 02/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                            <li>📅 15/10/2025 - Mensalidade 03/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                            <li>📅 15/11/2025 - Mensalidade 04/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                            <li>📅 15/12/2025 - Mensalidade 05/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                            <li>📅 15/01/2026 - Mensalidade 06/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                            <li class="text-success"><strong>💚 Economia total: R$ 150,00</strong></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <h5 class="section-title">
                                <i class="fas fa-exclamation-triangle text-warning"></i> Importantes
                            </h5>
                            
                            <div class="alert alert-warning">
                                <ul class="mb-0">
                                    <li><strong>Formato de arquivo:</strong> Apenas PDF é aceito</li>
                                    <li><strong>Tamanho máximo:</strong> 5MB por arquivo</li>
                                    <li><strong>Validação:</strong> Sistema verifica se aluno está matriculado no curso</li>
                                    <li><strong>Duplicatas:</strong> Números de boleto devem ser únicos</li>
                                    <li><strong>Desconto PIX:</strong> Você define o valor - sem limites por polo</li>
                                    <li><strong>Nomenclatura:</strong> Para upload em lote, siga exatamente o padrão</li>
                                    <li><strong>Segurança:</strong> Arquivos são armazenados de forma segura</li>
                                    <li><strong>Valor Final:</strong> Sistema garante valor mínimo de R$ 10,00 após desconto</li>
                                    <li><strong>Parcelas PIX:</strong> Sem arquivo PDF necessário - apenas dados</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/dropzone.min.js"></script>


    <!-- PARTE 5 -->

    <script>
// ========== VARIÁVEIS GLOBAIS ==========
let fileListMultiplo = [];
let isUpdating = false;
let parcelasIndividuais = []; // NOVO: para parcelas individuais

// ========== MÁSCARAS DE CPF ==========
function aplicarMascaraCPF(elemento) {
    if (elemento) {
        elemento.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
    }
}

// ========== FUNÇÕES DE DESCONTO PIX ==========

// Toggle do desconto PIX individual
function togglePixDesconto() {
    const checkbox = document.getElementById('pix_desconto_disponivel');
    const controls = document.getElementById('pixDescontoControls');
    const section = document.getElementById('pixDescontoSection');
    
    if (checkbox && controls && section) {
        if (checkbox.checked) {
            controls.classList.add('show');
            section.classList.remove('collapsed');
            
            setTimeout(() => {
                const valorDescontoInput = document.getElementById('valor_desconto_pix');
                if (valorDescontoInput) valorDescontoInput.focus();
            }, 300);
        } else {
            controls.classList.remove('show');
            section.classList.add('collapsed');
            
            const valorDescontoInput = document.getElementById('valor_desconto_pix');
            const valorMinimoInput = document.getElementById('valor_minimo_desconto');
            if (valorDescontoInput) valorDescontoInput.value = '';
            if (valorMinimoInput) valorMinimoInput.value = '';
        }
    }
}

// Toggle do desconto PIX global (múltiplo)
function togglePixDescontoGlobal() {
    const checkbox = document.getElementById('pix_desconto_global');
    const controls = document.getElementById('pixDescontoGlobalControls');
    
    if (checkbox && controls) {
        if (checkbox.checked) {
            controls.style.display = 'block';
            controls.classList.remove('collapsed');
            
            setTimeout(() => {
                const valorDescontoInput = document.getElementById('valor_desconto_global');
                if (valorDescontoInput) valorDescontoInput.focus();
            }, 300);
        } else {
            controls.style.display = 'none';
            controls.classList.add('collapsed');
            
            const valorDescontoInput = document.getElementById('valor_desconto_global');
            const valorMinimoInput = document.getElementById('valor_minimo_global');
            if (valorDescontoInput) valorDescontoInput.value = '';
            if (valorMinimoInput) valorMinimoInput.value = '';
        }
    }
}

// Toggle do desconto PIX lote
function togglePixDescontoLote() {
    const checkbox = document.getElementById('pix_desconto_lote');
    const controls = document.getElementById('pixDescontoLoteControls');
    
    if (checkbox && controls) {
        if (checkbox.checked) {
            controls.style.display = 'block';
            controls.classList.remove('collapsed');
            
            setTimeout(() => {
                const valorDescontoInput = document.getElementById('valor_desconto_lote');
                if (valorDescontoInput) valorDescontoInput.focus();
            }, 300);
        } else {
            controls.style.display = 'none';
            controls.classList.add('collapsed');
            
            const valorDescontoInput = document.getElementById('valor_desconto_lote');
            const valorMinimoInput = document.getElementById('valor_minimo_lote');
            if (valorDescontoInput) valorDescontoInput.value = '';
            if (valorMinimoInput) valorMinimoInput.value = '';
        }
    }
}

// ========== FUNCIONALIDADE DE PARCELAS PIX ==========

// Toggle do desconto PIX para parcelas
function toggleParcelasPixDesconto() {
    const checkbox = document.getElementById('parcelas_pix_desconto');
    const controls = document.getElementById('parcelasPixDescontoControls');
    
    if (checkbox && controls) {
        if (checkbox.checked) {
            controls.style.display = 'block';
            setTimeout(() => {
                const valorDescontoInput = document.getElementById('parcelas_valor_desconto');
                if (valorDescontoInput) valorDescontoInput.focus();
            }, 300);
        } else {
            controls.style.display = 'none';
            const valorDescontoInput = document.getElementById('parcelas_valor_desconto');
            const valorMinimoInput = document.getElementById('parcelas_valor_minimo');
            if (valorDescontoInput) valorDescontoInput.value = '';
            if (valorMinimoInput) valorMinimoInput.value = '';
            atualizarDescontoTotal();
        }
        verificarFormularioParcelas();
    }
}

// Cálculo automático do valor da parcela
function calcularValorParcela() {
    const valorTotalInput = document.getElementById('valor_total_parcelas');
    const quantidadeSelect = document.getElementById('quantidade_parcelas');
    const valorParcelaInput = document.getElementById('valor_parcela');
    
    if (valorTotalInput && quantidadeSelect && valorParcelaInput) {
        const valorTotal = parseFloat(valorTotalInput.value) || 0;
        const quantidade = parseInt(quantidadeSelect.value) || 0;
        
        if (valorTotal > 0 && quantidade > 0) {
            const valorParcela = valorTotal / quantidade;
            valorParcelaInput.value = valorParcela.toFixed(2);
            
            // Atualiza o preview se estiver visível
            const previewElement = document.getElementById('preview_parcelas');
            if (previewElement && previewElement.style.display !== 'none') {
                gerarPreviewParcelas();
            }
            
            // Atualiza desconto total
            atualizarDescontoTotal();
            
            // Habilita o botão se todos os campos estão preenchidos
            verificarFormularioParcelas();
        } else {
            valorParcelaInput.value = '';
            const btnGerar = document.getElementById('btnGerarParcelas');
            if (btnGerar) btnGerar.disabled = true;
        }
    }
}

// Atualiza desconto total
function atualizarDescontoTotal() {
    const quantidadeSelect = document.getElementById('quantidade_parcelas');
    const valorDescontoInput = document.getElementById('parcelas_valor_desconto');
    const descontoInfoElement = document.getElementById('desconto_total_info');
    
    if (quantidadeSelect && valorDescontoInput && descontoInfoElement) {
        const quantidade = parseInt(quantidadeSelect.value) || 0;
        const valorDesconto = parseFloat(valorDescontoInput.value) || 0;
        
        const descontoTotal = quantidade * valorDesconto;
        
        if (descontoTotal > 0) {
            descontoInfoElement.innerHTML = `<strong style="color: var(--pix-discount-color);">R$ ${descontoTotal.toFixed(2).replace('.', ',')}</strong>`;
        } else {
            descontoInfoElement.innerHTML = '<strong>R$ 0,00</strong>';
        }
    }
}

// Gerar preview das parcelas
function gerarPreviewParcelas() {
    const quantidade = parseInt(document.getElementById('quantidade_parcelas')?.value);
    const valorParcela = parseFloat(document.getElementById('valor_parcela')?.value);
    const primeiraParcela = document.getElementById('primeira_parcela')?.value;
    const descricaoBase = document.getElementById('descricao_parcelas')?.value;
    const temDesconto = document.getElementById('parcelas_pix_desconto')?.checked;
    const valorDesconto = parseFloat(document.getElementById('parcelas_valor_desconto')?.value) || 0;
    
    if (!quantidade || !valorParcela || !primeiraParcela || !descricaoBase) {
        showToast('Preencha todos os campos obrigatórios para visualizar', 'warning');
        return;
    }
    
    // Gerar resumo financeiro
    let html = `
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h5 class="card-title text-info">
                            <i class="fas fa-calculator"></i> Resumo Financeiro
                        </h5>
                        <p class="mb-1"><strong>Total:</strong> R$ ${(quantidade * valorParcela).toFixed(2).replace('.', ',')}</p>
                        <p class="mb-1"><strong>Parcelas:</strong> ${quantidade}x de R$ ${valorParcela.toFixed(2).replace('.', ',')}</p>
                        ${temDesconto && valorDesconto > 0 ? `
                            <p class="mb-1 text-success"><strong>Com PIX:</strong> ${quantidade}x de R$ ${Math.max(10, valorParcela - valorDesconto).toFixed(2).replace('.', ',')}</p>
                            <p class="mb-0 text-success"><strong>Economia Total:</strong> R$ ${(quantidade * Math.min(valorDesconto, valorParcela - 10)).toFixed(2).replace('.', ',')}</p>
                        ` : '<p class="mb-0 text-muted">Sem desconto PIX</p>'}
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <h5 class="card-title text-warning">
                            <i class="fas fa-info-circle"></i> Detalhes
                        </h5>
                        <p class="mb-1"><strong>Primeira parcela:</strong> ${new Date(primeiraParcela).toLocaleDateString('pt-BR')}</p>
                        <p class="mb-1"><strong>Última parcela:</strong> ${new Date(new Date(primeiraParcela).setMonth(new Date(primeiraParcela).getMonth() + quantidade - 1)).toLocaleDateString('pt-BR')}</p>
                        <p class="mb-1"><strong>Descrição:</strong> ${descricaoBase}</p>
                        <p class="mb-0"><strong>Tipo:</strong> <span class="badge bg-info">Apenas PIX</span></p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    html += '<div class="preview-parcelas-table">';
    html += '<table class="table table-sm table-hover mb-0">';
    html += '<thead class="table-dark"><tr>';
    html += '<th width="8%">#</th><th width="30%">Descrição</th><th width="15%">Vencimento</th><th width="15%">Valor</th>';
    if (temDesconto && valorDesconto > 0) {
        html += '<th width="16%">Desconto PIX</th><th width="16%">Valor c/ PIX</th>';
    }
    html += '</tr></thead><tbody>';
    
    const dataBase = new Date(primeiraParcela);
    
    for (let i = 1; i <= quantidade; i++) {
        const dataVencimento = new Date(dataBase);
        dataVencimento.setMonth(dataVencimento.getMonth() + (i - 1));
        
        const parcelaFormatada = String(i).padStart(2, '0');
        const quantidadeFormatada = String(quantidade).padStart(2, '0');
        const descricao = `${descricaoBase} ${parcelaFormatada}/${quantidadeFormatada}`;
        const vencimentoFormatado = dataVencimento.toLocaleDateString('pt-BR');
        const valorFormatado = `R$ ${valorParcela.toFixed(2).replace('.', ',')}`;
        
        // Classe da linha baseada no vencimento
        const dataAtual = new Date();
        const isProximo = dataVencimento <= new Date(dataAtual.getTime() + (30 * 24 * 60 * 60 * 1000)); // 30 dias
        const rowClass = isProximo ? 'table-warning' : '';
        
        html += `<tr class="${rowClass}">`;
        html += `<td><strong>${i}ª</strong></td>`;
        html += `<td>${descricao}</td>`;
        html += `<td>${vencimentoFormatado}</td>`;
        html += `<td>${valorFormatado}</td>`;
        
        if (temDesconto && valorDesconto > 0) {
            const valorComDesconto = Math.max(10, valorParcela - valorDesconto);
            const descontoAplicado = valorParcela - valorComDesconto;
            
            html += `<td class="text-success">-R$ ${descontoAplicado.toFixed(2).replace('.', ',')}</td>`;
            html += `<td class="valor-com-desconto">R$ ${valorComDesconto.toFixed(2).replace('.', ',')}</td>`;
        }
        
        html += `</tr>`;
    }
    
    html += '</tbody></table></div>';
    
    if (temDesconto && valorDesconto > 0) {
        const economiaTotal = quantidade * Math.min(valorDesconto, valorParcela - 10);
        html += `<div class="economia-total mt-3">`;
        html += `<i class="fas fa-piggy-bank text-success"></i> `;
        html += `<strong>Economia total possível com PIX: R$ ${economiaTotal.toFixed(2).replace('.', ',')}</strong>`;
        html += `<br><small class="text-muted">Desconto aplicado apenas até a data de vencimento de cada parcela</small>`;
        html += `</div>`;
    }
    
    const previewContent = document.getElementById('preview_parcelas_content');
    const previewElement = document.getElementById('preview_parcelas');
    
    if (previewContent && previewElement) {
        previewContent.innerHTML = html;
        previewElement.style.display = 'block';
        previewElement.classList.add('preview-parcelas');
        
        // Scroll suave para o preview
        previewElement.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'nearest' 
        });
        
        showToast('Preview Gerado', `${quantidade} parcelas calculadas com sucesso!`, 'success');
    }
}

// Verificar se formulário de parcelas está completo
function verificarFormularioParcelas() {
    const campos = [
        'polo_parcelas',
        'curso_parcelas', 
        'aluno_cpf_parcelas',
        'quantidade_parcelas',
        'valor_total_parcelas',
        'primeira_parcela',
        'descricao_parcelas'
    ];
    
    let todosCamposPreenchidos = true;
    
    campos.forEach(campo => {
        const elemento = document.getElementById(campo);
        if (!elemento || !elemento.value || elemento.value.trim() === '') {
            todosCamposPreenchidos = false;
        }
    });
    
    // Verifica se tem desconto PIX mas não tem valor
    const temDesconto = document.getElementById('parcelas_pix_desconto')?.checked;
    const valorDesconto = document.getElementById('parcelas_valor_desconto')?.value;
    
    if (temDesconto && (!valorDesconto || parseFloat(valorDesconto) <= 0)) {
        todosCamposPreenchidos = false;
    }
    
    const botao = document.getElementById('btnGerarParcelas');
    if (botao) {
        botao.disabled = !todosCamposPreenchidos;
        
        if (todosCamposPreenchidos) {
            botao.classList.remove('btn-secondary');
            botao.classList.add('btn-gerar-parcelas');
        } else {
            botao.classList.remove('btn-gerar-parcelas');
            botao.classList.add('btn-secondary');
        }
    }
}

// ========== PARCELAS PIX INDIVIDUAIS (CORRIGIDO) ==========

// Gerar lista inicial de parcelas
function gerarListaParcelas() {
    console.log('🚀 Iniciando geração de lista de parcelas...');
    
    const quantidade = parseInt(document.getElementById('quantidade_parcelas')?.value);
    const primeiraParcela = document.getElementById('primeira_parcela')?.value;
    const descricaoBase = document.getElementById('descricao_parcelas')?.value;
    
    console.log('📊 Dados coletados:', {quantidade, primeiraParcela, descricaoBase});
    
    if (!quantidade || !primeiraParcela || !descricaoBase) {
        showToast('Preencha quantidade, data da primeira parcela e descrição base', 'warning');
        return;
    }
    
    if (quantidade < 1 || quantidade > 32) {
        showToast('Quantidade deve ser entre 1 e 32 parcelas', 'error');
        return;
    }
    
    // Limpar lista anterior
    parcelasIndividuais = [];
    
    // Gerar parcelas
    const dataBase = new Date(primeiraParcela);
    
    for (let i = 1; i <= quantidade; i++) {
        const dataVencimento = new Date(dataBase);
        dataVencimento.setMonth(dataVencimento.getMonth() + (i - 1));
        
        const parcelaFormatada = String(i).padStart(2, '0');
        const quantidadeFormatada = String(quantidade).padStart(2, '0');
        const descricao = `${descricaoBase} ${parcelaFormatada}/${quantidadeFormatada}`;
        
        parcelasIndividuais.push({
            id: i,
            numero: i,
            descricao: descricao,
            vencimento: dataVencimento.toISOString().split('T')[0],
            valor: 0,
            pix_disponivel: false,
            valor_desconto: 0,
            valor_minimo: 0
        });
    }
    
    console.log('✅ Parcelas geradas:', parcelasIndividuais.length);
    
    atualizarTabelaParcelas();
    document.getElementById('listaParcelasIndividuais').style.display = 'block';
    
    // Scroll suave para a lista
    document.getElementById('listaParcelasIndividuais').scrollIntoView({ 
        behavior: 'smooth', 
        block: 'nearest' 
    });
    
    showToast(`${quantidade} parcelas criadas! Configure cada uma individualmente.`, 'success');
}

// Atualizar tabela de parcelas
function atualizarTabelaParcelas() {
    const tbody = document.getElementById('tabelaParcelasIndividuais');
    if (!tbody || parcelasIndividuais.length === 0) {
        console.log('⚠️ Tbody não encontrado ou sem parcelas');
        return;
    }
    
    let html = '';
    
    parcelasIndividuais.forEach((parcela, index) => {
        const isValid = parcela.valor > 0;
        const rowClass = isValid ? '' : 'table-warning';
        
        html += `
            <tr class="${rowClass}">
                <td class="text-center">
                    <strong>${parcela.numero}ª</strong>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" 
                           value="${parcela.descricao}"
                           onchange="atualizarParcela(${index}, 'descricao', this.value)">
                </td>
                <td>
                    <input type="date" class="form-control form-control-sm" 
                           value="${parcela.vencimento}"
                           onchange="atualizarParcela(${index}, 'vencimento', this.value)">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm" 
                           step="0.01" min="0" placeholder="0.00"
                           value="${parcela.valor || ''}"
                           onchange="atualizarParcela(${index}, 'valor', parseFloat(this.value) || 0)">
                </td>
                <td class="text-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" 
                               ${parcela.pix_disponivel ? 'checked' : ''}
                               onchange="atualizarParcela(${index}, 'pix_disponivel', this.checked)">
                    </div>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm" 
                           step="0.01" min="0" placeholder="0.00"
                           value="${parcela.valor_desconto || ''}"
                           ${parcela.pix_disponivel ? '' : 'disabled'}
                           onchange="atualizarParcela(${index}, 'valor_desconto', parseFloat(this.value) || 0)">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm" 
                           step="0.01" min="0" placeholder="0.00"
                           value="${parcela.valor_minimo || ''}"
                           ${parcela.pix_disponivel ? '' : 'disabled'}
                           onchange="atualizarParcela(${index}, 'valor_minimo', parseFloat(this.value) || 0)">
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-primary" 
                            onclick="duplicarValorParcela(${index})" title="Duplicar para próximas">
                        <i class="fas fa-copy"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    calcularValoresTotais();
    verificarFormularioCompleto();
}

// Atualizar uma parcela específica
function atualizarParcela(index, campo, valor) {
    if (parcelasIndividuais[index]) {
        parcelasIndividuais[index][campo] = valor;
        
        // Se desabilitou PIX, limpar desconto
        if (campo === 'pix_disponivel' && !valor) {
            parcelasIndividuais[index].valor_desconto = 0;
            parcelasIndividuais[index].valor_minimo = 0;
        }
        
        atualizarTabelaParcelas();
    }
}

// Duplicar valor para as próximas parcelas
function duplicarValorParcela(index) {
    const parcela = parcelasIndividuais[index];
    if (!parcela || parcela.valor <= 0) {
        showToast('Configure o valor desta parcela primeiro', 'warning');
        return;
    }
    
    const confirmar = confirm(`Aplicar os valores da parcela ${parcela.numero} para todas as próximas parcelas?`);
    if (!confirmar) return;
    
    for (let i = index + 1; i < parcelasIndividuais.length; i++) {
        parcelasIndividuais[i].valor = parcela.valor;
        parcelasIndividuais[i].pix_disponivel = parcela.pix_disponivel;
        parcelasIndividuais[i].valor_desconto = parcela.valor_desconto;
        parcelasIndividuais[i].valor_minimo = parcela.valor_minimo;
    }
    
    atualizarTabelaParcelas();
    showToast(`Valores aplicados às parcelas ${index + 2} até ${parcelasIndividuais.length}`, 'success');
}

// Aplicar valor global a todas as parcelas
function aplicarValorGlobalParcelas() {
    const valorGlobal = parseFloat(document.getElementById('valor_global_parcelas')?.value);
    
    if (!valorGlobal || valorGlobal <= 0) {
        showToast('Digite um valor global válido', 'warning');
        return;
    }
    
    parcelasIndividuais.forEach(parcela => {
        parcela.valor = valorGlobal;
    });
    
    atualizarTabelaParcelas();
    showToast(`Valor R$ ${valorGlobal.toFixed(2).replace('.', ',')} aplicado a todas as parcelas`, 'success');
}

// Aplicar desconto PIX global
function aplicarDescontoGlobalParcelas() {
    const descontoGlobal = parseFloat(document.getElementById('desconto_global_parcelas')?.value);
    const minimoGlobal = parseFloat(document.getElementById('minimo_global_parcelas')?.value) || 0;
    const pixGlobal = document.getElementById('pix_global_parcelas')?.checked;
    
    if (!pixGlobal && (!descontoGlobal || descontoGlobal <= 0)) {
        showToast('Marque "PIX Global" ou digite um valor de desconto', 'warning');
        return;
    }
    
    parcelasIndividuais.forEach(parcela => {
        if (pixGlobal) {
            parcela.pix_disponivel = true;
            if (descontoGlobal) parcela.valor_desconto = descontoGlobal;
            if (minimoGlobal) parcela.valor_minimo = minimoGlobal;
        }
    });
    
    atualizarTabelaParcelas();
    showToast('Configurações de PIX aplicadas a todas as parcelas', 'success');
}

// Calcular valores totais
function calcularValoresTotais() {
    let valorTotal = 0;
    let valorComPix = 0;
    let economia = 0;
    
    parcelasIndividuais.forEach(parcela => {
        const valor = parseFloat(parcela.valor) || 0;
        valorTotal += valor;
        
        if (parcela.pix_disponivel && parcela.valor_desconto > 0) {
            const desconto = Math.min(parcela.valor_desconto, valor - 10);
            const valorFinalPix = Math.max(10, valor - desconto);
            valorComPix += valorFinalPix;
            economia += (valor - valorFinalPix);
        } else {
            valorComPix += valor;
        }
    });
    
    // Atualizar displays com verificação de existência
    const elementos = {
        'valorTotalFinal': valorTotal,
        'valorPixFinal': valorComPix,
        'economiaFinal': economia,
        'resumoTotalParcelas': `Total: R$ ${valorTotal.toFixed(2).replace('.', ',')} | Com PIX: R$ ${valorComPix.toFixed(2).replace('.', ',')} | Economia: R$ ${economia.toFixed(2).replace('.', ',')}`
    };
    
    Object.entries(elementos).forEach(([id, valor]) => {
        const elemento = document.getElementById(id);
        if (elemento) {
            if (id === 'resumoTotalParcelas') {
                elemento.textContent = valor;
            } else {
                elemento.textContent = `R$ ${valor.toFixed(2).replace('.', ',')}`;
            }
        }
    });
}

// CORREÇÃO: Verificar se formulário está completo
function verificarFormularioCompleto() {
    const campos = ['polo_parcelas', 'curso_parcelas', 'aluno_cpf_parcelas'];
    let dadosValidos = true;
    
    // Verificar campos básicos
    campos.forEach(campo => {
        const elemento = document.getElementById(campo);
        if (!elemento || !elemento.value.trim()) {
            dadosValidos = false;
        }
    });
    
    // Verificar se tem parcelas válidas
    const parcelasValidas = parcelasIndividuais.filter(p => p.valor > 0);
    if (parcelasValidas.length === 0) {
        dadosValidos = false;
    }
    
    // Verificar configurações de PIX
    const parcelasComPixInvalido = parcelasIndividuais.filter(p => 
        p.pix_disponivel && (!p.valor_desconto || p.valor_desconto <= 0)
    );
    
    if (parcelasComPixInvalido.length > 0) {
        dadosValidos = false;
    }
    
    const botao = document.getElementById('btnGerarParcelasIndividuais');
    if (botao) {
        botao.disabled = !dadosValidos;
        
        if (dadosValidos) {
            botao.classList.remove('btn-secondary');
            botao.classList.add('btn-gerar-parcelas');
        } else {
            botao.classList.remove('btn-gerar-parcelas');
            botao.classList.add('btn-secondary');
        }
    }
}

// ========== UPLOAD INDIVIDUAL ==========

// Upload individual - zona de drag and drop
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('arquivo_pdf');
const filePreview = document.getElementById('filePreview');

if (uploadZone && fileInput) {
    uploadZone.addEventListener('click', () => fileInput.click());
    
    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('dragover');
    });
    
    uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('dragover');
    });
    
    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            showFilePreview(files[0]);
        }
    });
    
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            showFilePreview(e.target.files[0]);
        }
    });
}

function showFilePreview(file) {
    if (file.type !== 'application/pdf') {
        alert('Apenas arquivos PDF são aceitos!');
        const fileInput = document.getElementById('arquivo_pdf');
        if (fileInput) fileInput.value = '';
        return;
    }
    
    if (file.size > 5 * 1024 * 1024) {
        alert('Arquivo deve ter no máximo 5MB!');
        const fileInput = document.getElementById('arquivo_pdf');
        if (fileInput) fileInput.value = '';
        return;
    }
    
    const filePreview = document.getElementById('filePreview');
    if (filePreview) {
        filePreview.style.display = 'block';
        filePreview.innerHTML = `
            <div class="file-item">
                <div class="file-info">
                    <div class="file-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div>
                        <div class="fw-bold">${file.name}</div>
                        <small class="text-muted">${(file.size / 1024 / 1024).toFixed(2)} MB</small>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFile()">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
    }
}

function removeFile() {
    const fileInput = document.getElementById('arquivo_pdf');
    const filePreview = document.getElementById('filePreview');
    if (fileInput) fileInput.value = '';
    if (filePreview) filePreview.style.display = 'none';
}

// ========== CARREGAMENTO DE CURSOS ==========

function carregarCursos(poloSelect, cursoSelect) {
    const polo = poloSelect.value;
    cursoSelect.innerHTML = '<option value="">Carregando...</option>';
    
    if (!polo) {
        cursoSelect.innerHTML = '<option value="">Primeiro selecione o polo</option>';
        return;
    }
    
    fetch('/admin/api/buscar-cursos.php?polo=' + encodeURIComponent(polo))
        .then(response => response.json())
        .then(data => {
            cursoSelect.innerHTML = '<option value="">Selecione o curso</option>';
            
            if (data.success && data.cursos) {
                data.cursos.forEach(curso => {
                    const option = document.createElement('option');
                    option.value = curso.id;
                    option.textContent = curso.nome;
                    cursoSelect.appendChild(option);
                });
            } else {
                cursoSelect.innerHTML = '<option value="">Nenhum curso encontrado</option>';
            }
        })
        .catch(error => {
            console.error('Erro ao carregar cursos:', error);
            cursoSelect.innerHTML = '<option value="">Erro ao carregar cursos</option>';
        });
}

// ========== VALIDAÇÕES ==========

function validarCPF(cpf) {
    cpf = cpf.replace(/[^0-9]/, '', cpf);
    if (cpf.length != 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    
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
    return resto === parseInt(cpf.charAt(10));
}

// ========== UPLOAD MÚLTIPLO ==========

// Upload múltiplo - zona de drag and drop
const uploadZoneMultiplo = document.getElementById('uploadZoneMultiplo');
const fileInputMultiplo = document.getElementById('arquivos_multiplos');
const fileListMultiploDiv = document.getElementById('fileListMultiplo');
const fileListContent = document.getElementById('fileListContent');
const btnEnviarMultiplo = document.getElementById('btnEnviarMultiplo');

if (uploadZoneMultiplo && fileInputMultiplo) {
    uploadZoneMultiplo.addEventListener('click', () => fileInputMultiplo.click());
    
    uploadZoneMultiplo.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZoneMultiplo.classList.add('dragover');
    });
    
    uploadZoneMultiplo.addEventListener('dragleave', () => {
        uploadZoneMultiplo.classList.remove('dragover');
    });
    
    uploadZoneMultiplo.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZoneMultiplo.classList.remove('dragover');
        
        const files = Array.from(e.dataTransfer.files);
        processarArquivosMultiplo(files);
    });
    
    fileInputMultiplo.addEventListener('change', (e) => {
        const files = Array.from(e.target.files);
        processarArquivosMultiplo(files);
    });
}

function processarArquivosMultiplo(files) {
    const pdfFiles = files.filter(file => file.type === 'application/pdf');
    
    if (pdfFiles.length !== files.length) {
        showToast('Apenas arquivos PDF são aceitos. Arquivos não-PDF foram ignorados.', 'warning');
    }
    
    const validFiles = pdfFiles.filter(file => file.size <= 5 * 1024 * 1024);
    
    if (validFiles.length !== pdfFiles.length) {
        showToast('Arquivos maiores que 5MB foram ignorados.', 'warning');
    }
    
    validFiles.forEach((file, index) => {
        const fileId = Date.now() + index;
        const fileData = {
            id: fileId,
            file: file,
            nome: file.name,
            tamanho: file.size,
            numero_boleto: '',
            valor: '',
            vencimento: '',
            descricao: '',
            pix_desconto_disponivel: 0,
            valor_desconto_pix: '',
            valor_minimo_desconto: ''
        };
        
        fileListMultiplo.push(fileData);
    });
    
    atualizarListaArquivosMultiplo();
    
    if (validFiles.length > 0) {
        showToast(`${validFiles.length} arquivo(s) adicionado(s) com sucesso!`, 'success');
    }
}

function atualizarListaArquivosMultiplo() {
    if (fileListMultiplo.length === 0) {
        if (fileListMultiploDiv) fileListMultiploDiv.style.display = 'none';
        if (btnEnviarMultiplo) btnEnviarMultiplo.disabled = true;
        return;
    }
    
    if (fileListMultiploDiv) fileListMultiploDiv.style.display = 'block';
    if (btnEnviarMultiplo) btnEnviarMultiplo.disabled = false;
    
    let html = '';
    
    fileListMultiplo.forEach((fileData, index) => {
        const isValid = fileData.numero_boleto && fileData.valor && fileData.vencimento;
        const itemClass = isValid ? 'valid' : 'invalid';
        
        html += `
            <div class="file-list-item ${itemClass}" data-file-id="${fileData.id}">
                <div class="row w-100 align-items-center">
                    <div class="col-md-2">
                        <div class="file-info">
                            <div class="file-icon" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <div>
                                <div class="fw-bold small">${fileData.nome}</div>
                                <small class="text-muted">${(fileData.tamanho / 1024 / 1024).toFixed(2)} MB</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control form-control-sm" 
                               placeholder="Número do boleto" 
                               value="${fileData.numero_boleto}"
                               onchange="atualizarDadosArquivo(${fileData.id}, 'numero_boleto', this.value)">
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control form-control-sm" 
                               placeholder="Valor" step="0.01" min="0"
                               value="${fileData.valor}"
                               onchange="atualizarDadosArquivo(${fileData.id}, 'valor', this.value)">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control form-control-sm" 
                               value="${fileData.vencimento}"
                               onchange="atualizarDadosArquivo(${fileData.id}, 'vencimento', this.value)">
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control form-control-sm" 
                               placeholder="Descrição" 
                               value="${fileData.descricao}"
                               onchange="atualizarDadosArquivo(${fileData.id}, 'descricao', this.value)">
                    </div>
                    <div class="col-md-1">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" 
                                   ${fileData.pix_desconto_disponivel ? 'checked' : ''}
                                   onchange="atualizarDadosArquivo(${fileData.id}, 'pix_desconto_disponivel', this.checked ? 1 : 0)"
                                   title="Desconto PIX">
                            <label class="form-check-label small">PIX</label>
                        </div>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                onclick="removerArquivoMultiplo(${fileData.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Configurações de Desconto PIX por arquivo -->
                <div class="pix-config-row" style="display: ${fileData.pix_desconto_disponivel ? 'block' : 'none'}; margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 6px;">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label small">Valor do Desconto (R$)</label>
                            <input type="number" class="form-control form-control-sm" 
                                   placeholder="Ex: 50.00" step="0.01" min="0"
                                   value="${fileData.valor_desconto_pix}"
                                   onchange="atualizarDadosArquivo(${fileData.id}, 'valor_desconto_pix', this.value)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Valor Mínimo (R$) - Opcional</label>
                            <input type="number" class="form-control form-control-sm" 
                                   placeholder="Ex: 100.00" step="0.01" min="0"
                                   value="${fileData.valor_minimo_desconto}"
                                   onchange="atualizarDadosArquivo(${fileData.id}, 'valor_minimo_desconto', this.value)">
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    if (fileListContent) {
        fileListContent.innerHTML = html;
    }
}

function atualizarDadosArquivo(fileId, campo, valor) {
    const fileData = fileListMultiplo.find(f => f.id === fileId);
    if (fileData) {
        fileData[campo] = valor;
        
        // Se mudou o checkbox do PIX, atualiza a exibição
        if (campo === 'pix_desconto_disponivel') {
            const pixConfigRow = document.querySelector(`[data-file-id="${fileId}"] .pix-config-row`);
            if (pixConfigRow) {
                pixConfigRow.style.display = valor ? 'block' : 'none';
            }
        }
        
        atualizarListaArquivosMultiplo();
    }
}

function removerArquivoMultiplo(fileId) {
    fileListMultiplo = fileListMultiplo.filter(f => f.id !== fileId);
    atualizarListaArquivosMultiplo();
    showToast('Arquivo removido', 'info');
}

function limparArquivosMultiplo() {
    fileListMultiplo = [];
    const fileInputMultiplo = document.getElementById('arquivos_multiplos');
    if (fileInputMultiplo) fileInputMultiplo.value = '';
    atualizarListaArquivosMultiplo();
    showToast('Todos os arquivos foram removidos', 'info');
}

function aplicarValoresGlobais() {
    const valorGlobal = document.getElementById('valor_global')?.value;
    const vencimentoGlobal = document.getElementById('vencimento_global')?.value;
    const descricaoGlobal = document.getElementById('descricao_global')?.value;
    const pixDescontoGlobal = document.getElementById('pix_desconto_global')?.checked;
    const valorDescontoGlobal = document.getElementById('valor_desconto_global')?.value;
    const valorMinimoGlobal = document.getElementById('valor_minimo_global')?.value;
    
    if (!valorGlobal && !vencimentoGlobal && !descricaoGlobal && !pixDescontoGlobal) {
        showToast('Preencha pelo menos um campo global para aplicar', 'warning');
        return;
    }
    
    fileListMultiplo.forEach(fileData => {
        if (valorGlobal) fileData.valor = valorGlobal;
        if (vencimentoGlobal) fileData.vencimento = vencimentoGlobal;
        if (descricaoGlobal) fileData.descricao = descricaoGlobal;
        
        // Aplica configurações de desconto PIX global
        fileData.pix_desconto_disponivel = pixDescontoGlobal ? 1 : 0;
        if (pixDescontoGlobal) {
            if (valorDescontoGlobal) fileData.valor_desconto_pix = valorDescontoGlobal;
            if (valorMinimoGlobal) fileData.valor_minimo_desconto = valorMinimoGlobal;
        }
    });
    
    atualizarListaArquivosMultiplo();
    showToast('Valores globais aplicados a todos os arquivos!', 'success');
}

function gerarNumerosSequenciais() {
    if (fileListMultiplo.length === 0) {
        showToast('Adicione arquivos primeiro', 'warning');
        return;
    }
    
    showToast('Gerando números sequenciais...', 'info');
    
    const dataAtual = new Date().toISOString().slice(0,10).replace(/-/g, '');
    const vencimentoBase = document.getElementById('vencimento_global')?.value;
    
    fetch('/admin/api/gerar-numeros-sequenciais.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            quantidade: fileListMultiplo.length,
            prefixo_data: dataAtual,
            vencimento_base: vencimentoBase
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.numeros) {
            fileListMultiplo.forEach((fileData, index) => {
                if (data.numeros[index]) {
                    fileData.numero_boleto = data.numeros[index];
                }
            });
            
            if (vencimentoBase && vencimentoBase.trim() !== '') {
                fileListMultiplo.forEach((fileData, index) => {
                    const dataVencimento = new Date(vencimentoBase);
                    dataVencimento.setMonth(dataVencimento.getMonth() + index);
                    fileData.vencimento = dataVencimento.toISOString().split('T')[0];
                });
            }
            
            atualizarListaArquivosMultiplo();
            showToast(`${data.numeros.length} números gerados!`, 'success');
            
        } else {
            throw new Error(data.message || 'Erro ao gerar números');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        showToast('Erro ao gerar números: ' + error.message, 'error');
    });
}

// ========== DROPZONE PARA LOTE ==========

Dropzone.autoDiscover = false;

// ========== SISTEMA DE NOTIFICAÇÕES ==========

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

// ========== EVENT LISTENERS PRINCIPAIS ==========

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Sistema de Upload de Boletos carregando...');
    
    // Aplicar máscaras nos campos de CPF
    aplicarMascaraCPF(document.getElementById('aluno_cpf'));
    aplicarMascaraCPF(document.getElementById('aluno_cpf_multiplo'));
    aplicarMascaraCPF(document.getElementById('aluno_cpf_parcelas'));
    
    // Event listeners para carregar cursos
    const poloSelect = document.getElementById('polo');
    const cursoSelect = document.getElementById('curso');
    if (poloSelect && cursoSelect) {
        poloSelect.addEventListener('change', function() {
            carregarCursos(this, cursoSelect);
        });
    }
    
    const poloMultiploSelect = document.getElementById('polo_multiplo');
    const cursoMultiploSelect = document.getElementById('curso_multiplo');
    if (poloMultiploSelect && cursoMultiploSelect) {
        poloMultiploSelect.addEventListener('change', function() {
            carregarCursos(this, cursoMultiploSelect);
        });
    }
    
    const poloLoteSelect = document.getElementById('polo_lote');
    const cursoLoteSelect = document.getElementById('curso_lote');
    if (poloLoteSelect && cursoLoteSelect) {
        poloLoteSelect.addEventListener('change', function() {
            carregarCursos(this, cursoLoteSelect);
        });
    }
    
    // Event listeners para parcelas PIX
    const poloParcelasSelect = document.getElementById('polo_parcelas');
    const cursoParcelasSelect = document.getElementById('curso_parcelas');
    if (poloParcelasSelect && cursoParcelasSelect) {
        poloParcelasSelect.addEventListener('change', function() {
            carregarCursos(this, cursoParcelasSelect);
        });
    }
    
    // Event listeners para cálculo de parcelas
    const valorTotalInput = document.getElementById('valor_total_parcelas');
    const quantidadeSelect = document.getElementById('quantidade_parcelas');
    if (valorTotalInput) valorTotalInput.addEventListener('input', calcularValorParcela);
    if (quantidadeSelect) quantidadeSelect.addEventListener('change', calcularValorParcela);
    
    // Event listeners para atualizar desconto total
    const parcelasValorDescontoInput = document.getElementById('parcelas_valor_desconto');
    if (parcelasValorDescontoInput) {
        parcelasValorDescontoInput.addEventListener('input', atualizarDescontoTotal);
    }
    if (quantidadeSelect) {
        quantidadeSelect.addEventListener('change', atualizarDescontoTotal);
    }
    
    // Event listeners para verificar formulário
    const camposVerificacao = [
        'polo_parcelas', 'curso_parcelas', 'aluno_cpf_parcelas',
        'quantidade_parcelas', 'valor_total_parcelas', 'primeira_parcela',
        'descricao_parcelas', 'parcelas_valor_desconto'
    ];
    
    camposVerificacao.forEach(id => {
        const elemento = document.getElementById(id);
        if (elemento) {
            elemento.addEventListener('change', verificarFormularioParcelas);
            elemento.addEventListener('input', verificarFormularioParcelas);
        }
    });
    
    const checkboxPix = document.getElementById('parcelas_pix_desconto');
    if (checkboxPix) {
        checkboxPix.addEventListener('change', verificarFormularioParcelas);
    }
    
    // Validação do CPF para parcelas
    const cpfParcelasInput = document.getElementById('aluno_cpf_parcelas');
    if (cpfParcelasInput) {
        cpfParcelasInput.addEventListener('blur', function() {
            const cpf = this.value.replace(/\D/g, '');
            if (cpf && !validarCPF(cpf)) {
                this.classList.add('is-invalid');
                showToast('CPF inválido', 'error');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    }
    
    // Datas mínimas para campos de data
    const hoje = new Date().toISOString().split('T')[0];
    const camposData = ['vencimento', 'vencimento_lote', 'vencimento_global', 'primeira_parcela'];
    
    camposData.forEach(id => {
        const input = document.getElementById(id);
        if (input) input.min = hoje;
    });
    
    // Auto-geração de número de boleto baseado na data
    const vencimentoInput = document.getElementById('vencimento');
    if (vencimentoInput) {
        vencimentoInput.addEventListener('change', function() {
            const data = this.value.replace(/-/g, '');
            const numeroBase = data + '0001';
            
            const numeroBoletoInput = document.getElementById('numero_boleto');
            if (numeroBoletoInput && !numeroBoletoInput.value) {
                numeroBoletoInput.value = numeroBase;
            }
        });
    }
    
    // Event listeners para campos globais
    const camposGlobais = ['valor_global_parcelas', 'desconto_global_parcelas', 'minimo_global_parcelas'];
    camposGlobais.forEach(id => {
        const elemento = document.getElementById(id);
        if (elemento) {
            elemento.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (id === 'valor_global_parcelas') {
                        aplicarValorGlobalParcelas();
                    } else {
                        aplicarDescontoGlobalParcelas();
                    }
                }
            });
        }
    });
    
    // CORREÇÃO: Event listener para o formulário de parcelas individuais
    const formParcelasIndividuais = document.getElementById('gerarParcelasPixForm');
    if (formParcelasIndividuais) {
        console.log('✅ Formulário de parcelas encontrado, configurando eventos...');
        
        formParcelasIndividuais.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('📝 Iniciando validação do formulário...');
            
            // CORREÇÃO: Validação mais robusta dos elementos
            const elementos = {
                polo: document.getElementById('polo_parcelas'),
                curso: document.getElementById('curso_parcelas'),
                cpf: document.getElementById('aluno_cpf_parcelas')
            };
            
            // Verificar se todos os elementos existem
            const elementosNaoEncontrados = [];
            Object.entries(elementos).forEach(([nome, elemento]) => {
                if (!elemento) {
                    elementosNaoEncontrados.push(nome);
                    console.error(`❌ Elemento ${nome} não encontrado`);
                }
            });
            
            if (elementosNaoEncontrados.length > 0) {
                console.error('❌ Elementos não encontrados:', elementosNaoEncontrados);
                showToast('Erro interno: Alguns campos do formulário não foram encontrados. Tente recarregar a página.', 'error');
                return;
            }
            
            // Continuar com validações normais
            if (parcelasIndividuais.length === 0) {
                showToast('Gere a lista de parcelas primeiro', 'error');
                return;
            }
            
            const parcelasValidas = parcelasIndividuais.filter(p => p.valor > 0);
            if (parcelasValidas.length === 0) {
                showToast('Configure pelo menos uma parcela com valor válido', 'error');
                return;
            }
            
            // Validar configurações de PIX
            const parcelasComPixInvalido = parcelasIndividuais.filter(p => 
                p.pix_disponivel && (!p.valor_desconto || p.valor_desconto <= 0)
            );
            
            if (parcelasComPixInvalido.length > 0) {
                showToast(`${parcelasComPixInvalido.length} parcela(s) com PIX habilitado mas sem valor de desconto`, 'error');
                return;
            }
            
            // Validar valores mínimos
            const parcelasComValorBaixo = parcelasIndividuais.filter(p => p.valor > 0 && p.valor < 10);
            if (parcelasComValorBaixo.length > 0) {
                showToast(`${parcelasComValorBaixo.length} parcela(s) com valor menor que R$ 10,00`, 'error');
                return;
            }
            
            // Extrair valores dos elementos (com verificação de existência)
            const polo = elementos.polo.value;
            const curso = elementos.curso.value;
            const cpf = elementos.cpf.value;
            
            if (!polo || !curso || !cpf) {
                showToast('Preencha todos os campos obrigatórios (Polo, Curso e CPF)', 'error');
                return;
            }
            
            // Confirmação
            const valorTotal = parcelasValidas.reduce((sum, p) => sum + p.valor, 0);
            const economia = parcelasValidas.reduce((sum, p) => {
                if (p.pix_disponivel && p.valor_desconto > 0) {
                    return sum + Math.min(p.valor_desconto, p.valor - 10);
                }
                return sum;
            }, 0);
            
            const poloTexto = elementos.polo.options[elementos.polo.selectedIndex].text;
            
            let mensagem = `Confirma a geração de ${parcelasValidas.length} parcelas PIX personalizadas?\n\n`;
            mensagem += `👤 CPF: ${cpf}\n`;
            mensagem += `🏢 Polo: ${poloTexto}\n`;
            mensagem += `💰 Valor total: R$ ${valorTotal.toFixed(2).replace('.', ',')}\n`;
            mensagem += `🎯 Parcelas com PIX: ${parcelasValidas.filter(p => p.pix_disponivel).length}\n`;
            if (economia > 0) {
                mensagem += `💚 Economia total: R$ ${economia.toFixed(2).replace('.', ',')}\n`;
            }
            
            if (!confirm(mensagem)) return;
            
            // Preparar dados para envio
            const formData = new FormData(this);
            
            // CORREÇÃO: Garantir que os dados das parcelas sejam adicionados corretamente
            formData.append('parcelas_individuais', JSON.stringify(parcelasValidas));
            
            console.log('📤 Enviando dados:', {
                parcelas: parcelasValidas.length,
                valorTotal: valorTotal,
                economia: economia
            });
            
            const submitBtn = document.getElementById('btnGerarParcelasIndividuais');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando Parcelas...';
                submitBtn.disabled = true;
            }
            
            // Enviar via AJAX
            fetch('/admin/upload-boletos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                console.log('✅ Resposta recebida');
                if (data.includes('<!DOCTYPE html>') || data.includes('<html')) {
                    location.reload();
                } else {
                    showToast('Parcelas PIX geradas com sucesso!', 'success');
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                console.error('❌ Erro:', error);
                showToast('Erro ao gerar parcelas: ' + error.message, 'error');
            })
            .finally(() => {
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-qrcode"></i> Gerar Parcelas PIX Personalizadas';
                    submitBtn.disabled = false;
                }
            });
        });
    } else {
        console.error('❌ Formulário de parcelas não encontrado!');
    }
    
    // Validação do formulário individual
    const formIndividual = document.getElementById('uploadIndividualForm');
    if (formIndividual) {
        formIndividual.addEventListener('submit', function(e) {
            const cpfInput = document.getElementById('aluno_cpf');
            const arquivoInput = document.getElementById('arquivo_pdf');
            const pixDescontoCheckbox = document.getElementById('pix_desconto_disponivel');
            const valorDescontoInput = document.getElementById('valor_desconto_pix');
            
            if (cpfInput) {
                const cpf = cpfInput.value.replace(/\D/g, '');
                if (cpf.length !== 11) {
                    e.preventDefault();
                    alert('CPF deve conter 11 dígitos');
                    return;
                }
            }
            
            if (arquivoInput && !arquivoInput.files[0]) {
                e.preventDefault();
                alert('Selecione um arquivo PDF');
                return;
            }
            
            if (arquivoInput && arquivoInput.files[0] && arquivoInput.files[0].type !== 'application/pdf') {
                e.preventDefault();
                alert('Apenas arquivos PDF são aceitos');
                return;
            }
            
            if (pixDescontoCheckbox && pixDescontoCheckbox.checked && valorDescontoInput && 
                (!valorDescontoInput.value || parseFloat(valorDescontoInput.value) <= 0)) {
                e.preventDefault();
                alert('Quando o desconto PIX está marcado, o valor do desconto é obrigatório');
                return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
                submitBtn.disabled = true;
            }
        });
    }
    
    // Validação do formulário de parcelas PIX (formulário antigo)
    const formParcelasPix = document.getElementById('gerarParcelasPixForm');
    if (formParcelasPix) {
        // Verificar se não é o novo formulário (já tratado acima)
        if (!formParcelasPix.querySelector('#tabelaParcelasIndividuais')) {
            formParcelasPix.addEventListener('submit', function(e) {
                const cpfInput = document.getElementById('aluno_cpf_parcelas');
                const poloSelect = document.getElementById('polo_parcelas');
                const cursoSelect = document.getElementById('curso_parcelas');
                const quantidadeSelect = document.getElementById('quantidade_parcelas');
                const valorTotalInput = document.getElementById('valor_total_parcelas');
                const temDescontoCheckbox = document.getElementById('parcelas_pix_desconto');
                const valorDescontoInput = document.getElementById('parcelas_valor_desconto');
                
                if (!cpfInput || !poloSelect || !cursoSelect || !quantidadeSelect || !valorTotalInput) {
                    e.preventDefault();
                    console.warn('⚠️ Formulário antigo detectado - alguns elementos não encontrados');
                    return;
                }
                
                const cpf = cpfInput.value.replace(/\D/g, '');
                const polo = poloSelect.value;
                const curso = cursoSelect.value;
                const quantidade = parseInt(quantidadeSelect.value);
                const valorTotal = parseFloat(valorTotalInput.value);
                const temDesconto = temDescontoCheckbox ? temDescontoCheckbox.checked : false;
                const valorDesconto = valorDescontoInput ? parseFloat(valorDescontoInput.value) : 0;
                
                if (cpf.length !== 11) {
                    e.preventDefault();
                    alert('CPF deve conter 11 dígitos');
                    return;
                }
                
                if (!polo || !curso) {
                    e.preventDefault();
                    alert('Selecione polo e curso');
                    return;
                }
                
                if (!quantidade || quantidade < 1 || quantidade > 32) {
                    e.preventDefault();
                    alert('Quantidade de parcelas deve ser entre 1 e 32');
                    return;
                }
                
                if (!valorTotal || valorTotal <= 0) {
                    e.preventDefault();
                    alert('Valor total deve ser maior que zero');
                    return;
                }
                
                const valorParcela = valorTotal / quantidade;
                if (valorParcela < 10.00) {
                    e.preventDefault();
                    alert('Valor da parcela não pode ser menor que R$ 10,00');
                    return;
                }
                
                if (temDesconto && (!valorDesconto || valorDesconto <= 0)) {
                    e.preventDefault();
                    alert('Quando o desconto PIX está marcado, o valor do desconto é obrigatório');
                    return;
                }
                
                if (temDesconto && valorDesconto >= valorParcela) {
                    e.preventDefault();
                    alert('Valor do desconto não pode ser maior ou igual ao valor da parcela');
                    return;
                }
                
                // Confirmação antes de gerar
                const poloTexto = poloSelect.options[poloSelect.selectedIndex].text;
                const cursoTexto = cursoSelect.options[cursoSelect.selectedIndex].text;
                
                let mensagem = `Confirma a geração de ${quantidade} parcelas PIX?\n\n`;
                mensagem += `👤 CPF: ${cpfInput.value}\n`;
                mensagem += `🏢 Polo: ${poloTexto}\n`;
                mensagem += `📚 Curso: ${cursoTexto}\n`;
                mensagem += `💰 Valor total: R$ ${valorTotal.toFixed(2).replace('.', ',')}\n`;
                mensagem += `📅 Valor por parcela: R$ ${valorParcela.toFixed(2).replace('.', ',')}\n`;
                
                if (temDesconto) {
                    const economiaTotal = quantidade * valorDesconto;
                    mensagem += `🎯 Desconto PIX: R$ ${valorDesconto.toFixed(2).replace('.', ',')} por parcela\n`;
                    mensagem += `💚 Economia total: R$ ${economiaTotal.toFixed(2).replace('.', ',')}\n`;
                }
                
                if (!confirm(mensagem)) {
                    e.preventDefault();
                    return;
                }
                
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando Parcelas...';
                    submitBtn.disabled = true;
                }
            });
        }
    }
    
    // Inicializar Dropzone para lote
    const dropzoneElement = document.getElementById('dropzoneLote');
    if (dropzoneElement) {
        const dropzoneLote = new Dropzone("#dropzoneLote", {
            url: "/admin/api/upload-lote-temp.php",
            autoProcessQueue: false,
            uploadMultiple: true,
            parallelUploads: 10,
            maxFiles: 50,
            maxFilesize: 5,
            acceptedFiles: ".pdf",
            addRemoveLinks: true,
            dictDefaultMessage: `
                <i class="fas fa-cloud-upload-alt fa-3x mb-3"></i>
                <h5>Arraste múltiplos arquivos PDF aqui ou clique para selecionar</h5>
                <p class="text-muted">
                    Nomeie os arquivos como: <code>CPF_NUMEROBANTO.pdf</code><br>
                    Máximo 5MB por arquivo
                </p>
            `,
            dictRemoveFile: "Remover",
            dictCancelUpload: "Cancelar",
            dictUploadCanceled: "Upload cancelado",
            dictInvalidFileType: "Apenas arquivos PDF são aceitos",
            dictFileTooBig: "Arquivo muito grande (máximo 5MB)",
            dictMaxFilesExceeded: "Muitos arquivos (máximo 50)",
            
            init: function() {
                const submitButton = document.querySelector('#uploadLoteForm button[type="submit"]');
                const myDropzone = this;
                
                if (submitButton) {
                    submitButton.addEventListener("click", function(e) {
                        e.preventDefault();
                        
                        if (myDropzone.getQueuedFiles().length > 0) {
                            myDropzone.processQueue();
                        } else {
                            document.getElementById('uploadLoteForm')?.submit();
                        }
                    });
                }
                
                this.on("addedfile", function(file) {
                    const fileName = file.name.replace('.pdf', '');
                    const parts = fileName.split('_');
                    
                    if (parts.length !== 2) {
                        this.removeFile(file);
                        showToast('Nome do arquivo inválido: ' + file.name + '. Use o formato CPF_NUMEROBANTO.pdf', 'error');
                        return;
                    }
                    
                    const cpf = parts[0].replace(/\D/g, '');
                    if (cpf.length !== 11) {
                        this.removeFile(file);
                        showToast('CPF inválido no nome do arquivo: ' + file.name, 'error');
                        return;
                    }
                });
                
                this.on("success", function(file, response) {
                    showToast('Arquivo enviado: ' + file.name, 'success');
                });
                
                this.on("error", function(file, response) {
                    showToast('Erro no arquivo ' + file.name + ': ' + response, 'error');
                });
                
                this.on("queuecomplete", function() {
                    showToast('Upload em lote concluído!', 'success');
                    setTimeout(() => location.reload(), 2000);
                });
            }
        });
    }
    
    console.log('✅ Sistema de Upload de Boletos carregado com sucesso!');
    console.log('🔧 Funcionalidades ativas:');
    console.log('   ⚡ Upload individual com desconto PIX');
    console.log('   📊 Parcelas PIX personalizadas (CORRIGIDO)');
    console.log('   📁 Upload múltiplo e em lote');
    console.log('   🎯 Validações robustas');
});

console.log('🎉 JavaScript de Upload de Boletos COMPLETO E CORRIGIDO carregado!');
console.log('🔧 Principais correções aplicadas:');
console.log('   ✓ Validação robusta de elementos do DOM');
console.log('   ✓ Verificação de existência antes de acessar propriedades');
console.log('   ✓ Logs detalhados para debugging');
console.log('   ✓ Tratamento de erros melhorado');
console.log('   ✓ Funcionalidade de parcelas individuais corrigida');
console.log('   ✓ Sistema de notificações aprimorado');

</script>

</body>
</html>