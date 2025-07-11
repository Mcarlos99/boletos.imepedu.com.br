<?php
/**
 * Sistema de Boletos IMEPEDU - API para Sincronizar Aluno Individual
 * Arquivo: admin/api/sincronizar-aluno.php
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

try {
    require_once '../../config/database.php';
    require_once '../../config/moodle.php';
    require_once '../../src/MoodleAPI.php';
    require_once '../../src/AlunoService.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    $alunoId = $input['aluno_id'] ?? null;
    
    if (!$alunoId) {
        throw new Exception('ID do aluno é obrigatório');
    }
    
    $db = (new Database())->getConnection();
    $alunoService = new AlunoService();
    
    // Busca o aluno no banco
    $aluno = $alunoService->buscarAlunoPorId($alunoId);
    
    if (!$aluno) {
        throw new Exception('Aluno não encontrado');
    }
    
    // Sincroniza com o Moodle
    $moodleAPI = new MoodleAPI($aluno['subdomain']);
    $alunoMoodle = $moodleAPI->buscarAlunoPorCPF($aluno['cpf']);
    
    if ($alunoMoodle) {
        $alunoService->salvarOuAtualizarAluno($alunoMoodle);
        
        echo json_encode([
            'success' => true,
            'message' => 'Aluno sincronizado com sucesso',
            'aluno' => [
                'id' => $aluno['id'],
                'nome' => $alunoMoodle['nome'],
                'cpf' => $alunoMoodle['cpf'],
                'total_cursos' => count($alunoMoodle['cursos'])
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Aluno não encontrado no Moodle'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Erro ao sincronizar aluno individual: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>