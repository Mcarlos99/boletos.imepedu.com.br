<?php
/**
 * Sistema de Boletos IMED - Debug Específico para Boletos do Aluno
 * Arquivo: admin/api/debug-boletos-aluno.php
 * 
 * Diagnóstica por que o aluno não vê os boletos que foram criados pelo admin
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../config/moodle.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $cpf = preg_replace('/[^0-9]/', '', $input['cpf'] ?? '03183924536'); // CPF do Carlos Santos
    $polo = $input['polo'] ?? 'breubranco.imepedu.com.br';
    
    $db = (new Database())->getConnection();
    $resultado = [
        'success' => true,
        'cpf' => $cpf,
        'polo' => $polo,
        'timestamp' => date('Y-m-d H:i:s'),
        'diagnostico' => []
    ];
    
    $resultado['diagnostico'][] = "=== DIAGNÓSTICO ESPECÍFICO: BOLETOS NÃO APARECENDO ===";
    $resultado['diagnostico'][] = "CPF: {$cpf} (Carlos Santos)";
    $resultado['diagnostico'][] = "Polo: {$polo}";
    
    // 1. VERIFICA ALUNO NO SISTEMA
    $resultado['diagnostico'][] = "\n1. 🔍 VERIFICANDO ALUNO NO SISTEMA LOCAL:";
    
    $stmt = $db->prepare("
        SELECT id, nome, cpf, email, subdomain, moodle_user_id, created_at
        FROM alunos 
        WHERE cpf = ? AND subdomain = ?
    ");
    $stmt->execute([$cpf, $polo]);
    $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno) {
        $resultado['aluno'] = $aluno;
        $resultado['diagnostico'][] = "✅ Aluno encontrado:";
        $resultado['diagnostico'][] = "   - ID: {$aluno['id']}";
        $resultado['diagnostico'][] = "   - Nome: {$aluno['nome']}";
        $resultado['diagnostico'][] = "   - Criado em: {$aluno['created_at']}";
    } else {
        $resultado['diagnostico'][] = "❌ PROBLEMA: Aluno não encontrado no sistema local";
        $resultado['diagnostico'][] = "   CAUSA: Aluno não fez login ainda ou não foi sincronizado";
        $resultado['diagnostico'][] = "   SOLUÇÃO: Aluno deve fazer login primeiro";
        
        echo json_encode($resultado);
        exit;
    }
    
    // 2. VERIFICA BOLETOS CRIADOS PELO ADMIN
    $resultado['diagnostico'][] = "\n2. 💼 BOLETOS CRIADOS PELO ADMIN:";
    
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain as curso_subdomain,
               a.nome as aluno_nome, a.cpf as aluno_cpf
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        INNER JOIN alunos a ON b.aluno_id = a.id
        WHERE a.cpf = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$cpf]);
    $boletosAdmin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $resultado['boletos_admin'] = $boletosAdmin;
    
    if (!empty($boletosAdmin)) {
        $resultado['diagnostico'][] = "✅ Encontrados " . count($boletosAdmin) . " boletos criados pelo admin:";
        
        foreach ($boletosAdmin as $boleto) {
            $resultado['diagnostico'][] = "   📄 #{$boleto['numero_boleto']}";
            $resultado['diagnostico'][] = "      - Curso: {$boleto['curso_nome']}";
            $resultado['diagnostico'][] = "      - Polo Curso: {$boleto['curso_subdomain']}";
            $resultado['diagnostico'][] = "      - Aluno ID: {$boleto['aluno_id']}";
            $resultado['diagnostico'][] = "      - Valor: R$ " . number_format($boleto['valor'], 2, ',', '.');
            $resultado['diagnostico'][] = "      - Status: {$boleto['status']}";
            $resultado['diagnostico'][] = "      - Vencimento: {$boleto['vencimento']}";
        }
    } else {
        $resultado['diagnostico'][] = "❌ PROBLEMA: Nenhum boleto encontrado para este CPF";
        $resultado['diagnostico'][] = "   CAUSA: Boletos não foram criados ou estão com CPF diferente";
        
        // Busca boletos similares
        $stmt = $db->prepare("
            SELECT b.*, a.cpf, a.nome
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            WHERE a.nome LIKE '%Carlos%' OR a.nome LIKE '%Santos%'
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $boletosSimilares = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($boletosSimilares)) {
            $resultado['diagnostico'][] = "   🔍 Boletos similares encontrados:";
            foreach ($boletosSimilares as $boleto) {
                $resultado['diagnostico'][] = "      - {$boleto['nome']} (CPF: {$boleto['cpf']})";
            }
        }
        
        echo json_encode($resultado);
        exit;
    }
    
    // 3. VERIFICA CONSULTA DO DASHBOARD DO ALUNO
    $resultado['diagnostico'][] = "\n3. 🎯 SIMULANDO CONSULTA DO DASHBOARD:";
    
    // Esta é a query que o dashboard.php usa
    $stmt = $db->prepare("
        SELECT b.*, c.nome as curso_nome
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.aluno_id = ?
        ORDER BY b.vencimento DESC, b.created_at DESC
    ");
    $stmt->execute([$aluno['id']]);
    $boletosDashboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $resultado['boletos_dashboard'] = $boletosDashboard;
    
    if (!empty($boletosDashboard)) {
        $resultado['diagnostico'][] = "✅ Dashboard deveria mostrar " . count($boletosDashboard) . " boletos";
        
        foreach ($boletosDashboard as $boleto) {
            $resultado['diagnostico'][] = "   📋 {$boleto['curso_nome']} - R$ " . number_format($boleto['valor'], 2, ',', '.');
        }
    } else {
        $resultado['diagnostico'][] = "❌ PROBLEMA CRÍTICO: Dashboard não encontra boletos!";
        $resultado['diagnostico'][] = "   Aluno ID usado: {$aluno['id']}";
        
        // Investiga o problema
        $resultado['diagnostico'][] = "\n🔬 INVESTIGAÇÃO DETALHADA:";
        
        // Verifica se os IDs correspondem
        foreach ($boletosAdmin as $boleto) {
            if ($boleto['aluno_id'] != $aluno['id']) {
                $resultado['diagnostico'][] = "❌ INCOMPATIBILIDADE: Boleto {$boleto['numero_boleto']} tem aluno_id {$boleto['aluno_id']}, mas aluno atual tem ID {$aluno['id']}";
            }
        }
    }
    
    // 4. VERIFICA MATRÍCULAS
    $resultado['diagnostico'][] = "\n4. 📚 VERIFICANDO MATRÍCULAS:";
    
    $stmt = $db->prepare("
        SELECT m.*, c.nome as curso_nome, c.subdomain as curso_subdomain
        FROM matriculas m
        INNER JOIN cursos c ON m.curso_id = c.id
        WHERE m.aluno_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$aluno['id']]);
    $matriculas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $resultado['matriculas'] = $matriculas;
    
    if (!empty($matriculas)) {
        $resultado['diagnostico'][] = "✅ Encontradas " . count($matriculas) . " matrículas:";
        
        foreach ($matriculas as $matricula) {
            $resultado['diagnostico'][] = "   📖 {$matricula['curso_nome']} (ID: {$matricula['curso_id']})";
            $resultado['diagnostico'][] = "      - Status: {$matricula['status']}";
            $resultado['diagnostico'][] = "      - Polo: {$matricula['curso_subdomain']}";
        }
    } else {
        $resultado['diagnostico'][] = "❌ PROBLEMA: Nenhuma matrícula encontrada";
        $resultado['diagnostico'][] = "   CAUSA: Dados não foram sincronizados do Moodle";
    }
    
    // 5. VERIFICA CURSOS DISPONÍVEIS
    $resultado['diagnostico'][] = "\n5. 📖 CURSOS DISPONÍVEIS NO POLO:";
    
    $stmt = $db->prepare("
        SELECT id, nome, nome_curto, moodle_course_id, subdomain
        FROM cursos 
        WHERE subdomain = ? AND ativo = 1
        ORDER BY nome
    ");
    $stmt->execute([$polo]);
    $cursosDisponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($cursosDisponiveis)) {
        $resultado['diagnostico'][] = "✅ Cursos disponíveis no polo:";
        foreach ($cursosDisponiveis as $curso) {
            $resultado['diagnostico'][] = "   📚 {$curso['nome']} (ID: {$curso['id']})";
            
            // Verifica se há boletos para este curso
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM boletos WHERE curso_id = ? AND aluno_id = ?");
            $stmt->execute([$curso['id'], $aluno['id']]);
            $countBoletos = $stmt->fetch()['count'];
            
            if ($countBoletos > 0) {
                $resultado['diagnostico'][] = "      ✅ {$countBoletos} boleto(s) neste curso";
            }
        }
    }
    
    // 6. DIAGNÓSTICO FINAL E SOLUÇÕES
    $resultado['diagnostico'][] = "\n6. 🎯 DIAGNÓSTICO FINAL:";
    
    if (count($boletosAdmin) > 0 && count($boletosDashboard) == 0) {
        $resultado['diagnostico'][] = "🔴 PROBLEMA IDENTIFICADO: Dessincronia entre Admin e Dashboard";
        $resultado['diagnostico'][] = "   - Admin criou boletos: ✅";
        $resultado['diagnostico'][] = "   - Dashboard encontra boletos: ❌";
        $resultado['diagnostico'][] = "";
        $resultado['diagnostico'][] = "💡 POSSÍVEIS CAUSAS:";
        $resultado['diagnostico'][] = "   1. IDs de aluno diferentes entre criação e consulta";
        $resultado['diagnostico'][] = "   2. Problema na query do dashboard";
        $resultado['diagnostico'][] = "   3. Cache ou sessão desatualizada";
        
        $resultado['diagnostico'][] = "";
        $resultado['diagnostico'][] = "🔧 SOLUÇÕES:";
        $resultado['diagnostico'][] = "   1. Forçar logout e login do aluno";
        $resultado['diagnostico'][] = "   2. Verificar se o aluno está usando o mesmo CPF";
        $resultado['diagnostico'][] = "   3. Executar sincronização forçada";
        
    } elseif (count($boletosAdmin) > 0 && count($boletosDashboard) > 0) {
        $resultado['diagnostico'][] = "🟢 TUDO PARECE CORRETO";
        $resultado['diagnostico'][] = "   - Boletos existem e dashboard deveria mostrá-los";
        $resultado['diagnostico'][] = "   - Possível problema de cache do navegador";
        
    } else {
        $resultado['diagnostico'][] = "🟡 SITUAÇÃO ATÍPICA";
        $resultado['diagnostico'][] = "   - Necessária investigação manual";
    }
    
    // 7. AÇÕES AUTOMÁTICAS DE CORREÇÃO
    if (isset($input['acao']) && $input['acao'] === 'corrigir') {
        $resultado['diagnostico'][] = "\n7. 🔧 EXECUTANDO CORREÇÕES AUTOMÁTICAS:";
        
        try {
            // Força atualização dos status
            $stmt = $db->prepare("UPDATE boletos SET updated_at = NOW() WHERE aluno_id = ?");
            $stmt->execute([$aluno['id']]);
            
            $resultado['diagnostico'][] = "✅ Timestamps dos boletos atualizados";
            
            // Limpa possível cache de sessão
            if (isset($_SESSION['aluno_' . $cpf])) {
                unset($_SESSION['aluno_' . $cpf]);
                $resultado['diagnostico'][] = "✅ Cache de sessão limpo";
            }
            
            $resultado['correcao_executada'] = true;
            
        } catch (Exception $e) {
            $resultado['diagnostico'][] = "❌ Erro na correção: " . $e->getMessage();
        }
    }
    
    echo json_encode($resultado);
    
} catch (Exception $e) {
    error_log("Erro no debug de boletos: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => ['erro_detalhado' => $e->getMessage()]
    ]);
}
?>