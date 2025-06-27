<?php
/**
 * Sistema de Boletos IMED - Configuração do Moodle
 * Arquivo: config/moodle.php
 * 
 * Classe responsável pelas configurações e tokens de acesso aos diferentes polos Moodle
 */

class MoodleConfig {
    
    /**
     * Tokens de acesso para cada subdomínio Moodle
     * Substitua os tokens pelos valores reais obtidos em cada polo
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
     * Funções Web Services habilitadas para cada polo
     */
    private static $allowedFunctions = [
        'core_user_get_users',
        'core_user_get_users_by_field',
        'core_enrol_get_users_courses',
        'core_course_get_courses',
        'core_course_get_courses_by_field',
        'core_user_get_course_user_profiles',
        'core_webservice_get_site_info',
        'core_course_get_enrolled_courses_by_timeline_classification'
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
        'cache_duration' => 300 // 5 minutos
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
     * Verifica se uma função está permitida
     */
    public static function isFunctionAllowed($function) {
        return in_array($function, self::$allowedFunctions);
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
            'polos_por_estado' => []
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
        if (!$token) {
            $errors[] = "Token não configurado";
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
            'config' => $config
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
     * Atualiza token de um polo (para uso administrativo)
     */
    public static function updateToken($subdomain, $newToken) {
        // Esta função seria usada para atualizar tokens dinamicamente
        // Em produção, isso deveria ser feito através de interface administrativa
        // Por segurança, esta implementação é apenas conceitual
        
        if (!self::isValidSubdomain($subdomain)) {
            throw new Exception("Subdomínio inválido: {$subdomain}");
        }
        
        // Aqui você implementaria a lógica para salvar o novo token
        // Por exemplo, em arquivo de configuração ou banco de dados
        
        return true;
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
                'response_time' => microtime(true)
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Obtém informações de cache para debugging
     */
    public static function getCacheInfo() {
        return [
            'cache_duration' => self::getGlobalConfig('cache_duration'),
            'last_update' => filemtime(__FILE__),
            'config_version' => '1.0.0'
        ];
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
    if (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost') {
        error_log("Configurações Moodle carregadas: " . count($activePolos) . " polos ativos");
    }
}
?>