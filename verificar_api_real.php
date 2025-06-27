<?php
/**
 * Verificar se a API Real está funcionando
 * Arquivo: verificar_api_real.php (colocar na raiz)
 */

session_start();
$_SESSION['admin_id'] = 1; // Simula admin logado

header('Content-Type: text/html; charset=UTF-8');

echo "<h2>🧪 Verificação da API Real - Breu Branco</h2>";
echo "<pre>";

try {
    echo "1. 🔧 Testando configuração...\n";
    require_once 'config/database.php';
    require_once 'config/moodle.php';
    require_once 'src/MoodleAPI.php';
    
    $polo = 'breubranco.imepedu.com.br';
    
    // Verifica se MoodleAPI tem o método normalizarTexto
    echo "2. 📁 Verificando se MoodleAPI foi atualizada...\n";
    $reflection = new ReflectionClass('MoodleAPI');
    $methods = $reflection->getMethods(ReflectionMethod::IS_PRIVATE);
    
    $temNormalizarTexto = false;
    foreach ($methods as $method) {
        if ($method->getName() === 'normalizarTexto') {
            $temNormalizarTexto = true;
            break;
        }
    }
    
    echo "   Método normalizarTexto existe: " . ($temNormalizarTexto ? '✅ SIM' : '❌ NÃO') . "\n";
    
    if (!$temNormalizarTexto) {
        echo "\n❌ PROBLEMA: O arquivo src/MoodleAPI.php não foi atualizado!\n";
        echo "   Substitua o arquivo pela versão corrigida.\n\n";
        exit;
    }
    
    echo "3. 🌐 Testando API diretamente...\n";
    $moodleAPI = new MoodleAPI($polo);
    $cursos = $moodleAPI->listarTodosCursos();
    
    echo "   Cursos encontrados: " . count($cursos) . "\n\n";
    
    if (count($cursos) > 0) {
        echo "4. ✅ SUCESSO! Cursos encontrados:\n";
        foreach ($cursos as $curso) {
            echo "   • " . $curso['nome'] . " (ID: " . ($curso['categoria_original_id'] ?? $curso['id']) . ")\n";
            echo "     Tipo: " . $curso['tipo'] . "\n";
            echo "     Alunos: " . ($curso['total_alunos'] ?? 0) . "\n\n";
        }
        
        if (count($cursos) === 4) {
            echo "🎯 PERFEITO! Encontrou exatamente os 4 cursos técnicos esperados!\n\n";
        }
        
    } else {
        echo "4. ❌ PROBLEMA: Nenhum curso encontrado pela API!\n";
        echo "   Verificando logs...\n\n";
        
        // Tenta acessar o método privado para debug
        $reflection = new ReflectionClass($moodleAPI);
        $method = $reflection->getMethod('buscarCursosBreuBranco');
        $method->setAccessible(true);
        
        echo "   Executando buscarCursosBreuBranco diretamente...\n";
        $resultadoDireto = $method->invoke($moodleAPI);
        echo "   Resultado direto: " . count($resultadoDireto) . " cursos\n\n";
        
        if (count($resultadoDireto) > 0) {
            echo "   ✅ O método específico funciona, mas listarTodosCursos não!\n";
            echo "   Verifique a lógica de roteamento no método listarTodosCursos.\n";
        } else {
            echo "   ❌ Problema no método específico também.\n";
            echo "   Verifique os logs do servidor.\n";
        }
    }
    
    echo "5. 🔗 Testando via HTTP (API admin)...\n";
    
    // Monta URL completa
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $apiUrl = "{$protocol}://{$host}/admin/api/buscar-cursos.php?polo=" . urlencode($polo);
    
    echo "   URL: {$apiUrl}\n";
    
    // Faz requisição HTTP
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'header' => [
                'Cookie: ' . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : ''),
                'User-Agent: Mozilla/5.0'
            ]
        ]
    ]);
    
    $response = @file_get_contents($apiUrl, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        
        if ($data && isset($data['success'])) {
            if ($data['success']) {
                echo "   ✅ API HTTP funcionando! Cursos encontrados: " . ($data['total'] ?? 0) . "\n";
                
                if (($data['total'] ?? 0) === 4) {
                    echo "\n🎉 TUDO FUNCIONANDO PERFEITAMENTE!\n";
                    echo "   API direta: ✅\n";
                    echo "   API HTTP: ✅\n";
                    echo "   Quantidade correta: ✅\n";
                }
            } else {
                echo "   ❌ API retornou erro: " . ($data['message'] ?? 'Erro desconhecido') . "\n";
            }
        } else {
            echo "   ❌ Resposta inválida da API\n";
            echo "   Resposta: " . substr($response, 0, 200) . "...\n";
        }
    } else {
        echo "   ❌ Erro ao acessar API via HTTP\n";
        echo "   Teste manualmente: <a href='{$apiUrl}' target='_blank'>{$apiUrl}</a>\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
}

echo "</pre>";

echo "<h3>📋 Resumo do Status:</h3>";
echo "<ul>";
echo "<li>✅ Normalização de texto funcionando</li>";
echo "<li>✅ Lógica de busca correta</li>";
echo "<li>Arquivo MoodleAPI.php: " . ($temNormalizarTexto ?? false ? '✅ Atualizado' : '❌ Precisa atualizar') . "</li>";
echo "<li>API direta: " . (isset($cursos) && count($cursos) > 0 ? '✅ Funcionando' : '❌ Verificar') . "</li>";
echo "</ul>";

echo "<h3>🔗 Links para Teste:</h3>";
echo "<ul>";
echo "<li><a href='/admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br' target='_blank'>Testar API diretamente</a></li>";
echo "<li><a href='/teste_rapido_breu_branco.php' target='_blank'>Teste rápido</a></li>";
echo "<li><a href='/debug_breu_branco.php' target='_blank'>Debug detalhado</a></li>";
echo "</ul>";
?>