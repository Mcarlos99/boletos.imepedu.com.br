<?php
/**
 * Sistema de Boletos IMEPEDU - API Download de Boleto PDF
 * Arquivo: api/download-boleto.php
 * 
 * API segura para download de PDFs de boletos com controle de acesso
 */

session_start();

// Headers de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verifica se usuário está logado
if (!isset($_SESSION['aluno_cpf']) && !isset($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

// Verifica método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

// Inclui dependências
require_once '../config/database.php';
require_once '../src/BoletoUploadService.php';

try {
    // Obtém ID do boleto
    $boletoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório e deve ser um número válido');
    }
    
    error_log("Download Boleto: Iniciando download para boleto ID: {$boletoId}");
    
    // Inicializa serviços
    $db = (new Database())->getConnection();
    $uploadService = new BoletoUploadService();
    
    // Busca dados do boleto com validação de acesso
    $stmt = $db->prepare("
        SELECT b.*, 
               a.nome as aluno_nome, 
               a.cpf as aluno_cpf,
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
    
    error_log("Download Boleto: Boleto encontrado - #{$boleto['numero_boleto']}, Aluno: {$boleto['aluno_nome']}");
    
    // Validação de acesso
    $acessoAutorizado = false;
    
    if (isset($_SESSION['admin_id'])) {
        // Administrador tem acesso a todos os boletos
        $acessoAutorizado = true;
        error_log("Download Boleto: Acesso autorizado - Administrador");
        
    } elseif (isset($_SESSION['aluno_cpf'])) {
        // Aluno só pode acessar seus próprios boletos do mesmo polo
        $cpfSessao = preg_replace('/[^0-9]/', '', $_SESSION['aluno_cpf']);
        $cpfBoleto = preg_replace('/[^0-9]/', '', $boleto['aluno_cpf']);
        $subdomainSessao = $_SESSION['subdomain'] ?? '';
        
        if ($cpfSessao === $cpfBoleto && $subdomainSessao === $boleto['curso_subdomain']) {
            $acessoAutorizado = true;
            error_log("Download Boleto: Acesso autorizado - Aluno proprietário");
        } else {
            error_log("Download Boleto: Acesso negado - CPF/Polo não confere");
            error_log("Download Boleto: Sessão CPF: {$cpfSessao}, Boleto CPF: {$cpfBoleto}");
            error_log("Download Boleto: Sessão Polo: {$subdomainSessao}, Boleto Polo: {$boleto['curso_subdomain']}");
        }
    }
    
    if (!$acessoAutorizado) {
        throw new Exception('Você não tem permissão para acessar este boleto');
    }
    
    // Verifica se arquivo PDF existe
    if (empty($boleto['arquivo_pdf'])) {
        // Se não tem arquivo PDF, gera um PDF básico
        error_log("Download Boleto: Arquivo PDF não encontrado, gerando PDF básico");
        $pdfContent = gerarPDFBasico($boleto);
        
        // Headers para download direto
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Boleto_' . $boleto['numero_boleto'] . '.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $pdfContent;
        
        // Registra log do download
        registrarLogDownload($boletoId, 'download_pdf_gerado', $boleto);
        
        exit;
    }
    
    // Caminho do arquivo físico
    $uploadDir = __DIR__ . '/../uploads/boletos/';
    $caminhoArquivo = $uploadDir . $boleto['arquivo_pdf'];
    
    error_log("Download Boleto: Verificando arquivo: {$caminhoArquivo}");
    
    if (!file_exists($caminhoArquivo)) {
        error_log("Download Boleto: Arquivo físico não encontrado: {$caminhoArquivo}");
        
        // Tenta gerar PDF básico como fallback
        $pdfContent = gerarPDFBasico($boleto);
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Boleto_' . $boleto['numero_boleto'] . '.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $pdfContent;
        
        // Registra log do download
        registrarLogDownload($boletoId, 'download_pdf_fallback', $boleto);
        
        exit;
    }
    
    // Verifica se é realmente um PDF
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $caminhoArquivo);
    finfo_close($finfo);
    
    if ($mimeType !== 'application/pdf') {
        error_log("Download Boleto: Arquivo não é PDF: {$mimeType}");
        throw new Exception('Arquivo corrompido. Entre em contato com o suporte.');
    }
    
    // Obtém informações do arquivo
    $tamanhoArquivo = filesize($caminhoArquivo);
    $nomeArquivo = "Boleto_" . $boleto['numero_boleto'] . ".pdf";
    
    error_log("Download Boleto: Iniciando download do arquivo - Tamanho: {$tamanhoArquivo} bytes");
    
    // Headers para download seguro
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Content-Length: ' . $tamanhoArquivo);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    
    // Desabilita buffering para arquivos grandes
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Envia arquivo em chunks para economizar memória
    $handle = fopen($caminhoArquivo, 'rb');
    
    if ($handle === false) {
        throw new Exception('Erro ao abrir arquivo para leitura');
    }
    
    while (!feof($handle)) {
        $buffer = fread($handle, 8192); // 8KB chunks
        echo $buffer;
        
        if (connection_aborted()) {
            error_log("Download Boleto: Download cancelado pelo cliente");
            break;
        }
        
        // Força envio imediato
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    fclose($handle);
    
    // Registra log do download bem-sucedido
    registrarLogDownload($boletoId, 'download_pdf_sucesso', $boleto);
    
    error_log("Download Boleto: Download concluído com sucesso");
    
} catch (Exception $e) {
    error_log("Download Boleto: ERRO - " . $e->getMessage());
    
    // Registra log do erro
    if (isset($boletoId) && isset($boleto)) {
        registrarLogDownload($boletoId, 'download_pdf_erro', $boleto, $e->getMessage());
    }
    
    // Se ainda não enviou headers, retorna JSON
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    } else {
        // Se já enviou headers, não pode retornar JSON
        error_log("Download Boleto: Headers já enviados, não é possível retornar erro JSON");
    }
}

/**
 * Gera PDF básico quando arquivo original não está disponível
 */
function gerarPDFBasico($boleto) {
    // Gera PDF simples usando TCPDF ou similar
    // Para esta implementação, vamos gerar um PDF básico com informações do boleto
    
    // Simula conteúdo PDF básico (em produção, usar biblioteca como TCPDF)
    $pdfHeader = "%PDF-1.4\n";
    $pdfBody = "1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n";
    $pdfBody .= "2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n";
    $pdfBody .= "3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n/Contents 4 0 R\n>>\nendobj\n";
    
    // Conteúdo da página com informações do boleto
    $conteudoPagina = "BT\n/F1 12 Tf\n100 700 Td\n";
    $conteudoPagina .= "(BOLETO DE PAGAMENTO - IMEPEDU EDUCACAO) Tj\n";
    $conteudoPagina .= "0 -20 Td\n";
    $conteudoPagina .= "(Numero: " . $boleto['numero_boleto'] . ") Tj\n";
    $conteudoPagina .= "0 -20 Td\n";
    $conteudoPagina .= "(Aluno: " . $boleto['aluno_nome'] . ") Tj\n";
    $conteudoPagina .= "0 -20 Td\n";
    $conteudoPagina .= "(Curso: " . $boleto['curso_nome'] . ") Tj\n";
    $conteudoPagina .= "0 -20 Td\n";
    $conteudoPagina .= "(Valor: R$ " . number_format($boleto['valor'], 2, ',', '.') . ") Tj\n";
    $conteudoPagina .= "0 -20 Td\n";
    $conteudoPagina .= "(Vencimento: " . date('d/m/Y', strtotime($boleto['vencimento'])) . ") Tj\n";
    $conteudoPagina .= "0 -40 Td\n";
    $conteudoPagina .= "(Este e um boleto temporario gerado pelo sistema.) Tj\n";
    $conteudoPagina .= "(Entre em contato com a secretaria para obter o boleto oficial.) Tj\n";
    $conteudoPagina .= "ET\n";
    
    $pdfBody .= "4 0 obj\n<<\n/Length " . strlen($conteudoPagina) . "\n>>\nstream\n";
    $pdfBody .= $conteudoPagina;
    $pdfBody .= "\nendstream\nendobj\n";
    
    $xrefOffset = strlen($pdfHeader . $pdfBody);
    $pdfXref = "xref\n0 5\n";
    $pdfXref .= "0000000000 65535 f \n";
    $pdfXref .= sprintf("%010d 00000 n \n", strlen($pdfHeader));
    $pdfXref .= sprintf("%010d 00000 n \n", strlen($pdfHeader) + strpos($pdfBody, "2 0 obj"));
    $pdfXref .= sprintf("%010d 00000 n \n", strlen($pdfHeader) + strpos($pdfBody, "3 0 obj"));
    $pdfXref .= sprintf("%010d 00000 n \n", strlen($pdfHeader) + strpos($pdfBody, "4 0 obj"));
    
    $pdfTrailer = "trailer\n<<\n/Size 5\n/Root 1 0 R\n>>\n";
    $pdfTrailer .= "startxref\n" . $xrefOffset . "\n%%EOF\n";
    
    return $pdfHeader . $pdfBody . $pdfXref . $pdfTrailer;
}

/**
 * Registra log do download
 */
function registrarLogDownload($boletoId, $tipo, $boleto, $erro = null) {
    try {
        $db = (new Database())->getConnection();
        
        $descricao = "Download boleto #{$boleto['numero_boleto']} - {$boleto['aluno_nome']}";
        if ($erro) {
            $descricao .= " - Erro: {$erro}";
        }
        
        $usuarioId = null;
        $tipoUsuario = 'guest';
        
        if (isset($_SESSION['admin_id'])) {
            $usuarioId = $_SESSION['admin_id'];
            $tipoUsuario = 'admin';
        } elseif (isset($_SESSION['aluno_id'])) {
            $usuarioId = $_SESSION['aluno_id'];
            $tipoUsuario = 'aluno';
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
        
        error_log("Download Boleto: Log registrado - Tipo: {$tipo}, Usuario: {$tipoUsuario}");
        
    } catch (Exception $e) {
        error_log("Download Boleto: Erro ao registrar log - " . $e->getMessage());
    }
}

/**
 * Função para validar integridade do arquivo PDF
 */
function validarIntegridadePDF($caminhoArquivo) {
    try {
        // Verifica se arquivo existe e é legível
        if (!is_readable($caminhoArquivo)) {
            return false;
        }
        
        // Verifica tamanho mínimo (PDF válido deve ter pelo menos alguns bytes)
        if (filesize($caminhoArquivo) < 100) {
            return false;
        }
        
        // Verifica assinatura PDF
        $handle = fopen($caminhoArquivo, 'rb');
        if ($handle === false) {
            return false;
        }
        
        $header = fread($handle, 8);
        fclose($handle);
        
        // PDF deve começar com %PDF-
        if (strpos($header, '%PDF-') !== 0) {
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Validação PDF: Erro - " . $e->getMessage());
        return false;
    }
}

/**
 * Função para obter estatísticas de download
 */
function obterEstatisticasDownload($boletoId) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_downloads,
                COUNT(CASE WHEN tipo LIKE '%sucesso%' THEN 1 END) as downloads_sucesso,
                COUNT(CASE WHEN tipo LIKE '%erro%' THEN 1 END) as downloads_erro,
                MAX(created_at) as ultimo_download
            FROM logs 
            WHERE boleto_id = ? 
            AND tipo LIKE 'download_pdf_%'
        ");
        $stmt->execute([$boletoId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Estatísticas Download: Erro - " . $e->getMessage());
        return [
            'total_downloads' => 0,
            'downloads_sucesso' => 0,
            'downloads_erro' => 0,
            'ultimo_download' => null
        ];
    }
}

/**
 * Rate limiting para downloads
 */
function verificarRateLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheKey = "download_rate_limit_{$ip}";
    
    // Simula verificação de rate limit
    // Em produção, implementar com Redis ou cache adequado
    $limite = 50; // 50 downloads por hora
    $janela = 3600; // 1 hora
    
    // Para esta implementação, apenas registra
    error_log("Rate Limit: IP {$ip} fazendo download");
    
    return true; // Sempre permite por enquanto
}

// Verifica rate limiting
if (!verificarRateLimit()) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Muitas tentativas de download. Tente novamente mais tarde.'
    ]);
    exit;
}
?>