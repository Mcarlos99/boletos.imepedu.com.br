<?php
/**
 * Sistema de Boletos IMED - API do Moodle IMPLEMENTAÇÃO CORRETA
 * Arquivo: src/MoodleAPI.php (SUBSTITUIR COMPLETAMENTE)
 * 
 * Implementação baseada na análise real da estrutura de cada polo
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
     * Lista todos os cursos disponíveis - IMPLEMENTAÇÃO CORRETA POR POLO
     */
    public function listarTodosCursos() {
        $cacheKey = "todos_cursos_corretos_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            error_log("MoodleAPI: 🎯 BUSCA CORRETA DE CURSOS para {$this->subdomain}");
            
            // Implementação específica baseada na estrutura de cada polo
            $cursosEncontrados = $this->buscarCursosEspecificoPorPolo();
            
            error_log("MoodleAPI: ✅ CURSOS ENCONTRADOS: " . count($cursosEncontrados));
            
            // Cache por 30 minutos
            $this->cache[$cacheKey] = $cursosEncontrados;
            
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            $this->logError("Erro ao listar cursos", $e);
            return []; // Retorna array vazio ao invés de cursos de emergência
        }
    }
    
    /**
     * 🎯 IMPLEMENTAÇÃO ESPECÍFICA POR POLO - Baseada na estrutura real
     */
    private function buscarCursosEspecificoPorPolo() {
        $subdomain = $this->subdomain;
        
        // BREU BRANCO: Estrutura hierárquica - subcategorias são cursos
        if (strpos($subdomain, 'breubranco') !== false) {
            return $this->buscarCursosBreuBranco();
        }
        
        // IGARAPÉ-MIRI: Estrutura tradicional - cursos diretos
        if (strpos($subdomain, 'igarape') !== false) {
            return $this->buscarCursosIgarape();
        }
        
        // OUTROS POLOS: Detecção automática
        return $this->buscarCursosDeteccaoAutomatica();
    }
    
    /**
     * 🔧 BREU BRANCO: Busca subcategorias de "CURSOS TÉCNICOS"
     */
    private function buscarCursosBreuBranco() {
        try {
            error_log("MoodleAPI: 🎯 BREU BRANCO - Buscando subcategorias técnicas");
            
            // Busca todas as categorias
            $allCategories = $this->callMoodleFunction('core_course_get_categories');
            
            error_log("MoodleAPI: 📂 Total de categorias encontradas: " . count($allCategories));
            
            if (empty($allCategories)) {
                error_log("MoodleAPI: ❌ Nenhuma categoria encontrada");
                return [];
            }
            
            // Log de todas as categorias para debug
            foreach ($allCategories as $cat) {
                error_log("MoodleAPI: 📁 Categoria: ID={$cat['id']}, Nome='{$cat['name']}', Parent={$cat['parent']}, Cursos=" . ($cat['coursecount'] ?? 0));
            }
            
            // Encontra a categoria pai "CURSOS TÉCNICOS"
            $categoriaCursosTecnicos = null;
            foreach ($allCategories as $category) {
                $nomeCategoria = strtolower(trim($category['name']));
                if (strpos($nomeCategoria, 'cursos técnicos') !== false || 
                    strpos($nomeCategoria, 'cursos tecnicos') !== false ||
                    $nomeCategoria === 'cursos técnicos' ||
                    $nomeCategoria === 'cursos tecnicos') {
                    $categoriaCursosTecnicos = $category;
                    error_log("MoodleAPI: ✅ Categoria pai encontrada: " . $category['name'] . " (ID: " . $category['id'] . ")");
                    break;
                }
            }
            
            if (!$categoriaCursosTecnicos) {
                error_log("MoodleAPI: ❌ Categoria 'CURSOS TÉCNICOS' não encontrada");
                return [];
            }
            
            $cursosEncontrados = [];
            
            // Busca subcategorias (que são os cursos técnicos reais)
            foreach ($allCategories as $category) {
                // Verifica se é subcategoria da categoria "CURSOS TÉCNICOS"
                if ($category['parent'] == $categoriaCursosTecnicos['id']) {
                    
                    // Valida se é realmente um curso técnico
                    $nomeSubcategoria = trim($category['name']); // Remove espaços extras
                    if ($this->ehCursoTecnicoValido($nomeSubcategoria)) {
                        $cursosEncontrados[] = [
                            'id' => 'cat_' . $category['id'],
                            'categoria_original_id' => $category['id'],
                            'tipo' => 'categoria_curso',
                            'nome' => $nomeSubcategoria, // Usa nome limpo
                            'nome_curto' => $this->gerarNomeCurtoCategoria($nomeSubcategoria),
                            'categoria_id' => $category['parent'],
                            'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                            'data_inicio' => null,
                            'data_fim' => null,
                            'total_alunos' => $category['coursecount'] ?? 0,
                            'formato' => 'category',
                            'summary' => isset($category['description']) ? strip_tags($category['description']) : '',
                            'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}",
                            'parent_name' => trim($categoriaCursosTecnicos['name'])
                        ];
                        
                        error_log("MoodleAPI: ✅ CURSO TÉCNICO: " . $nomeSubcategoria . " (ID: " . $category['id'] . ")");
                    } else {
                        error_log("MoodleAPI: ❌ Rejeitado: " . $nomeSubcategoria . " (não é curso técnico válido)");
                    }
                }
            }
            
            error_log("MoodleAPI: 🏆 BREU BRANCO - Total encontrado: " . count($cursosEncontrados));
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro Breu Branco: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 📚 IGARAPÉ-MIRI: Busca cursos tradicionais com filtro
     */
    private function buscarCursosIgarape() {
        try {
            error_log("MoodleAPI: 🎯 IGARAPÉ - Buscando cursos tradicionais");
            
            $courses = $this->callMoodleFunction('core_course_get_courses');
            $cursosEncontrados = [];
            
            if (!empty($courses)) {
                foreach ($courses as $course) {
                    // Pula o curso "Site" (ID 1)
                    if ($course['id'] == 1) continue;
                    
                    // Verifica se é curso visível e válido
                    if (isset($course['visible']) && $course['visible'] == 1) {
                        if ($this->ehCursoTradicionalValido($course)) {
                            $cursosEncontrados[] = [
                                'id' => $course['id'],
                                'tipo' => 'curso',
                                'nome' => $course['fullname'],
                                'nome_curto' => $course['shortname'] ?? '',
                                'categoria_id' => $course['categoryid'] ?? null,
                                'visivel' => true,
                                'data_inicio' => isset($course['startdate']) && $course['startdate'] > 0 
                                    ? date('Y-m-d', $course['startdate']) : null,
                                'data_fim' => isset($course['enddate']) && $course['enddate'] > 0 
                                    ? date('Y-m-d', $course['enddate']) : null,
                                'total_alunos' => $course['enrolledusercount'] ?? 0,
                                'formato' => $course['format'] ?? 'topics',
                                'summary' => isset($course['summary']) ? strip_tags($course['summary']) : '',
                                'url' => "https://{$this->subdomain}/course/view.php?id={$course['id']}"
                            ];
                            
                            error_log("MoodleAPI: ✅ CURSO IGARAPÉ: " . $course['fullname']);
                        } else {
                            error_log("MoodleAPI: ❌ Rejeitado: " . $course['fullname'] . " (não é curso válido)");
                        }
                    }
                }
            }
            
            error_log("MoodleAPI: 🏆 IGARAPÉ - Total encontrado: " . count($cursosEncontrados));
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro Igarapé: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 🔍 OUTROS POLOS: Detecção automática da estrutura
     */
    private function buscarCursosDeteccaoAutomatica() {
        try {
            error_log("MoodleAPI: 🎯 DETECÇÃO AUTOMÁTICA para {$this->subdomain}");
            
            // Testa primeiro categorias
            $cursosCategorias = $this->tentarBuscarPorCategorias();
            
            // Testa cursos tradicionais
            $cursosTracionais = $this->tentarBuscarCursosTracionais();
            
            // Decide qual usar baseado na quantidade e qualidade
            if (count($cursosCategorias) > count($cursosTracionais) && count($cursosCategorias) > 0) {
                error_log("MoodleAPI: 📂 Usando estrutura de CATEGORIAS (" . count($cursosCategorias) . " encontrados)");
                return $cursosCategorias;
            } elseif (count($cursosTracionais) > 0) {
                error_log("MoodleAPI: 📚 Usando estrutura TRADICIONAL (" . count($cursosTracionais) . " encontrados)");
                return $cursosTracionais;
            }
            
            error_log("MoodleAPI: ⚠️ Nenhuma estrutura válida encontrada");
            return [];
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro detecção automática: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Tenta buscar por categorias (para polos com estrutura similar ao Breu Branco)
     */
    private function tentarBuscarPorCategorias() {
        try {
            $allCategories = $this->callMoodleFunction('core_course_get_categories');
            $cursosEncontrados = [];
            
            if (!empty($allCategories)) {
                foreach ($allCategories as $category) {
                    // Pula categoria raiz
                    if ($category['id'] == 1 || $category['parent'] == 0) continue;
                    
                    // Verifica se categoria parece ser um curso
                    if ($this->categoriaPareceCurso($category)) {
                        $cursosEncontrados[] = [
                            'id' => 'cat_' . $category['id'],
                            'categoria_original_id' => $category['id'],
                            'tipo' => 'categoria_curso',
                            'nome' => $category['name'],
                            'nome_curto' => $this->gerarNomeCurtoCategoria($category['name']),
                            'categoria_id' => $category['parent'],
                            'visivel' => isset($category['visible']) ? ($category['visible'] == 1) : true,
                            'total_alunos' => $category['coursecount'] ?? 0,
                            'url' => "https://{$this->subdomain}/course/index.php?categoryid={$category['id']}"
                        ];
                    }
                }
            }
            
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Tenta buscar cursos tradicionais
     */
    private function tentarBuscarCursosTracionais() {
        try {
            $courses = $this->callMoodleFunction('core_course_get_courses');
            $cursosEncontrados = [];
            
            if (!empty($courses)) {
                foreach ($courses as $course) {
                    if ($course['id'] == 1) continue;
                    
                    if (isset($course['visible']) && $course['visible'] == 1) {
                        if ($this->ehCursoTradicionalValido($course)) {
                            $cursosEncontrados[] = [
                                'id' => $course['id'],
                                'tipo' => 'curso',
                                'nome' => $course['fullname'],
                                'nome_curto' => $course['shortname'] ?? '',
                                'categoria_id' => $course['categoryid'] ?? null,
                                'visivel' => true,
                                'total_alunos' => $course['enrolledusercount'] ?? 0,
                                'url' => "https://{$this->subdomain}/course/view.php?id={$course['id']}"
                            ];
                        }
                    }
                }
            }
            
            return $cursosEncontrados;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Normaliza texto removendo acentos e convertendo para minúsculas
     */
    private function normalizarTexto($texto) {
        // Remove espaços extras
        $texto = trim($texto);
        
        // Converte para minúsculas primeiro
        $texto = mb_strtolower($texto, 'UTF-8');
        
        // Remove acentos
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
     * ✅ Verifica se é curso técnico válido (para Breu Branco)
     */
    private function ehCursoTecnicoValido($nome) {
        $nome = $this->normalizarTexto($nome); // Usa normalização consistente
        
        error_log("🔍 Validando curso técnico: '{$nome}'");
        
        // Deve conter "tecnico" obrigatoriamente (sem acento)
        if (strpos($nome, 'tecnico') === false) {
            error_log("❌ Rejeitado: não contém 'tecnico'");
            return false;
        }
        
        // Lista de palavras-chave dos cursos técnicos do Breu Branco (sem acentos)
        $cursosValidos = [
            'enfermagem',
            'eletromecanica', 
            'eletrotecnica', 
            'seguranca',
            'trabalho'
        ];
        
        // Verifica se contém pelo menos uma palavra-chave válida
        foreach ($cursosValidos as $palavraChave) {
            if (strpos($nome, $palavraChave) !== false) {
                error_log("✅ Curso técnico válido: contém '{$palavraChave}'");
                return true;
            }
        }
        
        // Palavras que invalidam (disciplinas dentro dos cursos)
        $palavrasInvalidas = [
            'higiene', 'medicina', 'psicologia', 'aplicada',
            'modulo', 'estagio', 'introducao', 'nocoes'
        ];
        
        foreach ($palavrasInvalidas as $invalida) {
            if (strpos($nome, $invalida) !== false) {
                error_log("❌ Rejeitado: contém palavra inválida '{$invalida}'");
                return false;
            }
        }
        
        // Se contém "tecnico" mas não encontrou palavra-chave específica,
        // aceita mesmo assim (pode ser variação de nome)
        error_log("✅ Curso técnico aceito: contém 'tecnico' e passou pelos filtros");
        return true;
    }
    
    /**
     * ✅ Verifica se é curso tradicional válido
     */
    private function ehCursoTradicionalValido($course) {
        $nome = strtolower($course['fullname']);
        
        // Palavras que indicam disciplinas (devem ser excluídas)
        $indicadoresDisciplina = [
            'módulo', 'modulo', 'disciplina', 'matéria', 'materia',
            'estágio', 'estagio', 'supervisionado',
            'introdução', 'introducao', 'noções', 'nocoes',
            'higiene', 'medicina do trabalho', 'psicologia aplicada',
            'informática aplicada', 'informatica aplicada',
            'metodologia', 'português', 'portugues', 'matemática', 'matematica',
            'ética', 'etica', 'instrumental'
        ];
        
        // Se contém indicador de disciplina, rejeita
        foreach ($indicadoresDisciplina as $indicador) {
            if (strpos($nome, $indicador) !== false) {
                return false;
            }
        }
        
        // Palavras que indicam cursos válidos
        $indicadoresCurso = [
            'técnico em', 'tecnico em',
            'superior em', 'graduação em', 'graduacao em',
            'bacharelado', 'licenciatura',
            'enfermagem', 'administração', 'administracao',
            'direito', 'contabilidade', 'pedagogia'
        ];
        
        foreach ($indicadoresCurso as $indicador) {
            if (strpos($nome, $indicador) !== false) {
                return true;
            }
        }
        
        // Se tem muitos alunos, provavelmente é curso
        $totalAlunos = $course['enrolledusercount'] ?? 0;
        if ($totalAlunos > 10) {
            return true;
        }
        
        // Se nome é muito longo, provavelmente é disciplina
        if (count(explode(' ', $nome)) > 6) {
            return false;
        }
        
        return true; // Default: aceita se passou por todos os filtros
    }
    
    /**
     * Verifica se categoria parece ser um curso
     */
    private function categoriaPareceCurso($category) {
        $nome = strtolower($category['name']);
        
        // Deve ter cursos dentro
        if (($category['coursecount'] ?? 0) == 0) {
            return false;
        }
        
        // Não deve ter subcategorias (ser folha da árvore)
        // Isso seria verificado com uma busca adicional, simplificamos aqui
        
        // Nomes que indicam cursos
        $indicadoresCurso = [
            'técnico', 'tecnico', 'superior', 'graduação', 'graduacao',
            'enfermagem', 'administração', 'administracao', 'contabilidade',
            'direito', 'pedagogia', 'engenharia'
        ];
        
        foreach ($indicadoresCurso as $indicador) {
            if (strpos($nome, $indicador) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verifica se curso é válido para o polo específico
     */
    private function ehCursoValidoParaPolo($course) {
        // Para alunos matriculados, usa critérios menos rigorosos
        $nome = strtolower($course['fullname']);
        
        // Exclui apenas disciplinas óbvias
        $disciplinasObvias = [
            'estágio supervisionado', 'estagio supervisionado',
            'módulo i', 'modulo i', 'módulo ii', 'modulo ii',
            'higiene e medicina', 'psicologia aplicada',
            'metodologia científica', 'metodologia cientifica'
        ];
        
        foreach ($disciplinasObvias as $disciplina) {
            if (strpos($nome, $disciplina) !== false) {
                return false;
            }
        }
        
        return true; // Mais permissivo para cursos de alunos
    }
    
    /**
     * Gera nome curto para categoria
     */
    private function gerarNomeCurtoCategoria($nome) {
        // Remove "Técnico em" e pega palavras principais
        $nome = str_replace(['Técnico em ', 'técnico em ', 'Tecnico em ', 'tecnico em '], '', $nome);
        
        $palavras = explode(' ', strtoupper($nome));
        $nomeCurto = '';
        
        foreach ($palavras as $palavra) {
            if (strlen($palavra) > 2 && !in_array(strtolower($palavra), ['DE', 'DA', 'DO', 'EM', 'E', 'OU'])) {
                $nomeCurto .= substr($palavra, 0, 3);
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