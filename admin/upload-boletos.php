<?php
/**
 * Sistema de Boletos IMEPEDU - Upload de Boletos PDF COM MÚLTIPLOS UPLOADS POR ALUNO
 * Arquivo: admin/upload-boletos.php - VERSÃO COMPLETA MELHORADA
 * 
 * NOVIDADE: Adicionada funcionalidade para múltiplos uploads para um único aluno
 */

session_start();

// Verifica se admin está logado
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

// Processa upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['acao']) && $_POST['acao'] === 'upload_individual') {
            // Upload individual
            $resultado = $uploadService->processarUploadIndividual($_POST, $_FILES);
            $sucesso = $resultado['message'];
        } elseif (isset($_POST['acao']) && $_POST['acao'] === 'upload_lote') {
            // Upload em lote
            $resultado = $uploadService->processarUploadLote($_POST, $_FILES);
            $sucesso = "Upload em lote processado! {$resultado['sucessos']} boletos enviados, {$resultado['erros']} erros.";
        } elseif (isset($_POST['acao']) && $_POST['acao'] === 'upload_multiplo_aluno') {
            // 🆕 NOVO: Upload múltiplo para um único aluno
            $resultado = $uploadService->processarUploadMultiploAluno($_POST, $_FILES);
            $sucesso = "Upload múltiplo processado! {$resultado['sucessos']} boletos enviados para {$resultado['aluno_nome']}, {$resultado['erros']} erros.";
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Busca dados para formulários
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
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
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
        
        /* 🆕 Estilos para múltiplos uploads */
        .upload-zone-multiple {
            border: 2px dashed #28a745;
            background: rgba(40,167,69,0.05);
        }
        
        .upload-zone-multiple:hover {
            border-color: #1e7e34;
            background: rgba(40,167,69,0.1);
        }
        
        .file-list {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .file-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        
        .file-list-item.valid {
            border-left: 4px solid #28a745;
        }
        
        .file-list-item.invalid {
            border-left: 4px solid #dc3545;
            background: rgba(220,53,69,0.05);
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
        
        /* 🆕 Botão especial para upload múltiplo */
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
        
        .progress {
            height: 10px;
            border-radius: 5px;
            margin-top: 1rem;
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
        
        /* 🆕 Tab especial para múltiplos uploads */
        .nav-tabs .nav-link.tab-multiple {
            position: relative;
        }
        
        .nav-tabs .nav-link.tab-multiple::after {
            content: "NOVO";
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ff6b6b;
            color: white;
            font-size: 0.6rem;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: bold;
        }
        
        .dropzone {
            border: 2px dashed #dee2e6 !important;
            border-radius: 10px !important;
            background: #f8f9fa !important;
            padding: 2rem !important;
        }
        
        .dropzone.dz-drag-hover {
            border-color: var(--primary-color) !important;
            background: rgba(0,102,204,0.05) !important;
        }
        
        /* Debug section styling */
        .debug-section {
            border-left: 4px solid #ffc107;
            background: linear-gradient(90deg, rgba(255,193,7,0.05) 0%, transparent 100%);
        }

        #debugConteudo {
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        #debugConteudo .text-success {
            color: #28a745 !important;
        }

        #debugConteudo .text-danger {
            color: #dc3545 !important;
        }

        #debugConteudo .text-warning {
            color: #ffc107 !important;
        }

        #debugConteudo .text-info {
            color: #17a2b8 !important;
        }

        #debugConteudo .text-primary {
            color: #007bff !important;
        }

        .debug-quick-link {
            display: inline-block;
            margin-top: 5px;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .debug-quick-link:hover {
            text-decoration: underline;
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
                <small class="text-muted">Envie arquivos PDF de boletos para os alunos</small>
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
        
        <!-- Seção de Debug - Matrícula -->
        <div class="card mb-4 debug-section">
            <div class="card-body">
                <h6 class="card-title text-warning">
                    <i class="fas fa-bug"></i> Debug de Matrícula
                </h6>
                <p class="text-muted mb-3">
                    Use esta ferramenta para diagnosticar problemas de matrícula quando aparecer o erro 
                    "Aluno não está matriculado neste curso".
                </p>
                
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label">CPF do Aluno</label>
                        <input type="text" class="form-control" id="debug_cpf" placeholder="000.000.000-00" maxlength="14">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Polo</label>
                        <select class="form-control" id="debug_polo">
                            <option value="">Selecione o polo</option>
                            <?php foreach ($polosAtivos as $polo): ?>
                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                <option value="<?= $polo ?>">
                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid gap-2">
                            <button class="btn btn-warning" onclick="debugMatricula('verificar')">
                                <i class="fas fa-search"></i> Verificar
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <button class="btn btn-info btn-sm me-2" onclick="debugMatricula('sincronizar')">
                        <i class="fas fa-sync"></i> Forçar Sincronização
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="limparDebug()">
                        <i class="fas fa-times"></i> Limpar
                    </button>
                </div>
                
                <!-- Área de Resultados -->
                <div id="debugResultados" class="mt-4" style="display: none;">
                    <h6><i class="fas fa-terminal"></i> Resultados do Debug:</h6>
                    <div class="bg-dark text-light p-3 rounded" style="font-family: monospace; font-size: 12px; max-height: 500px; overflow-y: auto;">
                        <div id="debugConteudo"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Guias de Upload -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-upload"></i>
                    Upload de Boletos PDF
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
                    
                    <!-- 🆕 NOVA ABA: Upload Múltiplo para Um Aluno -->
                    <li class="nav-item" role="presentation">
                        <button class="nav-link tab-multiple" id="multiplo-aluno-tab" data-bs-toggle="tab" 
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
                                        <input type="number" class="form-control" id="valor" name="valor" 
                                               step="0.01" min="0" placeholder="0,00" required>
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
                    
                    <!-- 🆕 NOVA ABA: Upload Múltiplo para Um Aluno -->
                    <div class="tab-pane fade" id="multiplo-aluno" role="tabpanel">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Upload Múltiplo para Um Aluno:</strong> Envie vários boletos para o mesmo aluno de uma só vez. 
                            Ideal para enviar múltiplas mensalidades ou diferentes tipos de boletos para um único estudante.
                            <br><small>⚡ <strong>Novidade:</strong> Agora você pode definir valores e vencimentos diferentes para cada boleto!</small>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="uploadMultiploAlunoForm">
                            <input type="hidden" name="acao" value="upload_multiplo_aluno">
                            
                            <!-- Dados do Aluno -->
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
                                        <small class="debug-quick-link" onclick="autoPreencherDebugMultiplo()">
                                            <i class="fas fa-bug"></i> Debug Matrícula
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Upload de Múltiplos Arquivos -->
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
                            
                            <!-- Lista de Arquivos Selecionados -->
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
                            
                            <!-- Configurações Globais -->
                            <div class="section-title mt-4">
                                <i class="fas fa-cogs"></i> 3. Configurações Globais (Opcional)
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="valor_global" class="form-label">
                                            <i class="fas fa-dollar-sign"></i> Valor Padrão
                                        </label>
                                        <input type="number" class="form-control" id="valor_global" 
                                               step="0.01" min="0" placeholder="Ex: 150.00">
                                        <small class="form-text text-muted">Será aplicado a todos os boletos</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="vencimento_global" class="form-label">
                                            <i class="fas fa-calendar"></i> Vencimento Base
                                        </label>
                                        <input type="date" class="form-control" id="vencimento_global">
                                        <small class="form-text text-muted">Data base para sequência mensal</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="descricao_global" class="form-label">
                                            <i class="fas fa-comment"></i> Descrição Padrão
                                        </label>
                                        <input type="text" class="form-control" id="descricao_global" 
                                               placeholder="Ex: Mensalidade">
                                        <small class="form-text text-muted">Será usada para todos os boletos</small>
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
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="valor_lote" class="form-label">
                                            <i class="fas fa-dollar-sign"></i> Valor Padrão
                                        </label>
                                        <input type="number" class="form-control" id="valor_lote" name="valor" 
                                               step="0.01" min="0" placeholder="0,00" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="vencimento_lote" class="form-label">
                                            <i class="fas fa-calendar"></i> Data de Vencimento
                                        </label>
                                        <input type="date" class="form-control" id="vencimento_lote" name="vencimento" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="descricao_lote" class="form-label">
                                            <i class="fas fa-comment"></i> Descrição
                                        </label>
                                        <input type="text" class="form-control" id="descricao_lote" name="descricao" 
                                               placeholder="Ex: Mensalidade Janeiro 2024">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Dropzone -->
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-files"></i> Arquivos PDF dos Boletos
                                </label>
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
                                        Número do boleto deve ser único
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
                                    <span class="badge bg-success ms-2">NOVO</span>
                                </h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Envie vários boletos para o mesmo aluno
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success"></i>
                                        Configure valores individuais ou globais
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
                                        Exemplo: <code>12345678901_202412150001.pdf</code>
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
                                <i class="fas fa-exclamation-triangle text-warning"></i> Importantes
                            </h5>
                            
                            <div class="alert alert-warning">
                                <ul class="mb-0">
                                    <li><strong>Formato de arquivo:</strong> Apenas PDF é aceito</li>
                                    <li><strong>Tamanho máximo:</strong> 5MB por arquivo</li>
                                    <li><strong>Validação:</strong> Sistema verifica se aluno está matriculado no curso</li>
                                    <li><strong>Duplicatas:</strong> Números de boleto devem ser únicos</li>
                                    <li><strong>Upload Múltiplo:</strong> 🆕 Ideal para enviar várias mensalidades de uma vez</li>
                                    <li><strong>Nomenclatura:</strong> Para upload em lote, siga exatamente o padrão</li>
                                    <li><strong>Segurança:</strong> Arquivos são armazenados de forma segura e criptografada</li>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- 🆕 NOVA SEÇÃO: Exemplos de Uso -->
                        <div class="mt-4">
                            <h5 class="section-title">
                                <i class="fas fa-lightbulb text-info"></i> Exemplos de Uso
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title text-primary">
                                                <i class="fas fa-user-plus"></i> Múltiplos para Um Aluno
                                            </h6>
                                            <p class="card-text">
                                                <strong>Cenário:</strong> Enviar 6 mensalidades para João Silva<br>
                                                <strong>Como fazer:</strong>
                                            </p>
                                            <ol class="small">
                                                <li>Selecione o polo e curso do João</li>
                                                <li>Digite o CPF: 123.456.789-01</li>
                                                <li>Arraste 6 arquivos PDF das mensalidades</li>
                                                <li>Configure valor global: R$ 150,00</li>
                                                <li>Data base: 15/01/2024</li>
                                                <li>Clique em "Números Sequenciais"</li>
                                                <li>Sistema criará mensalidades de Jan a Jun</li>
                                            </ol>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title text-success">
                                                <i class="fas fa-files"></i> Upload em Lote
                                            </h6>
                                            <p class="card-text">
                                                <strong>Cenário:</strong> Mensalidades de vários alunos<br>
                                                <strong>Nomeação dos arquivos:</strong>
                                            </p>
                                            <ul class="small">
                                                <li><code>12345678901_202401001.pdf</code> - João</li>
                                                <li><code>98765432100_202401002.pdf</code> - Maria</li>
                                                <li><code>11122233344_202401003.pdf</code> - Pedro</li>
                                            </ul>
                                            <p class="small text-muted">
                                                Todos terão o mesmo valor e vencimento configurados no formulário.
                                            </p>
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
        
        // ========== FUNÇÕES DE DEBUG DE MATRÍCULA ==========
        
        // Máscara para CPF no debug
        document.getElementById('debug_cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });

        // Função principal de debug
        function debugMatricula(acao) {
            const cpf = document.getElementById('debug_cpf').value;
            const polo = document.getElementById('debug_polo').value;
            
            if (!cpf || !polo) {
                showToast('Preencha CPF e Polo para fazer o debug', 'error');
                return;
            }
            
            const resultadosDiv = document.getElementById('debugResultados');
            const conteudoDiv = document.getElementById('debugConteudo');
            
            resultadosDiv.style.display = 'block';
            conteudoDiv.innerHTML = '<span class="text-warning">🔄 Executando debug...</span>';
            resultadosDiv.scrollIntoView({ behavior: 'smooth' });
            
            fetch('/admin/api/debug-matricula.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cpf: cpf, polo: polo, acao: acao })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    exibirResultadosDebug(data);
                    if (acao === 'sincronizar' && data.sincronizacao_realizada) {
                        showToast('Sincronização realizada com sucesso!', 'success');
                    }
                } else {
                    conteudoDiv.innerHTML = `<span class="text-danger">❌ Erro: ${data.message}</span>`;
                    showToast('Erro no debug: ' + data.message, 'error');
                }
            })
            .catch(error => {
                conteudoDiv.innerHTML = `<span class="text-danger">❌ Erro de conexão: ${error.message}</span>`;
                showToast('Erro de conexão', 'error');
            });
        }

        // Exibe resultados formatados do debug
        function exibirResultadosDebug(data) {
            const conteudoDiv = document.getElementById('debugConteudo');
            let html = `<div class="text-info">📋 DEBUG DE MATRÍCULA</div>`;
            html += `<div class="text-muted">CPF: ${data.cpf} | Polo: ${data.polo} | ${data.timestamp}</div>\n\n`;
            
            if (data.diagnostico) {
                const diagnosticoCor = {
                    'TUDO_OK': 'text-success',
                    'ALUNO_NAO_ENCONTRADO': 'text-danger', 
                    'ALUNO_NAO_SINCRONIZADO': 'text-warning',
                    'SEM_MATRICULAS': 'text-warning'
                };
                html += `<div class="${diagnosticoCor[data.diagnostico] || 'text-info'}">`;
                html += `🎯 DIAGNÓSTICO: ${data.diagnostico}</div>\n\n`;
            }
            
            if (data.debug) {
                data.debug.forEach(linha => {
                    html += escapeHtml(linha) + '\n';
                });
            }
            
            conteudoDiv.innerHTML = html;
        }

        // Limpa debug
        function limparDebug() {
            document.getElementById('debug_cpf').value = '';
            document.getElementById('debug_polo').value = '';
            document.getElementById('debugResultados').style.display = 'none';
        }

        // Auto-preenchimento do debug com dados do formulário múltiplo
        function autoPreencherDebugMultiplo() {
            const cpfMultiplo = document.getElementById('aluno_cpf_multiplo').value;
            const poloMultiplo = document.getElementById('polo_multiplo').value;
            
            if (cpfMultiplo) document.getElementById('debug_cpf').value = cpfMultiplo;
            if (poloMultiplo) document.getElementById('debug_polo').value = poloMultiplo;
            
            document.getElementById('debugResultados').parentElement.scrollIntoView({ behavior: 'smooth' });
        }

        // Escape HTML para segurança
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ========== FUNÇÕES DE UPLOAD INDIVIDUAL ==========
        
        // Máscara para CPF
        document.getElementById('aluno_cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
        
        // Máscara para CPF múltiplo
        document.getElementById('aluno_cpf_multiplo').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
        
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
        
        // ========== 🆕 FUNÇÕES DE UPLOAD MÚLTIPLO PARA UM ALUNO ==========
        
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
            // Filtra apenas PDFs
            const pdfFiles = files.filter(file => file.type === 'application/pdf');
            
            if (pdfFiles.length !== files.length) {
                showToast('Apenas arquivos PDF são aceitos. Arquivos não-PDF foram ignorados.', 'warning');
            }
            
            // Verifica tamanho
            const validFiles = pdfFiles.filter(file => file.size <= 5 * 1024 * 1024);
            
            if (validFiles.length !== pdfFiles.length) {
                showToast('Arquivos maiores que 5MB foram ignorados.', 'warning');
            }
            
            // Adiciona arquivos válidos à lista
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
                    descricao: ''
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
                            <div class="col-md-3">
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
                                       placeholder="Descrição (opcional)" 
                                       value="${fileData.descricao}"
                                       onchange="atualizarDadosArquivo(${fileData.id}, 'descricao', this.value)">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                        onclick="removerArquivoMultiplo(${fileData.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
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
            
            if (!valorGlobal && !vencimentoGlobal && !descricaoGlobal) {
                showToast('Preencha pelo menos um campo global para aplicar', 'warning');
                return;
            }
            
            fileListMultiplo.forEach(fileData => {
                if (valorGlobal) fileData.valor = valorGlobal;
                if (vencimentoGlobal) fileData.vencimento = vencimentoGlobal;
                if (descricaoGlobal) fileData.descricao = descricaoGlobal;
            });
            
            atualizarListaArquivosMultiplo();
            showToast('Valores globais aplicados a todos os arquivos!', 'success');
        }
        
        function gerarNumerosSequenciais() {
            if (fileListMultiplo.length === 0) {
                showToast('Adicione arquivos primeiro', 'warning');
                return;
            }
            
            const dataBase = new Date();
            const numeroBase = dataBase.getFullYear().toString() + 
                              String(dataBase.getMonth() + 1).padStart(2, '0') + 
                              String(dataBase.getDate()).padStart(2, '0');
            
            const vencimentoBase = document.getElementById('vencimento_global').value;
            
            fileListMultiplo.forEach((fileData, index) => {
                // Gera número sequencial
                fileData.numero_boleto = numeroBase + String(index + 1).padStart(4, '0');
                
                // Se tem vencimento base, gera vencimentos mensais
                if (vencimentoBase) {
                    const dataVencimento = new Date(vencimentoBase);
                    dataVencimento.setMonth(dataVencimento.getMonth() + index);
                    fileData.vencimento = dataVencimento.toISOString().split('T')[0];
                }
            });
            
            atualizarListaArquivosMultiplo();
            showToast('Números sequenciais e vencimentos gerados!', 'success');
        }
        
        // ========== CONFIGURAÇÃO DO DROPZONE PARA LOTE ==========
        
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
        
        // ========== FUNÇÕES DE BUSCA DE CURSOS ==========
        
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
        
        // ========== VALIDAÇÕES DOS FORMULÁRIOS ==========
        
        // Validação do formulário individual
        document.getElementById('uploadIndividualForm').addEventListener('submit', function(e) {
            const cpf = document.getElementById('aluno_cpf').value.replace(/\D/g, '');
            const arquivo = document.getElementById('arquivo_pdf').files[0];
            
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
            
            // Mostra loading
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
            submitBtn.disabled = true;
        });
        
        // 🆕 Validação do formulário múltiplo
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
            
            // Valida se todos os arquivos têm dados obrigatórios
            const arquivosIncompletos = fileListMultiplo.filter(f => !f.numero_boleto || !f.valor || !f.vencimento);
            if (arquivosIncompletos.length > 0) {
                e.preventDefault();
                alert(`${arquivosIncompletos.length} arquivo(s) estão incompletos. Preencha número do boleto, valor e vencimento para todos.`);
                return;
            }
            
            // Adiciona dados dos arquivos ao FormData
            const formData = new FormData(this);
            formData.delete('arquivos_multiplos[]'); // Remove o input original
            
            fileListMultiplo.forEach((fileData, index) => {
                formData.append('arquivos_multiplos[]', fileData.file);
                formData.append(`arquivo_${index}_numero`, fileData.numero_boleto);
                formData.append(`arquivo_${index}_valor`, fileData.valor);
                formData.append(`arquivo_${index}_vencimento`, fileData.vencimento);
                formData.append(`arquivo_${index}_descricao`, fileData.descricao || '');
            });
            
            // Previne submissão normal e envia via AJAX
            e.preventDefault();
            enviarFormularioMultiplo(formData);
        });
        
        // 🆕 Função para enviar formulário múltiplo via AJAX
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
                // Se a resposta contém HTML (página recarregada), recarrega
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
        
        // Validação do formulário em lote
        document.getElementById('uploadLoteForm').addEventListener('submit', function(e) {
            const polo = document.getElementById('polo_lote').value;
            const curso = document.getElementById('curso_lote').value;
            const valor = document.getElementById('valor_lote').value;
            const vencimento = document.getElementById('vencimento_lote').value;
            
            if (!polo || !curso || !valor || !vencimento) {
                e.preventDefault();
                alert('Preencha todos os campos obrigatórios');
                return;
            }
            
            if (dropzoneLote.getQueuedFiles().length === 0) {
                e.preventDefault();
                alert('Adicione pelo menos um arquivo PDF');
                return;
            }
        });
        
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
        
        // ========== FUNÇÕES AUXILIARES ==========
        
        // Define data mínima como hoje
        const hoje = new Date().toISOString().split('T')[0];
        document.getElementById('vencimento').min = hoje;
        document.getElementById('vencimento_lote').min = hoje;
        document.getElementById('vencimento_global').min = hoje;
        
        // Auto-complete número do boleto baseado na data
        document.getElementById('vencimento').addEventListener('change', function() {
            const data = this.value.replace(/-/g, '');
            const numeroBase = data + '0001';
            
            if (!document.getElementById('numero_boleto').value) {
                document.getElementById('numero_boleto').value = numeroBase;
            }
        });
        
        // Validação de CPF
        function validarCPF(cpf) {
            cpf = cpf.replace(/[^\d]+/g, '');
            if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
            
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
        
        // Valida CPF em tempo real
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
        
        // ========== ESTILOS PARA ANIMAÇÕES ==========
        
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
            
            .file-list-item.valid {
                border-left: 4px solid #28a745;
                background: rgba(40,167,69,0.02);
            }
            
            .file-list-item.invalid {
                border-left: 4px solid #dc3545;
                background: rgba(220,53,69,0.02);
            }
            
            .upload-zone-multiple {
                border-color: #28a745 !important;
                background: rgba(40,167,69,0.05) !important;
            }
            
            .upload-zone-multiple:hover {
                border-color: #1e7e34 !important;
                background: rgba(40,167,69,0.1) !important;
            }
            
            .nav-tabs .nav-link.tab-multiple::after {
                content: "NOVO";
                position: absolute;
                top: -8px;
                right: -8px;
                background: #ff6b6b;
                color: white;
                font-size: 0.6rem;
                padding: 2px 6px;
                border-radius: 10px;
                font-weight: bold;
                animation: pulse 2s infinite;
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.1); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(style);
        
        // ========== INICIALIZAÇÃO ==========
        
        // Log de debug final
        console.log('✅ Sistema de Upload Múltiplo para Um Aluno carregado!');
        console.log('🆕 Nova funcionalidade disponível na aba "Múltiplos para Um Aluno"');
        
        // Tooltip para elementos com title
        document.addEventListener('DOMContentLoaded', function() {
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                    new bootstrap.Tooltip(element);
                }
            });
        });
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl + M para ir para aba múltipla
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                document.getElementById('multiplo-aluno-tab').click();
            }
            
            // Ctrl + 1, 2, 3, 4 para alternar entre abas
            if (e.ctrlKey && ['1', '2', '3', '4'].includes(e.key)) {
                e.preventDefault();
                const tabs = ['individual-tab', 'multiplo-aluno-tab', 'lote-tab', 'instrucoes-tab'];
                const tabIndex = parseInt(e.key) - 1;
                if (tabs[tabIndex]) {
                    document.getElementById(tabs[tabIndex]).click();
                }
            }
        });
        
        // Intercepta erros de matrícula e sugere debug
        const originalSubmitIndividual = document.getElementById('uploadIndividualForm').onsubmit;
        const originalSubmitMultiplo = document.getElementById('uploadMultiploAlunoForm').onsubmit;
        
        // Guarda dados para possível debug após erro
        function guardarDadosParaDebug(form) {
            if (form.id === 'uploadIndividualForm') {
                window.lastUploadData = {
                    cpf: document.getElementById('aluno_cpf').value,
                    polo: document.getElementById('polo').value
                };
            } else if (form.id === 'uploadMultiploAlunoForm') {
                window.lastUploadData = {
                    cpf: document.getElementById('aluno_cpf_multiplo').value,
                    polo: document.getElementById('polo_multiplo').value
                };
            }
        }
        
        document.getElementById('uploadIndividualForm').addEventListener('submit', function() {
            guardarDadosParaDebug(this);
        });
        
        document.getElementById('uploadMultiploAlunoForm').addEventListener('submit', function() {
            guardarDadosParaDebug(this);
        });
        
        // Função para sugerir debug após erro
        function sugerirDebugAposErro(mensagemErro) {
            if (mensagemErro.includes('não está matriculado') && window.lastUploadData) {
                setTimeout(() => {
                    if (confirm('Erro de matrícula detectado. Deseja executar o debug automático?')) {
                        document.getElementById('debug_cpf').value = window.lastUploadData.cpf;
                        document.getElementById('debug_polo').value = window.lastUploadData.polo;
                        debugMatricula('verificar');
                    }
                }, 2000);
            }
        }
        
        console.log('🎉 Upload de Boletos - Sistema Completo Carregado!');
        console.log('📋 Funcionalidades disponíveis:');
        console.log('   1️⃣ Upload Individual');
        console.log('   2️⃣ 🆕 Upload Múltiplo para Um Aluno');
        console.log('   3️⃣ Upload em Lote');
        console.log('   🐛 Debug de Matrícula integrado');
        console.log('⌨️ Atalhos: Ctrl+1/2/3/4 (abas), Ctrl+M (múltiplo)');
    </script>
</body>
</html>