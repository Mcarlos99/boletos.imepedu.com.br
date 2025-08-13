<?php
/**
 * Webhook PagSeguro para Baixa Automática de Boletos
 * Arquivo: webhooks/pagseguro-callback.php
 */

// Headers de segurança
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Log todas as requisições para debug
$logFile = __DIR__ . '/../logs/pagseguro-webhook.log';
$requestBody = file_get_contents('php://input');
$requestHeaders = getallheaders();

// Log da requisição
error_log(
    "\n[" . date('Y-m-d H:i:s') . "] WEBHOOK RECEBIDO\n" .
    "Headers: " . json_encode($requestHeaders) . "\n" .
    "Body: " . $requestBody . "\n" .
    "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n" .
    "---\n",
    3,
    $logFile
);

try {
    require_once '../config/database.php';
    require_once '../src/PagSeguroWebhookService.php';
    
    // Verificar se é uma requisição POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    // Decodificar o payload
    $payload = json_decode($requestBody, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload');
    }
    
    // Processar webhook
    $webhookService = new PagSeguroWebhookService();
    $resultado = $webhookService->processarWebhook($payload, $requestHeaders);
    
    // Resposta de sucesso
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Webhook processado com sucesso',
        'boleto_atualizado' => $resultado['boleto_id'] ?? null,
        'novo_status' => $resultado['novo_status'] ?? null
    ]);
    
} catch (Exception $e) {
    // Log do erro
    error_log(
        "\n[" . date('Y-m-d H:i:s') . "] ERRO NO WEBHOOK: " . $e->getMessage() . "\n" .
        "Trace: " . $e->getTraceAsString() . "\n" .
        "---\n",
        3,
        $logFile
    );
    
    // Resposta de erro
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>