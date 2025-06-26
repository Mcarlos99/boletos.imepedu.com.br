<?php
/**
 * Sistema de Boletos IMED - API do Moodle
 * Arquivo: src/MoodleAPI.php
 * 
 * Classe responsável pela comunicação com as APIs dos diferentes polos Moodle
 */

require_once __DIR__ . '/../config/moodle.php';

class MoodleAPI {
    
    private $subdomain;
    private $token;
    private $baseUrl;
    private $timeout;
    private $maxRetries;
    private $retryDelay;
    private $userAgent;
    private $cache = [];
    
    /**
     * Construtor
     */
    public function __construct($subdomain) {
        $this->subdomain = $this->cleanSubdomain($subdomain);
        $this->token = MoodleConfig::getToken($this->subdomain);
        $this->baseUrl = MoodleConfig::getMoodleUrl($this->subdomain);
        
        // Configurações globais
        $this->timeout = MoodleConfig::getGlobalConfig('timeout');
        $this->maxRetries = MoodleConfig::getGlobalConfig('max_retries');
        $this->retryDelay = MoodleConfig::getGlobalConfig('retry_delay');
        $this->userAgent = MoodleConfig::getGlobalConfig('user_agent');
        
        if (!$this->token) {
            throw new Exception("Token não encontrado para o subdomínio: {$this->subdomain}");
        }
        
        if (!MoodleConfig::isActiveSubdomain($this->subdomain)) {
            throw new Exception("Polo não está ativo: {$this->subdomain}");
        }
    }
    
    /**
     * Remove protocolo do subdomínio
     */
    private function cleanSubdomain($subdomain) {
        return str_replace(['http://', 'https://'], '', $subdomain);
    }
    
    /**
     * Busca aluno por CPF
     */
    public function buscarAlunoPorCPF($cpf) {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF deve conter 11 dígitos");
        }
        
        // Cache key
        $cacheKey = "aluno_cpf_{$cpf}_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            error_log("MoodleAPI: Iniciando busca por CPF {$cpf} no subdomínio {$this->subdomain}");
            
            // Busca por CPF no campo idnumber
            $users = $this->callMoodleFunction('core_user_get_users', [
                'criteria' => [
                    [
                        'key' => 'idnumber',
                        'value' => $cpf
                    ]
                ]
            ]);
            
            error_log("MoodleAPI: Busca por idnumber retornou " . count($users['users'] ?? []) . " usuários");
            
            if (empty($users['users'])) {
                error_log("MoodleAPI: Tentando busca por username");
                // Tenta buscar por username se não encontrou por idnumber
                $users = $this->callMoodleFunction('core_user_get_users', [
                    'criteria' => [
                        [
                            'key' => 'username',
                            'value' => $cpf
                        ]
                    ]
                ]);
                
                error_log("MoodleAPI: Busca por username retornou " . count($users['users'] ?? []) . " usuários");
            }
            
            if (!empty($users['users'])) {
                $user = $users['users'][0];
                error_log("MoodleAPI: Usuário encontrado - ID: {$user['id']}, Nome: {$user['fullname']}");
                
                // Busca cursos do aluno
                error_log("MoodleAPI: Buscando cursos do usuário {$user['id']}");
                $cursos = $this->buscarCursosAluno($user['id']);
                error_log("MoodleAPI: Encontrados " . count($cursos) . " cursos");
                
                $dadosAluno = [
                    'nome' => $user['fullname'],
                    'cpf' => $cpf,
                    'email' => $user['email'],
                    'moodle_user_id' => $user['id'],
                    'subdomain' => $this->subdomain,
                    'cursos' => $cursos,
                    'ultimo_acesso' => isset($user['lastaccess']) ? date('Y-m-d H:i:s', $user['lastaccess']) : null,
                    'profile_image' => $user['profileimageurl'] ?? null,
                    'city' => $user['city'] ?? null,
                    'country' => $user['country'] ?? null
                ];
                
                // Cache por 5 minutos
                $this->cache[$cacheKey] = $dadosAluno;
                
                error_log("MoodleAPI: Dados do aluno preparados com sucesso");
                return $dadosAluno;
            }
            
            error_log("MoodleAPI: Nenhum usuário encontrado para CPF {$cpf}");
            return null;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: Erro na busca por CPF {$cpf}: " . $e->getMessage());
            error_log("MoodleAPI: Stack trace: " . $e->getTraceAsString());
            $this->logError("Erro ao buscar aluno por CPF: {$cpf}", $e);
            throw new Exception("Erro ao buscar dados do aluno no Moodle: " . $e->getMessage());
        }
    }
    
    /**
     * Busca cursos de um aluno
     */
    public function buscarCursosAluno($userId) {
        $cacheKey = "cursos_user_{$userId}_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $courses = $this->callMoodleFunction('core_enrol_get_users_courses', [
                'userid' => (int)$userId
            ]);
            
            $cursosFormatados = [];
            
            if (!empty($courses)) {
                foreach ($courses as $course) {
                    // Filtra apenas cursos visíveis e ativos
                    if (isset($course['visible']) && $course['visible'] == 1) {
                        $cursosFormatados[] = [
                            'id' => $course['id'],
                            'moodle_course_id' => $course['id'],
                            'nome' => $course['fullname'],
                            'nome_curto' => $course['shortname'] ?? '',
                            'categoria' => $course['categoryid'] ?? null,
                            'data_inicio' => isset($course['startdate']) ? date('Y-m-d', $course['startdate']) : null,
                            'data_fim' => isset($course['enddate']) ? date('Y-m-d', $course['enddate']) : null,
                            'formato' => $course['format'] ?? 'topics',
                            'summary' => strip_tags($course['summary'] ?? ''),
                            'url' => isset($course['id']) ? "https://{$this->subdomain}/course/view.php?id={$course['id']}" : null
                        ];
                    }
                }
            }
            
            // Cache por 10 minutos
            $this->cache[$cacheKey] = $cursosFormatados;
            
            return $cursosFormatados;
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar cursos do usuário: {$userId}", $e);
            throw new Exception("Erro ao buscar cursos do aluno no Moodle");
        }
    }
    
    /**
     * Busca detalhes de um curso específico
     */
    public function buscarDetalhesCurso($courseId) {
        $cacheKey = "curso_detalhes_{$courseId}_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $courses = $this->callMoodleFunction('core_course_get_courses_by_field', [
                'field' => 'id',
                'value' => (int)$courseId
            ]);
            
            if (!empty($courses['courses'])) {
                $course = $courses['courses'][0];
                
                $detalhes = [
                    'id' => $course['id'],
                    'nome' => $course['fullname'],
                    'nome_curto' => $course['shortname'],
                    'summary' => strip_tags($course['summary'] ?? ''),
                    'categoria' => $course['categoryid'],
                    'data_inicio' => isset($course['startdate']) ? date('Y-m-d H:i:s', $course['startdate']) : null,
                    'data_fim' => isset($course['enddate']) ? date('Y-m-d H:i:s', $course['enddate']) : null,
                    'formato' => $course['format'],
                    'visivel' => $course['visible'] == 1,
                    'total_alunos' => $course['enrolledusercount'] ?? 0,
                    'url' => "https://{$this->subdomain}/course/view.php?id={$course['id']}"
                ];
                
                // Cache por 30 minutos
                $this->cache[$cacheKey] = $detalhes;
                
                return $detalhes;
            }
            
            return null;
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar detalhes do curso: {$courseId}", $e);
            throw new Exception("Erro ao buscar detalhes do curso no Moodle");
        }
    }
    
    /**
     * Busca informações do site Moodle
     */
    public function buscarInformacoesSite() {
        $cacheKey = "site_info_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $siteInfo = $this->callMoodleFunction('core_webservice_get_site_info');
            
            $info = [
                'nome_site' => $siteInfo['sitename'] ?? '',
                'url' => $siteInfo['siteurl'] ?? '',
                'versao_moodle' => $siteInfo['release'] ?? '',
                'versao_banco' => $siteInfo['version'] ?? '',
                'idioma_padrao' => $siteInfo['lang'] ?? 'pt_br',
                'timezone' => $siteInfo['timezone'] ?? 'America/Sao_Paulo',
                'usuario_api' => [
                    'id' => $siteInfo['userid'] ?? 0,
                    'nome' => $siteInfo['userfullname'] ?? '',
                    'username' => $siteInfo['username'] ?? ''
                ],
                'funcoes_disponiveis' => $siteInfo['functions'] ?? [],
                'mobile_app' => $siteInfo['downloadfiles'] ?? 0
            ];
            
            // Cache por 1 hora
            $this->cache[$cacheKey] = $info;
            
            return $info;
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar informações do site", $e);
            throw new Exception("Erro ao conectar com o Moodle");
        }
    }
    
    /**
     * Valida se o usuário tem acesso a um curso específico
     */
    public function validarAcessoCurso($userId, $courseId) {
        try {
            $cursos = $this->buscarCursosAluno($userId);
            
            foreach ($cursos as $curso) {
                if ($curso['moodle_course_id'] == $courseId) {
                    return true;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logError("Erro ao validar acesso ao curso: {$courseId} para usuário: {$userId}", $e);
            return false;
        }
    }
    
    /**
     * Busca perfil completo de um usuário
     */
    public function buscarPerfilUsuario($userId) {
        $cacheKey = "perfil_user_{$userId}_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $users = $this->callMoodleFunction('core_user_get_users_by_field', [
                'field' => 'id',
                'values' => [(int)$userId]
            ]);
            
            if (!empty($users)) {
                $user = $users[0];
                
                $perfil = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'nome_completo' => $user['fullname'],
                    'primeiro_nome' => $user['firstname'] ?? '',
                    'sobrenome' => $user['lastname'] ?? '',
                    'email' => $user['email'],
                    'cidade' => $user['city'] ?? '',
                    'pais' => $user['country'] ?? 'BR',
                    'timezone' => $user['timezone'] ?? 'America/Sao_Paulo',
                    'idioma' => $user['lang'] ?? 'pt_br',
                    'descricao' => strip_tags($user['description'] ?? ''),
                    'foto_perfil' => $user['profileimageurl'] ?? null,
                    'primeiro_acesso' => isset($user['firstaccess']) ? date('Y-m-d H:i:s', $user['firstaccess']) : null,
                    'ultimo_acesso' => isset($user['lastaccess']) ? date('Y-m-d H:i:s', $user['lastaccess']) : null,
                    'telefone1' => $user['phone1'] ?? '',
                    'telefone2' => $user['phone2'] ?? '',
                    'endereco' => $user['address'] ?? '',
                    'cpf' => $user['idnumber'] ?? ''
                ];
                
                // Cache por 15 minutos
                $this->cache[$cacheKey] = $perfil;
                
                return $perfil;
            }
            
            return null;
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar perfil do usuário: {$userId}", $e);
            throw new Exception("Erro ao buscar perfil do usuário");
        }
    }
    
    /**
     * Lista todos os cursos disponíveis (para administração)
     */
    public function listarTodosCursos() {
        $cacheKey = "todos_cursos_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $courses = $this->callMoodleFunction('core_course_get_courses');
            
            $cursosFormatados = [];
            
            if (!empty($courses)) {
                foreach ($courses as $course) {
                    // Pula o curso "Site" (ID 1)
                    if ($course['id'] == 1) continue;
                    
                    $cursosFormatados[] = [
                        'id' => $course['id'],
                        'nome' => $course['fullname'],
                        'nome_curto' => $course['shortname'],
                        'categoria' => $course['categoryid'],
                        'visivel' => $course['visible'] == 1,
                        'data_inicio' => isset($course['startdate']) ? date('Y-m-d', $course['startdate']) : null,
                        'data_fim' => isset($course['enddate']) ? date('Y-m-d', $course['enddate']) : null,
                        'total_alunos' => $course['enrolledusercount'] ?? 0,
                        'formato' => $course['format'] ?? 'topics'
                    ];
                }
            }
            
            // Cache por 1 hora
            $this->cache[$cacheKey] = $cursosFormatados;
            
            return $cursosFormatados;
            
        } catch (Exception $e) {
            $this->logError("Erro ao listar todos os cursos", $e);
            throw new Exception("Erro ao buscar lista de cursos");
        }
    }
    
    /**
     * Chama uma função da API do Moodle
     */
    private function callMoodleFunction($function, $params = []) {
        if (!MoodleConfig::isFunctionAllowed($function)) {
            throw new Exception("Função não permitida: {$function}");
        }
        
        $defaultParams = [
            'wstoken' => $this->token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json'
        ];
        
        $allParams = array_merge($defaultParams, $this->formatParams($params));
        
        $attempt = 1;
        $lastError = null;
        
        while ($attempt <= $this->maxRetries) {
            try {
                $response = $this->makeRequest($allParams);
                
                // Verifica se há erro na resposta
                if (isset($response['errorcode'])) {
                    throw new Exception("Erro do Moodle: {$response['message']} (Código: {$response['errorcode']})");
                }
                
                return $response;
                
            } catch (Exception $e) {
                $lastError = $e;
                
                if ($attempt < $this->maxRetries) {
                    $this->logError("Tentativa {$attempt} falhou, tentando novamente em {$this->retryDelay}s", $e);
                    sleep($this->retryDelay);
                }
                
                $attempt++;
            }
        }
        
        throw $lastError;
    }
    
    /**
     * Faz a requisição HTTP para o Moodle
     */
    private function makeRequest($params) {
        $queryString = http_build_query($params);
        $url = $this->baseUrl . '?' . $queryString;
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'user_agent' => $this->userAgent,
                'header' => [
                    'Accept: application/json',
                    'Cache-Control: no-cache'
                ]
            ],
            'ssl' => [
                'verify_peer' => MoodleConfig::getGlobalConfig('verify_ssl'),
                'verify_peer_name' => MoodleConfig::getGlobalConfig('verify_ssl')
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            throw new Exception("Falha na requisição HTTP: " . ($error['message'] ?? 'Erro desconhecido'));
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Resposta inválida do Moodle: " . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Formata parâmetros para a API do Moodle
     */
    private function formatParams($params) {
        $formatted = [];
        
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $index => $item) {
                    if (is_array($item)) {
                        foreach ($item as $subKey => $subValue) {
                            $formatted["{$key}[{$index}][{$subKey}]"] = $subValue;
                        }
                    } else {
                        $formatted["{$key}[{$index}]"] = $item;
                    }
                }
            } else {
                $formatted[$key] = $value;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Testa a conectividade com o Moodle
     */
    public function testarConexao() {
        try {
            $siteInfo = $this->buscarInformacoesSite();
            
            return [
                'sucesso' => true,
                'nome_site' => $siteInfo['nome_site'],
                'versao' => $siteInfo['versao_moodle'],
                'url' => $siteInfo['url'],
                'tempo_resposta' => microtime(true)
            ];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => $e->getMessage(),
                'subdomain' => $this->subdomain
            ];
        }
    }
    
    /**
     * Limpa cache interno
     */
    public function limparCache() {
        $this->cache = [];
    }
    
    /**
     * Obtém estatísticas do cache
     */
    public function getEstatisticasCache() {
        return [
            'total_itens' => count($this->cache),
            'memoria_usada' => memory_get_usage(true),
            'chaves' => array_keys($this->cache)
        ];
    }
    
    /**
     * Registra erros
     */
    private function logError($message, $exception = null) {
        $logMessage = "[MoodleAPI - {$this->subdomain}] {$message}";
        
        if ($exception) {
            $logMessage .= " | Erro: " . $exception->getMessage();
        }
        
        error_log($logMessage);
    }
    
    /**
     * Destrutor
     */
    public function __destruct() {
        $this->limparCache();
    }
}
?>