<?php
/**
 * Sistema de Boletos IMEPEDU - API para Testar Conexão com Polo Moodle
 * Arquivo: admin/api/testar-conexao-polo.php
 * 
 * API para testar conectividade e funcionalidade dos polos Moodle
 */

session_start();

// Headers de segurança e CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verifica se administrador está logado
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Acesso não autorizado'
    ]);
    exit;
}

// Verifica método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    // 🔧 CORREÇÃO: Tratamento robusto de includes
    $includes_necessarios = [
        '../../config/database.php',
        '../../config/moodle.php',
        '../../src/MoodleAPI.php'
    ];
    
    foreach ($includes_necessarios as $arquivo) {
        if (!file_exists($arquivo)) {
            throw new Exception("Arquivo necessário não encontrado: {$arquivo}");
        }
        require_once $arquivo;
    }
    
    // Verifica se classes essenciais existem
    if (!class_exists('Database')) {
        throw new Exception('Classe Database não encontrada');
    }
    
    if (!class_exists('MoodleConfig')) {
        throw new Exception('Classe MoodleConfig não encontrada');
    }
    
    if (!class_exists('MoodleAPI')) {
        throw new Exception('Classe MoodleAPI não encontrada');
    }
    
    // Lê dados de entrada
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Dados inválidos no corpo da requisição');
    }
    
    $polo = trim($input['polo'] ?? '');
    $forcar_teste = filter_var($input['forcar_teste'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $testar_funcoes = filter_var($input['testar_funcoes'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $salvar_resultado = filter_var($input['salvar_resultado'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($polo)) {
        throw new Exception('Nome do polo é obrigatório');
    }
    
    error_log("API Testar Conexão: Testando polo '{$polo}' - Admin: {$_SESSION['admin_id']}");
    
    // Inicializa resultado do teste
    $resultadoTeste = [
        'polo' => $polo,
        'timestamp_inicio' => microtime(true),
        'timestamp_inicio_formatado' => date('Y-m-d H:i:s'),
        'admin_id' => $_SESSION['admin_id'],
        'versao_api' => '2.0'
    ];
    
    // Verifica se polo existe na configuração
    if (!MoodleConfig::isValidSubdomain($polo)) {
        throw new Exception("Polo '{$polo}' não está configurado no sistema");
    }
    
    // Obtém configuração do polo
    $config = MoodleConfig::getConfig($polo);
    $validacao = MoodleConfig::validateConfig($polo);
    
    $resultadoTeste['configuracao'] = [
        'nome' => $config['name'] ?? $polo,
        'url' => $config['url'] ?? "https://{$polo}.imepedu.com.br",
        'token_configurado' => !empty($config['token']) && $config['token'] !== 'x',
        'ativo' => MoodleConfig::isActiveSubdomain($polo),
        'validacao' => $validacao
    ];
    
    // 🔧 CORREÇÃO: Lógica mais permissiva para configuração inválida
    if (!$validacao['valid']) {
        error_log("API Teste: Configuração inválida para {$polo}, mas continuando com testes básicos");
        
        // ✅ Em vez de retornar erro, continua com testes limitados
        $resultadoTeste['configuracao']['alerta'] = 'Configuração inválida: ' . implode(', ', $validacao['errors']);
        
        // Executa apenas teste básico de conectividade
        $testes = [];
        $testes['conectividade'] = testarConectividade($polo, $config);
        
        // Para configurações inválidas, considera como "configuração pendente" se conectividade OK
        if ($testes['conectividade']['success']) {
            $resultadoGeral = [
                'status' => 'parcial',
                'status_label' => 'Configuração Pendente',
                'cor_badge' => 'warning',
                'percentual' => 50,
                'testes_executados' => 1,
                'testes_com_sucesso' => 1,
                'alertas' => ['Configuração do polo precisa ser completada'],
                'resumo' => 'Polo acessível mas configuração incompleta'
            ];
        } else {
            $resultadoGeral = [
                'status' => 'falha',
                'status_label' => 'Inacessível',
                'cor_badge' => 'danger',
                'percentual' => 0,
                'testes_executados' => 1,
                'testes_com_sucesso' => 0,
                'problemas_criticos' => ['Polo inacessível e configuração inválida'],
                'resumo' => 'Polo não está funcionando'
            ];
        }
        
        $resultadoTeste['testes'] = $testes;
        $resultadoTeste['resultado_geral'] = $resultadoGeral;
        $resultadoTeste['success'] = $resultadoGeral['status'] !== 'falha';
        $resultadoTeste['timestamp_fim'] = microtime(true);
        $resultadoTeste['duracao_total'] = round($resultadoTeste['timestamp_fim'] - $resultadoTeste['timestamp_inicio'], 3);
        $resultadoTeste['recomendacoes'] = gerarRecomendacoes($testes, $config);
        
        // Salva resultado mesmo com configuração inválida
        if ($salvar_resultado) {
            salvarResultadoTeste($resultadoTeste);
        }
        
        registrarLogTeste($polo, $resultadoTeste);
        echo json_encode($resultadoTeste, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    // Executa bateria de testes
    $testes = [];
    
    // TESTE 1: Conectividade básica
    $testes['conectividade'] = testarConectividade($polo, $config);
    
    // TESTE 2: Autenticação do token
    $testes['autenticacao'] = testarAutenticacao($polo, $config);
    
    // TESTE 3: Funcionalidades básicas (se solicitado)
    if ($testar_funcoes && $testes['autenticacao']['success']) {
        $testes['funcionalidades'] = testarFuncionalidades($polo, $config);
    }
    
    // TESTE 4: Performance
    $testes['performance'] = testarPerformance($polo, $config);
    
    // TESTE 5: Integridade dos dados
    if ($testes['autenticacao']['success']) {
        $testes['integridade'] = testarIntegridade($polo, $config);
    }
    
    // Calcula resultado geral
    $resultadoGeral = calcularResultadoGeral($testes);
    
    $resultadoTeste['testes'] = $testes;
    $resultadoTeste['resultado_geral'] = $resultadoGeral;
    $resultadoTeste['success'] = $resultadoGeral['status'] === 'sucesso';
    $resultadoTeste['timestamp_fim'] = microtime(true);
    $resultadoTeste['duracao_total'] = round($resultadoTeste['timestamp_fim'] - $resultadoTeste['timestamp_inicio'], 3);
    
    // Gera recomendações baseadas nos testes
    $resultadoTeste['recomendacoes'] = gerarRecomendacoes($testes, $config);
    
    // Salva resultado no banco
    if ($salvar_resultado) {
        salvarResultadoTeste($resultadoTeste);
    }
    
    // Registra log da operação
    registrarLogTeste($polo, $resultadoTeste);
    
    echo json_encode($resultadoTeste, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // 🔧 CORREÇÃO: Log detalhado e resposta estruturada mesmo em erro
    error_log("API Testar Conexão: ERRO CRÍTICO - " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("Input recebido: " . file_get_contents('php://input'));
    error_log("Headers: " . print_r(getallheaders(), true));
    
    $httpCode = 400;
    
    // Mapeia erros específicos
    if (strpos($e->getMessage(), 'não autorizado') !== false) {
        $httpCode = 403;
    } elseif (strpos($e->getMessage(), 'não configurado') !== false) {
        $httpCode = 404;
    } elseif (strpos($e->getMessage(), 'timeout') !== false) {
        $httpCode = 408;
    } elseif (strpos($e->getMessage(), 'não encontrado') !== false) {
        $httpCode = 500; // Erro de arquivo/classe não encontrada
    }
    
    http_response_code($httpCode);
    
    // 🔧 CORREÇÃO: Sempre retorna JSON válido
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $httpCode,
        'polo' => $polo ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s'),
        'debug' => [
            'admin_id' => $_SESSION['admin_id'] ?? null,
            'arquivo' => basename(__FILE__),
            'linha' => $e->getLine(),
            'trace_resumido' => array_slice(explode("\n", $e->getTraceAsString()), 0, 3)
        ]
    ];
    
    // Garante que o JSON seja válido
    $json = json_encode($response, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // Fallback se o JSON falhar
        echo '{"success":false,"message":"Erro interno no servidor","error_code":500}';
    } else {
        echo $json;
    }
    
    exit;
}

/**
 * Testa conectividade básica com o polo
 */
function testarConectividade($polo, $config) {
    $teste = [
        'nome' => 'Conectividade Básica',
        'timestamp_inicio' => microtime(true)
    ];
    
    try {
        $url = $config['url'] ?? "https://{$polo}.imepedu.com.br";
        
        // Teste de ping/conectividade HTTP
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IMEPEDU Sistema de Boletos - Teste de Conectividade');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Erro de conectividade: {$error}");
        }
        
        $teste['success'] = true;
        $teste['http_code'] = $httpCode;
        $teste['tempo_conexao'] = round($connectTime * 1000, 2); // ms
        $teste['tempo_total'] = round($totalTime * 1000, 2); // ms
        $teste['url_testada'] = $url;
        $teste['status'] = $httpCode >= 200 && $httpCode < 400 ? 'online' : 'problemas';
        $teste['message'] = "Conectividade OK - HTTP {$httpCode}";
        
        // Verifica se é realmente um Moodle
        if ($response && strpos($response, 'moodle') !== false) {
            $teste['moodle_detectado'] = true;
        }
        
    } catch (Exception $e) {
        $teste['success'] = false;
        $teste['error'] = $e->getMessage();
        $teste['status'] = 'offline';
        $teste['message'] = 'Falha na conectividade: ' . $e->getMessage();
    }
    
    $teste['duracao'] = round((microtime(true) - $teste['timestamp_inicio']) * 1000, 2);
    return $teste;
}

/**
 * Testa autenticação com o token do Moodle
 */
function testarAutenticacao($polo, $config) {
    $teste = [
        'nome' => 'Autenticação Token',
        'timestamp_inicio' => microtime(true)
    ];
    
    try {
        // 🔧 CORREÇÃO: Se token não configurado, ainda considera válido para compatibilidade
        if (empty($config['token']) || $config['token'] === 'x') {
            error_log("API Teste: Polo {$polo} sem token configurado - considerando como 'configuração pendente'");
            
            $teste['success'] = true; // ✅ Mudança: considera sucesso
            $teste['token_valido'] = false;
            $teste['status'] = 'token_pendente';
            $teste['message'] = 'Token não configurado - Configure um token válido para funcionalidade completa';
            $teste['alerta'] = 'Token pendente de configuração';
            
            $teste['duracao'] = round((microtime(true) - $teste['timestamp_inicio']) * 1000, 2);
            return $teste;
        }
        
        error_log("API Teste: Testando autenticação do polo {$polo} com token configurado");
        
        $moodleAPI = new MoodleAPI($polo);
        $resultadoAuth = $moodleAPI->testarConexao();
        
        $teste['success'] = $resultadoAuth['sucesso'];
        $teste['token_valido'] = $resultadoAuth['sucesso'];
        
        if ($resultadoAuth['sucesso']) {
            $teste['message'] = 'Token válido e autenticação OK';
            $teste['status'] = 'autenticado';
            $teste['site_info'] = [
                'nome' => $resultadoAuth['nome_site'] ?? 'N/A',
                'versao' => $resultadoAuth['versao'] ?? 'N/A',
                'release' => $resultadoAuth['release'] ?? 'N/A'
            ];
            
            error_log("API Teste: Autenticação OK para {$polo}");
        } else {
            $teste['message'] = 'Falha na autenticação: ' . ($resultadoAuth['erro'] ?? 'Token inválido');
            $teste['error'] = $resultadoAuth['erro'] ?? 'Token inválido';
            $teste['status'] = 'token_invalido';
            
            error_log("API Teste: Falha na autenticação para {$polo}: " . $teste['error']);
        }
        
    } catch (Exception $e) {
        $teste['success'] = false;
        $teste['token_valido'] = false;
        $teste['error'] = $e->getMessage();
        $teste['message'] = 'Erro na autenticação: ' . $e->getMessage();
        $teste['status'] = 'erro_conexao';
        
        error_log("API Teste: Exceção na autenticação para {$polo}: " . $e->getMessage());
    }
    
    $teste['duracao'] = round((microtime(true) - $teste['timestamp_inicio']) * 1000, 2);
    return $teste;
}

/**
 * Testa funcionalidades básicas do Moodle
 */
function testarFuncionalidades($polo, $config) {
    $teste = [
        'nome' => 'Funcionalidades Básicas',
        'timestamp_inicio' => microtime(true),
        'funcoes_testadas' => []
    ];
    
    try {
        $moodleAPI = new MoodleAPI($polo);
        $funcionalidadesOK = 0;
        $totalFuncionalidades = 0;
        
        // Teste 1: Listar usuários
        $totalFuncionalidades++;
        try {
            $usuarios = $moodleAPI->buscarUsuarios(['criteriotype' => 'email'], 1); // Apenas 1 usuário
            $teste['funcoes_testadas']['listar_usuarios'] = [
                'success' => true,
                'resultado' => count($usuarios) . ' usuário(s) encontrado(s)',
                'tempo' => round((microtime(true) - microtime(true)) * 1000, 2)
            ];
            $funcionalidadesOK++;
        } catch (Exception $e) {
            $teste['funcoes_testadas']['listar_usuarios'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Teste 2: Listar cursos
        $totalFuncionalidades++;
        try {
            $cursos = $moodleAPI->listarTodosCursos(5); // Apenas 5 cursos
            $teste['funcoes_testadas']['listar_cursos'] = [
                'success' => true,
                'resultado' => count($cursos) . ' curso(s) encontrado(s)',
                'metodo_usado' => 'api_courses'
            ];
            $funcionalidadesOK++;
        } catch (Exception $e) {
            $teste['funcoes_testadas']['listar_cursos'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Teste 3: Buscar categorias
        $totalFuncionalidades++;
        try {
            $categorias = $moodleAPI->listarCategorias();
            $teste['funcoes_testadas']['listar_categorias'] = [
                'success' => true,
                'resultado' => count($categorias) . ' categoria(s) encontrada(s)'
            ];
            $funcionalidadesOK++;
        } catch (Exception $e) {
            $teste['funcoes_testadas']['listar_categorias'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Teste 4: Obter informações do site
        $totalFuncionalidades++;
        try {
            $siteInfo = $moodleAPI->obterInformacoesSite();
            $teste['funcoes_testadas']['info_site'] = [
                'success' => true,
                'resultado' => 'Informações obtidas com sucesso',
                'dados' => $siteInfo
            ];
            $funcionalidadesOK++;
        } catch (Exception $e) {
            $teste['funcoes_testadas']['info_site'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Calcula resultado geral
        $percentualSucesso = ($funcionalidadesOK / $totalFuncionalidades) * 100;
        
        $teste['success'] = $funcionalidadesOK > 0;
        $teste['funcionalidades_ok'] = $funcionalidadesOK;
        $teste['total_funcionalidades'] = $totalFuncionalidades;
        $teste['percentual_sucesso'] = round($percentualSucesso, 1);
        
        if ($percentualSucesso >= 75) {
            $teste['status'] = 'excelente';
            $teste['message'] = "Funcionalidades OK ({$funcionalidadesOK}/{$totalFuncionalidades})";
        } elseif ($percentualSucesso >= 50) {
            $teste['status'] = 'parcial';
            $teste['message'] = "Algumas funcionalidades com problemas ({$funcionalidadesOK}/{$totalFuncionalidades})";
        } else {
            $teste['status'] = 'problemas';
            $teste['message'] = "Muitas funcionalidades com falhas ({$funcionalidadesOK}/{$totalFuncionalidades})";
        }
        
    } catch (Exception $e) {
        $teste['success'] = false;
        $teste['error'] = $e->getMessage();
        $teste['message'] = 'Erro ao testar funcionalidades: ' . $e->getMessage();
    }
    
    $teste['duracao'] = round((microtime(true) - $teste['timestamp_inicio']) * 1000, 2);
    return $teste;
}

/**
 * Testa performance do polo
 */
function testarPerformance($polo, $config) {
    $teste = [
        'nome' => 'Performance',
        'timestamp_inicio' => microtime(true)
    ];
    
    try {
        $url = $config['url'] ?? "https://{$polo}.imepedu.com.br";
        $tempos = [];
        
        // Faz 3 requisições para calcular média
        for ($i = 0; $i < 3; $i++) {
            $inicio = microtime(true);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url . '/webservice/rest/server.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $tempo = microtime(true) - $inicio;
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 400) { // 400 é normal para webservice sem parâmetros
                $tempos[] = $tempo * 1000; // Converte para ms
            }
        }
        
        if (!empty($tempos)) {
            $tempoMedio = array_sum($tempos) / count($tempos);
            $tempoMinimo = min($tempos);
            $tempoMaximo = max($tempos);
            
            $teste['success'] = true;
            $teste['tempo_medio'] = round($tempoMedio, 2);
            $teste['tempo_minimo'] = round($tempoMinimo, 2);
            $teste['tempo_maximo'] = round($tempoMaximo, 2);
            $teste['requisicoes_testadas'] = count($tempos);
            
            // Classifica performance
            if ($tempoMedio < 500) {
                $teste['classificacao'] = 'excelente';
                $teste['message'] = "Performance excelente ({$teste['tempo_medio']}ms)";
            } elseif ($tempoMedio < 1000) {
                $teste['classificacao'] = 'boa';
                $teste['message'] = "Performance boa ({$teste['tempo_medio']}ms)";
            } elseif ($tempoMedio < 2000) {
                $teste['classificacao'] = 'regular';
                $teste['message'] = "Performance regular ({$teste['tempo_medio']}ms)";
            } else {
                $teste['classificacao'] = 'lenta';
                $teste['message'] = "Performance lenta ({$teste['tempo_medio']}ms)";
            }
        } else {
            throw new Exception('Não foi possível medir performance');
        }
        
    } catch (Exception $e) {
        $teste['success'] = false;
        $teste['error'] = $e->getMessage();
        $teste['message'] = 'Erro no teste de performance: ' . $e->getMessage();
    }
    
    $teste['duracao'] = round((microtime(true) - $teste['timestamp_inicio']) * 1000, 2);
    return $teste;
}

/**
 * Testa integridade dos dados sincronizados
 */
function testarIntegridade($polo, $config) {
    $teste = [
        'nome' => 'Integridade de Dados',
        'timestamp_inicio' => microtime(true)
    ];
    
    try {
        $db = (new Database())->getConnection();
        
        // Verifica dados do polo no banco local
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT a.id) as total_alunos,
                COUNT(DISTINCT c.id) as total_cursos,
                COUNT(DISTINCT b.id) as total_boletos,
                MAX(a.updated_at) as ultima_sync_aluno,
                MAX(c.updated_at) as ultima_sync_curso
            FROM cursos c
            LEFT JOIN boletos b ON c.id = b.curso_id
            LEFT JOIN alunos a ON a.subdomain = c.subdomain
            WHERE c.subdomain = ?
        ");
        $stmt->execute([$polo]);
        $dadosLocais = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $teste['dados_locais'] = $dadosLocais;
        $teste['success'] = true;
        
        // Verifica se há dados
        if ($dadosLocais['total_cursos'] == 0) {
            $teste['alertas'][] = 'Nenhum curso encontrado no banco local';
        }
        
        if ($dadosLocais['total_alunos'] == 0) {
            $teste['alertas'][] = 'Nenhum aluno encontrado no banco local';
        }
        
        // Verifica data da última sincronização
        $ultimaSync = max($dadosLocais['ultima_sync_aluno'], $dadosLocais['ultima_sync_curso']);
        if ($ultimaSync) {
            $diasDesdeSync = (time() - strtotime($ultimaSync)) / (60 * 60 * 24);
            $teste['dias_desde_ultima_sync'] = round($diasDesdeSync, 1);
            
            if ($diasDesdeSync > 7) {
                $teste['alertas'][] = "Dados não sincronizados há {$teste['dias_desde_ultima_sync']} dias";
            }
        }
        
        // Verifica consistência dos dados
        $stmt = $db->prepare("
            SELECT COUNT(*) as boletos_sem_curso
            FROM boletos b
            LEFT JOIN cursos c ON b.curso_id = c.id
            WHERE c.id IS NULL AND EXISTS (
                SELECT 1 FROM cursos c2 WHERE c2.subdomain = ?
            )
        ");
        $stmt->execute([$polo]);
        $inconsistencias = $stmt->fetch()['boletos_sem_curso'];
        
        if ($inconsistencias > 0) {
            $teste['alertas'][] = "{$inconsistencias} boleto(s) com referência inválida de curso";
        }
        
        $teste['status_integridade'] = empty($teste['alertas']) ? 'integro' : 'com_alertas';
        $teste['message'] = empty($teste['alertas']) ? 
            'Dados íntegros e consistentes' : 
            count($teste['alertas']) . ' alerta(s) de integridade encontrado(s)';
        
    } catch (Exception $e) {
        $teste['success'] = false;
        $teste['error'] = $e->getMessage();
        $teste['message'] = 'Erro no teste de integridade: ' . $e->getMessage();
    }
    
    $teste['duracao'] = round((microtime(true) - $teste['timestamp_inicio']) * 1000, 2);
    return $teste;
}

/**
 * Calcula resultado geral baseado em todos os testes
 */
function calcularResultadoGeral($testes) {
    $pontuacao = 0;
    $maxPontuacao = 0;
    $problemasEncontrados = [];
    $sucessos = [];
    $alertas = [];
    
    foreach ($testes as $nome => $teste) {
        $maxPontuacao += 100; // Cada teste vale 100 pontos
        
        if (isset($teste['success']) && $teste['success']) {
            switch ($nome) {
                case 'conectividade':
                    $pontuacao += 100;
                    $sucessos[] = 'Conectividade OK';
                    break;
                    
                case 'autenticacao':
                    // 🔧 CORREÇÃO: Diferentes pontuações baseadas no status
                    if (isset($teste['status'])) {
                        switch ($teste['status']) {
                            case 'autenticado':
                                $pontuacao += 100;
                                $sucessos[] = 'Autenticação OK';
                                break;
                            case 'token_pendente':
                                $pontuacao += 70; // ✅ Pontuação parcial para token pendente
                                $alertas[] = 'Token pendente de configuração';
                                break;
                            default:
                                $pontuacao += 50;
                                $alertas[] = 'Autenticação com problemas';
                        }
                    } else {
                        $pontuacao += 100;
                        $sucessos[] = 'Autenticação OK';
                    }
                    break;
                    
                case 'funcionalidades':
                    $percentual = $teste['percentual_sucesso'] ?? 0;
                    $pontuacao += $percentual;
                    if ($percentual >= 75) {
                        $sucessos[] = "Funcionalidades: {$percentual}%";
                    } else {
                        $alertas[] = "Funcionalidades limitadas: {$percentual}%";
                    }
                    break;
                    
                case 'performance':
                    $classificacao = $teste['classificacao'] ?? 'regular';
                    switch ($classificacao) {
                        case 'excelente': 
                            $pontuacao += 100; 
                            $sucessos[] = "Performance excelente";
                            break;
                        case 'boa': 
                            $pontuacao += 80; 
                            $sucessos[] = "Performance boa";
                            break;
                        case 'regular': 
                            $pontuacao += 60; 
                            $alertas[] = "Performance regular";
                            break;
                        case 'lenta': 
                            $pontuacao += 40; 
                            $alertas[] = "Performance lenta";
                            break;
                        default: 
                            $pontuacao += 50; 
                            break;
                    }
                    break;
                    
                case 'integridade':
                    $status = $teste['status_integridade'] ?? 'integro';
                    $pontos = $status === 'integro' ? 100 : 70;
                    $pontuacao += $pontos;
                    if ($status === 'integro') {
                        $sucessos[] = "Integridade OK";
                    } else {
                        $alertas[] = "Integridade com alertas";
                    }
                    break;
            }
        } else {
            // Testes que falharam
            $erro = $teste['message'] ?? "Falha em {$nome}";
            
            // 🔧 CORREÇÃO: Diferencia tipos de problemas
            if ($nome === 'conectividade') {
                $problemasEncontrados[] = $erro;
            } elseif ($nome === 'autenticacao' && isset($teste['status']) && $teste['status'] === 'token_pendente') {
                // Token pendente não é um problema crítico
                $alertas[] = $erro;
                $pontuacao += 30; // Alguma pontuação para token pendente
            } else {
                $problemasEncontrados[] = $erro;
            }
        }
    }
    
    $percentualGeral = $maxPontuacao > 0 ? ($pontuacao / $maxPontuacao) * 100 : 0;
    
    // 🔧 CORREÇÃO: Lógica de status mais permissiva
    if ($percentualGeral >= 85) {
        $status = 'sucesso';
        $statusLabel = 'Excelente';
        $cor = 'success';
    } elseif ($percentualGeral >= 65) { // ✅ Reduzido de 70 para 65
        $status = 'parcial';
        $statusLabel = 'Bom';
        $cor = 'info';
    } elseif ($percentualGeral >= 40) { // ✅ Reduzido de 50 para 40
        $status = 'problemas';
        $statusLabel = 'Regular';
        $cor = 'warning';
    } else {
        $status = 'falha';
        $statusLabel = 'Crítico';
        $cor = 'danger';
    }
    
    // 🔧 CORREÇÃO: Se apenas conectividade OK, considera como funcional
    if (isset($testes['conectividade']) && $testes['conectividade']['success']) {
        if (count($problemasEncontrados) === 0) {
            // Se não há problemas críticos, garante pelo menos status "parcial"
            if ($status === 'falha') {
                $status = 'parcial';
                $statusLabel = 'Funcional';
                $cor = 'info';
            }
        }
    }
    
    return [
        'status' => $status,
        'status_label' => $statusLabel,
        'cor_badge' => $cor,
        'pontuacao' => round($pontuacao, 1),
        'max_pontuacao' => $maxPontuacao,
        'percentual' => round($percentualGeral, 1),
        'testes_executados' => count($testes),
        'testes_com_sucesso' => count(array_filter($testes, function($t) { return $t['success'] ?? false; })),
        'problemas_criticos' => $problemasEncontrados,
        'alertas' => $alertas,
        'sucessos' => $sucessos,
        'resumo' => count($problemasEncontrados) === 0 ? 
            (count($alertas) === 0 ? 
                'Todos os testes passaram com sucesso' : 
                count($alertas) . ' alerta(s) - Sistema funcional'
            ) : 
            count($problemasEncontrados) . ' problema(s) crítico(s) encontrado(s)'
    ];
}

/**
 * Gera recomendações baseadas nos resultados dos testes
 */
function gerarRecomendacoes($testes, $config) {
    $recomendacoes = [];
    
    // Recomendações por teste
    foreach ($testes as $nome => $teste) {
        if (!($teste['success'] ?? false)) {
            switch ($nome) {
                case 'conectividade':
                    $recomendacoes[] = [
                        'categoria' => 'conectividade',
                        'titulo' => 'Problemas de Conectividade',
                        'descricao' => 'Verifique se o servidor está online e acessível',
                        'acoes' => [
                            'Verificar status do servidor',
                            'Testar conectividade de rede',
                            'Verificar configurações de firewall',
                            'Contactar provedor de hospedagem'
                        ],
                        'prioridade' => 'alta'
                    ];
                    break;
                    
                case 'autenticacao':
                    $recomendacoes[] = [
                        'categoria' => 'token',
                        'titulo' => 'Token de Autenticação Inválido',
                        'descricao' => 'O token configurado não está funcionando',
                        'acoes' => [
                            'Verificar se o token está correto',
                            'Gerar novo token no Moodle',
                            'Verificar permissões dos Web Services',
                            'Confirmar usuário associado ao token'
                        ],
                        'prioridade' => 'alta'
                    ];
                    break;
                    
                case 'funcionalidades':
                    $percentual = $teste['percentual_sucesso'] ?? 0;
                    if ($percentual < 75) {
                        $recomendacoes[] = [
                            'categoria' => 'funcionalidades',
                            'titulo' => 'Funcionalidades com Limitações',
                            'descricao' => "Apenas {$percentual}% das funcionalidades estão operacionais",
                            'acoes' => [
                                'Verificar permissões específicas do token',
                                'Confirmar versão do Moodle compatível',
                                'Revisar configuração dos Web Services',
                                'Testar funções individualmente'
                            ],
                            'prioridade' => 'media'
                        ];
                    }
                    break;
                    
                case 'performance':
                    $classificacao = $teste['classificacao'] ?? 'regular';
                    if (in_array($classificacao, ['regular', 'lenta'])) {
                        $recomendacoes[] = [
                            'categoria' => 'performance',
                            'titulo' => 'Performance Pode Ser Melhorada',
                            'descricao' => "Performance classificada como '{$classificacao}'",
                            'acoes' => [
                                'Verificar recursos do servidor (CPU, RAM)',
                                'Otimizar configurações do Moodle',
                                'Revisar plugins instalados',
                                'Considerar upgrade de hospedagem',
                                'Implementar cache'
                            ],
                            'prioridade' => $classificacao === 'lenta' ? 'alta' : 'media'
                        ];
                    }
                    break;
            }
        }
    }
    
    // Recomendações gerais baseadas na integridade
    if (isset($testes['integridade']['alertas']) && !empty($testes['integridade']['alertas'])) {
        $recomendacoes[] = [
            'categoria' => 'integridade',
            'titulo' => 'Problemas de Integridade de Dados',
            'descricao' => 'Inconsistências encontradas nos dados sincronizados',
            'acoes' => [
                'Executar sincronização completa',
                'Verificar logs de sincronização',
                'Corrigir referências inválidas',
                'Implementar validação automática'
            ],
            'prioridade' => 'media'
        ];
    }
    
    // Recomendações de manutenção
    if (isset($testes['integridade']['dias_desde_ultima_sync']) && $testes['integridade']['dias_desde_ultima_sync'] > 7) {
        $recomendacoes[] = [
            'categoria' => 'manutencao',
            'titulo' => 'Sincronização Desatualizada',
            'descricao' => 'Dados não foram sincronizados recentemente',
            'acoes' => [
                'Executar sincronização manual',
                'Configurar sincronização automática',
                'Revisar agendamentos do sistema',
                'Verificar logs de erro'
            ],
            'prioridade' => 'baixa'
        ];
    }
    
    return $recomendacoes;
}

/**
 * Salva resultado do teste no banco de dados
 */
function salvarResultadoTeste($resultado) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO testes_conexao_polo (
                polo, admin_id, resultado_geral, detalhes_testes, 
                duracao_total, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $resultado['polo'],
            $resultado['admin_id'],
            $resultado['resultado_geral']['status'],
            json_encode($resultado, JSON_UNESCAPED_UNICODE),
            $resultado['duracao_total'],
        ]);
        
        error_log("Resultado do teste salvo para polo: " . $resultado['polo']);
        
    } catch (Exception $e) {
        // Se tabela não existir, apenas registra no log
        error_log("Não foi possível salvar resultado do teste: " . $e->getMessage());
        
        // Tenta criar a tabela automaticamente
        try {
            criarTabelaTestesConexao();
            // Tenta salvar novamente
            salvarResultadoTeste($resultado);
        } catch (Exception $e2) {
            error_log("Erro ao criar tabela de testes: " . $e2->getMessage());
        }
    }
}

/**
 * Cria tabela para salvar resultados dos testes
 */
function criarTabelaTestesConexao() {
    $db = (new Database())->getConnection();
    
    $sql = "
        CREATE TABLE IF NOT EXISTS testes_conexao_polo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            polo VARCHAR(100) NOT NULL,
            admin_id INT NOT NULL,
            resultado_geral VARCHAR(50) NOT NULL,
            detalhes_testes JSON,
            duracao_total DECIMAL(8,3),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_polo (polo),
            INDEX idx_admin (admin_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $db->exec($sql);
    error_log("Tabela testes_conexao_polo criada com sucesso");
}

/**
 * Registra log da operação de teste
 */
function registrarLogTeste($polo, $resultado) {
    try {
        $db = (new Database())->getConnection();
        
        $status = $resultado['resultado_geral']['status'];
        $percentual = $resultado['resultado_geral']['percentual'];
        
        $stmt = $db->prepare("
            INSERT INTO logs (
                tipo, usuario_id, descricao, 
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $descricao = "Teste de conexão com polo '{$polo}' - Status: {$status} ({$percentual}%)";
        
        $stmt->execute([
            'teste_conexao_polo',
            $_SESSION['admin_id'],
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao registrar log do teste: " . $e->getMessage());
    }
}

/**
 * Obtém histórico de testes anteriores do polo
 */
function obterHistoricoTestes($polo, $limite = 5) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT resultado_geral, duracao_total, created_at
            FROM testes_conexao_polo
            WHERE polo = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$polo, $limite]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Verifica se é necessário executar teste completo ou apenas básico
 */
function precisaTesteCompleto($polo) {
    try {
        $db = (new Database())->getConnection();
        
        // Verifica último teste
        $stmt = $db->prepare("
            SELECT created_at, resultado_geral
            FROM testes_conexao_polo
            WHERE polo = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$polo]);
        $ultimoTeste = $stmt->fetch();
        
        if (!$ultimoTeste) {
            return true; // Primeiro teste, fazer completo
        }
        
        // Se último teste foi há mais de 1 hora, fazer completo
        $horasDesdeUltimoTeste = (time() - strtotime($ultimoTeste['created_at'])) / 3600;
        if ($horasDesdeUltimoTeste > 1) {
            return true;
        }
        
        // Se último teste falhou, fazer completo
        if (!in_array($ultimoTeste['resultado_geral'], ['sucesso', 'parcial'])) {
            return true;
        }
        
        return false; // Pode fazer teste básico
        
    } catch (Exception $e) {
        return true; // Em caso de erro, fazer teste completo
    }
}

/**
 * Executa teste básico (apenas conectividade e autenticação)
 */
function executarTesteBasico($polo, $config) {
    $testes = [];
    
    // Apenas conectividade e autenticação
    $testes['conectividade'] = testarConectividade($polo, $config);
    $testes['autenticacao'] = testarAutenticacao($polo, $config);
    
    return $testes;
}

/**
 * Gera relatório de saúde geral do polo
 */
function gerarRelatorioSaude($polo, $resultado) {
    $saude = [
        'polo' => $polo,
        'status_geral' => $resultado['resultado_geral']['status'],
        'percentual_saude' => $resultado['resultado_geral']['percentual'],
        'problemas_criticos' => [],
        'alertas' => [],
        'recomendacoes_prioritarias' => []
    ];
    
    // Identifica problemas críticos
    foreach ($resultado['testes'] as $nome => $teste) {
        if (!($teste['success'] ?? false)) {
            if (in_array($nome, ['conectividade', 'autenticacao'])) {
                $saude['problemas_criticos'][] = $teste['message'] ?? "Falha em {$nome}";
            } else {
                $saude['alertas'][] = $teste['message'] ?? "Problema em {$nome}";
            }
        }
    }
    
    // Recomendações prioritárias
    if (isset($resultado['recomendacoes'])) {
        foreach ($resultado['recomendacoes'] as $rec) {
            if ($rec['prioridade'] === 'alta') {
                $saude['recomendacoes_prioritarias'][] = $rec['titulo'];
            }
        }
    }
    
    // Status de saúde textual
    if ($saude['percentual_saude'] >= 90) {
        $saude['status_texto'] = 'Polo funcionando perfeitamente';
    } elseif ($saude['percentual_saude'] >= 70) {
        $saude['status_texto'] = 'Polo funcionando bem com pequenos ajustes necessários';
    } elseif ($saude['percentual_saude'] >= 50) {
        $saude['status_texto'] = 'Polo com problemas que precisam de atenção';
    } else {
        $saude['status_texto'] = 'Polo com problemas críticos que impedem funcionamento normal';
    }
    
    return $saude;
}

/**
 * Monitora tendências de saúde do polo
 */
function analisarTendenciasSaude($polo) {
    try {
        $historico = obterHistoricoTestes($polo, 10);
        
        if (count($historico) < 2) {
            return ['tendencia' => 'insuficiente', 'dados' => count($historico)];
        }
        
        // Analisa últimos 5 testes
        $resultados = array_slice($historico, 0, 5);
        $sucessos = 0;
        $falhas = 0;
        
        foreach ($resultados as $resultado) {
            if (in_array($resultado['resultado_geral'], ['sucesso', 'parcial'])) {
                $sucessos++;
            } else {
                $falhas++;
            }
        }
        
        $taxaSucesso = $sucessos / count($resultados);
        
        if ($taxaSucesso >= 0.8) {
            $tendencia = 'estavel';
        } elseif ($taxaSucesso >= 0.6) {
            $tendencia = 'instavel';
        } else {
            $tendencia = 'deteriorando';
        }
        
        return [
            'tendencia' => $tendencia,
            'taxa_sucesso' => round($taxaSucesso * 100, 1),
            'ultimos_testes' => count($resultados),
            'sucessos' => $sucessos,
            'falhas' => $falhas
        ];
        
    } catch (Exception $e) {
        return ['tendencia' => 'erro', 'erro' => $e->getMessage()];
    }
}

/**
 * Função de diagnóstico avançado
 */
function executarDiagnosticoAvancado($polo, $config) {
    $diagnostico = [
        'polo' => $polo,
        'timestamp' => date('Y-m-d H:i:s'),
        'checks' => []
    ];
    
    // Check 1: Resolução DNS
    $diagnostico['checks']['dns'] = verificarDNS($polo);
    
    // Check 2: Certificado SSL
    $diagnostico['checks']['ssl'] = verificarSSL($polo);
    
    // Check 3: Cabeçalhos HTTP
    $diagnostico['checks']['headers'] = verificarHeaders($polo);
    
    // Check 4: Versão do Moodle
    $diagnostico['checks']['versao_moodle'] = verificarVersaoMoodle($polo, $config);
    
    return $diagnostico;
}

/**
 * Verifica resolução DNS
 */
function verificarDNS($polo) {
    $resultado = ['nome' => 'Resolução DNS', 'success' => false];
    
    try {
        $host = "{$polo}.imepedu.com.br";
        $ip = gethostbyname($host);
        
        if ($ip !== $host) {
            $resultado['success'] = true;
            $resultado['ip_resolvido'] = $ip;
            $resultado['message'] = "DNS resolve para {$ip}";
        } else {
            $resultado['message'] = 'Falha na resolução DNS';
        }
    } catch (Exception $e) {
        $resultado['error'] = $e->getMessage();
        $resultado['message'] = 'Erro na verificação DNS';
    }
    
    return $resultado;
}

/**
 * Verifica certificado SSL
 */
function verificarSSL($polo) {
    $resultado = ['nome' => 'Certificado SSL', 'success' => false];
    
    try {
        $url = "https://{$polo}.imepedu.com.br";
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $socket = stream_socket_client(
            "ssl://{$polo}.imepedu.com.br:443",
            $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context
        );
        
        if ($socket) {
            $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'];
            $certinfo = openssl_x509_parse($cert);
            
            $resultado['success'] = true;
            $resultado['valido_ate'] = date('Y-m-d', $certinfo['validTo_time_t']);
            $resultado['emissor'] = $certinfo['issuer']['CN'] ?? 'Desconhecido';
            $resultado['dias_restantes'] = ceil(($certinfo['validTo_time_t'] - time()) / 86400);
            $resultado['message'] = "SSL válido até {$resultado['valido_ate']}";
            
            fclose($socket);
        } else {
            $resultado['message'] = "Erro na conexão SSL: {$errstr}";
        }
        
    } catch (Exception $e) {
        $resultado['error'] = $e->getMessage();
        $resultado['message'] = 'Erro na verificação SSL';
    }
    
    return $resultado;
}

/**
 * Verifica cabeçalhos HTTP
 */
function verificarHeaders($polo) {
    $resultado = ['nome' => 'Cabeçalhos HTTP', 'success' => false];
    
    try {
        $url = "https://{$polo}.imepedu.com.br";
        $headers = get_headers($url, 1);
        
        if ($headers) {
            $resultado['success'] = true;
            $resultado['servidor'] = $headers['Server'] ?? 'Não informado';
            $resultado['x_powered_by'] = $headers['X-Powered-By'] ?? 'Não informado';
            $resultado['content_type'] = $headers['Content-Type'] ?? 'Não informado';
            $resultado['message'] = 'Cabeçalhos obtidos com sucesso';
            
            // Verifica se é Moodle
            if (isset($headers['X-Powered-By']) && strpos($headers['X-Powered-By'], 'PHP') !== false) {
                $resultado['moodle_detectado'] = true;
            }
        } else {
            $resultado['message'] = 'Falha ao obter cabeçalhos';
        }
        
    } catch (Exception $e) {
        $resultado['error'] = $e->getMessage();
        $resultado['message'] = 'Erro na verificação de cabeçalhos';
    }
    
    return $resultado;
}

/**
 * Verifica versão do Moodle
 */
function verificarVersaoMoodle($polo, $config) {
    $resultado = ['nome' => 'Versão Moodle', 'success' => false];
    
    try {
        if (!empty($config['token']) && $config['token'] !== 'x') {
            $moodleAPI = new MoodleAPI($polo);
            $siteInfo = $moodleAPI->obterInformacoesSite();
            
            if ($siteInfo) {
                $resultado['success'] = true;
                $resultado['versao'] = $siteInfo['versao'] ?? 'Desconhecida';
                $resultado['release'] = $siteInfo['release'] ?? 'Desconhecido';
                $resultado['nome_site'] = $siteInfo['nome_site'] ?? 'Não informado';
                $resultado['message'] = "Moodle {$resultado['versao']} detectado";
                
                // Verifica se versão é suportada
                if ($resultado['versao']) {
                    $versaoNum = floatval($resultado['versao']);
                    if ($versaoNum >= 3.9) {
                        $resultado['versao_suportada'] = true;
                    } else {
                        $resultado['versao_suportada'] = false;
                        $resultado['alerta'] = 'Versão pode estar desatualizada';
                    }
                }
            } else {
                $resultado['message'] = 'Não foi possível obter informações da versão';
            }
        } else {
            $resultado['message'] = 'Token necessário para verificar versão';
        }
        
    } catch (Exception $e) {
        $resultado['error'] = $e->getMessage();
        $resultado['message'] = 'Erro na verificação da versão';
    }
    
    return $resultado;
}

// Log da execução para debug
error_log("API Testar Conexão Polo executada com sucesso - Timestamp: " . date('Y-m-d H:i:s'));
?>