<?php
/**
 * Sistema de Boletos IMED - API Debug de Matrícula
 * Arquivo: admin/api/debug-matricula.php
 * 
 * Esta API ajuda a diagnosticar problemas de matrícula
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
require_once '../../src/AlunoService.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $cpf = preg_replace('/[^0-9]/', '', $input['cpf'] ?? '');
    $polo = $input['polo'] ?? '';
    $acao = $input['acao'] ?? 'verificar';
    
    if (empty($cpf) || strlen($cpf) !== 11) {
        throw new Exception('CPF inválido');
    }
    
    if (empty($polo)) {
        throw new Exception('Polo é obrigatório');
    }
    
    $db = (new Database())->getConnection();
    $resultado = [
        'success' => true,
        'cpf' => $cpf,
        'polo' => $polo,
        'acao' => $acao,
        'timestamp' => date('Y-m-d H:i:s'),
        'debug' => []
    ];
    
    error_log("DEBUG MATRÍCULA: Iniciando para CPF {$cpf}, Polo {$polo}, Ação: {$acao}");
    
    // 1. VERIFICA ALUNO NO SISTEMA LOCAL
    $resultado['debug'][] = "=== VERIFICAÇÃO NO SISTEMA LOCAL ===";
    
    $stmt = $db->prepare("
        SELECT id, nome, cpf, email, subdomain, moodle_user_id, created_at, updated_at
        FROM alunos 
        WHERE cpf = ? AND subdomain = ?
    ");
    $stmt->execute([$cpf, $polo]);
    $alunoLocal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($alunoLocal) {
        $resultado['aluno_local'] = $alunoLocal;
        $resultado['debug'][] = "✅ Aluno encontrado no sistema local";
        $resultado['debug'][] = "   - ID: {$alunoLocal['id']}";
        $resultado['debug'][] = "   - Nome: {$alunoLocal['nome']}";
        $resultado['debug'][] = "   - Moodle User ID: {$alunoLocal['moodle_user_id']}";
        $resultado['debug'][] = "   - Última atualização: {$alunoLocal['updated_at']}";
    } else {
        $resultado['aluno_local'] = null;
        $resultado['debug'][] = "❌ Aluno NÃO encontrado no sistema local";
    }
    
    // 2. BUSCA MATRÍCULAS LOCAIS
    if ($alunoLocal) {
        $resultado['debug'][] = "\n=== MATRÍCULAS NO SISTEMA LOCAL ===";
        
        $stmt = $db->prepare("
            SELECT m.*, c.nome as curso_nome, c.moodle_course_id, c.subdomain as curso_subdomain
            FROM matriculas m
            INNER JOIN cursos c ON m.curso_id = c.id
            WHERE m.aluno_id = ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$alunoLocal['id']]);
        $matriculasLocais = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $resultado['matriculas_locais'] = $matriculasLocais;
        
        if (!empty($matriculasLocais)) {
            $resultado['debug'][] = "✅ Encontradas " . count($matriculasLocais) . " matrículas";
            
            foreach ($matriculasLocais as $matricula) {
                $resultado['debug'][] = "   📚 {$matricula['curso_nome']}";
                $resultado['debug'][] = "      - Curso ID: {$matricula['curso_id']}";
                $resultado['debug'][] = "      - Moodle Course ID: {$matricula['moodle_course_id']}";
                $resultado['debug'][] = "      - Status: {$matricula['status']}";
                $resultado['debug'][] = "      - Polo: {$matricula['curso_subdomain']}";
                $resultado['debug'][] = "      - Data matrícula: {$matricula['data_matricula']}";
            }
        } else {
            $resultado['debug'][] = "❌ Nenhuma matrícula encontrada no sistema local";
        }
    }
    
    // 3. VERIFICA NO MOODLE
    $resultado['debug'][] = "\n=== VERIFICAÇÃO NO MOODLE ===";
    
    try {
        $moodleAPI = new MoodleAPI($polo);
        
        // Testa conexão
        $testeConexao = $moodleAPI->testarConexao();
        if ($testeConexao['sucesso']) {
            $resultado['debug'][] = "✅ Conexão com Moodle OK";
            $resultado['debug'][] = "   - Site: {$testeConexao['nome_site']}";
            $resultado['debug'][] = "   - Versão: {$testeConexao['versao']}";
        } else {
            $resultado['debug'][] = "❌ Erro na conexão: {$testeConexao['erro']}";
            $resultado['moodle_conectado'] = false;
        }
        
        // Busca aluno no Moodle
        $alunoMoodle = $moodleAPI->buscarAlunoPorCPF($cpf);
        
        if ($alunoMoodle) {
            $resultado['aluno_moodle'] = $alunoMoodle;
            $resultado['debug'][] = "✅ Aluno encontrado no Moodle";
            $resultado['debug'][] = "   - Nome: {$alunoMoodle['nome']}";
            $resultado['debug'][] = "   - Email: {$alunoMoodle['email']}";
            $resultado['debug'][] = "   - Moodle User ID: {$alunoMoodle['moodle_user_id']}";
            $resultado['debug'][] = "   - Cursos encontrados: " . count($alunoMoodle['cursos']);
            
            if (!empty($alunoMoodle['cursos'])) {
                $resultado['debug'][] = "\n📚 CURSOS NO MOODLE:";
                foreach ($alunoMoodle['cursos'] as $curso) {
                    $resultado['debug'][] = "   - {$curso['nome']} (ID: {$curso['moodle_course_id']})";
                }
            }
        } else {
            $resultado['aluno_moodle'] = null;
            $resultado['debug'][] = "❌ Aluno NÃO encontrado no Moodle";
        }
        
    } catch (Exception $e) {
        $resultado['debug'][] = "❌ Erro ao conectar com Moodle: " . $e->getMessage();
        $resultado['moodle_erro'] = $e->getMessage();
    }
    
    // 4. BUSCA CURSOS DISPONÍVEIS NO POLO
    $resultado['debug'][] = "\n=== CURSOS DISPONÍVEIS NO POLO ===";
    
    $stmt = $db->prepare("
        SELECT id, nome, nome_curto, moodle_course_id, ativo
        FROM cursos 
        WHERE subdomain = ? AND ativo = 1
        ORDER BY nome
    ");
    $stmt->execute([$polo]);
    $cursosDisponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $resultado['cursos_disponiveis'] = $cursosDisponiveis;
    
    if (!empty($cursosDisponiveis)) {
        $resultado['debug'][] = "✅ Encontrados " . count($cursosDisponiveis) . " cursos disponíveis";
        foreach ($cursosDisponiveis as $curso) {
            $resultado['debug'][] = "   📖 {$curso['nome']} (ID Local: {$curso['id']}, Moodle ID: {$curso['moodle_course_id']})";
        }
    } else {
        $resultado['debug'][] = "❌ Nenhum curso disponível encontrado para este polo";
    }
    
    // 5. AÇÕES ESPECIAIS
    if ($acao === 'sincronizar' && isset($alunoMoodle)) {
        $resultado['debug'][] = "\n=== SINCRONIZAÇÃO FORÇADA ===";
        
        try {
            $alunoService = new AlunoService();
            $alunoId = $alunoService->salvarOuAtualizarAluno($alunoMoodle);
            
            $resultado['debug'][] = "✅ Sincronização realizada com sucesso";
            $resultado['debug'][] = "   - Aluno ID: {$alunoId}";
            $resultado['sincronizacao_realizada'] = true;
            
            // Busca matrículas atualizadas
            $stmt = $db->prepare("
                SELECT m.*, c.nome as curso_nome
                FROM matriculas m
                INNER JOIN cursos c ON m.curso_id = c.id
                WHERE m.aluno_id = ?
            ");
            $stmt->execute([$alunoId]);
            $matriculasAtualizadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $resultado['matriculas_apos_sincronizacao'] = $matriculasAtualizadas;
            $resultado['debug'][] = "   - Matrículas após sincronização: " . count($matriculasAtualizadas);
            
        } catch (Exception $e) {
            $resultado['debug'][] = "❌ Erro na sincronização: " . $e->getMessage();
            $resultado['sincronizacao_erro'] = $e->getMessage();
        }
    }
    
    // 6. DIAGNÓSTICO FINAL
    $resultado['debug'][] = "\n=== DIAGNÓSTICO FINAL ===";
    
    if (!$alunoLocal && !isset($alunoMoodle)) {
        $resultado['diagnostico'] = 'ALUNO_NAO_ENCONTRADO';
        $resultado['debug'][] = "🔴 PROBLEMA: Aluno não encontrado nem no sistema local nem no Moodle";
        $resultado['debug'][] = "   SOLUÇÃO: Verificar se o CPF está correto e se o aluno está realmente matriculado no Moodle";
    } elseif (!$alunoLocal && isset($alunoMoodle)) {
        $resultado['diagnostico'] = 'ALUNO_NAO_SINCRONIZADO';
        $resultado['debug'][] = "🟡 PROBLEMA: Aluno existe no Moodle mas não está sincronizado no sistema local";
        $resultado['debug'][] = "   SOLUÇÃO: Execute a sincronização forçada ou faça login no sistema";
    } elseif ($alunoLocal && empty($matriculasLocais)) {
        $resultado['diagnostico'] = 'SEM_MATRICULAS';
        $resultado['debug'][] = "🟡 PROBLEMA: Aluno existe mas não possui matrículas ativas";
        $resultado['debug'][] = "   SOLUÇÃO: Verificar se o aluno está matriculado em algum curso no Moodle";
    } elseif ($alunoLocal && !empty($matriculasLocais)) {
        $resultado['diagnostico'] = 'TUDO_OK';
        $resultado['debug'][] = "🟢 STATUS: Tudo parece estar correto";
        $resultado['debug'][] = "   - Aluno existe no sistema";
        $resultado['debug'][] = "   - Possui " . count($matriculasLocais) . " matrícula(s) ativa(s)";
    }
    
    // 7. SUGESTÕES
    $resultado['sugestoes'] = [];
    
    if (!isset($alunoMoodle)) {
        $resultado['sugestoes'][] = "Verificar se o CPF está correto no Moodle";
        $resultado['sugestoes'][] = "Verificar se o aluno está matriculado no polo correto";
    }
    
    if (empty($cursosDisponiveis)) {
        $resultado['sugestoes'][] = "Sincronizar cursos do polo usando a API de buscar cursos";
    }
    
    if ($alunoLocal && empty($matriculasLocais) && isset($alunoMoodle)) {
        $resultado['sugestoes'][] = "Executar sincronização forçada para atualizar matrículas";
    }
    
    echo json_encode($resultado);
    
} catch (Exception $e) {
    error_log("Erro na API debug matrícula: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'erro_detalhado' => $e->getMessage(),
            'linha' => $e->getLine(),
            'arquivo' => basename($e->getFile())
        ]
    ]);
}
?>