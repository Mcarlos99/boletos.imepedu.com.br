<?php
/**
 * Sistema de Boletos IMED - API para Detalhes do Boleto
 * Arquivo: admin/api/boleto-detalhes.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once '../../config/database.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método não permitido');
    }
    
    $boletoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório e deve ser um número válido');
    }
    
    error_log("API Detalhes: Buscando boleto ID: {$boletoId}");
    
    $db = (new Database())->getConnection();
    
    // Busca detalhes completos do boleto
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
            ad.nome as admin_nome,
            ad.email as admin_email,
            -- Logs relacionados
            (SELECT COUNT(*) FROM logs WHERE boleto_id = b.id AND tipo LIKE 'download_%') as total_downloads,
            (SELECT MAX(created_at) FROM logs WHERE boleto_id = b.id AND tipo LIKE 'download_%') as ultimo_download,
            (SELECT COUNT(*) FROM logs WHERE boleto_id = b.id AND tipo LIKE 'pix_%') as total_pix_gerados
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id
        INNER JOIN cursos c ON b.curso_id = c.id
        LEFT JOIN administradores ad ON b.admin_id = ad.id
        WHERE b.id = ?
    ");
    
    $stmt->execute([$boletoId]);
    $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$boleto) {
        throw new Exception('Boleto não encontrado');
    }
    
    error_log("API Detalhes: Boleto encontrado - #{$boleto['numero_boleto']}");
    
    // Busca histórico de alterações
    $stmtHistorico = $db->prepare("
        SELECT 
            l.*,
            a.nome as admin_nome
        FROM logs l
        LEFT JOIN administradores a ON l.usuario_id = a.id
        WHERE l.boleto_id = ?
        ORDER BY l.created_at DESC
        LIMIT 20
    ");
    $stmtHistorico->execute([$boletoId]);
    $historico = $stmtHistorico->fetchAll(PDO::FETCH_ASSOC);
    
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
        $caminhoArquivo = __DIR__ . '/../../uploads/boletos/' . $boleto['arquivo_pdf'];
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
    
    // Monta resposta completa
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
            
            // Observações
            'observacoes' => $boleto['observacoes'] ?: null,
        ],
        
        'aluno' => [
            'id' => (int)$boleto['aluno_id'],
            'nome' => $boleto['aluno_nome'],
            'cpf' => $boleto['aluno_cpf'],
            'cpf_formatado' => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $boleto['aluno_cpf']),
            'email' => $boleto['aluno_email'],
            'cidade' => $boleto['aluno_cidade'],
            'pais' => $boleto['aluno_pais'],
            'cadastro' => $boleto['aluno_cadastro'],
            'cadastro_formatado' => (new DateTime($boleto['aluno_cadastro']))->format('d/m/Y H:i:s')
        ],
        
        'curso' => [
            'id' => (int)$boleto['curso_id'],
            'nome' => $boleto['curso_nome'],
            'nome_curto' => $boleto['curso_nome_curto'],
            'subdomain' => $boleto['curso_subdomain'],
            'polo_nome' => getNomePolo($boleto['curso_subdomain']),
            'valor_padrao' => $boleto['curso_valor'] ? (float)$boleto['curso_valor'] : null
        ],
        
        'administrador' => [
            'nome' => $boleto['admin_nome'] ?: 'Sistema',
            'email' => $boleto['admin_email'] ?: null
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
                'descricao' => $log['descricao'],
                'admin_nome' => $log['admin_nome'] ?: 'Sistema',
                'data' => $log['created_at'],
                'data_formatada' => (new DateTime($log['created_at']))->format('d/m/Y H:i:s'),
                'ip_address' => $log['ip_address']
            ];
        }, $historico),
        
        'acoes_disponiveis' => getAcoesDisponiveis($boleto)
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API Detalhes: ERRO - " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
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
    require_once '../../config/moodle.php';
    
    $config = MoodleConfig::getConfig($subdomain);
    return $config['name'] ?? str_replace('.imepedu.com.br', '', $subdomain);
}

/**
 * Função auxiliar para determinar ações disponíveis
 */
function getAcoesDisponiveis($boleto) {
    $acoes = [];
    
    // Download sempre disponível se tem arquivo
    if (!empty($boleto['arquivo_pdf'])) {
        $acoes[] = [
            'tipo' => 'download',
            'label' => 'Download PDF',
            'icone' => 'fas fa-download',
            'classe' => 'btn-info'
        ];
    }
    
    // PIX disponível para pendentes e vencidos
    if (in_array($boleto['status'], ['pendente', 'vencido'])) {
        $acoes[] = [
            'tipo' => 'pix',
            'label' => 'Gerar PIX',
            'icone' => 'fas fa-qrcode',
            'classe' => 'btn-success'
        ];
        
        $acoes[] = [
            'tipo' => 'marcar_pago',
            'label' => 'Marcar como Pago',
            'icone' => 'fas fa-check',
            'classe' => 'btn-success'
        ];
        
        $acoes[] = [
            'tipo' => 'cancelar',
            'label' => 'Cancelar',
            'icone' => 'fas fa-times',
            'classe' => 'btn-warning'
        ];
    }
    
    // Editar sempre disponível (exceto pagos)
    if ($boleto['status'] != 'pago') {
        $acoes[] = [
            'tipo' => 'editar',
            'label' => 'Editar',
            'icone' => 'fas fa-edit',
            'classe' => 'btn-secondary'
        ];
    }
    
    // Remover sempre disponível para admins
    $acoes[] = [
        'tipo' => 'remover',
        'label' => 'Remover',
        'icone' => 'fas fa-trash',
        'classe' => 'btn-danger'
    ];
    
    return $acoes;
}
?>