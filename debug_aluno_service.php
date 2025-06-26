<?php
/**
 * Debug específico do AlunoService
 * Arquivo: debug_aluno_service.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug do AlunoService</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    pre{background:#f5f5f5; padding:15px; border:1px solid #ddd; overflow-x:auto;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
</style>";

try {
    // Inclui arquivos necessários
    require_once 'config/database.php';
    require_once 'config/moodle.php';
    require_once 'src/MoodleAPI.php';
    require_once 'src/AlunoService.php';
    
    echo "<div class='step'><span class='ok'>✓ Arquivos carregados com sucesso</span></div>";
    
    // Dados do aluno de teste (baseado no seu erro)
    $dadosAluno = [
        'nome' => 'Carlos Santos',
        'cpf' => '03183924536', // CPF do teste
        'email' => 'diego2008tuc@gmail.com',
        'moodle_user_id' => 4,
        'subdomain' => 'breubranco.imepedu.com.br',
        'cursos' => [
            [
                'id' => 91,
                'moodle_course_id' => 91,
                'nome' => 'NR-35',
                'nome_curto' => 'nr35',
                'categoria' => 1,
                'data_inicio' => '2025-01-01',
                'data_fim' => '2025-12-31',
                'formato' => 'topics',
                'summary' => 'Curso NR-35',
                'url' => 'https://breubranco.imepedu.com.br/course/view.php?id=91'
            ],
            [
                'id' => 90,
                'moodle_course_id' => 90,
                'nome' => 'NR-33',
                'nome_curto' => 'nr33',
                'categoria' => 1,
                'data_inicio' => '2025-01-01',
                'data_fim' => '2025-12-31',
                'formato' => 'topics',
                'summary' => 'Curso NR-33',
                'url' => 'https://breubranco.imepedu.com.br/course/view.php?id=90'
            ]
        ],
        'ultimo_acesso' => '2025-01-20 10:30:00',
        'profile_image' => null,
        'city' => 'Breu Branco',
        'country' => 'BR'
    ];
    
    echo "<div class='step'>";
    echo "<h3>1. Testando Conexão com Banco</h3>";
    $db = new Database();
    $connection = $db->getConnection();
    echo "<span class='ok'>✓ Conexão estabelecida</span>";
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Verificando Estrutura das Tabelas</h3>";
    
    // Verifica se as tabelas existem
    $tabelas = ['alunos', 'cursos', 'matriculas'];
    foreach ($tabelas as $tabela) {
        try {
            $stmt = $connection->query("DESCRIBE {$tabela}");
            $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<span class='ok'>✓ Tabela {$tabela} existe</span> (" . count($colunas) . " colunas)<br>";
            
            // Mostra colunas da tabela alunos
            if ($tabela === 'alunos') {
                echo "<small>Colunas: ";
                $nomesColunas = array_column($colunas, 'Field');
                echo implode(', ', $nomesColunas);
                echo "</small><br>";
            }
        } catch (Exception $e) {
            echo "<span class='error'>✗ Erro na tabela {$tabela}: " . $e->getMessage() . "</span><br>";
        }
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Testando AlunoService Passo a Passo</h3>";
    
    // Inicializa AlunoService
    echo "Inicializando AlunoService...<br>";
    $alunoService = new AlunoService();
    echo "<span class='ok'>✓ AlunoService inicializado</span><br>";
    
    // Verifica se aluno já existe
    echo "<br>Verificando se aluno já existe...<br>";
    $alunoExistente = $alunoService->buscarAlunoPorCPF($dadosAluno['cpf']);
    if ($alunoExistente) {
        echo "<span class='warning'>⚠ Aluno já existe no banco (ID: {$alunoExistente['id']})</span><br>";
        echo "<pre>";
        print_r($alunoExistente);
        echo "</pre>";
    } else {
        echo "<span class='info'>ℹ Aluno não existe no banco (será criado)</span><br>";
    }
    
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Simulando Operação de Salvamento</h3>";
    
    try {
        echo "Dados que serão salvos:<br>";
        echo "<pre>";
        print_r($dadosAluno);
        echo "</pre>";
        
        echo "<br>Tentando salvar/atualizar aluno...<br>";
        
        // Vamos fazer isso manualmente para identificar onde está o erro
        $connection->beginTransaction();
        
        // 1. Verifica se aluno existe
        $stmt = $connection->prepare("SELECT id, updated_at FROM alunos WHERE cpf = ? LIMIT 1");
        $stmt->execute([$dadosAluno['cpf']]);
        $alunoExistente = $stmt->fetch();
        
        if ($alunoExistente) {
            echo "<span class='info'>ℹ Atualizando aluno existente (ID: {$alunoExistente['id']})</span><br>";
            
            // Atualiza aluno
            $stmt = $connection->prepare("
                UPDATE alunos 
                SET nome = ?, email = ?, moodle_user_id = ?, subdomain = ?,
                    city = ?, country = ?, profile_image = ?,
                    ultimo_acesso_moodle = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $dadosAluno['nome'],
                $dadosAluno['email'],
                $dadosAluno['moodle_user_id'],
                $dadosAluno['subdomain'],
                $dadosAluno['city'] ?? null,
                $dadosAluno['country'] ?? 'BR',
                $dadosAluno['profile_image'] ?? null,
                $dadosAluno['ultimo_acesso'] ?? null,
                $alunoExistente['id']
            ]);
            
            if ($result) {
                echo "<span class='ok'>✓ Aluno atualizado com sucesso</span><br>";
                $alunoId = $alunoExistente['id'];
            } else {
                echo "<span class='error'>✗ Erro ao atualizar aluno</span><br>";
            }
            
        } else {
            echo "<span class='info'>ℹ Criando novo aluno</span><br>";
            
            // Cria novo aluno
            $stmt = $connection->prepare("
                INSERT INTO alunos (
                    cpf, nome, email, moodle_user_id, subdomain, 
                    city, country, profile_image, primeiro_acesso,
                    ultimo_acesso_moodle, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $result = $stmt->execute([
                $dadosAluno['cpf'],
                $dadosAluno['nome'],
                $dadosAluno['email'],
                $dadosAluno['moodle_user_id'],
                $dadosAluno['subdomain'],
                $dadosAluno['city'] ?? null,
                $dadosAluno['country'] ?? 'BR',
                $dadosAluno['profile_image'] ?? null,
                $dadosAluno['primeiro_acesso'] ?? null,
                $dadosAluno['ultimo_acesso'] ?? null
            ]);
            
            if ($result) {
                $alunoId = $connection->lastInsertId();
                echo "<span class='ok'>✓ Aluno criado com sucesso (ID: {$alunoId})</span><br>";
            } else {
                echo "<span class='error'>✗ Erro ao criar aluno</span><br>";
                $connection->rollback();
                exit;
            }
        }
        
        echo "<br>Processando cursos...<br>";
        
        // 2. Processa cursos
        foreach ($dadosAluno['cursos'] as $index => $cursoMoodle) {
            echo "<br>Curso " . ($index + 1) . ": {$cursoMoodle['nome']}<br>";
            
            // Verifica se curso já existe
            $stmt = $connection->prepare("
                SELECT id FROM cursos 
                WHERE moodle_course_id = ? AND subdomain = ?
                LIMIT 1
            ");
            $stmt->execute([$cursoMoodle['moodle_course_id'], $dadosAluno['subdomain']]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                $cursoId = $cursoExistente['id'];
                echo "  <span class='info'>ℹ Curso já existe (ID: {$cursoId})</span><br>";
                
                // Atualiza curso
                $stmt = $connection->prepare("
                    UPDATE cursos 
                    SET nome = ?, nome_curto = ?, categoria_id = ?,
                        data_inicio = ?, data_fim = ?, formato = ?,
                        summary = ?, url = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $cursoMoodle['nome'],
                    $cursoMoodle['nome_curto'] ?? '',
                    $cursoMoodle['categoria'] ?? null,
                    $cursoMoodle['data_inicio'] ?? null,
                    $cursoMoodle['data_fim'] ?? null,
                    $cursoMoodle['formato'] ?? 'topics',
                    $cursoMoodle['summary'] ?? '',
                    $cursoMoodle['url'] ?? null,
                    $cursoId
                ]);
                
                echo "  <span class='ok'>✓ Curso atualizado</span><br>";
                
            } else {
                echo "  <span class='info'>ℹ Criando novo curso</span><br>";
                
                // Cria novo curso
                $stmt = $connection->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, nome, nome_curto, valor, subdomain,
                        categoria_id, data_inicio, data_fim, formato, summary,
                        url, ativo, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $cursoMoodle['moodle_course_id'],
                    $cursoMoodle['nome'],
                    $cursoMoodle['nome_curto'] ?? '',
                    0.00, // Valor padrão
                    $dadosAluno['subdomain'],
                    $cursoMoodle['categoria'] ?? null,
                    $cursoMoodle['data_inicio'] ?? null,
                    $cursoMoodle['data_fim'] ?? null,
                    $cursoMoodle['formato'] ?? 'topics',
                    $cursoMoodle['summary'] ?? '',
                    $cursoMoodle['url'] ?? null,
                    true
                ]);
                
                $cursoId = $connection->lastInsertId();
                echo "  <span class='ok'>✓ Curso criado (ID: {$cursoId})</span><br>";
            }
            
            // 3. Verifica/cria matrícula
            $stmt = $connection->prepare("
                SELECT id, status FROM matriculas 
                WHERE aluno_id = ? AND curso_id = ?
                LIMIT 1
            ");
            $stmt->execute([$alunoId, $cursoId]);
            $matriculaExistente = $stmt->fetch();
            
            if ($matriculaExistente) {
                echo "  <span class='info'>ℹ Matrícula já existe (ID: {$matriculaExistente['id']})</span><br>";
                
                if ($matriculaExistente['status'] !== 'ativa') {
                    // Reativa matrícula
                    $stmt = $connection->prepare("
                        UPDATE matriculas 
                        SET status = 'ativa', updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$matriculaExistente['id']]);
                    echo "  <span class='ok'>✓ Matrícula reativada</span><br>";
                }
            } else {
                echo "  <span class='info'>ℹ Criando nova matrícula</span><br>";
                
                // Cria nova matrícula
                $stmt = $connection->prepare("
                    INSERT INTO matriculas (
                        aluno_id, curso_id, status, data_matricula, created_at
                    ) VALUES (?, ?, ?, ?, NOW())
                ");
                
                $dataMatricula = $cursoMoodle['data_inicio'] ?? date('Y-m-d');
                
                $stmt->execute([
                    $alunoId,
                    $cursoId,
                    'ativa',
                    $dataMatricula
                ]);
                
                $matriculaId = $connection->lastInsertId();
                echo "  <span class='ok'>✓ Matrícula criada (ID: {$matriculaId})</span><br>";
            }
        }
        
        $connection->commit();
        echo "<br><span class='ok'>✓ Operação concluída com sucesso!</span><br>";
        echo "Aluno ID: {$alunoId}<br>";
        
    } catch (Exception $e) {
        $connection->rollback();
        echo "<br><span class='error'>✗ Erro durante a operação:</span><br>";
        echo "<pre>" . $e->getMessage() . "</pre>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Testando com AlunoService Real</h3>";
    
    try {
        echo "Tentando usar AlunoService->salvarOuAtualizarAluno()...<br>";
        $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAluno);
        echo "<span class='ok'>✓ AlunoService funcionou! Aluno ID: {$alunoId}</span><br>";
        
        // Verifica cursos salvos
        $cursosLocal = $alunoService->buscarCursosAluno($alunoId);
        echo "Cursos no banco local: " . count($cursosLocal) . "<br>";
        
        if (count($cursosLocal) > 0) {
            echo "<ul>";
            foreach ($cursosLocal as $curso) {
                echo "<li>{$curso['nome']} (ID: {$curso['id']})</li>";
            }
            echo "</ul>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro no AlunoService:</span><br>";
        echo "<pre>" . $e->getMessage() . "</pre>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro crítico: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #f0f8ff; border: 1px solid #b0d4f1; border-radius: 5px;">
    <h4>🔧 Próximos Passos:</h4>
    <ol>
        <li>Execute este script para identificar onde está o problema exato</li>
        <li>Se o erro persistir, verifique os logs do PHP para mais detalhes</li>
        <li>Teste novamente o <a href="debug_completo.php">Debug Completo</a></li>
    </ol>
</div>