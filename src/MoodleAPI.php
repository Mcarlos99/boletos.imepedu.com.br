<?php
/**
 * Sistema de Boletos IMED - API do Moodle CORRIGIDA
 * Arquivo: src/MoodleAPI.php (SUBSTITUIR - FILTRO CORRETO)
 * 
 * CORREÇÃO: Filtra apenas cursos principais, excluindo disciplinas/matérias
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
                        
                        // 🔥 FILTRO CRÍTICO: Só pega cursos principais
                        if ($this->ehCursoPrincipal($course)) {
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
     * 🔥 FUNÇÃO CRÍTICA: Determina se é um curso principal ou disciplina
     */
    private function ehCursoPrincipal($course) {
        $nome = strtolower($course['fullname']);
        $nomeShort = strtolower($course['shortname'] ?? '');
        
        // ❌ EXCLUSÕES: Palavras que indicam disciplinas/matérias
        $disciplinasIndicadores = [
            // Disciplinas específicas
            'estágio', 'estagio', 'supervisionado',
            'introdução', 'introducao', 'noções', 'nocoes',
            'higiene', 'medicina', 'psicologia', 'aplicada',
            'informática', 'informatica', 'aplicada',
            'administração de unidade', 'administracao de unidade',
            'desenvolvimento interpessoal',
            'construção civil', 'construcao civil',
            
            // Tipos de disciplina
            'módulo', 'modulo', 'unidade', 'disciplina',
            'matéria', 'materia', 'aula', 'seminário', 'seminario',
            'workshop', 'palestra', 'treinamento',
            
            // Indicadores temporais (disciplinas são específicas)
            'semestre', 'período', 'periodo', 'bimestre',
            'trimestre', 'etapa', 'fase',
            
            // Áreas muito específicas
            'saúde e segurança', 'saude e seguranca',
            'meio ambiente', 'recursos humanos',
            'gestão de', 'gestao de', 'controle de',
            
            // Palavras que indicam sub-areas
            'fundamentos de', 'principios de', 'princípios de',
            'conceitos de', 'teoria de', 'prática de', 'pratica de'
        ];
        
        // Verifica se contém indicadores de disciplina
        foreach ($disciplinasIndicadores as $indicador) {
            if (strpos($nome, $indicador) !== false) {
                error_log("🚫 FILTRADO como disciplina: '{$course['fullname']}' (contém: {$indicador})");
                return false;
            }
        }
        
        // ✅ INCLUSÕES: Palavras que indicam cursos principais
        $cursosIndicadores = [
            'técnico em', 'tecnico em',
            'superior em', 'graduação em', 'graduacao em',
            'bacharelado em', 'licenciatura em',
            'tecnólogo em', 'tecnologo em',
            'especialização em', 'especializacao em',
            'mestrado em', 'doutorado em'
        ];
        
        // Verifica se contém indicadores de curso principal
        foreach ($cursosIndicadores as $indicador) {
            if (strpos($nome, $indicador) !== false) {
                error_log("✅ ACEITO como curso: '{$course['fullname']}' (contém: {$indicador})");
                return true;
            }
        }
        
        // 📋 ANÁLISE AVANÇADA: Características de curso vs disciplina
        
        // 1. Cursos têm nomes mais diretos e concisos
        $palavrasNome = explode(' ', $nome);
        if (count($palavrasNome) > 8) {
            error_log("🚫 FILTRADO: '{$course['fullname']}' (nome muito longo - parece disciplina)");
            return false;
        }
        
        // 2. Códigos de disciplina (ex: "ADM101", "ENF201")
        if (preg_match('/^[A-Z]{2,4}\d{2,4}/', $nomeShort)) {
            error_log("🚫 FILTRADO: '{$course['fullname']}' (código de disciplina no shortname)");
            return false;
        }
        
        // 3. Verifica se nome é muito genérico (provavelmente disciplina)
        $nomesGenericos = [
            'ética', 'etica', 'comunicação', 'comunicacao',
            'português', 'portugues', 'matemática', 'matematica',
            'física', 'fisica', 'química', 'quimica',
            'biologia', 'anatomia', 'fisiologia',
            'estatística', 'estatistica', 'metodologia'
        ];
        
        foreach ($nomesGenericos as $generico) {
            if ($nome === $generico || strpos($nome, $generico) === 0) {
                error_log("🚫 FILTRADO: '{$course['fullname']}' (nome muito genérico)");
                return false;
            }
        }
        
        // 4. Polo específico: Breu Branco
        if (strpos($this->subdomain, 'breubranco') !== false) {
            // Para Breu Branco, aceita apenas se tem "técnico" e não tem palavras de disciplina
            if (strpos($nome, 'técnico') !== false || strpos($nome, 'tecnico') !== false) {
                error_log("✅ ACEITO (Breu Branco): '{$course['fullname']}' (curso técnico)");
                return true;
            }
        }
        
        // 5. Se chegou até aqui e tem características de curso, aceita
        $caracteristicasCurso = [
            'enfermagem' => !strpos($nome, 'unidade'), // Aceita "Enfermagem" mas não "Administração de Unidade de Enfermagem"
            'administração' => !strpos($nome, 'de unidade'), // Aceita "Administração" mas não "Administração de Unidade"
            'eletromecânica' => true,
            'eletromecanica' => true,
            'eletrotécnica' => true,
            'eletrotecnica' => true,
            'segurança do trabalho' => true,
            'seguranca do trabalho' => true,
            'contabilidade' => true,
            'direito' => true,
            'pedagogia' => true
        ];
        
        foreach ($caracteristicasCurso as $caracteristica => $condicao) {
            if (strpos($nome, $caracteristica) !== false && $condicao) {
                error_log("✅ ACEITO (característica): '{$course['fullname']}' (contém: {$caracteristica})");
                return true;
            }
        }
        
        // 🔴 DEFAULT: Se não tem certeza, rejeita (mais seguro)
        error_log("🚫 FILTRADO (padrão): '{$course['fullname']}' (não identificado como curso principal)");
        return false;
    }
    
    /**
     * Lista todos os cursos disponíveis (VERSÃO CORRIGIDA)
     */
    public function listarTodosCursos() {
        $cacheKey = "todos_cursos_filtrados_{$this->subdomain}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            error_log("MoodleAPI: 🎯 INICIANDO BUSCA FILTRADA DE CURSOS para {$this->subdomain}");
            
            // Método 1: Busca cursos tradicionais (com filtro)
            $cursosTracionais = $this->buscarCursosTracionaisFiltrados();
            
            // Método 2: Busca categorias como cursos (específico para alguns polos)
            $cursosCategorias = $this->buscarCursosComoCategoriasFiltrado();
            
            // Combina e remove duplicatas
            $todosCursos = $this->combinarEFiltrarCursos($cursosTracionais, $cursosCategorias);
            
            error_log("MoodleAPI: ✅ CURSOS FINAIS FILTRADOS: " . count($todosCursos));
            
            // Log de cada curso aceito
            foreach ($todosCursos as $curso) {
                error_log("MoodleAPI: 📚 CURSO FINAL: " . $curso['nome'] . " (Tipo: " . $curso['tipo'] . ")");
            }
            
            // Cache por 30 minutos
            $this->cache[$cacheKey] = $todosCursos;
            
            return $todosCursos;
            
        } catch (Exception $e) {
            $this->logError("Erro ao listar cursos filtrados", $e);
            return $this->getCursosEmergencia();
        }
    }
    
    /**
     * Busca cursos tradicionais COM FILTRO
     */
    private function buscarCursosTracionaisFiltrados() {
        try {
            $courses = $this->callMoodleFunction('core_course_get_courses');
            $cursos = [];
            
            if (!empty($courses)) {
                foreach ($courses as $course) {
                    // Pula o curso "Site" (ID 1)
                    if ($course['id'] == 1) continue;
                    
                    // 🔥 APLICA FILTRO CRÍTICO
                    if ($this->ehCursoPrincipal($course)) {
                        $cursos[] = [
                            'id' => $course['id'],
                            'tipo' => 'curso',
                            'nome' => $course['fullname'],
                            'nome_curto' => $course['shortname'] ?? '',
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
                }
            }
            
            error_log("MoodleAPI: 📋 Cursos tradicionais filtrados: " . count($cursos));
            return $cursos;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro ao buscar cursos tradicionais: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca categorias como cursos (VERSÃO FILTRADA)
     */
    private function buscarCursosComoCategoriasFiltrado() {
        try {
            // Para alguns polos, categorias específicas são tratadas como cursos
            if (strpos($this->subdomain, 'breubranco') === false) {
                return []; // Só aplica para Breu Branco por enquanto
            }
            
            $categories = $this->callMoodleFunction('core_course_get_categories');
            $cursos = [];
            
            if (!empty($categories)) {
                // Busca categoria "CURSOS TÉCNICOS"
                $categoriaPai = null;
                foreach ($categories as $cat) {
                    if (strpos(strtolower($cat['name']), 'cursos técnicos') !== false ||
                        strpos(strtolower($cat['name']), 'cursos tecnicos') !== false) {
                        $categoriaPai = $cat;
                        break;
                    }
                }
                
                if ($categoriaPai) {
                    // Busca subcategorias da categoria pai
                    foreach ($categories as $category) {
                        if ($category['parent'] == $categoriaPai['id']) {
                            // Verifica se é realmente um curso técnico
                            if ($this->ehCategoriaCursoTecnico($category)) {
                                $cursos[] = [
                                    'id' => 'cat_' . $category['id'],
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
                                    'parent_name' => $categoriaPai['name']
                                ];
                            }
                        }
                    }
                }
            }
            
            error_log("MoodleAPI: 📂 Categorias como cursos filtradas: " . count($cursos));
            return $cursos;
            
        } catch (Exception $e) {
            error_log("MoodleAPI: ❌ Erro ao buscar categorias: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verifica se categoria é realmente um curso técnico
     */
    private function ehCategoriaCursoTecnico($category) {
        $nome = strtolower($category['name']);
        
        // Deve conter "técnico"
        if (strpos($nome, 'técnico') === false && strpos($nome, 'tecnico') === false) {
            return false;
        }
        
        // Nomes aceitos para cursos técnicos
        $cursosValidos = [
            'técnico em enfermagem',
            'tecnico em enfermagem',
            'técnico em eletromecânica',
            'tecnico em eletromecanica',
            'técnico em eletrotécnica',
            'tecnico em eletrotecnica',
            'técnico em segurança do trabalho',
            'tecnico em seguranca do trabalho',
            'técnico em administração',
            'tecnico em administracao',
            'técnico em informática',
            'tecnico em informatica'
        ];
        
        foreach ($cursosValidos as $valido) {
            if (strpos($nome, $valido) !== false) {
                error_log("✅ CATEGORIA ACEITA: " . $category['name']);
                return true;
            }
        }
        
        error_log("🚫 CATEGORIA REJEITADA: " . $category['name']);
        return false;
    }
    
    /**
     * Combina e filtra cursos, removendo duplicatas
     */
    private function combinarEFiltrarCursos($cursosTracionais, $cursosCategorias) {
        $todosCursos = array_merge($cursosTracionais, $cursosCategorias);
        
        // Remove duplicatas baseado no nome
        $cursosUnicos = [];
        $nomesJaAdicionados = [];
        
        foreach ($todosCursos as $curso) {
            $nomeNormalizado = strtolower(trim($curso['nome']));
            
            // Remove palavras comuns para comparação
            $nomeParaComparacao = str_replace([
                'técnico em ', 'tecnico em ', 'curso de ', 'curso '
            ], '', $nomeNormalizado);
            
            if (!in_array($nomeParaComparacao, $nomesJaAdicionados)) {
                $cursosUnicos[] = $curso;
                $nomesJaAdicionados[] = $nomeParaComparacao;
                error_log("✅ CURSO ÚNICO ADICIONADO: " . $curso['nome']);
            } else {
                error_log("🔄 CURSO DUPLICADO IGNORADO: " . $curso['nome']);
            }
        }
        
        // Ordena por nome
        usort($cursosUnicos, function($a, $b) {
            return strcmp($a['nome'], $b['nome']);
        });
        
        return $cursosUnicos;
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
        
        return substr($nomeCurto, 0, 10) ?: 'TEC';
    }
    
    /**
     * Retorna cursos de emergência se tudo falhar
     */
    private function getCursosEmergencia() {
        $subdomain = $this->subdomain;
        
        // Cursos específicos baseado no polo
        if (strpos($subdomain, 'breubranco') !== false) {
            return [
                ['id' => 'emg_001', 'nome' => 'Técnico em Enfermagem', 'nome_curto' => 'TEC_ENF', 'tipo' => 'emergencia'],
                ['id' => 'emg_002', 'nome' => 'Técnico em Eletromecânica', 'nome_curto' => 'TEC_ELE', 'tipo' => 'emergencia'],
                ['id' => 'emg_003', 'nome' => 'Técnico em Eletrotécnica', 'nome_curto' => 'TEC_ELT', 'tipo' => 'emergencia'],
                ['id' => 'emg_004', 'nome' => 'Técnico em Segurança do Trabalho', 'nome_curto' => 'TEC_SEG', 'tipo' => 'emergencia']
            ];
        }
        
        // Outros polos
        return [
            ['id' => 'emg_101', 'nome' => 'Administração', 'nome_curto' => 'ADM', 'tipo' => 'emergencia'],
            ['id' => 'emg_102', 'nome' => 'Enfermagem', 'nome_curto' => 'ENF', 'tipo' => 'emergencia'],
            ['id' => 'emg_103', 'nome' => 'Direito', 'nome_curto' => 'DIR', 'tipo' => 'emergencia']
        ];
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