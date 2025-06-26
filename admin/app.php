<?php
define('APP_NAME', 'Sistema de Boletos IMED');
define('APP_URL', 'https://boletos.imepedu.com.br');
define('APP_ENV', 'production'); // ou 'development'

// Configuração do banco
define('DB_HOST', 'localhost');
define('DB_NAME', 'boletodb');
define('DB_USER', 'boletouser');
define('DB_PASS', 'gg3V6cNafyqsukXEJCcQ');

// Configuração de email (para notificações)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'sistema@imepedu.com.br');
define('SMTP_PASS', 'senha_email');

// Configuração de logs
define('LOG_PATH', __DIR__ . '/../logs/');
define('LOG_LEVEL', 'INFO');

// Configuração de segurança
define('JWT_SECRET', 'chave_secreta_muito_forte_aqui');
define('HASH_SALT', 'salt_unico_para_senhas');
?>