<?php
/**
 * Sistema de Boletos IMEPEDU - Upload Simplificado com Desconto PIX Personalizado
 * Arquivo: admin/upload-boletos.php
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
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

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
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .pix-desconto-info {
            background: rgba(40, 167, 69, 0.1);
            border-radius: 6px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        
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
        
        .btn-upload {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
        }
        
        .btn-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40,167,69,0.3);
            color: white;
        }
        
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
        
        .input-group-text {
            background: white;
            border-right: none;
            color: var(--primary-color);
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 102, 204, 0.25);
        }
        
        .alert-pix {
            background: linear-gradient(135deg, #e8f5e8, #f0f8f0);
            border: 1px solid var(--pix-discount-color);
            color: #155724;
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
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div>
                <h3 class="mb-0">Upload de Boletos</h3>
                <small class="text-muted">Envie arquivos PDF de boletos com desconto PIX personalizado</small>
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
        
        <!-- Alertas -->
        <?php if ($sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Guias de Upload -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-upload"></i>
                    Upload de Boletos PDF com Desconto PIX Personalizado
                </h5>
            </div>

            <div class="card-body">
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
                
                <div class="tab-content" id="uploadTabContent">
                    <!-- Upload Individual -->
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
                                        <input type="text" class="form-control" id="aluno_cpf" name="aluno_cpf"" 
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
                            
                            <!-- Seção de Desconto PIX Simplificada -->
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
                            
                            <!-- Upload Zone -->
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
                    
                    <!-- Upload Múltiplo para Um Aluno -->
                    <div class="tab-pane fade" id="multiplo-aluno" role="tabpanel">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Upload Múltiplo para Um Aluno:</strong> Envie vários boletos para o mesmo aluno de uma só vez. 
                            Você pode configurar desconto PIX individualmente para cada boleto.
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="uploadMultiploAlunoForm">
                            <input type="hidden" name="acao" value="upload_multiplo_aluno">
                            
                            <div class="section-title">
                                <i class="fas fa-user"></i> 1. Dados do Aluno
                            </div>
                            
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
                            
                            <div class="section-title mt-4">
                                <i class="fas fa-files"></i> 2. Arquivos PDF dos Boletos
                            </div>
                            
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
                            
                            <div class="section-title mt-4">
                                <i class="fas fa-cogs"></i> 3. Configurações Globais (Opcional)
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
                            
                            <!-- Configurações de Desconto Global -->
                            <div id="pixDescontoGlobalControls" class="pix-desconto-section collapsed" style="display: none;">
                                <div class="pix-desconto-title">
                                    <i class="fas fa-qrcode"></i> 
                                    Configuração Global de Desconto PIX
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="valor_desconto_global" class="form-label">
                                            <i class="fas fa-percentage"></i> Valor do Desconto
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor_desconto_global" 
                                                   step="0.01" min="0" placeholder="Ex: 50,00">
                                        </div>
                                        <small class="form-text text-muted">Valor fixo aplicado a todos os boletos</small>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="valor_minimo_global" class="form-label">
                                            <i class="fas fa-calculator"></i> Valor Mínimo (Opcional)
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" class="form-control" id="valor_minimo_global" 
                                                   step="0.01" min="0" placeholder="Ex: 100,00">
                                        </div>
                                        <small class="form-text text-muted">Valor mínimo para aplicar desconto</small>
                                    </div>
                                </div>
                                
                                <div class="alert alert-pix mt-3">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Desconto Global:</strong> Este desconto será aplicado a todos os boletos deste upload. 
                                    Você ainda poderá ajustar individualmente cada boleto se necessário.
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-upload-multiple" id="btnEnviarMultiplo" disabled>
                                    <i class="fas fa-user-plus"></i> Enviar Boletos para o Aluno
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Upload em Lote -->
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
                    
                    <!-- Instruções -->
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
                                    <i class="fas fa-user-plus"></i> Múltiplos para Um Aluno
                                </h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Envie vários boletos para o mesmo aluno
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Configure desconto PIX global ou individual
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Numeração automática sequencial
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Vencimentos com intervalos mensais
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Ideal para múltiplas mensalidades
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
                                </ul>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <h5 class="section-title">
                                <i class="fas fa-examples text-info"></i> Exemplos de Uso do Desconto PIX
                            </h5>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-lightbulb"></i> Exemplos Práticos:</h6>
                                <ul class="mb-0">
                                    <li><strong>Mensalidade R$ 150,00 → Desconto R$ 25,00:</strong> Aluno paga R$ 125,00 via PIX</li>
                                    <li><strong>Matrícula R$ 300,00 → Desconto R$ 50,00:</strong> Aluno paga R$ 250,00 via PIX</li>
                                    <li><strong>Taxa R$ 80,00 → Desconto R$ 15,00:</strong> Aluno paga R$ 65,00 via PIX</li>
                                    <li><strong>Valor Mínimo R$ 100,00:</strong> Só boletos acima de R$ 100,00 ganham desconto</li>
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
    
    <script>
        let fileListMultiplo = [];
        let isUpdating = false;
        
        // Máscara para CPF
        document.getElementById('aluno_cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
        
        document.getElementById('aluno_cpf_multiplo').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
        
        // Função para toggle do desconto PIX individual
        function togglePixDesconto() {
            const checkbox = document.getElementById('pix_desconto_disponivel');
            const controls = document.getElementById('pixDescontoControls');
            const section = document.getElementById('pixDescontoSection');
            
            if (checkbox.checked) {
                controls.classList.add('show');
                section.classList.remove('collapsed');
                
                // Foca no campo de valor do desconto
                setTimeout(() => {
                    document.getElementById('valor_desconto_pix').focus();
                }, 300);
            } else {
                controls.classList.remove('show');
                section.classList.add('collapsed');
                
                // Limpa os valores
                document.getElementById('valor_desconto_pix').value = '';
                document.getElementById('valor_minimo_desconto').value = '';
            }
        }
        
        // Função para toggle do desconto PIX global (múltiplo)
        function togglePixDescontoGlobal() {
            const checkbox = document.getElementById('pix_desconto_global');
            const controls = document.getElementById('pixDescontoGlobalControls');
            
            if (checkbox.checked) {
                controls.style.display = 'block';
                controls.classList.remove('collapsed');
                
                setTimeout(() => {
                    document.getElementById('valor_desconto_global').focus();
                }, 300);
            } else {
                controls.style.display = 'none';
                controls.classList.add('collapsed');
                
                // Limpa os valores
                document.getElementById('valor_desconto_global').value = '';
                document.getElementById('valor_minimo_global').value = '';
            }
        }
        
        // Função para toggle do desconto PIX lote
        function togglePixDescontoLote() {
            const checkbox = document.getElementById('pix_desconto_lote');
            const controls = document.getElementById('pixDescontoLoteControls');
            
            if (checkbox.checked) {
                controls.style.display = 'block';
                controls.classList.remove('collapsed');
                
                setTimeout(() => {
                    document.getElementById('valor_desconto_lote').focus();
                }, 300);
            } else {
                controls.style.display = 'none';
                controls.classList.add('collapsed');
                
                // Limpa os valores
                document.getElementById('valor_desconto_lote').value = '';
                document.getElementById('valor_minimo_lote').value = '';
            }
        }
        
        // Upload individual - zona de drag and drop
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('arquivo_pdf');
        const filePreview = document.getElementById('filePreview');
        
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
        
        function showFilePreview(file) {
            if (file.type !== 'application/pdf') {
                alert('Apenas arquivos PDF são aceitos!');
                fileInput.value = '';
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                alert('Arquivo deve ter no máximo 5MB!');
                fileInput.value = '';
                return;
            }
            
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
        
        function removeFile() {
            fileInput.value = '';
            filePreview.style.display = 'none';
        }
        
        // Funções de busca de cursos
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
        
        // Event listeners para carregar cursos
        document.getElementById('polo').addEventListener('change', function() {
            carregarCursos(this, document.getElementById('curso'));
        });
        
        document.getElementById('polo_multiplo').addEventListener('change', function() {
            carregarCursos(this, document.getElementById('curso_multiplo'));
        });
        
        document.getElementById('polo_lote').addEventListener('change', function() {
            carregarCursos(this, document.getElementById('curso_lote'));
        });
        
        // Upload múltiplo - zona de drag and drop
        const uploadZoneMultiplo = document.getElementById('uploadZoneMultiplo');
        const fileInputMultiplo = document.getElementById('arquivos_multiplos');
        const fileListMultiploDiv = document.getElementById('fileListMultiplo');
        const fileListContent = document.getElementById('fileListContent');
        const btnEnviarMultiplo = document.getElementById('btnEnviarMultiplo');
        
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
                fileListMultiploDiv.style.display = 'none';
                btnEnviarMultiplo.disabled = true;
                return;
            }
            
            fileListMultiploDiv.style.display = 'block';
            btnEnviarMultiplo.disabled = false;
            
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
            
            fileListContent.innerHTML = html;
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
            fileInputMultiplo.value = '';
            atualizarListaArquivosMultiplo();
            showToast('Todos os arquivos foram removidos', 'info');
        }
        
        function aplicarValoresGlobais() {
            const valorGlobal = document.getElementById('valor_global').value;
            const vencimentoGlobal = document.getElementById('vencimento_global').value;
            const descricaoGlobal = document.getElementById('descricao_global').value;
            const pixDescontoGlobal = document.getElementById('pix_desconto_global').checked;
            const valorDescontoGlobal = document.getElementById('valor_desconto_global').value;
            const valorMinimoGlobal = document.getElementById('valor_minimo_global').value;
            
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
            const vencimentoBase = document.getElementById('vencimento_global').value;
            
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
        
        // Configuração do Dropzone para lote
        Dropzone.autoDiscover = false;
        
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
                
                submitButton.addEventListener("click", function(e) {
                    e.preventDefault();
                    
                    if (myDropzone.getQueuedFiles().length > 0) {
                        myDropzone.processQueue();
                    } else {
                        document.getElementById('uploadLoteForm').submit();
                    }
                });
                
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
        
        // Validações dos formulários
        document.getElementById('uploadIndividualForm').addEventListener('submit', function(e) {
            const cpf = document.getElementById('aluno_cpf').value.replace(/\D/g, '');
            const arquivo = document.getElementById('arquivo_pdf').files[0];
            const pixDesconto = document.getElementById('pix_desconto_disponivel').checked;
            const valorDesconto = document.getElementById('valor_desconto_pix').value;
            
            if (cpf.length !== 11) {
                e.preventDefault();
                alert('CPF deve conter 11 dígitos');
                return;
            }
            
            if (!arquivo) {
                e.preventDefault();
                alert('Selecione um arquivo PDF');
                return;
            }
            
            if (arquivo.type !== 'application/pdf') {
                e.preventDefault();
                alert('Apenas arquivos PDF são aceitos');
                return;
            }
            
            if (pixDesconto && (!valorDesconto || parseFloat(valorDesconto) <= 0)) {
                e.preventDefault();
                alert('Quando o desconto PIX está marcado, o valor do desconto é obrigatório');
                return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
            submitBtn.disabled = true;
        });
        
        document.getElementById('uploadMultiploAlunoForm').addEventListener('submit', function(e) {
            const cpf = document.getElementById('aluno_cpf_multiplo').value.replace(/\D/g, '');
            const polo = document.getElementById('polo_multiplo').value;
            const curso = document.getElementById('curso_multiplo').value;
            
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
            
            if (fileListMultiplo.length === 0) {
                e.preventDefault();
                alert('Adicione pelo menos um arquivo PDF');
                return;
            }
            
            const arquivosIncompletos = fileListMultiplo.filter(f => !f.numero_boleto || !f.valor || !f.vencimento);
            if (arquivosIncompletos.length > 0) {
                e.preventDefault();
                alert(`${arquivosIncompletos.length} arquivo(s) estão incompletos. Preencha número do boleto, valor e vencimento para todos.`);
                return;
            }
            
            // Valida configurações de desconto PIX
            const arquivosComDescontoInvalido = fileListMultiplo.filter(f => 
                f.pix_desconto_disponivel && (!f.valor_desconto_pix || parseFloat(f.valor_desconto_pix) <= 0)
            );
            
            if (arquivosComDescontoInvalido.length > 0) {
                e.preventDefault();
                alert(`${arquivosComDescontoInvalido.length} arquivo(s) tem desconto PIX marcado mas sem valor do desconto definido.`);
                return;
            }
            
            const formData = new FormData(this);
            formData.delete('arquivos_multiplos[]');
            
            fileListMultiplo.forEach((fileData, index) => {
                formData.append('arquivos_multiplos[]', fileData.file);
                formData.append(`arquivo_${index}_numero`, fileData.numero_boleto);
                formData.append(`arquivo_${index}_valor`, fileData.valor);
                formData.append(`arquivo_${index}_vencimento`, fileData.vencimento);
                formData.append(`arquivo_${index}_descricao`, fileData.descricao || '');
                formData.append(`arquivo_${index}_pix_desconto`, fileData.pix_desconto_disponivel || 0);
                formData.append(`arquivo_${index}_valor_desconto`, fileData.valor_desconto_pix || '');
                formData.append(`arquivo_${index}_valor_minimo`, fileData.valor_minimo_desconto || '');
            });
            
            e.preventDefault();
            enviarFormularioMultiplo(formData);
        });
        
        function enviarFormularioMultiplo(formData) {
            const submitBtn = document.getElementById('btnEnviarMultiplo');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
            submitBtn.disabled = true;
            
            fetch('/admin/upload-boletos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (data.includes('<!DOCTYPE html>') || data.includes('<html')) {
                    location.reload();
                } else {
                    showToast('Upload múltiplo processado com sucesso!', 'success');
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('Erro no upload múltiplo: ' + error.message, 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        document.getElementById('uploadLoteForm').addEventListener('submit', function(e) {
            const polo = document.getElementById('polo_lote').value;
            const curso = document.getElementById('curso_lote').value;
            const valor = document.getElementById('valor_lote').value;
            const vencimento = document.getElementById('vencimento_lote').value;
            const pixDesconto = document.getElementById('pix_desconto_lote').checked;
            const valorDesconto = document.getElementById('valor_desconto_lote').value;
            
            if (!polo || !curso || !valor || !vencimento) {
                e.preventDefault();
                alert('Preencha todos os campos obrigatórios');
                return;
            }
            
            if (pixDesconto && (!valorDesconto || parseFloat(valorDesconto) <= 0)) {
                e.preventDefault();
                alert('Quando o desconto PIX está marcado, o valor do desconto é obrigatório');
                return;
            }
            
            if (dropzoneLote.getQueuedFiles().length === 0) {
                e.preventDefault();
                alert('Adicione pelo menos um arquivo PDF');
                return;
            }
        });
        
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
        
        // Funções auxiliares
        const hoje = new Date().toISOString().split('T')[0];
        document.getElementById('vencimento').min = hoje;
        document.getElementById('vencimento_lote').min = hoje;
        document.getElementById('vencimento_global').min = hoje;
        
        document.getElementById('vencimento').addEventListener('change', function() {
            const data = this.value.replace(/-/g, '');
            const numeroBase = data + '0001';
            
            if (!document.getElementById('numero_boleto').value) {
                document.getElementById('numero_boleto').value = numeroBase;
            }
        });
        
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
        
        document.getElementById('aluno_cpf').addEventListener('blur', function() {
            const cpf = this.value.replace(/\D/g, '');
            if (cpf && !validarCPF(cpf)) {
                this.classList.add('is-invalid');
                showToast('CPF inválido', 'error');
            } else {
                this.classList.remove('is-invalid');
            }
        });
        
        document.getElementById('aluno_cpf_multiplo').addEventListener('blur', function() {
            const cpf = this.value.replace(/\D/g, '');
            if (cpf && !validarCPF(cpf)) {
                this.classList.add('is-invalid');
                showToast('CPF inválido', 'error');
            } else {
                this.classList.remove('is-invalid');
            }
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
            
            .toast-container {
                position: fixed !important;
                top: 20px !important;
                right: 20px !important;
                z-index: 2001 !important;
            }
            
            .is-invalid {
                border-color: #dc3545 !important;
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
            
            .upload-zone-multiple {
                border: 2px dashed #28a745;
                background: rgba(40,167,69,0.05);
            }
            
            .upload-zone-multiple:hover {
                border-color: #1e7e34;
                background: rgba(40,167,69,0.1);
            }
            
            .btn-upload-multiple {
                background: linear-gradient(135deg, var(--info-color), #138496);
                border: none;
                border-radius: 25px;
                padding: 12px 30px;
                font-weight: 600;
                color: white;
            }
            
            .btn-upload-multiple:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(23,162,184,0.3);
                color: white;
            }
            
            .pix-config-row {
                animation: slideDown 0.3s ease;
            }
        `;
        document.head.appendChild(style);
        
        console.log('✅ Sistema de Upload com Desconto PIX Personalizado carregado!');
        console.log('🆕 Funcionalidades implementadas:');
        console.log('   - Desconto PIX configurável por boleto');
        console.log('   - Valor personalizado definido pelo admin');
        console.log('   - Funciona em qualquer polo');
        console.log('   - Interface simplificada e intuitiva');
    </script>
</body>
</html>                                 