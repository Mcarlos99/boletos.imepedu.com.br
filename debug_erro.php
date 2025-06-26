<?php
/**
 * Debug de erros do sistema de boletos
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug - Erros do Sistema</h1>";
echo "<style>body{font-family:Arial;} .ok{color:green;} .error{color:red;} .warning{color:orange;} pre{background:#f5f5f5;padding:10px;border:1px solid #ddd;}</style>";

// 1. Verificar se arquivos existem
echo "<h3>1. Verificação de Arquivos</h3>";
$arquivos = [
    'config/database.php' => 'Configuração do banco',
    'config/moodle.php' => 'Configuração do Moodle',
    'src/MoodleAPI.php' => 'API do Moodle',
    'src/AlunoService.php' => 'Serviço de Alunos',
    'src/BoletoService.php' => 'Serviço de Boletos'
];

foreach ($arquivos as $arquivo => $descricao) {
    echo "{$descricao}: ";
    if (file_exists($arquivo)) {
        echo "<span class='ok'>✓ OK</span>";
        
        // Testa inclusão do arquivo
        try {
            require_once $arquivo;
            echo " <span class='ok'>✓ Carregado</span>";
        } catch (Exception $e) {
            echo " <span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span class='error'>✗ Não encontrado</span>";
    }
    echo "<br>";
}

echo "<br>";

// 2. Testar conexão com banco
echo "<h3>2. Teste de Banco de Dados</h3>";
try {
    $db = new Database();
    $connection = $db->getConnection();
    echo "Conexão com banco: <span class='ok'>✓ OK</span><br>";
    
    // Testa query simples
    $stmt = $connection->query("SELECT 1 as teste");
    $result = $stmt->fetch();
    if ($result['teste'] == 1) {
        echo "Query de teste: <span class='ok'>✓ OK</span><br>";
    }
    
    // Verifica se tabelas existem
    $tabelas = ['alunos', 'cursos', 'matriculas', 'boletos'];
    foreach ($tabelas as $tabela) {
        try {
            $stmt = $connection->query("SELECT COUNT(*) as count FROM {$tabela}");
            $result = $stmt->fetch();
            echo "Tabela {$tabela}: <span class='ok'>✓ OK ({$result['count']} registros)</span><br>";
        } catch (Exception $e) {
            echo "Tabela {$tabela}: <span class='error'>✗ Erro: " . $e->getMessage() . "</span><br>";
        }
    }
    
} catch (Exception $e) {
    echo "Erro no banco: <span class='error'>" . $e->getMessage() . "</span><br>";
}

echo "<br>";

// 3. Testar classe MoodleAPI com logs detalhados
echo "<h3>3. Teste Detalhado da MoodleAPI</h3>";

if (!isset($_POST['test_cpf'])) {
    ?>
    <form method="post">
        <label>CPF para teste: <input type="text" name="test_cpf" value="03183924536" required></label><br><br>
        <label>Polo: 
            <select name="test_subdomain" required>
                <option value="breubranco.imepedu.com.br">Breu Branco</option>
                <?php
                if (class_exists('MoodleConfig')) {
                    foreach (MoodleConfig::getAllSubdomains() as $subdomain) {
                        if ($subdomain != 'breubranco.imepedu.com.br') {
                            $config = MoodleConfig::getConfig($subdomain);
                            echo "<option value='{$subdomain}'>{$config['name']}</option>";
                        }
                    }
                }
                ?>
            </select>
        </label><br><br>
        <input type="submit" value="Testar API">
    </form>
    <?php
} else {
    $cpf = preg_replace('/[^0-9]/', '', $_POST['test_cpf']);
    $subdomain = $_POST['test_subdomain'];
    
    echo "Testando CPF: {$cpf}<br>";
    echo "Polo: {$subdomain}<br><br>";
    
    try {
        echo "<h4>Passo 1: Inicializando MoodleAPI</h4>";
        $api = new MoodleAPI($subdomain);
        echo "<span class='ok'>✓ API inicializada</span><br><br>";
        
        echo "<h4>Passo 2: Testando conexão básica</h4>";
        $conexao = $api->testarConexao();
        if ($conexao['sucesso']) {
            echo "<span class='ok'>✓ Conexão OK</span><br>";
            echo "Site: " . $conexao['nome_site'] . "<br>";
            echo "Versão: " . $conexao['versao'] . "<br><br>";
        } else {
            echo "<span class='error'>✗ Erro na conexão: " . $conexao['erro'] . "</span><br>";
            throw new Exception("Falha na conexão com Moodle");
        }
        
        echo "<h4>Passo 3: Buscando aluno por CPF</h4>";
        $aluno = $api->buscarAlunoPorCPF($cpf);
        
        if ($aluno) {
            echo "<span class='ok'>✓ Aluno encontrado</span><br>";
            echo "<pre>";
            print_r($aluno);
            echo "</pre>";
            
            echo "<h4>Passo 4: Testando AlunoService</h4>";
            $alunoService = new AlunoService();
            $alunoId = $alunoService->salvarOuAtualizarAluno($aluno);
            echo "<span class='ok'>✓ Aluno salvo/atualizado no banco local (ID: {$alunoId})</span><br>";
            
        } else {
            echo "<span class='error'>✗ Aluno não encontrado</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ ERRO: " . $e->getMessage() . "</span><br>";
        echo "<h4>Stack Trace:</h4>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
        
        // Log do erro
        error_log("Erro no sistema de boletos: " . $e->getMessage());
    }
}

echo "<br>";

// 4. Verificar logs de erro do PHP
echo "<h3>4. Logs de Erro Recentes</h3>";
$logFile = ini_get('error_log');
if ($logFile && file_exists($logFile) && is_readable($logFile)) {
    echo "Arquivo de log: {$logFile}<br>";
    $lines = file($logFile);
    $recentLines = array_slice($lines, -20); // Últimas 20 linhas
    
    echo "<h4>Últimos erros:</h4>";
    echo "<pre style='max-height:300px;overflow-y:scroll;'>";
    foreach ($recentLines as $line) {
        if (strpos($line, 'boleto') !== false || strpos($line, 'Fatal') !== false || strpos($line, 'Error') !== false) {
            echo htmlspecialchars($line);
        }
    }
    echo "</pre>";
} else {
    echo "Log de erro não acessível<br>";
}

echo "<br>";

// 5. Informações do sistema
echo "<h3>5. Informações do Sistema</h3>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Max Execution Time: " . ini_get('max_execution_time') . "s<br>";
echo "Error Reporting: " . error_reporting() . "<br>";
echo "Display Errors: " . (ini_get('display_errors') ? 'On' : 'Off') . "<br>";

// 6. Teste manual de requisição
echo "<br><h3>6. Teste Manual de Requisição HTTP</h3>";
if (class_exists('MoodleConfig')) {
    $token = MoodleConfig::getToken('breubranco.imepedu.com.br');
    $url = "https://breubranco.imepedu.com.br/webservice/rest/server.php";
    
    if ($token) {
        echo "Token: " . substr($token, 0, 10) . "...<br>";
        echo "URL: {$url}<br>";
        
        $testUrl = $url . "?" . http_build_query([
            'wstoken' => $token,
            'wsfunction' => 'core_webservice_get_site_info',
            'moodlewsrestformat' => 'json'
        ]);
        
        echo "<h4>Testando requisição direta:</h4>";
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Debug-Test/1.0'
            ]
        ]);
        
        $response = @file_get_contents($testUrl, false, $context);
        
        if ($response !== false) {
            echo "<span class='ok'>✓ Requisição HTTP OK</span><br>";
            $data = json_decode($response, true);
            if ($data && !isset($data['errorcode'])) {
                echo "Site: " . ($data['sitename'] ?? 'N/A') . "<br>";
            } else {
                echo "<span class='error'>Erro na resposta:</span><br>";
                echo "<pre>" . htmlspecialchars($response) . "</pre>";
            }
        } else {
            echo "<span class='error'>✗ Falha na requisição HTTP</span><br>";
            $error = error_get_last();
            if ($error) {
                echo "Erro: " . $error['message'] . "<br>";
            }
        }
    } else {
        echo "<span class='error'>Token não configurado</span><br>";
    }
}

echo "<br><a href='debug_erro.php'>← Executar novamente</a>";
?>