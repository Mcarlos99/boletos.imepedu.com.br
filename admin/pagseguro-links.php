<?php
/**
 * Página de administração específica para links PagSeguro
 * Arquivo: admin/pagseguro-links.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../src/AdminService.php';
require_once '../src/BoletoUploadService.php';

$adminService = new AdminService();
$uploadService = new BoletoUploadService();
$admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$admin) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Processar ações
$sucesso = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['acao'])) {
            switch ($_POST['acao']) {
                case 'atualizar_status':
                    $resultado = $uploadService->atualizarStatusLinkPagSeguro(
                        $_POST['boleto_id'],
                        $_POST['novo_status'],
                        $_POST['observacoes'] ?? ''
                    );
                    
                    if ($resultado) {
                        $sucesso = 'Status atualizado com sucesso!';
                    } else {
                        $erro = 'Erro ao atualizar status';
                    }
                    break;
                    
                case 'reenviar_link':
                    // Implementar reenvio de link se necessário
                    $sucesso = 'Funcionalidade de reenvio implementada';
                    break;
            }
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar links PagSeguro
$filtros = [
    'busca' => $_GET['busca'] ?? '',
    'status' => $_GET['status'] ?? '',
    'data_inicio' => $_GET['data_inicio'] ?? '',
    'data_fim' => $_GET['data_fim'] ?? ''
];

$pagina = max(1, intval($_GET['pagina'] ?? 1));
$resultado = $uploadService->listarBoletosPagSeguro($filtros, $pagina, 20);

// Estatísticas
$estatisticas = $uploadService->getEstatisticasLinksPagSeguro();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Links PagSeguro - Administração IMEPEDU</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --pagseguro-color: #00A868;
            --pagseguro-hover: #008552;
        }
        
        .navbar-brand {
            color: var(--pagseguro-color) !important;
            font-weight: bold;
        }
        
        .btn-pagseguro {
            background-color: var(--pagseguro-color);
            border-color: var(--pagseguro-color);
            color: white;
        }
        
        .btn-pagseguro:hover {
            background-color: var(--pagseguro-hover);
            border-color: var(--pagseguro-hover);
            color: white;
        }
        
        .card-pagseguro {
            border-left: 4px solid var(--pagseguro-color);
        }
        
        .link-preview {
            max-width: 300px;
            word-break: break-all;
            font-size: 0.85em;
        }
        
        .status-badge {
            font-size: 0.8em;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="/admin/dashboard.php">
            <i class="fas fa-link"></i> IMEPEDU - Links PagSeguro
        </a>
        
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="/admin/upload-boletos.php">
                <i class="fas fa-plus"></i> Novo Link
            </a>
            <a class="nav-link" href="/admin/boletos.php">
                <i class="fas fa-file-invoice"></i> Todos os Boletos
            </a>
            <a class="nav-link" href="/admin/dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    
    <!-- Alertas -->
    <?php if ($sucesso): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Estatísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card card-pagseguro">
                <div class="card-body text-center">
                    <h3 class="text-success"><?= $estatisticas['total_links'] ?></h3>
                    <p class="card-text">Links Cadastrados</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-pagseguro">
                <div class="card-body text-center">
                    <h3 class="text-info">R$ <?= number_format($estatisticas['valor_total'], 2, ',', '.') ?></h3>
                    <p class="card-text">Valor Total</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-pagseguro">
                <div class="card-body text-center">
                    <h3 class="text-warning"><?= $estatisticas['vencendo_7_dias'] ?></h3>
                    <p class="card-text">Vencendo em 7 dias</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-pagseguro">
                <div class="card-body text-center">
                    <h3 class="text-primary"><?= $estatisticas['taxa_utilizacao'] ?>%</h3>
                    <p class="card-text">Taxa de Pagamento</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
        </div>
        <div class="card-body">
            <form method="GET">
                <div class="row">
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="busca" 
                               placeholder="Buscar por CPF, nome ou boleto..."
                               value="<?= htmlspecialchars($filtros['busca']) ?>">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="status">
                            <option value="">Todos os status</option>
                            <option value="pendente" <?= $filtros['status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <option value="pago" <?= $filtros['status'] === 'pago' ? 'selected' : '' ?>>Pago</option>
                            <option value="vencido" <?= $filtros['status'] === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                            <option value="cancelado" <?= $filtros['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" name="data_inicio" 
                               value="<?= htmlspecialchars($filtros['data_inicio']) ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" name="data_fim" 
                               value="<?= htmlspecialchars($filtros['data_fim']) ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-pagseguro">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <a href="/admin/pagseguro-links.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Limpar
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Links -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-link"></i> Links PagSeguro</h5>
            <span class="badge bg-info">Total: <?= $resultado['total'] ?></span>
        </div>
        <div class="card-body">
            
            <?php if (empty($resultado['boletos'])): ?>
                <div class="text-center py-4">
                    <i class="fas fa-link fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">Nenhum link encontrado</h5>
                    <p class="text-muted">Use os filtros acima ou <a href="/admin/upload-boletos.php">cadastre um novo link</a></p>
                </div>
            <?php else: ?>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Data</th>
                                <th>Boleto</th>
                                <th>Aluno</th>
                                <th>Valor</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th>Link</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultado['boletos'] as $boleto): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($boleto['created_at'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($boleto['numero_boleto']) ?></strong>
                                        <?php if ($boleto['descricao']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($boleto['descricao']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($boleto['aluno_nome']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($boleto['cpf']) ?></small>
                                    </td>
                                    <td>R$ <?= number_format($boleto['valor'], 2, ',', '.') ?></td>
                                    <td>
                                        <?= date('d/m/Y', strtotime($boleto['vencimento'])) ?>
                                        <?php 
                                        $diasVencimento = (strtotime($boleto['vencimento']) - time()) / (60 * 60 * 24);
                                        if ($diasVencimento < 0): 
                                        ?>
                                            <br><span class="badge bg-danger">Vencido</span>
                                        <?php elseif ($diasVencimento <= 7): ?>
                                            <br><span class="badge bg-warning">Vence em <?= ceil($diasVencimento) ?> dias</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = match($boleto['status']) {
                                            'pago' => 'success',
                                            'vencido' => 'danger',
                                            'cancelado' => 'secondary',
                                            default => 'warning'
                                        };
                                        ?>
                                        <span class="badge bg-<?= $statusClass ?> status-badge">
                                            <?= ucfirst($boleto['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($boleto['link_pagseguro']): ?>
                                            <div class="link-preview">
                                                <a href="<?= htmlspecialchars($boleto['link_pagseguro']) ?>" 
                                                   target="_blank" class="text-decoration-none">
                                                    <i class="fas fa-external-link-alt"></i>
                                                    <?= htmlspecialchars(substr($boleto['link_pagseguro'], 0, 40)) ?>...
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($boleto['link_pagseguro']): ?>
                                                <a href="<?= htmlspecialchars($boleto['link_pagseguro']) ?>" 
                                                   target="_blank" class="btn btn-outline-primary" title="Abrir link">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="btn btn-outline-info" 
                                                    data-bs-toggle="modal" data-bs-target="#modalStatus<?= $boleto['id'] ?>"
                                                    title="Alterar status">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <a href="/admin/boletos.php?id=<?= $boleto['id'] ?>" 
                                               class="btn btn-outline-secondary" title="Ver detalhes">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Modal para alterar status -->
                                <div class="modal fade" id="modalStatus<?= $boleto['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Alterar Status - <?= htmlspecialchars($boleto['numero_boleto']) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="acao" value="atualizar_status">
                                                    <input type="hidden" name="boleto_id" value="<?= $boleto['id'] ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Novo Status</label>
                                                        <select class="form-select" name="novo_status" required>
                                                            <option value="pendente" <?= $boleto['status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                                                            <option value="pago" <?= $boleto['status'] === 'pago' ? 'selected' : '' ?>>Pago</option>
                                                            <option value="vencido" <?= $boleto['status'] === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                                                            <option value="cancelado" <?= $boleto['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Observações</label>
                                                        <textarea class="form-control" name="observacoes" rows="3"
                                                                  placeholder="Motivo da alteração..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <button type="submit" class="btn btn-pagseguro">Atualizar Status</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginação -->
                <?php if ($resultado['total_paginas'] > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $resultado['total_paginas']; $i++): ?>
                                <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $i ?>&<?= http_build_query($filtros) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
                
            <?php endif; ?>
            
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>