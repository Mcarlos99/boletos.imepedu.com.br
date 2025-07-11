<?php
/**
 * Sistema de Boletos IMEPEDU - Serviço de Boletos
 * Arquivo: src/BoletoService.php
 * 
 * Classe responsável pelo gerenciamento de boletos
 */

require_once __DIR__ . '/../config/database.php';

class BoletoService {
    
    private $db;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    /**
     * Gera um novo boleto
     */
    public function gerarBoleto($alunoId, $cursoId, $valor, $vencimento, $descricao = '', $referencia = null) {
        try {
            $this->db->beginTransaction();
            
            // Gera número único do boleto
            $numeroBoleto = $this->gerarNumeroBoleto();
            
            // Valida dados
            if ($valor <= 0) {
                throw new Exception("Valor do boleto deve ser maior que zero");
            }
            
            if (strtotime($vencimento) < strtotime(date('Y-m-d'))) {
                throw new Exception("Data de vencimento não pode ser anterior à data atual");
            }
            
            // Verifica se aluno e curso existem
            if (!$this->validarAlunoECurso($alunoId, $cursoId)) {
                throw new Exception("Aluno ou curso não encontrado");
            }
            
            // Insere o boleto
            $stmt = $this->db->prepare("
                INSERT INTO boletos (
                    aluno_id, curso_id, numero_boleto, valor, vencimento, 
                    status, descricao, referencia, created_at
                ) VALUES (?, ?, ?, ?, ?, 'pendente', ?, ?, NOW())
            ");
            
            $stmt->execute([
                $alunoId,
                $cursoId,
                $numeroBoleto,
                $valor,
                $vencimento,
                $descricao,
                $referencia
            ]);
            
            $boletoId = $this->db->lastInsertId();
            
            // Processa dados bancários (código de barras, linha digitável)
            $this->processarDadosBancarios($boletoId);
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('boleto_gerado', $alunoId, $boletoId, "Boleto gerado: {$numeroBoleto}");
            
            return $boletoId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro ao gerar boleto: " . $e->getMessage());
            throw new Exception("Erro ao gerar boleto: " . $e->getMessage());
        }
    }
    
    /**
     * Gera número único do boleto
     */
    private function gerarNumeroBoleto() {
        // Formato: AAAAMMDD + 6 dígitos sequenciais
        $prefixo = date('Ymd');
        
        // Busca último número do dia
        $stmt = $this->db->prepare("
            SELECT numero_boleto 
            FROM boletos 
            WHERE numero_boleto LIKE ? 
            ORDER BY numero_boleto DESC 
            LIMIT 1
        ");
        $stmt->execute([$prefixo . '%']);
        $ultimo = $stmt->fetch();
        
        if ($ultimo) {
            $ultimoSequencial = (int)substr($ultimo['numero_boleto'], 8);
            $novoSequencial = $ultimoSequencial + 1;
        } else {
            $novoSequencial = 1;
        }
        
        return $prefixo . str_pad($novoSequencial, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Processa dados bancários do boleto
     */
    private function processarDadosBancarios($boletoId) {
        // Gera nosso número (simplificado)
        $nossoNumero = str_pad($boletoId, 10, '0', STR_PAD_LEFT);
        
        // Gera código de barras (simplificado - você deve implementar conforme seu banco)
        $codigoBarras = $this->gerarCodigoBarras($boletoId, $nossoNumero);
        
        // Gera linha digitável
        $linhaDigitavel = $this->gerarLinhaDigitavel($codigoBarras);
        
        // Atualiza o boleto
        $stmt = $this->db->prepare("
            UPDATE boletos 
            SET nosso_numero = ?, codigo_barras = ?, linha_digitavel = ?
            WHERE id = ?
        ");
        $stmt->execute([$nossoNumero, $codigoBarras, $linhaDigitavel, $boletoId]);
    }
    
    /**
     * Gera código de barras (implementação simplificada)
     */
    private function gerarCodigoBarras($boletoId, $nossoNumero) {
        // Este é um exemplo simplificado
        // Em produção, você deve implementar conforme as especificações do seu banco
        
        $banco = '001'; // Banco do Brasil
        $moeda = '9';   // Real
        
        // Busca dados do boleto
        $stmt = $this->db->prepare("SELECT valor, vencimento FROM boletos WHERE id = ?");
        $stmt->execute([$boletoId]);
        $boleto = $stmt->fetch();
        
        // Calcula DV (dígito verificador) - implementação simplificada
        $dv = '1';
        
        // Fator de vencimento (dias desde 07/10/1997)
        $dataBase = new DateTime('1997-10-07');
        $dataVencimento = new DateTime($boleto['vencimento']);
        $fatorVencimento = $dataVencimento->diff($dataBase)->days;
        
        // Valor em centavos, com 10 dígitos
        $valorCentavos = str_pad((int)($boleto['valor'] * 100), 10, '0', STR_PAD_LEFT);
        
        // Campo livre (específico do banco - exemplo)
        $campoLivre = '0000' . $nossoNumero . '000000000000000000';
        
        return $banco . $moeda . $dv . str_pad($fatorVencimento, 4, '0', STR_PAD_LEFT) . $valorCentavos . $campoLivre;
    }
    
    /**
     * Gera linha digitável a partir do código de barras
     */
    private function gerarLinhaDigitavel($codigoBarras) {
        // Implementação simplificada
        // Em produção, você deve implementar o algoritmo completo conforme FEBRABAN
        
        $campo1 = substr($codigoBarras, 0, 4) . substr($codigoBarras, 32, 5);
        $dv1 = $this->calcularDV($campo1);
        $campo1Formatado = substr($campo1, 0, 5) . '.' . substr($campo1, 5) . $dv1;
        
        $campo2 = substr($codigoBarras, 37, 10);
        $dv2 = $this->calcularDV($campo2);
        $campo2Formatado = substr($campo2, 0, 5) . '.' . substr($campo2, 5) . $dv2;
        
        $campo3 = substr($codigoBarras, 47, 10);
        $dv3 = $this->calcularDV($campo3);
        $campo3Formatado = substr($campo3, 0, 5) . '.' . substr($campo3, 5) . $dv3;
        
        $campo4 = substr($codigoBarras, 4, 1); // DV do código de barras
        
        $campo5 = substr($codigoBarras, 5, 4) . substr($codigoBarras, 9, 10); // Fator vencimento + valor
        
        return $campo1Formatado . ' ' . $campo2Formatado . ' ' . $campo3Formatado . ' ' . $campo4 . ' ' . $campo5;
    }
    
    /**
     * Calcula dígito verificador (algoritmo módulo 10)
     */
    private function calcularDV($campo) {
        $soma = 0;
        $peso = 2;
        
        for ($i = strlen($campo) - 1; $i >= 0; $i--) {
            $produto = (int)$campo[$i] * $peso;
            $soma += $produto > 9 ? $produto - 9 : $produto;
            $peso = $peso == 2 ? 1 : 2;
        }
        
        $resto = $soma % 10;
        return $resto == 0 ? 0 : 10 - $resto;
    }
    
    /**
     * Busca boletos de um curso específico
     */
    public function buscarBoletosCurso($alunoId, $cursoId) {
        $stmt = $this->db->prepare("
            SELECT b.*, c.nome as curso_nome, a.nome as aluno_nome
            FROM boletos b
            INNER JOIN cursos c ON b.curso_id = c.id
            INNER JOIN alunos a ON b.aluno_id = a.id
            WHERE b.aluno_id = ? AND b.curso_id = ?
            ORDER BY b.vencimento DESC, b.created_at DESC
        ");
        $stmt->execute([$alunoId, $cursoId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca um boleto específico
     */
    public function buscarBoleto($boletoId) {
        $stmt = $this->db->prepare("
            SELECT b.*, a.nome as aluno_nome, a.cpf, a.email,
                   c.nome as curso_nome, c.subdomain
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE b.id = ?
        ");
        $stmt->execute([$boletoId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca todos os boletos de um aluno
     */
    public function buscarBoletosAluno($alunoId, $status = null) {
        $sql = "
            SELECT b.*, c.nome as curso_nome
            FROM boletos b
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE b.aluno_id = ?
        ";
        
        $params = [$alunoId];
        
        if ($status) {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY b.vencimento DESC, b.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Dar baixa em um boleto (marcar como pago)
     */
    public function darBaixa($boletoId, $valorPago = null, $dataPagamento = null, $observacoes = '') {
        try {
            $this->db->beginTransaction();
            
            // Busca dados do boleto
            $boleto = $this->buscarBoleto($boletoId);
            if (!$boleto) {
                throw new Exception("Boleto não encontrado");
            }
            
            if ($boleto['status'] === 'pago') {
                throw new Exception("Boleto já está pago");
            }
            
            if ($boleto['status'] === 'cancelado') {
                throw new Exception("Boleto está cancelado");
            }
            
            // Define valores padrão
            if (!$valorPago) {
                $valorPago = $boleto['valor'];
            }
            
            if (!$dataPagamento) {
                $dataPagamento = date('Y-m-d');
            }
            
            // Atualiza o boleto
            $stmt = $this->db->prepare("
                UPDATE boletos 
                SET status = 'pago', 
                    data_pagamento = ?, 
                    valor_pago = ?,
                    observacoes = ?
                WHERE id = ?
            ");
            $stmt->execute([$dataPagamento, $valorPago, $observacoes, $boletoId]);
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('boleto_pago', $boleto['aluno_id'], $boletoId, "Baixa realizada - Valor: R$ {$valorPago}");
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro ao dar baixa no boleto: " . $e->getMessage());
            throw new Exception("Erro ao processar pagamento: " . $e->getMessage());
        }
    }
    
    /**
     * Cancela um boleto
     */
    public function cancelarBoleto($boletoId, $motivo = '') {
        try {
            $this->db->beginTransaction();
            
            $boleto = $this->buscarBoleto($boletoId);
            if (!$boleto) {
                throw new Exception("Boleto não encontrado");
            }
            
            if ($boleto['status'] === 'pago') {
                throw new Exception("Não é possível cancelar boleto já pago");
            }
            
            $stmt = $this->db->prepare("
                UPDATE boletos 
                SET status = 'cancelado', observacoes = ?
                WHERE id = ?
            ");
            $stmt->execute([$motivo, $boletoId]);
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('boleto_cancelado', $boleto['aluno_id'], $boletoId, "Boleto cancelado - Motivo: {$motivo}");
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro ao cancelar boleto: " . $e->getMessage());
            throw new Exception("Erro ao cancelar boleto: " . $e->getMessage());
        }
    }
    
    /**
     * Atualiza status de boletos vencidos
     */
    public function atualizarStatusVencidos() {
        $stmt = $this->db->prepare("
            UPDATE boletos 
            SET status = 'vencido' 
            WHERE status = 'pendente' 
            AND vencimento < CURDATE()
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Atualiza status de um boleto específico para vencido
     */
    public function atualizarStatusVencido($boletoId) {
        $stmt = $this->db->prepare("
            UPDATE boletos 
            SET status = 'vencido' 
            WHERE id = ? AND status = 'pendente' AND vencimento < CURDATE()
        ");
        $stmt->execute([$boletoId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Busca boletos por período
     */
    public function buscarBoletosPorPeriodo($dataInicio, $dataFim, $status = null, $subdomain = null) {
        $sql = "
            SELECT b.*, a.nome as aluno_nome, a.cpf, c.nome as curso_nome, c.subdomain
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE b.vencimento BETWEEN ? AND ?
        ";
        
        $params = [$dataInicio, $dataFim];
        
        if ($status) {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }
        
        if ($subdomain) {
            $sql .= " AND c.subdomain = ?";
            $params[] = $subdomain;
        }
        
        $sql .= " ORDER BY b.vencimento DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca boletos recentes para administração
     */
    public function buscarBoletosRecentes($limite = 10, $subdomain = null) {
        $sql = "
            SELECT b.*, a.nome as aluno_nome, a.cpf, c.nome as curso_nome, c.subdomain
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
        ";
        
        $params = [];
        
        if ($subdomain) {
            $sql .= " WHERE c.subdomain = ?";
            $params[] = $subdomain;
        }
        
        $sql .= " ORDER BY b.created_at DESC LIMIT ?";
        $params[] = $limite;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém estatísticas gerais
     */
    public function obterEstatisticas($subdomain = null) {
        $whereClause = $subdomain ? "WHERE c.subdomain = ?" : "";
        $params = $subdomain ? [$subdomain] : [];
        
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_boletos,
                COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
                COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as boletos_pendentes,
                COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as boletos_vencidos,
                COUNT(CASE WHEN b.status = 'cancelado' THEN 1 END) as boletos_cancelados,
                SUM(b.valor) as valor_total,
                SUM(CASE WHEN b.status = 'pago' THEN b.valor_pago ELSE 0 END) as valor_recebido,
                SUM(CASE WHEN b.status IN ('pendente', 'vencido') THEN b.valor ELSE 0 END) as valor_pendente
            FROM boletos b
            INNER JOIN cursos c ON b.curso_id = c.id
            {$whereClause}
        ");
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Valida se aluno e curso existem e estão relacionados
     */
    private function validarAlunoECurso($alunoId, $cursoId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM matriculas m
            INNER JOIN alunos a ON m.aluno_id = a.id
            INNER JOIN cursos c ON m.curso_id = c.id
            WHERE m.aluno_id = ? AND m.curso_id = ? AND m.status = 'ativa'
        ");
        $stmt->execute([$alunoId, $cursoId]);
        
        return $stmt->fetch()['count'] > 0;
    }
    
    /**
     * Gera boletos em lote para um curso
     */
    public function gerarBoletosLote($cursoId, $valor, $vencimento, $descricao = '', $referencia = null) {
        try {
            $this->db->beginTransaction();
            
            // Busca todos os alunos ativos do curso
            $stmt = $this->db->prepare("
                SELECT DISTINCT m.aluno_id
                FROM matriculas m
                WHERE m.curso_id = ? AND m.status = 'ativa'
            ");
            $stmt->execute([$cursoId]);
            $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $boletosGerados = 0;
            $erros = [];
            
            foreach ($alunos as $aluno) {
                try {
                    $this->gerarBoleto($aluno['aluno_id'], $cursoId, $valor, $vencimento, $descricao, $referencia);
                    $boletosGerados++;
                } catch (Exception $e) {
                    $erros[] = "Aluno ID {$aluno['aluno_id']}: " . $e->getMessage();
                }
            }
            
            $this->db->commit();
            
            return [
                'boletos_gerados' => $boletosGerados,
                'total_alunos' => count($alunos),
                'erros' => $erros
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception("Erro na geração em lote: " . $e->getMessage());
        }
    }
    
    /**
     * Registra log de operação
     */
    private function registrarLog($tipo, $alunoId, $boletoId, $descricao) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO logs (tipo, usuario_id, boleto_id, descricao, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tipo,
                $alunoId,
                $boletoId,
                $descricao,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }
    
    /**
     * Limpa dados antigos
     */
    public function limpezaManutencao() {
        try {
            // Remove boletos cancelados antigos (mais de 1 ano)
            $stmt = $this->db->prepare("
                DELETE FROM boletos 
                WHERE status = 'cancelado' 
                AND created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
            ");
            $boletosRemovidos = $stmt->execute() ? $stmt->rowCount() : 0;
            
            return ['boletos_removidos' => $boletosRemovidos];
            
        } catch (Exception $e) {
            error_log("Erro na limpeza de manutenção: " . $e->getMessage());
            return false;
        }
    }
}
?>