<?php
/**
* Sistema de Boletos IMED - API para Detalhes do Aluno (ADMINISTRAÇÃO)
 * Arquivo: admin/api/aluno-detalhes.php
 * 
 * API específica para administradores visualizarem detalhes completos dos alunos
 * VERSÃO COMPLETA COM INTEGRAÇÃO DE DOCUMENTOS - CORRIGIDA
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
    
    // 🔧 CORREÇÃO 1: Verifica se DocumentosService existe antes de usar
    $documentosServiceExiste = false;
    $documentosServicePath = __DIR__ . '/../../src/DocumentosService.php';
    
    if (file_exists($documentosServicePath)) {
        require_once $documentosServicePath;
        if (class_exists('DocumentosService')) {
            $documentosServiceExiste = true;
            error_log("Admin API: DocumentosService carregado com sucesso");
        }
    }
    
    if (!$documentosServiceExiste) {
        error_log("Admin API: DocumentosService não encontrado em: $documentosServicePath");
    }
    
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
    
    // 🔧 CORREÇÃO 2: Busca documentos com tratamento de erro
    $documentosAluno = [];
    $statusDocumentos = [];
    $tiposDocumentos = [];
    $documentosHtml = '';
    
    if ($documentosServiceExiste) {
        try {
            $documentosService = new DocumentosService();
            $documentosAluno = $documentosService->listarDocumentosAluno($alunoId);
            $statusDocumentos = $documentosService->getStatusDocumentosAluno($alunoId);
            $tiposDocumentos = DocumentosService::getTiposDocumentos();
            
            error_log("Admin API: Documentos carregados - " . count($documentosAluno) . " documentos, " . count($tiposDocumentos) . " tipos");
            
            // Gera HTML dos documentos
            $documentosHtml = gerarHtmlDocumentosAdmin($documentosAluno, $statusDocumentos, $tiposDocumentos, $alunoId);
            
        } catch (Exception $e) {
            error_log("Admin API: Erro ao buscar documentos: " . $e->getMessage());
            $documentosHtml = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Erro ao carregar documentos: ' . $e->getMessage() . '</div>';
        }
    } else {
        $documentosHtml = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Sistema de documentos não configurado. Execute setup-documentos.php para configurar.</div>';
    }
    
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
    
    // 🔧 CORREÇÃO 3: Monta resposta completa com documentos
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
            'observacoes' => $aluno['observacoes'] ?? null,
            'documentos_completos' => $aluno['documentos_completos'] ?? false,
            'documentos_data_atualizacao' => $aluno['documentos_data_atualizacao'] ?? null
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
        
        // 🔧 CORREÇÃO 4: Seção de documentos SEMPRE presente
        'documentos' => [
            'ativo' => $documentosServiceExiste,
            'enviados' => $documentosAluno,
            'status' => $statusDocumentos,
            'tipos' => $tiposDocumentos,
            'html' => $documentosHtml,
            'total_enviados' => count($documentosAluno),
            'total_tipos' => count($tiposDocumentos)
        ],
        
        'resumo' => [
            'situacao_geral' => getSituacaoGeral($aluno, $estatisticas, $ativo),
            'proximas_acoes' => getProximasAcoes($aluno, $estatisticas, $ativo),
            'alertas' => getAlertas($aluno, $estatisticas, $ativo, $temBoletosVencidos, $statusDocumentos)
        ],
        
        'acoes_disponiveis' => getAcoesDisponiveisAdmin($aluno, $estatisticas),
        
        'meta' => [
            'gerado_em' => date('Y-m-d H:i:s'),
            'admin_id' => $_SESSION['admin_id'],
            'versao_api' => '2.1',
            'documentos_service_ativo' => $documentosServiceExiste
        ]
    ];
    
    // Registra acesso aos detalhes
    registrarLogAcessoDetalhes($alunoId);
    
    error_log("Admin API: Resposta montada com sucesso - " . strlen(json_encode($response)) . " bytes");
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API Detalhes Aluno: ERRO - " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
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
        'timestamp' => date('Y-m-d H:i:s'),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'admin_id' => $_SESSION['admin_id'] ?? 'null'
        ]
    ], JSON_UNESCAPED_UNICODE);
}

// =====================================
// 🔧 CORREÇÃO 5: FUNÇÃO PARA GERAR HTML DOS DOCUMENTOS
// =====================================

/**
 * Gera HTML para exibir documentos no painel admin
 */
function gerarHtmlDocumentosAdmin($documentos, $status, $tiposDocumentos, $alunoId) {
    error_log("Gerando HTML documentos para aluno $alunoId - " . count($documentos) . " documentos");
    
    $html = '';
    
    // Cabeçalho da seção
    $html .= '
    <!-- Documentos do Aluno -->
    <div class="row mb-4">
        <div class="col-12">
            <h6 class="text-primary mb-3">
                <i class="fas fa-folder-open"></i> Documentos do Aluno
                <small class="text-muted">(' . count($documentos) . ' enviados)</small>
            </h6>';
    
    // Status geral (se houver tipos de documentos configurados)
    if (!empty($tiposDocumentos)) {
        $enviados = count($documentos);
        $totalTipos = count($tiposDocumentos);
        $aprovados = 0;
        $pendentes = 0;
        $rejeitados = 0;
        
        foreach ($documentos as $doc) {
            switch ($doc['status']) {
                case 'aprovado': $aprovados++; break;
                case 'rejeitado': $rejeitados++; break;
                default: $pendentes++; break;
            }
        }
        
        $percentual = $totalTipos > 0 ? round(($enviados / $totalTipos) * 100) : 0;
        
        $html .= '
            <div class="card bg-light mb-3">
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-3">
                            <h6 class="mb-1 text-primary">' . $enviados . '</h6>
                            <small class="text-muted">Enviados</small>
                        </div>
                        <div class="col-3">
                            <h6 class="mb-1 text-success">' . $aprovados . '</h6>
                            <small class="text-muted">Aprovados</small>
                        </div>
                        <div class="col-3">
                            <h6 class="mb-1 text-warning">' . $pendentes . '</h6>
                            <small class="text-muted">Pendentes</small>
                        </div>
                        <div class="col-3">
                            <h6 class="mb-1 text-danger">' . $rejeitados . '</h6>
                            <small class="text-muted">Rejeitados</small>
                        </div>
                    </div>
                    <div class="progress mt-2" style="height: 6px;">
                        <div class="progress-bar bg-success" style="width: ' . $percentual . '%"></div>
                    </div>
                    <small class="text-muted d-block text-center mt-1">
                        ' . $percentual . '% dos tipos de documentos enviados
                    </small>
                </div>
            </div>';
    }
    
    // Lista de documentos
    if (!empty($documentos)) {
        $html .= '<div class="row">';
        
        foreach ($documentos as $tipo => $documento) {
            $tipoInfo = $tiposDocumentos[$tipo] ?? ['nome' => $tipo, 'icone' => 'fas fa-file'];
            
            $statusColor = [
                'pendente' => 'warning',
                'aprovado' => 'success', 
                'rejeitado' => 'danger'
            ][$documento['status']] ?? 'secondary';
            
            $statusIcon = [
                'pendente' => 'fas fa-clock',
                'aprovado' => 'fas fa-check',
                'rejeitado' => 'fas fa-times'
            ][$documento['status']] ?? 'fas fa-question';
            
            $tamanhoFormatado = isset($documento['tamanho_formatado']) ? 
                $documento['tamanho_formatado'] : 
                formatarTamanhoArquivo($documento['tamanho_arquivo'] ?? 0);
            
            $html .= '
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start">
                            <div class="me-3">
                                <div class="bg-' . $statusColor . ' text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="' . $tipoInfo['icone'] . '"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="card-title mb-1">' . htmlspecialchars($tipoInfo['nome']) . '</h6>
                                <p class="card-text small text-muted mb-2">' . htmlspecialchars($documento['nome_original']) . '</p>
                                
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge bg-' . $statusColor . '">
                                        <i class="' . $statusIcon . ' me-1"></i>' . ucfirst($documento['status']) . '
                                    </span>
                                    <small class="text-muted">' . $tamanhoFormatado . '</small>
                                </div>
                                
                                <small class="text-muted d-block">
                                    Enviado: ' . date('d/m/Y H:i', strtotime($documento['data_upload'])) . '
                                </small>
                                
                                ' . (!empty($documento['observacoes']) ? '
                                <div class="alert alert-info p-2 mt-2">
                                    <small><strong>Obs:</strong> ' . htmlspecialchars($documento['observacoes']) . '</small>
                                </div>
                                ' : '') . '
                                
                                <div class="btn-group w-100 mt-2" role="group">
                                    <button class="btn btn-outline-primary btn-sm" onclick="downloadDocumentoAdmin(' . $documento['id'] . ')" title="Download">
                                        <i class="fas fa-download"></i>
                                    </button>
                                    
                                    ' . ($documento['status'] === 'pendente' ? '
                                    <button class="btn btn-outline-success btn-sm" onclick="aprovarDocumento(' . $documento['id'] . ')" title="Aprovar">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="rejeitarDocumento(' . $documento['id'] . ')" title="Rejeitar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    ' : '') . '
                                    
                                    <button class="btn btn-outline-secondary btn-sm" onclick="removerDocumento(' . $documento['id'] . ')" title="Remover">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
        }
        
        $html .= '</div>';
    } else {
        // Nenhum documento enviado
        $html .= '
        <div class="alert alert-info">
            <h6><i class="fas fa-info-circle"></i> Nenhum documento enviado</h6>
            <p class="mb-0">Este aluno ainda não enviou nenhum documento.</p>
        </div>';
    }
    
    // Documentos faltando (se houver tipos configurados)
    if (!empty($tiposDocumentos)) {
        $documentosFaltando = [];
        foreach ($tiposDocumentos as $tipo => $info) {
            if (!isset($documentos[$tipo]) && ($info['obrigatorio'] ?? false)) {
                $documentosFaltando[] = $info['nome'];
            }
        }
        
        if (!empty($documentosFaltando)) {
            $html .= '
            <div class="alert alert-warning">
                <h6><i class="fas fa-exclamation-triangle"></i> Documentos Obrigatórios Pendentes:</h6>
                <ul class="mb-0">
                    ' . implode('', array_map(function($doc) { return '<li>' . htmlspecialchars($doc) . '</li>'; }, $documentosFaltando)) . '
                </ul>
            </div>';
        }
    }
    
    // Ações para documentos
    $html .= '
        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
            <button class="btn btn-outline-primary btn-sm" onclick="window.open(\'/admin/documentos.php?aluno_id=' . $alunoId . '\', \'_blank\')">
                <i class="fas fa-external-link-alt"></i> Gerenciar Documentos
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="notificarAluno(' . $alunoId . ', \'documentos\')">
                <i class="fas fa-bell"></i> Notificar Aluno
            </button>
        </div>';
    
    $html .= '
        </div>
    </div>';
    
    error_log("HTML gerado: " . strlen($html) . " caracteres");
    return $html;
}

/**
 * Formatar tamanho do arquivo
 */
function formatarTamanhoArquivo($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}

// =====================================
// FUNÇÕES AUXILIARES (MANTIDAS DO ORIGINAL)
// =====================================

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
        'detalhes_visualizado' => 'Detalhes Visualizados',
        'documento_upload' => 'Documento Enviado',
        'documento_aprovado' => 'Documento Aprovado',
        'documento_rejeitado' => 'Documento Rejeitado',
        'documento_removido' => 'Documento Removido',
        'documento_download' => 'Download de Documento'
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
    
    $totalBoletos = intval($estatisticas['total_boletos']);
    if ($totalBoletos == 0) {
        $acoes[] = [
            'acao' => 'gerar_boletos',
            'titulo' => 'Gerar Boletos',
            'descricao' => 'Aluno sem boletos cadastrados',
            'prioridade' => 'media'
        ];
    }
    
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
 * Obtém alertas importantes (CORRIGIDA para incluir documentos)
 */
function getAlertas($aluno, $estatisticas, $ativo, $temBoletosVencidos, $statusDocumentos = []) {
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
    
    if (empty($aluno['email']) || !filter_var($aluno['email'], FILTER_VALIDATE_EMAIL)) {
        $alertas[] = [
            'tipo' => 'dados_incompletos',
            'titulo' => 'Email Inválido',
            'mensagem' => 'Email não está preenchido corretamente',
            'severidade' => 'info'
        ];
    }
    
    if (!validarCPF($aluno['cpf'])) {
        $alertas[] = [
            'tipo' => 'dados_invalidos',
            'titulo' => 'CPF Inválido',
            'mensagem' => 'CPF não é válido',
            'severidade' => 'warning'
        ];
    }
    
    // Alertas para documentos
    if (!empty($statusDocumentos)) {
        if (($statusDocumentos['percentual_completo'] ?? 0) < 100) {
            $faltando = count($statusDocumentos['faltando'] ?? []);
            $alertas[] = [
                'tipo' => 'documentos_incompletos',
                'titulo' => 'Documentos Incompletos',
                'mensagem' => "Faltam {$faltando} documento(s) obrigatório(s)",
                'severidade' => 'warning'
            ];
        }
        
        if (($statusDocumentos['rejeitados'] ?? 0) > 0) {
            $alertas[] = [
                'tipo' => 'documentos_rejeitados',
                'titulo' => 'Documentos Rejeitados',
                'mensagem' => "{$statusDocumentos['rejeitados']} documento(s) rejeitado(s) - necessário reenvio",
                'severidade' => 'danger'
            ];
        }
    }
    
    return $alertas;
}

/**
 * Obtém ações disponíveis para administradores
 */
function getAcoesDisponiveisAdmin($aluno, $estatisticas) {
    $acoes = [];
    
    $acoes[] = [
        'tipo' => 'sincronizar',
        'label' => 'Sincronizar com Moodle',
        'icone' => 'fas fa-sync-alt',
        'classe' => 'btn-info'
    ];
    
    $acoes[] = [
        'tipo' => 'editar',
        'label' => 'Editar Dados',
        'icone' => 'fas fa-edit',
        'classe' => 'btn-secondary'
    ];
    
    $acoes[] = [
        'tipo' => 'ver_boletos',
        'label' => 'Ver Todos os Boletos',
        'icone' => 'fas fa-file-invoice-dollar',
        'classe' => 'btn-primary'
    ];
    
    $acoes[] = [
        'tipo' => 'criar_boleto',
        'label' => 'Criar Novo Boleto',
        'icone' => 'fas fa-plus',
        'classe' => 'btn-success'
    ];
    
    $acoes[] = [
        'tipo' => 'ver_documentos',
        'label' => 'Gerenciar Documentos',
        'icone' => 'fas fa-folder-open',
        'classe' => 'btn-warning'
    ];
    
    $acoes[] = [
        'tipo' => 'exportar',
        'label' => 'Exportar Dados',
        'icone' => 'fas fa-download',
        'classe' => 'btn-outline-secondary'
    ];
    
    $boletosVencidos = intval($estatisticas['boletos_vencidos']);
    if ($boletosVencidos > 0) {
        $acoes[] = [
            'tipo' => 'cobrar',
            'label' => 'Enviar Cobrança',
            'icone' => 'fas fa-envelope',
            'classe' => 'btn-danger'
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
            null,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log de acesso aos detalhes: " . $e->getMessage());
    }
}

// Log da execução para debug
error_log("API Detalhes Aluno executada com sucesso - Timestamp: " . date('Y-m-d H:i:s'));

?>