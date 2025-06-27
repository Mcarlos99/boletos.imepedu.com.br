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
            // Busca por CPF no campo idnumber
            $users = $this->callMoodleFunction('core_user_get_users', [
                'criteria' => [
                    [
                        'key' => 'idnumber',
                        'value' => $cpf
                    ]
                ]
            ]);
            
            if (empty($users['users'])) {
                // Tenta buscar por username se não encontrou por idnumber
                $users = $this->callMoodleFunction('core_user_get_users', [
                    'criteria' => [
                        [
                            'key' => 'username',
                            'value' => $cpf
                        ]
                    ]
                ]);
            }
            
            if (!empty($users['users'])) {
                $user = $users['users'][0];
                
                // Busca cursos do aluno
                $cursos = $this->buscarCursosAluno($user['id']);
                
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
                
                return $dadosAluno;
            }
            
            return null;
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar aluno por CPF: {$cpf}", $e);
            throw new Exception("Erro ao buscar dados do aluno no Moodle");
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
            error_log("MoodleAPI: Listando cursos com hierarquia para {$this->subdomain}");
            
            // Lógica específica por polo
            $cursosFinais = [];
            
            if (strpos($this->subdomain, 'breubranco') !== false) {
                // BREU BRANCO: Prioriza SUBCATEGORIAS como cursos
                $cursosFinais = $this->buscarCursosBreuBranco();
                
            } elseif (strpos($this->subdomain, 'igarape') !== false) {
                // IGARAPÉ-MIRI: Categorias principais como cursos
                $cursosFinais = $this->buscarCursosIgarape();
                
            } else {
                // OUTROS POLOS: Estrutura tradicional
                $cursosFinais = $this->buscarCursosTracionais();
            }
            
            // Se não encontrou nada, tenta método híbrido
            if (empty($cursosFinais)) {
                error_log("MoodleAPI: Tentando método híbrido para {$this->subdomain}");
                $cursosFinais = $this->buscarCursosHibrido();
            }
            
            // Ordena por nome
            usort($cursosFinais, function($a, $b) {
                return strcmp($a['nome'], $b['nome']);
            });
            
            error_log("MoodleAPI: Total de cursos finais para {$this->subdomain}: " . count($cursosFinais));
            
            // Cache por 30 minutos
            $this->cache[$cacheKey] = $cursosFinais;
            
            return $cursosFinais;
            
        } catch (Exception $e) {
            $this->logError("Erro ao listar todos os cursos", $e);
            return $this->getCursosEmergencia();
        }
    }


    /**
 * Busca cursos específicos para Breu Branco (SUBCATEGORIAS)
 */
private function buscarCursosBreuBranco() {
    try {
        error_log("MoodleAPI: Buscando SUBCATEGORIAS para Breu Branco");
        
        // Busca todas as categorias
        $categories = $this->callMoodleFunction('core_course_get_categories');
        $cursosFormatados = [];
        
        if (!empty($categories)) {
            // Procura por categorias que são FILHAS (subcategorias)
            foreach ($categories as $category) {
                // Pula categoria raiz
                if ($category['id'] == 1 || $category['parent'] == 0) continue;
                
                $nomeCategoria = strtolower($category['name']);
                
                // Verifica se é uma subcategoria que representa um curso
                $ehCursoTecnico = (
                    strpos($nomeCategoria, 'técnico') !== false ||
                    strpos($nomeCategoria, 'tecnico') !== false ||
                    strpos($nomeCategoria, 'enfermagem') !== false ||
                    strpos($nomeCategoria, 'administração') !== false ||
                    strpos($nomeCategoria, 'administracao') !== false ||
                    strpos($nomeCategoria, 'informática') !== false ||
                    strpos($nomeCategoria, 'informatica') !== false ||
                    strpos($nomeCategoria, 'contabilidade') !== false ||
                    strpos($nomeCategoria, 'segurança') !== false ||
                    strpos($nomeCategoria, 'seguranca') !== false ||
                    strpos($nomeCategoria, 'meio ambiente') !== false ||
                    strpos($nomeCategoria, 'recursos humanos') !== false ||
                    strpos($nomeCategoria, 'logística') !== false ||
                    strpos($nomeCategoria, 'logistica') !== false
                );
                
                // Se tem parent != 0, é subcategoria - no Breu Branco isso é um curso!
                if ($ehCursoTecnico && $category['parent'] != 0) {
                    // Busca nome da categoria pai
                    $categoriaPai = $this->buscarNomeCategoriaPai($category['parent'], $categories);
                    
                    $cursosFormatados[] = [
                        'id' => 'cat_' . $category['id'],
                        'categoria_original_id' => $category['id'],
                        'tipo' => 'categoria_curso',
                        'nome' => $category['name'],
                        'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                        'categoria_id' => $category['parent'],
                        'parent_name' => $categoriaPai,
                        'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                        'data_inicio' => null,
                        'data_fim' => null,
                        'total_alunos' => $category['coursecount'] ?? 0,
                        'formato' => 'category',
                        'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                        'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}"
                    ];
                    
                    error_log("MoodleAPI: Breu Branco - Encontrada subcategoria: {$category['name']} (pai: {$categoriaPai})");
                }
            }
        }
        
        error_log("MoodleAPI: Breu Branco - Total subcategorias encontradas: " . count($cursosFormatados));
        return $cursosFormatados;
        
    } catch (Exception $e) {
        error_log("MoodleAPI: Erro ao buscar cursos Breu Branco: " . $e->getMessage());
        return [];
    }
}

/**
 * Busca cursos específicos para Igarapé-Miri (CATEGORIAS PRINCIPAIS)
 */
private function buscarCursosIgarape() {
    try {
        error_log("MoodleAPI: Buscando CATEGORIAS PRINCIPAIS para Igarapé-Miri");
        
        $categories = $this->callMoodleFunction('core_course_get_categories');
        $cursosFormatados = [];
        
        if (!empty($categories)) {
            foreach ($categories as $category) {
                // Pula categoria raiz
                if ($category['id'] == 1) continue;
                
                $nomeCategoria = strtolower($category['name']);
                
                // No Igarapé, categorias principais (parent = 0 ou categorias de nível alto) são cursos
                $ehCursoIgarape = (
                    strpos($nomeCategoria, 'enfermagem') !== false ||
                    strpos($nomeCategoria, 'administração') !== false ||
                    strpos($nomeCategoria, 'administracao') !== false ||
                    strpos($nomeCategoria, 'técnico') !== false ||
                    strpos($nomeCategoria, 'tecnico') !== false ||
                    strpos($nomeCategoria, 'informática') !== false ||
                    strpos($nomeCategoria, 'informatica') !== false ||
                    strpos($nomeCategoria, 'contabilidade') !== false
                );
                
                // Exclui categorias organizacionais
                $naoEhOrganizacional = (
                    strpos($nomeCategoria, 'cursos') === false &&
                    strpos($nomeCategoria, 'geral') === false &&
                    strpos($nomeCategoria, 'categoria') === false &&
                    strpos($nomeCategoria, 'área') === false &&
                    strpos($nomeCategoria, 'area') === false
                );
                
                if ($ehCursoIgarape && $naoEhOrganizacional) {
                    $cursosFormatados[] = [
                        'id' => 'cat_' . $category['id'],
                        'categoria_original_id' => $category['id'],
                        'tipo' => 'categoria_curso',
                        'nome' => $category['name'],
                        'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                        'categoria_id' => $category['parent'],
                        'parent_name' => null,
                        'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                        'data_inicio' => null,
                        'data_fim' => null,
                        'total_alunos' => $category['coursecount'] ?? 0,
                        'formato' => 'category',
                        'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                        'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}"
                    ];
                    
                    error_log("MoodleAPI: Igarapé - Encontrada categoria curso: {$category['name']}");
                }
            }
        }
        
        error_log("MoodleAPI: Igarapé - Total categorias-curso encontradas: " . count($cursosFormatados));
        return $cursosFormatados;
        
    } catch (Exception $e) {
        error_log("MoodleAPI: Erro ao buscar cursos Igarapé: " . $e->getMessage());
        return [];
    }
}


/**
 * Busca nome da categoria pai (auxiliar)
 */
private function buscarNomeCategoriaPai($parentId, $allCategories = null) {
    if ($parentId == 0) return null;
    
    try {
        if ($allCategories === null) {
            $allCategories = $this->callMoodleFunction('core_course_get_categories');
        }
        
        foreach ($allCategories as $cat) {
            if ($cat['id'] == $parentId) {
                return $cat['name'];
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Busca cursos tradicionais (para outros polos)
 */
private function buscarCursosTracionais() {
    try {
        error_log("MoodleAPI: Buscando cursos tradicionais para {$this->subdomain}");
        
        $courses = $this->callMoodleFunction('core_course_get_courses');
        $cursos = [];
        
        if (!empty($courses)) {
            foreach ($courses as $course) {
                // Pula o curso "Site" (ID 1)
                if ($course['id'] == 1) continue;
                
                $cursos[] = [
                    'id' => $course['id'],
                    'tipo' => 'curso',
                    'nome' => $course['fullname'],
                    'nome_curto' => $course['shortname'] ?? '',
                    'categoria_id' => $course['categoryid'] ?? null,
                    'parent_name' => null,
                    'visivel' => isset($course['visible']) ? ($course['visible'] == 1) : true,
                    'data_inicio' => isset($course['startdate']) && $course['startdate'] > 0 
                        ? date('Y-m-d', $course['startdate']) : null,
                    'data_fim' => isset($course['enddate']) && $course['enddate'] > 0 
                        ? date('Y-m-d', $course['enddate']) : null,
                    'total_alunos' => $course['enrolledusercount'] ?? 0,
                    'formato' => $course['format'] ?? 'topics',
                    'summary' => isset($course['summary']) ? strip_tags($course['summary']) : '',
                    'url' => "https://{$this->subdomain}/course/view.php?id={$course['id']}"
                ];
            }
        }
        
        error_log("MoodleAPI: Cursos tradicionais encontrados: " . count($cursos));
        return $cursos;
        
    } catch (Exception $e) {
        error_log("MoodleAPI: Erro ao buscar cursos tradicionais: " . $e->getMessage());
        return [];
    }
}

/**
 * Método híbrido - tenta diferentes abordagens
 */
private function buscarCursosHibrido() {
    try {
        error_log("MoodleAPI: Executando busca híbrida para {$this->subdomain}");
        
        // Primeiro tenta cursos tradicionais
        $cursosTracionais = $this->buscarCursosTracionais();
        
        // Depois tenta categorias
        $categorias = [];
        try {
            $allCategories = $this->callMoodleFunction('core_course_get_categories');
            
            foreach ($allCategories as $category) {
                if ($category['id'] == 1) continue;
                
                // Qualquer categoria que não seja organizacional
                $nome = strtolower($category['name']);
                $naoEhOrganizacional = (
                    strpos($nome, 'cursos') === false &&
                    strpos($nome, 'geral') === false &&
                    strpos($nome, 'categoria') === false
                );
                
                if ($naoEhOrganizacional) {
                    $categorias[] = [
                        'id' => 'cat_' . $category['id'],
                        'categoria_original_id' => $category['id'],
                        'tipo' => 'categoria_curso',
                        'nome' => $category['name'],
                        'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                        'categoria_id' => $category['parent'],
                        'parent_name' => $this->buscarNomeCategoriaPai($category['parent'], $allCategories),
                        'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                        'data_inicio' => null,
                        'data_fim' => null,
                        'total_alunos' => $category['coursecount'] ?? 0,
                        'formato' => 'category',
                        'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                        'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}"
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("MoodleAPI: Erro ao buscar categorias no método híbrido: " . $e->getMessage());
        }
        
        // Retorna o que tiver mais itens
        if (count($categorias) > count($cursosTracionais)) {
            error_log("MoodleAPI: Método híbrido retornando categorias (" . count($categorias) . " itens)");
            return $categorias;
        } else {
            error_log("MoodleAPI: Método híbrido retornando cursos tradicionais (" . count($cursosTracionais) . " itens)");
            return $cursosTracionais;
        }
        
    } catch (Exception $e) {
        error_log("MoodleAPI: Erro no método híbrido: " . $e->getMessage());
        return [];
    }
}

/**
 * Gera nome curto para categoria (auxiliar)
 */
private function gerarNomeCurtoCategoria($nome) {
    // Remove acentos e caracteres especiais
    $nome = $this->removerAcentos($nome);
    
    // Pega palavras principais
    $palavras = explode(' ', strtoupper($nome));
    $nomeCurto = '';
    
    foreach ($palavras as $palavra) {
        if (strlen($palavra) > 2 && !in_array(strtolower($palavra), ['DE', 'DA', 'DO', 'EM', 'E', 'OU', 'TECNICO', 'TÉCNICO'])) {
            $nomeCurto .= substr($palavra, 0, 3);
        }
    }
    
    return substr($nomeCurto, 0, 10);
}

/**
 * Remove acentos de string (auxiliar)
 */
private function removerAcentos($string) {
    $acentos = [
        'À', 'Á', 'Â', 'Ã', 'Ä', 'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï',
        'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ù', 'Ú', 'Û', 'Ü', 'à', 'á', 'â', 'ã', 
        'ä', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ñ', 'ò', 'ó', 'ô', 
        'õ', 'ö', 'ù', 'ú', 'û', 'ü'
    ];
    
    $semAcentos = [
        'A', 'A', 'A', 'A', 'A', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I',
        'N', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'a', 'a', 'a', 'a',
        'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'n', 'o', 'o', 'o',
        'o', 'o', 'u', 'u', 'u', 'u'
    ];
    
    return str_replace($acentos, $semAcentos, $string);
}

/**
 * Retorna cursos de emergência específicos por polo
 */
private function getCursosEmergencia() {
    $subdomain = $this->subdomain;
    
    if (strpos($subdomain, 'breubranco') !== false) {
        return [
            ['id' => 'emg_bb_001', 'nome' => 'Técnico em Enfermagem', 'nome_curto' => 'TEC_ENF', 'tipo' => 'emergencia'],
            ['id' => 'emg_bb_002', 'nome' => 'Técnico em Administração', 'nome_curto' => 'TEC_ADM', 'tipo' => 'emergencia'],
            ['id' => 'emg_bb_003', 'nome' => 'Técnico em Informática', 'nome_curto' => 'TEC_INF', 'tipo' => 'emergencia'],
            ['id' => 'emg_bb_004', 'nome' => 'Técnico em Segurança do Trabalho', 'nome_curto' => 'TEC_SEG', 'tipo' => 'emergencia']
        ];
    }
    
    if (strpos($subdomain, 'igarape') !== false) {
        return [
            ['id' => 'emg_ig_001', 'nome' => 'Enfermagem', 'nome_curto' => 'ENF', 'tipo' => 'emergencia'],
            ['id' => 'emg_ig_002', 'nome' => 'Administração', 'nome_curto' => 'ADM', 'tipo' => 'emergencia'],
            ['id' => 'emg_ig_003', 'nome' => 'Técnico em Informática', 'nome_curto' => 'TEC_INF', 'tipo' => 'emergencia']
        ];
    }
    
    // Outros polos
    return [
        ['id' => 'emg_gen_001', 'nome' => 'Administração', 'nome_curto' => 'ADM', 'tipo' => 'emergencia'],
        ['id' => 'emg_gen_002', 'nome' => 'Enfermagem', 'nome_curto' => 'ENF', 'tipo' => 'emergencia'],
        ['id' => 'emg_gen_003', 'nome' => 'Direito', 'nome_curto' => 'DIR', 'tipo' => 'emergencia']
    ];
}










  

    /**
 * Busca categorias que podem representar cursos
 */
private function buscarCursosComoCategorias() {
    try {
        // Busca todas as categorias
        $categories = $this->callMoodleFunction('core_course_get_categories');
        $cursos = [];
        
        if (!empty($categories)) {
            foreach ($categories as $category) {
                // Pula categoria raiz
                if ($category['id'] == 1) continue;
                
                // Verifica se é uma categoria "final" (pode ser um curso)
                $ehCursoFinal = $this->verificarSeCategoriaEhCurso($category);
                
                if ($ehCursoFinal) {
                    $cursos[] = [
                        'id' => 'cat_' . $category['id'], // Prefixo para diferenciar
                        'categoria_original_id' => $category['id'],
                        'tipo' => 'categoria_curso',
                        'nome' => $category['name'],
                        'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                        'categoria_id' => $category['parent'],
                        'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                        'data_inicio' => null,
                        'data_fim' => null,
                        'total_alunos' => $category['coursecount'] ?? 0,
                        'formato' => 'category',
                        'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                        'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}",
                        'parent_name' => $this->buscarNomeCategoriaPai($category['parent'])
                    ];
                }
            }
        }
        
        error_log("MoodleAPI: Categorias como cursos encontradas: " . count($cursos));
        return $cursos;
        
    } catch (Exception $e) {
        error_log("MoodleAPI: Erro ao buscar categorias: " . $e->getMessage());
        return [];
    }
}
/**
 * Verifica se uma categoria deve ser tratada como curso
 */
private function verificarSeCategoriaEhCurso($category) {
    // Critérios para considerar uma categoria como curso:
    
    // 1. Não tem subcategorias (é folha na árvore)
    $temSubcategorias = $this->categoriaTemFilhos($category['id']);
    
    // 2. Nome sugere ser um curso
    $nomeSugereCurso = $this->nomeCategoriaSugereCurso($category['name']);
    
    // 3. Está em nível específico (não muito alto na hierarquia)
    $nivelAdequado = $this->categoriaNivelAdequado($category);
    
    // 4. Tem descrição detalhada (cursos costumam ter mais descrição)
    $temDescricaoDetalhada = !empty($category['description']) && strlen($category['description']) > 100;
    
    // Lógica de decisão
    if (!$temSubcategorias && $nomeSugereCurso) {
        return true; // Categoria folha com nome de curso
    }
    
    if ($nivelAdequado && $nomeSugereCurso && $temDescricaoDetalhada) {
        return true; // Categoria em nível bom, nome sugere curso e tem descrição
    }
    
    // Verificação específica por polo (baseado em padrões conhecidos)
    return $this->verificarCategoriaPorPolo($category);
}
/**
 * Verifica se categoria tem subcategorias filhas
 */
private function categoriaTemFilhos($categoryId) {
    try {
        $allCategories = $this->callMoodleFunction('core_course_get_categories');
        
        foreach ($allCategories as $cat) {
            if ($cat['parent'] == $categoryId) {
                return true;
            }
        }
        return false;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Verifica se nome da categoria sugere ser um curso
 */
private function nomeCategoriaSugereCurso($nome) {
    $nome = strtolower($nome);
    
    // Palavras que indicam curso
    $indicadoresCurso = [
        'técnico', 'tecnico', 'superior', 'graduação', 'graduacao',
        'enfermagem', 'administração', 'administracao', 'contabilidade',
        'direito', 'pedagogia', 'psicologia', 'engenharia', 'medicina',
        'fisioterapia', 'farmácia', 'farmacia', 'odontologia',
        'informática', 'informatica', 'sistemas', 'redes',
        'segurança', 'seguranca', 'meio ambiente', 'agronegócio',
        'agronegocio', 'recursos humanos', 'logística', 'logistica'
    ];
    
    foreach ($indicadoresCurso as $indicador) {
        if (strpos($nome, $indicador) !== false) {
            return true;
        }
    }
    
    // Palavras que NÃO indicam curso (são categorias organizacionais)
    $naoIndicadoresCurso = [
        'cursos', 'categorias', 'área', 'area', 'departamento',
        'setor', 'divisão', 'divisao', 'geral', 'outros'
    ];
    
    foreach ($naoIndicadoresCurso as $naoIndicador) {
        if (strpos($nome, $naoIndicador) !== false) {
            return false;
        }
    }
    
    return false;
}

/**
 * Verifica se categoria está em nível adequado da hierarquia
 */
private function categoriaNivelAdequado($category) {
    // Calcula o nível da categoria na hierarquia
    $nivel = $this->calcularNivelCategoria($category['id']);
    
    // Níveis 2-4 são ideais para cursos
    // Nível 1: muito alto (organizacional)
    // Nível 5+: muito específico (disciplinas)
    return $nivel >= 2 && $nivel <= 4;
}

/**
 * Calcula o nível de uma categoria na hierarquia
 */
private function calcularNivelCategoria($categoryId, $nivel = 1) {
    try {
        if ($categoryId == 0) return 0; // Raiz
        
        $categories = $this->callMoodleFunction('core_course_get_categories');
        
        foreach ($categories as $cat) {
            if ($cat['id'] == $categoryId) {
                if ($cat['parent'] == 0) {
                    return $nivel;
                } else {
                    return $this->calcularNivelCategoria($cat['parent'], $nivel + 1);
                }
            }
        }
        
        return $nivel;
        
    } catch (Exception $e) {
        return 1;
    }
}
/**
 * Verificação específica por polo baseado em padrões conhecidos
 */
private function verificarCategoriaPorPolo($category) {
    $subdomain = $this->subdomain;
    $nome = strtolower($category['name']);
    
    // Breu Branco: subcategorias são cursos
    if (strpos($subdomain, 'breubranco') !== false) {
        // Se tem "técnico" no nome e não é a categoria principal
        if (strpos($nome, 'técnico') !== false && $category['parent'] != 0) {
            return true;
        }
    }
    
    // Igarapé-Miri: categorias principais são cursos
    if (strpos($subdomain, 'igarape') !== false) {
        // Se é categoria de primeiro nível e não tem "cursos" no nome
        if ($category['parent'] == 0 && strpos($nome, 'cursos') === false) {
            return true;
        }
    }
    
    return false;
}






/**
 * Combina cursos tradicionais e categorias, removendo duplicatas
 */
private function combinarCursos($cursosTracionais, $cursosCategorias) {
    $todosCursos = array_merge($cursosTracionais, $cursosCategorias);
    
    // Remove duplicatas baseado no nome
    $cursosUnicos = [];
    $nomesJaAdicionados = [];
    
    foreach ($todosCursos as $curso) {
        $nomeNormalizado = strtolower(trim($curso['nome']));
        
        if (!in_array($nomeNormalizado, $nomesJaAdicionados)) {
            $cursosUnicos[] = $curso;
            $nomesJaAdicionados[] = $nomeNormalizado;
        }
    }
    
    return $cursosUnicos;
}

/**
 * Detecta automaticamente a estrutura do polo
 */
private function detectarEstruturaPolo($cursosTracionais, $cursosCategorias) {
    $totalTracionais = count($cursosTracionais);
    $totalCategorias = count($cursosCategorias);
    
    error_log("MoodleAPI: Cursos tradicionais: {$totalTracionais}, Categorias: {$totalCategorias}");
    
    // Se tem muitos cursos tradicionais e poucas categorias -> estrutura tradicional
    if ($totalTracionais > 5 && $totalCategorias < 3) {
        return 'tradicional';
    }
    
    // Se tem mais categorias que cursos -> estrutura de categorias
    if ($totalCategorias > $totalTracionais) {
        return 'categorias';
    }
    
    // Se tem ambos em quantidade similar -> híbrida
    if ($totalTracionais > 0 && $totalCategorias > 0) {
        return 'hibrida';
    }
    
    // Se só tem um tipo
    if ($totalTracionais > 0) return 'tradicional';
    if ($totalCategorias > 0) return 'categorias';
    
    return 'vazia';
}

/**
 * Filtra cursos baseado na estrutura detectada
 */
private function filtrarCursosPorEstrutura($todosCursos, $estrutura) {
    switch ($estrutura) {
        case 'tradicional':
            // Prioriza cursos tradicionais
            return array_filter($todosCursos, function($curso) {
                return $curso['tipo'] === 'curso';
            });
            
        case 'categorias':
            // Prioriza categorias como cursos
            return array_filter($todosCursos, function($curso) {
                return $curso['tipo'] === 'categoria_curso';
            });
            
        case 'hibrida':
            // Retorna todos, mas ordena por tipo
            usort($todosCursos, function($a, $b) {
                if ($a['tipo'] === $b['tipo']) {
                    return strcmp($a['nome'], $b['nome']);
                }
                return $a['tipo'] === 'curso' ? -1 : 1;
            });
            return $todosCursos;
            
        default:
            return $todosCursos;
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