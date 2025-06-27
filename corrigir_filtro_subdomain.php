<?php
/**
 * Script para corrigir o filtro por subdomínio no AlunoService
 * Arquivo: corrigir_filtro_subdomain.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Correção do Filtro por Subdomínio</h1>";
echo "<style>
    body{font-family:Arial; line-height:1.6;}
    .ok{color:green; font-weight:bold;}
    .error{color:red; font-weight:bold;}
    .warning{color:orange; font-weight:bold;}
    .info{color:blue; font-weight:bold;}
    .step{margin:10px 0; padding:10px; background:#f9f9f9; border-left:4px solid #007bff;}
    pre{background:#f5f5f5; padding:10px; border:1px solid #ddd; overflow-x:auto;}
    .highlight{background:#fff3cd; padding:10px; border:1px solid #ffeaa7; border-radius:5px; margin:10px 0;}
</style>";

try {
    require_once 'config/database.php';
    
    echo "<div class='step'>";
    echo "<h3>1. Analisando Problema</h3>";
    echo "<div class='highlight'>";
    echo "<strong>Problema identificado:</strong><br>";
    echo "- CPF: 03183924536 está matriculado em múltiplos polos<br>";
    echo "- Sistema está mostrando cursos de TODOS os polos<br>";
    echo "- Deveria mostrar apenas cursos do polo da sessão atual<br>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Verificando Dados Atuais</h3>";
    
    $db = new Database();
    $connection = $db->getConnection();
    
    $cpf = '03183924536';
    
    // Busca todos os registros do aluno em diferentes subdomains
    $stmt = $connection->prepare("SELECT * FROM alunos WHERE cpf = ?");
    $stmt->execute([$cpf]);
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Registros de aluno encontrados: " . count($alunos) . "<br>";
    
    foreach ($alunos as $aluno) {
        echo "- Aluno ID: {$aluno['id']}, Nome: {$aluno['nome']}, Subdomain: {$aluno['subdomain']}<br>";
        
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
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Criando AlunoService Corrigido</h3>";
    
    // Cria versão corrigida do AlunoService
    $alunoServiceCorrigido = '<?php
/**
 * Sistema de Boletos IMED - Serviço de Alunos (Versão Corrigida com Filtro)
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
            } else {
                // Cria novo aluno (mesmo CPF pode existir em múltiplos subdomínios)
                $alunoId = $this->criarAluno($dadosAluno);
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
            error_log("Erro ao salvar/atualizar aluno: " . $e->getMessage());
            throw new Exception("Erro ao processar dados do aluno");
        }
    }
    
    /**
     * Busca aluno por CPF E subdomínio específico
     */
    public function buscarAlunoPorCPFESubdomain($cpf, $subdomain) {
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
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca aluno por CPF (busca geral - para compatibilidade)
     */
    public function buscarAlunoPorCPF($cpf) {
        $cpf = preg_replace("/[^0-9]/", "", $cpf);
        
        $stmt = $this->db->prepare("
            SELECT * FROM alunos 
            WHERE cpf = ? 
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$cpf]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca cursos ativos de um aluno FILTRADOS por subdomínio
     */
    public function buscarCursosAluno($alunoId, $filtrarPorSubdomain = null) {
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
    }
    
    // [Resto dos métodos permanecem iguais...]
    
    private function criarAluno($dadosAluno) {
        $stmt = $this->db->prepare("
            INSERT INTO alunos (
                cpf, nome, email, moodle_user_id, subdomain, 
                city, country, profile_image, primeiro_acesso,
                ultimo_acesso_moodle, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $dadosAluno["cpf"],
            $dadosAluno["nome"],
            $dadosAluno["email"],
            $dadosAluno["moodle_user_id"],
            $dadosAluno["subdomain"],
            $dadosAluno["city"] ?? null,
            $dadosAluno["country"] ?? "BR",
            $dadosAluno["profile_image"] ?? null,
            $dadosAluno["primeiro_acesso"] ?? null,
            $dadosAluno["ultimo_acesso"] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    private function atualizarAluno($alunoId, $dadosAluno) {
        $stmt = $this->db->prepare("
            UPDATE alunos 
            SET nome = ?, email = ?, moodle_user_id = ?, 
                city = ?, country = ?, profile_image = ?,
                ultimo_acesso_moodle = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $dadosAluno["nome"],
            $dadosAluno["email"],
            $dadosAluno["moodle_user_id"],
            $dadosAluno["city"] ?? null,
            $dadosAluno["country"] ?? "BR",
            $dadosAluno["profile_image"] ?? null,
            $dadosAluno["ultimo_acesso"] ?? null,
            $alunoId
        ]);
        
        return $alunoId;
    }
    
    private function atualizarCursosAluno($alunoId, $cursosMoodle, $subdomain) {
        foreach ($cursosMoodle as $cursoMoodle) {
            // Verifica se curso já existe NESTE subdomínio
            $stmt = $this->db->prepare("
                SELECT id FROM cursos 
                WHERE moodle_course_id = ? AND subdomain = ?
                LIMIT 1
            ");
            $stmt->execute([$cursoMoodle["moodle_course_id"], $subdomain]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                $cursoId = $cursoExistente["id"];
                $this->atualizarCurso($cursoId, $cursoMoodle);
            } else {
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
                if ($matriculaExistente["status"] !== "ativa") {
                    $this->atualizarMatricula($matriculaExistente["id"], "ativa");
                }
            } else {
                $this->criarMatricula($alunoId, $cursoId, $cursoMoodle);
            }
        }
    }
    
    private function criarCurso($cursoMoodle, $subdomain) {
        $stmt = $this->db->prepare("
            INSERT INTO cursos (
                moodle_course_id, nome, nome_curto, valor, subdomain,
                categoria_id, data_inicio, data_fim, formato, summary,
                url, ativo, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
            true
        ]);
        
        return $this->db->lastInsertId();
    }
    
    private function atualizarCurso($cursoId, $cursoMoodle) {
        $stmt = $this->db->prepare("
            UPDATE cursos 
            SET nome = ?, nome_curto = ?, categoria_id = ?,
                data_inicio = ?, data_fim = ?, formato = ?,
                summary = ?, url = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $cursoMoodle["nome"],
            $cursoMoodle["nome_curto"] ?? "",
            $cursoMoodle["categoria"] ?? null,
            $cursoMoodle["data_inicio"] ?? null,
            $cursoMoodle["data_fim"] ?? null,
            $cursoMoodle["formato"] ?? "topics",
            $cursoMoodle["summary"] ?? "",
            $cursoMoodle["url"] ?? null,
            $cursoId
        ]);
    }
    
    private function criarMatricula($alunoId, $cursoId, $cursoMoodle) {
        $stmt = $this->db->prepare("
            INSERT INTO matriculas (
                aluno_id, curso_id, status, data_matricula, created_at
            ) VALUES (?, ?, ?, ?, NOW())
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
    
    private function atualizarMatricula($matriculaId, $novoStatus) {
        $stmt = $this->db->prepare("
            UPDATE matriculas 
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$novoStatus, $matriculaId]);
    }
    
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
    if (file_put_contents('src/AlunoService_corrigido.php', $alunoServiceCorrigido)) {
        echo "<span class='ok'>✓ AlunoService corrigido criado</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao criar arquivo corrigido</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Criando Dashboard Corrigido</h3>";
    
    // Dashboard que usa o filtro por subdomínio
    $dashboardCorrigido = '<?php
session_start();

if (!isset($_SESSION["aluno_cpf"])) {
    header("Location: /login.php");
    exit;
}

require_once "config/database.php";
require_once "src/AlunoService.php";

$alunoService = new AlunoService();

// CORREÇÃO: Busca aluno específico do subdomínio da sessão
$aluno = $alunoService->buscarAlunoPorCPFESubdomain($_SESSION["aluno_cpf"], $_SESSION["subdomain"]);

if (!$aluno) {
    // Fallback: busca geral se não encontrar no subdomínio específico
    $aluno = $alunoService->buscarAlunoPorCPF($_SESSION["aluno_cpf"]);
}

if (!$aluno) {
    session_destroy();
    header("Location: /login.php");
    exit;
}

// CORREÇÃO: Busca cursos FILTRADOS pelo subdomínio da sessão
$cursos = $alunoService->buscarCursosAluno($aluno["id"], $_SESSION["subdomain"]);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - Sistema de Boletos</title>
    <link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\">
</head>
<body>
    <div class=\"container mt-4\">
        <h1>Bem-vindo, " . htmlspecialchars($aluno["nome"]) . "</h1>
        <p><strong>Polo:</strong> " . htmlspecialchars($_SESSION["subdomain"]) . "</p>
        
        <div class=\"alert alert-info\">
            <h5>Cursos Filtrados por Polo</h5>
            <p>Exibindo apenas cursos do polo atual: <strong>" . $_SESSION["subdomain"] . "</strong></p>
        </div>
        
        <h3>Seus Cursos</h3>";
        
if (empty($cursos)) {
    echo "<div class=\"alert alert-warning\">
        <h5>Nenhum curso encontrado neste polo</h5>
        <p>Não foram encontrados cursos ativos para você neste polo específico.</p>
        <p><small>Se você tem cursos em outros polos, acesse-os diretamente pelo Moodle correspondente.</small></p>
    </div>";
} else {
    echo "<div class=\"row\">";
    foreach ($cursos as $curso) {
        echo "<div class=\"col-md-6 mb-3\">
            <div class=\"card\">
                <div class=\"card-body\">
                    <h5 class=\"card-title\">" . htmlspecialchars($curso["nome"]) . "</h5>
                    <p class=\"card-text\">
                        <small class=\"text-muted\">Polo: " . htmlspecialchars($curso["subdomain"]) . "</small><br>
                        <small class=\"text-muted\">Status: " . htmlspecialchars($curso["matricula_status"]) . "</small>
                    </p>
                </div>
            </div>
        </div>";
    }
    echo "</div>";
}

echo "        <hr>
        <div class=\"d-flex justify-content-between\">
            <a href=\"/logout.php\" class=\"btn btn-secondary\">Sair</a>
            <button class=\"btn btn-primary\" onclick=\"atualizarDados()\">Atualizar Dados</button>
        </div>
    </div>
    
    <script>
    function atualizarDados() {
        fetch(\"/api/atualizar_dados.php\", {
            method: \"POST\",
            headers: {\"Content-Type\": \"application/json\"}
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(\"Dados atualizados! Cursos: \" + (data.data?.cursos_encontrados || 0));
                location.reload();
            } else {
                alert(\"Erro: \" + data.message);
            }
        });
    }
    </script>
</body>
</html>";
?>';
    
    if (file_put_contents('dashboard_com_filtro.php', $dashboardCorrigido)) {
        echo "<span class='ok'>✓ Dashboard com filtro criado</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao criar dashboard</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Testando Correção</h3>";
    
    // Testa o novo método
    require_once 'src/AlunoService_corrigido.php';
    
    $alunoServiceCorrigido = new AlunoService();
    
    // Testa busca por subdomínio específico
    $subdomainsParaTestar = [
        'breubranco.imepedu.com.br' => 'Breu Branco',
        'igarape.imepedu.com.br' => 'Igarapé'
    ];
    
    foreach ($subdomainsParaTestar as $subdomainTeste => $nomePolo) {
        echo "<h4>Testando polo: {$nomePolo}</h4>";
        
        // Busca aluno específico para este subdomain
        $alunoEspecifico = $alunoServiceCorrigido->buscarAlunoPorCPFESubdomain($cpf, $subdomainTeste);
        
        if ($alunoEspecifico) {
            echo "- <span class='ok'>✓ Aluno encontrado: {$alunoEspecifico['nome']} (ID: {$alunoEspecifico['id']})</span><br>";
            
            // Busca cursos filtrados
            $cursosFiltrados = $alunoServiceCorrigido->buscarCursosAluno($alunoEspecifico['id'], $subdomainTeste);
            echo "- Cursos filtrados: " . count($cursosFiltrados) . "<br>";
            
            if (count($cursosFiltrados) > 0) {
                foreach ($cursosFiltrados as $curso) {
                    echo "  * <span class='info'>{$curso['nome']}</span> (Subdomain: {$curso['subdomain']})<br>";
                }
            } else {
                echo "  <span class='warning'>⚠ Nenhum curso ativo encontrado neste polo</span><br>";
            }
            
            // Busca cursos SEM filtro para comparação
            $todosCursos = $alunoServiceCorrigido->buscarCursosAluno($alunoEspecifico['id']);
            echo "- Total de cursos (sem filtro): " . count($todosCursos) . "<br>";
            
        } else {
            echo "- <span class='warning'>⚠ Aluno não encontrado neste subdomain</span><br>";
        }
        echo "<br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>6. Próximos Passos</h3>";
    echo "<div class='highlight'>";
    echo "<strong>✅ Correção criada com sucesso!</strong><br><br>";
    
    echo "<strong>Para aplicar a correção:</strong><br>";
    echo "<ol>";
    echo "<li><strong>Substituir o AlunoService original:</strong><br>";
    echo "<code>cp src/AlunoService.php src/AlunoService_backup.php</code><br>";
    echo "<code>cp src/AlunoService_corrigido.php src/AlunoService.php</code></li>";
    echo "<li><strong>Substituir o dashboard:</strong><br>";
    echo "<code>cp dashboard.php dashboard_backup.php</code><br>";
    echo "<code>cp dashboard_com_filtro.php dashboard.php</code></li>";
    echo "<li><strong>Testar o login</strong> em ambos os polos</li>";
    echo "</ol>";
    
    echo "<strong>Resultado esperado:</strong><br>";
    echo "<ul>";
    echo "<li>🎯 Login em breubranco.imepedu.com.br → mostra apenas NR-35 e NR-33</li>";
    echo "<li>🎯 Login em igarape.imepedu.com.br → mostra apenas POLÍTICA DE SAÚDE PÚBLICA</li>";
    echo "<li>🚫 Não mistura mais cursos de polos diferentes</li>";
    echo "</ul>";
    echo "</div>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;">
    <h4>🎯 Resumo da Correção</h4>
    
    <div class="row">
        <div class="col-md-6">
            <h5>❌ Problema Anterior:</h5>
            <ul>
                <li>Sistema mostrava cursos de TODOS os polos</li>
                <li>breubranco.imepedu.com.br mostrava NR-35, NR-33 + POLÍTICA DE SAÚDE</li>
                <li>Busca por CPF não filtrava por subdomínio</li>
            </ul>
        </div>
        <div class="col-md-6">
            <h5>✅ Solução Aplicada:</h5>
            <ul>
                <li>Filtro por subdomínio na busca de cursos</li>
                <li>breubranco.imepedu.com.br mostra apenas NR-35 e NR-33</li>
                <li>Cada polo mostra apenas seus próprios cursos</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 20px;">
    <h4>🔗 Links de Teste</h4>
    <ul>
        <li><a href="dashboard_com_filtro.php" target="_blank">🏠 Dashboard com Filtro (Preview)</a></li>
        <li><a href="index.php" target="_blank">🔑 Teste Login com CPF 03183924536</a></li>
        <li><a href="debug_completo.php" target="_blank">🐛 Debug Completo</a></li>
    </ul>
</div>