<?php
/**
 * Sistema de Boletos IMED - Logout
 * Arquivo: logout.php
 */

session_start();

// Log do logout se usuário estiver logado
if (isset($_SESSION['aluno_cpf'])) {
    try {
        require_once 'config/database.php';
        
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
            VALUES ('logout', ?, 'Logout realizado', ?, ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['aluno_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Ignora erros de log
    }
}

// Destrói a sessão
session_destroy();

// Remove cookies de sessão
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redireciona para página inicial
header('Location: /index.php');
exit;
?>