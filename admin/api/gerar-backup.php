<?php
/**
 * Sistema de Boletos IMEPEDU - API de Geração de Backup
 * Arquivo: admin/api/gerar-backup.php
 * 
 * Gera backup completo do sistema incluindo:
 * - Banco de dados
 * - Arquivos de configuração
 * - Uploads de boletos
 * - Logs importantes
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

// Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    $db = new Database();
    $adminService = new AdminService();
    
    // Verifica se admin existe e está ativo
    $admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);
    if (!$admin) {
        throw new Exception('Administrador não encontrado');
    }
    
    // Inicia geração do backup
    $backup = new BackupGenerator($db, $admin);
    $resultado = $backup->gerarBackupCompleto();
    
    // Log da operação
    $logMessage = "Backup gerado: {$resultado['filename']} ({$resultado['size_mb']}MB)";
    registrarLog('backup_gerado', $_SESSION['admin_id'], null, $logMessage);
    
    echo json_encode([
        'success' => true,
        'message' => 'Backup gerado com sucesso!',
        'filename' => $resultado['filename'],
        'download_url' => $resultado['download_url'],
        'size_mb' => $resultado['size_mb'],
        'components' => $resultado['components'],
        'timestamp' => $resultado['timestamp']
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao gerar backup: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao gerar backup: ' . $e->getMessage()
    ]);
}

/**
 * Classe para geração de backup
 */
class BackupGenerator {
    
    private $db;
    private $admin;
    private $backupDir;
    private $tempDir;
    private $timestamp;
    
    public function __construct($db, $admin) {
        $this->db = $db;
        $this->admin = $admin;
        $this->timestamp = date('Y-m-d_H-i-s');
        
        // Diretórios
        $this->backupDir = __DIR__ . '/../../backups/';
        $this->tempDir = $this->backupDir . 'temp_' . $this->timestamp . '/';
        
        // Cria diretórios se não existirem
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }
    
    /**
     * Gera backup completo
     */
    public function gerarBackupCompleto() {
        $inicio = microtime(true);
        
        $components = [
            'database' => $this->backupDatabase(),
            'config' => $this->backupConfiguracoes(),
            'uploads' => $this->backupUploads(),
            'logs' => $this->backupLogs(),
            'system_info' => $this->backupSystemInfo()
        ];
        
        // Cria arquivo ZIP
        $zipFile = $this->criarZipBackup($components);
        
        // Remove arquivos temporários
        $this->limparTemp();
        
        $fim = microtime(true);
        $tempo = round($fim - $inicio, 2);
        
        // Calcula tamanho
        $size = filesize($zipFile);
        $sizeMB = round($size / 1024 / 1024, 2);
        
        return [
            'filename' => basename($zipFile),
            'download_url' => '/admin/api/download-backup.php?file=' . urlencode(basename($zipFile)),
            'size_mb' => $sizeMB,
            'components' => $components,
            'timestamp' => $this->timestamp,
            'generation_time' => $tempo . 's'
        ];
    }
    
    /**
     * Backup do banco de dados
     */
    private function backupDatabase() {
        try {
            $filename = 'database_' . $this->timestamp . '.sql';
            $filepath = $this->tempDir . $filename;
            
            // Obtém backup SQL
            $sqlBackup = $this->gerarBackupSQL();
            
            file_put_contents($filepath, $sqlBackup);
            
            return [
                'status' => 'success',
                'filename' => $filename,
                'size' => filesize($filepath),
                'tables_backed_up' => $this->contarTabelas()
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Gera backup SQL
     */
    private function gerarBackupSQL() {
        $sql = "-- Backup Sistema de Boletos IMEPEDU\n";
        $sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Administrador: " . $this->admin['nome'] . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
        
        // Lista de tabelas para backup
        $tabelas = [
            'administradores',
            'alunos', 
            'cursos',
            'matriculas',
            'boletos',
            'logs',
            'configuracoes'
        ];
        
        foreach ($tabelas as $tabela) {
            if ($this->tabelaExiste($tabela)) {
                $sql .= $this->backupTabela($tabela);
            }
        }
        
        $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        
        return $sql;
    }
    
    /**
     * Backup de uma tabela específica
     */
    private function backupTabela($tabela) {
        $sql = "\n-- ========================================\n";
        $sql .= "-- Backup da tabela: {$tabela}\n";
        $sql .= "-- ========================================\n\n";
        
        // Estrutura da tabela
        $createStmt = $this->db->getConnection()->query("SHOW CREATE TABLE `{$tabela}`");
        $createData = $createStmt->fetch();
        
        $sql .= "DROP TABLE IF EXISTS `{$tabela}`;\n";
        $sql .= $createData['Create Table'] . ";\n\n";
        
        // Dados da tabela
        $dataStmt = $this->db->getConnection()->query("SELECT * FROM `{$tabela}`");
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($rows)) {
            $sql .= "-- Dados da tabela {$tabela}\n";
            
            // Inserts em lotes para performance
            $batchSize = 100;
            $batches = array_chunk($rows, $batchSize);
            
            foreach ($batches as $batch) {
                $columns = array_keys($batch[0]);
                $sql .= "INSERT INTO `{$tabela}` (`" . implode('`, `', $columns) . "`) VALUES\n";
                
                $values = [];
                foreach ($batch as $row) {
                    $escapedValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $escapedValues[] = 'NULL';
                        } else {
                            $escapedValues[] = "'" . str_replace("'", "''", $value) . "'";
                        }
                    }
                    $values[] = '(' . implode(', ', $escapedValues) . ')';
                }
                
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        return $sql;
    }
    
    /**
     * Backup das configurações
     */
    private function backupConfiguracoes() {
        try {
            $configs = [];
            
            // Configurações do banco
            $stmt = $this->db->getConnection()->query("SELECT * FROM configuracoes");
            $configs['database_configs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Arquivos de configuração
            $configFiles = [
                'app.php' => '../../config/app.php',
                'database.php' => '../../config/database.php',
                'moodle.php' => '../../config/moodle.php'
            ];
            
            $configs['config_files'] = [];
            
            foreach ($configFiles as $name => $path) {
                if (file_exists($path)) {
                    $configs['config_files'][$name] = [
                        'content' => file_get_contents($path),
                        'size' => filesize($path),
                        'modified' => filemtime($path)
                    ];
                }
            }
            
            // Salva configurações
            $filename = 'configuracoes_' . $this->timestamp . '.json';
            $filepath = $this->tempDir . $filename;
            
            file_put_contents($filepath, json_encode($configs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            return [
                'status' => 'success',
                'filename' => $filename,
                'size' => filesize($filepath),
                'config_count' => count($configs['database_configs']),
                'files_count' => count($configs['config_files'])
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Backup dos uploads
     */
    private function backupUploads() {
        try {
            $uploadsDir = __DIR__ . '/../../uploads/';
            $backupFile = $this->tempDir . 'uploads_' . $this->timestamp . '.tar.gz';
            
            if (!is_dir($uploadsDir)) {
                return [
                    'status' => 'skipped',
                    'message' => 'Diretório de uploads não encontrado'
                ];
            }
            
            // Conta arquivos
            $totalFiles = 0;
            $totalSize = 0;
            
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $totalFiles++;
                    $totalSize += $file->getSize();
                }
            }
            
            // Cria tar.gz dos uploads (se disponível)
            if (function_exists('exec') && $totalFiles > 0) {
                $command = "cd " . escapeshellarg(dirname($uploadsDir)) . " && tar -czf " . 
                          escapeshellarg($backupFile) . " uploads/ 2>/dev/null";
                
                exec($command, $output, $returnVar);
                
                if ($returnVar === 0 && file_exists($backupFile)) {
                    return [
                        'status' => 'success',
                        'filename' => basename($backupFile),
                        'size' => filesize($backupFile),
                        'original_files' => $totalFiles,
                        'original_size' => $totalSize
                    ];
                }
            }
            
            // Fallback: copia arquivos importantes
            return $this->backupUploadsManual($uploadsDir);
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Backup manual dos uploads (fallback)
     */
    private function backupUploadsManual($uploadsDir) {
        $uploadBackupDir = $this->tempDir . 'uploads/';
        if (!is_dir($uploadBackupDir)) {
            mkdir($uploadBackupDir, 0755, true);
        }
        
        $copiedFiles = 0;
        $copiedSize = 0;
        
        // Copia apenas PDFs recentes (últimos 30 dias)
        $cutoffDate = time() - (30 * 24 * 60 * 60);
        
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir));
        foreach ($iterator as $file) {
            if ($file->isFile() && 
                $file->getExtension() === 'pdf' && 
                $file->getMTime() > $cutoffDate) {
                
                $relativePath = str_replace($uploadsDir, '', $file->getPathname());
                $destPath = $uploadBackupDir . $relativePath;
                
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                
                if (copy($file->getPathname(), $destPath)) {
                    $copiedFiles++;
                    $copiedSize += $file->getSize();
                }
            }
        }
        
        return [
            'status' => 'partial',
            'message' => 'Backup manual dos uploads recentes',
            'files_copied' => $copiedFiles,
            'size_copied' => $copiedSize
        ];
    }
    
    /**
     * Backup dos logs
     */
    private function backupLogs() {
        try {
            // Logs do banco (últimos 7 dias)
            $stmt = $this->db->getConnection()->prepare("
                SELECT * FROM logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY created_at DESC
            ");
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $logsData = [
                'database_logs' => $logs,
                'log_count' => count($logs),
                'backup_date' => date('Y-m-d H:i:s'),
                'admin_id' => $this->admin['id'],
                'admin_name' => $this->admin['nome']
            ];
            
            // Logs de arquivos (se existirem)
            $logDir = __DIR__ . '/../../logs/';
            if (is_dir($logDir)) {
                $logFiles = glob($logDir . '*.log');
                $logsData['file_logs'] = [];
                
                foreach ($logFiles as $logFile) {
                    // Apenas últimas 1000 linhas de cada arquivo
                    $lines = file($logFile);
                    $recentLines = array_slice($lines, -1000);
                    
                    $logsData['file_logs'][basename($logFile)] = [
                        'content' => implode('', $recentLines),
                        'total_lines' => count($lines),
                        'backed_up_lines' => count($recentLines),
                        'modified' => filemtime($logFile)
                    ];
                }
            }
            
            $filename = 'logs_' . $this->timestamp . '.json';
            $filepath = $this->tempDir . $filename;
            
            file_put_contents($filepath, json_encode($logsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            return [
                'status' => 'success',
                'filename' => $filename,
                'size' => filesize($filepath),
                'db_logs_count' => count($logs),
                'file_logs_count' => isset($logsData['file_logs']) ? count($logsData['file_logs']) : 0
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Backup das informações do sistema
     */
    private function backupSystemInfo() {
        try {
            $systemInfo = [
                'backup_info' => [
                    'version' => '1.0',
                    'timestamp' => $this->timestamp,
                    'admin' => [
                        'id' => $this->admin['id'],
                        'name' => $this->admin['nome'],
                        'login' => $this->admin['login']
                    ]
                ],
                'server_info' => [
                    'php_version' => PHP_VERSION,
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size')
                ],
                'database_info' => $this->db->getDatabaseInfo(),
                'statistics' => $this->obterEstatisticasSistema(),
                'moodle_config' => $this->obterConfigMoodle()
            ];
            
            $filename = 'system_info_' . $this->timestamp . '.json';
            $filepath = $this->tempDir . $filename;
            
            file_put_contents($filepath, json_encode($systemInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            return [
                'status' => 'success',
                'filename' => $filename,
                'size' => filesize($filepath)
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Cria arquivo ZIP final
     */
    private function criarZipBackup($components) {
        $zipFilename = "backup_imepedu_{$this->timestamp}.zip";
        $zipPath = $this->backupDir . $zipFilename;
        
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("Não foi possível criar arquivo ZIP");
        }
        
        // Adiciona arquivos do diretório temporário
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tempDir));
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($this->tempDir, '', $file->getPathname());
                $zip->addFile($file->getPathname(), $relativePath);
            }
        }
        
        // Adiciona README do backup
        $readme = $this->gerarReadmeBackup($components);
        $zip->addFromString('README.txt', $readme);
        
        $zip->close();
        
        if (!file_exists($zipPath)) {
            throw new Exception("Falha ao criar arquivo de backup");
        }
        
        return $zipPath;
    }
    
    /**
     * Gera README do backup
     */
    private function gerarReadmeBackup($components) {
        $readme = "BACKUP SISTEMA DE BOLETOS IMEPEDU\n";
        $readme .= "=====================================\n\n";
        $readme .= "Data: " . date('d/m/Y H:i:s') . "\n";
        $readme .= "Administrador: " . $this->admin['nome'] . " (" . $this->admin['login'] . ")\n";
        $readme .= "Versão: 1.0\n\n";
        
        $readme .= "COMPONENTES DO BACKUP:\n";
        $readme .= "----------------------\n\n";
        
        foreach ($components as $component => $data) {
            $readme .= ucfirst($component) . ":\n";
            if ($data['status'] === 'success') {
                $readme .= "  ✓ Status: Sucesso\n";
                if (isset($data['filename'])) {
                    $readme .= "  ✓ Arquivo: " . $data['filename'] . "\n";
                }
                if (isset($data['size'])) {
                    $readme .= "  ✓ Tamanho: " . number_format($data['size'] / 1024, 2) . " KB\n";
                }
            } else {
                $readme .= "  ✗ Status: " . ($data['status'] ?? 'erro') . "\n";
                if (isset($data['message'])) {
                    $readme .= "  ✗ Detalhes: " . $data['message'] . "\n";
                }
            }
            $readme .= "\n";
        }
        
        $readme .= "INSTRUÇÕES DE RESTAURAÇÃO:\n";
        $readme .= "---------------------------\n\n";
        $readme .= "1. Extraia todos os arquivos deste backup\n";
        $readme .= "2. Para restaurar o banco de dados:\n";
        $readme .= "   - Execute o arquivo database_*.sql no MySQL\n";
        $readme .= "3. Para restaurar configurações:\n";
        $readme .= "   - Substitua os arquivos em /config/ pelos do backup\n";
        $readme .= "4. Para restaurar uploads:\n";
        $readme .= "   - Extraia uploads_*.tar.gz para /uploads/ ou\n";
        $readme .= "   - Copie a pasta uploads/ para o local correto\n\n";
        
        $readme .= "IMPORTANTE:\n";
        $readme .= "-----------\n";
        $readme .= "- Verifique as configurações do banco após restaurar\n";
        $readme .= "- Teste a conectividade com o Moodle\n";
        $readme .= "- Verifique permissões dos diretórios\n";
        $readme .= "- Teste o sistema completamente antes de usar\n\n";
        
        $readme .= "Em caso de dúvidas, consulte a documentação do sistema.\n";
        
        return $readme;
    }
    
    /**
     * Remove arquivos temporários
     */
    private function limparTemp() {
        if (is_dir($this->tempDir)) {
            $this->removerDiretorio($this->tempDir);
        }
    }
    
    /**
     * Remove diretório recursivamente
     */
    private function removerDiretorio($dir) {
        if (!is_dir($dir)) return false;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removerDiretorio($path) : unlink($path);
        }
        
        return rmdir($dir);
    }
    
    /**
     * Verifica se tabela existe
     */
    private function tabelaExiste($tabela) {
        try {
            $stmt = $this->db->getConnection()->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tabela]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Conta número de tabelas
     */
    private function contarTabelas() {
        try {
            $stmt = $this->db->getConnection()->query("SHOW TABLES");
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Obtém estatísticas do sistema
     */
    private function obterEstatisticasSistema() {
        try {
            $stats = [];
            
            // Estatísticas das tabelas principais
            $tabelas = ['alunos', 'cursos', 'matriculas', 'boletos', 'logs'];
            
            foreach ($tabelas as $tabela) {
                if ($this->tabelaExiste($tabela)) {
                    $stmt = $this->db->getConnection()->query("SELECT COUNT(*) as count FROM {$tabela}");
                    $stats[$tabela] = $stmt->fetch()['count'];
                }
            }
            
            // Estatísticas específicas
            if ($this->tabelaExiste('boletos')) {
                $stmt = $this->db->getConnection()->query("
                    SELECT 
                        status,
                        COUNT(*) as count,
                        SUM(valor) as total_valor
                    FROM boletos 
                    GROUP BY status
                ");
                $stats['boletos_por_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $stats;
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Obtém configuração do Moodle
     */
    private function obterConfigMoodle() {
        try {
            require_once '../../config/moodle.php';
            
            return [
                'polos_total' => count(MoodleConfig::getAllSubdomains()),
                'polos_ativos' => count(MoodleConfig::getActiveSubdomains()),
                'funcoes_permitidas' => count(MoodleConfig::getAllowedFunctions()),
                'configuracao_global' => MoodleConfig::getGlobalConfig(),
                'stats' => MoodleConfig::getPolosStats()
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

/**
 * Função para registrar logs
 */
function registrarLog($tipo, $usuarioId, $boletoId, $descricao) {
    try {
        $db = new Database();
        
        $stmt = $db->getConnection()->prepare("
            INSERT INTO logs (tipo, usuario_id, boleto_id, descricao, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $tipo,
            $usuarioId,
            $boletoId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}
?>