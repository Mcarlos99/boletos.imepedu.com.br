<?php
/**
 * Sistema de Boletos IMEPEDU - Serviço de Usuários Administrativos
 * Arquivo: src/UsuarioAdminService.php
 */

require_once __DIR__ . '/../config/database.php';

class UsuarioAdminService {
    
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    /**
     * Verifica se usuário pode gerenciar outros usuários
     */
    public function podeGerenciarUsuarios($usuario) {
        return $usuario['nivel_acesso'] === 'super_admin';
    }
    
    /**
     * Verifica se usuário tem permissão específica
     */
    public function temPermissao($usuario, $permissao) {
        // Super admin tem todas as permissões
        if ($usuario['nivel_acesso'] === 'super_admin') {
            return true;
        }
        
        // Verifica permissões específicas
        if (!empty($usuario['permissoes'])) {
            $permissoes = json_decode($usuario['permissoes'], true);
            return isset($permissoes[$permissao]) && $permissoes[$permissao];
        }
        
        return false;
    }
    
    /**
     * Aplica filtros de polo nas consultas
     */
    public function aplicarFiltroPolo($query, $usuario, $alias = '') {
        if ($usuario['nivel_acesso'] !== 'super_admin' && !empty($usuario['polo_restrito'])) {
            $poloField = $alias ? "{$alias}.subdomain" : "subdomain";
            return "{$query} AND {$poloField} = '{$usuario['polo_restrito']}'";
        }
        return $query;
    }
    
    /**
     * Lista usuários com filtros
     */
    public function listarUsuarios($filtros = [], $usuarioAtual = null) {
        $where = ['1=1'];
        $params = [];
        
        // Aplica filtros
        if (!empty($filtros['polo'])) {
            $where[] = "polo_restrito = ?";
            $params[] = $filtros['polo'];
        }
        
        if (!empty($filtros['nivel'])) {
            $where[] = "nivel_acesso = ?";
            $params[] = $filtros['nivel'];
        }
        
        if (!empty($filtros['busca'])) {
            $where[] = "(nome LIKE ? OR email LIKE ? OR login LIKE ?)";
            $busca = '%' . $filtros['busca'] . '%';
            $params[] = $busca;
            $params[] = $busca;
            $params[] = $busca;
        }
        
        // Se não é super admin, mostra apenas usuários do mesmo polo
        if ($usuarioAtual && $usuarioAtual['nivel_acesso'] !== 'super_admin') {
            if (!empty($usuarioAtual['polo_restrito'])) {
                $where[] = "(polo_restrito = ? OR polo_restrito IS NULL)";
                $params[] = $usuarioAtual['polo_restrito'];
            }
        }
        
        $whereClause = implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT * FROM administradores 
            WHERE {$whereClause}
            ORDER BY nome ASC
        ");
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Cria novo usuário
     */
    public function criarUsuario($dados) {
        try {
            $this->db->beginTransaction();
            
            // Valida se login já existe
            $stmt = $this->db->prepare("SELECT id FROM administradores WHERE login = ?");
            $stmt->execute([$dados['login']]);
            if ($stmt->fetch()) {
                throw new Exception("Login já existe");
            }
            
            // Valida se email já existe
            $stmt = $this->db->prepare("SELECT id FROM administradores WHERE email = ?");
            $stmt->execute([$dados['email']]);
            if ($stmt->fetch()) {
                throw new Exception("Email já cadastrado");
            }
            
            // Prepara permissões
            $permissoes = null;
            if ($dados['nivel_acesso'] !== 'super_admin' && isset($dados['permissoes'])) {
                $permissoes = json_encode($dados['permissoes']);
            }
            
            // Insere usuário
            $stmt = $this->db->prepare("
                INSERT INTO administradores (
                    nome, email, login, senha, nivel, nivel_acesso, 
                    polo_restrito, permissoes, ativo, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $stmt->execute([
                $dados['nome'],
                $dados['email'],
                $dados['login'],
                password_hash($dados['senha'], PASSWORD_DEFAULT),
                'admin', // nivel padrão
                $dados['nivel_acesso'],
                $dados['polo_restrito'] ?? null,
                $permissoes
            ]);
            
            $userId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            // Log
            $this->registrarLog('criar_usuario', $userId, "Usuário {$dados['nome']} criado");
            
            return $userId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Edita usuário existente
     */
    public function editarUsuario($dados) {
        try {
            $this->db->beginTransaction();
            
            // Busca usuário atual
            $stmt = $this->db->prepare("SELECT * FROM administradores WHERE id = ?");
            $stmt->execute([$dados['usuario_id']]);
            $usuario = $stmt->fetch();
            
            if (!$usuario) {
                throw new Exception("Usuário não encontrado");
            }
            
            // Prepara campos para atualização
            $campos = [
                'nome = ?',
                'email = ?',
                'nivel_acesso = ?',
                'polo_restrito = ?',
                'permissoes = ?',
                'ativo = ?',
                'updated_at = NOW()'
            ];
            $valores = [
                $dados['nome'],
                $dados['email'],
                $dados['nivel_acesso'],
                $dados['polo_restrito'] ?? null,
                isset($dados['permissoes']) ? json_encode($dados['permissoes']) : null,
                isset($dados['ativo']) ? 1 : 0
            ];
            
            // Se tem nova senha
            if (!empty($dados['nova_senha'])) {
                $campos[] = 'senha = ?';
                $valores[] = password_hash($dados['nova_senha'], PASSWORD_DEFAULT);
            }
            
            $valores[] = $dados['usuario_id'];
            
            $sql = "UPDATE administradores SET " . implode(', ', $campos) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($valores);
            
            $this->db->commit();
            
            // Log
            $this->registrarLog('editar_usuario', $dados['usuario_id'], "Usuário {$usuario['nome']} editado");
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Exclui usuário (soft delete)
     */
    public function excluirUsuario($usuarioId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE administradores 
                SET ativo = 0, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$usuarioId]);
            
            // Log
            $this->registrarLog('excluir_usuario', $usuarioId, "Usuário desativado");
            
            return true;
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Reseta senha do usuário
     */
    public function resetarSenha($usuarioId) {
        try {
            // Gera nova senha aleatória
            $novaSenha = $this->gerarSenhaAleatoria();
            
            $stmt = $this->db->prepare("
                UPDATE administradores 
                SET senha = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([
                password_hash($novaSenha, PASSWORD_DEFAULT),
                $usuarioId
            ]);
            
            // Log
            $this->registrarLog('resetar_senha', $usuarioId, "Senha resetada");
            
            return $novaSenha;
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Busca usuário por ID
     */
    public function buscarUsuarioPorId($usuarioId) {
        $stmt = $this->db->prepare("SELECT * FROM administradores WHERE id = ?");
        $stmt->execute([$usuarioId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém label do nível de acesso
     */
    public function getNivelLabel($nivel) {
        $labels = [
            'super_admin' => 'Super Admin',
            'admin_polo' => 'Admin Polo',
            'visualizador' => 'Visualizador'
        ];
        return $labels[$nivel] ?? $nivel;
    }
    
    /**
     * Obtém label da permissão
     */
    public function getPermissaoLabel($permissao) {
        $labels = [
            'ver_boletos' => 'Ver Boletos',
            'criar_boletos' => 'Criar Boletos',
            'editar_boletos' => 'Editar Boletos',
            'ver_alunos' => 'Ver Alunos',
            'editar_alunos' => 'Editar Alunos',
            'sincronizar' => 'Sincronizar',
            'ver_relatorios' => 'Ver Relatórios',
            'exportar_dados' => 'Exportar Dados',
            'ver_logs' => 'Ver Logs'
        ];
        return $labels[$permissao] ?? $permissao;
    }
    
    /**
     * Gera senha aleatória
     */
    private function gerarSenhaAleatoria($tamanho = 8) {
        $caracteres = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $senha = '';
        for ($i = 0; $i < $tamanho; $i++) {
            $senha .= $caracteres[rand(0, strlen($caracteres) - 1)];
        }
        return $senha;
    }
    
    /**
     * Registra log
     */
    private function registrarLog($tipo, $usuarioId, $descricao) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO logs (tipo, usuario_id, descricao, ip_address, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tipo,
                $_SESSION['admin_id'] ?? null,
                $descricao,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }
    
    /**
     * Valida permissões para ações específicas
     */
    public function validarPermissaoAcao($usuario, $acao, $recurso = null) {
        // Super admin tem todas as permissões
        if ($usuario['nivel_acesso'] === 'super_admin') {
            return true;
        }
        
        // Mapa de ações para permissões
        $mapaPermissoes = [
            'ver_boletos' => 'ver_boletos',
            'criar_boleto' => 'criar_boletos',
            'editar_boleto' => 'editar_boletos',
            'marcar_pago' => 'editar_boletos',
            'cancelar_boleto' => 'editar_boletos',
            'ver_alunos' => 'ver_alunos',
            'editar_aluno' => 'editar_alunos',
            'sincronizar_aluno' => 'sincronizar',
            'sincronizar_curso' => 'sincronizar',
            'ver_relatorios' => 'ver_relatorios',
            'exportar_relatorio' => 'exportar_dados',
            'ver_logs' => 'ver_logs'
        ];
        
        // Verifica se a ação tem permissão mapeada
        if (!isset($mapaPermissoes[$acao])) {
            return false;
        }
        
        // Verifica se tem a permissão
        if (!$this->temPermissao($usuario, $mapaPermissoes[$acao])) {
            return false;
        }
        
        // Se tem recurso com polo, verifica se é do mesmo polo
        if ($recurso && isset($recurso['subdomain']) && !empty($usuario['polo_restrito'])) {
            return $recurso['subdomain'] === $usuario['polo_restrito'];
        }
        
        return true;
    }
    
    /**
     * Obtém estatísticas de usuários
     */
    public function obterEstatisticasUsuarios() {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN nivel_acesso = 'super_admin' THEN 1 END) as super_admins,
                COUNT(CASE WHEN nivel_acesso = 'admin_polo' THEN 1 END) as admins_polo,
                COUNT(CASE WHEN nivel_acesso = 'visualizador' THEN 1 END) as visualizadores,
                COUNT(CASE WHEN ativo = 1 THEN 1 END) as ativos,
                COUNT(CASE WHEN ativo = 0 THEN 1 END) as inativos
            FROM administradores
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>