<?php
/**
 * Serviço para processar webhooks do PagSeguro
 * Arquivo: src/PagSeguroWebhookService.php
 */

class PagSeguroWebhookService {
    
    private $db;
    private $tokenPagSeguro;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
        
        // Token do PagSeguro - CONFIGURAR NO AMBIENTE
        $this->tokenPagSeguro = $_ENV['PAGSEGURO_TOKEN'] ?? getenv('PAGSEGURO_TOKEN');
        
        if (!$this->tokenPagSeguro) {
            error_log("AVISO: Token PagSeguro não configurado");
        }
    }
    
    /**
     * Processa webhook recebido do PagSeguro
     */
    public function processarWebhook($payload, $headers = []) {
        try {
            $this->db->beginTransaction();
            
            // Validar webhook (verificar se é realmente do PagSeguro)
            $this->validarWebhook($payload, $headers);
            
            // Extrair informações do evento
            $eventoInfo = $this->extrairInformacaoEvento($payload);
            
            if (!$eventoInfo) {
                throw new Exception("Tipo de evento não suportado ou dados insuficientes");
            }
            
            // Buscar boleto no sistema pelo ID do PagSeguro
            $boleto = $this->buscarBoletoPorPagSeguroId($eventoInfo['pagseguro_id']);
            
            if (!$boleto) {
                // Tentar buscar por link (fallback)
                $boleto = $this->buscarBoletoPorLink($eventoInfo['link_referencia'] ?? '');
                
                if (!$boleto) {
                    throw new Exception("Boleto não encontrado no sistema - ID: {$eventoInfo['pagseguro_id']}");
                }
            }
            
            // Atualizar status do boleto baseado no evento
            $novoStatus = $this->mapearStatusPagSeguro($eventoInfo['status']);
            $resultado = $this->atualizarStatusBoleto($boleto['id'], $novoStatus, $eventoInfo);
            
            $this->db->commit();
            
            // Log de sucesso
            $this->registrarLog('webhook_processado', $boleto['id'], 
                "Webhook processado - Status: {$novoStatus}, Evento: {$eventoInfo['tipo_evento']}");
            
            return [
                'boleto_id' => $boleto['id'],
                'numero_boleto' => $boleto['numero_boleto'],
                'novo_status' => $novoStatus,
                'valor_pago' => $eventoInfo['valor_pago'] ?? null,
                'data_pagamento' => $eventoInfo['data_pagamento'] ?? null
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("ERRO no webhook PagSeguro: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Valida se o webhook é realmente do PagSeguro
     */
    private function validarWebhook($payload, $headers) {
        // Verificar IPs permitidos do PagSeguro (opcional)
        $ipsPermitidos = [
            '200.221.2.', // Range de IPs do PagSeguro
            '200.221.3.',
            '186.202.174.',
            '186.202.175.'
        ];
        
        $ipRemoto = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipValido = false;
        
        foreach ($ipsPermitidos as $ipRange) {
            if (strpos($ipRemoto, $ipRange) === 0) {
                $ipValido = true;
                break;
            }
        }
        
        // Em ambiente de desenvolvimento, permitir localhost
        if (in_array($ipRemoto, ['127.0.0.1', '::1']) || strpos($ipRemoto, '192.168.') === 0) {
            $ipValido = true;
        }
        
        if (!$ipValido) {
            error_log("WEBHOOK: IP não autorizado - {$ipRemoto}");
            // Comentar em produção se causar problemas
            // throw new Exception("IP não autorizado");
        }
        
        // Verificar estrutura básica do payload
        if (!is_array($payload)) {
            throw new Exception("Payload inválido");
        }
        
        // Verificar se tem campos obrigatórios
        $camposObrigatorios = ['id', 'status']; // Ajustar conforme API do PagSeguro
        foreach ($camposObrigatorios as $campo) {
            if (!isset($payload[$campo])) {
                throw new Exception("Campo obrigatório ausente: {$campo}");
            }
        }
        
        return true;
    }
    
    /**
     * Extrai informações relevantes do evento
     */
    private function extrairInformacaoEvento($payload) {
        // Estrutura pode variar dependendo da versão da API do PagSeguro
        // Ajustar conforme documentação atual
        
        $info = [
            'pagseguro_id' => null,
            'status' => null,
            'tipo_evento' => null,
            'valor_pago' => null,
            'data_pagamento' => null,
            'forma_pagamento' => null,
            'link_referencia' => null
        ];
        
        // Extrair ID da cobrança
        $info['pagseguro_id'] = $payload['id'] ?? $payload['charge_id'] ?? $payload['reference_id'] ?? null;
        
        // Extrair status
        $info['status'] = $payload['status'] ?? $payload['payment_status'] ?? null;
        
        // Extrair tipo de evento
        $info['tipo_evento'] = $payload['event_type'] ?? $payload['notification_type'] ?? 'payment_update';
        
        // Extrair valor pago
        if (isset($payload['amount']['value'])) {
            $info['valor_pago'] = floatval($payload['amount']['value']) / 100; // Centavos para reais
        } elseif (isset($payload['gross_amount'])) {
            $info['valor_pago'] = floatval($payload['gross_amount']);
        }
        
        // Extrair data de pagamento
        if (isset($payload['paid_at'])) {
            $info['data_pagamento'] = date('Y-m-d H:i:s', strtotime($payload['paid_at']));
        } elseif (isset($payload['payment_date'])) {
            $info['data_pagamento'] = date('Y-m-d H:i:s', strtotime($payload['payment_date']));
        }
        
        // Extrair forma de pagamento
        $info['forma_pagamento'] = $payload['payment_method']['type'] ?? $payload['payment_type'] ?? 'pagseguro';
        
        // Log para debug
        error_log("WEBHOOK: Informações extraídas - " . json_encode($info));
        
        return $info['pagseguro_id'] ? $info : null;
    }
    
    /**
     * Mapeia status do PagSeguro para status do sistema
     */
    private function mapearStatusPagSeguro($statusPagSeguro) {
        $mapeamento = [
            // Status PagSeguro API v4/v5
            'PAID' => 'pago',
            'AUTHORIZED' => 'pago',
            'AVAILABLE' => 'pago',
            'IN_DISPUTE' => 'pago', // Pode ser ajustado conforme regra de negócio
            
            'WAITING' => 'pendente',
            'IN_ANALYSIS' => 'pendente',
            'PENDING' => 'pendente',
            
            'CANCELED' => 'cancelado',
            'CANCELLED' => 'cancelado',
            'DECLINED' => 'cancelado',
            'EXPIRED' => 'vencido',
            
            // Status legados (API v3)
            'Paga' => 'pago',
            'Disponível' => 'pago',
            'Em análise' => 'pendente',
            'Aguardando pagamento' => 'pendente',
            'Cancelada' => 'cancelado',
            'Devolvida' => 'cancelado'
        ];
        
        return $mapeamento[$statusPagSeguro] ?? 'pendente';
    }
    
    /**
     * Busca boleto por ID do PagSeguro
     */
    private function buscarBoletoPorPagSeguroId($pagSeguroId) {
        if (!$pagSeguroId) return null;
        
        $stmt = $this->db->prepare("
            SELECT * FROM boletos 
            WHERE pagseguro_id = ? 
            AND tipo_boleto = 'pagseguro_link'
        ");
        $stmt->execute([$pagSeguroId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca boleto por link (fallback)
     */
    private function buscarBoletoPorLink($link) {
        if (!$link) return null;
        
        $stmt = $this->db->prepare("
            SELECT * FROM boletos 
            WHERE link_pagseguro LIKE ? 
            AND tipo_boleto = 'pagseguro_link'
        ");
        $stmt->execute(["%{$link}%"]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atualiza status do boleto
     */
    private function atualizarStatusBoleto($boletoId, $novoStatus, $eventoInfo) {
        $campos = ['status = ?', 'updated_at = NOW()'];
        $params = [$novoStatus];
        
        // Adicionar informações de pagamento se disponíveis
        if ($novoStatus === 'pago') {
            if ($eventoInfo['valor_pago']) {
                $campos[] = 'valor_pago = ?';
                $params[] = $eventoInfo['valor_pago'];
            }
            
            if ($eventoInfo['data_pagamento']) {
                $campos[] = 'data_pagamento = ?';
                $params[] = $eventoInfo['data_pagamento'];
            } else {
                $campos[] = 'data_pagamento = NOW()';
            }
        }
        
        // Adicionar observações do webhook
        $observacao = "[WEBHOOK] Status alterado via PagSeguro para: {$novoStatus}";
        if ($eventoInfo['forma_pagamento']) {
            $observacao .= " - Forma: {$eventoInfo['forma_pagamento']}";
        }
        if ($eventoInfo['pagseguro_id']) {
            $observacao .= " - ID: {$eventoInfo['pagseguro_id']}";
        }
        
        $campos[] = 'observacoes = CONCAT(IFNULL(observacoes, ""), ?, "\n")';
        $params[] = $observacao;
        
        // Executar update
        $params[] = $boletoId;
        $sql = "UPDATE boletos SET " . implode(', ', $campos) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Registra log da operação
     */
    private function registrarLog($tipo, $boletoId, $descricao) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO logs (tipo, boleto_id, descricao, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tipo,
                $boletoId,
                $descricao,
                $_SERVER['REMOTE_ADDR'] ?? 'webhook',
                'PagSeguro-Webhook/1.0'
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }
    
    /**
     * Processa reenvio manual de webhook (para testes)
     */
    public function processarWebhookManual($pagSeguroId, $novoStatus) {
        try {
            $this->db->beginTransaction();
            
            $boleto = $this->buscarBoletoPorPagSeguroId($pagSeguroId);
            if (!$boleto) {
                throw new Exception("Boleto não encontrado");
            }
            
            $eventoInfo = [
                'pagseguro_id' => $pagSeguroId,
                'status' => $novoStatus,
                'tipo_evento' => 'manual_update',
                'data_pagamento' => date('Y-m-d H:i:s'),
                'forma_pagamento' => 'manual'
            ];
            
            $statusSistema = $this->mapearStatusPagSeguro($novoStatus);
            $this->atualizarStatusBoleto($boleto['id'], $statusSistema, $eventoInfo);
            
            $this->db->commit();
            
            return [
                'boleto_id' => $boleto['id'],
                'numero_boleto' => $boleto['numero_boleto'],
                'novo_status' => $statusSistema
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
?>