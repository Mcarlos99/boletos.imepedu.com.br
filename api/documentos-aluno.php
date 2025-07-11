<?php
/**
 * Sistema de Boletos IMEPEDU - API Listar Documentos do Aluno
 * Arquivo: api/documentos-aluno.php
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

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
    
    // Determina qual aluno buscar
    $alunoId = null;
    
    if ($isAdmin) {
        // Admin pode especificar aluno_id via GET
        $alunoId = filter_input(INPUT_GET, 'aluno_id', FILTER_VALIDATE_INT);
        if (!$alunoId) {
            throw new Exception('ID do aluno é obrigatório para administradores');
        }
    } else {
        // Aluno só pode ver seus próprios documentos
        $alunoId = $_SESSION['aluno_id'];
    }
    
    // Lista documentos do aluno
    $documentos = $documentosService->listarDocumentosAluno($alunoId);
    $status = $documentosService->getStatusDocumentosAluno($alunoId);
    $tiposDocumentos = DocumentosService::getTiposDocumentos();
    
    // Adiciona informações sobre documentos faltando
    $documentosFaltando = [];
    foreach ($tiposDocumentos as $tipo => $info) {
        if (!isset($documentos[$tipo])) {
            $documentosFaltando[$tipo] = $info;
        }
    }
    
    echo json_encode([
        'success' => true,
        'documentos' => $documentos,
        'documentos_faltando' => $documentosFaltando,
        'tipos_documentos' => $tiposDocumentos,
        'status' => $status,
        'aluno_id' => $alunoId
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro ao listar documentos: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>