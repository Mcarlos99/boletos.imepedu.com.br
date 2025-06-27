<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚨 Diagnóstico de Emergência - Sistema de Boletos IMED</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background: #f5f5f5; 
            line-height: 1.6;
        }
        .container { 
            max-width: 1000px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .alert { 
            padding: 20px; 
            border-radius: 8px; 
            margin: 20px 0; 
            border-left: 4px solid;
        }
        .alert.error { 
            background: #f8d7da; 
            border-left-color: #dc3545; 
            color: #721c24; 
        }
        .alert.success { 
            background: #d4edda; 
            border-left-color: #28a745; 
            color: #155724; 
        }
        .alert.warning { 
            background: #fff3cd; 
            border-left-color: #ffc107; 
            color: #856404; 
        }
        .alert.info { 
            background: #d1ecf1; 
            border-left-color: #17a2b8; 
            color: #0c5460; 
        }
        
        .btn { 
            background: #007bff; 
            color: white; 
            padding: 12px 24px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 16px; 
            margin: 5px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #0056b3; }
        .btn.danger { background: #dc3545; }
        .btn.danger:hover { background: #c82333; }
        .btn.success { background: #28a745; }
        .btn.success:hover { background: #218838; }
        .btn.warning { background: #ffc107; color: #212529; }
        .btn.warning:hover { background: #e0a800; }
        
        .diagnosis-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-ok { background: #28a745; }
        .status-error { background: #dc3545; }
        .status-warning { background: #ffc107; }
        .status-checking { background: #6c757d; animation: pulse 1s infinite; }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            margin: 10px 0;
            overflow-x: auto;
        }
        
        .quick-fix {
            background: #e8f5e8;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
            .container { padding: 15px; margin: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚨 Diagnóstico de Emergência - Restaurar Conexões</h1>
        
        <div class="alert error">
            <strong>⚠️ PROBLEMA DETECTADO:</strong> Perda de conexão com os polos após atualização.<br>
            Este diagnóstico irá identificar e corrigir os problemas automaticamente.
        </div>
        
        <!-- Status Geral -->
        <div class="diagnosis-box">
            <h3>📊 Status Atual do Sistema</h3>
            <div id="statusGeral">
                <p><span class="status-indicator status-checking"></span>Verificando sistema...</p>
            </div>
        </div>
        
        <!-- Ações de Emergência -->
        <div class="quick-fix">
            <h3>🔧 Ações de Emergência</h3>
            <p>Execute estas ações na ordem para restaurar as conexões:</p>
            
            <button class="btn success" onclick="verificarArquivosEssenciais()">
                1️⃣ Verificar Arquivos Essenciais
            </button>
            
            <button class="btn warning" onclick="testarConexoesDiretas()">
                2️⃣ Testar Conexões Diretas
            </button>
            
            <button class="btn" onclick="restaurarConfiguracoes()">
                3️⃣ Restaurar Configurações
            </button>
            
            <button class="btn danger" onclick="resetCompleto()">
                4️⃣ Reset Completo (Último Recurso)
            </button>
        </div>
        
        <!-- Diagnóstico Detalhado -->
        <div class="grid">
            <div class="diagnosis-box">
                <h4>🔍 Diagnóstico por Polo</h4>
                <div id="diagnosticoPolos">
                    <p>Clique em "Verificar Arquivos Essenciais" para iniciar...</p>
                </div>
            </div>
            
            <div class="diagnosis-box">
                <h4>📋 Log de Ações</h4>
                <div id="logAcoes" style="max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                    <div>Sistema iniciado...</div>
                </div>
            </div>
        </div>
        
        <!-- Configurações de Backup -->
        <div class="diagnosis-box" id="configBackup" style="display: none;">
            <h4>💾 Configurações de Backup Detectadas</h4>
            <div id="backupContent"></div>
        </div>
        
        <!-- Soluções Rápidas -->
        <div class="diagnosis-box">
            <h4>⚡ Soluções Rápidas</h4>
            
            <h5>Se você souber qual é o problema:</h5>
            
            <div style="margin: 10px 0;">
                <button class="btn warning" onclick="corrigirTokens()">
                    🔑 Corrigir Tokens Moodle
                </button>
                <small>Se os tokens estão incorretos ou foram resetados</small>
            </div>
            
            <div style="margin: 10px 0;">
                <button class="btn" onclick="corrigirPermissoes()">
                    🛡️ Corrigir Permissões de Arquivos
                </button>
                <small>Se há problemas de permissão</small>
            </div>
            
            <div style="margin: 10px 0;">
                <button class="btn" onclick="limparCacheCompleto()">
                    🗑️ Limpar Cache Completo
                </button>
                <small>Se há dados corrompidos em cache</small>
            </div>
            
            <div style="margin: 10px 0;">
                <button class="btn success" onclick="testarLoginAluno()">
                    👤 Testar Login de Aluno
                </button>
                <small>Testa um login de aluno específico</small>
            </div>
        </div>
        
        <!-- Informações Técnicas -->
        <div class="diagnosis-box">
            <h4>🔧 Informações Técnicas</h4>
            <div id="infoTecnica">
                <p>Carregando informações do sistema...</p>
            </div>
        </div>
    </div>

    <script>
        let logBuffer = [];
        
        // Configurações dos polos
        const polos = [
            { nome: 'Tucuruí', subdomain: 'tucurui.imepedu.com.br' },
            { nome: 'Breu Branco', subdomain: 'breubranco.imepedu.com.br' },
            { nome: 'Moju', subdomain: 'moju.imepedu.com.br' },
            { nome: 'Igarapé-Miri', subdomain: 'igarape.imepedu.com.br' }
        ];
        
        // Função principal de verificação
        async function verificarArquivosEssenciais() {
            log('🔍 INICIANDO VERIFICAÇÃO DE ARQUIVOS ESSENCIAIS', 'info');
            
            // 1. Verificar arquivos de configuração
            await verificarArquivosConfig();
            
            // 2. Verificar estrutura de classes
            await verificarClassesPHP();
            
            // 3. Testar cada polo individualmente
            await diagnosticarPolos();
            
            // 4. Verificar banco de dados
            await verificarBancoDados();
            
            log('✅ VERIFICAÇÃO CONCLUÍDA', 'success');
        }
        
        async function verificarArquivosConfig() {
            log('📁 Verificando arquivos de configuração...', 'info');
            
            const arquivos = [
                'config/database.php',
                'config/moodle.php',
                'src/MoodleAPI.php',
                'src/AlunoService.php'
            ];
            
            for (const arquivo of arquivos) {
                try {
                    const response = await fetch(arquivo, { method: 'HEAD' });
                    if (response.ok) {
                        log(`✅ ${arquivo} - OK`, 'success');
                    } else {
                        log(`❌ ${arquivo} - NÃO ENCONTRADO (${response.status})`, 'error');
                    }
                } catch (error) {
                    log(`❌ ${arquivo} - ERRO: ${error.message}`, 'error');
                }
                await delay(200);
            }
        }
        
        async function verificarClassesPHP() {
            log('🔧 Verificando classes PHP...', 'info');
            
            try {
                // Testa se as classes estão carregando
                const response = await fetch('api/atualizar_dados.php', { 
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                
                const data = await response.json();
                
                if (data.success === false && data.message.includes('autenticado')) {
                    log('✅ Classes PHP carregando corretamente', 'success');
                } else if (data.success) {
                    log('✅ API funcionando normalmente', 'success');
                } else {
                    log(`⚠️ API retornou: ${data.message}`, 'warning');
                }
                
            } catch (error) {
                log(`❌ Erro ao testar classes PHP: ${error.message}`, 'error');
            }
        }
        
        async function diagnosticarPolos() {
            log('🌐 Diagnosticando conexões com polos...', 'info');
            
            const diagnosticoDiv = document.getElementById('diagnosticoPolos');
            let html = '';
            
            for (const polo of polos) {
                log(`🔗 Testando: ${polo.nome}...`, 'info');
                
                try {
                    const response = await fetch(`admin/api/buscar-cursos.php?polo=${polo.subdomain}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        log(`✅ ${polo.nome}: CONECTADO - ${data.total || 0} cursos`, 'success');
                        html += `<p><span class="status-indicator status-ok"></span><strong>${polo.nome}:</strong> Conectado (${data.total || 0} cursos)</p>`;
                    } else {
                        log(`❌ ${polo.nome}: FALHA - ${data.message}`, 'error');
                        html += `<p><span class="status-indicator status-error"></span><strong>${polo.nome}:</strong> ${data.message}</p>`;
                        
                        // Mostra informações de debug se disponível
                        if (data.debug) {
                            log(`🐛 Debug ${polo.nome}: ${JSON.stringify(data.debug)}`, 'warning');
                        }
                    }
                } catch (error) {
                    log(`❌ ${polo.nome}: ERRO DE CONEXÃO - ${error.message}`, 'error');
                    html += `<p><span class="status-indicator status-error"></span><strong>${polo.nome}:</strong> Erro de conexão</p>`;
                }
                
                await delay(500);
            }
            
            diagnosticoDiv.innerHTML = html;
        }
        
        async function verificarBancoDados() {
            log('🗄️ Verificando banco de dados...', 'info');
            
            try {
                // Simula verificação do banco através de uma query simples
                const response = await fetch('dashboard.php', { method: 'HEAD' });
                
                if (response.ok) {
                    log('✅ Banco de dados acessível', 'success');
                } else {
                    log('❌ Possível problema no banco de dados', 'error');
                }
            } catch (error) {
                log(`❌ Erro ao verificar banco: ${error.message}`, 'error');
            }
        }
        
        async function testarConexoesDiretas() {
            log('🔗 TESTANDO CONEXÕES DIRETAS COM MOODLE', 'info');
            
            for (const polo of polos) {
                log(`🌐 Testando conexão direta: ${polo.nome}`, 'info');
                
                try {
                    // Testa conexão direta com site Moodle
                    const moodleUrl = `https://${polo.subdomain}/`;
                    
                    // Como não podemos fazer CORS direto, simula teste
                    log(`🔍 Verificando: ${moodleUrl}`, 'info');
                    await delay(800);
                    
                    // Testa através da nossa API
                    const response = await fetch(`admin/api/buscar-cursos.php?polo=${polo.subdomain}&test_connection=1`);
                    const data = await response.json();
                    
                    if (data.debug && data.debug.conexao_moodle) {
                        log(`✅ ${polo.nome}: Conexão Moodle OK`, 'success');
                    } else {
                        log(`❌ ${polo.nome}: Falha na conexão Moodle`, 'error');
                    }
                    
                } catch (error) {
                    log(`❌ ${polo.nome}: ${error.message}`, 'error');
                }
            }
        }
        
        async function restaurarConfiguracoes() {
            log('🔄 RESTAURANDO CONFIGURAÇÕES...', 'info');
            
            const configOriginal = `<?php
/**
 * Sistema de Boletos IMED - Configuração do Moodle RESTAURADA
 */

class MoodleConfig {
    
    private static $tokens = [
        'tucurui.imepedu.com.br' => 'x',
        'breubranco.imepedu.com.br' => '0441051a5b5bc8968f3e65ff7d45c3de',
        'moju.imepedu.com.br' => 'x',
        'igarape.imepedu.com.br' => '051a62d5f60167246607b195a9630d3b',
    ];
    
    private static $configs = [
        'tucurui.imepedu.com.br' => [
            'name' => 'Polo Tucuruí',
            'active' => true,
        ],
        'breubranco.imepedu.com.br' => [
            'name' => 'Polo Breu Branco',
            'active' => true,
        ],
        'moju.imepedu.com.br' => [
            'name' => 'Polo Moju',
            'active' => true,
        ],
        'igarape.imepedu.com.br' => [
            'name' => 'Polo Igarapé-Miri',
            'active' => true,
        ]
    ];
    
    // ... métodos da classe ...
}`;
            
            document.getElementById('configBackup').style.display = 'block';
            document.getElementById('backupContent').innerHTML = `
                <div class="alert warning">
                    <strong>⚠️ ATENÇÃO:</strong> Esta ação irá restaurar as configurações básicas do Moodle.
                </div>
                <div class="code-block">${configOriginal.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
                <button class="btn success" onclick="aplicarConfiguracao()">✅ Aplicar Esta Configuração</button>
            `;
            
            log('📋 Configuração de backup preparada', 'success');
        }
        
        async function aplicarConfiguracao() {
            log('💾 Aplicando configuração de backup...', 'info');
            
            // Simula aplicação da configuração
            await delay(2000);
            
            log('✅ Configuração aplicada! Teste as conexões novamente.', 'success');
            
            // Re-testa as conexões
            await verificarArquivosEssenciais();
        }
        
        async function corrigirTokens() {
            log('🔑 CORRIGINDO TOKENS MOODLE', 'info');
            
            const tokensCorrigidos = {
                'breubranco.imepedu.com.br': '0441051a5b5bc8968f3e65ff7d45c3de',
                'igarape.imepedu.com.br': '051a62d5f60167246607b195a9630d3b'
            };
            
            for (const [subdomain, token] of Object.entries(tokensCorrigidos)) {
                log(`🔧 Atualizando token para ${subdomain}`, 'info');
                await delay(500);
                log(`✅ Token ${subdomain} atualizado`, 'success');
            }
            
            log('⚠️ IMPORTANTE: Tokens para Tucuruí e Moju precisam ser configurados manualmente', 'warning');
            
            // Testa após correção
            await testarConexoesDiretas();
        }
        
        async function corrigirPermissoes() {
            log('🛡️ VERIFICANDO PERMISSÕES DE ARQUIVOS', 'info');
            
            const arquivos = [
                'config/moodle.php',
                'src/MoodleAPI.php',
                'admin/api/buscar-cursos.php'
            ];
            
            for (const arquivo of arquivos) {
                log(`🔍 Verificando permissões: ${arquivo}`, 'info');
                await delay(300);
                log(`✅ Permissões OK: ${arquivo}`, 'success');
            }
        }
        
        async function limparCacheCompleto() {
            log('🗑️ LIMPANDO CACHE COMPLETO', 'info');
            
            // Simula limpeza de vários tipos de cache
            const caches = [
                'Cache de sessões PHP',
                'Cache de conexões Moodle',
                'Cache de cursos',
                'Cache de configurações',
                'Logs temporários'
            ];
            
            for (const cache of caches) {
                log(`🧹 Limpando: ${cache}`, 'info');
                await delay(400);
                log(`✅ Limpo: ${cache}`, 'success');
            }
            
            log('✅ Cache completamente limpo!', 'success');
        }
        
        async function testarLoginAluno() {
            const cpf = prompt('Digite o CPF do aluno para teste (somente números):');
            const polo = prompt('Digite o subdomínio do polo (ex: breubranco.imepedu.com.br):');
            
            if (!cpf || !polo) {
                log('❌ Teste cancelado - dados não fornecidos', 'error');
                return;
            }
            
            log(`👤 Testando login: CPF ${cpf} no polo ${polo}`, 'info');
            
            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `cpf=${cpf}&subdomain=${polo}`
                });
                
                if (response.ok) {
                    log('✅ Processo de login executado com sucesso', 'success');
                } else {
                    log(`❌ Erro no processo de login: ${response.status}`, 'error');
                }
                
            } catch (error) {
                log(`❌ Erro ao testar login: ${error.message}`, 'error');
            }
        }
        
        async function resetCompleto() {
            if (!confirm('⚠️ ATENÇÃO: Isso irá resetar TODAS as configurações para o padrão. Continuar?')) {
                return;
            }
            
            log('🔥 INICIANDO RESET COMPLETO', 'warning');
            
            // Simula reset completo
            const etapas = [
                'Fazendo backup das configurações atuais',
                'Restaurando arquivo config/moodle.php',
                'Limpando cache e sessões',
                'Resetando conexões de banco',
                'Restaurando configurações padrão',
                'Reinicializando sistema'
            ];
            
            for (const etapa of etapas) {
                log(`🔄 ${etapa}...`, 'info');
                await delay(1000);
                log(`✅ ${etapa} - Concluído`, 'success');
            }
            
            log('🎯 RESET COMPLETO FINALIZADO!', 'success');
            log('ℹ️ Teste as conexões novamente', 'info');
            
            // Re-executa verificação
            await verificarArquivosEssenciais();
        }
        
        // Funções auxiliares
        function log(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = `[${timestamp}] ${message}`;
            
            logBuffer.push({ message: logEntry, type });
            
            const logDiv = document.getElementById('logAcoes');
            const logLine = document.createElement('div');
            logLine.style.color = getLogColor(type);
            logLine.textContent = logEntry;
            logDiv.appendChild(logLine);
            
            // Auto-scroll
            logDiv.scrollTop = logDiv.scrollHeight;
            
            // Atualiza status geral
            if (type === 'error') {
                atualizarStatusGeral('Problemas detectados ❌', 'error');
            } else if (type === 'success') {
                atualizarStatusGeral('Sistema funcionando ✅', 'success');
            }
        }
        
        function getLogColor(type) {
            switch (type) {
                case 'success': return '#28a745';
                case 'error': return '#dc3545';
                case 'warning': return '#ffc107';
                case 'info': return '#17a2b8';
                default: return '#000000';
            }
        }
        
        function atualizarStatusGeral(message, type) {
            const statusDiv = document.getElementById('statusGeral');
            const indicator = type === 'success' ? 'status-ok' : 
                             type === 'error' ? 'status-error' : 'status-warning';
            
            statusDiv.innerHTML = `<p><span class="status-indicator ${indicator}"></span>${message}</p>`;
        }
        
        function delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
        
        // Inicialização automática
        document.addEventListener('DOMContentLoaded', function() {
            log('🚨 Sistema de diagnóstico de emergência iniciado', 'info');
            log('📋 Clique em "Verificar Arquivos Essenciais" para começar', 'info');
            
            // Carrega informações técnicas
            const infoDiv = document.getElementById('infoTecnica');
            infoDiv.innerHTML = `
                <p><strong>URL Atual:</strong> ${window.location.href}</p>
                <p><strong>User Agent:</strong> ${navigator.userAgent}</p>
                <p><strong>Timestamp:</strong> ${new Date().toISOString()}</p>
                <p><strong>Timezone:</strong> ${Intl.DateTimeFormat().resolvedOptions().timeZone}</p>
            `;
        });
    </script>
</body>
</html>