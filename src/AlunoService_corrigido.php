<?php
/**
 * Sistema de Boletos IMED - Serviço de Alunos (Versão Corrigida)
 * Arquivo: src/AlunoService_corrigido.php
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
        try {
            $this->db = (new Database())->getConnection();
        } catch (PDOException $e) {
            error_log("AlunoService: Erro PDO ao criar curso: " . $e->getMessage());
            throw new Exception("Erro de banco ao criar curso: " . $e->getMessage());
        }
    }
    
    /**
     * Atualiza dados de um curso existente
     */
    private function atualizarCurso($cursoId, $cursoMoodle) {
        try {
            $stmt = $this->db->prepare("
                UPDATE cursos 
                SET nome = ?, nome_curto = ?, categoria_id = ?,
                    data_inicio = ?, data_fim = ?, formato = ?,
                    summary = ?, url = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $resultado = $stmt->execute([
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
            
            if (!$resultado) {
                throw new Exception("Falha ao atualizar curso no banco");
            }
            
            error_log("AlunoService: Curso atualizado ID: " . $cursoId);
            
        } catch (PDOException $e) {
            error_log("AlunoService: Erro PDO ao atualizar curso: " . $e->getMessage());
            throw new Exception("Erro de banco ao atualizar curso: " . $e->getMessage());
        }
    }
    
    /**
     * Cria uma nova matrícula
     */
    private function criarMatricula($alunoId, $cursoId, $cursoMoodle) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO matriculas (
                    aluno_id, curso_id, status, data_matricula, created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            
            $dataMatricula = $cursoMoodle['data_inicio'] ?? date('Y-m-d');
            
            $resultado = $stmt->execute([
                $alunoId,
                $cursoId,
                'ativa',
                $dataMatricula
            ]);
            
            if (!$resultado) {
                throw new Exception("Falha ao inserir matrícula no banco");
            }
            
            $matriculaId = $this->db->lastInsertId();
            error_log("AlunoService: Matrícula criada ID: " . $matriculaId);
            
            return $matriculaId;
            
        } catch (PDOException $e) {
            error_log("AlunoService: Erro PDO ao criar matrícula: " . $e->getMessage());
            throw new Exception("Erro de banco ao criar matrícula: " . $e->getMessage());
        }
    }
    
    /**
     * Atualiza status de uma matrícula
     */
    private function atualizarMatricula($matriculaId, $novoStatus) {
        try {
            $stmt = $this->db->prepare("
                UPDATE matriculas 
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $resultado = $stmt->execute([$novoStatus, $matriculaId]);
            
            if (!$resultado) {
                throw new Exception("Falha ao atualizar matrícula no banco");
            }
            
            error_log("AlunoService: Matrícula atualizada ID: " . $matriculaId . " Status: " . $novoStatus);
            
        } catch (PDOException $e) {
            error_log("AlunoService: Erro PDO ao atualizar matrícula: " . $e->getMessage());
            throw new Exception("Erro de banco ao atualizar matrícula: " . $e->getMessage());
        }
    }
    
    /**
     * Busca aluno por CPF
     */
    public function buscarAlunoPorCPF($cpf) {
        try {
            $cpf = preg_replace('/[^0-9]/', '', $cpf);
            
            if (strlen($cpf) !== 11) {
                throw new Exception("CPF deve ter 11 dígitos");
            }
            
            $stmt = $this->db->prepare("SELECT * FROM alunos WHERE cpf = ? LIMIT 1");
            $stmt->execute([$cpf]);
            
            $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($aluno) {
                error_log("AlunoService: Aluno encontrado por CPF: " . $cpf . " ID: " . $aluno['id']);
            } else {
                error_log("AlunoService: Aluno não encontrado por CPF: " . $cpf);
            }
            
            return $aluno;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar aluno por CPF: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Busca aluno por ID
     */
    public function buscarAlunoPorId($alunoId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM alunos WHERE id = ? LIMIT 1");
            $stmt->execute([$alunoId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar aluno por ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Busca cursos ativos de um aluno
     */
    public function buscarCursosAluno($alunoId) {
        try {
            $stmt = $this->db->prepare("
                SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
                FROM cursos c
                INNER JOIN matriculas m ON c.id = m.curso_id
                WHERE m.aluno_id = ? AND m.status = 'ativa' AND c.ativo = 1
                ORDER BY c.nome ASC
            ");
            $stmt->execute([$alunoId]);
            
            $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("AlunoService: Cursos encontrados para aluno ID " . $alunoId . ": " . count($cursos));
            
            return $cursos;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar cursos do aluno: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca todas as matrículas de um aluno (ativas e inativas)
     */
    public function buscarTodasMatriculas($alunoId) {
        try {
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
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar todas as matrículas: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca alunos por subdomínio (polo)
     */
    public function buscarAlunosPorPolo($subdomain, $limite = 50, $offset = 0) {
        try {
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
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar alunos por polo: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Conta total de alunos por polo
     */
    public function contarAlunosPorPolo($subdomain) {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM alunos WHERE subdomain = ?");
            $stmt->execute([$subdomain]);
            
            $resultado = $stmt->fetch();
            return $resultado['total'] ?? 0;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao contar alunos por polo: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Busca alunos por nome ou CPF
     */
    public function buscarAlunos($termo, $limite = 20) {
        try {
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
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar alunos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Atualiza último acesso do aluno no sistema de boletos
     */
    public function atualizarUltimoAcesso($alunoId) {
        try {
            $stmt = $this->db->prepare("UPDATE alunos SET ultimo_acesso = NOW() WHERE id = ?");
            $stmt->execute([$alunoId]);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao atualizar último acesso: " . $e->getMessage());
            return false;
        }
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
            
            // Cancela boletos pendentes se existir a tabela
            try {
                $stmt = $this->db->prepare("
                    UPDATE boletos 
                    SET status = 'cancelado', observacoes = 'Matrícula inativada'
                    WHERE aluno_id = ? AND curso_id = ? AND status IN ('pendente', 'vencido')
                ");
                $stmt->execute([$alunoId, $cursoId]);
            } catch (Exception $e) {
                // Ignora erro se tabela boletos não existir ainda
                error_log("AlunoService: Aviso - não foi possível cancelar boletos: " . $e->getMessage());
            }
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('matricula_inativada', $alunoId, "Matrícula inativada - Curso ID: {$cursoId}, Motivo: {$motivo}");
            
            return true;
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log("AlunoService: Erro ao inativar matrícula: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtém estatísticas de um aluno
     */
    public function obterEstatisticasAluno($alunoId) {
        try {
            // Total de cursos
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_cursos,
                       COUNT(CASE WHEN m.status = 'ativa' THEN 1 END) as cursos_ativos,
                       COUNT(CASE WHEN m.status = 'concluida' THEN 1 END) as cursos_concluidos
                FROM matriculas m
                WHERE m.aluno_id = ?
            ");
            $stmt->execute([$alunoId]);
            $estatisticasCursos = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Total de boletos (se a tabela existir)
            $estatisticasBoletos = [
                'total_boletos' => 0,
                'boletos_pagos' => 0,
                'boletos_pendentes' => 0,
                'boletos_vencidos' => 0,
                'valor_total' => 0,
                'valor_pago' => 0,
                'valor_pendente' => 0
            ];
            
            try {
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
                $estatisticasBoletos = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Tabela boletos pode não existir ainda
                error_log("AlunoService: Tabela boletos não disponível para estatísticas");
            }
            
            return array_merge($estatisticasCursos, $estatisticasBoletos);
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao obter estatísticas: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca histórico de acessos do aluno
     */
    public function buscarHistoricoAcessos($alunoId, $limite = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT tipo, descricao, ip_address, user_agent, created_at
                FROM logs 
                WHERE usuario_id = ? AND tipo IN ('login', 'logout', 'acesso_boleto')
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$alunoId, $limite]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar histórico: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Atualiza dados complementares do aluno
     */
    public function atualizarDadosComplementares($alunoId, $dados) {
        try {
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
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao atualizar dados complementares: " . $e->getMessage());
            return false;
        }
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
            // Log de erro sem interromper o fluxo principal
            error_log("AlunoService: Erro ao registrar log: " . $e->getMessage());
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
            $logsRemovidos = $stmt->execute() ? $stmt->rowCount() : 0;
            
            // Atualiza status de matrículas sem atividade
            $stmt = $this->db->prepare("
                UPDATE matriculas m
                LEFT JOIN boletos b ON m.aluno_id = b.aluno_id AND m.curso_id = b.curso_id
                SET m.status = 'inativa'
                WHERE m.status = 'ativa' 
                AND m.created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)
                AND b.id IS NULL
            ");
            $matriculasAtualizadas = $stmt->execute() ? $stmt->rowCount() : 0;
            
            $this->db->commit();
            
            return [
                'logs_removidos' => $logsRemovidos,
                'matriculas_atualizadas' => $matriculasAtualizadas
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log("AlunoService: Erro na limpeza de manutenção: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica se o serviço está funcionando corretamente
     */
    public function testarServico() {
        try {
            // Testa conexão com banco
            $stmt = $this->db->query("SELECT 1 as teste");
            $resultado = $stmt->fetch();
            
            if ($resultado['teste'] !== 1) {
                throw new Exception("Falha no teste de conexão");
            }
            
            // Verifica se tabelas essenciais existem
            $tabelas = ['alunos', 'cursos', 'matriculas'];
            foreach ($tabelas as $tabela) {
                $stmt = $this->db->query("SHOW TABLES LIKE '{$tabela}'");
                if ($stmt->rowCount() === 0) {
                    throw new Exception("Tabela {$tabela} não encontrada");
                }
            }
            
            return [
                'status' => 'ok',
                'timestamp' => date('Y-m-d H:i:s'),
                'tabelas_verificadas' => $tabelas
            ];
            
        } catch (Exception $e) {
            error_log("AlunoService: Falha no teste de serviço: " . $e->getMessage());
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}
?> (Exception $e) {
            error_log("AlunoService: Erro ao conectar com banco: " . $e->getMessage());
            throw new Exception("Erro de conexão com banco de dados");
        }
    }
    
    /**
     * Salva ou atualiza dados do aluno no banco local
     */
    public function salvarOuAtualizarAluno($dadosAluno) {
        try {
            // Log de entrada
            error_log("AlunoService: Iniciando salvamento/atualização do aluno CPF: " . ($dadosAluno['cpf'] ?? 'N/A'));
            
            // Validação básica dos dados
            if (empty($dadosAluno['cpf'])) {
                throw new Exception("CPF é obrigatório");
            }
            
            if (empty($dadosAluno['nome'])) {
                throw new Exception("Nome é obrigatório");
            }
            
            if (empty($dadosAluno['subdomain'])) {
                throw new Exception("Subdomain é obrigatório");
            }
            
            $this->db->beginTransaction();
            error_log("AlunoService: Transação iniciada");
            
            // Verifica se aluno já existe
            $stmt = $this->db->prepare("SELECT id, updated_at FROM alunos WHERE cpf = ? LIMIT 1");
            $stmt->execute([$dadosAluno['cpf']]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                error_log("AlunoService: Aluno existe, atualizando ID: " . $alunoExistente['id']);
                $alunoId = $this->atualizarAluno($alunoExistente['id'], $dadosAluno);
            } else {
                error_log("AlunoService: Aluno não existe, criando novo");
                $alunoId = $this->criarAluno($dadosAluno);
            }
            
            // Atualiza/cria cursos e matrículas se existirem
            if (!empty($dadosAluno['cursos']) && is_array($dadosAluno['cursos'])) {
                error_log("AlunoService: Processando " . count($dadosAluno['cursos']) . " cursos");
                $this->atualizarCursosAluno($alunoId, $dadosAluno['cursos'], $dadosAluno['subdomain']);
            } else {
                error_log("AlunoService: Nenhum curso fornecido para processar");
            }
            
            $this->db->commit();
            error_log("AlunoService: Transação commitada com sucesso. Aluno ID: " . $alunoId);
            
            // Log da operação
            $this->registrarLog('aluno_sincronizado', $alunoId, "Dados sincronizados do Moodle: {$dadosAluno['subdomain']}");
            
            return $alunoId;
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
                error_log("AlunoService: Transação revertida devido ao erro");
            }
            
            error_log("AlunoService: Erro ao salvar/atualizar aluno: " . $e->getMessage());
            error_log("AlunoService: Stack trace: " . $e->getTraceAsString());
            
            // Re-lança a exceção com mais contexto
            throw new Exception("Erro ao processar dados do aluno: " . $e->getMessage());
        }
    }
    
    /**
     * Cria um novo aluno
     */
    private function criarAluno($dadosAluno) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO alunos (
                    cpf, nome, email, moodle_user_id, subdomain, 
                    city, country, profile_image, primeiro_acesso,
                    ultimo_acesso_moodle, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $resultado = $stmt->execute([
                $dadosAluno['cpf'],
                $dadosAluno['nome'],
                $dadosAluno['email'] ?? null,
                $dadosAluno['moodle_user_id'] ?? null,
                $dadosAluno['subdomain'],
                $dadosAluno['city'] ?? null,
                $dadosAluno['country'] ?? 'BR',
                $dadosAluno['profile_image'] ?? null,
                $dadosAluno['primeiro_acesso'] ?? null,
                $dadosAluno['ultimo_acesso'] ?? null
            ]);
            
            if (!$resultado) {
                throw new Exception("Falha ao inserir aluno no banco");
            }
            
            $alunoId = $this->db->lastInsertId();
            error_log("AlunoService: Aluno criado com ID: " . $alunoId);
            
            return $alunoId;
            
        } catch (PDOException $e) {
            error_log("AlunoService: Erro PDO ao criar aluno: " . $e->getMessage());
            throw new Exception("Erro de banco ao criar aluno: " . $e->getMessage());
        }
    }
    
    /**
     * Atualiza dados de um aluno existente
     */
    private function atualizarAluno($alunoId, $dadosAluno) {
        try {
            $stmt = $this->db->prepare("
                UPDATE alunos 
                SET nome = ?, email = ?, moodle_user_id = ?, subdomain = ?,
                    city = ?, country = ?, profile_image = ?,
                    ultimo_acesso_moodle = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $resultado = $stmt->execute([
                $dadosAluno['nome'],
                $dadosAluno['email'] ?? null,
                $dadosAluno['moodle_user_id'] ?? null,
                $dadosAluno['subdomain'],
                $dadosAluno['city'] ?? null,
                $dadosAluno['country'] ?? 'BR',
                $dadosAluno['profile_image'] ?? null,
                $dadosAluno['ultimo_acesso'] ?? null,
                $alunoId
            ]);
            
            if (!$resultado) {
                throw new Exception("Falha ao atualizar aluno no banco");
            }
            
            error_log("AlunoService: Aluno atualizado ID: " . $alunoId);
            
            return $alunoId;
            
        } catch (PDOException $e) {
            error_log("AlunoService: Erro PDO ao atualizar aluno: " . $e->getMessage());
            throw new Exception("Erro de banco ao atualizar aluno: " . $e->getMessage());
        }
    }
    
    /**
     * Atualiza cursos e matrículas do aluno
     */
    private function atualizarCursosAluno($alunoId, $cursosMoodle, $subdomain) {
        try {
            foreach ($cursosMoodle as $index => $cursoMoodle) {
                error_log("AlunoService: Processando curso " . ($index + 1) . ": " . ($cursoMoodle['nome'] ?? 'Nome não informado'));
                
                // Validação básica do curso
                if (empty($cursoMoodle['moodle_course_id'])) {
                    error_log("AlunoService: Curso sem ID do Moodle, pulando");
                    continue;
                }
                
                if (empty($cursoMoodle['nome'])) {
                    error_log("AlunoService: Curso sem nome, pulando");
                    continue;
                }
                
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
                    error_log("AlunoService: Curso existe, atualizando ID: " . $cursoId);
                    $this->atualizarCurso($cursoId, $cursoMoodle);
                } else {
                    error_log("AlunoService: Criando novo curso");
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
                    error_log("AlunoService: Matrícula existe ID: " . $matriculaExistente['id']);
                    // Atualiza matrícula se necessário
                    if ($matriculaExistente['status'] !== 'ativa') {
                        $this->atualizarMatricula($matriculaExistente['id'], 'ativa');
                    }
                } else {
                    error_log("AlunoService: Criando nova matrícula");
                    $this->criarMatricula($alunoId, $cursoId, $cursoMoodle);
                }
            }
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao atualizar cursos: " . $e->getMessage());
            throw new Exception("Erro ao processar cursos do aluno: " . $e->getMessage());
        }
    }
    
    /**
     * Cria um novo curso
     */
    private function criarCurso($cursoMoodle, $subdomain) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cursos (
                    moodle_course_id, nome, nome_curto, valor, subdomain,
                    categoria_id, data_inicio, data_fim, formato, summary,
                    url, ativo, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $resultado = $stmt->execute([
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
            
            if (!$resultado) {
                throw new Exception("Falha ao inserir curso no banco");
            }
            
            $cursoId = $this->db->lastInsertId();
            error_log("AlunoService: Curso criado ID: " . $cursoId);
            
            return $cursoId;
            
        } catch