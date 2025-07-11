<?php
/**
 * Teste de Conectividade com Polos Moodle - CORRIGIDO
 * Arquivo: test-moodle-polos.php
 */

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

echo "========================================\n";
echo "TESTE DE CONECTIVIDADE - POLOS MOODLE\n";
echo "========================================\n";

$polosAtivos = MoodleConfig::getActiveSubdomains();

foreach ($polosAtivos as $subdomain) {
    echo "\n🔍 TESTANDO POLO: $subdomain\n";
    echo str_repeat("-", 50) . "\n";
    
    try {
        // 1. Verifica configuração
        $config = MoodleConfig::getConfig($subdomain);
        $token = MoodleConfig::getToken($subdomain);
        
        echo "Nome: " . ($config['name'] ?? 'N/A') . "\n";
        echo "Token: " . (strlen($token) > 10 ? substr($token, 0, 10) . '...' : $token) . "\n";
        
        if ($token === 'x') {
            echo "❌ POLO INATIVO - Token = 'x'\n";
            continue;
        }
        
        // 2. Testa conectividade básica
        echo "Testando conectividade...\n";
        $moodleAPI = new MoodleAPI($subdomain);
        
        // 3. Testa informações do site
        echo "Buscando informações do site...\n";
        $siteInfo = $moodleAPI->buscarInformacoesSite();
        
        if (!empty($siteInfo['nome_site'])) {
            echo "✅ CONECTADO: " . $siteInfo['nome_site'] . "\n";
            echo "   Versão: " . $siteInfo['versao_moodle'] . "\n";
            echo "   URL: " . $siteInfo['url'] . "\n";
        }
        
        // 4. Testa busca de usuários via método público
        echo "Testando busca de usuários...\n";
        
        try {
            // 🔧 CORRIGIDO: Usa método público direto
            if (method_exists($moodleAPI, 'buscarTodosUsuarios')) {
                echo "Método buscarTodosUsuarios() disponível, testando...\n";
                $usuarios = $moodleAPI->buscarTodosUsuarios();
                
                if (count($usuarios) > 0) {
                    echo "✅ USUÁRIOS ENCONTRADOS: " . count($usuarios) . "\n";
                    
                    // Mostra primeiro usuário como exemplo
                    $primeiroUser = $usuarios[0];
                    echo "   Exemplo - Nome: {$primeiroUser['nome']}\n";
                    echo "   CPF: {$primeiroUser['cpf']}\n";
                    echo "   Email: {$primeiroUser['email']}\n";
                    echo "   Cursos: " . count($primeiroUser['cursos']) . "\n";
                    
                } else {
                    echo "❌ NENHUM USUÁRIO RETORNADO pelo método buscarTodosUsuarios()\n";
                }
            } else {
                echo "⚠️  Método buscarTodosUsuarios() NÃO DISPONÍVEL\n";
                echo "   Você precisa atualizar o arquivo src/MoodleAPI.php\n";
                
                // Testa método alternativo via buscarAlunoPorCPF
                echo "   Testando método alternativo...\n";
                try {
                    // Tenta buscar por um CPF teste
                    $testeCPF = '12345678901';
                    $resultado = $moodleAPI->buscarAlunoPorCPF($testeCPF);
                    echo "   Método buscarAlunoPorCPF() funciona (retornou: " . ($resultado ? 'dados' : 'null') . ")\n";
                } catch (Exception $e) {
                    echo "   Método buscarAlunoPorCPF() erro: " . $e->getMessage() . "\n";
                }
            }
            
        } catch (Exception $e) {
            echo "❌ ERRO na busca de usuários: " . $e->getMessage() . "\n";
        }
        
        // 5. Testa busca de cursos
        echo "Testando busca de cursos...\n";
        try {
            $cursos = $moodleAPI->listarTodosCursos();
            
            if (is_array($cursos) && count($cursos) > 0) {
                echo "✅ CURSOS ENCONTRADOS: " . count($cursos) . "\n";
                
                // Mostra primeiro curso
                if (!empty($cursos[0])) {
                    $primeiroCurso = $cursos[0];
                    echo "   Exemplo - Nome: " . ($primeiroCurso['nome'] ?? 'N/A') . "\n";
                    echo "   Tipo: " . ($primeiroCurso['tipo'] ?? 'N/A') . "\n";
                }
            } else {
                echo "⚠️  Nenhum curso encontrado\n";
            }
            
        } catch (Exception $e) {
            echo "❌ ERRO na busca de cursos: " . $e->getMessage() . "\n";
        }
        
        // 6. Teste de conectividade geral
        echo "Testando conectividade geral...\n";
        try {
            $testeConexao = $moodleAPI->testarConexao();
            
            if ($testeConexao['sucesso']) {
                echo "✅ CONECTIVIDADE OK\n";
                echo "   Site: " . $testeConexao['nome_site'] . "\n";
                echo "   Versão: " . $testeConexao['versao'] . "\n";
            } else {
                echo "❌ CONECTIVIDADE FALHOU: " . $testeConexao['erro'] . "\n";
            }
            
        } catch (Exception $e) {
            echo "❌ ERRO no teste de conectividade: " . $e->getMessage() . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ ERRO GERAL no polo $subdomain: " . $e->getMessage() . "\n";
        echo "   Arquivo: " . $e->getFile() . "\n";
        echo "   Linha: " . $e->getLine() . "\n";
    }
    
    echo "\n";
}

echo "========================================\n";
echo "TESTE CONCLUÍDO\n";
echo "========================================\n";

// 7. Resumo das configurações
echo "\n📋 RESUMO DAS CONFIGURAÇÕES:\n";
echo str_repeat("-", 50) . "\n";

foreach ($polosAtivos as $subdomain) {
    $config = MoodleConfig::getConfig($subdomain);
    $token = MoodleConfig::getToken($subdomain);
    
    echo "Polo: {$config['name']} ($subdomain)\n";
    echo "  Ativo: " . ($config['active'] ? 'SIM' : 'NÃO') . "\n";
    echo "  Token válido: " . ($token && $token !== 'x' ? 'SIM' : 'NÃO') . "\n";
    echo "  URL: https://$subdomain/\n";
    echo "\n";
}

// 8. Verifica versão do MoodleAPI
echo "🔧 VERIFICAÇÃO DO MOODLEAPI:\n";
echo str_repeat("-", 50) . "\n";

$reflection = new ReflectionClass('MoodleAPI');
$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

$methodsImportantes = ['buscarTodosUsuarios', 'buscarUsuarioPorCPF', 'buscarUsuarioPorEmail'];
foreach ($methodsImportantes as $method) {
    $existe = $reflection->hasMethod($method);
    echo "Método $method: " . ($existe ? '✅ EXISTE' : '❌ FALTANDO') . "\n";
}

echo "\nTotal de métodos públicos: " . count($methods) . "\n";

if (!$reflection->hasMethod('buscarTodosUsuarios')) {
    echo "\n⚠️  ATENÇÃO: Você precisa atualizar o arquivo src/MoodleAPI.php!\n";
    echo "   O método buscarTodosUsuarios() não foi encontrado.\n";
    echo "   Substitua o arquivo pela versão atualizada que foi fornecida.\n";
}
?>
?>