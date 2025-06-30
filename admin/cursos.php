<?php
/**
 * Sistema de Boletos IMEPEDU - Gerenciamento de Cursos
 * Arquivo: admin/cursos.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';
require_once '../src/MoodleAPI.php';
require_once 'includes/verificar-permissao.php';



$adminService = new AdminService();

// Processa filtros
$filtros = [];
$pagina = intval($_GET['pagina'] ?? 1);

if (!empty($_GET['polo'])) $filtros['polo'] = $_GET['polo'];
if (!empty($_GET['status'])) $filtros['status'] = $_GET['status'];
if (!empty($_GET['busca'])) $filtros['busca'] = $_GET['busca'];

// Busca cursos
$resultado = buscarCursosComFiltros($filtros, $pagina, 20);
$polosAtivos = MoodleConfig::getActiveSubdomains();

// Busca estatísticas gerais
$estatisticas = obterEstatisticasCursos();

/**
 * Função para buscar cursos com filtros e paginação
 */
function buscarCursosComFiltros($filtros, $pagina, $itensPorPagina) {
    $db = (new Database())->getConnection();
    
    $where = ['1=1'];
    $params = [];
    
    // Aplica filtros
    if (!empty($filtros['polo'])) {
        $where[] = "c.subdomain = ?";
        $params[] = $filtros['polo'];
    }
    
    if (!empty($filtros['status'])) {
        if ($filtros['status'] === 'ativo') {
            $where[] = "c.ativo = 1";
        } elseif ($filtros['status'] === 'inativo') {
            $where[] = "c.ativo = 0";
        } elseif ($filtros['status'] === 'com_alunos') {
            $where[] = "EXISTS (SELECT 1 FROM matriculas m WHERE m.curso_id = c.id AND m.status = 'ativa')";
        } elseif ($filtros['status'] === 'sem_alunos') {
            $where[] = "NOT EXISTS (SELECT 1 FROM matriculas m WHERE m.curso_id = c.id AND m.status = 'ativa')";
        }
    }
    
    if (!empty($filtros['busca'])) {
        $where[] = "(c.nome LIKE ? OR c.nome_curto LIKE ? OR c.moodle_course_id LIKE ?)";
        $termoBusca = '%' . $filtros['busca'] . '%';
        $params[] = $termoBusca;
        $params[] = $termoBusca;
        $params[] = $termoBusca;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Conta total
    $stmtCount = $db->prepare("
        SELECT COUNT(DISTINCT c.id) as total
        FROM cursos c
        WHERE {$whereClause}
    ");
    $stmtCount->execute($params);
    $total = $stmtCount->fetch()['total'];
    
    // Busca registros
    $offset = ($pagina - 1) * $itensPorPagina;
    $stmt = $db->prepare("
        SELECT c.*,
               COUNT(DISTINCT m.id) as total_matriculas,
               COUNT(DISTINCT CASE WHEN m.status = 'ativa' THEN m.id END) as matriculas_ativas,
               COUNT(DISTINCT b.id) as total_boletos,
               COUNT(DISTINCT CASE WHEN b.status = 'pago' THEN b.id END) as boletos_pagos,
               COALESCE(SUM(CASE WHEN b.status = 'pago' THEN COALESCE(b.valor_pago, b.valor) ELSE 0 END), 0) as valor_arrecadado,
               COALESCE(SUM(CASE WHEN b.status IN ('pendente', 'vencido') THEN b.valor ELSE 0 END), 0) as valor_pendente
        FROM cursos c
        LEFT JOIN matriculas m ON c.id = m.curso_id
        LEFT JOIN boletos b ON c.id = b.curso_id
        WHERE {$whereClause}
        GROUP BY c.id
        ORDER BY c.nome ASC
        LIMIT ? OFFSET ?
    ");
    $params[] = $itensPorPagina;
    $params[] = $offset;
    $stmt->execute($params);
    
    return [
        'cursos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'pagina' => $pagina,
        'total_paginas' => ceil($total / $itensPorPagina),
        'itens_por_pagina' => $itensPorPagina
    ];
}

/**
 * Função para obter estatísticas gerais de cursos
 */
function obterEstatisticasCursos() {
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT c.id) as total_cursos,
            COUNT(DISTINCT CASE WHEN c.ativo = 1 THEN c.id END) as cursos_ativos,
            COUNT(DISTINCT CASE WHEN EXISTS(SELECT 1 FROM matriculas m WHERE m.curso_id = c.id AND m.status = 'ativa') THEN c.id END) as cursos_com_alunos,
            COUNT(DISTINCT CASE WHEN EXISTS(SELECT 1 FROM boletos b WHERE b.curso_id = c.id) THEN c.id END) as cursos_com_boletos,
            COUNT(DISTINCT c.subdomain) as total_polos
        FROM cursos c
    ");
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Cursos - Administração IMEPEDU</title>
    
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
        .badge-com-alunos { background: rgba(0,123,255,0.1); color: #007bff; }
        .badge-sem-alunos { background: rgba(255,193,7,0.1); color: #856404; }
        
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
        
        .curso-avatar {
            width: 40px;
            height: 40px;
            border-radius: 8px;
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
                <a href="/admin/alunos.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    Alunos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/cursos.php" class="nav-link active">
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
                <h3>Gerenciar Cursos</h3>
                <small class="text-muted">Total: <?= $resultado['total'] ?> cursos encontrados</small>
            </div>
            <div>
                <button class="btn btn-primary" onclick="sincronizarCursos()">
                    <i class="fas fa-sync-alt"></i> Sincronizar com Moodle
                </button>
                <button class="btn btn-outline-secondary" onclick="exportarCursos()">
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
                        <div class="stat-number"><?= number_format($estatisticas['total_cursos']) ?></div>
                        <div class="stat-label">Total de Cursos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= number_format($estatisticas['cursos_ativos']) ?></div>
                        <div class="stat-label">Cursos Ativos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= number_format($estatisticas['cursos_com_alunos']) ?></div>
                        <div class="stat-label">Com Alunos</div>
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
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">Todos</option>
                            <option value="ativo" <?= ($_GET['status'] ?? '') == 'ativo' ? 'selected' : '' ?>>Ativos</option>
                            <option value="inativo" <?= ($_GET['status'] ?? '') == 'inativo' ? 'selected' : '' ?>>Inativos</option>
                            <option value="com_alunos" <?= ($_GET['status'] ?? '') == 'com_alunos' ? 'selected' : '' ?>>Com Alunos</option>
                            <option value="sem_alunos" <?= ($_GET['status'] ?? '') == 'sem_alunos' ? 'selected' : '' ?>>Sem Alunos</option>
                        </select>
                    </div>
                    
                    <div class="col-md-5">
                        <label class="form-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nome do curso, código..." value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
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
                        <a href="/admin/cursos.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Limpar Filtros
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Cursos -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($resultado['cursos'])): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-book fa-3x text-muted mb-3"></i>
                        <h5>Nenhum curso encontrado</h5>
                        <p class="text-muted">Tente ajustar os filtros ou sincronizar com o Moodle</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="cursosTable">
                            <thead>
                                <tr>
                                    <th>Curso</th>
                                    <th>Polo</th>
                                    <th>Matrículas</th>
                                    <th>Boletos</th>
                                    <th>Financeiro</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultado['cursos'] as $curso): ?>
                                    <?php
                                    $totalMatriculas = intval($curso['total_matriculas']);
                                    $matriculasAtivas = intval($curso['matriculas_ativas']);
                                    $totalBoletos = intval($curso['total_boletos']);
                                    $boletosPagos = intval($curso['boletos_pagos']);
                                    
                                    $valorArrecadado = floatval($curso['valor_arrecadado']);
                                    $valorPendente = floatval($curso['valor_pendente']);
                                    $valorTotal = $valorArrecadado + $valorPendente;
                                    
                                    $percentualArrecadado = $valorTotal > 0 ? ($valorArrecadado / $valorTotal) * 100 : 0;
                                    ?>
                                    <tr data-curso-id="<?= $curso['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="curso-avatar">
                                                    <?= strtoupper(substr($curso['nome'], 0, 2)) ?>
                                                </div>
                                                <div>
                                                    <strong><?= htmlspecialchars($curso['nome']) ?></strong>
                                                    <br><small class="text-muted">
                                                        <?= htmlspecialchars($curso['nome_curto']) ?>
                                                        <?php if ($curso['moodle_course_id']): ?>
                                                            · ID: <?= $curso['moodle_course_id'] ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php $config = MoodleConfig::getConfig($curso['subdomain']); ?>
                                            <span class="badge bg-primary"><?= htmlspecialchars($config['name'] ?? $curso['subdomain']) ?></span>
                                            <br><small class="text-muted"><?= $curso['subdomain'] ?></small>
                                        </td>
                                        <td>
                                            <div class="text-center">
                                                <strong class="text-info"><?= $matriculasAtivas ?></strong> ativas
                                                <?php if ($totalMatriculas > $matriculasAtivas): ?>
                                                    <br><small class="text-muted"><?= $totalMatriculas - $matriculasAtivas ?> inativas</small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($totalBoletos > 0): ?>
                                                <div class="text-center">
                                                    <strong><?= $totalBoletos ?></strong> total
                                                    <?php if ($boletosPagos > 0): ?>
                                                        <br><small class="text-success"><?= $boletosPagos ?> pagos</small>
                                                    <?php endif; ?>
                                                    <?php if ($totalBoletos > $boletosPagos): ?>
                                                        <br><small class="text-warning"><?= $totalBoletos - $boletosPagos ?> pendentes</small>
                                                    <?php endif; ?>
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
                                                    <?php if ($valorArrecadado > 0): ?>
                                                        <br><small class="text-success">Arrecadado: R$ <?= number_format($valorArrecadado, 2, ',', '.') ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($valorPendente > 0): ?>
                                                        <br><small class="text-warning">Pendente: R$ <?= number_format($valorPendente, 2, ',', '.') ?></small>
                                                    <?php endif; ?>
                                                    <div class="progress progress-mini">
                                                        <div class="progress-bar bg-success" style="width: <?= $percentualArrecadado ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div>
                                                <span class="badge-status badge-<?= $curso['ativo'] ? 'ativo' : 'inativo' ?>">
                                                    <?= $curso['ativo'] ? 'Ativo' : 'Inativo' ?>
                                                </span>
                                                <?php if ($matriculasAtivas > 0): ?>
                                                    <br><span class="badge-status badge-com-alunos">Com Alunos</span>
                                                <?php else: ?>
                                                    <br><span class="badge-status badge-sem-alunos">Sem Alunos</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary btn-sm" 
                                                        onclick="verDetalhesCurso(<?= $curso['id'] ?>)" 
                                                        title="Ver detalhes">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="btn btn-outline-info btn-sm" 
                                                        onclick="verAlunosCurso(<?= $curso['id'] ?>)" 
                                                        title="Ver alunos">
                                                    <i class="fas fa-users"></i>
                                                </button>
                                                
                                                <button class="btn btn-outline-success btn-sm" 
                                                        onclick="verBoletosCurso(<?= $curso['id'] ?>)" 
                                                        title="Ver boletos">
                                                    <i class="fas fa-file-invoice-dollar"></i>
                                                </button>
                                                
                                                <button class="btn btn-outline-secondary btn-sm" 
                                                        onclick="editarCurso(<?= $curso['id'] ?>)" 
                                                        title="Editar">
                                                    <i class="fas fa-edit"></i>
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
    
    <!-- Modal para detalhes do curso -->
    <div class="modal fade" id="detalhesCursoModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalhes do Curso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesCursoConteudo">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="exportarDetalhesCurso(currentCursoId)">
                        <i class="fas fa-download"></i> Exportar
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para editar curso -->
    <div class="modal fade" id="editarCursoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Curso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editarCursoForm">
                        <input type="hidden" id="edit_curso_id">
                        
                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome do Curso</label>
                            <input type="text" class="form-control" id="edit_nome" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nome_curto" class="form-label">Nome Curto</label>
                            <input type="text" class="form-control" id="edit_nome_curto" maxlength="20">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_valor" class="form-label">Valor Padrão</label>
                                    <input type="number" class="form-control" id="edit_valor" step="0.01" min="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_ativo" class="form-label">Status</label>
                                    <select class="form-select" id="edit_ativo">
                                        <option value="1">Ativo</option>
                                        <option value="0">Inativo</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_summary" class="form-label">Descrição</label>
                            <textarea class="form-control" id="edit_summary" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="salvarEdicaoCurso()">
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
        let currentCursoId = null;
        let sincronizacaoAtiva = false;
        
        // ========== FUNÇÕES DE DETALHES DOS CURSOS ==========
        
        // Ver detalhes completos do curso
        function verDetalhesCurso(cursoId) {
            currentCursoId = cursoId;
            const modal = new bootstrap.Modal(document.getElementById('detalhesCursoModal'));
            const conteudo = document.getElementById('detalhesCursoConteudo');
            
            conteudo.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Carregando detalhes do curso...</p>
                </div>
            `;
            
            modal.show();
            
            // Busca detalhes via API
            fetch(`/admin/api/curso-detalhes.php?id=${cursoId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        exibirDetalhesCurso(data);
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
        
        // Exibe detalhes do curso
        function exibirDetalhesCurso(data) {
            const { curso, estatisticas, alunos_recentes, boletos_recentes } = data;
            
            const html = `
                <!-- Header com Informações Principais -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center">
                            <div class="curso-avatar me-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                                ${curso.nome.charAt(0).toUpperCase()}${curso.nome.charAt(1).toUpperCase()}
                            </div>
                            <div>
                                <h4 class="mb-1">${curso.nome}</h4>
                                <p class="text-muted mb-0">${curso.nome_curto} • ID Moodle: ${curso.moodle_course_id || 'N/A'}</p>
                                <span class="badge bg-${curso.ativo ? 'success' : 'secondary'}">${curso.ativo ? 'Ativo' : 'Inativo'}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="h5 text-primary mb-0">${estatisticas.total_matriculas} Matrículas</div>
                        <small class="text-muted">${estatisticas.total_boletos} Boletos</small>
                    </div>
                </div>

                <!-- Informações do Curso -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-book"></i> Informações do Curso
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Nome:</strong></td>
                                <td>${curso.nome}</td>
                            </tr>
                            <tr>
                                <td><strong>Nome Curto:</strong></td>
                                <td>${curso.nome_curto}</td>
                            </tr>
                            <tr>
                                <td><strong>Polo:</strong></td>
                                <td>${curso.polo_nome} (${curso.subdomain})</td>
                            </tr>
                            <tr>
                                <td><strong>ID Moodle:</strong></td>
                                <td>${curso.moodle_course_id || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td><strong>Valor Padrão:</strong></td>
                                <td>R$ ${formatarValor(curso.valor)}</td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td><span class="badge bg-${curso.ativo ? 'success' : 'secondary'}">${curso.ativo ? 'Ativo' : 'Inativo'}</span></td>
                            </tr>
                            <tr>
                                <td><strong>Criado em:</strong></td>
                                <td>${new Date(curso.created_at).toLocaleDateString('pt-BR')}</td>
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
                                        <small class="text-muted">Total Matrículas</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.matriculas_ativas}</h6>
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
                        </div>
                        
                        <div class="mt-3">
                            <h6>Situação Financeira</h6>
                            <div class="d-flex justify-content-between">
                                <small>Arrecadado: R$ ${formatarValor(estatisticas.valor_arrecadado)}</small>
                                <small>Pendente: R$ ${formatarValor(estatisticas.valor_pendente)}</small>
                            </div>
                            <div class="progress mt-1">
                                <div class="progress-bar bg-success" style="width: ${estatisticas.percentual_arrecadado}%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alunos Recentes -->
                ${alunos_recentes && alunos_recentes.length > 0 ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-users"></i> Alunos Recentes
                        </h6>
                        <div class="row">
                            ${alunos_recentes.slice(0, 6).map(aluno => `
                            <div class="col-md-4 mb-3">
                                <div class="card">
                                    <div class="card-body py-2">
                                        <h6 class="card-title">${aluno.nome}</h6>
                                        <p class="card-text small text-muted">
                                            CPF: ${formatarCPF(aluno.cpf)}<br>
                                            Matrícula: ${new Date(aluno.data_matricula).toLocaleDateString('pt-BR')}
                                        </p>
                                        <span class="badge bg-${aluno.status === 'ativa' ? 'success' : 'secondary'}">${aluno.status}</span>
                                    </div>
                                </div>
                            </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Boletos Recentes -->
                ${boletos_recentes && boletos_recentes.length > 0 ? `
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
                                        <th>Aluno</th>
                                        <th>Valor</th>
                                        <th>Vencimento</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${boletos_recentes.slice(0, 5).map(boleto => `
                                    <tr>
                                        <td><strong>#${boleto.numero_boleto}</strong></td>
                                        <td>${boleto.aluno_nome}</td>
                                        <td>R$ ${formatarValor(boleto.valor)}</td>
                                        <td>${new Date(boleto.vencimento).toLocaleDateString('pt-BR')}</td>
                                        <td><span class="badge bg-${getStatusColor(boleto.status)}">${boleto.status}</span></td>
                                    </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        ${boletos_recentes.length > 5 ? `<small class="text-muted">Mostrando 5 de ${boletos_recentes.length} boletos</small>` : ''}
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
                            <button class="btn btn-info" onclick="sincronizarCurso(${curso.id})">
                                <i class="fas fa-sync-alt"></i> Sincronizar com Moodle
                            </button>
                            <button class="btn btn-secondary" onclick="editarCurso(${curso.id})">
                                <i class="fas fa-edit"></i> Editar Curso
                            </button>
                            <button class="btn btn-primary" onclick="verAlunosCurso(${curso.id})">
                                <i class="fas fa-users"></i> Ver Todos os Alunos
                            </button>
                            <button class="btn btn-success" onclick="verBoletosCurso(${curso.id})">
                                <i class="fas fa-file-invoice-dollar"></i> Ver Todos os Boletos
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('detalhesCursoConteudo').innerHTML = html;
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
            
            document.getElementById('detalhesCursoConteudo').innerHTML = html;
        }
        
        // ========== FUNÇÕES DE AÇÕES DOS CURSOS ==========
        
        // Ver alunos do curso
        function verAlunosCurso(cursoId) {
            window.location.href = `/admin/alunos.php?curso_id=${cursoId}`;
        }
        
        // Ver boletos do curso
        function verBoletosCurso(cursoId) {
            window.location.href = `/admin/boletos.php?curso_id=${cursoId}`;
        }
        
        // Editar curso
        function editarCurso(cursoId) {
            // Busca dados do curso para edição
            fetch(`/admin/api/curso-detalhes.php?id=${cursoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const curso = data.curso;
                        
                        // Preenche o formulário
                        document.getElementById('edit_curso_id').value = curso.id;
                        document.getElementById('edit_nome').value = curso.nome;
                        document.getElementById('edit_nome_curto').value = curso.nome_curto || '';
                        document.getElementById('edit_valor').value = curso.valor || 0;
                        document.getElementById('edit_ativo').value = curso.ativo ? '1' : '0';
                        document.getElementById('edit_summary').value = curso.summary || '';
                        
                        // Abre modal
                        const modal = new bootstrap.Modal(document.getElementById('editarCursoModal'));
                        modal.show();
                    } else {
                        showToast('Erro ao carregar dados do curso', 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
        }
        
        // Salvar edição do curso
        function salvarEdicaoCurso() {
            const cursoId = document.getElementById('edit_curso_id').value;
            const nome = document.getElementById('edit_nome').value;
            const nomeCurto = document.getElementById('edit_nome_curto').value;
            const valor = document.getElementById('edit_valor').value;
            const ativo = document.getElementById('edit_ativo').value;
            const summary = document.getElementById('edit_summary').value;
            
            if (!nome) {
                showToast('Nome do curso é obrigatório', 'error');
                return;
            }
            
            const submitBtn = document.querySelector('#editarCursoModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            submitBtn.disabled = true;
            
            fetch('/admin/api/editar-curso.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    curso_id: parseInt(cursoId),
                    nome: nome.trim(),
                    nome_curto: nomeCurto.trim(),
                    valor: parseFloat(valor) || 0,
                    ativo: parseInt(ativo),
                    summary: summary.trim()
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Dados do curso atualizados!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editarCursoModal')).hide();
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
        
        // Sincronizar curso individual
        function sincronizarCurso(cursoId) {
            if (sincronizacaoAtiva) {
                showToast('Sincronização já está em andamento', 'warning');
                return;
            }
            
            if (confirm('Deseja sincronizar os dados deste curso com o Moodle?')) {
                sincronizacaoAtiva = true;
                showToast('Iniciando sincronização...', 'info');
                
                fetch('/admin/api/sincronizar-curso.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        curso_id: cursoId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Curso sincronizado com sucesso!', 'success');
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
        
        // Sincronizar todos os cursos
        function sincronizarCursos() {
            if (sincronizacaoAtiva) {
                showToast('Sincronização já está em andamento', 'warning');
                return;
            }
            
            if (confirm('Deseja sincronizar todos os cursos com o Moodle? Esta operação pode demorar alguns minutos.')) {
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
                    progressText.textContent = `Sincronizando cursos... ${Math.round(progresso)}%`;
                }, 500);
                
                fetch('/admin/api/sincronizar-todos-cursos.php', {
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
                            showToast(`Sincronização concluída! ${data.cursos_atualizados} cursos atualizados.`, 'success');
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
        
        // Exportar lista de cursos
        function exportarCursos() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            
            showToast('Preparando exportação...', 'info');
            
            // Simula exportação (implementar API real)
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = `/admin/api/exportar-cursos.php?${params.toString()}`;
                link.download = `cursos_${new Date().toISOString().split('T')[0]}.csv`;
                link.click();
                
                showToast('Exportação iniciada!', 'success');
            }, 1000);
        }
        
        // Exportar detalhes do curso
        function exportarDetalhesCurso(cursoId) {
            if (!cursoId) {
                showToast('Nenhum curso selecionado para exportar', 'error');
                return;
            }
            
            fetch(`/admin/api/curso-detalhes.php?id=${cursoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const csvContent = gerarCSVDetalhesCurso(data);
                        downloadCSV(csvContent, `curso_${data.curso.nome.replace(/\s+/g, '_')}_detalhes.csv`);
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
        
        // 🔧 CORREÇÃO: Fechar detalhes com limpeza completa do backdrop
        function fecharDetalhes() {
            try {
                // Método 1: Remove modal via Bootstrap
                const modal = document.getElementById('detalhesCursoModal');
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
                    currentCursoId = null;
                    
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
        
        // Gerar CSV dos detalhes do curso
        function gerarCSVDetalhesCurso(data) {
            const { curso, estatisticas, alunos_recentes, boletos_recentes } = data;
            
            const rows = [
                ['Campo', 'Valor'],
                ['Nome', curso.nome],
                ['Nome Curto', curso.nome_curto],
                ['Polo', curso.polo_nome],
                ['ID Moodle', curso.moodle_course_id || ''],
                ['Valor Padrão', 'R$ ' + formatarValor(curso.valor)],
                ['Status', curso.ativo ? 'Ativo' : 'Inativo'],
                ['Criado em', new Date(curso.created_at).toLocaleDateString('pt-BR')],
                [''],
                ['=== ESTATÍSTICAS ==='],
                ['Total de Matrículas', estatisticas.total_matriculas],
                ['Matrículas Ativas', estatisticas.matriculas_ativas],
                ['Total de Boletos', estatisticas.total_boletos],
                ['Boletos Pagos', estatisticas.boletos_pagos],
                ['Valor Arrecadado', 'R$ ' + formatarValor(estatisticas.valor_arrecadado)],
                ['Valor Pendente', 'R$ ' + formatarValor(estatisticas.valor_pendente)],
                [''],
                ['=== ALUNOS RECENTES ===']
            ];
            
            if (alunos_recentes && alunos_recentes.length > 0) {
                alunos_recentes.slice(0, 10).forEach(aluno => {
                    rows.push([
                        aluno.nome,
                        formatarCPF(aluno.cpf) + ' - ' + aluno.status
                    ]);
                });
            }
            
            rows.push(['']);
            rows.push(['=== BOLETOS RECENTES ===']);
            
            if (boletos_recentes && boletos_recentes.length > 0) {
                rows.push(['Número', 'Aluno', 'Valor', 'Vencimento', 'Status']);
                boletos_recentes.slice(0, 10).forEach(boleto => {
                    rows.push([
                        '#' + boleto.numero_boleto,
                        boleto.aluno_nome,
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
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl + S para sincronizar todos
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                sincronizarCursos();
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
                const detalhesModal = bootstrap.Modal.getInstance(document.getElementById('detalhesCursoModal'));
                const editarModal = bootstrap.Modal.getInstance(document.getElementById('editarCursoModal'));
                const progressModal = bootstrap.Modal.getInstance(document.getElementById('progressoModal'));
                
                if (detalhesModal) {
                    fecharDetalhes(); // Usa nossa função corrigida
                } else if (editarModal) {
                    editarModal.hide();
                    // Limpa backdrop após fechar
                    setTimeout(() => {
                        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                    }, 150);
                } else if (progressModal) {
                    cancelarSincronizacao();
                } else {
                    window.location.href = '/admin/cursos.php';
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
            // 🔧 CORREÇÃO: Intercepta evento de fechamento do modal
            const modalDetalhes = document.getElementById('detalhesCursoModal');
            
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
                        fecharDetalhes();
                    });
                }
                
                // Event listener para botão Fechar
                const btnFechar = modalDetalhes.querySelector('.modal-footer .btn-secondary');
                if (btnFechar) {
                    btnFechar.addEventListener('click', function(e) {
                        e.preventDefault();
                        fecharDetalhes();
                    });
                }
                
                // Event listener para ESC
                modalDetalhes.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        fecharDetalhes();
                    }
                });
                
                // Event listener para clique no backdrop
                modalDetalhes.addEventListener('click', function(e) {
                    if (e.target === this) {
                        fecharDetalhes();
                    }
                });
            }
            
            // Modal de edição também precisa da mesma correção
            const modalEdicao = document.getElementById('editarCursoModal');
            if (modalEdicao) {
                modalEdicao.addEventListener('hidden.bs.modal', function(e) {
                    setTimeout(() => {
                        const backdropsOrfaos = document.querySelectorAll('.modal-backdrop');
                        backdropsOrfaos.forEach(backdrop => backdrop.remove());
                        
                        if (document.body.classList.contains('modal-open')) {
                            document.body.classList.remove('modal-open');
                            document.body.style.overflow = '';
                            document.body.style.paddingRight = '';
                        }
                    }, 150);
                });
            }
            
            // Modal de progresso também precisa da mesma correção
            const modalProgresso = document.getElementById('progressoModal');
            if (modalProgresso) {
                modalProgresso.addEventListener('hidden.bs.modal', function(e) {
                    setTimeout(() => {
                        const backdropsOrfaos = document.querySelectorAll('.modal-backdrop');
                        backdropsOrfaos.forEach(backdrop => backdrop.remove());
                        
                        if (document.body.classList.contains('modal-open')) {
                            document.body.classList.remove('modal-open');
                            document.body.style.overflow = '';
                            document.body.style.paddingRight = '';
                        }
                    }, 150);
                });
            }
            
            // Tooltip para botões
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                new bootstrap.Tooltip(element);
            });
            
            // Melhoria visual das linhas da tabela
            document.querySelectorAll('#cursosTable tbody tr').forEach(row => {
                row.addEventListener('click', function() {
                    const cursoId = this.dataset.cursoId;
                    if (cursoId) {
                        verDetalhesCurso(cursoId);
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
            
            .curso-avatar {
                transition: all 0.2s ease;
            }
            
            .curso-avatar:hover {
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
        console.log('✅ Página de Gerenciamento de Cursos carregada!');
        console.log('Admin Cursos carregado', {
            total_cursos: <?= $resultado['total'] ?>,
            cursos_pagina: <?= count($resultado['cursos']) ?>,
            pagina_atual: <?= $resultado['pagina'] ?>,
            total_paginas: <?= $resultado['total_paginas'] ?>,
            filtros_ativos: <?= json_encode($filtros) ?>,
            estatisticas: <?= json_encode($estatisticas) ?>
        });
        
        // 🔧 CORREÇÃO ADICIONAL: Função de emergência para usuário
        window.fixModalBackdrop = function() {
            console.log('🔧 Executando correção manual do backdrop...');
            forcarLimpezaCompleta();
            showToast('Backdrop limpo! Tela desbloqueada.', 'success');
        };
        
        // 🔧 CORREÇÃO: Monitora mudanças no DOM para detectar backdrops órfãos
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList') {
                    // Verifica se há backdrop sem modal visível
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    const modalsVisiveis = document.querySelectorAll('.modal.show');
                    
                    if (backdrops.length > 0 && modalsVisiveis.length === 0) {
                        console.log('🔧 Backdrop órfão detectado, removendo...');
                        setTimeout(() => {
                            backdrops.forEach(backdrop => backdrop.remove());
                            if (document.body.classList.contains('modal-open')) {
                                document.body.classList.remove('modal-open');
                                document.body.style.overflow = '';
                                document.body.style.paddingRight = '';
                            }
                        }, 100);
                    }
                }
            });
        });
        
        // Inicia monitoramento
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
        
        // Verifica se há parâmetros para ações automáticas
        const urlParams = new URLSearchParams(window.location.search);
        const cursoIdAuto = urlParams.get('curso_id');
        const acaoAuto = urlParams.get('acao');
        
        if (cursoIdAuto && acaoAuto === 'detalhes') {
            // Abre detalhes automaticamente
            setTimeout(() => {
                verDetalhesCurso(parseInt(cursoIdAuto));
            }, 500);
        }
        
        if (acaoAuto === 'sincronizar_todos') {
            // Inicia sincronização automática
            setTimeout(() => {
                sincronizarCursos();
            }, 1000);
        }
        
        // Função para filtro rápido por polo (opcional)
        function filtrarPorPolo(polo) {
            const url = new URL(window.location);
            if (polo) {
                url.searchParams.set('polo', polo);
            } else {
                url.searchParams.delete('polo');
            }
            url.searchParams.delete('pagina'); // Reset página
            window.location.href = url.toString();
        }
        
        // Função para filtro rápido por status (opcional)
        function filtrarPorStatus(status) {
            const url = new URL(window.location);
            if (status) {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }
            url.searchParams.delete('pagina'); // Reset página
            window.location.href = url.toString();
        }
        
        // Adiciona eventos de clique nos badges das estatísticas para filtros rápidos
        document.addEventListener('DOMContentLoaded', function() {
            // Adiciona funcionalidade de filtro rápido nas estatísticas
            const statsNumbers = document.querySelectorAll('.stats-row .stat-number');
            statsNumbers.forEach((stat, index) => {
                stat.style.cursor = 'pointer';
                stat.title = 'Clique para filtrar';
                
                stat.addEventListener('click', function() {
                    switch(index) {
                        case 0: // Total cursos
                            window.location.href = '/admin/cursos.php';
                            break;
                        case 1: // Cursos ativos
                            filtrarPorStatus('ativo');
                            break;
                        case 2: // Com alunos
                            filtrarPorStatus('com_alunos');
                            break;
                        case 3: // Total polos
                            showToast('Filtrar por polo usando o dropdown de filtros', 'info');
                            break;
                    }
                });
            });
        });
    </script>
</body>
</html>