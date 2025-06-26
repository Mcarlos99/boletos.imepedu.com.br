<?php
/**
 * Script para verificar e corrigir problemas no banco de dados (Versão Corrigida)
 * Arquivo: corrigir_banco_v2.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';

echo "<h1>Correção do Banco de Dados - V2</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    .fix{background:#e7f3ff; border-left-color:#007bff;}
    .problem{background:#ffebee; border-left-color:#f44336;}
    pre{background:#f5f5f5; padding:10px; border:1px solid #ddd; overflow-x:auto;}
</style>";

try {
    $db = new Database();
    $connection = $db->getConnection();
    
    echo "<div class='step'><span class='ok'>✓ Conexão com banco estabelecida</span></div>";
    
    // 1. Verifica e cria tabelas se necessário
    echo "<h3>1. Verificando e Criando Tabelas</h3>";
    
    // Função para verificar se tabela existe
    function tabelaExiste($connection, $nomeTabela) {
        try {
            $result = $connection->query("SHOW TABLES LIKE '{$nomeTabela}'");
            return $result->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Função para verificar se coluna existe
    function colunaExiste($connection, $tabela, $coluna) {
        try {
            $result = $connection->query("SHOW COLUMNS FROM {$tabela} LIKE '{$coluna}'");
            return $result->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Tabela alunos
    echo "<h4>Verificando tabela: alunos</h4>";
    echo "<div class='step'>";
    
    if (!tabelaExiste($connection, 'alunos')) {
        echo "<span class='error'>✗ Tabela alunos não existe</span><br>";
        echo "Criando tabela alunos...";
        
        $createAlunos = "
        CREATE TABLE alunos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            cpf VARCHAR(11) UNIQUE NOT NULL,
            nome VARCHAR(255) NOT NULL,
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
        
        try {
            $connection->exec($createAlunos);
            echo "<br><span class='ok'>✓ Tabela alunos criada com sucesso</span>";
        } catch (Exception $e) {
            echo "<br><span class='error'>✗ Erro ao criar tabela alunos: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span class='ok'>✓ Tabela alunos existe</span>";
        
        // Verifica colunas essenciais e adiciona se necessário
        $colunasAlunos = [
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
        ];
        
        foreach ($colunasAlunos as $coluna => $tipo) {
            if (!colunaExiste($connection, 'alunos', $coluna)) {
                echo "<br><span class='warning'>⚠ Adicionando coluna {$coluna}...</span>";
                try {
                    $connection->exec("ALTER TABLE alunos ADD COLUMN {$coluna} {$tipo}");
                    echo " <span class='ok'>✓</span>";
                } catch (Exception $e) {
                    echo " <span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
                }
            }
        }
    }
    echo "</div>";
    
    // Tabela cursos
    echo "<h4>Verificando tabela: cursos</h4>";
    echo "<div class='step'>";
    
    if (!tabelaExiste($connection, 'cursos')) {
        echo "<span class='error'>✗ Tabela cursos não existe</span><br>";
        echo "Criando tabela cursos...";
        
        $createCursos = "
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
        
        try {
            $connection->exec($createCursos);
            echo "<br><span class='ok'>✓ Tabela cursos criada com sucesso</span>";
        } catch (Exception $e) {
            echo "<br><span class='error'>✗ Erro ao criar tabela cursos: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span class='ok'>✓ Tabela cursos existe</span>";
    }
    echo "</div>";
    
    // Tabela matriculas
    echo "<h4>Verificando tabela: matriculas</h4>";
    echo "<div class='step'>";
    
    if (!tabelaExiste($connection, 'matriculas')) {
        echo "<span class='error'>✗ Tabela matriculas não existe</span><br>";
        echo "Criando tabela matriculas...";
        
        $createMatriculas = "
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
            INDEX idx_aluno (aluno_id),
            INDEX idx_curso (curso_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        try {
            $connection->exec($createMatriculas);
            echo "<br><span class='ok'>✓ Tabela matriculas criada com sucesso</span>";
        } catch (Exception $e) {
            echo "<br><span class='error'>✗ Erro ao criar tabela matriculas: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span class='ok'>✓ Tabela matriculas existe</span>";
    }
    echo "</div>";
    
    // Tabela boletos
    echo "<h4>Verificando tabela: boletos</h4>";
    echo "<div class='step'>";
    
    if (!tabelaExiste($connection, 'boletos')) {
        echo "<span class='error'>✗ Tabela boletos não existe</span><br>";
        echo "Criando tabela boletos...";
        
        $createBoletos = "
        CREATE TABLE boletos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            aluno_id INT NOT NULL,
            curso_id INT NOT NULL,
            numero_boleto VARCHAR(100) UNIQUE NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            vencimento DATE NOT NULL,
            status ENUM('pendente', 'pago', 'vencido', 'cancelado') DEFAULT 'pendente',
            descricao TEXT,
            nosso_numero VARCHAR(50),
            linha_digitavel TEXT,
            codigo_barras TEXT,
            data_pagamento DATE NULL,
            valor_pago DECIMAL(10,2) NULL,
            observacoes TEXT,
            referencia VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_aluno (aluno_id),
            INDEX idx_curso (curso_id),
            INDEX idx_status (status),
            INDEX idx_vencimento (vencimento),
            INDEX idx_numero (numero_boleto)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        try {
            $connection->exec($createBoletos);
            echo "<br><span class='ok'>✓ Tabela boletos criada com sucesso</span>";
        } catch (Exception $e) {
            echo "<br><span class='error'>✗ Erro ao criar tabela boletos: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span class='ok'>✓ Tabela boletos existe</span>";
    }
    echo "</div>";
    
    // Tabela logs
    echo "<h4>Verificando tabela: logs</h4>";
    echo "<div class='step'>";
    
    if (!tabelaExiste($connection, 'logs')) {
        echo "<span class='error'>✗ Tabela logs não existe</span><br>";
        echo "Criando tabela logs...";
        
        $createLogs = "
        CREATE TABLE logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            tipo ENUM('login', 'logout', 'boleto_gerado', 'boleto_pago', 'boleto_cancelado', 'admin_action', 'aluno_sincronizado') NOT NULL,
            usuario_id INT NULL,
            admin_id INT NULL,
            boleto_id INT NULL,
            descricao TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tipo (tipo),
            INDEX idx_created_at (created_at),
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        try {
            $connection->exec($createLogs);
            echo "<br><span class='ok'>✓ Tabela logs criada com sucesso</span>";
        } catch (Exception $e) {
            echo "<br><span class='error'>✗ Erro ao criar tabela logs: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span class='ok'>✓ Tabela logs existe</span>";
    }
    echo "</div>";
    
    // Tabela administradores
    echo "<h4>Verificando tabela: administradores</h4>";
    echo "<div class='step'>";
    
    if (!tabelaExiste($connection, 'administradores')) {
        echo "<span class='error'>✗ Tabela administradores não existe</span><br>";
        echo "Criando tabela administradores...";
        
        $createAdmins = "
        CREATE TABLE administradores (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            senha VARCHAR(255) NOT NULL,
            nivel ENUM('admin', 'operador') DEFAULT 'operador',
            ativo BOOLEAN DEFAULT TRUE,
            ultimo_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        try {
            $connection->exec($createAdmins);
            echo "<br><span class='ok'>✓ Tabela administradores criada com sucesso</span>";
            
            // Insere administrador padrão
            $senhaHash = password_hash('admin123', PASSWORD_DEFAULT);
            $connection->exec("
                INSERT INTO administradores (nome, email, senha, nivel) 
                VALUES ('Administrador', 'admin@imepedu.com.br', '{$senhaHash}', 'admin')
            ");
            echo "<br><span class='info'>ℹ Administrador padrão criado (admin@imepedu.com.br / admin123)</span>";
            
        } catch (Exception $e) {
            echo "<br><span class='error'>✗ Erro ao criar tabela administradores: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span class='ok'>✓ Tabela administradores existe</span>";
    }
    echo "</div>";
    
    // 2. Adiciona Foreign Keys (com verificação)
    echo "<h3>2. Verificando Foreign Keys</h3>";
    
    function foreignKeyExiste($connection, $tabela, $constraint) {
        try {
            $result = $connection->query("
                SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_NAME = '{$tabela}' 
                AND CONSTRAINT_NAME LIKE 'fk_%'
                AND TABLE_SCHEMA = DATABASE()
            ");
            $constraints = $result->fetchAll(PDO::FETCH_COLUMN);
            return in_array($constraint, $constraints);
        } catch (Exception $e) {
            return false;
        }
    }
    
    echo "<div class='step'>";
    echo "Verificando foreign keys...";
    
    // Foreign keys para matriculas
    if (tabelaExiste($connection, 'matriculas') && tabelaExiste($connection, 'alunos') && tabelaExiste($connection, 'cursos')) {
        try {
            // FK para aluno_id
            $connection->exec("
                ALTER TABLE matriculas 
                ADD CONSTRAINT fk_matricula_aluno 
                FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
            ");
            echo "<br><span class='ok'>✓ FK matricula->aluno adicionada</span>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "<br><span class='ok'>✓ FK matricula->aluno já existe</span>";
            } else {
                echo "<br><span class='warning'>⚠ FK matricula->aluno: " . $e->getMessage() . "</span>";
            }
        }
        
        try {
            // FK para curso_id
            $connection->exec("
                ALTER TABLE matriculas 
                ADD CONSTRAINT fk_matricula_curso 
                FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
            ");
            echo "<br><span class='ok'>✓ FK matricula->curso adicionada</span>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "<br><span class='ok'>✓ FK matricula->curso já existe</span>";
            } else {
                echo "<br><span class='warning'>⚠ FK matricula->curso: " . $e->getMessage() . "</span>";
            }
        }
    }
    
    // Foreign keys para boletos
    if (tabelaExiste($connection, 'boletos') && tabelaExiste($connection, 'alunos') && tabelaExiste($connection, 'cursos')) {
        try {
            $connection->exec("
                ALTER TABLE boletos 
                ADD CONSTRAINT fk_boleto_aluno 
                FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
            ");
            echo "<br><span class='ok'>✓ FK boleto->aluno adicionada</span>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "<br><span class='ok'>✓ FK boleto->aluno já existe</span>";
            } else {
                echo "<br><span class='warning'>⚠ FK boleto->aluno: " . $e->getMessage() . "</span>";
            }
        }
        
        try {
            $connection->exec("
                ALTER TABLE boletos 
                ADD CONSTRAINT fk_boleto_curso 
                FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
            ");
            echo "<br><span class='ok'>✓ FK boleto->curso adicionada</span>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "<br><span class='ok'>✓ FK boleto->curso já existe</span>";
            } else {
                echo "<br><span class='warning'>⚠ FK boleto->curso: " . $e->getMessage() . "</span>";
            }
        }
    }
    echo "</div>";
    
    // 3. Verifica dados e corrige inconsistências
    echo "<h3>3. Verificando Dados</h3>";
    
    echo "<div class='step'>";
    echo "Verificando integridade dos dados...";
    
    // Conta registros por tabela
    $tabelas = ['alunos', 'cursos', 'matriculas', 'boletos', 'logs', 'administradores'];
    echo "<br><strong>Contagem de registros:</strong>";
    foreach ($tabelas as $tabela) {
        if (tabelaExiste($connection, $tabela)) {
            try {
                $result = $connection->query("SELECT COUNT(*) as count FROM {$tabela}");
                $count = $result->fetch()['count'];
                echo "<br>- {$tabela}: {$count} registros";
            } catch (Exception $e) {
                echo "<br>- {$tabela}: Erro ao contar";
            }
        } else {
            echo "<br>- {$tabela}: Tabela não existe";
        }
    }
    echo "</div>";
    
    // 4. Teste de funcionalidade básica
    echo "<h3>4. Teste de Funcionalidade</h3>";
    
    echo "<div class='step'>";
    echo "Testando operações básicas...";
    
    try {
        $connection->beginTransaction();
        
        // Testa inserção de aluno
        $testCPF = '99999999999';
        $connection->exec("DELETE FROM matriculas WHERE aluno_id IN (SELECT id FROM alunos WHERE cpf = '{$testCPF}')");
        $connection->exec("DELETE FROM alunos WHERE cpf = '{$testCPF}'");
        
        $stmt = $connection->prepare("
            INSERT INTO alunos (cpf, nome, email, subdomain) 
            VALUES (?, 'Teste Sistema', 'teste@sistema.com', 'teste.imepedu.com.br')
        ");
        $stmt->execute([$testCPF]);
        $alunoId = $connection->lastInsertId();
        echo "<br><span class='ok'>✓ Inserção de aluno: OK</span>";
        
        // Testa busca
        $stmt = $connection->prepare("SELECT * FROM alunos WHERE id = ?");
        $stmt->execute([$alunoId]);
        $aluno = $stmt->fetch();
        
        if ($aluno && $aluno['cpf'] == $testCPF) {
            echo "<br><span class='ok'>✓ Busca de aluno: OK</span>";
        } else {
            echo "<br><span class='error'>✗ Busca de aluno: FALHOU</span>";
        }
        
        // Remove dados de teste
        $connection->exec("DELETE FROM alunos WHERE id = {$alunoId}");
        echo "<br><span class='ok'>✓ Remoção de dados: OK</span>";
        
        $connection->rollback(); // Desfaz todas as alterações de teste
        echo "<br><span class='ok'>✓ Transação: OK</span>";
        
    } catch (Exception $e) {
        $connection->rollback();
        echo "<br><span class='error'>✗ Erro no teste: " . $e->getMessage() . "</span>";
    }
    echo "</div>";
    
    // 5. Resumo e próximos passos
    echo "<h3>5. Resumo Final</h3>";
    
    echo "<div class='step fix'>";
    echo "<h4><span class='ok'>✓ Banco de Dados Verificado e Corrigido</span></h4>";
    echo "<p>Todas as tabelas necessárias foram verificadas e criadas/corrigidas conforme necessário.</p>";
    
    echo "<h4>Próximos Passos:</h4>";
    echo "<ol>";
    echo "<li><strong>Teste o login:</strong> <a href='index.php' target='_blank'>Acesse o sistema</a></li>";
    echo "<li><strong>Debug da API:</strong> <a href='debug_completo.php' target='_blank'>Execute debug completo</a></li>";
    echo "<li><strong>Teste Moodle:</strong> <a href='teste_moodle.php' target='_blank'>Teste conexão Moodle</a></li>";
    echo "</ol>";
    
    echo "<h4>Credenciais de Administrador:</h4>";
    echo "<p><strong>Email:</strong> admin@imepedu.com.br<br>";
    echo "<strong>Senha:</strong> admin123</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step problem'>";
    echo "<span class='error'>✗ Erro crítico: " . $e->getMessage() . "</span>";
    echo "<br><pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<style>
.links-uteis {
    margin-top: 30px;
    padding: 20px;
    background: #e8f5e8;
    border: 1px solid #c3e6c3;
    border-radius: 5px;
}
</style>

<div class="links-uteis">
    <h4>🔧 Ferramentas de Debug</h4>
    <ul>
        <li><a href="debug_completo.php" target="_blank">🐛 Debug Completo do Sistema</a></li>
        <li><a href="teste_moodle.php" target="_blank">🔌 Teste da API Moodle</a></li>
        <li><a href="dashboard_corrigido.php" target="_blank">📊 Dashboard com Debug</a></li>
        <li><a href="diagnostico.php" target="_blank">🏥 Diagnóstico Geral</a></li>
    </ul>
    
    <h4>📝 Sistema</h4>
    <ul>
        <li><a href="index.php">🏠 Página Principal</a></li>
        <li><a href="admin/" target="_blank">⚙️ Área Administrativa</a></li>
    </ul>
</div>