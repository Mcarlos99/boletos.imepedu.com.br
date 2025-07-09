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

    /**
 * 🆕 Gera parcelas automaticamente apenas com PIX (sem PDF)
 */
public function gerarParcelasPix($post) {
    try {
        $this->db->beginTransaction();
        
        // Validação dos dados básicos
        $dadosValidados = $this->validarDadosParcelasPixIndividuais($post);
        
        // Verifica se o aluno existe
        $aluno = $this->verificarAlunoFlexivel(
            $dadosValidados['cpf'], 
            $dadosValidados['curso_id'], 
            $dadosValidados['polo']
        );
        
        $parcelasGeradas = [];
        $sucessos = 0;
        $erros = 0;
        $detalhesErros = [];
        $valorTotalGerado = 0;
        $economiaTotal = 0;
        $parcelasComPix = 0;
        
        // Processa cada parcela individual
        foreach ($dadosValidados['parcelas'] as $index => $parcelaData) {
            try {
                // Valida dados da parcela
                $this->validarDadosParcela($parcelaData, $index + 1);
                
                // Gera número único para o boleto
                $numeroBoleto = $this->gerarNumeroSequencialSeguro();
                $this->verificarNumeroBoletoUnico($numeroBoleto);
                
                // Calcula valores com desconto PIX
                $valorOriginal = floatval($parcelaData['valor']);
                $valorFinalPix = $valorOriginal;
                $descontoAplicado = 0;
                
                if ($parcelaData['pix_disponivel'] && $parcelaData['valor_desconto'] > 0) {
                    $descontoAplicado = min($parcelaData['valor_desconto'], $valorOriginal - 10);
                    $valorFinalPix = max(10, $valorOriginal - $descontoAplicado);
                    $parcelasComPix++;
                    $economiaTotal += $descontoAplicado;
                }
                
                // Salva a parcela no banco
                $boletoId = $this->salvarBoleto([
                    'aluno_id' => $aluno['id'],
                    'curso_id' => $dadosValidados['curso_id'],
                    'numero_boleto' => $numeroBoleto,
                    'valor' => $valorOriginal,
                    'vencimento' => $parcelaData['vencimento'],
                    'descricao' => $parcelaData['descricao'],
                    'arquivo_pdf' => null, // Sem arquivo PDF para parcelas PIX
                    'status' => 'pendente',
                    'admin_id' => $_SESSION['admin_id'] ?? null,
                    'pix_desconto_disponivel' => $parcelaData['pix_disponivel'] ? 1 : 0,
                    'pix_desconto_usado' => 0,
                    'pix_valor_desconto' => $parcelaData['pix_disponivel'] ? $parcelaData['valor_desconto'] : null,
                    'pix_valor_minimo' => $parcelaData['pix_disponivel'] ? $parcelaData['valor_minimo'] : null,
                    'tipo_boleto' => 'pix_only'
                ]);
                
                $parcelasGeradas[] = [
                    'boleto_id' => $boletoId,
                    'numero_boleto' => $numeroBoleto,
                    'parcela' => $parcelaData['numero'],
                    'descricao' => $parcelaData['descricao'],
                    'valor_original' => $valorOriginal,
                    'vencimento' => date('d/m/Y', strtotime($parcelaData['vencimento'])),
                    'tem_desconto_pix' => $parcelaData['pix_disponivel'],
                    'valor_desconto' => $descontoAplicado,
                    'valor_final_pix' => $valorFinalPix
                ];
                
                $valorTotalGerado += $valorOriginal;
                $sucessos++;
                
            } catch (Exception $e) {
                $erros++;
                $detalhesErros[] = [
                    'parcela' => $parcelaData['numero'] ?? ($index + 1),
                    'erro' => $e->getMessage()
                ];
                
                error_log("Erro ao gerar parcela individual {$index}: " . $e->getMessage());
            }
        }
        
        $this->db->commit();
        
        // Log da operação
        $this->registrarLog('parcelas_pix_individuais_geradas', null, 
            "Parcelas PIX individuais para {$aluno['nome']}: {$sucessos} parcelas, " .
            "valor total R$ " . number_format($valorTotalGerado, 2, ',', '.') . 
            ", economia R$ " . number_format($economiaTotal, 2, ',', '.') . 
            ", {$parcelasComPix} com desconto PIX");
        
        return [
            'success' => true,
            'message' => 'Parcelas PIX personalizadas geradas com sucesso!',
            'aluno_nome' => $aluno['nome'],
            'parcelas_geradas' => $sucessos,
            'parcelas_com_erro' => $erros,
            'valor_total' => $valorTotalGerado,
            'economia_total' => $economiaTotal,
            'parcelas_com_pix' => $parcelasComPix,
            'detalhes_parcelas' => $parcelasGeradas,
            'detalhes_erros' => $detalhesErros
        ];
        
    } catch (Exception $e) {
        $this->db->rollback();
        error_log("Erro ao gerar parcelas PIX individuais: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

/**
 * 🆕 Valida dados para geração de parcelas PIX individuais
 */
private function validarDadosParcelasPixIndividuais($post) {
    $erros = [];
    
    // Validações obrigatórias
    if (empty($post['polo'])) $erros[] = "Polo é obrigatório";
    if (empty($post['curso_id'])) $erros[] = "Curso é obrigatório";
    if (empty($post['aluno_cpf'])) $erros[] = "CPF do aluno é obrigatório";
    
    if (!empty($erros)) {
        throw new Exception(implode(', ', $erros));
    }
    
    // Validação do CPF
    $cpf = preg_replace('/[^0-9]/', '', $post['aluno_cpf']);
    if (strlen($cpf) !== 11) {
        throw new Exception("CPF deve conter 11 dígitos");
    }
    
    if (!$this->validarCPF($cpf)) {
        throw new Exception("CPF inválido");
    }
    
    // Validação das parcelas individuais
    if (empty($post['parcelas_individuais'])) {
        throw new Exception("Dados das parcelas não encontrados");
    }
    
    $parcelas = json_decode($post['parcelas_individuais'], true);
    if (!$parcelas || !is_array($parcelas)) {
        throw new Exception("Formato de dados das parcelas inválido");
    }
    
    if (count($parcelas) < 2 || count($parcelas) > 32) {
        throw new Exception("Quantidade de parcelas deve ser entre 2 e 32");
    }
    
    // Filtra apenas parcelas com valor válido
    $parcelasValidas = array_filter($parcelas, function($parcela) {
        return isset($parcela['valor']) && floatval($parcela['valor']) > 0;
    });
    
    if (empty($parcelasValidas)) {
        throw new Exception("Pelo menos uma parcela deve ter valor válido");
    }
    
    return [
        'polo' => $post['polo'],
        'curso_id' => intval($post['curso_id']),
        'cpf' => $cpf,
        'parcelas' => $parcelasValidas
    ];
}

/**
 * 🆕 Valida dados de uma parcela individual
 */
private function validarDadosParcela($parcela, $numero) {
    $erros = [];
    
    if (empty($parcela['descricao'])) {
        $erros[] = "Descrição é obrigatória";
    }
    
    if (empty($parcela['vencimento'])) {
        $erros[] = "Data de vencimento é obrigatória";
    } elseif (strtotime($parcela['vencimento']) < strtotime(date('Y-m-d'))) {
        $erros[] = "Data de vencimento não pode ser anterior a hoje";
    }
    
    $valor = floatval($parcela['valor'] ?? 0);
    if ($valor <= 0) {
        $erros[] = "Valor deve ser maior que zero";
    } elseif ($valor < 10.00) {
        $erros[] = "Valor mínimo é R$ 10,00";
    }
    
    // Validação específica do PIX
    if (!empty($parcela['pix_disponivel'])) {
        $valorDesconto = floatval($parcela['valor_desconto'] ?? 0);
        
        if ($valorDesconto <= 0) {
            $erros[] = "Valor do desconto PIX é obrigatório quando PIX está habilitado";
        }
        
        if ($valorDesconto >= $valor) {
            $erros[] = "Valor do desconto não pode ser maior ou igual ao valor da parcela";
        }
        
        // Verifica se após o desconto o valor mínimo é respeitado
        $valorFinalComDesconto = $valor - $valorDesconto;
        if ($valorFinalComDesconto < 10.00) {
            $erros[] = "Valor da parcela com desconto seria R$ " . 
                      number_format($valorFinalComDesconto, 2, ',', '.') . 
                      ", mas o mínimo é R$ 10,00";
        }
        
        // Validação do valor mínimo para aplicar desconto
        $valorMinimo = floatval($parcela['valor_minimo'] ?? 0);
        if ($valorMinimo > 0 && $valor < $valorMinimo) {
            $erros[] = "Valor da parcela (R$ " . number_format($valor, 2, ',', '.') . 
                      ") é menor que o valor mínimo para desconto (R$ " . 
                      number_format($valorMinimo, 2, ',', '.') . ")";
        }
    }
    
    if (!empty($erros)) {
        throw new Exception("Parcela {$numero}: " . implode(', ', $erros));
    }
}

/**
 * 🆕 Busca parcelas individuais de um aluno (com detalhes)
 */
public function buscarParcelasIndividuaisAluno($alunoId, $cursoId = null) {
    $where = ['b.aluno_id = ?', 'b.tipo_boleto = ?'];
    $params = [$alunoId, 'pix_only'];
    
    if ($cursoId) {
        $where[] = 'b.curso_id = ?';
        $params[] = $cursoId;
    }
    
    $whereClause = implode(' AND ', $where);
    
    $stmt = $this->db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain,
               b.pix_desconto_disponivel,
               b.pix_desconto_usado,
               b.pix_valor_desconto,
               b.pix_valor_minimo,
               CASE 
                   WHEN b.pix_desconto_disponivel = 1 AND b.pix_desconto_usado = 0 
                        AND b.vencimento >= CURDATE() AND b.status = 'pendente'
                   THEN 1 ELSE 0 
               END as desconto_ativo,
               CASE 
                   WHEN b.pix_desconto_disponivel = 1 AND b.pix_valor_desconto > 0
                   THEN GREATEST(10, b.valor - b.pix_valor_desconto)
                   ELSE b.valor 
               END as valor_com_pix
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE {$whereClause}
        ORDER BY b.vencimento ASC, b.created_at ASC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 🆕 Estatísticas detalhadas de parcelas PIX individuais
 */
public function getEstatisticasParcelasPixIndividuais() {
    try {
        // Total de parcelas PIX individuais
        $stmt = $this->db->query("
            SELECT COUNT(*) as total_parcelas,
                   SUM(valor) as valor_total,
                   AVG(valor) as valor_medio
            FROM boletos 
            WHERE tipo_boleto = 'pix_only'
        ");
        $totais = $stmt->fetch();
        
        // Parcelas com desconto PIX
        $stmt = $this->db->query("
            SELECT COUNT(*) as com_desconto,
                   SUM(pix_valor_desconto) as desconto_total_disponivel,
                   AVG(pix_valor_desconto) as desconto_medio
            FROM boletos 
            WHERE tipo_boleto = 'pix_only' 
            AND pix_desconto_disponivel = 1
        ");
        $descontos = $stmt->fetch();
        
        // Parcelas por status
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as quantidade
            FROM boletos 
            WHERE tipo_boleto = 'pix_only'
            GROUP BY status
        ");
        $porStatus = [];
        while ($row = $stmt->fetch()) {
            $porStatus[$row['status']] = $row['quantidade'];
        }
        
        // Parcelas vencendo nos próximos 30 dias
        $stmt = $this->db->query("
            SELECT COUNT(*) as vencendo_30_dias
            FROM boletos 
            WHERE tipo_boleto = 'pix_only'
            AND status = 'pendente'
            AND vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ");
        $vencendo = $stmt->fetch();
        
        // Economia total já utilizada
        $stmt = $this->db->query("
            SELECT COUNT(*) as descontos_usados,
                   SUM(pix_valor_desconto) as economia_realizada
            FROM boletos 
            WHERE tipo_boleto = 'pix_only'
            AND pix_desconto_usado = 1
        ");
        $economiaUsada = $stmt->fetch();
        
        return [
            'total_parcelas' => $totais['total_parcelas'] ?? 0,
            'valor_total' => $totais['valor_total'] ?? 0,
            'valor_medio' => $totais['valor_medio'] ?? 0,
            'parcelas_com_desconto' => $descontos['com_desconto'] ?? 0,
            'desconto_total_disponivel' => $descontos['desconto_total_disponivel'] ?? 0,
            'desconto_medio' => $descontos['desconto_medio'] ?? 0,
            'por_status' => $porStatus,
            'vencendo_30_dias' => $vencendo['vencendo_30_dias'] ?? 0,
            'descontos_ja_usados' => $economiaUsada['descontos_usados'] ?? 0,
            'economia_realizada' => $economiaUsada['economia_realizada'] ?? 0,
            'percentual_com_desconto' => $totais['total_parcelas'] > 0 ? 
                round((($descontos['com_desconto'] ?? 0) / $totais['total_parcelas']) * 100, 1) : 0
        ];
        
    } catch (Exception $e) {
        error_log("Erro nas estatísticas de parcelas individuais: " . $e->getMessage());
        return [
            'total_parcelas' => 0,
            'valor_total' => 0,
            'valor_medio' => 0,
            'parcelas_com_desconto' => 0,
            'desconto_total_disponivel' => 0,
            'desconto_medio' => 0,
            'por_status' => [],
            'vencendo_30_dias' => 0,
            'descontos_ja_usados' => 0,
            'economia_realizada' => 0,
            'percentual_com_desconto' => 0
        ];
    }
}

/**
 * 🆕 Duplica parcelas de um boleto existente
 */
public function duplicarParcelasPix($boletoId, $quantidadeParcelas, $intervaloDias = 30) {
    try {
        $this->db->beginTransaction();
        
        // Busca o boleto original
        $boletoOriginal = $this->buscarBoletoPorId($boletoId);
        if (!$boletoOriginal) {
            throw new Exception("Boleto original não encontrado");
        }
        
        $parcelasGeradas = [];
        $dataBase = new DateTime($boletoOriginal['vencimento']);
        
        for ($i = 1; $i <= $quantidadeParcelas; $i++) {
            $novaData = clone $dataBase;
            $novaData->modify("+{$i} month");
            
            $numeroBoleto = $this->gerarNumeroSequencialSeguro();
            $this->verificarNumeroBoletoUnico($numeroBoleto);
            
            $boletoId = $this->salvarBoleto([
                'aluno_id' => $boletoOriginal['aluno_id'],
                'curso_id' => $boletoOriginal['curso_id'],
                'numero_boleto' => $numeroBoleto,
                'valor' => $boletoOriginal['valor'],
                'vencimento' => $novaData->format('Y-m-d'),
                'descricao' => $boletoOriginal['descricao'] . " - Parcela " . ($i + 1),
                'arquivo_pdf' => null,
                'status' => 'pendente',
                'admin_id' => $_SESSION['admin_id'] ?? null,
                'pix_desconto_disponivel' => $boletoOriginal['pix_desconto_disponivel'],
                'pix_desconto_usado' => 0,
                'pix_valor_desconto' => $boletoOriginal['pix_valor_desconto'],
                'pix_valor_minimo' => $boletoOriginal['pix_valor_minimo'],
                'tipo_boleto' => 'pix_only'
            ]);
            
            $parcelasGeradas[] = $boletoId;
        }
        
        $this->db->commit();
        
        $this->registrarLog('parcelas_duplicadas', $boletoId, 
            "Duplicadas {$quantidadeParcelas} parcelas baseadas no boleto {$boletoOriginal['numero_boleto']}");
        
        return [
            'success' => true,
            'parcelas_geradas' => count($parcelasGeradas),
            'boletos_ids' => $parcelasGeradas
        ];
        
    } catch (Exception $e) {
        $this->db->rollback();
        error_log("Erro ao duplicar parcelas: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}


/**
 * 🆕 Valida dados para geração de parcelas PIX
 */
private function validarDadosParcelasPix($post) {
    $erros = [];
    
    // Validações obrigatórias
    if (empty($post['polo'])) $erros[] = "Polo é obrigatório";
    if (empty($post['curso_id'])) $erros[] = "Curso é obrigatório";
    if (empty($post['aluno_cpf'])) $erros[] = "CPF do aluno é obrigatório";
    if (empty($post['quantidade_parcelas'])) $erros[] = "Quantidade de parcelas é obrigatória";
    if (empty($post['valor_total'])) $erros[] = "Valor total é obrigatório";
    if (empty($post['primeira_parcela'])) $erros[] = "Data da primeira parcela é obrigatória";
    if (empty($post['descricao_base'])) $erros[] = "Descrição base é obrigatória";
    
    if (!empty($erros)) {
        throw new Exception(implode(', ', $erros));
    }
    
    // Validação do CPF
    $cpf = preg_replace('/[^0-9]/', '', $post['aluno_cpf']);
    if (strlen($cpf) !== 11) {
        throw new Exception("CPF deve conter 11 dígitos");
    }
    
    if (!$this->validarCPF($cpf)) {
        throw new Exception("CPF inválido");
    }
    
    // Validação da quantidade de parcelas
    $quantidadeParcelas = intval($post['quantidade_parcelas']);
    if ($quantidadeParcelas < 2 || $quantidadeParcelas > 32) {
        throw new Exception("Quantidade de parcelas deve ser entre 2 e 32");
    }
    
    // Validação dos valores
    $valorTotal = floatval($post['valor_total']);
    if ($valorTotal <= 0) {
        throw new Exception("Valor total deve ser maior que zero");
    }
    
    $valorParcela = $valorTotal / $quantidadeParcelas;
    if ($valorParcela < 10.00) {
        throw new Exception("Valor da parcela não pode ser menor que R$ 10,00 (Valor atual: R$ " . 
                          number_format($valorParcela, 2, ',', '.') . ")");
    }
    
    // Validação da data
    if (strtotime($post['primeira_parcela']) < strtotime(date('Y-m-d'))) {
        throw new Exception("Data da primeira parcela não pode ser anterior a hoje");
    }
    
    // Validação do desconto PIX
    $pixDesconto = isset($post['parcelas_pix_desconto']) ? intval($post['parcelas_pix_desconto']) : 0;
    $valorDesconto = null;
    $valorMinimo = null;
    
    if ($pixDesconto) {
        if (empty($post['parcelas_valor_desconto']) || floatval($post['parcelas_valor_desconto']) <= 0) {
            throw new Exception("Valor do desconto PIX é obrigatório quando desconto está habilitado");
        }
        
        $valorDesconto = floatval($post['parcelas_valor_desconto']);
        $valorMinimo = floatval($post['parcelas_valor_minimo'] ?? 0);
        
        if ($valorDesconto >= $valorParcela) {
            throw new Exception("Valor do desconto (R$ " . number_format($valorDesconto, 2, ',', '.') . 
                              ") não pode ser maior ou igual ao valor da parcela (R$ " . 
                              number_format($valorParcela, 2, ',', '.') . ")");
        }
        
        // Verifica se após o desconto o valor mínimo é respeitado
        $valorFinalComDesconto = $valorParcela - $valorDesconto;
        if ($valorFinalComDesconto < 10.00) {
            throw new Exception("Valor da parcela com desconto seria R$ " . 
                              number_format($valorFinalComDesconto, 2, ',', '.') . 
                              ", mas o mínimo é R$ 10,00. Reduza o desconto.");
        }
    }
    
    return [
        'polo' => $post['polo'],
        'curso_id' => intval($post['curso_id']),
        'cpf' => $cpf,
        'quantidade_parcelas' => $quantidadeParcelas,
        'valor_total' => $valorTotal,
        'valor_parcela' => $valorParcela,
        'primeira_parcela' => $post['primeira_parcela'],
        'descricao_base' => trim($post['descricao_base']),
        'pix_desconto_disponivel' => $pixDesconto,
        'valor_desconto_pix' => $valorDesconto,
        'valor_minimo_pix' => $valorMinimo
    ];
}

/**
 * 🆕 Lista boletos PIX (sem arquivo PDF)
 */
public function listarBoletosPix($filtros = [], $pagina = 1, $itensPorPagina = 20) {
    $filtros['tipo_boleto'] = 'pix_only';
    return $this->listarBoletos($filtros, $pagina, $itensPorPagina);
}

/**
 * 🆕 Busca parcelas de um aluno específico
 */
public function buscarParcelasAluno($alunoId, $cursoId = null) {
    $where = ['b.aluno_id = ?'];
    $params = [$alunoId];
    
    if ($cursoId) {
        $where[] = 'b.curso_id = ?';
        $params[] = $cursoId;
    }
    
    // Foca em boletos PIX ou sem arquivo
    $where[] = "(b.arquivo_pdf IS NULL OR b.tipo_boleto = 'pix_only')";
    
    $whereClause = implode(' AND ', $where);
    
    $stmt = $this->db->prepare("
        SELECT b.*, c.nome as curso_nome, c.subdomain,
               b.pix_desconto_disponivel,
               b.pix_desconto_usado,
               b.pix_valor_desconto,
               b.pix_valor_minimo
        FROM boletos b
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE {$whereClause}
        ORDER BY b.vencimento ASC, b.created_at ASC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 🆕 Estatísticas de parcelas PIX
 */
public function getEstatisticasParcelasPix() {
    try {
        // Total de boletos PIX
        $stmt = $this->db->query("
            SELECT COUNT(*) as total_pix
            FROM boletos 
            WHERE arquivo_pdf IS NULL OR tipo_boleto = 'pix_only'
        ");
        $totalPix = $stmt->fetch()['total_pix'];
        
        // Boletos PIX com desconto disponível
        $stmt = $this->db->query("
            SELECT COUNT(*) as com_desconto
            FROM boletos 
            WHERE (arquivo_pdf IS NULL OR tipo_boleto = 'pix_only')
            AND pix_desconto_disponivel = 1
        ");
        $comDesconto = $stmt->fetch()['com_desconto'];
        
        // Valor total dos boletos PIX
        $stmt = $this->db->query("
            SELECT SUM(valor) as valor_total
            FROM boletos 
            WHERE (arquivo_pdf IS NULL OR tipo_boleto = 'pix_only')
            AND status = 'pendente'
        ");
        $valorTotal = $stmt->fetch()['valor_total'] ?? 0;
        
        // Desconto total disponível
        $stmt = $this->db->query("
            SELECT SUM(pix_valor_desconto) as desconto_total
            FROM boletos 
            WHERE (arquivo_pdf IS NULL OR tipo_boleto = 'pix_only')
            AND pix_desconto_disponivel = 1
            AND pix_desconto_usado = 0
            AND status = 'pendente'
            AND vencimento >= CURDATE()
        ");
        $descontoTotal = $stmt->fetch()['desconto_total'] ?? 0;
        
        // Boletos PIX vencidos
        $stmt = $this->db->query("
            SELECT COUNT(*) as vencidos
            FROM boletos 
            WHERE (arquivo_pdf IS NULL OR tipo_boleto = 'pix_only')
            AND status = 'pendente'
            AND vencimento < CURDATE()
        ");
        $vencidos = $stmt->fetch()['vencidos'];
        
        return [
            'total_boletos_pix' => $totalPix,
            'com_desconto_disponivel' => $comDesconto,
            'valor_total_pendente' => $valorTotal,
            'desconto_total_disponivel' => $descontoTotal,
            'boletos_vencidos' => $vencidos,
            'percentual_com_desconto' => $totalPix > 0 ? round(($comDesconto / $totalPix) * 100, 1) : 0
        ];
        
    } catch (Exception $e) {
        error_log("Erro nas estatísticas PIX: " . $e->getMessage());
        return [
            'total_boletos_pix' => 0,
            'com_desconto_disponivel' => 0,
            'valor_total_pendente' => 0,
            'desconto_total_disponivel' => 0,
            'boletos_vencidos' => 0,
            'percentual_com_desconto' => 0
        ];
    }
}

}
?>