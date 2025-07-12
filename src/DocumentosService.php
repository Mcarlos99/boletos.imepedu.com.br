<?php
/**
 * Sistema de Boletos IMEPEDU - Serviço de Documentos dos Alunos
 * Arquivo: src/DocumentosService.php - VERSÃO LIMPA SEM DUPLICAÇÕES
 */

require_once __DIR__ . '/../config/database.php';

class DocumentosService {
    
    private $db;
    private $uploadDir;
    private $maxFileSize;
    private $allowedTypes;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
        $this->uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/documentos/alunos/';
        $this->maxFileSize = 5 * 1024 * 1024; // 5MB
        $this->allowedTypes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        
        // Cria diretório se não existir
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    /**
     * Tipos de documentos aceitos
     */
    public static function getTiposDocumentos() {
        return [
            'rg' => [
                'nome' => 'RG - Documento de Identidade',
                'descricao' => 'Documento de identidade com foto (RG ou CNH)',
                'obrigatorio' => true,
                'icone' => 'fas fa-id-card'
            ],
            'cpf' => [
                'nome' => 'CPF',
                'descricao' => 'Cadastro de Pessoa Física',
                'obrigatorio' => true,
                'icone' => 'fas fa-user-check'
            ],
            'certidao' => [
                'nome' => 'Certidão',
                'descricao' => 'Certidão de nascimento ou casamento',
                'obrigatorio' => true,
                'icone' => 'fas fa-certificate'
            ],
            'historico_medio' => [
                'nome' => 'Histórico Escolar',
                'descricao' => 'Histórico escolar do ensino médio',
                'obrigatorio' => true,
                'icone' => 'fas fa-graduation-cap'
            ],
            'certificado_medio' => [
                'nome' => 'Certificado Ensino Médio',
                'descricao' => 'Certificado de conclusão do ensino médio',
                'obrigatorio' => true,
                'icone' => 'fas fa-scroll'
            ],
            'comprovante_residencia' => [
                'nome' => 'Comprovante de Residência',
                'descricao' => 'Comprovante de residência atualizado (últimos 3 meses)',
                'obrigatorio' => true,
                'icone' => 'fas fa-home'
            ],
            'titulo_eleitor' => [
                'nome' => 'Título de Eleitor',
                'descricao' => 'Título de eleitor (para maiores de 18 anos)',
                'obrigatorio' => true,
                'icone' => 'fas fa-vote-yea'
            ],
            'reservista' => [
                'nome' => 'Certificado de Reservista',
                'descricao' => 'Certificado de reservista (para homens maiores de 18 anos)',
                'obrigatorio' => true,
                'icone' => 'fas fa-shield-alt'
            ],
            'foto_3x4' => [
                'nome' => 'Foto 3x4',
                'descricao' => 'Foto 3x4 recente',
                'obrigatorio' => true,
                'icone' => 'fas fa-camera'
            ]
        ];
    }
    
    /**
     * Faz upload de um documento
     */
    public function uploadDocumento($alunoId, $tipoDocumento, $arquivo) {
        try {
            $this->validarUpload($arquivo, $tipoDocumento);
            
            // Gera nome único para o arquivo
            $extensao = $this->getExtensao($arquivo['name']);
            $nomeArquivo = $this->gerarNomeArquivo($alunoId, $tipoDocumento, $extensao);
            $caminhoCompleto = $this->uploadDir . $nomeArquivo;
            
            // Remove arquivo anterior se existir
            $this->removerDocumentoAnterior($alunoId, $tipoDocumento);
            
            // Move arquivo para diretório de upload
            if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
                throw new Exception('Erro ao salvar arquivo no servidor');
            }
            
            // Salva no banco de dados
            $stmt = $this->db->prepare("
                INSERT INTO alunos_documentos (
                    aluno_id, tipo_documento, nome_original, nome_arquivo, 
                    caminho_arquivo, tamanho_arquivo, tipo_mime, 
                    ip_upload, user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $alunoId,
                $tipoDocumento,
                $arquivo['name'],
                $nomeArquivo,
                $caminhoCompleto,
                $arquivo['size'],
                $arquivo['type'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            $documentoId = $this->db->lastInsertId();
            
            // Atualiza status dos documentos do aluno
            $this->atualizarStatusDocumentosAluno($alunoId);
            
            // Registra log
            $this->registrarLog($alunoId, $tipoDocumento, 'upload', 'Documento enviado pelo aluno');
            
            return [
                'success' => true,
                'documento_id' => $documentoId,
                'nome_arquivo' => $nomeArquivo,
                'message' => 'Documento enviado com sucesso!'
            ];
            
        } catch (Exception $e) {
            // Remove arquivo se foi criado
            if (isset($caminhoCompleto) && file_exists($caminhoCompleto)) {
                unlink($caminhoCompleto);
            }
            
            error_log("Erro no upload de documento: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Valida upload do arquivo
     */
    private function validarUpload($arquivo, $tipoDocumento) {
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            $erros = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande (limite do servidor)',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande (limite do formulário)',
                UPLOAD_ERR_PARTIAL => 'Upload incompleto',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
                UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever arquivo',
                UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão'
            ];
            
            throw new Exception($erros[$arquivo['error']] ?? 'Erro desconhecido no upload');
        }
        
        if ($arquivo['size'] > $this->maxFileSize) {
            throw new Exception('Arquivo muito grande. Tamanho máximo: 5MB');
        }
        
        if (!in_array($arquivo['type'], $this->allowedTypes)) {
            throw new Exception('Tipo de arquivo não permitido. Use: JPG, PNG, GIF, PDF, DOC ou DOCX');
        }
        
        // Validação adicional baseada no tipo de documento
        if ($tipoDocumento === 'foto_3x4' && !in_array($arquivo['type'], ['image/jpeg', 'image/jpg', 'image/png'])) {
            throw new Exception('Foto deve ser em formato JPG ou PNG');
        }
        
        // Verifica se não é um script malicioso
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if ($mimeType !== $arquivo['type']) {
            throw new Exception('Tipo de arquivo suspeito detectado');
        }
    }
    
    /**
     * Gera nome único para o arquivo
     */
    private function gerarNomeArquivo($alunoId, $tipoDocumento, $extensao) {
        $timestamp = date('YmdHis');
        $hash = substr(md5($alunoId . $tipoDocumento . $timestamp), 0, 8);
        return "aluno_{$alunoId}_{$tipoDocumento}_{$timestamp}_{$hash}.{$extensao}";
    }
    
    /**
     * Obtém extensão do arquivo
     */
    private function getExtensao($nomeArquivo) {
        return strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));
    }
    
    /**
     * Remove documento anterior do mesmo tipo
     */
    private function removerDocumentoAnterior($alunoId, $tipoDocumento) {
        $stmt = $this->db->prepare("
            SELECT caminho_arquivo FROM alunos_documentos 
            WHERE aluno_id = ? AND tipo_documento = ?
        ");
        $stmt->execute([$alunoId, $tipoDocumento]);
        
        while ($doc = $stmt->fetch()) {
            if (file_exists($doc['caminho_arquivo'])) {
                unlink($doc['caminho_arquivo']);
            }
        }
        
        // Remove registros antigos do banco
        $stmt = $this->db->prepare("
            DELETE FROM alunos_documentos 
            WHERE aluno_id = ? AND tipo_documento = ?
        ");
        $stmt->execute([$alunoId, $tipoDocumento]);
    }
    
    /**
     * Lista documentos de um aluno
     */
    public function listarDocumentosAluno($alunoId) {
        $stmt = $this->db->prepare("
            SELECT d.*, a.nome as aprovado_por_nome
            FROM alunos_documentos d
            LEFT JOIN administradores a ON d.aprovado_por = a.id
            WHERE d.aluno_id = ?
            ORDER BY d.data_upload DESC
        ");
        $stmt->execute([$alunoId]);
        
        $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tiposDocumentos = self::getTiposDocumentos();
        
        // Organiza por tipo
        $documentosOrganizados = [];
        foreach ($documentos as $doc) {
            $tipo = $tiposDocumentos[$doc['tipo_documento']] ?? null;
            if ($tipo) {
                $doc['tipo_info'] = $tipo;
                $doc['url_download'] = $this->gerarUrlDownload($doc['id']);
                $doc['tamanho_formatado'] = $this->formatarTamanho($doc['tamanho_arquivo']);
                $documentosOrganizados[$doc['tipo_documento']] = $doc;
            }
        }
        
        return $documentosOrganizados;
    }
    
    /**
     * Obtém status dos documentos do aluno
     */
    public function getStatusDocumentosAluno($alunoId) {
        $documentosEnviados = $this->listarDocumentosAluno($alunoId);
        $tiposDocumentos = self::getTiposDocumentos();
        
        $status = [
            'total_tipos' => count($tiposDocumentos),
            'enviados' => count($documentosEnviados),
            'obrigatorios_enviados' => 0,
            'total_obrigatorios' => 0,
            'aprovados' => 0,
            'pendentes' => 0,
            'rejeitados' => 0,
            'faltando' => [],
            'percentual_completo' => 0
        ];
        
        foreach ($tiposDocumentos as $tipo => $info) {
            if ($info['obrigatorio']) {
                $status['total_obrigatorios']++;
                
                if (isset($documentosEnviados[$tipo])) {
                    $status['obrigatorios_enviados']++;
                    
                    switch ($documentosEnviados[$tipo]['status']) {
                        case 'aprovado':
                            $status['aprovados']++;
                            break;
                        case 'rejeitado':
                            $status['rejeitados']++;
                            break;
                        default:
                            $status['pendentes']++;
                    }
                } else {
                    $status['faltando'][] = $info['nome'];
                }
            }
        }
        
        if ($status['total_obrigatorios'] > 0) {
            $status['percentual_completo'] = round(
                ($status['obrigatorios_enviados'] / $status['total_obrigatorios']) * 100
            );
        }
        
        return $status;
    }
    
    /**
     * 🆕 MÉTODO PÚBLICO: Atualiza status dos documentos do aluno
     */
    public function atualizarStatusDocumentosAluno($alunoId) {
        try {
            $status = $this->getStatusDocumentosAluno($alunoId);
            
            $completo = ($status['obrigatorios_enviados'] >= $status['total_obrigatorios']);
            
            $stmt = $this->db->prepare("
                UPDATE alunos 
                SET documentos_completos = ?, 
                    documentos_data_atualizacao = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $completo ? 1 : 0, 
                $alunoId
            ]);

            error_log("📋 Status documentos atualizado para aluno {$alunoId}: " .
                      "Completo: " . ($completo ? 'SIM' : 'NÃO') . ", " .
                      "Enviados: {$status['enviados']}, " .
                      "Aprovados: {$status['aprovados']}");

            return true;

        } catch (Exception $e) {
            error_log("❌ Erro ao atualizar status documentos aluno {$alunoId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 🆕 MÉTODO: Força recarregamento dos documentos do aluno
     */
    public function recarregarDocumentosAluno($alunoId) {
        try {
            // Recarrega status
            $status = $this->getStatusDocumentosAluno($alunoId);
            
            // Atualiza na base
            $this->atualizarStatusDocumentosAluno($alunoId);

            // Retorna documentos atualizados
            $documentos = $this->listarDocumentosAluno($alunoId);

            return [
                'success' => true,
                'documentos' => $documentos,
                'status' => $status,
                'recarregado_em' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            error_log("❌ Erro ao recarregar documentos aluno {$alunoId}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 🆕 MÉTODO: Verifica se aluno tem documentos válidos em sessão
     */
    public function verificarConsistenciaDocumentos($alunoId) {
        try {
            // 1. Busca na base de dados
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_db,
                       COUNT(CASE WHEN status = 'aprovado' THEN 1 END) as aprovados_db,
                       MAX(data_upload) as ultimo_upload
                FROM alunos_documentos 
                WHERE aluno_id = ?
            ");
            $stmt->execute([$alunoId]);
            $dadosDB = $stmt->fetch();

            // 2. Busca status calculado
            $status = $this->getStatusDocumentosAluno($alunoId);

            // 3. Verifica consistência
            $consistente = ($dadosDB['total_db'] == $status['enviados']);

            return [
                'consistente' => $consistente,
                'dados_db' => $dadosDB,
                'status_calculado' => $status,
                'verificado_em' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            error_log("❌ Erro verificação consistência documentos: " . $e->getMessage());
            return ['consistente' => false, 'erro' => $e->getMessage()];
        }
    }
    
    /**
     * Aprova ou rejeita documento (admin)
     */
    public function aprovarRejeitarDocumento($documentoId, $acao, $adminId, $observacoes = '') {
        if (!in_array($acao, ['aprovado', 'rejeitado'])) {
            throw new Exception('Ação inválida');
        }
        
        $stmt = $this->db->prepare("
            UPDATE alunos_documentos 
            SET status = ?, aprovado_por = ?, data_aprovacao = NOW(), observacoes = ?
            WHERE id = ?
        ");
        $stmt->execute([$acao, $adminId, $observacoes, $documentoId]);
        
        if ($stmt->rowCount() > 0) {
            // Busca info do documento para log
            $stmt = $this->db->prepare("
                SELECT aluno_id, tipo_documento FROM alunos_documentos WHERE id = ?
            ");
            $stmt->execute([$documentoId]);
            $doc = $stmt->fetch();
            
            if ($doc) {
                $this->registrarLog(
                    $doc['aluno_id'], 
                    $doc['tipo_documento'], 
                    $acao, 
                    "Documento {$acao} pelo administrador. Obs: {$observacoes}",
                    $adminId
                );
                
                // Atualiza status geral do aluno
                $this->atualizarStatusDocumentosAluno($doc['aluno_id']);
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Gera URL para download do documento
     */
    private function gerarUrlDownload($documentoId) {
        return "/api/download-documento.php?id=" . $documentoId;
    }
    
    /**
     * Download de documento
     */
    public function downloadDocumento($documentoId, $usuarioId = null, $isAdmin = false) {
        $stmt = $this->db->prepare("
            SELECT d.*, a.nome as aluno_nome
            FROM alunos_documentos d
            INNER JOIN alunos a ON d.aluno_id = a.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentoId]);
        $documento = $stmt->fetch();
        
        if (!$documento) {
            throw new Exception('Documento não encontrado');
        }
        
        // Verifica permissão
        if (!$isAdmin && $documento['aluno_id'] != $usuarioId) {
            throw new Exception('Sem permissão para acessar este documento');
        }
        
        if (!file_exists($documento['caminho_arquivo'])) {
            throw new Exception('Arquivo não encontrado no servidor');
        }
        
        // Registra acesso
        $this->registrarLog(
            $documento['aluno_id'], 
            $documento['tipo_documento'], 
            'download', 
            $isAdmin ? 'Download pelo administrador' : 'Download pelo aluno',
            $usuarioId
        );
        
        return [
            'caminho' => $documento['caminho_arquivo'],
            'nome_original' => $documento['nome_original'],
            'tipo_mime' => $documento['tipo_mime'],
            'tamanho' => $documento['tamanho_arquivo']
        ];
    }
    
    /**
     * Formata tamanho do arquivo
     */
    private function formatarTamanho($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Registra log de atividades
     */
    private function registrarLog($alunoId, $tipoDocumento, $acao, $descricao, $usuarioId = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO logs (
                    tipo, usuario_id, boleto_id, descricao, 
                    ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                'documento_' . $acao,
                $usuarioId,
                $alunoId, // Usa aluno_id no campo boleto_id para referência
                "Documento {$tipoDocumento}: {$descricao}",
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar log de documento: " . $e->getMessage());
        }
    }
    
    /**
     * Remove documento (apenas admin)
     */
    public function removerDocumento($documentoId, $adminId) {
        $stmt = $this->db->prepare("
            SELECT * FROM alunos_documentos WHERE id = ?
        ");
        $stmt->execute([$documentoId]);
        $documento = $stmt->fetch();
        
        if (!$documento) {
            throw new Exception('Documento não encontrado');
        }
        
        // Remove arquivo físico
        if (file_exists($documento['caminho_arquivo'])) {
            unlink($documento['caminho_arquivo']);
        }
        
        // Remove do banco
        $stmt = $this->db->prepare("DELETE FROM alunos_documentos WHERE id = ?");
        $stmt->execute([$documentoId]);
        
        // Registra log
        $this->registrarLog(
            $documento['aluno_id'], 
            $documento['tipo_documento'], 
            'removido', 
            'Documento removido pelo administrador',
            $adminId
        );
        
        // Atualiza status do aluno
        $this->atualizarStatusDocumentosAluno($documento['aluno_id']);
        
        return true;
    }
    
    /**
     * Estatísticas gerais de documentos
     */
    public function getEstatisticasDocumentos() {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT aluno_id) as alunos_com_documentos,
                COUNT(*) as total_documentos,
                COUNT(CASE WHEN status = 'aprovado' THEN 1 END) as aprovados,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
                COUNT(CASE WHEN status = 'rejeitado' THEN 1 END) as rejeitados,
                tipo_documento,
                COUNT(*) as quantidade
            FROM alunos_documentos
            GROUP BY tipo_documento
        ");
        $stmt->execute();
        $porTipo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT aluno_id) as alunos_com_documentos,
                COUNT(*) as total_documentos,
                COUNT(CASE WHEN status = 'aprovado' THEN 1 END) as aprovados,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
                COUNT(CASE WHEN status = 'rejeitado' THEN 1 END) as rejeitados
            FROM alunos_documentos
        ");
        $stmt->execute();
        $geral = $stmt->fetch();
        
        return [
            'geral' => $geral,
            'por_tipo' => $porTipo
        ];
    }
}
?>