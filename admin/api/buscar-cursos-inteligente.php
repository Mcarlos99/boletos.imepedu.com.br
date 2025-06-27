<?php
/**
 * Detecção INTELIGENTE e AUTOMÁTICA de Cursos vs Disciplinas
 * Arquivo: admin/api/buscar-cursos-inteligente.php
 * 
 * Esta versão detecta automaticamente novos cursos sem precisar atualizar listas
 */

session_start();

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
    
    error_log("=== DETECÇÃO INTELIGENTE: {$polo} ===");
    
    // Configuração específica por polo
    $poloConfig = getPoloDetectionConfig($polo);
    
    // Conecta com Moodle
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception("Polo não configurado: {$polo}");
    }
    
    $token = MoodleConfig::getToken($polo);
    if (!$token || $token === 'x') {
        throw new Exception("Token não configurado para {$polo}");
    }
    
    $moodleAPI = new MoodleAPI($polo);
    
    // Testa conexão
    $testeConexao = $moodleAPI->testarConexao();
    if (!$testeConexao['sucesso']) {
        throw new Exception("Erro ao conectar: " . $testeConexao['erro']);
    }
    
    // 🧠 DETECÇÃO INTELIGENTE
    $cursosDetectados = detectarCursosInteligente($moodleAPI, $poloConfig);
    
    // Salva no banco
    $cursosProcessados = salvarCursosDetectados($cursosDetectados, $polo);
    
    echo json_encode([
        'success' => true,
        'cursos' => $cursosProcessados,
        'total' => count($cursosProcessados),
        'polo' => $polo,
        'estrutura_detectada' => 'inteligente_automatica',
        'deteccao_info' => [
            'metodo' => $poloConfig['metodo'],
            'criterios_usados' => $poloConfig['criterios'],
            'automatico' => true,
            'observacao' => 'Detecta automaticamente novos cursos'
        ],
        'debug' => [
            'total_analisados' => $cursosDetectados['total_analisados'] ?? 0,
            'total_aceitos' => count($cursosProcessados),
            'criterio_principal' => $poloConfig['criterio_principal'],
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    error_log("DETECÇÃO INTELIGENTE: Erro - " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'polo' => $polo ?? 'não informado'
    ]);
}

/**
 * 🔧 Configuração de detecção por polo
 */
function getPoloDetectionConfig($polo) {
    $configs = [
        'breubranco.imepedu.com.br' => [
            'metodo' => 'categoria_hierarquica',
            'criterio_principal' => 'subcategorias_de_cursos_tecnicos',
            'categoria_pai' => 'CURSOS TÉCNICOS',
            'criterios' => [
                'deve_ter_tecnico' => true,
                'nivel_hierarquico' => 2, // Subcategoria
                'minimo_alunos' => 0,
                'excluir_disciplinas' => true
            ],
            'palavras_obrigatorias' => ['técnico'],
            'palavras_proibidas' => [
                'estágio', 'higiene', 'medicina', 'introdução', 'noções',
                'psicologia', 'informática aplicada', 'desenho', 'módulo',
                'i', 'ii', 'iii', 'iv', 'v' // Numerações de disciplinas
            ]
        ],
        
        'igarape.imepedu.com.br' => [
            'metodo' => 'cursos_principais',
            'criterio_principal' => 'cursos_com_muitos_alunos',
            'criterios' => [
                'minimo_alunos' => 10,
                'nivel_hierarquico' => 1,
                'excluir_disciplinas' => true
            ],
            'palavras_obrigatorias' => [],
            'palavras_proibidas' => [
                'disciplina', 'módulo', 'estágio', 'prática'
            ]
        ],
        
        'default' => [
            'metodo' => 'analise_hibrida',
            'criterio_principal' => 'cursos_com_caracteristicas_principais',
            'criterios' => [
                'minimo_alunos' => 5,
                'excluir_disciplinas' => true
            ],
            'palavras_obrigatorias' => [],
            'palavras_proibidas' => [
                'disciplina', 'módulo', 'estágio', 'introdução'
            ]
        ]
    ];
    
    return $configs[$polo] ?? $configs['default'];
}

/**
 * 🧠 DETECÇÃO INTELIGENTE PRINCIPAL
 */
function detectarCursosInteligente($moodleAPI, $config) {
    $cursosDetectados = [];
    $totalAnalisados = 0;
    
    switch ($config['metodo']) {
        case 'categoria_hierarquica':
            $resultado = detectarPorCategoriaHierarquica($moodleAPI, $config);
            break;
            
        case 'cursos_principais':
            $resultado = detectarPorCursosPrincipais($moodleAPI, $config);
            break;
            
        default:
            $resultado = detectarHibrido($moodleAPI, $config);
    }
    
    return $resultado;
}

/**
 * 📂 MÉTODO 1: Detecção por hierarquia de categorias (BREU BRANCO)
 */
function detectarPorCategoriaHierarquica($moodleAPI, $config) {
    error_log("INTELIGENTE: Usando método categoria hierárquica");
    
    try {
        // Busca todas as categorias
        $allCategories = $moodleAPI->callMoodleFunction('core_course_get_categories');
        error_log("INTELIGENTE: Total categorias encontradas: " . count($allCategories));
        
        $cursosDetectados = [];
        $totalAnalisados = 0;
        
        // Primeiro, encontra a categoria pai
        $categoriaPai = null;
        foreach ($allCategories as $cat) {
            $nomeCategoria = strtolower(trim($cat['name']));
            if (strpos($nomeCategoria, strtolower($config['categoria_pai'])) !== false) {
                $categoriaPai = $cat;
                break;
            }
        }
        
        if (!$categoriaPai) {
            error_log("INTELIGENTE: ⚠️ Categoria pai '{$config['categoria_pai']}' não encontrada");
            return ['cursos' => [], 'total_analisados' => 0];
        }
        
        error_log("INTELIGENTE: ✅ Categoria pai encontrada: " . $categoriaPai['name']);
        
        // Agora busca subcategorias da categoria pai
        foreach ($allCategories as $categoria) {
            $totalAnalisados++;
            
            // Verifica se é subcategoria da categoria pai
            if ($categoria['parent'] == $categoriaPai['id']) {
                
                if (ehCursoValidoPorAnaliseInteligente($categoria['name'], $config)) {
                    $cursosDetectados[] = [
                        'id' => 'cat_' . $categoria['id'],
                        'categoria_original_id' => $categoria['id'],
                        'tipo' => 'categoria_curso',
                        'nome' => $categoria['name'],
                        'nome_curto' => gerarNomeCurtoInteligente($categoria['name']),
                        'categoria_id' => $categoria['parent'],
                        'parent_name' => $categoriaPai['name'],
                        'total_alunos' => $categoria['coursecount'] ?? 0,
                        'visivel' => isset($categoria['visible']) ? ($categoria['visible'] == 1) : true,
                        'metodo_deteccao' => 'categoria_hierarquica',
                        'confianca' => 95 // Alta confiança
                    ];
                    
                    error_log("INTELIGENTE: ✅ CURSO DETECTADO: " . $categoria['name']);
                } else {
                    error_log("INTELIGENTE: ❌ Rejeitado: " . $categoria['name']);
                }
            }
        }
        
        return [
            'cursos' => $cursosDetectados,
            'total_analisados' => $totalAnalisados,
            'categoria_pai_usada' => $categoriaPai['name']
        ];
        
    } catch (Exception $e) {
        error_log("INTELIGENTE: Erro no método hierárquico: " . $e->getMessage());
        return ['cursos' => [], 'total_analisados' => 0];
    }
}

/**
 * 📚 MÉTODO 2: Detecção por cursos principais (OUTROS POLOS)
 */
function detectarPorCursosPrincipais($moodleAPI, $config) {
    error_log("INTELIGENTE: Usando método cursos principais");
    
    try {
        $courses = $moodleAPI->callMoodleFunction('core_course_get_courses');
        $cursosDetectados = [];
        $totalAnalisados = count($courses);
        
        foreach ($courses as $course) {
            // Pula o curso "Site" (ID 1)
            if ($course['id'] == 1) continue;
            
            // Análise inteligente do curso
            if (ehCursoValidoPorAnaliseInteligente($course['fullname'], $config)) {
                
                // Verifica critérios adicionais
                $totalAlunos = $course['enrolledusercount'] ?? 0;
                $minimoAlunos = $config['criterios']['minimo_alunos'] ?? 0;
                
                if ($totalAlunos >= $minimoAlunos) {
                    $cursosDetectados[] = [
                        'id' => $course['id'],
                        'tipo' => 'curso',
                        'nome' => $course['fullname'],
                        'nome_curto' => $course['shortname'] ?? gerarNomeCurtoInteligente($course['fullname']),
                        'categoria_id' => $course['categoryid'] ?? null,
                        'total_alunos' => $totalAlunos,
                        'visivel' => isset($course['visible']) ? ($course['visible'] == 1) : true,
                        'metodo_deteccao' => 'curso_principal',
                        'confianca' => calcularConfiancaCurso($course, $config)
                    ];
                    
                    error_log("INTELIGENTE: ✅ CURSO DETECTADO: " . $course['fullname']);
                }
            }
        }
        
        return [
            'cursos' => $cursosDetectados,
            'total_analisados' => $totalAnalisados
        ];
        
    } catch (Exception $e) {
        error_log("INTELIGENTE: Erro no método cursos principais: " . $e->getMessage());
        return ['cursos' => [], 'total_analisados' => 0];
    }
}

/**
 * 🔍 ANÁLISE INTELIGENTE: Determina se é curso válido
 */
function ehCursoValidoPorAnaliseInteligente($nome, $config) {
    $nome = strtolower(trim($nome));
    
    // 1. Verifica palavras obrigatórias
    if (!empty($config['palavras_obrigatorias'])) {
        $temPalavraObrigatoria = false;
        foreach ($config['palavras_obrigatorias'] as $obrigatoria) {
            if (strpos($nome, strtolower($obrigatoria)) !== false) {
                $temPalavraObrigatoria = true;
                break;
            }
        }
        if (!$temPalavraObrigatoria) {
            return false;
        }
    }
    
    // 2. Verifica palavras proibidas
    foreach ($config['palavras_proibidas'] as $proibida) {
        if (strpos($nome, strtolower($proibida)) !== false) {
            return false;
        }
    }
    
    // 3. Verifica padrões de disciplinas (numerações)
    if (preg_match('/\b(i{1,3}|iv|v|vi{1,3}|ix|x)\b/', $nome)) {
        return false; // Disciplina numerada (I, II, III, etc.)
    }
    
    // 4. Verifica se é muito específico (provável disciplina)
    $palavras = explode(' ', $nome);
    if (count($palavras) > 7) {
        return false; // Nome muito longo = disciplina
    }
    
    // 5. Padrões positivos (indicam curso)
    $padroesPositivos = [
        'técnico em', 'tecnico em', 'graduação em', 'graduacao em',
        'superior em', 'bacharelado', 'licenciatura',
        'enfermagem', 'administração', 'administracao', 'direito',
        'contabilidade', 'pedagogia', 'psicologia'
    ];
    
    foreach ($padroesPositivos as $padrao) {
        if (strpos($nome, $padrao) !== false) {
            return true;
        }
    }
    
    // 6. Se chegou até aqui, analisa o contexto
    $indicadoresCurso = [
        'técnico', 'tecnico', 'superior', 'graduação', 'graduacao'
    ];
    
    foreach ($indicadoresCurso as $indicador) {
        if (strpos($nome, $indicador) !== false) {
            return true;
        }
    }
    
    return false; // Default: rejeita se não tem certeza
}

/**
 * 📊 Calcula confiança do curso detectado
 */
function calcularConfiancaCurso($course, $config) {
    $confianca = 50; // Base
    
    // Mais alunos = mais confiança
    $totalAlunos = $course['enrolledusercount'] ?? 0;
    if ($totalAlunos > 20) $confianca += 30;
    elseif ($totalAlunos > 10) $confianca += 20;
    elseif ($totalAlunos > 5) $confianca += 10;
    
    // Nome curto e conciso = mais confiança
    $palavras = explode(' ', $course['fullname']);
    if (count($palavras) <= 4) $confianca += 20;
    
    return min(95, $confianca);
}

/**
 * 🔤 Gera nome curto inteligente
 */
function gerarNomeCurtoInteligente($nome) {
    // Remove prefixos comuns
    $nome = preg_replace('/^(técnico em |tecnico em |graduação em |graduacao em |superior em )/i', '', $nome);
    
    $palavras = explode(' ', strtoupper($nome));
    $nomeCurto = '';
    
    $palavrasIgnorar = ['DE', 'DA', 'DO', 'EM', 'E', 'OU', 'NO', 'NA'];
    
    foreach ($palavras as $palavra) {
        if (strlen($palavra) > 2 && !in_array($palavra, $palavrasIgnorar)) {
            $nomeCurto .= substr($palavra, 0, 3);
            if (strlen($nomeCurto) >= 9) break; // Limite de tamanho
        }
    }
    
    return substr($nomeCurto, 0, 10) ?: 'CURSO';
}

/**
 * 💾 Salva cursos detectados no banco
 */
function salvarCursosDetectados($resultadoDeteccao, $polo) {
    $cursosDetectados = $resultadoDeteccao['cursos'] ?? [];
    
    if (empty($cursosDetectados)) {
        return [];
    }
    
    $db = (new Database())->getConnection();
    $cursosProcessados = [];
    
    foreach ($cursosDetectados as $curso) {
        try {
            $moodleCourseId = $curso['categoria_original_id'] ?? $curso['id'];
            
            // Verifica se já existe
            $stmt = $db->prepare("
                SELECT id FROM cursos 
                WHERE nome = ? AND subdomain = ?
                LIMIT 1
            ");
            $stmt->execute([$curso['nome'], $polo]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                // Atualiza
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET nome_curto = ?, moodle_course_id = ?, tipo_estrutura = ?, ativo = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $curso['nome_curto'],
                    $moodleCourseId,
                    $curso['metodo_deteccao'],
                    $cursoExistente['id']
                ]);
                $cursoId = $cursoExistente['id'];
            } else {
                // Cria
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, nome, nome_curto, subdomain, tipo_estrutura,
                        ativo, valor, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, 1, 0.00, NOW(), NOW())
                ");
                $stmt->execute([
                    $moodleCourseId,
                    $curso['nome'],
                    $curso['nome_curto'],
                    $polo,
                    $curso['metodo_deteccao']
                ]);
                $cursoId = $db->lastInsertId();
            }
            
            $cursosProcessados[] = [
                'id' => $cursoId,
                'nome' => $curso['nome'],
                'nome_curto' => $curso['nome_curto'],
                'subdomain' => $polo,
                'tipo_estrutura' => $curso['metodo_deteccao'],
                'moodle_course_id' => $moodleCourseId,
                'confianca' => $curso['confianca'] ?? 0,
                'ativo' => 1
            ];
            
        } catch (Exception $e) {
            error_log("INTELIGENTE: Erro ao salvar curso: " . $e->getMessage());
            continue;
        }
    }
    
    return $cursosProcessados;
}

/**
 * 🔄 Método híbrido (fallback)
 */
function detectarHibrido($moodleAPI, $config) {
    // Tenta primeiro por categorias, depois por cursos
    $resultado = detectarPorCategoriaHierarquica($moodleAPI, $config);
    
    if (empty($resultado['cursos'])) {
        $resultado = detectarPorCursosPrincipais($moodleAPI, $config);
    }
    
    return $resultado;
}
?>