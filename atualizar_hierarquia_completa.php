<?php
/**
 * Script para Atualizar Banco - Suporte Total à Hierarquia
 * Arquivo: atualizar_hierarquia_completa.php
 * 
 * Execute este script para garantir suporte completo à hierarquia de cursos
 */

require_once 'config/database.php';
require_once 'config/moodle.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualização Hierarquia Completa - Sistema de Boletos IMED</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .step { margin: 20px 0; padding: 15px; border-radius: 5px; }
        .step.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .step.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .step.info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .step.warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .btn { background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0056b3; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .btn.danger { background: #dc3545; }
        .btn.danger:hover { background: #c82333; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .highlight { background: yellow; font-weight: bold; padding: 2px 4px; }
        .debug-box { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 4px; font-family: monospace; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .teste-box { background: #e8f4f8; border: 1px solid #b8daff; padding: 15px; border-radius: 5px; transition: all 0.3s ease; }
        .teste-box:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        
        /* Animações */
        .step { opacity: 0; animation: fadeInUp 0.6s ease-out forwards; }
        .step:nth-child(1) { animation-delay: 0.1s; }
        .step:nth-child(2) { animation-delay: 0.2s; }
        .step:nth-child(3) { animation-delay: 0.3s; }
        .step:nth-child(4) { animation-delay: 0.4s; }
        .step:nth-child(5) { animation-delay: 0.5s; }
        .step:nth-child(6) { animation-delay: 0.6s; }
        .step:nth-child(7) { animation-delay: 0.7s; }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
            .container { margin: 10px; padding: 15px; }
            .step { padding: 10px; }
            button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Atualização Completa - Hierarquia de Cursos IMED</h1>
        <p><em>Script específico para suporte total à hierarquia, incluindo Breu Branco</em></p>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
            
            try {
                $db = (new Database())->getConnection();
                echo "<h2>🚀 Executando Atualização Completa da Hierarquia...</h2>";
                
                // Passo 1: Verificar e criar colunas necessárias
                echo "<div class='step info'>";
                echo "<h3>1️⃣ Verificando e Criando Estrutura do Banco</h3>";
                
                $stmt = $db->query("DESCRIBE cursos");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                echo "<p>📋 Colunas existentes: " . implode(', ', $columns) . "</p>";
                
                $novasColumns = [
                    'identificador_moodle' => "VARCHAR(100) NULL COMMENT 'Identificador único: course_123 ou cat_456'",
                    'tipo_estrutura' => "ENUM('curso', 'categoria_curso', 'emergencia') DEFAULT 'curso' COMMENT 'Tipo de estrutura no Moodle'",
                    'categoria_pai' => "VARCHAR(255) NULL COMMENT 'Nome da categoria pai (para hierarquia)'",
                    'total_alunos' => "INT DEFAULT 0 COMMENT 'Total de alunos matriculados'",
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
                
                // Passo 2: Criar índices otimizados
                echo "<div class='step info'>";
                echo "<h3>2️⃣ Criando Índices Otimizados</h3>";
                
                $indices = [
                    'idx_identificador_moodle' => 'identificador_moodle',
                    'idx_tipo_estrutura' => 'tipo_estrutura',
                    'idx_subdomain_tipo' => 'subdomain, tipo_estrutura',
                    'idx_categoria_pai' => 'categoria_pai',
                    'idx_visivel_ativo' => 'visivel, ativo'
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
                
                // Passo 3: Limpar dados duplicados e inconsistentes
                echo "<div class='step info'>";
                echo "<h3>3️⃣ Limpando Dados Inconsistentes</h3>";
                
                // Remove duplicatas
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
                
                // Corrige identificadores
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET identificador_moodle = CONCAT('course_', COALESCE(moodle_course_id, id)),
                        tipo_estrutura = 'curso'
                    WHERE identificador_moodle IS NULL OR identificador_moodle = ''
                ");
                $stmt->execute();
                $updated = $stmt->rowCount();
                echo "<p>✅ Corrigidos <strong>{$updated}</strong> identificadores</p>";
                
                echo "</div>";
                
                // Passo 4: Testar conexões com todos os polos
                echo "<div class='step info'>";
                echo "<h3>4️⃣ Testando Conexões com Polos</h3>";
                
                $polosAtivos = MoodleConfig::getActiveSubdomains();
                $polosTestados = [];
                
                foreach ($polosAtivos as $polo) {
                    $config = MoodleConfig::getConfig($polo);
                    $token = MoodleConfig::getToken($polo);
                    
                    echo "<div style='margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;'>";
                    echo "<strong>🏫 {$config['name']} ({$polo})</strong><br>";
                    
                    if (!$token || $token === 'x') {
                        echo "<span style='color: #dc3545;'>❌ Token não configurado</span>";
                        $polosTestados[$polo] = ['status' => 'erro', 'motivo' => 'Token não configurado'];
                    } else {
                        try {
                            require_once 'src/MoodleAPI.php';
                            $moodleAPI = new MoodleAPI($polo);
                            $teste = $moodleAPI->testarConexao();
                            
                            if ($teste['sucesso']) {
                                echo "<span style='color: #28a745;'>✅ Conectado - {$teste['nome_site']}</span>";
                                $polosTestados[$polo] = ['status' => 'ok', 'nome' => $teste['nome_site']];
                            } else {
                                echo "<span style='color: #dc3545;'>❌ Falha: {$teste['erro']}</span>";
                                $polosTestados[$polo] = ['status' => 'erro', 'motivo' => $teste['erro']];
                            }
                        } catch (Exception $e) {
                            echo "<span style='color: #dc3545;'>❌ Erro: {$e->getMessage()}</span>";
                            $polosTestados[$polo] = ['status' => 'erro', 'motivo' => $e->getMessage()];
                        }
                    }
                    echo "</div>";
                }
                echo "</div>";
                
                // Passo 5: Testar busca de cursos para cada polo
                echo "<div class='step info'>";
                echo "<h3>5️⃣ Testando Busca de Cursos por Polo</h3>";
                
                foreach ($polosAtivos as $polo) {
                    if ($polosTestados[$polo]['status'] !== 'ok') continue;
                    
                    echo "<div style='margin: 15px 0; padding: 15px; background: #e8f4f8; border-radius: 5px;'>";
                    echo "<h4>🔍 Testando {$polo}</h4>";
                    
                    try {
                        $moodleAPI = new MoodleAPI($polo);
                        $cursos = $moodleAPI->listarTodosCursos();
                        
                        echo "<p><strong>Total encontrado:</strong> " . count($cursos) . " itens</p>";
                        
                        // Analisa tipos
                        $tipos = array_column($cursos, 'tipo');
                        $contadorTipos = array_count_values($tipos);
                        
                        echo "<p><strong>Tipos:</strong> ";
                        foreach ($contadorTipos as $tipo => $quantidade) {
                            echo "<span class='highlight'>{$tipo}: {$quantidade}</span> ";
                        }
                        echo "</p>";
                        
                        // Mostra alguns exemplos
                        echo "<p><strong>Exemplos encontrados:</strong></p>";
                        echo "<ul>";
                        foreach (array_slice($cursos, 0, 5) as $curso) {
                            $tipoIcon = $curso['tipo'] === 'categoria_curso' ? '📁' : '📚';
                            echo "<li>{$tipoIcon} {$curso['nome']} ({$curso['tipo']})";
                            if (!empty($curso['parent_name'])) {
                                echo " - Pai: {$curso['parent_name']}";
                            }
                            echo "</li>";
                        }
                        echo "</ul>";
                        
                        // Destaque para Breu Branco
                        if (strpos($polo, 'breubranco') !== false) {
                            $subcategorias = array_filter($cursos, function($c) {
                                return $c['tipo'] === 'categoria_curso' && !empty($c['parent_name']);
                            });
                            
                            echo "<div class='highlight' style='padding: 10px; margin: 10px 0;'>";
                            echo "<strong>🎯 BREU BRANCO ESPECÍFICO:</strong><br>";
                            echo "Subcategorias encontradas: " . count($subcategorias) . "<br>";
                            
                            if (!empty($subcategorias)) {
                                echo "✅ Sistema detectou hierarquia corretamente!<br>";
                                echo "<em>Subcategorias serão usadas como cursos para este polo.</em>";
                            } else {
                                echo "⚠️ Nenhuma subcategoria detectada. Verifique configuração.";
                            }
                            echo "</div>";
                        }
                        
                    } catch (Exception $e) {
                        echo "<p style='color: #dc3545;'>❌ Erro ao buscar cursos: {$e->getMessage()}</p>";
                    }
                    
                    echo "</div>";
                }
                echo "</div>";
                
                // Passo 6: Verificando dados de teste
                echo "<div class='step info'>";
                echo "<h3>6️⃣ Verificando Dados de Teste</h3>";
                
                foreach ($polosAtivos as $polo) {
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM cursos WHERE subdomain = ? AND ativo = 1");
                    $stmt->execute([$polo]);
                    $count = $stmt->fetchColumn();
                    
                    if ($count == 0) {
                        echo "<div style='background: #fff3cd; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
                        echo "<strong>⚠️ {$polo}:</strong> Nenhum curso no banco local. ";
                        echo "Execute a API de busca para sincronizar.";
                        echo "</div>";
                    } else {
                        echo "<div style='background: #d4edda; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
                        echo "<strong>✅ {$polo}:</strong> {$count} curso(s) no banco local.";
                        echo "</div>";
                    }
                }
                echo "</div>";
                
                // Passo 7: Estatísticas finais e links úteis
                echo "<div class='step success'>";
                echo "<h3>7️⃣ Estatísticas Finais e Próximos Passos</h3>";
                
                $stmt = $db->query("
                    SELECT 
                        COUNT(*) as total_cursos,
                        COUNT(DISTINCT subdomain) as total_polos,
                        COUNT(CASE WHEN tipo_estrutura = 'curso' THEN 1 END) as cursos_tradicionais,
                        COUNT(CASE WHEN tipo_estrutura = 'categoria_curso' THEN 1 END) as categorias_como_cursos,
                        COUNT(CASE WHEN tipo_estrutura = 'emergencia' THEN 1 END) as cursos_emergencia,
                        COUNT(CASE WHEN ativo = 1 THEN 1 END) as cursos_ativos
                    FROM cursos
                ");
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo "<div class='grid'>";
                echo "<div>";
                echo "<h4>📊 Estatísticas do Banco</h4>";
                echo "<ul>";
                echo "<li><strong>Total de cursos:</strong> {$stats['total_cursos']}</li>";
                echo "<li><strong>Polos diferentes:</strong> {$stats['total_polos']}</li>";
                echo "<li><strong>Cursos tradicionais:</strong> {$stats['cursos_tradicionais']}</li>";
                echo "<li><strong>Categorias como cursos:</strong> {$stats['categorias_como_cursos']}</li>";
                echo "<li><strong>Cursos de emergência:</strong> {$stats['cursos_emergencia']}</li>";
                echo "<li><strong>Cursos ativos:</strong> {$stats['cursos_ativos']}</li>";
                echo "</ul>";
                echo "</div>";
                
                echo "<div>";
                echo "<h4>🔗 Testes Recomendados</h4>";
                echo "<div class='teste-box'>";
                echo "<p><strong>1. Teste API Breu Branco:</strong></p>";
                echo "<a href='/admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br' target='_blank' style='color: #007bff;'>API Breu Branco</a>";
                
                echo "<p><strong>2. Upload de Boletos:</strong></p>";
                echo "<a href='/admin/upload-boletos.php' target='_blank' style='color: #007bff;'>Página de Upload</a>";
                
                echo "<p><strong>3. Diagnóstico Completo:</strong></p>";
                echo "<a href='/diagnostico_hierarquia.php' target='_blank' style='color: #007bff;'>Diagnóstico de Hierarquia</a>";
                echo "</div>";
                echo "</div>";
                echo "</div>";
                
                echo "<h4>🎯 Status por Polo:</h4>";
                echo "<table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>";
                echo "<tr style='background: #f8f9fa;'><th style='border: 1px solid #dee2e6; padding: 8px;'>Polo</th><th style='border: 1px solid #dee2e6; padding: 8px;'>Status</th><th style='border: 1px solid #dee2e6; padding: 8px;'>Ação</th></tr>";
                foreach ($polosTestados as $polo => $resultado) {
                    $statusIcon = $resultado['status'] === 'ok' ? '✅' : '❌';
                    $statusText = $resultado['status'] === 'ok' ? 'Conectado' : 'Erro';
                    echo "<tr>";
                    echo "<td style='border: 1px solid #dee2e6; padding: 8px;'>{$polo}</td>";
                    echo "<td style='border: 1px solid #dee2e6; padding: 8px;'>{$statusIcon} {$statusText}</td>";
                    if ($resultado['status'] === 'ok') {
                        echo "<td style='border: 1px solid #dee2e6; padding: 8px;'><a href='/admin/api/buscar-cursos.php?polo={$polo}' target='_blank' style='color: #28a745;'>Testar API</a></td>";
                    } else {
                        echo "<td style='border: 1px solid #dee2e6; padding: 8px;'><span style='color: #dc3545;'>Configure token</span></td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
                
                echo "</div>";
                
                echo "<div class='step success'>";
                echo "<h2>🎉 Atualização da Hierarquia Concluída!</h2>";
                echo "<p><strong>✅ Sistema pronto para:</strong></p>";
                echo "<ul>";
                echo "<li>Suporte completo à hierarquia de categorias</li>";
                echo "<li>Detecção automática de subcategorias como cursos (Breu Branco)</li>";
                echo "<li>Estrutura tradicional para outros polos</li>";
                echo "<li>Índices otimizados para melhor performance</li>";
                echo "<li>Cursos de emergência como fallback</li>";
                echo "</ul>";
                
                echo "<p><strong>🔍 Para verificar se está funcionando:</strong></p>";
                echo "<ol>";
                echo "<li>Acesse <code>/admin/upload-boletos.php</code></li>";
                echo "<li>Selecione <strong>Breu Branco</strong> no dropdown de polos</li>";
                echo "<li>Verifique se as subcategorias aparecem na lista de cursos</li>";
                echo "<li>Se não aparecer, clique no botão de \"Atualizar Cursos\" na página</li>";
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
                    <li>✅ Verificar e criar colunas necessárias para hierarquia</li>
                    <li>✅ Criar índices otimizados para performance</li>
                    <li>✅ Limpar dados duplicados e inconsistentes</li>
                    <li>✅ Testar conexões com todos os polos Moodle</li>
                    <li>✅ Testar busca específica para Breu Branco (subcategorias)</li>
                    <li>✅ Verificar se cursos estão sendo detectados corretamente</li>
                    <li>✅ Gerar relatório completo de status</li>
                </ul>
            </div>
            
            <div class="step warning">
                <h3>⚠️ Importante - Leia Antes de Executar:</h3>
                <ul>
                    <li><strong>Backup:</strong> Faça um backup do banco antes de executar</li>
                    <li><strong>Tokens:</strong> Certifique-se de que os tokens estão configurados em <code>config/moodle.php</code></li>
                    <li><strong>Breu Branco:</strong> Este script foi otimizado especificamente para detectar as subcategorias do Breu Branco</li>
                    <li><strong>Ambiente:</strong> Execute primeiro em ambiente de teste</li>
                </ul>
            </div>
            
            <div class="step info">
                <h3>🎯 Específico para Breu Branco:</h3>
                <p>Este script implementa a lógica especial para Breu Branco, onde as <strong>subcategorias</strong> representam os cursos técnicos. 
                O sistema buscará por categorias que contenham palavras-chave como:</p>
                <ul>
                    <li>Técnico em Enfermagem</li>
                    <li>Técnico em Administração</li>
                    <li>Técnico em Informática</li>
                    <li>Técnico em Segurança do Trabalho</li>
                    <li>E outras categorias técnicas</li>
                </ul>
            </div>
            
            <form method="POST">
                <input type="hidden" name="executar" value="1">
                <button type="submit" class="btn" onclick="return confirm('⚠️ ATENÇÃO: Este script irá modificar a estrutura do banco de dados.\n\n✅ Certifique-se de ter feito backup\n✅ Verifique se os tokens estão configurados\n\nDeseja continuar?')">
                    🚀 Executar Atualização Completa da Hierarquia
                </button>
            </form>
            
            <div style="margin-top: 30px;">
                <h3>🔗 Links de Teste Após Atualização:</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="teste-box">
                        <h4>APIs de Teste</h4>
                        <ul>
                            <li><a href="/admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br" target="_blank">API Breu Branco</a></li>
                            <li><a href="/admin/api/buscar-cursos.php?polo=igarape.imepedu.com.br" target="_blank">API Igarapé-Miri</a></li>
                            <li><a href="/admin/api/buscar-cursos.php?polo=tucurui.imepedu.com.br" target="_blank">API Tucuruí</a></li>
                            <li><a href="/admin/api/buscar-cursos.php?polo=moju.imepedu.com.br" target="_blank">API Moju</a></li>
                        </ul>
                    </div>
                    <div class="teste-box">
                        <h4>Páginas do Sistema</h4>
                        <ul>
                            <li><a href="/admin/upload-boletos.php" target="_blank">Upload de Boletos</a></li>
                            <li><a href="/diagnostico_hierarquia.php" target="_blank">Diagnóstico Completo</a></li>
                            <li><a href="/debug_breu_branco.php" target="_blank">Debug Breu Branco</a></li>
                            <li><a href="/admin/dashboard.php" target="_blank">Dashboard Admin</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php
        } // Fecha o else
        ?>
        
        <!-- Botão extra para testar todas as APIs -->
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div style="position: fixed; bottom: 20px; right: 20px; z-index: 999;">
            <button onclick="testarTodasAPIs()" style="background: #28a745; color: white; border: none; padding: 15px 20px; border-radius: 25px; cursor: pointer; box-shadow: 0 4px 8px rgba(0,0,0,0.2); font-weight: bold;">
                🚀 Testar Todas as APIs
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Footer informativo -->
        <div style="margin-top: 50px; padding: 20px; background: #f8f9fa; border-radius: 8px; text-align: center; color: #6c757d;">
            <h4>📚 Documentação da Hierarquia</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin: 20px 0;">
                <div>
                    <h5>🏗️ Breu Branco</h5>
                    <p><small>Usa <strong>subcategorias</strong> como cursos. Sistema busca por categorias filhas que contenham palavras-chave como "técnico", "enfermagem", etc.</small></p>
                </div>
                <div>
                    <h5>🏛️ Igarapé-Miri</h5>
                    <p><small>Usa <strong>categorias principais</strong> como cursos. Sistema busca por categorias que representam cursos específicos.</small></p>
                </div>
                <div>
                    <h5>📚 Outros Polos</h5>
                    <p><small>Usa <strong>cursos tradicionais</strong> do Moodle. Sistema busca por cursos nativos da plataforma.</small></p>
                </div>
            </div>
            
            <div style="margin-top: 30px; padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #007bff;">
                <h5>🔧 Estrutura de Dados</h5>
                <p><small>
                    <strong>tipo_estrutura:</strong> 'curso' | 'categoria_curso' | 'emergencia'<br>
                    <strong>identificador_moodle:</strong> 'course_123' | 'cat_456' | 'emg_xxx'<br>
                    <strong>categoria_pai:</strong> Nome da categoria pai (para hierarquia)
                </small></p>
            </div>
            
            <div style="margin-top: 20px;">
                <p><small>
                    <strong>Sistema de Boletos IMED</strong> - Versão Hierarquia v2.0<br>
                    Suporte completo para estruturas Moodle diversificadas<br>
                    <em>Desenvolvido especificamente para os polos IMED</em>
                </small></p>
            </div>
        </div>
        
    </div>
    
    <!-- Scripts JavaScript para funcionalidades extras -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-scroll para resultados se existe
        const successDiv = document.querySelector('.step.success h2');
        if (successDiv && successDiv.textContent.includes('Concluída')) {
            successDiv.scrollIntoView({ behavior: 'smooth' });
        }
        
        // Adiciona confirmação extra para botão de execução
        const executeButton = document.querySelector('button[type="submit"]');
        if (executeButton) {
            executeButton.addEventListener('click', function(e) {
                const confirmed = confirm(
                    '🔧 ATUALIZAÇÃO COMPLETA DA HIERARQUIA\n\n' +
                    '⚠️ IMPORTANTE:\n' +
                    '• Faça backup do banco antes de continuar\n' +
                    '• Certifique-se de que os tokens estão configurados\n' +
                    '• Este processo irá modificar a estrutura do banco\n\n' +
                    '✅ BENEFÍCIOS:\n' +
                    '• Suporte completo à hierarquia de cursos\n' +
                    '• Breu Branco funcionará com subcategorias\n' +
                    '• Sistema mais robusto e otimizado\n\n' +
                    'Deseja continuar com a atualização?'
                );
                
                if (confirmed) {
                    executeButton.innerHTML = '⏳ Executando... Aguarde...';
                    executeButton.disabled = true;
                    executeButton.style.opacity = '0.7';
                    return true;
                } else {
                    e.preventDefault();
                    return false;
                }
            });
        }
        
        // Adiciona funcionalidade de teste rápido para os links
        const testLinks = document.querySelectorAll('a[href*="/admin/api/buscar-cursos.php"]');
        testLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const url = this.href;
                const polo = url.split('polo=')[1];
                
                // Cria modal de teste
                const modal = document.createElement('div');
                modal.style.cssText = `
                    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                    background: rgba(0,0,0,0.7); z-index: 1000;
                    display: flex; align-items: center; justify-content: center;
                `;
                
                modal.innerHTML = `
                    <div style="background: white; padding: 20px; border-radius: 8px; max-width: 600px; max-height: 80%; overflow-y: auto;">
                        <h3>🧪 Testando API: ${polo}</h3>
                        <div id="testResult">⏳ Carregando...</div>
                        <br>
                        <button onclick="this.parentElement.parentElement.remove()" style="background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                            Fechar
                        </button>
                        <a href="${url}" target="_blank" style="background: #28a745; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; margin-left: 10px; display: inline-block;">
                            Abrir em Nova Aba
                        </a>
                    </div>
                `;
                
                document.body.appendChild(modal);
                
                // Faz requisição AJAX
                fetch(url)
                    .then(response => response.text())
                    .then(text => {
                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            data = { raw: text.substring(0, 500) + '...' };
                        }
                        
                        const resultDiv = document.getElementById('testResult');
                        resultDiv.innerHTML = `<pre style="background: #f8f9fa; padding: 15px; border-radius: 4px; font-size: 12px; white-space: pre-wrap;">${JSON.stringify(data, null, 2)}</pre>`;
                    })
                    .catch(error => {
                        document.getElementById('testResult').innerHTML = `<div style="color: #dc3545;">❌ Erro: ${error.message}</div>`;
                    });
            });
        });
    });
    
    // Função para testar todas as APIs de uma vez
    function testarTodasAPIs() {
        const polos = [
            'breubranco.imepedu.com.br',
            'igarape.imepedu.com.br', 
            'tucurui.imepedu.com.br',
            'moju.imepedu.com.br'
        ];
        
        const resultados = {};
        let testesCompletos = 0;
        
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8); z-index: 1001;
            display: flex; align-items: center; justify-content: center;
        `;
        
        modal.innerHTML = `
            <div style="background: white; padding: 20px; border-radius: 8px; width: 80%; max-height: 80%; overflow-y: auto;">
                <h3>🚀 Testando Todas as APIs dos Polos</h3>
                <div id="testProgress">Iniciando testes...</div>
                <div id="testResults"></div>
                <br>
                <button onclick="this.parentElement.parentElement.remove()" style="background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                    Fechar
                </button>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        polos.forEach(polo => {
            const url = `/admin/api/buscar-cursos.php?polo=${polo}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    resultados[polo] = data;
                    testesCompletos++;
                    
                    const progressDiv = document.getElementById('testProgress');
                    progressDiv.innerHTML = `Testados: ${testesCompletos}/${polos.length} polos`;
                    
                    const resultsDiv = document.getElementById('testResults');
                    let html = '<h4>Resultados:</h4>';
                    
                    Object.keys(resultados).forEach(p => {
                        const r = resultados[p];
                        const status = r.success ? '✅' : '❌';
                        const total = r.total || 0;
                        const estrutura = r.estrutura_detectada || 'N/A';
                        
                        html += `
                            <div style="margin: 10px 0; padding: 10px; background: ${r.success ? '#d4edda' : '#f8d7da'}; border-radius: 4px;">
                                <strong>${status} ${p}</strong><br>
                                Cursos: ${total} | Estrutura: ${estrutura}
                            </div>
                        `;
                    });
                    
                    resultsDiv.innerHTML = html;
                    
                    if (testesCompletos === polos.length) {
                        progressDiv.innerHTML = '🎉 Todos os testes concluídos!';
                    }
                })
                .catch(error => {
                    resultados[polo] = { error: error.message };
                    testesCompletos++;
                    
                    document.getElementById('testProgress').innerHTML = `Testados: ${testesCompletos}/${polos.length} polos (${polo} com erro)`;
                });
        });
    }
    </script>
</body>
</html>