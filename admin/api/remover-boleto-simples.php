<?php
/**
 * Sistema de Boletos IMED - API Remover Boleto SIMPLIFICADA
 * Arquivo: admin/api/remover-boleto-simples.php
 * 
 * Versão simplificada que funciona sem setup complexo
 */

ini_set('max_execution_time', 30);
session_start();

// Headers básicos
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log para debug
error_log("=== API REMOVER SIMPLES ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);

// Verifica autenticação
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }
    
    // Lê input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Dados não fornecidos');
    }
    
    $input = json_decode($rawInput, true);
    if (!$input) {
        throw new Exception('JSON inválido: ' . json_last_error_msg());
    }
    
    // Valida campos
    $boletoId = filter_var($input['boleto_id'] ?? null, FILTER_VALIDATE_INT);
    $motivo = trim($input['motivo'] ?? '');
    $confirmar = filter_var($input['confirmar_remocao'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (!$boletoId) {
        throw new Exception('ID do boleto inválido');
    }
    
    if (empty($motivo)) {
        throw new Exception('Motivo é obrigatório');
    }
    
    if (!$confirmar) {
        throw new Exception('Confirmação necessária');
    }
    
    error_log("Removendo boleto ID: {$boletoId}");
    
    // Conecta banco
    require_once '../../config/database.php';
    $db = (new Database())->getConnection();
    
    // Inicia transação
    $db->beginTransaction();
    
    // Busca boleto
    $stmt = $db->prepare("
        SELECT b.*, a.nome as aluno_nome, c.nome as curso_nome
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id  
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.id = ?
    ");
    $stmt->execute([$boletoId]);
    $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$boleto) {
        throw new Exception('Boleto não encontrado');
    }
    
    // Verifica permissão básica (sem tabela de níveis)
    $podeRemover = true;
    
    // Se boleto está pago, só super admin pode remover
    if ($boleto['status'] === 'pago') {
        // Verifica se existe coluna nivel
        try {
            $stmt = $db->prepare("SELECT nivel FROM administradores WHERE id = ?");
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch();
            
            if (!$admin || !in_array($admin['nivel'], ['super_admin', 'master'])) {
                $podeRemover = false;
            }
        } catch (Exception $e) {
            // Se coluna nivel não existe, permite remoção (compatibilidade)
            error_log("Coluna nivel não existe - permitindo remoção");
        }
    }
    
    if (!$podeRemover) {
        throw new Exception('Você não tem permissão para remover este boleto pago');
    }
    
    // Remove arquivo PDF se existir
    $arquivoRemovido = false;
    if (!empty($boleto['arquivo_pdf'])) {
        $caminhoArquivo = __DIR__ . '/../../uploads/boletos/' . $boleto['arquivo_pdf'];
        if (file_exists($caminhoArquivo)) {
            if (unlink($caminhoArquivo)) {
                $arquivoRemovido = true;
                error_log("Arquivo PDF removido: " . $boleto['arquivo_pdf']);
            }
        }
    }
    
    // Registra log ANTES de remover
    try {
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, boleto_id, descricao, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "REMOVIDO - Boleto #{$boleto['numero_boleto']} - {$boleto['aluno_nome']} - Motivo: " . substr($motivo, 0, 200);
        
        $stmt->execute([
            'removido',
            $_SESSION['admin_id'],
            $boletoId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
        // Continua mesmo com erro no log
    }
    
    // Remove registros relacionados primeiro
    
    // 1. Remove outros logs do boleto (exceto o que acabamos de criar)
    try {
        $stmt = $db->prepare("DELETE FROM logs WHERE boleto_id = ? AND tipo != 'removido'");
        $stmt->execute([$boletoId]);
        $logsRemovidos = $stmt->rowCount();
    } catch (Exception $e) {
        $logsRemovidos = 0;
        error_log("Erro ao remover logs: " . $e->getMessage());
    }
    
    // 2. Remove PIX se tabela existir
    try {
        $stmt = $db->prepare("DELETE FROM pix_gerados WHERE boleto_id = ?");
        $stmt->execute([$boletoId]);
        $pixRemovidos = $stmt->rowCount();
    } catch (Exception $e) {
        $pixRemovidos = 0;
        // Tabela pode não existir
    }
    
    // 3. Remove o boleto principal
    $stmt = $db->prepare("DELETE FROM boletos WHERE id = ?");
    $stmt->execute([$boletoId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Falha ao remover boleto do banco');
    }
    
    // Commit
    $db->commit();
    
    // Resposta de sucesso
    echo json_encode([
        'success' => true,
        'message' => 'Boleto removido com sucesso!',
        'boleto' => [
            'id' => $boletoId,
            'numero_boleto' => $boleto['numero_boleto'],
            'aluno_nome' => $boleto['aluno_nome'],
            'curso_nome' => $boleto['curso_nome'],
            'valor' => $boleto['valor'],
            'motivo_remocao' => $motivo
        ],
        'arquivos' => [
            'pdf_removido' => $arquivoRemovido,
            'arquivo_original' => $boleto['arquivo_pdf'] ?? null
        ],
        'registros' => [
            'logs_removidos' => $logsRemovidos,
            'pix_removidos' => $pixRemovidos
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    error_log("Boleto {$boletoId} removido com sucesso");
    
} catch (Exception $e) {
    // Rollback
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("ERRO na remoção: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    $httpCode = 400;
    if (strpos($e->getMessage(), 'não encontrado') !== false) {
        $httpCode = 404;
    } elseif (strpos($e->getMessage(), 'não tem permissão') !== false) {
        $httpCode = 403;
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $httpCode,
        'debug' => [
            'admin_id' => $_SESSION['admin_id'] ?? null,
            'boleto_id' => $boletoId ?? null,
            'motivo_length' => isset($motivo) ? strlen($motivo) : 0
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>