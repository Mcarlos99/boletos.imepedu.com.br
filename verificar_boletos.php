<?php
/**
 * Verificar status dos boletos e cursos
 * Arquivo: verificar_boletos.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Verificação de Boletos e Cursos</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    pre{background:#f5f5f5; padding:15px; border:1px solid #ddd;}
    table{border-collapse:collapse; width:100%; margin:10px 0;}
    th,td{border:1px solid #ddd; padding:8px; text-align:left;}
    th{background:#f2f2f2;}
</style>";

$cpf_teste = '03183924536';

try {
    require_once 'config/database.php';
    
    $db = (new Database())->getConnection();
    
    echo "<h3>1. Verificando Aluno</h3>";
    $stmt = $db->prepare("SELECT * FROM alunos WHERE cpf = ?");
    $stmt->execute([$cpf_teste]);
    $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno) {
        echo "<span class='ok'>✓ Aluno encontrado</span><br>";
        echo "ID: {$aluno['id']}<br>";
        echo "Nome: {$aluno['nome']}<br>";
        echo "Email: {$aluno['email']}<br>";
        echo "Subdomain: {$aluno['subdomain']}<br>";
    } else {
        echo "<span class='error'>✗ Aluno não encontrado</span><br>";
        exit;
    }
    
    echo "<h3>2. Verificando Cursos</h3>";
    $stmt = $db->prepare("
        SELECT c.*, m.status as matricula_status, m.data_matricula
        FROM cursos c
        INNER JOIN matriculas m ON c.id = m.curso_id
        WHERE m.aluno_id = ?
        ORDER BY c.nome ASC
    ");
    $stmt->execute([$aluno['id']]);
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Cursos encontrados: " . count($cursos) . "<br>";
    
    if (!empty($cursos)) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Nome</th><th>Status Curso</th><th>Status Matrícula</th><th>Data Matrícula</th></tr>";
        foreach ($cursos as $curso) {
            echo "<tr>";
            echo "<td>{$curso['id']}</td>";
            echo "<td>{$curso['nome']}</td>";
            echo "<td>" . ($curso['ativo'] ? '<span class="ok">Ativo</span>' : '<span class="error">Inativo</span>') . "</td>";
            echo "<td>" . ($curso['matricula_status'] == 'ativa' ? '<span class="ok">Ativa</span>' : '<span class="warning">' . $curso['matricula_status'] . '</span>') . "</td>";
            echo "<td>" . date('d/m/Y', strtotime($curso['data_matricula'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<span class='warning'>⚠ Nenhum curso encontrado</span><br>";
    }
    
    echo "<h3>3. Verificando Boletos</h3>";
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ?
        ORDER BY b.vencimento DESC
    ");
    $stmt->execute([$aluno['id']]);
    $boletos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Boletos encontrados: " . count($boletos) . "<br>";
    
    if (!empty($boletos)) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Curso</th><th>Número</th><th>Descrição</th><th>Valor</th><th>Vencimento</th><th>Status</th></tr>";
        foreach ($boletos as $boleto) {
            $status_class = '';
            switch($boleto['status']) {
                case 'pago': $status_class = 'ok'; break;
                case 'vencido': $status_class = 'error'; break;
                case 'pendente': $status_class = 'warning'; break;
            }
            
            echo "<tr>";
            echo "<td>{$boleto['id']}</td>";
            echo "<td>{$boleto['curso_nome']}</td>";
            echo "<td>{$boleto['numero_boleto']}</td>";
            echo "<td>{$boleto['descricao']}</td>";
            echo "<td>R$ " . number_format($boleto['valor'], 2, ',', '.') . "</td>";
            echo "<td>" . date('d/m/Y', strtotime($boleto['vencimento'])) . "</td>";
            echo "<td><span class='{$status_class}'>" . strtoupper($boleto['status']) . "</span></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<span class='warning'>⚠ Nenhum boleto encontrado</span><br>";
        echo "<p><strong>Para gerar boletos de teste:</strong></p>";
        echo "<ol>";
        echo "<li><a href='gerar_boletos_teste.php'>Execute o gerador de boletos</a></li>";
        echo "<li>Ou execute o comando SQL abaixo:</li>";
        echo "</ol>";
        
        echo "<h4>SQL para gerar boletos manualmente:</h4>";
        echo "<pre>";
        foreach ($cursos as $curso) {
            echo "-- Boletos para o curso: {$curso['nome']}\n";
            echo "INSERT INTO boletos (aluno_id, curso_id, numero_boleto, valor, vencimento, status, descricao, created_at)\nVALUES \n";
            echo "({$aluno['id']}, {$curso['id']}, '" . date('Ymd') . "001', 150.00, '2025-02-15', 'pendente', 'Mensalidade Fevereiro/2025', NOW()),\n";
            echo "({$aluno['id']}, {$curso['id']}, '" . date('Ymd') . "002', 150.00, '2025-03-15', 'pendente', 'Mensalidade Março/2025', NOW()),\n";
            echo "({$aluno['id']}, {$curso['id']}, '" . date('Ymd') . "003', 150.00, '2024-12-15', 'vencido', 'Mensalidade Dezembro/2024', NOW());\n\n";
        }
        echo "</pre>";
    }
    
    echo "<h3>4. Testando AlunoService (Método Corrigido)</h3>";
    
    // Testa o método buscarCursosAluno
    require_once 'src/AlunoService.php';
    
    $alunoService = new AlunoService();
    $cursosService = $alunoService->buscarCursosAluno($aluno['id']);
    
    echo "Cursos retornados pelo AlunoService: " . count($cursosService) . "<br>";
    
    if (count($cursosService) != count($cursos)) {
        echo "<span class='warning'>⚠ Divergência entre consulta direta (" . count($cursos) . ") e AlunoService (" . count($cursosService) . ")</span><br>";
        
        echo "<h4>Investigando diferença:</h4>";
        echo "<strong>Consulta direta do AlunoService:</strong><br>";
        echo "<pre>";
        echo "SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao\n";
        echo "FROM cursos c\n";
        echo "INNER JOIN matriculas m ON c.id = m.curso_id\n";
        echo "WHERE m.aluno_id = {$aluno['id']} AND m.status = 'ativa' AND c.ativo = 1\n";
        echo "ORDER BY c.nome ASC";
        echo "</pre>";
        
        // Executa a consulta exata do AlunoService
        $stmt = $db->prepare("
            SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ? AND m.status = 'ativa' AND c.ativo = 1
            ORDER BY c.nome ASC
        ");
        $stmt->execute([$aluno['id']]);
        $cursosCorrigidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<br><strong>Resultado da consulta do AlunoService:</strong><br>";
        echo "Cursos encontrados: " . count($cursosCorrigidos) . "<br>";
        
        if (!empty($cursosCorrigidos)) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Nome</th><th>Ativo</th><th>Status Matrícula</th></tr>";
            foreach ($cursosCorrigidos as $curso) {
                echo "<tr>";
                echo "<td>{$curso['id']}</td>";
                echo "<td>{$curso['nome']}</td>";
                echo "<td>" . ($curso['ativo'] ? 'Sim' : 'Não') . "</td>";
                echo "<td>{$curso['matricula_status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Verifica quais cursos estão sendo filtrados
        echo "<h4>Cursos filtrados (possivelmente o problema):</h4>";
        $stmt = $db->prepare("
            SELECT c.*, m.status as matricula_status
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ? AND (m.status != 'ativa' OR c.ativo = 0)
        ");
        $stmt->execute([$aluno['id']]);
        $cursosFiltrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($cursosFiltrados)) {
            echo "<table>";
            echo "<tr><th>Nome</th><th>Ativo</th><th>Status Matrícula</th><th>Motivo do Filtro</th></tr>";
            foreach ($cursosFiltrados as $curso) {
                $motivo = [];
                if ($curso['matricula_status'] != 'ativa') $motivo[] = "Matrícula: {$curso['matricula_status']}";
                if (!$curso['ativo']) $motivo[] = "Curso inativo";
                
                echo "<tr>";
                echo "<td>{$curso['nome']}</td>";
                echo "<td>" . ($curso['ativo'] ? 'Sim' : 'Não') . "</td>";
                echo "<td>{$curso['matricula_status']}</td>";
                echo "<td>" . implode(', ', $motivo) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "Nenhum curso foi filtrado.<br>";
        }
    } else {
        echo "<span class='ok'>✓ AlunoService funcionando corretamente</span><br>";
    }
    
    echo "<h3>5. Resumo e Soluções</h3>";
    
    if (count($cursosService) == 0) {
        echo "<div style='background:#fff3cd; padding:15px; border:1px solid #ffeaa7; border-radius:5px;'>";
        echo "<h4>🔧 Problema Identificado: Nenhum curso ativo</h4>";
        echo "<p><strong>Possíveis causas:</strong></p>";
        echo "<ul>";
        echo "<li>Matrículas com status diferente de 'ativa'</li>";
        echo "<li>Cursos com flag 'ativo' = false</li>";
        echo "<li>Dados não sincronizados corretamente</li>";
        echo "</ul>";
        
        echo "<p><strong>Soluções:</strong></p>";
        echo "<ol>";
        echo "<li><strong>Ativar matrículas:</strong></li>";
        echo "<pre>UPDATE matriculas SET status = 'ativa' WHERE aluno_id = {$aluno['id']};</pre>";
        
        echo "<li><strong>Ativar cursos:</strong></li>";
        echo "<pre>UPDATE cursos SET ativo = 1 WHERE id IN (SELECT curso_id FROM matriculas WHERE aluno_id = {$aluno['id']});</pre>";
        
        echo "<li><strong>Executar correção completa:</strong></li>";
        echo "<pre>";
        echo "UPDATE matriculas SET status = 'ativa' WHERE aluno_id = {$aluno['id']};\n";
        echo "UPDATE cursos SET ativo = 1 WHERE id IN (SELECT curso_id FROM matriculas WHERE aluno_id = {$aluno['id']});\n";
        echo "</pre>";
        echo "</ol>";
        echo "</div>";
        
        // Executa correção automaticamente
        echo "<h4>Executando correção automática...</h4>";
        
        $db->beginTransaction();
        
        // Ativa matrículas
        $stmt = $db->prepare("UPDATE matriculas SET status = 'ativa' WHERE aluno_id = ?");
        $stmt->execute([$aluno['id']]);
        echo "Matrículas ativadas: " . $stmt->rowCount() . "<br>";
        
        // Ativa cursos
        $stmt = $db->prepare("UPDATE cursos SET ativo = 1 WHERE id IN (SELECT curso_id FROM matriculas WHERE aluno_id = ?)");
        $stmt->execute([$aluno['id']]);
        echo "Cursos ativados: " . $stmt->rowCount() . "<br>";
        
        $db->commit();
        
        echo "<span class='ok'>✓ Correção executada com sucesso!</span><br>";
        echo "<br><a href='verificar_boletos.php'>🔄 Recarregar página para verificar</a><br>";
        
    } elseif (count($boletos) == 0) {
        echo "<div style='background:#e7f3ff; padding:15px; border:1px solid #b0d4f1; border-radius:5px;'>";
        echo "<h4>ℹ️ Cursos OK, mas sem boletos</h4>";
        echo "<p>Os cursos estão corretos, mas não há boletos gerados.</p>";
        echo "<p><strong>Próximo passo:</strong> <a href='gerar_boletos_teste.php'>Gerar boletos de teste</a></p>";
        echo "</div>";
        
    } else {
        echo "<div style='background:#d4edda; padding:15px; border:1px solid #c3e6cb; border-radius:5px;'>";
        echo "<h4>✅ Sistema funcionando corretamente!</h4>";
        echo "<ul>";
        echo "<li>Aluno: OK</li>";
        echo "<li>Cursos: " . count($cursosService) . " ativos</li>";
        echo "<li>Boletos: " . count($boletos) . " gerados</li>";
        echo "</ul>";
        echo "<p><strong>Próximo passo:</strong> <a href='dashboard.php'>Acessar Dashboard</a></p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>