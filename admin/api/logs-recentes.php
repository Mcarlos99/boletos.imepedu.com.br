<?php
/**
 * Sistema de Boletos IMEPEDU - API para Logs Recentes
 * Arquivo: admin/api/logs-recentes.php
 * 
 * API para fornecer logs recentes do sistema para a página de configurações
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
    
    // Parâmetros de entrada
    $limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT) ?: 10;
    $offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT) ?: 0;
    $tipo = filter_input(INPUT_GET, 'tipo', FILTER_SANITIZE_STRING);
    $admin_id = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
    $periodo = filter_input(INPUT_GET, 'periodo', FILTER_SANITIZE_STRING) ?: '24h';
    
    // Valida limite
    if ($limite < 1 || $limite > 100) {
        $limite = 10;
    }
    
    error_log("API Logs Recentes: Admin {$_SESSION['admin_id']} - Limite: {$limite}, Período: {$periodo}");
    
    $db = (new Database())->getConnection();
    
    // Monta condições WHERE
    $where = ['1=1'];
    $params = [];
    
    // Filtro por período
    switch ($periodo) {
        case '1h':
            $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            break;
        case '6h':
            $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)";
            break;
        case '24h':
            $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            break;
        case '7d':
            $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case '30d':
            $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
    }
    
    // Filtro por tipo de log
    if (!empty($tipo)) {
        $where[] = "l.tipo = ?";
        $params[] = $tipo;
    }
    
    // Filtro por admin específico
    if (!empty($admin_id)) {
        $where[] = "l.usuario_id = ?";
        $params[] = $admin_id;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Busca logs recentes com informações relacionadas
    $stmt = $db->prepare("
        SELECT 
            l.id,
            l.tipo,
            l.descricao,
            l.created_at,
            l.ip_address,
            l.user_agent,
            -- Informações do admin
            a.nome as admin_nome,
            a.login as admin_login,
            -- Informações do aluno (se aplicável)
            al.nome as aluno_nome,
            al.cpf as aluno_cpf,
            -- Informações do boleto (se aplicável)
            b.numero_boleto,
            b.valor as boleto_valor,
            b.status as boleto_status,
            -- Informações do curso (se aplicável)
            c.nome as curso_nome,
            c.subdomain as curso_polo
        FROM logs l
        LEFT JOIN administradores a ON l.usuario_id = a.id
        LEFT JOIN boletos b ON l.boleto_id = b.id
        LEFT JOIN alunos al ON b.aluno_id = al.id
        LEFT JOIN cursos c ON b.curso_id = c.id
        WHERE {$whereClause}
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $params[] = $limite;
    $params[] = $offset;
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Conta total de logs no período
    $stmtCount = $db->prepare("
        SELECT COUNT(*) as total
        FROM logs l
        WHERE {$whereClause}
    ");
    
    $paramsCount = array_slice($params, 0, -2); // Remove LIMIT e OFFSET
    $stmtCount->execute($paramsCount);
    $total = $stmtCount->fetch()['total'];
    
    // Busca estatísticas por tipo
    $stmtStats = $db->prepare("
        SELECT 
            l.tipo,
            COUNT(*) as quantidade,
            MAX(l.created_at) as ultimo_registro
        FROM logs l
        WHERE {$whereClause}
        GROUP BY l.tipo
        ORDER BY quantidade DESC
    ");
    $stmtStats->execute($paramsCount);
    $estatisticasTipo = $stmtStats->fetchAll(PDO::FETCH_ASSOC);
    
    // Processa logs para resposta
    $logsFormatados = [];
    foreach ($logs as $log) {
        $logFormatado = [
            'id' => intval($log['id']),
            'tipo' => $log['tipo'],
            'tipo_label' => getTipoLogLabel($log['tipo']),
            'descricao' => $log['descricao'],
            'created_at' => $log['created_at'],
            'created_at_formatado' => date('d/m/Y H:i:s', strtotime($log['created_at'])),
            'created_at_relativo' => getTempoRelativo($log['created_at']),
            'ip_address' => $log['ip_address'] ? preg_replace('/\.\d+$/', '.***', $log['ip_address']) : null,
            'severidade' => getSeveridadeLog($log['tipo']),
            'icone' => getIconeLog($log['tipo']),
            'cor_badge' => getCorBadgeLog($log['tipo'])
        ];
        
        // Adiciona informações contextuais
        if (!empty($log['admin_nome'])) {
            $logFormatado['admin'] = [
                'nome' => $log['admin_nome'],
                'login' => $log['admin_login']
            ];
        }
        
        if (!empty($log['aluno_nome'])) {
            $logFormatado['aluno'] = [
                'nome' => $log['aluno_nome'],
                'cpf' => $log['aluno_cpf']
            ];
        }
        
        if (!empty($log['numero_boleto'])) {
            $logFormatado['boleto'] = [
                'numero' => $log['numero_boleto'],
                'valor' => floatval($log['boleto_valor']),
                'valor_formatado' => 'R$ ' . number_format($log['boleto_valor'], 2, ',', '.'),
                'status' => $log['boleto_status']
            ];
        }
        
        if (!empty($log['curso_nome'])) {
            $logFormatado['curso'] = [
                'nome' => $log['curso_nome'],
                'polo' => $log['curso_polo']
            ];
        }
        
        $logsFormatados[] = $logFormatado;
    }
    
    // Processa estatísticas por tipo
    $estatisticasFormatadas = [];
    foreach ($estatisticasTipo as $stat) {
        $estatisticasFormatadas[] = [
            'tipo' => $stat['tipo'],
            'tipo_label' => getTipoLogLabel($stat['tipo']),
            'quantidade' => intval($stat['quantidade']),
            'ultimo_registro' => $stat['ultimo_registro'],
            'ultimo_registro_formatado' => date('d/m/Y H:i:s', strtotime($stat['ultimo_registro'])),
            'percentual' => $total > 0 ? round((intval($stat['quantidade']) / $total) * 100, 1) : 0
        ];
    }
    
    // Busca atividade por hora (últimas 24h)
    $stmtAtividade = $db->prepare("
        SELECT 
            HOUR(l.created_at) as hora,
            COUNT(*) as quantidade
        FROM logs l
        WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY HOUR(l.created_at)
        ORDER BY hora
    ");
    $stmtAtividade->execute();
    $atividadePorHora = $stmtAtividade->fetchAll(PDO::FETCH_ASSOC);
    
    // Registra acesso aos logs
    registrarAcessoLogs($_SESSION['admin_id'], $limite);
    
    // Monta resposta
    $response = [
        'success' => true,
        'logs' => $logsFormatados,
        'total' => intval($total),
        'limite' => $limite,
        'offset' => $offset,
        'periodo' => $periodo,
        'estatisticas_tipo' => $estatisticasFormatadas,
        'atividade_por_hora' => $atividadePorHora,
        'resumo' => [
            'total_logs_periodo' => intval($total),
            'tipos_diferentes' => count($estatisticasTipo),
            'log_mais_recente' => !empty($logs) ? $logs[0]['created_at'] : null,
            'log_mais_antigo' => !empty($logs) ? end($logs)['created_at'] : null
        ],
        'meta' => [
            'gerado_em' => date('Y-m-d H:i:s'),
            'admin_id' => $_SESSION['admin_id'],
            'versao_api' => '1.0'
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API Logs Recentes: ERRO - " . $e->getMessage());
    
    $httpCode = 400;
    
    // Mapeia erros específicos
    if (strpos($e->getMessage(), 'não autorizado') !== false) {
        $httpCode = 403;
    } elseif (strpos($e->getMessage(), 'não encontrado') !== false) {
        $httpCode = 404;
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
 * Obtém label amigável para tipo de log
 */
function getTipoLogLabel($tipo) {
    $labels = [
        // Autenticação
        'login_admin' => 'Login Admin',
        'logout_admin' => 'Logout Admin',
        'login_aluno' => 'Login Aluno',
        'logout_aluno' => 'Logout Aluno',
        
        // Boletos
        'upload_individual' => 'Upload Individual',
        'upload_lote' => 'Upload em Lote',
        'upload_multiplo_aluno' => 'Upload Múltiplo',
        'boleto_pago' => 'Boleto Pago',
        'boleto_pago_admin' => 'Marcado como Pago',
        'boleto_cancelado' => 'Boleto Cancelado',
        'boleto_cancelado_admin' => 'Cancelado pelo Admin',
        'download_boleto' => 'Download Boleto',
        'download_pdf_sucesso' => 'Download PDF',
        'download_pdf_erro' => 'Erro no Download',
        'pix_gerado' => 'PIX Gerado',
        'pix_erro' => 'Erro no PIX',
        'removido' => 'Boleto Removido',
        
        // Sistema
        'sincronizacao_moodle' => 'Sincronização Moodle',
        'aluno_sincronizado' => 'Aluno Sincronizado',
        'curso_sincronizado' => 'Curso Sincronizado',
        'admin_detalhes_aluno' => 'Detalhes Aluno',
        'admin_detalhes_curso' => 'Detalhes Curso',
        'admin_criado' => 'Admin Criado',
        'admin_editado' => 'Admin Editado',
        'senha_alterada' => 'Senha Alterada',
        'senha_resetada' => 'Senha Resetada',
        'admin_status_alterado' => 'Status Admin Alterado',
        
        // Erros
        'erro_sistema' => 'Erro do Sistema',
        'erro_database' => 'Erro de Banco',
        'erro_moodle' => 'Erro Moodle',
        'erro_upload' => 'Erro no Upload',
        'acesso_negado' => 'Acesso Negado',
        
        // Configurações
        'config_alterada' => 'Configuração Alterada',
        'cache_limpo' => 'Cache Limpo',
        'logs_limpos' => 'Logs Limpos',
        'backup_gerado' => 'Backup Gerado'
    ];
    
    return $labels[$tipo] ?? ucwords(str_replace(['_', '-'], ' ', $tipo));
}

/**
 * Obtém severidade do log baseado no tipo
 */
function getSeveridadeLog($tipo) {
    $severidades = [
        // Críticos
        'erro_sistema' => 'critico',
        'erro_database' => 'critico',
        'acesso_negado' => 'critico',
        
        // Alto
        'senha_resetada' => 'alto',
        'admin_criado' => 'alto',
        'boleto_cancelado_admin' => 'alto',
        'removido' => 'alto',
        
        // Médio
        'upload_lote' => 'medio',
        'sincronizacao_moodle' => 'medio',
        'config_alterada' => 'medio',
        'admin_editado' => 'medio',
        
        // Baixo
        'login_admin' => 'baixo',
        'download_boleto' => 'baixo',
        'boleto_pago' => 'baixo',
        'admin_detalhes_aluno' => 'baixo',
        
        // Info
        'logout_admin' => 'info',
        'download_pdf_sucesso' => 'info',
        'pix_gerado' => 'info'
    ];
    
    return $severidades[$tipo] ?? 'info';
}

/**
 * Obtém ícone FontAwesome para o tipo de log
 */
function getIconeLog($tipo) {
    $icones = [
        // Autenticação
        'login_admin' => 'fas fa-sign-in-alt',
        'logout_admin' => 'fas fa-sign-out-alt',
        'login_aluno' => 'fas fa-user-check',
        'logout_aluno' => 'fas fa-user-times',
        
        // Boletos
        'upload_individual' => 'fas fa-upload',
        'upload_lote' => 'fas fa-file-upload',
        'upload_multiplo_aluno' => 'fas fa-layer-group',
        'boleto_pago' => 'fas fa-check-circle',
        'boleto_cancelado' => 'fas fa-times-circle',
        'download_boleto' => 'fas fa-download',
        'download_pdf_sucesso' => 'fas fa-file-pdf',
        'download_pdf_erro' => 'fas fa-exclamation-triangle',
        'pix_gerado' => 'fas fa-qrcode',
        'removido' => 'fas fa-trash',
        
        // Sistema
        'sincronizacao_moodle' => 'fas fa-sync-alt',
        'aluno_sincronizado' => 'fas fa-user-sync',
        'admin_criado' => 'fas fa-user-plus',
        'admin_editado' => 'fas fa-user-edit',
        'senha_alterada' => 'fas fa-key',
        'config_alterada' => 'fas fa-cog',
        'cache_limpo' => 'fas fa-broom',
        'backup_gerado' => 'fas fa-database',
        
        // Erros
        'erro_sistema' => 'fas fa-exclamation-circle',
        'erro_database' => 'fas fa-database',
        'erro_moodle' => 'fas fa-exclamation-triangle',
        'acesso_negado' => 'fas fa-ban'
    ];
    
    return $icones[$tipo] ?? 'fas fa-info-circle';
}

/**
 * Obtém cor do badge para o tipo de log
 */
function getCorBadgeLog($tipo) {
    $severidade = getSeveridadeLog($tipo);
    
    $cores = [
        'critico' => 'danger',
        'alto' => 'warning',
        'medio' => 'info',
        'baixo' => 'secondary',
        'info' => 'light'
    ];
    
    return $cores[$severidade] ?? 'secondary';
}

/**
 * Calcula tempo relativo (ex: "há 2 horas")
 */
function getTempoRelativo($datetime) {
    $agora = time();
    $tempo = strtotime($datetime);
    $diff = $agora - $tempo;
    
    if ($diff < 60) {
        return 'agora mesmo';
    } elseif ($diff < 3600) {
        $minutos = floor($diff / 60);
        return "há {$minutos} min";
    } elseif ($diff < 86400) {
        $horas = floor($diff / 3600);
        return "há {$horas}h";
    } elseif ($diff < 2592000) {
        $dias = floor($diff / 86400);
        return "há {$dias} dia" . ($dias > 1 ? 's' : '');
    } else {
        $meses = floor($diff / 2592000);
        return "há {$meses} mês" . ($meses > 1 ? 'es' : '');
    }
}

/**
 * Registra acesso aos logs para auditoria
 */
function registrarAcessoLogs($adminId, $limite) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "Consulta de logs recentes (limite: {$limite})";
        
        $stmt->execute([
            'consulta_logs',
            $adminId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        // Ignora erros de log para não criar loop infinito
        error_log("Erro ao registrar acesso aos logs: " . $e->getMessage());
    }
}

/**
 * Obtém estatísticas de sistema para contexto
 */
function obterEstatisticasContexto() {
    try {
        $db = (new Database())->getConnection();
        
        // Conta logs por período
        $stmt = $db->query("
            SELECT 
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 END) as ultima_hora,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as ultimo_dia,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as ultima_semana,
                COUNT(*) as total
            FROM logs
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return [
            'ultima_hora' => 0,
            'ultimo_dia' => 0,
            'ultima_semana' => 0,
            'total' => 0
        ];
    }
}

/**
 * Detecta possíveis problemas nos logs
 */
function detectarProblemasLogs($logs) {
    $problemas = [];
    
    // Conta tipos de erro
    $erros = 0;
    $acessosNegados = 0;
    $tentativasLogin = 0;
    
    foreach ($logs as $log) {
        if (strpos($log['tipo'], 'erro_') === 0) {
            $erros++;
        }
        if ($log['tipo'] === 'acesso_negado') {
            $acessosNegados++;
        }
        if ($log['tipo'] === 'login_admin') {
            $tentativasLogin++;
        }
    }
    
    // Verifica se há muitos erros
    if ($erros > 5) {
        $problemas[] = [
            'tipo' => 'muitos_erros',
            'descricao' => "Detectados {$erros} erros recentes",
            'severidade' => 'alto'
        ];
    }
    
    // Verifica acessos negados
    if ($acessosNegados > 3) {
        $problemas[] = [
            'tipo' => 'acessos_negados',
            'descricao' => "Detectados {$acessosNegados} acessos negados",
            'severidade' => 'medio'
        ];
    }
    
    // Verifica atividade suspeita de login
    if ($tentativasLogin > 10) {
        $problemas[] = [
            'tipo' => 'muitos_logins',
            'descricao' => "Detectadas {$tentativasLogin} tentativas de login",
            'severidade' => 'medio'
        ];
    }
    
    return $problemas;
}

// Log da execução para debug
error_log("API Logs Recentes executada com sucesso - Timestamp: " . date('Y-m-d H:i:s'));
?>