<?php
/**
 * Sistema de Boletos IMEPEDU - API para Detalhes do Boleto (ALUNOS)
 * Arquivo: api/boleto-detalhes.php
 * 
 * Versão específica para alunos logados (não administradores)
 */

session_start();

// Headers de segurança e CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verifica se aluno está logado
if (!isset($_SESSION['aluno_cpf'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Usuário não autenticado'
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
    require_once '../config/database.php';
    
    // Obtém ID do boleto
    $boletoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório e deve ser um número válido');
    }
    
    error_log("API Detalhes Aluno: Buscando boleto ID: {$boletoId} para CPF: " . $_SESSION['aluno_cpf']);
    
    $db = (new Database())->getConnection();
    
    // Busca detalhes do boleto com validação de propriedade
    $stmt = $db->prepare("
        SELECT 
            b.*,
            a.nome as aluno_nome,
            a.cpf as aluno_cpf,
            a.email as aluno_email,
            a.city as aluno_cidade,
            a.country as aluno_pais,
            a.created_at as aluno_cadastro,
            c.nome as curso_nome,
            c.nome_curto as curso_nome_curto,
            c.subdomain as curso_subdomain,
            c.valor as curso_valor,
            -- Logs relacionados (apenas downloads e pix do próprio aluno)
            (SELECT COUNT(*) FROM logs WHERE boleto_id = b.id AND tipo LIKE 'download_%') as total_downloads,
            (SELECT MAX(created_at) FROM logs WHERE boleto_id = b.id AND tipo LIKE 'download_%') as ultimo_download,
            (SELECT COUNT(*) FROM logs WHERE boleto_id = b.id AND tipo LIKE 'pix_%') as total_pix_gerados
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.id = ? 
        AND a.cpf = ?
        AND c.subdomain = ?
    ");
    
    $cpfLimpo = preg_replace('/[^0-9]/', '', $_SESSION['aluno_cpf']);
    $stmt->execute([$boletoId, $cpfLimpo, $_SESSION['subdomain']]);
    $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$boleto) {
        throw new Exception('Boleto não encontrado ou você não tem permissão para acessá-lo');
    }
    
    error_log("API Detalhes Aluno: Boleto encontrado - #{$boleto['numero_boleto']}");
    
    // Calcula informações extras
    $hoje = new DateTime();
    $vencimento = new DateTime($boleto['vencimento']);
    $diasVencimento = $hoje->diff($vencimento)->format('%r%a');
    
    // Status de vencimento
    $statusVencimento = '';
    if ($boleto['status'] == 'pago') {
        $dataPagamento = $boleto['data_pagamento'] ? new DateTime($boleto['data_pagamento']) : $vencimento;
        $statusVencimento = 'Pago em ' . $dataPagamento->format('d/m/Y');
    } elseif ($diasVencimento < 0) {
        $statusVencimento = 'Vencido há ' . abs($diasVencimento) . ' dias';
    } elseif ($diasVencimento == 0) {
        $statusVencimento = 'Vence hoje';
    } else {
        $statusVencimento = 'Vence em ' . $diasVencimento . ' dias';
    }
    
    // Informações do arquivo
    $infoArquivo = null;
    if (!empty($boleto['arquivo_pdf'])) {
        $caminhoArquivo = __DIR__ . '/../uploads/boletos/' . $boleto['arquivo_pdf'];
        if (file_exists($caminhoArquivo)) {
            $infoArquivo = [
                'existe' => true,
                'tamanho' => filesize($caminhoArquivo),
                'tamanho_formatado' => formatarTamanho(filesize($caminhoArquivo)),
                'data_modificacao' => date('d/m/Y H:i:s', filemtime($caminhoArquivo)),
                'tipo_mime' => mime_content_type($caminhoArquivo)
            ];
        } else {
            $infoArquivo = [
                'existe' => false,
                'erro' => 'Arquivo não encontrado no servidor'
            ];
        }
    }
    
    // Busca histórico simplificado (apenas ações do próprio aluno)
    $stmtHistorico = $db->prepare("
        SELECT 
            l.tipo,
            l.descricao,
            l.created_at,
            l.ip_address
        FROM logs l
        WHERE l.boleto_id = ?
        AND (l.tipo LIKE 'download_%' OR l.tipo LIKE 'pix_%')
        ORDER BY l.created_at DESC
        LIMIT 10
    ");
    $stmtHistorico->execute([$boletoId]);
    $historico = $stmtHistorico->fetchAll(PDO::FETCH_ASSOC);
    
    // Monta resposta
    $response = [
        'success' => true,
        'boleto' => [
            // Informações básicas
            'id' => (int)$boleto['id'],
            'numero_boleto' => $boleto['numero_boleto'],
            'valor' => (float)$boleto['valor'],
            'valor_formatado' => 'R$ ' . number_format($boleto['valor'], 2, ',', '.'),
            'vencimento' => $boleto['vencimento'],
            'vencimento_formatado' => $vencimento->format('d/m/Y'),
            'status' => $boleto['status'],
            'status_label' => ucfirst($boleto['status']),
            'descricao' => $boleto['descricao'] ?: 'Sem descrição',
            
            // Status calculado
            'dias_vencimento' => (int)$diasVencimento,
            'status_vencimento' => $statusVencimento,
            'esta_vencido' => ($diasVencimento < 0 && $boleto['status'] != 'pago'),
            
            // Informações de pagamento
            'data_pagamento' => $boleto['data_pagamento'],
            'data_pagamento_formatada' => $boleto['data_pagamento'] ? 
                (new DateTime($boleto['data_pagamento']))->format('d/m/Y') : null,
            'valor_pago' => $boleto['valor_pago'] ? (float)$boleto['valor_pago'] : null,
            'valor_pago_formatado' => $boleto['valor_pago'] ? 
                'R$ ' . number_format($boleto['valor_pago'], 2, ',', '.') : null,
            
            // Datas de controle
            'created_at' => $boleto['created_at'],
            'created_at_formatado' => (new DateTime($boleto['created_at']))->format('d/m/Y H:i:s'),
            'updated_at' => $boleto['updated_at'],
            'updated_at_formatado' => $boleto['updated_at'] ? 
                (new DateTime($boleto['updated_at']))->format('d/m/Y H:i:s') : null,
            
            // Observações (apenas se houver)
            'observacoes' => $boleto['observacoes'] ?: null,
        ],
        
        'aluno' => [
            'nome' => $boleto['aluno_nome'],
            'cpf' => $boleto['aluno_cpf'],
            'cpf_formatado' => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $boleto['aluno_cpf']),
            'email' => $boleto['aluno_email'],
            'cidade' => $boleto['aluno_cidade'],
            'pais' => $boleto['aluno_pais']
        ],
        
        'curso' => [
            'nome' => $boleto['curso_nome'],
            'nome_curto' => $boleto['curso_nome_curto'],
            'subdomain' => $boleto['curso_subdomain'],
            'polo_nome' => getNomePolo($boleto['curso_subdomain'])
        ],
        
        'arquivo' => $infoArquivo,
        
        'estatisticas' => [
            'total_downloads' => (int)$boleto['total_downloads'],
            'ultimo_download' => $boleto['ultimo_download'],
            'ultimo_download_formatado' => $boleto['ultimo_download'] ? 
                (new DateTime($boleto['ultimo_download']))->format('d/m/Y H:i:s') : 'Nunca',
            'total_pix_gerados' => (int)$boleto['total_pix_gerados']
        ],
        
        'historico' => array_map(function($log) {
            return [
                'tipo' => $log['tipo'],
                'tipo_label' => getTipoLabel($log['tipo']),
                'descricao' => $log['descricao'],
                'data' => $log['created_at'],
                'data_formatada' => (new DateTime($log['created_at']))->format('d/m/Y H:i:s'),
                'ip_address' => preg_replace('/\.\d+$/', '.***', $log['ip_address']) // Mascara último octeto
            ];
        }, $historico),
        
        'acoes_disponiveis' => getAcoesDisponiveisAluno($boleto),
        
        'config_polo' => [
            'nome' => getNomePolo($boleto['curso_subdomain']),
            'subdomain' => $boleto['curso_subdomain']
        ]
    ];
    
    // Registra acesso aos detalhes
    registrarLogAcesso($boletoId, 'detalhes_visualizado');
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API Detalhes Aluno: ERRO - " . $e->getMessage());
    
    $httpCode = 400;
    
    // Mapeia erros específicos
    if (strpos($e->getMessage(), 'não encontrado') !== false) {
        $httpCode = 404;
    } elseif (strpos($e->getMessage(), 'não tem permissão') !== false) {
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
 * Função auxiliar para formatar tamanho de arquivo
 */
function formatarTamanho($bytes) {
    $unidades = ['B', 'KB', 'MB', 'GB'];
    $unidade = 0;
    
    while ($bytes >= 1024 && $unidade < count($unidades) - 1) {
        $bytes /= 1024;
        $unidade++;
    }
    
    return round($bytes, 2) . ' ' . $unidades[$unidade];
}

/**
 * Função auxiliar para obter nome do polo
 */
function getNomePolo($subdomain) {
    require_once '../config/moodle.php';
    
    $config = MoodleConfig::getConfig($subdomain);
    return $config['name'] ?? str_replace('.imepedu.com.br', '', $subdomain);
}

/**
 * Função auxiliar para labels dos tipos de log
 */
function getTipoLabel($tipo) {
    $labels = [
        'download_boleto' => 'Download do Boleto',
        'download_pdf_sucesso' => 'Download PDF',
        'download_pdf_erro' => 'Erro no Download',
        'download_pdf_gerado' => 'PDF Gerado',
        'download_pdf_fallback' => 'PDF Temporário',
        'pix_gerado' => 'PIX Gerado',
        'pix_erro' => 'Erro no PIX',
        'detalhes_visualizado' => 'Detalhes Visualizados'
    ];
    
    return $labels[$tipo] ?? ucwords(str_replace('_', ' ', $tipo));
}

/**
 * Função auxiliar para determinar ações disponíveis para alunos
 */
function getAcoesDisponiveisAluno($boleto) {
    $acoes = [];
    
    // Download sempre disponível se tem arquivo
    if (!empty($boleto['arquivo_pdf'])) {
        $acoes[] = [
            'tipo' => 'download',
            'label' => 'Download PDF',
            'icone' => 'fas fa-download',
            'classe' => 'btn-info',
            'disponivel' => true
        ];
    }
    
    // PIX disponível para pendentes e vencidos
    if (in_array($boleto['status'], ['pendente', 'vencido'])) {
        $acoes[] = [
            'tipo' => 'pix',
            'label' => 'Gerar PIX',
            'icone' => 'fas fa-qrcode',
            'classe' => 'btn-success',
            'disponivel' => true
        ];
    }
    
    // Ação de compartilhar sempre disponível
    $acoes[] = [
        'tipo' => 'compartilhar',
        'label' => 'Compartilhar',
        'icone' => 'fas fa-share',
        'classe' => 'btn-secondary',
        'disponivel' => true
    ];
    
    // Informação se está pago
    if ($boleto['status'] === 'pago') {
        $acoes[] = [
            'tipo' => 'info',
            'label' => 'Boleto Pago',
            'icone' => 'fas fa-check-circle',
            'classe' => 'btn-success',
            'disponivel' => false,
            'info' => 'Este boleto já foi pago'
        ];
    }
    
    return $acoes;
}

/**
 * Registra log de acesso
 */
function registrarLogAcesso($boletoId, $tipo) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, boleto_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "Detalhes do boleto visualizados pelo aluno";
        
        $stmt->execute([
            $tipo,
            $_SESSION['aluno_id'] ?? null,
            $boletoId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log de acesso: " . $e->getMessage());
    }
}
?>