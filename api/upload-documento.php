<?php
/**
 * Sistema de Boletos IMEPEDU - API Upload de Documentos
 * Arquivo: api/upload-documento.php
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verifica se aluno está logado
if (!isset($_SESSION['aluno_cpf']) || !isset($_SESSION['aluno_id'])) {
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
    require_once '../src/DocumentosService.php';
    
    $documentosService = new DocumentosService();
    $alunoId = $_SESSION['aluno_id'];
    
    // Valida parâmetros
    $tipoDocumento = $_POST['tipo_documento'] ?? '';
    
    if (empty($tipoDocumento)) {
        throw new Exception('Tipo de documento é obrigatório');
    }
    
    // Verifica se tipo é válido
    $tiposValidos = array_keys(DocumentosService::getTiposDocumentos());
    if (!in_array($tipoDocumento, $tiposValidos)) {
        throw new Exception('Tipo de documento inválido');
    }
    
    // Verifica se arquivo foi enviado
    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Nenhum arquivo foi enviado');
    }
    
    // Faz upload
    $resultado = $documentosService->uploadDocumento($alunoId, $tipoDocumento, $_FILES['arquivo']);
    
    if ($resultado['success']) {
        // Busca status atualizado dos documentos
        $status = $documentosService->getStatusDocumentosAluno($alunoId);
        $resultado['status_documentos'] = $status;
        
        error_log("Upload realizado: Aluno {$alunoId}, Documento {$tipoDocumento}");
    }
    
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro no upload de documento: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>