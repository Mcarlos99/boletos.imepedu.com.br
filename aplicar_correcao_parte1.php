<?php
/**
 * Script para aplicar correção do filtro por subdomínio - PARTE 1
 * Arquivo: aplicar_correcao_parte1.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Aplicar Correção do Filtro por Subdomínio - PARTE 1</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    pre{background:#f5f5f5; padding:10px; border:1px solid #ddd; overflow-x:auto; max-height:300px;}
    .resultado{background:#d4edda; padding:15px; border:1px solid #c3e6cb; border-radius:5px; margin:20px 0;}
</style>";

try {
    echo "<div class='step'>";
    echo "<h3>🚀 PARTE 1: Preparação e Backup</h3>";
    echo "</div>";
    
    // Passo 1: Verificar problema atual
    echo "<div class='step'>";
    echo "<h4>1. Verificando Problema Atual</h4>";
    
    require_once 'config/database.php';
    
    $db = new Database();
    $connection = $db->getConnection();
    
    $cpf = '03183924536';
    
    // Busca todos os alunos com este CPF
    $stmt = $connection->prepare("SELECT * FROM alunos WHERE cpf = ?");
    $stmt->execute([$cpf]);
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "CPF de teste: <strong>{$cpf}</strong><br>";
    echo "Registros de aluno encontrados: " . count($alunos) . "<br><br>";
    
    foreach ($alunos as $aluno) {
        echo "- Aluno ID: {$aluno['id']}, Nome: {$aluno['nome']}, Subdomain: <strong>{$aluno['subdomain']}</strong><br>";
        
        // Busca cursos deste aluno
        $stmt = $connection->prepare("
            SELECT c.nome, c.subdomain, m.status
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ?
            ORDER BY c.subdomain, c.nome
        ");
        $stmt->execute([$aluno['id']]);
        $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "  Cursos matriculados: " . count($cursos) . "<br>";
        foreach ($cursos as $curso) {
            $statusColor = $curso['status'] == 'ativa' ? 'ok' : 'warning';
            echo "    - <span class='{$statusColor}'>{$curso['nome']} ({$curso['subdomain']}) - {$curso['status']}</span><br>";
        }
        echo "<br>";
    }
    
    echo "<div style='background:#fff3cd; padding:10px; border:1px solid #ffeaa7; border-radius:5px;'>";
    echo "<strong>❌ Problema identificado:</strong><br>";
    echo "O sistema está mostrando cursos de TODOS os polos para o mesmo CPF.<br>";
    echo "Cada polo deveria mostrar apenas seus próprios cursos.";
    echo "</div>";
    echo "</div>";
    
    // Passo 2: Fazer backup dos arquivos atuais
    echo "<div class='step'>";
    echo "<h4>2. Criando Backup dos Arquivos Atuais</h4>";
    
    $timestamp = date('Y-m-d_H-i-s');
    
    // Backup do AlunoService
    if (file_exists('src/AlunoService.php')) {
        $backupAluno = "src/AlunoService_backup_{$timestamp}.php";
        if (copy('src/AlunoService.php', $backupAluno)) {
            echo "<span class='ok'>✓ Backup do AlunoService criado: {$backupAluno}</span><br>";
        } else {
            echo "<span class='error'>✗ Falha ao criar backup do AlunoService</span><br>";
            exit;
        }
    } else {
        echo "<span class='warning'>⚠ AlunoService não encontrado</span><br>";
    }
    
    // Backup do Dashboard
    if (file_exists('dashboard.php')) {
        $backupDashboard = "dashboard_backup_{$timestamp}.php";
        if (copy('dashboard.php', $backupDashboard)) {
            echo "<span class='ok'>✓ Backup do Dashboard criado: {$backupDashboard}</span><br>";
        } else {
            echo "<span class='warning'>⚠ Dashboard não encontrado para backup</span><br>";
        }
    } else {
        echo "<span class='info'>ℹ Dashboard não encontrado (será criado)</span><br>";
    }
    
    // Backup da API
    if (file_exists('api/atualizar_dados.php')) {
        $backupApi = "api/atualizar_dados_backup_{$timestamp}.php";
        if (copy('api/atualizar_dados.php', $backupApi)) {
            echo "<span class='ok'>✓ Backup da API criado: {$backupApi}</span><br>";
        } else {
            echo "<span class='warning'>⚠ Falha ao criar backup da API</span><br>";
        }
    } else {
        echo "<span class='info'>ℹ API não encontrada</span><br>";
    }
    echo "</div>";
    
    // Passo 3: Verificar estrutura do banco
    echo "<div class='step'>";
    echo "<h4>3. Verificando Estrutura do Banco</h4>";
    
    // Verifica se tabelas existem
    $tabelas = ['alunos', 'cursos', 'matriculas'];
    $tabelasOk = true;
    
    foreach ($tabelas as $tabela) {
        try {
            $stmt = $connection->query("SHOW TABLES LIKE '{$tabela}'");
            if ($stmt->rowCount() > 0) {
                echo "<span class='ok'>✓ Tabela {$tabela} existe</span><br>";
            } else {
                echo "<span class='error'>✗ Tabela {$tabela} não existe</span><br>";
                $tabelasOk = false;
            }
        } catch (Exception $e) {
            echo "<span class='error'>✗ Erro ao verificar tabela {$tabela}: " . $e->getMessage() . "</span><br>";
            $tabelasOk = false;
        }
    }
    
    if (!$tabelasOk) {
        echo "<br><span class='error'>⚠ Execute primeiro o script de correção do banco: corrigir_banco.php</span><br>";
    } else {
        echo "<br><span class='ok'>✓ Estrutura do banco OK</span><br>";
    }
    echo "</div>";
    
    // Passo 4: Criar AlunoService corrigido
    echo "<div class='step'>";
    echo "<h4>4. Criando AlunoService Corrigido</h4>";
    
    $alunoServiceCorrigido = '<?php
/**
 * Sistema de Boletos IMED - Serviço de Alunos (CORRIGIDO com Filtro por Subdomínio)
 * Arquivo: src/AlunoService.php
 */

require_once __DIR__ . "/../config/database.php";

class AlunoService {
    
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    /**
     * Salva ou atualiza dados do aluno no banco local
     */
    public function salvarOuAtualizarAluno($dadosAluno) {
        try {
            $this->db->beginTransaction();
            
            // Verifica se aluno já existe NESTE subdomínio específico
            $stmt = $this->db->prepare("
                SELECT id, updated_at 
                FROM alunos 
                WHERE cpf = ? AND subdomain = ?
                LIMIT 1
            ");
            $stmt->execute([$dadosAluno["cpf"], $dadosAluno["subdomain"]]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                // Atualiza dados do aluno existente
                $alunoId = $this->atualizarAluno($alunoExistente["id"], $dadosAluno);
                error_log("AlunoService: Aluno atualizado ID: {$alunoId}, Subdomain: {$dadosAluno["subdomain"]}");
            } else {
                // Cria novo aluno (mesmo CPF pode existir em múltiplos subdomínios)
                $alunoId = $this->criarAluno($dadosAluno);
                error_log("AlunoService: Novo aluno criado ID: {$alunoId}, Subdomain: {$dadosAluno["subdomain"]}");
            }
            
            // Atualiza/cria cursos e matrículas APENAS do subdomínio atual
            if (!empty($dadosAluno["cursos"])) {
                $this->atualizarCursosAluno($alunoId, $dadosAluno["cursos"], $dadosAluno["subdomain"]);
            }
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog("aluno_sincronizado", $alunoId, "Dados sincronizados do Moodle: {$dadosAluno["subdomain"]}");
            
            return $alunoId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("AlunoService: Erro ao salvar/atualizar aluno: " . $e->getMessage());
            throw new Exception("Erro ao processar dados do aluno: " . $e->getMessage());
        }
    }
    
    /**
     * Busca aluno por CPF E subdomínio específico (NOVO MÉTODO)
     */
    public function buscarAlunoPorCPFESubdomain($cpf, $subdomain) {
        try {
            $cpf = preg_replace("/[^0-9]/", "", $cpf);
            
            if (strlen($cpf) !== 11) {
                throw new Exception("CPF deve ter 11 dígitos");
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM alunos 
                WHERE cpf = ? AND subdomain = ? 
                LIMIT 1
            ");
            $stmt->execute([$cpf, $subdomain]);
            
            $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($aluno) {
                error_log("AlunoService: Aluno encontrado por CPF: " . $cpf . " e subdomain: " . $subdomain . " ID: " . $aluno["id"]);
            } else {
                error_log("AlunoService: Aluno não encontrado por CPF: " . $cpf . " e subdomain: " . $subdomain);
            }
            
            return $aluno;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar aluno por CPF e subdomain: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Busca aluno por CPF (busca geral - para compatibilidade)
     */
    public function buscarAlunoPorCPF($cpf) {
        try {
            $cpf = preg_replace("/[^0-9]/", "", $cpf);
            
            if (strlen($cpf) !== 11) {
                throw new Exception("CPF deve ter 11 dígitos");
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM alunos 
                WHERE cpf = ? 
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            $stmt->execute([$cpf]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar aluno por CPF: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Busca cursos ativos de um aluno FILTRADOS por subdomínio (MÉTODO CORRIGIDO)
     */
    public function buscarCursosAluno($alunoId, $filtrarPorSubdomain = null) {
        try {
            $sql = "
                SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
                FROM cursos c
                INNER JOIN matriculas m ON c.id = m.curso_id
                WHERE m.aluno_id = ? AND m.status = \"ativa\" AND c.ativo = 1
            ";
            
            $params = [$alunoId];
            
            // Se um subdomínio específico for fornecido, filtra por ele
            if ($filtrarPorSubdomain) {
                $sql .= " AND c.subdomain = ?";
                $params[] = $filtrarPorSubdomain;
                error_log("AlunoService: Filtrando cursos por subdomain: " . $filtrarPorSubdomain);
            }
            
            $sql .= " ORDER BY c.nome ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("AlunoService: Cursos encontrados para aluno ID " . $alunoId . 
                     ($filtrarPorSubdomain ? " (filtrado por {$filtrarPorSubdomain})" : "") . 
                     ": " . count($cursos));
            
            return $cursos;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar cursos do aluno: " . $e->getMessage());
            return [];
        }
    }';
    
    // Adiciona métodos privados (continuação na parte 2)
    $alunoServiceCorrigido .= '
    
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
     * Teste de serviço (para diagnóstico)
     */
    public function testarServico() {
        try {
            // Testa conexão com banco
            $stmt = $this->db->query("SELECT 1 as test");
            $result = $stmt->fetch();
            
            if ($result["test"] != 1) {
                return ["status" => "error", "error" => "Falha no teste de conexão"];
            }
            
            // Verifica se tabelas existem
            $tabelas = ["alunos", "cursos", "matriculas"];
            $tabelasVerificadas = [];
            
            foreach ($tabelas as $tabela) {
                $stmt = $this->db->query("SHOW TABLES LIKE \"$tabela\"");
                if ($stmt->rowCount() > 0) {
                    $tabelasVerificadas[] = $tabela;
                }
            }
            
            return [
                "status" => "ok",
                "tabelas_verificadas" => $tabelasVerificadas
            ];
            
        } catch (Exception $e) {
            return ["status" => "error", "error" => $e->getMessage()];
        }
    }';
    
    // Salva a primeira parte
    if (file_put_contents('src/AlunoService_parte1.php', $alunoServiceCorrigido)) {
        echo "<span class='ok'>✓ Primeira parte do AlunoService criada</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao criar primeira parte</span><br>";
        exit;
    }
    echo "</div>";
    
    // Resultado da Parte 1
    echo "<div class='resultado'>";
    echo "<h3>✅ PARTE 1 Concluída com Sucesso!</h3>";
    
    echo "<h4>📝 O que foi feito:</h4>";
    echo "<ul>";
    echo "<li>✅ Problema analisado e identificado</li>";
    echo "<li>✅ Backups de segurança criados</li>";
    echo "<li>✅ Estrutura do banco verificada</li>";
    echo "<li>✅ Primeira parte do AlunoService corrigido criada</li>";
    echo "</ul>";
    
    echo "<h4>💾 Backups criados:</h4>";
    echo "<ul>";
    if (isset($backupAluno)) echo "<li><code>{$backupAluno}</code></li>";
    if (isset($backupDashboard)) echo "<li><code>{$backupDashboard}</code></li>";
    if (isset($backupApi)) echo "<li><code>{$backupApi}</code></li>";
    echo "</ul>";
    
    echo "<h4>🚀 Próximo passo:</h4>";
    echo "<p>Execute <strong>aplicar_correcao_parte2.php</strong> para completar a correção.</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro na Parte 1: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #e7f3ff; border: 1px solid #b6d7ff; border-radius: 5px;">
    <h4>📋 Status da Correção</h4>
    <p><strong>✅ PARTE 1:</strong> Preparação e backup concluídos</p>
    <p><strong>⏳ PARTE 2:</strong> Aguardando execução</p>
    
    <p><strong>Próximo passo:</strong> Execute <code>aplicar_correcao_parte2.php</code></p>
</div>

<div style="margin-top: 20px;">
    <h4>🔗 Links</h4>
    <ul>
        <li><a href="aplicar_correcao_parte2.php">▶️ Executar Parte 2</a></li>
        <li><a href="debug_completo.php" target="_blank">🐛 Debug Completo</a></li>
    </ul>
</div>