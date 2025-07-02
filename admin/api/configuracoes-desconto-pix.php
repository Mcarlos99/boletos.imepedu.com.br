<?php
/**
 * Sistema de Boletos IMEPEDU - API Configurações de Desconto PIX
 * Arquivo: admin/api/configuracoes-desconto-pix.php
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Validação de acesso administrativo
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'UNAUTHORIZED',
        'message' => 'Acesso administrativo necessário'
    ]);
    exit;
}

require_once '../../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = (new Database())->getConnection();

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        case 'DELETE':
            handleDelete($db);
            break;
        default:
            throw new Exception('Método não permitido');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'REQUEST_ERROR',
        'message' => $e->getMessage()
    ]);
}

/**
 * GET - Lista configurações ou busca específica
 */
function handleGet($db) {
    $polo = $_GET['polo'] ?? null;
    $ativo = isset($_GET['ativo']) ? (int)$_GET['ativo'] : null;
    
    if ($polo) {
        // Busca configuração específica de um polo
        $stmt = $db->prepare("
            SELECT * FROM configuracoes_desconto_pix 
            WHERE polo_subdomain = ? 
            " . ($ativo !== null ? "AND ativo = ?" : "") . "
            ORDER BY id DESC
            LIMIT 1
        ");
        
        $params = [$polo];
        if ($ativo !== null) {
            $params[] = $ativo;
        }
        
        $stmt->execute($params);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'configuracao' => $config ?: null
        ]);
    } else {
        // Lista todas as configurações
        $where = $ativo !== null ? "WHERE ativo = ?" : "";
        $params = $ativo !== null ? [$ativo] : [];
        
        $stmt = $db->prepare("
            SELECT * FROM configuracoes_desconto_pix 
            {$where}
            ORDER BY polo_subdomain, id DESC
        ");
        $stmt->execute($params);
        $configuracoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupa por polo (apenas a configuração mais recente de cada)
        $configPorPolo = [];
        foreach ($configuracoes as $config) {
            if (!isset($configPorPolo[$config['polo_subdomain']])) {
                $configPorPolo[$config['polo_subdomain']] = $config;
            }
        }
        
        echo json_encode([
            'success' => true,
            'configuracoes' => array_values($configPorPolo),
            'total' => count($configPorPolo)
        ]);
    }
}

/**
 * POST - Criar nova configuração
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Dados não fornecidos');
    }
    
    // Validação dos campos obrigatórios
    $camposObrigatorios = ['polo_subdomain', 'valor_desconto_fixo', 'valor_minimo_boleto'];
    foreach ($camposObrigatorios as $campo) {
        if (!isset($input[$campo]) || empty($input[$campo])) {
            throw new Exception("Campo obrigatório: {$campo}");
        }
    }
    
    // Validações específicas
    if (!filter_var($input['valor_desconto_fixo'], FILTER_VALIDATE_FLOAT) || $input['valor_desconto_fixo'] <= 0) {
        throw new Exception('Valor do desconto deve ser um número positivo');
    }
    
    if (!filter_var($input['valor_minimo_boleto'], FILTER_VALIDATE_FLOAT) || $input['valor_minimo_boleto'] <= 0) {
        throw new Exception('Valor mínimo do boleto deve ser um número positivo');
    }
    
    if (isset($input['percentual_desconto'])) {
        if (!filter_var($input['percentual_desconto'], FILTER_VALIDATE_FLOAT) || 
            $input['percentual_desconto'] < 0 || $input['percentual_desconto'] > 100) {
            throw new Exception('Percentual de desconto deve estar entre 0 e 100');
        }
    }
    
    // Verifica se polo é válido
    $polosValidos = [
        'breubranco.imepedu.com.br',
        'igarape.imepedu.com.br', 
        'tucurui.imepedu.com.br',
        'ava.imepedu.com.br'
    ];
    
    if (!in_array($input['polo_subdomain'], $polosValidos)) {
        throw new Exception('Polo inválido');
    }
    
    try {
        $db->beginTransaction();
        
        // Desativa configurações anteriores do polo se necessário
        if (isset($input['ativo']) && $input['ativo'] == 1) {
            $stmtDesativar = $db->prepare("
                UPDATE configuracoes_desconto_pix 
                SET ativo = 0, updated_at = NOW()
                WHERE polo_subdomain = ? AND ativo = 1
            ");
            $stmtDesativar->execute([$input['polo_subdomain']]);
        }
        
        // Insere nova configuração
        $stmt = $db->prepare("
            INSERT INTO configuracoes_desconto_pix (
                polo_subdomain, valor_desconto_fixo, valor_minimo_boleto,
                percentual_desconto, tipo_desconto, ativo, 
                condicao_aplicacao, data_inicio, data_fim, observacoes,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $input['polo_subdomain'],
            floatval($input['valor_desconto_fixo']),
            floatval($input['valor_minimo_boleto']),
            floatval($input['percentual_desconto'] ?? 0),
            $input['tipo_desconto'] ?? 'fixo',
            isset($input['ativo']) ? (int)$input['ativo'] : 1,
            $input['condicao_aplicacao'] ?? 'ate_vencimento',
            $input['data_inicio'] ?? null,
            $input['data_fim'] ?? null,
            $input['observacoes'] ?? null
        ]);
        
        $configId = $db->lastInsertId();
        
        $db->commit();
        
        // Log da operação
        registrarLog($db, 'configuracao_desconto_criada', 
            "Nova configuração de desconto para {$input['polo_subdomain']} - ID: {$configId}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuração criada com sucesso',
            'configuracao_id' => $configId
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * PUT - Atualizar configuração existente
 */
function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        throw new Exception('ID da configuração é obrigatório');
    }
    
    $configId = (int)$input['id'];
    
    // Verifica se configuração existe
    $stmtCheck = $db->prepare("SELECT * FROM configuracoes_desconto_pix WHERE id = ?");
    $stmtCheck->execute([$configId]);
    $configExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$configExistente) {
        throw new Exception('Configuração não encontrada');
    }
    
    // Campos que podem ser atualizados
    $camposPermitidos = [
        'valor_desconto_fixo', 'valor_minimo_boleto', 'percentual_desconto',
        'tipo_desconto', 'ativo', 'condicao_aplicacao', 'data_inicio', 
        'data_fim', 'observacoes'
    ];
    
    $updates = [];
    $params = [];
    
    foreach ($camposPermitidos as $campo) {
        if (isset($input[$campo])) {
            $updates[] = "{$campo} = ?";
            
            if (in_array($campo, ['valor_desconto_fixo', 'valor_minimo_boleto', 'percentual_desconto'])) {
                $params[] = floatval($input[$campo]);
            } elseif ($campo === 'ativo') {
                $params[] = (int)$input[$campo];
            } else {
                $params[] = $input[$campo];
            }
        }
    }
    
    if (empty($updates)) {
        throw new Exception('Nenhum campo para atualizar');
    }
    
    try {
        $db->beginTransaction();
        
        // Se está ativando esta configuração, desativa outras do mesmo polo
        if (isset($input['ativo']) && $input['ativo'] == 1) {
            $stmtDesativar = $db->prepare("
                UPDATE configuracoes_desconto_pix 
                SET ativo = 0, updated_at = NOW()
                WHERE polo_subdomain = ? AND ativo = 1 AND id != ?
            ");
            $stmtDesativar->execute([$configExistente['polo_subdomain'], $configId]);
        }
        
        // Atualiza a configuração
        $updates[] = "updated_at = NOW()";
        $params[] = $configId;
        
        $sql = "UPDATE configuracoes_desconto_pix SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $db->commit();
        
        // Log da operação
        registrarLog($db, 'configuracao_desconto_atualizada', 
            "Configuração ID {$configId} atualizada para {$configExistente['polo_subdomain']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuração atualizada com sucesso'
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * DELETE - Desativar configuração
 */
function handleDelete($db) {
    $configId = $_GET['id'] ?? null;
    
    if (!$configId) {
        throw new Exception('ID da configuração é obrigatório');
    }
    
    $configId = (int)$configId;
    
    // Verifica se configuração existe
    $stmtCheck = $db->prepare("SELECT * FROM configuracoes_desconto_pix WHERE id = ?");
    $stmtCheck->execute([$configId]);
    $config = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('Configuração não encontrada');
    }
    
    // Desativa ao invés de deletar (para manter histórico)
    $stmt = $db->prepare("
        UPDATE configuracoes_desconto_pix 
        SET ativo = 0, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$configId]);
    
    // Log da operação
    registrarLog($db, 'configuracao_desconto_desativada', 
        "Configuração ID {$configId} desativada para {$config['polo_subdomain']}");
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuração desativada com sucesso'
    ]);
}

/**
 * Registra log das operações
 */
function registrarLog($db, $tipo, $descricao) {
    try {
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $tipo,
            $_SESSION['admin_id'],
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}
?>