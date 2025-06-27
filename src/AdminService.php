<?php
/**
 * Sistema de Boletos IMED - Serviço Administrativo CORRIGIDO
 * Arquivo: src/AdminService.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/moodle.php';

class AdminService {
    
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    /**
     * Busca administrador por ID
     */
    public function buscarAdminPorId($adminId) {
        $stmt = $this->db->prepare("
            SELECT * FROM administradores 
            WHERE id = ? AND ativo = 1
        ");
        $stmt->execute([$adminId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca administrador por login
     */
    public function buscarAdminPorLogin($login) {
        $stmt = $this->db->prepare("
            SELECT * FROM administradores 
            WHERE login = ? AND ativo = 1
        ");
        $stmt->execute([$login]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Autentica administrador
     */
    public function autenticarAdmin($login, $senha) {
        $admin = $this->buscarAdminPorLogin($login);
        
        if (!$admin) {
            throw new Exception("Usuário não encontrado");
        }
        
        if (!password_verify($senha, $admin['senha'])) {
            throw new Exception("Senha incorreta");
        }
        
        // Atualiza último acesso (apenas se a coluna existir)
        $this->atualizarUltimoAcesso($admin['id']);
        
        // Log do login
        $this->registrarLog('login_admin', $admin['id'], "Login realizado: {$admin['nome']}");
        
        return $admin;
    }
    
    /**
     * Atualiza último acesso do administrador
     */
    private function atualizarUltimoAcesso($adminId) {
        try {
            // Verifica se a coluna ultimo_acesso existe
            $stmt = $this->db->query("SHOW COLUMNS FROM administradores LIKE 'ultimo_acesso'");
            if ($stmt->rowCount() > 0) {
                // Coluna existe, pode atualizar
                $stmt = $this->db->prepare("
                    UPDATE administradores 
                    SET ultimo_acesso = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$adminId]);
            }
            // Se não existir, simplesmente ignora
        } catch (Exception $e) {
            // Ignora erros de atualização de último acesso
            error_log("Erro ao atualizar último acesso: " . $e->getMessage());
        }
    }
    
    /**
     * Obtém estatísticas gerais do sistema
     */
    public function obterEstatisticasGerais() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_boletos,
                    COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
                    COUNT(CASE WHEN status = 'pendente' THEN 1 END) as boletos_pendentes,
                    COUNT(CASE WHEN status = 'vencido' THEN 1 END) as boletos_vencidos,
                    COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as boletos_cancelados,
                    COALESCE(SUM(valor), 0) as valor_total,
                    COALESCE(SUM(CASE WHEN status = 'pago' THEN COALESCE(valor_pago, valor) ELSE 0 END), 0) as valor_recebido,
                    COALESCE(SUM(CASE WHEN status IN ('pendente', 'vencido') THEN valor ELSE 0 END), 0) as valor_pendente,
                    COUNT(DISTINCT aluno_id) as total_alunos,
                    COUNT(DISTINCT curso_id) as total_cursos
                FROM boletos
            ");
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao obter estatísticas: " . $e->getMessage());
            // Retorna estatísticas vazias em caso de erro
            return [
                'total_boletos' => 0,
                'boletos_pagos' => 0,
                'boletos_pendentes' => 0,
                'boletos_vencidos' => 0,
                'boletos_cancelados' => 0,
                'valor_total' => 0,
                'valor_recebido' => 0,
                'valor_pendente' => 0,
                'total_alunos' => 0,
                'total_cursos' => 0
            ];
        }
    }
    
    /**
     * Obtém estatísticas por polo
     */
    public function obterEstatisticasPolo($polo) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_boletos,
                    COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
                    COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as boletos_pendentes,
                    COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as boletos_vencidos,
                    COALESCE(SUM(b.valor), 0) as valor_total,
                    COALESCE(SUM(CASE WHEN b.status = 'pago' THEN COALESCE(b.valor_pago, b.valor) ELSE 0 END), 0) as valor_recebido,
                    COUNT(DISTINCT b.aluno_id) as total_alunos,
                    COUNT(DISTINCT b.curso_id) as total_cursos
                FROM boletos b
                INNER JOIN cursos c ON b.curso_id = c.id
                WHERE c.subdomain = ?
            ");
            $stmt->execute([$polo]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao obter estatísticas do polo: " . $e->getMessage());
            return [
                'total_boletos' => 0,
                'boletos_pagos' => 0,
                'boletos_pendentes' => 0,
                'boletos_vencidos' => 0,
                'valor_total' => 0,
                'valor_recebido' => 0,
                'total_alunos' => 0,
                'total_cursos' => 0
            ];
        }
    }
    
    /**
     * Busca boletos recentes
     */
    public function buscarBoletosRecentes($limite = 10, $polo = null) {
        try {
            $sql = "
                SELECT b.*, a.nome as aluno_nome, a.cpf, c.nome as curso_nome, c.subdomain,
                       ad.nome as admin_nome
                FROM boletos b
                INNER JOIN alunos a ON b.aluno_id = a.id
                INNER JOIN cursos c ON b.curso_id = c.id
                LEFT JOIN administradores ad ON b.admin_id = ad.id
            ";
            
            $params = [];
            
            if ($polo) {
                $sql .= " WHERE c.subdomain = ?";
                $params[] = $polo;
            }
            
            $sql .= " ORDER BY b.created_at DESC LIMIT ?";
            $params[] = $limite;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar boletos recentes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca alunos recentes
     */
    public function buscarAlunosRecentes($limite = 10, $polo = null) {
        try {
            $sql = "SELECT * FROM alunos";
            $params = [];
            
            if ($polo) {
                $sql .= " WHERE subdomain = ?";
                $params[] = $polo;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT ?";
            $params[] = $limite;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar alunos recentes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca todos os cursos
     */
    public function buscarTodosCursos($polo = null) {
        try {
            $sql = "SELECT * FROM cursos WHERE ativo = 1";
            $params = [];
            
            if ($polo) {
                $sql .= " AND subdomain = ?";
                $params[] = $polo;
            }
            
            $sql .= " ORDER BY nome ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar cursos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca cursos por polo (para API)
     */
    public function buscarCursosPorPolo($polo) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, nome, nome_curto, subdomain 
                FROM cursos 
                WHERE subdomain = ? AND ativo = 1
                ORDER BY nome ASC
            ");
            $stmt->execute([$polo]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar cursos por polo: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Marca boleto como pago
     */
    public function marcarBoletoComoPago($boletoId, $valorPago = null, $dataPagamento = null, $observacoes = '') {
        try {
            $this->db->beginTransaction();
            
            // Busca dados do boleto
            $stmt = $this->db->prepare("
                SELECT b.*, a.nome as aluno_nome, c.nome as curso_nome 
                FROM boletos b
                INNER JOIN alunos a ON b.aluno_id = a.id
                INNER JOIN cursos c ON b.curso_id = c.id
                WHERE b.id = ?
            ");
            $stmt->execute([$boletoId]);
            $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
            
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
            
            // Monta SQL dinamicamente baseado nas colunas disponíveis
            $updateFields = ['status = ?'];
            $updateParams = ['pago'];
            
            // Verifica se as colunas existem antes de usar
            $columns = $this->getTableColumns('boletos');
            
            if (in_array('data_pagamento', $columns)) {
                $updateFields[] = 'data_pagamento = ?';
                $updateParams[] = $dataPagamento;
            }
            
            if (in_array('valor_pago', $columns)) {
                $updateFields[] = 'valor_pago = ?';
                $updateParams[] = $valorPago;
            }
            
            if (in_array('observacoes', $columns)) {
                $updateFields[] = 'observacoes = ?';
                $updateParams[] = $observacoes;
            }
            
            if (in_array('updated_at', $columns)) {
                $updateFields[] = 'updated_at = NOW()';
            }
            
            $updateParams[] = $boletoId;
            
            $sql = "UPDATE boletos SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($updateParams);
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('boleto_pago_admin', $boletoId, "Boleto {$boleto['numero_boleto']} marcado como pago - Valor: R$ {$valorPago}");
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro ao marcar boleto como pago: " . $e->getMessage());
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Cancela boleto
     */
    public function cancelarBoleto($boletoId, $motivo = '') {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                SELECT b.*, a.nome as aluno_nome 
                FROM boletos b
                INNER JOIN alunos a ON b.aluno_id = a.id
                WHERE b.id = ?
            ");
            $stmt->execute([$boletoId]);
            $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$boleto) {
                throw new Exception("Boleto não encontrado");
            }
            
            if ($boleto['status'] === 'pago') {
                throw new Exception("Não é possível cancelar boleto já pago");
            }
            
            // Monta SQL dinamicamente
            $updateFields = ['status = ?'];
            $updateParams = ['cancelado'];
            
            $columns = $this->getTableColumns('boletos');
            
            if (in_array('observacoes', $columns)) {
                $updateFields[] = 'observacoes = ?';
                $updateParams[] = $motivo;
            }
            
            if (in_array('updated_at', $columns)) {
                $updateFields[] = 'updated_at = NOW()';
            }
            
            $updateParams[] = $boletoId;
            
            $sql = "UPDATE boletos SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($updateParams);
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('boleto_cancelado_admin', $boletoId, "Boleto {$boleto['numero_boleto']} cancelado - Motivo: {$motivo}");
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro ao cancelar boleto: " . $e->getMessage());
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Obtém colunas de uma tabela
     */
    private function getTableColumns($tableName) {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM {$tableName}");
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            return $columns;
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Registra log de operação
     */
    private function registrarLog($tipo, $boletoId, $descricao) {
        try {
            // Verifica colunas disponíveis na tabela logs
            $columns = $this->getTableColumns('logs');
            
            $insertFields = ['tipo', 'descricao', 'created_at'];
            $insertValues = ['?', '?', 'NOW()'];
            $insertParams = [$tipo, $descricao];
            
            if (in_array('usuario_id', $columns)) {
                $insertFields[] = 'usuario_id';
                $insertValues[] = '?';
                $insertParams[] = $_SESSION['admin_id'] ?? null;
            }
            
            if (in_array('boleto_id', $columns)) {
                $insertFields[] = 'boleto_id';
                $insertValues[] = '?';
                $insertParams[] = $boletoId;
            }
            
            if (in_array('ip_address', $columns)) {
                $insertFields[] = 'ip_address';
                $insertValues[] = '?';
                $insertParams[] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            }
            
            if (in_array('user_agent', $columns)) {
                $insertFields[] = 'user_agent';
                $insertValues[] = '?';
                $insertParams[] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            }
            
            $sql = "INSERT INTO logs (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($insertParams);
            
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }
}
?>