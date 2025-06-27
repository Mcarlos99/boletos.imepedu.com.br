<?php
/**
 * Debug específico do salvamento de cursos
 * Arquivo: debug_salvamento_cursos.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug - Salvamento de Cursos</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    pre{background:#f5f5f5; padding:15px; border:1px solid #ddd; overflow-x:auto;}
    .step{margin:15px 0; padding:15px; background:#f9f9f9; border-left:4px solid #007bff;}
</style>";

$cpf_teste = '03183924536';
$subdomain_teste = 'breubranco.imepedu.com.br';

try {
    require_once 'config/database.php';
    require_once 'config/moodle.php';
    require_once 'src/MoodleAPI.php';
    require_once 'src/AlunoService.php';
    
    echo "<div class='step'>";
    echo "<h3>1. Buscando Dados do Moodle</h3>";
    
    $moodleAPI = new MoodleAPI($subdomain_teste);
    $dadosAluno = $moodleAPI->buscarAlunoPorCPF($cpf_teste);
    
    if (!$dadosAluno) {
        echo "<span class='error'>✗ Aluno não encontrado no Moodle</span><br>";
        exit;
    }
    
    echo "<span class='ok'>✓ Aluno encontrado no Moodle</span><br>";
    echo "Nome: {$dadosAluno['nome']}<br>";
    echo "CPF: {$dadosAluno['cpf']}<br>";
    echo "Subdomain: {$dadosAluno['subdomain']}<br>";
    echo "Cursos encontrados: " . count($dadosAluno['cursos']) . "<br><br>";
    
    echo "<strong>Detalhes dos cursos do Moodle:</strong><br>";
    foreach ($dadosAluno['cursos'] as $index => $curso) {
        echo "<div style='background:#f8f9fa; padding:10px; margin:5px 0; border-left:3px solid #007bff;'>";
        echo "<strong>Curso " . ($index + 1) . ":</strong><br>";
        echo "ID Moodle: {$curso['moodle_course_id']}<br>";
        echo "Nome: {$curso['nome']}<br>";
        echo "Nome curto: " . ($curso['nome_curto'] ?? 'N/A') . "<br>";
        echo "Categoria: " . ($curso['categoria'] ?? 'N/A') . "<br>";
        echo "Data início: " . ($curso['data_inicio'] ?? 'N/A') . "<br>";
        echo "Data fim: " . ($curso['data_fim'] ?? 'N/A') . "<br>";
        echo "URL: " . ($curso['url'] ?? 'N/A') . "<br>";
        echo "</div>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Testando AlunoService - Passo a Passo</h3>";
    
    $alunoService = new AlunoService();
    
    // Vamos fazer o salvamento manualmente para identificar onde falha
    $db = (new Database())->getConnection();
    
    echo "<strong>Passo 2.1:</strong> Verificando/criando aluno...<br>";
    $db->beginTransaction();
    
    try {
        // Busca aluno existente
        $stmt = $db->prepare("
            SELECT id, updated_at 
            FROM alunos 
            WHERE cpf = ? AND subdomain = ?
        ");
        $stmt->execute([$dadosAluno['cpf'], $dadosAluno['subdomain']]);
        $alunoExistente = $stmt->fetch();
        
        if ($alunoExistente) {
            echo "<span class='info'>ℹ Aluno já existe (ID: {$alunoExistente['id']})</span><br>";
            $alunoId = $alunoExistente['id'];
        } else {
            echo "<span class='info'>ℹ Criando novo aluno...</span><br>";
            
            $stmt = $db->prepare("
                INSERT INTO alunos (
                    cpf, nome, email, moodle_user_id, subdomain, 
                    city, country, profile_image, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $dadosAluno['cpf'],
                $dadosAluno['nome'],
                $dadosAluno['email'],
                $dadosAluno['moodle_user_id'],
                $dadosAluno['subdomain'],
                $dadosAluno['city'] ?? null,
                $dadosAluno['country'] ?? 'BR',
                $dadosAluno['profile_image'] ?? null
            ]);
            
            $alunoId = $db->lastInsertId();
            echo "<span class='ok'>✓ Aluno criado (ID: {$alunoId})</span><br>";
        }
        
        echo "<br><strong>Passo 2.2:</strong> Processando cursos...<br>";
        
        foreach ($dadosAluno['cursos'] as $index => $cursoMoodle) {
            echo "<br><strong>Processando curso " . ($index + 1) . ": {$cursoMoodle['nome']}</strong><br>";
            
            // Verifica se curso já existe
            $stmt = $db->prepare("
                SELECT id, nome, ativo FROM cursos 
                WHERE moodle_course_id = ? AND subdomain = ?
            ");
            $stmt->execute([$cursoMoodle['moodle_course_id'], $dadosAluno['subdomain']]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                echo "  <span class='info'>ℹ Curso já existe (ID: {$cursoExistente['id']}, Ativo: " . ($cursoExistente['ativo'] ? 'Sim' : 'Não') . ")</span><br>";
                $cursoId = $cursoExistente['id'];
                
                // Atualiza curso se necessário
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET nome = ?, nome_curto = ?, ativo = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $cursoMoodle['nome'],
                    $cursoMoodle['nome_curto'] ?? '',
                    $cursoId
                ]);
                echo "  <span class='ok'>✓ Curso atualizado</span><br>";
                
            } else {
                echo "  <span class='info'>ℹ Criando novo curso...</span><br>";
                
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, nome, nome_curto, valor, subdomain,
                        categoria_id, data_inicio, data_fim, formato, summary,
                        url, ativo, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
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
                    1 // ativo = true
                ]);
                
                $cursoId = $db->lastInsertId();
                echo "  <span class='ok'>✓ Curso criado (ID: {$cursoId})</span><br>";
            }
            
            // Verifica/cria matrícula
            $stmt = $db->prepare("
                SELECT id, status FROM matriculas 
                WHERE aluno_id = ? AND curso_id = ?
            ");
            $stmt->execute([$alunoId, $cursoId]);
            $matriculaExistente = $stmt->fetch();
            
            if ($matriculaExistente) {
                echo "  <span class='info'>ℹ Matrícula já existe (ID: {$matriculaExistente['id']}, Status: {$matriculaExistente['status']})</span><br>";
                
                // Ativa matrícula se necessário
                if ($matriculaExistente['status'] !== 'ativa') {
                    $stmt = $db->prepare("
                        UPDATE matriculas 
                        SET status = 'ativa', updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$matriculaExistente['id']]);
                    echo "  <span class='ok'>✓ Matrícula ativada</span><br>";
                }
            } else {
                echo "  <span class='info'>ℹ Criando nova matrícula...</span><br>";
                
                $stmt = $db->prepare("
                    INSERT INTO matriculas (
                        aluno_id, curso_id, status, data_matricula, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                
                $dataMatricula = $cursoMoodle['data_inicio'] ?? date('Y-m-d');
                
                $stmt->execute([
                    $alunoId,
                    $cursoId,
                    'ativa',
                    $dataMatricula
                ]);
                
                $matriculaId = $db->lastInsertId();
                echo "  <span class='ok'>✓ Matrícula criada (ID: {$matriculaId})</span><br>";
            }
        }
        
        $db->commit();
        echo "<br><span class='ok'>✓ Todos os cursos processados com sucesso!</span><br>";
        
    } catch (Exception $e) {
        $db->rollback();
        echo "<br><span class='error'>✗ Erro durante o processamento: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Verificando Resultados no Banco</h3>";
    
    // Verifica cursos salvos
    $stmt = $db->prepare("
        SELECT c.*, m.status as matricula_status, m.data_matricula
        FROM cursos c
        INNER JOIN matriculas m ON c.id = m.curso_id
        WHERE m.aluno_id = ? AND c.subdomain = ?
        ORDER BY c.nome
    ");
    $stmt->execute([$alunoId, $dadosAluno['subdomain']]);
    $cursosLocal = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<strong>Cursos encontrados no banco local:</strong><br>";
    echo "Total: " . count($cursosLocal) . "<br><br>";
    
    if (!empty($cursosLocal)) {
        foreach ($cursosLocal as $curso) {
            echo "<div style='background:#d4edda; padding:10px; margin:5px 0; border-left:3px solid #28a745;'>";
            echo "<strong>{$curso['nome']}</strong><br>";
            echo "ID: {$curso['id']}<br>";
            echo "Moodle ID: {$curso['moodle_course_id']}<br>";
            echo "Subdomain: {$curso['subdomain']}<br>";
            echo "Ativo: " . ($curso['ativo'] ? 'Sim' : 'Não') . "<br>";
            echo "Status Matrícula: {$curso['matricula_status']}<br>";
            echo "Data Matrícula: " . date('d/m/Y', strtotime($curso['data_matricula'])) . "<br>";
            echo "</div>";
        }
    } else {
        echo "<span class='error'>✗ Nenhum curso encontrado no banco local!</span><br>";
        
        echo "<br><strong>Diagnóstico:</strong><br>";
        
        // Verifica se os cursos existem na tabela cursos
        $stmt = $db->prepare("SELECT * FROM cursos WHERE subdomain = ?");
        $stmt->execute([$dadosAluno['subdomain']]);
        $cursosSubdomain = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Cursos na tabela 'cursos' para o subdomain: " . count($cursosSubdomain) . "<br>";
        
        if (!empty($cursosSubdomain)) {
            foreach ($cursosSubdomain as $curso) {
                echo "- {$curso['nome']} (ID: {$curso['id']}, Ativo: " . ($curso['ativo'] ? 'Sim' : 'Não') . ")<br>";
            }
            
            // Verifica se há matrículas
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM matriculas WHERE aluno_id = ?");
            $stmt->execute([$alunoId]);
            $totalMatriculas = $stmt->fetch()['total'];
            
            echo "<br>Matrículas para o aluno: {$totalMatriculas}<br>";
            
            if ($totalMatriculas == 0) {
                echo "<span class='error'>✗ PROBLEMA: Cursos existem mas não há matrículas!</span><br>";
            }
        } else {
            echo "<span class='error'>✗ PROBLEMA: Nenhum curso foi salvo na tabela 'cursos'!</span><br>";
        }
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Testando Método do AlunoService</h3>";
    
    // Testa o método oficial
    echo "<strong>Testando buscarCursosAlunoPorSubdomain():</strong><br>";
    
    if (method_exists($alunoService, 'buscarCursosAlunoPorSubdomain')) {
        $cursosService = $alunoService->buscarCursosAlunoPorSubdomain($alunoId, $dadosAluno['subdomain']);
        echo "Cursos retornados pelo método: " . count($cursosService) . "<br>";
        
        if (count($cursosService) != count($cursosLocal)) {
            echo "<span class='warning'>⚠ Divergência entre consulta direta (" . count($cursosLocal) . ") e método (" . count($cursosService) . ")</span><br>";
        }
        
        // Mostra a SQL exata que o método executa
        echo "<br><strong>SQL executada pelo método:</strong><br>";
        echo "<pre>";
        echo "SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao\n";
        echo "FROM cursos c\n";
        echo "INNER JOIN matriculas m ON c.id = m.curso_id\n";
        echo "WHERE m.aluno_id = {$alunoId}\n";
        echo "AND c.subdomain = '{$dadosAluno['subdomain']}'\n";
        echo "AND m.status = 'ativa'\n";
        echo "AND c.ativo = 1\n";
        echo "ORDER BY c.nome ASC";
        echo "</pre>";
        
    } else {
        echo "<span class='error'>✗ Método buscarCursosAlunoPorSubdomain não existe!</span><br>";
        
        echo "<br><strong>Testando método padrão:</strong><br>";
        $cursosService = $alunoService->buscarCursosAluno($alunoId);
        echo "Cursos retornados pelo método padrão: " . count($cursosService) . "<br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Testando Salvamento via AlunoService</h3>";
    
    echo "<strong>Executando salvarOuAtualizarAluno():</strong><br>";
    
    try {
        $alunoIdService = $alunoService->salvarOuAtualizarAluno($dadosAluno);
        echo "<span class='ok'>✓ AlunoService executado com sucesso (ID: {$alunoIdService})</span><br>";
        
        // Verifica novamente os cursos
        if (method_exists($alunoService, 'buscarCursosAlunoPorSubdomain')) {
            $cursosApós = $alunoService->buscarCursosAlunoPorSubdomain($alunoIdService, $dadosAluno['subdomain']);
        } else {
            $cursosApós = $alunoService->buscarCursosAluno($alunoIdService);
        }
        
        echo "Cursos após AlunoService: " . count($cursosApós) . "<br>";
        
        if (count($cursosApós) == count($dadosAluno['cursos'])) {
            echo "<span class='ok'>✓ PROBLEMA RESOLVIDO! Cursos salvos corretamente.</span><br>";
        } else {
            echo "<span class='error'>✗ Problema persiste. Cursos esperados: " . count($dadosAluno['cursos']) . ", Encontrados: " . count($cursosApós) . "</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro no AlunoService: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro crítico: " . $e->getMessage() . "</span><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "<div style='margin-top: 30px; padding: 20px; background: #e7f3ff; border: 1px solid #b0d4f1; border-radius: 5px;'>";
echo "<h4>🔍 Resumo do Diagnóstico:</h4>";
echo "<p>Este script executa o salvamento dos cursos passo a passo para identificar exatamente onde está falhando.</p>";

echo "<h5>Possíveis problemas identificados:</h5>";
echo "<ul>";
echo "<li><strong>Cursos não sendo criados:</strong> Erro na inserção na tabela 'cursos'</li>";
echo "<li><strong>Matrículas não sendo criadas:</strong> Erro na inserção na tabela 'matriculas'</li>";
echo "<li><strong>Status incorreto:</strong> Cursos ou matrículas com status 'inativo'</li>";
echo "<li><strong>Método de busca:</strong> O método buscarCursosAlunoPorSubdomain pode não existir</li>";
echo "</ul>";

echo "<h5>Próximos passos:</h5>";
echo "<ol>";
echo "<li>Execute este script para ver onde exatamente falha</li>";
echo "<li>Se os cursos forem salvos aqui, teste novamente o <a href='dashboard.php'>dashboard</a></li>";
echo "<li>Se ainda não funcionar, verifique o arquivo <code>src/AlunoService.php</code></li>";
echo "</ol>";
echo "</div>";
?>