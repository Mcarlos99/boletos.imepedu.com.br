<?php
/**
 * Sistema de Boletos IMED - API do Moodle CORRIGIDA
 * Arquivo: src/MoodleAPI.php
 * 
 * VERSÃO ESPECÍFICA PARA SUPORTE À HIERARQUIA DE BREU BRANCO
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
     * MÉTODO PRINCIPAL CORRIGIDO - Lista todos os cursos com hierarquia
     */
    public function listarTodosCursos() {
        $cacheKey = "todos_cursos_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            error_log("MoodleAPI: === INÍCIO BUSCA HIERÁRQUICA PARA {$this->subdomain} ===");
            
            $cursosFinais = [];
            
            // BREU BRANCO: Lógica específica para subcategorias
            if (strpos($this->subdomain, 'breubranco') !== false) {
                error_log("MoodleAPI: Aplicando lógica ESPECÍFICA para Breu Branco");
                $cursosFinais = $this->buscarCursosBreuBrancoEspecifico();
            }
            // IGARAPÉ-MIRI: Categorias principais
            elseif (strpos($this->subdomain, 'igarape') !== false) {
                error_log("MoodleAPI: Aplicando lógica para Igarapé-Miri");
                $cursosFinais = $this->buscarCursosIgarapeEspecifico();
            }
            // OUTROS POLOS: Estrutura tradicional ou híbrida
            else {
                error_log("MoodleAPI: Aplicando lógica tradicional/híbrida");
                $cursosFinais = $this->buscarCursosTraicionaisOuHibridos();
            }
            
            // Se ainda não encontrou nada, usa cursos de emergência
            if (empty($cursosFinais)) {
                error_log("MoodleAPI: NENHUM CURSO ENCONTRADO - Usando cursos de emergência");
                $cursosFinais = $this->getCursosEmergencia();
            }
            
            // Ordena por nome
            usort($cursosFinais, function($a, $b) {
                return strcmp($a['nome'], $b['nome']);
            });
            
            error_log("MoodleAPI: === TOTAL FINAL: " . count($cursosFinais) . " cursos para {$this->subdomain} ===");
            
            // Cache por 30 minutos
            $this->cache[$cacheKey] = $cursosFinais;
            
            return $cursosFinais;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ERRO CRÍTICO na busca: " . $e->getMessage());
            $this->logError("Erro ao listar todos os cursos", $e);
            return $this->getCursosEmergencia();
        }
    }
    
    /**
     * NOVO MÉTODO ESPECÍFICO PARA BREU BRANCO
     */
    private function buscarCursosBreuBrancoEspecifico() {
        error_log("MoodleAPI: 🎯 INICIANDO BUSCA ESPECÍFICA BREU BRANCO");
        
        try {
            // Busca TODAS as categorias
            $allCategories = $this->callMoodleFunction('core_course_get_categories');
            error_log("MoodleAPI: Total de categorias encontradas: " . count($allCategories));
            
            $cursosEncontrados = [];
            
            // Organiza categorias por parent para facilitar busca
            $categoriasPorParent = [];
            $categoriasById = [];
            
            foreach ($allCategories as $cat) {
                $categoriasById[$cat['id']] = $cat;
                if (!isset($categoriasPorParent[$cat['parent']])) {
                    $categoriasPorParent[$cat['parent']] = [];
                }
                $categoriasPorParent[$cat['parent']][] = $cat;
            }
            
            error_log("MoodleAPI: Estrutura organizada. Buscando subcategorias...");
            
            // ESTRATÉGIA 1: Busca subcategorias que são cursos técnicos
            foreach ($allCategories as $category) {
                // Pula categoria raiz
                if ($category['id'] == 1) continue;
                
                $nomeCategoria = strtolower($category['name']);
                $temParent = $category['parent'] != 0;
                
                // Verifica se é um curso técnico (palavras-chave)
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
                    strpos($nomeCategoria, 'logística') !== false ||
                    strpos($nomeCategoria, 'logistica') !== false ||
                    strpos($nomeCategoria, 'recursos humanos') !== false ||
                    strpos($nomeCategoria, 'eletrônica') !== false ||
                    strpos($nomeCategoria, 'eletronica') !== false ||
                    strpos($nomeCategoria, 'mecânica') !== false ||
                    strpos($nomeCategoria, 'mecanica') !== false
                );
                
                // CONDIÇÃO PRINCIPAL: É curso técnico E tem categoria pai
                if ($ehCursoTecnico && $temParent) {
                    $nomeCategoriaPai = isset($categoriasById[$category['parent']]) 
                        ? $categoriasById[$category['parent']]['name'] 
                        : null;
                    
                    $cursoData = [
                        'id' => 'cat_' . $category['id'],
                        'categoria_original_id' => $category['id'],
                        'tipo' => 'categoria_curso',
                        'nome' => $category['name'],
                        'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                        'categoria_id' => $category['parent'],
                        'parent_name' => $nomeCategoriaPai,
                        'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                        'data_inicio' => null,
                        'data_fim' => null,
                        'total_alunos' => $category['coursecount'] ?? 0,
                        'formato' => 'category',
                        'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                        'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}"
                    ];
                    
                    $cursosEncontrados[] = $cursoData;
                    
                    error_log("MoodleAPI: ✅ CURSO TÉCNICO ENCONTRADO: '{$category['name']}' (ID: {$category['id']}, Pai: {$nomeCategoriaPai})");
                }
            }
            
            // ESTRATÉGIA 2: Se não encontrou cursos técnicos, busca QUALQUER subcategoria
            if (empty($cursosEncontrados)) {
                error_log("MoodleAPI: ⚠️ Nenhum curso técnico encontrado. Buscando QUALQUER subcategoria...");
                
                foreach ($allCategories as $category) {
                    if ($category['id'] == 1) continue;
                    
                    // Qualquer categoria que tenha pai (é subcategoria)
                    if ($category['parent'] != 0) {
                        $nomeCategoriaPai = isset($categoriasById[$category['parent']]) 
                            ? $categoriasById[$category['parent']]['name'] 
                            : null;
                        
                        $cursosEncontrados[] = [
                            'id' => 'cat_' . $category['id'],
                            'categoria_original_id' => $category['id'],
                            'tipo' => 'categoria_curso',
                            'nome' => $category['name'],
                            'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                            'categoria_id' => $category['parent'],
                            'parent_name' => $nomeCategoriaPai,
                            'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                            'data_inicio' => null,
                            'data_fim' => null,
                            'total_alunos' => $category['coursecount'] ?? 0,
                            'formato' => 'category',
                            'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                            'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}"
                        ];
                        
                        error_log("MoodleAPI: ➕ SUBCATEGORIA GERAL: '{$category['name']}' (ID: {$category['id']})");
                    }
                }
            }
            
            // ESTRATÉGIA 3: Se ainda não encontrou, busca categorias principais
            if (empty($cursosEncontrados)) {
                error_log("MoodleAPI: ⚠️ Nenhuma subcategoria encontrada. Buscando categorias principais...");
                
                foreach ($allCategories as $category) {
                    if ($category['id'] == 1) continue;
                    
                    // Categorias principais (parent = 0)
                    if ($category['parent'] == 0) {
                        $cursosEncontrados[] = [
                            'id' => 'cat_' . $category['id'],
                            'categoria_original_id' => $category['id'],
                            'tipo' => 'categoria_curso',
                            'nome' => $category['name'],
                            'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                            'categoria_id' => 0,
                            'parent_name' => null,
                            'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                            'data_inicio' => null,
                            'data_fim' => null,
                            'total_alunos' => $category['coursecount'] ?? 0,
                            'formato' => 'category',
                            'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                            'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}"
                        ];
                        
                        error_log("MoodleAPI: 📁 CATEGORIA PRINCIPAL: '{$category['name']}' (ID: {$category['id']})");
                    }
                }
            }
            
            error_log("MoodleAPI: 🏆 BREU BRANCO - TOTAL ENCONTRADO: " . count($cursosEncontrados));
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ ERRO na busca específica Breu Branco: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca específica para Igarapé-Miri
     */
    private function buscarCursosIgarapeEspecifico() {
        try {
            error_log("MoodleAPI: Buscando cursos para Igarapé-Miri");
            
            $categories = $this->callMoodleFunction('core_course_get_categories');
            $cursosEncontrados = [];
            
            foreach ($categories as $category) {
                if ($category['id'] == 1) continue;
                
                $nomeCategoria = strtolower($category['name']);
                
                // Busca categorias que representam cursos
                $ehCurso = (
                    strpos($nomeCategoria, 'enfermagem') !== false ||
                    strpos($nomeCategoria, 'administração') !== false ||
                    strpos($nomeCategoria, 'administracao') !== false ||
                    strpos($nomeCategoria, 'técnico') !== false ||
                    strpos($nomeCategoria, 'tecnico') !== false ||
                    strpos($nomeCategoria, 'informática') !== false ||
                    strpos($nomeCategoria, 'informatica') !== false
                );
                
                // Evita categorias organizacionais
                $naoEhOrganizacional = (
                    strpos($nomeCategoria, 'cursos') === false &&
                    strpos($nomeCategoria, 'geral') === false &&
                    strpos($nomeCategoria, 'categoria') === false
                );
                
                if ($ehCurso && $naoEhOrganizacional) {
                    $cursosEncontrados[] = [
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
                }
            }
            
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: Erro na busca específica Igarapé: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca tradicional ou híbrida para outros polos
     */
    private function buscarCursosTraicionaisOuHibridos() {
        try {
            error_log("MoodleAPI: Buscando cursos tradicionais/híbridos");
            
            // Primeiro tenta cursos tradicionais
            $cursosTracionais = $this->buscarCursosTracionais();
            
            // Se não encontrou muitos, tenta categorias
            if (count($cursosTracionais) < 3) {
                $categorias = $this->buscarCategoriasCursos();
                
                // Retorna o que tiver mais itens
                if (count($categorias) > count($cursosTracionais)) {
                    return $categorias;
                }
            }
            
            return $cursosTracionais;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: Erro na busca tradicional/híbrida: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca cursos tradicionais do Moodle
     */
    private function buscarCursosTracionais() {
        try {
            $courses = $this->callMoodleFunction('core_course_get_courses');
            $cursos = [];
            
            if (!empty($courses)) {
                foreach ($courses as $course) {
                    if ($course['id'] == 1) continue; // Pula curso Site
                    
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
            
            return $cursos;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: Erro ao buscar cursos tradicionais: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca categorias que representam cursos
     */
    private function buscarCategoriasCursos() {
        try {
            $categories = $this->callMoodleFunction('core_course_get_categories');
            $cursos = [];
            
            foreach ($categories as $category) {
                if ($category['id'] == 1) continue;
                
                // Lógica genérica para detectar se categoria é um curso
                $nomeCategoria = strtolower($category['name']);
                $ehCurso = (
                    strpos($nomeCategoria, 'técnico') !== false ||
                    strpos($nomeCategoria, 'superior') !== false ||
                    strpos($nomeCategoria, 'graduação') !== false ||
                    strpos($nomeCategoria, 'enfermagem') !== false ||
                    strpos($nomeCategoria, 'administração') !== false
                );
                
                if ($ehCurso) {
                    $cursos[] = [
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
                }
            }
            
            return $cursos;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: Erro ao buscar categorias como cursos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Gera nome curto para categoria
     */
    private function gerarNomeCurtoCategoria($nome) {
        $nome = $this->removerAcentos($nome);
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
     * Remove acentos de string
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
                [
                    'id' => 'emg_bb_001',
                    'tipo' => 'emergencia',
                    'nome' => 'Técnico em Enfermagem',
                    'nome_curto' => 'TEC_ENF',
                    'categoria_id' => null,
                    'parent_name' => null,
                    'visivel' => true,
                    'data_inicio' => null,
                    'data_fim' => null,
                    'total_alunos' => 0,
                    'formato' => 'emergency',
                    'summary' => 'Curso de emergência - Técnico em Enfermagem',
                    'url' => "https://{$this->subdomain}"
                ],
                [
                    'id' => 'emg_bb_002',
                    'tipo' => 'emergencia',
                    'nome' => 'Técnico em Administração',
                    'nome_curto' => 'TEC_ADM',
                    'categoria_id' => null,
                    'parent_name' => null,
                    'visivel' => true,
                    'data_inicio' => null,
                    'data_fim' => null,
                    'total_alunos' => 0,
                    'formato' => 'emergency',
                    'summary' => 'Curso de emergência - Técnico em Administração',
                    'url' => "https://{$this->subdomain}"
                ],
                [
                    'id' => 'emg_bb_003',
                    'tipo' => 'emergencia',
                    'nome' => 'Técnico em Informática',
                    'nome_curto' => 'TEC_INF',
                    'categoria_id' => null,
                    'parent_name' => null,
                    'visivel' => true,
                    'data_inicio' => null,
                    'data_fim' => null,
                    'total_alunos' => 0,
                    'formato' => 'emergency',
                    'summary' => 'Curso de emergência - Técnico em Informática',
                    'url' => "https://{$this->subdomain}"
                ],
                [
                    'id' => 'emg_bb_004',
                    'tipo' => 'emergencia',
                    'nome' => 'Técnico em Segurança do Trabalho',
                    'nome_curto' => 'TEC_SEG',
                    'categoria_id' => null,
                    'parent_name' => null,
                    'visivel' => true,
                    'data_inicio' => null,
                    'data_fim' => null,
                    'total_alunos' => 0,
                    'formato' => 'emergency',
                    'summary' => 'Curso de emergência - Técnico em Segurança do Trabalho',
                    'url' => "https://{$this->subdomain}"
                ]
            ];
        }
        
        if (strpos($subdomain, 'igarape') !== false) {
            return [
                [
                    'id' => 'emg_ig_001',
                    'tipo' => 'emergencia',
                    'nome' => 'Enfermagem',
                    'nome_curto' => 'ENF',
                    'categoria_id' => null,
                    'parent_name' => null,
                    'visivel' => true,
                    'data_inicio' => null,
                    'data_fim' => null,
                    'total_alunos' => 0,
                    'formato' => 'emergency',
                    'summary' => 'Curso de emergência - Enfermagem',
                    'url' => "https://{$this->subdomain}"
                ],
                [
                    'id' => 'emg_ig_002',
                    'tipo' => 'emergencia',
                    'nome' => 'Administração',
                    'nome_curto' => 'ADM',
                    'categoria_id' => null,
                    'parent_name' => null,
                    'visivel' => true,
                    'data_inicio' => null,
                    'data_fim' => null,
                    'total_alunos' => 0,
                    'formato' => 'emergency',
                    'summary' => 'Curso de emergência - Administração',
                    'url' => "https://{$this->subdomain}"
                ],
                [
                    'id' => 'emg_ig_003',
                    'tipo' => 'emergencia',
                    'nome' => 'Técnico em Informática',
                    'nome_curto' => 'TEC_INF',
                    'categoria_id' => null,
                    'parent_name' => null,
                    'visivel' => true,
                    'data_inicio' => null,
                    'data_fim' => null,
                    'total_alunos' => 0,
                    'formato' => 'emergency',
                    'summary' => 'Curso de emergência - Técnico em Informática',
                    'url' => "https://{$this->subdomain}"
                ]
            ];
        }
        
        // Outros polos - cursos genéricos
        return [
            [
                'id' => 'emg_gen_001',
                'tipo' => 'emergencia',
                'nome' => 'Administração',
                'nome_curto' => 'ADM',
                'categoria_id' => null,
                'parent_name' => null,
                'visivel' => true,
                'data_inicio' => null,
                'data_fim' => null,
                'total_alunos' => 0,
                'formato' => 'emergency',
                'summary' => 'Curso de emergência - Administração',
                'url' => "https://{$this->subdomain}"
            ],
            [
                'id' => 'emg_gen_002',
                'tipo' => 'emergencia',
                'nome' => 'Enfermagem',
                'nome_curto' => 'ENF',
                'categoria_id' => null,
                'parent_name' => null,
                'visivel' => true,
                'data_inicio' => null,
                'data_fim' => null,
                'total_alunos' => 0,
                'formato' => 'emergency',
                'summary' => 'Curso de emergência - Enfermagem',
                'url' => "https://{$this->subdomain}"
            ],
            [
                'id' => 'emg_gen_003',
                'tipo' => 'emergencia',
                'nome' => 'Direito',
                'nome_curto' => 'DIR',
                'categoria_id' => null,
                'parent_name' => null,
                'visivel' => true,
                'data_inicio' => null,
                'data_fim' => null,
                'total_alunos' => 0,
                'formato' => 'emergency',
                'summary' => 'Curso de emergência - Direito',
                'url' => "https://{$this->subdomain}"
            ]
        ];
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
     * Chama uma função da API do Moodle
     */
    public function callMoodleFunction($function, $params = []) {
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