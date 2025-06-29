<?php
/**
 * Sistema de Boletos IMEPEDU - API de Listagem de Backups
 * Arquivo: admin/api/listar-backups.php
 * 
 * Lista backups existentes e permite gerenciamento
 */

// Headers para evitar cache
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-SW-Bypass: true');
header('Content-Type: application/json; charset=utf-8');

// Verifica autenticação
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

// Aceita GET e DELETE
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'DELETE'])) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
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
    
    $backupManager = new BackupManager();
    
    if ($method === 'GET') {
        // Lista backups
        $action = $_GET['action'] ?? 'list';
        
        switch ($action) {
            case 'list':
                $result = $backupManager->listarBackups();
                break;
                
            case 'info':
                $filename = $_GET['filename'] ?? '';
                $result = $backupManager->obterInfoBackup($filename);
                break;
                
            case 'stats':
                $result = $backupManager->obterEstatisticas();
                break;
                
            default:
                throw new Exception('Ação não reconhecida');
        }
        
    } elseif ($method === 'DELETE') {
        // Remove backup
        $input = json_decode(file_get_contents('php://input'), true);
        $filename = $input['filename'] ?? '';
        
        if (empty($filename)) {
            throw new Exception('Nome do arquivo é obrigatório');
        }
        
        $result = $backupManager->removerBackup($filename, $admin);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    error_log("Erro na listagem de backups: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Classe para gerenciar backups
 */
class BackupManager {
    
    private $backupDir;
    
    public function __construct() {
        $this->backupDir = __DIR__ . '/../../backups/';
        
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }
    
    /**
     * Lista todos os backups disponíveis
     */
    public function listarBackups() {
        $backups = [];
        $files = glob($this->backupDir . 'backup_imepedu_*.zip');
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Extrai data do nome do arquivo
            if (preg_match('/backup_imepedu_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.zip/', $filename, $matches)) {
                $dateStr = $matches[1];
                $timestamp = DateTime::createFromFormat('Y-m-d_H-i-s', $dateStr);
                
                $fileInfo = [
                    'filename' => $filename,
                    'size' => filesize($file),
                    'size_mb' => round(filesize($file) / 1024 / 1024, 2),
                    'created_at' => $timestamp ? $timestamp->format('Y-m-d H:i:s') : 'Data inválida',
                    'age_days' => $timestamp ? floor((time() - $timestamp->getTimestamp()) / 86400) : 0,
                    'download_url' => '/admin/api/download-backup.php?file=' . urlencode($filename),
                    'is_valid' => $this->validarBackup($file),
                    'is_expired' => $this->isBackupExpired($file)
                ];
                
                // Adiciona informações do log se disponível
                $logInfo = $this->buscarLogBackup($filename);
                if ($logInfo) {
                    $fileInfo['admin_name'] = $logInfo['admin_name'];
                    $fileInfo['log_date'] = $logInfo['created_at'];
                }
                
                $backups[] = $fileInfo;
            }
        }
        
        // Ordena por data (mais recente primeiro)
        usort($backups, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return [
            'backups' => $backups,
            'total_count' => count($backups),
            'total_size_mb' => array_sum(array_column($backups, 'size_mb')),
            'directory' => $this->backupDir,
            'disk_space' => $this->obterEspacoDisco()
        ];
    }
    
    /**
     * Obtém informações detalhadas de um backup
     */
    public function obterInfoBackup($filename) {
        if (empty($filename)) {
            throw new Exception('Nome do arquivo é obrigatório');
        }
        
        $filepath = $this->backupDir . basename($filename);
        
        if (!file_exists($filepath)) {
            throw new Exception('Arquivo de backup não encontrado');
        }
        
        $zip = new ZipArchive();
        $info = [
            'filename' => $filename,
            'size' => filesize($filepath),
            'size_mb' => round(filesize($filepath) / 1024 / 1024, 2),
            'created_at' => date('Y-m-d H:i:s', filemtime($filepath)),
            'is_valid' => false,
            'contents' => []
        ];
        
        if ($zip->open($filepath) === TRUE) {
            $info['is_valid'] = true;
            $info['file_count'] = $zip->numFiles;
            
            // Lista conteúdo do ZIP
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $fileInfo = $zip->statIndex($i);
                $info['contents'][] = [
                    'name' => $fileInfo['name'],
                    'size' => $fileInfo['size'],
                    'compressed_size' => $fileInfo['comp_size'],
                    'compression_ratio' => $fileInfo['size'] > 0 ? 
                        round((1 - $fileInfo['comp_size'] / $fileInfo['size']) * 100, 1) : 0
                ];
            }
            
            // Tenta ler README se existir
            $readme = $zip->getFromName('README.txt');
            if ($readme !== false) {
                $info['readme'] = $readme;
            }
            
            $zip->close();
        }
        
        // Informações do log
        $logInfo = $this->buscarLogBackup($filename);
        if ($logInfo) {
            $info['log_info'] = $logInfo;
        }
        
        return $info;
    }
    
    /**
     * Obtém estatísticas gerais dos backups
     */
    public function obterEstatisticas() {
        $files = glob($this->backupDir . 'backup_imepedu_*.zip');
        
        $stats = [
            'total_backups' => count($files),
            'total_size_mb' => 0,
            'oldest_backup' => null,
            'newest_backup' => null,
            'expired_count' => 0,
            'valid_count' => 0,
            'average_size_mb' => 0,
            'disk_usage' => $this->obterEspacoDisco()
        ];
        
        if (empty($files)) {
            return $stats;
        }
        
        $sizes = [];
        $dates = [];
        
        foreach ($files as $file) {
            $size = filesize($file);
            $sizes[] = $size;
            $stats['total_size_mb'] += $size;
            
            $date = filemtime($file);
            $dates[] = $date;
            
            if ($this->isBackupExpired($file)) {
                $stats['expired_count']++;
            }
            
            if ($this->validarBackup($file)) {
                $stats['valid_count']++;
            }
        }
        
        $stats['total_size_mb'] = round($stats['total_size_mb'] / 1024 / 1024, 2);
        $stats['average_size_mb'] = round($stats['total_size_mb'] / count($files), 2);
        
        if (!empty($dates)) {
            $stats['oldest_backup'] = date('Y-m-d H:i:s', min($dates));
            $stats['newest_backup'] = date('Y-m-d H:i:s', max($dates));
        }
        
        return $stats;
    }
    
    /**
     * Remove um backup específico
     */
    public function removerBackup($filename, $admin) {
        $filename = basename($filename); // Segurança
        $filepath = $this->backupDir . $filename;
        
        if (!file_exists($filepath)) {
            throw new Exception('Arquivo de backup não encontrado');
        }
        
        // Validações de segurança
        if (!preg_match('/^backup_imepedu_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $filename)) {
            throw new Exception('Nome do arquivo inválido');
        }
        
        $size = filesize($filepath);
        $sizeMB = round($size / 1024 / 1024, 2);
        
        if (!unlink($filepath)) {
            throw new Exception('Não foi possível remover o arquivo');
        }
        
        // Registra log da remoção
        $this->registrarLogRemocao($admin['id'], $filename, $sizeMB);
        
        return [
            'filename' => $filename,
            'size_mb' => $sizeMB,
            'removed_at' => date('Y-m-d H:i:s'),
            'admin_name' => $admin['nome']
        ];
    }
    
    /**
     * Valida se backup é válido
     */
    private function validarBackup($filepath) {
        if (!file_exists($filepath) || filesize($filepath) < 1024) {
            return false;
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($filepath, ZipArchive::CHECKCONS);
        
        if ($result === TRUE) {
            $hasDatabase = $zip->locateName('database_') !== false;
            $hasSystemInfo = $zip->locateName('system_info_') !== false;
            $zip->close();
            
            return $hasDatabase && $hasSystemInfo;
        }
        
        return false;
    }
    
    /**
     * Verifica se backup está expirado
     */
    private function isBackupExpired($filepath) {
        $fileAge = time() - filemtime($filepath);
        $maxAge = 7 * 24 * 60 * 60; // 7 dias
        
        return $fileAge > $maxAge;
    }
    
    /**
     * Busca informações do log para um backup
     */
    private function buscarLogBackup($filename) {
        try {
            $db = new Database();
            
            $stmt = $db->getConnection()->prepare("
                SELECT l.*, a.nome as admin_name
                FROM logs l
                LEFT JOIN administradores a ON l.usuario_id = a.id
                WHERE l.tipo = 'backup_gerado' 
                AND l.descricao LIKE ?
                ORDER BY l.created_at DESC
                LIMIT 1
            ");
            
            $stmt->execute(['%' . $filename . '%']);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Obtém informações de espaço em disco
     */
    private function obterEspacoDisco() {
        $totalSpace = disk_total_space($this->backupDir);
        $freeSpace = disk_free_space($this->backupDir);
        $usedSpace = $totalSpace - $freeSpace;
        
        return [
            'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
            'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
            'used_gb' => round($usedSpace / 1024 / 1024 / 1024, 2),
            'free_percent' => round(($freeSpace / $totalSpace) * 100, 1),
            'used_percent' => round(($usedSpace / $totalSpace) * 100, 1)
        ];
    }
    
    /**
     * Registra log da remoção de backup
     */
    private function registrarLogRemocao($adminId, $filename, $sizeMB) {
        try {
            $db = new Database();
            
            $descricao = "Backup removido: {$filename} ({$sizeMB}MB)";
            
            $stmt = $db->getConnection()->prepare("
                INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                'backup_removido',
                $adminId,
                $descricao,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
            ]);
            
        } catch (Exception $e) {
            error_log("Erro ao registrar log de remoção: " . $e->getMessage());
        }
    }
}
?>