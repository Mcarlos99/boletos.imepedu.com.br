<?php
/**
 * Teste Rápido - Breu Branco
 * Arquivo: teste_rapido_breu_branco.php (colocar na raiz)
 */

session_start();
$_SESSION['admin_id'] = 1;

header('Content-Type: text/html; charset=UTF-8');

echo "<h2>🧪 Teste Rápido - Breu Branco</h2>";
echo "<pre>";

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

try {
    $polo = 'breubranco.imepedu.com.br';
    
    echo "1. 🔧 Testando configuração...\n";
    $token = MoodleConfig::getToken($polo);
    echo "   Token: " . ($token && $token !== 'x' ? '✅ Configurado' : '❌ Não configurado') . "\n";
    echo "   Polo ativo: " . (MoodleConfig::isActiveSubdomain($polo) ? '✅ Sim' : '❌ Não') . "\n\n";
    
    if (!$token || $token === 'x') {
        throw new Exception("Configure o token primeiro!");
    }
    
    echo "2. 🌐 Testando conexão...\n";
    $moodleAPI = new MoodleAPI($polo);
    $teste = $moodleAPI->testarConexao();
    echo "   Conexão: " . ($teste['sucesso'] ? '✅ OK' : '❌ Falhou') . "\n";
    if ($teste['sucesso']) {
        echo "   Site: " . $teste['nome_site'] . "\n";
    }
    echo "\n";
    
    echo "3. 📚 Buscando cursos...\n";
    $cursos = $moodleAPI->listarTodosCursos();
    echo "   Total encontrado: " . count($cursos) . "\n\n";
    
    if (count($cursos) > 0) {
        echo "4. ✅ CURSOS ENCONTRADOS:\n";
        foreach ($cursos as $curso) {
            echo "   • " . $curso['nome'] . " (Tipo: " . $curso['tipo'] . ")\n";
            if (isset($curso['categoria_original_id'])) {
                echo "     ID Categoria: " . $curso['categoria_original_id'] . "\n";
            }
            echo "     Total Alunos: " . ($curso['total_alunos'] ?? 0) . "\n";
            echo "\n";
        }
    } else {
        echo "4. ❌ NENHUM CURSO ENCONTRADO\n";
        echo "   Verifique os logs do sistema para mais detalhes.\n";
        echo "   Comando: tail -f /var/log/apache2/error.log | grep MoodleAPI\n\n";
    }
    
    echo "5. 🔍 Testando API diretamente...\n";
    $url = "/admin/api/buscar-cursos.php?polo=" . urlencode($polo);
    echo "   URL para testar: <a href='{$url}' target='_blank'>{$url}</a>\n\n";
    
    echo "6. 📋 RESUMO:\n";
    echo "   Configuração: " . ($token && $token !== 'x' ? '✅' : '❌') . "\n";
    echo "   Conexão: " . ($teste['sucesso'] ? '✅' : '❌') . "\n";
    echo "   Cursos encontrados: " . count($cursos) . "\n";
    echo "   Status: " . (count($cursos) > 0 ? '✅ FUNCIONANDO' : '⚠️ VERIFICAR LOGS') . "\n";
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
}

echo "</pre>";

echo "<h3>🔧 Próximos Passos:</h3>";
echo "<ol>";
echo "<li>Se não encontrou cursos, verifique os logs: <code>tail -f /var/log/apache2/error.log | grep MoodleAPI</code></li>";
echo "<li>Execute o debug detalhado: <a href='/debug_breu_branco.php' target='_blank'>debug_breu_branco.php</a></li>";
echo "<li>Teste a API diretamente: <a href='/admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br' target='_blank'>API</a></li>";
echo "<li>Se ainda não funcionar, pode ser necessário ajustar a validação dos nomes dos cursos</li>";
echo "</ol>";
?>