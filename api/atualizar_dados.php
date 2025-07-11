<?php
/**
 * VERSÃO CORRIGIDA - api/atualizar_dados.php
 * Usa métodos que realmente existem no AlunoService
 */

// Ativa exibição de erros temporariamente para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

if (!isset($_SESSION['aluno_cpf']) || !isset($_SESSION['subdomain'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Usuário não autenticado'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Carrega apenas o essencial com verificação
    $requiredFiles = [
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../src/AlunoService.php',
        __DIR__ . '/../src/BoletoService.php'
    ];
    
    foreach ($requiredFiles as $file) {
        if (!file_exists($file)) {
            throw new Exception('Arquivo não encontrado: ' . basename($file));
        }
        require_once $file;
    }

    $alunoService = new AlunoService();
    $boletoService = new BoletoService();

    $cpf = $_SESSION['aluno_cpf'];
    $subdomain = $_SESSION['subdomain'];

    error_log("Sincronização corrigida iniciada para CPF: {$cpf}, Polo: {$subdomain}");

    // 1. Busca aluno existente ou sincroniza do Moodle
    $aluno = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $subdomain);
    
    if (!$aluno) {
        // Se não encontrou, tenta buscar no Moodle
        try {
            require_once __DIR__ . '/../src/MoodleAPI.php';
            $moodleAPI = new MoodleAPI($subdomain);
            $dadosMoodle = $moodleAPI->buscarAlunoPorCPF($cpf);
            
            if ($dadosMoodle) {
                // Salva/atualiza aluno na base local
                $aluno = $alunoService->salvarOuAtualizarAluno($dadosMoodle);
            }
        } catch (Exception $moodleError) {
            error_log("Erro ao buscar no Moodle: " . $moodleError->getMessage());
        }
    }
    
    if (!$aluno) {
        throw new Exception('Aluno não encontrado no sistema nem no Moodle');
    }

    // 2. Atualiza sessão
    $_SESSION['aluno_id'] = $aluno['id'];
    $_SESSION['aluno_nome'] = $aluno['nome'];

    // 3. Busca cursos do aluno (usa método que existe)
    $cursos = $alunoService->buscarCursosAlunoPorSubdomain($aluno['id'], $subdomain);
    
    // 4. Tenta sincronizar cursos do Moodle se não tem cursos locais
    if (empty($cursos)) {
        try {
            if (isset($moodleAPI)) {
                $cursosMoodle = $moodleAPI->buscarCursosAluno($aluno['moodle_user_id']);
                if (!empty($cursosMoodle)) {
                    // Salva cursos na base local
                    foreach ($cursosMoodle as $cursoMoodle) {
                        $alunoService->salvarOuAtualizarCurso($cursoMoodle, $subdomain);
                    }
                    // Recarrega cursos
                    $cursos = $alunoService->buscarCursosAlunoPorSubdomain($aluno['id'], $subdomain);
                }
            }
        } catch (Exception $cursoError) {
            error_log("Erro ao sincronizar cursos: " . $cursoError->getMessage());
        }
    }

    // 5. Busca boletos (sem sincronização automática por enquanto)
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_boletos,
            COUNT(CASE WHEN status = 'pago' THEN 1 END) as boletos_pagos,
            COUNT(CASE WHEN status = 'pendente' THEN 1 END) as boletos_pendentes,
            COUNT(CASE WHEN status = 'vencido' THEN 1 END) as boletos_vencidos,
            COALESCE(SUM(valor), 0) as valor_total,
            COALESCE(SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END), 0) as valor_pago,
            COALESCE(SUM(CASE WHEN status != 'pago' THEN valor ELSE 0 END), 0) as valor_pendente
        FROM boletos 
        WHERE aluno_id = ?
    ");
    $stmt->execute([$aluno['id']]);
    $estatisticasBoletos = $stmt->fetch();

    // 6. Tenta buscar documentos (opcional - não quebra se falhar)
    $documentosInfo = null;
    try {
        $documentosServicePath = __DIR__ . '/../src/DocumentosService.php';
        if (file_exists($documentosServicePath)) {
            require_once $documentosServicePath;
            if (class_exists('DocumentosService')) {
                $documentosService = new DocumentosService();
                $statusDocs = $documentosService->getStatusDocumentosAluno($aluno['id']);
                $documentosService->atualizarStatusDocumentosAluno($aluno['id']);
                
                $documentosInfo = [
                    'enviados' => $statusDocs['enviados'],
                    'aprovados' => $statusDocs['aprovados'],
                    'percentual' => $statusDocs['percentual_completo']
                ];
            }
        }
    } catch (Exception $docError) {
        error_log("Aviso: Erro ao sincronizar documentos (não crítico): " . $docError->getMessage());
    }

    $response = [
        'success' => true,
        'message' => 'Dados sincronizados com sucesso',
        'timestamp' => date('Y-m-d H:i:s'),
        'aluno' => [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'cpf' => $aluno['cpf'],
            'email' => $aluno['email'],
            'subdomain' => $aluno['subdomain']
        ],
        'estatisticas' => [
            'cursos' => count($cursos),
            'boletos' => $estatisticasBoletos,
            'documentos' => $documentosInfo
        ],
        'sync_info' => [
            'cursos_encontrados' => count($cursos),
            'boletos_encontrados' => $estatisticasBoletos['total_boletos'],
            'documentos_sincronizados' => $documentosInfo ? true : false,
            'metodo' => 'busca_local_com_fallback_moodle',
            'polo' => $subdomain
        ]
    ];

    error_log("Sincronização concluída para {$cpf}: " . 
              "{$response['estatisticas']['cursos']} cursos, " .
              "{$estatisticasBoletos['total_boletos']} boletos");

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Erro na sincronização: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro na sincronização: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'cpf' => $cpf ?? 'N/A',
            'subdomain' => $subdomain ?? 'N/A'
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>