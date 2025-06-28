<?php
/**
 * Sistema de Boletos IMED - API Geração de Código PIX
 * Arquivo: api/gerar-pix.php
 * 
 * API para geração de códigos PIX dinâmicos para pagamento de boletos
 */

session_start();

// Headers de segurança e CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Verifica se usuário está logado
if (!isset($_SESSION['aluno_cpf']) && !isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'UNAUTHORIZED',
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

// Tratamento para requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verifica método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'METHOD_NOT_ALLOWED',
        'message' => 'Método não permitido'
    ]);
    exit;
}

// Inclui dependências
require_once '../config/database.php';

try {
    // Obtém ID do boleto
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
    
    // Busca dados do boleto
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
    
    // Validação de acesso (mesmo esquema do download)
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
    
    // Verifica se boleto já está pago
    if ($boleto['status'] === 'pago') {
        throw new Exception('Este boleto já foi pago');
    }
    
    // Verifica se boleto foi cancelado
    if ($boleto['status'] === 'cancelado') {
        throw new Exception('Este boleto foi cancelado');
    }
    
    // Configurações PIX da instituição
    $configPIX = obterConfiguracaoPIX($boleto['curso_subdomain']);
    
    // Gera dados do PIX
    $dadosPIX = gerarCodigoPIX($boleto, $configPIX);
    
    // Salva PIX gerado no banco para controle
    $pixId = salvarPIXGerado($boletoId, $dadosPIX, $configPIX);
    
    // Gera QR Code
    $qrCodeData = gerarQRCode($dadosPIX['pix_copia_cola']);
    
    // Prepara resposta
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
                'Confirme os dados e efetue o pagamento'
            ],
            'observacoes' => [
                'O PIX tem validade até ' . date('d/m/Y H:i', strtotime($dadosPIX['validade'])),
                'Após o pagamento, aguarde até 24h para processamento',
                'Em caso de dúvidas, entre em contato com a secretaria',
                'Guarde o comprovante de pagamento'
            ]
        ],
        'gerado_em' => date('Y-m-d H:i:s'),
        'gerado_timestamp' => time()
    ];
    
    // Registra log
    registrarLogPIX($boletoId, $pixId, 'pix_gerado', $boleto);
    
    error_log("PIX Generator: PIX gerado com sucesso - ID: {$pixId}");
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("PIX Generator: ERRO - " . $e->getMessage());
    
    // Registra log do erro
    if (isset($boletoId) && isset($boleto)) {
        registrarLogPIX($boletoId, null, 'pix_erro', $boleto, $e->getMessage());
    }
    
    $errorCode = 'UNKNOWN_ERROR';
    $httpCode = 400;
    
    // Mapeia erros específicos
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
 * Obtém configuração PIX do polo
 */
function obterConfiguracaoPIX($subdomain) {
    // Configurações PIX por polo
    $configuracoes = [
        'breubranco.imepedu.com.br' => [
            'chave_pix' => '12.345.678/0001-90', // CNPJ da instituição
            'beneficiario' => 'IMED EDUCACAO - POLO BREU BRANCO',
            'cidade' => 'BREU BRANCO',
            'cep' => '68470000',
            'merchant_category_code' => '8299', // Educação
            'country_code' => 'BR',
            'currency' => '986' // Real brasileiro
        ],
        'igarape.imepedu.com.br' => [
            'chave_pix' => '12.345.678/0001-90',
            'beneficiario' => 'IMED EDUCACAO - POLO IGARAPE-MIRI',
            'cidade' => 'IGARAPE-MIRI',
            'cep' => '68552000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'tucurui.imepedu.com.br' => [
            'chave_pix' => '12.345.678/0001-90',
            'beneficiario' => 'IMED EDUCACAO - POLO TUCURUI',
            'cidade' => 'TUCURUI',
            'cep' => '68455000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'moju.imepedu.com.br' => [
            'chave_pix' => '12.345.678/0001-90',
            'beneficiario' => 'IMED EDUCACAO - POLO MOJU',
            'cidade' => 'MOJU',
            'cep' => '68450000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ]
    ];
    
    // Configuração padrão se polo não encontrado
    $configPadrao = [
        'chave_pix' => '12.345.678/0001-90',
        'beneficiario' => 'IMED EDUCACAO',
        'cidade' => 'BELEM',
        'cep' => '66000000',
        'merchant_category_code' => '8299',
        'country_code' => 'BR',
        'currency' => '986'
    ];
    
    return $configuracoes[$subdomain] ?? $configPadrao;
}

/**
 * Gera código PIX conforme padrão do Banco Central
 */
function gerarCodigoPIX($boleto, $configPIX) {
    // Identificador único para o PIX (baseado no boleto)
    $identificador = 'IMED' . $boleto['id'] . date('YmdHis');
    
    // Validade do PIX (24 horas após geração)
    $validade = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Valor em centavos
    $valorCentavos = intval($boleto['valor'] * 100);
    
    // Monta payload PIX conforme especificação
    $payload = montarPayloadPIX($configPIX, $boleto, $identificador);
    
    return [
        'identificador' => $identificador,
        'pix_copia_cola' => $payload,
        'validade' => $validade,
        'valor' => $boleto['valor'],
        'valor_centavos' => $valorCentavos
    ];
}

/**
 * Monta payload PIX conforme padrão do Banco Central
 */
function montarPayloadPIX($config, $boleto, $identificador) {
    // Função auxiliar para adicionar campo PIX
    function adicionarCampo($id, $valor) {
        $tamanho = str_pad(strlen($valor), 2, '0', STR_PAD_LEFT);
        return $id . $tamanho . $valor;
    }
    
    $payload = '';
    
    // 00 - Payload Format Indicator
    $payload .= adicionarCampo('00', '01');
    
    // 01 - Point of Initiation Method (PIX dinâmico)
    $payload .= adicionarCampo('01', '12');
    
    // 26 - Merchant Account Information (Chave PIX)
    $chavePIX = '';
    $chavePIX .= adicionarCampo('00', 'BR.GOV.BCB.PIX'); // GUI
    $chavePIX .= adicionarCampo('01', $config['chave_pix']); // Chave PIX
    
    if (!empty($identificador)) {
        $chavePIX .= adicionarCampo('02', $identificador); // Identificador da transação
    }
    
    $payload .= adicionarCampo('26', $chavePIX);
    
    // 52 - Merchant Category Code
    $payload .= adicionarCampo('52', $config['merchant_category_code']);
    
    // 53 - Transaction Currency (Real brasileiro)
    $payload .= adicionarCampo('53', $config['currency']);
    
    // 54 - Transaction Amount
    $valorFormatado = number_format($boleto['valor'], 2, '.', '');
    $payload .= adicionarCampo('54', $valorFormatado);
    
    // 58 - Country Code
    $payload .= adicionarCampo('58', $config['country_code']);
    
    // 59 - Merchant Name
    $payload .= adicionarCampo('59', $config['beneficiario']);
    
    // 60 - Merchant City
    $payload .= adicionarCampo('60', $config['cidade']);
    
    // 61 - Postal Code
    $payload .= adicionarCampo('61', $config['cep']);
    
    // 62 - Additional Data Field Template
    $additionalData = '';
    $additionalData .= adicionarCampo('05', $identificador); // Reference Label
    
    // Informações do boleto
    $descricaoBoleto = 'Boleto ' . $boleto['numero_boleto'] . ' - ' . substr($boleto['curso_nome'], 0, 20);
    $additionalData .= adicionarCampo('08', $descricaoBoleto);
    
    $payload .= adicionarCampo('62', $additionalData);
    
    // 63 - CRC16
    $payload .= '6304';
    
    // Calcula CRC16
    $crc16 = calcularCRC16($payload);
    $payload .= strtoupper($crc16);
    
    return $payload;
}

/**
 * Calcula CRC16 para validação do PIX
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
 * Gera QR Code para o PIX
 */
function gerarQRCode($pixCopiaCola) {
    try {
        // Usando API pública do Google (para produção, usar serviço próprio)
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
        
        // Baixa a imagem do QR Code
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'IMED-PIX-Generator/1.0'
            ]
        ]);
        
        $imageData = file_get_contents($qrUrl, false, $context);
        
        if ($imageData === false) {
            throw new Exception('Erro ao gerar QR Code');
        }
        
        // Converte para base64
        $base64 = 'data:image/png;base64,' . base64_encode($imageData);
        
        return [
            'base64' => $base64,
            'url' => $qrUrl,
            'size' => strlen($imageData)
        ];
        
    } catch (Exception $e) {
        error_log("QR Code: Erro ao gerar - " . $e->getMessage());
        
        // Fallback: retorna um QR code básico
        return [
            'base64' => 'data:image/svg+xml;base64,' . base64_encode(gerarQRCodeSVG($pixCopiaCola)),
            'url' => null,
            'size' => 0
        ];
    }
}

/**
 * Gera QR Code básico em SVG como fallback
 */
function gerarQRCodeSVG($data) {
    // QR Code SVG básico (placeholder)
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

/**
 * Salva PIX gerado no banco para controle
 */
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
        
        // Se não conseguir salvar no banco, retorna ID temporário
        return 'temp_' . time();
    }
}

/**
 * Registra log de operações PIX
 */
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

/**
 * Verifica se PIX ainda é válido
 */
function verificarValidadePIX($pixId) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT *, 
                   CASE WHEN validade > NOW() THEN 1 ELSE 0 END as valido
            FROM pix_gerados 
            WHERE id = ? AND status = 'ativo'
        ");
        $stmt->execute([$pixId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("PIX Validation: Erro - " . $e->getMessage());
        return null;
    }
}

/**
 * Marca PIX como usado/pago
 */
function marcarPIXUsado($pixId, $transacaoId = null) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            UPDATE pix_gerados 
            SET status = 'usado', 
                transacao_id = ?,
                data_pagamento = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$transacaoId, $pixId]);
        
    } catch (Exception $e) {
        error_log("PIX Update: Erro - " . $e->getMessage());
        return false;
    }
}

/**
 * Obtém estatísticas de PIX gerados
 */
function obterEstatisticasPIX($boletoId = null) {
    try {
        $db = (new Database())->getConnection();
        
        $where = $boletoId ? "WHERE boleto_id = ?" : "";
        $params = $boletoId ? [$boletoId] : [];
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_pix,
                COUNT(CASE WHEN status = 'ativo' AND validade > NOW() THEN 1 END) as pix_validos,
                COUNT(CASE WHEN status = 'usado' THEN 1 END) as pix_usados,
                COUNT(CASE WHEN status = 'ativo' AND validade <= NOW() THEN 1 END) as pix_expirados,
                SUM(valor) as valor_total,
                MAX(created_at) as ultimo_pix
            FROM pix_gerados 
            {$where}
        ");
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("PIX Stats: Erro - " . $e->getMessage());
        return [
            'total_pix' => 0,
            'pix_validos' => 0,
            'pix_usados' => 0,
            'pix_expirados' => 0,
            'valor_total' => 0,
            'ultimo_pix' => null
        ];
    }
}
?>