<?php
/**
 * Sistema de Boletos IMEPEDU - API de Download de Backup
 * Arquivo: admin/api/download-backup.php
 * 
 * Permite download seguro dos arquivos de backup gerados
 */

// Headers para evitar cache
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-SW-Bypass: true');

// Verifica autenticação
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    die('Acesso não autorizado');
}

// Só aceita GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die('Método não permitido');
}

require_once '../../config/database.php';
require_once '../../src/AdminService.php';

try {
    $adminService = new AdminService();
    
    // Verifica se admin existe e está ativo
    $admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);
    if (!$admin) {
        throw new Exception('Administrador não encontrado');
    }
    
    // Valida parâmetro do arquivo
    if (!isset($_GET['file']) || empty($_GET['file'])) {
        throw new Exception('Arquivo não especificado');
    }
    
    $filename = $_GET['file'];
    
    // Validações de segurança
    if (!validarNomeArquivo($filename)) {
        throw new Exception('Nome do arquivo inválido');
    }
    
    $backupDir = __DIR__ . '/../../backups/';
    $filepath = $backupDir . $filename;
    
    // Verifica se arquivo existe
    if (!file_exists($filepath)) {
        throw new Exception('Arquivo de backup não encontrado');
    }
    
    // Verifica se é realmente um backup
    if (!isBackupFile($filename)) {
        throw new Exception('Tipo de arquivo não permitido');
    }
    
    // Verifica idade do arquivo (máximo 7 dias)
    $fileAge = time() - filemtime($filepath);
    $maxAge = 7 * 24 * 60 * 60; // 7 dias
    
    if ($fileAge > $maxAge) {
        // Remove arquivo antigo
        unlink($filepath);
        throw new Exception('Arquivo de backup expirado e foi removido');
    }
    
    // Log do download
    registrarLogDownload($admin['id'], $filename);
    
    // Prepara download
    $filesize = filesize($filepath);
    $mimetype = 'application/zip';
    
    // Headers para download
    header('Content-Type: ' . $mimetype);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $filesize);
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    
    // Disable output buffering
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Stream do arquivo
    streamFile($filepath);
    
} catch (Exception $e) {
    // Reset headers em caso de erro
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(400);
    }
    
    error_log("Erro no download de backup: " . $e->getMessage());
    echo "Erro: " . htmlspecialchars($e->getMessage());
}

/**
 * Valida nome do arquivo de backup
 */
function validarNomeArquivo($filename) {
    // Remove qualquer path
    $filename = basename($filename);
    
    // Verifica padrão esperado
    if (!preg_match('/^backup_imepedu_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $filename)) {
        return false;
    }
    
    // Verifica caracteres perigosos
    $dangerous = ['..', '/', '\\', '<', '>', ':', '"', '|', '?', '*'];
    foreach ($dangerous as $char) {
        if (strpos($filename, $char) !== false) {
            return false;
        }
    }
    
    return true;
}

/**
 * Verifica se é arquivo de backup válido
 */
function isBackupFile($filename) {
    $allowedExtensions = ['.zip'];
    $allowedPrefixes = ['backup_imepedu_'];
    
    $extension = strtolower(substr($filename, strrpos($filename, '.')));
    if (!in_array($extension, $allowedExtensions)) {
        return false;
    }
    
    $hasValidPrefix = false;
    foreach ($allowedPrefixes as $prefix) {
        if (strpos($filename, $prefix) === 0) {
            $hasValidPrefix = true;
            break;
        }
    }
    
    return $hasValidPrefix;
}

/**
 * Stream do arquivo para download
 */
function streamFile($filepath) {
    $handle = fopen($filepath, 'rb');
    
    if ($handle === false) {
        throw new Exception('Não foi possível abrir o arquivo');
    }
    
    // Stream em chunks para não sobrecarregar memória
    $chunkSize = 8192; // 8KB chunks
    
    while (!feof($handle)) {
        $chunk = fread($handle, $chunkSize);
        if ($chunk === false) {
            break;
        }
        
        echo $chunk;
        
        // Flush output
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        
        // Verifica se conexão ainda está ativa
        if (connection_aborted()) {
            break;
        }
    }
    
    fclose($handle);
}

/**
 * Registra log do download
 */
function registrarLogDownload($adminId, $filename) {
    try {
        $db = new Database();
        
        $descricao = "Download de backup: {$filename}";
        
        $stmt = $db->getConnection()->prepare("
            INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            'backup_download',
            $adminId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log de download: " . $e->getMessage());
    }
}

/**
 * Limpa backups antigos (executado automaticamente)
 */
function limparBackupsAntigos() {
    try {
        $backupDir = __DIR__ . '/../../backups/';
        
        if (!is_dir($backupDir)) {
            return;
        }
        
        $files = glob($backupDir . 'backup_imepedu_*.zip');
        $maxAge = 7 * 24 * 60 * 60; // 7 dias
        $removidos = 0;
        
        foreach ($files as $file) {
            $fileAge = time() - filemtime($file);
            
            if ($fileAge > $maxAge) {
                if (unlink($file)) {
                    $removidos++;
                    error_log("Backup antigo removido: " . basename($file));
                }
            }
        }
        
        return $removidos;
        
    } catch (Exception $e) {
        error_log("Erro ao limpar backups antigos: " . $e->getMessage());
        return 0;
    }
}

// Executa limpeza automática (10% de chance)
if (rand(1, 10) === 1) {
    limparBackupsAntigos();
}
?>