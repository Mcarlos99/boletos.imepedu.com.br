<?php
/**
 * ARQUIVO: admin/api/atualizar-documento.php
 * API para aprovar/rejeitar documentos
 */

session_start();

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    require_once '../../src/DocumentosService.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $documentoId = filter_var($input['documento_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $input['status'] ?? '';
    $observacoes = trim($input['observacoes'] ?? '');
    
    if (!$documentoId) {
        throw new Exception('ID do documento é obrigatório');
    }
    
    if (!in_array($status, ['aprovado', 'rejeitado'])) {
        throw new Exception('Status inválido');
    }
    
    if ($status === 'rejeitado' && empty($observacoes)) {
        throw new Exception('Observações são obrigatórias para rejeição');
    }
    
    $documentosService = new DocumentosService();
    $adminId = $_SESSION['admin_id'];
    
    $sucesso = $documentosService->aprovarRejeitarDocumento(
        $documentoId, 
        $status, 
        $adminId, 
        $observacoes
    );
    
    if ($sucesso) {
        echo json_encode([
            'success' => true,
            'message' => "Documento {$status} com sucesso!"
        ]);
        
        error_log("Admin {$adminId} {$status} documento {$documentoId}");
    } else {
        throw new Exception('Erro ao atualizar documento');
    }
    
} catch (Exception $e) {
    error_log("Erro ao atualizar documento: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}


?>