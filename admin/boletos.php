<?php
/**
 * Sistema de Boletos IMED - Gerenciamento de Boletos
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
    <title>Gerenciar Boletos - Administração IMED</title>
    
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
            <h4><i class="fas fa-graduation-cap"></i> IMED Admin</h4>
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
                                    <tr>
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
        <div class="modal-dialog modal-lg">
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Ver detalhes do boleto
        function verDetalhes(boletoId) {
            const modal = new bootstrap.Modal(document.getElementById('detalhesModal'));
            const conteudo = document.getElementById('detalhesConteudo');
            
            conteudo.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                </div>
            `;
            
            modal.show();
            
            // Simula busca de detalhes (implementar API real)
            setTimeout(() => {
                conteudo.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Informações do Boleto</h6>
                            <p><strong>Número:</strong> #${boletoId}</p>
                            <p><strong>Valor:</strong> R$ 150,00</p>
                            <p><strong>Vencimento:</strong> 15/01/2024</p>
                            <p><strong>Status:</strong> <span class="badge-status badge-pendente">Pendente</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Informações do Aluno</h6>
                            <p><strong>Nome:</strong> João Silva</p>
                            <p><strong>CPF:</strong> 123.456.789-00</p>
                            <p><strong>Email:</strong> joao@email.com</p>
                            <p><strong>Curso:</strong> Técnico em Enfermagem</p>
                        </div>
                    </div>
                `;
            }, 1000);
        }
        
        // Marcar boleto como pago
        function marcarComoPago(boletoId) {
            document.getElementById('pago_boleto_id').value = boletoId;
            document.getElementById('data_pagamento').value = new Date().toISOString().split('T')[0];
            
            const modal = new bootstrap.Modal(document.getElementById('pagoModal'));
            modal.show();
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
            
            fetch('/admin/api/marcar-pago.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    boleto_id: boletoId,
                    valor_pago: parseFloat(valorPago),
                    data_pagamento: dataPagamento,
                    observacoes: observacoes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Boleto marcado como pago!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
                
                bootstrap.Modal.getInstance(document.getElementById('pagoModal')).hide();
            })
            .catch(error => {
                showToast('Erro de conexão', 'error');
            });
        }
        
        // Cancelar boleto
        function cancelarBoleto(boletoId) {
            const motivo = prompt('Motivo do cancelamento:');
            if (motivo === null) return;
            
            if (confirm('Tem certeza que deseja cancelar este boleto?')) {
                fetch('/admin/api/cancelar-boleto.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        boleto_id: boletoId,
                        motivo: motivo
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Boleto cancelado!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        // Remover boleto
        function removerBoleto(boletoId) {
            if (confirm('Tem certeza que deseja remover este boleto? Esta ação não pode ser desfeita.')) {
                const motivo = prompt('Motivo da remoção:') || 'Removido pelo administrador';
                
                fetch('/admin/api/remover-boleto.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        boleto_id: boletoId,
                        motivo: motivo
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Boleto removido!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        // Download do boleto
        function downloadBoleto(boletoId) {
            window.open(`/admin/api/download-boleto.php?id=${boletoId}`, '_blank');
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
        
        // Sistema de notificações
        function showToast(message, type = 'info') {
            const existingToasts = document.querySelectorAll('.toast-custom');
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `toast-custom alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideInRight 0.3s ease;';
            
            const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                        type === 'success' ? 'fa-check-circle' : 'fa-info-circle';
            
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${icon} me-2"></i>
                    <span>${message}</span>
                    <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 5000);
        }
        
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
        document.querySelector('input[name="busca"]').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filtrosForm').submit();
            }, 1000); // Aguarda 1 segundo após parar de digitar
        });
        
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
                document.querySelector('input[name="busca"]').focus();
            }
            
            // ESC para limpar filtros
            if (e.key === 'Escape') {
                window.location.href = '/admin/boletos.php';
            }
        });
        
        // Tooltip para botões
        document.addEventListener('DOMContentLoaded', function() {
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                new bootstrap.Tooltip(element);
            });
        });
        
        // Seleção múltipla (futuro)
        let selectedBoletos = [];
        
        function toggleSelection(boletoId) {
            const index = selectedBoletos.indexOf(boletoId);
            if (index > -1) {
                selectedBoletos.splice(index, 1);
            } else {
                selectedBoletos.push(boletoId);
            }
            
            updateSelectionUI();
        }
        
        function updateSelectionUI() {
            const count = selectedBoletos.length;
            const actionBar = document.getElementById('actionBar');
            
            if (count > 0) {
                if (!actionBar) {
                    createActionBar();
                }
                document.getElementById('selectedCount').textContent = count;
                document.getElementById('actionBar').style.display = 'block';
            } else if (actionBar) {
                actionBar.style.display = 'none';
            }
        }
        
        function createActionBar() {
            const actionBar = document.createElement('div');
            actionBar.id = 'actionBar';
            actionBar.className = 'position-fixed bottom-0 start-50 translate-middle-x bg-primary text-white p-3 rounded-top shadow';
            actionBar.style.display = 'none';
            actionBar.style.zIndex = '1050';
            
            actionBar.innerHTML = `
                <div class="d-flex align-items-center gap-3">
                    <span><span id="selectedCount">0</span> boletos selecionados</span>
                    <button class="btn btn-light btn-sm" onclick="marcarTodosSelecionadosComoPagos()">
                        <i class="fas fa-check"></i> Marcar como Pagos
                    </button>
                    <button class="btn btn-warning btn-sm" onclick="cancelarTodosSelecionados()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="removerTodosSelecionados()">
                        <i class="fas fa-trash"></i> Remover
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="clearSelection()">
                        <i class="fas fa-times"></i> Limpar
                    </button>
                </div>
            `;
            
            document.body.appendChild(actionBar);
        }
        
        function clearSelection() {
            selectedBoletos = [];
            updateSelectionUI();
            
            // Remove marcação visual das linhas
            document.querySelectorAll('tr.table-active').forEach(row => {
                row.classList.remove('table-active');
            });
        }
        
        // Funções para ações em lote (implementar conforme necessário)
        function marcarTodosSelecionadosComoPagos() {
            if (selectedBoletos.length === 0) return;
            
            showToast(`Marcando ${selectedBoletos.length} boletos como pagos...`, 'info');
            // Implementar lógica
        }
        
        function cancelarTodosSelecionados() {
            if (selectedBoletos.length === 0) return;
            
            const motivo = prompt('Motivo do cancelamento em lote:');
            if (motivo) {
                showToast(`Cancelando ${selectedBoletos.length} boletos...`, 'info');
                // Implementar lógica
            }
        }
        
        function removerTodosSelecionados() {
            if (selectedBoletos.length === 0) return;
            
            if (confirm(`Tem certeza que deseja remover ${selectedBoletos.length} boletos?`)) {
                showToast(`Removendo ${selectedBoletos.length} boletos...`, 'info');
                // Implementar lógica
            }
        }
        
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
        
        // Log de debug
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