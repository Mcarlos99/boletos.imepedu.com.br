<?php
/**
 * CORREÇÃO ESPECÍFICA PARA BREU BRANCO
 * Arquivo: admin/api/buscar-cursos-breubranco-fix.php
 * 
 * Este script corrige especificamente o problema de hierarquia do Breu Branco
 */

session_start();

// Verifica se admin está logado
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../config/moodle.php';
require_once '../../src/MoodleAPI.php';

try {
    $polo = $_GET['polo'] ?? '';
    
    if (empty($polo)) {
        throw new Exception('Polo é obrigatório');
    }
    
    error_log("=== CORREÇÃO BREU BRANCO: INÍCIO PARA POLO {$polo} ===");
    
    // Verifica se é Breu Branco
    if (strpos($polo, 'breubranco') === false) {
        throw new Exception('Esta correção é específica para Breu Branco');
    }
    
    // Verifica configuração do polo
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception("Polo não configurado: {$polo}");
    }
    
    $token = MoodleConfig::getToken($polo);
    if (!$token || $token === 'x') {
        throw new Exception("Token não configurado para Breu Branco. Configure um token válido.");
    }
    
    error_log("CORREÇÃO: Token OK para {$polo}");
    
    // Conecta com o Moodle
    $moodleAPI = new MoodleAPI($polo);
    
    // Testa a conexão
    $testeConexao = $moodleAPI->testarConexao();
    if (!$testeConexao['sucesso']) {
        throw new Exception("Erro ao conectar com Moodle: " . $testeConexao['erro']);
    }
    
    error_log("CORREÇÃO: Conexão OK com {$polo}");
    
    // 🎯 BUSCA ESPECÍFICA PARA BREU BRANCO
    $cursosEncontrados = buscarCursosBreuBrancoEspecifico($moodleAPI);
    
    error_log("CORREÇÃO: Cursos/categorias encontrados: " . count($cursosEncontrados));
    
    // Processa e salva cursos no banco local
    $db = (new Database())->getConnection();
    $cursosProcessados = [];
    $cursosNovos = 0;
    $cursosAtualizados = 0;
    
    foreach ($cursosEncontrados as $curso) {
        try {
            error_log("CORREÇÃO: Processando: " . $curso['nome'] . " (Tipo: " . $curso['tipo'] . ")");
            
            // Determina ID do Moodle baseado no tipo
            if ($curso['tipo'] === 'categoria_curso') {
                $moodleCourseId = $curso['categoria_original_id'];
                $identificador = 'cat_' . $curso['categoria_original_id'];
            } else {
                $moodleCourseId = $curso['id'];
                $identificador = 'course_' . $curso['id'];
            }
            
            // Verifica se já existe
            $stmt = $db->prepare("
                SELECT id FROM cursos 
                WHERE (
                    (moodle_course_id = ? AND subdomain = ?) OR
                    (identificador_moodle = ? AND subdomain = ?)
                )
                LIMIT 1
            ");
            $stmt->execute([
                $moodleCourseId, $polo,
                $identificador, $polo
            ]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                // Atualiza curso existente
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET nome = ?, nome_curto = ?, tipo_estrutura = ?, 
                        categoria_pai = ?, ativo = 1, updated_at = NOW(),
                        identificador_moodle = ?, categoria_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $curso['nome'],
                    $curso['nome_curto'],
                    $curso['tipo'],
                    $curso['parent_name'] ?? null,
                    $identificador,
                    $curso['categoria_id'] ?? null,
                    $cursoExistente['id']
                ]);
                $cursoId = $cursoExistente['id'];
                $cursosAtualizados++;
                
            } else {
                // Cria novo curso
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, identificador_moodle, nome, nome_curto, 
                        subdomain, tipo_estrutura, categoria_pai, categoria_id,
                        ativo, valor, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0.00, NOW(), NOW())
                ");
                $stmt->execute([
                    $moodleCourseId,
                    $identificador,
                    $curso['nome'],
                    $curso['nome_curto'],
                    $polo,
                    $curso['tipo'],
                    $curso['parent_name'] ?? null,
                    $curso['categoria_id'] ?? null
                ]);
                $cursoId = $db->lastInsertId();
                $cursosNovos++;
            }
            
            // Adiciona à lista de retorno
            $cursosProcessados[] = [
                'id' => $cursoId,
                'moodle_course_id' => $moodleCourseId,
                'identificador_moodle' => $identificador,
                'nome' => $curso['nome'],
                'nome_curto' => $curso['nome_curto'],
                'subdomain' => $polo,
                'tipo_estrutura' => $curso['tipo'],
                'categoria_pai' => $curso['parent_name'] ?? null,
                'categoria_id' => $curso['categoria_id'] ?? null
            ];
            
        } catch (Exception $e) {
            error_log("CORREÇÃO: ERRO ao processar curso/categoria {$curso['nome']}: " . $e->getMessage());
            continue;
        }
    }
    
    // Ordena por nome
    usort($cursosProcessados, function($a, $b) {
        return strcmp($a['nome'], $b['nome']);
    });
    
    error_log("=== CORREÇÃO BREU BRANCO: CONCLUÍDA - {$cursosNovos} novos, {$cursosAtualizados} atualizados ===");
    
    echo json_encode([
        'success' => true,
        'cursos' => $cursosProcessados,
        'total' => count($cursosProcessados),
        'polo' => $polo,
        'estrutura_detectada' => 'hierarquica_corrigida',
        'estatisticas' => [
            'novos' => $cursosNovos,
            'atualizados' => $cursosAtualizados,
            'total_processados' => count($cursosProcessados)
        ],
        'info_especifica' => [
            'polo_especial' => 'Breu Branco - Hierarquia Corrigida',
            'metodo_usado' => 'busca_especifica_subcategorias',
            'categorias_pai_detectadas' => array_unique(array_column($cursosProcessados, 'categoria_pai'))
        ],
        'debug' => [
            'token_configurado' => !empty($token) && $token !== 'x',
            'polo_ativo' => true,
            'conexao_moodle' => $testeConexao['sucesso'],
            'timestamp' => date('Y-m-d H:i:s'),
            'metodo_corrigido' => 'breubranco_subcategorias_especifico'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("=== CORREÇÃO BREU BRANCO: ERRO CRÍTICO ===");
    error_log("Erro: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'polo' => $polo ?? 'não informado',
        'debug' => [
            'erro_detalhado' => $e->getMessage(),
            'versao_api' => 'correcao_breubranco_v1'
        ]
    ]);
}

/**
 * FUNÇÃO ESPECÍFICA PARA BREU BRANCO
 * Busca subcategorias que representam os cursos técnicos
 */
function buscarCursosBreuBrancoEspecifico($moodleAPI) {
    error_log("CORREÇÃO: 🎯 INICIANDO BUSCA ESPECÍFICA BREU BRANCO");
    
    try {
        // Busca TODAS as categorias
        $allCategories = $moodleAPI->callMoodleFunction('core_course_get_categories');
        error_log("CORREÇÃO: Total de categorias encontradas: " . count($allCategories));
        
        $cursosEncontrados = [];
        
        // Organiza categorias por ID para facilitar busca
        $categoriasById = [];
        foreach ($allCategories as $cat) {
            $categoriasById[$cat['id']] = $cat;
        }
        
        // Procura especificamente pela categoria "CURSOS TÉCNICOS"
        $categoriaCursosTecnicos = null;
        foreach ($allCategories as $category) {
            $nomeCategoria = strtolower(trim($category['name']));
            
            if (strpos($nomeCategoria, 'cursos técnicos') !== false || 
                strpos($nomeCategoria, 'cursos tecnicos') !== false ||
                strpos($nomeCategoria, 'técnicos') !== false) {
                $categoriaCursosTecnicos = $category;
                break;
            }
        }
        
        if ($categoriaCursosTecnicos) {
            error_log("CORREÇÃO: ✅ Categoria pai encontrada: " . $categoriaCursosTecnicos['name'] . " (ID: " . $categoriaCursosTecnicos['id'] . ")");
            
            // Busca SUBCATEGORIAS da categoria "CURSOS TÉCNICOS"
            foreach ($allCategories as $category) {
                // Verifica se é subcategoria da categoria "CURSOS TÉCNICOS"
                if ($category['parent'] == $categoriaCursosTecnicos['id']) {
                    
                    $nomeSubcategoria = trim($category['name']);
                    
                    // Verifica se é um curso técnico
                    $ehCursoTecnico = (
                        strpos(strtolower($nomeSubcategoria), 'técnico') !== false ||
                        strpos(strtolower($nomeSubcategoria), 'tecnico') !== false
                    );
                    
                    if ($ehCursoTecnico) {
                        $cursoData = [
                            'id' => 'cat_' . $category['id'],
                            'categoria_original_id' => $category['id'],
                            'tipo' => 'categoria_curso',
                            'nome' => $nomeSubcategoria,
                            'nome_curto' => gerarNomeCurtoCategoria($nomeSubcategoria),
                            'categoria_id' => $category['parent'],
                            'parent_name' => $categoriaCursosTecnicos['name'],
                            'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                            'data_inicio' => null,
                            'data_fim' => null,
                            'total_alunos' => $category['coursecount'] ?? 0,
                            'formato' => 'category',
                            'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                            'url' => "https://breubranco.imepedu.com.br/course/index.php?categoryid={$category['id']}"
                        ];
                        
                        $cursosEncontrados[] = $cursoData;
                        
                        error_log("CORREÇÃO: ✅ SUBCATEGORIA TÉCNICA ENCONTRADA: '{$nomeSubcategoria}' (ID: {$category['id']})");
                    }
                }
            }
        }
        
        // Se não encontrou subcategorias, busca qualquer categoria com "técnico"
        if (empty($cursosEncontrados)) {
            error_log("CORREÇÃO: ⚠️ Nenhuma subcategoria encontrada. Buscando categorias com 'técnico'...");
            
            foreach ($allCategories as $category) {
                if ($category['id'] == 1) continue; // Pula categoria raiz
                
                $nomeCategoria = strtolower($category['name']);
                
                $ehCursoTecnico = (
                    strpos($nomeCategoria, 'técnico') !== false ||
                    strpos($nomeCategoria, 'tecnico') !== false ||
                    strpos($nomeCategoria, 'enfermagem') !== false ||
                    strpos($nomeCategoria, 'eletromecânica') !== false ||
                    strpos($nomeCategoria, 'eletromecanica') !== false ||
                    strpos($nomeCategoria, 'eletrotécnica') !== false ||
                    strpos($nomeCategoria, 'eletrotecnica') !== false ||
                    strpos($nomeCategoria, 'segurança') !== false ||
                    strpos($nomeCategoria, 'seguranca') !== false
                );
                
                if ($ehCursoTecnico) {
                    $nomeCategoriaPai = isset($categoriasById[$category['parent']]) 
                        ? $categoriasById[$category['parent']]['name'] 
                        : null;
                    
                    $cursosEncontrados[] = [
                        'id' => 'cat_' . $category['id'],
                        'categoria_original_id' => $category['id'],
                        'tipo' => 'categoria_curso',
                        'nome' => $category['name'],
                        'nome_curto' => gerarNomeCurtoCategoria($category['name']),
                        'categoria_id' => $category['parent'],
                        'parent_name' => $nomeCategoriaPai,
                        'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                        'data_inicio' => null,
                        'data_fim' => null,
                        'total_alunos' => $category['coursecount'] ?? 0,
                        'formato' => 'category',
                        'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                        'url' => "https://breubranco.imepedu.com.br/course/index.php?categoryid={$category['id']}"
                    ];
                    
                    error_log("CORREÇÃO: 📋 CATEGORIA TÉCNICA ENCONTRADA: '{$category['name']}' (ID: {$category['id']})");
                }
            }
        }
        
        error_log("CORREÇÃO: 🏆 BREU BRANCO - TOTAL ENCONTRADO: " . count($cursosEncontrados));
        return $cursosEncontrados;
        
    } catch (Exception $e) {
        error_log("CORREÇÃO: ❌ ERRO na busca específica Breu Branco: " . $e->getMessage());
        return [];
    }
}

/**
 * Gera nome curto para categoria
 */
function gerarNomeCurtoCategoria($nome) {
    // Remove "Técnico em" e pega palavras principais
    $nome = str_replace(['Técnico em ', 'técnico em ', 'Tecnico em ', 'tecnico em '], '', $nome);
    
    $palavras = explode(' ', strtoupper($nome));
    $nomeCurto = '';
    
    foreach ($palavras as $palavra) {
        if (strlen($palavra) > 2 && !in_array(strtolower($palavra), ['DE', 'DA', 'DO', 'EM', 'E', 'OU'])) {
            $nomeCurto .= substr($palavra, 0, 3);
        }
    }
    
    return substr($nomeCurto, 0, 10) ?: 'TEC';
}
?>