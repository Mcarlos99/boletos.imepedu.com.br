<?php
/**
 * Sistema de Boletos IMEPEDU - Gerenciamento de Alunos
 * Arquivo: admin/alunos.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';
require_once '../src/AlunoService.php';

$adminService = new AdminService();
$alunoService = new AlunoService();

// Processa filtros
$filtros = [];
$pagina = intval($_GET['pagina'] ?? 1);

if (!empty($_GET['polo'])) $filtros['polo'] = $_GET['polo'];
if (!empty($_GET['curso_id'])) $filtros['curso_id'] = $_GET['curso_id'];
if (!empty($_GET['status'])) $filtros['status'] = $_GET['status'];
if (!empty($_GET['busca'])) $filtros['busca'] = $_GET['busca'];

// Busca alunos
$resultado = buscarAlunosComFiltros($filtros, $pagina, 20);
$polosAtivos = MoodleConfig::getActiveSubdomains();

// Busca cursos disponíveis para filtro
$cursosDisponiveis = $adminService->buscarTodosCursos();

/**
 * Função para buscar alunos com filtros e paginação
 */
function buscarAlunosComFiltros($filtros, $pagina, $itensPorPagina) {
    $db = (new Database())->getConnection();
    
    $where = ['1=1'];
    $params = [];
    
    // Aplica filtros
    if (!empty($filtros['polo'])) {
        $where[] = "a.subdomain = ?";
        $params[] = $filtros['polo'];
    }
    
    if (!empty($filtros['curso_id'])) {
        $where[] = "EXISTS (
            SELECT 1 FROM matriculas m 
            WHERE m.aluno_id = a.id 
            AND m.curso_id = ? 
            AND m.status = 'ativa'
        )";
        $params[] = $filtros['curso_id'];
    }
    
    if (!empty($filtros['status'])) {
        if ($filtros['status'] === 'com_boletos') {
            $where[] = "EXISTS (SELECT 1 FROM boletos b WHERE b.aluno_id = a.id)";
        } elseif ($filtros['status'] === 'sem_boletos') {
            $where[] = "NOT EXISTS (SELECT 1 FROM boletos b WHERE b.aluno_id = a.id)";
        } elseif ($filtros['status'] === 'ativo') {
            $where[] = "a.ultimo_acesso > DATE_SUB(NOW(), INTERVAL 30 DAY)";
        } elseif ($filtros['status'] === 'inativo') {
            $where[] = "(a.ultimo_acesso IS NULL OR a.ultimo_acesso <= DATE_SUB(NOW(), INTERVAL 30 DAY))";
        }
    }
    
    if (!empty($filtros['busca'])) {
        $where[] = "(a.nome LIKE ? OR a.cpf LIKE ? OR a.email LIKE ?)";
        $termoBusca = '%' . $filtros['busca'] . '%';
        $params[] = $termoBusca;
        $params[] = $termoBusca;
        $params[] = $termoBusca;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Conta total
    $stmtCount = $db->prepare("
        SELECT COUNT(DISTINCT a.id) as total
        FROM alunos a
        WHERE {$whereClause}
    ");
    $stmtCount->execute($params);
    $total = $stmtCount->fetch()['total'];
    
    // Busca registros
    $offset = ($pagina - 1) * $itensPorPagina;
    $stmt = $db->prepare("
        SELECT a.*,
               COUNT(DISTINCT m.id) as total_matriculas,
               COUNT(DISTINCT b.id) as total_boletos,
               COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
               COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as boletos_pendentes,
               COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as boletos_vencidos,
               COALESCE(SUM(CASE WHEN b.status = 'pago' THEN COALESCE(b.valor_pago, b.valor) ELSE 0 END), 0) as valor_pago_total,
               COALESCE(SUM(CASE WHEN b.status IN ('pendente', 'vencido') THEN b.valor ELSE 0 END), 0) as valor_pendente_total
        FROM alunos a
        LEFT JOIN matriculas m ON a.id = m.aluno_id AND m.status = 'ativa'
        LEFT JOIN boletos b ON a.id = b.aluno_id
        WHERE {$whereClause}
        GROUP BY a.id
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $itensPorPagina;
    $params[] = $offset;
    $stmt->execute($params);
    
    return [
        'alunos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'pagina' => $pagina,
        'total_paginas' => ceil($total / $itensPorPagina),
        'itens_por_pagina' => $itensPorPagina
    ];
}

/**
 * Função para obter estatísticas gerais de alunos
 */
function obterEstatisticasAlunos() {
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT a.id) as total_alunos,
            COUNT(DISTINCT CASE WHEN a.ultimo_acesso > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN a.id END) as alunos_ativos,
            COUNT(DISTINCT CASE WHEN EXISTS(SELECT 1 FROM boletos b WHERE b.aluno_id = a.id) THEN a.id END) as alunos_com_boletos,
            COUNT(DISTINCT CASE WHEN EXISTS(SELECT 1 FROM matriculas m WHERE m.aluno_id = a.id AND m.status = 'ativa') THEN a.id END) as alunos_matriculados,
            COUNT(DISTINCT a.subdomain) as total_polos
        FROM alunos a
    ");
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$estatisticas = obterEstatisticasAlunos();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Alunos - Administração IMEPEDU</title>
    
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
        
        .badge-ativo { background: rgba(40,167,69,0.1); color: #28a745; }
        .badge-inativo { background: rgba(108,117,125,0.1); color: #6c757d; }
        .badge-com-boletos { background: rgba(0,123,255,0.1); color: #007bff; }
        .badge-sem-boletos { background: rgba(255,193,7,0.1); color: #856404; }
        
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
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 0.75rem;
        }
        
        .progress-mini {
            height: 6px;
            border-radius: 3px;
            margin-top: 0.25rem;
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
                <a href="/admin/upload-boletos.php" class="nav-link">
                    <i class="fas fa-upload"></i>
                    Upload de Boletos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/alunos.php" class="nav-link active">
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
                <h3>Gerenciar Alunos</h3>
                <small class="text-muted">Total: <?= $resultado['total'] ?> alunos encontrados</small>
            </div>
            <div>
                <button class="btn btn-primary" onclick="sincronizarAlunos()">
                    <i class="fas fa-sync-alt"></i> Sincronizar com Moodle
                </button>
                <button class="btn btn-outline-secondary" onclick="exportarAlunos()">
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
                        <div class="stat-number"><?= number_format($estatisticas['total_alunos']) ?></div>
                        <div class="stat-label">Total de Alunos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= number_format($estatisticas['alunos_ativos']) ?></div>
                        <div class="stat-label">Alunos Ativos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= number_format($estatisticas['alunos_com_boletos']) ?></div>
                        <div class="stat-label">Com Boletos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= number_format($estatisticas['total_polos']) ?></div>
                        <div class="stat-label">Polos Ativos</div>
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
                    
                    <div class="col-md-3">
                        <label class="form-label">Curso</label>
                        <select name="curso_id" class="form-select">
                            <option value="">Todos os cursos</option>
                            <?php foreach ($cursosDisponiveis as $curso): ?>
                                <option value="<?= $curso['id'] ?>" <?= ($_GET['curso_id'] ?? '') == $curso['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($curso['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">Todos</option>
                            <option value="ativo" <?= ($_GET['status'] ?? '') == 'ativo' ? 'selected' : '' ?>>Ativos (30 dias)</option>
                            <option value="inativo" <?= ($_GET['status'] ?? '') == 'inativo' ? 'selected' : '' ?>>Inativos</option>
                            <option value="com_boletos" <?= ($_GET['status'] ?? '') == 'com_boletos' ? 'selected' : '' ?>>Com Boletos</option>
                            <option value="sem_boletos" <?= ($_GET['status'] ?? '') == 'sem_boletos' ? 'selected' : '' ?>>Sem Boletos</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nome, CPF, email..." value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
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
                        <a href="/admin/alunos.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Limpar Filtros
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Alunos -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($resultado['alunos'])): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>Nenhum aluno encontrado</h5>
                        <p class="text-muted">Tente ajustar os filtros ou sincronizar com o Moodle</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="alunosTable">
                            <thead>
                                <tr>
                                    <th>Aluno</th>
                                    <th>Contato</th>
                                    <th>Polo</th>
                                    <th>Matrículas</th>
                                    <th>Boletos</th>
                                    <th>Financeiro</th>
                                    <th>Último Acesso</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultado['alunos'] as $aluno): ?>
                                    <?php
                                    $totalBoletos = intval($aluno['total_boletos']);
                                    $boletosPagos = intval($aluno['boletos_pagos']);
                                    $boletosPendentes = intval($aluno['boletos_pendentes']);
                                    $boletosVencidos = intval($aluno['boletos_vencidos']);
                                    
                                    $ativo = $aluno['ultimo_acesso'] && 
                                             strtotime($aluno['ultimo_acesso']) > strtotime('-30 days');
                                    
                                    $valorPago = floatval($aluno['valor_pago_total']);
                                    $valorPendente = floatval($aluno['valor_pendente_total']);
                                    $valorTotal = $valorPago + $valorPendente;
                                    
                                    $percentualPago = $valorTotal > 0 ? ($valorPago / $valorTotal) * 100 : 0;
                                    ?>
                                    <tr data-aluno-id="<?= $aluno['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar">
                                                    <?= strtoupper(substr($aluno['nome'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <strong><?= htmlspecialchars($aluno['nome']) ?></strong>
                                                    <br><small class="text-muted">CPF: <?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $aluno['cpf']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <i class="fas fa-envelope text-muted"></i>
                                                <small><?= htmlspecialchars($aluno['email']) ?></small>
                                            </div>
                                            <?php if (!empty($aluno['city'])): ?>
                                            <div class="mt-1">
                                                <i class="fas fa-map-marker-alt text-muted"></i>
                                                <small><?= htmlspecialchars($aluno['city']) ?></small>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php $config = MoodleConfig::getConfig($aluno['subdomain']); ?>
                                            <span class="badge bg-primary"><?= htmlspecialchars($config['name'] ?? $aluno['subdomain']) ?></span>
                                            <br><small class="text-muted"><?= $aluno['subdomain'] ?></small>
                                        </td>
                                        <td>
                                            <div class="text-center">
                                                <strong class="text-info"><?= intval($aluno['total_matriculas']) ?></strong>
                                                <br><small class="text-muted">Cursos</small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($totalBoletos > 0): ?>
                                                <div class="text-center">
                                                    <strong><?= $totalBoletos ?></strong> total
                                                    <div class="small">
                                                        <?php if ($boletosPagos > 0): ?>
                                                            <span class="text-success"><?= $boletosPagos ?> pagos</span>
                                                        <?php endif; ?>
                                                        <?php if ($boletosPendentes > 0): ?>
                                                            <span class="text-warning"><?= $boletosPendentes ?> pendentes</span>
                                                        <?php endif; ?>
                                                        <?php if ($boletosVencidos > 0): ?>
                                                            <span class="text-danger"><?= $boletosVencidos ?> vencidos</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-center text-muted">
                                                    <small>Sem boletos</small>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($valorTotal > 0): ?>
                                                <div>
                                                    <strong>R$ <?= number_format($valorTotal, 2, ',', '.') ?></strong>
                                                    <?php if ($valorPago > 0): ?>
                                                        <br><small class="text-success">Pago: R$ <?= number_format($valorPago, 2, ',', '.') ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($valorPendente > 0): ?>
                                                        <br><small class="text-warning">Pendente: R$ <?= number_format($valorPendente, 2, ',', '.') ?></small>
                                                    <?php endif; ?>
                                                    <div class="progress progress-mini">
                                                        <div class="progress-bar bg-success" style="width: <?= $percentualPago ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($aluno['ultimo_acesso']): ?>
                                                <div>
                                                    <?= date('d/m/Y', strtotime($aluno['ultimo_acesso'])) ?>
                                                    <br><span class="badge-status badge-<?= $ativo ? 'ativo' : 'inativo' ?>">
                                                        <?= $ativo ? 'Ativo' : 'Inativo' ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Nunca acessou</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary btn-sm" 
                                                        onclick="verDetalhesAluno(<?= $aluno['id'] ?>)" 
                                                        title="Ver detalhes">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="btn btn-outline-info btn-sm" 
                                                        onclick="verBoletosAluno(<?= $aluno['id'] ?>)" 
                                                        title="Ver boletos">
                                                    <i class="fas fa-file-invoice-dollar"></i>
                                                </button>
                                                
                                                <button class="btn btn-outline-success btn-sm" 
                                                        onclick="sincronizarAluno(<?= $aluno['id'] ?>)" 
                                                        title="Sincronizar">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                                
                                                    <!-- <button class="btn btn-outline-secondary btn-sm" 
                                                        onclick="editarAluno(<?= $aluno['id'] ?>)" 
                                                        title="Editar">
                                                    <i class="fas fa-edit"></i> -->
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
    
    <!-- Modal para detalhes do aluno -->
    <div class="modal fade" id="detalhesAlunoModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalhes do Aluno</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesAlunoConteudo">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="exportarDetalhesAluno(currentAlunoId)">
                        <i class="fas fa-download"></i> Exportar
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para editar aluno -->
    <div class="modal fade" id="editarAlunoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Aluno</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editarAlunoForm">
                        <input type="hidden" id="edit_aluno_id">
                        
                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome Completo</label>
                            <input type="text" class="form-control" id="edit_nome" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_cpf" class="form-label">CPF</label>
                                    <input type="text" class="form-control" id="edit_cpf" maxlength="14" readonly>
                                    <small class="form-text text-muted">CPF não pode ser alterado</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_city" class="form-label">Cidade</label>
                                    <input type="text" class="form-control" id="edit_city">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_observacoes" class="form-label">Observações</label>
                            <textarea class="form-control" id="edit_observacoes" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="salvarEdicaoAluno()">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de progresso para sincronização -->
    <div class="modal fade" id="progressoModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sincronizando com Moodle</h5>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                    <div class="progress mb-3">
                        <div class="progress-bar" id="progressBar" style="width: 0%"></div>
                    </div>
                    <div id="progressoTexto" class="text-center">
                        Iniciando sincronização...
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelarSincronizacao" onclick="cancelarSincronizacao()">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Variáveis globais
        let currentAlunoId = null;
        let sincronizacaoAtiva = false;
        
        // ========== FUNÇÕES DE DETALHES DOS ALUNOS ==========
        
        // Ver detalhes completos do aluno
        function verDetalhesAluno(alunoId) {
            currentAlunoId = alunoId;
            const modal = new bootstrap.Modal(document.getElementById('detalhesAlunoModal'));
            const conteudo = document.getElementById('detalhesAlunoConteudo');
            
            conteudo.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Carregando detalhes do aluno...</p>
                </div>
            `;
            
            modal.show();
            
            // Busca detalhes via API
            fetch(`/admin/api/aluno-detalhes.php?id=${alunoId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        exibirDetalhesAluno(data);
                        //showToast('Detalhes carregados!', 'success');
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
        
        // Exibe detalhes do aluno
        function exibirDetalhesAluno(data) {
            const { aluno, matriculas, boletos, estatisticas } = data;
            
            const ultimoAcesso = aluno.ultimo_acesso ? 
                new Date(aluno.ultimo_acesso).toLocaleDateString('pt-BR') : 'Nunca acessou';
            
            const ativo = aluno.ultimo_acesso && 
                (Date.now() - new Date(aluno.ultimo_acesso).getTime()) < (30 * 24 * 60 * 60 * 1000);
            
            const html = `
                <!-- Header com Informações Principais -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center">
                            <div class="user-avatar me-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                                ${aluno.nome.charAt(0).toUpperCase()}
                            </div>
                            <div>
                                <h4 class="mb-1">${aluno.nome}</h4>
                                <p class="text-muted mb-0">CPF: ${formatarCPF(aluno.cpf)}</p>
                                <span class="badge bg-${ativo ? 'success' : 'secondary'}">${ativo ? 'Ativo' : 'Inativo'}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="h5 text-primary mb-0">${estatisticas.total_boletos} Boletos</div>
                        <small class="text-muted">R$ ${formatarValor(estatisticas.valor_total)}</small>
                    </div>
                </div>

                <!-- Informações Pessoais -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-user"></i> Informações Pessoais
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Nome:</strong></td>
                                <td>${aluno.nome}</td>
                            </tr>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td>
                                    <a href="mailto:${aluno.email}" class="text-decoration-none">
                                        ${aluno.email}
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>CPF:</strong></td>
                                <td>${formatarCPF(aluno.cpf)}</td>
                            </tr>
                            ${aluno.city ? `
                            <tr>
                                <td><strong>Cidade:</strong></td>
                                <td>${aluno.city}</td>
                            </tr>
                            ` : ''}
                            <tr>
                                <td><strong>Cadastro:</strong></td>
                                <td>${new Date(aluno.created_at).toLocaleDateString('pt-BR')}</td>
                            </tr>
                            <tr>
                                <td><strong>Último Acesso:</strong></td>
                                <td>${ultimoAcesso}</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-chart-bar"></i> Estatísticas
                        </h6>
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.total_matriculas}</h6>
                                        <small class="text-muted">Matrículas Ativas</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.total_boletos}</h6>
                                        <small class="text-muted">Total Boletos</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1 text-success">${estatisticas.boletos_pagos}</h6>
                                        <small class="text-muted">Boletos Pagos</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1 text-warning">${estatisticas.boletos_pendentes}</h6>
                                        <small class="text-muted">Pendentes</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <h6>Situação Financeira</h6>
                            <div class="d-flex justify-content-between">
                                <small>Pago: R$ ${formatarValor(estatisticas.valor_pago)}</small>
                                <small>Pendente: R$ ${formatarValor(estatisticas.valor_pendente)}</small>
                            </div>
                            <div class="progress mt-1">
                                <div class="progress-bar bg-success" style="width: ${estatisticas.percentual_pago}%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Matrículas -->
                ${matriculas && matriculas.length > 0 ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-graduation-cap"></i> Matrículas Ativas
                        </h6>
                        <div class="row">
                            ${matriculas.map(matricula => `
                            <div class="col-md-6 mb-3">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">${matricula.curso_nome}</h6>
                                        <p class="card-text small text-muted">
                                            Polo: ${matricula.polo_nome}<br>
                                            Matrícula: ${new Date(matricula.data_matricula).toLocaleDateString('pt-BR')}
                                        </p>
                                        <span class="badge bg-success">Ativa</span>
                                    </div>
                                </div>
                            </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Boletos Recentes -->
                ${boletos && boletos.length > 0 ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-file-invoice-dollar"></i> Boletos Recentes
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>Curso</th>
                                        <th>Valor</th>
                                        <th>Vencimento</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${boletos.slice(0, 5).map(boleto => `
                                    <tr>
                                        <td><strong>#${boleto.numero_boleto}</strong></td>
                                        <td>${boleto.curso_nome}</td>
                                        <td>R$ ${formatarValor(boleto.valor)}</td>
                                        <td>${new Date(boleto.vencimento).toLocaleDateString('pt-BR')}</td>
                                        <td><span class="badge bg-${getStatusColor(boleto.status)}">${boleto.status}</span></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="verDetalhesBoleto(${boleto.id})">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        ${boletos.length > 5 ? `<small class="text-muted">Mostrando 5 de ${boletos.length} boletos</small>` : ''}
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
                            <button class="btn btn-info" onclick="sincronizarAluno(${aluno.id})">
                                <i class="fas fa-sync-alt"></i> Sincronizar com Moodle
                            </button>
                            <button class="btn btn-secondary" onclick="editarAluno(${aluno.id})">
                                <i class="fas fa-edit"></i> Editar Dados
                            </button>
                            <button class="btn btn-primary" onclick="verBoletosAluno(${aluno.id})">
                                <i class="fas fa-file-invoice-dollar"></i> Ver Todos os Boletos
                            </button>
                            <button class="btn btn-success" onclick="criarBoletoParaAluno(${aluno.id})">
                                <i class="fas fa-plus"></i> Criar Boleto
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('detalhesAlunoConteudo').innerHTML = html;
        }
        
        // Função para mostrar erros
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
            
            document.getElementById('detalhesAlunoConteudo').innerHTML = html;
        }
        
        // ========== FUNÇÕES DE AÇÕES DOS ALUNOS ==========
        
        // Ver boletos do aluno
        function verBoletosAluno(alunoId) {
            window.location.href = `/admin/boletos.php?aluno_id=${alunoId}`;
        }
        
        // Editar aluno
        function editarAluno(alunoId) {
            // Busca dados do aluno para edição
            fetch(`/admin/api/aluno-detalhes.php?id=${alunoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const aluno = data.aluno;
                        
                        // Preenche o formulário
                        document.getElementById('edit_aluno_id').value = aluno.id;
                        document.getElementById('edit_nome').value = aluno.nome;
                        document.getElementById('edit_email').value = aluno.email;
                        document.getElementById('edit_cpf').value = formatarCPF(aluno.cpf);
                        document.getElementById('edit_city').value = aluno.city || '';
                        document.getElementById('edit_observacoes').value = aluno.observacoes || '';
                        
                        // Abre modal
                        const modal = new bootstrap.Modal(document.getElementById('editarAlunoModal'));
                        modal.show();
                    } else {
                        showToast('Erro ao carregar dados do aluno', 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
        }
        
        // Salvar edição do aluno
        function salvarEdicaoAluno() {
            const alunoId = document.getElementById('edit_aluno_id').value;
            const nome = document.getElementById('edit_nome').value;
            const email = document.getElementById('edit_email').value;
            const city = document.getElementById('edit_city').value;
            const observacoes = document.getElementById('edit_observacoes').value;
            
            if (!nome || !email) {
                showToast('Nome e email são obrigatórios', 'error');
                return;
            }
            
            const submitBtn = document.querySelector('#editarAlunoModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            submitBtn.disabled = true;
            
            fetch('/admin/api/editar-aluno.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    aluno_id: parseInt(alunoId),
                    nome: nome.trim(),
                    email: email.trim(),
                    city: city.trim(),
                    observacoes: observacoes.trim()
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Dados do aluno atualizados!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editarAlunoModal')).hide();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Erro de conexão', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Sincronizar aluno individual
        function sincronizarAluno(alunoId) {
            if (sincronizacaoAtiva) {
                showToast('Sincronização já está em andamento', 'warning');
                return;
            }
            
            if (confirm('Deseja sincronizar os dados deste aluno com o Moodle?')) {
                sincronizacaoAtiva = true;
                showToast('Iniciando sincronização...', 'info');
                
                fetch('/admin/api/sincronizar-aluno.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        aluno_id: alunoId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Aluno sincronizado com sucesso!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast('Erro na sincronização: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                })
                .finally(() => {
                    sincronizacaoAtiva = false;
                });
            }
        }
        
        // Sincronizar todos os alunos
        function sincronizarAlunos() {
            if (sincronizacaoAtiva) {
                showToast('Sincronização já está em andamento', 'warning');
                return;
            }
            
            if (confirm('Deseja sincronizar todos os alunos com o Moodle? Esta operação pode demorar alguns minutos.')) {
                sincronizacaoAtiva = true;
                
                // Abre modal de progresso
                const progressModal = new bootstrap.Modal(document.getElementById('progressoModal'));
                progressModal.show();
                
                let progresso = 0;
                const progressBar = document.getElementById('progressBar');
                const progressText = document.getElementById('progressoTexto');
                
                // Simula progresso (em implementação real, usar WebSocket ou polling)
                const interval = setInterval(() => {
                    progresso += Math.random() * 10;
                    if (progresso > 95) progresso = 95;
                    
                    progressBar.style.width = progresso + '%';
                    progressText.textContent = `Sincronizando alunos... ${Math.round(progresso)}%`;
                }, 500);
                
                fetch('/admin/api/sincronizar-todos-alunos.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    clearInterval(interval);
                    progressBar.style.width = '100%';
                    progressText.textContent = 'Sincronização concluída!';
                    
                    setTimeout(() => {
                        progressModal.hide();
                        if (data.success) {
                            showToast(`Sincronização concluída! ${data.alunos_atualizados} alunos atualizados.`, 'success');
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            showToast('Erro na sincronização: ' + data.message, 'error');
                        }
                    }, 1000);
                })
                .catch(error => {
                    clearInterval(interval);
                    progressModal.hide();
                    showToast('Erro de conexão', 'error');
                })
                .finally(() => {
                    sincronizacaoAtiva = false;
                });
            }
        }
        
        // Cancelar sincronização
        function cancelarSincronizacao() {
            if (confirm('Deseja realmente cancelar a sincronização?')) {
                sincronizacaoAtiva = false;
                bootstrap.Modal.getInstance(document.getElementById('progressoModal')).hide();
                showToast('Sincronização cancelada', 'info');
            }
        }
        
        // Criar boleto para aluno
        function criarBoletoParaAluno(alunoId) {
            window.location.href = `/admin/upload-boletos.php?aluno_id=${alunoId}`;
        }
        
        // Exportar lista de alunos
        function exportarAlunos() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            
            showToast('Preparando exportação...', 'info');
            
            // Simula exportação (implementar API real)
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = `/admin/api/exportar-alunos.php?${params.toString()}`;
                link.download = `alunos_${new Date().toISOString().split('T')[0]}.csv`;
                link.click();
                
                showToast('Exportação iniciada!', 'success');
            }, 1000);
        }
        
        // Exportar detalhes do aluno
        function exportarDetalhesAluno(alunoId) {
            if (!alunoId) {
                showToast('Nenhum aluno selecionado para exportar', 'error');
                return;
            }
            
            fetch(`/admin/api/aluno-detalhes.php?id=${alunoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const csvContent = gerarCSVDetalhesAluno(data);
                        downloadCSV(csvContent, `aluno_${data.aluno.nome.replace(/\s+/g, '_')}_detalhes.csv`);
                        showToast('Detalhes exportados!', 'success');
                    }
                })
                .catch(error => {
                    showToast('Erro ao exportar detalhes', 'error');
                });
        }
        
        // ========== FUNÇÕES AUXILIARES ==========
        
        // Formatar CPF
        function formatarCPF(cpf) {
            return cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        }
        
        // Formatar valor monetário
        function formatarValor(valor) {
            return parseFloat(valor || 0).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        // Obter cor do status
        function getStatusColor(status) {
            const cores = {
                'pago': 'success',
                'pendente': 'warning',
                'vencido': 'danger',
                'cancelado': 'secondary'
            };
            return cores[status] || 'secondary';
        }
        
        // Fechar detalhes
        function fecharDetalhes() {
            const detalhesModal = bootstrap.Modal.getInstance(document.getElementById('detalhesAlunoModal'));
            if (detalhesModal) {
                detalhesModal.hide();
            }
            currentAlunoId = null;
        }
        
        // Ver detalhes do boleto (integração com página de boletos)
        function verDetalhesBoleto(boletoId) {
            // Fecha modal atual e abre página de boletos
            fecharDetalhes();
            window.location.href = `/admin/boletos.php?boleto_id=${boletoId}`;
        }
        
        // Gerar CSV dos detalhes do aluno
        function gerarCSVDetalhesAluno(data) {
            const { aluno, matriculas, boletos, estatisticas } = data;
            
            const rows = [
                ['Campo', 'Valor'],
                ['Nome', aluno.nome],
                ['CPF', formatarCPF(aluno.cpf)],
                ['Email', aluno.email],
                ['Cidade', aluno.city || ''],
                ['Polo', aluno.subdomain],
                ['Cadastro', new Date(aluno.created_at).toLocaleDateString('pt-BR')],
                ['Último Acesso', aluno.ultimo_acesso ? new Date(aluno.ultimo_acesso).toLocaleDateString('pt-BR') : 'Nunca'],
                [''],
                ['=== ESTATÍSTICAS ==='],
                ['Total de Matrículas', estatisticas.total_matriculas],
                ['Total de Boletos', estatisticas.total_boletos],
                ['Boletos Pagos', estatisticas.boletos_pagos],
                ['Boletos Pendentes', estatisticas.boletos_pendentes],
                ['Valor Total', 'R$ ' + formatarValor(estatisticas.valor_total)],
                ['Valor Pago', 'R$ ' + formatarValor(estatisticas.valor_pago)],
                ['Valor Pendente', 'R$ ' + formatarValor(estatisticas.valor_pendente)],
                [''],
                ['=== MATRÍCULAS ===']
            ];
            
            if (matriculas && matriculas.length > 0) {
                matriculas.forEach(matricula => {
                    rows.push([
                        matricula.curso_nome,
                        'Ativa desde ' + new Date(matricula.data_matricula).toLocaleDateString('pt-BR')
                    ]);
                });
            }
            
            rows.push(['']);
            rows.push(['=== BOLETOS RECENTES ===']);
            
            if (boletos && boletos.length > 0) {
                rows.push(['Número', 'Curso', 'Valor', 'Vencimento', 'Status']);
                boletos.slice(0, 10).forEach(boleto => {
                    rows.push([
                        '#' + boleto.numero_boleto,
                        boleto.curso_nome,
                        'R$ ' + formatarValor(boleto.valor),
                        new Date(boleto.vencimento).toLocaleDateString('pt-BR'),
                        boleto.status.toUpperCase()
                    ]);
                });
            }
            
            return rows.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
        }
        
        // Download de CSV
        function downloadCSV(content, filename) {
            const blob = new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8;' });
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
        
        // Máscara para CPF no formulário de edição
        document.getElementById('edit_cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            e.target.value = value;
        });
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl + S para sincronizar todos
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                sincronizarAlunos();
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
                const detalhesModal = bootstrap.Modal.getInstance(document.getElementById('detalhesAlunoModal'));
                const editarModal = bootstrap.Modal.getInstance(document.getElementById('editarAlunoModal'));
                const progressModal = bootstrap.Modal.getInstance(document.getElementById('progressoModal'));
                
                if (detalhesModal) {
                    detalhesModal.hide();
                } else if (editarModal) {
                    editarModal.hide();
                } else if (progressModal) {
                    cancelarSincronizacao();
                } else {
                    window.location.href = '/admin/alunos.php';
                }
            }
            
            // F5 para recarregar
            if (e.key === 'F5' && !e.ctrlKey) {
                e.preventDefault();
                showToast('Atualizando lista...', 'info');
                setTimeout(() => location.reload(), 500);
            }
        });
        
        // Inicialização do DataTable (se necessário)
        document.addEventListener('DOMContentLoaded', function() {
            // Tooltip para botões
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                new bootstrap.Tooltip(element);
            });
            
            // Melhoria visual das linhas da tabela
            document.querySelectorAll('#alunosTable tbody tr').forEach(row => {
                row.addEventListener('click', function() {
                    const alunoId = this.dataset.alunoId;
                    if (alunoId) {
                        verDetalhesAluno(alunoId);
                    }
                });
                
                row.style.cursor = 'pointer';
                row.title = 'Clique para ver detalhes';
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
            
            .user-avatar {
                transition: all 0.2s ease;
            }
            
            .user-avatar:hover {
                transform: scale(1.1);
            }
            
            .progress-mini {
                transition: all 0.3s ease;
            }
            
            .card {
                transition: all 0.2s ease;
            }
            
            .card:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
            }
        `;
        document.head.appendChild(style);
        
        // Log de debug final
        console.log('✅ Página de Gerenciamento de Alunos carregada!');
        console.log('Admin Alunos carregado', {
            total_alunos: <?= $resultado['total'] ?>,
            alunos_pagina: <?= count($resultado['alunos']) ?>,
            pagina_atual: <?= $resultado['pagina'] ?>,
            total_paginas: <?= $resultado['total_paginas'] ?>,
            filtros_ativos: <?= json_encode($filtros) ?>,
            estatisticas: <?= json_encode($estatisticas) ?>
        });
        
        // Verifica se há parâmetros para ações automáticas
        const urlParams = new URLSearchParams(window.location.search);
        const alunoIdAuto = urlParams.get('aluno_id');
        const acaoAuto = urlParams.get('acao');
        
        if (alunoIdAuto && acaoAuto === 'detalhes') {
            // Abre detalhes automaticamente
            setTimeout(() => {
                verDetalhesAluno(parseInt(alunoIdAuto));
            }, 500);
        }
        
        if (acaoAuto === 'sincronizar_todos') {
            // Inicia sincronização automática
            setTimeout(() => {
                sincronizarAlunos();
            }, 1000);
        }
      
        

        /**
 * CORREÇÃO DO BACKDROP (Tela Escura) - Modal de Detalhes
 * Adicionar este código no JavaScript do admin/alunos.php
 */

// 🔧 CORREÇÃO: Força limpeza completa do modal e backdrop
function fecharModalDetalhes() {
    try {
        // Método 1: Remove modal via Bootstrap
        const modal = document.getElementById('detalhesAlunoModal');
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) {
                modalInstance.hide();
            }
        }
        
        // Método 2: Remove backdrop manualmente após um delay
        setTimeout(() => {
            // Remove todos os backdrops órfãos
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.remove();
            });
            
            // Remove classes do body
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Limpa variáveis
            currentAlunoId = null;
            
        }, 100);
        
    } catch (error) {
        console.error('Erro ao fechar modal:', error);
        // Força limpeza em caso de erro
        forcarLimpezaCompleta();
    }
}

// 🔧 CORREÇÃO: Limpeza forçada em caso de emergência
function forcarLimpezaCompleta() {
    // Remove TODOS os modais e backdrops
    document.querySelectorAll('.modal').forEach(modal => {
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
    });
    
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.remove();
    });
    
    // Limpa classes do body
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    document.body.style.marginRight = '';
    
    console.log('✅ Limpeza forçada executada');
}

// 🔧 CORREÇÃO: Intercepta evento de fechamento do modal
document.addEventListener('DOMContentLoaded', function() {
    const modalDetalhes = document.getElementById('detalhesAlunoModal');
    
    if (modalDetalhes) {
        // Event listener para quando modal é escondido
        modalDetalhes.addEventListener('hidden.bs.modal', function(e) {
            setTimeout(() => {
                // Verifica se ainda há backdrops órfãos
                const backdropsOrfaos = document.querySelectorAll('.modal-backdrop');
                if (backdropsOrfaos.length > 0) {
                    console.log('🔧 Removendo backdrops órfãos:', backdropsOrfaos.length);
                    backdropsOrfaos.forEach(backdrop => backdrop.remove());
                }
                
                // Garante que body está limpo
                if (document.body.classList.contains('modal-open')) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            }, 150);
        });
        
        // Event listener para botão X
        const btnClose = modalDetalhes.querySelector('.btn-close');
        if (btnClose) {
            btnClose.addEventListener('click', function(e) {
                e.preventDefault();
                fecharModalDetalhes();
            });
        }
        
        // Event listener para botão Fechar
        const btnFechar = modalDetalhes.querySelector('.modal-footer .btn-secondary');
        if (btnFechar) {
            btnFechar.addEventListener('click', function(e) {
                e.preventDefault();
                fecharModalDetalhes();
            });
        }
        
        // Event listener para ESC
        modalDetalhes.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                fecharModalDetalhes();
            }
        });
        
        // Event listener para clique no backdrop
        modalDetalhes.addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModalDetalhes();
            }
        });
    }
});

// 🔧 CORREÇÃO: Substitui função verDetalhes original se necessário
const originalVerDetalhes = window.verDetalhes;
window.verDetalhes = function(alunoId) {
    // Limpa qualquer backdrop órfão antes de abrir novo modal
    forcarLimpezaCompleta();
    
    // Chama função original
    if (originalVerDetalhes) {
        originalVerDetalhes(alunoId);
    }
};


    </script>
</body>
</html>