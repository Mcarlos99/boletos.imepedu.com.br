<?php
/**
 * Debug detalhado para encontrar usuários no Moodle
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/moodle.php';

echo "<h1>Debug - Busca de Usuário</h1>";
echo "<style>body{font-family:Arial;} .ok{color:green;} .error{color:red;} .info{color:blue;} pre{background:#f5f5f5;padding:10px;}</style>";

if (!isset($_POST['cpf'])) {
    ?>
    <form method="post">
        <h3>Debug Detalhado</h3>
        <label>CPF: <input type="text" name="cpf" placeholder="12345678901" required></label><br><br>
        <label>Polo: 
            <select name="subdomain" required>
                <option value="">Selecione...</option>
                <?php
                foreach (MoodleConfig::getAllSubdomains() as $subdomain) {
                    $config = MoodleConfig::getConfig($subdomain);
                    echo "<option value='{$subdomain}'>{$config['name']}</option>";
                }
                ?>
            </select>
        </label><br><br>
        <input type="submit" value="Debug Busca">
    </form>
    <?php
    exit;
}

$cpf = preg_replace('/[^0-9]/', '', $_POST['cpf']);
$subdomain = $_POST['subdomain'];

echo "<h3>Parâmetros de Busca</h3>";
echo "CPF: {$cpf}<br>";
echo "Polo: {$subdomain}<br>";
echo "Token: " . (MoodleConfig::getToken($subdomain) ? "Configurado" : "NÃO CONFIGURADO") . "<br><br>";

// Função para fazer requisição raw
function makeRawRequest($url, $params) {
    $queryString = http_build_query($params);
    $fullUrl = $url . '?' . $queryString;
    
    echo "<h4>URL da Requisição:</h4>";
    echo "<pre>" . htmlspecialchars($fullUrl) . "</pre>";
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'IMED-Debug/1.0'
        ]
    ]);
    
    $response = file_get_contents($fullUrl, false, $context);
    
    if ($response === false) {
        echo "<span class='error'>✗ Falha na requisição HTTP</span><br>";
        return false;
    }
    
    $data = json_decode($response, true);
    
    echo "<h4>Resposta Raw:</h4>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    
    return $data;
}

$token = MoodleConfig::getToken($subdomain);
$baseUrl = MoodleConfig::getMoodleUrl($subdomain);

if (!$token) {
    echo "<span class='error'>✗ Token não configurado para este subdomínio!</span><br>";
    exit;
}

// 1. Teste básico de conectividade
echo "<h3>1. Teste de Conectividade</h3>";
$siteInfoParams = [
    'wstoken' => $token,
    'wsfunction' => 'core_webservice_get_site_info',
    'moodlewsrestformat' => 'json'
];

$siteInfo = makeRawRequest($baseUrl, $siteInfoParams);

if ($siteInfo && !isset($siteInfo['errorcode'])) {
    echo "<span class='ok'>✓ Conexão OK</span><br>";
    echo "Site: " . ($siteInfo['sitename'] ?? 'N/A') . "<br>";
    echo "Usuário API: " . ($siteInfo['username'] ?? 'N/A') . "<br><br>";
} else {
    echo "<span class='error'>✗ Erro na conexão</span><br>";
    if (isset($siteInfo['errorcode'])) {
        echo "Código: " . $siteInfo['errorcode'] . "<br>";
        echo "Mensagem: " . $siteInfo['message'] . "<br>";
    }
    exit;
}

// 2. Busca por CPF no campo idnumber
echo "<h3>2. Busca por CPF no campo 'idnumber'</h3>";
$searchParams = [
    'wstoken' => $token,
    'wsfunction' => 'core_user_get_users',
    'moodlewsrestformat' => 'json',
    'criteria[0][key]' => 'idnumber',
    'criteria[0][value]' => $cpf
];

$result = makeRawRequest($baseUrl, $searchParams);

if ($result && !isset($result['errorcode'])) {
    if (!empty($result['users'])) {
        echo "<span class='ok'>✓ Usuário encontrado por idnumber!</span><br>";
        $user = $result['users'][0];
        echo "<pre>";
        print_r($user);
        echo "</pre>";
    } else {
        echo "<span class='error'>✗ Nenhum usuário encontrado por idnumber</span><br>";
    }
} else {
    echo "<span class='error'>✗ Erro na busca por idnumber</span><br>";
    if (isset($result['errorcode'])) {
        echo "Erro: " . $result['message'] . "<br>";
    }
}

// 3. Busca por CPF no campo username
echo "<h3>3. Busca por CPF no campo 'username'</h3>";
$searchParams2 = [
    'wstoken' => $token,
    'wsfunction' => 'core_user_get_users',
    'moodlewsrestformat' => 'json',
    'criteria[0][key]' => 'username',
    'criteria[0][value]' => $cpf
];

$result2 = makeRawRequest($baseUrl, $searchParams2);

if ($result2 && !isset($result2['errorcode'])) {
    if (!empty($result2['users'])) {
        echo "<span class='ok'>✓ Usuário encontrado por username!</span><br>";
        $user = $result2['users'][0];
        echo "<pre>";
        print_r($user);
        echo "</pre>";
    } else {
        echo "<span class='error'>✗ Nenhum usuário encontrado por username</span><br>";
    }
} else {
    echo "<span class='error'>✗ Erro na busca por username</span><br>";
}

// 4. Busca por email (caso o CPF esteja no email)
echo "<h3>4. Busca por CPF no campo 'email'</h3>";
$searchParams3 = [
    'wstoken' => $token,
    'wsfunction' => 'core_user_get_users',
    'moodlewsrestformat' => 'json',
    'criteria[0][key]' => 'email',
    'criteria[0][value]' => $cpf . '@exemplo.com'
];

$result3 = makeRawRequest($baseUrl, $searchParams3);

if ($result3 && !isset($result3['errorcode'])) {
    if (!empty($result3['users'])) {
        echo "<span class='ok'>✓ Usuário encontrado por email!</span><br>";
    } else {
        echo "<span class='error'>✗ Nenhum usuário encontrado por email</span><br>";
    }
}

// 5. Lista alguns usuários para verificar estrutura
echo "<h3>5. Listagem de Usuários (primeiros 5)</h3>";
$listParams = [
    'wstoken' => $token,
    'wsfunction' => 'core_user_get_users',
    'moodlewsrestformat' => 'json',
    'criteria[0][key]' => 'id',
    'criteria[0][value]' => '2' // Usuário admin geralmente é ID 2
];

$userList = makeRawRequest($baseUrl, $listParams);

if ($userList && !empty($userList['users'])) {
    echo "<span class='info'>Exemplo de estrutura de usuário:</span><br>";
    echo "<pre>";
    print_r($userList['users'][0]);
    echo "</pre>";
}

echo "<h3>Próximos Passos</h3>";
echo "<div class='info'>";
echo "<p><strong>Se nenhum usuário foi encontrado:</strong></p>";
echo "<ol>";
echo "<li>Verifique se existe um usuário com CPF <strong>{$cpf}</strong> no Moodle</li>";
echo "<li>Vá em <strong>Administração do site > Usuários > Contas > Navegar lista de usuários</strong></li>";
echo "<li>Procure o usuário e verifique:</li>";
echo "<ul>";
echo "<li>Se o campo 'Número de identificação' (idnumber) contém o CPF</li>";
echo "<li>Se o nome de usuário (username) contém o CPF</li>";
echo "<li>Se o usuário não está suspenso</li>";
echo "<li>Se o usuário tem cursos matriculados</li>";
echo "</ul>";
echo "<li>Se necessário, edite o usuário e adicione o CPF no campo correto</li>";
echo "</ol>";
echo "</div>";

echo "<br><a href='debug_usuario.php'>← Tentar novamente</a>";
?>