<?php
/**
 * Debug Específico para Breu Branco
 * Arquivo: debug_breu_branco.php
 * 
 * Mostra exatamente o que está sendo retornado pelo Moodle do Breu Branco
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
    <title>Debug Breu Branco - Estrutura do Moodle</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .section { margin: 30px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .cursos { background: #e8f4f8; }
        .categorias { background: #f0f8e8; }
        .item { margin: 10px 0; padding: 10px; background: white; border-radius: 4px; border-left: 4px solid #007bff; }
        .categoria-item { border-left-color: #28a745; }
        .hierarquia { margin-left: 20px; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .highlight { background: yellow; font-weight: bold; }
        .debug-box { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 4px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug Breu Branco - Estrutura do Moodle</h1>
        
        <?php
        try {
            $polo = 'breubranco.imepedu.com.br';
            $token = MoodleConfig::getToken($polo);
            
            if (!$token || $token === 'x') {
                echo "<div style='color: red; padding: 20px; border: 2px solid red;'>";
                echo "<h2>❌ Token não configurado!</h2>";
                echo "<p>Configure um token válido para {$polo} no arquivo config/moodle.php</p>";
                echo "</div>";
                exit;
            }
            
            echo "<div class='debug-box'>";
            echo "<strong>🔗 Polo:</strong> {$polo}<br>";
            echo "<strong>🔑 Token:</strong> " . substr($token, 0, 10) . "..." . substr($token, -5) . "<br>";
            echo "<strong>🌐 URL:</strong> " . MoodleConfig::getMoodleUrl($polo) . "<br>";
            echo "<strong>⏰ Timestamp:</strong> " . date('Y-m-d H:i:s');
            echo "</div>";
            
            // Conecta com Moodle
            $moodleAPI = new MoodleAPI($polo);
            
            // Testa conexão
            echo "<h2>🔗 Teste de Conexão</h2>";
            $testeConexao = $moodleAPI->testarConexao();
            
            if (!$testeConexao['sucesso']) {
                echo "<div style='color: red; padding: 15px; border: 2px solid red;'>";
                echo "<h3>❌ Falha na Conexão</h3>";
                echo "<p><strong>Erro:</strong> " . htmlspecialchars($testeConexao['erro']) . "</p>";
                echo "</div>";
                exit;
            }
            
            echo "<div style='color: green; padding: 15px; border: 2px solid green;'>";
            echo "<h3>✅ Conexão Bem-sucedida!</h3>";
            echo "<p><strong>Site:</strong> " . htmlspecialchars($testeConexao['nome_site']) . "</p>";
            echo "<p><strong>Versão:</strong> " . htmlspecialchars($testeConexao['versao']) . "</p>";
            echo "</div>";
            
            // 1. BUSCA CURSOS TRADICIONAIS
            echo "<div class='section cursos'>";
            echo "<h2>📚 1. CURSOS TRADICIONAIS (core_course_get_courses)</h2>";
            
            try {
                $courses = $moodleAPI->callMoodleFunction('core_course_get_courses');
                
                echo "<p><strong>Total encontrado:</strong> " . count($courses) . " cursos</p>";
                
                foreach ($courses as $course) {
                    if ($course['id'] == 1) continue; // Pula curso Site
                    
                    echo "<div class='item'>";
                    echo "<strong>ID {$course['id']}:</strong> " . htmlspecialchars($course['fullname']);
                    if (!empty($course['shortname'])) {
                        echo " <em>({$course['shortname']})</em>";
                    }
                    echo "<br>";
                    echo "<small>";
                    echo "Categoria: {$course['categoryid']} | ";
                    echo "Alunos: " . ($course['enrolledusercount'] ?? 0) . " | ";
                    echo "Visível: " . (isset($course['visible']) && $course['visible'] ? 'Sim' : 'Não') . " | ";
                    echo "Formato: " . ($course['format'] ?? 'N/A');
                    echo "</small>";
                    
                    if (!empty($course['summary'])) {
                        echo "<br><small><strong>Resumo:</strong> " . htmlspecialchars(substr(strip_tags($course['summary']), 0, 100)) . "...</small>";
                    }
                    echo "</div>";
                }
                
                if (empty($courses) || count($courses) <= 1) {
                    echo "<p style='color: orange;'><strong>⚠️ Poucos ou nenhum curso tradicional encontrado!</strong></p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'><strong>❌ Erro ao buscar cursos:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            echo "</div>";
            
            // 2. BUSCA CATEGORIAS
            echo "<div class='section categorias'>";
            echo "<h2>📁 2. CATEGORIAS (core_course_get_categories)</h2>";
            
            try {
                $categories = $moodleAPI->callMoodleFunction('core_course_get_categories');
                
                echo "<p><strong>Total encontrado:</strong> " . count($categories) . " categorias</p>";
                
                // Organiza categorias por nível hierárquico
                $categoriasPorNivel = [];
                foreach ($categories as $cat) {
                    if ($cat['id'] == 1) continue; // Pula categoria raiz
                    
                    $nivel = $cat['parent'] == 0 ? 1 : 2; // Simplificado: nível 1 ou 2
                    if (!isset($categoriasPorNivel[$nivel])) {
                        $categoriasPorNivel[$nivel] = [];
                    }
                    $categoriasPorNivel[$nivel][] = $cat;
                }
                
                // Mostra por nível
                foreach ($categoriasPorNivel as $nivel => $categorias) {
                    echo "<h3>📂 Nível {$nivel} " . ($nivel == 1 ? "(Categorias Principais)" : "(Subcategorias)") . "</h3>";
                    
                    foreach ($categorias as $category) {
                        $nomeCategoria = htmlspecialchars($category['name']);
                        $isSubcategoria = $category['parent'] != 0;
                        
                        // Verifica se seria considerado um "curso" pela nossa lógica
                        $nomeLower = strtolower($category['name']);
                        $seria_curso = (
                            strpos($nomeLower, 'técnico') !== false ||
                            strpos($nomeLower, 'enfermagem') !== false ||
                            strpos($nomeLower, 'administração') !== false ||
                            strpos($nomeLower, 'administracao') !== false ||
                            strpos($nomeLower, 'informática') !== false ||
                            strpos($nomeLower, 'informatica') !== false ||
                            strpos($nomeLower, 'contabilidade') !== false ||
                            strpos($nomeLower, 'segurança') !== false ||
                            strpos($nomeLower, 'meio ambiente') !== false
                        );
                        
                        echo "<div class='item categoria-item'>";
                        echo "<strong>ID {$category['id']}:</strong> {$nomeCategoria}";
                        
                        if ($seria_curso && $isSubcategoria) {
                            echo " <span class='highlight'>🎯 SERIA UM CURSO!</span>";
                        }
                        
                        echo "<br>";
                        echo "<small>";
                        echo "Parent: {$category['parent']} | ";
                        echo "Cursos: " . ($category['coursecount'] ?? 0) . " | ";
                        echo "Visível: " . (isset($category['visible']) && $category['visible'] ? 'Sim' : 'Não') . " | ";
                        echo "Tipo: " . ($isSubcategoria ? 'Subcategoria' : 'Categoria Principal');
                        echo "</small>";
                        
                        if (!empty($category['description'])) {
                            echo "<br><small><strong>Descrição:</strong> " . htmlspecialchars(substr(strip_tags($category['description']), 0, 150)) . "...</small>";
                        }
                        
                        // Mostra categoria pai se for subcategoria
                        if ($isSubcategoria) {
                            foreach ($categories as $possibleParent) {
                                if ($possibleParent['id'] == $category['parent']) {
                                    echo "<div class='hierarquia'>";
                                    echo "<strong>↳ Categoria Pai:</strong> " . htmlspecialchars($possibleParent['name']);
                                    echo "</div>";
                                    break;
                                }
                            }
                        }
                        
                        echo "</div>";
                    }
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'><strong>❌ Erro ao buscar categorias:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            echo "</div>";
            
            // 3. ANÁLISE E RECOMENDAÇÃO
            echo "<div class='section' style='background: #fff3cd;'>";
            echo "<h2>🧠 3. ANÁLISE E RECOMENDAÇÃO</h2>";
            
            $totalCursos = count($courses ?? []) - 1; // Remove curso Site
            $totalCategorias = count($categories ?? []) - 1; // Remove categoria raiz
            
            $subcategorias = [];
            if (!empty($categories)) {
                foreach ($categories as $cat) {
                    if ($cat['id'] != 1 && $cat['parent'] != 0) {
                        $subcategorias[] = $cat;
                    }
                }
            }
            $totalSubcategorias = count($subcategorias);
            
            echo "<div style='display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin: 20px 0;'>";
            echo "<div style='text-align: center; padding: 15px; background: #e3f2fd; border-radius: 5px;'>";
            echo "<h3 style='margin: 0; color: #1976d2;'>{$totalCursos}</h3>";
            echo "<p style='margin: 5px 0;'>Cursos Tradicionais</p>";
            echo "</div>";
            
            echo "<div style='text-align: center; padding: 15px; background: #e8f5e8; border-radius: 5px;'>";
            echo "<h3 style='margin: 0; color: #388e3c;'>{$totalCategorias}</h3>";
            echo "<p style='margin: 5px 0;'>Total Categorias</p>";
            echo "</div>";
            
            echo "<div style='text-align: center; padding: 15px; background: #fff8e1; border-radius: 5px;'>";
            echo "<h3 style='margin: 0; color: #f57c00;'>{$totalSubcategorias}</h3>";
            echo "<p style='margin: 5px 0;'>Subcategorias</p>";
            echo "</div>";
            echo "</div>";
            
            echo "<h3>💡 Recomendação para Breu Branco:</h3>";
            
            if ($totalSubcategorias > $totalCursos) {
                echo "<div style='color: green; padding: 15px; background: #e8f5e8; border-radius: 5px;'>";
                echo "<p><strong>✅ USAR SUBCATEGORIAS COMO CURSOS</strong></p>";
                echo "<p>Breu Branco tem mais subcategorias ({$totalSubcategorias}) que cursos tradicionais ({$totalCursos}).</p>";
                echo "<p>As subcategorias parecem representar os cursos reais (ex: Técnico em Enfermagem, etc.)</p>";
                echo "</div>";
                
                echo "<h4>🎯 Subcategorias que seriam consideradas CURSOS:</h4>";
                foreach ($subcategorias as $sub) {
                    $nomeLower = strtolower($sub['name']);
                    $seria_curso = (
                        strpos($nomeLower, 'técnico') !== false ||
                        strpos($nomeLower, 'enfermagem') !== false ||
                        strpos($nomeLower, 'administração') !== false ||
                        strpos($nomeLower, 'administracao') !== false ||
                        strpos($nomeLower, 'informática') !== false ||
                        strpos($nomeLower, 'informatica') !== false
                    );
                    
                    if ($seria_curso) {
                        echo "<div style='margin: 5px 0; padding: 8px; background: #c8e6c9; border-radius: 3px;'>";
                        echo "🎯 <strong>" . htmlspecialchars($sub['name']) . "</strong> (ID: {$sub['id']})";
                        echo "</div>";
                    }
                }
                
            } else {
                echo "<div style='color: orange; padding: 15px; background: #fff3cd; border-radius: 5px;'>";
                echo "<p><strong>⚠️ USAR CURSOS TRADICIONAIS</strong></p>";
                echo "<p>Breu Branco tem mais cursos tradicionais que subcategorias.</p>";
                echo "</div>";
            }
            echo "</div>";
            
            // 4. TESTE DA API ATUAL
            echo "<div class='section' style='background: #f3e5f5;'>";
            echo "<h2>🔧 4. TESTE DA API ATUAL</h2>";
            
            echo "<p><strong>URL de teste:</strong> <a href='/admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br' target='_blank'>API Breu Branco</a></p>";
            
            try {
                // Simula chamada da API
                require_once 'src/AdminService.php';
                $adminService = new AdminService();
                $cursosAPI = $adminService->buscarCursosPorPolo('breubranco.imepedu.com.br');
                
                echo "<h3>📊 Resultado da API Atual:</h3>";
                echo "<p><strong>Total retornado:</strong> " . count($cursosAPI) . " itens</p>";
                
                if (!empty($cursosAPI)) {
                    foreach ($cursosAPI as $curso) {
                        echo "<div style='margin: 5px 0; padding: 8px; background: white; border-left: 4px solid #9c27b0; border-radius: 3px;'>";
                        echo "<strong>" . htmlspecialchars($curso['nome']) . "</strong>";
                        if (!empty($curso['nome_curto'])) {
                            echo " <em>({$curso['nome_curto']})</em>";
                        }
                        echo "<br><small>ID: {$curso['id']} | Tipo: " . ($curso['tipo_estrutura'] ?? 'N/A') . "</small>";
                        echo "</div>";
                    }
                } else {
                    echo "<p style='color: red;'><strong>❌ API não retornou nenhum curso!</strong></p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'><strong>❌ Erro na API:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            echo "</div>";
            
            // 5. DADOS BRUTOS PARA DEBUG
            echo "<div class='section' style='background: #f5f5f5;'>";
            echo "<h2>🔍 5. DADOS BRUTOS (para debug técnico)</h2>";
            
            echo "<details>";
            echo "<summary><strong>📚 Cursos Brutos (JSON)</strong></summary>";
            echo "<pre>" . htmlspecialchars(json_encode($courses ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
            echo "</details>";
            
            echo "<details>";
            echo "<summary><strong>📁 Categorias Brutas (JSON)</strong></summary>";
            echo "<pre>" . htmlspecialchars(json_encode($categories ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
            echo "</details>";
            echo "</div>";
            
            // 6. PRÓXIMOS PASSOS
            echo "<div class='section' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;'>";
            echo "<h2>🚀 6. PRÓXIMOS PASSOS</h2>";
            echo "<ol>";
            echo "<li><strong>Atualizar MoodleAPI:</strong> Substitua o método <code>listarTodosCursos()</code> pela versão corrigida</li>";
            echo "<li><strong>Forçar Subcategorias:</strong> Para Breu Branco, priorizar subcategorias ao invés de cursos</li>";
            echo "<li><strong>Testar Novamente:</strong> Execute <code>/admin/upload-boletos.php</code> e veja se mostra as subcategorias</li>";
            echo "<li><strong>Verificar Outros Polos:</strong> Confirme se outros polos ainda funcionam</li>";
            echo "</ol>";
            
            echo "<h3>🔧 Comando SQL para verificar banco:</h3>";
            echo "<pre style='background: rgba(255,255,255,0.1); color: #fff;'>";
            echo "SELECT nome, tipo_estrutura, categoria_pai, subdomain \n";
            echo "FROM cursos \n";
            echo "WHERE subdomain = 'breubranco.imepedu.com.br' \n";
            echo "ORDER BY nome;";
            echo "</pre>";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<div style='color: red; padding: 20px; border: 2px solid red;'>";
            echo "<h2>❌ Erro Geral</h2>";
            echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            echo "</div>";
        }
        ?>
        
    </div>
</body>
</html>