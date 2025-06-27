<?php
/**
 * Teste Simples de Busca de Cursos
 * Arquivo: teste_busca_simples.php (colocar na raiz para testar)
 */

session_start();

// Simula login de admin
$_SESSION['admin_id'] = 1;

header('Content-Type: application/json');

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

try {
    $polo = $_GET['polo'] ?? 'breubranco.imepedu.com.br';
    
    echo json_encode([
        'teste' => 'inicio',
        'polo' => $polo,
        'etapa' => 'verificando_configuracao'
    ]);
    
    // Verifica configuração básica
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception("Polo não configurado: {$polo}");
    }
    
    $token = MoodleConfig::getToken($polo);
    if (!$token || $token === 'x') {
        throw new Exception("Token não configurado para {$polo}");
    }
    
    echo json_encode([
        'teste' => 'configuracao_ok',
        'polo' => $polo,
        'token_configurado' => true,
        'etapa' => 'conectando_moodle'
    ]);
    
    // Testa conexão
    $moodleAPI = new MoodleAPI($polo);
    $testeConexao = $moodleAPI->testarConexao();
    
    if (!$testeConexao['sucesso']) {
        throw new Exception("Erro ao conectar: " . $testeConexao['erro']);
    }
    
    echo json_encode([
        'teste' => 'conexao_ok',
        'polo' => $polo,
        'moodle_info' => $testeConexao,
        'etapa' => 'buscando_cursos'
    ]);
    
    // Busca cursos usando método público
    $todosCursos = $moodleAPI->listarTodosCursos();
    
    echo json_encode([
        'teste' => 'busca_completa',
        'polo' => $polo,
        'total_encontrados' => count($todosCursos),
        'cursos' => array_slice($todosCursos, 0, 10), // Primeiros 10 para ver
        'todos_cursos' => $todosCursos,
        'sucesso' => true
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'teste' => 'erro',
        'erro' => $e->getMessage(),
        'polo' => $polo ?? 'não informado',
        'sucesso' => false
    ]);
}
?>