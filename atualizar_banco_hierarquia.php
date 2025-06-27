<?php
/**
 * Script para atualizar estrutura do banco - Hierarquia de Cursos
 * Arquivo: atualizar_banco_hierarquia.php
 * 
 * Execute este arquivo caso prefira atualizar via PHP ao invés de SQL direto
 */

require_once 'config/database.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualizar Banco - Hierarquia</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .step { margin: 20px 0; padding: 15px; border-radius: 5px; }
        .step.success { background: #d4edda; border: 1px solid #c3e6cb; }
        .step.error { background: #f8d7da; border: 1px solid #f5c6cb; }
        .step.info { background: #d1ecf1; border: 1px solid #bee5eb; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Atualizar Banco de Dados - Hierarquia de Cursos</h1>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
            
            try {
                $db = (new Database())->getConnection();
                echo "<h2>📋 Executando Atualizações...</h2>";
                
                // Passo 1: Verificar colunas existentes
                echo "<div class='step info'>";
                echo "<h3>1️⃣ Verificando estrutura atual</h3>";
                
                $stmt = $db->query("DESCRIBE cursos");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                echo "<p>Colunas existentes: " . implode(', ', $columns) . "</p>";
                echo "</div>";
                
                // Passo 2: Adicionar colunas que não existem
                echo "<div class='step info'>";
                echo "<h3>2️⃣ Adicionando novas colunas</h3>";
                
                $novasColumns = [
                    'identificador_moodle' => "VARCHAR(100) NULL COMMENT 'Identificador único do Moodle'",
                    'tipo_estrutura' => "ENUM('curso', 'categoria_curso', 'emergencia') DEFAULT 'curso' COMMENT 'Tipo de estrutura'",
                    'categoria_pai' => "VARCHAR(255) NULL COMMENT 'Nome da categoria pai'",
                    'total_alunos' => "INT DEFAULT 0 COMMENT 'Total de alunos'",
                    'visivel' => "TINYINT(1) DEFAULT 1 COMMENT 'Visível no Moodle'"
                ];
                
                foreach ($novasColumns as $nomeColuna => $definicao) {
                    if (!in_array($nomeColuna, $columns)) {
                        $sql = "ALTER TABLE cursos ADD COLUMN {$nomeColuna} {$definicao}";
                        $db->exec($sql);
                        echo "<p>✅ Adicionada coluna: <strong>{$nomeColuna}</strong></p>";
                    } else {
                        echo "<p>⏭️ Coluna <strong>{$nomeColuna}</strong> já existe</p>";
                    }
                }
                echo "</div>";
                
                // Passo 3: Adicionar índices
                echo "<div class='step info'>";
                echo "<h3>3️⃣ Criando índices</h3>";
                
                $indices = [
                    'idx_identificador_moodle' => 'identificador_moodle',
                    'idx_tipo_estrutura' => 'tipo_estrutura',
                    'idx_visivel' => 'visivel',
                    'idx_subdomain_tipo' => 'subdomain, tipo_estrutura'
                ];
                
                foreach ($indices as $nomeIndice => $colunas) {
                    try {
                        $sql = "CREATE INDEX {$nomeIndice} ON cursos({$colunas})";
                        $db->exec($sql);
                        echo "<p>✅ Criado índice: <strong>{$nomeIndice}</strong></p>";
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                            echo "<p>⏭️ Índice <strong>{$nomeIndice}</strong> já existe</p>";
                        } else {
                            echo "<p>⚠️ Erro ao criar índice {$nomeIndice}: " . $e->getMessage() . "</p>";
                        }
                    }
                }
                echo "</div>";
                
                // Passo 4: Atualizar registros existentes
                echo "<div class='step info'>";
                echo "<h3>4️⃣ Atualizando registros existentes</h3>";
                
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET identificador_moodle = CONCAT('course_', COALESCE(moodle_course_id, id)),
                        tipo_estrutura = 'curso'
                    WHERE identificador_moodle IS NULL
                ");
                $stmt->execute();
                $updated = $stmt->rowCount();
                
                echo "<p>✅ Atualizados <strong>{$updated}</strong> registros existentes</p>";
                echo "</div>";
                
                // Passo 5: Remover duplicatas
                echo "<div class='step info'>";
                echo "<h3>5️⃣ Removendo duplicatas</h3>";
                
                $stmt = $db->prepare("
                    DELETE c1 FROM cursos c1
                    INNER JOIN cursos c2 
                    WHERE c1.id < c2.id 
                    AND c1.nome = c2.nome 
                    AND c1.subdomain = c2.subdomain
                ");
                $stmt->execute();
                $deleted = $stmt->rowCount();
                
                echo "<p>✅ Removidas <strong>{$deleted}</strong> duplicatas</p>";
                echo "</div>";
                
                // Passo 6: Criar dados de exemplo
                echo "<div class='step info'>";
                echo "<h3>6️⃣ Criando dados de exemplo</h3>";
                
                $exemplosCriados = 0;
                $polosAtivos = ['breubranco.imepedu.com.br', 'igarape.imepedu.com.br', 'tucurui.imepedu.com.br', 'moju.imepedu.com.br'];
                
                foreach ($polosAtivos as $polo) {
                    // Verifica se polo já tem dados
                    $stmt = $db->prepare("SELECT COUNT(*) FROM cursos WHERE subdomain = ?");
                    $stmt->execute([$polo]);
                    $count = $stmt->fetchColumn();
                    
                    if ($count == 0) {
                        // Cria dados específicos por polo
                        if ($polo == 'breubranco.imepedu.com.br') {
                            $cursosExemplo = [
                                ['Técnico em Enfermagem', 'TEC_ENF', 'categoria_curso', 'Cursos Técnicos', 'cat_101'],
                                ['Técnico em Administração', 'TEC_ADM', 'categoria_curso', 'Cursos Técnicos', 'cat_102'],
                                ['Técnico em Informática', 'TEC_INF', 'categoria_curso', 'Cursos Técnicos', 'cat_103']
                            ];
                        } elseif ($polo == 'igarape.imepedu.com.br') {
                            $cursosExemplo = [
                                ['Enfermagem', 'ENF', 'categoria_curso', null, 'cat_201'],
                                ['Administração', 'ADM', 'categoria_curso', null, 'cat_202'],
                                ['Técnico em Informática', 'TEC_INF', 'categoria_curso', null, 'cat_203']
                            ];
                        } else {
                            $cursosExemplo = [
                                ['Administração', 'ADM', 'curso', null, 'course_301'],
                                ['Direito', 'DIR', 'curso', null, 'course_302'],
                                ['Enfermagem', 'ENF', 'curso', null, 'course_303']
                            ];
                        }
                        
                        $stmt = $db->prepare("
                            INSERT INTO cursos (nome, nome_curto, subdomain, tipo_estrutura, categoria_pai, identificador_moodle, ativo, valor, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, ?, 1, 0.00, NOW(), NOW())
                        ");
                        
                        foreach ($cursosExemplo as $curso) {
                            $stmt->execute([$curso[0], $curso[1], $polo, $curso[2], $curso[3], $curso[4]]);
                            $exemplosCriados++;
                        }
                        
                        echo "<p>✅ Criados exemplos para <strong>{$polo}</strong></p>";
                    } else {
                        echo "<p>⏭️ Polo <strong>{$polo}</strong> já possui {$count} curso(s)</p>";
                    }
                }
                
                echo "<p><strong>Total de exemplos criados: {$exemplosCriados}</strong></p>";
                echo "</div>";
                
                // Passo 7: Estatísticas finais
                echo "<div class='step success'>";
                echo "<h3>7️⃣ Estatísticas Finais</h3>";
                
                $stmt = $db->query("
                    SELECT 
                        COUNT(*) as total_cursos,
                        COUNT(DISTINCT subdomain) as total_polos,
                        COUNT(CASE WHEN tipo_estrutura = 'curso' THEN 1 END) as cursos_tradicionais,
                        COUNT(CASE WHEN tipo_estrutura = 'categoria_curso' THEN 1 END) as categorias_como_cursos,
                        COUNT(CASE WHEN ativo = 1 THEN 1 END) as cursos_ativos
                    FROM cursos
                ");
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo "<ul>";
                echo "<li><strong>Total de cursos:</strong> {$stats['total_cursos']}</li>";
                echo "<li><strong>Total de polos:</strong> {$stats['total_polos']}</li>";
                echo "<li><strong>Cursos tradicionais:</strong> {$stats['cursos_tradicionais']}</li>";
                echo "<li><strong>Categorias como cursos:</strong> {$stats['categorias_como_cursos']}</li>";
                echo "<li><strong>Cursos ativos:</strong> {$stats['cursos_ativos']}</li>";
                echo "</ul>";
                
                // Distribuição por polo
                echo "<h4>📊 Distribuição por Polo:</h4>";
                $stmt = $db->query("
                    SELECT subdomain, tipo_estrutura, COUNT(*) as quantidade
                    FROM cursos 
                    WHERE ativo = 1
                    GROUP BY subdomain, tipo_estrutura
                    ORDER BY subdomain, tipo_estrutura
                ");
                $distribuicao = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
                echo "<tr><th>Polo</th><th>Tipo</th><th>Quantidade</th></tr>";
                foreach ($distribuicao as $dist) {
                    echo "<tr>";
                    echo "<td>{$dist['subdomain']}</td>";
                    echo "<td>{$dist['tipo_estrutura']}</td>";
                    echo "<td>{$dist['quantidade']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
                
                echo "</div>";
                
                echo "<div class='step success'>";
                echo "<h2>🎉 Atualização Concluída com Sucesso!</h2>";
                echo "<p><strong>Próximos passos:</strong></p>";
                echo "<ol>";
                echo "<li>Teste a página <a href='/admin/upload-boletos.php'>/admin/upload-boletos.php</a></li>";
                echo "<li>Execute o <a href='/diagnostico_hierarquia.php'>diagnóstico de hierarquia</a></li>";
                echo "<li>Verifique se os cursos aparecem ao selecionar cada polo</li>";
                echo "</ol>";
                echo "</div>";
                
            } catch (Exception $e) {
                echo "<div class='step error'>";
                echo "<h3>❌ Erro na Atualização</h3>";
                echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
                echo "</div>";
            }
            
        } else {
            // Formulário inicial
            ?>
            
            <div class="step info">
                <h3>📋 O que este script fará:</h3>
                <ul>
                    <li>✅ Verificar estrutura atual da tabela <code>cursos</code></li>
                    <li>✅ Adicionar novas colunas para suporte a hierarquia</li>
                    <li>✅ Criar índices para melhor performance</li>
                    <li>✅ Atualizar registros existentes</li>
                    <li>✅ Remover duplicatas</li>
                    <li>✅ Criar dados de exemplo para teste</li>
                    <li>✅ Mostrar estatísticas finais</li>
                </ul>
            </div>
            
            <div class="step info">
                <h3>⚠️ Importante:</h3>
                <ul>
                    <li>Faça um backup do banco antes de executar</li>
                    <li>Execute em ambiente de teste primeiro</li>
                    <li>Verifique se tem permissões de ALTER TABLE</li>
                </ul>
            </div>
            
            <form method="POST">
                <input type="hidden" name="executar" value="1">
                <button type="submit" class="btn" onclick="return confirm('Tem certeza que deseja executar a atualização? Faça um backup primeiro!')">
                    🚀 Executar Atualização
                </button>
            </form>
            
            <div style="margin-top: 30px;">
                <h3>🔗 Links Úteis:</h3>
                <ul>
                    <li><a href="/admin/upload-boletos.php">Testar Upload de Boletos</a></li>
                    <li><a href="/diagnostico_hierarquia.php">Diagnóstico de Hierarquia</a></li>
                    <li><a href="/teste_conexao_moodle.php">Teste de Conexão Moodle</a></li>
                    </ul>
            </div>
        <?php
        } // <<< ESSA CHAVE FECHA O ELSE
        ?>
    </div>
</body>
</html>