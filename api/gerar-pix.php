<?php
/**
 * Sistema de Boletos IMEPEDU - API Geração de PIX com Desconto
 * Arquivo: api/gerar-pix.php
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Validação de acesso
if (!isset($_SESSION['aluno_cpf']) && !isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'UNAUTHORIZED',
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'METHOD_NOT_ALLOWED',
        'message' => 'Método não permitido'
    ]);
    exit;
}

require_once '../config/database.php';

try {
    $boletoId = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $boletoId = filter_var($input['boleto_id'] ?? null, FILTER_VALIDATE_INT);
    } else {
        $boletoId = filter_input(INPUT_GET, 'boleto_id', FILTER_VALIDATE_INT);
    }
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório e deve ser um número válido');
    }
    
    $db = (new Database())->getConnection();
    
    // Busca dados completos do boleto com verificação de desconto
    $stmt = $db->prepare("
        SELECT b.*, 
               a.nome as aluno_nome, 
               a.cpf as aluno_cpf,
               a.email as aluno_email,
               a.subdomain as aluno_subdomain,
               c.nome as curso_nome,
               c.subdomain as curso_subdomain
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.id = ?
    ");
    $stmt->execute([$boletoId]);
    $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$boleto) {
        throw new Exception('Boleto não encontrado');
    }
    
    // Validação de acesso específica
    $acessoAutorizado = false;
    
    if (isset($_SESSION['admin_id'])) {
        $acessoAutorizado = true;
    } elseif (isset($_SESSION['aluno_cpf'])) {
        $cpfSessao = preg_replace('/[^0-9]/', '', $_SESSION['aluno_cpf']);
        $cpfBoleto = preg_replace('/[^0-9]/', '', $boleto['aluno_cpf']);
        $subdomainSessao = $_SESSION['subdomain'] ?? '';
        
        if ($cpfSessao === $cpfBoleto && $subdomainSessao === $boleto['curso_subdomain']) {
            $acessoAutorizado = true;
        }
    }
    
    if (!$acessoAutorizado) {
        throw new Exception('Você não tem permissão para gerar PIX deste boleto');
    }
    
    if ($boleto['status'] === 'pago') {
        throw new Exception('Este boleto já foi pago');
    }
    
    if ($boleto['status'] === 'cancelado') {
        throw new Exception('Este boleto foi cancelado');
    }
    
    // Calcula desconto PIX se aplicável
    $dadosDesconto = calcularDescontoPIX($boleto, $db);
    
    // Gera PIX com valor final (com ou sem desconto)
    $configPIX = obterConfiguracaoPIX($boleto['curso_subdomain']);
    $dadosPIX = gerarCodigoPIX($boleto, $configPIX, $dadosDesconto);
    
    // Salva PIX gerado no banco com informações de desconto
    $pixId = salvarPIXGerado($boletoId, $dadosPIX, $configPIX, $dadosDesconto, $db);
    
    // Gera QR Code
    $qrCodeData = gerarQRCode($dadosPIX['pix_copia_cola']);
    
    // Resposta completa
    $response = [
        'success' => true,
        'boleto' => [
            'id' => $boleto['id'],
            'numero' => $boleto['numero_boleto'],
            'valor_original' => floatval($boleto['valor']),
            'valor_original_formatado' => 'R$ ' . number_format($boleto['valor'], 2, ',', '.'),
            'valor_final' => $dadosDesconto['valor_final'],
            'valor_final_formatado' => 'R$ ' . number_format($dadosDesconto['valor_final'], 2, ',', '.'),
            'vencimento' => $boleto['vencimento'],
            'vencimento_formatado' => date('d/m/Y', strtotime($boleto['vencimento'])),
            'aluno_nome' => $boleto['aluno_nome'],
            'curso_nome' => $boleto['curso_nome'],
            'status' => $boleto['status']
        ],
        'desconto' => [
            'tem_desconto' => $dadosDesconto['tem_desconto'],
            'valor_desconto' => $dadosDesconto['valor_desconto'],
            'valor_desconto_formatado' => 'R$ ' . number_format($dadosDesconto['valor_desconto'], 2, ',', '.'),
            'motivo' => $dadosDesconto['motivo'],
            'economia' => $dadosDesconto['tem_desconto'] ? 
                'Você economizará R$ ' . number_format($dadosDesconto['valor_desconto'], 2, ',', '.') . ' pagando via PIX!' : null
        ],
        'pix' => [
            'id' => $pixId,
            'chave_pix' => $configPIX['chave_pix'],
            'beneficiario' => $configPIX['beneficiario'],
            'cidade' => $configPIX['cidade'],
            'identificador' => $dadosPIX['identificador'],
            'pix_copia_cola' => $dadosPIX['pix_copia_cola'],
            'qr_code_base64' => $qrCodeData['base64'],
            'qr_code_url' => $qrCodeData['url'] ?? null,
            'validade' => $dadosPIX['validade'],
            'validade_formatada' => date('d/m/Y H:i', strtotime($dadosPIX['validade']))
        ],
        'instrucoes' => [
            'como_pagar' => [
                'Abra o app do seu banco',
                'Acesse a área PIX',
                'Escolha "Pagar com QR Code" ou "PIX Copia e Cola"',
                'Escaneie o código ou cole o texto',
                'Confirme os dados e o valor de R$ ' . number_format($dadosDesconto['valor_final'], 2, ',', '.'),
                'Efetue o pagamento',
                'Envie o comprovante via WhatsApp: <a href="https://wa.me/5594992435333" target="_blank">Clique Aqui!</a>',
                'Aguarde até 48h para processamento'
            ],
            'observacoes' => [
                'PIX válido até ' . date('d/m/Y H:i', strtotime($dadosPIX['validade'])),
                $dadosDesconto['tem_desconto'] ? 'Desconto aplicado automaticamente' : 'Sem desconto para este boleto',
                'Em caso de dúvidas, entre em contato com a secretaria',
                'Guarde o comprovante de pagamento'
            ]
        ],
        'gerado_em' => date('Y-m-d H:i:s'),
        'gerado_timestamp' => time()
    ];
    
    // Registra log
    registrarLogPIX($boletoId, $pixId, 'pix_gerado_com_desconto', $boleto, $dadosDesconto, $db);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("PIX Generator: ERRO - " . $e->getMessage());
    
    if (isset($boletoId) && isset($boleto)) {
        registrarLogPIX($boletoId, null, 'pix_erro', $boleto, null, $db ?? null, $e->getMessage());
    }
    
    $errorCode = 'UNKNOWN_ERROR';
    $httpCode = 400;
    
    if (strpos($e->getMessage(), 'não encontrado') !== false) {
        $errorCode = 'BOLETO_NOT_FOUND';
        $httpCode = 404;
    } elseif (strpos($e->getMessage(), 'não tem permissão') !== false) {
        $errorCode = 'ACCESS_DENIED';
        $httpCode = 403;
    } elseif (strpos($e->getMessage(), 'já foi pago') !== false) {
        $errorCode = 'ALREADY_PAID';
        $httpCode = 409;
    } elseif (strpos($e->getMessage(), 'foi cancelado') !== false) {
        $errorCode = 'CANCELLED';
        $httpCode = 410;
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $errorCode,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Calcula desconto PIX baseado nas configurações personalizadas do boleto
 */
function calcularDescontoPIX($boleto, $db) {
    $valorOriginal = (float)$boleto['valor'];
    $valorFinal = $valorOriginal;
    $valorDesconto = 0.00;
    $temDesconto = false;
    $motivo = '';
    
    // Verifica se o boleto tem desconto habilitado
    if (!$boleto['pix_desconto_disponivel'] || $boleto['pix_desconto_usado']) {
        return [
            'tem_desconto' => false,
            'valor_original' => $valorOriginal,
            'valor_desconto' => 0.00,
            'valor_final' => $valorOriginal,
            'motivo' => $boleto['pix_desconto_usado'] ? 'Desconto já utilizado' : 'Desconto não habilitado para este boleto'
        ];
    }
    
    // Verifica se ainda está no prazo (até o vencimento)
    $hoje = new DateTime();
    $vencimento = new DateTime($boleto['vencimento']);
    
    if ($hoje > $vencimento) {
        return [
            'tem_desconto' => false,
            'valor_original' => $valorOriginal,
            'valor_desconto' => 0.00,
            'valor_final' => $valorOriginal,
            'motivo' => 'Boleto vencido - desconto não disponível'
        ];
    }
    
    // Busca configurações personalizadas do boleto
    $stmt = $db->prepare("
        SELECT pix_valor_desconto, pix_valor_minimo 
        FROM boletos 
        WHERE id = ?
    ");
    $stmt->execute([$boleto['id']]);
    $configBoleto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$configBoleto || !$configBoleto['pix_valor_desconto'] || $configBoleto['pix_valor_desconto'] <= 0) {
        return [
            'tem_desconto' => false,
            'valor_original' => $valorOriginal,
            'valor_desconto' => 0.00,
            'valor_final' => $valorOriginal,
            'motivo' => 'Valor do desconto não configurado para este boleto'
        ];
    }
    
    // Verifica valor mínimo se configurado
    $valorMinimo = (float)($configBoleto['pix_valor_minimo'] ?? 0);
    if ($valorMinimo > 0 && $valorOriginal < $valorMinimo) {
        return [
            'tem_desconto' => false,
            'valor_original' => $valorOriginal,
            'valor_desconto' => 0.00,
            'valor_final' => $valorOriginal,
            'motivo' => 'Valor mínimo não atingido (mín: R$ ' . number_format($valorMinimo, 2, ',', '.') . ')'
        ];
    }
    
    // Aplica o desconto personalizado
    $valorDesconto = (float)$configBoleto['pix_valor_desconto'];
    
    // Garante que o valor final não seja menor que R$ 10,00
    $valorFinal = $valorOriginal - $valorDesconto;
    if ($valorFinal < 10.00) {
        $valorDesconto = $valorOriginal - 10.00;
        $valorFinal = 10.00;
        
        if ($valorDesconto <= 0) {
            return [
                'tem_desconto' => false,
                'valor_original' => $valorOriginal,
                'valor_desconto' => 0.00,
                'valor_final' => $valorOriginal,
                'motivo' => 'Valor do boleto muito baixo para aplicar desconto'
            ];
        }
    }
    
    $temDesconto = true;
    $motivo = "Desconto PIX de R$ " . number_format($valorDesconto, 2, ',', '.') . " aplicado";
    
    return [
        'tem_desconto' => $temDesconto,
        'valor_original' => $valorOriginal,
        'valor_desconto' => $valorDesconto,
        'valor_final' => $valorFinal,
        'motivo' => $motivo
    ];
}

/**
 * Configurações PIX por polo
 */
function obterConfiguracaoPIX($subdomain) {
    $configuracoes = [
        'breubranco.imepedu.com.br' => [
            'chave_pix' => '51071986000121',
            'beneficiario' => 'MAGALHAES EDUCACAO LTDA',
            'cidade' => 'BREU BRANCO',
            'cep' => '68488000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'igarape.imepedu.com.br' => [
            'chave_pix' => '12345678000190',
            'beneficiario' => 'IMEPEDU IGARAPE LTDA',
            'cidade' => 'IGARAPE-MIRI',
            'cep' => '68552000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'tucurui.imepedu.com.br' => [
            'chave_pix' => '12345678000190',
            'beneficiario' => 'IMEPEDU TUCURUI LTDA',
            'cidade' => 'TUCURUI',
            'cep' => '68455000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'moju.imepedu.com.br' => [
            'chave_pix' => '12345678000190',
            'beneficiario' => 'IMEPEDU MOJU LTDA',
            'cidade' => 'MOJU',
            'cep' => '68450000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ]
    ];
    
    $configPadrao = [
        'chave_pix' => '12345678000190',
        'beneficiario' => 'IMEPEDU EDUCACAO LTDA',
        'cidade' => 'BELEM',
        'cep' => '66000000',
        'merchant_category_code' => '8299',
        'country_code' => 'BR',
        'currency' => '986'
    ];
    
    return $configuracoes[$subdomain] ?? $configPadrao;
}

/**
 * Gera código PIX com valor final (com desconto aplicado)
 */
function gerarCodigoPIX($boleto, $configPIX, $dadosDesconto) {
    $numeroBoleto = $boleto['numero_boleto'];
    $identificador = 'IMEPEDU' . $numeroBoleto;
    $identificador = substr($identificador, 0, 25);
    
    $validade = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $valorFinal = $dadosDesconto['valor_final']; // Usa valor com desconto aplicado
    $valorCentavos = intval($valorFinal * 100);
    
    $payload = montarPayloadPIX($configPIX, $valorFinal, $identificador);
    
    return [
        'identificador' => $identificador,
        'pix_copia_cola' => $payload,
        'validade' => $validade,
        'valor' => $valorFinal,
        'valor_centavos' => $valorCentavos
    ];
}

/**
 * Monta payload PIX conforme padrão brasileiro
 */
function montarPayloadPIX($config, $valor, $identificador) {
    function adicionarCampo($id, $valor) {
        $tamanho = str_pad(strlen($valor), 2, '0', STR_PAD_LEFT);
        return $id . $tamanho . $valor;
    }
    
    $payload = '';
    
    // 00 - Payload Format Indicator
    $payload .= adicionarCampo('00', '01');
    
    // 01 - Point of Initiation Method
    $payload .= adicionarCampo('01', '12');
    
    // 26 - Merchant Account Information
    $chavePIX = '';
    $chavePIX .= adicionarCampo('00', 'BR.GOV.BCB.PIX');
    $chavePIX .= adicionarCampo('01', $config['chave_pix']);
    $payload .= adicionarCampo('26', $chavePIX);
    
    // 52 - Merchant Category Code
    $payload .= adicionarCampo('52', $config['merchant_category_code']);
    
    // 53 - Transaction Currency
    $payload .= adicionarCampo('53', $config['currency']);
    
    // 54 - Transaction Amount
    $valorFormatado = number_format($valor, 2, '.', '');
    $payload .= adicionarCampo('54', $valorFormatado);
    
    // 58 - Country Code
    $payload .= adicionarCampo('58', $config['country_code']);
    
    // 59 - Merchant Name
    $nomeFormatado = substr($config['beneficiario'], 0, 25);
    $payload .= adicionarCampo('59', $nomeFormatado);
    
    // 60 - Merchant City
    $cidadeFormatada = substr($config['cidade'], 0, 15);
    $payload .= adicionarCampo('60', $cidadeFormatada);
    
    // 61 - Postal Code
    $cepFormatado = preg_replace('/[^0-9]/', '', $config['cep']);
    $cepFormatado = substr($cepFormatado, 0, 8);
    $payload .= adicionarCampo('61', $cepFormatado);
    
    // 62 - Additional Data Field Template
    $additionalData = '';
    $additionalData .= adicionarCampo('05', substr($identificador, 0, 25));
    $payload .= adicionarCampo('62', $additionalData);
    
    // 63 - CRC16
    $payload .= '6304';
    $crc16 = calcularCRC16($payload);
    $payload .= strtoupper($crc16);
    
    return $payload;
}

/**
 * Calcula CRC16 para PIX
 */
function calcularCRC16($data) {
    $crc = 0xFFFF;
    $polynomial = 0x1021;
    
    for ($i = 0; $i < strlen($data); $i++) {
        $crc ^= (ord($data[$i]) << 8);
        
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x8000) {
                $crc = (($crc << 1) ^ $polynomial) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }
    
    return str_pad(dechex($crc), 4, '0', STR_PAD_LEFT);
}

/**
 * Gera QR Code para PIX
 */
function gerarQRCode($pixCopiaCola) {
    try {
        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/';
        
        $params = [
            'size' => '300x300',
            'data' => $pixCopiaCola,
            'format' => 'png',
            'margin' => '10',
            'qzone' => '1',
            'color' => '000000',
            'bgcolor' => 'ffffff'
        ];
        
        $qrUrl = $qrApiUrl . '?' . http_build_query($params);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'IMEPEDU-PIX-Generator/2.0'
            ]
        ]);
        
        $imageData = file_get_contents($qrUrl, false, $context);
        
        if ($imageData === false) {
            throw new Exception('Erro ao gerar QR Code');
        }
        
        $base64 = 'data:image/png;base64,' . base64_encode($imageData);
        
        return [
            'base64' => $base64,
            'url' => $qrUrl,
            'size' => strlen($imageData)
        ];
        
    } catch (Exception $e) {
        error_log("QR Code: Erro ao gerar - " . $e->getMessage());
        
        return [
            'base64' => 'data:image/svg+xml;base64,' . base64_encode(gerarQRCodeSVG($pixCopiaCola)),
            'url' => null,
            'size' => 0
        ];
    }
}

/**
 * SVG fallback para QR Code
 */
function gerarQRCodeSVG($data) {
    $svg = '<?xml version="1.0" encoding="UTF-8"?>
    <svg width="300" height="300" xmlns="http://www.w3.org/2000/svg">
        <rect width="300" height="300" fill="white"/>
        <rect x="20" y="20" width="260" height="260" fill="none" stroke="black" stroke-width="2"/>
        <text x="150" y="140" text-anchor="middle" font-family="Arial" font-size="14" fill="black">
            QR Code PIX
        </text>
        <text x="150" y="160" text-anchor="middle" font-family="Arial" font-size="12" fill="gray">
            Use o código copia e cola
        </text>
        <text x="150" y="180" text-anchor="middle" font-family="Arial" font-size="10" fill="green">
            Com desconto aplicado
        </text>
    </svg>';
    
    return $svg;
}

/**
 * Salva PIX gerado no banco com dados de desconto
 */
function salvarPIXGerado($boletoId, $dadosPIX, $configPIX, $dadosDesconto, $db) {
    try {
        $stmt = $db->prepare("
            INSERT INTO pix_gerados (
                boleto_id, identificador, chave_pix, beneficiario,
                valor, valor_original, valor_desconto, tem_desconto, motivo_desconto,
                pix_copia_cola, validade, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo', NOW())
        ");
        
        $stmt->execute([
            $boletoId,
            $dadosPIX['identificador'],
            $configPIX['chave_pix'],
            $configPIX['beneficiario'],
            $dadosPIX['valor'], // Valor final (com desconto)
            $dadosDesconto['valor_original'], // Valor original
            $dadosDesconto['valor_desconto'], // Valor do desconto
            $dadosDesconto['tem_desconto'] ? 1 : 0, // Se tem desconto
            $dadosDesconto['motivo'], // Motivo do desconto/não desconto
            $dadosPIX['pix_copia_cola'],
            $dadosPIX['validade']
        ]);
        
        $pixId = $db->lastInsertId();
        
        // Se tem desconto, marca como usado no boleto
        if ($dadosDesconto['tem_desconto']) {
            $stmtUpdate = $db->prepare("
                UPDATE boletos 
                SET pix_desconto_usado = 1, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpdate->execute([$boletoId]);
        }
        
        return $pixId;
        
    } catch (Exception $e) {
        error_log("PIX Database: Erro ao salvar - " . $e->getMessage());
        return 'temp_' . time();
    }
}

/**
 * Registra log das operações PIX
 */
function registrarLogPIX($boletoId, $pixId, $tipo, $boleto, $dadosDesconto = null, $db = null, $erro = null) {
    if (!$db) return;
    
    try {
        $descricao = "PIX boleto #{$boleto['numero_boleto']} - {$boleto['aluno_nome']}";
        
        if ($pixId) {
            $descricao .= " - PIX ID: {$pixId}";
        }
        
        if ($dadosDesconto && $dadosDesconto['tem_desconto']) {
            $descricao .= " - Desconto: R$ " . number_format($dadosDesconto['valor_desconto'], 2, ',', '.');
            $descricao .= " - Valor final: R$ " . number_format($dadosDesconto['valor_final'], 2, ',', '.');
        }
        
        if ($erro) {
            $descricao .= " - Erro: {$erro}";
        }
        
        $usuarioId = null;
        if (isset($_SESSION['admin_id'])) {
            $usuarioId = $_SESSION['admin_id'];
        } elseif (isset($_SESSION['aluno_id'])) {
            $usuarioId = $_SESSION['aluno_id'];
        }
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, boleto_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $tipo,
            $usuarioId,
            $boletoId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (Exception $e) {
        error_log("PIX Log: Erro ao registrar - " . $e->getMessage());
    }
}
?>