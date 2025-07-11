<?php
/**
 * Sistema de Boletos IMEPEDU - Dashboard Administrativo
 * Arquivo: admin/dashboard.php
 */

session_start();

// Verifica se admin está logado
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}
// Adicione após verificar se admin está logado:
    $poloRestrito = $admin['polo_restrito'] ?? null;
    $temRestricao = $admin['nivel_acesso'] !== 'super_admin' && !empty($poloRestrito);
    

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';
require_once 'includes/verificar-permissao.php';

$adminService = new AdminService();
$admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$admin) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Busca estatísticas gerais COM FILTRO DE POLO
$estatisticas = $adminService->obterEstatisticasComFiltroPolo($_SESSION['admin_id']);
$boletosRecentes = $adminService->buscarBoletosRecentesComFiltro($_SESSION['admin_id'], 10);
$alunosRecentes = $adminService->buscarAlunosRecentesComFiltro($_SESSION['admin_id'], 5);

// Busca estatísticas por polo (apenas polos disponíveis para o admin)
$estatisticasPolos = [];
$polosDisponiveis = $adminService->getPolosDisponiveis($_SESSION['admin_id']);

foreach ($polosDisponiveis as $polo) {
    $estatisticasPolos[$polo] = $adminService->obterEstatisticasPolo($polo);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - Sistema de Boletos IMEPEDU</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
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
            justify-content: between;
            align-items: center;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-left: 4px solid;
            transition: transform 0.2s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.total { border-left-color: var(--info-color); }
        .stat-card.pagos { border-left-color: var(--success-color); }
        .stat-card.pendentes { border-left-color: var(--warning-color); }
        .stat-card.vencidos { border-left-color: var(--danger-color); }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
        }
        
        .badge-status {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pago { background: rgba(40,167,69,0.1); color: var(--success-color); }
        .badge-pendente { background: rgba(255,193,7,0.1); color: #856404; }
        .badge-vencido { background: rgba(220,53,69,0.1); color: var(--danger-color); }
        
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
        
        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .quick-action-btn {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 1.5rem;
            text-decoration: none;
            color: #495057;
            transition: all 0.3s ease;
            flex: 1;
            text-align: center;
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            text-decoration: none;
        }
        
        .quick-action-btn i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
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
            
            .quick-actions {
                flex-direction: column;
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
                <a href="/admin/dashboard.php" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </div>
            <?php if ($usuarioService->temPermissao($admin, 'ver_boletos')): ?>
<div class="nav-item">
    <a href="/admin/boletos.php" class="nav-link">
        <i class="fas fa-file-invoice-dollar"></i>
        Gerenciar Boletos
    </a>
</div>
<?php endif; ?>
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
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div>
                <h3 class="mb-0">Dashboard Administrativo</h3>
                <?php if ($temRestricao): ?>
                <?php $configPolo = MoodleConfig::getConfig($poloRestrito); ?>
                <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle"></i>
                Visualizando dados apenas do polo: <strong><?= htmlspecialchars($configPolo['name'] ?? $poloRestrito) ?></strong>
                </div>
                <?php endif; ?>

                <small class="text-muted">Visão geral do sistema de boletos</small>
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
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="/admin/upload-boletos.php" class="quick-action-btn">
                <i class="fas fa-upload text-primary"></i>
                <strong>Upload de Boletos</strong>
                <small class="d-block text-muted">Enviar PDFs de boletos</small>
            </a>
            <a href="/admin/boletos.php?status=pendente" class="quick-action-btn">
                <i class="fas fa-clock text-warning"></i>
                <strong>Boletos Pendentes</strong>
                <small class="d-block text-muted"><?= $estatisticas['boletos_pendentes'] ?> pendentes</small>
            </a>
            <a href="/admin/boletos.php?status=vencido" class="quick-action-btn">
                <i class="fas fa-exclamation-triangle text-danger"></i>
                <strong>Boletos Vencidos</strong>
                <small class="d-block text-muted"><?= $estatisticas['boletos_vencidos'] ?> vencidos</small>
            </a>
            <a href="/admin/relatorios.php" class="quick-action-btn">
                <i class="fas fa-chart-line text-success"></i>
                <strong>Relatórios</strong>
                <small class="d-block text-muted">Visualizar estatísticas</small>
            </a>
        </div>
        
        <!-- Estatísticas Gerais -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card total">
                    <div class="stat-number text-info"><?= number_format($estatisticas['total_boletos']) ?></div>
                    <div class="stat-label">Total de Boletos</div>
                    <small class="text-muted">
                        R$ <?= number_format($estatisticas['valor_total'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card pagos">
                    <div class="stat-number text-success"><?= number_format($estatisticas['boletos_pagos']) ?></div>
                    <div class="stat-label">Boletos Pagos</div>
                    <small class="text-muted">
                        R$ <?= number_format($estatisticas['valor_recebido'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card pendentes">
                    <div class="stat-number text-warning"><?= number_format($estatisticas['boletos_pendentes']) ?></div>
                    <div class="stat-label">Pendentes</div>
                    <small class="text-muted">
                        R$ <?= number_format($estatisticas['valor_pendente'], 2, ',', '.') ?>
                    </small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card vencidos">
                    <div class="stat-number text-danger"><?= number_format($estatisticas['boletos_vencidos']) ?></div>
                    <div class="stat-label">Vencidos</div>
                    <small class="text-muted">Requer atenção</small>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Boletos Recentes -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Boletos Recentes
                        </h5>
                    </div>
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
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($boletosRecentes as $boleto): ?>
                                        <tr>
                                            <td>
                                                <strong>#<?= $boleto['numero_boleto'] ?></strong>
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
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="/admin/boleto-detalhes.php?id=<?= $boleto['id'] ?>" 
                                                       class="btn btn-outline-primary btn-sm" title="Ver detalhes">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($boleto['status'] == 'pendente'): ?>
                                                        <button class="btn btn-outline-success btn-sm" 
                                                                onclick="marcarComoPago(<?= $boleto['id'] ?>)" 
                                                                title="Marcar como pago">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!empty($boleto['arquivo_pdf'])): ?>
                                                        <a href="/admin/download-boleto.php?id=<?= $boleto['id'] ?>" 
                                                           class="btn btn-outline-info btn-sm" title="Download PDF">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <a href="/admin/boletos.php" class="btn btn-primary">
                                Ver Todos os Boletos
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar Info -->
            <div class="col-lg-4">
                <!-- Estatísticas por Polo -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="fas fa-map-marker-alt"></i>
                            Estatísticas por Polo
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($estatisticasPolos as $polo => $stats): ?>
                            <?php $configPolo = MoodleConfig::getConfig($polo); ?>
                            <div class="mb-3 pb-3 border-bottom">
                                <h6 class="text-primary mb-2">
                                    <?= htmlspecialchars($configPolo['name'] ?? $polo) ?>
                                </h6>
                                <div class="row text-center">
                                    <div class="col-4">
                                        <strong class="text-info d-block"><?= $stats['total_boletos'] ?></strong>
                                        <small class="text-muted">Total</small>
                                    </div>
                                    <div class="col-4">
                                        <strong class="text-success d-block"><?= $stats['boletos_pagos'] ?></strong>
                                        <small class="text-muted">Pagos</small>
                                    </div>
                                    <div class="col-4">
                                        <strong class="text-warning d-block"><?= $stats['boletos_pendentes'] ?></strong>
                                        <small class="text-muted">Pendentes</small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Alunos Recentes -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="fas fa-users"></i>
                            Alunos Recentes
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($alunosRecentes as $aluno): ?>
                            <div class="d-flex align-items-center mb-3">
                                <div class="user-avatar me-3" style="width: 35px; height: 35px; font-size: 0.8rem;">
                                    <?= strtoupper(substr($aluno['nome'], 0, 1)) ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= htmlspecialchars($aluno['nome']) ?></div>
                                    <small class="text-muted">
                                        <?= $aluno['cpf'] ?> • <?= $aluno['subdomain'] ?>
                                    </small>
                                </div>
                                <small class="text-muted">
                                    <?= date('d/m', strtotime($aluno['created_at'])) ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="text-center mt-3">
                            <a href="/admin/alunos.php" class="btn btn-outline-primary btn-sm">
                                Ver Todos os Alunos
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Inicializa DataTable
        $(document).ready(function() {
            $('#boletosTable').DataTable({
                "order": [[4, "desc"]], // Ordena por vencimento
                "pageLength": 10,
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/pt-BR.json"
                },
                "columnDefs": [
                    { "orderable": false, "targets": 6 } // Coluna de ações não ordenável
                ]
            });
        });
        
        // Função para marcar boleto como pago
        function marcarComoPago(boletoId) {
            if (confirm('Tem certeza que deseja marcar este boleto como pago?')) {
                fetch('/admin/api/marcar-pago.php', {
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
                        showToast('Boleto marcado como pago!', 'success');
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
            const toast = document.createElement('div');
            toast.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${type === 'error' ? 'fa-exclamation-triangle' : type === 'success' ? 'fa-check-circle' : 'fa-info-circle'} me-2"></i>
                    <span>${message}</span>
                    <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 5000);
        }
        
        // Auto-refresh a cada 5 minutos
        setTimeout(() => {
            location.reload();
        }, 300000);
    </script>
</body>
</html>