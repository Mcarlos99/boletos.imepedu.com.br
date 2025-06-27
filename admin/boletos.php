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
    
    <!-- CSS comum do admin -->
    <style>
        /* Mesmos estilos do dashboard.php */
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
    </style>
</head>
<body>
    <!-- Sidebar (mesma do dashboard) -->
    <div class="sidebar">
        <!-- Conteúdo da sidebar -->
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3>Gerenciar Boletos</h3>
                <small class="text-muted">Total: <?= $resultado['total'] ?> boletos</small>
            </div>
            <a href="/admin/upload-boletos.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Novo Boleto
            </a>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
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
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Boletos -->
        <div class="card">
            <div class="card-body">
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
                                        <?php if ($boleto['status'] == 'pago' && $boleto['valor_pago']): ?>
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
                                        <?php if ($boleto['status'] == 'pago' && $boleto['data_pagamento']): ?>
                                            <br><small class="text-muted">
                                                <?= date('d/m/Y', strtotime($boleto['data_pagamento'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= date('d/m/Y H:i', strtotime($boleto['created_at'])) ?>
                                        <?php if ($boleto['admin_nome']): ?>
                                            <br><small class="text-muted">
                                                por <?= htmlspecialchars($boleto['admin_nome']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="/admin/boleto-detalhes.php?id=<?= $boleto['id'] ?>" 
                                               class="btn btn-outline-primary btn-sm" title="Ver detalhes">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
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
                                                <a href="/admin/api/download-boleto.php?id=<?= $boleto['id'] ?>" 
                                                   class="btn btn-outline-info btn-sm" title="Download PDF">
                                                    <i class="fas fa-download"></i>
                                                </a>
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
                    <nav aria-label="Paginação">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $resultado['total_paginas']; $i++): ?>
                                <li class="page-item <?= $i == $resultado['pagina'] ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
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
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Função para marcar boleto como pago
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
                alert('Preencha todos os campos obrigatórios');
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
        
        // Função para cancelar boleto
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
        
        // Função para remover boleto
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
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>