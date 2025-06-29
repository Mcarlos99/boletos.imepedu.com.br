<?php
/**
 * DEBUG - Teste de Conexão Polo
 * Arquivo temporário para identificar problemas
 * Salve como: /admin/api/debug-teste-polo.php
 */

// Ativa exibição de erros para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

echo "=== DEBUG TESTE POLO ===\n";

// Teste 1: Verificar sessão
echo "1. Sessão Admin: " . ($_SESSION['admin_id'] ?? 'NÃO LOGADO') . "\n";

// Teste 2: Verificar arquivos
$arquivos = [
    '../../config/database.php',
    '../../config/moodle.php', 
    '../../src/MoodleAPI.php'
];

echo "2. Verificando arquivos:\n";
foreach ($arquivos as $arquivo) {
    $existe = file_exists($arquivo);
    $tamanho = $existe ? filesize($arquivo) : 0;
    echo "   {$arquivo}: " . ($existe ? "✅ ({$tamanho} bytes)" : "❌ NÃO EXISTE") . "\n";
}

// Teste 3: Testar includes
echo "3. Testando includes:\n";
try {
    if (file_exists('../../config/database.php')) {
        require_once '../../config/database.php';
        echo "   Database.php: ✅\n";
        echo "   Classe Database: " . (class_exists('Database') ? "✅" : "❌") . "\n";
    }
    
    if (file_exists('../../config/moodle.php')) {
        require_once '../../config/moodle.php';
        echo "   Moodle.php: ✅\n";
        echo "   Classe MoodleConfig: " . (class_exists('MoodleConfig') ? "✅" : "❌") . "\n";
    }
    
    if (file_exists('../../src/MoodleAPI.php')) {
        require_once '../../src/MoodleAPI.php';
        echo "   MoodleAPI.php: ✅\n";
        echo "   Classe MoodleAPI: " . (class_exists('MoodleAPI') ? "✅" : "❌") . "\n";
    }
    
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
    echo "   Linha: " . $e->getLine() . "\n";
}

// Teste 4: Verificar input
echo "4. Input recebido:\n";
$input = file_get_contents('php://input');
echo "   Raw: " . $input . "\n";
$decoded = json_decode($input, true);
echo "   JSON válido: " . (json_last_error() === JSON_ERROR_NONE ? "✅" : "❌ " . json_last_error_msg()) . "\n";
if ($decoded) {
    echo "   Polo: " . ($decoded['polo'] ?? 'NÃO DEFINIDO') . "\n";
}

// Teste 5: Verificar banco
echo "5. Testando banco:\n";
try {
    if (class_exists('Database')) {
        $db = (new Database())->getConnection();
        echo "   Conexão DB: ✅\n";
        
        // Testa query simples
        $stmt = $db->query("SELECT 1 as teste");
        $resultado = $stmt->fetch();
        echo "   Query teste: " . ($resultado['teste'] == 1 ? "✅" : "❌") . "\n";
    } else {
        echo "   Classe Database não existe\n";
    }
} catch (Exception $e) {
    echo "   ERRO DB: " . $e->getMessage() . "\n";
}

// Teste 6: Verificar MoodleConfig
echo "6. Testando MoodleConfig:\n";
try {
    if (class_exists('MoodleConfig')) {
        $subdomains = MoodleConfig::getAllSubdomains();
        echo "   Subdomains encontrados: " . count($subdomains) . "\n";
        
        $polo_teste = 'breubranco.imepedu.com.br';
        $config = MoodleConfig::getConfig($polo_teste);
        echo "   Config breubranco: " . (empty($config) ? "❌ Vazio" : "✅ Encontrado") . "\n";
        
        if (!empty($config)) {
            echo "   Token configurado: " . (!empty($config['token']) && $config['token'] !== 'x' ? "✅" : "❌") . "\n";
        }
    } else {
        echo "   Classe MoodleConfig não existe\n";
    }
} catch (Exception $e) {
    echo "   ERRO MoodleConfig: " . $e->getMessage() . "\n";
}

// Teste 7: Verificar logs de erro
echo "7. Verificando logs de erro:\n";
$log_file = '/tmp/php_errors.log';
if (file_exists($log_file)) {
    $logs = tail($log_file, 5);
    echo "   Últimos 5 erros PHP:\n";
    foreach ($logs as $log) {
        echo "   > " . $log . "\n";
    }
} else {
    echo "   Arquivo de log não encontrado\n";
}

// Teste 8: Info do servidor
echo "8. Info do servidor:\n";
echo "   PHP Version: " . PHP_VERSION . "\n";
echo "   Memory Limit: " . ini_get('memory_limit') . "\n";
echo "   Max Execution Time: " . ini_get('max_execution_time') . "\n";
echo "   Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "   Script Name: " . $_SERVER['SCRIPT_NAME'] . "\n";

// Função auxiliar para ler final do arquivo
function tail($filename, $lines = 10) {
    if (!file_exists($filename)) return [];
    
    $handle = fopen($filename, "r");
    if (!$handle) return [];
    
    $linecounter = $lines;
    $pos = -2;
    $beginning = false;
    $text = [];
    
    while ($linecounter > 0) {
        $t = " ";
        while ($t != "\n") {
            if (fseek($handle, $pos, SEEK_END) == -1) {
                $beginning = true;
                break;
            }
            $t = fgetc($handle);
            $pos--;
        }
        $linecounter--;
        if ($beginning) {
            rewind($handle);
        }
        $text[$lines - $linecounter - 1] = fgets($handle);
        if ($beginning) break;
    }
    fclose($handle);
    return array_reverse($text);
}

echo "\n=== FIM DEBUG ===\n";
?>