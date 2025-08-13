<?php
/**
 * Página para configurar webhooks e testes
 * Arquivo: admin/pagseguro-webhook.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../src/PagSeguroWebhookService.php';

$webhookService = new PagSeguroWebhookService();
$sucesso = '';
$erro = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['acao'])) {
            switch ($_POST['acao']) {
                case 'teste_webhook':
                    $resultado = $webhookService->processarWebhookManual(
                        $_POST['pagseguro_id'],
                        $_POST['status_teste']
                    );
                    $sucesso = "Webhook de teste processado! Boleto {$resultado['numero_boleto']} -> Status: {$resultado['novo_status']}";
                    break;
            }
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// URLs do webhook
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$webhookUrl = $baseUrl . '/webhooks/pagseguro-callback.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhooks PagSeguro - IMEPEDU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-4">
    
    <div class="row">
        <div class="col-md-8">
            <h2><i class="fas fa-webhook"></i> Configuração de Webhooks PagSeguro</h2>
        </div>
        <div class="col-md-4 text-end">
            <a href="/admin/pagseguro-links.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Voltar aos Links
            </a>
        </div>
    </div>
    
    <!-- Alertas -->
    <?php if ($sucesso): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>
    
    <!-- Configuração -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-cog"></i> URL do Webhook</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle"></i> Configure esta URL no painel do PagSeguro:</h6>
                <div class="input-group">
                    <input type="text" class="form-control" value="<?= $webhookUrl ?>" readonly>
                    <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText('<?= $webhookUrl ?>')">
                        <i class="fas fa-copy"></i> Copiar
                    </button>
                </div>
            </div>
            
            <h6>Passos para configurar:</h6>
            <ol>
                <li>Acesse o painel do PagSeguro/PagBank</li>
                <li>Vá em "Configurações" → "Webhooks" ou "Notificações"</li>
                <li>Adicione a URL acima</li>
                <li>Selecione os eventos: "Pagamento", "Cancelamento", "Estorno"</li>
                <li>Salve as configurações</li>
            </ol>
        </div>
    </div>
    
    <!-- Teste Manual -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-test-tube"></i> Teste Manual</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="acao" value="teste_webhook">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">ID PagSeguro</label>
                            <input type="text" class="form-control" name="pagseguro_id" 
                                   placeholder="exemplo-uuid-12345" required>
                            <small class="form-text text-muted">ID do boleto no sistema PagSeguro</small>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Status de Teste</label>
                            <select class="form-select" name="status_teste" required>
                                <option value="">Selecione o status</option>
                                <option value="PAID">PAID (Pago)</option>
                                <option value="WAITING">WAITING (Aguardando)</option>
                                <option value="CANCELED">CANCELED (Cancelado)</option>
                                <option value="EXPIRED">EXPIRED (Expirado)</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-play"></i> Executar Teste
                </button>
            </form>
        </div>
    </div>
    
    <!-- Logs Recentes -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> Logs de Webhook (Últimos 10)</h5>
        </div>
        <div class="card-body">
            <?php
            // Buscar logs recentes do webhook
            $logFile = __DIR__ . '/../logs/pagseguro-webhook.log';
            if (file_exists($logFile)):
                $logs = array_slice(file($logFile), -10);
                if (!empty($logs)):
            ?>
                <div class="log-container" style="max-height: 400px; overflow-y: auto;">
                    <pre class="small"><?= htmlspecialchars(implode('', array_reverse($logs))) ?></pre>
                </div>
            <?php else: ?>
                <p class="text-muted">Nenhum log de webhook encontrado.</p>
            <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">Arquivo de log não existe ainda.</p>
            <?php endif; ?>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>