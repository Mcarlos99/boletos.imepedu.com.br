<?php
/**
 * Sistema de Boletos IMEPEDU - API para Limpeza de Logs
 * Arquivo: admin/api/limpar-logs.php
 * 
 * FUNCIONALIDADES:
 * - Limpar logs antigos (por período)
 * - Remover TODOS os logs (operação crítica)
 * - Backup antes da remoção
 */

session_start();

// Headers de segurança
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verifica se administrador está logado
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

// Verifica método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    require_once '../../config/database.php';
    
    // Lê input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Dados inválidos no corpo da requisição');
    }
    
    // Verifica permissões do administrador
    $adminId = $_SESSION['admin_id'];
    if (!verificarPermissaoLimpeza($adminId)) {
        throw new Exception('Você não tem permissão para esta operação');
    }
    
    $db = (new Database())->getConnection();
    
    // Determina tipo de operação
    if (isset($input['remover_todos']) && $input['remover_todos'] === true) {
        // 🔥 OPERAÇÃO CRÍTICA: Remover TODOS os logs
        $resultado = removerTodosLogs($db, $input, $adminId);
    } else {
        // Operação normal: Limpar logs antigos
        $dias = intval($input['dias'] ?? 90);
        $resultado = limparLogsAntigos($db, $dias, $adminId);
    }
    
    echo json_encode($resultado);
    
} catch (Exception $e) {
    error_log("API Limpar Logs: ERRO - " . $e->getMessage());
    
    $httpCode = 400;
    if (strpos($e->getMessage(), 'não autorizado') !== false || strpos($e->getMessage(), 'não tem permissão') !== false) {
        $httpCode = 403;
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $httpCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * 🔥 FUNÇÃO CRÍTICA: Remove TODOS os logs do sistema
 */
function removerTodosLogs($db, $input, $adminId) {
    try {
        error_log("🔥 OPERAÇÃO CRÍTICA: Remoção de TODOS os logs iniciada pelo admin {$adminId}");
        
        // Verificações de segurança extras
        if (!isset($input['confirmar']) || $input['confirmar'] !== true) {
            throw new Exception('Confirmação necessária');
        }
        
        if (!isset($input['confirmacao_texto']) || $input['confirmacao_texto'] !== 'REMOVER TODOS') {
            throw new Exception('Confirmação textual incorreta');
        }
        
        $db->beginTransaction();
        
        // 1. Conta total de logs antes da remoção
        $stmt = $db->query("SELECT COUNT(*) as total FROM logs");
        $totalLogs = $stmt->fetch()['total'];
        
        if ($totalLogs == 0) {
            $db->rollback();
            return [
                'success' => true,
                'message' => 'Nenhum log encontrado para remover',
                'logs_removidos' => 0,
                'backup_criado' => false
            ];
        }
        
        // 2. Cria backup ANTES da remoção (CRÍTICO)
        $backupPath = criarBackupCompleto($db, $totalLogs);
        
        // 3. Remove TODOS os logs
        $stmt = $db->prepare("DELETE FROM logs");
        $stmt->execute();
        $logsRemovidos = $stmt->rowCount();
        
        // 4. Reseta AUTO_INCREMENT para economizar espaço
        $db->exec("ALTER TABLE logs AUTO_INCREMENT = 1");
        
        // 5. Registra a operação crítica (cria novo log)
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            'logs_removidos_todos',
            $adminId,
            "🔥 OPERAÇÃO CRÍTICA: {$logsRemovidos} logs removidos. Backup: {$backupPath}",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
        $db->commit();
        
        error_log("🔥 OPERAÇÃO CRÍTICA CONCLUÍDA: {$logsRemovidos} logs removidos. Backup: {$backupPath}");
        
        return [
            'success' => true,
            'message' => 'TODOS os logs foram removidos com sucesso!',
            'logs_removidos' => $logsRemovidos,
            'backup_criado' => true,
            'backup_path' => basename($backupPath),
            'operacao' => 'REMOCAO_COMPLETA',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
        }
        
        error_log("🔥 ERRO CRÍTICO na remoção completa: " . $e->getMessage());
        throw new Exception("Erro na remoção completa: " . $e->getMessage());
    }
}

/**
 * Limpa logs antigos (operação normal)
 */
function limparLogsAntigos($db, $dias, $adminId) {
    try {
        error_log("Limpeza de logs antigos iniciada: {$dias} dias pelo admin {$adminId}");
        
        if ($dias < 1 || $dias > 3650) { // Máximo 10 anos
            throw new Exception('Período inválido. Use entre 1 e 3650 dias');
        }
        
        $db->beginTransaction();
        
        // Conta logs que serão removidos
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$dias]);
        $totalParaRemover = $stmt->fetch()['total'];
        
        if ($totalParaRemover == 0) {
            $db->rollback();
            return [
                'success' => true,
                'message' => "Nenhum log anterior a {$dias} dias encontrado",
                'logs_removidos' => 0,
                'dias' => $dias
            ];
        }
        
        // Cria backup dos logs que serão removidos
        $backupPath = criarBackupParcial($db, $dias, $totalParaRemover);
        
        // Remove logs antigos
        $stmt = $db->prepare("
            DELETE FROM logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$dias]);
        $logsRemovidos = $stmt->rowCount();
        
        // Registra a operação
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            'logs_limpos',
            $adminId,
            "Limpeza de logs: {$logsRemovidos} registros anteriores a {$dias} dias removidos. Backup: {$backupPath}",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
        $db->commit();
        
        error_log("Limpeza concluída: {$logsRemovidos} logs removidos");
        
        return [
            'success' => true,
            'message' => "Logs antigos removidos com sucesso!",
            'logs_removidos' => $logsRemovidos,
            'dias' => $dias,
            'backup_criado' => !empty($backupPath),
            'backup_path' => $backupPath ? basename($backupPath) : null,
            'operacao' => 'LIMPEZA_PERIODO'
        ];
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
        }
        
        error_log("Erro na limpeza de logs antigos: " . $e->getMessage());
        throw new Exception("Erro na limpeza: " . $e->getMessage());
    }
}

/**
 * Cria backup completo de TODOS os logs
 */
function criarBackupCompleto($db, $totalLogs) {
    try {
        $backupDir = __DIR__ . '/../../backups/logs/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_logs_COMPLETO_{$timestamp}.sql";
        $filepath = $backupDir . $filename;
        
        $backup = "-- Backup COMPLETO de logs - Sistema IMEPEDU\n";
        $backup .= "-- Data: " . date('Y-m-d H:i:s') . "\n";
        $backup .= "-- Total de registros: {$totalLogs}\n";
        $backup .= "-- Motivo: REMOÇÃO COMPLETA DE TODOS OS LOGS\n\n";
        
        // Estrutura da tabela
        $stmt = $db->query("SHOW CREATE TABLE logs");
        $createTable = $stmt->fetch();
        $backup .= "-- Estrutura da tabela logs\n";
        $backup .= $createTable['Create Table'] . ";\n\n";
        
        // Dados em lotes para economia de memória
        $limite = 1000;
        $offset = 0;
        $totalExportado = 0;
        
        while ($offset < $totalLogs) {
            $stmt = $db->prepare("
                SELECT * FROM logs 
                ORDER BY id ASC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limite, $offset]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($logs)) {
                $backup .= "-- Lote " . ($offset / $limite + 1) . "\n";
                $backup .= "INSERT INTO logs (id, tipo, usuario_id, boleto_id, descricao, ip_address, user_agent, created_at) VALUES\n";
                
                $values = [];
                foreach ($logs as $log) {
                    $values[] = sprintf(
                        "(%s, %s, %s, %s, %s, %s, %s, %s)",
                        $db->quote($log['id']),
                        $db->quote($log['tipo']),
                        $log['usuario_id'] ? $db->quote($log['usuario_id']) : 'NULL',
                        $log['boleto_id'] ? $db->quote($log['boleto_id']) : 'NULL',
                        $db->quote($log['descricao']),
                        $db->quote($log['ip_address']),
                        $db->quote($log['user_agent']),
                        $db->quote($log['created_at'])
                    );
                }
                
                $backup .= implode(",\n", $values) . ";\n\n";
                $totalExportado += count($logs);
            }
            
            $offset += $limite;
        }
        
        $backup .= "-- Fim do backup. Total exportado: {$totalExportado} registros\n";
        
        if (file_put_contents($filepath, $backup) === false) {
            throw new Exception("Erro ao salvar backup");
        }
        
        // Comprime o backup para economizar espaço
        if (function_exists('gzencode')) {
            $compressed = gzencode($backup, 9);
            file_put_contents($filepath . '.gz', $compressed);
            unlink($filepath); // Remove versão não comprimida
            $filepath .= '.gz';
        }
        
        error_log("Backup completo criado: {$filepath} ({$totalExportado} registros)");
        
        return $filepath;
        
    } catch (Exception $e) {
        error_log("Erro ao criar backup completo: " . $e->getMessage());
        throw new Exception("Erro ao criar backup: " . $e->getMessage());
    }
}

/**
 * Cria backup parcial dos logs antigos
 */
function criarBackupParcial($db, $dias, $totalLogs) {
    try {
        if ($totalLogs == 0) {
            return null;
        }
        
        $backupDir = __DIR__ . '/../../backups/logs/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_logs_antigos_{$dias}dias_{$timestamp}.sql";
        $filepath = $backupDir . $filename;
        
        $backup = "-- Backup de logs antigos - Sistema IMEPEDU\n";
        $backup .= "-- Data: " . date('Y-m-d H:i:s') . "\n";
        $backup .= "-- Logs anteriores a: {$dias} dias\n";
        $backup .= "-- Total de registros: {$totalLogs}\n\n";
        
        // Exporta logs antigos
        $stmt = $db->prepare("
            SELECT * FROM logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY created_at ASC
        ");
        $stmt->execute([$dias]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($logs)) {
            $backup .= "INSERT INTO logs (id, tipo, usuario_id, boleto_id, descricao, ip_address, user_agent, created_at) VALUES\n";
            
            $values = [];
            foreach ($logs as $log) {
                $values[] = sprintf(
                    "(%s, %s, %s, %s, %s, %s, %s, %s)",
                    $db->quote($log['id']),
                    $db->quote($log['tipo']),
                    $log['usuario_id'] ? $db->quote($log['usuario_id']) : 'NULL',
                    $log['boleto_id'] ? $db->quote($log['boleto_id']) : 'NULL',
                    $db->quote($log['descricao']),
                    $db->quote($log['ip_address']),
                    $db->quote($log['user_agent']),
                    $db->quote($log['created_at'])
                );
            }
            
            $backup .= implode(",\n", $values) . ";\n";
        }
        
        if (file_put_contents($filepath, $backup) === false) {
            throw new Exception("Erro ao salvar backup parcial");
        }
        
        error_log("Backup parcial criado: {$filepath} ({$totalLogs} registros)");
        
        return $filepath;
        
    } catch (Exception $e) {
        error_log("Erro ao criar backup parcial: " . $e->getMessage());
        return null; // Não falha a operação se backup falhar
    }
}

/**
 * Verifica se o administrador tem permissão para limpeza de logs
 */
function verificarPermissaoLimpeza($adminId) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("SELECT nivel FROM administradores WHERE id = ? AND ativo = 1");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            return false;
        }
        
        // Apenas super admins podem fazer limpeza de logs
        return in_array($admin['nivel'], ['admin', 'super_admin', 'master']);
        
    } catch (Exception $e) {
        error_log("Erro ao verificar permissão: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtém estatísticas dos logs para retorno
 */
function obterEstatisticasLogs($db) {
    try {
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as ultimas_24h,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as ultimos_7d,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as ultimos_30d,
                MIN(created_at) as mais_antigo,
                MAX(created_at) as mais_recente
            FROM logs
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return [
            'total' => 0,
            'ultimas_24h' => 0,
            'ultimos_7d' => 0,
            'ultimos_30d' => 0,
            'mais_antigo' => null,
            'mais_recente' => null
        ];
    }
}

/**
 * Lista backups disponíveis
 */
function listarBackupsDisponiveis() {
    try {
        $backupDir = __DIR__ . '/../../backups/logs/';
        if (!is_dir($backupDir)) {
            return [];
        }
        
        $backups = [];
        $files = glob($backupDir . 'backup_logs_*.{sql,gz}', GLOB_BRACE);
        
        foreach ($files as $file) {
            $backups[] = [
                'nome' => basename($file),
                'tamanho' => filesize($file),
                'data_criacao' => date('Y-m-d H:i:s', filemtime($file)),
                'tipo' => strpos($file, 'COMPLETO') !== false ? 'completo' : 'parcial'
            ];
        }
        
        // Ordena por data de criação (mais recente primeiro)
        usort($backups, function($a, $b) {
            return strtotime($b['data_criacao']) - strtotime($a['data_criacao']);
        });
        
        return $backups;
        
    } catch (Exception $e) {
        error_log("Erro ao listar backups: " . $e->getMessage());
        return [];
    }
}

// Log da execução
error_log("API Limpar Logs executada - Admin: " . ($_SESSION['admin_id'] ?? 'unknown') . " - Timestamp: " . date('Y-m-d H:i:s'));
?>