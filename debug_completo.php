<?php
/**
 * Debug completo do sistema de boletos
 * Arquivo: debug_completo.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug Completo - Sistema de Boletos</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    pre{background:#f5f5f5; padding:15px; border:1px solid #ddd; overflow-x:auto;}
    .section{margin:20px 0; padding:15px; border:1px solid #ccc; border-radius:5px;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9;}
</style>";

// Formulário para testar CPF específico
if (!isset($_POST['test_cpf'])) {
    ?>
    <div class="section">
        <h3>Teste com CPF Específico</h3>
        <form method="post">
            <label>CPF: <input type="text" name="test_cpf" placeholder="12345678901" maxlength="11"></label><br><br>
            <label>Polo: 
                <select name="test_subdomain">
                    <option value="">Selecione...</option>
                    <option value="tucurui.imepedu.com.br">Tucuruí</option>
                    <option value="breubranco.imepedu.com.br">Breu Branco</option>
                    <option value="moju.imepedu.com.br">Moju</option>
                    <option value="igarape.imepedu.com.br">Igarapé-Miri</option>
                </select>
            </label><br><br>
            <input type="submit" value="Debug CPF Específico">
        </form>
    </div>
    <?php
}

// 1. Verificação de Arquivos
echo "<div class='section'>";
echo "<h3>1. Verificação de Arquivos Críticos</h3>";

$arquivos_criticos = [
    'config/database.php' => 'Configuração do banco',
    'config/moodle.php' => 'Configuração do Moodle',
    'src/MoodleAPI.php' => 'API do Moodle',
    'src/AlunoService.php' => 'Serviço de Alunos',
    'src/BoletoService.php' => 'Serviço de Boletos',
    'api/atualizar_dados.php' => 'API de atualização'
];

foreach ($arquivos_criticos as $arquivo => $descricao) {
    echo "<div class='step'>";
    echo "{$descricao}: ";
    if (file_exists($arquivo)) {
        echo "<span class='ok'>✓ Existe</span>";
        
        // Verifica se é legível
        if (is_readable($arquivo)) {
            echo " <span class='ok'>✓ Legível</span>";
            
            // Verifica se não há erros de sintaxe
            $output = shell_exec("php -l {$arquivo} 2>&1");
            if (strpos($output, 'No syntax errors') !== false) {
                echo " <span class='ok'>✓ Sintaxe OK</span>";
            } else {
                echo " <span class='error'>✗ Erro de sintaxe</span>";
                echo "<pre>" . htmlspecialchars($output) . "</pre>";
            }
        } else {
            echo " <span class='error'>✗ Não legível</span>";
        }
    } else {
        echo "<span class='error'>✗ Não encontrado</span>";
    }
    echo "</div>";
}
echo "</div>";

// 2. Teste de Banco de Dados
echo "<div class='section'>";
echo "<h3>2. Teste de Banco de Dados</h3>";

try {
    require_once 'config/database.php';
    
    echo "<div class='step'>";
    echo "Conexão com banco: ";
    $db = new Database();
    $connection = $db->getConnection();
    echo "<span class='ok'>✓ Conectado</span>";
    echo "</div>";
    
    // Verifica tabelas
    $tabelas = ['alunos', 'cursos', 'matriculas', 'boletos'];
    foreach ($tabelas as $tabela) {
        echo "<div class='step'>";
        echo "Tabela {$tabela}: ";
        try {
            $stmt = $connection->query("SELECT COUNT(*) as count FROM {$tabela}");
            $result = $stmt->fetch();
            echo "<span class='ok'>✓ OK ({$result['count']} registros)</span>";
        } catch (Exception $e) {
            echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
        }
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro na conexão: " . $e->getMessage() . "</span>";
    echo "</div>";
}
echo "</div>";

// 3. Teste de Configuração Moodle
echo "<div class='section'>";
echo "<h3>3. Configuração Moodle</h3>";

try {
    require_once 'config/moodle.php';
    
    $subdomains = MoodleConfig::getAllSubdomains();
    echo "<div class='step'>";
    echo "Subdomínios configurados: <span class='info'>" . count($subdomains) . "</span>";
    echo "</div>";
    
    foreach ($subdomains as $subdomain) {
        echo "<div class='step'>";
        echo "Polo {$subdomain}: ";
        
        $token = MoodleConfig::getToken($subdomain);
        if ($token) {
            echo "<span class='ok'>✓ Token OK</span> ";
            
            $config = MoodleConfig::getConfig($subdomain);
            if ($config['active'] ?? false) {
                echo "<span class='ok'>✓ Ativo</span>";
            } else {
                echo "<span class='warning'>⚠ Inativo</span>";
            }
        } else {
            echo "<span class='error'>✗ Sem token</span>";
        }
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
    echo "</div>";
}
echo "</div>";

// 4. Teste da API Moodle (se CPF foi fornecido)
if (isset($_POST['test_cpf']) && !empty($_POST['test_cpf'])) {
    $cpf = preg_replace('/[^0-9]/', '', $_POST['test_cpf']);
    $subdomain = $_POST['test_subdomain'];
    
    echo "<div class='section'>";
    echo "<h3>4. Teste da API Moodle</h3>";
    echo "<div class='step'>";
    echo "Testando CPF: <strong>{$cpf}</strong> no polo: <strong>{$subdomain}</strong>";
    echo "</div>";
    
    try {
        require_once 'src/MoodleAPI.php';
        
        echo "<div class='step'>";
        echo "Inicializando API... ";
        $api = new MoodleAPI($subdomain);
        echo "<span class='ok'>✓ API inicializada</span>";
        echo "</div>";
        
        echo "<div class='step'>";
        echo "Testando conexão... ";
        $conexao = $api->testarConexao();
        if ($conexao['sucesso']) {
            echo "<span class='ok'>✓ Conexão OK</span>";
            echo "<br>Site: " . $conexao['nome_site'];
        } else {
            echo "<span class='error'>✗ Falha: " . $conexao['erro'] . "</span>";
        }
        echo "</div>";
        
        echo "<div class='step'>";
        echo "Buscando aluno por CPF... ";
        $aluno = $api->buscarAlunoPorCPF($cpf);
        
        if ($aluno) {
            echo "<span class='ok'>✓ Aluno encontrado!</span>";
            echo "<pre>";
            echo "Nome: " . $aluno['nome'] . "\n";
            echo "Email: " . $aluno['email'] . "\n";
            echo "ID Moodle: " . $aluno['moodle_user_id'] . "\n";
            echo "Cursos encontrados: " . count($aluno['cursos']) . "\n\n";
            
            if (!empty($aluno['cursos'])) {
                echo "Lista de cursos:\n";
                foreach ($aluno['cursos'] as $curso) {
                    echo "- " . $curso['nome'] . " (ID: " . $curso['moodle_course_id'] . ")\n";
                }
            } else {
                echo "⚠ PROBLEMA: Nenhum curso encontrado!\n";
            }
            echo "</pre>";
        } else {
            echo "<span class='error'>✗ Aluno não encontrado</span>";
        }
        echo "</div>";
        
        // Teste de salvamento no banco
        if ($aluno) {
            echo "<div class='step'>";
            echo "Testando salvamento no banco... ";
            
            require_once 'src/AlunoService.php';
            $alunoService = new AlunoService();
            $alunoId = $alunoService->salvarOuAtualizarAluno($aluno);
            
            echo "<span class='ok'>✓ Salvo (ID: {$alunoId})</span>";
            
            // Verifica cursos salvos
            $cursosLocal = $alunoService->buscarCursosAluno($alunoId);
            echo "<br>Cursos no banco local: " . count($cursosLocal);
            
            if (count($cursosLocal) != count($aluno['cursos'])) {
                echo " <span class='warning'>⚠ Divergência entre Moodle (" . count($aluno['cursos']) . ") e local (" . count($cursosLocal) . ")</span>";
            }
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='step'>";
        echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
        echo "<pre>Stack trace:\n" . $e->getTraceAsString() . "</pre>";
        echo "</div>";
    }
    echo "</div>";
}

// 5. Teste da API de Atualização
echo "<div class='section'>";
echo "<h3>5. Teste da API de Atualização</h3>";

if (file_exists('api/atualizar_dados.php')) {
    echo "<div class='step'>";
    echo "Arquivo API: <span class='ok'>✓ Existe</span>";
    
    // Verifica se a pasta api é acessível via web
    $api_url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . '/api/atualizar_dados.php';
    echo "<br>URL da API: <a href='{$api_url}' target='_blank'>{$api_url}</a>";
    echo "</div>";
} else {
    echo "<div class='step'>";
    echo "Arquivo API: <span class='error'>✗ Não encontrado</span>";
    echo "<br><span class='info'>Solução: Criar o arquivo api/atualizar_dados.php</span>";
    echo "</div>";
}
echo "</div>";

// 6. Verificação de Logs
echo "<div class='section'>";
echo "<h3>6. Logs de Erro Recentes</h3>";

$logFile = ini_get('error_log');
if ($logFile && file_exists($logFile) && is_readable($logFile)) {
    echo "<div class='step'>";
    echo "Arquivo de log: {$logFile}<br>";
    
    $lines = file($logFile);
    $recentLines = array_slice($lines, -10);
    
    echo "<strong>Últimos 10 erros:</strong>";
    echo "<pre>";
    foreach ($recentLines as $line) {
        if (strpos($line, 'boleto') !== false || strpos($line, 'moodle') !== false) {
            echo htmlspecialchars($line);
        }
    }
    echo "</pre>";
    echo "</div>";
} else {
    echo "<div class='step'>";
    echo "Log de erro não acessível";
    echo "</div>";
}
echo "</div>";

// 7. Sugestões de Correção
echo "<div class='section'>";
echo "<h3>7. Próximos Passos</h3>";
echo "<div class='step'>";
echo "<h4>Se o problema persiste:</h4>";
echo "<ol>";
echo "<li><strong>Verifique o token do Moodle:</strong> Confirme se o token está correto no arquivo config/moodle.php</li>";
echo "<li><strong>Teste no Moodle:</strong> Verifique se existe um usuário com o CPF no campo 'idnumber'</li>";
echo "<li><strong>Permissões Web Services:</strong> Confirme se as funções estão habilitadas no Moodle</li>";
echo "<li><strong>Logs do servidor:</strong> Verifique os logs do Apache/Nginx para erros de requisição</li>";
echo "<li><strong>Firewall:</strong> Verifique se não há bloqueio entre os servidores</li>";
echo "</ol>";

echo "<h4>Comandos úteis para debug:</h4>";
echo "<pre>";
echo "# Testar conexão direta com Moodle
curl -k 'https://breubranco.imepedu.com.br/webservice/rest/server.php?wstoken=SEU_TOKEN&wsfunction=core_webservice_get_site_info&moodlewsrestformat=json'

# Verificar logs em tempo real
tail -f /var/log/apache2/error.log | grep -i boleto

# Testar CPF específico
php -r \"require 'teste_moodle.php';\"";
echo "</pre>";
echo "</div>";
echo "</div>";

echo "<br><a href='debug_completo.php'>← Executar novamente</a>";
?>