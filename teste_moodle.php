<?php
/**
 * Sistema de Boletos IMED - Teste de Integração Moodle
 * Arquivo: teste_moodle.php
 * 
 * Script para testar a conexão e busca de usuários no Moodle
 */

// Habilita exibição de erros
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

echo "<h1>Teste de Integração com Moodle</h1>";
echo "<style>body{font-family:Arial;} .ok{color:green;} .error{color:red;} .info{color:blue;}</style>";

// Formulário para teste
if (!isset($_POST['cpf'])) {
    ?>
    <form method="post">
        <h3>Teste de Busca por CPF</h3>
        <label>CPF: <input type="text" name="cpf" placeholder="12345678901" maxlength="11" required></label><br><br>
        <label>Polo: 
            <select name="subdomain" required>
                <option value="">Selecione...</option>
                <?php
                $subdomains = MoodleConfig::getAllSubdomains();
                foreach ($subdomains as $subdomain) {
                    $config = MoodleConfig::getConfig($subdomain);
                    echo "<option value='{$subdomain}'>{$config['name']} ({$subdomain})</option>";
                }
                ?>
            </select>
        </label><br><br>
        <input type="submit" value="Testar Busca">
    </form>
    
    <hr>
    <h3>Teste de Conectividade</h3>
    <?php
    foreach (MoodleConfig::getAllSubdomains() as $subdomain) {
        echo "<h4>Testando: {$subdomain}</h4>";
        
        $resultado = MoodleConfig::testConnection($subdomain);
        
        if ($resultado['success']) {
            echo "<span class='ok'>✓ Conexão OK</span><br>";
            echo "Site: " . ($resultado['site_info']['sitename'] ?? 'N/A') . "<br>";
            echo "Versão: " . ($resultado['site_info']['release'] ?? 'N/A') . "<br>";
            echo "Usuário API: " . ($resultado['site_info']['username'] ?? 'N/A') . "<br>";
        } else {
            echo "<span class='error'>✗ Erro: " . $resultado['error'] . "</span><br>";
        }
        echo "<br>";
    }
    
    exit;
}

// Processa teste de busca
$cpf = preg_replace('/[^0-9]/', '', $_POST['cpf']);
$subdomain = $_POST['subdomain'];

echo "<h3>Resultado do Teste</h3>";
echo "CPF: {$cpf}<br>";
echo "Polo: {$subdomain}<br><br>";

try {
    // Testa conexão básica
    echo "<h4>1. Teste de Conexão</h4>";
    $conexao = MoodleConfig::testConnection($subdomain);
    
    if ($conexao['success']) {
        echo "<span class='ok'>✓ Conexão estabelecida</span><br>";
        echo "Site: " . $conexao['site_info']['sitename'] . "<br>";
        echo "URL: " . $conexao['site_info']['siteurl'] . "<br><br>";
    } else {
        echo "<span class='error'>✗ Falha na conexão: " . $conexao['error'] . "</span><br>";
        exit;
    }
    
    // Testa API
    echo "<h4>2. Teste da API</h4>";
    $api = new MoodleAPI($subdomain);
    
    echo "<span class='ok'>✓ API inicializada</span><br>";
    
    // Busca informações do site
    $siteInfo = $api->buscarInformacoesSite();
    echo "Nome do site: " . $siteInfo['nome_site'] . "<br>";
    echo "Versão Moodle: " . $siteInfo['versao_moodle'] . "<br>";
    echo "Funções disponíveis: " . count($siteInfo['funcoes_disponiveis']) . "<br><br>";
    
    // Busca usuário por CPF
    echo "<h4>3. Busca por CPF</h4>";
    $aluno = $api->buscarAlunoPorCPF($cpf);
    
    if ($aluno) {
        echo "<span class='ok'>✓ Aluno encontrado!</span><br>";
        echo "<pre>";
        echo "Nome: " . $aluno['nome'] . "\n";
        echo "Email: " . $aluno['email'] . "\n";
        echo "CPF: " . $aluno['cpf'] . "\n";
        echo "ID Moodle: " . $aluno['moodle_user_id'] . "\n";
        echo "Subdomain: " . $aluno['subdomain'] . "\n";
        echo "Último acesso: " . ($aluno['ultimo_acesso'] ?? 'N/A') . "\n";
        echo "Cidade: " . ($aluno['city'] ?? 'N/A') . "\n";
        echo "\nCursos matriculados:\n";
        
        if (!empty($aluno['cursos'])) {
            foreach ($aluno['cursos'] as $curso) {
                echo "- " . $curso['nome'] . " (ID: " . $curso['moodle_course_id'] . ")\n";
                echo "  Início: " . ($curso['data_inicio'] ?? 'N/A') . "\n";
                echo "  URL: " . ($curso['url'] ?? 'N/A') . "\n\n";
            }
        } else {
            echo "Nenhum curso encontrado.\n";
        }
        echo "</pre>";
        
        // Teste de validação de acesso
        echo "<h4>4. Teste de Validação de Acesso</h4>";
        if (!empty($aluno['cursos'])) {
            $primeiroCurso = $aluno['cursos'][0];
            $temAcesso = $api->validarAcessoCurso($aluno['moodle_user_id'], $primeiroCurso['moodle_course_id']);
            
            if ($temAcesso) {
                echo "<span class='ok'>✓ Aluno tem acesso ao curso: " . $primeiroCurso['nome'] . "</span><br>";
            } else {
                echo "<span class='error'>✗ Aluno não tem acesso validado</span><br>";
            }
        }
        
        // Teste de busca de perfil completo
        echo "<h4>5. Teste de Perfil Completo</h4>";
        $perfil = $api->buscarPerfilUsuario($aluno['moodle_user_id']);
        
        if ($perfil) {
            echo "<span class='ok'>✓ Perfil completo obtido</span><br>";
            echo "Primeiro nome: " . $perfil['primeiro_nome'] . "<br>";
            echo "Sobrenome: " . $perfil['sobrenome'] . "<br>";
            echo "Timezone: " . $perfil['timezone'] . "<br>";
            echo "Idioma: " . $perfil['idioma'] . "<br>";
        }
        
    } else {
        echo "<span class='error'>✗ Aluno não encontrado</span><br>";
        echo "<span class='info'>Verifique se:</span><br>";
        echo "- O CPF está correto<br>";
        echo "- O CPF está cadastrado no campo 'idnumber' ou 'username' no Moodle<br>";
        echo "- O usuário tem cursos ativos<br>";
        echo "- O usuário não está suspenso<br>";
    }
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span><br>";
    echo "<span class='info'>Verifique:</span><br>";
    echo "- Se o token está correto<br>";
    echo "- Se as funções Web Services estão habilitadas<br>";
    echo "- Se o usuário da API tem permissões adequadas<br>";
}

echo "<br><hr>";
echo "<h4>Debug - Configurações</h4>";
echo "Token configurado: " . (MoodleConfig::getToken($subdomain) ? 'Sim' : 'Não') . "<br>";
echo "URL da API: " . MoodleConfig::getMoodleUrl($subdomain) . "<br>";

$config = MoodleConfig::getConfig($subdomain);
if ($config) {
    echo "Nome do polo: " . $config['name'] . "<br>";
    echo "Polo ativo: " . ($config['active'] ? 'Sim' : 'Não') . "<br>";
}

echo "<br><a href='teste_moodle.php'>← Voltar</a>";
?>