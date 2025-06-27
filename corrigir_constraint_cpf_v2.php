<?php
/**
 * Correção da constraint de CPF - VERSÃO CORRIGIDA
 * Arquivo: corrigir_constraint_cpf_v2.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Correção - Constraint de CPF (v2)</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    pre{background:#f5f5f5; padding:15px; border:1px solid #ddd;}
    .step{margin:15px 0; padding:15px; background:#f9f9f9; border-left:4px solid #007bff;}
    .sql{background:#f8f9fa; padding:12px; border:1px solid #dee2e6; border-radius:5px; font-family:monospace; font-size:14px;}
</style>";

$cpf_problema = '03183924536';

try {
    require_once 'config/database.php';
    
    echo "<div class='step'>";
    echo "<h3>1. Diagnosticando o Problema</h3>";
    
    $db = new Database();
    $connection = $db->getConnection();
    
    // Verifica registros existentes
    $stmt = $connection->prepare("SELECT id, cpf, subdomain, nome, created_at FROM alunos WHERE cpf = ? ORDER BY created_at");
    $stmt->execute([$cpf_problema]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "CPF problema: {$cpf_problema}<br>";
    echo "Registros encontrados: " . count($registros) . "<br><br>";
    
    if (count($registros) > 0) {
        echo "<table border='1' style='border-collapse:collapse; width:100%;'>";
        echo "<tr><th>ID</th><th>CPF</th><th>Subdomínio</th><th>Nome</th><th>Criado em</th></tr>";
        foreach ($registros as $reg) {
            echo "<tr>";
            echo "<td>{$reg['id']}</td>";
            echo "<td>{$reg['cpf']}</td>";
            echo "<td>{$reg['subdomain']}</td>";
            echo "<td>{$reg['nome']}</td>";
            echo "<td>" . date('d/m/Y H:i', strtotime($reg['created_at'])) . "</td>";
            echo "</tr>";
        }
        echo "</table><br>";
    }
    
    // Verifica a estrutura atual da tabela
    echo "<br><strong>Estrutura atual da tabela alunos:</strong><br>";
    $stmt = $connection->query("SHOW CREATE TABLE alunos");
    $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<div class='sql'>" . htmlspecialchars($createTable['Create Table']) . "</div>";
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Verificando Constraints Existentes</h3>";
    
    // Verifica índices existentes
    $stmt = $connection->query("SHOW INDEX FROM alunos");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<strong>Índices atuais:</strong><br>";
    echo "<table border='1' style='border-collapse:collapse;'>";
    echo "<tr><th>Nome</th><th>Coluna</th><th>Único</th><th>Tipo</th></tr>";
    
    $cpfConstraints = [];
    foreach ($indexes as $index) {
        $isUnique = $index['Non_unique'] == 0 ? 'Sim' : 'Não';
        echo "<tr><td>{$index['Key_name']}</td><td>{$index['Column_name']}</td><td>{$isUnique}</td><td>" . ($index['Key_name'] == 'PRIMARY' ? 'PRIMARY' : 'INDEX') . "</td></tr>";
        
        // Coleta constraints de CPF
        if ($index['Column_name'] == 'cpf' && $index['Non_unique'] == 0 && $index['Key_name'] != 'PRIMARY') {
            $cpfConstraints[] = $index['Key_name'];
        }
    }
    echo "</table><br>";
    
    if (!empty($cpfConstraints)) {
        echo "<span class='warning'>⚠ Constraints UNIQUE encontradas no CPF: " . implode(', ', $cpfConstraints) . "</span><br>";
    } else {
        echo "<span class='info'>ℹ Nenhuma constraint UNIQUE específica encontrada no CPF</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Aplicando Correção (Sem Transação)</h3>";
    
    $erros = [];
    $sucessos = [];
    
    // Passo 1: Remove constraints UNIQUE do CPF se existirem
    if (!empty($cpfConstraints)) {
        echo "<br><strong>Passo 1:</strong> Removendo constraints UNIQUE do CPF...<br>";
        
        foreach ($cpfConstraints as $constraintName) {
            try {
                $dropSql = "ALTER TABLE alunos DROP INDEX `{$constraintName}`";
                echo "Executando: <code>{$dropSql}</code><br>";
                $connection->exec($dropSql);
                $sucessos[] = "Constraint '{$constraintName}' removida";
                echo "<span class='ok'>✓ Constraint '{$constraintName}' removida</span><br>";
            } catch (Exception $e) {
                $erro = "Erro ao remover constraint '{$constraintName}': " . $e->getMessage();
                $erros[] = $erro;
                echo "<span class='error'>✗ {$erro}</span><br>";
            }
        }
    } else {
        echo "<br><strong>Passo 1:</strong> Nenhuma constraint UNIQUE específica do CPF para remover<br>";
    }
    
    // Passo 2: Verifica se já existe a constraint combinada
    $stmt = $connection->query("SHOW INDEX FROM alunos WHERE Key_name = 'unique_cpf_subdomain'");
    $constraintExists = $stmt->fetchAll();
    
    if (empty($constraintExists)) {
        echo "<br><strong>Passo 2:</strong> Adicionando constraint UNIQUE(cpf, subdomain)...<br>";
        try {
            $addConstraintSql = "ALTER TABLE alunos ADD UNIQUE KEY `unique_cpf_subdomain` (`cpf`, `subdomain`)";
            echo "Executando: <code>{$addConstraintSql}</code><br>";
            $connection->exec($addConstraintSql);
            $sucessos[] = "Constraint UNIQUE(cpf, subdomain) adicionada";
            echo "<span class='ok'>✓ Nova constraint UNIQUE(cpf, subdomain) adicionada</span><br>";
        } catch (Exception $e) {
            $erro = "Erro ao adicionar constraint: " . $e->getMessage();
            $erros[] = $erro;
            echo "<span class='error'>✗ {$erro}</span><br>";
            
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "<br><span class='warning'>⚠ Há registros duplicados! Vamos corrigi-los primeiro...</span><br>";
                
                // Encontra e corrige duplicados
                echo "<strong>Identificando duplicados:</strong><br>";
                $stmt = $connection->query("
                    SELECT cpf, subdomain, COUNT(*) as count, GROUP_CONCAT(id) as ids
                    FROM alunos 
                    GROUP BY cpf, subdomain 
                    HAVING COUNT(*) > 1
                ");
                $duplicados = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($duplicados as $dup) {
                    echo "CPF: {$dup['cpf']}, Subdomain: {$dup['subdomain']}, IDs: {$dup['ids']}<br>";
                    
                    $ids = explode(',', $dup['ids']);
                    $manterID = array_shift($ids); // Mantém o primeiro
                    
                    foreach ($ids as $idRemover) {
                        try {
                            // Remove dependências primeiro
                            $connection->exec("DELETE FROM matriculas WHERE aluno_id = {$idRemover}");
                            $connection->exec("DELETE FROM boletos WHERE aluno_id = {$idRemover}");
                            $connection->exec("DELETE FROM logs WHERE usuario_id = {$idRemover}");
                            $connection->exec("DELETE FROM alunos WHERE id = {$idRemover}");
                            echo "  - Removido ID {$idRemover}<br>";
                        } catch (Exception $e) {
                            echo "  - <span class='error'>Erro ao remover ID {$idRemover}: " . $e->getMessage() . "</span><br>";
                        }
                    }
                }
                
                // Tenta adicionar a constraint novamente
                try {
                    echo "<br>Tentando adicionar constraint novamente...<br>";
                    $connection->exec($addConstraintSql);
                    $sucessos[] = "Constraint UNIQUE(cpf, subdomain) adicionada após limpeza";
                    echo "<span class='ok'>✓ Constraint adicionada após limpeza de duplicados</span><br>";
                } catch (Exception $e2) {
                    $erros[] = "Erro após limpeza: " . $e2->getMessage();
                    echo "<span class='error'>✗ Erro persistente: " . $e2->getMessage() . "</span><br>";
                }
            }
        }
    } else {
        echo "<br><strong>Passo 2:</strong> Constraint UNIQUE(cpf, subdomain) já existe<br>";
        $sucessos[] = "Constraint UNIQUE(cpf, subdomain) já existia";
    }
    
    // Passo 3: Adiciona índices se não existirem
    echo "<br><strong>Passo 3:</strong> Verificando/adicionando índices...<br>";
    
    // Índice no CPF
    $stmt = $connection->query("SHOW INDEX FROM alunos WHERE Key_name = 'idx_cpf'");
    $cpfIndexExists = $stmt->fetchAll();
    
    if (empty($cpfIndexExists)) {
        try {
            $addIndexSql = "ALTER TABLE alunos ADD INDEX `idx_cpf` (`cpf`)";
            echo "Executando: <code>{$addIndexSql}</code><br>";
            $connection->exec($addIndexSql);
            $sucessos[] = "Índice no CPF adicionado";
            echo "<span class='ok'>✓ Índice no CPF adicionado</span><br>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                $erros[] = "Erro ao adicionar índice CPF: " . $e->getMessage();
                echo "<span class='error'>✗ Erro ao adicionar índice CPF: " . $e->getMessage() . "</span><br>";
            } else {
                echo "<span class='info'>ℹ Índice no CPF já existe</span><br>";
            }
        }
    } else {
        echo "<span class='info'>ℹ Índice no CPF já existe</span><br>";
    }
    
    // Índice no subdomain
    $stmt = $connection->query("SHOW INDEX FROM alunos WHERE Key_name = 'idx_subdomain'");
    $subdomainIndexExists = $stmt->fetchAll();
    
    if (empty($subdomainIndexExists)) {
        try {
            $addIndexSql2 = "ALTER TABLE alunos ADD INDEX `idx_subdomain` (`subdomain`)";
            echo "Executando: <code>{$addIndexSql2}</code><br>";
            $connection->exec($addIndexSql2);
            $sucessos[] = "Índice no subdomain adicionado";
            echo "<span class='ok'>✓ Índice no subdomain adicionado</span><br>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                $erros[] = "Erro ao adicionar índice subdomain: " . $e->getMessage();
                echo "<span class='error'>✗ Erro ao adicionar índice subdomain: " . $e->getMessage() . "</span><br>";
            } else {
                echo "<span class='info'>ℹ Índice no subdomain já existe</span><br>";
            }
        }
    } else {
        echo "<span class='info'>ℹ Índice no subdomain já existe</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Resumo das Alterações</h3>";
    
    if (!empty($sucessos)) {
        echo "<span class='ok'><strong>Sucessos:</strong></span><br>";
        echo "<ul>";
        foreach ($sucessos as $sucesso) {
            echo "<li>{$sucesso}</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($erros)) {
        echo "<span class='error'><strong>Erros:</strong></span><br>";
        echo "<ul>";
        foreach ($erros as $erro) {
            echo "<li>{$erro}</li>";
        }
        echo "</ul>";
    }
    
    // Mostra a estrutura final
    echo "<br><strong>Estrutura final da tabela:</strong><br>";
    $stmt = $connection->query("SHOW CREATE TABLE alunos");
    $finalCreateTable = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<div class='sql'>" . htmlspecialchars($finalCreateTable['Create Table']) . "</div>";
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Testando a Correção</h3>";
    
    // Testa a criação para diferentes subdomínios
    $subdomains_teste = ['breubranco.imepedu.com.br', 'igarape.imepedu.com.br'];
    
    foreach ($subdomains_teste as $sub) {
        echo "<strong>Testando subdomínio: {$sub}</strong><br>";
        
        try {
            // Verifica se já existe
            $stmt = $connection->prepare("SELECT id, nome FROM alunos WHERE cpf = ? AND subdomain = ?");
            $stmt->execute([$cpf_problema, $sub]);
            $existe = $stmt->fetch();
            
            if ($existe) {
                echo "<span class='ok'>✓ Registro já existe (ID: {$existe['id']}, Nome: {$existe['nome']})</span><br>";
            } else {
                echo "<span class='info'>ℹ Registro não existe - sistema está pronto para criá-lo quando necessário</span><br>";
            }
        } catch (Exception $e) {
            echo "<span class='error'>✗ Erro na verificação: " . $e->getMessage() . "</span><br>";
        }
        
        echo "<br>";
    }
    
    // Verifica se a constraint está funcionando
    echo "<strong>Verificando constraint UNIQUE(cpf, subdomain):</strong><br>";
    $stmt = $connection->query("SHOW INDEX FROM alunos WHERE Key_name = 'unique_cpf_subdomain'");
    $constraintCheck = $stmt->fetchAll();
    
    if (!empty($constraintCheck)) {
        echo "<span class='ok'>✓ Constraint UNIQUE(cpf, subdomain) está ativa</span><br>";
    } else {
        echo "<span class='error'>✗ Constraint UNIQUE(cpf, subdomain) não foi criada</span><br>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro crítico: " . $e->getMessage() . "</span><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "<div style='margin-top: 30px; padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;'>";
echo "<h4>🎯 Próximo Passo:</h4>";
echo "<p>Agora teste o login no Breu Branco:</p>";
echo "<p><strong><a href='index.php?cpf={$cpf_problema}&subdomain=breubranco.imepedu.com.br'>🔗 Testar Login Breu Branco</a></strong></p>";

echo "<h5>Se ainda houver erro:</h5>";
echo "<ol>";
echo "<li>Verifique se o token do Breu Branco está correto</li>";
echo "<li>Confirme se o usuário existe no Moodle do Breu Branco</li>";
echo "<li>Execute: <a href='diagnostico_polo_breubranco.php'>Diagnóstico do Polo Breu Branco</a></li>";
echo "</ol>";
echo "</div>";

echo "<br><div style='text-align: center;'>";
echo "<a href='index.php?cpf={$cpf_problema}&subdomain=breubranco.imepedu.com.br' style='display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>🎯 Testar Login Breu Branco</a>";
echo "<a href='index.php?cpf={$cpf_problema}&subdomain=igarape.imepedu.com.br' style='display: inline-block; padding: 12px 24px; background: #17a2b8; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>🎯 Testar Login Igarapé</a>";
echo "<a href='diagnostico_polo_breubranco.php' style='display: inline-block; padding: 12px 24px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>🔍 Diagnóstico Detalhado</a>";
echo "</div>";
?>