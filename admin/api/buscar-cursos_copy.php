<?php
/**
 * Sistema de Boletos IMED - Buscar Cursos ATUALIZADO
 * Arquivo: admin/api/buscar-cursos.php (SUBSTITUIR)
 * 
 * Versão com lógica específica para cada polo
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
    
    error_log("=== BUSCA CURSOS: {$polo} ===");
    
    // 🎯 LÓGICA ESPECÍFICA POR POLO
    if (strpos($polo, 'breubranco') !== false) {
        // BREU BRANCO: Lista específica e fixa
        $resultado = buscarCursosBreuBrancoEspecifico($polo);
    } else {
        // OUTROS POLOS: Lógica padrão com API
        $resultado = buscarCursosGenerico($polo);
    }
    
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
 * 🎯 BREU BRANCO: Cursos específicos e fixos
 */
function buscarCursosBreuBrancoEspecifico($polo) {
    error_log("BREU BRANCO: Usando lista específica de cursos técnicos");
    
    // Lista FIXA dos cursos técnicos do Breu Branco
    $cursosEspecificos = [
        [
            'nome' => 'Técnico em Enfermagem',
            'nome_curto' => 'TEC_ENF',
            'moodle_course_id' => 1001
        ],
        [
            'nome' => 'Técnico em Eletromecânica', 
            'nome_curto' => 'TEC_ELE',
            'moodle_course_id' => 1002
        ],
        [
            'nome' => 'Técnico em Eletrotécnica',
            'nome_curto' => 'TEC_ELT', 
            'moodle_course_id' => 1003
        ],
        [
            'nome' => 'Técnico em Segurança do Trabalho',
            'nome_curto' => 'TEC_SEG',
            'moodle_course_id' => 1004
        ]
    ];
    
    $db = (new Database())->getConnection();
    $cursosProcessados = [];
    
    foreach ($cursosEspecificos as $curso) {
        try {
            // Verifica se curso já existe
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
                    SET nome_curto = ?, moodle_course_id = ?, ativo = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $curso['nome_curto'],
                    $curso['moodle_course_id'],
                    $cursoExistente['id']
                ]);
                $cursoId = $cursoExistente['id'];
            } else {
                // Cria
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, nome, nome_curto, subdomain, 
                        tipo_estrutura, ativo, valor, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, 'curso_principal', 1, 0.00, NOW(), NOW())
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
                'moodle_course_id' => $curso['moodle_course_id'],
                'nome' => $curso['nome'],
                'nome_curto' => $curso['nome_curto'],
                'subdomain' => $polo,
                'tipo_estrutura' => 'curso_principal',
                'ativo' => 1
            ];
            
            error_log("BREU BRANCO: Processado - " . $curso['nome']);
            
        } catch (Exception $e) {
            error_log("BREU BRANCO: Erro ao processar " . $curso['nome'] . ": " . $e->getMessage());
            continue;
        }
    }
    
    return [
        'success' => true,
        'cursos' => $cursosProcessados,
        'total' => count($cursosProcessados),
        'polo' => $polo,
        'estrutura_detectada' => 'especifica_breubranco',
        'info' => [
            'metodo' => 'lista_fixa_cursos_tecnicos',
            'obs' => 'Apenas os 4 cursos técnicos principais do Breu Branco'
        ],
        'debug' => [
            'polo_especifico' => 'Breu Branco',
            'total_processados' => count($cursosProcessados),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

/**
 * 🔄 OUTROS POLOS: Lógica genérica com API
 */
function buscarCursosGenerico($polo) {
    error_log("POLO GENÉRICO: Usando API do Moodle para {$polo}");
    
    // Verifica configuração
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
            throw new Exception("Erro ao conectar com Moodle: " . $testeConexao['erro']);
        }
        
        // Busca cursos usando a API filtrada
        $todosCursos = $moodleAPI->listarTodosCursos();
        
        if (empty($todosCursos)) {
            return retornarCursosEmergenciaPolo($polo);
        }
        
        // Processa e salva no banco
        $db = (new Database())->getConnection();
        $cursosProcessados = [];
        
        foreach ($todosCursos as $curso) {
            try {
                $moodleCourseId = $curso['categoria_original_id'] ?? $curso['id'];
                $nome = $curso['nome'];
                $nomeCurto = $curso['nome_curto'] ?? substr(strtoupper($nome), 0, 10);
                $tipo = $curso['tipo'] ?? 'curso';
                
                // Verifica se já existe
                $stmt = $db->prepare("
                    SELECT id FROM cursos 
                    WHERE nome = ? AND subdomain = ?
                    LIMIT 1
                ");
                $stmt->execute([$nome, $polo]);
                $cursoExistente = $stmt->fetch();
                
                if ($cursoExistente) {
                    // Atualiza
                    $stmt = $db->prepare("
                        UPDATE cursos 
                        SET nome_curto = ?, moodle_course_id = ?, tipo_estrutura = ?, ativo = 1, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$nomeCurto, $moodleCourseId, $tipo, $cursoExistente['id']]);
                    $cursoId = $cursoExistente['id'];
                } else {
                    // Cria
                    $stmt = $db->prepare("
                        INSERT INTO cursos (
                            moodle_course_id, nome, nome_curto, subdomain, tipo_estrutura,
                            ativo, valor, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, 1, 0.00, NOW(), NOW())
                    ");
                    $stmt->execute([$moodleCourseId, $nome, $nomeCurto, $polo, $tipo]);
                    $cursoId = $db->lastInsertId();
                }
                
                $cursosProcessados[] = [
                    'id' => $cursoId,
                    'nome' => $nome,
                    'nome_curto' => $nomeCurto,
                    'subdomain' => $polo,
                    'tipo_estrutura' => $tipo,
                    'moodle_course_id' => $moodleCourseId,
                    'ativo' => 1
                ];
                
            } catch (Exception $e) {
                error_log("GENÉRICO: Erro ao processar curso: " . $e->getMessage());
                continue;
            }
        }
        
        return [
            'success' => true,
            'cursos' => $cursosProcessados,
            'total' => count($cursosProcessados),
            'polo' => $polo,
            'estrutura_detectada' => 'api_moodle',
            'moodle_info' => [
                'site' => $testeConexao['nome_site'] ?? '',
                'versao' => $testeConexao['versao'] ?? ''
            ],
            'debug' => [
                'metodo_usado' => 'api_moodle_filtrada',
                'cursos_processados' => count($cursosProcessados),
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        error_log("GENÉRICO: Erro na API - " . $e->getMessage());
        return retornarCursosEmergenciaPolo($polo);
    }
}

/**
 * 🚨 Cursos de emergência por polo
 */
function retornarCursosEmergenciaPolo($polo) {
    error_log("EMERGÊNCIA: Retornando cursos padrão para {$polo}");
    
    $cursosEmergencia = [];
    
    // Cursos específicos por polo
    if (strpos($polo, 'igarape') !== false) {
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
            $stmt = $db->prepare("
                INSERT INTO cursos (
                    moodle_course_id, nome, nome_curto, subdomain, tipo_estrutura,
                    ativo, valor, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'emergencia', 1, 0.00, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                nome_curto = VALUES(nome_curto), ativo = 1, updated_at = NOW()
            ");
            $stmt->execute([
                $curso['moodle_course_id'],
                $curso['nome'],
                $curso['nome_curto'],
                $polo
            ]);
            
            $cursosProcessados[] = [
                'id' => $db->lastInsertId() ?: 'existing',
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
                'motivo' => 'falha_na_api_moodle',
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