<?php
/**
 * ARQUIVO: admin/api/listar-documentos-pendentes.php
 * API para listar documentos pendentes de aprovação
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
    
    $db = (new Database())->getConnection();
    $tiposDocumentos = DocumentosService::getTiposDocumentos();
    
    // Parâmetros de filtro
    $status = $_GET['status'] ?? 'pendente';
    $limite = min(50, max(10, intval($_GET['limite'] ?? 20)));
    $pagina = max(1, intval($_GET['pagina'] ?? 1));
    $offset = ($pagina - 1) * $limite;
    
    // Query principal
    $whereClause = "WHERE d.status = ?";
    $params = [$status];
    
    // Filtro por tipo de documento
    if (!empty($_GET['tipo'])) {
        $whereClause .= " AND d.tipo_documento = ?";
        $params[] = $_GET['tipo'];
    }
    
    // Filtro por data
    if (!empty($_GET['data_inicio'])) {
        $whereClause .= " AND DATE(d.data_upload) >= ?";
        $params[] = $_GET['data_inicio'];
    }
    
    if (!empty($_GET['data_fim'])) {
        $whereClause .= " AND DATE(d.data_upload) <= ?";
        $params[] = $_GET['data_fim'];
    }
    
    // Busca documentos
    $stmt = $db->prepare("
        SELECT 
            d.*,
            a.nome as aluno_nome,
            a.cpf as aluno_cpf,
            a.email as aluno_email,
            a.subdomain as aluno_polo,
            admin_aprovador.nome as aprovado_por_nome
        FROM alunos_documentos d
        INNER JOIN alunos a ON d.aluno_id = a.id
        LEFT JOIN administradores admin_aprovador ON d.aprovado_por = admin_aprovador.id
        {$whereClause}
        ORDER BY d.data_upload DESC
        LIMIT ? OFFSET ?
    ");
    
    $params[] = $limite;
    $params[] = $offset;
    $stmt->execute($params);
    $documentos = $stmt->fetchAll();
    
    // Conta total
    $stmtCount = $db->prepare("
        SELECT COUNT(*) as total
        FROM alunos_documentos d
        INNER JOIN alunos a ON d.aluno_id = a.id
        {$whereClause}
    ");
    $stmtCount->execute(array_slice($params, 0, -2));
    $total = $stmtCount->fetch()['total'];
    
    // Formata documentos
    $documentosFormatados = [];
    foreach ($documentos as $doc) {
        $tipoInfo = $tiposDocumentos[$doc['tipo_documento']] ?? ['nome' => $doc['tipo_documento'], 'icone' => 'fas fa-file'];
        
        $documentosFormatados[] = [
            'id' => $doc['id'],
            'tipo_documento' => $doc['tipo_documento'],
            'tipo_info' => $tipoInfo,
            'nome_original' => $doc['nome_original'],
            'tamanho_formatado' => formatarTamanho($doc['tamanho_arquivo']),
            'data_upload' => $doc['data_upload'],
            'data_upload_formatada' => date('d/m/Y H:i', strtotime($doc['data_upload'])),
            'status' => $doc['status'],
            'observacoes' => $doc['observacoes'],
            'aluno' => [
                'id' => $doc['aluno_id'],
                'nome' => $doc['aluno_nome'],
                'cpf' => $doc['aluno_cpf'],
                'email' => $doc['aluno_email'],
                'polo' => $doc['aluno_polo']
            ],
            'aprovacao' => [
                'aprovado_por' => $doc['aprovado_por_nome'],
                'data_aprovacao' => $doc['data_aprovacao'],
                'data_aprovacao_formatada' => $doc['data_aprovacao'] ? 
                    date('d/m/Y H:i', strtotime($doc['data_aprovacao'])) : null
            ],
            'url_download' => "/api/download-documento.php?id={$doc['id']}"
        ];
    }
    
    echo json_encode([
        'success' => true,
        'documentos' => $documentosFormatados,
        'total' => intval($total),
        'pagina' => $pagina,
        'limite' => $limite,
        'total_paginas' => ceil($total / $limite),
        'filtros' => [
            'status' => $status,
            'tipo' => $_GET['tipo'] ?? null,
            'data_inicio' => $_GET['data_inicio'] ?? null,
            'data_fim' => $_GET['data_fim'] ?? null
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro ao listar documentos pendentes: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor'
    ]);
}

/**
 * Formata tamanho do arquivo
 */
function formatarTamanho($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}
?>