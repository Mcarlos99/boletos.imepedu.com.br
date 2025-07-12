<?php
    /**
     * Sistema de Boletos IMEPEDU - Serviço de Alunos (VERSÃO LIMPA E CORRIGIDA)
     * Arquivo: src/AlunoService.php
     */
    
    require_once __DIR__ . "/../config/database.php";
    
    class AlunoService {
        
        private $db;
        
        public function __construct() {
            $this->db = (new Database())->getConnection();
        }
        
        /**
         * Busca aluno por CPF E subdomínio específico
         */
        public function buscarAlunoPorCPFESubdomain($cpf, $subdomain) {
            $cpf = preg_replace("/[^0-9]/", "", $cpf);
            
            $stmt = $this->db->prepare("
                SELECT * FROM alunos 
                WHERE cpf = ? AND subdomain = ?
                LIMIT 1
            ");
            $stmt->execute([$cpf, $subdomain]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        /**
         * Busca cursos APENAS do subdomínio específico
         */
        public function buscarCursosAlunoPorSubdomain($alunoId, $subdomain) {
            $stmt = $this->db->prepare("
                SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
                FROM cursos c
                INNER JOIN matriculas m ON c.id = m.curso_id
                WHERE m.aluno_id = ? 
                AND c.subdomain = ?
                AND m.status = ? 
                AND c.ativo = 1
                ORDER BY c.nome ASC
            ");
            $stmt->execute([$alunoId, $subdomain, "ativa"]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        /**
         * Busca aluno por CPF (compatibilidade)
         */
        public function buscarAlunoPorCPF($cpf) {
            $cpf = preg_replace("/[^0-9]/", "", $cpf);
            
            $stmt = $this->db->prepare("
                SELECT * FROM alunos 
                WHERE cpf = ? 
                LIMIT 1
            ");
            $stmt->execute([$cpf]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        /**
         * Busca cursos de um aluno (compatibilidade)
         */
        public function buscarCursosAluno($alunoId) {
            $stmt = $this->db->prepare("
                SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
                FROM cursos c
                INNER JOIN matriculas m ON c.id = m.curso_id
                WHERE m.aluno_id = ? AND m.status = ? AND c.ativo = 1
                ORDER BY c.nome ASC
            ");
            $stmt->execute([$alunoId, "ativa"]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        /**
         * Salva ou atualiza dados do aluno no banco local
         */
    public function salvarOuAtualizarAluno($dadosMoodle) {
        try {
            $db = (new Database())->getConnection();
            
            // Verifica se aluno já existe
            $stmt = $db->prepare("
                SELECT id FROM alunos 
                WHERE cpf = ? AND subdomain = ?
            ");
            $stmt->execute([$dadosMoodle['cpf'], $dadosMoodle['subdomain']]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                // Atualiza aluno existente
                $stmt = $db->prepare("
                    UPDATE alunos SET
                        nome = ?,
                        email = ?,
                        moodle_user_id = ?,
                        ultimo_acesso = ?,
                        city = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $dadosMoodle['nome'],
                    $dadosMoodle['email'],
                    $dadosMoodle['moodle_user_id'],
                    $dadosMoodle['ultimo_acesso'],
                    $dadosMoodle['city'] ?? null,
                    $alunoExistente['id']
                ]);
                
                $alunoId = $alunoExistente['id'];
            } else {
                // Cria novo aluno
                $stmt = $db->prepare("
                    INSERT INTO alunos (
                        nome, cpf, email, subdomain, moodle_user_id,
                        ultimo_acesso, city, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $dadosMoodle['nome'],
                    $dadosMoodle['cpf'],
                    $dadosMoodle['email'],
                    $dadosMoodle['subdomain'],
                    $dadosMoodle['moodle_user_id'],
                    $dadosMoodle['ultimo_acesso'],
                    $dadosMoodle['city'] ?? null
                ]);
                
                $alunoId = $db->lastInsertId();
            }
            
            // Retorna aluno atualizado
            $stmt = $db->prepare("SELECT * FROM alunos WHERE id = ?");
            $stmt->execute([$alunoId]);
            $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("Aluno salvo/atualizado: ID {$alunoId}, Nome: {$dadosMoodle['nome']}");
            
            return $aluno;
            
        } catch (Exception $e) {
            error_log("Erro ao salvar/atualizar aluno: " . $e->getMessage());
            throw new Exception("Erro ao salvar dados do aluno");
        }
    }

      /**
     * 🆕 MÉTODO: Salva ou atualiza curso vindo do Moodle
     */
    public function salvarOuAtualizarCurso($cursoMoodle, $subdomain) {
        try {
            $db = (new Database())->getConnection();
            
            // Verifica se curso já existe
            $stmt = $db->prepare("
                SELECT id FROM cursos 
                WHERE moodle_course_id = ? AND subdomain = ?
            ");
            $stmt->execute([$cursoMoodle['moodle_course_id'], $subdomain]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                // Atualiza curso existente
                $stmt = $db->prepare("
                    UPDATE cursos SET
                        nome = ?,
                        nome_curto = ?,
                        data_inicio = ?,
                        data_fim = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $cursoMoodle['nome'],
                    $cursoMoodle['nome_curto'],
                    $cursoMoodle['data_inicio'],
                    $cursoMoodle['data_fim'],
                    $cursoExistente['id']
                ]);
                
                return $cursoExistente['id'];
            } else {
                // Cria novo curso
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        nome, nome_curto, subdomain, moodle_course_id,
                        data_inicio, data_fim, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $cursoMoodle['nome'],
                    $cursoMoodle['nome_curto'],
                    $subdomain,
                    $cursoMoodle['moodle_course_id'],
                    $cursoMoodle['data_inicio'],
                    $cursoMoodle['data_fim']
                ]);
                
                $cursoId = $db->lastInsertId();
                
                error_log("Curso criado: ID {$cursoId}, Nome: {$cursoMoodle['nome']}");
                
                return $cursoId;
            }
            
        } catch (Exception $e) {
            error_log("Erro ao salvar/atualizar curso: " . $e->getMessage());
            return false;
        }
    }
        
        public function sincronizarCursosAluno($alunoId, $subdomain) {
        try {
            // Primeiro busca cursos locais
            $cursosLocais = $this->buscarCursosAlunoPorSubdomain($alunoId, $subdomain);
            
            if (!empty($cursosLocais)) {
                return $cursosLocais;
            }
            
            // Se não tem cursos locais, busca no Moodle
            $db = (new Database())->getConnection();
            $stmt = $db->prepare("SELECT moodle_user_id FROM alunos WHERE id = ?");
            $stmt->execute([$alunoId]);
            $aluno = $stmt->fetch();
            
            if ($aluno && $aluno['moodle_user_id']) {
                require_once __DIR__ . '/../src/MoodleAPI.php';
                $moodleAPI = new MoodleAPI($subdomain);
                $cursosMoodle = $moodleAPI->buscarCursosAluno($aluno['moodle_user_id']);
                
                // Salva cursos localmente
                foreach ($cursosMoodle as $cursoMoodle) {
                    $cursoId = $this->salvarOuAtualizarCurso($cursoMoodle, $subdomain);
                    
                    if ($cursoId) {
                        // Cria matrícula se não existir
                        $stmt = $db->prepare("
                            INSERT IGNORE INTO matriculas (aluno_id, curso_id, data_matricula, status)
                            VALUES (?, ?, ?, 'ativa')
                        ");
                        $stmt->execute([$alunoId, $cursoId, date('Y-m-d')]);
                    }
                }
                
                // Retorna cursos atualizados
                return $this->buscarCursosAlunoPorSubdomain($alunoId, $subdomain);
            }
            
            return [];
            
        } catch (Exception $e) {
            error_log("Erro na sincronização de cursos: " . $e->getMessage());
            // Retorna cursos locais em caso de erro
            return $this->buscarCursosAlunoPorSubdomain($alunoId, $subdomain);
        }
    }

        public function sincronizarAlunoMoodle($cpf, $subdomain) {
        try {
            // Primeiro tenta buscar localmente
            $aluno = $this->buscarAlunoPorCPFESubdomain($cpf, $subdomain);
            
            if ($aluno) {
                return $aluno;
            }
            
            // Se não encontrou, busca no Moodle
            require_once __DIR__ . '/../src/MoodleAPI.php';
            $moodleAPI = new MoodleAPI($subdomain);
            $dadosMoodle = $moodleAPI->buscarAlunoPorCPF($cpf);
            
            if ($dadosMoodle) {
                // Salva na base local e retorna
                return $this->salvarOuAtualizarAluno($dadosMoodle);
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Erro na sincronização com Moodle: " . $e->getMessage());
            // Retorna dados locais se houver erro no Moodle
            return $this->buscarAlunoPorCPFESubdomain($cpf, $subdomain);
        }
    }

        /**
         * Cria um novo aluno
         */
        private function criarAluno($dadosAluno) {
            $stmt = $this->db->prepare("
                INSERT INTO alunos (
                    cpf, nome, email, moodle_user_id, subdomain, 
                    city, country, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $dadosAluno["cpf"],
                $dadosAluno["nome"],
                $dadosAluno["email"],
                $dadosAluno["moodle_user_id"],
                $dadosAluno["subdomain"],
                $dadosAluno["city"] ?? null,
                $dadosAluno["country"] ?? "BR"
            ]);
            
            return $this->db->lastInsertId();
        }
        
        /**
         * Atualiza um aluno existente
         */
        private function atualizarAluno($alunoId, $dadosAluno) {
            $stmt = $this->db->prepare("
                UPDATE alunos 
                SET nome = ?, email = ?, moodle_user_id = ?, subdomain = ?,
                    city = ?, country = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $dadosAluno["nome"],
                $dadosAluno["email"],
                $dadosAluno["moodle_user_id"],
                $dadosAluno["subdomain"],
                $dadosAluno["city"] ?? null,
                $dadosAluno["country"] ?? "BR",
                $alunoId
            ]);
            
            return $alunoId;
        }
        
        /**
         * Atualiza cursos e matrículas do aluno
         */
        private function atualizarCursosAluno($alunoId, $cursosMoodle, $subdomain) {
            error_log("AlunoService: Processando cursos para aluno " . $alunoId . ", subdomain " . $subdomain);
            
            foreach ($cursosMoodle as $index => $cursoMoodle) {
                try {
                    error_log("AlunoService: Curso " . ($index + 1) . ": " . $cursoMoodle["nome"]);
                    
                    // Verifica se curso existe
                    $stmt = $this->db->prepare("
                        SELECT id FROM cursos 
                        WHERE moodle_course_id = ? AND subdomain = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$cursoMoodle["moodle_course_id"], $subdomain]);
                    $cursoExistente = $stmt->fetch();
                    
                    if ($cursoExistente) {
                        $cursoId = $cursoExistente["id"];
                        error_log("AlunoService: Curso existe - ID: " . $cursoId);
                        
                        // Atualiza curso
                        $this->atualizarCurso($cursoId, $cursoMoodle);
                    } else {
                        // Cria curso
                        $cursoId = $this->criarCurso($cursoMoodle, $subdomain);
                        error_log("AlunoService: Curso criado - ID: " . $cursoId);
                    }
                    
                    // Verifica matrícula
                    $stmt = $this->db->prepare("
                        SELECT id, status FROM matriculas 
                        WHERE aluno_id = ? AND curso_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$alunoId, $cursoId]);
                    $matriculaExistente = $stmt->fetch();
                    
                    if ($matriculaExistente) {
                        if ($matriculaExistente["status"] !== "ativa") {
                            $this->atualizarMatricula($matriculaExistente["id"], "ativa");
                            error_log("AlunoService: Matrícula reativada");
                        }
                    } else {
                        // Cria matrícula
                        $matriculaId = $this->criarMatricula($alunoId, $cursoId, $cursoMoodle);
                        error_log("AlunoService: Matrícula criada - ID: " . $matriculaId);
                    }
                    
                } catch (Exception $e) {
                    error_log("AlunoService: ERRO curso " . $cursoMoodle["nome"] . ": " . $e->getMessage());
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
                $cursoMoodle["moodle_course_id"],
                $cursoMoodle["nome"],
                $cursoMoodle["nome_curto"] ?? "",
                0.00,
                $subdomain,
                $cursoMoodle["categoria"] ?? null,
                $cursoMoodle["data_inicio"] ?? null,
                $cursoMoodle["data_fim"] ?? null,
                $cursoMoodle["formato"] ?? "topics",
                $cursoMoodle["summary"] ?? "",
                $cursoMoodle["url"] ?? null,
                1
            ]);
            
            return $this->db->lastInsertId();
        }
        
        /**
         * Atualiza um curso existente
         */
        private function atualizarCurso($cursoId, $cursoMoodle) {
            $stmt = $this->db->prepare("
                UPDATE cursos 
                SET nome = ?, nome_curto = ?, ativo = 1, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $cursoMoodle["nome"],
                $cursoMoodle["nome_curto"] ?? "",
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
            
            $dataMatricula = $cursoMoodle["data_inicio"] ?? date("Y-m-d");
            
            $stmt->execute([
                $alunoId,
                $cursoId,
                "ativa",
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
         * Atualiza último acesso
         */
public function atualizarUltimoAcesso($alunoId) {
    try {
        $stmt = $this->db->prepare("
            UPDATE alunos 
            SET ultimo_acesso = NOW(), updated_at = NOW() 
            WHERE id = ?
        ");
        $resultado = $stmt->execute([$alunoId]);
        
        if ($resultado) {
            error_log("✅ Último acesso atualizado - Aluno ID: {$alunoId}");
            return true;
        } else {
            error_log("⚠️ Falha ao atualizar último acesso - Aluno ID: {$alunoId}");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ Erro ao atualizar último acesso - Aluno ID: {$alunoId} - " . $e->getMessage());
        return false;
    }
}
public function buscarAlunoComUltimoAcesso($alunoId) {
    try {
        $stmt = $this->db->prepare("
            SELECT *, 
                   DATE_FORMAT(ultimo_acesso, '%d/%m/%Y %H:%i:%s') as ultimo_acesso_formatado,
                   CASE 
                       WHEN ultimo_acesso IS NULL THEN 'Nunca acessou'
                       WHEN ultimo_acesso > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'Ativo'
                       ELSE 'Inativo'
                   END as status_atividade,
                   TIMESTAMPDIFF(DAY, ultimo_acesso, NOW()) as dias_desde_ultimo_acesso
            FROM alunos 
            WHERE id = ?
        ");
        $stmt->execute([$alunoId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Erro ao buscar aluno com último acesso: " . $e->getMessage());
        return $this->buscarAlunoPorId($alunoId); // Fallback
    }
}

/**
 * Novo método: Registra acesso a uma página específica
 */
public function registrarAcessoPagina($alunoId, $pagina) {
    try {
        // Atualiza último acesso
        $this->atualizarUltimoAcesso($alunoId);
        
        // Registra log específico da página
        $stmt = $this->db->prepare("
            INSERT INTO logs (tipo, descricao, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            'page_access',
            "Acesso à página: {$pagina} - Aluno ID: {$alunoId}",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Erro ao registrar acesso à página: " . $e->getMessage());
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
                    $_SERVER["REMOTE_ADDR"] ?? "unknown",
                    $_SERVER["HTTP_USER_AGENT"] ?? "unknown"
                ]);
            } catch (Exception $e) {
                error_log("Erro ao registrar log: " . $e->getMessage());
            }
        }
            
    }

    ?>