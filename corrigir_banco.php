<?php
/**
 * Script para verificar e corrigir problemas no banco de dados
 * Arquivo: corrigir_banco.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';

echo "<h1>Correção do Banco de Dados</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    .fix{background:#e7f3ff; border-left-color:#007bff;}
    .problem{background:#ffebee; border-left-color:#f44336;}
</style>";

try {
    $db = new Database();
    $connection = $db->getConnection();
    
    echo "<div class='step'><span class='ok'>✓ Conexão com banco estabelecida</span></div>";
    
    // 1. Verifica estrutura das tabelas
    echo "<h3>1. Verificando Estrutura das Tabelas</h3>";
    
    $tabelas = [
        'alunos' => [
            'columns' => [
                'id' => 'INT PRIMARY KEY AUTO_INCREMENT',
                'cpf' => 'VARCHAR(11) UNIQUE NOT NULL',
                'nome' => 'VARCHAR(255) NOT NULL',
                'email' => 'VARCHAR(255)',
                'moodle_user_id' => 'INT',
                'subdomain' => 'VARCHAR(100)',
                'city' => 'VARCHAR(100) NULL',
                'country' => 'VARCHAR(10) DEFAULT "BR"',
                'profile_image' => 'TEXT NULL',
                'primeiro_acesso' => 'DATETIME NULL',
                'ultimo_acesso_moodle' => 'DATETIME NULL',
                'ultimo_acesso' => 'DATETIME NULL',
                'telefone1' => 'VARCHAR(20) NULL',
                'telefone2' => 'VARCHAR(20) NULL',
                'endereco' => 'TEXT NULL',
                'observacoes' => 'TEXT NULL',
                'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            ]
        ],
        'cursos' => [
            'columns' => [
                'id' => 'INT PRIMARY KEY AUTO_INCREMENT',
                'moodle_course_id' => 'INT NOT NULL',
                'nome' => 'VARCHAR(255) NOT NULL',
                'nome_curto' => 'VARCHAR(100)',
                'valor' => 'DECIMAL(10,2) DEFAULT 0.00',
                'subdomain' => 'VARCHAR(100) NOT NULL',
                'categoria_id' => 'INT NULL',
                'data_inicio' => 'DATE NULL',
                'data_fim' => 'DATE NULL',
                'formato' => 'VARCHAR(50) DEFAULT "topics"',
                'summary' => 'TEXT NULL',
                'url' => 'TEXT NULL',
                'ativo' => 'BOOLEAN DEFAULT TRUE',
                'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            ]
        ],
        'matriculas' => [
            'columns' => [
                'id' => 'INT PRIMARY KEY AUTO_INCREMENT',
                'aluno_id' => 'INT NOT NULL',
                'curso_id' => 'INT NOT NULL',
                'status' => 'ENUM("ativa", "inativa", "concluida", "cancelada") DEFAULT "ativa"',
                'data_matricula' => 'DATE NOT NULL',
                'data_conclusao' => 'DATE NULL',
                'observacoes' => 'TEXT NULL',
                'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            ]
        ]
    ];
    
    foreach ($tabelas as $nomeTabela => $estrutura) {
        echo "<h4>Verificando tabela: {$nomeTabela}</h4>";
        
        // Verifica se a tabela existe
        $stmt = $connection->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$nomeTabela]);
        
        if (!$stmt->fetch()) {
            echo "<div class='step problem'>";
            echo "<span class='error'>✗ Tabela {$nomeTabela} não existe</span><br>";
            echo "Criando tabela...";
            
            $createSQL = criarSQLTabela($nomeTabela, $estrutura);
            try {
                $connection->exec($createSQL);
                echo "<br><span class='ok'>✓ Tabela {$nomeTabela} criada com sucesso</span>";
            } catch (Exception $e) {
                echo "<br><span class='error'>✗ Erro ao criar tabela: " . $e->getMessage() . "</span>";
            }
            echo "</div>";
        } else {
            echo "<div class='step'>";
            echo "<span class='ok'>✓ Tabela {$nomeTabela} existe</span>";
            
            // Verifica colunas
            $stmt = $connection->query("DESCRIBE {$nomeTabela}");
            $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $colunasExistentes = array_column($colunas, 'Field');
            
            foreach ($estrutura['columns'] as $nomeColuna => $tipo) {
                if (!in_array($nomeColuna, $colunasExistentes)) {
                    echo "<br><span class='warning'>⚠ Coluna {$nomeColuna} não existe, adicionando...</span>";
                    try {
                        $alterSQL = "ALTER TABLE {$nomeTabela} ADD COLUMN {$nomeColuna} {$tipo}";
                        $connection->exec($alterSQL);
                        echo " <span class='ok'>✓ Adicionada</span>";
                    } catch (Exception $e) {
                        echo " <span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
                    }
                }
            }
            echo "</div>";
        }
    }
    
    // 2. Verifica foreign keys
    echo "<h3>2. Verificando Foreign Keys</h3>";
    
    $foreignKeys = [
        'matriculas' => [
            'FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE',
            'FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE'
        ]
    ];
    
    foreach ($foreignKeys as $tabela => $keys) {
        echo "<div class='step'>";
        echo "Verificando foreign keys da tabela {$tabela}...";
        
        foreach ($keys as $key) {
            try {
                // Tenta criar a foreign key se não existir
                $connection->exec("ALTER TABLE {$tabela} ADD {$key}");
                echo "<br><span class='ok'>✓ Foreign key adicionada</span>";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                    echo "<br><span class='ok'>✓ Foreign key já existe</span>";
                } else {
                    echo "<br><span class='warning'>⚠ " . $e->getMessage() . "</span>";
                }
            }
        }
        echo "</div>";
    }
    
    // 3. Verifica índices
    echo "<h3>3. Verificando Índices</h3>";
    
    $indices = [
        'alunos' => [
            'INDEX idx_cpf (cpf)',
            'INDEX idx_subdomain (subdomain)'
        ],
        'cursos' => [
            'INDEX idx_moodle_course (moodle_course_id, subdomain)',
            'INDEX idx_subdomain (subdomain)'
        ],
        'matriculas' => [
            'UNIQUE KEY unique_matricula (aluno_id, curso_id)'
        ]
    ];
    
    foreach ($indices as $tabela => $indexList) {
        echo "<div class='step'>";
        echo "Verificando índices da tabela {$tabela}...";
        
        foreach ($indexList as $index) {
            try {
                $connection->exec("ALTER TABLE {$tabela} ADD {$index}");
                echo "<br><span class='ok'>✓ Índice adicionado</span>";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') !== false || 
                    strpos($e->getMessage(), 'already exists') !== false) {
                    echo "<br><span class='ok'>✓ Índice já existe</span>";
                } else {
                    echo "<br><span class='warning'>⚠ " . $e->getMessage() . "</span>";
                }
            }
        }
        echo "</div>";
    }
    
    // 4. Verifica dados inconsistentes
    echo "<h3>4. Verificando Dados Inconsistentes</h3>";
    
    // Verifica matrículas órfãs
    echo "<div class='step'>";
    echo "Verificando matrículas órfãs...";
    $stmt = $connection->query("
        SELECT COUNT(*) as count 
        FROM matriculas m 
        LEFT JOIN alunos a ON m.aluno_id = a.id 
        LEFT JOIN cursos c ON m.curso_id = c.id 
        WHERE a.id IS NULL OR c.id IS NULL
    ");
    $orfas = $stmt->fetch()['count'];
    
    if ($orfas > 0) {
        echo "<br><span class='warning'>⚠ Encontradas {$orfas} matrículas órfãs</span>";
        echo "<br>Removendo matrículas órfãs...";
        $connection->exec("
            DELETE m FROM matriculas m 
            LEFT JOIN alunos a ON m.aluno_id = a.id 
            LEFT JOIN cursos c ON m.curso_id = c.id 
            WHERE a.id IS NULL OR c.id IS NULL
        ");
        echo " <span class='ok'>✓ Removidas</span>";
    } else {
        echo " <span class='ok'>✓ Nenhuma matrícula órfã encontrada</span>";
    }
    echo "</div>";
    
    // Verifica cursos inativos com matrículas ativas
    echo "<div class='step'>";
    echo "Verificando cursos inativos com matrículas ativas...";
    $stmt = $connection->query("
        SELECT COUNT(*) as count 
        FROM matriculas m 
        INNER JOIN cursos c ON m.curso_id = c.id 
        WHERE m.status = 'ativa' AND c.ativo = 0
    ");
    $inconsistentes = $stmt->fetch()['count'];
    
    if ($inconsistentes > 0) {
        echo "<br><span class='warning'>⚠ Encontradas {$inconsistentes} matrículas ativas em cursos inativos</span>";
        echo "<br>Você deseja inativar essas matrículas? ";
        echo "<button onclick=\"inativarMatriculas()\">Sim, inativar</button>";
    } else {
        echo " <span class='ok'>✓ Nenhuma inconsistência encontrada</span>";
    }
    echo "</div>";
    
    // 5. Estatísticas do banco
    echo "<h3>5. Estatísticas do Banco</h3>";
    
    $stats = [];
    
    // Total de registros por tabela
    foreach (['alunos', 'cursos', 'matriculas', 'boletos'] as $tabela) {
        try {
            $stmt = $connection->query("SELECT COUNT(*) as count FROM {$tabela}");
            $stats[$tabela] = $stmt->fetch()['count'];
        } catch (Exception $e) {
            $stats[$tabela] = 'N/A';
        }
    }
    
    echo "<div class='step'>";
    echo "<h4>Resumo dos Dados:</h4>";
    echo "<ul>";
    echo "<li>Alunos: <strong>{$stats['alunos']}</strong></li>";
    echo "<li>Cursos: <strong>{$stats['cursos']}</strong></li>";
    echo "<li>Matrículas: <strong>{$stats['matriculas']}</strong></li>";
    echo "<li>Boletos: <strong>{$stats['boletos']}</strong></li>";
    echo "</ul>";
    echo "</div>";
    
    // Distribuição por subdomain
    echo "<div class='step'>";
    echo "Distribuição por Polo:";
    try {
        $stmt = $connection->query("
            SELECT subdomain, COUNT(*) as total 
            FROM alunos 
            GROUP BY subdomain 
            ORDER BY total DESC
        ");
        $distribuicao = $stmt->fetchAll();
        
        echo "<ul>";
        foreach ($distribuicao as $polo) {
            echo "<li>{$polo['subdomain']}: <strong>{$polo['total']}</strong> alunos</li>";
        }
        echo "</ul>";
    } catch (Exception $e) {
        echo "<br><span class='error'>Erro ao obter distribuição: " . $e->getMessage() . "</span>";
    }
    echo "</div>";
    
    // 6. Testes de integridade
    echo "<h3>6. Testes de Integridade</h3>";
    
    // Teste de inserção
    echo "<div class='step'>";
    echo "Testando inserção de dados...";
    
    try {
        $connection->beginTransaction();
        
        // Dados de teste
        $testCPF = '99999999999';
        $testSubdomain = 'teste.imepedu.com.br';
        
        // Remove dados de teste se existirem
        $connection->exec("DELETE FROM matriculas WHERE aluno_id IN (SELECT id FROM alunos WHERE cpf = '{$testCPF}')");
        $connection->exec("DELETE FROM alunos WHERE cpf = '{$testCPF}'");
        $connection->exec("DELETE FROM cursos WHERE subdomain = '{$testSubdomain}'");
        
        // Insere aluno de teste
        $stmt = $connection->prepare("
            INSERT INTO alunos (cpf, nome, email, subdomain) 
            VALUES (?, 'Teste Aluno', 'teste@teste.com', ?)
        ");
        $stmt->execute([$testCPF, $testSubdomain]);
        $alunoId = $connection->lastInsertId();
        
        // Insere curso de teste
        $stmt = $connection->prepare("
            INSERT INTO cursos (moodle_course_id, nome, subdomain) 
            VALUES (999, 'Curso Teste', ?)
        ");
        $stmt->execute([$testSubdomain]);
        $cursoId = $connection->lastInsertId();
        
        // Insere matrícula de teste
        $stmt = $connection->prepare("
            INSERT INTO matriculas (aluno_id, curso_id, data_matricula) 
            VALUES (?, ?, CURDATE())
        ");
        $stmt->execute([$alunoId, $cursoId]);
        
        // Remove dados de teste
        $connection->exec("DELETE FROM matriculas WHERE aluno_id = {$alunoId}");
        $connection->exec("DELETE FROM alunos WHERE id = {$alunoId}");
        $connection->exec("DELETE FROM cursos WHERE id = {$cursoId}");
        
        $connection->commit();
        
        echo " <span class='ok'>✓ Teste de inserção bem-sucedido</span>";
        
    } catch (Exception $e) {
        $connection->rollback();
        echo " <span class='error'>✗ Erro no teste: " . $e->getMessage() . "</span>";
    }
    echo "</div>";
    
    // 7. Otimização do banco
    echo "<h3>7. Otimização do Banco</h3>";
    
    echo "<div class='step'>";
    echo "Otimizando tabelas...";
    
    $tabelasParaOtimizar = ['alunos', 'cursos', 'matriculas', 'boletos'];
    foreach ($tabelasParaOtimizar as $tabela) {
        try {
            $connection->exec("OPTIMIZE TABLE {$tabela}");
            echo "<br><span class='ok'>✓ Tabela {$tabela} otimizada</span>";
        } catch (Exception $e) {
            echo "<br><span class='warning'>⚠ Erro ao otimizar {$tabela}: " . $e->getMessage() . "</span>";
        }
    }
    echo "</div>";
    
    echo "<h3>8. Resumo Final</h3>";
    echo "<div class='step fix'>";
    echo "<h4><span class='ok'>✓ Verificação Concluída</span></h4>";
    echo "<p>O banco de dados foi verificado e as correções necessárias foram aplicadas.</p>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ol>";
    echo "<li>Teste o login no sistema</li>";
    echo "<li>Verifique se os dados estão sendo sincronizados do Moodle</li>";
    echo "<li>Execute o script debug_completo.php para diagnóstico adicional</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step problem'>";
    echo "<span class='error'>✗ Erro crítico: " . $e->getMessage() . "</span>";
    echo "</div>";
}

// Função para criar SQL de tabela
function criarSQLTabela($nome, $estrutura) {
    $sql = "CREATE TABLE {$nome} (\n";
    
    $colunas = [];
    foreach ($estrutura['columns'] as $nomeColuna => $tipo) {
        $colunas[] = "    {$nomeColuna} {$tipo}";
    }
    
    $sql .= implode(",\n", $colunas);
    $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    return $sql;
}
?>

<script>
function inativarMatriculas() {
    if (confirm('Tem certeza que deseja inativar as matrículas ativas em cursos inativos?')) {
        fetch('corrigir_banco.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=inativar_matriculas'
        })
        .then(response => response.text())
        .then(data => {
            alert('Matrículas inativadas com sucesso!');
            location.reload();
        })
        .catch(error => {
            alert('Erro ao inativar matrículas: ' + error);
        });
    }
}
</script>

<?php
// Processa ações via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'inativar_matriculas':
            try {
                $connection->exec("
                    UPDATE matriculas m 
                    INNER JOIN cursos c ON m.curso_id = c.id 
                    SET m.status = 'inativa' 
                    WHERE m.status = 'ativa' AND c.ativo = 0
                ");
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
    }
}
?>

<br>
<div style="margin-top: 30px; padding: 20px; background: #f0f8ff; border: 1px solid #b0d4f1; border-radius: 5px;">
    <h4>Links Úteis para Debug:</h4>
    <ul>
        <li><a href="debug_completo.php" target="_blank">Debug Completo do Sistema</a></li>
        <li><a href="teste_moodle.php" target="_blank">Teste da API Moodle</a></li>
        <li><a href="dashboard_corrigido.php" target="_blank">Dashboard com Debug</a></li>
        <li><a href="index.php">Voltar ao Sistema</a></li>
    </ul>
</div>