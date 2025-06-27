<?php
/**
 * Sistema de Boletos IMED - Serviço de Alunos (VERSÃO FINAL CORRIGIDA)
 * Arquivo: src/AlunoService_final.php
 * 
 * CORREÇÃO DEFINITIVA: Agora salva cursos e matrículas corretamente
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
     * Busca aluno por CPF E subdomínio específico
     */
    public function buscarAlunoPorCPFESubdomain($cpf, $subdomain) {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        $stmt = $this->db->prepare("
            SELECT * FROM alunos 
            WHERE cpf = ? AND subdomain = ?
            LIMIT 1
        ");
        $stmt->execute([$cpf, $subdomain]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca cursos ativos de um aluno APENAS do subdomínio específico
     */
    public function buscarCursosAlunoPorSubdomain($alunoId, $subdomain) {
        $stmt = $this->db->prepare("
            SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ? 
            AND c.subdomain = ?
            AND m.status = 'ativa' 
            AND c.ativo = 1
            ORDER BY c.nome ASC
        ");
        $stmt->execute([$alunoId, $subdomain]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca aluno por CPF (todos os subdomínios) - mantido para compatibilidade
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
     * Busca cursos de um aluno (todos os subdomínios) - mantido para compatibilidade
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
     * Salva ou atualiza dados do aluno no banco local (VERSÃO DEFINITIVAMENTE CORRIGIDA)
     */
    public function salvarOuAtualizarAluno($dadosAluno) {
        try {
            $this->db->beginTransaction();
            
            error_log("AlunoService: Iniciando salvamento - CPF: {$dadosAluno['cpf']}, Subdomain: {$dadosAluno['subdomain']}");
            
            // CORREÇÃO: Busca por CPF E subdomain
            $stmt = $this->db->prepare("
                SELECT id, updated_at 
                FROM alunos 
                WHERE cpf = ? AND subdomain = ?
                FOR UPDATE
            ");
            $stmt->execute([$dadosAluno['cpf'], $dadosAluno['subdomain']]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                // Atualiza dados do aluno existente
                $alunoId = $this->atualizarAluno($alunoExistente['id'], $dadosAluno);
                error_log("AlunoService: Aluno atualizado - ID: {$alunoId}");
            } else {
                // CORREÇÃO: Verifica novamente antes de inserir
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM alunos WHERE cpf = ? AND subdomain = ?");
                $stmt->execute([$dadosAluno['cpf'], $dadosAluno['subdomain']]);
                $count = $stmt->fetch()['count'];
                
                if ($count > 0) {
                    // Se chegou aqui, houve uma condição de corrida
                    $stmt = $this->db->prepare("SELECT id FROM alunos WHERE cpf = ? AND subdomain = ? LIMIT 1");
                    $stmt->execute([$dadosAluno['cpf'], $dadosAluno['subdomain']]);
                    $alunoExistente = $stmt->fetch();
                    $alunoId = $this->atualizarAluno($alunoExistente['id'], $dadosAluno);
                    error_log("AlunoService: Aluno encontrado após condição de corrida - ID: {$alunoId}");
                } else {
                    // Cria novo aluno
                    $alunoId = $this->criarAluno($dadosAluno);
                    error_log("AlunoService: Novo aluno criado - ID: {$alunoId}");
                }
            }
            
            // CORREÇÃO PRINCIPAL: Atualiza/cria cursos e matrículas
            if (!empty($dadosAluno['cursos'])) {
                error_log("AlunoService: Processando " . count($dadosAluno['cursos']) . " cursos");
                $this->atualizarCursosAluno($alunoId, $dadosAluno['cursos'], $dadosAluno['subdomain']);
            } else {
                error_log("AlunoService: AVISO - Nenhum curso fornecido para processamento");
            }
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('aluno_sincronizado', $alunoId, "Dados sincronizados do Moodle: {$dadosAluno['subdomain']}");
            
            error_log("AlunoService: Salvamento concluído com sucesso - ID: {$alunoId}");
            return $alunoId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("AlunoService: ERRO no salvamento - " . $e->getMessage());
            error_log("AlunoService: Stack trace - " . $e->getTraceAsString());
            throw new Exception("Erro ao processar dados do aluno: " . $e->getMessage());
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
     * Atualiza cursos e matrículas do aluno (MÉTODO CORRIGIDO)
     */
    private function atualizarCursosAluno($alunoId, $cursosMoodle, $subdomain) {
        error_log("AlunoService: Iniciando processamento de cursos para aluno {$alunoId}, subdomain {$subdomain}");
        
        foreach ($cursosMoodle as $index => $cursoMoodle) {
            try {
                error_log("AlunoService: Processando curso " . ($index + 1) . ": {$cursoMoodle['nome']} (Moodle ID: {$cursoMoodle['moodle_course_id']})");
                
                // Verifica se curso já existe NO SUBDOMÍNIO ESPECÍFICO
                $stmt = $this->db->prepare("
                    SELECT id, nome, ativo FROM cursos 
                    WHERE moodle_course_id = ? AND subdomain = ?
                    LIMIT 1
                ");
                $stmt->execute([$cursoMoodle['moodle_course_id'], $subdomain]);
                $cursoExistente = $stmt->fetch();
                
                if ($cursoExistente) {
                    $cursoId = $cursoExistente['id'];
                    error_log("AlunoService: Curso já existe - ID: {$cursoId}, Nome: {$cursoExistente['nome']}");
                    
                    // Atualiza dados do curso e garante que está ativo
                    $this->atualizarCurso($cursoId, $cursoMoodle);
                } else {
                    // Cria novo curso
                    $cursoId = $this->criarCurso($cursoMoodle, $subdomain);
                    error_log("AlunoService: Novo curso criado - ID: {$cursoId}");
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
                    error_log("AlunoService: Matrícula já existe - ID: {$matriculaExistente['id']}, Status: {$matriculaExistente['status']}");
                    
                    // Ativa matrícula se necessário
                    if ($matriculaExistente['status'] !== 'ativa') {
                        $this->atualizarMatricula($matriculaExistente['id'], 'ativa');
                        error_log("AlunoService: Matrícula reativada");
                    }
                } else {
                    // Cria nova matrícula
                    $matriculaId = $this->criarMatricula($alunoId, $cursoId, $cursoMoodle);
                    error_log("AlunoService: Nova matrícula criada - ID: {$matriculaId}");
                }
                
            } catch (Exception $e) {
                error_log("AlunoService: ERRO ao processar curso {$cursoMoodle['nome']}: " . $e->getMessage());
                // Continua processando outros cursos mesmo se um falhar
                continue;
            }
        }
        
        error_log("AlunoService: Processamento de cursos concluído");
    }
    
    /**
     * Cria um novo curso
     */
    private function criarCurso($cursoMoodle, $subdomain) {
        $stmt = $this->db->prepare("
            INSERT INTO cursos (
                moodle_course_id, nome, nome_curto, valor, subdomain,
                categoria_id, data_inicio, data_fim, formato, summary,
                url, ativo, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $cursoMoodle['moodle_course_id'],
            $cursoMoodle['nome'],
            $cursoMoodle['nome_curto'] ?? '',
            0.00, // Valor padrão
            $subdomain,
            $cursoMoodle['categoria'] ?? null,
            $cursoMoodle['data_inicio'] ?? null,
            $cursoMoodle['data_fim'] ?? null,
            $cursoMoodle['formato'] ?? 'topics',
            $cursoMoodle['summary'] ?? '',
            $cursoMoodle['url'] ?? null,
            1 // ativo = true
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
                summary = ?, url = ?, ativo = 1, updated_at = NOW()
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
                aluno_id, curso_id, status, data_matricula, created_at, updated_at
            ) VALUES (?, ?, ?, ?, NOW(), NOW())
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
}
?>