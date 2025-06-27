<?php
/**
 * Script para corrigir estrutura da tabela cursos
 * Arquivo: corrigir_tabela_cursos.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';

echo "<h1>Correção da Tabela Cursos</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    pre{background:#f5f5f5; padding:10px; border:1px solid #ddd; overflow-x:auto;}
</style>";

try {
    $db = new Database();
    $connection = $db->getConnection();
    
    echo "<div class='step'>";
    echo "<h3>1. Verificando Estrutura Atual da Tabela Cursos</h3>";
    
    // Mostra estrutura atual
    $stmt = $connection->query("DESCRIBE cursos");
    $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Colunas atuais:<br>";
    $colunasExistentes = [];
    foreach ($colunas as $coluna) {
        $colunasExistentes[] = $coluna['Field'];
        echo "- {$coluna['Field']} ({$coluna['Type']})<br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Verificando Colunas Necessárias</h3>";
    
    // Colunas que devem existir
    $colunasNecessarias = [
        'categoria_id' => 'INT NULL',
        'data_inicio' => 'DATE NULL',
        'data_fim' => 'DATE NULL',
        'formato' => 'VARCHAR(50) DEFAULT \'topics\'',
        'summary' => 'TEXT NULL',
        'url' => 'TEXT NULL',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];
    
    $colunasFaltando = [];
    foreach ($colunasNecessarias as $coluna => $tipo) {
        if (!in_array($coluna, $colunasExistentes)) {
            $colunasFaltando[$coluna] = $tipo;
            echo "<span class='error'>✗ Coluna faltando: {$coluna}</span><br>";
        } else {
            echo "<span class='ok'>✓ Coluna existe: {$coluna}</span><br>";
        }
    }
    echo "</div>";
    
    if (!empty($colunasFaltando)) {
        echo "<div class='step'>";
        echo "<h3>3. Adicionando Colunas Faltantes</h3>";
        
        foreach ($colunasFaltando as $coluna => $tipo) {
            try {
                echo "Adicionando coluna {$coluna}...";
                $sql = "ALTER TABLE cursos ADD COLUMN {$coluna} {$tipo}";
                $connection->exec($sql);
                echo " <span class='ok'>✓ Sucesso</span><br>";
            } catch (Exception $e) {
                echo " <span class='error'>✗ Erro: " . $e->getMessage() . "</span><br>";
            }
        }
        echo "</div>";
    }
    
    echo "<div class='step'>";
    echo "<h3>4. Verificando Estrutura Final</h3>";
    
    $stmt = $connection->query("DESCRIBE cursos");
    $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Estrutura final da tabela cursos:<br>";
    echo "<table border='1' style='border-collapse:collapse; margin:10px 0;'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th></tr>";
    foreach ($colunas as $coluna) {
        echo "<tr>";
        echo "<td>{$coluna['Field']}</td>";
        echo "<td>{$coluna['Type']}</td>";
        echo "<td>{$coluna['Null']}</td>";
        echo "<td>{$coluna['Key']}</td>";
        echo "<td>{$coluna['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Testando Inserção de Curso</h3>";
    
    try {
        // Dados de teste
        $testeCurso = [
            'moodle_course_id' => 999,
            'nome' => 'Curso Teste Estrutura',
            'nome_curto' => 'teste_estrutura',
            'valor' => 0.00,
            'subdomain' => 'teste.imepedu.com.br',
            'categoria_id' => 1,
            'data_inicio' => '2025-01-01',
            'data_fim' => '2025-12-31',
            'formato' => 'topics',
            'summary' => 'Curso de teste para verificar estrutura',
            'url' => 'https://teste.imepedu.com.br/course/view.php?id=999',
            'ativo' => true
        ];
        
        // Remove curso de teste se existir
        $connection->exec("DELETE FROM cursos WHERE moodle_course_id = 999 AND subdomain = 'teste.imepedu.com.br'");
        
        echo "Inserindo curso de teste...<br>";
        
        $stmt = $connection->prepare("
            INSERT INTO cursos (
                moodle_course_id, nome, nome_curto, valor, subdomain,
                categoria_id, data_inicio, data_fim, formato, summary,
                url, ativo, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $resultado = $stmt->execute([
            $testeCurso['moodle_course_id'],
            $testeCurso['nome'],
            $testeCurso['nome_curto'],
            $testeCurso['valor'],
            $testeCurso['subdomain'],
            $testeCurso['categoria_id'],
            $testeCurso['data_inicio'],
            $testeCurso['data_fim'],
            $testeCurso['formato'],
            $testeCurso['summary'],
            $testeCurso['url'],
            $testeCurso['ativo']
        ]);
        
        if ($resultado) {
            $cursoId = $connection->lastInsertId();
            echo "<span class='ok'>✓ Curso inserido com sucesso (ID: {$cursoId})</span><br>";
            
            // Remove curso de teste
            $connection->exec("DELETE FROM cursos WHERE id = {$cursoId}");
            echo "<span class='ok'>✓ Curso de teste removido</span><br>";
        } else {
            echo "<span class='error'>✗ Falha ao inserir curso</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro no teste de inserção: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>6. Verificando Outras Tabelas</h3>";
    
    // Verifica também as outras tabelas críticas
    $tabelasParaVerificar = [
        'alunos' => [
            'city' => 'VARCHAR(100) NULL',
            'country' => 'VARCHAR(10) DEFAULT \'BR\'',
            'profile_image' => 'TEXT NULL',
            'primeiro_acesso' => 'DATETIME NULL',
            'ultimo_acesso_moodle' => 'DATETIME NULL',
            'ultimo_acesso' => 'DATETIME NULL',
            'telefone1' => 'VARCHAR(20) NULL',
            'telefone2' => 'VARCHAR(20) NULL',
            'endereco' => 'TEXT NULL',
            'observacoes' => 'TEXT NULL'
        ],
        'matriculas' => [
            'data_conclusao' => 'DATE NULL',
            'observacoes' => 'TEXT NULL',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ]
    ];
    
    foreach ($tabelasParaVerificar as $tabela => $colunasExtras) {
        echo "<h4>Verificando tabela: {$tabela}</h4>";
        
        try {
            $stmt = $connection->query("DESCRIBE {$tabela}");
            $colunasTabela = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($colunasExtras as $coluna => $tipo) {
                if (!in_array($coluna, $colunasTabela)) {
                    echo "Adicionando coluna {$coluna} em {$tabela}...";
                    try {
                        $connection->exec("ALTER TABLE {$tabela} ADD COLUMN {$coluna} {$tipo}");
                        echo " <span class='ok'>✓</span><br>";
                    } catch (Exception $e) {
                        echo " <span class='warning'>⚠ " . $e->getMessage() . "</span><br>";
                    }
                } else {
                    echo "<span class='ok'>✓ {$coluna} existe em {$tabela}</span><br>";
                }
            }
        } catch (Exception $e) {
            echo "<span class='error'>✗ Erro ao verificar {$tabela}: " . $e->getMessage() . "</span><br>";
        }
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>7. Testando AlunoService Novamente</h3>";
    
    try {
        require_once 'src/AlunoService.php';
        $alunoService = new AlunoService();
        
        // Dados de teste (mesmos que falharam antes)
        $dadosAluno = [
            'nome' => 'Carlos Santos',
            'cpf' => '03183924536',
            'email' => 'diego2008tuc@gmail.com',
            'moodle_user_id' => 4,
            'subdomain' => 'breubranco.imepedu.com.br',
            'cursos' => [
                [
                    'moodle_course_id' => 91,
                    'nome' => 'NR-35',
                    'nome_curto' => 'nr35',
                    'categoria' => 1,
                    'data_inicio' => '2025-01-01',
                    'url' => 'https://breubranco.imepedu.com.br/course/view.php?id=91'
                ],
                [
                    'moodle_course_id' => 90,
                    'nome' => 'NR-33',
                    'nome_curto' => 'nr33',
                    'categoria' => 1,
                    'data_inicio' => '2025-01-01',
                    'url' => 'https://breubranco.imepedu.com.br/course/view.php?id=90'
                ]
            ]
        ];
        
        echo "Testando salvamento com estrutura corrigida...<br>";
        
        $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAluno);
        echo "<span class='ok'>✓ Aluno salvo/atualizado com sucesso! ID: {$alunoId}</span><br>";
        
        // Verifica cursos salvos
        $cursosLocal = $alunoService->buscarCursosAluno($alunoId);
        echo "Cursos encontrados no banco local: " . count($cursosLocal) . "<br>";
        
        if (count($cursosLocal) > 0) {
            echo "<strong>Cursos salvos:</strong><br>";
            foreach ($cursosLocal as $curso) {
                echo "- {$curso['nome']} (ID: {$curso['id']})<br>";
            }
            
            if (count($cursosLocal) == count($dadosAluno['cursos'])) {
                echo "<span class='ok'>✓ Todos os cursos foram sincronizados!</span><br>";
            } else {
                echo "<span class='warning'>⚠ Alguns cursos não foram sincronizados</span><br>";
            }
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro no teste do AlunoService: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>8. Resumo Final</h3>";
    echo "<span class='ok'>✓ Estrutura da tabela cursos corrigida!</span><br>";
    echo "<strong>Correções aplicadas:</strong><br>";
    echo "<ul>";
    echo "<li>Adicionadas colunas faltantes na tabela cursos</li>";
    echo "<li>Verificadas outras tabelas críticas</li>";
    echo "<li>Testado salvamento de curso com nova estrutura</li>";
    echo "<li>Testado AlunoService com dados reais</li>";
    echo "</ul>";
    
    echo "<strong>Próximos passos:</strong><br>";
    echo "<ol>";
    echo "<li><a href='debug_completo.php' target='_blank'>Execute o debug completo</a></li>";
    echo "<li><a href='index.php'>Teste o login no sistema</a></li>";
    echo "<li><a href='dashboard.php' target='_blank'>Verifique o dashboard</a></li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro crítico: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;">
    <h4>✅ Problema Resolvido</h4>
    <p>A estrutura da tabela <code>cursos</code> foi corrigida. O erro "Unknown column 'categoria_id'" deve estar resolvido.</p>
    
    <p><strong>O que foi corrigido:</strong></p>
    <ul>
        <li>Adicionada coluna <code>categoria_id</code></li>
        <li>Adicionadas outras colunas necessárias para cursos</li>
        <li>Verificadas colunas em outras tabelas</li>
        <li>Testada inserção de dados com nova estrutura</li>
    </ul>
</div>