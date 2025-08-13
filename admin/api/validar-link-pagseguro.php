<?php
/**
 * API para validação de links PagSeguro
 * Arquivo: admin/api/validar-link-pagseguro.php
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

require_once '../../config/database.php';
require_once '../../src/BoletoUploadService.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['link']) || empty($input['link'])) {
        throw new Exception('Link não fornecido');
    }
    
    $link = trim($input['link']);
    
    // Validação básica
    if (!filter_var($link, FILTER_VALIDATE_URL)) {
        throw new Exception('URL inválida');
    }
    
    // Verificar se é domínio PagSeguro válido
    $dominiosValidos = [
        'cobranca.pagbank.com',
        'cobranca.pagseguro.uol.com.br',
        'pag.ae',
        'pagbank.com.br'
    ];
    
    $parsedUrl = parse_url($link);
    $host = $parsedUrl['host'] ?? '';
    $linkValido = false;
    
    foreach ($dominiosValidos as $dominio) {
        if (strpos($host, $dominio) !== false) {
            $linkValido = true;
            break;
        }
    }
    
    if (!$linkValido) {
        throw new Exception('Link não é do PagSeguro/PagBank');
    }
    
    // Verificar se o link já existe no sistema
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT numero_boleto, status FROM boletos WHERE link_pagseguro = ?");
    $stmt->execute([$link]);
    $existente = $stmt->fetch();
    
    if ($existente) {
        throw new Exception("Link já cadastrado (Boleto: {$existente['numero_boleto']}, Status: {$existente['status']})");
    }
    
    // Tentar acessar o link (com timeout)
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method' => 'HEAD',
            'user_agent' => 'IMEPEDU-System/1.0'
        ]
    ]);
    
    $headers = @get_headers($link, 1, $context);
    $acessivel = $headers && strpos($headers[0], '200') !== false;
    
    // Extrair informações básicas do link
    $info = [
        'pagseguro_id' => null,
        'valor' => null,
        'vencimento' => null,
        'descricao' => 'Cobrança PagSeguro'
    ];
    
    // Tentar extrair ID da URL
    if (preg_match('/\/([a-f0-9-]{36})\/?$/i', $link, $matches)) {
        $info['pagseguro_id'] = $matches[1];
    }
    
    // Log da validação
    error_log("VALIDAÇÃO PAGSEGURO: Link {$link} - Válido: " . ($linkValido ? 'SIM' : 'NÃO') . ", Acessível: " . ($acessivel ? 'SIM' : 'NÃO'));
    
    echo json_encode([
        'success' => true,
        'valido' => $linkValido,
        'acessivel' => $acessivel,
        'info' => $info,
        'message' => $acessivel ? 'Link válido e acessível' : 'Link válido mas pode estar inacessível'
    ]);
    
} catch (Exception $e) {
    error_log("ERRO na validação PagSeguro: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>