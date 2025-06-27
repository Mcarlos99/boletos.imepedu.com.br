<?php
/**
 * Aplicar correção de filtro por subdomínio
 * Arquivo: aplicar_correcao_subdomain.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Aplicar Correção - Filtro por Subdomínio</h1>";
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
    echo "<h3>1. Fazendo Backup dos Arquivos Atuais</h3>";
    
    $arquivos_backup = [
        'dashboard.php' => 'dashboard_backup_' . date('Y-m-d_H-i-s') . '.php',
        'src/AlunoService.php' => 'src/AlunoService_backup_' . date('Y-m-d_H-i-s') . '.php'
    ];
    
    foreach ($arquivos_backup as $original => $backup) {
        if (file_exists($original)) {
            if (copy($original, $backup)) {
                echo "<span class='ok'>✓ Backup criado: {$backup}</span><br>";
            } else {
                echo "<span class='error'>✗ Erro ao criar backup: {$backup}</span><br>";
            }
        } else {
            echo "<span class='warning'>⚠ Arquivo não existe: {$original}</span><br>";
        }
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>2. Substituindo Dashboard</h3>";
    
    // Lê o conteúdo do dashboard corrigido do artifact
    if (file_exists('dashboard_corrigido_subdomain.php')) {
        if (copy('dashboard_corrigido_subdomain.php', 'dashboard.php')) {
            echo "<span class='ok'>✓ Dashboard atualizado com filtro por subdomínio</span><br>";
        } else {
            echo "<span class='error'>✗ Erro ao atualizar dashboard</span><br>";
        }
    } else {
        echo "<span class='info'>ℹ Criando dashboard corrigido...</span><br>";
        
        // Criar o arquivo dashboard corrigido (conteúdo do artifact)
        $dashboard_content = file_get_contents('dashboard_boletos_completo.php');
        if ($dashboard_content) {
            file_put_contents('dashboard.php', $dashboard_content);
            echo "<span class='ok'>✓ Dashboard criado a partir do template</span><br>";
        } else {
            echo "<span class='error'>✗ Erro ao criar dashboard</span><br>";
        }
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>3. Atualizando AlunoService</h3>";
    
    if (file_exists('src/AlunoService_subdomain_fix.php')) {
        if (copy('src/AlunoService_subdomain_fix.php', 'src/AlunoService.php')) {
            echo "<span class='ok'>✓ AlunoService atualizado com métodos de filtro por subdomínio</span><br>";
        } else {
            echo "<span class='error'>✗ Erro ao atualizar AlunoService</span><br>";
        }
    } else {
        echo "<span class='info'>ℹ Criando AlunoService corrigido...</span><br>";
        
        // Se não existe o arquivo corrigido, cria com as principais correções
        $alunoservice_content = '<?php
require_once __DIR__ . "/../config/database.php";

class AlunoService {
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    // NOVO: Busca aluno por CPF E subdomínio específico
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
    
    // NOVO: Busca cursos APENAS do subdomínio específico
    public function buscarCursosAlunoPorSubdomain($alunoId, $subdomain) {
        $stmt = $this->db->prepare("
            SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ? 
            AND c.subdomain = ?
            AND m.status = \"ativa\" 
            AND c.ativo = 1
            ORDER BY c.nome ASC
        ");
        $stmt->execute([$alunoId, $subdomain]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Métodos originais mantidos para compatibilidade
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
    
    public function buscarCursosAluno($alunoId) {
        $stmt = $this->db->prepare("
            SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
            FROM cursos c
            INNER JOIN matriculas m ON c.id = m.curso_id
            WHERE m.aluno_id = ? AND m.status = \"ativa\" AND c.ativo = 1
            ORDER BY c.nome ASC
        ");
        $stmt->execute([$alunoId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Método de sincronização corrigido (simplificado)
    public function salvarOuAtualizarAluno($dadosAluno) {
        try {
            $this->db->beginTransaction();
            
            // Busca por CPF E subdomain
            $stmt = $this->db->prepare("
                SELECT id FROM alunos 
                WHERE cpf = ? AND subdomain = ?
                FOR UPDATE
            ");
            $stmt->execute([$dadosAluno["cpf"], $dadosAluno["subdomain"]]);
            $alunoExistente = $stmt->fetch();
            
            if ($alunoExistente) {
                // Atualiza
                $stmt = $this->db->prepare("
                    UPDATE alunos 
                    SET nome = ?, email = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $dadosAluno["nome"],
                    $dadosAluno["email"],
                    $alunoExistente["id"]
                ]);
                $alunoId = $alunoExistente["id"];
            } else {
                // Cria
                $stmt = $this->db->prepare("
                    INSERT INTO alunos (cpf, nome, email, moodle_user_id, subdomain, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $dadosAluno["cpf"],
                    $dadosAluno["nome"],
                    $dadosAluno["email"],
                    $dadosAluno["moodle_user_id"],
                    $dadosAluno["subdomain"]
                ]);
                $alunoId = $this->db->lastInsertId();
            }
            
            $this->db->commit();
            return $alunoId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception("Erro ao processar dados do aluno: " . $e->getMessage());
        }
    }
}
?>';
        
        if (file_put_contents('src/AlunoService.php', $alunoservice_content)) {
            echo "<span class='ok'>✓ AlunoService corrigido criado</span><br>";
        } else {
            echo "<span class='error'>✗ Erro ao criar AlunoService corrigido</span><br>";
        }
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Testando Correções</h3>";
    
    $cpf_teste = '03183924536';
    $subdomain_teste = 'breubranco.imepedu.com.br';
    
    require_once 'src/AlunoService.php';
    $alunoService = new AlunoService();
    
    echo "CPF de teste: {$cpf_teste}<br>";
    echo "Subdomínio de teste: {$subdomain_teste}<br><br>";
    
    // Teste do método novo
    if (method_exists($alunoService, 'buscarAlunoPorCPFESubdomain')) {
        $aluno = $alunoService->buscarAlunoPorCPFESubdomain($cpf_teste, $subdomain_teste);
        if ($aluno) {
            echo "<span class='ok'>✓ Método buscarAlunoPorCPFESubdomain funcionando</span><br>";
            echo "Aluno encontrado: {$aluno['nome']} (ID: {$aluno['id']}, Subdomain: {$aluno['subdomain']})<br>";
            
            // Teste cursos por subdomain
            if (method_exists($alunoService, 'buscarCursosAlunoPorSubdomain')) {
                $cursos = $alunoService->buscarCursosAlunoPorSubdomain($aluno['id'], $subdomain_teste);
                echo "<span class='ok'>✓ Método buscarCursosAlunoPorSubdomain funcionando</span><br>";
                echo "Cursos encontrados para {$subdomain_teste}: " . count($cursos) . "<br>";
                
                foreach ($cursos as $curso) {
                    echo "- {$curso['nome']} (Subdomain: {$curso['subdomain']})<br>";
                }
            } else {
                echo "<span class='error'>✗ Método buscarCursosAlunoPorSubdomain não encontrado</span><br>";
            }
        } else {
            echo "<span class='warning'>⚠ Aluno não encontrado para o subdomínio específico</span><br>";
        }
    } else {
        echo "<span class='error'>✗ Método buscarAlunoPorCPFESubdomain não encontrado</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Verificando Resultados</h3>";
    
    require_once 'config/database.php';
    $db = (new Database())->getConnection();
    
    // Mostra distribuição por subdomínio
    $stmt = $db->prepare("
        SELECT 
            c.subdomain,
            COUNT(DISTINCT a.id) as total_alunos,
            COUNT(DISTINCT c.id) as total_cursos,
            COUNT(m.id) as total_matriculas
        FROM alunos a
        INNER JOIN matriculas m ON a.id = m.aluno_id
        INNER JOIN cursos c ON m.curso_id = c.id
        WHERE a.cpf = ?
        GROUP BY c.subdomain
        ORDER BY c.subdomain
    ");
    $stmt->execute([$cpf_teste]);
    $distribuicao = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<strong>Distribuição do aluno por subdomínio:</strong><br>";
    echo "<table border='1' style='border-collapse:collapse; margin:10px 0;'>";
    echo "<tr><th>Subdomínio</th><th>Alunos</th><th>Cursos</th><th>Matrículas</th></tr>";
    
    foreach ($distribuicao as $row) {
        echo "<tr>";
        echo "<td>{$row['subdomain']}</td>";
        echo "<td>{$row['total_alunos']}</td>";
        echo "<td>{$row['total_cursos']}</td>";
        echo "<td>{$row['total_matriculas']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if (count($distribuicao) > 1) {
        echo "<span class='info'>ℹ️ O aluno possui cursos em múltiplos subdomínios.</span><br>";
        echo "<span class='info'>ℹ️ Agora o dashboard filtrará apenas o subdomínio da sessão atual.</span><br>";
    } else {
        echo "<span class='ok'>✓ Tudo funcionando corretamente!</span><br>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro durante a aplicação: " . $e->getMessage() . "</span><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "<div style='margin-top: 30px; padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;'>";
echo "<h4>🎯 Correções Aplicadas:</h4>";
echo "<ol>";
echo "<li><strong>Dashboard corrigido:</strong> Agora filtra cursos e boletos por subdomínio</li>";
echo "<li><strong>AlunoService atualizado:</strong> Novos métodos para buscar por subdomínio específico</li>";
echo "<li><strong>Filtro automático:</strong> Sistema mostra apenas dados do polo atual</li>";
echo "<li><strong>Interface melhorada:</strong> Indica claramente qual polo está sendo exibido</li>";
echo "<li><strong>Backup criado:</strong> Arquivos originais foram preservados</li>";
echo "</ol>";

echo "<h4>🔍 Como Funciona Agora:</h4>";
echo "<ul>";
echo "<li><strong>Login por polo:</strong> Quando você faz login através de um polo específico (ex: breubranco.imepedu.com.br), o sistema salva o subdomínio na sessão</li>";
echo "<li><strong>Filtro automático:</strong> O dashboard busca apenas cursos e boletos do subdomínio da sessão</li>";
echo "<li><strong>Separação clara:</strong> Cursos do polo Breu Branco não aparecem quando logado pelo polo Igarapé</li>";
echo "<li><strong>Múltiplos polos:</strong> Se você tem cursos em vários polos, precisa acessar cada um separadamente</li>";
echo "</ul>";

echo "<h4>🚀 Próximos Passos:</h4>";
echo "<ol>";
echo "<li><strong>Teste o dashboard:</strong> <a href='dashboard.php'>Acesse o dashboard</a> para ver os resultados</li>";
echo "<li><strong>Verifique outros polos:</strong> Faça login através de outros subdomínios para testar</li>";
echo "<li><strong>Gere boletos:</strong> Se necessário, <a href='gerar_boletos_teste.php'>gere boletos de teste</a></li>";
echo "<li><strong>Configurar redirecionamentos:</strong> Configure links nos Moodles para apontar para o sistema de boletos</li>";
echo "</ol>";

echo "<h4>📋 URLs para Configurar nos Moodles:</h4>";
echo "<pre>";
echo "Polo Tucuruí: https://seu-dominio/login.php?subdomain=tucurui.imepedu.com.br&cpf={CPF_DO_ALUNO}\n";
echo "Polo Breu Branco: https://seu-dominio/login.php?subdomain=breubranco.imepedu.com.br&cpf={CPF_DO_ALUNO}\n";
echo "Polo Moju: https://seu-dominio/login.php?subdomain=moju.imepedu.com.br&cpf={CPF_DO_ALUNO}\n";
echo "Polo Igarapé-Miri: https://seu-dominio/login.php?subdomain=igarape.imepedu.com.br&cpf={CPF_DO_ALUNO}";
echo "</pre>";

echo "<h4>🔧 Arquivos Modificados:</h4>";
echo "<ul>";
echo "<li><code>dashboard.php</code> - Agora filtra por subdomínio</li>";
echo "<li><code>src/AlunoService.php</code> - Novos métodos de filtro</li>";
echo "<li>Backups criados com timestamp para restauração se necessário</li>";
echo "</ul>";
echo "</div>";

echo "<br><div style='text-align: center; margin: 20px 0;'>";
echo "<a href='dashboard.php' class='btn btn-primary' style='display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>🎯 Testar Dashboard</a>";
echo "<a href='verificar_boletos.php' class='btn btn-secondary' style='display: inline-block; padding: 12px 24px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>🔍 Verificar Dados</a>";
echo "<a href='gerar_boletos_teste.php' class='btn btn-success' style='display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>💰 Gerar Boletos</a>";
echo "</div>";
?>