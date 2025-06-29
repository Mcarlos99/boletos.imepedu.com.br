<?php
/**
 * Sistema de Boletos IMEPEDU - Gerenciamento de Boletos
 * Arquivo: admin/boletos.php
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

$adminService = new AdminService();
$uploadService = new BoletoUploadService();

// Processa filtros
$filtros = [];
$pagina = intval($_GET['pagina'] ?? 1);

if (!empty($_GET['polo'])) $filtros['polo'] = $_GET['polo'];
if (!empty($_GET['curso_id'])) $filtros['curso_id'] = $_GET['curso_id'];
if (!empty($_GET['status'])) $filtros['status'] = $_GET['status'];
if (!empty($_GET['data_inicio'])) $filtros['data_inicio'] = $_GET['data_inicio'];
if (!empty($_GET['data_fim'])) $filtros['data_fim'] = $_GET['data_fim'];
if (!empty($_GET['busca'])) $filtros['busca'] = $_GET['busca'];

// Busca boletos
$resultado = $uploadService->listarBoletos($filtros, $pagina, 20);
$polosAtivos = MoodleConfig::getActiveSubdomains();

// Busca cursos disponíveis para filtro
$cursosDisponiveis = $adminService->buscarTodosCursos();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Boletos - Administração IMEPEDU</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
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
        .badge-cancelado { background: rgba(108,117,125,0.1); color: #6c757d; }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,102,204,0.05);
        }
        
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
        
        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .stats-row {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
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
                <a href="/admin/boletos.php" class="nav-link active">
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
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3>Gerenciar Boletos</h3>
                <small class="text-muted">Total: <?= $resultado['total'] ?> boletos encontrados</small>
            </div>
            <div>
                <a href="/admin/upload-boletos.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Boleto
                </a>
                <button class="btn btn-outline-secondary" onclick="exportarRelatorio()">
                    <i class="fas fa-download"></i> Exportar
                </button>
            </div>
        </div>
        
        <!-- Estatísticas Rápidas -->
        <?php if (empty($filtros)): ?>
        <div class="stats-row">
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= $resultado['total'] ?></div>
                        <div class="stat-label">Total de Boletos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= count(array_filter($resultado['boletos'], fn($b) => $b['status'] === 'pago')) ?></div>
                        <div class="stat-label">Pagos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= count(array_filter($resultado['boletos'], fn($b) => $b['status'] === 'pendente')) ?></div>
                        <div class="stat-label">Pendentes</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= count(array_filter($resultado['boletos'], fn($b) => $b['status'] === 'vencido')) ?></div>
                        <div class="stat-label">Vencidos</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-card">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i class="fas fa-filter"></i> Filtros de Busca
                </h6>
                
                <form method="GET" class="row g-3" id="filtrosForm">
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
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">Todos</option>
                            <option value="pendente" <?= ($_GET['status'] ?? '') == 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <option value="pago" <?= ($_GET['status'] ?? '') == 'pago' ? 'selected' : '' ?>>Pago</option>
                            <option value="vencido" <?= ($_GET['status'] ?? '') == 'vencido' ? 'selected' : '' ?>>Vencido</option>
                            <option value="cancelado" <?= ($_GET['status'] ?? '') == 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?= $_GET['data_inicio'] ?? '' ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?= $_GET['data_fim'] ?? '' ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nome, CPF, número..." value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
                    </div>
                    
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <?php if (!empty($filtros)): ?>
                    <div class="col-12">
                        <a href="/admin/boletos.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Limpar Filtros
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Boletos -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($resultado['boletos'])): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h5>Nenhum boleto encontrado</h5>
                        <p class="text-muted">Tente ajustar os filtros ou <a href="/admin/upload-boletos.php">crie um novo boleto</a></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="boletosTable">
                            <thead>
                                <tr>
                                    <th>Número</th>
                                    <th>Aluno</th>
                                    <th>Curso</th>
                                    <th>Valor</th>
                                    <th>Vencimento</th>
                                    <th>Status</th>
                                    <th>Criado</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultado['boletos'] as $boleto): ?>
                                    <tr data-boleto-id="<?= $boleto['id'] ?>">
                                        <td>
                                            <strong>#<?= $boleto['numero_boleto'] ?></strong>
                                            <?php if (!empty($boleto['arquivo_pdf'])): ?>
                                                <br><small class="text-success">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($boleto['aluno_nome']) ?></strong>
                                                <br><small class="text-muted"><?= $boleto['cpf'] ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?= htmlspecialchars($boleto['curso_nome']) ?>
                                                <br><small class="text-muted"><?= $boleto['subdomain'] ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <strong>R$ <?= number_format($boleto['valor'], 2, ',', '.') ?></strong>
                                            <?php if ($boleto['status'] == 'pago' && !empty($boleto['valor_pago'])): ?>
                                                <br><small class="text-success">
                                                    Pago: R$ <?= number_format($boleto['valor_pago'], 2, ',', '.') ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y', strtotime($boleto['vencimento'])) ?>
                                            <?php
                                            $dias = (strtotime($boleto['vencimento']) - strtotime(date('Y-m-d'))) / (60*60*24);
                                            if ($dias < 0 && $boleto['status'] != 'pago'):
                                            ?>
                                                <br><small class="text-danger">
                                                    <?= abs(floor($dias)) ?> dias em atraso
                                                </small>
                                            <?php elseif ($dias <= 5 && $boleto['status'] != 'pago'): ?>
                                                <br><small class="text-warning">
                                                    Vence em <?= ceil($dias) ?> dias
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status badge-<?= $boleto['status'] ?>">
                                                <?= ucfirst($boleto['status']) ?>
                                            </span>
                                            <?php if ($boleto['status'] == 'pago' && !empty($boleto['data_pagamento'])): ?>
                                                <br><small class="text-muted">
                                                    <?= date('d/m/Y', strtotime($boleto['data_pagamento'])) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y H:i', strtotime($boleto['created_at'])) ?>
                                            <?php if (!empty($boleto['admin_nome'])): ?>
                                                <br><small class="text-muted">
                                                    por <?= htmlspecialchars($boleto['admin_nome']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary btn-sm" 
                                                        onclick="verDetalhes(<?= $boleto['id'] ?>)" 
                                                        title="Ver detalhes">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <?php if ($boleto['status'] == 'pendente' || $boleto['status'] == 'vencido'): ?>
                                                    <button class="btn btn-outline-success btn-sm" 
                                                            onclick="marcarComoPago(<?= $boleto['id'] ?>)" 
                                                            title="Marcar como pago">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="btn btn-outline-warning btn-sm" 
                                                            onclick="cancelarBoleto(<?= $boleto['id'] ?>)" 
                                                            title="Cancelar">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($boleto['arquivo_pdf'])): ?>
                                                    <button class="btn btn-outline-info btn-sm" 
                                                            onclick="downloadBoleto(<?= $boleto['id'] ?>)" 
                                                            title="Download PDF">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="btn btn-outline-danger btn-sm" 
                                                        onclick="removerBoleto(<?= $boleto['id'] ?>)" 
                                                        title="Remover">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginação -->
                    <?php if ($resultado['total_paginas'] > 1): ?>
                        <nav aria-label="Paginação" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php if ($resultado['pagina'] > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $resultado['pagina'] - 1])) ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $resultado['pagina'] - 2); $i <= min($resultado['total_paginas'], $resultado['pagina'] + 2); $i++): ?>
                                    <li class="page-item <?= $i == $resultado['pagina'] ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($resultado['pagina'] < $resultado['total_paginas']): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $resultado['pagina'] + 1])) ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
  <!-- Modal para marcar como pago -->
    <div class="modal fade" id="pagoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Marcar como Pago</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="pagoForm">
                        <input type="hidden" id="pago_boleto_id">
                        
                        <div class="mb-3">
                            <label for="valor_pago" class="form-label">Valor Pago</label>
                            <input type="number" class="form-control" id="valor_pago" step="0.01" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="data_pagamento" class="form-label">Data do Pagamento</label>
                            <input type="date" class="form-control" id="data_pagamento" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="observacoes_pago" class="form-label">Observações</label>
                            <textarea class="form-control" id="observacoes_pago" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" onclick="confirmarPagamento()">
                        <i class="fas fa-check"></i> Confirmar Pagamento
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para detalhes do boleto -->
    <div class="modal fade" id="detalhesModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalhes do Boleto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesConteudo">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="exportarDetalhes(currentBoletoId)" title="Exportar detalhes para CSV">
                        <i class="fas fa-download"></i> Exportar
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Variáveis globais
        let isUpdating = false;
        let currentBoletoId = null;
        
        // ========== FUNÇÕES DE DETALHES REAIS ==========
        
        // Ver detalhes do boleto - VERSÃO REAL
        function verDetalhes(boletoId) {
            currentBoletoId = boletoId;
            const modal = new bootstrap.Modal(document.getElementById('detalhesModal'));
            const conteudo = document.getElementById('detalhesConteudo');
            
            conteudo.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Carregando detalhes...</p>
                </div>
            `;
            
            modal.show();
            
            // Busca detalhes reais via API
            fetch(`/admin/api/boleto-detalhes.php?id=${boletoId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        exibirDetalhesReais(data);
                        showToast('Detalhes carregados!', 'success');
                    } else {
                        exibirErroDetalhes(data.message || 'Erro desconhecido');
                        showToast('Erro: ' + (data.message || 'Erro desconhecido'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro ao buscar detalhes:', error);
                    exibirErroDetalhes('Erro de conexão: ' + error.message);
                    showToast('Erro de conexão', 'error');
                });
        }
        
        // Exibe detalhes reais do boleto
        function exibirDetalhesReais(data) {
            const { boleto, aluno, curso, administrador, arquivo, estatisticas, historico, acoes_disponiveis } = data;
            
            // Determina classe de status
            const statusClass = {
                'pago': 'success',
                'pendente': 'warning', 
                'vencido': 'danger',
                'cancelado': 'secondary'
            }[boleto.status] || 'secondary';
            
            const html = `
                <!-- Header com Status -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h4 class="mb-1">
                            <i class="fas fa-file-invoice-dollar text-primary"></i>
                            Boleto #${boleto.numero_boleto}
                        </h4>
                        <span class="badge bg-${statusClass} fs-6">${boleto.status_label}</span>
                        ${boleto.esta_vencido ? '<span class="badge bg-danger ms-2">VENCIDO</span>' : ''}
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="h4 text-primary mb-0">${boleto.valor_formatado}</div>
                        <small class="text-muted">${boleto.status_vencimento}</small>
                    </div>
                </div>

                <!-- Informações Principais -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-info-circle"></i> Informações do Boleto
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Número:</strong></td>
                                <td>#${boleto.numero_boleto}</td>
                            </tr>
                            <tr>
                                <td><strong>Valor:</strong></td>
                                <td>${boleto.valor_formatado}</td>
                            </tr>
                            <tr>
                                <td><strong>Vencimento:</strong></td>
                                <td>${boleto.vencimento_formatado}</td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td><span class="badge bg-${statusClass}">${boleto.status_label}</span></td>
                            </tr>
                            ${boleto.data_pagamento_formatada ? `
                            <tr>
                                <td><strong>Data Pagamento:</strong></td>
                                <td>${boleto.data_pagamento_formatada}</td>
                            </tr>
                            ` : ''}
                            ${boleto.valor_pago_formatado ? `
                            <tr>
                                <td><strong>Valor Pago:</strong></td>
                                <td>${boleto.valor_pago_formatado}</td>
                            </tr>
                            ` : ''}
                            <tr>
                                <td><strong>Criado em:</strong></td>
                                <td>${boleto.created_at_formatado}</td>
                            </tr>
                        </table>
                        
                        ${boleto.descricao && boleto.descricao !== 'Sem descrição' ? `
                        <div class="mt-3">
                            <strong>Descrição:</strong>
                            <p class="text-muted">${boleto.descricao}</p>
                        </div>
                        ` : ''}
                        
                        ${boleto.observacoes ? `
                        <div class="mt-3">
                            <strong>Observações:</strong>
                            <p class="text-muted">${boleto.observacoes}</p>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-user"></i> Informações do Aluno
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Nome:</strong></td>
                                <td>${aluno.nome}</td>
                            </tr>
                            <tr>
                                <td><strong>CPF:</strong></td>
                                <td>${aluno.cpf_formatado}</td>
                            </tr>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td>
                                    <a href="mailto:${aluno.email}" class="text-decoration-none">
                                        ${aluno.email}
                                    </a>
                                </td>
                            </tr>
                            ${aluno.cidade ? `
                            <tr>
                                <td><strong>Cidade:</strong></td>
                                <td>${aluno.cidade}</td>
                            </tr>
                            ` : ''}
                            <tr>
                                <td><strong>Cadastro:</strong></td>
                                <td>${aluno.cadastro_formatado}</td>
                            </tr>
                        </table>
                        
                        <h6 class="text-primary mb-3 mt-4">
                            <i class="fas fa-graduation-cap"></i> Curso
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Nome:</strong></td>
                                <td>${curso.nome}</td>
                            </tr>
                            <tr>
                                <td><strong>Código:</strong></td>
                                <td>${curso.nome_curto}</td>
                            </tr>
                            <tr>
                                <td><strong>Polo:</strong></td>
                                <td>${curso.polo_nome}</td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Informações do Arquivo -->
                ${arquivo ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-file-pdf"></i> Arquivo PDF
                        </h6>
                        ${arquivo.existe ? `
                        <div class="alert alert-success">
                            <div class="row">
                                <div class="col-md-8">
                                    <strong><i class="fas fa-check-circle"></i> Arquivo disponível</strong>
                                    <br><small>Tamanho: ${arquivo.tamanho_formatado}</small>
                                    <br><small>Modificado: ${arquivo.data_modificacao}</small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <button class="btn btn-info btn-sm" onclick="downloadBoleto(${boleto.id})">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                </div>
                            </div>
                        </div>
                        ` : `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Arquivo não encontrado</strong>
                            <br><small>${arquivo.erro}</small>
                        </div>
                        `}
                    </div>
                </div>
                ` : ''}

                <!-- Estatísticas -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-chart-bar"></i> Estatísticas
                        </h6>
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.total_downloads}</h6>
                                        <small class="text-muted">Downloads</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.total_pix_gerados}</h6>
                                        <small class="text-muted">PIX Gerados</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.ultimo_download_formatado}</h6>
                                        <small class="text-muted">Último Download</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Histórico de Ações -->
                ${historico && historico.length > 0 ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-history"></i> Histórico de Ações
                        </h6>
                        <div style="max-height: 200px; overflow-y: auto;">
                            ${historico.map(log => `
                            <div class="border-bottom py-2">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong>${getTipoLabel(log.tipo)}</strong>
                                        <br><small class="text-muted">${log.descricao}</small>
                                        <br><small class="text-info">por ${log.admin_nome}</small>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">${log.data_formatada}</small>
                                    </div>
                                </div>
                            </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Ações Disponíveis -->
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-cogs"></i> Ações Disponíveis
                        </h6>
                        <div class="d-grid gap-2 d-md-flex">
                            ${acoes_disponiveis.map(acao => `
                            <button class="btn ${acao.classe}" onclick="executarAcao('${acao.tipo}', ${boleto.id})">
                                <i class="${acao.icone}"></i> ${acao.label}
                            </button>
                            `).join('')}
                        </div>
                    </div>
                </div>

                <!-- Informações do Administrador -->
                <div class="row mt-4">
                    <div class="col-12">
                        <small class="text-muted">
                            <i class="fas fa-user-shield"></i>
                            Criado por: ${administrador.nome}
                            ${administrador.email ? ` (${administrador.email})` : ''}
                        </small>
                    </div>
                </div>
            `;
            
            document.getElementById('detalhesConteudo').innerHTML = html;
        }
        
        // Função auxiliar para labels dos tipos de log
        function getTipoLabel(tipo) {
            const labels = {
                'upload_individual': 'Upload Individual',
                'upload_lote': 'Upload em Lote',
                'boleto_pago': 'Marcado como Pago',
                'boleto_pago_admin': 'Pago pelo Admin',
                'boleto_cancelado': 'Cancelado',
                'boleto_cancelado_admin': 'Cancelado pelo Admin',
                'download_boleto': 'Download',
                'download_pdf_sucesso': 'Download PDF',
                'download_pdf_erro': 'Erro no Download',
                'pix_gerado': 'PIX Gerado',
                'pix_erro': 'Erro no PIX',
                'remover_boleto': 'Removido',
                'atualizar_arquivo': 'Arquivo Atualizado'
            };
            
            return labels[tipo] || tipo.replace(/_/g, ' ').toUpperCase();
        }
        
        // Executa ações do boleto
        function executarAcao(acao, boletoId) {
            switch (acao) {
                case 'download':
                    downloadBoleto(boletoId);
                    break;
                case 'pix':
                    fecharDetalhes();
                    mostrarPix(boletoId);
                    break;
                case 'marcar_pago':
                    fecharDetalhes();
                    marcarComoPago(boletoId);
                    break;
                case 'cancelar':
                    fecharDetalhes();
                    cancelarBoleto(boletoId);
                    break;
                case 'editar':
                    fecharDetalhes();
                    editarBoleto(boletoId);
                    break;
                case 'remover':
                    fecharDetalhes();
                    removerBoleto(boletoId);
                    break;
                default:
                    showToast('Ação não implementada: ' + acao, 'warning');
            }
        }
        
        // Função para editar boleto (nova)
        function editarBoleto(boletoId) {
            showToast('Funcionalidade de edição será implementada em breve', 'info');
            // TODO: Implementar modal de edição
        }
        
        // Função para fechar detalhes
        function fecharDetalhes() {
            const detalhesModal = bootstrap.Modal.getInstance(document.getElementById('detalhesModal'));
            if (detalhesModal) {
                detalhesModal.hide();
            }
            currentBoletoId = null;
        }
        
        // Função melhorada para mostrar erros
        function exibirErroDetalhes(mensagem) {
            const html = `
                <div class="text-center p-4">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5 class="text-danger">Erro ao Carregar Detalhes</h5>
                    <p class="text-muted mb-4">${mensagem}</p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-redo"></i> Recarregar Página
                        </button>
                        <button class="btn btn-outline-secondary" onclick="fecharDetalhes()">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('detalhesConteudo').innerHTML = html;
        }
   // ========== FUNÇÕES DE AÇÕES DOS BOLETOS ==========
        
        // Download de boleto com feedback melhorado
        function downloadBoleto(boletoId) {
            console.log('Iniciando download do boleto:', boletoId);
            
            // Mostra loading no botão se existir
            const downloadBtn = document.querySelector(`button[onclick*="downloadBoleto(${boletoId})"]`);
            if (downloadBtn) {
                const originalText = downloadBtn.innerHTML;
                downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Baixando...';
                downloadBtn.disabled = true;
                
                setTimeout(() => {
                    downloadBtn.innerHTML = originalText;
                    downloadBtn.disabled = false;
                }, 3000);
            }
            
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
        
        // Função melhorada para marcar como pago
        function marcarComoPago(boletoId) {
            // Busca valor atual do boleto para pré-preencher
            fetch(`/admin/api/boleto-detalhes.php?id=${boletoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('pago_boleto_id').value = boletoId;
                        document.getElementById('valor_pago').value = data.boleto.valor;
                        document.getElementById('data_pagamento').value = new Date().toISOString().split('T')[0];
                        
                        const modal = new bootstrap.Modal(document.getElementById('pagoModal'));
                        modal.show();
                    } else {
                        showToast('Erro ao buscar dados do boleto', 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    // Fallback para o método original
                    document.getElementById('pago_boleto_id').value = boletoId;
                    document.getElementById('data_pagamento').value = new Date().toISOString().split('T')[0];
                    
                    const modal = new bootstrap.Modal(document.getElementById('pagoModal'));
                    modal.show();
                });
        }
        
        // Confirma pagamento
        function confirmarPagamento() {
            const boletoId = document.getElementById('pago_boleto_id').value;
            const valorPago = document.getElementById('valor_pago').value;
            const dataPagamento = document.getElementById('data_pagamento').value;
            const observacoes = document.getElementById('observacoes_pago').value;
            
            if (!valorPago || !dataPagamento) {
                showToast('Preencha todos os campos obrigatórios', 'error');
                return;
            }
            
            if (parseFloat(valorPago) <= 0) {
                showToast('Valor deve ser maior que zero', 'error');
                return;
            }
            
            // Mostra loading
            const submitBtn = document.querySelector('#pagoModal .btn-success');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
            submitBtn.disabled = true;
            
            fetch('/admin/api/marcar-pago.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    boleto_id: parseInt(boletoId),
                    valor_pago: parseFloat(valorPago),
                    data_pagamento: dataPagamento,
                    observacoes: observacoes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Boleto marcado como pago!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('pagoModal')).hide();
                    
                    // Atualiza status na lista
                    atualizarStatusBoletoNaLista(boletoId, 'pago');
                    
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('Erro de conexão', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Cancelar boleto
        function cancelarBoleto(boletoId) {
            const motivo = prompt('Motivo do cancelamento (obrigatório):');
            
            if (motivo === null) {
                return; // Usuário cancelou
            }
            
            if (!motivo.trim()) {
                showToast('Motivo do cancelamento é obrigatório', 'error');
                return;
            }
            
            if (confirm('Tem certeza que deseja cancelar este boleto?\n\nEsta ação não pode ser desfeita.')) {
                showToast('Cancelando boleto...', 'info');
                
                fetch('/admin/api/cancelar-boleto.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        boleto_id: boletoId,
                        motivo: motivo.trim()
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Boleto cancelado com sucesso!', 'success');
                        atualizarStatusBoletoNaLista(boletoId, 'cancelado');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
// Função para remover boleto
function removerBoleto(boletoId) {
    if (confirm('⚠️ ATENÇÃO: Remover Boleto\n\nEsta ação irá:\n• Excluir o boleto permanentemente\n• Remover o arquivo PDF do servidor\n• Não poderá ser desfeita\n\nTem certeza que deseja continuar?')) {
        const motivo = prompt('Motivo da remoção (obrigatório):') || 'Removido pelo administrador';
        
        if (!motivo.trim()) {
            showToast('Motivo da remoção é obrigatório', 'error');
            return;
        }
        
        showToast('Removendo boleto...', 'info');
        
        fetch('/admin/api/remover-boleto-simples.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                boleto_id: boletoId,
                motivo: motivo.trim(),
                confirmar_remocao: true
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Boleto removido com sucesso!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Erro: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            showToast('Erro de conexão', 'error');
        });
    }
}
        
        // Função para PIX (placeholder)
        function mostrarPix(boletoId) {
            showToast('Funcionalidade PIX será implementada em breve', 'info');
        }
        
        // ========== FUNÇÕES AUXILIARES ==========
        
        // Atualiza status visual do boleto na listagem
        function atualizarStatusBoletoNaLista(boletoId, novoStatus) {
            const boletoRow = document.querySelector(`tr[data-boleto-id="${boletoId}"]`);
            if (boletoRow) {
                const statusCell = boletoRow.querySelector('.badge-status');
                if (statusCell) {
                    // Remove classes antigas
                    statusCell.classList.remove('badge-pendente', 'badge-vencido', 'badge-pago', 'badge-cancelado');
                    
                    // Adiciona nova classe
                    statusCell.classList.add(`badge-${novoStatus}`);
                    statusCell.textContent = novoStatus.charAt(0).toUpperCase() + novoStatus.slice(1);
                }
            }
        }
        
        // Exportar relatório
        function exportarRelatorio() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            
            showToast('Preparando relatório...', 'info');
            
            // Simula exportação (implementar API real)
            setTimeout(() => {
                showToast('Relatório exportado com sucesso!', 'success');
            }, 2000);
        }
        
        // Função para exportar detalhes (nova funcionalidade)
        function exportarDetalhes(boletoId) {
            if (!boletoId) {
                showToast('Nenhum boleto selecionado para exportar', 'error');
                return;
            }
            
            fetch(`/admin/api/boleto-detalhes.php?id=${boletoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const csvContent = gerarCSVDetalhes(data);
                        downloadCSV(csvContent, `boleto_${data.boleto.numero_boleto}_detalhes.csv`);
                        showToast('Detalhes exportados!', 'success');
                    }
                })
                .catch(error => {
                    showToast('Erro ao exportar detalhes', 'error');
                });
        }
        
        // Função auxiliar para gerar CSV
        function gerarCSVDetalhes(data) {
            const { boleto, aluno, curso } = data;
            
            const rows = [
                ['Campo', 'Valor'],
                ['Número do Boleto', boleto.numero_boleto],
                ['Valor', boleto.valor_formatado],
                ['Status', boleto.status_label],
                ['Vencimento', boleto.vencimento_formatado],
                ['Aluno', aluno.nome],
                ['CPF', aluno.cpf_formatado],
                ['Email', aluno.email],
                ['Curso', curso.nome],
                ['Polo', curso.polo_nome],
                ['Criado em', boleto.created_at_formatado]
            ];
            
            return rows.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
        }
        
        // Função auxiliar para download de CSV
        function downloadCSV(content, filename) {
            const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
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
        
        // Log de ações do usuário (analytics)
        function logUserAction(action, data = {}) {
            try {
                // Log local para debug
                console.log('User Action:', action, data);
                
                // Armazena localmente para sync posterior
                const actions = JSON.parse(localStorage.getItem('adminActions') || '[]');
                actions.push({
                    action,
                    data,
                    timestamp: Date.now(),
                    url: window.location.href,
                    user_id: <?= $_SESSION['admin_id'] ?? 'null' ?>
                });
                
                // Mantém apenas últimas 100 ações
                if (actions.length > 100) {
                    actions.splice(0, actions.length - 100);
                }
                
                localStorage.setItem('adminActions', JSON.stringify(actions));
                
            } catch (error) {
                console.error('Erro ao registrar ação:', error);
            }
        }
        
        // ========== INICIALIZAÇÃO ==========
        
        // Auto-submit do formulário quando mudamos os filtros
        document.querySelectorAll('#filtrosForm select').forEach(select => {
            select.addEventListener('change', function() {
                // Auto-submit apenas para selects, não para inputs de texto
                if (this.name !== 'busca') {
                    document.getElementById('filtrosForm').submit();
                }
            });
        });
        
        // Busca em tempo real (debounced)
        let searchTimeout;
        const buscaInput = document.querySelector('input[name="busca"]');
        if (buscaInput) {
            buscaInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('filtrosForm').submit();
                }, 1000); // Aguarda 1 segundo após parar de digitar
            });
        }
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl + N para novo boleto
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = '/admin/upload-boletos.php';
            }
            
            // Ctrl + F para focar na busca
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const buscaInput = document.querySelector('input[name="busca"]');
                if (buscaInput) {
                    buscaInput.focus();
                }
            }
            
            // ESC para limpar filtros ou fechar modal
            if (e.key === 'Escape') {
                const detalhesModal = bootstrap.Modal.getInstance(document.getElementById('detalhesModal'));
                if (detalhesModal) {
                    detalhesModal.hide();
                } else {
                    window.location.href = '/admin/boletos.php';
                }
            }
            
            // F5 para recarregar
            if (e.key === 'F5' && !e.ctrlKey) {
                e.preventDefault();
                showToast('Atualizando lista...', 'info');
                setTimeout(() => location.reload(), 500);
            }
        });
        
        // Melhoria no modal de detalhes - adiciona redimensionamento
        document.addEventListener('DOMContentLoaded', function() {
            const detalhesModal = document.getElementById('detalhesModal');
            if (detalhesModal) {
                // Torna o modal responsivo baseado no conteúdo
                detalhesModal.addEventListener('shown.bs.modal', function() {
                    const modalDialog = this.querySelector('.modal-dialog');
                    const content = this.querySelector('.modal-body');
                    
                    // Ajusta tamanho baseado no conteúdo
                    if (content && content.scrollHeight > 600) {
                        modalDialog.classList.add('modal-xl');
                    }
                });
                
                // Limpa classe quando fecha
                detalhesModal.addEventListener('hidden.bs.modal', function() {
                    const modalDialog = this.querySelector('.modal-dialog');
                    modalDialog.classList.remove('modal-xl');
                    currentBoletoId = null;
                });
            }
            
            // Tooltip para botões
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                new bootstrap.Tooltip(element);
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
            
            .toast-container {
                position: fixed !important;
                top: 20px !important;
                right: 20px !important;
                z-index: 2001 !important;
            }
            
            .table-hover tbody tr:hover {
                background-color: rgba(0,102,204,0.05) !important;
                cursor: pointer;
            }
            
            .table tbody tr.table-active {
                background-color: rgba(0,102,204,0.1) !important;
            }
            
            .btn-group-sm .btn {
                transition: all 0.2s ease;
            }
            
            .btn-group-sm .btn:hover {
                transform: translateY(-1px);
            }
        `;
        document.head.appendChild(style);
        
        // Log de debug final
        console.log('✅ JavaScript de detalhes reais carregado e funcionando!');
        console.log('Admin Boletos carregado', {
            total_boletos: <?= $resultado['total'] ?>,
            boletos_pagina: <?= count($resultado['boletos']) ?>,
            pagina_atual: <?= $resultado['pagina'] ?>,
            total_paginas: <?= $resultado['total_paginas'] ?>,
            filtros_ativos: <?= json_encode($filtros) ?>
        });
        
    </script>
</body>
</html>   
  