<?php
/**
 * Sistema de Boletos IMEPEDU - Comando para Sincronização Automática via Cron
 * Arquivo: admin/cron/sincronizar-alunos.php
 * 
 * Execute: php admin/cron/sincronizar-alunos.php
 * Cron: 0 2 * * * /usr/bin/php /caminho/para/admin/cron/sincronizar-alunos.php
 */

// Verifica se está sendo executado via CLI
if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando\n");
}

// Define diretório base
$baseDir = dirname(dirname(__DIR__));
chdir($baseDir);

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';
require_once 'src/AlunoService.php';

echo "========================================\n";
echo "IMEPEDU - Sincronização Automática\n";
echo "Iniciada em: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

try {
    $alunoService = new AlunoService();
    
    // Estatísticas da sincronização
    $stats = [
        'total_polos' => 0,
        'total_alunos_moodle' => 0,
        'alunos_novos' => 0,
        'alunos_atualizados' => 0,
        'alunos_erro' => 0,
        'cursos_processados' => 0,
        'tempo_inicio' => microtime(true),
        'errors' => []
    ];

    // Obtém polos ativos
    $polosAtivos = MoodleConfig::getActiveSubdomains();
    $stats['total_polos'] = count($polosAtivos);

    if (empty($polosAtivos)) {
        throw new Exception('Nenhum polo ativo configurado');
    }

    echo "Polos encontrados: {$stats['total_polos']}\n";
    foreach ($polosAtivos as $polo) {
        $config = MoodleConfig::getConfig($polo);
        echo "  - {$config['name']} ({$polo})\n";
    }
    echo "\n";

    // Processa cada polo
    foreach ($polosAtivos as $subdomain) {
        echo "Processando polo: $subdomain\n";
        echo str_repeat("-", 50) . "\n";
        
        try {
            $poloStats = sincronizarPoloCLI($subdomain, $alunoService);
            
            // Atualiza estatísticas globais
            $stats['total_alunos_moodle'] += $poloStats['total_usuarios'];
            $stats['alunos_novos'] += $poloStats['alunos_novos'];
            $stats['alunos_atualizados'] += $poloStats['alunos_atualizados'];
            $stats['alunos_erro'] += $poloStats['alunos_erro'];
            $stats['cursos_processados'] += $poloStats['cursos_processados'];
            
            if (!empty($poloStats['errors'])) {
                $stats['errors'][$subdomain] = $poloStats['errors'];
            }
            
            echo "✅ Polo concluído:\n";
            echo "   Usuários encontrados: {$poloStats['total_usuarios']}\n";
            echo "   Novos alunos: {$poloStats['alunos_novos']}\n";
            echo "   Atualizados: {$poloStats['alunos_atualizados']}\n";
            echo "   Erros: {$poloStats['alunos_erro']}\n";
            echo "   Cursos processados: {$poloStats['cursos_processados']}\n";
            
        } catch (Exception $e) {
            echo "❌ ERRO no polo $subdomain: " . $e->getMessage() . "\n";
            $stats['errors'][$subdomain] = $e->getMessage();
            $stats['alunos_erro']++;
        }
        
        echo "\n";
    }

    $stats['tempo_fim'] = microtime(true);
    $tempoTotal = round($stats['tempo_fim'] - $stats['tempo_inicio'], 2);

    // Registra log da sincronização
    registrarLogSincronizacaoCLI($stats);

    echo "========================================\n";
    echo "SINCRONIZAÇÃO CONCLUÍDA\n";
    echo "========================================\n";
    echo "Tempo total: {$tempoTotal}s\n";
    echo "Polos processados: {$stats['total_polos']}\n";
    echo "Total de alunos no Moodle: {$stats['total_alunos_moodle']}\n";
    echo "Novos alunos criados: {$stats['alunos_novos']}\n";
    echo "Alunos atualizados: {$stats['alunos_atualizados']}\n";
    echo "Erros: {$stats['alunos_erro']}\n";
    echo "Cursos processados: {$stats['cursos_processados']}\n";
    
    if ($stats['total_alunos_moodle'] > 0) {
        $taxaSucesso = round((($stats['alunos_novos'] + $stats['alunos_atualizados']) / $stats['total_alunos_moodle']) * 100, 1);
        echo "Taxa de sucesso: {$taxaSucesso}%\n";
    }
    
    if (!empty($stats['errors'])) {
        echo "\n⚠️  ERROS ENCONTRADOS:\n";
        foreach ($stats['errors'] as $polo => $erros) {
            echo "Polo: $polo\n";
            if (is_array($erros)) {
                foreach ($erros as $erro) {
                    echo "  - $erro\n";
                }
            } else {
                echo "  - $erros\n";
            }
        }
    }
    
    echo "\nFinalizada em: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "❌ ERRO GERAL: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Sincroniza um polo específico (versão CLI)
 */
function sincronizarPoloCLI($subdomain, $alunoService) {
    $stats = [
        'total_usuarios' => 0,
        'alunos_novos' => 0,
        'alunos_atualizados' => 0,
        'alunos_erro' => 0,
        'cursos_processados' => 0,
        'errors' => []
    ];

    try {
        echo "Conectando ao Moodle...\n";
        $moodleAPI = new MoodleAPI($subdomain);

        echo "Buscando usuários do Moodle...\n";
        $usuarios = $moodleAPI->buscarTodosUsuarios();
        
        if (empty($usuarios)) {
            throw new Exception("Nenhum usuário encontrado no Moodle");
        }

        $stats['total_usuarios'] = count($usuarios);
        echo "Encontrados {$stats['total_usuarios']} usuários válidos\n";

        $contador = 0;
        foreach ($usuarios as $dadosUsuario) {
            $contador++;
            
            try {
                // Progress indicator
                if ($contador % 10 == 0 || $contador == $stats['total_usuarios']) {
                    $percent = round(($contador / $stats['total_usuarios']) * 100);
                    echo "Processando... {$contador}/{$stats['total_usuarios']} ({$percent}%)\n";
                }

                // Verifica se é novo ou atualização
                $alunoExistente = $alunoService->buscarAlunoPorCPFESubdomain(
                    $dadosUsuario['cpf'], 
                    $subdomain
                );

                // Salva/atualiza aluno
                $alunoId = $alunoService->salvarOuAtualizarAluno($dadosUsuario);

                if ($alunoExistente) {
                    $stats['alunos_atualizados']++;
                } else {
                    $stats['alunos_novos']++;
                }

                $stats['cursos_processados'] += count($dadosUsuario['cursos']);

            } catch (Exception $e) {
                $stats['alunos_erro']++;
                $stats['errors'][] = "Usuário {$dadosUsuario['nome']}: " . $e->getMessage();
                echo "⚠️  Erro no usuário {$dadosUsuario['nome']}: " . $e->getMessage() . "\n";
            }
        }

    } catch (Exception $e) {
        throw new Exception("Erro ao sincronizar polo $subdomain: " . $e->getMessage());
    }

    return $stats;
}

/**
 * Registra log da sincronização CLI
 */
function registrarLogSincronizacaoCLI($stats) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = sprintf(
            "Sincronização via CRON: %d polos, %d alunos (%d novos, %d atualizados, %d erros) em %.2fs",
            $stats['total_polos'],
            $stats['total_alunos_moodle'],
            $stats['alunos_novos'],
            $stats['alunos_atualizados'],
            $stats['alunos_erro'],
            $stats['tempo_fim'] - $stats['tempo_inicio']
        );
        
        $stmt->execute([
            'sincronizacao_cron',
            0, // Sistema
            $descricao,
            'CLI',
            'CRON Job'
        ]);

    } catch (Exception $e) {
        echo "⚠️  Erro ao registrar log: " . $e->getMessage() . "\n";
    }
}
?>