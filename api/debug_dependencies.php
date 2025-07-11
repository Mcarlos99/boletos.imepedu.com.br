<?php
/**
 * ARQUIVO DE DEBUG: debug_dependencies.php
 * Coloque na pasta /api/ para verificar se todas as dependências estão OK
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>🔍 Verificação de Dependências - Sistema IMEPEDU</h1>";

// Verifica PHP
echo "<h2>📋 Informações do PHP</h2>";
echo "<p><strong>Versão PHP:</strong> " . phpversion() . "</p>";
echo "<p><strong>Memory Limit:</strong> " . ini_get('memory_limit') . "</p>";
echo "<p><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . "s</p>";

// Verifica arquivos principais
echo "<h2>📁 Verificação de Arquivos</h2>";

$arquivos = [
    'config/database.php' => '../config/database.php',
    'config/moodle.php' => '../config/moodle.php',
    'src/AlunoService.php' => '../src/AlunoService.php',
    'src/BoletoService.php' => '../src/BoletoService.php',
    'src/DocumentosService.php' => '../src/DocumentosService.php',
    'src/MoodleAPI.php' => '../src/MoodleAPI.php'
];

echo "<ul>";
foreach ($arquivos as $nome => $caminho) {
    $existe = file_exists($caminho);
    $icon = $existe ? "✅" : "❌";
    $status = $existe ? "OK" : "MISSING";
    echo "<li>{$icon} <strong>{$nome}:</strong> {$status}</li>";
    
    if ($existe) {
        $tamanho = filesize($caminho);
        echo "<ul><li>Tamanho: " . number_format($tamanho) . " bytes</li></ul>";
    }
}
echo "</ul>";

// Testa includes básicos
echo "<h2>🔗 Teste de Includes</h2>";

try {
    echo "<p>🔄 Testando database.php...</p>";
    require_once '../config/database.php';
    echo "<p>✅ Database: OK</p>";
    
    $db = new Database();
    $connection = $db->getConnection();
    echo "<p>✅ Conexão DB: OK</p>";
    
} catch (Exception $e) {
    echo "<p>❌ Erro Database: " . $e->getMessage() . "</p>";
}

try {
    echo "<p>🔄 Testando AlunoService.php...</p>";
    if (file_exists('../src/AlunoService.php')) {
        require_once '../src/AlunoService.php';
        $alunoService = new AlunoService();
        echo "<p>✅ AlunoService: OK</p>";
    } else {
        echo "<p>❌ AlunoService.php não encontrado</p>";
    }
} catch (Exception $e) {
    echo "<p>❌ Erro AlunoService: " . $e->getMessage() . "</p>";
}

try {
    echo "<p>🔄 Testando DocumentosService.php...</p>";
    if (file_exists('../src/DocumentosService.php')) {
        require_once '../src/DocumentosService.php';
        $documentosService = new DocumentosService();
        echo "<p>✅ DocumentosService: OK</p>";
        
        // Testa método específico
        $tipos = DocumentosService::getTiposDocumentos();
        echo "<p>✅ Tipos de documentos: " . count($tipos) . " tipos carregados</p>";
        
    } else {
        echo "<p>❌ DocumentosService.php não encontrado</p>";
    }
} catch (Exception $e) {
    echo "<p>❌ Erro DocumentosService: " . $e->getMessage() . "</p>";
    echo "<p>🔍 Linha: " . $e->getLine() . " | Arquivo: " . $e->getFile() . "</p>";
}

// Verifica sessão
echo "<h2>🔐 Verificação de Sessão</h2>";

session_start();
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Variáveis de Sessão:</strong></p>";
echo "<ul>";
foreach ($_SESSION as $key => $value) {
    if (is_string($value) && strlen($value) > 50) {
        $value = substr($value, 0, 50) . "...";
    }
    echo "<li><strong>{$key}:</strong> " . htmlspecialchars($value) . "</li>";
}
echo "</ul>";

// Testa API simples
echo "<h2>🔬 Teste de API Simples</h2>";

if (isset($_SESSION['aluno_id'])) {
    try {
        echo "<p>🔄 Testando API de documentos...</p>";
        
        $alunoId = $_SESSION['aluno_id'];
        
        if (class_exists('DocumentosService')) {
            $documentosService = new DocumentosService();
            $documentos = $documentosService->listarDocumentosAluno($alunoId);
            echo "<p>✅ API Documentos: " . count($documentos) . " documentos encontrados</p>";
        }
        
    } catch (Exception $e) {
        echo "<p>❌ Erro API: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>⚠️ Aluno não logado - faça login primeiro</p>";
}

// Verifica logs
echo "<h2>📝 Logs Recentes</h2>";

$logPath = '../logs/';
if (is_dir($logPath)) {
    $logs = glob($logPath . '*.log');
    if (empty($logs)) {
        echo "<p>ℹ️ Nenhum arquivo de log encontrado</p>";
    } else {
        echo "<ul>";
        foreach (array_slice($logs, -5) as $log) {
            $tamanho = filesize($log);
            echo "<li>" . basename($log) . " (" . number_format($tamanho) . " bytes)</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p>⚠️ Pasta de logs não encontrada</p>";
}

// Verifica erro_log padrão
if (function_exists('error_get_last')) {
    $lastError = error_get_last();
    if ($lastError) {
        echo "<h2>⚠️ Último Erro PHP</h2>";
        echo "<p><strong>Tipo:</strong> " . $lastError['type'] . "</p>";
        echo "<p><strong>Mensagem:</strong> " . htmlspecialchars($lastError['message']) . "</p>";
        echo "<p><strong>Arquivo:</strong> " . $lastError['file'] . "</p>";
        echo "<p><strong>Linha:</strong> " . $lastError['line'] . "</p>";
    }
}

echo "<hr>";
echo "<p><strong>✅ Verificação concluída em:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><small>🔍 Para testar APIs específicas, acesse:</small></p>";
echo "<ul>";
echo "<li><a href='documentos-aluno.php'>documentos-aluno.php</a></li>";
echo "<li><a href='atualizar_dados.php'>atualizar_dados.php</a> (POST)</li>";
echo "</ul>";
?>