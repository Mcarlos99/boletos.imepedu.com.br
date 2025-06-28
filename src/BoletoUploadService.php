<?php
/**
 * Sistema de Boletos IMED - Serviço de Upload de Boletos COM UPLOAD MÚLTIPLO
 * Arquivo: src/BoletoUploadService.php - VERSÃO COMPLETA CORRIGIDA
 * 
 * 🆕 NOVIDADE: Suporte a múltiplos uploads para um único aluno
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AlunoService.php';

class BoletoUploadService {
    
    private $db;
    private $uploadDir;
    private $maxFileSize;
    private $allowedTypes;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
        $this->uploadDir = __DIR__ . '/../uploads/boletos/';
        $this->maxFileSize = 5 * 1024 * 1024; // 5MB
        $this->allowedTypes = ['application/pdf'];
        
        // Cria diretório se não existir
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    /**
     * Processa upload individual
     */
    public function processarUploadIndividual($post, $files) {
        try {
            $this->db->beginTransaction();
            
            // Validações
            $dadosValidados = $this->validarDadosIndividual($post, $files);
            
            // Verifica se aluno existe e está matriculado no curso
            $aluno = $this->verificarAlunoECurso($dadosValidados['cpf'], $dadosValidados['curso_id'], $dadosValidados['polo']);
            
            // Verifica se número do boleto já existe
            $this->verificarNumeroBoletoUnico($dadosValidados['numero_boleto']);
            
            // Processa upload do arquivo
            $nomeArquivo = $this->processarUploadArquivo($files['arquivo_pdf'], $dadosValidados['numero_boleto']);
            
            // Salva boleto no banco
            $boletoId = $this->salvarBoleto([
                'aluno_id' => $aluno['id'],
                'curso_id' => $dadosValidados['curso_id'],
                'numero_boleto' => $dadosValidados['numero_boleto'],
                'valor' => $dadosValidados['valor'],
                'vencimento' => $dadosValidados['vencimento'],
                'descricao' => $dadosValidados['descricao'],
                'arquivo_pdf' => $nomeArquivo,
                'status' => 'pendente',
                'admin_id' => $_SESSION['admin_id'] ?? null
            ]);
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('upload_individual', $boletoId, "Boleto {$dadosValidados['numero_boleto']} enviado para {$aluno['nome']}");
            
            return [
                'success' => true,
                'message' => 'Boleto enviado com sucesso!',
                'boleto_id' => $boletoId
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            
            // Remove arquivo se foi criado
            if (isset($nomeArquivo) && file_exists($this->uploadDir . $nomeArquivo)) {
                unlink($this->uploadDir . $nomeArquivo);
            }
            
            error_log("Erro no upload individual: " . $e->getMessage());
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * 🆕 NOVO: Processa upload múltiplo para um único aluno
     */
    public function processarUploadMultiploAluno($post, $files) {
        try {
            $this->db->beginTransaction();
            
            error_log("🆕 UPLOAD MÚLTIPLO: Iniciando processamento");
            
            // Validações básicas
            $dadosBase = $this->validarDadosMultiploAluno($post, $files);
            
            // Verifica se aluno existe e está matriculado no curso
            $aluno = $this->verificarAlunoECurso($dadosBase['cpf'], $dadosBase['curso_id'], $dadosBase['polo']);
            
            error_log("UPLOAD MÚLTIPLO: Aluno validado - {$aluno['nome']} (ID: {$aluno['id']})");
            
            // Processa dados dos arquivos individuais
            $dadosArquivos = $this->extrairDadosArquivosMultiplo($post);
            
            error_log("UPLOAD MÚLTIPLO: " . count($dadosArquivos) . " arquivos para processar");
            
            $sucessos = 0;
            $erros = 0;
            $detalhesErros = [];
            $boletosGerados = [];
            
            // Processa cada arquivo
            if (isset($files['arquivos_multiplos'])) {
                $arquivos = $this->organizarArquivosMultiplo($files['arquivos_multiplos']);
                
                foreach ($arquivos as $index => $arquivo) {
                    try {
                        error_log("UPLOAD MÚLTIPLO: Processando arquivo {$index}: {$arquivo['name']}");
                        
                        // Busca dados específicos deste arquivo
                        $dadosArquivo = $dadosArquivos[$index] ?? null;
                        
                        if (!$dadosArquivo) {
                            throw new Exception("Dados não encontrados para o arquivo {$arquivo['name']}");
                        }
                        
                        // Valida dados obrigatórios
                        $this->validarDadosArquivoIndividual($dadosArquivo, $arquivo['name']);
                        
                        // Verifica se número do boleto já existe
                        $this->verificarNumeroBoletoUnico($dadosArquivo['numero_boleto']);
                        
                        // Processa upload do arquivo
                        $nomeArquivoSalvo = $this->processarUploadArquivoMultiplo($arquivo, $dadosArquivo['numero_boleto']);
                        
                        // Salva boleto
                        $boletoId = $this->salvarBoleto([
                            'aluno_id' => $aluno['id'],
                            'curso_id' => $dadosBase['curso_id'],
                            'numero_boleto' => $dadosArquivo['numero_boleto'],
                            'valor' => $dadosArquivo['valor'],
                            'vencimento' => $dadosArquivo['vencimento'],
                            'descricao' => $dadosArquivo['descricao'],
                            'arquivo_pdf' => $nomeArquivoSalvo,
                            'status' => 'pendente',
                            'admin_id' => $_SESSION['admin_id'] ?? null
                        ]);
                        
                        $boletosGerados[] = [
                            'boleto_id' => $boletoId,
                            'numero_boleto' => $dadosArquivo['numero_boleto'],
                            'valor' => $dadosArquivo['valor'],
                            'vencimento' => $dadosArquivo['vencimento'],
                            'arquivo' => $arquivo['name']
                        ];
                        
                        $sucessos++;
                        error_log("UPLOAD MÚLTIPLO: ✅ Sucesso - Boleto {$dadosArquivo['numero_boleto']} criado (ID: {$boletoId})");
                        
                    } catch (Exception $e) {
                        $erros++;
                        $detalhesErros[] = [
                            'arquivo' => $arquivo['name'],
                            'erro' => $e->getMessage()
                        ];
                        
                        error_log("UPLOAD MÚLTIPLO: ❌ Erro no arquivo {$arquivo['name']}: " . $e->getMessage());
                        
                        // Remove arquivo se foi criado
                        if (isset($nomeArquivoSalvo) && file_exists($this->uploadDir . $nomeArquivoSalvo)) {
                            unlink($this->uploadDir . $nomeArquivoSalvo);
                        }
                    }
                }
            }
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('upload_multiplo_aluno', null, "Upload múltiplo para {$aluno['nome']}: {$sucessos} sucessos, {$erros} erros");
            
            error_log("UPLOAD MÚLTIPLO: Concluído - {$sucessos} sucessos, {$erros} erros");
            
            return [
                'success' => true,
                'message' => "Upload múltiplo processado com sucesso!",
                'aluno_nome' => $aluno['nome'],
                'sucessos' => $sucessos,
                'erros' => $erros,
                'detalhes_erros' => $detalhesErros,
                'boletos_gerados' => $boletosGerados
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("UPLOAD MÚLTIPLO: ERRO GERAL - " . $e->getMessage());
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Processa upload em lote
     */
    public function processarUploadLote($post, $files) {
        try {
            $this->db->beginTransaction();
            
            // Validações básicas
            $dadosBase = $this->validarDadosLote($post, $files);
            
            $sucessos = 0;
            $erros = 0;
            $detalhesErros = [];
            
            // Processa cada arquivo
            if (isset($files['arquivos_pdf'])) {
                $arquivos = $this->organizarArquivosLote($files['arquivos_pdf']);
                
                foreach ($arquivos as $arquivo) {
                    try {
                        // Extrai dados do nome do arquivo
                        $dadosArquivo = $this->extrairDadosNomeArquivo($arquivo['name']);
                        
                        // Verifica aluno
                        $aluno = $this->verificarAlunoECurso($dadosArquivo['cpf'], $dadosBase['curso_id'], $dadosBase['polo']);
                        
                        // Verifica número único
                        $this->verificarNumeroBoletoUnico($dadosArquivo['numero_boleto']);
                        
                        // Upload do arquivo
                        $nomeArquivoSalvo = $this->processarUploadArquivoLote($arquivo, $dadosArquivo['numero_boleto']);
                        
                        // Salva boleto
                        $boletoId = $this->salvarBoleto([
                            'aluno_id' => $aluno['id'],
                            'curso_id' => $dadosBase['curso_id'],
                            'numero_boleto' => $dadosArquivo['numero_boleto'],
                            'valor' => $dadosBase['valor'],
                            'vencimento' => $dadosBase['vencimento'],
                            'descricao' => $dadosBase['descricao'],
                            'arquivo_pdf' => $nomeArquivoSalvo,
                            'status' => 'pendente',
                            'admin_id' => $_SESSION['admin_id'] ?? null
                        ]);
                        
                        $sucessos++;
                        
                    } catch (Exception $e) {
                        $erros++;
                        $detalhesErros[] = [
                            'arquivo' => $arquivo['name'],
                            'erro' => $e->getMessage()
                        ];
                        
                        // Remove arquivo se foi criado
                        if (isset($nomeArquivoSalvo) && file_exists($this->uploadDir . $nomeArquivoSalvo)) {
                            unlink($this->uploadDir . $nomeArquivoSalvo);
                        }
                    }
                }
            }
            
            $this->db->commit();
            
            // Log da operação
            $this->registrarLog('upload_lote', null, "Upload em lote: {$sucessos} sucessos, {$erros} erros");
            
            return [
                'success' => true,
                'message' => "Upload em lote processado",
                'sucessos' => $sucessos,
                'erros' => $erros,
                'detalhes_erros' => $detalhesErros
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erro no upload em lote: " . $e->getMessage());
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Processa upload de arquivo individual
     */
    private function processarUploadArquivo($arquivo, $numeroBoleto) {
        // Validações do arquivo
        if ($arquivo['size'] > $this->maxFileSize) {
            throw new Exception("Arquivo muito grande (máximo 5MB)");
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            throw new Exception("Tipo de arquivo não permitido. Apenas PDF é aceito");
        }
        
        // Gera nome único para o arquivo
        $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
        $nomeArquivo = $numeroBoleto . '_' . uniqid() . '.' . $extensao;
        $caminhoCompleto = $this->uploadDir . $nomeArquivo;
        
        // Move arquivo
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
            throw new Exception("Erro ao salvar arquivo");
        }
        
        // Define permissões
        chmod($caminhoCompleto, 0644);
        
        return $nomeArquivo;
    }
    
    /**
     * 🆕 NOVO: Processa upload de arquivo do upload múltiplo
     */
    private function processarUploadArquivoMultiplo($arquivo, $numeroBoleto) {
        // Validações do arquivo
        if ($arquivo['size'] > $this->maxFileSize) {
            throw new Exception("Arquivo {$arquivo['name']} muito grande (máximo 5MB)");
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            throw new Exception("Arquivo {$arquivo['name']} não é um PDF válido");
        }
        
        // Gera nome único para o arquivo
        $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
        $timestamp = date('YmdHis');
        $nomeArquivo = $numeroBoleto . '_' . $timestamp . '_' . uniqid() . '.' . $extensao;
        $caminhoCompleto = $this->uploadDir . $nomeArquivo;
        
        // Move arquivo
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
            throw new Exception("Erro ao salvar arquivo {$arquivo['name']}");
        }
        
        // Define permissões
        chmod($caminhoCompleto, 0644);
        
        error_log("UPLOAD MÚLTIPLO: Arquivo salvo - {$nomeArquivo}");
        
        return $nomeArquivo;
    }
    
    /**
     * Processa upload de arquivo em lote
     */
    private function processarUploadArquivoLote($arquivo, $numeroBoleto) {
        // Validações do arquivo
        if ($arquivo['size'] > $this->maxFileSize) {
            throw new Exception("Arquivo {$arquivo['name']} muito grande (máximo 5MB)");
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            throw new Exception("Arquivo {$arquivo['name']} não é um PDF válido");
        }
        
        // Gera nome único para o arquivo
        $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
        $nomeArquivo = $numeroBoleto . '_' . uniqid() . '.' . $extensao;
        $caminhoCompleto = $this->uploadDir . $nomeArquivo;
        
        // Move arquivo
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
            throw new Exception("Erro ao salvar arquivo {$arquivo['name']}");
        }
        
        // Define permissões
        chmod($caminhoCompleto, 0644);
        
        return $nomeArquivo;
    }
    
    /**
     * Organiza array de arquivos do upload múltiplo (lote)
     */
    private function organizarArquivosLote($files) {
        $arquivos = [];
        $count = count($files['name']);
        
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $arquivos[] = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];
            }
        }
        
        return $arquivos;
    }
    
    /**
     * 🆕 NOVO: Organiza array de arquivos do upload múltiplo
     */
    private function organizarArquivosMultiplo($files) {
        $arquivos = [];
        
        // Verifica se é um array de arquivos ou arquivo único
        if (is_array($files['name'])) {
            $count = count($files['name']);
            
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $arquivos[] = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];
                }
            }
        } else {
            // Arquivo único
            if ($files['error'] === UPLOAD_ERR_OK) {
                $arquivos[] = [
                    'name' => $files['name'],
                    'type' => $files['type'],
                    'tmp_name' => $files['tmp_name'],
                    'error' => $files['error'],
                    'size' => $files['size']
                ];
            }
        }
        
        error_log("UPLOAD MÚLTIPLO: " . count($arquivos) . " arquivos organizados");
        
        return $arquivos;
    }
    
    /**
     * Extrai dados do nome do arquivo (CPF_NUMEROBANTO.pdf)
     */
    private function extrairDadosNomeArquivo($nomeArquivo) {
        $nomeBase = pathinfo($nomeArquivo, PATHINFO_FILENAME);
        $partes = explode('_', $nomeBase);
        
        if (count($partes) !== 2) {
            throw new Exception("Nome do arquivo inválido: {$nomeArquivo}. Use o formato CPF_NUMEROBANTO.pdf");
        }
        
        $cpf = preg_replace('/[^0-9]/', '', $partes[0]);
        $numeroBoleto = $partes[1];
        
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido no arquivo {$nomeArquivo}");
        }
        
        if (!$this->validarCPF($cpf)) {
            throw new Exception("CPF inválido no arquivo {$nomeArquivo}");
        }
        
        if (empty($numeroBoleto)) {
            throw new Exception("Número do boleto inválido no arquivo {$nomeArquivo}");
        }
        
        return [
            'cpf' => $cpf,
            'numero_boleto' => $numeroBoleto
        ];
    }
    
    /**
     * 🆕 NOVO: Extrai dados individuais dos arquivos do formulário múltiplo
     */
    private function extrairDadosArquivosMultiplo($post) {
        $dadosArquivos = [];
        $index = 0;
        
        // Extrai dados de cada arquivo baseado no padrão arquivo_X_campo
        while (isset($post["arquivo_{$index}_numero"])) {
            $dadosArquivos[$index] = [
                'numero_boleto' => trim($post["arquivo_{$index}_numero"] ?? ''),
                'valor' => floatval($post["arquivo_{$index}_valor"] ?? 0),
                'vencimento' => trim($post["arquivo_{$index}_vencimento"] ?? ''),
                'descricao' => trim($post["arquivo_{$index}_descricao"] ?? '')
            ];
            
            error_log("UPLOAD MÚLTIPLO: Dados arquivo {$index} - Número: {$dadosArquivos[$index]['numero_boleto']}, Valor: {$dadosArquivos[$index]['valor']}");
            
            $index++;
        }
        
        error_log("UPLOAD MÚLTIPLO: Total de dados extraídos: " . count($dadosArquivos));
        
        return $dadosArquivos;
    }
    
    /**
     * 🆕 NOVO: Valida dados individuais de cada arquivo
     */
    private function validarDadosArquivoIndividual($dadosArquivo, $nomeArquivo) {
        $erros = [];
        
        if (empty($dadosArquivo['numero_boleto'])) {
            $erros[] = "Número do boleto é obrigatório";
        }
        
        if (empty($dadosArquivo['valor']) || $dadosArquivo['valor'] <= 0) {
            $erros[] = "Valor deve ser maior que zero";
        }
        
        if (empty($dadosArquivo['vencimento'])) {
            $erros[] = "Data de vencimento é obrigatória";
        } elseif (strtotime($dadosArquivo['vencimento']) < strtotime(date('Y-m-d'))) {
            $erros[] = "Data de vencimento não pode ser anterior a hoje";
        }
        
        if (!empty($erros)) {
            throw new Exception("Arquivo {$nomeArquivo}: " . implode(', ', $erros));
        }
    }
    
    /**
     * Salva boleto no banco de dados
     */
    private function salvarBoleto($dados) {
        // Verifica quais colunas existem na tabela
        $colunas = $this->obterColunasTabelaBoletos();
        
        // Monta SQL dinamicamente baseado nas colunas disponíveis
        $camposObrigatorios = [
            'aluno_id', 'curso_id', 'numero_boleto', 'valor', 
            'vencimento', 'status', 'created_at'
        ];
        
        $camposOpcionais = [
            'descricao', 'arquivo_pdf', 'admin_id', 'updated_at',
            'data_pagamento', 'valor_pago', 'observacoes'
        ];
        
        $campos = [];
        $valores = [];
        $params = [];
        
        // Adiciona campos obrigatórios
        foreach ($camposObrigatorios as $campo) {
            if (in_array($campo, $colunas)) {
                $campos[] = $campo;
                
                if ($campo === 'created_at') {
                    $valores[] = 'NOW()';
                } else {
                    $valores[] = '?';
                    $params[] = $dados[$campo] ?? null;
                }
            }
        }
        
        // Adiciona campos opcionais se existirem
        foreach ($camposOpcionais as $campo) {
            if (in_array($campo, $colunas) && isset($dados[$campo])) {
                $campos[] = $campo;
                
                if ($campo === 'updated_at') {
                    $valores[] = 'NOW()';
                } else {
                    $valores[] = '?';
                    $params[] = $dados[$campo];
                }
            }
        }
        
        $sql = "INSERT INTO boletos (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $valores) . ")";
        
        error_log("SALVAR BOLETO: SQL - " . $sql);
        error_log("SALVAR BOLETO: Params - " . print_r($params, true));
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $boletoId = $this->db->lastInsertId();
        
        error_log("SALVAR BOLETO: Boleto salvo com ID: {$boletoId}");
        
        return $boletoId;
    }
    
    /**
     * Valida dados do upload individual
     */
    private function validarDadosIndividual($post, $files) {
        $erros = [];
        
        // Validações obrigatórias
        if (empty($post['polo'])) $erros[] = "Polo é obrigatório";
        if (empty($post['curso_id'])) $erros[] = "Curso é obrigatório";
        if (empty($post['aluno_cpf'])) $erros[] = "CPF do aluno é obrigatório";
        if (empty($post['valor'])) $erros[] = "Valor é obrigatório";
        if (empty($post['vencimento'])) $erros[] = "Data de vencimento é obrigatória";
        if (empty($post['numero_boleto'])) $erros[] = "Número do boleto é obrigatório";
        
        // Valida arquivo
        if (!isset($files['arquivo_pdf']) || $files['arquivo_pdf']['error'] !== UPLOAD_ERR_OK) {
            $erros[] = "Arquivo PDF é obrigatório";
        }
        
        if (!empty($erros)) {
            throw new Exception(implode(', ', $erros));
        }
        
        // Validações específicas
        $cpf = preg_replace('/[^0-9]/', '', $post['aluno_cpf']);
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF deve conter 11 dígitos");
        }
        
        if (!$this->validarCPF($cpf)) {
            throw new Exception("CPF inválido");
        }
        
        $valor = floatval($post['valor']);
        if ($valor <= 0) {
            throw new Exception("Valor deve ser maior que zero");
        }
        
        if (strtotime($post['vencimento']) < strtotime(date('Y-m-d'))) {
            throw new Exception("Data de vencimento não pode ser anterior a hoje");
        }
        
        return [
            'polo' => $post['polo'],
            'curso_id' => intval($post['curso_id']),
            'cpf' => $cpf,
            'valor' => $valor,
            'vencimento' => $post['vencimento'],
            'numero_boleto' => $post['numero_boleto'],
            'descricao' => $post['descricao'] ?? ''
        ];
    }
    
    /**
     * 🆕 NOVO: Valida dados do upload múltiplo para um aluno
     */
    private function validarDadosMultiploAluno($post, $files) {
        $erros = [];
        
        // Validações obrigatórias
        if (empty($post['polo'])) $erros[] = "Polo é obrigatório";
        if (empty($post['curso_id'])) $erros[] = "Curso é obrigatório";
        if (empty($post['aluno_cpf'])) $erros[] = "CPF do aluno é obrigatório";
        
        // Valida se tem arquivos
        if (!isset($files['arquivos_multiplos']) || empty($files['arquivos_multiplos']['name'][0])) {
            $erros[] = "Pelo menos um arquivo PDF é obrigatório";
        }
        
        if (!empty($erros)) {
            throw new Exception(implode(', ', $erros));
        }
        
        // Validações específicas
        $cpf = preg_replace('/[^0-9]/', '', $post['aluno_cpf']);
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF deve conter 11 dígitos");
        }
        
        if (!$this->validarCPF($cpf)) {
            throw new Exception("CPF inválido");
        }
        
        return [
            'polo' => $post['polo'],
            'curso_id' => intval($post['curso_id']),
            'cpf' => $cpf
        ];
    }
    
    /**
     * Valida dados do upload em lote
     */
    private function validarDadosLote($post, $files) {
        $erros = [];
        
        if (empty($post['polo'])) $erros[] = "Polo é obrigatório";
        if (empty($post['curso_id'])) $erros[] = "Curso é obrigatório";
        if (empty($post['valor'])) $erros[] = "Valor é obrigatório";
        if (empty($post['vencimento'])) $erros[] = "Data de vencimento é obrigatória";
        
        if (!isset($files['arquivos_pdf']) || empty($files['arquivos_pdf']['name'][0])) {
            $erros[] = "Pelo menos um arquivo PDF é obrigatório";
        }
        
        if (!empty($erros)) {
            throw new Exception(implode(', ', $erros));
        }
        
        $valor = floatval($post['valor']);
        if ($valor <= 0) {
            throw new Exception("Valor deve ser maior que zero");
        }
        
        if (strtotime($post['vencimento']) < strtotime(date('Y-m-d'))) {
            throw new Exception("Data de vencimento não pode ser anterior a hoje");
        }
        
        return [
            'polo' => $post['polo'],
            'curso_id' => intval($post['curso_id']),
            'valor' => $valor,
            'vencimento' => $post['vencimento'],
            'descricao' => $post['descricao'] ?? ''
        ];
    }
    
    /**
     * Verifica se aluno existe e está matriculado no curso
     */
    private function verificarAlunoECurso($cpf, $cursoId, $polo) {
        $alunoService = new AlunoService();
        
        // Busca aluno por CPF e polo
        $aluno = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $polo);
        
        if (!$aluno) {
            throw new Exception("Aluno com CPF {$cpf} não encontrado no polo {$polo}");
        }
        
        error_log("BoletoUpload: Verificando matrícula - Aluno ID: {$aluno['id']}, Curso ID: {$cursoId}, Polo: {$polo}");
        
        // CORREÇÃO: Verificação de matrícula usando estrutura correta do Moodle
        // Método 1: Verificação via tabela matriculas do sistema local
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM matriculas m 
            INNER JOIN cursos c ON m.curso_id = c.id
            WHERE m.aluno_id = ? 
            AND c.id = ? 
            AND c.subdomain = ?
            AND m.status = 'ativa'
        ");
        $stmt->execute([$aluno['id'], $cursoId, $polo]);
        $matriculaLocal = $stmt->fetch()['count'];
        
        error_log("BoletoUpload: Matrícula local encontrada: {$matriculaLocal}");
        
        if ($matriculaLocal > 0) {
            error_log("BoletoUpload: ✅ Matrícula confirmada via sistema local");
            return $aluno;
        }
        
        // CORREÇÃO: Se não encontrou localmente, verifica no Moodle e sincroniza
        error_log("BoletoUpload: 🔄 Tentando sincronizar matrícula do Moodle");
        
        try {
            require_once __DIR__ . '/../config/moodle.php';
            require_once __DIR__ . '/MoodleAPI.php';
            
            // Conecta com o Moodle para verificar matrícula real
            $moodleAPI = new MoodleAPI($polo);
            
            // Busca dados atualizados do aluno no Moodle
            $dadosAlunoMoodle = $moodleAPI->buscarAlunoPorCPF($cpf);
            
            if ($dadosAlunoMoodle && !empty($dadosAlunoMoodle['cursos'])) {
                error_log("BoletoUpload: 📚 Cursos encontrados no Moodle: " . count($dadosAlunoMoodle['cursos']));
                
                // Verifica se o curso solicitado está entre os cursos do Moodle
                $cursoEncontrado = false;
                
                // Busca informações do curso local
                $stmtCurso = $this->db->prepare("
                    SELECT moodle_course_id, nome, nome_curto 
                    FROM cursos 
                    WHERE id = ? AND subdomain = ?
                ");
                $stmtCurso->execute([$cursoId, $polo]);
                $cursoLocal = $stmtCurso->fetch();
                
                if ($cursoLocal) {
                    error_log("BoletoUpload: 🎯 Curso local: {$cursoLocal['nome']} (Moodle ID: {$cursoLocal['moodle_course_id']})");
                    
                    foreach ($dadosAlunoMoodle['cursos'] as $cursoMoodle) {
                        error_log("BoletoUpload: 🔍 Verificando curso Moodle: {$cursoMoodle['nome']} (ID: {$cursoMoodle['moodle_course_id']})");
                        
                        // Verifica correspondência por ID do Moodle
                        if ($cursoMoodle['moodle_course_id'] == $cursoLocal['moodle_course_id']) {
                            $cursoEncontrado = true;
                            error_log("BoletoUpload: ✅ Correspondência encontrada por Moodle ID");
                            break;
                        }
                        
                        // Verificação por nome (fallback)
                        $nomeCursoMoodle = $this->normalizarNome($cursoMoodle['nome']);
                        $nomeCursoLocal = $this->normalizarNome($cursoLocal['nome']);
                        
                        if ($nomeCursoMoodle === $nomeCursoLocal) {
                            $cursoEncontrado = true;
                            error_log("BoletoUpload: ✅ Correspondência encontrada por nome");
                            break;
                        }
                        
                        // Verificação por nome curto (fallback)
                        if (!empty($cursoMoodle['nome_curto']) && !empty($cursoLocal['nome_curto'])) {
                            $nomeCurtoMoodle = $this->normalizarNome($cursoMoodle['nome_curto']);
                            $nomeCurtoLocal = $this->normalizarNome($cursoLocal['nome_curto']);
                            
                            if ($nomeCurtoMoodle === $nomeCurtoLocal) {
                                $cursoEncontrado = true;
                                error_log("BoletoUpload: ✅ Correspondência encontrada por nome curto");
                                break;
                            }
                        }
                    }
                    
                    if ($cursoEncontrado) {
                        // Sincroniza dados do aluno no sistema local
                        error_log("BoletoUpload: 🔄 Sincronizando dados do aluno");
                        $alunoService->salvarOuAtualizarAluno($dadosAlunoMoodle);
                        
                        error_log("BoletoUpload: ✅ Matrícula confirmada e sincronizada do Moodle");
                        return $aluno;
                    } else {
                        error_log("BoletoUpload: ❌ Curso não encontrado entre os cursos do aluno no Moodle");
                        
                        // Log dos cursos disponíveis para debug
                        $cursosDisponiveis = array_map(function($c) {
                            return $c['nome'] . " (ID: " . $c['moodle_course_id'] . ")";
                        }, $dadosAlunoMoodle['cursos']);
                        error_log("BoletoUpload: 📋 Cursos disponíveis: " . implode(', ', $cursosDisponiveis));
                    }
                } else {
                    error_log("BoletoUpload: ❌ Curso local não encontrado");
                }
            } else {
                error_log("BoletoUpload: ❌ Nenhum curso encontrado para o aluno no Moodle");
            }
            
        } catch (Exception $e) {
            error_log("BoletoUpload: ⚠️ Erro ao verificar no Moodle: " . $e->getMessage());
            // Em caso de erro do Moodle, continua com verificação básica
        }
        
        // NOVA VERIFICAÇÃO: Busca flexível por qualquer curso do aluno no polo
        $stmt = $this->db->prepare("
            SELECT c.nome, c.moodle_course_id
            FROM matriculas m 
            INNER JOIN cursos c ON m.curso_id = c.id
            WHERE m.aluno_id = ? 
            AND c.subdomain = ?
            AND m.status = 'ativa'
        ");
        $stmt->execute([$aluno['id'], $polo]);
        $cursosAluno = $stmt->fetchAll();
        
        if (!empty($cursosAluno)) {
            $nomesCursos = array_map(function($c) {
                return $c['nome'] . " (Moodle ID: " . $c['moodle_course_id'] . ")";
            }, $cursosAluno);
            
            throw new Exception("Aluno {$aluno['nome']} está matriculado em outros cursos deste polo, mas não no curso selecionado. Cursos disponíveis: " . implode(', ', $nomesCursos));
        }
        
        throw new Exception("Aluno {$aluno['nome']} não possui matrículas ativas no polo {$polo}. Verifique se o aluno está matriculado no curso correto no Moodle.");
    }
    
    /**
     * Normaliza nome para comparação
     */
    private function normalizarNome($nome) {
        $nome = trim(strtolower($nome));
        
        // Remove acentos
        $acentos = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n'
        ];
        
        $nome = str_replace(array_keys($acentos), array_values($acentos), $nome);
        
        // Remove caracteres especiais
        $nome = preg_replace('/[^a-z0-9\s]/', '', $nome);
        
        // Remove espaços extras
        $nome = preg_replace('/\s+/', ' ', $nome);
        
        return trim($nome);
    }
    
    /**
     * Verifica se número do boleto é único
     */
    private function verificarNumeroBoletoUnico($numeroBoleto) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM boletos WHERE numero_boleto = ?");
        $stmt->execute([$numeroBoleto]);
        
        if ($stmt->fetch()['count'] > 0) {
            throw new Exception("Número do boleto {$numeroBoleto} já existe");
        }
    }
    
    /**
     * Obtém colunas da tabela boletos
     */
    private function obterColunasTabelaBoletos() {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM boletos");
            $colunas = [];
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $colunas[] = $row['Field'];
            }
            
            return $colunas;
        } catch (Exception $e) {
            error_log("Erro ao obter colunas da tabela boletos: " . $e->getMessage());
            
            // Retorna colunas básicas como fallback
            return [
                'id', 'aluno_id', 'curso_id', 'numero_boleto', 'valor', 
                'vencimento', 'status', 'descricao', 'arquivo_pdf', 
                'admin_id', 'created_at', 'updated_at'
            ];
        }
    }
    
    /**
     * Valida CPF
     */
    private function validarCPF($cpf) {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
        
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Lista boletos com paginação e filtros
     */
    public function listarBoletos($filtros = [], $pagina = 1, $itensPorPagina = 20) {
        $where = ['1=1'];
        $params = [];
        
        // Aplica filtros
        if (!empty($filtros['polo'])) {
            $where[] = "c.subdomain = ?";
            $params[] = $filtros['polo'];
        }
        
        if (!empty($filtros['curso_id'])) {
            $where[] = "b.curso_id = ?";
            $params[] = $filtros['curso_id'];
        }
        
        if (!empty($filtros['aluno_id'])) {
            $where[] = "b.aluno_id = ?";
            $params[] = $filtros['aluno_id'];
        }
        
        if (!empty($filtros['status'])) {
            $where[] = "b.status = ?";
            $params[] = $filtros['status'];
        }
        
        if (!empty($filtros['data_inicio'])) {
            $where[] = "b.vencimento >= ?";
            $params[] = $filtros['data_inicio'];
        }
        
        if (!empty($filtros['data_fim'])) {
            $where[] = "b.vencimento <= ?";
            $params[] = $filtros['data_fim'];
        }
        
        if (!empty($filtros['busca'])) {
            $where[] = "(a.nome LIKE ? OR a.cpf LIKE ? OR b.numero_boleto LIKE ?)";
            $termoBusca = '%' . $filtros['busca'] . '%';
            $params[] = $termoBusca;
            $params[] = $termoBusca;
            $params[] = $termoBusca;
        }
        
        // 🆕 Filtro por upload múltiplo
        if (!empty($filtros['upload_multiplo'])) {
            $where[] = "EXISTS (
                SELECT 1 FROM logs l 
                WHERE l.boleto_id = b.id 
                AND l.tipo = 'upload_multiplo_aluno'
            )";
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Conta total
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE {$whereClause}
        ");
        $stmtCount->execute($params);
        $total = $stmtCount->fetch()['total'];
        
        // Busca registros
        $offset = ($pagina - 1) * $itensPorPagina;
        $stmt = $this->db->prepare("
            SELECT b.*, a.nome as aluno_nome, a.cpf, c.nome as curso_nome, c.subdomain,
                   ad.nome as admin_nome
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            LEFT JOIN administradores ad ON b.admin_id = ad.id
            WHERE {$whereClause}
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $itensPorPagina;
        $params[] = $offset;
        $stmt->execute($params);
        
        return [
            'boletos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => ceil($total / $itensPorPagina),
            'itens_por_pagina' => $itensPorPagina
        ];
    }
    
    /**
     * Busca boleto por ID com verificações de segurança
     */
    public function buscarBoletoPorId($boletoId) {
        $stmt = $this->db->prepare("
            SELECT b.*, a.nome as aluno_nome, a.cpf, c.nome as curso_nome, c.subdomain
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE b.id = ?
        ");
        $stmt->execute([$boletoId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Download seguro de boleto
     */
    public function downloadBoleto($boletoId) {
        $boleto = $this->buscarBoletoPorId($boletoId);
        
        if (!$boleto) {
            throw new Exception("Boleto não encontrado");
        }
        
        if (empty($boleto['arquivo_pdf'])) {
            throw new Exception("Arquivo PDF não disponível");
        }
        
        $caminhoArquivo = $this->uploadDir . $boleto['arquivo_pdf'];
        
        if (!file_exists($caminhoArquivo)) {
            throw new Exception("Arquivo PDF não encontrado no servidor");
        }
        
        // Log do download
        $this->registrarLog('download_boleto', $boletoId, "Download do boleto {$boleto['numero_boleto']}");
        
        return [
            'caminho' => $caminhoArquivo,
            'nome_arquivo' => "Boleto_{$boleto['numero_boleto']}.pdf",
            'tipo_mime' => 'application/pdf'
        ];
    }
    
    /**
     * 🆕 MÉTODO ADICIONAL: Força sincronização completa do aluno
     */
    public function forcarSincronizacaoAluno($cpf, $polo) {
        try {
            error_log("BoletoUpload: 🔄 Forçando sincronização completa do aluno CPF: {$cpf}");
            
            require_once __DIR__ . '/MoodleAPI.php';
            
            $moodleAPI = new MoodleAPI($polo);
            $dadosAlunoMoodle = $moodleAPI->buscarAlunoPorCPF($cpf);
            
            if ($dadosAlunoMoodle) {
                $alunoService = new AlunoService();
                $alunoId = $alunoService->salvarOuAtualizarAluno($dadosAlunoMoodle);
                
                error_log("BoletoUpload: ✅ Sincronização completa realizada");
                return $alunoId;
            } else {
                error_log("BoletoUpload: ❌ Aluno não encontrado no Moodle");
                return false;
            }
            
        } catch (Exception $e) {
            error_log("BoletoUpload: ❌ Erro na sincronização: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Registra log de operação
     */
    private function registrarLog($tipo, $boletoId, $descricao) {
        try {
            // Verifica colunas disponíveis na tabela logs
            $columns = $this->getTableColumns('logs');
            
            $insertFields = ['tipo', 'descricao', 'created_at'];
            $insertValues = ['?', '?', 'NOW()'];
            $insertParams = [$tipo, $descricao];
            
            if (in_array('usuario_id', $columns)) {
                $insertFields[] = 'usuario_id';
                $insertValues[] = '?';
                $insertParams[] = $_SESSION['admin_id'] ?? null;
            }
            
            if (in_array('boleto_id', $columns)) {
                $insertFields[] = 'boleto_id';
                $insertValues[] = '?';
                $insertParams[] = $boletoId;
            }
            
            if (in_array('ip_address', $columns)) {
                $insertFields[] = 'ip_address';
                $insertValues[] = '?';
                $insertParams[] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            }
            
            if (in_array('user_agent', $columns)) {
                $insertFields[] = 'user_agent';
                $insertValues[] = '?';
                $insertParams[] = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
            }
            
            $sql = "INSERT INTO logs (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($insertParams);
            
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }
    
    /**
     * Obtém colunas de uma tabela
     */
    private function getTableColumns($tableName) {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM {$tableName}");
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            return $columns;
        } catch (Exception $e) {
            return [];
        }
    }
}
?>