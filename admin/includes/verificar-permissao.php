<?php
/**
 * Sistema de Boletos IMEPEDU - Middleware de Verificação
 * Arquivo: admin/includes/verificar-permissao.php
 * 
 * Incluir em todas as páginas admin após session_start()
 */

// Verifica se está logado
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

// Carrega serviços necessários
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/AdminService.php';
require_once __DIR__ . '/../../src/UsuarioAdminService.php';

$adminService = new AdminService();
$usuarioService = new UsuarioAdminService();

// Busca dados do admin logado
$adminLogado = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$adminLogado || !$adminLogado['ativo']) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Define página atual
$paginaAtual = basename($_SERVER['PHP_SELF'], '.php');

// Mapa de páginas e permissões necessárias
$permissoesPagina = [
    'boletos' => 'ver_boletos',
    'upload-boletos' => 'criar_boletos',
    'alunos' => 'ver_alunos',
    'cursos' => 'ver_alunos', // Cursos geralmente vem junto com alunos
    'relatorios' => 'ver_relatorios',
    'logs' => 'ver_logs',
    'usuarios' => 'super_admin' // Apenas super admin
];

// Verifica permissão específica da página
if (isset($permissoesPagina[$paginaAtual])) {
    $permissaoNecessaria = $permissoesPagina[$paginaAtual];
    
    // Se a permissão é 'super_admin', verifica nível
    if ($permissaoNecessaria === 'super_admin') {
        if ($adminLogado['nivel_acesso'] !== 'super_admin') {
            header('Location: /admin/dashboard.php');
            exit;
        }
    } else {
        // Verifica permissão normal
        if (!$usuarioService->temPermissao($adminLogado, $permissaoNecessaria)) {
            header('Location: /admin/dashboard.php');
            exit;
        }
    }
}

// Adiciona filtros automáticos baseados no polo
if ($adminLogado['nivel_acesso'] !== 'super_admin' && !empty($adminLogado['polo_restrito'])) {
    // Força filtro de polo em GETs
    if (!isset($_GET['polo'])) {
        $_GET['polo'] = $adminLogado['polo_restrito'];
    } elseif ($_GET['polo'] !== $adminLogado['polo_restrito']) {
        // Se tentou acessar outro polo, redireciona
        $_GET['polo'] = $adminLogado['polo_restrito'];
        $query = http_build_query($_GET);
        header("Location: {$_SERVER['PHP_SELF']}?{$query}");
        exit;
    }
}

// Função helper para verificar permissão inline
function temPermissao($permissao) {
    global $adminLogado, $usuarioService;
    return $usuarioService->temPermissao($adminLogado, $permissao);
}

// Função helper para verificar se é super admin
function ehSuperAdmin() {
    global $adminLogado;
    return $adminLogado['nivel_acesso'] === 'super_admin';
}

// Função helper para obter polo restrito
function getPoloRestrito() {
    global $adminLogado;
    return $adminLogado['polo_restrito'] ?? null;
}

// Registra último acesso
try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("UPDATE administradores SET ultimo_acesso = NOW() WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
} catch (Exception $e) {
    // Ignora erros de atualização
}
?>