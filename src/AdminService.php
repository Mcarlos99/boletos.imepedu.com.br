<?php
/**
 * Sistema de Boletos IMED - Serviço Administrativo
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
        
        // Atualiza último acesso
        $this->atualizarUltimoAcesso($admin['id']);
        
        // Log do login
        $this->registrarLog('login_admin', $admin['id'], "Login realizado: {$admin['nome']}");
        
        return $admin;
    }
    
    /**
     * Atualiza último acesso do administrador
     */
    private function atualizarUltimoAcesso($adminId) {
        $stmt = $this->db->prepare("
            UPDATE administradores 
            SET ultimo_acesso = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$adminId]);
    }
    
    /**
     * Obtém estatísticas gerais do sistema
     */
    public function obterEstatisticasGerais() {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_boletos,
                COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as boletos_pendentes,
                COUNT(CASE WHEN status = 'vencido' THEN 1 END) as boletos_vencidos,
                COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as boletos_cancelados,
                SUM(valor) as valor_total,
                SUM(CASE WHEN status = 'pago' THEN valor_pago ELSE 0 END) as valor_recebido,
                SUM(CASE WHEN status IN ('pendente', 'vencido') THEN valor ELSE 0 END) as valor_pendente,
                COUNT(DISTINCT aluno_id) as total_alunos,
                COUNT(DISTINCT curso_id) as total_cursos
            FROM boletos
        ");
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém estatísticas por polo
     */
    public function obterEstatisticasPolo($polo) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_boletos,
                COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
                COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as boletos_pendentes,
                COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as boletos_vencidos,
                SUM(b.valor) as valor_total,
                SUM(CASE WHEN b.status = 'pago' THEN b.valor_pago ELSE 0 END) as valor_recebido,
                COUNT(DISTINCT b.aluno_id) as total_alunos,
                COUNT(DISTINCT b.curso_id) as total_cursos
            FROM boletos b
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE c.subdomain = ?
        ");
        $stmt->execute([$polo]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca boletos recentes
     */
    public function buscarBoletosRecentes($limite = 10, $polo = null) {
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
    }
    
    /**
     * Busca alunos recentes
     */
    public function buscarAlunosRecentes($limite = 10, $polo = null) {
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
    }
    
    /**
     * Busca todos os cursos
     */
    public function buscarTodosCursos($polo = null) {
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
    }
    
    /**
     * Busca cursos por polo (para API)
     */
    public function buscarCursosPorPolo($polo) {
        $stmt = $this->db->prepare("
            SELECT id, nome, nome_curto, subdomain 
            FROM cursos 
            WHERE subdomain = ? AND ativo = 1
            ORDER BY nome ASC
        ");
        $stmt->execute([$polo]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            
            // Atualiza o boleto
            $stmt = $this->db->prepare("
                UPDATE boletos 
                SET status = 'pago', 
                    data_pagamento = ?, 
                    valor_pago = ?,
                    observacoes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$dataPagamento, $valorPago, $observacoes, $boletoId]);
            
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
            
            $stmt = $this->db->prepare("
                UPDATE boletos 
                SET status = 'cancelado', observacoes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$motivo, $boletoId]);
            
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
     * Busca logs do sistema
     */
    public function buscarLogs($filtros = [], $pagina = 1, $itensPorPagina = 50) {
        $where = ['1=1'];
        $params = [];
        
        // Aplica filtros
        if (!empty($filtros['tipo'])) {
            $where[] = "tipo = ?";
            $params[] = $filtros['tipo'];
        }
        
        if (!empty($filtros['data_inicio'])) {
            $where[] = "DATE(created_at) >= ?";
            $params[] = $filtros['data_inicio'];
        }
        
        if (!empty($filtros['data_fim'])) {
            $where[] = "DATE(created_at) <= ?";
            $params[] = $filtros['data_fim'];
        }
        
        if (!empty($filtros['usuario_id'])) {
            $where[] = "usuario_id = ?";
            $params[] = $filtros['usuario_id'];
        }
        
        if (!empty($filtros['busca'])) {
            $where[] = "descricao LIKE ?";
            $params[] = '%' . $filtros['busca'] . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Conta total
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM logs
            WHERE {$whereClause}
        ");
        $stmtCount->execute($params);
        $total = $stmtCount->fetch()['total'];
        
        // Busca registros
        $offset = ($pagina - 1) * $itensPorPagina;
        $stmt = $this->db->prepare("
            SELECT l.*, a.nome as admin_nome, al.nome as aluno_nome
            FROM logs l
            LEFT JOIN administradores a ON l.usuario_id = a.id AND l.tipo LIKE '%admin%'
            LEFT JOIN alunos al ON l.usuario_id = al.id AND l.tipo NOT LIKE '%admin%'
            WHERE {$whereClause}
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $itensPorPagina;
        $params[] = $offset;
        $stmt->execute($params);
        
        return [
            'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => ceil($total / $itensPorPagina),
            'itens_por_pagina' => $itensPorPagina
        ];
    }
    
    /**
     * Obtém estatísticas de logs
     */
    public function obterEstatisticasLogs($periodo = '30 days') {
        $stmt = $this->db->prepare("
            SELECT 
                tipo,
                COUNT(*) as total,
                DATE(created_at) as data
            FROM logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$periodo})
            GROUP BY tipo, DATE(created_at)
            ORDER BY data DESC, total DESC
        ");
        $stmt->execute();
        
        $logsPorTipo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Resumo por tipo
        $stmt = $this->db->prepare("
            SELECT 
                tipo,
                COUNT(*) as total
            FROM logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$periodo})
            GROUP BY tipo
            ORDER BY total DESC
        ");
        $stmt->execute();
        
        $resumoPorTipo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'por_tipo_e_data' => $logsPorTipo,
            'resumo_por_tipo' => $resumoPorTipo
        ];
    }
    
    /**
     * Gera relatório de boletos
     */
    public function gerarRelatorioBoletos($filtros = []) {
        $where = ['1=1'];
        $params = [];
        
        // Aplica filtros
        if (!empty($filtros['polo'])) {
            $where[] = "c.subdomain = ?";
            $params[] = $filtros['polo'];
        }
        
        if (!empty($filtros['curso_id'])) {
            $where[] = "b.curso_id = ?";
            $params[] = $filtros['curso_id'];
        }
        
        if (!empty($filtros['status'])) {
            $where[] = "b.status = ?";
            $params[] = $filtros['status'];
        }
        
        if (!empty($filtros['data_inicio'])) {
            $where[] = "b.vencimento >= ?";
            $params[] = $filtros['data_inicio'];
        }
        
        if (!empty($filtros['data_fim'])) {
            $where[] = "b.vencimento <= ?";
            $params[] = $filtros['data_fim'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Dados principais
        $stmt = $this->db->prepare("
            SELECT 
                b.*,
                a.nome as aluno_nome,
                a.cpf,
                a.email,
                c.nome as curso_nome,
                c.subdomain,
                ad.nome as admin_nome
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            LEFT JOIN administradores ad ON b.admin_id = ad.id
            WHERE {$whereClause}
            ORDER BY b.vencimento DESC, b.created_at DESC
        ");
        $stmt->execute($params);
        $boletos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estatísticas do relatório
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_boletos,
                COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
                COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as boletos_pendentes,
                COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as boletos_vencidos,
                COUNT(CASE WHEN b.status = 'cancelado' THEN 1 END) as boletos_cancelados,
                SUM(b.valor) as valor_total,
                SUM(CASE WHEN b.status = 'pago' THEN b.valor_pago ELSE 0 END) as valor_recebido,
                SUM(CASE WHEN b.status IN ('pendente', 'vencido') THEN b.valor ELSE 0 END) as valor_pendente,
                AVG(b.valor) as valor_medio,
                COUNT(DISTINCT b.aluno_id) as alunos_distintos,
                COUNT(DISTINCT b.curso_id) as cursos_distintos
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE {$whereClause}
        ");
        $stmt->execute($params);
        $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'boletos' => $boletos,
            'estatisticas' => $estatisticas,
            'filtros_aplicados' => $filtros,
            'gerado_em' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Exporta relatório para CSV
     */
    public function exportarRelatorioCSV($filtros = []) {
        $relatorio = $this->gerarRelatorioBoletos($filtros);
        
        $csv = [];
        $csv[] = [
            'Número Boleto',
            'Aluno Nome',
            'CPF',
            'Email',
            'Curso',
            'Polo',
            'Valor',
            'Vencimento',
            'Status',
            'Data Pagamento',
            'Valor Pago',
            'Criado em',
            'Admin Responsável'
        ];
        
        foreach ($relatorio['boletos'] as $boleto) {
            $csv[] = [
                $boleto['numero_boleto'],
                $boleto['aluno_nome'],
                $boleto['cpf'],
                $boleto['email'],
                $boleto['curso_nome'],
                $boleto['subdomain'],
                number_format($boleto['valor'], 2, ',', '.'),
                date('d/m/Y', strtotime($boleto['vencimento'])),
                ucfirst($boleto['status']),
                $boleto['data_pagamento'] ? date('d/m/Y', strtotime($boleto['data_pagamento'])) : '',
                $boleto['valor_pago'] ? number_format($boleto['valor_pago'], 2, ',', '.') : '',
                date('d/m/Y H:i', strtotime($boleto['created_at'])),
                $boleto['admin_nome'] ?? ''
            ];
        }
        
        return $csv;
    }
    
    /**
     * Busca alunos com filtros
     */
    public function buscarAlunos($filtros = [], $pagina = 1, $itensPorPagina = 20) {
        $where = ['1=1'];
        $params = [];
        
        // Aplica filtros
        if (!empty($filtros['polo'])) {
            $where[] = "a.subdomain = ?";
            $params[] = $filtros['polo'];
        }
        
        if (!empty($filtros['busca'])) {
            $where[] = "(a.nome LIKE ? OR a.cpf LIKE ? OR a.email LIKE ?)";
            $termoBusca = '%' . $filtros['busca'] . '%';
            $params[] = $termoBusca;
            $params[] = $termoBusca;
            $params[] = $termoBusca;
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Conta total
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM alunos a
            WHERE {$whereClause}
        ");
        $stmtCount->execute($params);
        $total = $stmtCount->fetch()['total'];
        
        // Busca registros
        $offset = ($pagina - 1) * $itensPorPagina;
        $stmt = $this->db->prepare("
            SELECT a.*,
                   COUNT(DISTINCT m.curso_id) as total_cursos,
                   COUNT(DISTINCT b.id) as total_boletos,
                   COUNT(CASE WHEN b.status = 'pago' THEN 1 END) as boletos_pagos,
                   COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) as boletos_pendentes,
                   COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) as boletos_vencidos
            FROM alunos a
            LEFT JOIN matriculas m ON a.id = m.aluno_id AND m.status = 'ativa'
            LEFT JOIN boletos b ON a.id = b.aluno_id
            WHERE {$whereClause}
            GROUP BY a.id
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $itensPorPagina;
        $params[] = $offset;
        $stmt->execute($params);
        
        return [
            'alunos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => ceil($total / $itensPorPagina),
            'itens_por_pagina' => $itensPorPagina
        ];
    }
    
    /**
     * Busca detalhes de um aluno
     */
    public function buscarDetalhesAluno($alunoId) {
        // Dados básicos do aluno
        $stmt = $this->db->prepare("SELECT * FROM alunos WHERE id = ?");
        $stmt->execute([$alunoId]);
        $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$aluno) {
            throw new Exception("Aluno não encontrado");
        }
        
        // Cursos matriculados
        $stmt = $this->db->prepare("
            SELECT c.*, m.status as matricula_status, m.data_matricula
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ?
            ORDER BY m.data_matricula DESC
        ");
        $stmt->execute([$alunoId]);
        $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Boletos
        $stmt = $this->db->prepare("
            SELECT b.*, c.nome as curso_nome
            FROM boletos b
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE b.aluno_id = ?
            ORDER BY b.vencimento DESC
        ");
        $stmt->execute([$alunoId]);
        $boletos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estatísticas do aluno
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_boletos,
                COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as boletos_pendentes,
                COUNT(CASE WHEN status = 'vencido' THEN 1 END) as boletos_vencidos,
                SUM(valor) as valor_total,
                SUM(CASE WHEN status = 'pago' THEN valor_pago ELSE 0 END) as valor_pago
            FROM boletos
            WHERE aluno_id = ?
        ");
        $stmt->execute([$alunoId]);
        $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'aluno' => $aluno,
            'cursos' => $cursos,
            'boletos' => $boletos,
            'estatisticas' => $estatisticas
        ];
    }
    
    /**
     * Atualiza status de todos os boletos vencidos
     */
    public function atualizarBoletosVencidos() {
        $stmt = $this->db->prepare("
            UPDATE boletos 
            SET status = 'vencido', updated_at = NOW()
            WHERE status = 'pendente' 
            AND vencimento < CURDATE()
        ");
        $stmt->execute();
        
        $boletosAtualizados = $stmt->rowCount();
        
        // Log da operação
        $this->registrarLog('atualizar_vencidos', null, "Atualização automática: {$boletosAtualizados} boletos marcados como vencidos");
        
        return $boletosAtualizados;
    }
    
    /**
     * Obtém configurações do sistema
     */
    public function obterConfiguracoes() {
        $stmt = $this->db->prepare("SELECT * FROM configuracoes ORDER BY chave");
        $stmt->execute();
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $configuracoes = [];
        foreach ($configs as $config) {
            $configuracoes[$config['chave']] = $config['valor'];
        }
        
        return $configuracoes;
    }
    
    /**
     * Atualiza configuração
     */
    public function atualizarConfiguracao($chave, $valor) {
        $stmt = $this->db->prepare("
            INSERT INTO configuracoes (chave, valor, updated_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            valor = VALUES(valor), updated_at = NOW()
        ");
        $stmt->execute([$chave, $valor]);
        
        // Log da operação
        $this->registrarLog('config_alterada', null, "Configuração '{$chave}' alterada para '{$valor}'");
        
        return true;
    }
    
    /**
     * Executa backup do banco de dados
     */
    public function executarBackup() {
        try {
            $database = new Database();
            $backup = $database->backup();
            
            // Salva arquivo de backup
            $nomeArquivo = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $caminhoBackup = __DIR__ . '/../backups/' . $nomeArquivo;
            
            // Cria diretório se não existir
            $dirBackup = dirname($caminhoBackup);
            if (!is_dir($dirBackup)) {
                mkdir($dirBackup, 0755, true);
            }
            
            file_put_contents($caminhoBackup, $backup);
            
            // Log da operação
            $this->registrarLog('backup_criado', null, "Backup criado: {$nomeArquivo}");
            
            return [
                'sucesso' => true,
                'arquivo' => $nomeArquivo,
                'caminho' => $caminhoBackup,
                'tamanho' => filesize($caminhoBackup)
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao criar backup: " . $e->getMessage());
            throw new Exception("Erro ao criar backup: " . $e->getMessage());
        }
    }
    
    /**
     * Lista backups disponíveis
     */
    public function listarBackups() {
        $dirBackup = __DIR__ . '/../backups/';
        
        if (!is_dir($dirBackup)) {
            return [];
        }
        
        $arquivos = scandir($dirBackup);
        $backups = [];
        
        foreach ($arquivos as $arquivo) {
            if (pathinfo($arquivo, PATHINFO_EXTENSION) === 'sql') {
                $caminhoCompleto = $dirBackup . $arquivo;
                $backups[] = [
                    'nome' => $arquivo,
                    'tamanho' => filesize($caminhoCompleto),
                    'data' => filemtime($caminhoCompleto),
                    'data_formatada' => date('d/m/Y H:i:s', filemtime($caminhoCompleto))
                ];
            }
        }
        
        // Ordena por data (mais recente primeiro)
        usort($backups, function($a, $b) {
            return $b['data'] - $a['data'];
        });
        
        return $backups;
    }
    
    /**
     * Executa limpeza automática
     */
    public function executarLimpeza() {
        $database = new Database();
        $resultados = [];
        
        // Limpa logs antigos (mais de 90 dias)
        $logsRemovidos = $database->cleanupLogs(90);
        $resultados['logs_removidos'] = $logsRemovidos;
        
        // Otimiza tabelas
        $tabelasOtimizadas = $database->optimizeTables();
        $resultados['tabelas_otimizadas'] = $tabelasRemovidos;
        
        // Remove boletos cancelados antigos (mais de 1 ano)
        $stmt = $this->db->prepare("
            DELETE FROM boletos 
            WHERE status = 'cancelado' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
        ");
        $stmt->execute();
        $boletosRemovidos = $stmt->rowCount();
        $resultados['boletos_cancelados_removidos'] = $boletosRemovidos;
        
        // Log da limpeza
        $this->registrarLog('limpeza_sistema', null, "Limpeza automática: {$logsRemovidos} logs, {$boletosRemovidos} boletos cancelados");
        
        return $resultados;
    }
    
    /**
     * Obtém informações do sistema
     */
    public function obterInfoSistema() {
        $database = new Database();
        
        return [
            'versao_php' => PHP_VERSION,
            'banco_dados' => $database->getDatabaseInfo(),
            'servidor' => [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Desconhecido',
                'documento_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
                'servidor_nome' => $_SERVER['SERVER_NAME'] ?? '',
                'memoria_limite' => ini_get('memory_limit'),
                'upload_max' => ini_get('upload_max_filesize'),
                'post_max' => ini_get('post_max_size')
            ],
            'espaco_disco' => [
                'livre' => disk_free_space(__DIR__),
                'total' => disk_total_space(__DIR__)
            ],
            'polos_configurados' => count(MoodleConfig::getAllSubdomains()),
            'polos_ativos' => count(MoodleConfig::getActiveSubdomains())
        ];
    }
    
    /**
     * Registra log de operação
     */
    private function registrarLog($tipo, $boletoId, $descricao) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO logs (tipo, usuario_id, boleto_id, descricao, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tipo,
                $_SESSION['admin_id'] ?? null,
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
     * Cria novo administrador
     */
    public function criarAdmin($dados) {
        try {
            $this->db->beginTransaction();
            
            // Validações
            if (empty($dados['nome']) || empty($dados['login']) || empty($dados['senha'])) {
                throw new Exception("Nome, login e senha são obrigatórios");
            }
            
            // Verifica se login já existe
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM administradores WHERE login = ?");
            $stmt->execute([$dados['login']]);
            
            if ($stmt->fetch()['count'] > 0) {
                throw new Exception("Login já existe");
            }
            
            // Hash da senha
            $senhaHash = password_hash($dados['senha'], PASSWORD_DEFAULT);
            
            // Insere administrador
            $stmt = $this->db->prepare("
                INSERT INTO administradores (nome, login, senha, email, nivel, ativo, created_at) 
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $dados['nome'],
                $dados['login'],
                $senhaHash,
                $dados['email'] ?? null,
                $dados['nivel'] ?? 'admin'
            ]);
            
            $adminId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('admin_criado', null, "Novo administrador criado: {$dados['nome']} ({$dados['login']})");
            
            return $adminId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception($e->getMessage());
        }
    }
}
?>