<?php
/**
 * Sistema de Boletos IMED - API do Moodle MELHORADA
 * Arquivo: src/MoodleAPI.php
 * 
 * Versão com suporte a múltiplos polos e estruturas flexíveis
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
     * Configurações de categorias por polo
     */
    private function getCategoriasPermitidas($polo) {
        $configuracoes = [
            // Breu Branco - Estrutura hierárquica
            'breubranco.imepedu.com.br' => [
                'categorias_pai' => [
                    'cursos tecnicos',
                    'norma regulamentadora', 
                    'industria',
                    'profissionalizantes'
                ],
                'tipo_validacao' => 'multiplas_categorias',
                'palavras_obrigatorias' => ['tecnico', 'nr', 'norma', 'profissional'],
                'subcategorias_como_cursos' => true,
                'metodo_busca' => 'hierarquico'
            ],
            
            // Igarapé-Miri - Estrutura tradicional
            'igarape.imepedu.com.br' => [
                'categorias_pai' => [
                    'graduacao',
                    'graduação',
                    'superior',
                    'bacharelado',
                    'licenciatura',
                    'cursos superiores'
                ],
                'tipo_validacao' => 'tecnico_obrigatorio',
                'palavras_obrigatorias' => ['tecnico'],
                'subcategorias_como_cursos' => true,
                'metodo_busca' => 'tradicional'
            ],
            
            // Tucuruí - Estrutura mista
            'tucurui.imepedu.com.br' => [
                'categorias_pai' => [
                    'cursos',
                    'educacao',
                    'educação',
                    'formacao',
                    'formação',
                    'ensino'
                ],
                'tipo_validacao' => 'flexivel',
                'palavras_obrigatorias' => [],
                'subcategorias_como_cursos' => true,
                'metodo_busca' => 'misto'
            ],
            
            // Moju - Estrutura profissionalizante
            'moju.imepedu.com.br' => [
                'categorias_pai' => [
                    'profissionalizante',
                    'tecnico',
                    'técnico',
                    'capacitacao',
                    'capacitação',
                    'qualificacao'
                ],
                'tipo_validacao' => 'profissionalizante',
                'palavras_obrigatorias' => ['profissional', 'tecnico', 'capacita'],
                'subcategorias_como_cursos' => true,
                'metodo_busca' => 'profissionalizante'
            ]
        ];
        
        // Retorna configuração específica do polo ou padrão
        return $configuracoes[$polo] ?? [
            'categorias_pai' => ['cursos', 'educacao', 'formacao'],
            'tipo_validacao' => 'flexivel',
            'palavras_obrigatorias' => [],
            'subcategorias_como_cursos' => true,
            'metodo_busca' => 'automatico'
        ];
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
                        
                        // Aplica filtro específico por polo
                        if ($this->ehCursoValidoParaPolo($course)) {
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
     * Lista todos os cursos disponíveis - IMPLEMENTAÇÃO MELHORADA
     */
    public function listarTodosCursos() {
        $cacheKey = "todos_cursos_melhorados_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            error_log("MoodleAPI: 🎯 BUSCA MELHORADA DE CURSOS para {$this->subdomain}");
            
            // Busca configuração específica do polo
            $config = $this->getCategoriasPermitidas($this->subdomain);
            
            // Implementação específica baseada no método de busca configurado
            $cursosEncontrados = $this->buscarCursosComConfiguracao($config);
            
            error_log("MoodleAPI: ✅ CURSOS ENCONTRADOS: " . count($cursosEncontrados));
            
            // Cache por 30 minutos
            $this->cache[$cacheKey] = $cursosEncontrados;
            
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            $this->logError("Erro ao listar cursos", $e);
            return [];
        }
    }
    
    /**
     * Busca cursos usando configuração específica do polo
     */
    private function buscarCursosComConfiguracao($config) {
        switch ($config['metodo_busca']) {
            case 'hierarquico':
                return $this->buscarCursosHierarquicos($config);
                
            case 'tradicional':
                return $this->buscarCursosTracionais($config);
                
            case 'misto':
                return $this->buscarCursosMisto($config);
                
            case 'profissionalizante':
                return $this->buscarCursosProfissionalizantes($config);
                
            case 'automatico':
            default:
                return $this->buscarCursosAutomatico($config);
        }
    }
    
    /**
     * Busca cursos em estrutura hierárquica (como Breu Branco)
     */
/**
 * Busca cursos em estrutura hierárquica (VERSÃO CORRIGIDA para múltiplas categorias pai)
 */
private function buscarCursosHierarquicos($config) {
    try {
        error_log("MoodleAPI: 📂 Usando método hierárquico MÚLTIPLAS CATEGORIAS PAI");
        
        $allCategories = $this->callMoodleFunction('core_course_get_categories');
        error_log("MoodleAPI: Total categorias: " . count($allCategories));
        
        if (empty($allCategories)) {
            return [];
        }
        
        $cursosEncontrados = [];
        
        // 🆕 BUSCA MÚLTIPLAS CATEGORIAS PAI
        $categoriasPaiEncontradas = [];
        
        foreach ($allCategories as $category) {
            // Verifica se é categoria raiz (parent = 0)
            if ($category['parent'] == 0) {
                $nomeNormalizado = $this->normalizarTexto($category['name']);
                
                // Testa contra todas as categorias pai permitidas
                foreach ($config['categorias_pai'] as $categoriaBusca) {
                    if (strpos($nomeNormalizado, $categoriaBusca) !== false) {
                        $categoriasPaiEncontradas[] = $category;
                        error_log("MoodleAPI: ✅ Categoria pai encontrada: " . $category['name'] . " (ID: " . $category['id'] . ")");
                        break; // Evita duplicatas
                    }
                }
            }
        }
        
        error_log("MoodleAPI: Total de categorias pai encontradas: " . count($categoriasPaiEncontradas));
        
        if (empty($categoriasPaiEncontradas)) {
            error_log("MoodleAPI: ❌ Nenhuma categoria pai encontrada");
            return [];
        }
        
        // 🔍 BUSCA SUBCATEGORIAS EM CADA CATEGORIA PAI
        foreach ($categoriasPaiEncontradas as $categoriaPai) {
            error_log("MoodleAPI: 🔍 Processando categoria pai: " . $categoriaPai['name']);
            
            foreach ($allCategories as $category) {
                // Verifica se é subcategoria desta categoria pai
                if ($category['parent'] == $categoriaPai['id']) {
                    error_log("MoodleAPI: 📋 Analisando subcategoria: " . $category['name']);
                    
                    if ($this->validarCursoPorTipoFlexivel($category['name'], $categoriaPai['name'], $config)) {
                        $cursosEncontrados[] = $this->formatarCursoCategoria($category, $categoriaPai);
                        error_log("MoodleAPI: ✅ Subcategoria válida: " . $category['name']);
                    } else {
                        error_log("MoodleAPI: ❌ Subcategoria rejeitada: " . $category['name']);
                    }
                }
            }
            
            // 🆕 TAMBÉM CONSIDERA A PRÓPRIA CATEGORIA PAI COMO CURSO (se tiver cursos)
            if (($categoriaPai['coursecount'] ?? 0) > 0) {
                if ($this->validarCursoPorTipoFlexivel($categoriaPai['name'], null, $config)) {
                    $cursosEncontrados[] = $this->formatarCursoCategoria($categoriaPai, null);
                    error_log("MoodleAPI: ✅ Categoria pai como curso: " . $categoriaPai['name']);
                }
            }
        }
        
        error_log("MoodleAPI: 🏆 Total de cursos encontrados: " . count($cursosEncontrados));
        return $cursosEncontrados;
        
    } catch (Exception $e) {
        error_log("MoodleAPI: ❌ Erro hierárquico múltiplo: " . $e->getMessage());
        return [];
    }
}
/**
 * 🆕 Validação flexível que considera a categoria pai
 */
private function validarCursoPorTipoFlexivel($nomeSubcategoria, $nomeCategoriaPai, $config) {
    $nome = $this->normalizarTexto($nomeSubcategoria);
    $categoriaPai = $nomeCategoriaPai ? $this->normalizarTexto($nomeCategoriaPai) : '';
    
    error_log("MoodleAPI: 🧪 Validando '{$nomeSubcategoria}' (pai: '{$nomeCategoriaPai}')");
    
    // 1. Se a categoria pai for "CURSOS TECNICOS", usa validação técnica
    if (strpos($categoriaPai, 'cursos tecnicos') !== false || strpos($categoriaPai, 'tecnicos') !== false) {
        return $this->ehCursoTecnicoValido($nomeSubcategoria);
    }
    
    // 2. Se a categoria pai for "NORMA REGULAMENTADORA", aceita NRs
    if (strpos($categoriaPai, 'norma') !== false || strpos($categoriaPai, 'regulamentadora') !== false) {
        if (strpos($nome, 'nr') !== false || strpos($nome, 'norma') !== false) {
            error_log("MoodleAPI: ✅ NR válida: " . $nomeSubcategoria);
            return true;
        }
    }
    
    // 3. Se a categoria pai for "INDUSTRIA", aceita cursos industriais
    if (strpos($categoriaPai, 'industria') !== false) {
        $cursosIndustriais = ['soldador', 'operador', 'mecanico', 'eletricista', 'tecnico'];
        foreach ($cursosIndustriais as $curso) {
            if (strpos($nome, $curso) !== false) {
                error_log("MoodleAPI: ✅ Curso industrial válido: " . $nomeSubcategoria);
                return true;
            }
        }
    }
    
    // 4. Se a categoria pai for "PROFISSIONALIZANTES", aceita cursos profissionalizantes
    if (strpos($categoriaPai, 'profissionalizantes') !== false) {
        return $this->ehCursoProfissionalizanteValido($nomeSubcategoria);
    }
    
    // 5. Validação geral baseada nas palavras obrigatórias
    if (!empty($config['palavras_obrigatorias'])) {
        foreach ($config['palavras_obrigatorias'] as $obrigatoria) {
            if (strpos($nome, $obrigatoria) !== false) {
                error_log("MoodleAPI: ✅ Palavra obrigatória encontrada: " . $obrigatoria);
                return true;
            }
        }
    }
    
    // 6. Se chegou até aqui, aplica validação básica (aceita se não tem palavras proibidas)
    $palavrasProibidas = ['disciplina', 'modulo', 'estagio'];
    foreach ($palavrasProibidas as $proibida) {
        if (strpos($nome, $proibida) !== false) {
            error_log("MoodleAPI: ❌ Palavra proibida: " . $proibida);
            return false;
        }
    }
    
    error_log("MoodleAPI: ✅ Validação básica aprovada: " . $nomeSubcategoria);
    return true;
}

    
    /**
     * Busca cursos tradicionais (como Igarapé)
     */
    private function buscarCursosTracionais($config) {
        try {
            error_log("MoodleAPI: 📚 Usando método tradicional");
            
            $courses = $this->callMoodleFunction('core_course_get_courses');
            $cursosEncontrados = [];
            
            foreach ($courses as $course) {
                if ($course['id'] == 1) continue; // Pula site principal
                
                if (isset($course['visible']) && $course['visible'] == 1) {
                    if ($this->validarCursoPorTipo($course['fullname'], $config)) {
                        $cursosEncontrados[] = $this->formatarCursoTradicional($course);
                        error_log("MoodleAPI: ✅ Curso tradicional válido: " . $course['fullname']);
                    }
                }
            }
            
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro tradicional: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca cursos em estrutura mista
     */
    private function buscarCursosMisto($config) {
        try {
            error_log("MoodleAPI: 🔄 Usando método misto");
            
            // Tenta primeiro hierárquico
            $cursosHierarquicos = $this->buscarCursosHierarquicos($config);
            
            // Se não encontrou suficientes, complementa com tradicionais
            if (count($cursosHierarquicos) < 3) {
                $cursosTracionais = $this->buscarCursosTracionais($config);
                return array_merge($cursosHierarquicos, $cursosTracionais);
            }
            
            return $cursosHierarquicos;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro misto: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca cursos profissionalizantes
     */
    private function buscarCursosProfissionalizantes($config) {
        try {
            error_log("MoodleAPI: 🛠️ Usando método profissionalizante");
            
            // Combina busca hierárquica e tradicional com foco em profissionalizantes
            $allCategories = $this->callMoodleFunction('core_course_get_categories');
            $courses = $this->callMoodleFunction('core_course_get_courses');
            
            $cursosEncontrados = [];
            
            // Busca em categorias
            foreach ($allCategories as $category) {
                if ($this->validarCursoPorTipo($category['name'], $config)) {
                    $cursosEncontrados[] = $this->formatarCursoCategoria($category, null);
                }
            }
            
            // Complementa com cursos diretos
            foreach ($courses as $course) {
                if ($course['id'] == 1) continue;
                
                if (isset($course['visible']) && $course['visible'] == 1) {
                    if ($this->validarCursoPorTipo($course['fullname'], $config)) {
                        $cursosEncontrados[] = $this->formatarCursoTradicional($course);
                    }
                }
            }
            
            // Remove duplicatas
            return $this->removerDuplicatas($cursosEncontrados);
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro profissionalizante: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca automática (fallback)
     */
    private function buscarCursosAutomatico($config) {
        try {
            error_log("MoodleAPI: 🤖 Usando detecção automática");
            
            // Tenta todos os métodos em ordem de preferência
            $metodos = ['buscarCursosHierarquicos', 'buscarCursosTracionais', 'buscarCursosMisto'];
            
            foreach ($metodos as $metodo) {
                $cursos = $this->$metodo($config);
                if (count($cursos) > 0) {
                    error_log("MoodleAPI: ✅ Método {$metodo} funcionou com " . count($cursos) . " cursos");
                    return $cursos;
                }
            }
            
            error_log("MoodleAPI: ⚠️ Nenhum método automático funcionou");
            return [];
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro automático: " . $e->getMessage());
            return [];
        }
    }
  /**
     * Encontra categoria pai flexível
     */
    private function encontrarCategoriaPai($allCategories, $categoriasPai) {
        foreach ($allCategories as $category) {
            $nomeNormalizado = $this->normalizarTexto($category['name']);
            
            foreach ($categoriasPai as $categoriaBusca) {
                if (strpos($nomeNormalizado, $categoriaBusca) !== false) {
                    return $category;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Validação flexível por tipo de polo
     */
    private function validarCursoPorTipo($nome, $config) {
        switch ($config['tipo_validacao']) {
            case 'tecnico_obrigatorio':
                return $this->ehCursoTecnicoValido($nome);
                
            case 'curso_superior':
                return $this->ehCursoSuperiorValido($nome);
                
            case 'profissionalizante':
                return $this->ehCursoProfissionalizanteValido($nome);
                
            case 'flexivel':
            default:
                return $this->ehCursoFlexivelValido($nome, $config);
        }
    }
    
    /**
     * Validação para cursos técnicos (Breu Branco)
     */
    private function ehCursoTecnicoValido($nome) {
        $nome = $this->normalizarTexto($nome);
        
        // Deve conter "tecnico" obrigatoriamente
        if (strpos($nome, 'tecnico') === false) {
            return false;
        }
        
        // Lista de palavras-chave dos cursos técnicos válidos
        $cursosValidos = [
            'enfermagem',
            'eletromecanica', 
            'eletrotecnica', 
            'seguranca',
            'trabalho',
          	'nr10',
          	'nr33',
          	'nr35'
          	
        ];
        
        // Verifica se contém pelo menos uma palavra-chave válida
        foreach ($cursosValidos as $palavraChave) {
            if (strpos($nome, $palavraChave) !== false) {
                return true;
            }
        }
        
        // Palavras que invalidam (disciplinas dentro dos cursos)
        $palavrasInvalidas = [
            'higiene', 'medicina', 'psicologia', 'aplicada',
            'modulo', 'estagio', 'introducao', 'nocoes',
            'cirurgica', 'materno', 'infantil', 'neuropsiquiatrica',
            'saude publica', 'unidade de', 'emergencia', 'urgencia'
        ];
        
        foreach ($palavrasInvalidas as $invalida) {
            if (strpos($nome, $invalida) !== false) {
                return false;
            }
        }
        
        // Se contém "tecnico" e passou pelos filtros, aceita
        return true;
    }
    
    /**
     * Validação para cursos superiores (Igarapé)
     */
    private function ehCursoSuperiorValido($nome) {
        $nome = $this->normalizarTexto($nome);
        
        $indicadoresSuperior = [
            'graduacao', 'bacharelado', 'licenciatura', 'superior',
            'administracao', 'direito', 'enfermagem', 'pedagogia',
            'contabilidade', 'psicologia', 'engenharia', 'medicina',
            'farmacia', 'fisioterapia', 'nutricao', 'biomedicina'
        ];
        
        foreach ($indicadoresSuperior as $indicador) {
            if (strpos($nome, $indicador) !== false) {
                return true;
            }
        }
        
        // Verifica padrões de disciplinas para excluir
        $disciplinas = [
            'disciplina', 'modulo', 'estagio', 'metodologia',
            'introducao', 'fundamentos', 'pratica'
        ];
        
        foreach ($disciplinas as $disciplina) {
            if (strpos($nome, $disciplina) !== false) {
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Validação para cursos profissionalizantes
     */
    private function ehCursoProfissionalizanteValido($nome) {
        $nome = $this->normalizarTexto($nome);
        
        $indicadoresProfissionalizante = [
            'profissionalizante', 'capacitacao', 'qualificacao',
            'aperfeicoamento', 'especializacao', 'tecnico',
            'operador', 'auxiliar', 'assistente', 'nr-', 'nr ',
            'seguranca', 'trabalho', 'industrial'
        ];
        
        foreach ($indicadoresProfissionalizante as $indicador) {
            if (strpos($nome, $indicador) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Validação flexível (usa palavras obrigatórias da configuração)
     */
    private function ehCursoFlexivelValido($nome, $config) {
        $nome = $this->normalizarTexto($nome);
        
        // Se tem palavras obrigatórias, verifica
        if (!empty($config['palavras_obrigatorias'])) {
            foreach ($config['palavras_obrigatorias'] as $obrigatoria) {
                if (strpos($nome, $obrigatoria) !== false) {
                    return true;
                }
            }
            return false;
        }
        
        // Validação básica - exclui disciplinas óbvias
        $palavrasProibidas = [
            'disciplina', 'modulo', 'estagio', 'introducao',
            'metodologia', 'pratica supervisionada', 'trabalho de conclusao'
        ];
        
        foreach ($palavrasProibidas as $proibida) {
            if (strpos($nome, $proibida) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Verifica se curso é válido para o polo específico (para alunos matriculados)
     */
    private function ehCursoValidoParaPolo($course) {
        $nome = strtolower($course['fullname']);
        
        // Exclui apenas disciplinas óbvias
        $disciplinasObvias = [
            'estagio supervisionado', 'metodologia cientifica',
            'trabalho de conclusao', 'atividades complementares',
            'pratica supervisionada'
        ];
        
        foreach ($disciplinasObvias as $disciplina) {
            if (strpos($nome, $disciplina) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Formata curso de categoria (hierárquico)
     */
    private function formatarCursoCategoria($category, $categoriaPai) {
        return [
            'id' => 'cat_' . $category['id'],
            'categoria_original_id' => $category['id'],
            'tipo' => 'categoria_curso',
            'nome' => trim($category['name']),
            'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
            'categoria_id' => $category['parent'],
            'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
            'data_inicio' => null,
            'data_fim' => null,
            'total_alunos' => $category['coursecount'] ?? 0,
            'formato' => 'category',
            'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
            'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}",
            'parent_name' => $categoriaPai ? trim($categoriaPai['name']) : null
        ];
    }
    
    /**
     * Formata curso tradicional
     */
    private function formatarCursoTradicional($course) {
        return [
            'id' => $course['id'],
            'tipo' => 'curso',
            'nome' => $course['fullname'],
            'nome_curto' => $course['shortname'] ?? $this->gerarNomeCurtoCategoria($course['fullname']),
            'categoria_id' => $course['categoryid'] ?? null,
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
    
    /**
     * Remove duplicatas da lista de cursos
     */
    private function removerDuplicatas($cursos) {
        $nomes = [];
        $cursosUnicos = [];
        
        foreach ($cursos as $curso) {
            $nomeNormalizado = $this->normalizarTexto($curso['nome']);
            
            if (!in_array($nomeNormalizado, $nomes)) {
                $nomes[] = $nomeNormalizado;
                $cursosUnicos[] = $curso;
            }
        }
        
        return $cursosUnicos;
    }
    
    /**
     * Normaliza texto removendo acentos e convertendo para minúsculas
     */
    private function normalizarTexto($texto) {
        // Remove espaços extras
        $texto = trim($texto);
        
        // Converte para minúsculas primeiro
        $texto = mb_strtolower($texto, 'UTF-8');
        
        // Remove acentos - LISTA COMPLETA
        $acentos = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n'
        ];
        
        return str_replace(array_keys($acentos), array_values($acentos), $texto);
    }
    
    /**
     * Gera nome curto para categoria
     */
    private function gerarNomeCurtoCategoria($nome) {
        // Remove prefixos comuns
        $nome = preg_replace('/^(técnico em |tecnico em |graduação em |graduacao em |superior em )/i', '', $nome);
        
        $palavras = explode(' ', strtoupper(trim($nome)));
        $nomeCurto = '';
        $ignorar = ['DE', 'DA', 'DO', 'EM', 'E', 'OU', 'NO', 'NA'];
        
        foreach ($palavras as $palavra) {
            if (strlen($palavra) > 2 && !in_array($palavra, $ignorar)) {
                $nomeCurto .= substr($palavra, 0, 3);
                if (strlen($nomeCurto) >= 9) break;
            }
        }
        
        return substr($nomeCurto, 0, 10) ?: 'CURSO';
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