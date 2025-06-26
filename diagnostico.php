<?php
/**
 * Sistema de Boletos IMED - Diagnóstico
 * Arquivo: public/diagnostico.php
 * 
 * Script para verificar problemas de configuração
 */

// Habilita exibição de erros
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Diagnóstico do Sistema de Boletos</h1>";
echo "<style>body{font-family:Arial;} .ok{color:green;} .error{color:red;} .warning{color:orange;}</style>";

// 1. Verificar PHP
echo "<h2>1. Versão do PHP</h2>";
echo "Versão: " . PHP_VERSION;
if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    echo " <span class='ok'>✓ OK</span>";
} else {
    echo " <span class='error'>✗ Versão muito antiga</span>";
}
echo "<br>";

// 2. Verificar extensões necessárias
echo "<h2>2. Extensões PHP</h2>";
$extensoes = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'];
foreach ($extensoes as $ext) {
    echo "Extensão {$ext}: ";
    if (extension_loaded($ext)) {
        echo "<span class='ok'>✓ Carregada</span>";
    } else {
        echo "<span class='error'>✗ Não encontrada</span>";
    }
    echo "<br>";
}

// 3. Verificar estrutura de arquivos
echo "<h2>3. Estrutura de Arquivos</h2>";
$arquivos = [
    'config/database.php',
    'config/moodle.php',
    'src/AlunoService.php',
    'src/MoodleAPI.php'
];

foreach ($arquivos as $arquivo) {
    echo "Arquivo {$arquivo}: ";
    if (file_exists($arquivo)) {
        echo "<span class='ok'>✓ Existe</span>";
        if (is_readable($arquivo)) {
            echo " <span class='ok'>✓ Legível</span>";
        } else {
            echo " <span class='error'>✗ Não legível</span>";
        }
    } else {
        echo "<span class='error'>✗ Não encontrado</span>";
    }
    echo "<br>";
}

// 4. Verificar permissões de pasta
echo "<h2>4. Permissões</h2>";
$pastas = ['.', 'config', 'src', 'logs'];
foreach ($pastas as $pasta) {
    if (is_dir($pasta)) {
        echo "Pasta {$pasta}: ";
        if (is_writable($pasta)) {
            echo "<span class='ok'>✓ Gravável</span>";
        } else {
            echo "<span class='warning'>⚠ Somente leitura</span>";
        }
        echo "<br>";
    }
}

// 5. Teste de conexão com banco
echo "<h2>5. Teste de Banco de Dados</h2>";
try {
    // Testa conexão direta primeiro
    $dsn = "mysql:host=localhost;dbname=boletodb;charset=utf8mb4";
    $pdo = new PDO($dsn, 'boletouser', 'gg3V6cNafyqsukXEJCcQ');
    echo "Conexão direta: <span class='ok'>✓ Sucesso</span><br>";
    
    // Testa se as tabelas existem
    $tabelas = ['alunos', 'cursos', 'matriculas', 'boletos', 'administradores'];
    foreach ($tabelas as $tabela) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tabela]);
        if ($stmt->fetch()) {
            echo "Tabela {$tabela}: <span class='ok'>✓ Existe</span><br>";
        } else {
            echo "Tabela {$tabela}: <span class='error'>✗ Não encontrada</span><br>";
        }
    }
    
} catch (Exception $e) {
    echo "Erro na conexão: <span class='error'>" . $e->getMessage() . "</span><br>";
}

// 6. Teste da classe Database
echo "<h2>6. Teste da Classe Database</h2>";
try {
    if (file_exists('config/database.php')) {
        require_once 'config/database.php';
        $db = new Database();
        $connection = $db->getConnection();
        echo "Classe Database: <span class='ok'>✓ Funcionando</span><br>";
        
        // Teste de query simples
        $stmt = $connection->query("SELECT 1 as teste");
        $result = $stmt->fetch();
        if ($result['teste'] == 1) {
            echo "Query de teste: <span class='ok'>✓ Funcionando</span><br>";
        }
    } else {
        echo "Arquivo database.php: <span class='error'>✗ Não encontrado</span><br>";
    }
} catch (Exception $e) {
    echo "Erro na classe Database: <span class='error'>" . $e->getMessage() . "</span><br>";
}

// 7. Teste da configuração Moodle
echo "<h2>7. Teste da Configuração Moodle</h2>";
try {
    if (file_exists('config/moodle.php')) {
        require_once 'config/moodle.php';
        $subdomains = MoodleConfig::getAllSubdomains();
        echo "Subdomínios configurados: <span class='ok'>" . count($subdomains) . "</span><br>";
        
        foreach ($subdomains as $subdomain) {
            $token = MoodleConfig::getToken($subdomain);
            echo "Token para {$subdomain}: ";
            if ($token) {
                echo "<span class='ok'>✓ Configurado</span>";
            } else {
                echo "<span class='error'>✗ Não configurado</span>";
            }
            echo "<br>";
        }
    } else {
        echo "Arquivo moodle.php: <span class='error'>✗ Não encontrado</span><br>";
    }
} catch (Exception $e) {
    echo "Erro na configuração Moodle: <span class='error'>" . $e->getMessage() . "</span><br>";
}

// 8. Verificar logs de erro
echo "<h2>8. Logs de Erro</h2>";
$errorLog = ini_get('error_log');
if ($errorLog && file_exists($errorLog)) {
    echo "Arquivo de log: {$errorLog}<br>";
    $lastErrors = array_slice(file($errorLog), -10);
    foreach ($lastErrors as $error) {
        echo "<small>" . htmlspecialchars($error) . "</small><br>";
    }
} else {
    echo "Log de erro não configurado ou não acessível<br>";
}

// 9. Informações do servidor
echo "<h2>9. Informações do Servidor</h2>";
echo "Servidor Web: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "Script atual: " . $_SERVER['SCRIPT_FILENAME'] . "<br>";
echo "Memória disponível: " . ini_get('memory_limit') . "<br>";
echo "Tempo máximo de execução: " . ini_get('max_execution_time') . "s<br>";

// 10. Teste do index.php
echo "<h2>10. Teste do index.php</h2>";
if (file_exists('index.php')) {
    echo "Arquivo index.php: <span class='ok'>✓ Existe</span><br>";
    
    // Verifica se não há erros de sintaxe
    $output = shell_exec('php -l index.php 2>&1');
    if (strpos($output, 'No syntax errors') !== false) {
        echo "Sintaxe do index.php: <span class='ok'>✓ OK</span><br>";
    } else {
        echo "Sintaxe do index.php: <span class='error'>✗ Erro</span><br>";
        echo "<pre>" . htmlspecialchars($output) . "</pre>";
    }
} else {
    echo "Arquivo index.php: <span class='error'>✗ Não encontrado</span><br>";
}

echo "<h2>Resumo</h2>";
echo "<p>Se todos os itens estão marcados como OK (✓), o sistema deve funcionar.</p>";
echo "<p>Itens com erro (✗) precisam ser corrigidos antes do sistema funcionar.</p>";
echo "<p>Acesse novamente <a href='index.php'>index.php</a> após corrigir os problemas.</p>";
?>