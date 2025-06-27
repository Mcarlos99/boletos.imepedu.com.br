<?php
/**
 * Script Simplificado de Análise de Categorias por Polo
 * Arquivo: analise_categorias_simples.php (colocar na raiz do projeto)
 * 
 * Versão que funciona apenas com métodos públicos da MoodleAPI
 */

header('Content-Type: text/html; charset=UTF-8');

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

/**
 * Faz requisição direta à API do Moodle para buscar categorias
 */
function buscarCategoriasDireto($subdomain, $token) {
    $url = "https://{$subdomain}/webservice/rest/server.php";
    $params = [
        'wstoken' => $token,
        'wsfunction' => 'core_course_get_categories',
        'moodlewsrestformat' => 'json'
    ];
    
    $queryString = http_build_query($params);
    $fullUrl = $url . '?' . $queryString;
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'IMED-Boletos-System/1.0'
        ]
    ]);
    
    $response = file_get_contents($fullUrl, false, $context);
    
    if ($response === false) {
        return [];
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['errorcode'])) {
        throw new Exception("Erro do Moodle: {$data['message']}");
    }
    
    return $data ?: [];
}

/**
 * Faz requisição direta à API do Moodle para buscar cursos
 */
function buscarCursosDireto($subdomain, $token) {
    $url = "https://{$subdomain}/webservice/rest/server.php";
    $params = [
        'wstoken' => $token,
        'wsfunction' => 'core_course_get_courses',
        'moodlewsrestformat' => 'json'
    ];
    
    $queryString = http_build_query($params);
    $fullUrl = $url . '?' . $queryString;
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'IMED-Boletos-System/1.0'
        ]
    ]);
    
    $response = file_get_contents($fullUrl, false, $context);
    
    if ($response === false) {
        return [];
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['errorcode'])) {
        throw new Exception("Erro do Moodle: {$data['message']}");
    }
    
    return $data ?: [];
}

/**
 * Exibe árvore de categorias em HTML
 */
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
        $icone = ($categoria['coursecount'] ?? 0) > 0 ? '📚' : '📁';
        $totalCursos = $categoria['coursecount'] ?? 0;
        
        $html .= "<li>";
        $html .= "<div class='categoria-item nivel-{$nivel}'>";
        $html .= "<span class='icone'>{$icone}</span>";
        $html .= "<span class='nome'>" . htmlspecialchars($categoria['name']) . "</span>";
        $html .= "<span class='info'>(ID: {$categoria['id']}, Cursos: {$totalCursos})</span>";
        
        if (!empty($categoria['description'])) {
            $descricao = strip_tags($categoria['description']);
            $descricao = mb_substr($descricao, 0, 100) . (mb_strlen($descricao) > 100 ? '...' : '');
            $html .= "<div class='descricao'>" . htmlspecialchars($descricao) . "</div>";
        }
        
        $html .= "</div>";
        
        // Recursão para subcategorias
        $html .= exibirArvoreHTML($categorias, $categoria['id'], $nivel + 1);
        $html .= "</li>";
    }
    
    $html .= "</ul>";
    return $html;
}

/**
 * Calcula nível da categoria na hierarquia
 */
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

/**
 * Analisa um polo específico
 */
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
        
        // Testa conexão básica
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
        
        // Busca categorias usando requisição direta
        echo "<h3>📂 Buscando Categorias...</h3>";
        $categories = buscarCategoriasDireto($subdomain, $token);
        
        if (empty($categories)) {
            echo "<div class='aviso'>⚠️ Nenhuma categoria encontrada</div>";
        } else {
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
                
                if (($cat['coursecount'] ?? 0) > 0) {
                    $categoriasComCursos++;
                    $totalCursos += $cat['coursecount'];
                }
            }
            
            echo "<strong>Categorias com cursos:</strong> {$categoriasComCursos}<br>";
            echo "<strong>Total de cursos nas categorias:</strong> {$totalCursos}<br>";
            
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
            
            // Lista detalhada das categorias
            echo "<h4>📋 Lista Detalhada de Categorias</h4>";
            echo "<div class='lista-categorias'>";
            echo "<table class='tabela-categorias'>";
            echo "<thead>";
            echo "<tr><th>ID</th><th>Nome</th><th>Pai</th><th>Nível</th><th>Cursos</th><th>Visível</th></tr>";
            echo "</thead>";
            echo "<tbody>";
            
            // Ordena por ID
            usort($categories, function($a, $b) {
                return $a['id'] - $b['id'];
            });
            
            foreach ($categories as $cat) {
                $nivel = calcularNivel($categories, $cat['id']);
                $parentName = '';
                if ($cat['parent'] > 0) {
                    foreach ($categories as $parentCat) {
                        if ($parentCat['id'] == $cat['parent']) {
                            $parentName = $parentCat['name'];
                            break;
                        }
                    }
                }
                
                $visivel = isset($cat['visible']) ? ($cat['visible'] ? '✅' : '❌') : '?';
                $cursos = $cat['coursecount'] ?? 0;
                $corLinha = $cursos > 0 ? 'style="background-color: #e8f5e8;"' : '';
                
                echo "<tr {$corLinha}>";
                echo "<td>{$cat['id']}</td>";
                echo "<td>" . htmlspecialchars($cat['name']) . "</td>";
                echo "<td>" . htmlspecialchars($parentName) . "</td>";
                echo "<td>{$nivel}</td>";
                echo "<td><strong>{$cursos}</strong></td>";
                echo "<td>{$visivel}</td>";
                echo "</tr>";
            }
            echo "</tbody>";
            echo "</table>";
            echo "</div>";
        }
        
        // Busca cursos tradicionais
        echo "<h3>📚 Cursos Tradicionais</h3>";
        try {
            $courses = buscarCursosDireto($subdomain, $token);
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
                echo "<table class='tabela-cursos'>";
                echo "<thead>";
                echo "<tr><th>ID</th><th>Nome Completo</th><th>Nome Curto</th><th>Categoria</th><th>Alunos</th><th>Visível</th></tr>";
                echo "</thead>";
                echo "<tbody>";
                
                foreach (array_slice($cursosVisiveis, 0, 20) as $course) {
                    $totalAlunos = $course['enrolledusercount'] ?? 0;
                    $categoryName = '';
                    if (!empty($categories)) {
                        foreach ($categories as $cat) {
                            if ($cat['id'] == ($course['categoryid'] ?? 0)) {
                                $categoryName = $cat['name'];
                                break;
                            }
                        }
                    }
                    
                    $visivel = isset($course['visible']) ? ($course['visible'] ? '✅' : '❌') : '?';
                    
                    echo "<tr>";
                    echo "<td>{$course['id']}</td>";
                    echo "<td>" . htmlspecialchars($course['fullname']) . "</td>";
                    echo "<td>" . htmlspecialchars($course['shortname'] ?? '') . "</td>";
                    echo "<td>" . htmlspecialchars($categoryName) . "</td>";
                    echo "<td><strong>{$totalAlunos}</strong></td>";
                    echo "<td>{$visivel}</td>";
                    echo "</tr>";
                }
                
                if (count($cursosVisiveis) > 20) {
                    echo "<tr><td colspan='6'><em>... e mais " . (count($cursosVisiveis) - 20) . " cursos</em></td></tr>";
                }
                echo "</tbody>";
                echo "</table>";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='erro'>Erro ao buscar cursos: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        
        // Análise e recomendações
        echo "<h3>💡 Análise e Recomendações</h3>";
        echo "<div class='recomendacoes'>";
        
        $categoriasComCursos = 0;
        $totalCursosCategorias = 0;
        if (!empty($categories)) {
            foreach ($categories as $cat) {
                if (($cat['coursecount'] ?? 0) > 0) {
                    $categoriasComCursos++;
                    $totalCursosCategorias += $cat['coursecount'];
                }
            }
        }
        
        $totalCursosTracionais = isset($cursosVisiveis) ? count($cursosVisiveis) : 0;
        
        echo "<div class='analise-item'>";
        echo "<strong>📊 Comparação de Estruturas:</strong><br>";
        echo "• Categorias com cursos: {$categoriasComCursos} (total de cursos: {$totalCursosCategorias})<br>";
        echo "• Cursos tradicionais visíveis: {$totalCursosTracionais}<br>";
        echo "</div>";
        
        if ($categoriasComCursos > $totalCursosTracionais) {
            echo "<div class='recomendacao'>🎯 <strong>Estrutura de Categorias:</strong> Este polo usa principalmente categorias como cursos. Recomenda-se usar a estratégia de 'categorias como cursos'.</div>";
        } elseif ($totalCursosTracionais > $categoriasComCursos) {
            echo "<div class='recomendacao'>📚 <strong>Estrutura Tradicional:</strong> Este polo usa principalmente cursos tradicionais. Recomenda-se usar a estratégia de 'cursos tradicionais'.</div>";
        } else {
            echo "<div class='recomendacao'>🔄 <strong>Estrutura Híbrida:</strong> Este polo usa tanto categorias quanto cursos. Recomenda-se usar a estratégia 'híbrida'.</div>";
        }
        
        // Verifica padrões específicos
        $temCursosTecnicos = false;
        if (!empty($categories)) {
            foreach ($categories as $cat) {
                if (stripos($cat['name'], 'técnico') !== false || stripos($cat['name'], 'tecnico') !== false) {
                    $temCursosTecnicos = true;
                    break;
                }
            }
        }
        
        if ($temCursosTecnicos) {
            echo "<div class='recomendacao'>🔧 <strong>Cursos Técnicos Detectados:</strong> Implementar filtros específicos para cursos técnicos.</div>";
        }
        
        // Verifica categorias suspeitas (que podem ser disciplinas)
        $categoriasSuspeitas = [];
        if (!empty($categories)) {
            foreach ($categories as $cat) {
                $nome = strtolower($cat['name']);
                $indicadoresDisciplina = ['módulo', 'modulo', 'disciplina', 'matéria', 'materia', 'estágio', 'estagio'];
                
                foreach ($indicadoresDisciplina as $indicador) {
                    if (strpos($nome, $indicador) !== false) {
                        $categoriasSuspeitas[] = $cat['name'];
                        break;
                    }
                }
            }
        }
        
        if (!empty($categoriasSuspeitas)) {
            echo "<div class='recomendacao aviso'>⚠️ <strong>Categorias Suspeitas (possíveis disciplinas):</strong><br>";
            foreach ($categoriasSuspeitas as $suspeita) {
                echo "• " . htmlspecialchars($suspeita) . "<br>";
            }
            echo "Considere filtrar essas categorias na busca de cursos.</div>";
        }
        
        echo "</div>";
        echo "</div>"; // Fecha polo-container
        
    } catch (Exception $e) {
        echo "<div class='erro'>❌ Erro geral: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "</div>";
    }
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
            max-width: 1400px;
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
        
        .tabela-categorias, .tabela-cursos {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 12px;
        }
        
        .tabela-categorias th, .tabela-categorias td,
        .tabela-cursos th, .tabela-cursos td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            text-align: left;
        }
        
        .tabela-categorias th, .tabela-cursos th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .lista-categorias, .lista-cursos {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
            max-height: 400px;
            overflow-y: auto;
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
        
        .recomendacao.aviso {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        
        .analise-item {
            margin: 10px 0;
            padding: 10px;
            background: #e8f4fd;
            border-radius: 4px;
            border-left: 4px solid #0066cc;
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
        <h1>🔍 Análise Simplificada de Categorias por Polo</h1>
        
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
            echo "<li><strong>Selecione um polo específico</strong> para análise detalhada da estrutura de categorias</li>";
            echo "<li><strong>Ou clique em 'Analisar Todos'</strong> para ver todos os polos ativos</li>";
            echo "<li><strong>O script mostrará:</strong>";
            echo "<ul>";
            echo "<li>📂 Estrutura completa de categorias e subcategorias</li>";
            echo "<li>📊 Estatísticas por nível de hierarquia</li>";
            echo "<li>📚 Lista de cursos tradicionais</li>";
            echo "<li>💡 Recomendações sobre qual estratégia usar</li>";
            echo "</ul>";
            echo "</li>";
            echo "<li><strong>Use as informações</strong> para configurar corretamente o sistema de busca de cursos</li>";
            echo "</ol>";
            
            echo "<div style='margin-top: 20px; padding: 15px; background: #e8f4fd; border-radius: 5px;'>";
            echo "<h4>🎯 Objetivo desta Análise:</h4>";
            echo "<p>Entender como cada polo organiza seus cursos para:</p>";
            echo "<ul>";
            echo "<li>Distinguir entre <strong>cursos principais</strong> e <strong>disciplinas/matérias</strong></li>";
            echo "<li>Identificar se o polo usa <strong>categorias como cursos</strong> ou <strong>cursos tradicionais</strong></li>";
            echo "<li>Configurar os <strong>filtros adequados</strong> para cada estrutura</li>";
            echo "<li>Evitar mostrar disciplinas individuais como se fossem cursos completos</li>";
            echo "</ul>";
            echo "</div>";
            echo "</div>";
        }
        ?>
        
        <div style="margin-top: 40px; padding: 20px; background: #f8f9fa; border-radius: 8px; text-align: center; color: #666;">
            <small>
                <strong>Sistema de Boletos IMED</strong> - Análise de Estrutura de Categorias<br>
                Gerado em: <?= date('d/m/Y H:i:s') ?> | 
                <a href="?" style="color: #0066cc;">🔄 Nova Análise</a> | 
                <a href="/admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br" target="_blank" style="color: #0066cc;">🧪 Testar API</a>
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
                setTimeout(() => {
                    this.form.submit();
                }, 100);
            });
        });
        
        // Adiciona funcionalidade de expandir/contrair tabelas grandes
        document.addEventListener('DOMContentLoaded', function() {
            const tabelas = document.querySelectorAll('.lista-categorias, .lista-cursos');
            tabelas.forEach(tabela => {
                if (tabela.scrollHeight > 400) {
                    const botaoExpandir = document.createElement('button');
                    botaoExpandir.innerHTML = '📋 Mostrar Todas';
                    botaoExpandir.className = 'btn-expandir';
                    botaoExpandir.style.cssText = 'margin: 10px 0; padding: 5px 10px; background: #0066cc; color: white; border: none; border-radius: 3px; cursor: pointer;';
                    
                    botaoExpandir.addEventListener('click', function() {
                        if (tabela.style.maxHeight === 'none') {
                            tabela.style.maxHeight = '400px';
                            this.innerHTML = '📋 Mostrar Todas';
                        } else {
                            tabela.style.maxHeight = 'none';
                            this.innerHTML = '📋 Mostrar Menos';
                        }
                    });
                    
                    tabela.parentNode.insertBefore(botaoExpandir, tabela.nextSibling);
                }
            });
        });
    </script>
</body>
</html>