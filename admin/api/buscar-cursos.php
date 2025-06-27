<?php
/**
 * Sistema de Boletos IMED - Buscar Cursos CORRIGIDO
 * Arquivo: admin/api/buscar-cursos.php (SUBSTITUIR)
 * 
 * Versão com detecção automática e sintaxe corrigida
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
    
    error_log("=== BUSCA CURSOS INTELIGENTE: {$polo} ===");
    
    // 🧠 DETECÇÃO INTELIGENTE AUTOMÁTICA
    $resultado = buscarCursosInteligente($polo);
    
    echo json_encode($resultado);
    
} catch (Exception $e) {
    error_log("BUSCA CURSOS: Erro - " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'polo' => $polo ?? 'não informado'
    ]);
}

/**
 * 🧠 DETECÇÃO INTELIGENTE E AUTOMÁTICA
 */
function buscarCursosInteligente($polo) {
    error_log("INTELIGENTE: Iniciando detecção automática para {$polo}");
    
    // Configuração de detecção por polo
    $config = getPoloDetectionConfig($polo);
    
    // Verifica configuração básica
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception("Polo não configurado: {$polo}");
    }
    
    $token = MoodleConfig::getToken($polo);
    if (!$token || $token === 'x') {
        throw new Exception("Token não configurado para {$polo}");
    }
    
    try {
        $moodleAPI = new MoodleAPI($polo);
        
        // Testa conexão
        $testeConexao = $moodleAPI->testarConexao();
        if (!$testeConexao['sucesso']) {
            throw new Exception("Erro ao conectar: " . $testeConexao['erro']);
        }
        
        // Detecção inteligente baseada na configuração do polo
        $cursosDetectados = detectarCursosInteligente($moodleAPI, $config);
        
        if (empty($cursosDetectados['cursos'])) {
            return retornarCursosEmergenciaPolo($polo);
        }
        
        // Salva no banco
        $cursosProcessados = salvarCursosDetectados($cursosDetectados, $polo);
        
        return [
            'success' => true,
            'cursos' => $cursosProcessados,
            'total' => count($cursosProcessados),
            'polo' => $polo,
            'estrutura_detectada' => 'inteligente_automatica',
            'deteccao_info' => [
                'metodo' => $config['metodo'],
                'criterio_principal' => $config['criterio_principal'],
                'automatico' => true,
                'observacao' => 'Detecta automaticamente novos cursos'
            ],
            'moodle_info' => [
                'site' => $testeConexao['nome_site'] ?? '',
                'versao' => $testeConexao['versao'] ?? ''
            ],
            'debug' => [
                'total_analisados' => $cursosDetectados['total_analisados'] ?? 0,
                'total_aceitos' => count($cursosProcessados),
                'metodo_usado' => $config['metodo'],
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        error_log("INTELIGENTE: Erro na detecção - " . $e->getMessage());
        return retornarCursosEmergenciaPolo($polo);
    }
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
                'nivel_hierarquico' => 2,
                'minimo_alunos' => 0,
                'excluir_disciplinas' => true
            ],
            'palavras_obrigatorias' => ['técnico'],
            'palavras_proibidas' => [
                'estágio', 'estagio', 'supervisionado',
                'higiene', 'medicina', 'introdução', 'introducao', 'noções', 'nocoes',
                'psicologia', 'informática aplicada', 'informatica aplicada', 
                'desenho', 'módulo', 'modulo',
                'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x',
                'cirúrgica', 'cirurgica', 'materno', 'infantil', 'neuropsiquiátrica',
                'neuropsiquiatrica', 'saúde pública', 'saude publica'
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
                'disciplina', 'módulo', 'modulo', 'estágio', 'estagio', 'prática', 'pratica'
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
                'disciplina', 'módulo', 'modulo', 'estágio', 'estagio', 'introdução', 'introducao'
            ]
        ]
    ];
    
    return $configs[$polo] ?? $configs['default'];
}

/**
 * 🧠 DETECÇÃO INTELIGENTE PRINCIPAL
 */
function detectarCursosInteligente($moodleAPI, $config) {
    switch ($config['metodo']) {
        case 'categoria_hierarquica':
            return detectarPorCategoriaHierarquica($moodleAPI, $config);
        case 'cursos_principais':
            return detectarPorCursosPrincipais($moodleAPI, $config);
        default:
            return detectarHibrido($moodleAPI, $config);
    }
}

/**
 * 📂 MÉTODO 1: Detecção por hierarquia (BREU BRANCO)
 */
function detectarPorCategoriaHierarquica($moodleAPI, $config) {
    try {
        // Usa método público da API para buscar cursos
        $todosCursos = $moodleAPI->listarTodosCursos();
        error_log("INTELIGENTE: Total cursos/categorias encontrados: " . count($todosCursos));
        
        $cursosDetectados = [];
        $totalAnalisados = count($todosCursos);
        
        // Filtra apenas cursos que são especificamente técnicos para Breu Branco
        foreach ($todosCursos as $curso) {
            $totalAnalisados++;
            
            // Verifica se é um curso técnico válido
            if (ehCursoValidoPorAnaliseInteligente($curso['nome'], $config)) {
                $cursosDetectados[] = [
                    'id' => $curso['categoria_original_id'] ?? $curso['id'],
                    'categoria_original_id' => $curso['categoria_original_id'] ?? $curso['id'],
                    'tipo' => $curso['tipo'] ?? 'curso',
                    'nome' => $curso['nome'],
                    'nome_curto' => $curso['nome_curto'] ?? gerarNomeCurtoInteligente($curso['nome']),
                    'categoria_id' => $curso['categoria_id'] ?? null,
                    'parent_name' => $curso['parent_name'] ?? null,
                    'total_alunos' => $curso['total_alunos'] ?? 0,
                    'visivel' => $curso['visivel'] ?? true,
                    'metodo_deteccao' => 'categoria_hierarquica'
                ];
                error_log("INTELIGENTE: ✅ DETECTADO: " . $curso['nome']);
            } else {
                error_log("INTELIGENTE: ❌ Rejeitado: " . $curso['nome']);
            }
        }
        
        return [
            'cursos' => $cursosDetectados,
            'total_analisados' => $totalAnalisados,
            'metodo_usado' => 'api_publica_moodle'
        ];
        
    } catch (Exception $e) {
        error_log("INTELIGENTE: Erro hierárquico: " . $e->getMessage());
        return ['cursos' => [], 'total_analisados' => 0];
    }
}

/**
 * 📚 MÉTODO 2: Detecção por cursos principais
 */
function detectarPorCursosPrincipais($moodleAPI, $config) {
    try {
        // Usa método público da API
        $todosCursos = $moodleAPI->listarTodosCursos();
        $cursosDetectados = [];
        $totalAnalisados = count($todosCursos);
        
        foreach ($todosCursos as $curso) {
            // Análise inteligente do curso
            if (ehCursoValidoPorAnaliseInteligente($curso['nome'], $config)) {
                $totalAlunos = $curso['total_alunos'] ?? 0;
                $minimoAlunos = $config['criterios']['minimo_alunos'] ?? 0;
                
                if ($totalAlunos >= $minimoAlunos) {
                    $cursosDetectados[] = [
                        'id' => $curso['id'],
                        'tipo' => $curso['tipo'] ?? 'curso',
                        'nome' => $curso['nome'],
                        'nome_curto' => $curso['nome_curto'] ?? gerarNomeCurtoInteligente($curso['nome']),
                        'categoria_id' => $curso['categoria_id'] ?? null,
                        'total_alunos' => $totalAlunos,
                        'visivel' => $curso['visivel'] ?? true,
                        'metodo_deteccao' => 'curso_principal'
                    ];
                    error_log("INTELIGENTE: ✅ DETECTADO: " . $curso['nome']);
                }
            }
        }
        
        return [
            'cursos' => $cursosDetectados,
            'total_analisados' => $totalAnalisados
        ];
        
    } catch (Exception $e) {
        error_log("INTELIGENTE: Erro cursos principais: " . $e->getMessage());
        return ['cursos' => [], 'total_analisados' => 0];
    }
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

/**
 * 🔍 ANÁLISE INTELIGENTE: Valida se é curso
 */
function ehCursoValidoPorAnaliseInteligente($nome, $config) {
    $nome = strtolower(trim($nome));
    
    // 1. Verifica palavras obrigatórias
    if (!empty($config['palavras_obrigatorias'])) {
        $temObrigatoria = false;
        foreach ($config['palavras_obrigatorias'] as $obrigatoria) {
            if (strpos($nome, strtolower($obrigatoria)) !== false) {
                $temObrigatoria = true;
                break;
            }
        }
        if (!$temObrigatoria) {
            return false;
        }
    }
    
    // 2. Verifica palavras proibidas
    foreach ($config['palavras_proibidas'] as $proibida) {
        if (strpos($nome, strtolower($proibida)) !== false) {
            return false;
        }
    }
    
    // 3. Verifica numerações (I, II, III)
    if (preg_match('/\b(i{1,3}|iv|v|vi{1,3}|ix|x)\b/', $nome)) {
        return false;
    }
    
    // 4. Nome muito longo = disciplina
    if (count(explode(' ', $nome)) > 7) {
        return false;
    }
    
    // 5. Padrões positivos
    $padroesPositivos = [
        'técnico em', 'tecnico em', 'graduação em', 'graduacao em',
        'superior em', 'bacharelado', 'licenciatura', 'NR-33', 'NR-35', 'NR-10'
    ];
    
    foreach ($padroesPositivos as $padrao) {
        if (strpos($nome, $padrao) !== false) {
            return true;
        }
    }
    
    return true; // Se passou por todos os filtros, aceita
}

/**
 * 🔤 Gera nome curto inteligente
 */
function gerarNomeCurtoInteligente($nome) {
    // Remove prefixos comuns
    $nome = preg_replace('/^(técnico em |tecnico em |graduação em |graduacao em |superior em )/i', '', $nome);
    
    $palavras = explode(' ', strtoupper($nome));
    $nomeCurto = '';
    $ignorar = ['DE', 'DA', 'DO', 'EM', 'E', 'OU', 'NO', 'NA'];
    
    foreach ($palavras as $palavra) {
        if (strlen($palavra) > 2 && !in_array($palavra, $ignorar)) {
            $nomeCurto .= substr($palavra, 0, 3);
            if (strlen($nomeCurto) >= 9) break;
        }
    }
    
    return substr($nomeCurto, 0, 10) ?: 'CURSO';
}

/**
 * 💾 Salva cursos detectados
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
            
            // Verifica se existe
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
                'ativo' => 1
            ];
            
        } catch (Exception $e) {
            error_log("INTELIGENTE: Erro ao salvar: " . $e->getMessage());
            continue;
        }
    }
    
    return $cursosProcessados;
}

/**
 * 🚨 Cursos de emergência por polo
 */
function retornarCursosEmergenciaPolo($polo) {
    error_log("EMERGÊNCIA: Retornando cursos padrão para {$polo}");
    
    $cursosEmergencia = [];
    
    // Cursos específicos por polo
    if (strpos($polo, 'breubranco') !== false) {
        $cursosEmergencia = [
            ['nome' => 'Técnico em Enfermagem', 'nome_curto' => 'TEC_ENF', 'moodle_course_id' => 1001],
            ['nome' => 'Técnico em Eletromecânica', 'nome_curto' => 'TEC_ELE', 'moodle_course_id' => 1002],
            ['nome' => 'Técnico em Eletrotécnica', 'nome_curto' => 'TEC_ELT', 'moodle_course_id' => 1003],
            ['nome' => 'Técnico em Segurança do Trabalho', 'nome_curto' => 'TEC_SEG', 'moodle_course_id' => 1004]
        ];
    } elseif (strpos($polo, 'igarape') !== false) {
        $cursosEmergencia = [
            ['nome' => 'Enfermagem', 'nome_curto' => 'ENF', 'moodle_course_id' => 2001],
            ['nome' => 'Administração', 'nome_curto' => 'ADM', 'moodle_course_id' => 2002],
            ['nome' => 'Contabilidade', 'nome_curto' => 'CONT', 'moodle_course_id' => 2003]
        ];
    } else {
        // Padrão genérico
        $cursosEmergencia = [
            ['nome' => 'Administração', 'nome_curto' => 'ADM', 'moodle_course_id' => 3001],
            ['nome' => 'Enfermagem', 'nome_curto' => 'ENF', 'moodle_course_id' => 3002],
            ['nome' => 'Direito', 'nome_curto' => 'DIR', 'moodle_course_id' => 3003]
        ];
    }
    
    // Salva no banco
    try {
        $db = (new Database())->getConnection();
        $cursosProcessados = [];
        
        foreach ($cursosEmergencia as $curso) {
            // Verifica se já existe
            $stmt = $db->prepare("
                SELECT id FROM cursos 
                WHERE nome = ? AND subdomain = ?
                LIMIT 1
            ");
            $stmt->execute([$curso['nome'], $polo]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                $cursoId = $cursoExistente['id'];
            } else {
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, nome, nome_curto, subdomain, tipo_estrutura,
                        ativo, valor, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, 'emergencia', 1, 0.00, NOW(), NOW())
                ");
                $stmt->execute([
                    $curso['moodle_course_id'],
                    $curso['nome'],
                    $curso['nome_curto'],
                    $polo
                ]);
                $cursoId = $db->lastInsertId();
            }
            
            $cursosProcessados[] = [
                'id' => $cursoId,
                'nome' => $curso['nome'],
                'nome_curto' => $curso['nome_curto'],
                'subdomain' => $polo,
                'tipo_estrutura' => 'emergencia',
                'moodle_course_id' => $curso['moodle_course_id'],
                'ativo' => 1
            ];
        }
        
        return [
            'success' => true,
            'cursos' => $cursosProcessados,
            'total' => count($cursosProcessados),
            'polo' => $polo,
            'estrutura_detectada' => 'emergencia',
            'message' => 'Usando cursos de emergência - verifique configuração do polo',
            'debug' => [
                'metodo_usado' => 'cursos_emergencia',
                'motivo' => 'falha_na_deteccao_inteligente',
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Erro crítico: ' . $e->getMessage(),
            'polo' => $polo
        ];
    }
}
?>