<?php
/**
 * Script para aplicar correção do AlunoService
 * Arquivo: aplicar_correcao_aluno_service.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Aplicar Correção do AlunoService</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    pre{background:#f5f5f5; padding:10px; border:1px solid #ddd; overflow-x:auto;}
</style>";

$arquivoOriginal = 'src/AlunoService.php';
$arquivoBackup = 'src/AlunoService_backup_' . date('Y-m-d_H-i-s') . '.php';
$arquivoCorrigido = 'src/AlunoService_corrigido.php';

try {
    echo "<div class='step'>";
    echo "<h3>1. Verificando Arquivos</h3>";
    
    // Verifica se arquivo original existe
    if (!file_exists($arquivoOriginal)) {
        echo "<span class='error'>✗ Arquivo original não encontrado: {$arquivoOriginal}</span><br>";
        exit;
    }
    echo "<span class='ok'>✓ Arquivo original encontrado</span><br>";
    
    // Verifica se arquivo corrigido existe
    if (!file_exists($arquivoCorrigido)) {
        echo "<span class='error'>✗ Arquivo corrigido não encontrado: {$arquivoCorrigido}</span><br>";
        echo "<span class='info'>Você precisa criar o arquivo corrigido primeiro</span><br>";
        exit;
    }
    echo "<span class='ok'>✓ Arquivo corrigido encontrado</span><br>";
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Fazendo Backup do Arquivo Original</h3>";
    
    if (copy($arquivoOriginal, $arquivoBackup)) {
        echo "<span class='ok'>✓ Backup criado: {$arquivoBackup}</span><br>";
    } else {
        echo "<span class='error'>✗ Falha ao criar backup</span><br>";
        exit;
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Aplicando Correção</h3>";
    
    if (copy($arquivoCorrigido, $arquivoOriginal)) {
        echo "<span class='ok'>✓ Arquivo corrigido aplicado com sucesso</span><br>";
    } else {
        echo "<span class='error'>✗ Falha ao aplicar correção</span><br>";
        exit;
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Testando AlunoService Corrigido</h3>";
    
    // Carrega o arquivo corrigido
    require_once $arquivoOriginal;
    
    try {
        $alunoService = new AlunoService();
        echo "<span class='ok'>✓ AlunoService carregado sem erros</span><br>";
        
        // Testa o serviço
        $testeServico = $alunoService->testarServico();
        
        if ($testeServico['status'] === 'ok') {
            echo "<span class='ok'>✓ Teste de serviço passou</span><br>";
            echo "Tabelas verificadas: " . implode(', ', $testeServico['tabelas_verificadas']) . "<br>";
        } else {
            echo "<span class='error'>✗ Teste de serviço falhou: " . $testeServico['error'] . "</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro ao carregar AlunoService: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Teste Completo com Dados Reais</h3>";
    
    // Dados de teste (mesmos do erro anterior)
    $dadosAluno = [
        'nome' => 'Carlos Santos',
        'cpf' => '03183924536',
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
    
    try {
        echo "Testando salvamento com dados reais...<br>";
        
        $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAluno);
        echo "<span class='ok'>✓ Aluno salvo/atualizado com sucesso! ID: {$alunoId}</span><br>";
        
        // Verifica cursos salvos
        $cursosLocal = $alunoService->buscarCursosAluno($alunoId);
        echo "Cursos encontrados no banco local: " . count($cursosLocal) . "<br>";
        
        if (count($cursosLocal) > 0) {
            echo "<ul>";
            foreach ($cursosLocal as $curso) {
                echo "<li>{$curso['nome']} (ID: {$curso['id']})</li>";
            }
            echo "</ul>";
        }
        
        // Verifica aluno
        $alunoVerificacao = $alunoService->buscarAlunoPorCPF($dadosAluno['cpf']);
        if ($alunoVerificacao) {
            echo "<span class='ok'>✓ Aluno pode ser encontrado por CPF</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro no teste de salvamento: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>6. Resumo</h3>";
    
    echo "<span class='ok'>✓ Correção aplicada com sucesso!</span><br>";
    echo "<strong>O que foi corrigido:</strong><br>";
    echo "<ul>";
    echo "<li>Melhor tratamento de erros com logs detalhados</li>";
    echo "<li>Validação robusta de dados de entrada</li>";
    echo "<li>Tratamento de exceções PDO específicas</li>";
    echo "<li>Verificação de transações antes de rollback</li>";
    echo "<li>Logs de debug em cada etapa do processo</li>";
    echo "<li>Verificação de existência de tabelas antes de usar</li>";
    echo "<li>Métodos de teste e diagnóstico</li>";
    echo "</ul>";
    
    echo "<strong>Próximos passos:</strong><br>";
    echo "<ol>";
    echo "<li><a href='debug_completo.php' target='_blank'>Execute o debug completo novamente</a></li>";
    echo "<li><a href='dashboard.php' target='_blank'>Teste o dashboard</a></li>";
    echo "<li><a href='index.php'>Teste o login no sistema</a></li>";
    echo "</ol>";
    
    echo "<strong>Arquivos de backup:</strong><br>";
    echo "Backup do arquivo original: <code>{$arquivoBackup}</code><br>";
    echo "Para reverter: <code>cp {$arquivoBackup} {$arquivoOriginal}</code><br>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro durante a aplicação da correção: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<style>
.teste-rapido {
    margin-top: 30px;
    padding: 20px;
    background: #e8f5e8;
    border: 1px solid #c3e6c3;
    border-radius: 5px;
}
</style>

<div class="teste-rapido">
    <h4>🧪 Teste Rápido</h4>
    <p>Para verificar se a correção funcionou, execute este teste simples:</p>
    
    <form method="post" action="">
        <input type="hidden" name="teste_rapido" value="1">
        <label>CPF para teste: 
            <input type="text" name="cpf_teste" value="03183924536" maxlength="11">
        </label>
        <label>Subdomain: 
            <select name="subdomain_teste">
                <option value="breubranco.imepedu.com.br">Breu Branco</option>
                <option value="tucurui.imepedu.com.br">Tucuruí</option>
                <option value="moju.imepedu.com.br">Moju</option>
            </select>
        </label>
        <input type="submit" value="Testar Agora" style="background:#007bff;color:white;padding:8px 16px;border:none;border-radius:4px;">
    </form>
</div>

<?php
// Teste rápido se solicitado
if (isset($_POST['teste_rapido']) && $_POST['teste_rapido'] == '1') {
    echo "<div class='step'>";
    echo "<h3>🧪 Resultado do Teste Rápido</h3>";
    
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf_teste']);
    $subdomain = $_POST['subdomain_teste'];
    
    try {
        // Inclui arquivos necessários
        require_once 'config/moodle.php';
        require_once 'src/MoodleAPI.php';
        
        echo "CPF: {$cpf}<br>";
        echo "Subdomain: {$subdomain}<br><br>";
        
        // Testa API do Moodle
        echo "1. Testando API do Moodle...<br>";
        $api = new MoodleAPI($subdomain);
        $dadosAluno = $api->buscarAlunoPorCPF($cpf);
        
        if ($dadosAluno) {
            echo "<span class='ok'>✓ Aluno encontrado no Moodle: {$dadosAluno['nome']}</span><br>";
            echo "Cursos: " . count($dadosAluno['cursos']) . "<br><br>";
            
            // Testa AlunoService
            echo "2. Testando AlunoService...<br>";
            $alunoService = new AlunoService();
            $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAluno);
            
            echo "<span class='ok'>✓ AlunoService funcionou! Aluno ID: {$alunoId}</span><br>";
            
            // Verifica cursos
            $cursosLocal = $alunoService->buscarCursosAluno($alunoId);
            echo "Cursos salvos localmente: " . count($cursosLocal) . "<br>";
            
            if (count($cursosLocal) == count($dadosAluno['cursos'])) {
                echo "<span class='ok'>✓ Todos os cursos foram sincronizados corretamente!</span><br>";
            } else {
                echo "<span class='warning'>⚠ Divergência: Moodle tem " . count($dadosAluno['cursos']) . " cursos, local tem " . count($cursosLocal) . "</span><br>";
            }
            
        } else {
            echo "<span class='error'>✗ Aluno não encontrado no Moodle</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro no teste: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <h4>📋 Checklist de Verificação</h4>
    <ul style="list-style-type: none;">
        <li>☐ AlunoService corrigido aplicado</li>
        <li>☐ Teste rápido executado com sucesso</li>
        <li>☐ Debug completo sem erros</li>
        <li>☐ Login funcionando</li>
        <li>☐ Dashboard mostrando cursos</li>
        <li>☐ API de atualização respondendo</li>
    </ul>
</div>

<div style="margin-top: 20px;">
    <h4>🔗 Links Úteis</h4>
    <ul>
        <li><a href="debug_completo.php" target="_blank">Debug Completo do Sistema</a></li>
        <li><a href="debug_aluno_service.php" target="_blank">Debug Específico do AlunoService</a></li>
        <li><a href="teste_moodle.php" target="_blank">Teste da API Moodle</a></li>
        <li><a href="dashboard.php" target="_blank">Dashboard (teste final)</a></li>
        <li><a href="index.php">Página Inicial do Sistema</a></li>
    </ul>
</div>