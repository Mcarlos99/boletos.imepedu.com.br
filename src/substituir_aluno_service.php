<?php
/**
 * Script para substituir AlunoService diretamente
 * Arquivo: substituir_aluno_service.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Substituição Direta do AlunoService</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    pre{background:#f5f5f5; padding:10px; border:1px solid #ddd; overflow-x:auto; max-height:300px;}
</style>";

$arquivoDestino = 'src/AlunoService.php';
$arquivoBackup = 'src/AlunoService_backup_' . date('Y-m-d_H-i-s') . '.php';

try {
    echo "<div class='step'>";
    echo "<h3>1. Fazendo Backup do Arquivo Atual</h3>";
    
    if (file_exists($arquivoDestino)) {
        if (copy($arquivoDestino, $arquivoBackup)) {
            echo "<span class='ok'>✓ Backup criado: {$arquivoBackup}</span><br>";
        } else {
            echo "<span class='error'>✗ Falha ao criar backup</span><br>";
        }
    } else {
        echo "<span class='warning'>⚠ Arquivo original não existe, será criado</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Criando Novo AlunoService</h3>";
    
    // Conteúdo completo do AlunoService corrigido
    $conteudoCorrigido = '<?php
/**
 * Sistema de Boletos IMED - Serviço de Alunos (Versão Corrigida)
 * Arquivo: src/AlunoService.php
 * 
 * Classe responsável pelo gerenciamento de dados dos alunos
 */

require_once __DIR__ . \'/../config/database.php\';

class AlunoService {
    
    private $db;
    
    /**
     * Construtor
     */
    public function __construct() {
        try {
            $this->db = (new Database())->getConnection();
        } catch (Exception $e) {
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
            error_log("AlunoService: Iniciando salvamento/atualização do aluno CPF: " . ($dadosAluno[\'cpf\'] ?? \'N/A\'));
            
            // Validação básica dos dados
            if (empty($dadosAluno[\'cpf\'])) {
                throw new Exception("CPF é obrigatório");
            }
            
            if (empty($dadosAluno[\'nome\'])) {
                throw new Exception("Nome é obrigatório");
            }
            
            if (empty($dadosAluno[\'subdomain\'])) {
                throw new Exception("Subdomain é obrigatório");
            }
            
            $this->db->beginTransaction();
            error_log("AlunoService: Transação iniciada");
            
            // Verifica se aluno já existe
            $stmt = $this->db->prepare("SELECT id, updated_at FROM alunos WHERE cpf = ? LIMIT 1");
            $stmt->execute([$dadosAluno[\'cpf\']]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                error_log("AlunoService: Aluno existe, atualizando ID: " . $alunoExistente[\'id\']);
                $alunoId = $this->atualizarAluno($alunoExistente[\'id\'], $dadosAluno);
            } else {
                error_log("AlunoService: Aluno não existe, criando novo");
                $alunoId = $this->criarAluno($dadosAluno);
            }
            
            // Atualiza/cria cursos e matrículas se existirem
            if (!empty($dadosAluno[\'cursos\']) && is_array($dadosAluno[\'cursos\'])) {
                error_log("AlunoService: Processando " . count($dadosAluno[\'cursos\']) . " cursos");
                $this->atualizarCursosAluno($alunoId, $dadosAluno[\'cursos\'], $dadosAluno[\'subdomain\']);
            } else {
                error_log("AlunoService: Nenhum curso fornecido para processar");
            }
            
            $this->db->commit();
            error_log("AlunoService: Transação commitada com sucesso. Aluno ID: " . $alunoId);
            
            // Log da operação
            $this->registrarLog(\'aluno_sincronizado\', $alunoId, "Dados sincronizados do Moodle: {$dadosAluno[\'subdomain\']}");
            
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
                $dadosAluno[\'cpf\'],
                $dadosAluno[\'nome\'],
                $dadosAluno[\'email\'] ?? null,
                $dadosAluno[\'moodle_user_id\'] ?? null,
                $dadosAluno[\'subdomain\'],
                $dadosAluno[\'city\'] ?? null,
                $dadosAluno[\'country\'] ?? \'BR\',
                $dadosAluno[\'profile_image\'] ?? null,
                $dadosAluno[\'primeiro_acesso\'] ?? null,
                $dadosAluno[\'ultimo_acesso\'] ?? null
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
                $dadosAluno[\'nome\'],
                $dadosAluno[\'email\'] ?? null,
                $dadosAluno[\'moodle_user_id\'] ?? null,
                $dadosAluno[\'subdomain\'],
                $dadosAluno[\'city\'] ?? null,
                $dadosAluno[\'country\'] ?? \'BR\',
                $dadosAluno[\'profile_image\'] ?? null,
                $dadosAluno[\'ultimo_acesso\'] ?? null,
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
                error_log("AlunoService: Processando curso " . ($index + 1) . ": " . ($cursoMoodle[\'nome\'] ?? \'Nome não informado\'));
                
                // Validação básica do curso
                if (empty($cursoMoodle[\'moodle_course_id\'])) {
                    error_log("AlunoService: Curso sem ID do Moodle, pulando");
                    continue;
                }
                
                if (empty($cursoMoodle[\'nome\'])) {
                    error_log("AlunoService: Curso sem nome, pulando");
                    continue;
                }
                
                // Verifica se curso já existe
                $stmt = $this->db->prepare("
                    SELECT id FROM cursos 
                    WHERE moodle_course_id = ? AND subdomain = ?
                    LIMIT 1
                ");
                $stmt->execute([$cursoMoodle[\'moodle_course_id\'], $subdomain]);
                $cursoExistente = $stmt->fetch();
                
                if ($cursoExistente) {
                    $cursoId = $cursoExistente[\'id\'];
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
                    error_log("AlunoService: Matrícula existe ID: " . $matriculaExistente[\'id\']);
                    // Atualiza matrícula se necessário
                    if ($matriculaExistente[\'status\'] !== \'ativa\') {
                        $this->atualizarMatricula($matriculaExistente[\'id\'], \'ativa\');
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
                $cursoMoodle[\'moodle_course_id\'],
                $cursoMoodle[\'nome\'],
                $cursoMoodle[\'nome_curto\'] ?? \'\',
                0.00, // Valor padrão, será definido administrativamente
                $subdomain,
                $cursoMoodle[\'categoria\'] ?? null,
                $cursoMoodle[\'data_inicio\'] ?? null,
                $cursoMoodle[\'data_fim\'] ?? null,
                $cursoMoodle[\'formato\'] ?? \'topics\',
                $cursoMoodle[\'summary\'] ?? \'\',
                $cursoMoodle[\'url\'] ?? null,
                true
            ]);
            
            if (!$resultado) {
                throw new Exception("Falha ao inserir curso no banco");
            }
            
            $cursoId = $this->db->lastInsertId();
            error_log("AlunoService: Curso criado ID: " . $cursoId);
            
            return $cursoId;
            
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
                $cursoMoodle[\'nome\'],
                $cursoMoodle[\'nome_curto\'] ?? \'\',
                $cursoMoodle[\'categoria\'] ?? null,
                $cursoMoodle[\'data_inicio\'] ?? null,
                $cursoMoodle[\'data_fim\'] ?? null,
                $cursoMoodle[\'formato\'] ?? \'topics\',
                $cursoMoodle[\'summary\'] ?? \'\',
                $cursoMoodle[\'url\'] ?? null,
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
            
            $dataMatricula = $cursoMoodle[\'data_inicio\'] ?? date(\'Y-m-d\');
            
            $resultado = $stmt->execute([
                $alunoId,
                $cursoId,
                \'ativa\',
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
            $cpf = preg_replace(\'/[^0-9]/\', \'\', $cpf);
            
            if (strlen($cpf) !== 11) {
                throw new Exception("CPF deve ter 11 dígitos");
            }
            
            $stmt = $this->db->prepare("SELECT * FROM alunos WHERE cpf = ? LIMIT 1");
            $stmt->execute([$cpf]);
            
            $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($aluno) {
                error_log("AlunoService: Aluno encontrado por CPF: " . $cpf . " ID: " . $aluno[\'id\']);
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
     * Busca cursos ativos de um aluno
     */
    public function buscarCursosAluno($alunoId) {
        try {
            $stmt = $this->db->prepare("
                SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
                FROM cursos c
                INNER JOIN matriculas m ON c.id = m.curso_id
                WHERE m.aluno_id = ? AND m.status = \'ativa\' AND c.ativo = 1
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
                $_SERVER[\'REMOTE_ADDR\'] ?? \'unknown\',
                $_SERVER[\'HTTP_USER_AGENT\'] ?? \'unknown\'
            ]);
        } catch (Exception $e) {
            // Log de erro sem interromper o fluxo principal
            error_log("AlunoService: Erro ao registrar log: " . $e->getMessage());
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
            
            if ($resultado[\'teste\'] !== 1) {
                throw new Exception("Falha no teste de conexão");
            }
            
            // Verifica se tabelas essenciais existem
            $tabelas = [\'alunos\', \'cursos\', \'matriculas\'];
            foreach ($tabelas as $tabela) {
                $stmt = $this->db->query("SHOW TABLES LIKE \'{$tabela}\'");
                if ($stmt->rowCount() === 0) {
                    throw new Exception("Tabela {$tabela} não encontrada");
                }
            }
            
            return [
                \'status\' => \'ok\',
                \'timestamp\' => date(\'Y-m-d H:i:s\'),
                \'tabelas_verificadas\' => $tabelas
            ];
            
        } catch (Exception $e) {
            error_log("AlunoService: Falha no teste de serviço: " . $e->getMessage());
            return [
                \'status\' => \'error\',
                \'error\' => $e->getMessage(),
                \'timestamp\' => date(\'Y-m-d H:i:s\')
            ];
        }
    }
}
?>';

    // Escreve o arquivo
    if (file_put_contents($arquivoDestino, $conteudoCorrigido)) {
        echo "<span class='ok'>✓ Arquivo AlunoService.php criado com sucesso</span><br>";
    } else {
        echo "<span class='error'>✗ Falha ao escrever arquivo</span><br>";
        exit;
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Testando Novo AlunoService</h3>";
    
    // Remove cache de classes se existir
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    
    // Força recarga da classe
    require_once $arquivoDestino;
    
    try {
        $alunoService = new AlunoService();
        echo "<span class='ok'>✓ AlunoService carregado sem erros</span><br>";
        
        // Testa o serviço
        $testeServico = $alunoService->testarServico();
        
        if ($testeServico['status'] === 'ok') {
            echo "<span class='ok'>✓ Teste de serviço passou</span><br>";
            echo "Tabelas verificadas: " . implode(', ', $testeServico['tabelas_verificadas']) . "<br>";
        } else {
            echo "<span class='error'>✗ Teste de serviço falhou: " . $testeServico['error'] . "</span><br>";
        }
        
        // Testa método específico
        if (method_exists($alunoService, 'salvarOuAtualizarAluno')) {
            echo "<span class='ok'>✓ Método salvarOuAtualizarAluno existe</span><br>";
        } else {
            echo "<span class='error'>✗ Método salvarOuAtualizarAluno não encontrado</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro ao carregar AlunoService: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Teste Completo</h3>";
    
    // Dados de teste
    $dadosAluno = [
        'nome' => 'Carlos Santos',
        'cpf' => '03183924536',
        'email' => 'diego2008tuc@gmail.com',
        'moodle_user_id' => 4,
        'subdomain' => 'breubranco.imepedu.com.br',
        'cursos' => [
            [
                'moodle_course_id' => 91,
                'nome' => 'NR-35',
                'nome_curto' => 'nr35',
                'categoria' => 1,
                'data_inicio' => '2025-01-01',
                'url' => 'https://breubranco.imepedu.com.br/course/view.php?id=91'
            ],
            [
                'moodle_course_id' => 90,
                'nome' => 'NR-33',
                'nome_curto' => 'nr33',
                'categoria' => 1,
                'data_inicio' => '2025-01-01',
                'url' => 'https://breubranco.imepedu.com.br/course/view.php?id=90'
            ]
        ]
    ];
    
    try {
        echo "Testando salvamento com dados reais...<br>";
        
        $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAluno);
        echo "<span class='ok'>✓ Aluno salvo/atualizado com sucesso! ID: {$alunoId}</span><br>";
        
        // Verifica cursos salvos
        $cursosLocal = $alunoService->buscarCursosAluno($alunoId);
        echo "Cursos encontrados no banco local: " . count($cursosLocal) . "<br>";
        
        if (count($cursosLocal) > 0) {
            echo "<strong>Cursos salvos:</strong><br>";
            foreach ($cursosLocal as $curso) {
                echo "- {$curso['nome']} (ID: {$curso['id']})<br>";
            }
        }
        
        // Verifica aluno
        $alunoVerificacao = $alunoService->buscarAlunoPorCPF($dadosAluno['cpf']);
        if ($alunoVerificacao) {
            echo "<span class='ok'>✓ Aluno pode ser encontrado por CPF</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro no teste de salvamento: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Resumo</h3>";
    echo "<span class='ok'>✓ AlunoService substituído com sucesso!</span><br>";
    echo "<strong>Arquivo de backup:</strong> {$arquivoBackup}<br>";
    echo "<strong>Próximos passos:</strong><br>";
    echo "<ol>";
    echo "<li><a href='debug_completo.php' target='_blank'>Execute o debug completo novamente</a></li>";
    echo "<li><a href='index.php'>Teste o login no sistema</a></li>";
    echo "<li><a href='dashboard.php' target='_blank'>Verifique se o dashboard mostra os cursos</a></li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro durante a substituição: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>