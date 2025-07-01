<?php
/**
 * Sistema de Boletos IMEPEDU - Serviço de Upload com Desconto PIX Personalizado
 * Arquivo: src/BoletoUploadService.php
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
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    /**
     * Processa upload individual com desconto PIX personalizado
     */
    public function processarUploadIndividual($post, $files) {
        try {
            $this->db->beginTransaction();
            
            $dadosValidados = $this->validarDadosIndividual($post, $files);
            $aluno = $this->verificarAlunoFlexivel($dadosValidados['cpf'], $dadosValidados['curso_id'], $dadosValidados['polo']);
            $this->verificarNumeroBoletoUnico($dadosValidados['numero_boleto']);
            
            $nomeArquivo = $this->processarUploadArquivo($files['arquivo_pdf'], $dadosValidados['numero_boleto']);
            
            $boletoId = $this->salvarBoleto([
                'aluno_id' => $aluno['id'],
                'curso_id' => $dadosValidados['curso_id'],
                'numero_boleto' => $dadosValidados['numero_boleto'],
                'valor' => $dadosValidados['valor'],
                'vencimento' => $dadosValidados['vencimento'],
                'descricao' => $dadosValidados['descricao'],
                'arquivo_pdf' => $nomeArquivo,
                'status' => 'pendente',
                'admin_id' => $_SESSION['admin_id'] ?? null,
                'pix_desconto_disponivel' => $dadosValidados['pix_desconto_disponivel'],
                'pix_desconto_usado' => 0,
                'pix_valor_desconto' => $dadosValidados['valor_desconto_pix'] ?? null,
                'pix_valor_minimo' => $dadosValidados['valor_minimo_desconto'] ?? null
            ]);
            
            $this->db->commit();
            
            $descontoTexto = $dadosValidados['pix_desconto_disponivel'] ? 
                "SIM (R$ " . number_format($dadosValidados['valor_desconto_pix'] ?? 0, 2, ',', '.') . ")" : "NÃO";
            
            $this->registrarLog('upload_individual_com_desconto', $boletoId, 
                "Boleto {$dadosValidados['numero_boleto']} - Desconto PIX: {$descontoTexto}");
            
            return [
                'success' => true,
                'message' => 'Boleto enviado com sucesso!',
                'boleto_id' => $boletoId,
                'pix_desconto_disponivel' => $dadosValidados['pix_desconto_disponivel'],
                'valor_desconto' => $dadosValidados['valor_desconto_pix'] ?? 0
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            
            if (isset($nomeArquivo) && file_exists($this->uploadDir . $nomeArquivo)) {
                unlink($this->uploadDir . $nomeArquivo);
            }
            
            error_log("Erro no upload individual: " . $e->getMessage());
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Processa upload múltiplo com desconto PIX personalizado por arquivo
     */
    public function processarUploadMultiploAluno($post, $files) {
        try {
            $this->db->beginTransaction();
            
            $dadosBase = $this->validarDadosMultiploAluno($post, $files);
            $aluno = $this->verificarAlunoFlexivel($dadosBase['cpf'], $dadosBase['curso_id'], $dadosBase['polo']);
            $dadosArquivos = $this->extrairDadosArquivosMultiplo($post);
            
            $sucessos = 0;
            $erros = 0;
            $detalhesErros = [];
            $boletosGerados = [];
            
            if (isset($files['arquivos_multiplos'])) {
                $arquivos = $this->organizarArquivosMultiplo($files['arquivos_multiplos']);
                $quantidadeArquivos = count($arquivos);
                $numerosDisponiveis = $this->gerarNumerosSequenciaisLote($quantidadeArquivos);
                
                foreach ($arquivos as $index => $arquivo) {
                    try {
                        $dadosArquivo = $dadosArquivos[$index] ?? null;
                        
                        if (!$dadosArquivo) {
                            throw new Exception("Dados não encontrados para o arquivo {$arquivo['name']}");
                        }
                        
                        if (empty($dadosArquivo['numero_boleto']) || $dadosArquivo['numero_boleto'] === 'auto') {
                            $dadosArquivo['numero_boleto'] = $numerosDisponiveis[$index] ?? $this->gerarNumeroSequencialSeguro();
                        } else {
                            $this->verificarNumeroBoletoUnico($dadosArquivo['numero_boleto']);
                        }
                        
                        $this->validarDadosArquivoIndividual($dadosArquivo, $arquivo['name']);
                        
                        $nomeArquivoSalvo = $this->processarUploadArquivoMultiplo($arquivo, $dadosArquivo['numero_boleto']);
                        
                        $boletoId = $this->salvarBoleto([
                            'aluno_id' => $aluno['id'],
                            'curso_id' => $dadosBase['curso_id'],
                            'numero_boleto' => $dadosArquivo['numero_boleto'],
                            'valor' => $dadosArquivo['valor'],
                            'vencimento' => $dadosArquivo['vencimento'],
                            'descricao' => $dadosArquivo['descricao'],
                            'arquivo_pdf' => $nomeArquivoSalvo,
                            'status' => 'pendente',
                            'admin_id' => $_SESSION['admin_id'] ?? null,
                            'pix_desconto_disponivel' => $dadosArquivo['pix_desconto_disponivel'] ?? 0,
                            'pix_desconto_usado' => 0,
                            'pix_valor_desconto' => $dadosArquivo['valor_desconto_pix'] ?? null,
                            'pix_valor_minimo' => $dadosArquivo['valor_minimo_desconto'] ?? null
                        ]);
                        
                        $boletosGerados[] = [
                            'boleto_id' => $boletoId,
                            'numero_boleto' => $dadosArquivo['numero_boleto'],
                            'valor' => $dadosArquivo['valor'],
                            'vencimento' => $dadosArquivo['vencimento'],
                            'arquivo' => $arquivo['name'],
                            'pix_desconto_disponivel' => $dadosArquivo['pix_desconto_disponivel'] ?? 0,
                            'valor_desconto_pix' => $dadosArquivo['valor_desconto_pix'] ?? 0
                        ];
                        
                        $sucessos++;
                        
                    } catch (Exception $e) {
                        $erros++;
                        $detalhesErros[] = [
                            'arquivo' => $arquivo['name'],
                            'erro' => $e->getMessage()
                        ];
                        
                        if (isset($nomeArquivoSalvo) && file_exists($this->uploadDir . $nomeArquivoSalvo)) {
                            unlink($this->uploadDir . $nomeArquivoSalvo);
                        }
                    }
                }
            }
            
            $this->db->commit();
            
            $this->registrarLog('upload_multiplo_com_desconto', null, 
                "Upload múltiplo para {$aluno['nome']}: {$sucessos} sucessos, {$erros} erros");
            
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
     * Processa upload em lote com configuração global de desconto
     */
    public function processarUploadLote($post, $files) {
        try {
            $this->db->beginTransaction();
            
            $dadosBase = $this->validarDadosLote($post, $files);
            
            $sucessos = 0;
            $erros = 0;
            $detalhesErros = [];
            
            if (isset($files['arquivos_pdf'])) {
                $arquivos = $this->organizarArquivosLote($files['arquivos_pdf']);
                
                foreach ($arquivos as $arquivo) {
                    try {
                        $dadosArquivo = $this->extrairDadosNomeArquivo($arquivo['name']);
                        $aluno = $this->verificarAlunoFlexivel($dadosArquivo['cpf'], $dadosBase['curso_id'], $dadosBase['polo']);
                        
                        $this->verificarNumeroBoletoUnico($dadosArquivo['numero_boleto']);
                        
                        $nomeArquivoSalvo = $this->processarUploadArquivoLote($arquivo, $dadosArquivo['numero_boleto']);
                        
                        $boletoId = $this->salvarBoleto([
                            'aluno_id' => $aluno['id'],
                            'curso_id' => $dadosBase['curso_id'],
                            'numero_boleto' => $dadosArquivo['numero_boleto'],
                            'valor' => $dadosBase['valor'],
                            'vencimento' => $dadosBase['vencimento'],
                            'descricao' => $dadosBase['descricao'],
                            'arquivo_pdf' => $nomeArquivoSalvo,
                            'status' => 'pendente',
                            'admin_id' => $_SESSION['admin_id'] ?? null,
                            'pix_desconto_disponivel' => $dadosBase['pix_desconto_global'] ?? 0,
                            'pix_desconto_usado' => 0,
                            'pix_valor_desconto' => $dadosBase['valor_desconto_lote'] ?? null,
                            'pix_valor_minimo' => $dadosBase['valor_minimo_lote'] ?? null
                        ]);
                        
                        $sucessos++;
                        
                    } catch (Exception $e) {
                        $erros++;
                        $detalhesErros[] = [
                            'arquivo' => $arquivo['name'],
                            'erro' => $e->getMessage()
                        ];
                        
                        if (isset($nomeArquivoSalvo) && file_exists($this->uploadDir . $nomeArquivoSalvo)) {
                            unlink($this->uploadDir . $nomeArquivoSalvo);
                        }
                    }
                }
            }
            
            $this->db->commit();
            
            $descontoTexto = ($dadosBase['pix_desconto_global'] ?? 0) ? 
                "SIM (R$ " . number_format($dadosBase['valor_desconto_lote'] ?? 0, 2, ',', '.') . ")" : "NÃO";
            
            $this->registrarLog('upload_lote_com_desconto', null, 
                "Upload em lote: {$sucessos} sucessos, {$erros} erros - Desconto global: {$descontoTexto}");
            
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
     * Verifica se boleto tem desconto PIX disponível
     */
    public function verificarDescontoPixDisponivel($boletoId) {
        $stmt = $this->db->prepare("
            SELECT pix_desconto_disponivel, pix_desconto_usado, pix_valor_desconto, 
                   pix_valor_minimo, vencimento, status, valor
            FROM boletos 
            WHERE id = ?
        ");
        $stmt->execute([$boletoId]);
        $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$boleto) {
            return false;
        }
        
        if ($boleto['pix_desconto_disponivel'] != 1 || $boleto['pix_desconto_usado'] == 1) {
            return false;
        }
        
        if (in_array($boleto['status'], ['pago', 'cancelado'])) {
            return false;
        }
        
        $hoje = new DateTime();
        $vencimento = new DateTime($boleto['vencimento']);
        
        if ($hoje > $vencimento) {
            return false;
        }
        
        if ($boleto['pix_valor_minimo'] && $boleto['valor'] < $boleto['pix_valor_minimo']) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Marca desconto PIX como usado
     */
    public function marcarDescontoPixUsado($boletoId) {
        $stmt = $this->db->prepare("
            UPDATE boletos 
            SET pix_desconto_usado = 1, updated_at = NOW()
            WHERE id = ? AND pix_desconto_disponivel = 1 AND pix_desconto_usado = 0
        ");
        $stmt->execute([$boletoId]);
        
        if ($stmt->rowCount() > 0) {
            $this->registrarLog('desconto_pix_usado', $boletoId, 
                "Desconto PIX utilizado para o boleto");
            return true;
        }
        
        return false;
    }
    
    /**
     * Calcula valor com desconto PIX personalizado
     */
    public function calcularValorComDesconto($boletoId) {
        $stmt = $this->db->prepare("
            SELECT valor, vencimento, pix_desconto_disponivel, pix_desconto_usado,
                   pix_valor_desconto, pix_valor_minimo
            FROM boletos 
            WHERE id = ?
        ");
        $stmt->execute([$boletoId]);
        $boleto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$boleto) {
            throw new Exception('Boleto não encontrado');
        }
        
        $valorOriginal = (float)$boleto['valor'];
        $valorFinal = $valorOriginal;
        $valorDesconto = 0.00;
        $temDesconto = false;
        $motivo = '';
        
        if (!$this->verificarDescontoPixDisponivel($boletoId)) {
            if ($boleto['pix_desconto_usado']) {
                $motivo = "Desconto já utilizado";
            } elseif (!$boleto['pix_desconto_disponivel']) {
                $motivo = "Desconto não habilitado para este boleto";
            } elseif (in_array($boleto['status'], ['pago', 'cancelado'])) {
                $motivo = "Boleto não está pendente";
            } else {
                $hoje = new DateTime();
                $vencimento = new DateTime($boleto['vencimento']);
                
                if ($hoje > $vencimento) {
                    $motivo = "Boleto vencido - desconto não disponível";
                } elseif ($boleto['pix_valor_minimo'] && $valorOriginal < $boleto['pix_valor_minimo']) {
                    $motivo = "Valor mínimo não atingido (mín: R$ " . number_format($boleto['pix_valor_minimo'], 2, ',', '.') . ")";
                } else {
                    $motivo = "Desconto não disponível";
                }
            }
            
            return [
                'tem_desconto' => false,
                'valor_original' => $valorOriginal,
                'valor_desconto' => 0.00,
                'valor_final' => $valorOriginal,
                'motivo' => $motivo
            ];
        }
        
        if ($boleto['pix_valor_desconto'] && $boleto['pix_valor_desconto'] > 0) {
            $valorDesconto = (float)$boleto['pix_valor_desconto'];
            
            $valorFinal = $valorOriginal - $valorDesconto;
            if ($valorFinal < 10.00) {
                $valorDesconto = $valorOriginal - 10.00;
                $valorFinal = 10.00;
                
                if ($valorDesconto <= 0) {
                    return [
                        'tem_desconto' => false,
                        'valor_original' => $valorOriginal,
                        'valor_desconto' => 0.00,
                        'valor_final' => $valorOriginal,
                        'motivo' => 'Valor do boleto muito baixo para aplicar desconto'
                    ];
                }
            }
            
            $temDesconto = true;
            $motivo = "Desconto PIX de R$ " . number_format($valorDesconto, 2, ',', '.') . " aplicado";
        } else {
            $motivo = "Valor do desconto não configurado";
        }
        
        return [
            'tem_desconto' => $temDesconto,
            'valor_original' => $valorOriginal,
            'valor_desconto' => $valorDesconto,
            'valor_final' => $valorFinal,
            'motivo' => $motivo,
            'desconto_valido_ate' => $boleto['vencimento']
        ];
    }
    
    // MÉTODOS PRIVADOS
    
    private function verificarAlunoFlexivel($cpf, $cursoId, $polo) {
        $alunoService = new AlunoService();
        
        $aluno = $alunoService->buscarAlunoPorCPFESubdomain($cpf, $polo);
        
        if (!$aluno) {
            throw new Exception("Aluno com CPF {$cpf} não encontrado no polo {$polo}");
        }
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM matriculas m 
            INNER JOIN cursos c ON m.curso_id = c.id
            WHERE m.aluno_id = ? 
            AND c.subdomain = ?
            AND m.status = 'ativa'
        ");
        $stmt->execute([$aluno['id'], $polo]);
        $matriculasAtivas = $stmt->fetch()['count'];
        
        if ($matriculasAtivas > 0) {
            $stmtCurso = $this->db->prepare("
                SELECT nome FROM cursos 
                WHERE id = ? AND subdomain = ?
            ");
            $stmtCurso->execute([$cursoId, $polo]);
            $cursoDestino = $stmtCurso->fetch();
            
            if (!$cursoDestino) {
                throw new Exception("Curso de destino não encontrado no polo {$polo}");
            }
            
            return $aluno;
        }
        
        $stmtCurso = $this->db->prepare("
            SELECT nome FROM cursos 
            WHERE id = ? AND subdomain = ?
        ");
        $stmtCurso->execute([$cursoId, $polo]);
        $cursoDestino = $stmtCurso->fetch();
        
        if (!$cursoDestino) {
            throw new Exception("Curso de destino não encontrado no polo {$polo}");
        }
        
        $this->registrarLog('boleto_aluno_sem_matricula', $aluno['id'], 
            "Boleto gerado para aluno sem matrícula ativa no curso {$cursoDestino['nome']}");
        
        return $aluno;
    }
    
    private function processarUploadArquivo($arquivo, $numeroBoleto) {
        if ($arquivo['size'] > $this->maxFileSize) {
            throw new Exception("Arquivo muito grande (máximo 5MB)");
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            throw new Exception("Tipo de arquivo não permitido. Apenas PDF é aceito");
        }
        
        $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
        $nomeArquivo = $numeroBoleto . '_' . uniqid() . '.' . $extensao;
        $caminhoCompleto = $this->uploadDir . $nomeArquivo;
        
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
            throw new Exception("Erro ao salvar arquivo");
        }
        
        chmod($caminhoCompleto, 0644);
        
        return $nomeArquivo;
    }
    
    private function processarUploadArquivoMultiplo($arquivo, $numeroBoleto) {
        if ($arquivo['size'] > $this->maxFileSize) {
            throw new Exception("Arquivo {$arquivo['name']} muito grande (máximo 5MB)");
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            throw new Exception("Arquivo {$arquivo['name']} não é um PDF válido");
        }
        
        $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
        $timestamp = date('YmdHis');
        $nomeArquivo = $numeroBoleto . '_' . $timestamp . '_' . uniqid() . '.' . $extensao;
        $caminhoCompleto = $this->uploadDir . $nomeArquivo;
        
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
            throw new Exception("Erro ao salvar arquivo {$arquivo['name']}");
        }
        
        chmod($caminhoCompleto, 0644);
        
        return $nomeArquivo;
    }
    
    private function processarUploadArquivoLote($arquivo, $numeroBoleto) {
        if ($arquivo['size'] > $this->maxFileSize) {
            throw new Exception("Arquivo {$arquivo['name']} muito grande (máximo 5MB)");
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            throw new Exception("Arquivo {$arquivo['name']} não é um PDF válido");
        }
        
        $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
        $nomeArquivo = $numeroBoleto . '_' . uniqid() . '.' . $extensao;
        $caminhoCompleto = $this->uploadDir . $nomeArquivo;
        
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
            throw new Exception("Erro ao salvar arquivo {$arquivo['name']}");
        }
        
        chmod($caminhoCompleto, 0644);
        
        return $nomeArquivo;
    }
    
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
    
    private function organizarArquivosMultiplo($files) {
        $arquivos = [];
        
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
        
        return $arquivos;
    }
    
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
    
    private function extrairDadosArquivosMultiplo($post) {
        $dadosArquivos = [];
        $index = 0;
        
        while (isset($post["arquivo_{$index}_numero"])) {
            $dadosArquivos[$index] = [
                'numero_boleto' => trim($post["arquivo_{$index}_numero"] ?? ''),
                'valor' => floatval($post["arquivo_{$index}_valor"] ?? 0),
                'vencimento' => trim($post["arquivo_{$index}_vencimento"] ?? ''),
                'descricao' => trim($post["arquivo_{$index}_descricao"] ?? ''),
                'pix_desconto_disponivel' => isset($post["arquivo_{$index}_pix_desconto"]) ? 
                    intval($post["arquivo_{$index}_pix_desconto"]) : 0,
                'valor_desconto_pix' => isset($post["arquivo_{$index}_valor_desconto"]) ? 
                    floatval($post["arquivo_{$index}_valor_desconto"]) : null,
                'valor_minimo_desconto' => isset($post["arquivo_{$index}_valor_minimo"]) ? 
                    floatval($post["arquivo_{$index}_valor_minimo"]) : null
            ];
            $index++;
        }
        
        return $dadosArquivos;
    }
    
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
        
        if ($dadosArquivo['pix_desconto_disponivel'] && 
            (!isset($dadosArquivo['valor_desconto_pix']) || $dadosArquivo['valor_desconto_pix'] <= 0)) {
            $erros[] = "Valor do desconto PIX é obrigatório quando desconto está habilitado";
        }
        
        if (!empty($erros)) {
            throw new Exception("Arquivo {$nomeArquivo}: " . implode(', ', $erros));
        }
    }
    
    private function salvarBoleto($dados) {
        $colunas = $this->obterColunasTabelaBoletos();
        
        $camposObrigatorios = [
            'aluno_id', 'curso_id', 'numero_boleto', 'valor', 
            'vencimento', 'status', 'created_at'
        ];
        
        $camposOpcionais = [
            'descricao', 'arquivo_pdf', 'admin_id', 'updated_at',
            'data_pagamento', 'valor_pago', 'observacoes',
            'pix_desconto_disponivel', 'pix_desconto_usado',
            'pix_valor_desconto', 'pix_valor_minimo'
        ];
        
        $campos = [];
        $valores = [];
        $params = [];
        
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
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $this->db->lastInsertId();
    }
    
    private function validarDadosIndividual($post, $files) {
        $erros = [];
        
        if (empty($post['polo'])) $erros[] = "Polo é obrigatório";
        if (empty($post['curso_id'])) $erros[] = "Curso é obrigatório";
        if (empty($post['aluno_cpf'])) $erros[] = "CPF do aluno é obrigatório";
        if (empty($post['valor'])) $erros[] = "Valor é obrigatório";
        if (empty($post['vencimento'])) $erros[] = "Data de vencimento é obrigatória";
        if (empty($post['numero_boleto'])) $erros[] = "Número do boleto é obrigatório";
        
        if (!isset($files['arquivo_pdf']) || $files['arquivo_pdf']['error'] !== UPLOAD_ERR_OK) {
            $erros[] = "Arquivo PDF é obrigatório";
        }
        
        if (!empty($erros)) {
            throw new Exception(implode(', ', $erros));
        }
        
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
        
        // Validação do desconto PIX
        $pixDesconto = isset($post['pix_desconto_disponivel']) ? intval($post['pix_desconto_disponivel']) : 0;
        $valorDesconto = null;
        $valorMinimo = null;
        
        if ($pixDesconto) {
            if (empty($post['valor_desconto_pix']) || floatval($post['valor_desconto_pix']) <= 0) {
                throw new Exception("Valor do desconto PIX é obrigatório quando desconto está habilitado");
            }
            
            $valorDesconto = floatval($post['valor_desconto_pix']);
            $valorMinimo = floatval($post['valor_minimo_desconto'] ?? 50.00);
            
            if ($valorDesconto >= $valor) {
                throw new Exception("Valor do desconto não pode ser maior ou igual ao valor do boleto");
            }
        }
        
        return [
            'polo' => $post['polo'],
            'curso_id' => intval($post['curso_id']),
            'cpf' => $cpf,
            'valor' => $valor,
            'vencimento' => $post['vencimento'],
            'numero_boleto' => $post['numero_boleto'],
            'descricao' => $post['descricao'] ?? '',
            'pix_desconto_disponivel' => $pixDesconto,
            'valor_desconto_pix' => $valorDesconto,
            'valor_minimo_desconto' => $valorMinimo
        ];
    }
    
    private function validarDadosMultiploAluno($post, $files) {
        $erros = [];
        
        if (empty($post['polo'])) $erros[] = "Polo é obrigatório";
        if (empty($post['curso_id'])) $erros[] = "Curso é obrigatório";
        if (empty($post['aluno_cpf'])) $erros[] = "CPF do aluno é obrigatório";
        
        if (!isset($files['arquivos_multiplos']) || empty($files['arquivos_multiplos']['name'][0])) {
            $erros[] = "Pelo menos um arquivo PDF é obrigatório";
        }
        
        if (!empty($erros)) {
            throw new Exception(implode(', ', $erros));
        }
        
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
        
        // Validação do desconto PIX global
        $pixDesconto = isset($post['pix_desconto_global']) ? intval($post['pix_desconto_global']) : 0;
        $valorDesconto = null;
        $valorMinimo = null;
        
        if ($pixDesconto) {
            if (empty($post['valor_desconto_lote']) || floatval($post['valor_desconto_lote']) <= 0) {
                throw new Exception("Valor do desconto PIX é obrigatório quando desconto está habilitado");
            }
            
            $valorDesconto = floatval($post['valor_desconto_lote']);
            $valorMinimo = floatval($post['valor_minimo_lote'] ?? 50.00);
            
            if ($valorDesconto >= $valor) {
                throw new Exception("Valor do desconto não pode ser maior ou igual ao valor do boleto");
            }
        }
        
        return [
            'polo' => $post['polo'],
            'curso_id' => intval($post['curso_id']),
            'valor' => $valor,
            'vencimento' => $post['vencimento'],
            'descricao' => $post['descricao'] ?? '',
            'pix_desconto_global' => $pixDesconto,
            'valor_desconto_lote' => $valorDesconto,
            'valor_minimo_lote' => $valorMinimo
        ];
    }
    
    private function verificarNumeroBoletoUnico($numeroBoleto) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM boletos WHERE numero_boleto = ?");
        $stmt->execute([$numeroBoleto]);
        
        if ($stmt->fetch()['count'] > 0) {
            throw new Exception("Número do boleto {$numeroBoleto} já existe");
        }
    }
    
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
            
            return [
                'id', 'aluno_id', 'curso_id', 'numero_boleto', 'valor', 
                'vencimento', 'status', 'descricao', 'arquivo_pdf', 
                'admin_id', 'created_at', 'updated_at', 'pix_desconto_disponivel', 
                'pix_desconto_usado', 'pix_valor_desconto', 'pix_valor_minimo'
            ];
        }
    }
    
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
    
    public function listarBoletos($filtros = [], $pagina = 1, $itensPorPagina = 20) {
        $where = ['1=1'];
        $params = [];
        
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
        
        if (isset($filtros['com_desconto_pix'])) {
            $where[] = "b.pix_desconto_disponivel = ?";
            $params[] = $filtros['com_desconto_pix'] ? 1 : 0;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE {$whereClause}
        ");
        $stmtCount->execute($params);
        $total = $stmtCount->fetch()['total'];
        
        $offset = ($pagina - 1) * $itensPorPagina;
        $stmt = $this->db->prepare("
            SELECT b.*, a.nome as aluno_nome, a.cpf, c.nome as curso_nome, c.subdomain,
                   ad.nome as admin_nome,
                   b.pix_desconto_disponivel,
                   b.pix_desconto_usado,
                   b.pix_valor_desconto,
                   b.pix_valor_minimo
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
    
    public function buscarBoletoPorId($boletoId) {
        $stmt = $this->db->prepare("
            SELECT b.*, a.nome as aluno_nome, a.cpf, c.nome as curso_nome, c.subdomain,
                   b.pix_desconto_disponivel,
                   b.pix_desconto_usado,
                   b.pix_valor_desconto,
                   b.pix_valor_minimo
            FROM boletos b
            INNER JOIN alunos a ON b.aluno_id = a.id
            INNER JOIN cursos c ON b.curso_id = c.id
            WHERE b.id = ?
        ");
        $stmt->execute([$boletoId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
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
        
        $this->registrarLog('download_boleto', $boletoId, "Download do boleto {$boleto['numero_boleto']}");
        
        return [
            'caminho' => $caminhoArquivo,
            'nome_arquivo' => "Boleto_{$boleto['numero_boleto']}.pdf",
            'tipo_mime' => 'application/pdf'
        ];
    }
    
    private function gerarNumeroSequencialSeguro($prefixoData = null) {
        try {
            if (!$prefixoData) {
                $prefixoData = date('Ymd');
            }
            
            $stmt = $this->db->prepare("
                SELECT MAX(CAST(SUBSTRING(numero_boleto, 9) AS UNSIGNED)) as ultimo_sequencial
                FROM boletos 
                WHERE numero_boleto LIKE ?
            ");
            $stmt->execute([$prefixoData . '%']);
            $resultado = $stmt->fetch();
            
            $ultimoSequencial = $resultado['ultimo_sequencial'] ?? 0;
            $novoSequencial = $ultimoSequencial + 1;
            
            $sequencialFormatado = str_pad($novoSequencial, 4, '0', STR_PAD_LEFT);
            $numeroCompleto = $prefixoData . $sequencialFormatado;
            
            return $numeroCompleto;
            
        } catch (Exception $e) {
            error_log("ERRO na numeração segura: " . $e->getMessage());
            
            $timestamp = substr(time(), -6);
            $random = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            
            return $timestamp . $random;
        }
    }
    
    private function gerarNumerosSequenciaisLote($quantidade, $prefixoData = null) {
        try {
            if (!$prefixoData) {
                $prefixoData = date('Ymd');
            }
            
            $stmt = $this->db->prepare("
                SELECT MAX(CAST(SUBSTRING(numero_boleto, 9) AS UNSIGNED)) as ultimo_sequencial
                FROM boletos 
                WHERE numero_boleto LIKE ?
            ");
            $stmt->execute([$prefixoData . '%']);
            $resultado = $stmt->fetch();
            
            $proximoSequencial = ($resultado['ultimo_sequencial'] ?? 0) + 1;
            
            $numeros = [];
            for ($i = 0; $i < $quantidade; $i++) {
                $sequencial = $proximoSequencial + $i;
                $sequencialFormatado = str_pad($sequencial, 4, '0', STR_PAD_LEFT);
                $numeros[] = $prefixoData . $sequencialFormatado;
            }
            
            return $numeros;
            
        } catch (Exception $e) {
            error_log("ERRO no lote de números: " . $e->getMessage());
            return [];
        }
    }
    
    private function registrarLog($tipo, $boletoId, $descricao) {
        try {
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