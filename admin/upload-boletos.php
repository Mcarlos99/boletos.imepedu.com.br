<?php
/**
 * Sistema de Boletos IMEPEDU - Upload Completo com Parcelas PIX e Links PagSeguro
 * Arquivo: admin/upload-boletos.php
 * Versão: 3.0 - Com funcionalidades completas
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
            
        } elseif (isset($_POST['acao']) && $_POST['acao'] === 'inserir_link_pagseguro') {
            // 🆕 NOVA FUNCIONALIDADE: Inserir Link PagSeguro
            $resultado = $uploadService->processarLinkPagSeguro($_POST);
            
            // Monta mensagem detalhada de sucesso
            $mensagemDetalhada = "<strong>Link PagSeguro cadastrado com sucesso!</strong><br>";
            $mensagemDetalhada .= "• <strong>Boleto:</strong> {$resultado['numero_boleto']}<br>";
            $mensagemDetalhada .= "• <strong>Aluno:</strong> {$resultado['aluno_nome']}<br>";
            
            if ($resultado['valor'] > 0) {
                $mensagemDetalhada .= "• <strong>Valor:</strong> R$ " . number_format($resultado['valor'], 2, ',', '.') . "<br>";
            }
            
            if (!empty($resultado['vencimento'])) {
                $dataVencimento = date('d/m/Y', strtotime($resultado['vencimento']));
                $mensagemDetalhada .= "• <strong>Vencimento:</strong> {$dataVencimento}<br>";
            }
            
            // Adiciona informações extras se disponíveis
            if (!empty($resultado['link_info']['pagseguro_id'])) {
                $mensagemDetalhada .= "• <strong>ID PagSeguro:</strong> {$resultado['link_info']['pagseguro_id']}<br>";
            }
            
            $mensagemDetalhada .= "<br><small class='text-muted'>O aluno poderá acessar o link de cobrança através do sistema.</small>";
            
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
            --pagseguro-color: #00A868;
            --pagseguro-hover: #008552;
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
        
        /* ========== PAGSEGURO SECTIONS ========== */
        .pagseguro-form {
            background: linear-gradient(135deg, #e8f8f5, #f0faf7);
            border: 2px solid var(--pagseguro-color);
            border-radius: 12px;
            padding: 2rem;
            margin: 1.5rem 0;
            position: relative;
        }
        
        .pagseguro-form::before {
            content: "🔗 NOVO";
            position: absolute;
            top: -8px;
            left: 20px;
            background: var(--pagseguro-color);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .link-preview {
            background: rgba(0, 168, 104, 0.1);
            border: 1px solid var(--pagseguro-color);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
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
        
        .btn-pagseguro {
            background: linear-gradient(135deg, var(--pagseguro-color), var(--pagseguro-hover));
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-pagseguro:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 168, 104, 0.3);
            color: white;
        }
        
        .btn-pagseguro:disabled {
            background: #6c757d;
            transform: none;
            box-shadow: none;
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
            
            .parcelas-pix-form,
            .pagseguro-form {
                padding: 1rem;
                margin: 1rem 0;
            }
            
            .preview-parcelas-table {
                font-size: 0.85rem;
            }
            
            .btn-gerar-parcelas,
            .btn-pagseguro {
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
            <a href="/admin/pagseguro-links.php" class="nav-link">
                <i class="fas fa-link"></i>
                Links PagSeguro
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
            <small class="text-muted">Envie arquivos PDF, gere parcelas PIX ou cadastre links PagSeguro</small>
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
                Upload de Boletos PDF, Parcelas PIX e Links PagSeguro
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
                        <span class="badge bg-success ms-1">PIX</span>
                    </button>
                </li>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="lote-tab" data-bs-toggle="tab" 
                            data-bs-target="#lote" type="button" role="tab">
                        <i class="fas fa-files"></i> Upload em Lote
                    </button>
                </li>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pagseguro-tab" data-bs-toggle="tab" 
                            data-bs-target="#pagseguro" type="button" role="tab">
                        <i class="fas fa-link"></i> Link PagSeguro
                        <span class="badge bg-primary ms-1">NOVO</span>
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
                
                <!-- ========== ABA 4: LINK PAGSEGURO ========== -->
                <div class="tab-pane fade" id="pagseguro" role="tabpanel">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Link PagSeguro:</strong> Cole aqui o link de cobrança gerado no PagSeguro. 
                        O sistema associará automaticamente ao aluno e curso selecionados.
                        <br><small>Exemplo: https://cobranca.pagbank.com/ccadad8c-c682-4d8c-9d2e-08dde55056d1</small>
                    </div>
                    
                    <form method="POST" id="linkPagSeguroForm" class="pagseguro-form">
                        <input type="hidden" name="acao" value="inserir_link_pagseguro">
                        
                        <!-- Dados do Aluno -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="polo_pagseguro" class="form-label">
                                        <i class="fas fa-map-marker-alt"></i> Polo
                                    </label>
                                    <select class="form-select" id="polo_pagseguro" name="polo" required>
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
                                    <label for="curso_pagseguro" class="form-label">
                                        <i class="fas fa-book"></i> Curso
                                    </label>
                                    <select class="form-select" id="curso_pagseguro" name="curso_id" required>
                                        <option value="">Primeiro selecione o polo</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="aluno_cpf_pagseguro" class="form-label">
                                        <i class="fas fa-user"></i> CPF do Aluno
                                    </label>
                                    <input type="text" class="form-control" id="aluno_cpf_pagseguro" name="aluno_cpf" 
                                           placeholder="000.000.000-00" maxlength="14" required>
                                    <div class="form-text">CPF do aluno que receberá o link de cobrança</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Link do PagSeguro -->
                        <div class="section-title">
                            <i class="fas fa-link"></i> Link de Cobrança PagSeguro
                        </div>
                        
                        <div class="mb-3">
                            <label for="link_pagseguro" class="form-label">
                                <i class="fas fa-external-link-alt"></i> URL de Cobrança
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-link"></i>
                                </span>
                                <input type="url" class="form-control" id="link_pagseguro" name="link_pagseguro" 
                                       placeholder="https://cobranca.pagbank.com/..." required>
                                <button type="button" class="btn btn-outline-secondary" onclick="validarLinkPagSeguro()">
                                    <i class="fas fa-check"></i> Validar
                                </button>
                            </div>
                            <div class="form-text">
                                Cole aqui o link completo de cobrança gerado no painel do PagSeguro
                            </div>
                        </div>
                        
                        <!-- Dados da Cobrança (Opcionais - extraídos automaticamente do link se possível) -->
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="valor_pagseguro" class="form-label">
                                        <i class="fas fa-dollar-sign"></i> Valor (Opcional)
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="valor_pagseguro" name="valor" 
                                               step="0.01" min="0" placeholder="0,00">
                                    </div>
                                    <small class="form-text text-muted">Se não informado, será extraído do link</small>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="vencimento_pagseguro" class="form-label">
                                        <i class="fas fa-calendar"></i> Vencimento (Opcional)
                                    </label>
                                    <input type="date" class="form-control" id="vencimento_pagseguro" name="vencimento">
                                    <small class="form-text text-muted">Data limite para pagamento</small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="descricao_pagseguro" class="form-label">
                                        <i class="fas fa-comment"></i> Descrição
                                    </label>
                                    <input type="text" class="form-control" id="descricao_pagseguro" name="descricao" 
                                           placeholder="Ex: Mensalidade Janeiro 2024">
                                    <small class="form-text text-muted">Descrição para identificação interna</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Configurações Avançadas -->
                        <div class="section-title">
                            <i class="fas fa-cogs"></i> Configurações Avançadas (Opcional)
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="referencia_pagseguro" class="form-label">
                                        <i class="fas fa-tag"></i> Referência Externa
                                    </label>
                                    <input type="text" class="form-control" id="referencia_pagseguro" name="referencia" 
                                           placeholder="Ex: REF-2024-001">
                                    <small class="form-text text-muted">Referência para controle interno</small>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="tipo_cobranca" class="form-label">
                                        <i class="fas fa-credit-card"></i> Tipo de Cobrança
                                    </label>
                                    <select class="form-select" id="tipo_cobranca" name="tipo_cobranca">
                                        <option value="unica">Cobrança Única</option>
                                        <option value="parcelada">Parcelada</option>
                                        <option value="recorrente">Recorrente</option>
                                        <option value="outros">Outros</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="observacoes_pagseguro" class="form-label">
                                        <i class="fas fa-sticky-note"></i> Observações
                                    </label>
                                    <textarea class="form-control" id="observacoes_pagseguro" name="observacoes" 
                                              rows="2" placeholder="Observações internas"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Preview do Link -->
                        <div id="link_preview" class="mb-3" style="display: none;">
                            <div class="card border-info">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">
                                        <i class="fas fa-eye"></i> Preview do Link
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Link:</strong>
                                            <div id="preview_link" class="text-break small text-muted"></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div id="preview_info">
                                                <!-- Informações extraídas do link serão exibidas aqui -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Histórico de Links (se houver) -->
                        <div id="historico_links" class="mb-3" style="display: none;">
                            <div class="section-title">
                                <i class="fas fa-history"></i> Links Anteriores para este Aluno
                            </div>
                            <div id="historico_content">
                                <!-- Será preenchido via JavaScript -->
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="button" class="btn btn-outline-info me-2" onclick="testarLinkPagSeguro()">
                                <i class="fas fa-external-link-alt"></i> Testar Link
                            </button>
                            <button type="submit" class="btn btn-pagseguro" id="btnSalvarLinkPagSeguro" disabled>
                                <i class="fas fa-save"></i> Salvar Link de Cobrança
                            </button>
                        </div>
                    </form>
                </div>
                <!-- ========== ABA 5: INSTRUÇÕES ========== -->
                <div class="tab-pane fade" id="instrucoes" role="tabpanel">
                    <div class="row">
                        <div class="col-md-3">
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
                        
                        <div class="col-md-3">
                            <h5 class="section-title">
                                <i class="fas fa-qrcode text-success"></i> Parcelas PIX Automáticas
                            </h5>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-star text-warning"></i>
                                    <strong>FUNCIONALIDADE AVANÇADA!</strong>
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success"></i>
                                    Gere 1 a 32 parcelas automaticamente
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
                                    Controle individual de valores
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success"></i>
                                    Numeração e datas automáticas
                                </li>
                            </ul>
                        </div>
                        
                        <div class="col-md-3">
                            <h5 class="section-title">
                                <i class="fas fa-link text-primary"></i> Links PagSeguro
                            </h5>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-star text-warning"></i>
                                    <strong>NOVA FUNCIONALIDADE!</strong>
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success"></i>
                                    Cole links do PagBank/PagSeguro
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success"></i>
                                    Validação automática de domínios
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success"></i>
                                    Extração de informações do link
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success"></i>
                                    Histórico por aluno
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success"></i>
                                    Baixa automática via webhook
                                </li>
                            </ul>
                        </div>
                        
                        <div class="col-md-3">
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
                            <i class="fas fa-magic text-info"></i> Exemplo Prático - Parcelas PIX Individuais
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
                                        <li>6 parcelas personalizadas</li>
                                        <li>Valores variados por parcela</li>
                                        <li>Primeira parcela: 15/08/2025</li>
                                        <li>Desconto PIX individual por parcela</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <strong>✅ Resultado:</strong>
                                    <ul class="mt-2">
                                        <li>📅 15/08/2025 - Mensalidade 01/06 - R$ 200,00 (PIX: R$ 170,00)</li>
                                        <li>📅 15/09/2025 - Mensalidade 02/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                        <li>📅 15/10/2025 - Mensalidade 03/06 - R$ 150,00 (PIX: R$ 125,00)</li>
                                        <li>📅 15/11/2025 - Mensalidade 04/06 - R$ 100,00 (Sem PIX)</li>
                                        <li>📅 15/12/2025 - Mensalidade 05/06 - R$ 180,00 (PIX: R$ 150,00)</li>
                                        <li>📅 15/01/2026 - Mensalidade 06/06 - R$ 120,00 (PIX: R$ 100,00)</li>
                                        <li class="text-success"><strong>💚 Economia total: R$ 150,00</strong></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5 class="section-title">
                            <i class="fas fa-link text-primary"></i> Exemplo Prático - Links PagSeguro
                        </h5>
                        
                        <div class="alert alert-primary">
                            <h6><i class="fas fa-external-link-alt"></i> Processo Simplificado:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>🔗 No PagSeguro:</strong>
                                    <ol class="mt-2">
                                        <li>Acesse o painel PagBank/PagSeguro</li>
                                        <li>Crie uma nova cobrança</li>
                                        <li>Configure valor, vencimento, descrição</li>
                                        <li>Gere o link de cobrança</li>
                                        <li>Copie o link gerado</li>
                                    </ol>
                                </div>
                                <div class="col-md-6">
                                    <strong>💻 No Sistema IMEPEDU:</strong>
                                    <ol class="mt-2">
                                        <li>Selecione polo e curso do aluno</li>
                                        <li>Digite o CPF do aluno</li>
                                        <li>Cole o link do PagSeguro</li>
                                        <li>Sistema valida e extrai informações</li>
                                        <li>Salva automaticamente no sistema</li>
                                        <li>🎉 Pronto! Aluno pode pagar via link</li>
                                    </ol>
                                </div>
                            </div>
                            <div class="mt-3">
                                <strong>🔄 Baixa Automática:</strong> Quando o aluno pagar, o sistema recebe webhook do PagSeguro e dá baixa automaticamente!
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5 class="section-title">
                            <i class="fas fa-exclamation-triangle text-warning"></i> Informações Importantes
                        </h5>
                        
                        <div class="alert alert-warning">
                            <ul class="mb-0">
                                <li><strong>Formato de arquivo:</strong> Apenas PDF é aceito (máximo 5MB)</li>
                                <li><strong>Validação:</strong> Sistema verifica se aluno está matriculado no curso</li>
                                <li><strong>Duplicatas:</strong> Números de boleto devem ser únicos</li>
                                <li><strong>Desconto PIX:</strong> Você define o valor - sem limites por polo</li>
                                <li><strong>Nomenclatura:</strong> Para upload em lote, siga exatamente o padrão</li>
                                <li><strong>Segurança:</strong> Arquivos são armazenados de forma segura</li>
                                <li><strong>Valor Final:</strong> Sistema garante valor mínimo de R$ 10,00 após desconto</li>
                                <li><strong>Parcelas PIX:</strong> Sem arquivo PDF necessário - apenas dados</li>
                                <li><strong>Links PagSeguro:</strong> Domínios válidos: pagbank.com, pagseguro.uol.com.br, pag.ae</li>
                                <li><strong>Webhook:</strong> Configure a URL do webhook no painel do PagSeguro para baixa automática</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5 class="section-title">
                            <i class="fas fa-chart-line text-info"></i> Comparativo de Funcionalidades
                        </h5>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Funcionalidade</th>
                                        <th class="text-center">Upload Individual</th>
                                        <th class="text-center">Parcelas PIX</th>
                                        <th class="text-center">Links PagSeguro</th>
                                        <th class="text-center">Upload Lote</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Arquivo PDF Necessário</strong></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Desconto PIX Personalizado</strong></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-minus text-warning"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Múltiplas Parcelas</strong></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Baixa Automática</strong></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Controle Individual</strong></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                        <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Velocidade de Cadastro</strong></td>
                                        <td class="text-center"><span class="badge bg-warning">Média</span></td>
                                        <td class="text-center"><span class="badge bg-success">Rápida</span></td>
                                        <td class="text-center"><span class="badge bg-success">Muito Rápida</span></td>
                                        <td class="text-center"><span class="badge bg-info">Muito Rápida</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5 class="section-title">
                            <i class="fas fa-question-circle text-primary"></i> Dúvidas Frequentes
                        </h5>
                        
                        <div class="accordion" id="faqAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                        Como funciona a baixa automática dos links PagSeguro?
                                    </button>
                                </h2>
                                <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        O sistema recebe webhooks do PagSeguro sempre que o status de um pagamento muda. 
                                        Quando o aluno paga via link, o PagSeguro envia uma notificação automática e o sistema 
                                        atualiza o status do boleto para "pago" instantaneamente.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                        Qual a diferença entre Parcelas PIX e Upload Múltiplo?
                                    </button>
                                </h2>
                                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        <strong>Parcelas PIX:</strong> Gera parcelas digitais sem PDF, com controle individual de valores e descontos PIX.<br>
                                        <strong>Upload Múltiplo:</strong> Envia vários arquivos PDF para o mesmo aluno, cada um com seu próprio arquivo.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                        Posso usar desconto PIX em qualquer polo?
                                    </button>
                                </h2>
                                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Sim! O sistema de desconto PIX é flexível e funciona em todos os polos. 
                                        Você define o valor do desconto diretamente no momento do cadastro, 
                                        sem necessidade de configuração prévia por polo.
                                    </div>
                                </div>
                            </div>
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

<script>
// ========== VARIÁVEIS GLOBAIS ==========
let fileListMultiplo = [];
let isUpdating = false;
let parcelasIndividuais = []; // Para parcelas individuais

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

// ========== PARCELAS PIX INDIVIDUAIS ==========

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

// Verificar se formulário está completo
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

// ========== FUNCIONALIDADES PAGSEGURO ==========

// Validação de link PagSeguro
function validarLinkPagSeguro() {
    const linkInput = document.getElementById('link_pagseguro');
    const link = linkInput?.value?.trim();
    
    if (!link) {
        showToast('Digite o link do PagSeguro primeiro', 'warning');
        return;
    }
    
    showToast('Validando link...', 'info');
    
    // Validação local primeiro
    const validacaoLocal = validarURLPagSeguro(link);
    if (!validacaoLocal) {
        showToast('Link não é um link válido do PagBank/PagSeguro', 'error');
        linkInput.classList.add('is-invalid');
        return;
    }
    
    linkInput.classList.remove('is-invalid');
    linkInput.classList.add('is-valid');
    
    // Fazer validação via servidor
    fetch('/admin/api/validar-link-pagseguro.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ link: link })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Link válido e acessível!', 'success');
            preencherDadosExtraidos(data.info);
        } else {
            showToast('Aviso: ' + (data.message || 'Link pode estar inacessível'), 'warning');
        }
    })
    .catch(error => {
        console.error('Erro na validação:', error);
        showToast('Erro na validação, mas o link foi aceito localmente', 'warning');
    });
    
    // Gerar preview
    previewLinkPagSeguro();
}

function validarLinkPagSeguroAutomatico() {
    const linkInput = document.getElementById('link_pagseguro');
    const link = linkInput?.value?.trim();
    
    if (!link) return;
    
    const valido = validarURLPagSeguro(link);
    
    if (valido) {
        linkInput.classList.remove('is-invalid');
        linkInput.classList.add('is-valid');
        previewLinkPagSeguro();
        verificarFormularioPagSeguro();
    } else {
        linkInput.classList.add('is-invalid');
        linkInput.classList.remove('is-valid');
        ocultarPreviewLink();
    }
}

function validarURLPagSeguro(url) {
    if (!url || typeof url !== 'string') return false;
    
    // Verificar se é uma URL válida
    try {
        const urlObj = new URL(url);
        const hostname = urlObj.hostname.toLowerCase();
        
        // Domínios válidos do PagSeguro/PagBank
        const dominiosValidos = [
            'cobranca.pagbank.com',
            'cobranca.pagseguro.uol.com.br',
            'pag.ae',
            'pagbank.com.br'
        ];
        
        return dominiosValidos.some(dominio => hostname.includes(dominio));
        
    } catch (e) {
        return false;
    }
}

// Preview do link PagSeguro
function previewLinkPagSeguro() {
    const link = document.getElementById('link_pagseguro')?.value;
    const previewDiv = document.getElementById('link_preview');
    const previewLink = document.getElementById('preview_link');
    const previewInfo = document.getElementById('preview_info');
    
    if (!link || !previewDiv) return;
    
    // Extrair informações básicas do link
    const info = extrairInformacoesPagSeguro(link);
    
    // Mostrar preview
    previewDiv.style.display = 'block';
    
    if (previewLink) {
        previewLink.textContent = link;
    }
    
    if (previewInfo) {
        let html = '<div class="small">';
        
        if (info.id) {
            html += `<div><strong>ID:</strong> ${info.id}</div>`;
        }
        
        if (info.valor) {
            html += `<div><strong>Valor:</strong> R$ ${info.valor.toFixed(2).replace('.', ',')}</div>`;
        }
        
        if (info.vencimento) {
            html += `<div><strong>Vencimento:</strong> ${info.vencimento}</div>`;
        }
        
        html += `<div><strong>Domínio:</strong> ${new URL(link).hostname}</div>`;
        html += `<div class="text-success"><i class="fas fa-check"></i> Link válido</div>`;
        html += '</div>';
        
        previewInfo.innerHTML = html;
    }
    
    // Preencher campos automaticamente se não estiverem preenchidos
    if (info.valor && !document.getElementById('valor_pagseguro')?.value) {
        document.getElementById('valor_pagseguro').value = info.valor.toFixed(2);
    }
    
    if (info.vencimento && !document.getElementById('vencimento_pagseguro')?.value) {
        document.getElementById('vencimento_pagseguro').value = info.vencimento;
    }
}

function ocultarPreviewLink() {
    const previewDiv = document.getElementById('link_preview');
    if (previewDiv) {
        previewDiv.style.display = 'none';
    }
}

function extrairInformacoesPagSeguro(link) {
    const info = {
        id: null,
        valor: null,
        vencimento: null,
        descricao: 'Cobrança PagSeguro'
    };
    
    try {
        const url = new URL(link);
        
        // Extrair ID da cobrança (UUID ou outro formato)
        const pathParts = url.pathname.split('/').filter(part => part.length > 0);
        const lastPart = pathParts[pathParts.length - 1];
        
        if (lastPart && lastPart.length > 10) {
            info.id = lastPart;
        }
        
        // Extrair parâmetros da query string
        const params = new URLSearchParams(url.search);
        
        // Valor
        const valor = params.get('amount') || params.get('value') || params.get('valor');
        if (valor) {
            info.valor = parseFloat(valor.replace(',', '.'));
        }
        
        // Vencimento
        const vencimento = params.get('due_date') || params.get('vencimento');
        if (vencimento) {
            const data = new Date(vencimento);
            if (!isNaN(data.getTime())) {
                info.vencimento = data.toISOString().split('T')[0];
            }
        }
        
        // Descrição
        const descricao = params.get('description') || params.get('descricao');
        if (descricao) {
            info.descricao = descricao;
        }
        
    } catch (e) {
        console.warn('Erro ao extrair informações:', e);
    }
    
    return info;
}

// Histórico de links PagSeguro
function buscarHistoricoAluno() {
    const cpf = document.getElementById('aluno_cpf_pagseguro')?.value;
    const polo = document.getElementById('polo_pagseguro')?.value;
    
    if (!cpf || !polo) return;
    
    const cpfLimpo = cpf.replace(/\D/g, '');
    if (cpfLimpo.length !== 11) return;
    
    fetch('/admin/api/historico-pagseguro.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            cpf: cpfLimpo,
            polo: polo
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.historico && data.historico.length > 0) {
            exibirHistoricoLinks(data.historico);
        } else {
            ocultarHistoricoLinks();
        }
    })
    .catch(error => {
        console.error('Erro ao buscar histórico:', error);
        ocultarHistoricoLinks();
    });
}

function exibirHistoricoLinks(historico) {
    const historicoDiv = document.getElementById('historico_links');
    const historicoContent = document.getElementById('historico_content');
    
    if (!historicoDiv || !historicoContent) return;
    
    let html = '<div class="table-responsive">';
    html += '<table class="table table-sm table-hover">';
    html += '<thead class="table-light">';
    html += '<tr><th>Data</th><th>Boleto</th><th>Valor</th><th>Status</th><th>Curso</th><th>Ações</th></tr>';
    html += '</thead><tbody>';
    
    historico.forEach(item => {
        const dataFormatada = new Date(item.created_at).toLocaleDateString('pt-BR');
        const valorFormatado = 'R$ ' + parseFloat(item.valor).toFixed(2).replace('.', ',');
        
        let statusClass = '';
        switch (item.status) {
            case 'pago': statusClass = 'success'; break;
            case 'vencido': statusClass = 'danger'; break;
            case 'cancelado': statusClass = 'secondary'; break;
            default: statusClass = 'warning'; break;
        }
        
        html += '<tr>';
        html += `<td>${dataFormatada}</td>`;
        html += `<td>${item.numero_boleto}</td>`;
        html += `<td>${valorFormatado}</td>`;
        html += `<td><span class="badge bg-${statusClass}">${item.status}</span></td>`;
        html += `<td>${item.curso_nome}</td>`;
        html += `<td>`;
        html += `<button type="button" class="btn btn-sm btn-outline-primary" onclick="abrirLinkPagSeguro('${item.link_pagseguro}')" title="Abrir link">`;
        html += `<i class="fas fa-external-link-alt"></i>`;
        html += `</button>`;
        html += `</td>`;
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    
    historicoContent.innerHTML = html;
    historicoDiv.style.display = 'block';
}

function ocultarHistoricoLinks() {
    const historicoDiv = document.getElementById('historico_links');
    if (historicoDiv) {
        historicoDiv.style.display = 'none';
    }
}

// Funcionalidades auxiliares PagSeguro
function testarLinkPagSeguro() {
    const link = document.getElementById('link_pagseguro')?.value;
    
    if (!link) {
        showToast('Digite o link do PagSeguro primeiro', 'warning');
        return;
    }
    
    if (!validarURLPagSeguro(link)) {
        showToast('Link inválido', 'error');
        return;
    }
    
    // Abrir link em nova aba
    window.open(link, '_blank', 'noopener,noreferrer');
    showToast('Link aberto em nova aba', 'info');
}

function abrirLinkPagSeguro(link) {
    if (link) {
        window.open(link, '_blank', 'noopener,noreferrer');
    }
}

function verificarFormularioPagSeguro() {
    const campos = [
        'polo_pagseguro',
        'curso_pagseguro',
        'aluno_cpf_pagseguro',
        'link_pagseguro'
    ];
    
    let todosPreenchidos = true;
    
    campos.forEach(id => {
        const elemento = document.getElementById(id);
        if (!elemento || !elemento.value || elemento.value.trim() === '') {
            todosPreenchidos = false;
        }
    });
    
    // Verificação adicional do CPF
    const cpfInput = document.getElementById('aluno_cpf_pagseguro');
    if (cpfInput) {
        const cpf = cpfInput.value.replace(/\D/g, '');
        if (cpf.length !== 11 || !validarCPF(cpf)) {
            todosPreenchidos = false;
        }
    }
    
    // Verificação adicional do link
    const linkInput = document.getElementById('link_pagseguro');
    if (linkInput && !validarURLPagSeguro(linkInput.value)) {
        todosPreenchidos = false;
    }
    
    const botao = document.getElementById('btnSalvarLinkPagSeguro');
    if (botao) {
        botao.disabled = !todosPreenchidos;
        
        if (todosPreenchidos) {
            botao.classList.remove('btn-secondary');
            botao.classList.add('btn-pagseguro');
        } else {
            botao.classList.remove('btn-pagseguro');
            botao.classList.add('btn-secondary');
        }
    }
}

function preencherDadosExtraidos(dadosServidor) {
    if (!dadosServidor) return;
    
    // Preencher valor se fornecido pelo servidor
    if (dadosServidor.valor && !document.getElementById('valor_pagseguro')?.value) {
        document.getElementById('valor_pagseguro').value = dadosServidor.valor;
    }
    
    // Preencher vencimento se fornecido pelo servidor
    if (dadosServidor.vencimento && !document.getElementById('vencimento_pagseguro')?.value) {
        document.getElementById('vencimento_pagseguro').value = dadosServidor.vencimento;
    }
    
    // Preencher descrição se fornecida pelo servidor
    if (dadosServidor.descricao && !document.getElementById('descricao_pagseguro')?.value) {
        document.getElementById('descricao_pagseguro').value = dadosServidor.descricao;
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

// ========== UTILITY FUNCTIONS ==========

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ========== EVENT LISTENERS PRINCIPAIS ==========

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Sistema de Upload de Boletos carregando...');
    
    // Aplicar máscaras nos campos de CPF
    aplicarMascaraCPF(document.getElementById('aluno_cpf'));
    aplicarMascaraCPF(document.getElementById('aluno_cpf_multiplo'));
    aplicarMascaraCPF(document.getElementById('aluno_cpf_parcelas'));
    aplicarMascaraCPF(document.getElementById('aluno_cpf_pagseguro'));
    
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
    
    // Event listeners para PagSeguro
    const poloPagSeguroSelect = document.getElementById('polo_pagseguro');
    const cursoPagSeguroSelect = document.getElementById('curso_pagseguro');
    if (poloPagSeguroSelect && cursoPagSeguroSelect) {
        poloPagSeguroSelect.addEventListener('change', function() {
            carregarCursos(this, cursoPagSeguroSelect);
        });
    }
    
    // Event listeners para validação em tempo real PagSeguro
    const linkPagSeguroInput = document.getElementById('link_pagseguro');
    if (linkPagSeguroInput) {
        linkPagSeguroInput.addEventListener('blur', validarLinkPagSeguroAutomatico);
        linkPagSeguroInput.addEventListener('input', debounce(function() {
            if (this.value.length > 20) {
                previewLinkPagSeguro();
            }
        }, 500));
    }
    
    // Event listeners para buscar histórico PagSeguro
    const cpfPagSeguroInput = document.getElementById('aluno_cpf_pagseguro');
    if (cpfPagSeguroInput) {
        cpfPagSeguroInput.addEventListener('blur', function() {
            const polo = document.getElementById('polo_pagseguro')?.value;
            if (this.value && polo) {
                buscarHistoricoAluno();
            }
        });
    }
    
    // Event listener para validação do formulário PagSeguro
    const formPagSeguro = document.getElementById('linkPagSeguroForm');
    if (formPagSeguro) {
        formPagSeguro.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validações básicas
            const polo = document.getElementById('polo_pagseguro')?.value;
            const curso = document.getElementById('curso_pagseguro')?.value;
            const cpf = document.getElementById('aluno_cpf_pagseguro')?.value;
            const link = document.getElementById('link_pagseguro')?.value;
            
            if (!polo || !curso || !cpf || !link) {
                showToast('Preencha todos os campos obrigatórios', 'error');
                return;
            }
            
            // Validação do CPF
            const cpfLimpo = cpf.replace(/\D/g, '');
            if (cpfLimpo.length !== 11 || !validarCPF(cpfLimpo)) {
                showToast('CPF inválido', 'error');
                document.getElementById('aluno_cpf_pagseguro').focus();
                return;
            }
            
            // Validação do link
            if (!validarURLPagSeguro(link)) {
                showToast('Link PagSeguro inválido', 'error');
                document.getElementById('link_pagseguro').focus();
                return;
            }
            
            // Confirmação antes de salvar
            const confirmacao = confirm(`Confirma o cadastro do link PagSeguro?\n\nLink: ${link.substring(0, 60)}...\nCPF: ${cpf}`);
            if (!confirmacao) return;
            
            // Desabilitar botão e mostrar loading
            const submitBtn = document.getElementById('btnSalvarLinkPagSeguro');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
                submitBtn.disabled = true;
            }
            
            // Enviar via AJAX
            fetch('/admin/upload-boletos.php', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(response => response.text())
            .then(data => {
                if (data.includes('<!DOCTYPE html>') || data.includes('<html')) {
                    location.reload();
                } else {
                    showToast('Link PagSeguro salvo com sucesso!', 'success');
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                console.error('Erro no envio:', error);
                showToast('Erro ao salvar link PagSeguro: ' + error.message, 'error');
            })
            .finally(() => {
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-save"></i> Salvar Link de Cobrança';
                    submitBtn.disabled = false;
                }
            });
        });
    }
    
    // Verificar campos e habilitar botão PagSeguro
    const camposPagSeguro = ['polo_pagseguro', 'curso_pagseguro', 'aluno_cpf_pagseguro', 'link_pagseguro'];
    camposPagSeguro.forEach(id => {
        const elemento = document.getElementById(id);
        if (elemento) {
            elemento.addEventListener('change', verificarFormularioPagSeguro);
            elemento.addEventListener('input', verificarFormularioPagSeguro);
        }
    });
    
    // Event listener para o formulário de parcelas individuais
    const formParcelasIndividuais = document.getElementById('gerarParcelasPixForm');
    if (formParcelasIndividuais) {
        console.log('✅ Formulário de parcelas encontrado, configurando eventos...');
        
        formParcelasIndividuais.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('📝 Iniciando validação do formulário...');
            
            // Validação dos elementos
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
            
            // Extrair valores dos elementos
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
            
            // Garantir que os dados das parcelas sejam adicionados corretamente
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
    
    // Datas mínimas para campos de data
    const hoje = new Date().toISOString().split('T')[0];
    const camposData = ['vencimento', 'vencimento_lote', 'vencimento_global', 'primeira_parcela', 'vencimento_pagseguro'];
    
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
    
    // Verificar campos e habilitar botões para parcelas
    const camposVerificacao = [
        'polo_parcelas', 'curso_parcelas', 'aluno_cpf_parcelas',
        'quantidade_parcelas', 'primeira_parcela', 'descricao_parcelas'
    ];
    
    camposVerificacao.forEach(id => {
        const elemento = document.getElementById(id);
        if (elemento) {
            elemento.addEventListener('change', verificarFormularioCompleto);
            elemento.addEventListener('input', verificarFormularioCompleto);
        }
    });
    
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
    console.log('   🔗 Links PagSeguro com validação');
    console.log('   📁 Upload múltiplo e em lote');
    console.log('   🎯 Validações robustas');
    console.log('   🔄 Baixa automática via webhook');
});

console.log('🎉 JavaScript de Upload de Boletos COMPLETO carregado!');
console.log('🔧 Principais funcionalidades implementadas:');
console.log('   ✓ Upload individual com PIX customizado');
console.log('   ✓ Parcelas PIX com controle individual');
console.log('   ✓ Links PagSeguro com validação automática');
console.log('   ✓ Upload múltiplo e em lote');
console.log('   ✓ Validação robusta de CPF e dados');
console.log('   ✓ Sistema de notificações avançado');
console.log('   ✓ Drag & drop para todos os uploads');
console.log('   ✓ Preview em tempo real');
console.log('   ✓ Histórico e estatísticas');

</script>

</body>
</html>













