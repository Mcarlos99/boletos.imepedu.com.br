<?php
/**
 * Debug Específico para Breu Branco
 * Arquivo: debug_breu_branco.php (colocar na raiz)
 * 
 * Investiga passo a passo o que está acontecendo
 */

// Simula sessão admin
session_start();
$_SESSION['admin_id'] = 1;

header('Content-Type: application/json; charset=UTF-8');

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

try {
    $polo = 'breubranco.imepedu.com.br';
    
    echo json_encode([
        'etapa' => 'inicio',
        'polo' => $polo,
        'timestamp' => date('Y-m-d H:i:s')
    ]) . "\n";
    
    // 1. Verifica configuração
    $token = MoodleConfig::getToken($polo);
    echo json_encode([
        'etapa' => 'configuracao',
        'token_configurado' => !empty($token) && $token !== 'x',
        'token_valor' => $token,
        'polo_ativo' => MoodleConfig::isActiveSubdomain($polo)
    ]) . "\n";
    
    if (!$token || $token === 'x') {
        throw new Exception("Token não configurado");
    }
    
    // 2. Testa conexão
    $moodleAPI = new MoodleAPI($polo);
    $testeConexao = $moodleAPI->testarConexao();
    
    echo json_encode([
        'etapa' => 'conexao',
        'sucesso' => $testeConexao['sucesso'],
        'site_info' => $testeConexao
    ]) . "\n";
    
    if (!$testeConexao['sucesso']) {
        throw new Exception("Erro de conexão: " . $testeConexao['erro']);
    }
    
    // 3. Busca categorias usando reflexão (temporária para debug)
    echo json_encode(['etapa' => 'buscando_categorias']) . "\n";
    
    $reflection = new ReflectionClass($moodleAPI);
    $method = $reflection->getMethod('callMoodleFunction');
    $method->setAccessible(true);
    
    $allCategories = $method->invoke($moodleAPI, 'core_course_get_categories');
    
    echo json_encode([
        'etapa' => 'categorias_encontradas',
        'total_categorias' => count($allCategories),
        'categorias' => array_slice($allCategories, 0, 10) // Primeiras 10 para debug
    ]) . "\n";
    
    // 4. Procura categoria "CURSOS TÉCNICOS"
    $categoriaCursosTecnicos = null;
    foreach ($allCategories as $category) {
        $nomeCategoria = strtolower(trim($category['name']));
        echo json_encode([
            'debug_categoria' => [
                'id' => $category['id'],
                'nome' => $category['name'],
                'nome_lower' => $nomeCategoria,
                'parent' => $category['parent'],
                'coursecount' => $category['coursecount'] ?? 0
            ]
        ]) . "\n";
        
        if (strpos($nomeCategoria, 'cursos técnicos') !== false || 
            strpos($nomeCategoria, 'cursos tecnicos') !== false ||
            $nomeCategoria === 'cursos técnicos' ||
            $nomeCategoria === 'cursos tecnicos') {
            $categoriaCursosTecnicos = $category;
            echo json_encode([
                'etapa' => 'categoria_pai_encontrada',
                'categoria' => $category
            ]) . "\n";
            break;
        }
    }
    
    if (!$categoriaCursosTecnicos) {
        echo json_encode([
            'etapa' => 'erro_categoria_pai',
            'erro' => 'Categoria CURSOS TÉCNICOS não encontrada',
            'todas_categorias' => $allCategories
        ]) . "\n";
        throw new Exception("Categoria pai não encontrada");
    }
    
    // 5. Busca subcategorias
    $subcategorias = [];
    foreach ($allCategories as $category) {
        if ($category['parent'] == $categoriaCursosTecnicos['id']) {
            $subcategorias[] = $category;
            echo json_encode([
                'subcategoria_encontrada' => [
                    'id' => $category['id'],
                    'nome' => $category['name'],
                    'parent' => $category['parent'],
                    'coursecount' => $category['coursecount'] ?? 0
                ]
            ]) . "\n";
        }
    }
    
    echo json_encode([
        'etapa' => 'subcategorias_totais',
        'quantidade' => count($subcategorias),
        'subcategorias' => $subcategorias
    ]) . "\n";
    
    // 6. Valida cursos técnicos
    $cursosValidos = [];
    foreach ($subcategorias as $subcategoria) {
        $nome = strtolower(trim($subcategoria['name']));
        
        // Lista específica de cursos técnicos válidos
        $cursosValidosBB = [
            'técnico em enfermagem',
            'tecnico em enfermagem',
            'técnico em eletromecânica',
            'tecnico em eletromecanica',
            'técnico em eletrotécnica',
            'tecnico em eletrotecnica',
            'técnico em segurança do trabalho',
            'tecnico em seguranca do trabalho',
            'técnico em seguranca do trabalho'
        ];
        
        $ehValido = false;
        $motivoValidacao = '';
        
        foreach ($cursosValidosBB as $cursoValido) {
            if (strpos($nome, $cursoValido) !== false || $nome === $cursoValido) {
                $ehValido = true;
                $motivoValidacao = "Encontrou: {$cursoValido}";
                break;
            }
        }
        
        echo json_encode([
            'validacao_curso' => [
                'nome' => $subcategoria['name'],
                'nome_lower' => $nome,
                'eh_valido' => $ehValido,
                'motivo' => $motivoValidacao ?: 'Não encontrou correspondência',
                'coursecount' => $subcategoria['coursecount'] ?? 0
            ]
        ]) . "\n";
        
        if ($ehValido) {
            $cursosValidos[] = [
                'id' => 'cat_' . $subcategoria['id'],
                'categoria_original_id' => $subcategoria['id'],
                'tipo' => 'categoria_curso',
                'nome' => $subcategoria['name'],
                'nome_curto' => substr(str_replace(['Técnico em ', 'técnico em '], '', $subcategoria['name']), 0, 10),
                'categoria_id' => $subcategoria['parent'],
                'total_alunos' => $subcategoria['coursecount'] ?? 0,
                'url' => "https://{$polo}/course/index.php?categoryid={$subcategoria['id']}"
            ];
        }
    }
    
    echo json_encode([
        'etapa' => 'resultado_final',
        'cursos_validos_encontrados' => count($cursosValidos),
        'cursos' => $cursosValidos
    ]) . "\n";
    
    // 7. Testa também o método público da API
    echo json_encode(['etapa' => 'testando_metodo_publico']) . "\n";
    
    $cursosMetodoPublico = $moodleAPI->listarTodosCursos();
    
    echo json_encode([
        'etapa' => 'metodo_publico_resultado',
        'cursos_encontrados' => count($cursosMetodoPublico),
        'cursos' => $cursosMetodoPublico
    ]) . "\n";
    
    // 8. Comparação final
    echo json_encode([
        'etapa' => 'comparacao_final',
        'metodo_direto' => count($cursosValidos),
        'metodo_publico' => count($cursosMetodoPublico),
        'problema_identificado' => count($cursosValidos) > 0 && count($cursosMetodoPublico) == 0
    ]) . "\n";
    
} catch (Exception $e) {
    echo json_encode([
        'etapa' => 'erro_critico',
        'erro' => $e->getMessage(),
        'linha' => $e->getLine(),
        'arquivo' => $e->getFile()
    ]) . "\n";
}

echo json_encode(['etapa' => 'fim_debug', 'timestamp' => date('Y-m-d H:i:s')]) . "\n";
?>