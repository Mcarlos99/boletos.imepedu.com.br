<?php
/**
 * Debug específico do banco de dados
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';

echo "<h1>Debug - Banco de Dados</h1>";
echo "<style>body{font-family:Arial;} .ok{color:green;} .error{color:red;} .warning{color:orange;} pre{background:#f5f5f5;padding:10px;border:1px solid #ddd;}</style>";

// 1. Teste de conexão básica
echo "<h3>1. Teste de Conexão</h3>";
try {
    $db = new Database();
    $connection = $db->getConnection();
    echo "<span class='ok'>✓ Conexão estabelecida</span><br>";
    
    // Informações do banco
    $info = $db->getDatabaseInfo();
    echo "Banco: " . $info['database_name'] . "<br>";
    echo "MySQL: " . $info['mysql_version'] . "<br>";
    echo "Charset: " . $info['charset'] . "<br>";
    echo "Tabelas: " . $info['table_count'] . "<br><br>";
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Erro de conexão: " . $e->getMessage() . "</span><br>";
    exit;
}

// 2. Verificar se tabelas existem
echo "<h3>2. Verificação de Tabelas</h3>";
$tabelasNecessarias = ['alunos', 'cursos', 'matriculas', 'boletos', 'administradores', 'logs'];

foreach ($tabelasNecessarias as $tabela) {
    echo "Tabela {$tabela}: ";
    
    if ($db->tableExists($tabela)) {
        echo "<span class='ok'>✓ Existe</span>";
        
        // Conta registros
        try {
            $stmt = $connection->query("SELECT COUNT(*) as count FROM `{$tabela}`");
            $result = $stmt->fetch();
            echo " ({$result['count']} registros)";
        } catch (Exception $e) {
            echo " <span class='error'>(Erro ao contar)</span>";
        }
    } else {
        echo "<span class='error'>✗ Não existe</span>";
    }
    echo "<br>";
}

echo "<br>";

// 3. Verificar estrutura da tabela alunos
echo "<h3>3. Estrutura da Tabela 'alunos'</h3>";
try {
    $stmt = $connection->query("DESCRIBE alunos");
    $columns = $stmt->fetchAll();
    
    if (!empty($columns)) {
        echo "<span class='ok'>✓ Tabela alunos existe</span><br>";
        echo "<table border='1' style='border-collapse:collapse;'>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>{$col['Default']}</td>";
            echo "</tr>";
        }
        echo "</table><br>";
    }
} catch (Exception $e) {
    echo "<span class='error'>✗ Erro ao verificar estrutura: " . $e->getMessage() . "</span><br>";
    
    // Criar tabela se não existir
    echo "<h4>Criando tabela 'alunos'...</h4>";
    try {
        $createTable = "
        CREATE TABLE alunos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            cpf VARCHAR(11) UNIQUE,
            nome VARCHAR(255),
            email VARCHAR(255),
            moodle_user_id INT,
            subdomain VARCHAR(100),
            city VARCHAR(100) NULL,
            country VARCHAR(10) DEFAULT 'BR',
            profile_image TEXT NULL,
            primeiro_acesso DATETIME NULL,
            ultimo_acesso_moodle DATETIME NULL,
            ultimo_acesso DATETIME NULL,
            telefone1 VARCHAR(20) NULL,
            telefone2 VARCHAR(20) NULL,
            endereco TEXT NULL,
            observacoes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cpf (cpf),
            INDEX idx_subdomain (subdomain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $connection->exec($createTable);
        echo "<span class='ok'>✓ Tabela 'alunos' criada</span><br>";
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro ao criar tabela: " . $e->getMessage() . "</span><br>";
    }
}

// 4. Verificar estrutura da tabela cursos
echo "<h3>4. Estrutura da Tabela 'cursos'</h3>";
try {
    $stmt = $connection->query("DESCRIBE cursos");
    $columns = $stmt->fetchAll();
    
    if (!empty($columns)) {
        echo "<span class='ok'>✓ Tabela cursos existe</span><br>";
    }
} catch (Exception $e) {
    echo "<span class='error'>✗ Tabela cursos não existe. Criando...</span><br>";
    
    try {
        $createTable = "
        CREATE TABLE cursos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            moodle_course_id INT NOT NULL,
            nome VARCHAR(255) NOT NULL,
            nome_curto VARCHAR(100),
            valor DECIMAL(10,2) DEFAULT 0.00,
            subdomain VARCHAR(100) NOT NULL,
            categoria_id INT NULL,
            data_inicio DATE NULL,
            data_fim DATE NULL,
            formato VARCHAR(50) DEFAULT 'topics',
            summary TEXT NULL,
            url TEXT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_moodle_course (moodle_course_id, subdomain),
            INDEX idx_subdomain (subdomain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $connection->exec($createTable);
        echo "<span class='ok'>✓ Tabela 'cursos' criada</span><br>";
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro ao criar tabela: " . $e->getMessage() . "</span><br>";
    }
}

// 5. Verificar estrutura da tabela matriculas
echo "<h3>5. Estrutura da Tabela 'matriculas'</h3>";
try {
    $stmt = $connection->query("DESCRIBE matriculas");
    $columns = $stmt->fetchAll();
    
    if (!empty($columns)) {
        echo "<span class='ok'>✓ Tabela matriculas existe</span><br>";
    }
} catch (Exception $e) {
    echo "<span class='error'>✗ Tabela matriculas não existe. Criando...</span><br>";
    
    try {
        $createTable = "
        CREATE TABLE matriculas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            aluno_id INT NOT NULL,
            curso_id INT NOT NULL,
            status ENUM('ativa', 'inativa', 'concluida', 'cancelada') DEFAULT 'ativa',
            data_matricula DATE NOT NULL,
            data_conclusao DATE NULL,
            observacoes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
            FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
            UNIQUE KEY unique_matricula (aluno_id, curso_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $connection->exec($createTable);
        echo "<span class='ok'>✓ Tabela 'matriculas' criada</span><br>";
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro ao criar tabela: " . $e->getMessage() . "</span><br>";
    }
}

// 6. Teste de inserção de dados
echo "<h3>6. Teste de Inserção de Dados</h3>";

// Dados de teste
$dadosTesteAluno = [
    'cpf' => '03183924536',
    'nome' => 'Teste Usuario',
    'email' => 'teste@exemplo.com',
    'moodle_user_id' => 999,
    'subdomain' => 'breubranco.imepedu.com.br',
    'cursos' => [
        [
            'moodle_course_id' => 1,
            'nome' => 'Curso Teste',
            'nome_curto' => 'teste',
            'categoria' => 1,
            'data_inicio' => '2025-01-01',
            'data_fim' => '2025-12-31',
            'formato' => 'topics',
            'summary' => 'Curso de teste',
            'url' => 'https://breubranco.imepedu.com.br/course/view.php?id=1'
        ]
    ]
];

try {
    echo "Testando inserção de aluno...<br>";
    
    // Primeiro, remove dados de teste se existirem
    $stmt = $connection->prepare("DELETE FROM matriculas WHERE aluno_id IN (SELECT id FROM alunos WHERE cpf = ?)");
    $stmt->execute([$dadosTesteAluno['cpf']]);
    
    $stmt = $connection->prepare("DELETE FROM alunos WHERE cpf = ?");
    $stmt->execute([$dadosTesteAluno['cpf']]);
    
    $stmt = $connection->prepare("DELETE FROM cursos WHERE moodle_course_id = ? AND subdomain = ?");
    $stmt->execute([1, 'breubranco.imepedu.com.br']);
    
    // Insere aluno
    $stmt = $connection->prepare("
        INSERT INTO alunos (cpf, nome, email, moodle_user_id, subdomain, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $dadosTesteAluno['cpf'],
        $dadosTesteAluno['nome'],
        $dadosTesteAluno['email'],
        $dadosTesteAluno['moodle_user_id'],
        $dadosTesteAluno['subdomain']
    ]);
    
    $alunoId = $connection->lastInsertId();
    echo "<span class='ok'>✓ Aluno inserido (ID: {$alunoId})</span><br>";
    
    // Insere curso
    $curso = $dadosTesteAluno['cursos'][0];
    $stmt = $connection->prepare("
        INSERT INTO cursos (moodle_course_id, nome, nome_curto, subdomain, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $curso['moodle_course_id'],
        $curso['nome'],
        $curso['nome_curto'],
        $dadosTesteAluno['subdomain']
    ]);
    
    $cursoId = $connection->lastInsertId();
    echo "<span class='ok'>✓ Curso inserido (ID: {$cursoId})</span><br>";
    
    // Insere matrícula
    $stmt = $connection->prepare("
        INSERT INTO matriculas (aluno_id, curso_id, data_matricula, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$alunoId, $cursoId, date('Y-m-d')]);
    
    echo "<span class='ok'>✓ Matrícula inserida</span><br>";
    
    echo "<span class='ok'>✓ Teste de inserção concluído com sucesso!</span><br>";
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Erro no teste de inserção: " . $e->getMessage() . "</span><br>";
    echo "<pre>SQL Error: " . $e->getTraceAsString() . "</pre>";
}

// 7. Teste do AlunoService
echo "<br><h3>7. Teste do AlunoService</h3>";
try {
    require_once 'src/AlunoService.php';
    
    $alunoService = new AlunoService();
    echo "<span class='ok'>✓ AlunoService carregado</span><br>";
    
    // Teste de busca
    $aluno = $alunoService->buscarAlunoPorCPF('03183924536');
    if ($aluno) {
        echo "<span class='ok'>✓ Busca por CPF funcionando</span><br>";
        echo "Aluno encontrado: " . $aluno['nome'] . "<br>";
    } else {
        echo "<span class='warning'>⚠ Nenhum aluno encontrado na busca</span><br>";
    }
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Erro no AlunoService: " . $e->getMessage() . "</span><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 8. Limpar dados de teste
echo "<br><h3>8. Limpeza</h3>";
try {
    $stmt = $connection->prepare("DELETE FROM matriculas WHERE aluno_id IN (SELECT id FROM alunos WHERE cpf = ?)");
    $stmt->execute(['03183924536']);
    
    $stmt = $connection->prepare("DELETE FROM alunos WHERE cpf = ?");
    $stmt->execute(['03183924536']);
    
    $stmt = $connection->prepare("DELETE FROM cursos WHERE moodle_course_id = ? AND subdomain = ?");
    $stmt->execute([1, 'breubranco.imepedu.com.br']);
    
    echo "<span class='ok'>✓ Dados de teste removidos</span><br>";
} catch (Exception $e) {
    echo "<span class='error'>✗ Erro na limpeza: " . $e->getMessage() . "</span><br>";
}

echo "<br><h3>Conclusão</h3>";
echo "<p>Se todos os testes passaram, o banco está funcionando corretamente.</p>";
echo "<p>Se houve erros, verifique as permissões do usuário do banco e a estrutura das tabelas.</p>";

echo "<br><a href='debug_banco.php'>← Executar novamente</a>";
?>