<?php
/**
 * Sistema de Boletos IMED - API Buscar Cursos IMPLEMENTAÇÃO CORRETA
 * Arquivo: admin/api/buscar-cursos.php (SUBSTITUIR)
 * 
 * Implementação baseada na estrutura real de cada polo
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
    
    error_log("=== API BUSCAR CURSOS CORRETA: {$polo} ===");
    
    // Verifica configuração básica
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception("Polo não configurado: {$polo}");
    }
    
    $token = MoodleConfig::getToken($polo);
    if (!$token || $token === 'x') {
        throw new Exception("Token não configurado para {$polo}. Configure um token válido.");
    }
    
    error_log("API: Token OK para {$polo}");
    
    // Conecta com o Moodle
    $moodleAPI = new MoodleAPI($polo);
    
    // Testa a conexão
    $testeConexao = $moodleAPI->testarConexao();
    if (!$testeConexao['sucesso']) {
        throw new Exception("Erro ao conectar com Moodle: " . $testeConexao['erro']);
    }
    
    error_log("API: Conexão OK com {$polo}");
    
    // 🎯 BUSCA USANDO IMPLEMENTAÇÃO CORRETA
    $cursosEncontrados = $moodleAPI->listarTodosCursos();
    
    error_log("API: Cursos encontrados: " . count($cursosEncontrados));
    
    // Processa e salva cursos no banco local
    $cursosProcessados = salvarCursosNoBanco($cursosEncontrados, $polo);
    
    // Determina o método usado baseado nos resultados
    $metodoUsado = determinarMetodoUsado($cursosEncontrados);
    
    echo json_encode([
        'success' => true,
        'cursos' => $cursosProcessados,
        'total' => count($cursosProcessados),
        'polo' => $polo,
        'estrutura_detectada' => $metodoUsado,
        'moodle_info' => [
            'site' => $testeConexao['nome_site'] ?? '',
            'versao' => $testeConexao['versao'] ?? ''
        ],
        'info_implementacao' => [
            'versao' => 'correta_v2.0',
            'metodo_usado' => $metodoUsado,
            'baseado_em' => 'analise_estrutura_real',
            'sem_cursos_emergencia' => true
        ],
        'debug' => [
            'token_configurado' => !empty($token) && $token !== 'x',
            'polo_ativo' => MoodleConfig::isActiveSubdomain($polo),
            'conexao_moodle' => $testeConexao['sucesso'],
            'timestamp' => date('Y-m-d H:i:s'),
            'cursos_brutos' => count($cursosEncontrados),
            'cursos_processados' => count($cursosProcessados)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("=== API BUSCAR CURSOS: ERRO ===");
    error_log("Erro: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'polo' => $polo ?? 'não informado',
        'debug' => [
            'erro_detalhado' => $e->getMessage(),
            'versao_api' => 'correta_v2.0',
            'sem_cursos_emergencia' => true
        ]
    ]);
}

/**
 * Salva cursos no banco de dados local
 */
function salvarCursosNoBanco($cursosEncontrados, $polo) {
    if (empty($cursosEncontrados)) {
        return [];
    }
    
    $db = (new Database())->getConnection();
    $cursosProcessados = [];
    $cursosNovos = 0;
    $cursosAtualizados = 0;
    
    error_log("API: Processando " . count($cursosEncontrados) . " cursos para salvar no banco");
    
    foreach ($cursosEncontrados as $curso) {
        try {
            // Determina ID do Moodle baseado no tipo
            if ($curso['tipo'] === 'categoria_curso') {
                $moodleCourseId = $curso['categoria_original_id'];
                $identificador = 'cat_' . $curso['categoria_original_id'];
            } else {
                $moodleCourseId = $curso['id'];
                $identificador = 'course_' . $curso['id'];
            }
            
            // Verifica se já existe no banco
            $stmt = $db->prepare("
                SELECT id FROM cursos 
                WHERE (
                    (moodle_course_id = ? AND subdomain = ?) OR
                    (nome = ? AND subdomain = ?)
                )
                LIMIT 1
            ");
            $stmt->execute([
                $moodleCourseId, $polo,
                $curso['nome'], $polo
            ]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                // Atualiza curso existente
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET nome = ?, nome_curto = ?, tipo_estrutura = ?, 
                        categoria_id = ?, ativo = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $curso['nome'],
                    $curso['nome_curto'],
                    $curso['tipo'],
                    $curso['categoria_id'] ?? null,
                    $cursoExistente['id']
                ]);
                $cursoId = $cursoExistente['id'];
                $cursosAtualizados++;
                
                error_log("API: Curso atualizado - " . $curso['nome']);
                
            } else {
                // Cria novo curso
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, nome, nome_curto, subdomain, tipo_estrutura,
                        categoria_id, ativo, valor, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, 0.00, NOW(), NOW())
                ");
                $stmt->execute([
                    $moodleCourseId,
                    $curso['nome'],
                    $curso['nome_curto'],
                    $polo,
                    $curso['tipo'],
                    $curso['categoria_id'] ?? null
                ]);
                $cursoId = $db->lastInsertId();
                $cursosNovos++;
                
                error_log("API: Novo curso criado - " . $curso['nome']);
            }
            
            // Adiciona à lista de retorno
            $cursosProcessados[] = [
                'id' => $cursoId,
                'moodle_course_id' => $moodleCourseId,
                'nome' => $curso['nome'],
                'nome_curto' => $curso['nome_curto'],
                'subdomain' => $polo,
                'tipo_estrutura' => $curso['tipo'],
                'categoria_id' => $curso['categoria_id'] ?? null,
                'total_alunos' => $curso['total_alunos'] ?? 0,
                'url' => $curso['url'] ?? null,
                'ativo' => 1
            ];
            
        } catch (Exception $e) {
            error_log("API: ERRO ao processar curso {$curso['nome']}: " . $e->getMessage());
            continue;
        }
    }
    
    error_log("API: Salvamento concluído - {$cursosNovos} novos, {$cursosAtualizados} atualizados");
    
    // Ordena por nome
    usort($cursosProcessados, function($a, $b) {
        return strcmp($a['nome'], $b['nome']);
    });
    
    return $cursosProcessados;
}

/**
 * Determina qual método foi usado baseado nos resultados
 */
function determinarMetodoUsado($cursosEncontrados) {
    if (empty($cursosEncontrados)) {
        return 'nenhum_encontrado';
    }
    
    $tiposPorContagem = [];
    foreach ($cursosEncontrados as $curso) {
        $tipo = $curso['tipo'] ?? 'desconhecido';
        if (!isset($tiposPorContagem[$tipo])) {
            $tiposPorContagem[$tipo] = 0;
        }
        $tiposPorContagem[$tipo]++;
    }
    
    // Determina método principal
    if (isset($tiposPorContagem['categoria_curso']) && $tiposPorContagem['categoria_curso'] > 0) {
        if (isset($tiposPorContagem['curso']) && $tiposPorContagem['curso'] > 0) {
            return 'hibrido_categorias_e_cursos';
        } else {
            return 'categorias_como_cursos';
        }
    } elseif (isset($tiposPorContagem['curso']) && $tiposPorContagem['curso'] > 0) {
        return 'cursos_tradicionais';
    }
    
    return 'deteccao_automatica';
}
?>