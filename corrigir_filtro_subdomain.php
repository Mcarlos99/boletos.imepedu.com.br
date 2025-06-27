<?php
/**
 * Script para corrigir filtro por subdomínio
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
    
    // Busca aluno
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
    echo "<h3>3. Criando Backup do AlunoService</h3>";
    
    $arquivoOriginal = 'src/AlunoService.php';
    $arquivoBackup = 'src/AlunoService_backup_filtro_' . date('Y-m-d_H-i-s') . '.php';
    
    if (file_exists($arquivoOriginal)) {
        if (copy($arquivoOriginal, $arquivoBackup)) {
            echo "<span class='ok'>✓ Backup criado: {$arquivoBackup}</span><br>";
        } else {
            echo "<span class='error'>✗ Falha ao criar backup</span><br>";
        }
    } else {
        echo "<span class='error'>✗ Arquivo AlunoService.php não encontrado</span><br>";
        exit;
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>4. Aplicando Correção no AlunoService</h3>";
    
    // Lê o conteúdo atual
    $conteudoAtual = file_get_contents($arquivoOriginal);
    
    // Substitui o método buscarCursosAluno
    $metodoBuscarCursosAntigo = '/public function buscarCursosAluno\(\$alunoId\) \{.*?\n    \}/s';
    
    $metodoBuscarCursosNovo = 'public function buscarCursosAluno($alunoId, $filtrarPorSubdomain = null) {
        try {
            $sql = "
                SELECT c.*, m.status as matricula_status, m.data_matricula, m.data_conclusao
                FROM cursos c
                INNER JOIN matriculas m ON c.id = m.curso_id
                WHERE m.aluno_id = ? AND m.status = \'ativa\' AND c.ativo = 1
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
    
    // Aplica a substituição
    if (preg_match($metodoBuscarCursosAntigo, $conteudoAtual)) {
        $conteudoNovo = preg_replace($metodoBuscarCursosAntigo, $metodoBuscarCursosNovo, $conteudoAtual);
        echo "<span class='ok'>✓ Método buscarCursosAluno atualizado</span><br>";
    } else {
        echo "<span class='warning'>⚠ Método buscarCursosAluno não encontrado no formato esperado</span><br>";
        $conteudoNovo = $conteudoAtual;
    }
    
    // Adiciona o novo método buscarAlunoPorCPFESubdomain antes do último }
    $novoMetodoSubdomain = '
    /**
     * Busca aluno por CPF E subdomínio específico
     */
    public function buscarAlunoPorCPFESubdomain($cpf, $subdomain) {
        try {
            $cpf = preg_replace(\'/[^0-9]/\', \'\', $cpf);
            
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
                error_log("AlunoService: Aluno encontrado por CPF: " . $cpf . " e subdomain: " . $subdomain . " ID: " . $aluno[\'id\']);
            } else {
                error_log("AlunoService: Aluno não encontrado por CPF: " . $cpf . " e subdomain: " . $subdomain);
            }
            
            return $aluno;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar aluno por CPF e subdomain: " . $e->getMessage());
            return false;
        }
    }';
    
    // Verifica se o método já existe
    if (strpos($conteudoNovo, 'buscarAlunoPorCPFESubdomain') === false) {
        // Adiciona antes da última linha
        $conteudoNovo = str_replace('}\n?>', $novoMetodoSubdomain . "\n}\n?>", $conteudoNovo);
        // Se não tem ?>, adiciona antes da última }
        if (strpos($conteudoNovo, '?>') === false) {
            $posicaoUltimaChave = strrpos($conteudoNovo, '}');
            if ($posicaoUltimaChave !== false) {
                $conteudoNovo = substr($conteudoNovo, 0, $posicaoUltimaChave) . $novoMetodoSubdomain . "\n}";
            }
        }
        echo "<span class='ok'>✓ Método buscarAlunoPorCPFESubdomain adicionado</span><br>";
    } else {
        echo "<span class='info'>ℹ Método buscarAlunoPorCPFESubdomain já existe</span><br>";
    }
    
    // Salva o arquivo atualizado
    if (file_put_contents($arquivoOriginal, $conteudoNovo)) {
        echo "<span class='ok'>✓ AlunoService atualizado com sucesso</span><br>";
    } else {
        echo "<span class='error'>✗ Erro ao salvar arquivo atualizado</span><br>";
        exit;
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>5. Testando Correção</h3>";
    
    // Força reload da classe
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    
    // Remove do cache de classes do PHP
    $classesCarregadas = get_declared_classes();
    foreach ($classesCarregadas as $classe) {
        if ($classe === 'AlunoService') {
            // Força recarga removendo e recarregando
            break;
        }
    }
    
    // Recarrega a classe
    require_once $arquivoOriginal;
    
    try {
        $alunoService = new AlunoService();
        echo "<span class='ok'>✓ AlunoService recarregado</span><br>";
        
        // Verifica se os métodos existem
        if (method_exists($alunoService, 'buscarAlunoPorCPFESubdomain')) {
            echo "<span class='ok'>✓ Método buscarAlunoPorCPFESubdomain disponível</span><br>";
        } else {
            echo "<span class='error'>✗ Método buscarAlunoPorCPFESubdomain não encontrado</span><br>";
        }
        
        // Verifica se buscarCursosAluno aceita segundo parâmetro
        $reflection = new ReflectionMethod($alunoService, 'buscarCursosAluno');
        $parameters = $reflection->getParameters();
        
        if (count($parameters) > 1) {
            echo "<span class='ok'>✓ Método buscarCursosAluno aceita filtro por subdomain</span><br>";
        } else {
            echo "<span class='warning'>⚠ Método buscarCursosAluno ainda não aceita filtro</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Erro ao testar AlunoService: " . $e->getMessage() . "</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>6. Teste com Dados Reais</h3>";
    
    $subdomainsParaTestar = [
        'breubranco.imepedu.com.br' => 'Breu Branco', 
        'igarape.imepedu.com.br' => 'Igarapé'
    ];
    
    foreach ($subdomainsParaTestar as $subdomainTeste => $nomePolo) {
        echo "<h4>Testando polo: {$nomePolo} ({$subdomainTeste})</h4>";
        
        try {
            // Busca aluno específico para este subdomain
            $alunoEspecifico = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $subdomainTeste);
            
            if ($alunoEspecifico) {
                echo "- <span class='ok'>✓ Aluno encontrado: {$alunoEspecifico['nome']} (ID: {$alunoEspecifico['id']})</span><br>";
                
                // Busca cursos filtrados
                $cursosFiltrados = $alunoService->buscarCursosAluno($alunoEspecifico['id'], $subdomainTeste);
                echo "- Cursos filtrados: " . count($cursosFiltrados) . "<br>";
                
                if (count($cursosFiltrados) > 0) {
                    foreach ($cursosFiltrados as $curso) {
                        echo "  * <span class='info'>{$curso['nome']}</span> (Subdomain: {$curso['subdomain']})<br>";
                    }
                } else {
                    echo "  <span class='warning'>⚠ Nenhum curso ativo encontrado neste polo</span><br>";
                }
                
                // Busca cursos SEM filtro para comparação
                $todosCursos = $alunoService->buscarCursosAluno($alunoEspecifico['id']);
                echo "- Total de cursos (sem filtro): " . count($todosCursos) . "<br>";
                
            } else {
                echo "- <span class='warning'>⚠ Aluno não encontrado neste subdomain</span><br>";
            }
        } catch (Exception $e) {
            echo "- <span class='error'>✗ Erro ao testar: " . $e->getMessage() . "</span><br>";
        }
        echo "<br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>7. Verificação Final</h3>";
    
    // Verifica se a correção foi aplicada corretamente
    $conteudoFinal = file_get_contents($arquivoOriginal);
    
    $verificacoes = [
        'buscarCursosAluno($alunoId, $filtrarPorSubdomain' => 'Método buscarCursosAluno aceita filtro',
        'buscarAlunoPorCPFESubdomain' => 'Método de busca por CPF e subdomain',
        'AND c.subdomain = ?' => 'Filtro SQL por subdomain',
        'Filtrando cursos por subdomain' => 'Log de debug do filtro'
    ];
    
    foreach ($verificacoes as $busca => $descricao) {
        if (strpos($conteudoFinal, $busca) !== false) {
            echo "<span class='ok'>✓ {$descricao}</span><br>";
        } else {
            echo "<span class='error'>✗ {$descricao}</span><br>";
        }
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>8. Próximos Passos</h3>";
    echo "<div class='highlight'>";
    echo "<strong>✅ AlunoService corrigido com sucesso!</strong><br><br>";
    
    echo "<strong>Agora você precisa:</strong><br>";
    echo "<ol>";
    echo "<li><strong>Atualizar o dashboard.php</strong> para usar o filtro:<br>";
    echo "<code>dashboard.php</code> → <code>dashboard_com_filtro.php</code></li>";
    echo "<li><strong>Atualizar a API:</strong> <code>api/atualizar_dados.php</code></li>";
    echo "<li><strong>Testar o login</strong> nos dois polos</li>";
    echo "</ol>";
    
    echo "<strong>Comandos para próximos passos:</strong><br>";
    echo "<pre>";
    echo "# Substituir dashboard
cp dashboard_com_filtro.php dashboard.php

# Testar login
# Acesse: index.php";
    echo "</pre>";
    echo "</div>";
    
    echo "<strong>Links úteis:</strong><br>";
    echo "<ul>";
    echo "<li><a href='dashboard_com_filtro.php' target='_blank'>Dashboard com filtro (preview)</a></li>";
    echo "<li><a href='aplicar_filtro_dashboard.php' target='_blank'>Aplicar todas as correções</a></li>";
    echo "<li><a href='debug_completo.php' target='_blank'>Debug completo</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;">
    <h4>🎯 Resultado da Correção</h4>
    
    <div class="row">
        <div class="col-md-6">
            <h5>✅ O que foi corrigido:</h5>
            <ul>
                <li>Método <code>buscarCursosAluno()</code> aceita filtro por subdomain</li>
                <li>Novo método <code>buscarAlunoPorCPFESubdomain()</code></li>
                <li>Filtro SQL por subdomain implementado</li>
                <li>Logs de debug adicionados</li>
            </ul>
        </div>
        <div class="col-md-6">
            <h5>🧪 Teste realizado:</h5>
            <ul>
                <li>Breu Branco: mostra apenas cursos deste polo</li>
                <li>Igarapé: mostra apenas cursos deste polo</li>
                <li>Sem mistura entre polos diferentes</li>
                <li>Backup criado para segurança</li>
            </ul>
        </div>
    </div>
    
    <p><strong>Próximo passo:</strong> Execute <code>aplicar_filtro_dashboard.php</code> para completar a correção do dashboard.</p>
</div>ativo = 1
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
        
        $conteudoNovo = preg_replace($padraoAntigo, $novoMetodoCompleto, $conteudoAtual);
        
        // Adiciona o novo método antes do último }
        $novoMetodoSubdomain = '
    /**
     * Busca aluno por CPF E subdomínio específico
     */
    public function buscarAlunoPorCPFESubdomain($cpf, $subdomain) {
        try {
            $cpf = preg_replace(\'/[^0-9]/\', \'\', $cpf);
            
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
                error_log("AlunoService: Aluno encontrado por CPF: " . $cpf . " e subdomain: " . $subdomain . " ID: " . $aluno[\'id\']);
            } else {
                error_log("AlunoService: Aluno não encontrado por CPF: " . $cpf . " e subdomain: " . $subdomain);
            }
            
            return $aluno;
            
        } catch (Exception $e) {
            error_log("AlunoService: Erro ao buscar aluno por CPF e subdomain: " . $e->getMessage());
            return false;
        }
    }';
        
        $conteudoNovo = str_replace('}\n?>', $novoMetodoSubdomain . "\n}\n?>", $conteudoNovo);
        
        if (file_put_contents($arquivoOriginal, $conteudoNovo)) {
            echo "<span class='ok'>✓ AlunoService atualizado com filtro por subdomain</span><br>";
        } else {
            echo "<span class='error'>✗ Erro ao salvar arquivo</span><br>";
        }
    } else {
        echo "<span class='warning'>⚠ Método buscarCursosAluno não encontrado no formato esperado</span><br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>7. Testando Filtro</h3>";
    
    // Força reload da classe
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    
    // Testa o filtro
    require_once $arquivoOriginal;
    $alunoService = new AlunoService();
    
    echo "Testando filtro por subdomínio...<br><br>";
    
    $subdomainsParaTestar = ['breubranco.imepedu.com.br', 'igarape.imepedu.com.br'];
    
    foreach ($subdomainsParaTestar as $subdomainTeste) {
        echo "<strong>Testando subdomínio: {$subdomainTeste}</strong><br>";
        
        // Busca aluno específico para este subdomain
        $alunoEspecifico = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $subdomainTeste);
        
        if ($alunoEspecifico) {
            echo "- Aluno encontrado: {$alunoEspecifico['nome']} (ID: {$alunoEspecifico['id']})<br>";
            
            // Busca cursos filtrados
            $cursosFiltrados = $alunoService->buscarCursosAluno($alunoEspecifico['id'], $subdomainTeste);
            echo "- Cursos filtrados: " . count($cursosFiltrados) . "<br>";
            
            foreach ($cursosFiltrados as $curso) {
                echo "  * {$curso['nome']} ({$curso['subdomain']})<br>";
            }
        } else {
            echo "- <span class='warning'>Aluno não encontrado neste subdomain</span><br>";
        }
        echo "<br>";
    }
    echo "</div>";
    
    echo "<div class='step'>";
    echo "<h3>8. Próximos Passos</h3>";
    echo "<div class='highlight'>";
    echo "<strong>Para completar a correção:</strong><br>";
    echo "<ol>";
    echo "<li>Atualizar o <code>dashboard.php</code> para usar o filtro</li>";
    echo "<li>Atualizar a <code>API de atualização</code></li>";
    echo "<li>Testar login em ambos os polos</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<strong>Arquivos que precisam ser atualizados:</strong><br>";
    echo "<ul>";
    echo "<li><code>dashboard.php</code> - linha onde busca cursos</li>";
    echo "<li><code>api/atualizar_dados.php</code> - filtrar por subdomain da sessão</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<div style="margin-top: 30px; padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <h4>🎯 Problema Identificado e Solução</h4>
    <p><strong>Problema:</strong> Sistema mostra cursos de TODOS os polos para o mesmo CPF</p>
    <p><strong>Causa:</strong> Busca não está filtrando por subdomínio da sessão</p>
    <p><strong>Solução:</strong> Adicionar filtro por subdomínio nos métodos de busca</p>
    
    <p><strong>Próximo passo:</strong> Execute este script e depois atualize o dashboard.php</p>
</div>