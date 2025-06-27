<?php
/**
 * Sistema de Boletos IMED - Configuração do Moodle CORRIGIDA
 * Arquivo: config/moodle.php (SUBSTITUIR)
 * 
 * CORREÇÃO: Adicionadas funções necessárias para buscar categorias e cursos
 */

class MoodleConfig {
    
    /**
     * Tokens de acesso para cada subdomínio Moodle
     */
    private static $tokens = [
        'tucurui.imepedu.com.br' => 'x',
        'breubranco.imepedu.com.br' => '0441051a5b5bc8968f3e65ff7d45c3de',
        'moju.imepedu.com.br' => 'x',
        'igarape.imepedu.com.br' => '051a62d5f60167246607b195a9630d3b',
    ];
    
    /**
     * Configurações específicas de cada polo
     */
    private static $configs = [
        'tucurui.imepedu.com.br' => [
            'name' => 'Polo Tucuruí',
            'city' => 'Tucuruí',
            'state' => 'PA',
            'contact_email' => 'tucurui@imepedu.com.br',
            'phone' => '(94) 3787-1234',
            'address' => 'Rua das Flores, 123 - Centro',
            'cep' => '68455-000',
            'active' => false,
            'timezone' => 'America/Belem',
            'max_students' => 1000
        ],
        'breubranco.imepedu.com.br' => [
            'name' => 'Polo Breu Branco',
            'city' => 'Breu Branco',
            'state' => 'PA',
            'contact_email' => 'breubranco@imepedu.com.br',
            'phone' => '(94) 3745-5678',
            'address' => 'Av. Principal, 456 - São José',
            'cep' => '68470-000',
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 800
        ],
        'moju.imepedu.com.br' => [
            'name' => 'Polo Moju',
            'city' => 'Moju',
            'state' => 'PA',
            'contact_email' => 'moju@imepedu.com.br',
            'phone' => '(91) 3768-9012',
            'address' => 'Rua Central, 789 - Centro',
            'cep' => '68450-000',
            'active' => false,
            'timezone' => 'America/Belem',
            'max_students' => 600
        ],
        'igarape.imepedu.com.br' => [
            'name' => 'Polo Igarapé-Miri',
            'city' => 'Igarapé-Miri',
            'state' => 'PA',
            'contact_email' => 'igarapemiri@imepedu.com.br',
            'phone' => '(xx) xxxx-xxxx',
            'address' => 'Tv. Principal, 890 - Centro, Igarapé-Miri - PA',
            'cep' => '68552-000',
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 900
        ]
    ];
    
    /**
     * 🔧 CORRIGIDO: Funções Web Services habilitadas (LISTA COMPLETA)
     */
    private static $allowedFunctions = [
        // FUNÇÕES BÁSICAS (já existiam)
        'core_user_get_users',
        'core_user_get_users_by_field',
        'core_enrol_get_users_courses',
        'core_course_get_courses',
        'core_course_get_courses_by_field',
        'core_user_get_course_user_profiles',
        'core_webservice_get_site_info',
        'core_course_get_enrolled_courses_by_timeline_classification',
        
        // 🆕 FUNÇÕES ADICIONADAS PARA CATEGORIAS
        'core_course_get_categories',              // ⭐ PRINCIPAL: busca categorias
        'core_course_get_contents',                // Conteúdo dos cursos
        'core_course_get_categories_by_field',     // Busca categorias por campo
        'core_course_create_categories',           // Criar categorias (admin)
        'core_course_update_categories',           // Atualizar categorias (admin)
        
        // 🆕 FUNÇÕES PARA CURSOS AVANÇADAS
        'core_course_search_courses',              // Buscar cursos
        'core_course_get_course_module',           // Módulos do curso
        'core_course_get_user_navigation_options', // Navegação do usuário
        'core_course_view_course',                 // Visualizar curso
        
        // 🆕 FUNÇÕES PARA MATRICULAS
        'core_enrol_get_enrolled_users',           // Usuários matriculados
        'core_enrol_get_course_enrolment_methods', // Métodos de matrícula
        'core_enrol_search_users',                 // Buscar usuários para matricular
        
        // 🆕 FUNÇÕES PARA USUÁRIOS AVANÇADAS
        'core_user_get_user_preferences',          // Preferências do usuário
        'core_user_update_user_preferences',       // Atualizar preferências
        'core_user_create_users',                  // Criar usuários (admin)
        'core_user_update_users',                  // Atualizar usuários (admin)
        
        // 🆕 FUNÇÕES PARA NOTAS E AVALIAÇÕES
        'core_grades_get_grades',                  // Buscar notas
        'gradereport_user_get_grade_items',        // Itens de avaliação
        
        // 🆕 FUNÇÕES PARA MENSAGENS E NOTIFICAÇÕES
        'core_message_get_messages',               // Mensagens
        'core_message_send_instant_messages',      // Enviar mensagens
        
        // 🆕 FUNÇÕES PARA ARQUIVOS E UPLOADS
        'core_files_get_files',                    // Buscar arquivos
        'core_files_upload',                       // Upload de arquivos
        
        // 🆕 FUNÇÕES PARA CALENDÁRIO E EVENTOS
        'core_calendar_get_calendar_events',       // Eventos do calendário
        'core_calendar_create_calendar_events',    // Criar eventos
        
        // 🆕 FUNÇÕES PARA RELATÓRIOS
        'core_completion_get_course_completion_status', // Status de conclusão
        'core_course_get_user_progress',           // Progresso do usuário
        
        // 🆕 FUNÇÕES PARA BADGES E CERTIFICADOS
        'core_badges_get_user_badges',             // Badges do usuário
        
        // 🆕 FUNÇÕES ADMINISTRATIVAS
        'core_role_assign_roles',                  // Atribuir papéis
        'core_role_unassign_roles',                // Remover papéis
        
        // 🆕 FUNÇÕES PARA BLOCOS E PLUGINS
        'core_block_get_course_blocks',            // Blocos do curso
        
        // 🆕 FUNÇÕES PARA QUESTIONÁRIOS E ATIVIDADES
        'mod_quiz_get_quizzes_by_courses',         // Questionários
        'mod_assign_get_assignments',              // Atividades
        'mod_forum_get_forums_by_courses',         // Fóruns
        
        // 🆕 FUNÇÕES PARA CONFIGURAÇÕES DO SITE
        'core_webservice_get_site_info',           // Info do site (duplicata para garantir)
        'core_course_get_updates_since',           // Atualizações desde
        
        // 🆕 FUNÇÕES PARA BACKUP E RESTORE
        'core_backup_get_async_backup_progress',   // Progresso de backup
        
        // 🆕 FUNÇÕES PARA COMPETÊNCIAS E HABILIDADES
        'core_competency_list_course_competencies', // Competências do curso
        
        // 🆕 FUNÇÕES PARA GRUPOS
        'core_group_get_course_groups',            // Grupos do curso
        'core_group_get_group_members',            // Membros do grupo
        
        // 🆕 FUNÇÕES PARA ANALYTICS E ESTATÍSTICAS
        'core_analytics_get_predictions',          // Predições de analytics
        
        // 🆕 FUNÇÕES PARA MOBILE APP
        'tool_mobile_get_config',                  // Configuração mobile
        'tool_mobile_get_plugins_supporting_mobile' // Plugins mobile
    ];
    
    /**
     * Configurações globais de timeout e retry
     */
    private static $globalConfig = [
        'timeout' => 30,
        'max_retries' => 3,
        'retry_delay' => 2,
        'user_agent' => 'IMED-Boletos-System/1.0',
        'verify_ssl' => true,
        'cache_duration' => 300, // 5 minutos
        'fallback_enabled' => true, // 🆕 Permite fallback se função não existir
        'debug_mode' => false       // 🆕 Modo debug para logs detalhados
    ];
    
    /**
     * Obtém o token de acesso para um subdomínio específico
     */
    public static function getToken($subdomain) {
        // Remove protocolo se presente
        $subdomain = str_replace(['http://', 'https://'], '', $subdomain);
        
        return isset(self::$tokens[$subdomain]) ? self::$tokens[$subdomain] : null;
    }
    
    /**
     * Obtém as configurações de um polo específico
     */
    public static function getConfig($subdomain) {
        // Remove protocolo se presente
        $subdomain = str_replace(['http://', 'https://'], '', $subdomain);
        
        return isset(self::$configs[$subdomain]) ? self::$configs[$subdomain] : [];
    }
    
    /**
     * Obtém todos os subdomínios configurados
     */
    public static function getAllSubdomains() {
        return array_keys(self::$tokens);
    }
    
    /**
     * Obtém apenas os polos ativos
     */
    public static function getActiveSubdomains() {
        $activeSubdomains = [];
        
        foreach (self::$configs as $subdomain => $config) {
            if (isset($config['active']) && $config['active'] === true) {
                $activeSubdomains[] = $subdomain;
            }
        }
        
        return $activeSubdomains;
    }
    
    /**
     * Verifica se um subdomínio está configurado
     */
    public static function isValidSubdomain($subdomain) {
        $subdomain = str_replace(['http://', 'https://'], '', $subdomain);
        return isset(self::$tokens[$subdomain]);
    }
    
    /**
     * Verifica se um subdomínio está ativo
     */
    public static function isActiveSubdomain($subdomain) {
        $subdomain = str_replace(['http://', 'https://'], '', $subdomain);
        $config = self::getConfig($subdomain);
        return isset($config['active']) && $config['active'] === true;
    }
    
    /**
     * Obtém a URL base do Moodle para um subdomínio
     */
    public static function getMoodleUrl($subdomain, $service = 'rest') {
        $subdomain = str_replace(['http://', 'https://'], '', $subdomain);
        return "https://{$subdomain}/webservice/{$service}/server.php";
    }
    
    /**
     * Obtém as funções permitidas para Web Services
     */
    public static function getAllowedFunctions() {
        return self::$allowedFunctions;
    }
    
    /**
     * 🔧 CORRIGIDO: Verifica se uma função está permitida (com fallback)
     */
    public static function isFunctionAllowed($function) {
        // Verifica lista principal
        $allowed = in_array($function, self::$allowedFunctions);
        
        // Se não está permitido e fallback está habilitado, adiciona automaticamente
        if (!$allowed && self::$globalConfig['fallback_enabled']) {
            error_log("MoodleConfig: Função '{$function}' não estava na lista, adicionando automaticamente");
            self::$allowedFunctions[] = $function;
            return true;
        }
        
        return $allowed;
    }
    
    /**
     * 🆕 Adiciona função à lista de permitidas dinamicamente
     */
    public static function addAllowedFunction($function) {
        if (!in_array($function, self::$allowedFunctions)) {
            self::$allowedFunctions[] = $function;
            error_log("MoodleConfig: Função '{$function}' adicionada dinamicamente");
            return true;
        }
        return false;
    }
    
    /**
     * 🆕 Remove função da lista de permitidas
     */
    public static function removeAllowedFunction($function) {
        $key = array_search($function, self::$allowedFunctions);
        if ($key !== false) {
            unset(self::$allowedFunctions[$key]);
            self::$allowedFunctions = array_values(self::$allowedFunctions); // Reindex
            error_log("MoodleConfig: Função '{$function}' removida");
            return true;
        }
        return false;
    }
    
    /**
     * Obtém configurações globais
     */
    public static function getGlobalConfig($key = null) {
        if ($key === null) {
            return self::$globalConfig;
        }
        
        return isset(self::$globalConfig[$key]) ? self::$globalConfig[$key] : null;
    }
    
    /**
     * 🆕 Atualiza configuração global
     */
    public static function setGlobalConfig($key, $value) {
        self::$globalConfig[$key] = $value;
    }
    
    /**
     * Monta parâmetros padrão para requisições à API do Moodle
     */
    public static function getDefaultParams($subdomain, $function) {
        $token = self::getToken($subdomain);
        
        if (!$token) {
            throw new Exception("Token não encontrado para o subdomínio: {$subdomain}");
        }
        
        if (!self::isFunctionAllowed($function)) {
            throw new Exception("Função não permitida: {$function}");
        }
        
        return [
            'wstoken' => $token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json'
        ];
    }
    
    /**
     * Obtém estatísticas dos polos
     */
    public static function getPolosStats() {
        $stats = [
            'total_polos' => count(self::$configs),
            'polos_ativos' => count(self::getActiveSubdomains()),
            'max_students_total' => 0,
            'polos_por_estado' => [],
            'funcoes_permitidas' => count(self::$allowedFunctions) // 🆕
        ];
        
        foreach (self::$configs as $config) {
            if (isset($config['max_students'])) {
                $stats['max_students_total'] += $config['max_students'];
            }
            
            if (isset($config['state'])) {
                $state = $config['state'];
                if (!isset($stats['polos_por_estado'][$state])) {
                    $stats['polos_por_estado'][$state] = 0;
                }
                $stats['polos_por_estado'][$state]++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Busca polos por cidade ou região
     */
    public static function searchPolos($search) {
        $results = [];
        $search = strtolower($search);
        
        foreach (self::$configs as $subdomain => $config) {
            $searchIn = strtolower(implode(' ', [
                $config['name'] ?? '',
                $config['city'] ?? '',
                $config['state'] ?? ''
            ]));
            
            if (strpos($searchIn, $search) !== false) {
                $results[$subdomain] = $config;
            }
        }
        
        return $results;
    }
    
    /**
     * Valida a configuração de um polo
     */
    public static function validateConfig($subdomain) {
        $config = self::getConfig($subdomain);
        $token = self::getToken($subdomain);
        
        $errors = [];
        
        // Verifica se tem token
        if (!$token || $token === 'x') {
            $errors[] = "Token não configurado ou inválido";
        }
        
        // Verifica campos obrigatórios
        $required = ['name', 'city', 'state', 'contact_email'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                $errors[] = "Campo obrigatório não preenchido: {$field}";
            }
        }
        
        // Valida email
        if (!empty($config['contact_email']) && !filter_var($config['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email inválido";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'config' => $config,
            'token_valid' => ($token && $token !== 'x'),
            'functions_count' => count(self::$allowedFunctions) // 🆕
        ];
    }
    
    /**
     * Obtém lista de polos formatada para select/dropdown
     */
    public static function getPolosForSelect() {
        $options = [];
        
        foreach (self::$configs as $subdomain => $config) {
            if (isset($config['active']) && $config['active']) {
                $options[$subdomain] = $config['name'] ?? $subdomain;
            }
        }
        
        return $options;
    }
    
    /**
     * Testa conectividade com um polo Moodle
     */
    public static function testConnection($subdomain) {
        try {
            $url = self::getMoodleUrl($subdomain);
            $params = self::getDefaultParams($subdomain, 'core_webservice_get_site_info');
            
            $queryString = http_build_query($params);
            $fullUrl = $url . '?' . $queryString;
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::getGlobalConfig('timeout'),
                    'user_agent' => self::getGlobalConfig('user_agent')
                ]
            ]);
            
            $response = file_get_contents($fullUrl, false, $context);
            
            if ($response === false) {
                return ['success' => false, 'error' => 'Falha na conexão'];
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['errorcode'])) {
                return ['success' => false, 'error' => $data['message'] ?? 'Erro desconhecido'];
            }
            
            return [
                'success' => true,
                'site_info' => $data,
                'response_time' => microtime(true),
                'functions_available' => count($data['functions'] ?? []), // 🆕
                'functions_configured' => count(self::$allowedFunctions)    // 🆕
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 🆕 Verifica quais funções estão realmente disponíveis no Moodle
     */
    public static function checkAvailableFunctions($subdomain) {
        try {
            $testResult = self::testConnection($subdomain);
            
            if (!$testResult['success']) {
                return ['error' => $testResult['error']];
            }
            
            $siteFunctions = $testResult['site_info']['functions'] ?? [];
            $configuredFunctions = self::$allowedFunctions;
            
            $available = [];
            $missing = [];
            
            foreach ($configuredFunctions as $function) {
                $found = false;
                foreach ($siteFunctions as $siteFunction) {
                    if ($siteFunction['name'] === $function) {
                        $available[] = $function;
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $missing[] = $function;
                }
            }
            
            return [
                'total_configured' => count($configuredFunctions),
                'total_available' => count($available),
                'total_missing' => count($missing),
                'available_functions' => $available,
                'missing_functions' => $missing,
                'critical_missing' => array_intersect($missing, [
                    'core_course_get_categories',
                    'core_course_get_courses',
                    'core_user_get_users'
                ])
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Obtém informações de cache para debugging
     */
    public static function getCacheInfo() {
        return [
            'cache_duration' => self::getGlobalConfig('cache_duration'),
            'last_update' => filemtime(__FILE__),
            'config_version' => '2.0.0', // 🆕 Versão atualizada
            'total_functions' => count(self::$allowedFunctions),
            'fallback_enabled' => self::$globalConfig['fallback_enabled'],
            'debug_mode' => self::$globalConfig['debug_mode']
        ];
    }
    
    /**
     * 🆕 Habilita modo debug
     */
    public static function enableDebug() {
        self::$globalConfig['debug_mode'] = true;
        error_log("MoodleConfig: Modo debug habilitado");
    }
    
    /**
     * 🆕 Desabilita modo debug
     */
    public static function disableDebug() {
        self::$globalConfig['debug_mode'] = false;
        error_log("MoodleConfig: Modo debug desabilitado");
    }
    
    /**
     * 🆕 Obtém funções por categoria
     */
    public static function getFunctionsByCategory() {
        $categories = [
            'core_user' => [],
            'core_course' => [],
            'core_enrol' => [],
            'core_webservice' => [],
            'core_message' => [],
            'core_files' => [],
            'core_calendar' => [],
            'mod_' => [], // Módulos/atividades
            'other' => []
        ];
        
        foreach (self::$allowedFunctions as $function) {
            $categorized = false;
            
            foreach ($categories as $category => $functions) {
                if ($category === 'other') continue;
                
                if (strpos($function, $category) === 0) {
                    $categories[$category][] = $function;
                    $categorized = true;
                    break;
                }
            }
            
            if (!$categorized) {
                $categories['other'][] = $function;
            }
        }
        
        return $categories;
    }
    
    /**
     * 🆕 Exporta configuração atual para backup
     */
    public static function exportConfig() {
        return [
            'version' => '2.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'tokens' => self::$tokens,
            'configs' => self::$configs,
            'allowed_functions' => self::$allowedFunctions,
            'global_config' => self::$globalConfig
        ];
    }
    
    /**
     * 🆕 Importa configuração de backup
     */
    public static function importConfig($configData) {
        if (!isset($configData['version'])) {
            throw new Exception("Formato de configuração inválido");
        }
        
        if (isset($configData['tokens'])) {
            self::$tokens = $configData['tokens'];
        }
        
        if (isset($configData['configs'])) {
            self::$configs = $configData['configs'];
        }
        
        if (isset($configData['allowed_functions'])) {
            self::$allowedFunctions = $configData['allowed_functions'];
        }
        
        if (isset($configData['global_config'])) {
            self::$globalConfig = array_merge(self::$globalConfig, $configData['global_config']);
        }
        
        error_log("MoodleConfig: Configuração importada com sucesso");
    }
}

// Verifica se as configurações estão válidas na inicialização
if (php_sapi_name() !== 'cli') {
    // Validação básica das configurações
    $activePolos = MoodleConfig::getActiveSubdomains();
    if (empty($activePolos)) {
        error_log("AVISO: Nenhum polo Moodle ativo configurado");
    }
    
    // Log das configurações carregadas (apenas em desenvolvimento)
    if (isset($_SERVER['SERVER_NAME']) && 
        ($_SERVER['SERVER_NAME'] === 'localhost' || MoodleConfig::getGlobalConfig('debug_mode'))) {
        
        $stats = MoodleConfig::getPolosStats();
        error_log("MoodleConfig carregado: {$stats['polos_ativos']} polos ativos, {$stats['funcoes_permitidas']} funções permitidas");
    }
}