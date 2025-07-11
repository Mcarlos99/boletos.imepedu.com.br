<?php

/**
 * ARQUIVO: admin/api/estatisticas-documentos.php
 * API para estatísticas de documentos
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    require_once '../../src/DocumentosService.php';
    require_once '../../config/database.php';
    
    $documentosService = new DocumentosService();
    $estatisticas = $documentosService->getEstatisticasDocumentos();
    
    // Busca alunos com documentos completos
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_alunos,
            COUNT(CASE WHEN documentos_completos = 1 THEN 1 END) as alunos_documentos_completos,
            COUNT(CASE WHEN documentos_completos = 0 THEN 1 END) as alunos_documentos_incompletos
        FROM alunos
    ");
    $stmt->execute();
    $statusAlunos = $stmt->fetch();
    
    // Documentos por status nos últimos 30 dias
    $stmt = $db->prepare("
        SELECT 
            DATE(data_upload) as data,
            COUNT(*) as quantidade,
            status
        FROM alunos_documentos 
        WHERE data_upload >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(data_upload), status
        ORDER BY data DESC
    ");
    $stmt->execute();
    $documentosPorDia = $stmt->fetchAll();
    
    // Tipos de documentos mais enviados
    $stmt = $db->prepare("
        SELECT 
            tipo_documento,
            COUNT(*) as quantidade,
            COUNT(CASE WHEN status = 'aprovado' THEN 1 END) as aprovados,
            COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
            COUNT(CASE WHEN status = 'rejeitado' THEN 1 END) as rejeitados
        FROM alunos_documentos
        GROUP BY tipo_documento
        ORDER BY quantidade DESC
    ");
    $stmt->execute();
    $tiposMaisEnviados = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'estatisticas' => $estatisticas,
        'status_alunos' => $statusAlunos,
        'documentos_por_dia' => $documentosPorDia,
        'tipos_mais_enviados' => $tiposMaisEnviados,
        'gerado_em' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de documentos: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor'
    ]);
}
?>