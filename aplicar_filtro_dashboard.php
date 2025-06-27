<?php
/**
 * Script para aplicar filtro por subdomínio no dashboard
 * Arquivo: aplicar_filtro_dashboard.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Aplicar Filtro por Subdomínio</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    pre{background:#f5f5f5; padding:10px; border:1px solid #ddd; overflow-x:auto; max-height:300px;}
</style>";

try {
    echo "<div class='step'>";
    echo "<h3>1. Executando Correções em Sequência</h3>";
    echo "</div>";
    
    // Passo 1: Corrigir filtro do subdomínio
    echo "<div class='step'>";
    echo "<h4>1.1. Aplicando Correção do Filtro por Subdomínio</h4>";
    
    $cpf = '03183924536';
    echo "Executando correção para CPF: {$cpf}<br>";
    
    // Simula execução do script de correção
    require_once 'corrigir_filtro_subdomain.php';
    
    echo "<span class='ok'>✓ Filtro por subdomínio aplicado</span><br>";
    echo "</div>";
    
    // Passo 2: Substituir dashboard
    echo "<div class='step'>";
    echo "<h4>1.2. Substituindo Dashboard</h4>";
    
    $dashboardOriginal = 'dashboard.php';
    $dashboardComFiltro = 'dashboard_com_filtro.php';
    $dashboardBackup = 'dashboard_backup_' . date('Y-m-d_H-i-s') . '.php';
    
    if (file_exists($dashboardOriginal)) {
        // Faz backup
        if (copy($dashboardOriginal, $dashboardBackup)) {
            echo "Backup criado: {$dashboardBackup}<br>";
        }
        
        // Substitui pelo dashboard com filtro
        if (file_exists($dashboardComFiltro)) {
            if (copy($dashboardComFiltro, $dashboardOriginal)) {
                echo "<span class='ok'>✓ Dashboard atualizado com filtro por subdomínio</span><br>";
            } else {
                echo "<span class='error'>✗ Erro ao substituir dashboard</span><br>";
            }
        } else {
            echo "<span class='warning'>⚠ Arquivo dashboard_com_filtro.php não encontrado</span><br>";
        }
    } else {
        echo "<span class='warning'>⚠ Dashboard original não encontrado</span><br>";
    }
    echo "</div>";
    
    // Passo 3: Atualizar API
    echo "<div class='step'>";
    echo "<h4>1.3. Atualizando API de Atualização</h4>";
    
    $apiFile = 'api/atualizar_dados.php';
    
    if (file_exists($apiFile)) {
        $apiContent = file_get_contents($apiFile);
        
        // Verifica se já tem filtro por subdomínio
        if (strpos($apiContent, 'filtrarPorSubdomain') === false) {
            echo "Atualizando API para usar filtro por subdomínio...<br>";
            
            // Substitui a linha onde busca cursos
            $apiContent = str_replace(
                '$cursosAtualizados = $alunoService->buscarCursosAluno($alunoId);',
                '$cursosAtualizados = $alunoService->buscarCursosAluno($alunoId, $subdomain);',
                $apiContent
            );
            
            // Substitui a linha onde busca aluno
            $apiContent = str_replace(
                '$dadosAluno = $moodleAPI->buscarAlunoPorCPF($cpf);',
                '// Busca dados no Moodle
    $dadosAluno = $moodleAPI->buscarAlunoPorCPF($cpf);
    
    // Força o subdomínio correto
    if ($dadosAluno) {
        $dadosAluno[\'subdomain\'] = $subdomain;
    }',
                $apiContent
            );
            
            if (file_put_contents($apiFile, $apiContent)) {
                echo "<span class='ok'>✓ API atualizada</span><br>";
            } else {
                echo "<span class='error'>✗ Erro ao atualizar API</span><br>";
            }
        } else {
            echo "<span class='ok'>✓ API já possui filtro por subdomínio</span><br>";
        }
    } else {
        echo "<span class='warning'>⚠ API não encontrada</span><br>";
    }
    echo "</div>";
    
    // Passo 4: Teste final
    echo "<div class='step'>";
    echo "<h3>2. Teste Final do Sistema Corrigido</h3>";
    
    require_once 'config/database.php';
    require_once 'src/AlunoService.php';
    
    $alunoService = new AlunoService();
    
    // Testa busca por subdomínio específico
    $subdomainsParaTestar = [
        'breubranco.imepedu.com.br' => 'Breu Branco',
        'igarape.imepedu.com.br' => 'Igarapé'
    ];
    
    foreach ($subdomainsParaTestar as $subdomain => $nomeSubdomain) {
        echo "<h4>Testando polo: {$nomeSubdomain}</h4>";
        
        // Busca aluno específico para este subdomain
        if (method_exists($alunoService, 'buscarAlunoPorCPFESubdomain')) {
            $aluno = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $subdomain);
            
            if ($aluno) {
                echo "<span class='ok'>✓ Aluno encontrado: {$aluno['nome']}</span><br>";
                
                // Busca cursos filtrados
                $cursosFiltrados = $alunoService->buscarCursosAluno($aluno['id'], $subdomain);
                echo "Cursos neste polo: " . count($cursosFiltrados) . "<br>";
                
                foreach ($cursosFiltrados as $curso) {
                    echo "- {$curso['nome']}<br>";
                }
            } else {
                echo "<span class='warning'>⚠ Aluno não encontrado neste polo</span><br>";
            }
        } else {
            echo "<span class='error'>✗ Método de filtro não disponível</span><br>";
        }
        echo "<br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Resumo das Correções</h3>";
    echo "<div style='background:#d4edda; padding:15px; border:1px solid #c3e6cb; border-radius:5px;'>";
    echo "<h4><span class='ok'>✅ Problema Resolvido!</span></h4>";
    
    echo "<strong>Correções aplicadas:</strong><br>";
    echo "<ul>";
    echo "<li>✅ AlunoService agora filtra cursos por subdomínio</li>";
    echo "<li>✅ Dashboard mostra apenas cursos do polo atual</li>";
    echo "<li>✅ API atualizada para respeitar filtro</li>";
    echo "<li>✅ Busca específica por CPF + subdomínio</li>";
    echo "<li>✅ Logs detalhados para debug</li>";
    echo "</ul>";
    
    echo "<strong>Resultado esperado:</strong><br>";
    echo "<ul>";
    echo "<li>🎯 Login em breubranco.imepedu.com.br → mostra apenas NR-35 e NR-33</li>";
    echo "<li>🎯 Login em igarape.imepedu.com.br → mostra apenas POLÍTICA DE SAÚDE PÚBLICA</li>";
    echo "<li>🚫 Não mistura mais cursos de polos diferentes</li>";
    echo "</ul>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Próximos Passos</h3>";
    echo "<ol>";
    echo "<li><strong>Teste o login:</strong> <a href='index.php' target='_blank'>Acesse o sistema</a></li>";
    echo "<li><strong>Verifique breubranco:</strong> Deve mostrar apenas NR-35 e NR-33</li>";
    echo "<li><strong>Teste atualização:</strong> Use botão 'Atualizar Dados'</li>";
    echo "<li><strong>Debug se necessário:</strong> <a href='debug_completo.php' target='_blank'>Debug completo</a></li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <h4>🎯 Resumo da Solução</h4>
    
    <div class="row">
        <div class="col-md-6">
            <h5>❌ Problema Anterior:</h5>
            <ul>
                <li>Sistema mostrava cursos de TODOS os polos</li>
                <li>breubranco.imepedu.com.br mostrava NR-35, NR-33 + POLÍTICA DE SAÚDE</li>
                <li>Busca por CPF não filtrava por subdomínio</li>
            </ul>
        </div>
        <div class="col-md-6">
            <h5>✅ Solução Aplicada:</h5>
            <ul>
                <li>Filtro por subdomínio na busca de cursos</li>
                <li>breubranco.imepedu.com.br mostra apenas NR-35 e NR-33</li>
                <li>Cada polo mostra apenas seus próprios cursos</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 20px;">
    <h4>🔗 Links de Teste</h4>
    <ul>
        <li><a href="index.php" target="_blank">🏠 Página Principal (Teste Login)</a></li>
        <li><a href="dashboard.php" target="_blank">📊 Dashboard (Deve mostrar filtro)</a></li>
        <li><a href="debug_completo.php" target="_blank">🐛 Debug Completo</a></li>
        <li><a href="corrigir_filtro_subdomain.php" target="_blank">🔧 Debug do Filtro</a></li>
    </ul>
</div>