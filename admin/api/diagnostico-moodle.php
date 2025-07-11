<?php
/**
 * Sistema de Boletos IMEPEDU - API Diagnóstico Moodle CORRIGIDA
 * Arquivo: admin/api/diagnostico-moodle.php
 */

// Headers primeiro, antes de qualquer output
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Inicia sessão
session_start();

// Função para enviar resposta JSON e sair
function sendJsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificações de segurança
if (!isset($_SESSION['admin_id'])) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Acesso não autorizado'
    ], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse([
        'success' => false,
        'message' => 'Método não permitido'
    ], 405);
}

try {
    // Log do início
    error_log("DIAGNÓSTICO: Iniciado pelo admin " . $_SESSION['admin_id']);
    
    // Includes com verificação
    $configPath = __DIR__ . '/../../config/moodle.php';
    $moodleApiPath = __DIR__ . '/../../src/MoodleAPI.php';
    
    if (!file_exists($configPath)) {
        throw new Exception('Arquivo de configuração do Moodle não encontrado');
    }
    
    if (!file_exists($moodleApiPath)) {
        throw new Exception('Classe MoodleAPI não encontrada');
    }
    
    require_once $configPath;
    require_once $moodleApiPath;
    
    // Verifica se as classes existem
    if (!class_exists('MoodleConfig')) {
        throw new Exception('Classe MoodleConfig não foi carregada');
    }
    
    // Obtém polos ativos
    $polosAtivos = MoodleConfig::getActiveSubdomains();
    
    if (empty($polosAtivos)) {
        sendJsonResponse([
            'success' => true,
            'diagnosticos' => [],
            'total_polos' => 0,
            'message' => 'Nenhum polo ativo configurado'
        ]);
    }
    
    $diagnosticos = [];
    $totalErros = 0;
    
    foreach ($polosAtivos as $polo) {
        $diagnostico = [
            'subdomain' => $polo,
            'token_configured' => false,
            'connection_test' => false,
            'functions_available' => [],
            'errors' => [],
            'response_time' => null,
            'site_name' => '',
            'moodle_version' => ''
        ];
        
        try {
            error_log("DIAGNÓSTICO: Testando polo {$polo}");
            
            // Verifica se tem token
            $token = MoodleConfig::getToken($polo);
            $diagnostico['token_configured'] = !empty($token) && $token !== 'x';
            
            if (!$diagnostico['token_configured']) {
                $diagnostico['errors'][] = 'Token não configurado ou inválido';
                $totalErros++;
                $diagnosticos[] = $diagnostico;
                continue;
            }
            
            // Testa conexão básica
            $startTime = microtime(true);
            
            // Verifica se MoodleAPI pode ser instanciada
            if (!class_exists('MoodleAPI')) {
                throw new Exception('Classe MoodleAPI não disponível');
            }
            
            $moodleAPI = new MoodleAPI($polo);
            
            // Testa método de diagnóstico se existir
            if (method_exists($moodleAPI, 'diagnosticar')) {
                $resultadoDiagnostico = $moodleAPI->diagnosticar();
                $diagnostico = array_merge($diagnostico, $resultadoDiagnostico);
            } else {
                // Testa conexão manual
                $testeConexao = testarConexaoManual($polo, $token);
                $diagnostico = array_merge($diagnostico, $testeConexao);
            }
            
            $diagnostico['response_time'] = round((microtime(true) - $startTime) * 1000, 2) . 'ms';
            
            error_log("DIAGNÓSTICO: Polo {$polo} - " . ($diagnostico['connection_test'] ? 'OK' : 'FALHA'));
            
        } catch (Exception $e) {
            $diagnostico['errors'][] = $e->getMessage();
            $totalErros++;
            error_log("DIAGNÓSTICO: ERRO no polo {$polo} - " . $e->getMessage());
        }
        
        $diagnosticos[] = $diagnostico;
    }
    
    // Prepara resposta final
    $response = [
        'success' => true,
        'diagnosticos' => $diagnosticos,
        'total_polos' => count($polosAtivos),
        'total_erros' => $totalErros,
        'executado_em' => date('Y-m-d H:i:s'),
        'admin_id' => $_SESSION['admin_id']
    ];
    
    error_log("DIAGNÓSTICO: Concluído - {$totalErros} erros em " . count($polosAtivos) . " polos");
    
    sendJsonResponse($response);
    
} catch (Exception $e) {
    error_log("DIAGNÓSTICO: ERRO CRÍTICO - " . $e->getMessage());
    
    sendJsonResponse([
        'success' => false,
        'message' => 'Erro no diagnóstico: ' . $e->getMessage(),
        'error_details' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], 500);
}

/**
 * Testa conexão manual com o Moodle
 */
function testarConexaoManual($subdomain, $token) {
    $resultado = [
        'connection_test' => false,
        'functions_available' => [],
        'errors' => [],
        'site_name' => '',
        'moodle_version' => ''
    ];
    
    try {
        // Monta URL para teste
        $url = "https://{$subdomain}/webservice/rest/server.php";
        $params = [
            'wstoken' => $token,
            'wsfunction' => 'core_webservice_get_site_info',
            'moodlewsrestformat' => 'json'
        ];
        
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;
        
        // Configurações do contexto
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'user_agent' => 'IMEPEDU-Boletos-Diagnostico/1.0',
                'header' => [
                    'Accept: application/json',
                    'Cache-Control: no-cache'
                ]
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        // Faz a requisição
        $response = file_get_contents($fullUrl, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            throw new Exception('Falha na conexão HTTP: ' . ($error['message'] ?? 'Erro desconhecido'));
        }
        
        // Decodifica resposta
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Resposta inválida do Moodle: ' . json_last_error_msg());
        }
        
        // Verifica se há erro na resposta
        if (isset($data['errorcode'])) {
            throw new Exception("Erro do Moodle: {$data['message']} (Código: {$data['errorcode']})");
        }
        
        // Sucesso!
        $resultado['connection_test'] = true;
        $resultado['site_name'] = $data['sitename'] ?? '';
        $resultado['moodle_version'] = $data['release'] ?? '';
        
        // Lista funções disponíveis
        if (isset($data['functions']) && is_array($data['functions'])) {
            $resultado['functions_available'] = array_slice(
                array_column($data['functions'], 'name'), 
                0, 
                10
            ); // Limita a 10 funções para não sobrecarregar
        }
        
    } catch (Exception $e) {
        $resultado['errors'][] = $e->getMessage();
    }
    
    return $resultado;
}

/**
 * Função para validar configuração básica
 */
function validarConfiguracaoBasica() {
    $erros = [];
    
    // Verifica se as classes necessárias existem
    if (!class_exists('MoodleConfig')) {
        $erros[] = 'Classe MoodleConfig não encontrada';
    }
    
    // Verifica se há polos configurados
    try {
        $polos = MoodleConfig::getAllSubdomains();
        if (empty($polos)) {
            $erros[] = 'Nenhum polo configurado';
        }
    } catch (Exception $e) {
        $erros[] = 'Erro ao obter polos: ' . $e->getMessage();
    }
    
    // Verifica se o PHP tem as extensões necessárias
    $extensoesNecessarias = ['curl', 'json', 'openssl'];
    foreach ($extensoesNecessarias as $ext) {
        if (!extension_loaded($ext)) {
            $erros[] = "Extensão PHP '{$ext}' não está carregada";
        }
    }
    
    return $erros;
}

// Log de execução
error_log("API diagnostico-moodle.php executada com sucesso");
?>
?>