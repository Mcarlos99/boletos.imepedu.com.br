-- install.sql - Script de instalação do banco de dados
CREATE DATABASE IF NOT EXISTS boletodb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE boletodb;

-- Tabela de alunos
CREATE TABLE alunos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cpf VARCHAR(11) UNIQUE NOT NULL,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    moodle_user_id INT,
    subdomain VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cpf (cpf),
    INDEX idx_subdomain (subdomain)
);

-- Tabela de cursos
CREATE TABLE cursos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    moodle_course_id INT NOT NULL,
    nome VARCHAR(255) NOT NULL,
    valor DECIMAL(10,2) DEFAULT 0.00,
    subdomain VARCHAR(100) NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_moodle_course (moodle_course_id, subdomain),
    INDEX idx_subdomain (subdomain)
);

-- Tabela de matrículas
CREATE TABLE matriculas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    aluno_id INT NOT NULL,
    curso_id INT NOT NULL,
    status ENUM('ativa', 'inativa', 'concluida', 'cancelada') DEFAULT 'ativa',
    data_matricula DATE NOT NULL,
    data_conclusao DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE KEY unique_matricula (aluno_id, curso_id)
);

-- Tabela de boletos
CREATE TABLE boletos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    aluno_id INT NOT NULL,
    curso_id INT NOT NULL,
    numero_boleto VARCHAR(100) UNIQUE NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    vencimento DATE NOT NULL,
    status ENUM('pendente', 'pago', 'vencido', 'cancelado') DEFAULT 'pendente',
    descricao TEXT,
    nosso_numero VARCHAR(50),
    linha_digitavel TEXT,
    codigo_barras TEXT,
    data_pagamento DATE NULL,
    valor_pago DECIMAL(10,2) NULL,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_vencimento (vencimento),
    INDEX idx_aluno_curso (aluno_id, curso_id)
);

-- Tabela de administradores
CREATE TABLE administradores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    nivel ENUM('admin', 'operador') DEFAULT 'operador',
    ativo BOOLEAN DEFAULT TRUE,
    ultimo_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
);

-- Tabela de logs
CREATE TABLE logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tipo ENUM('login', 'boleto_gerado', 'boleto_pago', 'boleto_cancelado', 'admin_action') NOT NULL,
    usuario_id INT NULL,
    admin_id INT NULL,
    boleto_id INT NULL,
    descricao TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tipo (tipo),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (usuario_id) REFERENCES alunos(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id) REFERENCES administradores(id) ON DELETE SET NULL,
    FOREIGN KEY (boleto_id) REFERENCES boletos(id) ON DELETE SET NULL
);

-- Inserir administrador padrão
INSERT INTO administradores (nome, email, senha, nivel) 
VALUES ('Administrador', 'admin@imepedu.com.br', MD5('admin123'), 'admin');

-- Dados de exemplo para teste
INSERT INTO alunos (cpf, nome, email, subdomain) VALUES 
('12345678901', 'João Silva', 'joao@email.com', 'tucurui.imepedu.com.br'),
('98765432100', 'Maria Santos', 'maria@email.com', 'breubranco.imepedu.com.br');

INSERT INTO cursos (moodle_course_id, nome, valor, subdomain) VALUES 
(1, 'Administração', 500.00, 'tucurui.imepedu.com.br'),
(2, 'Enfermagem', 600.00, 'tucurui.imepedu.com.br'),
(1, 'Pedagogia', 450.00, 'breubranco.imepedu.com.br');

INSERT INTO matriculas (aluno_id, curso_id, data_matricula) VALUES 
(1, 1, '2025-01-01'),
(2, 3, '2025-01-15');

-- Triggers para atualizar status de boletos vencidos
DELIMITER //

CREATE TRIGGER update_boletos_vencidos
BEFORE UPDATE ON boletos
FOR EACH ROW
BEGIN
    IF NEW.status = 'pendente' AND NEW.vencimento < CURDATE() THEN
        SET NEW.status = 'vencido';
    END IF;
END//

DELIMITER ;

-- Procedure para gerar boletos em lote
DELIMITER //

CREATE PROCEDURE GerarBoletosMensais()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_aluno_id INT;
    DECLARE v_curso_id INT;
    DECLARE v_valor DECIMAL(10,2);
    DECLARE v_curso_nome VARCHAR(255);
    
    -- Cursor para buscar matrículas ativas
    DECLARE cur CURSOR FOR 
        SELECT m.aluno_id, m.curso_id, c.valor, c.nome
        FROM matriculas m
        INNER JOIN cursos c ON m.curso_id = c.id
        WHERE m.status = 'ativa' AND c.ativo = TRUE;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_aluno_id, v_curso_id, v_valor, v_curso_nome;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Verifica se já existe boleto para este mês
        IF NOT EXISTS (
            SELECT 1 FROM boletos 
            WHERE aluno_id = v_aluno_id 
            AND curso_id = v_curso_id 
            AND MONTH(vencimento) = MONTH(CURDATE())
            AND YEAR(vencimento) = YEAR(CURDATE())
        ) THEN
            -- Gera boleto com vencimento no dia 10 do próximo mês
            INSERT INTO boletos (
                aluno_id, 
                curso_id, 
                numero_boleto, 
                valor, 
                vencimento, 
                descricao,
                status
            ) VALUES (
                v_aluno_id,
                v_curso_id,
                CONCAT(DATE_FORMAT(CURDATE(), '%Y%m'), LPAD(v_aluno_id, 4, '0'), LPAD(v_curso_id, 2, '0')),
                v_valor,
                DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 10 DAY),
                CONCAT('Mensalidade - ', v_curso_nome, ' - ', DATE_FORMAT(CURDATE(), '%m/%Y')),
                'pendente'
            );
        END IF;
    END LOOP;
    
    CLOSE cur;
END//

DELIMITER ;

-- Views úteis para relatórios
CREATE VIEW vw_boletos_completo AS
SELECT 
    b.id,
    b.numero_boleto,
    b.valor,
    b.vencimento,
    b.status,
    b.data_pagamento,
    b.descricao,
    a.nome as aluno_nome,
    a.cpf,
    a.email,
    c.nome as curso_nome,
    a.subdomain,
    DATEDIFF(CURDATE(), b.vencimento) as dias_vencido
FROM boletos b
INNER JOIN alunos a ON b.aluno_id = a.id
INNER JOIN cursos c ON b.curso_id = c.id;

CREATE VIEW vw_financeiro_resumo AS
SELECT 
    subdomain,
    COUNT(*) as total_boletos,
    SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as boletos_pagos,
    SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as boletos_pendentes,
    SUM(CASE WHEN status = 'vencido' THEN 1 ELSE 0 END) as boletos_vencidos,
    SUM(valor) as valor_total,
    SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as valor_recebido,
    SUM(CASE WHEN status IN ('pendente', 'vencido') THEN valor ELSE 0 END) as valor_pendente
FROM vw_boletos_completo
GROUP BY subdomain;