<?php
/**
 * Diagnóstico Simples - Sistema de Boletos IMED
 * Arquivo: diagnostico_simples.php
 */

// Configura exibição de erros para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Inicia sessão
session_start();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚨 Diagnóstico Simples - IMED</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 5px; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert.info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #0056b3; }
        .btn.success { background: #28a745; }
        .btn.danger { background: #dc3545; }
        .btn.warning { background: #ffc107; color: #212529; }
        .test-result { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #dee2e6; }
        .test-result.ok { border-left-color: #28a745; }
        .test-result.error { border-left-color: #dc3545; }
        .test-result.warning { border-left-color: #ffc107; }
        pre { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚨 Diagnóstico Simples do Sistema</h1>
        
        <div class="alert error">
            <strong>PROBLEMA:</strong> Perda de conexão com polos após atualização.
        </div>

        <?php
        // TESTE 1: Verificar arquivos essenciais
        echo "<h2>📁 1. Verificação de Arquivos</h2>";
        
        $arquivos = [
            'config/database.php',
            'config/moodle.php', 
            'src/MoodleAPI.php',
            'src/AlunoService.php',
            'admin/api/buscar-cursos.php'
        ];
        
        foreach ($arquivos as $arquivo) {
            echo "<div class='test-result ";
            if (file_exists($arquivo)) {
                echo "ok'>✅ <strong>$arquivo</strong> - Arquivo existe";
                if (is_readable($arquivo)) {
                    echo " (Legível)";
                } else {
                    echo " (⚠️ Não legível)";
                }
            } else {
                echo "error'>❌ <strong>$arquivo</strong> - ARQUIVO NÃO ENCONTRADO";
            }
            echo "</div>";
        }

        // TESTE 2: Verificar configuração do Moodle
        echo "<h2>🔧 2. Configuração do Moodle</h2>";
        
        try {
            if (file_exists('config/moodle.php')) {
                require_once 'config/moodle.php';
                
                if (class_exists('MoodleConfig')) {
                    echo "<div class='test-result ok'>✅ Classe MoodleConfig carregada</div>";
                    
                    // Testa alguns métodos
                    try {
                        $polos = MoodleConfig::getActiveSubdomains();
                        echo "<div class='test-result ok'>✅ Polos ativos encontrados: " . count($polos) . "</div>";
                        
                        foreach ($polos as $polo) {
                            $token = MoodleConfig::getToken($polo);
                            $config = MoodleConfig::getConfig($polo);
                            
                            echo "<div class='test-result ";
                            if ($token && $token !== 'x') {
                                echo "ok'>✅ <strong>$polo</strong> - Token configurado";
                            } else {
                                echo "warning'>⚠️ <strong>$polo</strong> - Token não configurado (valor: $token)";
                            }
                            
                            if (isset($config['name'])) {
                                echo " - Nome: " . $config['name'];
                            }
                            echo "</div>";
                        }
                        
                    } catch (Exception $e) {
                        echo "<div class='test-result error'>❌ Erro ao testar métodos: " . $e->getMessage() . "</div>";
                    }
                    
                } else {
                    echo "<div class='test-result error'>❌ Classe MoodleConfig não encontrada</div>";
                }
            } else {
                echo "<div class='test-result error'>❌ Arquivo config/moodle.php não encontrado</div>";
            }
        } catch (Exception $e) {
            echo "<div class='test-result error'>❌ Erro ao carregar configuração: " . $e->getMessage() . "</div>";
        }

        // TESTE 3: Verificar banco de dados
        echo "<h2>🗄️ 3. Banco de Dados</h2>";
        
        try {
            if (file_exists('config/database.php')) {
                require_once 'config/database.php';
                
                if (class_exists('Database')) {
                    echo "<div class='test-result ok'>✅ Classe Database carregada</div>";
                    
                    try {
                        $db = new Database();
                        $connection = $db->getConnection();
                        
                        if ($connection) {
                            echo "<div class='test-result ok'>✅ Conexão com banco estabelecida</div>";
                            
                            // Testa algumas tabelas
                            $tabelas = ['alunos', 'cursos', 'matriculas', 'boletos'];
                            foreach ($tabelas as $tabela) {
                                try {
                                    $stmt = $connection->query("SELECT COUNT(*) as count FROM $tabela");
                                    $result = $stmt->fetch();
                                    echo "<div class='test-result ok'>✅ Tabela $tabela: " . $result['count'] . " registros</div>";
                                } catch (Exception $e) {
                                    echo "<div class='test-result error'>❌ Tabela $tabela: " . $e->getMessage() . "</div>";
                                }
                            }
                            
                        } else {
                            echo "<div class='test-result error'>❌ Falha ao conectar com banco</div>";
                        }
                    } catch (Exception $e) {
                        echo "<div class='test-result error'>❌ Erro de banco: " . $e->getMessage() . "</div>";
                    }
                } else {
                    echo "<div class='test-result error'>❌ Classe Database não encontrada</div>";
                }
            } else {
                echo "<div class='test-result error'>❌ Arquivo config/database.php não encontrado</div>";
            }
        } catch (Exception $e) {
            echo "<div class='test-result error'>❌ Erro ao testar banco: " . $e->getMessage() . "</div>";
        }

        // TESTE 4: Verificar API Moodle
        echo "<h2>🌐 4. Teste da API Moodle</h2>";
        
        try {
            if (class_exists('MoodleAPI')) {
                echo "<div class='test-result ok'>✅ Classe MoodleAPI carregada</div>";
                
                // Testa um polo específico
                $poloTeste = 'breubranco.imepedu.com.br';
                
                try {
                    $moodleAPI = new MoodleAPI($poloTeste);
                    echo "<div class='test-result ok'>✅ MoodleAPI instanciada para $poloTeste</div>";
                    
                    // Testa conexão
                    $testeConexao = $moodleAPI->testarConexao();
                    if ($testeConexao['sucesso']) {
                        echo "<div class='test-result ok'>✅ Conexão com $poloTeste: SUCESSO</div>";
                        echo "<div class='test-result ok'>Site: " . ($testeConexao['nome_site'] ?? 'N/A') . "</div>";
                    } else {
                        echo "<div class='test-result error'>❌ Conexão com $poloTeste: " . $testeConexao['erro'] . "</div>";
                    }
                    
                } catch (Exception $e) {
                    echo "<div class='test-result error'>❌ Erro ao testar MoodleAPI: " . $e->getMessage() . "</div>";
                }
                
            } else {
                echo "<div class='test-result error'>❌ Classe MoodleAPI não encontrada</div>";
            }
        } catch (Exception $e) {
            echo "<div class='test-result error'>❌ Erro geral na API: " . $e->getMessage() . "</div>";
        }

        // TESTE 5: Verificar logs de erro
        echo "<h2>📝 5. Logs de Erro</h2>";
        
        // Verifica logs do PHP
        $logErrors = error_get_last();
        if ($logErrors) {
            echo "<div class='test-result warning'>⚠️ Último erro PHP detectado:</div>";
            echo "<pre>" . print_r($logErrors, true) . "</pre>";
        } else {
            echo "<div class='test-result ok'>✅ Nenhum erro PHP recente detectado</div>";
        }

        // TESTE 6: Informações do sistema
        echo "<h2>ℹ️ 6. Informações do Sistema</h2>";
        
        echo "<div class='test-result ok'>";
        echo "<strong>PHP Version:</strong> " . phpversion() . "<br>";
        echo "<strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "<br>";
        echo "<strong>Document Root:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "<br>";
        echo "<strong>Script Path:</strong> " . __FILE__ . "<br>";
        echo "<strong>Current Directory:</strong> " . getcwd() . "<br>";
        echo "<strong>Memory Limit:</strong> " . ini_get('memory_limit') . "<br>";
        echo "<strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . "<br>";
        echo "</div>";
        ?>

        <h2>🔧 Ações Corretivas</h2>
        
        <div class="alert info">
            <strong>Com base no diagnóstico acima, tente as soluções abaixo:</strong>
        </div>

        <form method="POST" style="margin: 20px 0;">
            <button type="submit" name="acao" value="limpar_cache" class="btn warning">
                🗑️ Limpar Cache PHP
            </button>
            
            <button type="submit" name="acao" value="testar_login" class="btn">
                👤 Testar Login Manual
            </button>
            
            <button type="submit" name="acao" value="verificar_permissoes" class="btn">
                🛡️ Verificar Permissões
            </button>
        </form>

        <?php
        // Processa ações corretivas
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
            echo "<h3>🔄 Executando Ação: " . $_POST['acao'] . "</h3>";
            
            switch ($_POST['acao']) {
                case 'limpar_cache':
                    // Limpa cache de sessão
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }
                    
                    // Limpa cache do OPcache se disponível
                    if (function_exists('opcache_reset')) {
                        opcache_reset();
                        echo "<div class='alert success'>✅ OPcache limpo</div>";
                    }
                    
                    echo "<div class='alert success'>✅ Cache de sessão limpo</div>";
                    break;
                    
                case 'testar_login':
                    echo "<div class='alert info'>";
                    echo "<strong>Teste de Login Manual:</strong><br>";
                    echo "1. Vá para: <a href='/index.php'>/index.php</a><br>";
                    echo "2. Use CPF: 12345678901<br>";
                    echo "3. Selecione polo: breubranco.imepedu.com.br<br>";
                    echo "4. Observe os erros no navegador (F12 -> Console)";
                    echo "</div>";
                    break;
                    
                case 'verificar_permissoes':
                    $arquivos = ['config/moodle.php', 'src/MoodleAPI.php'];
                    foreach ($arquivos as $arquivo) {
                        if (file_exists($arquivo)) {
                            $perms = fileperms($arquivo);
                            echo "<div class='test-result ok'>$arquivo: " . substr(sprintf('%o', $perms), -4) . "</div>";
                        }
                    }
                    break;
            }
        }
        ?>

        <div class="alert warning" style="margin-top: 30px;">
            <strong>🎯 PRÓXIMOS PASSOS:</strong><br>
            1. Analise os resultados acima<br>
            2. Se houver erros em arquivos, verifique se eles existem<br>
            3. Se tokens estão como 'x', configure tokens válidos<br>
            4. Se API falhar, verifique conectividade com Moodle<br>
            5. Envie os resultados deste diagnóstico para análise
        </div>

    </div>
</body>
</html>