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
        'tucurui.imepedu.com.br' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0',
        'breubranco.imepedu.com.br' => 'b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1',
        'moju.imepedu.com.br' => 'c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2',
        'altamira.imepedu.com.br' => 'd4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3',
        'santarem.imepedu.com.br' => 'e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4',
        'maraba.imepedu.com.br' => 'f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5',
        'parauapebas.imepedu.com.br' => 'g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6',
        'redenção.imepedu.com.br' => 'h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7',
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
            'active' => true,
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
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 600
        ],
        'altamira.imepedu.com.br' => [
            'name' => 'Polo Altamira',
            'city' => 'Altamira',
            'state' => 'PA',
            'contact_email' => 'altamira@imepedu.com.br',
            'phone' => '(93) 3515-3456',
            'address' => 'Rua Transamazônica, 321 - Brasília',
            'cep' => '68372-000',
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 1200
        ],
        'santarem.imepedu.com.br' => [
            'name' => 'Polo Santarém',
            'city' => 'Santarém',
            'state' => 'PA',
            'contact_email' => 'santarem@imepedu.com.br',
            'phone' => '(93) 3523-7890',
            'address' => 'Av. Tapajós, 654 - Centro',
            'cep' => '68005-000',
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 1500
        ],
        'maraba.imepedu.com.br' => [
            'name' => 'Polo Marabá',
            'city' => 'Marabá',
            'state' => 'PA',
            'contact_email' => 'maraba@imepedu.com.br',
            'phone' => '(94) 3324-1357',
            'address' => 'Rua Tocantins, 987 - Nova Marabá',
            'cep' => '68508-000',
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 2000
        ],
        'parauapebas.imepedu.com.br' => [
            'name' => 'Polo Parauapebas',
            'city' => 'Parauapebas',
            'state' => 'PA',
            'contact_email' => 'parauapebas@imepedu.com.br',
            'phone' => '(94) 3346-2468',
            'address' => 'Av. Liberdade, 147 - Rio Verde',
            'cep' => '68515-000',
            'active' => true,
            'timezone' => 'America/Belem',
            'max_students' => 1800
        ],
        'redenção.imepedu.com.br' => [
            'name' => 'Polo Redenção',
            'city' => 'Redenção',
            'state' => 'PA',
            'contact_email' => 'redencao@imepedu.com.br',
            'phone' => '(94) 3424-3691',
            'address' => 'Rua da Paz, 258 - Centro',
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