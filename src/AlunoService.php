<?php
/**
 * Sistema de Boletos IMED - Serviço de Alunos
 * Arquivo: src/AlunoService.php
 * 
 * Classe responsável pelo gerenciamento de dados dos alunos
 */

require_once __DIR__ . '/../config/database.php';

class AlunoService {
    
    private $db;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    /**
     * Salva ou atualiza dados do aluno no banco local
     */
    public function salvarOuAtualizarAluno($dadosAluno) {
        try {
            $this->db->beginTransaction();
            
            // Verifica se aluno já existe
            $stmt = $this->db->prepare("
                SELECT id, updated_at 
                FROM alunos 
                WHERE cpf = ? 
                LIMIT 1
            ");
            $stmt->execute([$dadosAluno['cpf']]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                // Atualiza dados do aluno existente
                $alunoId = $this->atualizarAluno($alunoExistente['id'], $dadosAluno);
            } else {
                // Cria novo aluno
                $alunoId = $this->criarAluno($dadosAluno);
            }
            
            // Atualiza/cria cursos e matrículas
            if (!empty($dadosAluno['cursos'])) {
                $this->atualizarCursosAluno($alunoId, $dadosAluno['cursos'], $dadosAluno['subdomain']);
            }
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('aluno_sincronizado', $alunoId, "Dados sincronizados do Moodle: {$dadosAluno['subdomain']}");
            
            return $alunoId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro ao salvar/atualizar aluno: " . $e->getMessage());
            throw new Exception("Erro ao processar dados do aluno");
        }
    }
    
    /**
     * Cria um novo aluno
     */
    private function criarAluno($dadosAluno) {
        $stmt = $this->db->prepare("
            INSERT INTO alunos (
                cpf, nome, email, moodle_user_id, subdomain, 
                city, country, profile_image, primeiro_acesso,
                ultimo_acesso_moodle, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $dadosAluno['cpf'],
            $dadosAluno['nome'],
            $dadosAluno['email'],
            $dadosAluno['moodle_user_id'],
            $dadosAluno['subdomain'],
            $dadosAluno['city'] ?? null,
            $dadosAluno['country'] ?? 'BR',
            $dadosAluno['profile_image'] ?? null,
            $dadosAluno['primeiro_acesso'] ?? null,
            $dadosAluno['ultimo_acesso'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Atualiza dados de um aluno existente
     */
    private function atualizarAluno($alunoId, $dadosAluno) {
        $stmt = $this->db->prepare("
            UPDATE alunos 
            SET nome = ?, email = ?, moodle_user_id = ?, subdomain = ?,
                city = ?, country = ?, profile_image = ?,
                ultimo_acesso_moodle = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $dadosAluno['nome'],
            $dadosAluno['email'],
            $dadosAluno['moodle_user_id'],
            $dadosAluno['subdomain'],
            $dadosAluno['city'] ?? null,
            $dadosAluno['country'] ?? 'BR',
            $dadosAluno['profile_image'] ?? null,
            $dadosAluno['ultimo_acesso'] ?? null,
            $alunoId
        ]);
        
        return $alunoId;
    }
    
    /**
     * Atualiza cursos e matrículas do aluno
     */
    private function atualizarCursosAluno($alunoId, $cursosMoodle, $subdomain) {
        foreach ($cursosMoodle as $cursoMoodle) {
            // Verifica se curso já existe
            $stmt = $this->db->prepare("
                SELECT id FROM cursos 
                WHERE moodle_course_id = ? AND subdomain = ?
                LIMIT 1
            ");
            $stmt->execute([$cursoMoodle['moodle_course_id'], $subdomain]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                $cursoId = $cursoExistente['id'];
                
                // Atualiza dados do curso
                $this->atualizarCurso($cursoId, $cursoMoodle);
            } else {
                // Cria novo curso
                $cursoId = $this->criarCurso($cursoMoodle, $subdomain);
            }
            
            // Verifica se matrícula já existe
            $stmt = $this->db->prepare("
                SELECT id, status FROM matriculas 
                WHERE aluno_id = ? AND curso_id = ?
                LIMIT 1
            ");
            $stmt->execute([$alunoId, $cursoId]);
            $matriculaExistente = $stmt->fetch();
            
            if ($matriculaExistente) {
                // Atualiza matrícula se necessário
                if ($matriculaExistente['status'] !== 'ativa') {
                    $this->atualizarMatricula($matriculaExistente['id'], 'ativa');
                }
            } else {
                // Cria nova matrícula
                $this->criarMatricula($alunoId, $cursoId, $cursoMoodle);
            }
        }
    }
    
    /**
     * Cria um novo curso
     */
    private function criarCurso($cursoMoodle, $subdomain) {
        $stmt = $this->db->prepare("
            INSERT INTO cursos (
                moodle_course_id, nome, nome_curto, valor, subdomain,
                categoria_id, data_inicio, data_fim, formato, summary,
                url, ativo, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $cursoMoodle['moodle_course_id'],
            $cursoMoodle['nome'],
            $cursoMoodle['nome_curto'] ?? '',
            0.00, // Valor padrão, será definido administrativamente
            $subdomain,
            $cursoMoodle['categoria'] ?? null,
            $cursoMoodle['data_inicio'] ?? null,
            $cursoMoodle['data_fim'] ?? null,
            $cursoMoodle['formato'] ?? 'topics',
            $cursoMoodle['summary'] ?? '',
            $cursoMoodle['url'] ?? null,
            true
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Atualiza dados de um curso existente
     */
    private function atualizarCurso($cursoId, $cursoMoodle) {
        $stmt = $this->db->prepare("
            UPDATE cursos 
            SET nome = ?, nome_curto = ?, categoria_id = ?,
                data_inicio = ?, data_fim = ?, formato = ?,
                summary = ?, url = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $cursoMoodle['nome'],
            $cursoMoodle['nome_curto'] ?? '',
            $cursoMoodle['categoria'] ?? null,
            $cursoMoodle['data_inicio'] ?? null,
            $cursoMoodle['data_fim'] ?? null,
            $cursoMoodle['formato'] ?? 'topics',
            $cursoMoodle['summary'] ?? '',
            $cursoMoodle['url'] ?? null,
            $cursoId
        ]);
    }
    
    /**
     * Cria uma nova matrícula
     */
    private function criarMatricula($alunoId, $cursoId, $cursoMoodle) {
        $stmt = $this->db->prepare("
            INSERT INTO matriculas (
                aluno_id, curso_id, status, data_matricula, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        
        $dataMatricula = $cursoMoodle['data_inicio'] ?? date('Y-m-d');
        
        $stmt->execute([
            $alunoId,
            $cursoId,
            'ativa',
            $dataMatricula
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Atualiza status de uma matrícula
     */
    private function atualizarMatricula($matriculaId, $novoStatus) {
        $stmt = $this->db->prepare("
            UPDATE matriculas 
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$novoStatus, $matriculaId]);
    }
    
    /**
     * Busca aluno por CPF
     */
    public function buscarAlunoPorCPF($cpf) {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        $stmt = $this->db->prepare("
            SELECT * FROM alunos 
            WHERE cpf = ? 
            LIMIT 1
        ");
        $stmt->execute([$cpf]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca aluno por ID
     */
    public function buscarAlunoPorId($alunoId) {
        $stmt = $this->db->prepare("
            SELECT * FROM alunos 
            WHERE id = ? 
            LIMIT 1
        ");
        $stmt->execute([$alunoId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca cursos ativos de um aluno
     */
    public function buscarCursosAluno($alunoId) {
        $stmt = $this->db->prepare("
            SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ? AND m.status = 'ativa' AND c.ativo = 1
            ORDER BY c.nome ASC
        ");
        $stmt->execute([$alunoId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca todas as matrículas de um aluno (ativas e inativas)
     */
    public function buscarTodasMatriculas($alunoId) {
        $stmt = $this->db->prepare("
            SELECT c.*, m.status as matricula_status, m.data_matricula, 
                   m.data_conclusao, m.created_at as data_criacao_matricula
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$alunoId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca alunos por subdomínio (polo)
     */
    public function buscarAlunosPorPolo($subdomain, $limite = 50, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT a.*, COUNT(m.id) as total_matriculas
            FROM alunos a
            LEFT JOIN matriculas m ON a.id = m.aluno_id AND m.status = 'ativa'
            WHERE a.subdomain = ?
            GROUP BY a.id
            ORDER BY a.nome ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$subdomain, $limite, $offset]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Conta total de alunos por polo
     */
    public function contarAlunosPorPolo($subdomain) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total 
            FROM alunos 
            WHERE subdomain = ?
        ");
        $stmt->execute([$subdomain]);
        
        return $stmt->fetch()['total'];
    }
    
    /**
     * Busca alunos por nome ou CPF
     */
    public function buscarAlunos($termo, $limite = 20) {
        $termo = "%{$termo}%";
        $cpf = preg_replace('/[^0-9]/', '', $termo);
        
        $sql = "
            SELECT a.*, 
                   COUNT(m.id) as total_matriculas,
                   COUNT(CASE WHEN m.status = 'ativa' THEN 1 END) as matriculas_ativas
            FROM alunos a
            LEFT JOIN matriculas m ON a.id = m.aluno_id
            WHERE a.nome LIKE ? OR a.email LIKE ?
        ";
        
        $params = [$termo, $termo];
        
        // Se o termo for numérico, busca também por CPF
        if (strlen($cpf) >= 3) {
            $sql .= " OR a.cpf LIKE ?";
            $params[] = "%{$cpf}%";
        }
        
        $sql .= "
            GROUP BY a.id
            ORDER BY a.nome ASC
            LIMIT ?
        ";
        $params[] = $limite;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atualiza último acesso do aluno no sistema de boletos
     */
    public function atualizarUltimoAcesso($alunoId) {
        $stmt = $this->db->prepare("
            UPDATE alunos 
            SET ultimo_acesso = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$alunoId]);
    }
    
    /**
     * Inativa matrícula de um aluno em um curso
     */
    public function inativarMatricula($alunoId, $cursoId, $motivo = 'cancelamento') {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE matriculas 
                SET status = 'inativa', data_conclusao = NOW(), observacoes = ?
                WHERE aluno_id = ? AND curso_id = ? AND status = 'ativa'
            ");
            $stmt->execute([$motivo, $alunoId, $cursoId]);
            
            // Cancela boletos pendentes
            $stmt = $this->db->prepare("
                UPDATE boletos 
                SET status = 'cancelado', observacoes = 'Matrícula inativada'
                WHERE aluno_id = ? AND curso_id = ? AND status IN ('pendente', 'vencido')
            ");
            $stmt->execute([$alunoId, $cursoId]);
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('matricula_inativada', $alunoId, "Matrícula inativada - Curso ID: {$cursoId}, Motivo: {$motivo}");
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro ao inativar matrícula: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtém estatísticas de um aluno
     */
    public function obterEstatisticasAluno($alunoId) {
        // Total de cursos
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total_cursos,
                   COUNT(CASE WHEN m.status = 'ativa' THEN 1 END) as cursos_ativos,
                   COUNT(CASE WHEN m.status = 'concluida' THEN 1 END) as cursos_concluidos
            FROM matriculas m
            WHERE m.aluno_id = ?
        ");
        $stmt->execute([$alunoId]);
        $estatisticasCursos = $stmt->fetch();
        
        // Total de boletos
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total_boletos,
                   COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
                   COUNT(CASE WHEN status = 'pendente' THEN 1 END) as boletos_pendentes,
                   COUNT(CASE WHEN status = 'vencido' THEN 1 END) as boletos_vencidos,
                   SUM(valor) as valor_total,
                   SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as valor_pago,
                   SUM(CASE WHEN status IN ('pendente', 'vencido') THEN valor ELSE 0 END) as valor_pendente
            FROM boletos
            WHERE aluno_id = ?
        ");
        $stmt->execute([$alunoId]);
        $estatisticasBoletos = $stmt->fetch();
        
        return array_merge($estatisticasCursos, $estatisticasBoletos);
    }
    
    /**
     * Busca histórico de acessos do aluno
     */
    public function buscarHistoricoAcessos($alunoId, $limite = 10) {
        $stmt = $this->db->prepare("
            SELECT tipo, descricao, ip_address, user_agent, created_at
            FROM logs 
            WHERE usuario_id = ? AND tipo IN ('login', 'logout', 'acesso_boleto')
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$alunoId, $limite]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atualiza dados complementares do aluno
     */
    public function atualizarDadosComplementares($alunoId, $dados) {
        $campos = [];
        $valores = [];
        
        $camposPermitidos = ['telefone1', 'telefone2', 'endereco', 'city', 'observacoes'];
        
        foreach ($camposPermitidos as $campo) {
            if (isset($dados[$campo])) {
                $campos[] = "{$campo} = ?";
                $valores[] = $dados[$campo];
            }
        }
        
        if (!empty($campos)) {
            $valores[] = $alunoId;
            
            $sql = "UPDATE alunos SET " . implode(', ', $campos) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($valores);
            
            return $stmt->rowCount() > 0;
        }
        
        return false;
    }
    
    /**
     * Registra log de operação
     */
    private function registrarLog($tipo, $usuarioId, $descricao) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tipo,
                $usuarioId,
                $descricao,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }
    
    /**
     * Limpa dados antigos e otimiza performance
     */
    public function limpezaManutencao() {
        try {
            $this->db->beginTransaction();
            
            // Remove logs antigos (mais de 6 meses)
            $stmt = $this->db->prepare("
                DELETE FROM logs 
                WHERE usuario_id IS NOT NULL 
                AND created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)
            ");
            $logsRemovidos = $stmt->execute();
            
            // Atualiza status de matrículas sem atividade
            $stmt = $this->db->prepare("
                UPDATE matriculas m
                LEFT JOIN boletos b ON m.aluno_id = b.aluno_id AND m.curso_id = b.curso_id
                SET m.status = 'inativa'
                WHERE m.status = 'ativa' 
                AND m.created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)
                AND b.id IS NULL
            ");
            $matriculasAtualizadas = $stmt->execute();
            
            $this->db->commit();
            
            return [
                'logs_removidos' => $logsRemovidos,
                'matriculas_atualizadas' => $matriculasAtualizadas
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro na limpeza de manutenção: " . $e->getMessage());
            return false;
        }
    }
}
?>