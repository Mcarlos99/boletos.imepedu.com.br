<?php
/**
 * Sistema de Boletos IMEPEDU - API Download de Documentos
 * Arquivo: api/download-documento.php
 */

session_start();

// Verifica autenticação (aluno ou admin)
$isAdmin = isset($_SESSION['admin_id']);
$isAluno = isset($_SESSION['aluno_cpf']) && isset($_SESSION['aluno_id']);

if (!$isAdmin && !$isAluno) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
    $documentoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$documentoId) {
        throw new Exception('ID do documento é obrigatório');
    }
    
    // Determina usuário para verificação de permissão
    $usuarioId = $isAdmin ? $_SESSION['admin_id'] : $_SESSION['aluno_id'];
    
    // Faz download
    $arquivo = $documentosService->downloadDocumento($documentoId, $usuarioId, $isAdmin);
    
    // Define headers para download
    header('Content-Type: ' . $arquivo['tipo_mime']);
    header('Content-Length: ' . $arquivo['tamanho']);
    header('Content-Disposition: attachment; filename="' . $arquivo['nome_original'] . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Envia arquivo
    readfile($arquivo['caminho']);
    
} catch (Exception $e) {
    error_log("Erro no download de documento: " . $e->getMessage());
    
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>