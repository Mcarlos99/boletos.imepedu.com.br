<?php
/**
 * TESTE SIMPLES - admin/api/teste-diagnostico.php
 * Use este arquivo para testar se o diagnóstico está funcionando
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Simula admin logado para teste
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['admin_id'] = 1; // Para teste apenas
}

try {
    // Testa se os arquivos existem
    $moodleConfigPath = __DIR__ . '/../../config/moodle.php';
    $moodleApiPath = __DIR__ . '/../../src/MoodleAPI.php';
    
    echo json_encode([
        'teste_arquivos' => [
            'moodle_config_exists' => file_exists($moodleConfigPath),
            'moodle_api_exists' => file_exists($moodleApiPath),
            'moodle_config_path' => $moodleConfigPath,
            'moodle_api_path' => $moodleApiPath
        ]
    ]);
    
    if (!file_exists($moodleConfigPath)) {
        throw new Exception('Arquivo moodle.php não encontrado');
    }
    
    require_once $moodleConfigPath;
    
    if (!class_exists('MoodleConfig')) {
        throw new Exception('Classe MoodleConfig não carregada');
    }
    
    $polos = MoodleConfig::getActiveSubdomains();
    
    echo json_encode([
        'success' => true,
        'polos_ativos' => $polos,
        'total_polos' => count($polos),
        'php_version' => PHP_VERSION,
        'extensions' => [
            'curl' => extension_loaded('curl'),
            'json' => extension_loaded('json'),
            'openssl' => extension_loaded('openssl')
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
?>