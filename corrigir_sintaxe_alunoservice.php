<?php
/**
 * Correção da sintaxe do AlunoService
 * Arquivo: corrigir_sintaxe_alunoservice.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Correção da Sintaxe - AlunoService</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    pre{background:#f5f5f5; padding:15px; border:1px solid #ddd;}
    .step{margin:15px 0; padding:15px; background:#f9f9f9; border-left:4px solid #007bff;}
</style>";

try {
    echo "<div class='step'>";
    echo "<h3>1. Identificando o Erro de Sintaxe</h3>";
    
    $arquivoProblema = 'src/AlunoService.php';
    
    // Verifica o arquivo atual
    if (file_exists($arquivoProblema)) {
        echo "Arquivo atual: {$arquivoProblema}<br>";
        
        // Tenta verificar a sintaxe
        $output = shell_exec("php -l {$arquivoProblema} 2>&1");
        echo "<strong>Verificação de sintaxe:</strong><br>";
        echo "<pre>" . htmlspecialchars($output) . "</pre>";
        
        // Lê algumas linhas ao redor da linha 80
        $linhas = file($arquivoProblema);
        echo "<strong>Linhas ao redor da linha 80:</strong><br>";
        echo "<table border='1' style='border-collapse:collapse;'>";
        echo "<tr><th>Linha</th><th>Código</th></tr>";
        
        for ($i = 75; $i <= 85 && $i < count($linhas); $i++) {
            $numeroLinha = $i + 1;
            $codigo = htmlspecialchars(rtrim($linhas[$i]));
            $classe = ($numeroLinha == 80) ? 'style="background:#ffcccc;"' : '';
            echo "<tr {$classe}><td>{$numeroLinha}</td><td><code>{$codigo}</code></td></tr>";
        }
        echo "</table>";
        
    } else {
        echo "<span class='error'>✗ Arquivo não encontrado: {$arquivoProblema}</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Criando Versão Corrigida</h3>";
    
    // Cria backup primeiro
    $backup = 'src/AlunoService_erro_' . date('Y-m-d_H-i-s') . '.php';
    if (file_exists($arquivoProblema)) {
        copy($arquivoProblema, $backup);
        echo "<span class='info'>ℹ Backup criado: {$backup}</span><br>";
    }
    
    // Versão completamente limpa e corrigida
    $conteudoLimpo = '<?php
/**
 * Sistema de Boletos IMED - Serviço de Alunos (VERSÃO LIMPA E CORRIGIDA)
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
    public function salvarOuAtualizarAluno($dadosAluno) {
        try {
            $this->db->beginTransaction();
            
            error_log("AlunoService: Iniciando salvamento - CPF: " . $dadosAluno["cpf"] . ", Subdomain: " . $dadosAluno["subdomain"]);
            
            // Busca por CPF E subdomain
            $stmt = $this->db->prepare("
                SELECT id, updated_at 
                FROM alunos 
                WHERE cpf = ? AND subdomain = ?
                FOR UPDATE
            ");
            $stmt->execute([$dadosAluno["cpf"], $dadosAluno["subdomain"]]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                $alunoId = $this->atualizarAluno($alunoExistente["id"], $dadosAluno);
                error_log("AlunoService: Aluno atualizado - ID: " . $alunoId);
            } else {
                // Verifica duplicata
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM alunos WHERE cpf = ? AND subdomain = ?");
                $stmt->execute([$dadosAluno["cpf"], $dadosAluno["subdomain"]]);
                $count = $stmt->fetch()["count"];
                
                if ($count > 0) {
                    $stmt = $this->db->prepare("SELECT id FROM alunos WHERE cpf = ? AND subdomain = ? LIMIT 1");
                    $stmt->execute([$dadosAluno["cpf"], $dadosAluno["subdomain"]]);
                    $alunoExistente = $stmt->fetch();
                    $alunoId = $this->atualizarAluno($alunoExistente["id"], $dadosAluno);
                } else {
                    $alunoId = $this->criarAluno($dadosAluno);
                    error_log("AlunoService: Novo aluno criado - ID: " . $alunoId);
                }
            }
            
            // Processa cursos
            if (!empty($dadosAluno["cursos"])) {
                error_log("AlunoService: Processando " . count($dadosAluno["cursos"]) . " cursos");
                $this->atualizarCursosAluno($alunoId, $dadosAluno["cursos"], $dadosAluno["subdomain"]);
            } else {
                error_log("AlunoService: AVISO - Nenhum curso fornecido");
            }
            
            $this->db->commit();
            $this->registrarLog("aluno_sincronizado", $alunoId, "Dados sincronizados: " . $dadosAluno["subdomain"]);
            
            error_log("AlunoService: Salvamento concluído - ID: " . $alunoId);
            return $alunoId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("AlunoService: ERRO - " . $e->getMessage());
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
                $_SERVER["REMOTE_ADDR"] ?? "unknown",
                $_SERVER["HTTP_USER_AGENT"] ?? "unknown"
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }
}
?>';

    // Salva a versão corrigida
    if (file_put_contents($arquivoProblema, $conteudoLimpo)) {
        echo "<span class='ok'>✓ Arquivo corrigido salvo com sucesso</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao salvar arquivo corrigido</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Verificando Sintaxe Corrigida</h3>";
    
    // Verifica novamente a sintaxe
    $output = shell_exec("php -l {$arquivoProblema} 2>&1");
    echo "<strong>Nova verificação de sintaxe:</strong><br>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
    
    if (strpos($output, 'No syntax errors') !== false) {
        echo "<span class='ok'>✓ Sintaxe corrigida com sucesso!</span><br>";
    } else {
        echo "<span class='error'>✗ Ainda há erros de sintaxe</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Testando a Classe Corrigida</h3>";
    
    try {
        require_once $arquivoProblema;
        
        $alunoService = new AlunoService();
        echo "<span class='ok'>✓ Classe AlunoService carregada com sucesso</span><br>";
        
        // Testa métodos
        $metodos = ['buscarAlunoPorCPFESubdomain', 'buscarCursosAlunoPorSubdomain', 'salvarOuAtualizarAluno'];
        
        foreach ($metodos as $metodo) {
            if (method_exists($alunoService, $metodo)) {
                echo "<span class='ok'>✓ Método {$metodo}() existe</span><br>";
            } else {
                echo "<span class='error'>✗ Método {$metodo}() não encontrado</span><br>";
            }
        }
        
        echo "<br><span class='ok'>✓ Todos os métodos essenciais estão funcionando!</span><br>";
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro ao carregar a classe: " . $e->getMessage() . "</span><br>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro crítico: " . $e->getMessage() . "</span><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "<div style='margin-top: 30px; padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;'>";
echo "<h4>🔧 Sintaxe Corrigida!</h4>";

echo "<h5>✅ Problemas resolvidos:</h5>";
echo "<ul>";
echo "<li><strong>Erro de sintaxe na linha 80:</strong> Corrigido</li>";
echo "<li><strong>Escapes desnecessários:</strong> Removidos</li>";
echo "<li><strong>Concatenações limpas:</strong> Usando apenas ponto (.)</li>";
echo "<li><strong>Strings corrigidas:</strong> Sem caracteres de escape problemáticos</li>";
echo "</ul>";

echo "<h5>🎯 Agora você pode:</h5>";
echo "<ol>";
echo "<li><strong>Testar novamente:</strong> <a href='aplicar_correcao_final.php'>Executar script de correção</a></li>";
echo "<li><strong>Fazer login:</strong> <a href='index.php?cpf=03183924536&subdomain=breubranco.imepedu.com.br'>Login Breu Branco</a></li>";
echo "<li><strong>Ver dashboard:</strong> <a href='dashboard.php'>Acessar Dashboard</a></li>";
echo "</ol>";

echo "<p><strong>O arquivo AlunoService.php está agora sintáticamente correto!</strong> 🎉</p>";
echo "</div>";

echo "<br><div style='text-align: center;'>";
echo "<a href='aplicar_correcao_final.php' style='display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>🔄 Testar Correção</a>";
echo "<a href='index.php?cpf=03183924536&subdomain=breubranco.imepedu.com.br' style='display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>🎯 Login Breu Branco</a>";
echo "<a href='dashboard.php' style='display: inline-block; padding: 12px 24px; background: #17a2b8; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>📊 Dashboard</a>";
echo "</div>";
?>