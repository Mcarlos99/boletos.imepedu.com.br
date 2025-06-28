<?php
/**
 * Sistema de Boletos IMED - API para Cancelar Boleto CORRIGIDA
 * Arquivo: admin/api/cancelar-boleto.php
 * 
 * CORREÇÃO: Campo 'tipo' da tabela logs limitado para evitar truncamento
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../src/AdminService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Dados inválidos no corpo da requisição');
    }
    
    $boletoId = filter_var($input['boleto_id'] ?? null, FILTER_VALIDATE_INT);
    $motivo = trim($input['motivo'] ?? '');
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório');
    }
    
    if (empty($motivo)) {
        throw new Exception('Motivo do cancelamento é obrigatório');
    }
    
    if (strlen($motivo) < 5) {
        throw new Exception('Motivo deve ter pelo menos 5 caracteres');
    }
    
    if (strlen($motivo) > 500) {
        throw new Exception('Motivo não pode exceder 500 caracteres');
    }
    
    error_log("API Cancelar: Cancelando boleto {$boletoId} - Motivo: {$motivo}");
    
    $db = (new Database())->getConnection();
    
    // Inicia transação
    $db->beginTransaction();
    
    // Busca dados atuais do boleto
    $stmt = $db->prepare("
        SELECT b.*, a.nome as aluno_nome, a.cpf as aluno_cpf, 
               c.nome as curso_nome, c.subdomain
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.id = ?
        FOR UPDATE
    ");
    $stmt->execute([$boletoId]);
    $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$boleto) {
        throw new Exception('Boleto não encontrado');
    }
    
    // Validações de estado
    if ($boleto['status'] === 'cancelado') {
        throw new Exception('Boleto já está cancelado');
    }
    
    if ($boleto['status'] === 'pago') {
        throw new Exception('Não é possível cancelar um boleto já pago');
    }
    
    // Verifica se o administrador tem permissão
    if (!verificarPermissaoCancelamento($_SESSION['admin_id'], $boleto)) {
        throw new Exception('Você não tem permissão para cancelar este boleto');
    }
    
    // Atualiza o boleto
    $stmt = $db->prepare("
        UPDATE boletos 
        SET status = 'cancelado', 
            observacoes = CONCAT(COALESCE(observacoes, ''), ?, '\n[CANCELADO EM: ', NOW(), ']'),
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $observacaoCompleta = "\n[CANCELADO POR: Administrador]\n[MOTIVO: " . $motivo . "]";
    $stmt->execute([$observacaoCompleta, $boletoId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Falha ao cancelar o boleto');
    }
    
    // 🔧 CORREÇÃO: Registra log detalhado com validações
    try {
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, boleto_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // 🔧 CORREÇÃO: Limita o tipo para evitar truncamento
        $tipoLog = 'cancelado_admin';
        
        // 🔧 CORREÇÃO: Valida se admin_id existe antes de usar
        $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        
        // 🔧 CORREÇÃO: Limita descrição se necessário
        $descricaoLog = "Boleto #{$boleto['numero_boleto']} cancelado - Aluno: {$boleto['aluno_nome']} - Motivo: " . substr($motivo, 0, 200);
        if (strlen($descricaoLog) > 500) {
            $descricaoLog = substr($descricaoLog, 0, 497) . '...';
        }
        
        $stmt->execute([
            $tipoLog,
            $adminId, // Pode ser NULL se sessão inválida
            $boletoId,
            $descricaoLog,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
        error_log("Log de cancelamento registrado com sucesso");
        
    } catch (Exception $logError) {
        // Se log falhar, não impede o cancelamento, apenas registra o erro
        error_log("AVISO: Erro ao registrar log de cancelamento: " . $logError->getMessage());
        error_log("Cancelamento do boleto continuará normalmente");
    }
    
    // Commit da transação
    $db->commit();
    
    // Busca dados atualizados para retorno
    $stmt = $db->prepare("
        SELECT b.numero_boleto, b.status, b.updated_at,
               a.nome as aluno_nome, a.email as aluno_email,
               c.nome as curso_nome, c.subdomain as polo
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.id = ?
    ");
    $stmt->execute([$boletoId]);
    $boletoAtualizado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Tenta enviar notificação por email (opcional)
    try {
        enviarNotificacaoCancelamento($boletoAtualizado, $motivo);
    } catch (Exception $e) {
        error_log("Erro ao enviar notificação de cancelamento: " . $e->getMessage());
        // Não falha a operação se email falhar
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Boleto cancelado com sucesso!',
        'boleto' => [
            'id' => $boletoId,
            'numero_boleto' => $boletoAtualizado['numero_boleto'],
            'status' => $boletoAtualizado['status'],
            'aluno_nome' => $boletoAtualizado['aluno_nome'],
            'curso_nome' => $boletoAtualizado['curso_nome'],
            'polo' => $boletoAtualizado['polo'],
            'motivo_cancelamento' => $motivo,
            'cancelado_em' => $boletoAtualizado['updated_at'],
            'cancelado_por' => $_SESSION['admin_nome'] ?? 'Administrador'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    error_log("API Cancelar: Sucesso - Boleto {$boletoId} cancelado");
    
} catch (Exception $e) {
    // Rollback da transação se houver erro
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("API Cancelar: ERRO - " . $e->getMessage());
    
    $httpCode = 400;
    
    // Mapeia alguns erros específicos para códigos HTTP apropriados
    if (strpos($e->getMessage(), 'não encontrado') !== false) {
        $httpCode = 404;
    } elseif (strpos($e->getMessage(), 'já está cancelado') !== false) {
        $httpCode = 409; // Conflict
    } elseif (strpos($e->getMessage(), 'já pago') !== false) {
        $httpCode = 409; // Conflict
    } elseif (strpos($e->getMessage(), 'não tem permissão') !== false) {
        $httpCode = 403; // Forbidden
    } elseif (strpos($e->getMessage(), 'Não autenticado') !== false) {
        $httpCode = 401; // Unauthorized
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $httpCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Verifica se o administrador tem permissão para cancelar o boleto
 */
function verificarPermissaoCancelamento($adminId, $boleto) {
    try {
        $db = (new Database())->getConnection();
        
        // Busca dados do admin
        $stmt = $db->prepare("SELECT nivel FROM administradores WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            return false;
        }
        
        // Super admins podem cancelar qualquer boleto
        if (in_array($admin['nivel'], ['super_admin', 'master'])) {
            return true;
        }
        
        // Admins normais podem cancelar boletos até 7 dias após criação
        $criadoEm = new DateTime($boleto['created_at']);
        $agora = new DateTime();
        $diasDesdeCreacao = $agora->diff($criadoEm)->days;
        
        if ($diasDesdeCreacao <= 7) {
            return true;
        }
        
        // Boletos muito antigos requerem permissão especial
        return false;
        
    } catch (Exception $e) {
        error_log("Erro ao verificar permissão: " . $e->getMessage());
        return false;
    }
}

/**
 * Envia notificação de cancelamento por email
 */
function enviarNotificacaoCancelamento($boleto, $motivo) {
    // Esta função pode ser implementada para enviar emails
    // Por enquanto, apenas registra no log
    
    $assunto = "Boleto #{$boleto['numero_boleto']} - Cancelado";
    $mensagem = "Seu boleto foi cancelado.\n\n";
    $mensagem .= "Número: #{$boleto['numero_boleto']}\n";
    $mensagem .= "Curso: {$boleto['curso_nome']}\n";
    $mensagem .= "Polo: {$boleto['polo']}\n";
    $mensagem .= "Motivo: {$motivo}\n\n";
    $mensagem .= "Para esclarecimentos, entre em contato conosco.\n";
    
    error_log("Email de cancelamento preparado para {$boleto['aluno_email']}: {$assunto}");
    
    // Aqui você pode implementar o envio real do email usando:
    // - PHPMailer
    // - SwiftMailer  
    // - Serviços como SendGrid, Mailgun, etc.
    
    return true;
}

/**
 * Registra estatísticas de cancelamento
 */
function registrarEstatisticasCancelamento($boletoId, $motivo) {
    try {
        $db = (new Database())->getConnection();
        
        // Registra na tabela de estatísticas (se existir)
        $stmt = $db->prepare("
            INSERT INTO estatisticas_cancelamentos 
            (boleto_id, motivo, admin_id, data_cancelamento) 
            VALUES (?, ?, ?, NOW())
        ");
        
        $stmt->execute([$boletoId, $motivo, $_SESSION['admin_id']]);
        
    } catch (Exception $e) {
        // Ignora erro se tabela não existir
        error_log("Estatísticas de cancelamento não registradas: " . $e->getMessage());
    }
}

/**
 * Valida integridade dos dados antes do cancelamento
 */
function validarIntegridadeDados($boleto) {
    $erros = [];
    
    // Verifica se boleto tem dados mínimos necessários
    if (empty($boleto['numero_boleto'])) {
        $erros[] = "Número do boleto é obrigatório";
    }
    
    if (empty($boleto['aluno_nome'])) {
        $erros[] = "Nome do aluno é obrigatório";
    }
    
    if (empty($boleto['curso_nome'])) {
        $erros[] = "Nome do curso é obrigatório";
    }
    
    if ($boleto['valor'] <= 0) {
        $erros[] = "Valor do boleto deve ser maior que zero";
    }
    
    return $erros;
}

/**
 * Obtém histórico de cancelamentos do admin
 */
function obterHistoricoCancelamentos($adminId, $limite = 10) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT b.numero_boleto, b.valor, a.nome as aluno_nome, 
                   c.nome as curso_nome, l.created_at, l.descricao
            FROM logs l
            INNER JOIN boletos b ON l.boleto_id = b.id
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE l.tipo = 'cancelado_admin' 
            AND l.usuario_id = ?
            ORDER BY l.created_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$adminId, $limite]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Erro ao buscar histórico: " . $e->getMessage());
        return [];
    }
}

/**
 * 🔧 CORREÇÃO ADICIONAL: Função para verificar estrutura da tabela logs
 */
function verificarEstruturaTabelaLogs() {
    try {
        $db = (new Database())->getConnection();
        
        // Verifica a estrutura da coluna 'tipo'
        $stmt = $db->prepare("SHOW COLUMNS FROM logs LIKE 'tipo'");
        $stmt->execute();
        $coluna = $stmt->fetch();
        
        if ($coluna) {
            error_log("Estrutura coluna 'tipo': " . print_r($coluna, true));
            
            // Se for VARCHAR muito pequeno, sugere alteração
            if (strpos($coluna['Type'], 'varchar') !== false) {
                preg_match('/varchar\((\d+)\)/', $coluna['Type'], $matches);
                $tamanho = isset($matches[1]) ? (int)$matches[1] : 0;
                
                if ($tamanho < 50) {
                    error_log("AVISO: Coluna 'tipo' tem apenas {$tamanho} caracteres. Recomenda-se VARCHAR(100)");
                    
                    // Opcional: Auto-correção (descomente se quiser aplicar automaticamente)
                    /*
                    try {
                        $db->exec("ALTER TABLE logs MODIFY COLUMN tipo VARCHAR(100) NOT NULL");
                        error_log("✅ Coluna 'tipo' expandida para VARCHAR(100)");
                    } catch (Exception $e) {
                        error_log("❌ Erro ao expandir coluna 'tipo': " . $e->getMessage());
                    }
                    */
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao verificar estrutura da tabela: " . $e->getMessage());
    }
}

// 🔧 CORREÇÃO: Executa verificação na primeira execução
if (php_sapi_name() === 'cli' || 
    (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST')) {
    
    // Executa verificação apenas uma vez por dia
    $lastCheck = '/tmp/imed_logs_structure_check';
    if (!file_exists($lastCheck) || (time() - filemtime($lastCheck)) > 86400) {
        verificarEstruturaTabelaLogs();
        touch($lastCheck);
    }
}
?>