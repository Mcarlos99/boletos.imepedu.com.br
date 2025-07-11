<?php
/**
 * Sistema de Boletos IMEPEDU - API para Sincronizar Todos os Alunos
 * Arquivo: admin/api/sincronizar-todos-alunos.php
 * 
 * Sincroniza TODOS os alunos do Moodle automaticamente, mesmo sem primeiro login
 */

session_start();

// Headers de segurança
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verifica se administrador está logado
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

// Verifica método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    require_once '../../config/database.php';
    require_once '../../config/moodle.php';
    require_once '../../src/MoodleAPI.php';
    require_once '../../src/AlunoService.php';
    
    error_log("SINCRONIZAÇÃO GERAL: Iniciada pelo admin " . $_SESSION['admin_id']);
    
    $db = (new Database())->getConnection();
    $alunoService = new AlunoService();
    
    // Obtém todos os polos ativos
    $polosAtivos = MoodleConfig::getActiveSubdomains();
    
    if (empty($polosAtivos)) {
        throw new Exception('Nenhum polo ativo configurado');
    }
    
    $resultados = [
        'total_polos' => count($polosAtivos),
        'polos_processados' => 0,
        'total_alunos_encontrados' => 0,
        'alunos_novos' => 0,
        'alunos_atualizados' => 0,
        'alunos_com_erro' => 0,
        'detalhes_polos' => [],
        'erros' => [],
        'tempo_inicio' => date('Y-m-d H:i:s')
    ];
    
    // 📊 PROCESSA CADA POLO
    foreach ($polosAtivos as $polo) {
        try {
            error_log("SINCRONIZAÇÃO: Processando polo {$polo}");
            
            $resultadoPolo = sincronizarAlunos($polo, $alunoService);
            
            $resultados['polos_processados']++;
            $resultados['total_alunos_encontrados'] += $resultadoPolo['alunos_encontrados'];
            $resultados['alunos_novos'] += $resultadoPolo['alunos_novos'];
            $resultados['alunos_atualizados'] += $resultadoPolo['alunos_atualizados'];
            $resultados['alunos_com_erro'] += $resultadoPolo['alunos_com_erro'];
            
            $resultados['detalhes_polos'][] = [
                'polo' => $polo,
                'status' => 'sucesso',
                'alunos_encontrados' => $resultadoPolo['alunos_encontrados'],
                'alunos_novos' => $resultadoPolo['alunos_novos'],
                'alunos_atualizados' => $resultadoPolo['alunos_atualizados'],
                'tempo_processamento' => $resultadoPolo['tempo_processamento']
            ];
            
            error_log("SINCRONIZAÇÃO: Polo {$polo} concluído - {$resultadoPolo['alunos_encontrados']} alunos encontrados");
            
        } catch (Exception $e) {
            error_log("SINCRONIZAÇÃO: ERRO no polo {$polo} - " . $e->getMessage());
            
            $resultados['erros'][] = "Polo {$polo}: " . $e->getMessage();
            $resultados['detalhes_polos'][] = [
                'polo' => $polo,
                'status' => 'erro',
                'erro' => $e->getMessage()
            ];
        }
    }
    
    $resultados['tempo_fim'] = date('Y-m-d H:i:s');
    $resultados['duracao_total'] = time() - strtotime($resultados['tempo_inicio']);
    
    // 📝 REGISTRA LOG DA SINCRONIZAÇÃO
    registrarLogSincronizacao($resultados);
    
    // 📊 RESPOSTA DE SUCESSO
    $response = [
        'success' => true,
        'message' => "Sincronização concluída! {$resultados['alunos_novos']} novos alunos e {$resultados['alunos_atualizados']} atualizados",
        'resultados' => $resultados,
        'estatisticas' => [
            'polos_processados' => $resultados['polos_processados'],
            'total_alunos' => $resultados['total_alunos_encontrados'],
            'novos' => $resultados['alunos_novos'],
            'atualizados' => $resultados['alunos_atualizados'],
            'erros' => $resultados['alunos_com_erro'],
            'tempo_total' => $resultados['duracao_total'] . 's'
        ]
    ];
    
    error_log("SINCRONIZAÇÃO GERAL: Concluída - " . json_encode($response['estatisticas']));
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("SINCRONIZAÇÃO GERAL: ERRO CRÍTICO - " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro na sincronização: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 🔄 FUNÇÃO PRINCIPAL: Sincroniza alunos de um polo específico
 */
function sincronizarAlunos($polo, $alunoService) {
    $tempoInicio = microtime(true);
    
    try {
        $moodleAPI = new MoodleAPI($polo);
        
        // 🎯 ESTRATÉGIA 1: Buscar TODOS os alunos matriculados em cursos
        $alunosEncontrados = buscarTodosAlunosMatriculados($moodleAPI, $polo);
        
        // 🎯 ESTRATÉGIA 2: Se não encontrou muitos, usa busca por categorias
        if (count($alunosEncontrados) < 10) {
            error_log("SINCRONIZAÇÃO: Poucos alunos encontrados via cursos, tentando categorias");
            $alunosCategorias = buscarAlunosPorCategorias($moodleAPI, $polo);
            $alunosEncontrados = array_merge($alunosEncontrados, $alunosCategorias);
        }
        
        // 🎯 ESTRATÉGIA 3: Se ainda não encontrou, busca geral
        if (count($alunosEncontrados) < 5) {
            error_log("SINCRONIZAÇÃO: Poucos alunos encontrados, tentando busca geral");
            $alunosGerais = buscarAlunosGeral($moodleAPI, $polo);
            $alunosEncontrados = array_merge($alunosEncontrados, $alunosGerais);
        }
        
        // Remove duplicatas por CPF
        $alunosUnicos = removerDuplicatasPorCPF($alunosEncontrados);
        
        error_log("SINCRONIZAÇÃO: {$polo} - Encontrados " . count($alunosUnicos) . " alunos únicos");
        
        // Processa cada aluno
        $alunosNovos = 0;
        $alunosAtualizados = 0;
        $alunosComErro = 0;
        
        foreach ($alunosUnicos as $alunoMoodle) {
            try {
                // Verifica se já existe no banco
                $alunoExistente = $alunoService->buscarAlunoPorCPFESubdomain(
                    $alunoMoodle['cpf'], 
                    $polo
                );
                
                if ($alunoExistente) {
                    // Atualiza dados existentes
                    $alunoService->salvarOuAtualizarAluno($alunoMoodle);
                    $alunosAtualizados++;
                    error_log("SINCRONIZAÇÃO: Aluno atualizado - {$alunoMoodle['nome']} (CPF: {$alunoMoodle['cpf']})");
                } else {
                    // Cria novo aluno
                    $alunoService->salvarOuAtualizarAluno($alunoMoodle);
                    $alunosNovos++;
                    error_log("SINCRONIZAÇÃO: Novo aluno - {$alunoMoodle['nome']} (CPF: {$alunoMoodle['cpf']})");
                }
                
            } catch (Exception $e) {
                $alunosComErro++;
                error_log("SINCRONIZAÇÃO: ERRO aluno {$alunoMoodle['nome']} - " . $e->getMessage());
            }
        }
        
        $tempoFim = microtime(true);
        
        return [
            'alunos_encontrados' => count($alunosUnicos),
            'alunos_novos' => $alunosNovos,
            'alunos_atualizados' => $alunosAtualizados,
            'alunos_com_erro' => $alunosComErro,
            'tempo_processamento' => round($tempoFim - $tempoInicio, 2)
        ];
        
    } catch (Exception $e) {
        error_log("SINCRONIZAÇÃO: ERRO no polo {$polo} - " . $e->getMessage());
        throw $e;
    }
}

/**
 * 🎯 ESTRATÉGIA 1: Busca alunos via cursos matriculados
 */
function buscarTodosAlunosMatriculados($moodleAPI, $polo) {
    $alunosEncontrados = [];
    
    try {
        // Busca todos os cursos do polo
        $cursos = $moodleAPI->listarTodosCursos();
        error_log("SINCRONIZAÇÃO: {$polo} - Encontrados " . count($cursos) . " cursos");
        
        foreach ($cursos as $curso) {
            try {
                // Para cada curso, busca alunos matriculados
                $alunosCurso = buscarAlunosMatriculadosNoCurso($moodleAPI, $curso['id'], $polo);
                $alunosEncontrados = array_merge($alunosEncontrados, $alunosCurso);
                
                if (count($alunosCurso) > 0) {
                    error_log("SINCRONIZAÇÃO: Curso '{$curso['nome']}' - {count($alunosCurso)} alunos");
                }
                
            } catch (Exception $e) {
                error_log("SINCRONIZAÇÃO: ERRO no curso {$curso['nome']} - " . $e->getMessage());
                continue;
            }
        }
        
    } catch (Exception $e) {
        error_log("SINCRONIZAÇÃO: ERRO ao buscar cursos - " . $e->getMessage());
    }
    
    return $alunosEncontrados;
}

/**
 * 🎯 ESTRATÉGIA 2: Busca alunos por categorias
 */
function buscarAlunosPorCategorias($moodleAPI, $polo) {
    $alunosEncontrados = [];
    
    try {
        // Busca categorias disponíveis
        $categorias = $moodleAPI->callMoodleFunction('core_course_get_categories');
        
        foreach ($categorias as $categoria) {
            if (($categoria['coursecount'] ?? 0) > 0) {
                // Busca cursos da categoria
                $cursosCat = $moodleAPI->callMoodleFunction('core_course_get_courses', [
                    'options' => [
                        'ids' => [$categoria['id']]
                    ]
                ]);
                
                foreach ($cursosCat as $curso) {
                    $alunosCurso = buscarAlunosMatriculadosNoCurso($moodleAPI, $curso['id'], $polo);
                    $alunosEncontrados = array_merge($alunosEncontrados, $alunosCurso);
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("SINCRONIZAÇÃO: ERRO na busca por categorias - " . $e->getMessage());
    }
    
    return $alunosEncontrados;
}

/**
 * 🎯 ESTRATÉGIA 3: Busca geral de usuários
 */
function buscarAlunosGeral($moodleAPI, $polo) {
    $alunosEncontrados = [];
    
    try {
        // Busca usuários com critérios gerais
        $usuarios = $moodleAPI->callMoodleFunction('core_user_get_users', [
            'criteria' => [
                [
                    'key' => 'confirmed',
                    'value' => '1'
                ]
            ]
        ]);
        
        if (!empty($usuarios['users'])) {
            foreach ($usuarios['users'] as $usuario) {
                // Filtra apenas usuários válidos (com CPF)
                if (!empty($usuario['idnumber']) && is_numeric(preg_replace('/[^0-9]/', '', $usuario['idnumber']))) {
                    $cpf = preg_replace('/[^0-9]/', '', $usuario['idnumber']);
                    
                    if (strlen($cpf) === 11) {
                        // Busca cursos do usuário
                        $cursos = $moodleAPI->buscarCursosAluno($usuario['id']);
                        
                        $alunoMoodle = [
                            'nome' => $usuario['fullname'],
                            'cpf' => $cpf,
                            'email' => $usuario['email'],
                            'moodle_user_id' => $usuario['id'],
                            'subdomain' => $polo,
                            'cursos' => $cursos,
                            'city' => $usuario['city'] ?? null,
                            'country' => $usuario['country'] ?? 'BR'
                        ];
                        
                        $alunosEncontrados[] = $alunoMoodle;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("SINCRONIZAÇÃO: ERRO na busca geral - " . $e->getMessage());
    }
    
    return $alunosEncontrados;
}

/**
 * 👥 Busca alunos matriculados em um curso específico
 */
function buscarAlunosMatriculadosNoCurso($moodleAPI, $courseId, $polo) {
    $alunosEncontrados = [];
    
    try {
        // Remove prefixo "cat_" se for categoria
        $courseIdNumerico = str_replace('cat_', '', $courseId);
        
        if (!is_numeric($courseIdNumerico)) {
            return [];
        }
        
        // Busca usuários matriculados no curso
        $usuariosMatriculados = $moodleAPI->callMoodleFunction('core_enrol_get_enrolled_users', [
            'courseid' => (int)$courseIdNumerico
        ]);
        
        if (!empty($usuariosMatriculados)) {
            foreach ($usuariosMatriculados as $usuario) {
                // Verifica se tem CPF válido
                $cpf = preg_replace('/[^0-9]/', '', $usuario['idnumber'] ?? '');
                
                if (strlen($cpf) === 11) {
                    // Busca todos os cursos do aluno
                    $cursosAluno = $moodleAPI->buscarCursosAluno($usuario['id']);
                    
                    $alunoMoodle = [
                        'nome' => $usuario['fullname'],
                        'cpf' => $cpf,
                        'email' => $usuario['email'],
                        'moodle_user_id' => $usuario['id'],
                        'subdomain' => $polo,
                        'cursos' => $cursosAluno,
                        'city' => $usuario['city'] ?? null,
                        'country' => $usuario['country'] ?? 'BR'
                    ];
                    
                    $alunosEncontrados[] = $alunoMoodle;
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("SINCRONIZAÇÃO: ERRO ao buscar alunos do curso {$courseId} - " . $e->getMessage());
    }
    
    return $alunosEncontrados;
}

/**
 * 🔄 Remove duplicatas baseado no CPF
 */
function removerDuplicatasPorCPF($alunos) {
    $cpfsVistos = [];
    $alunosUnicos = [];
    
    foreach ($alunos as $aluno) {
        $cpf = $aluno['cpf'];
        
        if (!in_array($cpf, $cpfsVistos)) {
            $cpfsVistos[] = $cpf;
            $alunosUnicos[] = $aluno;
        }
    }
    
    return $alunosUnicos;
}

/**
 * 📝 Registra log da sincronização
 */
function registrarLogSincronizacao($resultados) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, detalhes, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "Sincronização geral: {$resultados['alunos_novos']} novos, {$resultados['alunos_atualizados']} atualizados";
        
        $stmt->execute([
            'sincronizacao_geral',
            $_SESSION['admin_id'],
            $descricao,
            json_encode($resultados),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (Exception $e) {
        error_log("ERRO ao registrar log de sincronização: " . $e->getMessage());
    }
}

/**
 * 🛠️ Função helper para validar CPF
 */
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }
    
    return true;
}

error_log("API sincronizar-todos-alunos.php carregada com sucesso");
?>