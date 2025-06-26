<?php
/**
 * Debug específico da comunicação com Moodle
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/moodle.php';

echo "<h1>Debug Detalhado - API Moodle</h1>";
echo "<style>body{font-family:Arial;} .ok{color:green;} .error{color:red;} .warning{color:orange;} pre{background:#f5f5f5;padding:10px;border:1px solid #ddd;max-height:400px;overflow-y:scroll;}</style>";

$cpf = '03183924536';
$subdomain = 'breubranco.imepedu.com.br';

echo "<h3>Parâmetros</h3>";
echo "CPF: {$cpf}<br>";
echo "Subdomínio: {$subdomain}<br><br>";

// 1. Verificar configurações
echo "<h3>1. Verificar Configurações</h3>";
$token = MoodleConfig::getToken($subdomain);
$baseUrl = MoodleConfig::getMoodleUrl($subdomain);

echo "Token: " . ($token ? substr($token, 0, 10) . "..." : "NÃO CONFIGURADO") . "<br>";
echo "URL Base: {$baseUrl}<br>";
echo "Subdomínio ativo: " . (MoodleConfig::isActiveSubdomain($subdomain) ? "Sim" : "Não") . "<br><br>";

if (!$token) {
    echo "<span class='error'>✗ ERRO: Token não configurado!</span><br>";
    echo "<h4>Solução:</h4>";
    echo "<ol>";
    echo "<li>Acesse o Moodle como administrador</li>";
    echo "<li>Vá em Administração > Plugins > Web services > Gerenciar tokens</li>";
    echo "<li>Crie um token para o usuário da API</li>";
    echo "<li>Atualize o arquivo config/moodle.php com o token correto</li>";
    echo "</ol>";
    exit;
}

// 2. Teste de conectividade básica
echo "<h3>2. Teste de Conectividade Básica</h3>";

function debugRequest($url, $params, $description) {
    echo "<h4>{$description}</h4>";
    
    $queryString = http_build_query($params);
    $fullUrl = $url . '?' . $queryString;
    
    echo "URL: <small>" . htmlspecialchars($fullUrl) . "</small><br>";
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'user_agent' => 'IMED-Debug/1.0',
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
    
    echo "Fazendo requisição...<br>";
    
    $startTime = microtime(true);
    $response = @file_get_contents($fullUrl, false, $context);
    $endTime = microtime(true);
    
    $responseTime = round(($endTime - $startTime) * 1000, 2);
    echo "Tempo de resposta: {$responseTime}ms<br>";
    
    if ($response === false) {
        $error = error_get_last();
        echo "<span class='error'>✗ Falha na requisição</span><br>";
        echo "Erro: " . ($error['message'] ?? 'Desconhecido') . "<br>";
        
        // Verifica se é problema de SSL
        if (strpos($error['message'] ?? '', 'SSL') !== false) {
            echo "<span class='warning'>⚠ Possível problema de SSL. Tentando sem verificação...</span><br>";
        }
        
        return false;
    }
    
    echo "<span class='ok'>✓ Resposta recebida</span><br>";
    echo "Tamanho da resposta: " . strlen($response) . " bytes<br>";
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<span class='error'>✗ Erro ao decodificar JSON: " . json_last_error_msg() . "</span><br>";
        echo "<h4>Resposta Raw:</h4>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
        return false;
    }
    
    if (isset($data['errorcode'])) {
        echo "<span class='error'>✗ Erro do Moodle</span><br>";
        echo "Código: " . $data['errorcode'] . "<br>";
        echo "Mensagem: " . $data['message'] . "<br>";
        echo "Debug Info: " . ($data['debuginfo'] ?? 'N/A') . "<br>";
        return false;
    }
    
    echo "<span class='ok'>✓ Resposta válida</span><br>";
    echo "<h4>Resposta (primeiros 500 caracteres):</h4>";
    echo "<pre>" . htmlspecialchars(substr($response, 0, 500)) . (strlen($response) > 500 ? '...' : '') . "</pre>";
    
    return $data;
}

// Teste 1: Informações do site
$siteInfoParams = [
    'wstoken' => $token,
    'wsfunction' => 'core_webservice_get_site_info',
    'moodlewsrestformat' => 'json'
];

$siteInfo = debugRequest($baseUrl, $siteInfoParams, "Teste 1: Informações do Site");

if (!$siteInfo) {
    echo "<br><span class='error'>✗ Falha no teste básico de conectividade</span><br>";
    echo "<h4>Possíveis causas:</h4>";
    echo "<ul>";
    echo "<li>Token inválido ou expirado</li>";
    echo "<li>Web Services não habilitados no Moodle</li>";
    echo "<li>Firewall bloqueando a conexão</li>";
    echo "<li>SSL/HTTPS mal configurado</li>";
    echo "<li>URL incorreta</li>";
    echo "</ul>";
    exit;
}

echo "<br>";

// Teste 2: Busca por CPF no idnumber
$searchParams = [
    'wstoken' => $token,
    'wsfunction' => 'core_user_get_users',
    'moodlewsrestformat' => 'json',
    'criteria[0][key]' => 'idnumber',
    'criteria[0][value]' => $cpf
];

$userResult = debugRequest($baseUrl, $searchParams, "Teste 2: Busca por CPF no campo idnumber");

if ($userResult) {
    if (!empty($userResult['users'])) {
        echo "<span class='ok'>✓ Usuário encontrado!</span><br>";
        $user = $userResult['users'][0];
        echo "<h4>Dados do usuário:</h4>";
        echo "<pre>";
        print_r($user);
        echo "</pre>";
        
        // Teste 3: Buscar cursos do usuário
        echo "<br>";
        $coursesParams = [
            'wstoken' => $token,
            'wsfunction' => 'core_enrol_get_users_courses',
            'moodlewsrestformat' => 'json',
            'userid' => $user['id']
        ];
        
        $coursesResult = debugRequest($baseUrl, $coursesParams, "Teste 3: Buscar cursos do usuário");
        
        if ($coursesResult) {
            echo "<span class='ok'>✓ Cursos obtidos</span><br>";
            echo "<h4>Cursos encontrados:</h4>";
            echo "<pre>";
            print_r($coursesResult);
            echo "</pre>";
        }
        
    } else {
        echo "<span class='error'>✗ Usuário não encontrado</span><br>";
        echo "<h4>Verificações necessárias:</h4>";
        echo "<ul>";
        echo "<li>Confirme se existe um usuário com idnumber = '{$cpf}' no Moodle</li>";
        echo "<li>Verifique se o usuário não está suspenso</li>";
        echo "<li>Confirme se o campo idnumber está preenchido corretamente</li>";
        echo "</ul>";
    }
}

echo "<br>";

// Teste 4: Verificar funções disponíveis
if (isset($siteInfo['functions']) && is_array($siteInfo['functions'])) {
    echo "<h3>3. Funções Web Service Disponíveis</h3>";
    $requiredFunctions = [
        'core_webservice_get_site_info',
        'core_user_get_users',
        'core_enrol_get_users_courses',
        'core_user_get_users_by_field'
    ];
    
    $availableFunctions = array_column($siteInfo['functions'], 'name');
    
    foreach ($requiredFunctions as $func) {
        echo "Função {$func}: ";
        if (in_array($func, $availableFunctions)) {
            echo "<span class='ok'>✓ Disponível</span>";
        } else {
            echo "<span class='error'>✗ Não disponível</span>";
        }
        echo "<br>";
    }
} else {
    echo "<h3>3. Funções Web Service</h3>";
    echo "<span class='warning'>⚠ Lista de funções não disponível</span><br>";
}

echo "<br>";

// Teste 5: Verificar permissões do usuário da API
echo "<h3>4. Informações do Usuário da API</h3>";
if (isset($siteInfo['username'])) {
    echo "Usuário: " . $siteInfo['username'] . "<br>";
    echo "ID: " . ($siteInfo['userid'] ?? 'N/A') . "<br>";
    echo "Nome completo: " . ($siteInfo['userfullname'] ?? 'N/A') . "<br>";
} else {
    echo "<span class='warning'>⚠ Informações do usuário não disponíveis</span><br>";
}

echo "<br>";

// Instruções para correção
echo "<h3>5. Próximos Passos</h3>";
echo "<div class='info'>";
echo "<h4>Se chegou até aqui sem erros:</h4>";
echo "<p>A comunicação com o Moodle está funcionando. O erro pode estar na classe MoodleAPI.php.</p>";

echo "<h4>Para corrigir, verifique:</h4>";
echo "<ol>";
echo "<li><strong>No arquivo src/MoodleAPI.php</strong>, adicione mais logs de debug</li>";
echo "<li><strong>Verifique se todas as exceções estão sendo tratadas corretamente</strong></li>";
echo "<li><strong>Confirme se o método buscarAlunoPorCPF está funcionando</strong></li>";
echo "</ol>";

echo "<h4>Teste manual do método:</h4>";
echo "<p>Execute este código para testar o método específico:</p>";
echo "<pre>";
echo "require_once 'src/MoodleAPI.php';\n";
echo "try {\n";
echo "    \$api = new MoodleAPI('{$subdomain}');\n";
echo "    \$result = \$api->buscarAlunoPorCPF('{$cpf}');\n";
echo "    var_dump(\$result);\n";
echo "} catch (Exception \$e) {\n";
echo "    echo 'Erro: ' . \$e->getMessage();\n";
echo "    echo 'Stack: ' . \$e->getTraceAsString();\n";
echo "}";
echo "</pre>";
echo "</div>";

echo "<br><a href='debug_moodle_detalhado.php'>← Executar novamente</a>";
?>