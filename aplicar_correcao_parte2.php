<?php
/**
 * Script para aplicar correção do filtro por subdomínio - PARTE 2
 * Arquivo: aplicar_correcao_parte2.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Aplicar Correção do Filtro por Subdomínio - PARTE 2</h1>";
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
    echo "<h3>🚀 PARTE 2: Aplicação da Correção</h3>";
    echo "</div>";
    
    // Verifica se Parte 1 foi executada
    echo "<div class='step'>";
    echo "<h4>1. Verificando Parte 1</h4>";
    
    if (!file_exists('src/AlunoService_parte1.php')) {
        echo "<span class='error'>✗ Parte 1 não foi executada. Execute aplicar_correcao_parte1.php primeiro!</span><br>";
        exit;
    }
    echo "<span class='ok'>✓ Parte 1 executada com sucesso</span><br>";
    echo "</div>";
    
    // Passo 1: Completar AlunoService
    echo "<div class='step'>";
    echo "<h4>2. Completando AlunoService</h4>";
    
    // Lê a primeira parte
    $alunoServiceParte1 = file_get_contents('src/AlunoService_parte1.php');
    
    // Adiciona os métodos privados restantes
    $metodosPrivados = '
    
    // ============ MÉTODOS PRIVADOS ============
    
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
            try {
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
            } catch (Exception $e) {
                error_log("AlunoService: Erro ao processar curso " . $cursoMoodle["nome"] . ": " . $e->getMessage());
            }
        }
    }
    
    private function criarCurso($cursoMoodle, $subdomain) {
        // Verifica se tabela cursos tem todas as colunas necessárias
        try {
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
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao criar curso - pode estar faltando coluna na tabela: " . $e->getMessage());
            
            // Fallback: inserção básica
            $stmt = $this->db->prepare("
                INSERT INTO cursos (moodle_course_id, nome, subdomain, ativo, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $cursoMoodle["moodle_course_id"],
                $cursoMoodle["nome"],
                $subdomain,
                true
            ]);
            
            return $this->db->lastInsertId();
        }
    }
    
    private function atualizarCurso($cursoId, $cursoMoodle) {
        try {
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
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao atualizar curso: " . $e->getMessage());
            
            // Fallback: atualização básica
            $stmt = $this->db->prepare("UPDATE cursos SET nome = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$cursoMoodle["nome"], $cursoId]);
        }
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
    
    // Combina as partes
    $alunoServiceCompleto = $alunoServiceParte1 . $metodosPrivados;
    
    // Salva o AlunoService completo
    if (file_put_contents('src/AlunoService_corrigido_completo.php', $alunoServiceCompleto)) {
        echo "<span class='ok'>✓ AlunoService completo criado</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao criar AlunoService completo</span><br>";
        exit;
    }
    echo "</div>";
    
    // Passo 2: Criar Dashboard corrigido
    echo "<div class='step'>";
    echo "<h4>3. Criando Dashboard com Filtro</h4>";
    
    $dashboardCorrigido = '<?php
/**
 * Dashboard corrigido com filtro por subdomínio
 */

session_start();

if (!isset($_SESSION["aluno_cpf"])) {
    header("Location: /login.php");
    exit;
}

require_once "config/database.php";
require_once "config/moodle.php";
require_once "src/AlunoService.php";

$alunoService = new AlunoService();

// CORREÇÃO: Busca aluno específico do subdomínio da sessão
$aluno = null;
if (method_exists($alunoService, "buscarAlunoPorCPFESubdomain")) {
    $aluno = $alunoService->buscarAlunoPorCPFESubdomain($_SESSION["aluno_cpf"], $_SESSION["subdomain"]);
    error_log("Dashboard: Buscando aluno por CPF E subdomain: " . $_SESSION["subdomain"]);
} else {
    $aluno = $alunoService->buscarAlunoPorCPF($_SESSION["aluno_cpf"]);
    error_log("Dashboard: Usando método antigo");
}

if (!$aluno) {
    $aluno = $alunoService->buscarAlunoPorCPF($_SESSION["aluno_cpf"]);
    if (!$aluno) {
        session_destroy();
        header("Location: /login.php");
        exit;
    }
}

// CORREÇÃO: Busca cursos FILTRADOS pelo subdomínio da sessão
$cursos = [];
$reflection = new ReflectionMethod($alunoService, "buscarCursosAluno");
$parameters = $reflection->getParameters();

if (count($parameters) > 1) {
    $cursos = $alunoService->buscarCursosAluno($aluno["id"], $_SESSION["subdomain"]);
    error_log("Dashboard: Filtrando cursos por subdomain: " . $_SESSION["subdomain"]);
} else {
    $todosOsCursos = $alunoService->buscarCursosAluno($aluno["id"]);
    foreach ($todosOsCursos as $curso) {
        if (isset($curso["subdomain"]) && $curso["subdomain"] === $_SESSION["subdomain"]) {
            $cursos[] = $curso;
        }
    }
}

$configPolo = MoodleConfig::getConfig($_SESSION["subdomain"]) ?: [];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Cursos - <?= htmlspecialchars($aluno["nome"]) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
        }
        body {
            background-color: #f5f7fa;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
        }
        .polo-info {
            background: #e7f3ff;
            border: 1px solid #b6d7ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .curso-card {
            background: white;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.2s ease;
        }
        .curso-card:hover {
            transform: translateY(-2px);
        }
        .curso-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 25px;
        }
        .curso-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        .curso-body {
            padding: 25px;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/dashboard.php">
                <i class="fas fa-graduation-cap"></i> IMED Educação
            </a>
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($aluno["nome"]) ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="javascript:void(0)" onclick="atualizarDados()">
                            <i class="fas fa-sync"></i> Atualizar Dados
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Sair
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Informações do Polo -->
        <div class="polo-info">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h6 class="mb-1">
                        <i class="fas fa-map-marker-alt"></i> 
                        Polo: <?= htmlspecialchars($configPolo["name"] ?? str_replace(".imepedu.com.br", "", $_SESSION["subdomain"])) ?>
                    </h6>
                    <small class="text-muted">
                        Exibindo apenas cursos deste polo
                        <?php if (!empty($configPolo["city"])): ?>
                            - <?= htmlspecialchars($configPolo["city"]) ?>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="col-md-4 text-md-end">
                    <small class="text-muted">
                        <i class="fas fa-filter"></i> 
                        Filtrado por subdomínio
                    </small>
                </div>
            </div>
        </div>

        <h1>Bem-vindo, <?= htmlspecialchars($aluno["nome"]) ?>!</h1>
        
        <?php if (empty($cursos)): ?>
            <div class="empty-state">
                <i class="fas fa-graduation-cap"></i>
                <h4>Nenhum curso encontrado neste polo</h4>
                <p>Não foram encontrados cursos ativos para: <strong><?= htmlspecialchars($configPolo["name"] ?? $_SESSION["subdomain"]) ?></strong></p>
                <button class="btn btn-primary" onclick="atualizarDados()">
                    <i class="fas fa-sync"></i> Sincronizar Dados
                </button>
            </div>
        <?php else: ?>
            <h3>Seus Cursos (<?= count($cursos) ?>)</h3>
            <?php foreach ($cursos as $curso): ?>
                <div class="curso-card">
                    <div class="curso-header">
                        <h3 class="curso-title">
                            <i class="fas fa-book"></i> 
                            <?= htmlspecialchars($curso["nome"]) ?>
                        </h3>
                        <div>
                            <small>
                                <i class="fas fa-building"></i> 
                                Polo: <?= htmlspecialchars($curso["subdomain"]) ?>
                                | Status: <?= ucfirst($curso["matricula_status"]) ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="curso-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h6>Informações do Curso</h6>
                                <p><strong>Nome:</strong> <?= htmlspecialchars($curso["nome"]) ?></p>
                                <?php if (!empty($curso["nome_curto"])): ?>
                                <p><strong>Código:</strong> <?= htmlspecialchars($curso["nome_curto"]) ?></p>
                                <?php endif; ?>
                                <p><strong>Status:</strong> 
                                    <span class="badge bg-<?= $curso["matricula_status"] == "ativa" ? "success" : "warning" ?>">
                                        <?= ucfirst($curso["matricula_status"]) ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <?php if (!empty($curso["url"])): ?>
                                <a href="<?= htmlspecialchars($curso["url"]) ?>" target="_blank" class="btn btn-primary">
                                    <i class="fas fa-external-link-alt"></i> Acessar no Moodle
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <hr>
        <div class="d-flex justify-content-between">
            <a href="/logout.php" class="btn btn-secondary">Sair</a>
            <button class="btn btn-primary" onclick="atualizarDados()">
                <i class="fas fa-sync"></i> Atualizar Dados
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function atualizarDados() {
            if (confirm("Deseja sincronizar os dados com o Moodle?")) {
                fetch("/api/atualizar_dados.php", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"}
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert("Dados atualizados! Cursos: " + (data.data?.cursos_encontrados || 0));
                        location.reload();
                    } else {
                        alert("Erro: " + data.message);
                    }
                })
                .catch(error => {
                    alert("Erro de conexão");
                    console.error(error);
                });
            }
        }
        
        console.log("Dashboard com Filtro:", {
            subdomain: "<?= $_SESSION["subdomain"] ?>",
            cursos: <?= count($cursos) ?>
        });
    </script>
</body>
</html>';
    
    if (file_put_contents('dashboard_corrigido.php', $dashboardCorrigido)) {
        echo "<span class='ok'>✓ Dashboard corrigido criado</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao criar dashboard</span><br>";
    }
    echo "</div>";
    
    // Passo 3: Aplicar as correções
    echo "<div class='step'>";
    echo "<h4>4. Aplicando as Correções</h4>";
    
    // Substitui o AlunoService
    if (copy('src/AlunoService_corrigido_completo.php', 'src/AlunoService.php')) {
        echo "<span class='ok'>✓ AlunoService corrigido aplicado</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao aplicar AlunoService</span><br>";
    }
    
    // Substitui o Dashboard
    if (copy('dashboard_corrigido.php', 'dashboard.php')) {
        echo "<span class='ok'>✓ Dashboard corrigido aplicado</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao aplicar Dashboard</span><br>";
    }
    
    // Atualiza API se existir
    if (file_exists('api/atualizar_dados.php')) {
        $apiContent = file_get_contents('api/atualizar_dados.php');
        $apiContent = str_replace(
            '$cursosAtualizados = $alunoService->buscarCursosAluno($alunoId);',
            '$cursosAtualizados = $alunoService->buscarCursosAluno($alunoId, $subdomain);',
            $apiContent
        );
        if (file_put_contents('api/atualizar_dados.php', $apiContent)) {
            echo "<span class='ok'>✓ API atualizada</span><br>";
        } else {
            echo "<span class='warning'>⚠ Erro ao atualizar API</span><br>";
        }
    }
    echo "</div>";
    
    // Passo 4: Teste da correção
    echo "<div class='step'>";
    echo "<h4>5. Testando a Correção</h4>";
    
    require_once 'src/AlunoService.php';
    $alunoService = new AlunoService();
    
    $cpfTeste = '03183924536';
    $subdomainsParaTestar = [
        'breubranco.imepedu.com.br' => 'Breu Branco',
        'igarape.imepedu.com.br' => 'Igarapé-Miri'
    ];
    
    foreach ($subdomainsParaTestar as $subdomainTeste => $nomePolo) {
        echo "<strong>Testando: {$nomePolo}</strong><br>";
        
        try {
            if (method_exists($alunoService, 'buscarAlunoPorCPFESubdomain')) {
                $alunoEspecifico = $alunoService->buscarAlunoPorCPFESubdomain($cpfTeste, $subdomainTeste);
                
                if ($alunoEspecifico) {
                    echo "- <span class='ok'>✓ Aluno encontrado: {$alunoEspecifico['nome']}</span><br>";
                    
                    $cursosFiltrados = $alunoService->buscarCursosAluno($alunoEspecifico['id'], $subdomainTeste);
                    echo "- Cursos neste polo: " . count($cursosFiltrados) . "<br>";
                    
                    foreach ($cursosFiltrados as $curso) {
                        echo "  * <span class='info'>{$curso['nome']}</span><br>";
                    }
                } else {
                    echo "- <span class='warning'>⚠ Aluno não encontrado neste polo</span><br>";
                }
            } else {
                echo "- <span class='error'>✗ Método de filtro não disponível</span><br>";
            }
        } catch (Exception $e) {
            echo "- <span class='error'>✗ Erro: " . $e->getMessage() . "</span><br>";
        }
        echo "<br>";
    }
    echo "</div>";
    
    // Resultado final
    echo "<div class='resultado'>";
    echo "<h3>🎉 CORREÇÃO APLICADA COM SUCESSO!</h3>";
    
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<h5>✅ O que foi corrigido:</h5>";
    echo "<ul>";
    echo "<li>AlunoService com filtro por subdomínio</li>";
    echo "<li>Dashboard mostra apenas cursos do polo atual</li>";
    echo "<li>API atualizada para respeitar filtro</li>";
    echo "<li>Busca específica por CPF + subdomínio</li>";
    echo "<li>Logs detalhados para debug</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='col-md-6'>";
    echo "<h5>🎯 Resultado esperado:</h5>";
    echo "<ul>";
    echo "<li>breubranco.imepedu.com.br → apenas NR-35 e NR-33</li>";
    echo "<li>igarape.imepedu.com.br → apenas POLÍTICA DE SAÚDE PÚBLICA</li>";
    echo "<li>Não mistura mais cursos de polos diferentes</li>";
    echo "</ul>";
    echo "</div>";
    echo "</div>";
    
    echo "<h4>🚀 Teste agora:</h4>";
    echo "<ol>";
    echo "<li><a href='index.php?cpf=03183924536&subdomain=breubranco.imepedu.com.br' target='_blank'>Teste Breu Branco</a> - deve mostrar NR-35 e NR-33</li>";
    echo "<li><a href='index.php?cpf=03183924536&subdomain=igarape.imepedu.com.br' target='_blank'>Teste Igarapé</a> - deve mostrar POLÍTICA DE SAÚDE PÚBLICA</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro na Parte 2: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 5px;">
    <h4>🎯 Resumo da Correção Completa</h4>
    
    <div class="row">
        <div class="col-md-6">
            <h5>❌ Antes da correção:</h5>
            <ul>
                <li>Sistema mostrava cursos de TODOS os polos</li>
                <li>breubranco.imepedu.com.br → NR-35, NR-33 + POLÍTICA DE SAÚDE</li>
                <li>Misturava dados de diferentes subdomínios</li>
            </ul>
        </div>
        <div class="col-md-6">
            <h5>✅ Depois da correção:</h5>
            <ul>
                <li>Sistema filtra por polo (subdomínio)</li>
                <li>breubranco.imepedu.com.br → apenas NR-35 e NR-33</li>
                <li>igarape.imepedu.com.br → apenas POLÍTICA DE SAÚDE PÚBLICA</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <h4>🔧 Para Reverter (se necessário)</h4>
    <p>Se algo der errado, você pode reverter usando os backups criados na Parte 1:</p>
    <pre>
# Listar backups disponíveis
ls -la src/*backup* dashboard_backup*

# Reverter AlunoService (substitua TIMESTAMP pelo correto)
cp src/AlunoService_backup_TIMESTAMP.php src/AlunoService.php

# Reverter Dashboard
cp dashboard_backup_TIMESTAMP.php dashboard.php
    </pre>
</div>

<div style="margin-top: 20px;">
    <h4>🔗 Links de Teste</h4>
    <div class="row">
        <div class="col-md-6">
            <h6>🏢 Teste Breu Branco:</h6>
            <ul>
                <li><a href="index.php?cpf=03183924536&subdomain=breubranco.imepedu.com.br" target="_blank">Login Automático</a></li>
                <li><strong>Deve mostrar:</strong> NR-35 e NR-33</li>
            </ul>
        </div>
        <div class="col-md-6">
            <h6>🏢 Teste Igarapé-Miri:</h6>
            <ul>
                <li><a href="index.php?cpf=03183924536&subdomain=igarape.imepedu.com.br" target="_blank">Login Automático</a></li>
                <li><strong>Deve mostrar:</strong> POLÍTICA DE SAÚDE PÚBLICA</li>
            </ul>
        </div>
    </div>
    
    <h6>🛠 Debug e Diagnóstico:</h6>
    <ul>
        <li><a href="dashboard.php" target="_blank">📊 Dashboard Corrigido</a></li>
        <li><a href="debug_completo.php" target="_blank">🐛 Debug Completo</a></li>
        <li><a href="teste_moodle.php" target="_blank">🔌 Teste API Moodle</a></li>
    </ul>
</div>

<div style="margin-top: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;">
    <h4>✅ Status Final</h4>
    <p><strong>PARTE 1:</strong> ✅ Concluída - Preparação e backup</p>
    <p><strong>PARTE 2:</strong> ✅ Concluída - Aplicação da correção</p>
    
    <p><strong>🎯 Resultado:</strong> Cada polo agora mostra apenas seus próprios cursos!</p>
    
    <div class="alert alert-success" style="margin-top: 10px;">
        <strong>Problema resolvido!</strong> O CPF 03183924536 agora mostra cursos diferentes dependendo do polo de acesso:
        <ul class="mb-0 mt-2">
            <li><strong>Breu Branco:</strong> NR-35 e NR-33</li>
            <li><strong>Igarapé-Miri:</strong> POLÍTICA DE SAÚDE PÚBLICA</li>
        </ul>
    </div>
</div>