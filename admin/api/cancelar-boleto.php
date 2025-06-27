<?php
/**
 * Sistema de Boletos IMED - API para cancelar boleto
 * Arquivo: admin/api/cancelar-boleto.php
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
    $boletoId = $input['boleto_id'] ?? null;
    $motivo = $input['motivo'] ?? 'Cancelado pelo administrador';
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório');
    }
    
    $adminService = new AdminService();
    $adminService->cancelarBoleto($boletoId, $motivo);
    
    echo json_encode([
        'success' => true,
        'message' => 'Boleto cancelado com sucesso'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

<?php
/**
 * Sistema de Boletos IMED - API para remover boleto
 * Arquivo: admin/api/remover-boleto.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../src/BoletoUploadService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $boletoId = $input['boleto_id'] ?? null;
    $motivo = $input['motivo'] ?? 'Removido pelo administrador';
    
    if (!$boletoId) {
        throw new Exception('ID do boleto é obrigatório');
    }
    
    $uploadService = new BoletoUploadService();
    $uploadService->removerBoleto($boletoId, $motivo);
    
    echo json_encode([
        'success' => true,
        'message' => 'Boleto removido com sucesso'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

<?php
/**
 * Sistema de Boletos IMED - Página de índice do admin
 * Arquivo: admin/index.php
 */

// Redireciona para login se não estiver logado, ou dashboard se estiver
session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: /admin/dashboard.php');
} else {
    header('Location: /admin/login.php');
}
exit;
?>

<?php
/**
 * Sistema de Boletos IMED - Proteção de arquivos
 * Arquivo: uploads/boletos/.htaccess
 */

// Conteúdo do arquivo .htaccess para a pasta uploads/boletos/
// Este arquivo deve ser criado na pasta uploads/boletos/

# Protege arquivos PDF - apenas administradores autenticados podem acessar
RewriteEngine On

# Bloqueia acesso direto a arquivos
RewriteRule ^.*$ /admin/api/download-boleto.php [L]

# Headers de segurança
<FilesMatch "\.(pdf)$">
    Order deny,allow
    Deny from all
</FilesMatch>

# Permite apenas via script PHP
<Files "download-boleto.php">
    Order allow,deny
    Allow from all
</Files>
?>

<?php
/**
 * Sistema de Boletos IMED - Configuração de upload
 * Arquivo: uploads/boletos/index.php
 */

// Arquivo de proteção - redireciona tentativas de acesso direto
header('Location: /admin/login.php');
exit;
?>