<?php
/**
 * Sistema de Boletos IMEPEDU - API Sincronizar Polo Específico
 * Arquivo: admin/api/sincronizar-polo.php
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

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
    $polo = $input['polo'] ?? null;
    
    if (!$polo) {
        throw new Exception('Polo é obrigatório');
    }
    
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception('Polo não é válido');
    }
    
    if (!MoodleConfig::isActiveSubdomain($polo)) {
        throw new Exception('Polo não está ativo');
    }
    
    $alunoService = new AlunoService();
    $moodleAPI = new MoodleAPI($polo);
    
    // Testa conectividade
    $teste = $moodleAPI->testarConexao();
    if (!$teste['sucesso']) {
        throw new Exception('Falha na conexão com o polo: ' . $teste['erro']);
    }
    
    // Busca alunos do polo
    $alunosMoodle = $moodleAPI->buscarTodosAlunosDoMoodle();
    
    $alunosNovos = 0;
    $alunosAtualizados = 0;
    $alunosComErro = 0;
    
    foreach ($alunosMoodle as $alunoMoodle) {
        try {
            $alunoExistente = $alunoService->buscarAlunoPorCPFESubdomain(
                $alunoMoodle['cpf'], 
                $polo
            );
            
            if ($alunoExistente) {
                $alunoService->salvarOuAtualizarAluno($alunoMoodle);
                $alunosAtualizados++;
            } else {
                $alunoService->salvarOuAtualizarAluno($alunoMoodle);
                $alunosNovos++;
            }
            
        } catch (Exception $e) {
            $alunosComErro++;
            error_log("Erro ao processar aluno {$alunoMoodle['nome']}: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Polo {$polo} sincronizado com sucesso",
        'resultado' => [
            'polo' => $polo,
            'alunos_encontrados' => count($alunosMoodle),
            'alunos_novos' => $alunosNovos,
            'alunos_atualizados' => $alunosAtualizados,
            'alunos_com_erro' => $alunosComErro
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao sincronizar polo: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>