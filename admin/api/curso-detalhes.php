<?php
/**
 * Sistema de Boletos IMEPEDU - API para Detalhes do Curso (ADMINISTRAÇÃO)
 * Arquivo: admin/api/curso-detalhes.php
 * 
 * API específica para administradores visualizarem detalhes completos dos cursos
 */

session_start();

// Headers de segurança e CORS
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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
    
    // Obtém ID do curso
    $cursoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$cursoId) {
        throw new Exception('ID do curso é obrigatório e deve ser um número válido');
    }
    
    error_log("API Detalhes Curso: Buscando curso ID: {$cursoId} - Admin: " . $_SESSION['admin_id']);
    
    $db = (new Database())->getConnection();
    
    // Busca dados básicos do curso
    $stmt = $db->prepare("
        SELECT c.*,
               COUNT(DISTINCT m.id) as total_matriculas_todas,
               COUNT(DISTINCT CASE WHEN m.status = 'ativa' THEN m.id END) as matriculas_ativas
        FROM cursos c
        LEFT JOIN matriculas m ON c.id = m.curso_id
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$cursoId]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$curso) {
        throw new Exception('Curso não encontrado');
    }
    
    error_log("API Detalhes Curso: Curso encontrado - {$curso['nome']} (Polo: {$curso['subdomain']})");
    
    // Busca estatísticas de boletos
    $stmtEstatisticas = $db->prepare("
        SELECT 
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
            COUNT(CASE WHEN status = 'pendente' THEN 1 END) as boletos_pendentes,
            COUNT(CASE WHEN status = 'vencido' THEN 1 END) as boletos_vencidos,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as boletos_cancelados,
            COALESCE(SUM(valor), 0) as valor_total,
            COALESCE(SUM(CASE WHEN status = 'pago' THEN COALESCE(valor_pago, valor) ELSE 0 END), 0) as valor_arrecadado,
            COALESCE(SUM(CASE WHEN status IN ('pendente', 'vencido') THEN valor ELSE 0 END), 0) as valor_pendente,
            MIN(created_at) as primeiro_boleto,
            MAX(created_at) as ultimo_boleto
        FROM boletos 
        WHERE curso_id = ?
    ");
    $stmtEstatisticas->execute([$cursoId]);
    $estatisticas = $stmtEstatisticas->fetch(PDO::FETCH_ASSOC);
    
    // Busca alunos recentes (últimos 10 matriculados)
    $stmtAlunos = $db->prepare("
        SELECT a.id, a.nome, a.cpf, a.email, a.city,
               m.status, m.data_matricula, m.created_at as matricula_criada
        FROM matriculas m
        INNER JOIN alunos a ON m.aluno_id = a.id
        WHERE m.curso_id = ?
        ORDER BY m.created_at DESC
        LIMIT 10
    ");
    $stmtAlunos->execute([$cursoId]);
    $alunosRecentes = $stmtAlunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Busca boletos recentes (últimos 10)
    $stmtBoletos = $db->prepare("
        SELECT b.id, b.numero_boleto, b.valor, b.vencimento, b.status, b.created_at,
               a.nome as aluno_nome, a.cpf as aluno_cpf
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id
        WHERE b.curso_id = ?
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    $stmtBoletos->execute([$cursoId]);
    $boletosRecentes = $stmtBoletos->fetchAll(PDO::FETCH_ASSOC);
    
    // Busca atividades recentes (logs relacionados ao curso)
    $stmtLogs = $db->prepare("
        SELECT l.tipo, l.descricao, l.created_at, l.ip_address,
               a.nome as admin_nome,
               b.numero_boleto
        FROM logs l
        LEFT JOIN administradores a ON l.usuario_id = a.id
        LEFT JOIN boletos b ON l.boleto_id = b.id
        WHERE (b.curso_id = ? OR l.descricao LIKE ?)
        ORDER BY l.created_at DESC
        LIMIT 15
    ");
    $stmtLogs->execute([$cursoId, "%curso {$cursoId}%"]);
    $atividades = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    
    // Busca informações do polo
    $poloConfig = MoodleConfig::getConfig($curso['subdomain']);
    
    // Calcula métricas adicionais
    $valorTotal = floatval($estatisticas['valor_total']);
    $valorArrecadado = floatval($estatisticas['valor_arrecadado']);
    $valorPendente = floatval($estatisticas['valor_pendente']);
    $percentualArrecadado = $valorTotal > 0 ? ($valorArrecadado / $valorTotal) * 100 : 0;
    
    // Calcula taxa de aproveitamento do curso
    $totalMatriculas = intval($curso['total_matriculas_todas']);
    $matriculasAtivas = intval($curso['matriculas_ativas']);
    $taxaRetencao = $totalMatriculas > 0 ? ($matriculasAtivas / $totalMatriculas) * 100 : 0;
    
    // Calcula score do curso (métrica customizada)
    $scoreCurso = calcularScoreCurso($curso, $estatisticas, $matriculasAtivas);
    
    // Prepara dados dos alunos formatados
    $alunosFormatados = [];
    foreach ($alunosRecentes as $aluno) {
        $alunosFormatados[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'cpf' => $aluno['cpf'],
            'email' => $aluno['email'],
            'city' => $aluno['city'],
            'status' => $aluno['status'],
            'data_matricula' => $aluno['data_matricula'],
            'data_matricula_formatada' => $aluno['data_matricula'] ? 
                date('d/m/Y', strtotime($aluno['data_matricula'])) : null,
            'created_at' => $aluno['matricula_criada'],
            'created_at_formatado' => date('d/m/Y H:i', strtotime($aluno['matricula_criada']))
        ];
    }
    
    // Prepara dados dos boletos formatados
    $boletosFormatados = [];
    foreach ($boletosRecentes as $boleto) {
        $vencimento = new DateTime($boleto['vencimento']);
        $hoje = new DateTime();
        $diasVencimento = $hoje->diff($vencimento)->format('%r%a');
        
        $boletosFormatados[] = [
            'id' => $boleto['id'],
            'numero_boleto' => $boleto['numero_boleto'],
            'valor' => floatval($boleto['valor']),
            'valor_formatado' => 'R$ ' . number_format($boleto['valor'], 2, ',', '.'),
            'vencimento' => $boleto['vencimento'],
            'vencimento_formatado' => $vencimento->format('d/m/Y'),
            'dias_vencimento' => intval($diasVencimento),
            'status' => $boleto['status'],
            'status_label' => ucfirst($boleto['status']),
            'aluno_nome' => $boleto['aluno_nome'],
            'aluno_cpf' => $boleto['aluno_cpf'],
            'created_at' => $boleto['created_at'],
            'created_at_formatado' => date('d/m/Y H:i', strtotime($boleto['created_at']))
        ];
    }
    
    // Prepara atividades formatadas
    $atividadesFormatadas = [];
    foreach ($atividades as $atividade) {
        $atividadesFormatadas[] = [
            'tipo' => $atividade['tipo'],
            'tipo_label' => getTipoAtividadeLabel($atividade['tipo']),
            'descricao' => $atividade['descricao'],
            'numero_boleto' => $atividade['numero_boleto'],
            'admin_nome' => $atividade['admin_nome'] ?: 'Sistema',
            'created_at' => $atividade['created_at'],
            'created_at_formatado' => date('d/m/Y H:i', strtotime($atividade['created_at'])),
            'ip_address' => preg_replace('/\.\d+$/', '.***', $atividade['ip_address'] ?? ''),
        ];
    }
    
    // Monta resposta completa
    $response = [
        'success' => true,
        'curso' => [
            'id' => intval($curso['id']),
            'nome' => $curso['nome'],
            'nome_curto' => $curso['nome_curto'],
            'subdomain' => $curso['subdomain'],
            'moodle_course_id' => $curso['moodle_course_id'],
            'categoria_id' => $curso['categoria_id'],
            'valor' => floatval($curso['valor'] ?? 0),
            'ativo' => boolval($curso['ativo']),
            'summary' => $curso['summary'] ?? '',
            'formato' => $curso['formato'] ?? 'topics',
            'data_inicio' => $curso['data_inicio'],
            'data_fim' => $curso['data_fim'],
            'url' => $curso['url'],
            'created_at' => $curso['created_at'],
            'updated_at' => $curso['updated_at'],
            'polo_nome' => $poloConfig['name'] ?? $curso['subdomain']
        ],
        
        'polo' => [
            'subdomain' => $curso['subdomain'],
            'nome' => $poloConfig['name'] ?? $curso['subdomain'],
            'cidade' => $poloConfig['city'] ?? '',
            'estado' => $poloConfig['state'] ?? '',
            'email' => $poloConfig['contact_email'] ?? '',
            'telefone' => $poloConfig['phone'] ?? '',
            'ativo' => MoodleConfig::isActiveSubdomain($curso['subdomain'])
        ],
        
        'estatisticas' => [
            'total_matriculas' => intval($curso['total_matriculas_todas']),
            'matriculas_ativas' => intval($curso['matriculas_ativas']),
            'matriculas_inativas' => intval($curso['total_matriculas_todas']) - intval($curso['matriculas_ativas']),
            'taxa_retencao' => round($taxaRetencao, 2),
            'total_boletos' => intval($estatisticas['total_boletos']),
            'boletos_pagos' => intval($estatisticas['boletos_pagos']),
            'boletos_pendentes' => intval($estatisticas['boletos_pendentes']),
            'boletos_vencidos' => intval($estatisticas['boletos_vencidos']),
            'boletos_cancelados' => intval($estatisticas['boletos_cancelados']),
            'valor_total' => $valorTotal,
            'valor_arrecadado' => $valorArrecadado,
            'valor_pendente' => $valorPendente,
            'percentual_arrecadado' => round($percentualArrecadado, 2),
            'primeiro_boleto' => $estatisticas['primeiro_boleto'],
            'ultimo_boleto' => $estatisticas['ultimo_boleto'],
            'score_curso' => $scoreCurso
        ],
        
        'alunos_recentes' => $alunosFormatados,
        
        'boletos_recentes' => $boletosFormatados,
        
        'atividades' => $atividadesFormatadas,
        
        'resumo' => [
            'situacao_geral' => getSituacaoGeralCurso($curso, $estatisticas, $matriculasAtivas),
            'proximas_acoes' => getProximasAcoesCurso($curso, $estatisticas, $matriculasAtivas),
            'alertas' => getAlertasCurso($curso, $estatisticas, $matriculasAtivas)
        ],
        
        'acoes_disponiveis' => getAcoesDisponiveisAdminCurso($curso, $estatisticas),
        
        'meta' => [
            'gerado_em' => date('Y-m-d H:i:s'),
            'admin_id' => $_SESSION['admin_id'],
            'versao_api' => '1.0'
        ]
    ];
    
    // Registra acesso aos detalhes
    registrarLogAcessoDetalhesCurso($cursoId);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API Detalhes Curso: ERRO - " . $e->getMessage());
    
    $httpCode = 400;
    
    // Mapeia erros específicos
    if (strpos($e->getMessage(), 'não encontrado') !== false) {
        $httpCode = 404;
    } elseif (strpos($e->getMessage(), 'não autorizado') !== false) {
        $httpCode = 403;
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $httpCode,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Calcula score do curso baseado em diversos fatores
 */
function calcularScoreCurso($curso, $estatisticas, $matriculasAtivas) {
    $score = 0;
    
    // Pontos por estar ativo (0-20 pontos)
    if ($curso['ativo']) {
        $score += 20;
    }
    
    // Pontos por ter alunos matriculados (0-25 pontos)
    if ($matriculasAtivas > 0) {
        if ($matriculasAtivas >= 50) {
            $score += 25;
        } elseif ($matriculasAtivas >= 20) {
            $score += 20;
        } elseif ($matriculasAtivas >= 10) {
            $score += 15;
        } else {
            $score += 10;
        }
    }
    
    // Pontos por performance de pagamentos (0-30 pontos)
    $totalBoletos = intval($estatisticas['total_boletos']);
    $boletosPagos = intval($estatisticas['boletos_pagos']);
    
    if ($totalBoletos > 0) {
        $percentualPagos = ($boletosPagos / $totalBoletos) * 100;
        if ($percentualPagos >= 90) {
            $score += 30;
        } elseif ($percentualPagos >= 70) {
            $score += 25;
        } elseif ($percentualPagos >= 50) {
            $score += 20;
        } else {
            $score += 10;
        }
    }
    
    // Pontos por não ter boletos vencidos (0-15 pontos)
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos == 0) {
        $score += 15;
    } elseif ($boletosVencidos <= 2) {
        $score += 10;
    } elseif ($boletosVencidos <= 5) {
        $score += 5;
    }
    
    // Pontos por tempo de atividade (0-10 pontos)
    $diasAtivo = (time() - strtotime($curso['created_at'])) / (60 * 60 * 24);
    if ($diasAtivo > 365) {
        $score += 10; // Curso consolidado
    } elseif ($diasAtivo > 180) {
        $score += 7;
    } elseif ($diasAtivo > 30) {
        $score += 5;
    } else {
        $score += 2; // Curso novo
    }
    
    return min(100, max(0, round($score)));
}

/**
 * Obtém labels para tipos de atividade
 */
function getTipoAtividadeLabel($tipo) {
    $labels = [
        'upload_individual' => 'Upload Individual',
        'upload_lote' => 'Upload em Lote',
        'upload_multiplo_aluno' => 'Upload Múltiplo',
        'boleto_pago' => 'Boleto Pago',
        'boleto_cancelado' => 'Boleto Cancelado',
        'curso_criado' => 'Curso Criado',
        'curso_editado' => 'Curso Editado',
        'curso_sincronizado' => 'Curso Sincronizado',
        'matricula_criada' => 'Nova Matrícula',
        'matricula_inativada' => 'Matrícula Inativada',
        'sincronizacao_moodle' => 'Sincronização Moodle',
        'detalhes_visualizado' => 'Detalhes Visualizados'
    ];
    
    return $labels[$tipo] ?? ucwords(str_replace('_', ' ', $tipo));
}

/**
 * Obtém situação geral do curso
 */
function getSituacaoGeralCurso($curso, $estatisticas, $matriculasAtivas) {
    if (!$curso['ativo']) {
        return [
            'status' => 'inativo',
            'label' => 'Curso Inativo',
            'descricao' => 'Curso desativado no sistema',
            'cor' => 'danger'
        ];
    }
    
    if ($matriculasAtivas == 0) {
        return [
            'status' => 'sem_alunos',
            'label' => 'Sem Alunos',
            'descricao' => 'Curso não possui alunos matriculados',
            'cor' => 'warning'
        ];
    }
    
    $totalBoletos = intval($estatisticas['total_boletos']);
    $boletosPagos = intval($estatisticas['boletos_pagos']);
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    
    if ($boletosVencidos > 5) {
        return [
            'status' => 'problemas_financeiros',
            'label' => 'Problemas Financeiros',
            'descricao' => "Possui {$boletosVencidos} boleto(s) vencido(s)",
            'cor' => 'danger'
        ];
    }
    
    if ($totalBoletos > 0 && $boletosPagos == $totalBoletos) {
        return [
            'status' => 'adimplente',
            'label' => 'Financeiramente Regular',
            'descricao' => 'Todos os boletos estão pagos',
            'cor' => 'success'
        ];
    }
    
    if ($matriculasAtivas >= 10) {
        return [
            'status' => 'ativo_popular',
            'label' => 'Curso Popular',
            'descricao' => "Possui {$matriculasAtivas} alunos matriculados",
            'cor' => 'success'
        ];
    }
    
    return [
        'status' => 'regular',
        'label' => 'Situação Regular',
        'descricao' => 'Curso ativo com alunos matriculados',
        'cor' => 'info'
    ];
}

/**
 * Obtém próximas ações recomendadas para o curso
 */
function getProximasAcoesCurso($curso, $estatisticas, $matriculasAtivas) {
    $acoes = [];
    
    if (!$curso['ativo']) {
        $acoes[] = [
            'acao' => 'reativar_curso',
            'titulo' => 'Reativar Curso',
            'descricao' => 'Curso está inativo e precisa ser reativado',
            'prioridade' => 'alta'
        ];
    }
    
    if ($matriculasAtivas == 0) {
        $acoes[] = [
            'acao' => 'buscar_alunos',
            'titulo' => 'Matricular Alunos',
            'descricao' => 'Curso sem alunos matriculados',
            'prioridade' => 'alta'
        ];
    }
    
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos > 0) {
        $acoes[] = [
            'acao' => 'cobrar_vencidos',
            'titulo' => 'Cobrar Boletos Vencidos',
            'descricao' => "Realizar cobrança de {$boletosVencidos} boleto(s)",
            'prioridade' => 'alta'
        ];
    }
    
    $boletosPendentes = intval($estatisticas['boletos_pendentes']);
    if ($boletosPendentes > 0) {
        $acoes[] = [
            'acao' => 'acompanhar_pendentes',
            'titulo' => 'Acompanhar Pendentes',
            'descricao' => "Monitorar {$boletosPendentes} boleto(s) pendente(s)",
            'prioridade' => 'media'
        ];
    }
    
    // Se não tem boletos, pode precisar de novos
    $totalBoletos = intval($estatisticas['total_boletos']);
    if ($totalBoletos == 0 && $matriculasAtivas > 0) {
        $acoes[] = [
            'acao' => 'gerar_boletos',
            'titulo' => 'Gerar Boletos',
            'descricao' => 'Curso com alunos mas sem boletos',
            'prioridade' => 'media'
        ];
    }
    
    // Verificar se dados estão atualizados (mais de 30 dias)
    $ultimaAtualizacao = strtotime($curso['updated_at'] ?? $curso['created_at']);
    if ((time() - $ultimaAtualizacao) > (30 * 24 * 60 * 60)) {
        $acoes[] = [
            'acao' => 'sincronizar_dados',
            'titulo' => 'Sincronizar Dados',
            'descricao' => 'Dados não são atualizados há mais de 30 dias',
            'prioridade' => 'baixa'
        ];
    }
    
    return $acoes;
}

/**
 * Obtém alertas importantes do curso
 */
function getAlertasCurso($curso, $estatisticas, $matriculasAtivas) {
    $alertas = [];
    
    if (!$curso['ativo']) {
        $alertas[] = [
            'tipo' => 'curso_inativo',
            'titulo' => 'Curso Inativo',
            'mensagem' => 'Este curso está desativado no sistema',
            'severidade' => 'danger'
        ];
    }
    
    if ($matriculasAtivas == 0) {
        $alertas[] = [
            'tipo' => 'sem_alunos',
            'titulo' => 'Sem Alunos',
            'mensagem' => 'Curso não possui alunos matriculados',
            'severidade' => 'warning'
        ];
    }
    
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos > 0) {
        $alertas[] = [
            'tipo' => 'boletos_vencidos',
            'titulo' => 'Boletos Vencidos',
            'mensagem' => "Possui {$boletosVencidos} boleto(s) vencido(s)",
            'severidade' => 'danger'
        ];
    }
    
    // Verifica se curso tem ID do Moodle
    if (empty($curso['moodle_course_id'])) {
        $alertas[] = [
            'tipo' => 'sem_moodle_id',
            'titulo' => 'Sem ID do Moodle',
            'mensagem' => 'Curso não está vinculado ao Moodle',
            'severidade' => 'info'
        ];
    }
    
    // Verifica se curso tem valor padrão
    if (empty($curso['valor']) || $curso['valor'] <= 0) {
        $alertas[] = [
            'tipo' => 'sem_valor_padrao',
            'titulo' => 'Sem Valor Padrão',
            'mensagem' => 'Curso não possui valor padrão configurado',
            'severidade' => 'info'
        ];
    }
    
    // Verifica cursos muito antigos sem atividade
    $ultimaAtualizacao = strtotime($curso['updated_at'] ?? $curso['created_at']);
    if ((time() - $ultimaAtualizacao) > (90 * 24 * 60 * 60)) {
        $alertas[] = [
            'tipo' => 'curso_antigo',
            'titulo' => 'Curso Sem Atualizações',
            'mensagem' => 'Não há atualizações há mais de 90 dias',
            'severidade' => 'warning'
        ];
    }
    
    return $alertas;
}

/**
 * Obtém ações disponíveis para administradores
 */
function getAcoesDisponiveisAdminCurso($curso, $estatisticas) {
    $acoes = [];
    
    // Sincronizar sempre disponível
    $acoes[] = [
        'tipo' => 'sincronizar',
        'label' => 'Sincronizar com Moodle',
        'icone' => 'fas fa-sync-alt',
        'classe' => 'btn-info'
    ];
    
    // Editar sempre disponível
    $acoes[] = [
        'tipo' => 'editar',
        'label' => 'Editar Dados',
        'icone' => 'fas fa-edit',
        'classe' => 'btn-secondary'
    ];
    
    // Ver alunos sempre disponível
    $acoes[] = [
        'tipo' => 'ver_alunos',
        'label' => 'Ver Todos os Alunos',
        'icone' => 'fas fa-users',
        'classe' => 'btn-primary'
    ];
    
    // Ver boletos sempre disponível
    $acoes[] = [
        'tipo' => 'ver_boletos',
        'label' => 'Ver Todos os Boletos',
        'icone' => 'fas fa-file-invoice-dollar',
        'classe' => 'btn-success'
    ];
    
    // Exportar dados sempre disponível
    $acoes[] = [
        'tipo' => 'exportar',
        'label' => 'Exportar Dados',
        'icone' => 'fas fa-download',
        'classe' => 'btn-outline-secondary'
    ];
    
    // Ações específicas baseadas no status
    if (!$curso['ativo']) {
        $acoes[] = [
            'tipo' => 'ativar',
            'label' => 'Ativar Curso',
            'icone' => 'fas fa-play',
            'classe' => 'btn-success'
        ];
    } else {
        $acoes[] = [
            'tipo' => 'desativar',
            'label' => 'Desativar Curso',
            'icone' => 'fas fa-pause',
            'classe' => 'btn-warning'
        ];
    }
    
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos > 0) {
        $acoes[] = [
            'tipo' => 'cobrar_vencidos',
            'label' => 'Cobrar Vencidos',
            'icone' => 'fas fa-exclamation-triangle',
            'classe' => 'btn-danger'
        ];
    }
    
    return $acoes;
}

/**
 * Registra log de acesso aos detalhes do curso
 */
function registrarLogAcessoDetalhesCurso($cursoId) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "Detalhes do curso visualizados pelo administrador (Curso ID: {$cursoId})";
        
        $stmt->execute([
            'admin_detalhes_curso',
            $_SESSION['admin_id'],
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log de acesso aos detalhes do curso: " . $e->getMessage());
    }
}

/**
 * Busca informações complementares do Moodle (se necessário)
 */
function obterInformacoesMoodleCurso($curso) {
    try {
        require_once '../../src/MoodleAPI.php';
        
        $moodleAPI = new MoodleAPI($curso['subdomain']);
        
        // Busca detalhes atualizados no Moodle
        $detalhesMoodle = $moodleAPI->buscarDetalhesCurso($curso['moodle_course_id']);
        
        if ($detalhesMoodle) {
            return [
                'conectado' => true,
                'nome_moodle' => $detalhesMoodle['nome'],
                'summary_moodle' => $detalhesMoodle['summary'],
                'data_inicio_moodle' => $detalhesMoodle['data_inicio'],
                'data_fim_moodle' => $detalhesMoodle['data_fim'],
                'total_alunos_moodle' => $detalhesMoodle['total_alunos'],
                'url_moodle' => $detalhesMoodle['url']
            ];
        }
        
        return ['conectado' => false, 'erro' => 'Curso não encontrado no Moodle'];
        
    } catch (Exception $e) {
        error_log("Erro ao buscar informações do Moodle para o curso: " . $e->getMessage());
        return ['conectado' => false, 'erro' => $e->getMessage()];
    }
}

/**
 * Calcula métricas avançadas do curso
 */
function calcularMetricasAvancadasCurso($curso, $estatisticas, $boletos) {
    $metricas = [];
    
    // Taxa de conversão (alunos que geraram boletos)
    $totalAlunos = intval($curso['matriculas_ativas']);
    $alunosComBoletos = count(array_unique(array_column($boletos, 'aluno_id')));
    
    if ($totalAlunos > 0) {
        $metricas['taxa_conversao_boletos'] = round(($alunosComBoletos / $totalAlunos) * 100, 2);
    }
    
    // Valor médio por aluno
    if ($alunosComBoletos > 0) {
        $valorTotal = floatval($estatisticas['valor_total']);
        $metricas['valor_medio_por_aluno'] = round($valorTotal / $alunosComBoletos, 2);
    }
    
    // Frequência de geração de boletos
    if (!empty($boletos)) {
        $datasCriacao = array_map(function($b) {
            return strtotime($b['created_at']);
        }, $boletos);
        
        sort($datasCriacao);
        $intervalos = [];
        
        for ($i = 1; $i < count($datasCriacao); $i++) {
            $intervalos[] = ($datasCriacao[$i] - $datasCriacao[$i-1]) / (60*60*24);
        }
        
        if (!empty($intervalos)) {
            $metricas['intervalo_medio_boletos'] = round(array_sum($intervalos) / count($intervalos));
        }
    }
    
    // Sazonalidade (mês com mais boletos)
    if (!empty($boletos)) {
        $meses = [];
        foreach ($boletos as $boleto) {
            $mes = date('n', strtotime($boleto['created_at']));
            $meses[$mes] = ($meses[$mes] ?? 0) + 1;
        }
        
        if (!empty($meses)) {
            $mesSazonalidade = array_keys($meses, max($meses))[0];
            $nomesMeses = [
                1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
            ];
            
            $metricas['mes_mais_ativo'] = $nomesMeses[$mesSazonalidade];
            $metricas['boletos_mes_ativo'] = $meses[$mesSazonalidade];
        }
    }
    
    return $metricas;
}

/**
 * Gera recomendações personalizadas para o curso
 */
function gerarRecomendacoesCurso($curso, $estatisticas, $matriculasAtivas, $metricas = []) {
    $recomendacoes = [];
    
    // Recomendações baseadas no número de alunos
    if ($matriculasAtivas == 0) {
        $recomendacoes[] = [
            'categoria' => 'matriculas',
            'titulo' => 'Aumentar Matrículas',
            'descricao' => 'Implementar estratégias para atrair alunos',
            'prioridade' => 'alta',
            'acoes' => ['Campanhas de marketing', 'Parcerias com empresas', 'Divulgação em redes sociais']
        ];
    } elseif ($matriculasAtivas < 10) {
        $recomendacoes[] = [
            'categoria' => 'crescimento',
            'titulo' => 'Expandir Base de Alunos',
            'descricao' => 'Curso tem potencial para crescimento',
            'prioridade' => 'media',
            'acoes' => ['Análise de mercado', 'Melhorar material do curso', 'Oferecer promoções']
        ];
    }
    
    // Recomendações financeiras
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos > 0) {
        $recomendacoes[] = [
            'categoria' => 'financeiro',
            'titulo' => 'Reduzir Inadimplência',
            'descricao' => "Implementar estratégias para reduzir {$boletosVencidos} boleto(s) vencido(s)",
            'prioridade' => 'alta',
            'acoes' => ['Lembretes automáticos', 'Facilitar formas de pagamento', 'Negociação de dívidas']
        ];
    }
    
    // Recomendações de valor
    if (isset($metricas['valor_medio_por_aluno']) && $metricas['valor_medio_por_aluno'] < 100) {
        $recomendacoes[] = [
            'categoria' => 'pricing',
            'titulo' => 'Revisar Precificação',
            'descricao' => 'Valor médio por aluno está baixo',
            'prioridade' => 'media',
            'acoes' => ['Análise de concorrência', 'Adicionar valor ao curso', 'Criar pacotes premium']
        ];
    }
    
    // Recomendações de moodle
    if (empty($curso['moodle_course_id'])) {
        $recomendacoes[] = [
            'categoria' => 'integracao',
            'titulo' => 'Integrar com Moodle',
            'descricao' => 'Curso não está integrado com o Moodle',
            'prioridade' => 'baixa',
            'acoes' => ['Configurar integração', 'Sincronizar dados', 'Treinar equipe']
        ];
    }
    
    return $recomendacoes;
}

/**
 * Busca estatísticas comparativas (curso vs média do polo)
 */
function obterEstatisticasComparativasCurso($curso, $estatisticas) {
    try {
        $db = (new Database())->getConnection();
        
        // Média do polo
        $stmtPolo = $db->prepare("
            SELECT 
                AVG(sub.total_matriculas) as media_matriculas_polo,
                AVG(sub.total_boletos) as media_boletos_polo,
                AVG(sub.percentual_pagos) as media_pagamentos_polo,
                AVG(sub.valor_total) as media_valor_polo
            FROM (
                SELECT 
                    c.id,
                    COUNT(DISTINCT m.id) as total_matriculas,
                    COUNT(DISTINCT b.id) as total_boletos,
                    CASE 
                        WHEN COUNT(b.id) > 0 
                        THEN (COUNT(CASE WHEN b.status = 'pago' THEN 1 END) * 100.0 / COUNT(b.id))
                        ELSE 0 
                    END as percentual_pagos,
                    COALESCE(SUM(b.valor), 0) as valor_total
                FROM cursos c
                LEFT JOIN matriculas m ON c.id = m.curso_id AND m.status = 'ativa'
                LEFT JOIN boletos b ON c.id = b.curso_id
                WHERE c.subdomain = ? AND c.id != ?
                GROUP BY c.id
            ) sub
        ");
        $stmtPolo->execute([$curso['subdomain'], $curso['id']]);
        $mediaPolo = $stmtPolo->fetch(PDO::FETCH_ASSOC);
        
        // Posição do curso no ranking do polo
        $stmtRanking = $db->prepare("
            SELECT COUNT(*) + 1 as posicao_polo
            FROM (
                SELECT 
                    c.id,
                    COUNT(DISTINCT CASE WHEN m.status = 'ativa' THEN m.id END) as matriculas_ativas
                FROM cursos c
                LEFT JOIN matriculas m ON c.id = m.curso_id
                WHERE c.subdomain = ? AND c.id != ?
                GROUP BY c.id
                HAVING matriculas_ativas > ?
            ) ranking
        ");
        $stmtRanking->execute([
            $curso['subdomain'], 
            $curso['id'], 
            intval($estatisticas['matriculas_ativas'])
        ]);
        $ranking = $stmtRanking->fetch(PDO::FETCH_ASSOC);
        
        return [
            'media_matriculas_polo' => round(floatval($mediaPolo['media_matriculas_polo'] ?? 0), 1),
            'media_boletos_polo' => round(floatval($mediaPolo['media_boletos_polo'] ?? 0), 1),
            'media_pagamentos_polo' => round(floatval($mediaPolo['media_pagamentos_polo'] ?? 0), 1),
            'media_valor_polo' => round(floatval($mediaPolo['media_valor_polo'] ?? 0), 2),
            'posicao_ranking_polo' => intval($ranking['posicao_polo'] ?? 0)
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao calcular estatísticas comparativas do curso: " . $e->getMessage());
        return [];
    }
}

// Log da execução para debug
error_log("API Detalhes Curso executada com sucesso - Timestamp: " . date('Y-m-d H:i:s'));
?>