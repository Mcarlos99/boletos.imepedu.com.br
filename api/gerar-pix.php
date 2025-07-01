<?php
/**
 * Sistema de Boletos IMEPEDU - API Geração de Código PIX CORRIGIDA
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
    
    error_log("PIX Generator: Iniciando geração PIX para boleto ID: {$boletoId}");
    
    $db = (new Database())->getConnection();
    
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
    
    error_log("PIX Generator: Boleto encontrado - #{$boleto['numero_boleto']}, Valor: R$ {$boleto['valor']}");
    
    // Validação de acesso
    $acessoAutorizado = false;
    
    if (isset($_SESSION['admin_id'])) {
        $acessoAutorizado = true;
        error_log("PIX Generator: Acesso autorizado - Administrador");
        
    } elseif (isset($_SESSION['aluno_cpf'])) {
        $cpfSessao = preg_replace('/[^0-9]/', '', $_SESSION['aluno_cpf']);
        $cpfBoleto = preg_replace('/[^0-9]/', '', $boleto['aluno_cpf']);
        $subdomainSessao = $_SESSION['subdomain'] ?? '';
        
        if ($cpfSessao === $cpfBoleto && $subdomainSessao === $boleto['curso_subdomain']) {
            $acessoAutorizado = true;
            error_log("PIX Generator: Acesso autorizado - Aluno proprietário");
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
    
    $configPIX = obterConfiguracaoPIX($boleto['curso_subdomain']);
    $dadosPIX = gerarCodigoPIX($boleto, $configPIX);
    $pixId = salvarPIXGerado($boletoId, $dadosPIX, $configPIX);
    $qrCodeData = gerarQRCode($dadosPIX['pix_copia_cola']);
    
    $response = [
        'success' => true,
        'boleto' => [
            'id' => $boleto['id'],
            'numero' => $boleto['numero_boleto'],
            'valor' => floatval($boleto['valor']),
            'valor_formatado' => 'R$ ' . number_format($boleto['valor'], 2, ',', '.'),
            'vencimento' => $boleto['vencimento'],
            'vencimento_formatado' => date('d/m/Y', strtotime($boleto['vencimento'])),
            'aluno_nome' => $boleto['aluno_nome'],
            'curso_nome' => $boleto['curso_nome'],
            'status' => $boleto['status']
        ],
        'pix' => [
            'id' => $pixId,
            'chave_pix' => $configPIX['chave_pix'],
            'beneficiario' => $configPIX['beneficiario'],
            'cidade' => $configPIX['cidade'],
            'cep' => $configPIX['cep'],
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
                'Confirme os dados e efetue o pagamento',
                'Enviar o comprovante via WhatsApp, <a href="https://wa.me/5594992435333" target="_blank">Clique Aqui!</a>',
              	'Após o pagamento, aguarde até 48h para processamento'
            ],
            'observacoes' => [
                'O PIX tem validade até ' . date('d/m/Y H:i', strtotime($dadosPIX['validade'])),
                //'Após o pagamento, aguarde até 24h para processamento',
                'Em caso de dúvidas, entre em contato com a secretaria',
                'Guarde o comprovante de pagamento'
            ]
        ],
        'gerado_em' => date('Y-m-d H:i:s'),
        'gerado_timestamp' => time()
    ];
    
    registrarLogPIX($boletoId, $pixId, 'pix_gerado', $boleto);
    
    error_log("PIX Generator: PIX gerado com sucesso - ID: {$pixId}");
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("PIX Generator: ERRO - " . $e->getMessage());
    
    if (isset($boletoId) && isset($boleto)) {
        registrarLogPIX($boletoId, null, 'pix_erro', $boleto, $e->getMessage());
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

function obterConfiguracaoPIX($subdomain) {
    $configuracoes = [
        'breubranco.imepedu.com.br' => [
            'chave_pix' => '51071986000121', // APENAS NÚMEROS - CORRIGIDO
            'beneficiario' => 'MAGALHAES EDUCACAO LTDA', // 25 chars
            'cidade' => 'BREU BRANCO',
            'cep' => '68488000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'igarape.imepedu.com.br' => [
            'chave_pix' => '12345678000190', // APENAS NÚMEROS - CORRIGIDO
            'beneficiario' => 'IMEPEDU IGARAPE LTDA',
            'cidade' => 'IGARAPE-MIRI',
            'cep' => '68552000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'tucurui.imepedu.com.br' => [
            'chave_pix' => '12345678000190', // APENAS NÚMEROS - CORRIGIDO
            'beneficiario' => 'IMEPEDU TUCURUI LTDA',
            'cidade' => 'TUCURUI',
            'cep' => '68455000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'moju.imepedu.com.br' => [
            'chave_pix' => '12345678000190', // APENAS NÚMEROS - CORRIGIDO
            'beneficiario' => 'IMEPEDU MOJU LTDA',
            'cidade' => 'MOJU',
            'cep' => '68450000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ]
    ];
    
    $configPadrao = [
        'chave_pix' => '12345678000190', // APENAS NÚMEROS - CORRIGIDO
        'beneficiario' => 'IMEPEDU EDUCACAO LTDA',
        'cidade' => 'BELEM',
        'cep' => '66000000',
        'merchant_category_code' => '8299',
        'country_code' => 'BR',
        'currency' => '986'
    ];
    
    return $configuracoes[$subdomain] ?? $configPadrao;
}

function gerarCodigoPIX($boleto, $configPIX) {
    // CORRIGIDO: USA O NÚMERO DO BOLETO EM VEZ DO ID
    $numeroBoleto = $boleto['numero_boleto']; // Ex: 202502200001
    $identificador = 'IMEPEDU' . $numeroBoleto; // IMEPEDU202502200001
    $identificador = substr($identificador, 0, 25); // Máximo 25 chars
    
    $validade = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $valorCentavos = intval($boleto['valor'] * 100);
    $payload = montarPayloadPIX($configPIX, $boleto, $identificador);
    
    return [
        'identificador' => $identificador,
        'pix_copia_cola' => $payload,
        'validade' => $validade,
        'valor' => $boleto['valor'],
        'valor_centavos' => $valorCentavos
    ];
}

function montarPayloadPIX($config, $boleto, $identificador) {
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
    $valorFormatado = number_format($boleto['valor'], 2, '.', '');
    $payload .= adicionarCampo('54', $valorFormatado);
    
    // 58 - Country Code
    $payload .= adicionarCampo('58', $config['country_code']);
    
    // 59 - Merchant Name (máximo 25 caracteres)
    $nomeFormatado = substr($config['beneficiario'], 0, 25);
    $payload .= adicionarCampo('59', $nomeFormatado);
    
    // 60 - Merchant City (máximo 15 caracteres)
    $cidadeFormatada = substr($config['cidade'], 0, 15);
    $payload .= adicionarCampo('60', $cidadeFormatada);
    
    // 61 - Postal Code (apenas números)
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
                'user_agent' => 'IMEPEDU-PIX-Generator/1.0'
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

function gerarQRCodeSVG($data) {
    $svg = '<?xml version="1.0" encoding="UTF-8"?>
    <svg width="300" height="300" xmlns="http://www.w3.org/2000/svg">
        <rect width="300" height="300" fill="white"/>
        <rect x="20" y="20" width="260" height="260" fill="none" stroke="black" stroke-width="2"/>
        <text x="150" y="150" text-anchor="middle" font-family="Arial" font-size="12" fill="black">
            QR Code PIX
        </text>
        <text x="150" y="170" text-anchor="middle" font-family="Arial" font-size="10" fill="gray">
            Use o código copia e cola
        </text>
    </svg>';
    
    return $svg;
}

function salvarPIXGerado($boletoId, $dadosPIX, $configPIX) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO pix_gerados (
                boleto_id, identificador, chave_pix, beneficiario,
                valor, pix_copia_cola, validade, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ativo', NOW())
        ");
        
        $stmt->execute([
            $boletoId,
            $dadosPIX['identificador'],
            $configPIX['chave_pix'],
            $configPIX['beneficiario'],
            $dadosPIX['valor'],
            $dadosPIX['pix_copia_cola'],
            $dadosPIX['validade']
        ]);
        
        $pixId = $db->lastInsertId();
        
        error_log("PIX Database: PIX salvo com ID: {$pixId}");
        
        return $pixId;
        
    } catch (Exception $e) {
        error_log("PIX Database: Erro ao salvar - " . $e->getMessage());
        return 'temp_' . time();
    }
}

function registrarLogPIX($boletoId, $pixId, $tipo, $boleto, $erro = null) {
    try {
        $db = (new Database())->getConnection();
        
        $descricao = "PIX boleto #{$boleto['numero_boleto']} - {$boleto['aluno_nome']}";
        if ($pixId) {
            $descricao .= " - PIX ID: {$pixId}";
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