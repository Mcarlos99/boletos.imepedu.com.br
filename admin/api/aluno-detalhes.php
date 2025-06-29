<?php
/**
 * Sistema de Boletos IMEPEDU - API para Detalhes do Aluno (ADMINISTRAÇÃO)
 * Arquivo: admin/api/aluno-detalhes.php
 * 
 * API específica para administradores visualizarem detalhes completos dos alunos
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
    
    // Obtém ID do aluno
    $alunoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$alunoId) {
        throw new Exception('ID do aluno é obrigatório e deve ser um número válido');
    }
    
    error_log("API Detalhes Aluno: Buscando aluno ID: {$alunoId} - Admin: " . $_SESSION['admin_id']);
    
    $db = (new Database())->getConnection();
    
    // Busca dados básicos do aluno
    $stmt = $db->prepare("
        SELECT a.*,
               COUNT(DISTINCT m.id) as total_matriculas_ativas,
               COUNT(DISTINCT m2.id) as total_matriculas_todas
        FROM alunos a
        LEFT JOIN matriculas m ON a.id = m.aluno_id AND m.status = 'ativa'
        LEFT JOIN matriculas m2 ON a.id = m2.aluno_id
        WHERE a.id = ?
        GROUP BY a.id
    ");
    $stmt->execute([$alunoId]);
    $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$aluno) {
        throw new Exception('Aluno não encontrado');
    }
    
    error_log("API Detalhes Aluno: Aluno encontrado - {$aluno['nome']} (CPF: {$aluno['cpf']})");
    
    // Busca matrículas ativas com detalhes dos cursos
    $stmtMatriculas = $db->prepare("
        SELECT m.*,
               c.nome as curso_nome,
               c.nome_curto as curso_nome_curto,
               c.subdomain as curso_subdomain,
               c.moodle_course_id,
               c.valor as curso_valor
        FROM matriculas m
        INNER JOIN cursos c ON m.curso_id = c.id
        WHERE m.aluno_id = ? AND m.status = 'ativa'
        ORDER BY m.created_at DESC
    ");
    $stmtMatriculas->execute([$alunoId]);
    $matriculas = $stmtMatriculas->fetchAll(PDO::FETCH_ASSOC);
    
    // Busca estatísticas de boletos
    $stmtEstatisticas = $db->prepare("
        SELECT 
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
            COUNT(CASE WHEN status = 'pendente' THEN 1 END) as boletos_pendentes,
            COUNT(CASE WHEN status = 'vencido' THEN 1 END) as boletos_vencidos,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as boletos_cancelados,
            COALESCE(SUM(valor), 0) as valor_total,
            COALESCE(SUM(CASE WHEN status = 'pago' THEN COALESCE(valor_pago, valor) ELSE 0 END), 0) as valor_pago,
            COALESCE(SUM(CASE WHEN status IN ('pendente', 'vencido') THEN valor ELSE 0 END), 0) as valor_pendente,
            MIN(created_at) as primeiro_boleto,
            MAX(created_at) as ultimo_boleto
        FROM boletos 
        WHERE aluno_id = ?
    ");
    $stmtEstatisticas->execute([$alunoId]);
    $estatisticas = $stmtEstatisticas->fetch(PDO::FETCH_ASSOC);
    
    // Busca boletos recentes (últimos 10)
    $stmtBoletos = $db->prepare("
        SELECT b.*,
               c.nome as curso_nome,
               c.subdomain as curso_subdomain,
               ad.nome as admin_nome
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        LEFT JOIN administradores ad ON b.admin_id = ad.id
        WHERE b.aluno_id = ?
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    $stmtBoletos->execute([$alunoId]);
    $boletos = $stmtBoletos->fetchAll(PDO::FETCH_ASSOC);
    
    // Busca atividades recentes (logs)
    $stmtLogs = $db->prepare("
        SELECT l.*,
               b.numero_boleto,
               ad.nome as admin_nome
        FROM logs l
        LEFT JOIN boletos b ON l.boleto_id = b.id
        LEFT JOIN administradores ad ON l.usuario_id = ad.id
        WHERE l.usuario_id = ? OR l.boleto_id IN (
            SELECT id FROM boletos WHERE aluno_id = ?
        )
        ORDER BY l.created_at DESC
        LIMIT 15
    ");
    $stmtLogs->execute([$alunoId, $alunoId]);
    $atividades = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    
    // Busca informações do polo
    $poloConfig = MoodleConfig::getConfig($aluno['subdomain']);
    
    // Calcula métricas adicionais
    $valorTotal = floatval($estatisticas['valor_total']);
    $valorPago = floatval($estatisticas['valor_pago']);
    $valorPendente = floatval($estatisticas['valor_pendente']);
    $percentualPago = $valorTotal > 0 ? ($valorPago / $valorTotal) * 100 : 0;
    
    // Determina status do aluno
    $ultimoAcesso = $aluno['ultimo_acesso'];
    $ativo = false;
    $diasInativo = null;
    
    if ($ultimoAcesso) {
        $dataUltimoAcesso = new DateTime($ultimoAcesso);
        $hoje = new DateTime();
        $diff = $hoje->diff($dataUltimoAcesso);
        $diasInativo = $diff->days;
        $ativo = $diasInativo <= 30;
    }
    
    // Verifica se tem boletos vencidos
    $temBoletosVencidos = intval($estatisticas['boletos_vencidos']) > 0;
    
    // Calcula score do aluno (métrica customizada)
    $scoreAluno = calcularScoreAluno($aluno, $estatisticas, $ativo);
    
    // Prepara dados das matrículas com informações do polo
    $matriculasFormatadas = [];
    foreach ($matriculas as $matricula) {
        $poloMatricula = MoodleConfig::getConfig($matricula['curso_subdomain']);
        $matriculasFormatadas[] = [
            'id' => $matricula['id'],
            'curso_id' => $matricula['curso_id'],
            'curso_nome' => $matricula['curso_nome'],
            'curso_nome_curto' => $matricula['curso_nome_curto'],
            'curso_subdomain' => $matricula['curso_subdomain'],
            'polo_nome' => $poloMatricula['name'] ?? $matricula['curso_subdomain'],
            'moodle_course_id' => $matricula['moodle_course_id'],
            'data_matricula' => $matricula['data_matricula'],
            'data_matricula_formatada' => date('d/m/Y', strtotime($matricula['data_matricula'])),
            'status' => $matricula['status'],
            'curso_valor' => $matricula['curso_valor'] ? floatval($matricula['curso_valor']) : null,
            'curso_valor_formatado' => $matricula['curso_valor'] ? 
                'R$ ' . number_format($matricula['curso_valor'], 2, ',', '.') : null
        ];
    }
    
    // Prepara dados dos boletos formatados
    $boletosFormatados = [];
    foreach ($boletos as $boleto) {
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
            'curso_nome' => $boleto['curso_nome'],
            'curso_subdomain' => $boleto['curso_subdomain'],
            'admin_nome' => $boleto['admin_nome'] ?: 'Sistema',
            'created_at' => $boleto['created_at'],
            'created_at_formatado' => date('d/m/Y H:i', strtotime($boleto['created_at'])),
            'data_pagamento' => $boleto['data_pagamento'],
            'data_pagamento_formatada' => $boleto['data_pagamento'] ? 
                date('d/m/Y', strtotime($boleto['data_pagamento'])) : null,
            'valor_pago' => $boleto['valor_pago'] ? floatval($boleto['valor_pago']) : null,
            'arquivo_pdf' => !empty($boleto['arquivo_pdf'])
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
        'aluno' => [
            'id' => intval($aluno['id']),
            'nome' => $aluno['nome'],
            'cpf' => $aluno['cpf'],
            'email' => $aluno['email'],
            'moodle_user_id' => $aluno['moodle_user_id'],
            'subdomain' => $aluno['subdomain'],
            'city' => $aluno['city'],
            'country' => $aluno['country'] ?: 'BR',
            'created_at' => $aluno['created_at'],
            'updated_at' => $aluno['updated_at'],
            'ultimo_acesso' => $aluno['ultimo_acesso'],
            'ultimo_acesso_formatado' => $ultimoAcesso ? 
                date('d/m/Y H:i', strtotime($ultimoAcesso)) : null,
            'dias_inativo' => $diasInativo,
            'ativo' => $ativo,
            'observacoes' => $aluno['observacoes'] ?? null
        ],
        
        'polo' => [
            'subdomain' => $aluno['subdomain'],
            'nome' => $poloConfig['name'] ?? $aluno['subdomain'],
            'cidade' => $poloConfig['city'] ?? '',
            'estado' => $poloConfig['state'] ?? '',
            'email' => $poloConfig['contact_email'] ?? '',
            'telefone' => $poloConfig['phone'] ?? '',
            'ativo' => MoodleConfig::isActiveSubdomain($aluno['subdomain'])
        ],
        
        'matriculas' => $matriculasFormatadas,
        
        'estatisticas' => [
            'total_matriculas' => intval($aluno['total_matriculas_ativas']),
            'total_matriculas_historico' => intval($aluno['total_matriculas_todas']),
            'total_boletos' => intval($estatisticas['total_boletos']),
            'boletos_pagos' => intval($estatisticas['boletos_pagos']),
            'boletos_pendentes' => intval($estatisticas['boletos_pendentes']),
            'boletos_vencidos' => intval($estatisticas['boletos_vencidos']),
            'boletos_cancelados' => intval($estatisticas['boletos_cancelados']),
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'valor_pendente' => $valorPendente,
            'percentual_pago' => round($percentualPago, 2),
            'primeiro_boleto' => $estatisticas['primeiro_boleto'],
            'ultimo_boleto' => $estatisticas['ultimo_boleto'],
            'tem_boletos_vencidos' => $temBoletosVencidos,
            'score_aluno' => $scoreAluno
        ],
        
        'boletos' => $boletosFormatados,
        
        'atividades' => $atividadesFormatadas,
        
        'resumo' => [
            'situacao_geral' => getSituacaoGeral($aluno, $estatisticas, $ativo),
            'proximas_acoes' => getProximasAcoes($aluno, $estatisticas, $ativo),
            'alertas' => getAlertas($aluno, $estatisticas, $ativo, $temBoletosVencidos)
        ],
        
        'acoes_disponiveis' => getAcoesDisponiveisAdmin($aluno, $estatisticas),
        
        'meta' => [
            'gerado_em' => date('Y-m-d H:i:s'),
            'admin_id' => $_SESSION['admin_id'],
            'versao_api' => '1.0'
        ]
    ];
    
    // Registra acesso aos detalhes
    registrarLogAcessoDetalhes($alunoId);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API Detalhes Aluno: ERRO - " . $e->getMessage());
    
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
 * Calcula score do aluno baseado em diversos fatores
 */
function calcularScoreAluno($aluno, $estatisticas, $ativo) {
    $score = 0;
    
    // Pontos por estar ativo (0-30 pontos)
    if ($ativo) {
        $score += 30;
    } else {
        // Penaliza inatividade
        $ultimoAcesso = $aluno['ultimo_acesso'];
        if ($ultimoAcesso) {
            $diasInativo = (time() - strtotime($ultimoAcesso)) / (60 * 60 * 24);
            if ($diasInativo <= 60) {
                $score += max(0, 30 - ($diasInativo * 0.5));
            }
        }
    }
    
    // Pontos por pagamentos (0-40 pontos)
    $totalBoletos = intval($estatisticas['total_boletos']);
    $boletosPagos = intval($estatisticas['boletos_pagos']);
    
    if ($totalBoletos > 0) {
        $percentualPagos = ($boletosPagos / $totalBoletos) * 100;
        $score += ($percentualPagos * 0.4);
    }
    
    // Pontos por não ter boletos vencidos (0-20 pontos)
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos == 0) {
        $score += 20;
    } else {
        $score += max(0, 20 - ($boletosVencidos * 5));
    }
    
    // Pontos por tempo no sistema (0-10 pontos)
    $diasCadastrado = (time() - strtotime($aluno['created_at'])) / (60 * 60 * 24);
    if ($diasCadastrado > 365) {
        $score += 10; // Aluno antigo
    } elseif ($diasCadastrado > 180) {
        $score += 7;
    } elseif ($diasCadastrado > 30) {
        $score += 5;
    } else {
        $score += 2; // Aluno novo
    }
    
    return min(100, max(0, round($score)));
}

/**
 * Obtém labels para tipos de atividade
 */
function getTipoAtividadeLabel($tipo) {
    $labels = [
        'login' => 'Login no Sistema',
        'logout' => 'Logout do Sistema',
        'boleto_pago' => 'Boleto Pago',
        'boleto_cancelado' => 'Boleto Cancelado',
        'download_boleto' => 'Download de Boleto',
        'download_pdf_sucesso' => 'Download PDF',
        'pix_gerado' => 'PIX Gerado',
        'upload_individual' => 'Boleto Criado',
        'upload_multiplo_aluno' => 'Múltiplos Boletos Criados',
        'aluno_sincronizado' => 'Dados Sincronizados',
        'aluno_editado' => 'Dados Editados',
        'detalhes_visualizado' => 'Detalhes Visualizados'
    ];
    
    return $labels[$tipo] ?? ucwords(str_replace('_', ' ', $tipo));
}

/**
 * Obtém situação geral do aluno
 */
function getSituacaoGeral($aluno, $estatisticas, $ativo) {
    $totalBoletos = intval($estatisticas['total_boletos']);
    $boletosPagos = intval($estatisticas['boletos_pagos']);
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    $boletosPendentes = intval($estatisticas['boletos_pendentes']);
    
    if (!$ativo) {
        return [
            'status' => 'inativo',
            'label' => 'Aluno Inativo',
            'descricao' => 'Não acessa o sistema há mais de 30 dias',
            'cor' => 'danger'
        ];
    }
    
    if ($boletosVencidos > 0) {
        return [
            'status' => 'inadimplente',
            'label' => 'Inadimplente',
            'descricao' => "Possui {$boletosVencidos} boleto(s) vencido(s)",
            'cor' => 'warning'
        ];
    }
    
    if ($totalBoletos > 0 && $boletosPagos == $totalBoletos) {
        return [
            'status' => 'adimplente',
            'label' => 'Adimplente',
            'descricao' => 'Todos os boletos estão pagos',
            'cor' => 'success'
        ];
    }
    
    if ($boletosPendentes > 0) {
        return [
            'status' => 'pendente',
            'label' => 'Pendências',
            'descricao' => "Possui {$boletosPendentes} boleto(s) pendente(s)",
            'cor' => 'info'
        ];
    }
    
    return [
        'status' => 'regular',
        'label' => 'Situação Regular',
        'descricao' => 'Aluno ativo sem pendências',
        'cor' => 'success'
    ];
}

/**
 * Obtém próximas ações recomendadas
 */
function getProximasAcoes($aluno, $estatisticas, $ativo) {
    $acoes = [];
    
    if (!$ativo) {
        $acoes[] = [
            'acao' => 'entrar_contato',
            'titulo' => 'Entrar em Contato',
            'descricao' => 'Verificar motivo da inatividade',
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
    if ($totalBoletos == 0) {
        $acoes[] = [
            'acao' => 'gerar_boletos',
            'titulo' => 'Gerar Boletos',
            'descricao' => 'Aluno sem boletos cadastrados',
            'prioridade' => 'media'
        ];
    }
    
    // Verificar se dados estão atualizados (mais de 60 dias)
    $ultimaAtualizacao = strtotime($aluno['updated_at'] ?? $aluno['created_at']);
    if ((time() - $ultimaAtualizacao) > (60 * 24 * 60 * 60)) {
        $acoes[] = [
            'acao' => 'sincronizar_dados',
            'titulo' => 'Sincronizar Dados',
            'descricao' => 'Dados não são atualizados há mais de 60 dias',
            'prioridade' => 'baixa'
        ];
    }
    
    return $acoes;
}

/**
 * Obtém alertas importantes
 */
function getAlertas($aluno, $estatisticas, $ativo, $temBoletosVencidos) {
    $alertas = [];
    
    if (!$ativo) {
        $ultimoAcesso = $aluno['ultimo_acesso'];
        $diasInativo = $ultimoAcesso ? 
            round((time() - strtotime($ultimoAcesso)) / (60 * 60 * 24)) : null;
        
        $alertas[] = [
            'tipo' => 'inatividade',
            'titulo' => 'Aluno Inativo',
            'mensagem' => $diasInativo ? 
                "Não acessa há {$diasInativo} dias" : 'Nunca acessou o sistema',
            'severidade' => 'warning'
        ];
    }
    
    if ($temBoletosVencidos) {
        $boletosVencidos = intval($estatisticas['boletos_vencidos']);
        $alertas[] = [
            'tipo' => 'inadimplencia',
            'titulo' => 'Boletos Vencidos',
            'mensagem' => "Possui {$boletosVencidos} boleto(s) vencido(s)",
            'severidade' => 'danger'
        ];
    }
    
    // Verifica se email está preenchido
    if (empty($aluno['email']) || !filter_var($aluno['email'], FILTER_VALIDATE_EMAIL)) {
        $alertas[] = [
            'tipo' => 'dados_incompletos',
            'titulo' => 'Email Inválido',
            'mensagem' => 'Email não está preenchido corretamente',
            'severidade' => 'info'
        ];
    }
    
    // Verifica se CPF está válido
    if (!validarCPF($aluno['cpf'])) {
        $alertas[] = [
            'tipo' => 'dados_invalidos',
            'titulo' => 'CPF Inválido',
            'mensagem' => 'CPF não é válido',
            'severidade' => 'warning'
        ];
    }
    
    return $alertas;
}

/**
 * Obtém ações disponíveis para administradores
 */
function getAcoesDisponiveisAdmin($aluno, $estatisticas) {
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
    
    // Ver boletos sempre disponível
    $acoes[] = [
        'tipo' => 'ver_boletos',
        'label' => 'Ver Todos os Boletos',
        'icone' => 'fas fa-file-invoice-dollar',
        'classe' => 'btn-primary'
    ];
    
    // Criar boleto sempre disponível
    $acoes[] = [
        'tipo' => 'criar_boleto',
        'label' => 'Criar Novo Boleto',
        'icone' => 'fas fa-plus',
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
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos > 0) {
        $acoes[] = [
            'tipo' => 'cobrar',
            'label' => 'Enviar Cobrança',
            'icone' => 'fas fa-envelope',
            'classe' => 'btn-warning'
        ];
    }
    
    return $acoes;
}

/**
 * Valida CPF
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

/**
 * Registra log de acesso aos detalhes
 */
function registrarLogAcessoDetalhes($alunoId) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, boleto_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "Detalhes do aluno visualizados pelo administrador";
        
        $stmt->execute([
            'admin_detalhes_aluno',
            $_SESSION['admin_id'],
            null, // Não é específico de um boleto
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log de acesso aos detalhes: " . $e->getMessage());
    }
}

/**
 * Obtém informações complementares do Moodle (se necessário)
 */
function obterInformacoesMoodle($aluno) {
    try {
        require_once '../../src/MoodleAPI.php';
        
        $moodleAPI = new MoodleAPI($aluno['subdomain']);
        
        // Busca perfil atualizado no Moodle
        $perfilMoodle = $moodleAPI->buscarPerfilUsuario($aluno['moodle_user_id']);
        
        if ($perfilMoodle) {
            return [
                'conectado' => true,
                'ultimo_acesso_moodle' => $perfilMoodle['ultimo_acesso'],
                'foto_perfil' => $perfilMoodle['foto_perfil'],
                'descricao' => $perfilMoodle['descricao'],
                'telefone1' => $perfilMoodle['telefone1'],
                'telefone2' => $perfilMoodle['telefone2'],
                'endereco' => $perfilMoodle['endereco']
            ];
        }
        
        return ['conectado' => false, 'erro' => 'Perfil não encontrado'];
        
    } catch (Exception $e) {
        error_log("Erro ao buscar informações do Moodle: " . $e->getMessage());
        return ['conectado' => false, 'erro' => $e->getMessage()];
    }
}

/**
 * Calcula métricas avançadas do aluno
 */
function calcularMetricasAvancadas($aluno, $estatisticas, $boletos) {
    $metricas = [];
    
    // Frequência de pagamentos
    $boletosPagos = array_filter($boletos, function($b) {
        return $b['status'] === 'pago';
    });
    
    if (count($boletosPagos) > 1) {
        $datasPagamento = array_map(function($b) {
            return strtotime($b['data_pagamento']);
        }, $boletosPagos);
        
        sort($datasPagamento);
        $intervalos = [];
        
        for ($i = 1; $i < count($datasPagamento); $i++) {
            $intervalos[] = ($datasPagamento[$i] - $datasPagamento[$i-1]) / (60*60*24);
        }
        
        $metricas['intervalo_medio_pagamentos'] = round(array_sum($intervalos) / count($intervalos));
    }
    
    // Pontualidade nos pagamentos
    $pagamentosEmDia = 0;
    $pagamentosAtrasados = 0;
    
    foreach ($boletosPagos as $boleto) {
        if ($boleto['data_pagamento'] && $boleto['vencimento']) {
            if (strtotime($boleto['data_pagamento']) <= strtotime($boleto['vencimento'])) {
                $pagamentosEmDia++;
            } else {
                $pagamentosAtrasados++;
            }
        }
    }
    
    $totalPagamentos = $pagamentosEmDia + $pagamentosAtrasados;
    if ($totalPagamentos > 0) {
        $metricas['percentual_pontualidade'] = round(($pagamentosEmDia / $totalPagamentos) * 100, 2);
    }
    
    // Valor médio dos boletos
    $valores = array_map(function($b) { return floatval($b['valor']); }, $boletos);
    if (!empty($valores)) {
        $metricas['valor_medio_boletos'] = array_sum($valores) / count($valores);
        $metricas['valor_maior_boleto'] = max($valores);
        $metricas['valor_menor_boleto'] = min($valores);
    }
    
    // Tempo médio para pagamento
    $temposPagamento = [];
    foreach ($boletosPagos as $boleto) {
        if ($boleto['data_pagamento'] && $boleto['created_at']) {
            $diasPagamento = (strtotime($boleto['data_pagamento']) - strtotime($boleto['created_at'])) / (60*60*24);
            $temposPagamento[] = $diasPagamento;
        }
    }
    
    if (!empty($temposPagamento)) {
        $metricas['tempo_medio_pagamento'] = round(array_sum($temposPagamento) / count($temposPagamento), 1);
    }
    
    return $metricas;
}

/**
 * Gera recomendações personalizadas para o aluno
 */
function gerarRecomendacoes($aluno, $estatisticas, $ativo, $metricas = []) {
    $recomendacoes = [];
    
    // Recomendações baseadas no status de atividade
    if (!$ativo) {
        $recomendacoes[] = [
            'categoria' => 'engajamento',
            'titulo' => 'Reativar Aluno',
            'descricao' => 'Entrar em contato para verificar dificuldades e oferecer suporte',
            'prioridade' => 'alta',
            'acoes' => ['Ligar para o aluno', 'Enviar email de suporte', 'Verificar problemas técnicos']
        ];
    }
    
    // Recomendações financeiras
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos > 0) {
        $recomendacoes[] = [
            'categoria' => 'financeiro',
            'titulo' => 'Regularizar Inadimplência',
            'descricao' => "Negociar pagamento de {$boletosVencidos} boleto(s) vencido(s)",
            'prioridade' => 'alta',
            'acoes' => ['Oferecer desconto', 'Parcelamento', 'Renegociar valores']
        ];
    }
    
    // Recomendações de pontualidade
    if (isset($metricas['percentual_pontualidade']) && $metricas['percentual_pontualidade'] < 70) {
        $recomendacoes[] = [
            'categoria' => 'pontualidade',
            'titulo' => 'Melhorar Pontualidade',
            'descricao' => 'Implementar lembretes automáticos de vencimento',
            'prioridade' => 'media',
            'acoes' => ['Configurar alertas por email', 'SMS de lembrete', 'Desconto por pontualidade']
        ];
    }
    
    // Recomendações de dados
    if (empty($aluno['city']) || !filter_var($aluno['email'], FILTER_VALIDATE_EMAIL)) {
        $recomendacoes[] = [
            'categoria' => 'dados',
            'titulo' => 'Atualizar Informações',
            'descricao' => 'Solicitar atualização de dados pessoais incompletos',
            'prioridade' => 'baixa',
            'acoes' => ['Enviar formulário de atualização', 'Ligar para confirmar dados']
        ];
    }
    
    return $recomendacoes;
}

/**
 * Busca estatísticas comparativas (aluno vs média da turma/polo)
 */
function obterEstatisticasComparativas($aluno, $estatisticas) {
    try {
        $db = (new Database())->getConnection();
        
        // Média do polo
        $stmtPolo = $db->prepare("
            SELECT 
                AVG(sub.total_boletos) as media_boletos_polo,
                AVG(sub.percentual_pagos) as media_pagamentos_polo,
                AVG(sub.valor_total) as media_valor_polo
            FROM (
                SELECT 
                    a.id,
                    COUNT(b.id) as total_boletos,
                    CASE 
                        WHEN COUNT(b.id) > 0 
                        THEN (COUNT(CASE WHEN b.status = 'pago' THEN 1 END) * 100.0 / COUNT(b.id))
                        ELSE 0 
                    END as percentual_pagos,
                    COALESCE(SUM(b.valor), 0) as valor_total
                FROM alunos a
                LEFT JOIN boletos b ON a.id = b.aluno_id
                WHERE a.subdomain = ?
                GROUP BY a.id
            ) sub
        ");
        $stmtPolo->execute([$aluno['subdomain']]);
        $mediaPolo = $stmtPolo->fetch(PDO::FETCH_ASSOC);
        
        // Posição do aluno no ranking do polo
        $stmtRanking = $db->prepare("
            SELECT COUNT(*) + 1 as posicao_polo
            FROM (
                SELECT 
                    a.id,
                    COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos
                FROM alunos a
                LEFT JOIN boletos b ON a.id = b.aluno_id
                WHERE a.subdomain = ?
                GROUP BY a.id
                HAVING boletos_pagos > ?
            ) ranking
        ");
        $stmtRanking->execute([$aluno['subdomain'], intval($estatisticas['boletos_pagos'])]);
        $ranking = $stmtRanking->fetch(PDO::FETCH_ASSOC);
        
        return [
            'media_boletos_polo' => round(floatval($mediaPolo['media_boletos_polo'] ?? 0), 1),
            'media_pagamentos_polo' => round(floatval($mediaPolo['media_pagamentos_polo'] ?? 0), 1),
            'media_valor_polo' => round(floatval($mediaPolo['media_valor_polo'] ?? 0), 2),
            'posicao_ranking_polo' => intval($ranking['posicao_polo'] ?? 0)
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao calcular estatísticas comparativas: " . $e->getMessage());
        return [];
    }
}

/**
 * Função principal para enriquecer dados (se solicitado via parâmetro)
 */
function enriquecerDados($aluno, $estatisticas, $boletos) {
    $dadosEnriquecidos = [];
    
    // Verifica se deve buscar dados do Moodle
    if (isset($_GET['incluir_moodle']) && $_GET['incluir_moodle'] === 'true') {
        $dadosEnriquecidos['moodle'] = obterInformacoesMoodle($aluno);
    }
    
    // Verifica se deve calcular métricas avançadas
    if (isset($_GET['incluir_metricas']) && $_GET['incluir_metricas'] === 'true') {
        $dadosEnriquecidos['metricas_avancadas'] = calcularMetricasAvancadas($aluno, $estatisticas, $boletos);
    }
    
    // Verifica se deve gerar recomendações
    if (isset($_GET['incluir_recomendacoes']) && $_GET['incluir_recomendacoes'] === 'true') {
        $ativo = $aluno['ultimo_acesso'] && 
                 (time() - strtotime($aluno['ultimo_acesso'])) < (30 * 24 * 60 * 60);
        
        $metricas = $dadosEnriquecidos['metricas_avancadas'] ?? [];
        $dadosEnriquecidos['recomendacoes'] = gerarRecomendacoes($aluno, $estatisticas, $ativo, $metricas);
    }
    
    // Verifica se deve incluir comparações
    if (isset($_GET['incluir_comparacoes']) && $_GET['incluir_comparacoes'] === 'true') {
        $dadosEnriquecidos['comparacoes'] = obterEstatisticasComparativas($aluno, $estatisticas);
    }
    
    return $dadosEnriquecidos;
}

/**
 * Função para validar permissões específicas do administrador
 */
function validarPermissoesAdmin($adminId, $alunoId) {
    try {
        $db = (new Database())->getConnection();
        
        // Busca nível do administrador
        $stmt = $db->prepare("SELECT nivel FROM administradores WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            return false;
        }
        
        // Super admins podem ver qualquer aluno
        if (in_array($admin['nivel'], ['super_admin', 'master'])) {
            return true;
        }
        
        // Admins normais podem ver alunos (implementar restrições específicas se necessário)
        return true;
        
    } catch (Exception $e) {
        error_log("Erro ao validar permissões: " . $e->getMessage());
        return false;
    }
}

/**
 * Função para logging detalhado de ações administrativas
 */
function logAcaoAdministrativa($acao, $alunoId, $detalhes = []) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs_administrativos (
                admin_id, acao, aluno_id, detalhes, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $_SESSION['admin_id'],
            $acao,
            $alunoId,
            json_encode($detalhes),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (Exception $e) {
        // Tabela pode não existir, continua normalmente
        error_log("Log administrativo não pôde ser salvo: " . $e->getMessage());
    }
}

/**
 * Função de cache para otimizar consultas frequentes
 */
function obterDadosCache($chave) {
    $cacheFile = sys_get_temp_dir() . '/IMEPEDU_cache_' . md5($chave) . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) { // 5 minutos
        return json_decode(file_get_contents($cacheFile), true);
    }
    
    return null;
}

function salvarDadosCache($chave, $dados) {
    $cacheFile = sys_get_temp_dir() . '/IMEPEDU_cache_' . md5($chave) . '.json';
    file_put_contents($cacheFile, json_encode($dados));
}

// Log da execução para debug
error_log("API Detalhes Aluno executada com sucesso - Timestamp: " . date('Y-m-d H:i:s'));
?>