<?php
/**
 * Sistema de Boletos IMED - API para buscar cursos com suporte a hierarquia
 * Arquivo: admin/api/buscar-cursos.php
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
    
    error_log("API buscar-cursos: Iniciando busca para polo: " . $polo);
    
    // Verifica configuração do polo
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception("Polo não configurado: {$polo}");
    }
    
    if (!MoodleConfig::isActiveSubdomain($polo)) {
        throw new Exception("Polo não está ativo: {$polo}");
    }
    
    $token = MoodleConfig::getToken($polo);
    if (!$token || $token === 'x') {
        throw new Exception("Token não configurado para polo {$polo}. Configure um token válido no arquivo config/moodle.php");
    }
    
    // Conecta com o Moodle
    $moodleAPI = new MoodleAPI($polo);
    
    // Testa a conexão
    $testeConexao = $moodleAPI->testarConexao();
    if (!$testeConexao['sucesso']) {
        throw new Exception("Erro ao conectar com Moodle: " . $testeConexao['erro']);
    }
    
    error_log("API buscar-cursos: Conexão OK com {$polo}");
    
    // Busca cursos com suporte a hierarquia
    $cursosMoodle = $moodleAPI->listarTodosCursos();
    
    error_log("API buscar-cursos: Encontrados " . count($cursosMoodle) . " cursos/categorias no Moodle");
    
    // Processa e salva cursos no banco local
    $db = (new Database())->getConnection();
    $cursosProcessados = [];
    $cursosNovos = 0;
    $cursosAtualizados = 0;
    
    foreach ($cursosMoodle as $curso) {
        try {
            // Determina ID do Moodle baseado no tipo
            $moodleCourseId = $curso['tipo'] === 'categoria_curso' 
                ? $curso['categoria_original_id'] 
                : $curso['id'];
            
            // Monta identificador único
            $identificador = $curso['tipo'] === 'categoria_curso' 
                ? 'cat_' . $curso['categoria_original_id']
                : 'course_' . $curso['id'];
            
            // Verifica se já existe
            $stmt = $db->prepare("
                SELECT id FROM cursos 
                WHERE (moodle_course_id = ? OR identificador_moodle = ?) 
                AND subdomain = ?
            ");
            $stmt->execute([$moodleCourseId, $identificador, $polo]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                // Atualiza curso existente
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET nome = ?, nome_curto = ?, tipo_estrutura = ?, 
                        categoria_pai = ?, ativo = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $curso['nome'],
                    $curso['nome_curto'],
                    $curso['tipo'],
                    $curso['parent_name'] ?? null,
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
                        data_inicio, data_fim, formato, summary, url,
                        ativo, valor, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0.00, NOW(), NOW())
                ");
                $stmt->execute([
                    $moodleCourseId,
                    $identificador,
                    $curso['nome'],
                    $curso['nome_curto'],
                    $polo,
                    $curso['tipo'],
                    $curso['parent_name'] ?? null,
                    $curso['categoria_id'] ?? null,
                    $curso['data_inicio'],
                    $curso['data_fim'],
                    $curso['formato'] ?? 'topics',
                    $curso['summary'] ?? '',
                    $curso['url'] ?? null
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
                'total_alunos' => $curso['total_alunos'] ?? 0,
                'visivel' => $curso['visivel'] ?? true,
                'url' => $curso['url'] ?? null
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao processar curso/categoria {$curso['nome']}: " . $e->getMessage());
            continue;
        }
    }
    
    // Ordena por nome
    usort($cursosProcessados, function($a, $b) {
        return strcmp($a['nome'], $b['nome']);
    });
    
    error_log("API buscar-cursos: Processamento concluído - {$cursosNovos} novos, {$cursosAtualizados} atualizados");
    
    // Detecta estrutura do polo para informação
    $estruturaDetectada = 'mista';
    $tiposCursos = array_column($cursosProcessados, 'tipo_estrutura');
    $contadorTipos = array_count_values($tiposCursos);
    
    if (isset($contadorTipos['categoria_curso']) && $contadorTipos['categoria_curso'] > 0) {
        if (!isset($contadorTipos['curso']) || $contadorTipos['categoria_curso'] > $contadorTipos['curso']) {
            $estruturaDetectada = 'hierarquica';
        }
    } elseif (isset($contadorTipos['curso'])) {
        $estruturaDetectada = 'tradicional';
    }
    
    echo json_encode([
        'success' => true,
        'cursos' => $cursosProcessados,
        'total' => count($cursosProcessados),
        'polo' => $polo,
        'estrutura_detectada' => $estruturaDetectada,
        'estatisticas' => [
            'novos' => $cursosNovos,
            'atualizados' => $cursosAtualizados,
            'total_processados' => count($cursosProcessados),
            'tipos' => $contadorTipos
        ],
        'moodle_info' => [
            'site' => $testeConexao['nome_site'] ?? '',
            'versao' => $testeConexao['versao'] ?? '',
            'url' => $testeConexao['url'] ?? ''
        ],
        'debug' => [
            'token_configurado' => !empty($token) && $token !== 'x',
            'polo_ativo' => true,
            'conexao_moodle' => $testeConexao['sucesso'],
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Erro na API buscar-cursos: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'polo' => $polo ?? 'não informado',
        'debug' => [
            'file' => __FILE__,
            'line' => __LINE__,
            'token_configurado' => !empty(MoodleConfig::getToken($polo ?? '')) && MoodleConfig::getToken($polo ?? '') !== 'x',
            'polo_ativo' => MoodleConfig::isActiveSubdomain($polo ?? ''),
            'erro_detalhado' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>