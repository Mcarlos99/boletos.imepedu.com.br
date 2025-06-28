<?php
/**
 * Sistema de Boletos IMED - API para Marcar Boleto como Pago
 * Arquivo: admin/api/marcar-pago.php
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
    $valorPago = filter_var($input['valor_pago'] ?? null, FILTER_VALIDATE_FLOAT);
    $dataPagamento = $input['data_pagamento'] ?? null;
    $observacoes = trim($input['observacoes'] ?? '');
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório');
    }
    
    if (!$valorPago || $valorPago <= 0) {
        throw new Exception('Valor pago deve ser maior que zero');
    }
    
    if (!$dataPagamento) {
        throw new Exception('Data de pagamento é obrigatória');
    }
    
    // Valida formato da data
    $dataObj = DateTime::createFromFormat('Y-m-d', $dataPagamento);
    if (!$dataObj) {
        throw new Exception('Data de pagamento inválida');
    }
    
    // Não permite data futura
    if ($dataObj > new DateTime()) {
        throw new Exception('Data de pagamento não pode ser futura');
    }
    
    error_log("API Pago: Marcando boleto {$boletoId} como pago - Valor: R$ {$valorPago}");
    
    $adminService = new AdminService();
    
    // Marca como pago
    $resultado = $adminService->marcarBoletoComoPago($boletoId, $valorPago, $dataPagamento, $observacoes);
    
    if ($resultado) {
        // Busca dados atualizados do boleto para retorno
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("
            SELECT b.numero_boleto, a.nome as aluno_nome
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            WHERE b.id = ?
        ");
        $stmt->execute([$boletoId]);
        $dadosBoleto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Boleto marcado como pago com sucesso!',
            'boleto' => [
                'id' => $boletoId,
                'numero_boleto' => $dadosBoleto['numero_boleto'] ?? null,
                'aluno_nome' => $dadosBoleto['aluno_nome'] ?? null,
                'valor_pago' => $valorPago,
                'data_pagamento' => $dataPagamento,
                'observacoes' => $observacoes
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        error_log("API Pago: Sucesso - Boleto {$boletoId} marcado como pago");
        
    } else {
        throw new Exception('Falha ao marcar boleto como pago');
    }
    
} catch (Exception $e) {
    error_log("API Pago: ERRO - " . $e->getMessage());
    
    $httpCode = 400;
    
    // Mapeia alguns erros específicos para códigos HTTP apropriados
    if (strpos($e->getMessage(), 'não encontrado') !== false) {
        $httpCode = 404;
    } elseif (strpos($e->getMessage(), 'já está pago') !== false) {
        $httpCode = 409; // Conflict
    } elseif (strpos($e->getMessage(), 'cancelado') !== false) {
        $httpCode = 410; // Gone
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $httpCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>