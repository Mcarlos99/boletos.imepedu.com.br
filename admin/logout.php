<?php
/**
 * Sistema de Boletos IMEPEDU - Logout Administrativo
 * Arquivo: admin/logout.php
 */

session_start();

// Log do logout se admin estiver logado
if (isset($_SESSION['admin_id'])) {
    try {
        require_once '../config/database.php';
        
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
            VALUES ('logout_admin', ?, 'Logout realizado', ?, ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['admin_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (Exception $e) {
        // Ignora erros de log
        error_log("Erro no log de logout: " . $e->getMessage());
    }
}

// Destrói a sessão
session_destroy();

// Remove cookies de sessão
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redireciona para login
header('Location: /admin/login.php');
exit;
?>