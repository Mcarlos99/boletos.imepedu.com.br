<?php
/**
 * VERSÃO SIMPLIFICADA - api/documentos-aluno.php
 * Remove complexidade que pode causar erro 500
 */

// Ativa exibição de erros temporariamente para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// Verifica autenticação
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
    // Carrega classes necessárias com verificação
    $documentosServicePath = __DIR__ . '/../src/DocumentosService.php';
    if (!file_exists($documentosServicePath)) {
        throw new Exception('DocumentosService não encontrado: ' . $documentosServicePath);
    }
    
    require_once $documentosServicePath;
    
    // Verifica se classe existe
    if (!class_exists('DocumentosService')) {
        throw new Exception('Classe DocumentosService não encontrada após include');
    }
    
    $documentosService = new DocumentosService();
    
    // Determina qual aluno buscar
    $alunoId = null;
    
    if ($isAdmin) {
        $alunoId = filter_input(INPUT_GET, 'aluno_id', FILTER_VALIDATE_INT);
        if (!$alunoId) {
            throw new Exception('ID do aluno é obrigatório para administradores');
        }
    } else {
        $alunoId = $_SESSION['aluno_id'];
    }

    // Busca dados básicos
    $documentos = $documentosService->listarDocumentosAluno($alunoId);
    $tiposDocumentos = DocumentosService::getTiposDocumentos();
    
    // Calcula status manualmente para evitar problemas
    $status = calcularStatusSimples($documentos, $tiposDocumentos);
    
    // Documentos faltando
    $documentosFaltando = [];
    foreach ($tiposDocumentos as $tipo => $info) {
        if (!isset($documentos[$tipo])) {
            $documentosFaltando[$tipo] = $info;
        }
    }

    // Resposta simples
    echo json_encode([
        'success' => true,
        'documentos' => $documentos,
        'documentos_faltando' => $documentosFaltando,
        'tipos_documentos' => $tiposDocumentos,
        'status' => $status,
        'aluno_id' => $alunoId,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro API documentos: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

/**
 * Calcula status de forma simples
 */
function calcularStatusSimples($documentos, $tiposDocumentos) {
    $totalTipos = count($tiposDocumentos);
    $enviados = count($documentos);
    $obrigatoriosEnviados = 0;
    $totalObrigatorios = 0;
    $aprovados = 0;
    $pendentes = 0;
    $rejeitados = 0;
    
    // Conta obrigatórios
    foreach ($tiposDocumentos as $tipo => $info) {
        if ($info['obrigatorio']) {
            $totalObrigatorios++;
            if (isset($documentos[$tipo])) {
                $obrigatoriosEnviados++;
            }
        }
    }
    
    // Conta status
    foreach ($documentos as $doc) {
        switch ($doc['status']) {
            case 'aprovado':
                $aprovados++;
                break;
            case 'rejeitado':
                $rejeitados++;
                break;
            default:
                $pendentes++;
        }
    }
    
    $percentual = $totalObrigatorios > 0 ? 
        round(($obrigatoriosEnviados / $totalObrigatorios) * 100) : 0;
    
    return [
        'total_tipos' => $totalTipos,
        'enviados' => $enviados,
        'obrigatorios_enviados' => $obrigatoriosEnviados,
        'total_obrigatorios' => $totalObrigatorios,
        'aprovados' => $aprovados,
        'pendentes' => $pendentes,
        'rejeitados' => $rejeitados,
        'percentual_completo' => $percentual
    ];
}
?>