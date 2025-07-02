<?php
/**
 * Script de Diagnóstico - Dashboard Boletos IMEPEDU
 * Arquivo: dashboard-debug.php
 * 
 * Execute este arquivo para diagnosticar problemas no dashboard
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Diagnóstico do Dashboard - IMEPEDU</h1>";

// 1. Verificar sessão
echo "<h2>1. Verificação da Sessão</h2>";
if (!isset($_SESSION['aluno_cpf'])) {
    echo "❌ <strong>ERRO:</strong> aluno_cpf não está na sessão<br>";
    echo "Redirecionando para login...<br>";
    echo '<a href="/login.php">Ir para Login</a>';
    exit;
} else {
    echo "✅ aluno_cpf na sessão: " . $_SESSION['aluno_cpf'] . "<br>";
}

if (!isset($_SESSION['subdomain'])) {
    echo "❌ <strong>ERRO:</strong> subdomain não está na sessão<br>";
} else {
    echo "✅ subdomain na sessão: " . $_SESSION['subdomain'] . "<br>";
}

// 2. Verificar conexão com banco
echo "<h2>2. Verificação do Banco de Dados</h2>";
try {
    require_once 'config/database.php';
    $db = (new Database())->getConnection();
    echo "✅ Conexão com banco estabelecida<br>";
    
    // Testar consulta básica
    $stmt = $db->query("SELECT COUNT(*) as total FROM boletos");
    $result = $stmt->fetch();
    echo "✅ Total de boletos no sistema: " . $result['total'] . "<br>";
    
} catch (Exception $e) {
    echo "❌ <strong>ERRO no banco:</strong> " . $e->getMessage() . "<br>";
    exit;
}

// 3. Verificar aluno
echo "<h2>3. Verificação do Aluno</h2>";
try {
    require_once 'src/AlunoService.php';
    $alunoService = new AlunoService();
    
    $aluno = $alunoService->buscarAlunoPorCPFESubdomain($_SESSION['aluno_cpf'], $_SESSION['subdomain']);
    
    if (!$aluno) {
        echo "❌ <strong>ERRO:</strong> Aluno não encontrado<br>";
        echo "CPF: " . $_SESSION['aluno_cpf'] . "<br>";
        echo "Subdomain: " . $_SESSION['subdomain'] . "<br>";
        
        // Verificar se aluno existe em outro subdomain
        $stmt = $db->prepare("SELECT * FROM alunos WHERE cpf = ?");
        $stmt->execute([$_SESSION['aluno_cpf']]);
        $alunoOutroLocal = $stmt->fetch();
        
        if ($alunoOutroLocal) {
            echo "⚠️ Aluno existe mas em outro local:<br>";
            echo "Nome: " . $alunoOutroLocal['nome'] . "<br>";
            echo "Subdomain: " . $alunoOutroLocal['subdomain'] . "<br>";
        } else {
            echo "❌ Aluno não existe no banco de dados<br>";
        }
        exit;
    } else {
        echo "✅ Aluno encontrado:<br>";
        echo "ID: " . $aluno['id'] . "<br>";
        echo "Nome: " . $aluno['nome'] . "<br>";
        echo "CPF: " . $aluno['cpf'] . "<br>";
        echo "Subdomain: " . $aluno['subdomain'] . "<br>";
    }
} catch (Exception $e) {
    echo "❌ <strong>ERRO ao buscar aluno:</strong> " . $e->getMessage() . "<br>";
    exit;
}

// 4. Verificar boletos do aluno
echo "<h2>4. Verificação dos Boletos</h2>";
try {
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain as curso_subdomain
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$aluno['id']]);
    $todosBoletos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📊 <strong>Total de boletos encontrados:</strong> " . count($todosBoletos) . "<br><br>";
    
    if (empty($todosBoletos)) {
        echo "❌ <strong>PROBLEMA:</strong> Nenhum boleto encontrado para este aluno<br>";
        
        // Verificar se existem boletos para outros alunos
        $stmt = $db->query("SELECT COUNT(*) as total FROM boletos");
        $total = $stmt->fetch()['total'];
        echo "Total de boletos no sistema: " . $total . "<br>";
        
        if ($total > 0) {
            echo "⚠️ Existem boletos no sistema, mas não para este aluno<br>";
            
            // Verificar boletos por curso
            $stmt = $db->prepare("
                SELECT c.nome, c.subdomain, COUNT(b.id) as total_boletos
                FROM cursos c
                LEFT JOIN boletos b ON c.id = b.curso_id
                WHERE c.subdomain = ?
                GROUP BY c.id
            ");
            $stmt->execute([$_SESSION['subdomain']]);
            $cursosBoletos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h3>Boletos por curso no polo " . $_SESSION['subdomain'] . ":</h3>";
            foreach ($cursosBoletos as $curso) {
                echo "- " . $curso['nome'] . ": " . $curso['total_boletos'] . " boletos<br>";
            }
        }
    } else {
        echo "✅ Boletos encontrados! Detalhes:<br><br>";
        
        foreach ($todosBoletos as $index => $boleto) {
            echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 5px; border-radius: 5px;'>";
            echo "<strong>Boleto " . ($index + 1) . ":</strong><br>";
            echo "ID: " . $boleto['id'] . "<br>";
            echo "Número: " . $boleto['numero_boleto'] . "<br>";
            echo "Valor: R$ " . number_format($boleto['valor'], 2, ',', '.') . "<br>";
            echo "Status: " . $boleto['status'] . "<br>";
            echo "Vencimento: " . date('d/m/Y', strtotime($boleto['vencimento'])) . "<br>";
            echo "Curso: " . $boleto['curso_nome'] . "<br>";
            echo "Curso Subdomain: " . $boleto['curso_subdomain'] . "<br>";
            
            // Verificar desconto PIX
            if (isset($boleto['pix_desconto_disponivel'])) {
                echo "PIX Desconto Disponível: " . ($boleto['pix_desconto_disponivel'] ? 'SIM' : 'NÃO') . "<br>";
                if ($boleto['pix_desconto_disponivel']) {
                    echo "PIX Valor Desconto: R$ " . number_format($boleto['pix_valor_desconto'] ?? 0, 2, ',', '.') . "<br>";
                    echo "PIX Valor Mínimo: R$ " . number_format($boleto['pix_valor_minimo'] ?? 0, 2, ',', '.') . "<br>";
                    echo "PIX Desconto Usado: " . ($boleto['pix_desconto_usado'] ? 'SIM' : 'NÃO') . "<br>";
                }
            }
            
            echo "Created: " . $boleto['created_at'] . "<br>";
            echo "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ <strong>ERRO ao buscar boletos:</strong> " . $e->getMessage() . "<br>";
    echo "SQL Error: " . $db->errorInfo()[2] . "<br>";
}

// 5. Verificar estrutura das tabelas
echo "<h2>5. Verificação da Estrutura das Tabelas</h2>";
try {
    // Verificar se colunas PIX existem
    $stmt = $db->query("SHOW COLUMNS FROM boletos LIKE 'pix_%'");
    $colunasPixExistentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<strong>Colunas PIX na tabela boletos:</strong><br>";
    if (empty($colunasPixExistentes)) {
        echo "❌ Nenhuma coluna PIX encontrada - execute a migração SQL<br>";
    } else {
        foreach ($colunasPixExistentes as $coluna) {
            echo "✅ " . $coluna['Field'] . " (" . $coluna['Type'] . ")<br>";
        }
    }
    
    // Verificar tabela pix_gerados
    $stmt = $db->query("SHOW TABLES LIKE 'pix_gerados'");
    $tabelaPix = $stmt->fetch();
    
    if ($tabelaPix) {
        echo "✅ Tabela pix_gerados existe<br>";
    } else {
        echo "❌ Tabela pix_gerados não existe - execute a migração SQL<br>";
    }
    
} catch (Exception $e) {
    echo "⚠️ Erro ao verificar estrutura: " . $e->getMessage() . "<br>";
}

// 6. Verificar configurações de sessão
echo "<h2>6. Informações da Sessão Completa</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// 7. Verificar configurações do servidor
echo "<h2>7. Informações do Servidor</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server: " . $_SERVER['HTTP_HOST'] . "<br>";
echo "Request URI: " . $_SERVER['REQUEST_URI'] . "<br>";
echo "User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "<br>";

// 8. Teste de consulta específica do dashboard
echo "<h2>8. Teste da Consulta do Dashboard</h2>";
try {
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain,
               b.pix_desconto_disponivel,
               b.pix_desconto_usado,
               b.pix_valor_desconto,
               b.pix_valor_minimo
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ? 
        AND c.subdomain = ?
        ORDER BY b.vencimento ASC, b.created_at DESC
    ");
    $stmt->execute([$aluno['id'], $_SESSION['subdomain']]);
    $boletosConsulta = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Consulta específica do dashboard retornou: " . count($boletosConsulta) . " boletos<br>";
    
    if (count($boletosConsulta) != count($todosBoletos)) {
        echo "⚠️ <strong>PROBLEMA:</strong> Diferença na quantidade de boletos!<br>";
        echo "Consulta geral: " . count($todosBoletos) . " boletos<br>";
        echo "Consulta dashboard: " . count($boletosConsulta) . " boletos<br>";
        echo "Possível problema: filtro por subdomain do curso<br>";
    }
    
} catch (Exception $e) {
    echo "❌ <strong>ERRO na consulta do dashboard:</strong> " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h2>🔧 Ações Recomendadas</h2>";

if (empty($todosBoletos)) {
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;'>";
    echo "<strong>Sem boletos encontrados:</strong><br>";
    echo "1. Verifique se boletos foram criados para este aluno<br>";
    echo "2. Confirme se o aluno está matriculado no curso correto<br>";
    echo "3. Verifique se o subdomain do curso está correto<br>";
    echo "4. Execute upload de teste via /admin/upload-boletos.php<br>";
    echo "</div>";
} else {
    echo "<div style='background: #d1edff; padding: 15px; border-radius: 5px; border-left: 4px solid #17a2b8;'>";
    echo "<strong>Boletos encontrados mas não aparecem no dashboard:</strong><br>";
    echo "1. Verifique se a migração SQL foi aplicada completamente<br>";
    echo "2. Limpe cache do navegador (Ctrl+F5)<br>";
    echo "3. Verifique logs de erro do PHP<br>";
    echo "4. Teste o dashboard original em /dashboard.php<br>";
    echo "</div>";
}

echo "<hr>";
echo "<p><strong>Diagnóstico concluído em:</strong> " . date('d/m/Y H:i:s') . "</p>";
echo "<p><a href='/dashboard.php'>🔙 Voltar ao Dashboard</a> | <a href='/admin/upload-boletos.php'>📤 Upload de Boletos</a></p>";
?>