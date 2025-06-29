Dieg@2907900<?php
/**
 * Sistema de Boletos IMEPEDU - Página de Logs do Sistema
 * Arquivo: admin/logs.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';

$adminService = new AdminService();
$admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$admin) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Processa filtros
$filtros = [];
$periodo = $_GET['periodo'] ?? '24h';
$tipo = $_GET['tipo'] ?? '';
$admin_id = $_GET['admin_id'] ?? '';
$busca = $_GET['busca'] ?? '';

// Busca logs via API
$parametrosAPI = [
    'limite' => 50,
    'periodo' => $periodo,
    'tipo' => $tipo,
    'admin_id' => $admin_id
];

if (!empty($busca)) {
    $parametrosAPI['busca'] = $busca;
}

// Simula chamada para API (você pode implementar a função real)
$logsData = obterLogsViaAPI($parametrosAPI);

// Busca tipos de log disponíveis para filtro
$tiposLogs = obterTiposLogsDisponiveis();

// Busca admins para filtro
$adminsDisponiveis = obterAdminsParaFiltro();

/**
 * Simula busca de logs (implementar chamada real para API)
 */
function obterLogsViaAPI($params) {
    try {
        $db = (new Database())->getConnection();
        
        $where = ['1=1'];
        $sqlParams = [];
        
        // Aplica filtros de período
        switch ($params['periodo']) {
            case '1h':
                $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
                break;
            case '6h':
                $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)";
                break;
            case '24h':
                $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                break;
            case '7d':
                $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case '30d':
                $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
        }
        
        if (!empty($params['tipo'])) {
            $where[] = "l.tipo = ?";
            $sqlParams[] = $params['tipo'];
        }
        
        if (!empty($params['admin_id'])) {
            $where[] = "l.usuario_id = ?";
            $sqlParams[] = $params['admin_id'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $stmt = $db->prepare("
            SELECT 
                l.id,
                l.tipo,
                l.descricao,
                l.created_at,
                l.ip_address,
                l.user_agent,
                a.nome as admin_nome,
                a.login as admin_login,
                b.numero_boleto,
                al.nome as aluno_nome
            FROM logs l
            LEFT JOIN administradores a ON l.usuario_id = a.id
            LEFT JOIN boletos b ON l.boleto_id = b.id
            LEFT JOIN alunos al ON b.aluno_id = al.id
            WHERE {$whereClause}
            ORDER BY l.created_at DESC
            LIMIT ?
        ");
        
        $sqlParams[] = $params['limite'];
        $stmt->execute($sqlParams);
        
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Conta total para estatísticas
        $stmtCount = $db->prepare("
            SELECT COUNT(*) as total
            FROM logs l
            WHERE {$whereClause}
        ");
        $stmtCount->execute(array_slice($sqlParams, 0, -1));
        $total = $stmtCount->fetch()['total'];
        
        return [
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'periodo' => $params['periodo']
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao buscar logs: " . $e->getMessage());
        return [
            'success' => false,
            'logs' => [],
            'total' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Obtém tipos de logs disponíveis
 */
function obterTiposLogsDisponiveis() {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT DISTINCT tipo, COUNT(*) as quantidade
            FROM logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY tipo 
            ORDER BY quantidade DESC
            LIMIT 20
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Obtém admins para filtro
 */
function obterAdminsParaFiltro() {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT DISTINCT a.id, a.nome, a.login
            FROM administradores a
            INNER JOIN logs l ON a.id = l.usuario_id
            WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY a.nome ASC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Obtém label amigável para tipo de log
 */
function getTipoLogLabel($tipo) {
    $labels = [
        // Autenticação
        'login_admin' => 'Login Admin',
        'logout_admin' => 'Logout Admin',
        'login_aluno' => 'Login Aluno',
        'logout_aluno' => 'Logout Aluno',
        
        // Boletos
        'upload_individual' => 'Upload Individual',
        'upload_lote' => 'Upload em Lote',
        'upload_multiplo_aluno' => 'Upload Múltiplo',
        'boleto_pago' => 'Boleto Pago',
        'boleto_pago_admin' => 'Marcado como Pago',
        'boleto_cancelado' => 'Boleto Cancelado',
        'boleto_cancelado_admin' => 'Cancelado pelo Admin',
        'download_boleto' => 'Download Boleto',
        'download_pdf_sucesso' => 'Download PDF',
        'download_pdf_erro' => 'Erro no Download',
        'pix_gerado' => 'PIX Gerado',
        'pix_erro' => 'Erro no PIX',
        'removido' => 'Boleto Removido',
        
        // Sistema
        'sincronizacao_moodle' => 'Sincronização Moodle',
        'aluno_sincronizado' => 'Aluno Sincronizado',
        'curso_sincronizado' => 'Curso Sincronizado',
        'admin_detalhes_aluno' => 'Detalhes Aluno',
        'admin_detalhes_curso' => 'Detalhes Curso',
        'admin_criado' => 'Admin Criado',
        'admin_editado' => 'Admin Editado',
        'senha_alterada' => 'Senha Alterada',
        'senha_resetada' => 'Senha Resetada',
        'admin_status_alterado' => 'Status Admin Alterado',
        
        // Erros
        'erro_sistema' => 'Erro do Sistema',
        'erro_database' => 'Erro de Banco',
        'erro_moodle' => 'Erro Moodle',
        'erro_upload' => 'Erro no Upload',
        'acesso_negado' => 'Acesso Negado',
        
        // Configurações
        'config_alterada' => 'Configuração Alterada',
        'cache_limpo' => 'Cache Limpo',
        'logs_limpos' => 'Logs Limpos',
        'backup_gerado' => 'Backup Gerado',
        'consulta_logs' => 'Consulta de Logs'
    ];
    
    return $labels[$tipo] ?? ucwords(str_replace(['_', '-'], ' ', $tipo));
}

/**
 * Obtém ícone para o tipo de log
 */
function getIconeLog($tipo) {
    $icones = [
        // Autenticação
        'login_admin' => 'fas fa-sign-in-alt text-success',
        'logout_admin' => 'fas fa-sign-out-alt text-secondary',
        'login_aluno' => 'fas fa-user-check text-info',
        'logout_aluno' => 'fas fa-user-times text-secondary',
        
        // Boletos
        'upload_individual' => 'fas fa-upload text-primary',
        'upload_lote' => 'fas fa-file-upload text-primary',
        'upload_multiplo_aluno' => 'fas fa-layer-group text-primary',
        'boleto_pago' => 'fas fa-check-circle text-success',
        'boleto_cancelado' => 'fas fa-times-circle text-danger',
        'download_boleto' => 'fas fa-download text-info',
        'download_pdf_sucesso' => 'fas fa-file-pdf text-success',
        'download_pdf_erro' => 'fas fa-exclamation-triangle text-warning',
        'pix_gerado' => 'fas fa-qrcode text-info',
        'removido' => 'fas fa-trash text-danger',
        
        // Sistema
        'sincronizacao_moodle' => 'fas fa-sync-alt text-primary',
        'aluno_sincronizado' => 'fas fa-user-sync text-success',
        'admin_criado' => 'fas fa-user-plus text-success',
        'admin_editado' => 'fas fa-user-edit text-warning',
        'senha_alterada' => 'fas fa-key text-warning',
        'config_alterada' => 'fas fa-cog text-info',
        'cache_limpo' => 'fas fa-broom text-info',
        'backup_gerado' => 'fas fa-database text-success',
        
        // Erros
        'erro_sistema' => 'fas fa-exclamation-circle text-danger',
        'erro_database' => 'fas fa-database text-danger',
        'erro_moodle' => 'fas fa-exclamation-triangle text-warning',
        'acesso_negado' => 'fas fa-ban text-danger',
        
        // Outros
        'consulta_logs' => 'fas fa-search text-secondary'
    ];
    
    return $icones[$tipo] ?? 'fas fa-info-circle text-secondary';
}

/**
 * Calcula tempo relativo
 */
function getTempoRelativo($datetime) {
    $agora = time();
    $tempo = strtotime($datetime);
    $diff = $agora - $tempo;
    
    if ($diff < 60) {
        return 'agora mesmo';
    } elseif ($diff < 3600) {
        $minutos = floor($diff / 60);
        return "há {$minutos} min";
    } elseif ($diff < 86400) {
        $horas = floor($diff / 3600);
        return "há {$horas}h";
    } elseif ($diff < 2592000) {
        $dias = floor($diff / 86400);
        return "há {$dias} dia" . ($dias > 1 ? 's' : '');
    } else {
        $meses = floor($diff / 2592000);
        return "há {$meses} mês" . ($meses > 1 ? 'es' : '');
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs do Sistema - Administração IMEPEDU</title>
    
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
        
        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .log-item {
            border-left: 4px solid #e9ecef;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 0 8px 8px 0;
            transition: all 0.2s ease;
        }
        
        .log-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transform: translateX(2px);
        }
        
        .log-item.success { border-left-color: #28a745; }
        .log-item.warning { border-left-color: #ffc107; }
        .log-item.danger { border-left-color: #dc3545; }
        .log-item.info { border-left-color: #17a2b8; }
        .log-item.primary { border-left-color: var(--primary-color); }
        .log-item.secondary { border-left-color: #6c757d; }
        
        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .log-title {
            font-weight: 600;
            margin-bottom: 0;
            display: flex;
            align-items: center;
        }
        
        .log-time {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .log-description {
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .log-meta {
            font-size: 0.8rem;
            color: #6c757d;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .log-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .stats-cards {
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            border-left: 4px solid var(--primary-color);
            height: 100%;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .log-list {
            max-height: 80vh;
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        
        .log-list::-webkit-scrollbar {
            width: 6px;
        }
        
        .log-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .log-list::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        
        .log-list::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .auto-refresh {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        /* Animações */
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
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
            
            .log-meta {
                flex-direction: column;
                gap: 0.5rem;
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
                <a href="/admin/logs.php" class="nav-link active">
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
                <h3>Logs do Sistema</h3>
                <small class="text-muted">
                    <?= $logsData['success'] ? $logsData['total'] : 0 ?> registros encontrados
                    <?php if (!empty($periodo)): ?>
                        (período: <?= $periodo ?>)
                    <?php endif; ?>
                </small>
            </div>
            <div>
                <button class="btn btn-primary" onclick="exportarLogs()">
                    <i class="fas fa-download"></i> Exportar
                </button>
                <button class="btn btn-outline-warning" onclick="limparLogsAntigos()">
                    <i class="fas fa-broom"></i> Limpar Antigos
                </button>
                <button class="btn btn-outline-danger" onclick="removerTodosLogs()">
                    <i class="fas fa-trash-alt"></i> Remover Todos
                </button>
                <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
            </div>
        </div>
        
        <!-- Estatísticas Rápidas -->
        <div class="stats-cards">
            <div class="row">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= $logsData['success'] ? $logsData['total'] : 0 ?></div>
                        <div class="stat-label">Total de Logs</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= count($tiposLogs) ?></div>
                        <div class="stat-label">Tipos Diferentes</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= count($adminsDisponiveis) ?></div>
                        <div class="stat-label">Usuários Ativos</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-number" id="logsHoje">-</div>
                        <div class="stat-label">Logs Hoje</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i class="fas fa-filter"></i> Filtros de Busca
                </h6>
                
                <form method="GET" class="row g-3" id="filtrosForm">
                    <div class="col-md-2">
                        <label class="form-label">Período</label>
                        <select name="periodo" class="form-select">
                            <option value="1h" <?= $periodo == '1h' ? 'selected' : '' ?>>Última Hora</option>
                            <option value="6h" <?= $periodo == '6h' ? 'selected' : '' ?>>Últimas 6h</option>
                            <option value="24h" <?= $periodo == '24h' ? 'selected' : '' ?>>Últimas 24h</option>
                            <option value="7d" <?= $periodo == '7d' ? 'selected' : '' ?>>Últimos 7 dias</option>
                            <option value="30d" <?= $periodo == '30d' ? 'selected' : '' ?>>Últimos 30 dias</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Tipo de Log</label>
                        <select name="tipo" class="form-select">
                            <option value="">Todos os tipos</option>
                            <?php foreach ($tiposLogs as $tipoLog): ?>
                                <option value="<?= htmlspecialchars($tipoLog['tipo']) ?>" 
                                        <?= $tipo == $tipoLog['tipo'] ? 'selected' : '' ?>>
                                    <?= getTipoLogLabel($tipoLog['tipo']) ?> (<?= $tipoLog['quantidade'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Usuário</label>
                        <select name="admin_id" class="form-select">
                            <option value="">Todos os usuários</option>
                            <?php foreach ($adminsDisponiveis as $adminDisponivel): ?>
                                <option value="<?= $adminDisponivel['id'] ?>" 
                                        <?= $admin_id == $adminDisponivel['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($adminDisponivel['nome']) ?> (<?= $adminDisponivel['login'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" 
                               placeholder="Buscar na descrição..." 
                               value="<?= htmlspecialchars($busca) ?>">
                    </div>
                    
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <?php if (!empty($periodo) || !empty($tipo) || !empty($admin_id) || !empty($busca)): ?>
                    <div class="col-12">
                        <a href="/admin/logs.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Limpar Filtros
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Lista de Logs -->
        <div class="card">
            <div class="card-body">
                <?php if (!$logsData['success']): ?>
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-triangle"></i> Erro ao Carregar Logs</h6>
                        <p class="mb-0"><?= htmlspecialchars($logsData['error'] ?? 'Erro desconhecido') ?></p>
                    </div>
                <?php elseif (empty($logsData['logs'])): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h5>Nenhum log encontrado</h5>
                        <p class="text-muted">Tente ajustar os filtros ou verificar um período maior</p>
                    </div>
                <?php else: ?>
                    <div class="log-list">
                        <?php foreach ($logsData['logs'] as $log): ?>
                            <?php
                            $tipoClasse = 'secondary';
                            $icone = getIconeLog($log['tipo']);
                            
                            // Determina classe baseada no tipo
                            if (strpos($log['tipo'], 'erro_') === 0 || $log['tipo'] === 'acesso_negado') {
                                $tipoClasse = 'danger';
                            } elseif (strpos($log['tipo'], 'upload_') === 0 || strpos($log['tipo'], 'sincronizacao') !== false) {
                                $tipoClasse = 'primary';
                            } elseif (strpos($log['tipo'], 'pago') !== false || strpos($log['tipo'], 'sucesso') !== false) {
                                $tipoClasse = 'success';
                            } elseif (strpos($log['tipo'], 'download_') === 0 || strpos($log['tipo'], 'consulta') !== false) {
                                $tipoClasse = 'info';
                            } elseif (strpos($log['tipo'], 'cancelado') !== false || strpos($log['tipo'], 'senha_') === 0) {
                                $tipoClasse = 'warning';
                            }
                            ?>
                            <div class="log-item <?= $tipoClasse ?>" data-log-id="<?= $log['id'] ?>">
                                <div class="log-header">
                                    <div class="log-title">
                                        <i class="<?= $icone ?> me-2"></i>
                                        <?= getTipoLogLabel($log['tipo']) ?>
                                    </div>
                                    <div class="log-time">
                                        <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                                        <small class="text-muted ms-1">(<?= getTempoRelativo($log['created_at']) ?>)</small>
                                    </div>
                                </div>
                                
                                <div class="log-description">
                                    <?= htmlspecialchars($log['descricao']) ?>
                                </div>
                                
                                <div class="log-meta">
                                    <?php if (!empty($log['admin_nome'])): ?>
                                        <span>
                                            <i class="fas fa-user text-muted"></i>
                                            <?= htmlspecialchars($log['admin_nome']) ?>
                                            <?php if (!empty($log['admin_login'])): ?>
                                                (<?= htmlspecialchars($log['admin_login']) ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($log['numero_boleto'])): ?>
                                        <span>
                                            <i class="fas fa-file-invoice text-muted"></i>
                                            Boleto #<?= htmlspecialchars($log['numero_boleto']) ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($log['aluno_nome'])): ?>
                                        <span>
                                            <i class="fas fa-graduation-cap text-muted"></i>
                                            <?= htmlspecialchars($log['aluno_nome']) ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($log['ip_address']) && $log['ip_address'] !== 'unknown'): ?>
                                        <span>
                                            <i class="fas fa-globe text-muted"></i>
                                            <?= preg_replace('/\.\d+$/', '.***', $log['ip_address']) ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <span>
                                        <i class="fas fa-clock text-muted"></i>
                                        ID: <?= $log['id'] ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="text-center mt-3">
                        <small class="text-muted">
                            Mostrando <?= count($logsData['logs']) ?> de <?= $logsData['total'] ?> registros
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Auto Refresh Button -->
    <div class="auto-refresh">
        <button class="btn btn-primary btn-sm rounded-circle" 
                onclick="toggleAutoRefresh()" 
                id="autoRefreshBtn" 
                title="Auto-atualização desabilitada">
            <i class="fas fa-play"></i>
        </button>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;" id="toastContainer"></div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // ===== VARIÁVEIS GLOBAIS =====
        let autoRefreshInterval = null;
        let autoRefreshEnabled = false;
        
        // ===== INICIALIZAÇÃO =====
        document.addEventListener('DOMContentLoaded', function() {
            initializeFilters();
            initializeSearch();
            initializeKeyboardShortcuts();
            carregarEstatisticasAdicionais();
            initializeTooltips();
            animarLogs();
            adicionarFiltroRapido();
            
            console.log('📋 Página de Logs carregada com sucesso!');
        });
        
        // ===== INICIALIZAÇÕES =====
        function initializeFilters() {
            // Auto-submit do formulário quando mudamos os filtros
            document.querySelectorAll('#filtrosForm select').forEach(select => {
                select.addEventListener('change', function() {
                    document.getElementById('filtrosForm').submit();
                });
            });
        }
        
        function initializeSearch() {
            // Busca em tempo real (debounced)
            let searchTimeout;
            const buscaInput = document.querySelector('input[name="busca"]');
            if (buscaInput) {
                buscaInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        document.getElementById('filtrosForm').submit();
                    }, 1000);
                });
            }
        }
        
        function initializeKeyboardShortcuts() {
            // Atalhos de teclado
            document.addEventListener('keydown', function(e) {
                // F5 para recarregar
                if (e.key === 'F5' && !e.ctrlKey) {
                    e.preventDefault();
                    showToast('Atualizando logs...', 'info');
                    setTimeout(() => location.reload(), 500);
                }
                
                // Ctrl + F para focar na busca
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    const buscaInput = document.querySelector('input[name="busca"]');
                    if (buscaInput) {
                        buscaInput.focus();
                    }
                }
                
                // Ctrl + B para criar backup manual
                if (e.ctrlKey && e.key === 'b') {
                    e.preventDefault();
                    criarBackupManual();
                }
                
                // ESC para limpar filtros
                if (e.key === 'Escape') {
                    window.location.href = '/admin/logs.php';
                }
            });
        }
        
        function initializeTooltips() {
            // Adiciona tooltips
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                new bootstrap.Tooltip(element);
            });
        }
        
        // ===== FUNCIONALIDADES PRINCIPAIS =====
        
        // Toggle auto-refresh
        function toggleAutoRefresh() {
            const btn = document.getElementById('autoRefreshBtn');
            const icon = btn.querySelector('i');
            
            if (autoRefreshEnabled) {
                // Desabilita
                clearInterval(autoRefreshInterval);
                autoRefreshEnabled = false;
                icon.className = 'fas fa-play';
                btn.title = 'Auto-atualização desabilitada - Clique para ativar';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
                showToast('Auto-atualização desabilitada', 'info');
            } else {
                // Habilita
                autoRefreshInterval = setInterval(() => {
                    showToast('Atualizando logs...', 'info');
                    window.location.reload();
                }, 30000); // 30 segundos
                
                autoRefreshEnabled = true;
                icon.className = 'fas fa-pause';
                btn.title = 'Auto-atualização ativa (30s) - Clique para desativar';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
                showToast('Auto-atualização ativada (30s)', 'success');
            }
        }
        
        // Exportar logs
        function exportarLogs() {
            showToast('Gerando exportação...', 'info');
            
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            
            // Simula exportação
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = `/admin/api/exportar-logs.php?${params.toString()}`;
                link.download = `logs_${new Date().toISOString().split('T')[0]}.csv`;
                link.click();
                
                showToast('Exportação iniciada!', 'success');
            }, 1000);
        }
        
        // Remover todos os logs
        function removerTodosLogs() {
            // Primeira confirmação visual
            const confirmDialog = document.createElement('div');
            confirmDialog.innerHTML = `
                <div class="modal fade" id="confirmRemoverTodos" tabindex="-1" data-bs-backdrop="static">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-danger">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    ATENÇÃO CRÍTICA - REMOÇÃO TOTAL
                                </h5>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-danger">
                                    <h6 class="alert-heading">⚠️ OPERAÇÃO IRREVERSÍVEL</h6>
                                    <hr>
                                    <p><strong>Esta ação irá:</strong></p>
                                    <ul>
                                        <li>❌ Remover <strong>TODOS</strong> os logs do sistema</li>
                                        <li>📊 Afetar auditoria e rastreabilidade</li>
                                        <li>🔒 Perder histórico de ações administrativas</li>
                                        <li>💾 Criar backup automático antes da remoção</li>
                                    </ul>
                                    <p class="mb-0"><strong>Esta operação NÃO pode ser desfeita!</strong></p>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirmacaoTexto" class="form-label">
                                        <strong>Para confirmar, digite exatamente:</strong>
                                        <code class="text-danger">REMOVER TODOS</code>
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="confirmacaoTexto" 
                                           placeholder="Digite: REMOVER TODOS"
                                           autocomplete="off">
                                    <div class="form-text text-muted">
                                        Diferencia maiúsculas e minúsculas
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times"></i> Cancelar
                                </button>
                                <button type="button" class="btn btn-danger" onclick="executarRemocaoTotal()" id="btnConfirmarRemocao" disabled>
                                    <i class="fas fa-trash-alt"></i> Confirmar Remoção Total
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(confirmDialog);
            
            const modal = new bootstrap.Modal(document.getElementById('confirmRemoverTodos'));
            const input = document.getElementById('confirmacaoTexto');
            const btnConfirmar = document.getElementById('btnConfirmarRemocao');
            
            // Monitora digitação para habilitar botão
            input.addEventListener('input', function() {
                if (this.value === 'REMOVER TODOS') {
                    btnConfirmar.disabled = false;
                    btnConfirmar.classList.remove('btn-danger');
                    btnConfirmar.classList.add('btn-outline-danger');
                } else {
                    btnConfirmar.disabled = true;
                    btnConfirmar.classList.remove('btn-outline-danger');
                    btnConfirmar.classList.add('btn-danger');
                }
            });
            
            // Foca no input quando modal abre
            document.getElementById('confirmRemoverTodos').addEventListener('shown.bs.modal', function() {
                input.focus();
            });
            
            // Remove modal ao fechar
            document.getElementById('confirmRemoverTodos').addEventListener('hidden.bs.modal', function() {
                confirmDialog.remove();
            });
            
            modal.show();
        }
        
        // Executa a remoção total
        function executarRemocaoTotal() {
            const confirmacao = document.getElementById('confirmacaoTexto').value;
            const modal = bootstrap.Modal.getInstance(document.getElementById('confirmRemoverTodos'));
            
            if (confirmacao !== 'REMOVER TODOS') {
                showToast('❌ Confirmação incorreta!', 'error');
                return;
            }
            
            // Fecha modal
            modal.hide();
            
            // Mostra loading
            showToast('🔄 Iniciando remoção total...', 'warning');
            
            // Executa remoção
            fetch('/admin/api/limpar-logs.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    remover_todos: true,
                    confirmar: true,
                    confirmacao_texto: confirmacao
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Sucesso
                    showToast(`✅ ${data.logs_removidos} logs removidos!`, 'success');
                    
                    if (data.backup_criado) {
                        showToast(`💾 Backup criado: ${data.backup_path}`, 'info');
                    }
                    
                    // Mostra detalhes da operação
                    mostrarResumoOperacao(data);
                    
                    // Recarrega após 3 segundos
                    setTimeout(() => location.reload(), 3000);
                } else {
                    showToast('❌ Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('❌ Erro de conexão', 'error');
                console.error('Erro:', error);
            });
        }
        
        // Mostra resumo da operação
        function mostrarResumoOperacao(data) {
            const resumoDialog = document.createElement('div');
            resumoDialog.innerHTML = `
                <div class="modal fade" id="resumoOperacao" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-check-circle"></i>
                                    Operação Concluída com Sucesso
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-success">📊 Estatísticas</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>Logs removidos:</strong> ${data.logs_removidos.toLocaleString()}</li>
                                            <li><strong>Operação:</strong> ${data.operacao}</li>
                                            <li><strong>Data/Hora:</strong> ${data.timestamp}</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-info">💾 Backup</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>Backup criado:</strong> ${data.backup_criado ? '✅ Sim' : '❌ Não'}</li>
                                            ${data.backup_path ? `<li><strong>Arquivo:</strong> <code>${data.backup_path}</code></li>` : ''}
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info mt-3">
                                    <h6 class="alert-heading">ℹ️ Informações Importantes</h6>
                                    <ul class="mb-0">
                                        <li>Um novo log foi criado registrando esta operação</li>
                                        <li>O backup está salvo no servidor para recuperação</li>
                                        <li>A página será recarregada automaticamente</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                                    <i class="fas fa-check"></i> Entendido
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(resumoDialog);
            
            const modal = new bootstrap.Modal(document.getElementById('resumoOperacao'));
            
            // Remove modal ao fechar
            document.getElementById('resumoOperacao').addEventListener('hidden.bs.modal', function() {
                resumoDialog.remove();
            });
            
            modal.show();
        }
        
        // Limpar logs antigos
        function limparLogsAntigos() {
            if (confirm('⚠️ ATENÇÃO!\n\nEsta ação irá remover logs antigos (mais de 90 dias) permanentemente.\n\nDeseja continuar?')) {
                showToast('Limpando logs antigos...', 'warning');
                
                fetch('/admin/api/limpar-logs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        dias: 90,
                        confirmar: true
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(`${data.logs_removidos} logs antigos removidos!`, 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        // Cria backup manual
        function criarBackupManual() {
            showToast('📦 Criando backup manual...', 'info');
            
            fetch('/admin/api/backup-logs.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    tipo: 'manual',
                    incluir_todos: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`✅ Backup criado: ${data.arquivo}`, 'success');
                } else {
                    showToast('❌ Erro ao criar backup: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('❌ Erro de conexão', 'error');
            });
        }
        
        // ===== FUNCIONALIDADES AUXILIARES =====
        
        // Carrega estatísticas adicionais
        function carregarEstatisticasAdicionais() {
            // Calcula logs de hoje baseado nos dados atuais
            const logsHoje = <?= count(array_filter($logsData['logs'] ?? [], function($log) {
                return date('Y-m-d', strtotime($log['created_at'])) === date('Y-m-d');
            })) ?>;
            
            document.getElementById('logsHoje').textContent = logsHoje;
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
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                container.style.zIndex = '2000';
                document.body.appendChild(container);
            }
            
            const toast = document.createElement('div');
            toast.className = `toast-custom alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info'} position-relative`;
            toast.style.cssText = 'animation: slideInRight 0.3s ease; margin-bottom: 8px; min-width: 300px;';
            
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
        
        // Filtros rápidos
        function filtroRapido(tipo) {
            const url = new URL(window.location);
            url.searchParams.set('tipo', tipo);
            url.searchParams.delete('busca');
            window.location.href = url.toString();
        }
        
        function filtroPeriodo(periodo) {
            const url = new URL(window.location);
            url.searchParams.set('periodo', periodo);
            window.location.href = url.toString();
        }
        
        // Adiciona animações aos logs
        function animarLogs() {
            const logs = document.querySelectorAll('.log-item');
            logs.forEach((log, index) => {
                log.style.opacity = '0';
                log.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    log.style.transition = 'all 0.3s ease';
                    log.style.opacity = '1';
                    log.style.transform = 'translateX(0)';
                }, index * 50);
            });
        }
        
        // Funcionalidades adicionais de filtro
        function adicionarFiltroRapido() {
            const container = document.querySelector('.stats-cards');
            
            if (container && !document.getElementById('filtros-rapidos')) {
                const filtrosRapidos = document.createElement('div');
                filtrosRapidos.id = 'filtros-rapidos';
                filtrosRapidos.className = 'mb-3';
                filtrosRapidos.innerHTML = `
                    <div class="card">
                        <div class="card-body py-2">
                            <h6 class="card-title mb-2">Filtros Rápidos:</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-danger btn-sm" onclick="filtroRapido('erro_sistema')">
                                    <i class="fas fa-exclamation-circle"></i> Erros
                                </button>
                                <button class="btn btn-outline-success btn-sm" onclick="filtroRapido('upload_individual')">
                                    <i class="fas fa-upload"></i> Uploads
                                </button>
                                <button class="btn btn-outline-info btn-sm" onclick="filtroRapido('login_admin')">
                                    <i class="fas fa-sign-in-alt"></i> Logins
                                </button>
                                <button class="btn btn-outline-warning btn-sm" onclick="filtroRapido('boleto_cancelado')">
                                    <i class="fas fa-times-circle"></i> Cancelamentos
                                </button>
                                <button class="btn btn-outline-primary btn-sm" onclick="filtroPeriodo('1h')">
                                    <i class="fas fa-clock"></i> Última Hora
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="window.location.href='/admin/logs.php'">
                                    <i class="fas fa-times"></i> Limpar
                                </button>
                                <button class="btn btn-outline-info btn-sm" onclick="criarBackupManual()">
                                    <i class="fas fa-save"></i> Backup Manual
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                container.appendChild(filtrosRapidos);
            }
        }
    </script>
</body>
</html>