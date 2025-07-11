<?php
/**
 * Sistema de Boletos IMEPEDU - API para Atualizar Dados
 * Arquivo: api/atualizar_dados.php
 * 
 * Endpoint para sincronizar dados do aluno com o Moodle
 */

session_start();

// Configura header para JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Verifica se usuário está logado
if (!isset($_SESSION['aluno_cpf'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Usuário não autenticado'
    ]);
    exit;
}

// Verifica método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Inclui arquivos necessários
    require_once '../config/database.php';
    require_once '../config/moodle.php';
    require_once '../src/MoodleAPI.php';
    require_once '../src/AlunoService.php';
    
    $cpf = $_SESSION['aluno_cpf'];
    $subdomain = $_SESSION['subdomain'];
    
    // Log da tentativa
    error_log("API: Atualizando dados do aluno CPF: {$cpf}, Subdomain: {$subdomain}");
    
    // Conecta com a API do Moodle
    $moodleAPI = new MoodleAPI($subdomain);
    // Busca dados no Moodle
    $dadosAluno = $moodleAPI->buscarAlunoPorCPF($cpf);
    
    // Força o subdomínio correto
    if ($dadosAluno) {
        $dadosAluno['subdomain'] = $subdomain;
    }
    
    if (!$dadosAluno) {
        throw new Exception("Aluno não encontrado no sistema do Moodle");
    }
    
    // Atualiza dados no banco local
    $alunoService = new AlunoService();
    $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAluno);
    
    // Atualiza sessão
    $_SESSION['aluno_id'] = $alunoId;
    $_SESSION['aluno_nome'] = $dadosAluno['nome'];
    $_SESSION['aluno_email'] = $dadosAluno['email'];
    
    // Busca cursos atualizados
    $cursosAtualizados = $alunoService->buscarCursosAluno($alunoId, $subdomain);
    
    // Log de sucesso
    error_log("API: Dados atualizados com sucesso. Cursos encontrados: " . count($cursosAtualizados));
    
    echo json_encode([
        'success' => true,
        'message' => 'Dados atualizados com sucesso',
        'data' => [
            'aluno' => [
                'id' => $alunoId,
                'nome' => $dadosAluno['nome'],
                'email' => $dadosAluno['email'],
                'cpf' => $dadosAluno['cpf']
            ],
            'cursos_encontrados' => count($cursosAtualizados),
            'cursos' => $cursosAtualizados,
            'ultima_atualizacao' => date('d/m/Y H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    error_log("API: Erro ao atualizar dados - " . $e->getMessage());
    error_log("API: Stack trace - " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'cpf' => $cpf ?? 'N/A',
            'subdomain' => $subdomain ?? 'N/A',
            'error_details' => $e->getMessage()
        ]
    ]);
}
?>