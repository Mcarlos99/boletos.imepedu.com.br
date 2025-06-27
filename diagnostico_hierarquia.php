<?php
/**
 * Diagnóstico de Hierarquia dos Polos Moodle
 * Arquivo: diagnostico_hierarquia.php
 * 
 * Testa e mostra como cada polo está estruturado
 */

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico de Hierarquia - Polos Moodle</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .polo { 
            background: white; 
            margin: 20px 0; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .polo h2 { 
            margin-top: 0; 
            color: #333; 
            border-bottom: 2px solid #007bff; 
            padding-bottom: 10px; 
        }
        .estrutura { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px; 
            margin: 20px 0; 
        }
        .cursos-tradicionais, .categorias-cursos { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 5px; 
            border-left: 4px solid #007bff; 
        }
        .categorias-cursos { border-left-color: #28a745; }
        .curso-item { 
            background: white; 
            margin: 8px 0; 
            padding: 10px; 
            border-radius: 4px; 
            border: 1px solid #dee2e6; 
        }
        .categoria-item { 
            background: #e8f5e8; 
            margin: 8px 0; 
            padding: 10px; 
            border-radius: 4px; 
            border: 1px solid #c3e6c3; 
        }
        .hierarquia { 
            font-size: 0.9em; 
            color: #666; 
            margin-left: 20px; 
        }
        .estatisticas { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); 
            gap: 10px; 
            margin: 15px 0; 
        }
        .stat-card { 
            background: #007bff; 
            color: white; 
            padding: 15px; 
            border-radius: 5px; 
            text-align: center; 
        }
        .stat-card.green { background: #28a745; }
        .stat-card.orange { background: #fd7e14; }
        .stat-card.red { background: #dc3545; }
        .debug { 
            background: #f8f9fa; 
            border: 1px solid #dee2e6; 
            padding: 10px; 
            border-radius: 4px; 
            font-family: monospace; 
            font-size: 12px; 
            margin: 10px 0; 
            max-height: 200px; 
            overflow-y: auto; 
        }
        .alert { 
            padding: 15px; 
            border-radius: 5px; 
            margin: 10px 0; 
        }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .btn { 
            display: inline-block; 
            padding: 8px 16px; 
            background: #007bff; 
            color: white; 
            text-decoration: none; 
            border-radius: 4px; 
            margin: 5px; 
        }
        .btn:hover { background: #0056b3; }
        .hierarchy-tree { 
            margin-left: 20px; 
            border-left: 2px solid #dee2e6; 
            padding-left: 15px; 
        }
        @media (max-width: 768px) {
            .estrutura { grid-template-columns: 1fr; }
            .estatisticas { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏗️ Diagnóstico de Hierarquia - Polos Moodle</h1>
        <p><em>Analisando estruturas organizacionais de cada polo...</em></p>

        <?php
        $polosAtivos = MoodleConfig::getActiveSubdomains();
        $resumoGeral = [
            'total_polos' => count($polosAtivos),
            'polos_com_hierarquia' => 0,
            'polos_tradicionais' => 0,
            'polos_com_erro' => 0
        ];

        foreach ($polosAtivos as $polo) {
            $config = MoodleConfig::getConfig($polo);
            echo "<div class='polo'>";
            echo "<h2>🏫 {$config['name']} ({$polo})</h2>";
            
            try {
                $token = MoodleConfig::getToken($polo);
                
                if (!$token || $token === 'x') {
                    echo "<div class='alert alert-warning'>";
                    echo "<strong>⚠️ Token não configurado</strong><br>";
                    echo "Configure um token válido no arquivo config/moodle.php";
                    echo "</div>";
                    $resumoGeral['polos_com_erro']++;
                    echo "</div>";
                    continue;
                }
                
                // Conecta com Moodle
                $moodleAPI = new MoodleAPI($polo);
                $testeConexao = $moodleAPI->testarConexao();
                
                if (!$testeConexao['sucesso']) {
                    echo "<div class='alert alert-danger'>";
                    echo "<strong>❌ Falha na conexão</strong><br>";
                    echo "Erro: " . htmlspecialchars($testeConexao['erro']);
                    echo "</div>";
                    $resumoGeral['polos_com_erro']++;
                    echo "</div>";
                    continue;
                }
                
                echo "<div class='alert alert-success'>";
                echo "<strong>✅ Conexão OK</strong> - {$testeConexao['nome_site']} (v{$testeConexao['versao']})";
                echo "</div>";
                
                // Busca cursos tradicionais
                echo "<h3>📊 Análise da Estrutura</h3>";
                
                $cursosTracionais = [];
                $categoriasCursos = [];
                
                try {
                    // Busca cursos
                    $courses = $moodleAPI->callMoodleFunction('core_course_get_courses');
                    foreach ($courses as $course) {
                        if ($course['id'] != 1) { // Pula curso Site
                            $cursosTracionais[] = $course;
                        }
                    }
                } catch (Exception $e) {
                    echo "<div class='alert alert-warning'>Erro ao buscar cursos: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
                
                try {
                    // Busca categorias
                    $categories = $moodleAPI->callMoodleFunction('core_course_get_categories');
                    foreach ($categories as $category) {
                        if ($category['id'] != 1) { // Pula categoria raiz
                            $categoriasCursos[] = $category;
                        }
                    }
                } catch (Exception $e) {
                    echo "<div class='alert alert-warning'>Erro ao buscar categorias: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
                
                // Estatísticas
                echo "<div class='estatisticas'>";
                echo "<div class='stat-card'><strong>" . count($cursosTracionais) . "</strong><br>Cursos</div>";
                echo "<div class='stat-card green'><strong>" . count($categoriasCursos) . "</strong><br>Categorias</div>";
                
                // Detecta estrutura
                $estruturaTipo = 'indefinida';
                if (count($cursosTracionais) > count($categoriasCursos)) {
                    $estruturaTipo = 'tradicional';
                    $resumoGeral['polos_tradicionais']++;
                    echo "<div class='stat-card orange'><strong>Tradicional</strong><br>Estrutura</div>";
                } elseif (count($categoriasCursos) > 3) {
                    $estruturaTipo = 'hierárquica';
                    $resumoGeral['polos_com_hierarquia']++;
                    echo "<div class='stat-card red'><strong>Hierárquica</strong><br>Estrutura</div>";
                } else {
                    echo "<div class='stat-card'><strong>Mista</strong><br>Estrutura</div>";
                }
                echo "</div>";
                
                // Mostra estrutura detalhada
                echo "<div class='estrutura'>";
                
                // Cursos tradicionais
                echo "<div class='cursos-tradicionais'>";
                echo "<h4>📚 Cursos Tradicionais (" . count($cursosTracionais) . ")</h4>";
                if (!empty($cursosTracionais)) {
                    foreach (array_slice($cursosTracionais, 0, 10) as $course) {
                        echo "<div class='curso-item'>";
                        echo "<strong>{$course['fullname']}</strong>";
                        if (!empty($course['shortname'])) {
                            echo " <em>({$course['shortname']})</em>";
                        }
                        echo "<br><small>ID: {$course['id']} | Categoria: {$course['categoryid']} | ";
                        echo "Alunos: " . ($course['enrolledusercount'] ?? 0) . "</small>";
                        echo "</div>";
                    }
                    if (count($cursosTracionais) > 10) {
                        echo "<div class='curso-item'><em>... e mais " . (count($cursosTracionais) - 10) . " cursos</em></div>";
                    }
                } else {
                    echo "<p><em>Nenhum curso tradicional encontrado</em></p>";
                }
                echo "</div>";
                
                // Categorias (possíveis cursos)
                echo "<div class='categorias-cursos'>";
                echo "<h4>📁 Categorias (" . count($categoriasCursos) . ")</h4>";
                
                if (!empty($categoriasCursos)) {
                    // Organiza categorias por hierarquia
                    $categoriasOrganizadas = [];
                    foreach ($categoriasCursos as $cat) {
                        if ($cat['parent'] == 0) {
                            $categoriasOrganizadas[$cat['id']] = [
                                'categoria' => $cat,
                                'filhos' => []
                            ];
                        }
                    }
                    
                    // Adiciona filhos
                    foreach ($categoriasCursos as $cat) {
                        if ($cat['parent'] != 0) {
                            if (isset($categoriasOrganizadas[$cat['parent']])) {
                                $categoriasOrganizadas[$cat['parent']]['filhos'][] = $cat;
                            } else {
                                // Categoria órfã ou nível mais profundo
                                $categoriasOrganizadas['orfas'][] = $cat;
                            }
                        }
                    }
                    
                    // Mostra hierarquia
                    foreach ($categoriasOrganizadas as $nivel1) {
                        if (is_array($nivel1) && isset($nivel1['categoria'])) {
                            $cat = $nivel1['categoria'];
                            echo "<div class='categoria-item'>";
                            echo "<strong>📁 {$cat['name']}</strong>";
                            echo "<br><small>ID: {$cat['id']} | Cursos: " . ($cat['coursecount'] ?? 0) . "</small>";
                            
                            if (!empty($nivel1['filhos'])) {
                                echo "<div class='hierarchy-tree'>";
                                foreach ($nivel1['filhos'] as $filho) {
                                    echo "<div class='categoria-item'>";
                                    echo "<strong>📂 {$filho['name']}</strong>";
                                    echo "<br><small>ID: {$filho['id']} | Cursos: " . ($filho['coursecount'] ?? 0) . "</small>";
                                    
                                    // Determina se esta subcategoria seria um "curso"
                                    $seriaCurso = $this->nomeCategoriaSugereCurso($filho['name']);
                                    if ($seriaCurso) {
                                        echo "<br><span style='color: #28a745; font-weight: bold;'>✓ Provável CURSO</span>";
                                    }
                                    echo "</div>";
                                }
                                echo "</div>";
                            }
                            echo "</div>";
                        }
                    }
                } else {
                    echo "<p><em>Nenhuma categoria encontrada</em></p>";
                }
                echo "</div>";
                echo "</div>";
                
                // Recomendações específicas
                echo "<h3>💡 Recomendações para este Polo</h3>";
                
                if ($estruturaTipo === 'hierárquica') {
                    echo "<div class='alert alert-success'>";
                    echo "<strong>✅ Estrutura Hierárquica Detectada</strong><br>";
                    echo "Este polo usa categorias como cursos. O sistema buscará automaticamente as subcategorias que representam cursos reais.";
                    echo "</div>";
                } elseif ($estruturaTipo === 'tradicional') {
                    echo "<div class='alert alert-success'>";
                    echo "<strong>✅ Estrutura Tradicional Detectada</strong><br>";
                    echo "Este polo usa cursos tradicionais do Moodle. O sistema funcionará normalmente.";
                    echo "</div>";
                } else {
                    echo "<div class='alert alert-warning'>";
                    echo "<strong>⚠️ Estrutura Mista ou Indefinida</strong><br>";
                    echo "Este polo tem uma estrutura mista. O sistema tentará detectar automaticamente os cursos corretos.";
                    echo "</div>";
                }
                
                // Teste da API
                echo "<h3>🧪 Teste da API</h3>";
                echo "<a href='/admin/api/buscar-cursos.php?polo=" . urlencode($polo) . "' target='_blank' class='btn'>Testar API deste Polo</a>";
                
                // Debug raw data
                echo "<details style='margin-top: 20px;'>";
                echo "<summary>🔍 Dados Brutos (Debug)</summary>";
                echo "<div class='debug'>";
                echo "<strong>Cursos brutos:</strong><br>";
                echo htmlspecialchars(print_r(array_slice($cursosTracionais, 0, 3), true));
                echo "<br><strong>Categorias brutas:</strong><br>";
                echo htmlspecialchars(print_r(array_slice($categoriasCursos, 0, 3), true));
                echo "</div>";
                echo "</details>";
                
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>";
                echo "<strong>❌ Erro geral:</strong><br>";
                echo htmlspecialchars($e->getMessage());
                echo "</div>";
                $resumoGeral['polos_com_erro']++;
            }
            
            echo "</div>";
        }
        
        // Função auxiliar (inline para simplificar)
        function nomeCategoriaSugereCurso($nome) {
            $nome = strtolower($nome);
            $indicadores = ['técnico', 'enfermagem', 'administração', 'informática', 'contabilidade'];
            foreach ($indicadores as $indicador) {
                if (strpos($nome, $indicador) !== false) return true;
            }
            return false;
        }
        ?>

        <!-- Resumo Geral -->
        <div class="polo" style="background: linear-gradient(135deg, #007bff, #0056b3); color: white;">
            <h2>📊 Resumo Geral</h2>
            <div class="estatisticas">
                <div class="stat-card" style="background: rgba(255,255,255,0.2);">
                    <strong><?= $resumoGeral['total_polos'] ?></strong><br>Total de Polos
                </div>
                <div class="stat-card green" style="background: rgba(40,167,69,0.8);">
                    <strong><?= $resumoGeral['polos_com_hierarquia'] ?></strong><br>Hierárquicos
                </div>
                <div class="stat-card orange" style="background: rgba(253,126,20,0.8);">
                    <strong><?= $resumoGeral['polos_tradicionais'] ?></strong><br>Tradicionais
                </div>
                <div class="stat-card red" style="background: rgba(220,53,69,0.8);">
                    <strong><?= $resumoGeral['polos_com_erro'] ?></strong><br>Com Erro
                </div>
            </div>
            
            <h3>🎯 Próximos Passos</h3>
            <ol style="color: rgba(255,255,255,0.9);">
                <li>Execute o SQL de atualização da tabela cursos</li>
                <li>Substitua o arquivo admin/api/buscar-cursos.php pela versão com suporte a hierarquia</li>
                <li>Adicione os métodos de hierarquia à classe MoodleAPI</li>
                <li>Teste a página /admin/upload-boletos.php em cada polo</li>
                <li>Configure tokens válidos para os polos que mostram erro</li>
            </ol>
        </div>

    </div>
    
    <script>
        // Adiciona funcionalidade de teste rápido
        document.addEventListener('DOMContentLoaded', function() {
            // Adiciona botão de teste em cada polo
            const polos = document.querySelectorAll('.polo');
            polos.forEach(polo => {
                const titulo = polo.querySelector('h2');
                if (titulo && titulo.textContent.includes('(') && titulo.textContent.includes(')')) {
                    const poloName = titulo.textContent.match(/\(([^)]+)\)/)[1];
                    
                    const testButton = document.createElement('button');
                    testButton.className = 'btn';
                    testButton.style.cssText = 'background: #28a745; margin-left: 10px; font-size: 12px;';
                    testButton.innerHTML = '🧪 Teste Rápido';
                    testButton.onclick = () => testarAPIPolo(poloName);
                    
                    titulo.appendChild(testButton);
                }
            });
        });
        
        function testarAPIPolo(polo) {
            const url = `/admin/api/buscar-cursos.php?polo=${encodeURIComponent(polo)}`;
            
            fetch(url)
                .then(response => response.text())
                .then(text => {
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        data = { raw: text };
                    }
                    
                    // Cria modal com resultado
                    const modal = document.createElement('div');
                    modal.style.cssText = `
                        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                        background: rgba(0,0,0,0.5); z-index: 1000;
                        display: flex; align-items: center; justify-content: center;
                    `;
                    
                    modal.innerHTML = `
                        <div style="background: white; padding: 20px; border-radius: 8px; max-width: 600px; max-height: 80%; overflow-y: auto;">
                            <h3>🧪 Resultado do Teste - ${polo}</h3>
                            <pre style="background: #f8f9fa; padding: 15px; border-radius: 4px; font-size: 12px;">${JSON.stringify(data, null, 2)}</pre>
                            <button onclick="this.parentElement.parentElement.remove()" 
                                    style="background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                                Fechar
                            </button>
                        </div>
                    `;
                    
                    document.body.appendChild(modal);
                })
                .catch(error => {
                    alert(`Erro no teste: ${error.message}`);
                });
        }
    </script>
</body>
</html>