<?php
/**
 * Sistema de Boletos IMEPEDU - API Buscar Usuário
 * Arquivo: admin/api/buscar-usuario.php
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

require_once '../../config/database.php';
require_once '../../src/AdminService.php';
require_once '../../src/UsuarioAdminService.php';

try {
    $adminService = new AdminService();
    $usuarioService = new UsuarioAdminService();
    
    // Verifica permissões
    $adminAtual = $adminService->buscarAdminPorId($_SESSION['admin_id']);
    if (!$usuarioService->podeGerenciarUsuarios($adminAtual)) {
        throw new Exception("Sem permissão para gerenciar usuários");
    }
    
    $usuarioId = intval($_GET['id'] ?? 0);
    
    if (!$usuarioId) {
        throw new Exception("ID do usuário não fornecido");
    }
    
    $usuario = $usuarioService->buscarUsuarioPorId($usuarioId);
    
    if (!$usuario) {
        throw new Exception("Usuário não encontrado");
    }
    
    // Remove senha do retorno
    unset($usuario['senha']);
    
    echo json_encode([
        'success' => true,
        'usuario' => $usuario
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>