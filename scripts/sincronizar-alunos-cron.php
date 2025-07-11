<?php
/**
 * Sistema de Boletos IMEPEDU - Script de Sincronização Automática
 * Arquivo: scripts/sincronizar-alunos-cron.php
 * 
 * Para usar no cron:
 * 0 2 * * * /usr/bin/php /caminho/para/scripts/sincronizar-alunos-cron.php
 */

// Só executa via linha de comando
if (php_sapi_name() !== 'cli') {
    die("Este script só pode ser executado via linha de comando\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/moodle.php';
require_once __DIR__ . '/../src/MoodleAPI.php';
require_once __DIR__ . '/../src/AlunoService.php';

echo "🔄 SINCRONIZAÇÃO AUTOMÁTICA DE ALUNOS - " . date('Y-m-d H:i:s') . "\n";
echo "================================================\n";

try {
    $db = (new Database())->getConnection();
    $alunoService = new AlunoService();
    
    // Obtém todos os polos ativos
    $polosAtivos = MoodleConfig::getActiveSubdomains();
    
    if (empty($polosAtivos)) {
        throw new Exception('Nenhum polo ativo configurado');
    }
    
    echo "📋 Polos ativos encontrados: " . count($polosAtivos) . "\n";
    
    $resultadosGerais = [
        'total_polos' => count($polosAtivos),
        'polos_processados' => 0,
        'total_alunos_encontrados' => 0,
        'alunos_novos' => 0,
        'alunos_atualizados' => 0,
        'alunos_com_erro' => 0,
        'tempo_inicio' => time()
    ];
    
    // Processa cada polo
    foreach ($polosAtivos as $polo) {
        echo "\n🎯 Processando polo: {$polo}\n";
        echo "-------------------------------------------\n";
        
        try {
            $moodleAPI = new MoodleAPI($polo);
            
            // Testa conectividade primeiro
            $teste = $moodleAPI->testarConexao();
            if (!$teste['sucesso']) {
                echo "❌ Erro de conexão com {$polo}: {$teste['erro']}\n";
                continue;
            }
            
            echo "✅ Conexão OK com {$polo}\n";
            
            // Busca todos os alunos do polo
            $alunosMoodle = $moodleAPI->buscarTodosAlunosDoMoodle();
            
            echo "📊 Alunos encontrados: " . count($alunosMoodle) . "\n";
            
            $alunosNovos = 0;
            $alunosAtualizados = 0;
            $alunosComErro = 0;
            
            foreach ($alunosMoodle as $alunoMoodle) {
                try {
                    // Verifica se já existe
                    $alunoExistente = $alunoService->buscarAlunoPorCPFESubdomain(
                        $alunoMoodle['cpf'], 
                        $polo
                    );
                    
                    if ($alunoExistente) {
                        $alunoService->salvarOuAtualizarAluno($alunoMoodle);
                        $alunosAtualizados++;
                        echo "🔄 Atualizado: {$alunoMoodle['nome']} (CPF: {$alunoMoodle['cpf']})\n";
                    } else {
                        $alunoService->salvarOuAtualizarAluno($alunoMoodle);
                        $alunosNovos++;
                        echo "🆕 Novo: {$alunoMoodle['nome']} (CPF: {$alunoMoodle['cpf']})\n";
                    }
                    
                } catch (Exception $e) {
                    $alunosComErro++;
                    echo "❌ Erro {$alunoMoodle['nome']}: {$e->getMessage()}\n";
                }
            }
            
            echo "📈 Resumo do polo {$polo}:\n";
            echo "   - Novos: {$alunosNovos}\n";
            echo "   - Atualizados: {$alunosAtualizados}\n";
            echo "   - Erros: {$alunosComErro}\n";
            
            $resultadosGerais['polos_processados']++;
            $resultadosGerais['total_alunos_encontrados'] += count($alunosMoodle);
            $resultadosGerais['alunos_novos'] += $alunosNovos;
            $resultadosGerais['alunos_atualizados'] += $alunosAtualizados;
            $resultadosGerais['alunos_com_erro'] += $alunosComErro;
            
        } catch (Exception $e) {
            echo "❌ ERRO no polo {$polo}: {$e->getMessage()}\n";
        }
    }
    
    $tempoTotal = time() - $resultadosGerais['tempo_inicio'];
    
    echo "\n";
    echo "================================================\n";
    echo "🏁 SINCRONIZAÇÃO CONCLUÍDA\n";
    echo "================================================\n";
    echo "⏱️  Tempo total: {$tempoTotal} segundos\n";
    echo "🏢 Polos processados: {$resultadosGerais['polos_processados']}/{$resultadosGerais['total_polos']}\n";
    echo "👥 Total de alunos: {$resultadosGerais['total_alunos_encontrados']}\n";
    echo "🆕 Alunos novos: {$resultadosGerais['alunos_novos']}\n";
    echo "🔄 Alunos atualizados: {$resultadosGerais['alunos_atualizados']}\n";
    echo "❌ Alunos com erro: {$resultadosGerais['alunos_com_erro']}\n";
    echo "================================================\n";
    
    // Registra log no banco
    registrarLogSincronizacaoCron($resultadosGerais);
    
    // Envia email de relatório (opcional)
    if ($resultadosGerais['alunos_novos'] > 0 || $resultadosGerais['alunos_com_erro'] > 0) {
        enviarRelatorioEmail($resultadosGerais);
    }
    
} catch (Exception $e) {
    echo "❌ ERRO CRÍTICO: {$e->getMessage()}\n";
    error_log("CRON SINCRONIZAÇÃO: ERRO CRÍTICO - " . $e->getMessage());
    
    // Registra erro crítico
    registrarErroCritico($e->getMessage());
    
    exit(1);
}

/**
 * Registra log da sincronização no banco
 */
function registrarLogSincronizacaoCron($resultados) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, detalhes, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "Sincronização CRON: {$resultados['alunos_novos']} novos, {$resultados['alunos_atualizados']} atualizados";
        
        $stmt->execute([
            'sincronizacao_cron',
            0, // Sistema
            $descricao,
            json_encode($resultados),
            'CRON',
            'Script automático'
        ]);
        
        echo "📝 Log registrado no banco de dados\n";
        
    } catch (Exception $e) {
        echo "⚠️  Erro ao registrar log: {$e->getMessage()}\n";
    }
}

/**
 * Registra erro crítico
 */
function registrarErroCritico($erro) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            'sincronizacao_erro_critico',
            0,
            "Erro crítico na sincronização CRON: {$erro}",
            'CRON',
            'Script automático'
        ]);
        
    } catch (Exception $e) {
        error_log("Não foi possível registrar erro crítico no banco: " . $e->getMessage());
    }
}

/**
 * Envia relatório por email (opcional)
 */
function enviarRelatorioEmail($resultados) {
    try {
        $to = 'admin@imepedu.com.br'; // Configure o email
        $subject = 'Relatório de Sincronização - ' . date('Y-m-d H:i:s');
        
        $message = "
Relatório de Sincronização Automática
=====================================

Data/Hora: " . date('Y-m-d H:i:s') . "
Polos processados: {$resultados['polos_processados']}/{$resultados['total_polos']}
Total de alunos: {$resultados['total_alunos_encontrados']}
Alunos novos: {$resultados['alunos_novos']}
Alunos atualizados: {$resultados['alunos_atualizados']}
Alunos com erro: {$resultados['alunos_com_erro']}

Sistema de Boletos IMEPEDU
        ";
        
        $headers = [
            'From: sistema@imepedu.com.br',
            'Reply-To: sistema@imepedu.com.br',
            'Content-Type: text/plain; charset=UTF-8'
        ];
        
        if (mail($to, $subject, $message, implode("\r\n", $headers))) {
            echo "📧 Relatório enviado por email\n";
        }
        
    } catch (Exception $e) {
        echo "⚠️  Erro ao enviar email: {$e->getMessage()}\n";
    }
}

echo "🔚 Script finalizado em " . date('Y-m-d H:i:s') . "\n";
?>