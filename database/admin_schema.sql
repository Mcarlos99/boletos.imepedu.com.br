-- Sistema de Boletos IMED - Schema SQL para Área Administrativa
-- Arquivo: database/admin_schema.sql

-- Tabela de administradores
CREATE TABLE IF NOT EXISTS administradores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    login VARCHAR(50) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    nivel ENUM('super_admin', 'admin', 'operador') DEFAULT 'admin',
    ativo BOOLEAN DEFAULT TRUE,
    ultimo_acesso DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_login (login),
    INDEX idx_ativo (ativo),
    INDEX idx_nivel (nivel)
);

-- Tabela de configurações do sistema
CREATE TABLE IF NOT EXISTS configuracoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    chave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT,
    descricao TEXT,
    tipo ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_chave (chave)
);

-- Adiciona coluna admin_id na tabela boletos (se não existir)
ALTER TABLE boletos 
ADD COLUMN IF NOT EXISTS admin_id INT,
ADD COLUMN IF NOT EXISTS arquivo_pdf VARCHAR(255),
ADD COLUMN IF NOT EXISTS data_pagamento DATE,
ADD COLUMN IF NOT EXISTS valor_pago DECIMAL(10,2),
ADD COLUMN IF NOT EXISTS observacoes TEXT,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
ADD INDEX IF NOT EXISTS idx_admin_id (admin_id),
ADD INDEX IF NOT EXISTS idx_arquivo_pdf (arquivo_pdf),
ADD INDEX IF NOT EXISTS idx_data_pagamento (data_pagamento),
ADD FOREIGN KEY IF NOT EXISTS fk_boletos_admin (admin_id) REFERENCES administradores(id) ON DELETE SET NULL;

-- Adiciona coluna boleto_id na tabela logs (se não existir)
ALTER TABLE logs 
ADD COLUMN IF NOT EXISTS boleto_id INT,
ADD INDEX IF NOT EXISTS idx_boleto_id (boleto_id),
ADD FOREIGN KEY IF NOT EXISTS fk_logs_boleto (boleto_id) REFERENCES boletos(id) ON DELETE SET NULL;

-- Insere administrador padrão (senha: admin123)
INSERT IGNORE INTO administradores (nome, login, senha, nivel) 
VALUES ('Administrador', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin');

-- Configurações padrão do sistema
INSERT IGNORE INTO configuracoes (chave, valor, descricao, tipo) VALUES
('sistema_nome', 'Sistema de Boletos IMED', 'Nome do sistema', 'string'),
('sistema_versao', '1.0.0', 'Versão do sistema', 'string'),
('email_notificacoes', 'admin@imepedu.com.br', 'Email para notificações do sistema', 'string'),
('boletos_por_pagina', '20', 'Número de boletos por página', 'number'),
('backup_automatico', 'true', 'Executa backup automático', 'boolean'),
('limpeza_automatica', 'true', 'Executa limpeza automática', 'boolean'),
('upload_max_size', '5', 'Tamanho máximo de upload em MB', 'number'),
('notificar_vencimentos', 'true', 'Enviar notificações de vencimento', 'boolean'),
('dias_aviso_vencimento', '7', 'Dias de antecedência para aviso de vencimento', 'number'),
('tema_admin', 'azul', 'Tema da interface administrativa', 'string'),
('manutencao_modo', 'false', 'Modo de manutenção ativo', 'boolean');

-- Índices adicionais para otimização
ALTER TABLE boletos 
ADD INDEX IF NOT EXISTS idx_status_vencimento (status, vencimento),
ADD INDEX IF NOT EXISTS idx_created_at (created_at),
ADD INDEX IF NOT EXISTS idx_numero_boleto (numero_boleto);

ALTER TABLE logs 
ADD INDEX IF NOT EXISTS idx_tipo_created (tipo, created_at),
ADD INDEX IF NOT EXISTS idx_usuario_tipo (usuario_id, tipo);

ALTER TABLE alunos 
ADD INDEX IF NOT EXISTS idx_cpf_subdomain (cpf, subdomain),
ADD INDEX IF NOT EXISTS idx_created_at (created_at);

ALTER TABLE cursos 
ADD INDEX IF NOT EXISTS idx_subdomain_ativo (subdomain, ativo);

ALTER TABLE matriculas 
ADD INDEX IF NOT EXISTS idx_aluno_curso_status (aluno_id, curso_id, status);

-- Triggers para manter dados atualizados

-- Trigger para atualizar updated_at em boletos
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS tr_boletos_updated_at 
    BEFORE UPDATE ON boletos
    FOR EACH ROW 
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END$$
DELIMITER ;

-- Trigger para registrar alterações importantes em boletos
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS tr_boletos_status_change 
    AFTER UPDATE ON boletos
    FOR EACH ROW 
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO logs (tipo, boleto_id, descricao, created_at) 
        VALUES (
            'status_alterado', 
            NEW.id, 
            CONCAT('Status alterado de "', OLD.status, '" para "', NEW.status, '"'),
            NOW()
        );
    END IF;
END$$
DELIMITER ;

-- View para relatórios de boletos
CREATE OR REPLACE VIEW vw_boletos_relatorio AS
SELECT 
    b.id,
    b.numero_boleto,
    b.valor,
    b.vencimento,
    b.status,
    b.data_pagamento,
    b.valor_pago,
    b.created_at,
    a.nome AS aluno_nome,
    a.cpf,
    a.email,
    c.nome AS curso_nome,
    c.subdomain,
    ad.nome AS admin_nome,
    CASE 
        WHEN b.status = 'pendente' AND b.vencimento < CURDATE() THEN 'VENCIDO'
        WHEN b.status = 'pendente' AND DATEDIFF(b.vencimento, CURDATE()) <= 7 THEN 'VENCE_BREVE'
        ELSE UPPER(b.status)
    END AS status_categoria,
    DATEDIFF(CURDATE(), b.vencimento) AS dias_atraso,
    DATEDIFF(b.vencimento, CURDATE()) AS dias_vencimento
FROM boletos b
INNER JOIN alunos a ON b.aluno_id = a.id
INNER JOIN cursos c ON b.curso_id = c.id
LEFT JOIN administradores ad ON b.admin_id = ad.id;

-- View para estatísticas por polo
CREATE OR REPLACE VIEW vw_estatisticas_polo AS
SELECT 
    c.subdomain,
    COUNT(b.id) AS total_boletos,
    COUNT(CASE WHEN b.status = 'pago' THEN 1 END) AS boletos_pagos,
    COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) AS boletos_pendentes,
    COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) AS boletos_vencidos,
    COUNT(CASE WHEN b.status = 'cancelado' THEN 1 END) AS boletos_cancelados,
-- View para estatísticas por polo
CREATE OR REPLACE VIEW vw_estatisticas_polo AS
SELECT 
    c.subdomain,
    COUNT(b.id) AS total_boletos,
    COUNT(CASE WHEN b.status = 'pago' THEN 1 END) AS boletos_pagos,
    COUNT(CASE WHEN b.status = 'pendente' THEN 1 END) AS boletos_pendentes,
    COUNT(CASE WHEN b.status = 'vencido' THEN 1 END) AS boletos_vencidos,
    COUNT(CASE WHEN b.status = 'cancelado' THEN 1 END) AS boletos_cancelados,
    SUM(b.valor) AS valor_total,
    SUM(CASE WHEN b.status = 'pago' THEN b.valor_pago ELSE 0 END) AS valor_recebido,
    SUM(CASE WHEN b.status IN ('pendente', 'vencido') THEN b.valor ELSE 0 END) AS valor_pendente,
    COUNT(DISTINCT b.aluno_id) AS alunos_distintos,
    COUNT(DISTINCT b.curso_id) AS cursos_distintos,
    AVG(b.valor) AS valor_medio
FROM cursos c
LEFT JOIN boletos b ON c.id = b.curso_id
WHERE c.ativo = 1
GROUP BY c.subdomain;

-- View para dashboard administrativo
CREATE OR REPLACE VIEW vw_dashboard_admin AS
SELECT 
    'total' AS metrica,
    COUNT(*) AS valor,
    SUM(valor) AS valor_monetario
FROM boletos
UNION ALL
SELECT 
    'pagos' AS metrica,
    COUNT(*) AS valor,
    SUM(CASE WHEN valor_pago IS NOT NULL THEN valor_pago ELSE valor END) AS valor_monetario
FROM boletos 
WHERE status = 'pago'
UNION ALL
SELECT 
    'pendentes' AS metrica,
    COUNT(*) AS valor,
    SUM(valor) AS valor_monetario
FROM boletos 
WHERE status = 'pendente'
UNION ALL
SELECT 
    'vencidos' AS metrica,
    COUNT(*) AS valor,
    SUM(valor) AS valor_monetario
FROM boletos 
WHERE status = 'vencido'
UNION ALL
SELECT 
    'cancelados' AS metrica,
    COUNT(*) AS valor,
    SUM(valor) AS valor_monetario
FROM boletos 
WHERE status = 'cancelado';

-- Procedure para atualizar boletos vencidos
DELIMITER $
CREATE PROCEDURE IF NOT EXISTS sp_atualizar_boletos_vencidos()
BEGIN
    DECLARE boletos_atualizados INT DEFAULT 0;
    
    UPDATE boletos 
    SET status = 'vencido', updated_at = NOW()
    WHERE status = 'pendente' 
    AND vencimento < CURDATE();
    
    SET boletos_atualizados = ROW_COUNT();
    
    -- Registra log da operação
    INSERT INTO logs (tipo, descricao, created_at) 
    VALUES ('sistema_auto', CONCAT('Atualização automática: ', boletos_atualizados, ' boletos marcados como vencidos'), NOW());
    
    SELECT boletos_atualizados AS boletos_atualizados;
END$
DELIMITER ;

-- Procedure para limpeza automática
DELIMITER $
CREATE PROCEDURE IF NOT EXISTS sp_limpeza_automatica()
BEGIN
    DECLARE logs_removidos INT DEFAULT 0;
    DECLARE boletos_removidos INT DEFAULT 0;
    
    -- Remove logs antigos (mais de 90 dias)
    DELETE FROM logs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND tipo NOT IN ('login_admin', 'sistema_auto');
    
    SET logs_removidos = ROW_COUNT();
    
    -- Remove boletos cancelados antigos (mais de 1 ano)
    DELETE FROM boletos 
    WHERE status = 'cancelado' 
    AND created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
    
    SET boletos_removidos = ROW_COUNT();
    
    -- Otimiza tabelas
    OPTIMIZE TABLE logs, boletos, alunos, cursos, matriculas;
    
    -- Registra log da limpeza
    INSERT INTO logs (tipo, descricao, created_at) 
    VALUES ('sistema_auto', CONCAT('Limpeza automática: ', logs_removidos, ' logs e ', boletos_removidos, ' boletos cancelados removidos'), NOW());
    
    SELECT logs_removidos, boletos_removidos;
END$
DELIMITER ;

-- Function para calcular taxa de pagamento
DELIMITER $
CREATE FUNCTION IF NOT EXISTS fn_taxa_pagamento(polo VARCHAR(100))
RETURNS DECIMAL(5,2)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE total_boletos INT DEFAULT 0;
    DECLARE boletos_pagos INT DEFAULT 0;
    DECLARE taxa DECIMAL(5,2) DEFAULT 0;
    
    IF polo IS NULL THEN
        SELECT COUNT(*) INTO total_boletos FROM boletos;
        SELECT COUNT(*) INTO boletos_pagos FROM boletos WHERE status = 'pago';
    ELSE
        SELECT COUNT(b.*) INTO total_boletos 
        FROM boletos b 
        INNER JOIN cursos c ON b.curso_id = c.id 
        WHERE c.subdomain = polo;
        
        SELECT COUNT(b.*) INTO boletos_pagos 
        FROM boletos b 
        INNER JOIN cursos c ON b.curso_id = c.id 
        WHERE c.subdomain = polo AND b.status = 'pago';
    END IF;
    
    IF total_boletos > 0 THEN
        SET taxa = (boletos_pagos / total_boletos) * 100;
    END IF;
    
    RETURN taxa;
END$
DELIMITER ;

-- Event scheduler para tarefas automáticas (executar diariamente às 2:00)
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS evt_manutencao_diaria
ON SCHEDULE EVERY 1 DAY STARTS '2024-01-01 02:00:00'
DO
BEGIN
    -- Atualiza boletos vencidos
    CALL sp_atualizar_boletos_vencidos();
    
    -- Limpeza automática (apenas aos domingos)
    IF DAYOFWEEK(NOW()) = 1 THEN
        CALL sp_limpeza_automatica();
    END IF;
END;

-- Insere dados de exemplo para teste (apenas se tabelas estiverem vazias)
INSERT IGNORE INTO alunos (cpf, nome, email, moodle_user_id, subdomain, created_at) VALUES
('12345678901', 'João Silva Santos', 'joao.silva@email.com', 100, 'tucurui.imepedu.com.br', NOW()),
('98765432109', 'Maria Oliveira Costa', 'maria.oliveira@email.com', 101, 'breubranco.imepedu.com.br', NOW()),
('11122233344', 'Pedro Santos Lima', 'pedro.santos@email.com', 102, 'moju.imepedu.com.br', NOW());

INSERT IGNORE INTO cursos (moodle_course_id, nome, nome_curto, subdomain, ativo, created_at) VALUES
(10, 'Administração de Empresas', 'ADM', 'tucurui.imepedu.com.br', 1, NOW()),
(11, 'Ciências Contábeis', 'CONT', 'breubranco.imepedu.com.br', 1, NOW()),
(12, 'Gestão de Recursos Humanos', 'RH', 'moju.imepedu.com.br', 1, NOW());

INSERT IGNORE INTO matriculas (aluno_id, curso_id, status, data_matricula, created_at) VALUES
(1, 1, 'ativa', '2024-01-15', NOW()),
(2, 2, 'ativa', '2024-01-20', NOW()),
(3, 3, 'ativa', '2024-02-01', NOW());

-- Boletos de exemplo
INSERT IGNORE INTO boletos (aluno_id, curso_id, numero_boleto, valor, vencimento, status, descricao, admin_id, created_at) VALUES
(1, 1, '2024011500001', 450.00, '2024-02-15', 'pago', 'Mensalidade Janeiro 2024', 1, '2024-01-15 10:00:00'),
(1, 1, '2024021500001', 450.00, '2024-03-15', 'pendente', 'Mensalidade Fevereiro 2024', 1, '2024-02-15 10:00:00'),
(2, 2, '2024012000001', 380.00, '2024-02-20', 'pago', 'Mensalidade Janeiro 2024', 1, '2024-01-20 10:00:00'),
(2, 2, '2024022000001', 380.00, '2024-03-20', 'vencido', 'Mensalidade Fevereiro 2024', 1, '2024-02-20 10:00:00'),
(3, 3, '2024020100001', 320.00, '2024-03-01', 'pendente', 'Mensalidade Fevereiro 2024', 1, '2024-02-01 10:00:00');

-- Atualiza boletos pagos com data de pagamento
UPDATE boletos 
SET data_pagamento = '2024-02-10', valor_pago = valor 
WHERE status = 'pago' AND data_pagamento IS NULL;

-- Logs de exemplo
INSERT IGNORE INTO logs (tipo, usuario_id, boleto_id, descricao, created_at) VALUES
('login_admin', 1, NULL, 'Login realizado: Administrador', NOW()),
('boleto_gerado', 1, 1, 'Boleto 2024011500001 criado para João Silva Santos', '2024-01-15 10:00:00'),
('boleto_pago_admin', 1, 1, 'Boleto 2024011500001 marcado como pago', '2024-02-10 14:30:00'),
('sistema_auto', NULL, NULL, 'Atualização automática: 1 boletos marcados como vencidos', '2024-03-21 02:00:00');

-- Verificação de integridade dos dados
SELECT 'Verificação de Integridade - Administradores' as Tabela, COUNT(*) as Registros FROM administradores
UNION ALL
SELECT 'Verificação de Integridade - Alunos', COUNT(*) FROM alunos
UNION ALL
SELECT 'Verificação de Integridade - Cursos', COUNT(*) FROM cursos
UNION ALL
SELECT 'Verificação de Integridade - Matrículas', COUNT(*) FROM matriculas
UNION ALL
SELECT 'Verificação de Integridade - Boletos', COUNT(*) FROM boletos
UNION ALL
SELECT 'Verificação de Integridade - Logs', COUNT(*) FROM logs
UNION ALL
SELECT 'Verificação de Integridade - Configurações', COUNT(*) FROM configuracoes;

-- Grants para usuário de aplicação (ajustar conforme necessário)
-- GRANT SELECT, INSERT, UPDATE, DELETE ON boletodb.* TO 'boletouser'@'localhost';
-- GRANT EXECUTE ON PROCEDURE boletodb.sp_atualizar_boletos_vencidos TO 'boletouser'@'localhost';
-- GRANT EXECUTE ON PROCEDURE boletodb.sp_limpeza_automatica TO 'boletouser'@'localhost';
-- FLUSH PRIVILEGES;