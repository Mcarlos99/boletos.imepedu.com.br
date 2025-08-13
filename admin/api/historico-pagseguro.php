<?php
/**
 * API para buscar histórico de links PagSeguro
 * Arquivo: admin/api/historico-pagseguro.php
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

require_once '../../config/database.php';
require_once '../../src/AlunoService.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['cpf']) || !isset($input['polo'])) {
        throw new Exception('CPF e polo são obrigatórios');
    }
    
    $cpf = preg_replace('/[^0-9]/', '', $input['cpf']);
    $polo = $input['polo'];
    
    if (strlen($cpf) !== 11) {
        throw new Exception('CPF inválido');
    }
    
    // Buscar aluno
    $alunoService = new AlunoService();
    $aluno = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $polo);
    
    if (!$aluno) {
        echo json_encode([
            'success' => true,
            'historico' => [],
            'message' => 'Aluno não encontrado'
        ]);
        exit;
    }
    
    // Buscar histórico de links PagSeguro
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ? 
        AND b.tipo_boleto = 'pagseguro_link'
        AND c.subdomain = ?
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$aluno['id'], $polo]);
    $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'historico' => $historico,
        'aluno_nome' => $aluno['nome']
    ]);
    
} catch (Exception $e) {
    error_log("ERRO no histórico PagSeguro: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>