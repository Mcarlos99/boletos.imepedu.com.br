<?php
/**
 * Sistema de Boletos IMEPEDU - Setup de Documentos
 * Arquivo: setup-documentos.php
 * 
 * Execute este arquivo para criar/verificar a estrutura de documentos
 */

require_once 'config/database.php';

try {
    $db = (new Database())->getConnection();
    
    echo "<h1>Setup do Sistema de Documentos IMEPEDU</h1>\n";
    echo "<p>Verificando e criando estrutura necessária...</p>\n";
    
    // 1. Verificar se tabela alunos_documentos existe
    $stmt = $db->query("SHOW TABLES LIKE 'alunos_documentos'");
    $tabelaExiste = $stmt->rowCount() > 0;
    
    if (!$tabelaExiste) {
        echo "<p><strong>Criando tabela alunos_documentos...</strong></p>\n";
        
        $sqlTabela = "
        CREATE TABLE `alunos_documentos` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `aluno_id` int(11) NOT NULL,
            `tipo_documento` varchar(50) NOT NULL,
            `nome_original` varchar(255) NOT NULL,
            `nome_arquivo` varchar(255) NOT NULL,
            `caminho_arquivo` varchar(500) NOT NULL,
            `tamanho_arquivo` int(11) NOT NULL,
            `tipo_mime` varchar(100) NOT NULL,
            `status` enum('pendente','aprovado','rejeitado') DEFAULT 'pendente',
            `observacoes` text,
            `data_upload` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `aprovado_por` int(11) DEFAULT NULL,
            `data_aprovacao` timestamp NULL DEFAULT NULL,
            `ip_upload` varchar(45) DEFAULT NULL,
            `user_agent` text,
            PRIMARY KEY (`id`),
            KEY `idx_aluno_id` (`aluno_id`),
            KEY `idx_tipo_documento` (`tipo_documento`),
            KEY `idx_status` (`status`),
            KEY `idx_data_upload` (`data_upload`),
            CONSTRAINT `fk_documentos_aluno` FOREIGN KEY (`aluno_id`) REFERENCES `alunos` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_documentos_aprovador` FOREIGN KEY (`aprovado_por`) REFERENCES `administradores` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($sqlTabela);
        echo "<p style='color: green;'>✓ Tabela alunos_documentos criada com sucesso!</p>\n";
    } else {
        echo "<p style='color: blue;'>✓ Tabela alunos_documentos já existe</p>\n";
    }
    
    // 2. Verificar colunas na tabela alunos
    $stmt = $db->query("SHOW COLUMNS FROM alunos LIKE 'documentos_completos'");
    $colunaExiste = $stmt->rowCount() > 0;
    
    if (!$colunaExiste) {
        echo "<p><strong>Adicionando colunas de controle de documentos...</strong></p>\n";
        
        $db->exec("ALTER TABLE `alunos` ADD COLUMN `documentos_completos` tinyint(1) DEFAULT 0");
        $db->exec("ALTER TABLE `alunos` ADD COLUMN `documentos_data_atualizacao` timestamp NULL DEFAULT NULL");
        
        echo "<p style='color: green;'>✓ Colunas adicionadas à tabela alunos</p>\n";
    } else {
        echo "<p style='color: blue;'>✓ Colunas de controle já existem</p>\n";
    }
    
    // 3. Criar diretório de upload
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/documentos/alunos/';
    if (!file_exists($uploadDir)) {
        if (mkdir($uploadDir, 0755, true)) {
            echo "<p style='color: green;'>✓ Diretório de upload criado: $uploadDir</p>\n";
        } else {
            echo "<p style='color: red;'>✗ Erro ao criar diretório: $uploadDir</p>\n";
        }
    } else {
        echo "<p style='color: blue;'>✓ Diretório de upload já existe</p>\n";
    }
    
    // 4. Verificar permissões
    if (is_writable($uploadDir)) {
        echo "<p style='color: green;'>✓ Diretório tem permissão de escrita</p>\n";
    } else {
        echo "<p style='color: red;'>✗ Diretório sem permissão de escrita. Execute: chmod 755 $uploadDir</p>\n";
    }
    
    // 5. Verificar DocumentosService
    $serviceFile = __DIR__ . '/src/DocumentosService.php';
    if (file_exists($serviceFile)) {
        echo "<p style='color: green;'>✓ DocumentosService.php encontrado</p>\n";
        
        // Testa instanciação
        require_once $serviceFile;
        try {
            $service = new DocumentosService();
            echo "<p style='color: green;'>✓ DocumentosService instanciado com sucesso</p>\n";
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Erro ao instanciar DocumentosService: " . $e->getMessage() . "</p>\n";
        }
    } else {
        echo "<p style='color: red;'>✗ DocumentosService.php não encontrado em: $serviceFile</p>\n";
    }
    
    // 6. Teste de conexão de API
    echo "<p><strong>Testando APIs de documentos...</strong></p>\n";
    
    $apis = [
        '/api/documentos-aluno.php',
        '/api/upload-documento.php',
        '/api/download-documento.php'
    ];
    
    foreach ($apis as $api) {
        $filepath = $_SERVER['DOCUMENT_ROOT'] . $api;
        if (file_exists($filepath)) {
            echo "<p style='color: green;'>✓ API encontrada: $api</p>\n";
        } else {
            echo "<p style='color: red;'>✗ API não encontrada: $api</p>\n";
        }
    }
    
    // 7. Estatísticas finais
    echo "<p><strong>Estatísticas atuais:</strong></p>\n";
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM alunos");
    $totalAlunos = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM alunos_documentos");
    $totalDocumentos = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(DISTINCT aluno_id) as total FROM alunos_documentos");
    $alunosComDocs = $stmt->fetch()['total'];
    
    echo "<ul>";
    echo "<li>Total de alunos: $totalAlunos</li>";
    echo "<li>Total de documentos: $totalDocumentos</li>";
    echo "<li>Alunos com documentos: $alunosComDocs</li>";
    echo "</ul>";
    
    echo "<p style='color: green; font-weight: bold;'>✓ Setup concluído com sucesso!</p>\n";
    echo "<p><a href='/dashboard.php'>← Voltar ao Dashboard</a> | <a href='/admin/documentos.php'>Ir para Admin →</a></p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Erro no setup:</strong> " . $e->getMessage() . "</p>\n";
    echo "<p>Verifique as permissões do banco de dados e tente novamente.</p>\n";
}
?>