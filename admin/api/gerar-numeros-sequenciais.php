<?php
/**
 * API para gerar números sequenciais de boletos
 * Arquivo: admin/api/gerar-numeros-sequenciais.php
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $quantidade = intval($input['quantidade'] ?? 0);
    $prefixoData = $input['prefixo_data'] ?? date('Ymd');
    
    if ($quantidade <= 0 || $quantidade > 50) {
        throw new Exception('Quantidade deve ser entre 1 e 50');
    }
    
    if (strlen($prefixoData) !== 8 || !is_numeric($prefixoData)) {
        throw new Exception('Prefixo de data inválido');
    }
    
    $db = (new Database())->getConnection();
    
    // Busca próximo número disponível
    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING(numero_boleto, 9) AS UNSIGNED)) as ultimo_sequencial
        FROM boletos 
        WHERE numero_boleto LIKE ?
    ");
    $stmt->execute([$prefixoData . '%']);
    $resultado = $stmt->fetch();
    
    $proximoSequencial = ($resultado['ultimo_sequencial'] ?? 0) + 1;
    
    $numeros = [];
    for ($i = 0; $i < $quantidade; $i++) {
        $sequencial = $proximoSequencial + $i;
        $sequencialFormatado = str_pad($sequencial, 4, '0', STR_PAD_LEFT);
        $numeros[] = $prefixoData . $sequencialFormatado;
    }
    
    echo json_encode([
        'success' => true,
        'numeros' => $numeros,
        'prefixo_usado' => $prefixoData,
        'proximo_sequencial' => $proximoSequencial,
        'quantidade_gerada' => count($numeros)
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao gerar números sequenciais: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>