<?php
/**
 * Sistema de Boletos IMEPEDU - API Geração PIX ULTRA CORRIGIDA
 * Versão com validação completa e debug
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
    echo json_encode(['success' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Acesso não autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Método não permitido']);
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
        throw new Exception('ID do boleto é obrigatório');
    }
    
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("
        SELECT b.*, a.nome as aluno_nome, a.cpf as aluno_cpf, a.email as aluno_email,
               a.subdomain as aluno_subdomain, c.nome as curso_nome, c.subdomain as curso_subdomain
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
    
    // Validação de acesso
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
        throw new Exception('Acesso negado');
    }
    
    if ($boleto['status'] === 'pago') {
        throw new Exception('Boleto já foi pago');
    }
    
    if ($boleto['status'] === 'cancelado') {
        throw new Exception('Boleto foi cancelado');
    }
    
    $configPIX = obterConfiguracaoPIX($boleto['curso_subdomain']);
    $dadosPIX = gerarCodigoPIXValidado($boleto, $configPIX);
    
    // Validação final do PIX gerado
    $validacao = validarPIX($dadosPIX['pix_copia_cola']);
    
    if (!$validacao['valido']) {
        error_log("PIX INVÁLIDO: " . json_encode($validacao));
        throw new Exception('Erro na geração do PIX: ' . $validacao['erro']);
    }
    
    $pixId = salvarPIXGerado($boletoId, $dadosPIX, $configPIX);
    $qrCodeData = gerarQRCode($dadosPIX['pix_copia_cola']);
    
    // Log detalhado para debug
    error_log("PIX GERADO: " . $dadosPIX['pix_copia_cola']);
    error_log("PIX VALIDAÇÃO: " . json_encode($validacao));
    
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
            'beneficiario' => $configPIX['beneficiario_original'],
            'cidade' => $configPIX['cidade'],
            'cep' => $configPIX['cep_original'],
            'identificador' => $dadosPIX['identificador'],
            'pix_copia_cola' => $dadosPIX['pix_copia_cola'],
            'qr_code_base64' => $qrCodeData['base64'],
            'qr_code_url' => $qrCodeData['url'] ?? null,
            'validade' => $dadosPIX['validade'],
            'validade_formatada' => date('d/m/Y H:i', strtotime($dadosPIX['validade']))
        ],
        'validacao' => $validacao,
        'debug' => [
            'comprimento' => strlen($dadosPIX['pix_copia_cola']),
            'inicio' => substr($dadosPIX['pix_copia_cola'], 0, 20),
            'fim' => substr($dadosPIX['pix_copia_cola'], -20),
            'config_usada' => $boleto['curso_subdomain']
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
                'PIX válido até ' . date('d/m/Y H:i', strtotime($dadosPIX['validade'])),
                'Processamento em até 24h',
                'Guarde o comprovante'
            ]
        ],
        'gerado_em' => date('Y-m-d H:i:s'),
        'timestamp' => time()
    ];
    
    registrarLogPIX($boletoId, $pixId, 'pix_gerado', $boleto);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("PIX ERROR: " . $e->getMessage());
    
    if (isset($boletoId) && isset($boleto)) {
        registrarLogPIX($boletoId, null, 'pix_erro', $boleto, $e->getMessage());
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'PIX_ERROR',
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}

function obterConfiguracaoPIX($subdomain) {
    $configuracoes = [
        'breubranco.imepedu.com.br' => [
            'chave_pix' => '51.071.986/0001-21',
            'beneficiario_original' => 'MAGALHAES EDUCACAO BREU BRANCO LTDA',
            'beneficiario' => 'MAGALHAES EDUCACAO LTDA', // 25 chars
            'cidade' => 'BREU BRANCO',
            'cep_original' => '68488-000',
            'cep' => '68488000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'igarape.imepedu.com.br' => [
            'chave_pix' => '12.345.678/0001-90',
            'beneficiario_original' => 'IMEPEDU EDUCACAO - POLO IGARAPE-MIRI',
            'beneficiario' => 'IMEPEDU IGARAPE LTDA',
            'cidade' => 'IGARAPE-MIRI',
            'cep_original' => '68552-000',
            'cep' => '68552000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'tucurui.imepedu.com.br' => [
            'chave_pix' => '12.345.678/0001-90',
            'beneficiario_original' => 'IMEPEDU EDUCACAO - POLO TUCURUI',
            'beneficiario' => 'IMEPEDU TUCURUI LTDA',
            'cidade' => 'TUCURUI',
            'cep_original' => '68455-000',
            'cep' => '68455000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ],
        'moju.imepedu.com.br' => [
            'chave_pix' => '12.345.678/0001-90',
            'beneficiario_original' => 'IMEPEDU EDUCACAO - POLO MOJU',
            'beneficiario' => 'IMEPEDU MOJU LTDA',
            'cidade' => 'MOJU',
            'cep_original' => '68450-000',
            'cep' => '68450000',
            'merchant_category_code' => '8299',
            'country_code' => 'BR',
            'currency' => '986'
        ]
    ];
    
    $configPadrao = [
        'chave_pix' => '12.345.678/0001-90',
        'beneficiario_original' => 'IMEPEDU EDUCACAO',
        'beneficiario' => 'IMEPEDU EDUCACAO LTDA',
        'cidade' => 'BELEM',
        'cep_original' => '66000-000',
        'cep' => '66000000',
        'merchant_category_code' => '8299',
        'country_code' => 'BR',
        'currency' => '986'
    ];
    
    return $configuracoes[$subdomain] ?? $configPadrao;
}

function gerarCodigoPIXValidado($boleto, $configPIX) {
    $identificador = 'BOL' . str_pad($boleto['id'], 10, '0', STR_PAD_LEFT) . date('His');
    $identificador = substr($identificador, 0, 25); // Máximo 25 chars
    
    $validade = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $payload = montarPayloadPIXValidado($configPIX, $boleto, $identificador);
    
    return [
        'identificador' => $identificador,
        'pix_copia_cola' => $payload,
        'validade' => $validade,
        'valor' => $boleto['valor'],
        'valor_centavos' => intval($boleto['valor'] * 100)
    ];
}

function montarPayloadPIXValidado($config, $boleto, $identificador) {
    function campo($id, $valor) {
        $valor = (string)$valor;
        $tamanho = str_pad(strlen($valor), 2, '0', STR_PAD_LEFT);
        return $id . $tamanho . $valor;
    }
    
    $payload = '';
    
    // 00 - Payload Format Indicator (obrigatório)
    $payload .= campo('00', '01');
    
    // 01 - Point of Initiation Method (obrigatório)
    $payload .= campo('01', '12');
    
    // 26 - Merchant Account Information (obrigatório)
    $merchantAccount = '';
    $merchantAccount .= campo('00', 'BR.GOV.BCB.PIX');
    $merchantAccount .= campo('01', $config['chave_pix']);
    $payload .= campo('26', $merchantAccount);
    
    // 52 - Merchant Category Code (obrigatório)
    $payload .= campo('52', $config['merchant_category_code']);
    
    // 53 - Transaction Currency (obrigatório)
    $payload .= campo('53', $config['currency']);
    
    // 54 - Transaction Amount (obrigatório)
    $valor = number_format($boleto['valor'], 2, '.', '');
    $payload .= campo('54', $valor);
    
    // 58 - Country Code (obrigatório)
    $payload .= campo('58', $config['country_code']);
    
    // 59 - Merchant Name (obrigatório, máximo 25 chars)
    $nome = substr(trim($config['beneficiario']), 0, 25);
    $payload .= campo('59', $nome);
    
    // 60 - Merchant City (obrigatório, máximo 15 chars)
    $cidade = substr(trim($config['cidade']), 0, 15);
    $payload .= campo('60', $cidade);
    
    // 61 - Postal Code (condicional, máximo 9 chars)
    $cep = preg_replace('/[^0-9]/', '', $config['cep']);
    if (strlen($cep) >= 8) {
        $cep = substr($cep, 0, 8);
        $payload .= campo('61', $cep);
    }
    
    // 62 - Additional Data Field Template (condicional)
    if (!empty($identificador)) {
        $additionalData = '';
        $additionalData .= campo('05', substr($identificador, 0, 25));
        $payload .= campo('62', $additionalData);
    }
    
    // 63 - CRC16 (obrigatório, sempre por último)
    $payload .= '6304';
    $crc = calcularCRC16Otimizado($payload);
    $payload .= strtoupper($crc);
    
    return $payload;
}

function calcularCRC16Otimizado($data) {
    $crc = 0xFFFF;
    $poly = 0x1021;
    
    for ($i = 0; $i < strlen($data); $i++) {
        $crc ^= (ord($data[$i]) << 8);
        
        for ($bit = 0; $bit < 8; $bit++) {
            if ($crc & 0x8000) {
                $crc = (($crc << 1) ^ $poly) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }
    
    return sprintf('%04X', $crc);
}

function validarPIX($pixString) {
    $erros = [];
    
    // Verifica comprimento mínimo
    if (strlen($pixString) < 50) {
        $erros[] = 'PIX muito curto (mínimo 50 caracteres)';
    }
    
    // Verifica se inicia corretamente
    if (substr($pixString, 0, 8) !== '00020101') {
        $erros[] = 'PIX deve iniciar com 00020101';
    }
    
    // Verifica se termina com CRC
    if (!preg_match('/6304[0-9A-F]{4}$/', $pixString)) {
        $erros[] = 'CRC16 inválido ou ausente';
    }
    
    // Valida CRC16
    $pixSemCRC = substr($pixString, 0, -4);
    $crcInformado = substr($pixString, -4);
    $crcCalculado = calcularCRC16Otimizado($pixSemCRC . '6304');
    
    if (strtoupper($crcInformado) !== strtoupper($crcCalculado)) {
        $erros[] = "CRC16 incorreto. Esperado: {$crcCalculado}, Informado: {$crcInformado}";
    }
    
    // Verifica campos obrigatórios
    $camposObrigatorios = ['00', '01', '26', '52', '53', '54', '58', '59', '60'];
    $camposEncontrados = [];
    
    $pos = 0;
    while ($pos < strlen($pixSemCRC)) {
        if ($pos + 4 <= strlen($pixSemCRC)) {
            $id = substr($pixSemCRC, $pos, 2);
            $tamanho = (int)substr($pixSemCRC, $pos + 2, 2);
            $camposEncontrados[] = $id;
            $pos += 4 + $tamanho;
        } else {
            break;
        }
    }
    
    foreach ($camposObrigatorios as $campo) {
        if (!in_array($campo, $camposEncontrados)) {
            $erros[] = "Campo obrigatório {$campo} ausente";
        }
    }
    
    return [
        'valido' => empty($erros),
        'erro' => implode('; ', $erros),
        'detalhes' => [
            'comprimento' => strlen($pixString),
            'inicio_correto' => substr($pixString, 0, 8) === '00020101',
            'crc_correto' => strtoupper($crcInformado) === strtoupper($crcCalculado),
            'crc_esperado' => $crcCalculado,
            'crc_informado' => $crcInformado,
            'campos_encontrados' => $camposEncontrados
        ]
    ];
}

function gerarQRCode($pixCopiaCola) {
    try {
        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/';
        $params = [
            'size' => '300x300',
            'data' => $pixCopiaCola,
            'format' => 'png',
            'margin' => '10'
        ];
        
        $qrUrl = $qrApiUrl . '?' . http_build_query($params);
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $imageData = file_get_contents($qrUrl, false, $context);
        
        if ($imageData !== false) {
            return [
                'base64' => 'data:image/png;base64,' . base64_encode($imageData),
                'url' => $qrUrl,
                'size' => strlen($imageData)
            ];
        }
    } catch (Exception $e) {
        error_log("QR Code Error: " . $e->getMessage());
    }
    
    return [
        'base64' => 'data:image/svg+xml;base64,' . base64_encode('<svg width="300" height="300" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="300" fill="white"/><text x="150" y="150" text-anchor="middle" font-size="12">QR Code PIX</text></svg>'),
        'url' => null,
        'size' => 0
    ];
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
        
        return $db->lastInsertId();
        
    } catch (Exception $e) {
        error_log("PIX Save Error: " . $e->getMessage());
        return 'temp_' . time();
    }
}

function registrarLogPIX($boletoId, $pixId, $tipo, $boleto, $erro = null) {
    try {
        $db = (new Database())->getConnection();
        
        $descricao = "PIX {$boleto['numero_boleto']} - {$boleto['aluno_nome']}";
        if ($pixId) $descricao .= " - PIX: {$pixId}";
        if ($erro) $descricao .= " - Erro: {$erro}";
        
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, boleto_id, descricao, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $tipo,
            $_SESSION['admin_id'] ?? $_SESSION['aluno_id'] ?? null,
            $boletoId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (Exception $e) {
        error_log("Log Error: " . $e->getMessage());
    }
}
?>