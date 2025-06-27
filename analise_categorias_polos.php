<?php
/**
 * Script de Análise de Categorias e Subcategorias por Polo
 * Arquivo: analise_categorias_polos.php (colocar na raiz do projeto)
 * 
 * Este script analisa a estrutura completa de categorias de cada polo Moodle
 * Mostra hierarquia, quantidades e ajuda a entender como organizar os cursos
 */

// Headers para saída HTML limpa
header('Content-Type: text/html; charset=UTF-8');

// Inclui arquivos necessários
require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

/**
 * Função para buscar categorias usando método customizado
 */
function buscarTodasCategorias($moodleAPI) {
    try {
        // Usa reflexão para acessar o método privado temporariamente
        $reflection = new ReflectionClass($moodleAPI);
        $method = $reflection->getMethod('callMoodleFunction');
        $method->setAccessible(true);
        
        return $method->invoke($moodleAPI, 'core_course_get_categories');
    } catch (Exception $e) {
        // Fallback: busca através de URL direta se necessário
        error_log("Erro ao buscar categorias: " . $e->getMessage());
        return [];
    }
}

/**
 * Função para buscar cursos usando método customizado
 */
function buscarTodosCursos($moodleAPI) {
    try {
        // Usa reflexão para acessar o método privado temporariamente
        $reflection = new ReflectionClass($moodleAPI);
        $method = $reflection->getMethod('callMoodleFunction');
        $method->setAccessible(true);
        
        return $method->invoke($moodleAPI, 'core_course_get_courses');
    } catch (Exception $e) {
        // Fallback: usa método público se disponível
        try {
            return $moodleAPI->listarTodosCursos();
        } catch (Exception $e2) {
            error_log("Erro ao buscar cursos: " . $e2->getMessage());
            return [];
        }
    }
}

// Função para exibir a árvore de categorias
function exibirArvoreHTML($categorias, $parentId = 0, $nivel = 0) {
    $html = '';
    $categoriasFilhas = array_filter($categorias, function($cat) use ($parentId) {
        return $cat['parent'] == $parentId;
    });
    
    if (empty($categoriasFilhas)) {
        return '';
    }
    
    $html .= "<ul class='nivel-{$nivel}'>";
    
    foreach ($categoriasFilhas as $categoria) {
        $indent = str_repeat('  ', $nivel);
        $icone = $categoria['coursecount'] > 0 ? '📚' : '📁';
        $totalCursos = $categoria['coursecount'] ?? 0;
        
        $html .= "<li>";
        $html .= "<div class='categoria-item nivel-{$nivel}'>";
        $html .= "<span class='icone'>{$icone}</span>";
        $html .= "<span class='nome'>{$categoria['name']}</span>";
        $html .= "<span class='info'>(ID: {$categoria['id']}, Cursos: {$totalCursos})</span>";
        
        if (!empty($categoria['description'])) {
            $descricao = strip_tags($categoria['description']);
            $descricao = mb_substr($descricao, 0, 100) . (mb_strlen($descricao) > 100 ? '...' : '');
            $html .= "<div class='descricao'>{$descricao}</div>";
        }
        
        $html .= "</div>";
        
        // Recursão para subcategorias
        $html .= exibirArvoreHTML($categorias, $categoria['id'], $nivel + 1);
        $html .= "</li>";
    }
    
    $html .= "</ul>";
    return $html;
}

// Função para analisar um polo específico
function analisarPolo($subdomain) {
    try {
        echo "<div class='polo-container'>";
        echo "<h2>🎯 Análise do Polo: " . htmlspecialchars($subdomain) . "</h2>";
        
        // Verifica configuração
        if (!MoodleConfig::isValidSubdomain($subdomain)) {
            echo "<div class='erro'>❌ Polo não configurado</div>";
            echo "</div>";
            return;
        }
        
        $token = MoodleConfig::getToken($subdomain);
        if (!$token || $token === 'x') {
            echo "<div class='aviso'>⚠️ Token não configurado (usando '{$token}')</div>";
            echo "</div>";
            return;
        }
        
        echo "<div class='sucesso'>✅ Token configurado</div>";
        
        // Conecta com o Moodle
        $moodleAPI = new MoodleAPI($subdomain);
        $testeConexao = $moodleAPI->testarConexao();
        
        if (!$testeConexao['sucesso']) {
            echo "<div class='erro'>❌ Erro de conexão: " . htmlspecialchars($testeConexao['erro']) . "</div>";
            echo "</div>";
            return;
        }
        
        echo "<div class='sucesso'>✅ Conexão com Moodle estabelecida</div>";
        echo "<div class='info-site'>";
        echo "<strong>Site:</strong> " . htmlspecialchars($testeConexao['nome_site']) . "<br>";
        echo "<strong>Versão:</strong> " . htmlspecialchars($testeConexao['versao']) . "<br>";
        echo "</div>";
        
        // Busca categorias
        echo "<h3>📂 Buscando Categorias...</h3>";
        $categories = buscarTodasCategorias($moodleAPI);
        
        if (empty($categories)) {
            echo "<div class='aviso'>⚠️ Nenhuma categoria encontrada</div>";
            echo "</div>";
            return;
        }
        
        echo "<div class='estatisticas'>";
        echo "<strong>Total de categorias:</strong> " . count($categories) . "<br>";
        
        // Estatísticas por nível
        $categoriasPorNivel = [];
        $categoriasComCursos = 0;
        $totalCursos = 0;
        
        foreach ($categories as $cat) {
            $nivel = calcularNivel($categories, $cat['id']);
            if (!isset($categoriasPorNivel[$nivel])) {
                $categoriasPorNivel[$nivel] = 0;
            }
            $categoriasPorNivel[$nivel]++;
            
            if ($cat['coursecount'] > 0) {
                $categoriasComCursos++;
                $totalCursos += $cat['coursecount'];
            }
        }
        
        echo "<strong>Categorias com cursos:</strong> {$categoriasComCursos}<br>";
        echo "<strong>Total de cursos:</strong> {$totalCursos}<br>";
        
        echo "<strong>Distribuição por níveis:</strong><br>";
        ksort($categoriasPorNivel);
        foreach ($categoriasPorNivel as $nivel => $quantidade) {
            echo "  Nível {$nivel}: {$quantidade} categorias<br>";
        }
        echo "</div>";
        
        // Exibe árvore de categorias
        echo "<h3>🌳 Árvore de Categorias</h3>";
        echo "<div class='arvore-categorias'>";
        echo exibirArvoreHTML($categories);
        echo "</div>";
        
        // Análise de cursos tradicionais
        echo "<h3>📚 Cursos Tradicionais (core_course_get_courses)</h3>";
        try {
            $courses = buscarTodosCursos($moodleAPI);
            $cursosVisiveis = array_filter($courses, function($course) {
                return $course['id'] != 1 && ($course['visible'] ?? 1) == 1;
            });
            
            echo "<div class='estatisticas'>";
            echo "<strong>Total de cursos:</strong> " . count($courses) . "<br>";
            echo "<strong>Cursos visíveis:</strong> " . count($cursosVisiveis) . "<br>";
            echo "</div>";
            
            if (!empty($cursosVisiveis)) {
                echo "<div class='lista-cursos'>";
                echo "<h4>Lista de Cursos:</h4>";
                echo "<ul>";
                foreach (array_slice($cursosVisiveis, 0, 10) as $course) {
                    $totalAlunos = $course['enrolledusercount'] ?? 0;
                    echo "<li>";
                    echo "<strong>" . htmlspecialchars($course['fullname']) . "</strong>";
                    echo " (ID: {$course['id']}, Alunos: {$totalAlunos})";
                    if (!empty($course['shortname'])) {
                        echo "<br><small>Nome curto: " . htmlspecialchars($course['shortname']) . "</small>";
                    }
                    echo "</li>";
                }
                if (count($cursosVisiveis) > 10) {
                    echo "<li><em>... e mais " . (count($cursosVisiveis) - 10) . " cursos</em></li>";
                }
                echo "</ul>";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='erro'>Erro ao buscar cursos: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        
        // Recomendações
        echo "<h3>💡 Recomendações</h3>";
        echo "<div class='recomendacoes'>";
        
        if ($categoriasComCursos > count($cursosVisiveis)) {
            echo "<div class='recomendacao'>🎯 <strong>Estrutura de Categorias:</strong> Este polo parece usar categorias como cursos principais. Recomenda-se usar a estratégia de 'categorias como cursos'.</div>";
        } elseif (count($cursosVisiveis) > $categoriasComCursos) {
            echo "<div class='recomendacao'>📚 <strong>Estrutura Tradicional:</strong> Este polo usa principalmente cursos tradicionais. Recomenda-se usar a estratégia de 'cursos tradicionais'.</div>";
        } else {
            echo "<div class='recomendacao'>🔄 <strong>Estrutura Híbrida:</strong> Este polo usa tanto categorias quanto cursos. Recomenda-se usar a estratégia 'híbrida'.</div>";
        }
        
        // Verifica padrões específicos
        $temCursosTecnicos = false;
        foreach ($categories as $cat) {
            if (stripos($cat['name'], 'técnico') !== false || stripos($cat['name'], 'tecnico') !== false) {
                $temCursosTecnicos = true;
                break;
            }
        }
        
        if ($temCursosTecnicos) {
            echo "<div class='recomendacao'>🔧 <strong>Cursos Técnicos Detectados:</strong> Implementar filtros específicos para cursos técnicos.</div>";
        }
        
        echo "</div>";
        
        echo "</div>"; // Fecha polo-container
        
    } catch (Exception $e) {
        echo "<div class='erro'>❌ Erro geral: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "</div>";
    }
}

// Função auxiliar para calcular nível da categoria
function calcularNivel($categories, $categoryId, $nivel = 1) {
    if ($categoryId == 0) return 0;
    
    foreach ($categories as $cat) {
        if ($cat['id'] == $categoryId) {
            if ($cat['parent'] == 0) {
                return $nivel;
            } else {
                return calcularNivel($categories, $cat['parent'], $nivel + 1);
            }
        }
    }
    
    return $nivel;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise de Categorias por Polo - Sistema IMED</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #0066cc;
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #0066cc;
            padding-bottom: 10px;
        }
        
        .polo-container {
            margin-bottom: 40px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #fafafa;
        }
        
        .polo-container h2 {
            color: #004499;
            margin-top: 0;
            background: #e8f4fd;
            padding: 10px;
            border-radius: 5px;
        }
        
        .sucesso {
            background: #d4edda;
            color: #155724;
            padding: 8px 12px;
            border-radius: 4px;
            margin: 5px 0;
            border-left: 4px solid #28a745;
        }
        
        .erro {
            background: #f8d7da;
            color: #721c24;
            padding: 8px 12px;
            border-radius: 4px;
            margin: 5px 0;
            border-left: 4px solid #dc3545;
        }
        
        .aviso {
            background: #fff3cd;
            color: #856404;
            padding: 8px 12px;
            border-radius: 4px;
            margin: 5px 0;
            border-left: 4px solid #ffc107;
        }
        
        .info-site {
            background: #e2e3e5;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            border-left: 4px solid #6c757d;
        }
        
        .estatisticas {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #0066cc;
        }
        
        .arvore-categorias {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .arvore-categorias ul {
            list-style: none;
            margin: 0;
            padding-left: 20px;
        }
        
        .arvore-categorias ul.nivel-0 {
            padding-left: 0;
        }
        
        .categoria-item {
            margin: 5px 0;
            padding: 8px;
            border-radius: 4px;
            background: #f8f9fa;
            border-left: 3px solid #0066cc;
        }
        
        .categoria-item.nivel-0 {
            background: #e3f2fd;
            font-weight: bold;
        }
        
        .categoria-item.nivel-1 {
            background: #f1f8e9;
            margin-left: 10px;
        }
        
        .categoria-item.nivel-2 {
            background: #fff3e0;
            margin-left: 20px;
        }
        
        .icone {
            margin-right: 8px;
            font-size: 16px;
        }
        
        .nome {
            font-weight: 600;
            color: #333;
        }
        
        .info {
            color: #666;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .descricao {
            font-size: 11px;
            color: #777;
            margin-top: 5px;
            font-style: italic;
        }
        
        .lista-cursos {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .lista-cursos ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .lista-cursos li {
            margin: 8px 0;
            padding: 6px;
            background: #f8f9fa;
            border-radius: 3px;
        }
        
        .recomendacoes {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .recomendacao {
            margin: 10px 0;
            padding: 10px;
            background: white;
            border-radius: 4px;
            border-left: 4px solid #28a745;
        }
        
        .filtros {
            background: #e8f4fd;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .filtros select, .filtros button {
            margin: 5px;
            padding: 8px 15px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        
        .filtros button {
            background: #0066cc;
            color: white;
            cursor: pointer;
        }
        
        .filtros button:hover {
            background: #004499;
        }
        
        .resumo-geral {
            background: #e8f4fd;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .resumo-geral h3 {
            margin-top: 0;
            color: #0066cc;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Análise de Categorias e Subcategorias por Polo</h1>
        
        <div class="filtros">
            <h3>Selecione o Polo para Análise</h3>
            <form method="GET">
                <select name="polo" id="poloSelect">
                    <option value="">-- Selecione um polo --</option>
                    <?php
                    $polosDisponiveis = MoodleConfig::getAllSubdomains();
                    $poloSelecionado = $_GET['polo'] ?? '';
                    
                    foreach ($polosDisponiveis as $polo) {
                        $config = MoodleConfig::getConfig($polo);
                        $nome = $config['name'] ?? $polo;
                        $ativo = MoodleConfig::isActiveSubdomain($polo) ? ' ✅' : ' ⚠️';
                        $selected = ($poloSelecionado === $polo) ? 'selected' : '';
                        echo "<option value='{$polo}' {$selected}>{$nome}{$ativo}</option>";
                    }
                    ?>
                </select>
                <button type="submit">Analisar Polo</button>
                <button type="submit" name="todos" value="1">Analisar Todos</button>
            </form>
        </div>
        
        <?php
        // Resumo geral primeiro
        if (isset($_GET['todos']) || !empty($_GET['polo'])) {
            echo "<div class='resumo-geral'>";
            echo "<h3>📊 Resumo Geral dos Polos</h3>";
            
            $stats = MoodleConfig::getPolosStats();
            echo "<strong>Total de polos configurados:</strong> {$stats['total_polos']}<br>";
            echo "<strong>Polos ativos:</strong> {$stats['polos_ativos']}<br>";
            echo "<strong>Capacidade total:</strong> {$stats['max_students_total']} alunos<br>";
            
            echo "<h4>Polos por Estado:</h4>";
            foreach ($stats['polos_por_estado'] as $estado => $quantidade) {
                echo "<span style='margin-right: 15px;'>{$estado}: {$quantidade}</span>";
            }
            echo "</div>";
        }
        
        // Análise específica
        if (isset($_GET['todos'])) {
            // Analisa todos os polos
            $polosAtivos = MoodleConfig::getActiveSubdomains();
            foreach ($polosAtivos as $polo) {
                analisarPolo($polo);
            }
        } elseif (!empty($_GET['polo'])) {
            // Analisa polo específico
            analisarPolo($_GET['polo']);
        } else {
            echo "<div class='aviso'>";
            echo "<h3>ℹ️ Como usar este script:</h3>";
            echo "<ol>";
            echo "<li>Selecione um polo específico para análise detalhada</li>";
            echo "<li>Ou clique em 'Analisar Todos' para ver todos os polos ativos</li>";
            echo "<li>O script mostrará a estrutura completa de categorias e subcategorias</li>";
            echo "<li>Também fornecerá recomendações sobre qual estratégia usar para cada polo</li>";
            echo "</ol>";
            echo "</div>";
        }
        ?>
        
        <div style="margin-top: 40px; padding: 20px; background: #f8f9fa; border-radius: 8px; text-align: center; color: #666;">
            <small>
                <strong>Sistema de Boletos IMED</strong> - Análise de Estrutura de Categorias<br>
                Gerado em: <?= date('d/m/Y H:i:s') ?> | 
                <a href="?" style="color: #0066cc;">🔄 Nova Análise</a>
            </small>
        </div>
    </div>

    <script>
        // Auto-submit quando trocar polo
        document.getElementById('poloSelect').addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });
        
        // Adiciona loading nos botões
        document.querySelectorAll('button[type="submit"]').forEach(button => {
            button.addEventListener('click', function() {
                this.disabled = true;
                this.innerHTML = '⏳ Analisando...';
                setTimeout(() => this.form.submit(), 100);
            });
        });
    </script>
</body>
</html>