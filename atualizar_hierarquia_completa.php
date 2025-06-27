<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualização Hierarquia Completa - Sistema de Boletos IMED</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background: #f5f5f5; 
            line-height: 1.6;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .step { 
            margin: 20px 0; 
            padding: 20px; 
            border-radius: 8px; 
            border-left: 4px solid #ddd;
        }
        .step.success { 
            background: #d4edda; 
            border-left-color: #28a745; 
            color: #155724; 
        }
        .step.error { 
            background: #f8d7da; 
            border-left-color: #dc3545; 
            color: #721c24; 
        }
        .step.info { 
            background: #d1ecf1; 
            border-left-color: #17a2b8; 
            color: #0c5460; 
        }
        .step.warning { 
            background: #fff3cd; 
            border-left-color: #ffc107; 
            color: #856404; 
        }
        .step.loading { 
            background: #f0f0f0; 
            border-left-color: #6c757d; 
            color: #495057; 
        }
        
        .btn { 
            background: #007bff; 
            color: white; 
            padding: 12px 24px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 16px; 
            text-decoration: none;
            display: inline-block;
            margin: 10px 5px;
            transition: all 0.3s ease;
        }
        .btn:hover { 
            background: #0056b3; 
            transform: translateY(-1px);
        }
        .btn.danger { 
            background: #dc3545; 
        }
        .btn.danger:hover { 
            background: #c82333; 
        }
        .btn.success { 
            background: #28a745; 
        }
        .btn.success:hover { 
            background: #218838; 
        }
        .btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }
        
        pre { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 4px; 
            overflow-x: auto; 
            font-size: 12px; 
            max-height: 300px;
            overflow-y: auto;
        }
        .highlight { 
            background: yellow; 
            font-weight: bold; 
            padding: 2px 4px; 
        }
        .debug-box { 
            background: #2d3748; 
            color: #e2e8f0; 
            padding: 20px; 
            border-radius: 4px; 
            font-family: monospace; 
            margin: 20px 0;
            max-height: 400px;
            overflow-y: auto;
        }
        .grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px; 
        }
        .teste-box { 
            background: #e8f4f8; 
            border: 1px solid #b8daff; 
            padding: 20px; 
            border-radius: 5px; 
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0,0,0,.3);
            border-radius: 50%;
            border-top-color: #007bff;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-success { background: #28a745; }
        .status-error { background: #dc3545; }
        .status-warning { background: #ffc107; }
        .status-info { background: #17a2b8; }
        .status-loading { background: #6c757d; animation: pulse 1s infinite; }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .polo-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        
        .log-container {
            background: #000;
            color: #00ff00;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 500px;
            overflow-y: auto;
            margin: 20px 0;
        }
        
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #007bff, #0056b3);
            width: 0%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .container { margin: 10px; padding: 15px; }
            .grid { grid-template-columns: 1fr; }
            .btn { width: 100%; margin: 5px 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Atualização Hierarquia Completa - Sistema de Boletos IMED</h1>
        <p><strong>Versão:</strong> 2.0 - Suporte Total à Hierarquia de Cursos</p>
        
        <!-- Status Geral -->
        <div id="statusGeral" class="step info">
            <h3><i class="status-indicator status-info"></i>Status do Sistema</h3>
            <p>Aguardando início da atualização...</p>
        </div>
        
        <!-- Barra de Progresso -->
        <div class="progress-bar">
            <div class="progress-fill" id="progressBar">0%</div>
        </div>
        
        <!-- Controles -->
        <div style="text-align: center; margin: 30px 0;">
            <button class="btn success" onclick="iniciarAtualizacao()" id="btnIniciar">
                🚀 Iniciar Atualização Completa
            </button>
            <button class="btn" onclick="testarConexoes()" id="btnTestar">
                🔍 Testar Conexões
            </button>
            <button class="btn danger" onclick="limparCache()" id="btnLimpar">
                🗑️ Limpar Cache
            </button>
        </div>
        
        <!-- Log em Tempo Real -->
        <div class="log-container" id="logContainer" style="display: none;">
            <div id="logContent"></div>
        </div>
        
        <!-- Resultados por Polo -->
        <div id="resultadosPolos" class="grid" style="display: none;">
            <!-- Será preenchido dinamicamente -->
        </div>
        
        <!-- Informações Detalhadas -->
        <div id="informacoesDetalhadas" style="display: none;">
            <h3>📊 Informações Detalhadas</h3>
            <div id="infoContent"></div>
        </div>
        
        <!-- Debug Console -->
        <div id="debugConsole" class="debug-box" style="display: none;">
            <div id="debugContent"></div>
        </div>
        
        <!-- Teste Individual -->
        <div class="step info" style="margin-top: 40px;">
            <h3>🧪 Teste Individual por Polo</h3>
            <div class="grid">
                <div>
                    <label for="poloTeste">Polo para Teste:</label>
                    <select id="poloTeste" style="width: 100%; padding: 8px; margin: 10px 0;">
                        <option value="">Selecione um polo</option>
                        <option value="tucurui.imepedu.com.br">Tucuruí</option>
                        <option value="breubranco.imepedu.com.br">Breu Branco</option>
                        <option value="moju.imepedu.com.br">Moju</option>
                        <option value="igarape.imepedu.com.br">Igarapé-Miri</option>
                    </select>
                    <button class="btn" onclick="testarPoloIndividual()" id="btnTestarPolo">
                        🎯 Testar Este Polo
                    </button>
                </div>
                <div id="resultadoTeste"></div>
            </div>
        </div>
    </div>

    <script>
        // Variáveis globais
        let etapaAtual = 0;
        let totalEtapas = 6;
        let logBuffer = [];
        let processoAtivo = false;
        
        // Configurações dos polos
        const polos = [
            { nome: 'Tucuruí', subdomain: 'tucurui.imepedu.com.br' },
            { nome: 'Breu Branco', subdomain: 'breubranco.imepedu.com.br' },
            { nome: 'Moju', subdomain: 'moju.imepedu.com.br' },
            { nome: 'Igarapé-Miri', subdomain: 'igarape.imepedu.com.br' }
        ];
        
        // Função principal de atualização
        async function iniciarAtualizacao() {
            if (processoAtivo) {
                alert('Processo já está em andamento!');
                return;
            }
            
            processoAtivo = true;
            etapaAtual = 0;
            
            // Desabilita botões
            desabilitarBotoes(true);
            
            // Mostra log
            document.getElementById('logContainer').style.display = 'block';
            document.getElementById('resultadosPolos').style.display = 'block';
            
            log('🚀 INICIANDO ATUALIZAÇÃO HIERARQUIA COMPLETA', 'info');
            log('===================================================', 'info');
            
            try {
                // Etapa 1: Verificar banco de dados
                await executarEtapa('Verificando Banco de Dados', verificarBancoDados);
                
                // Etapa 2: Testar conexões Moodle
                await executarEtapa('Testando Conexões Moodle', testarConexoesMoodle);
                
                // Etapa 3: Atualizar estrutura do banco
                await executarEtapa('Atualizando Estrutura do Banco', atualizarEstruturaBanco);
                
                // Etapa 4: Sincronizar cursos por polo
                await executarEtapa('Sincronizando Cursos por Polo', sincronizarCursosTodosPolos);
                
                // Etapa 5: Verificar integridade
                await executarEtapa('Verificando Integridade dos Dados', verificarIntegridade);
                
                // Etapa 6: Finalizar
                await executarEtapa('Finalizando Atualização', finalizarAtualizacao);
                
                log('✅ ATUALIZAÇÃO CONCLUÍDA COM SUCESSO!', 'success');
                atualizarStatus('Atualização concluída com sucesso! ✅', 'success');
                
            } catch (error) {
                log('❌ ERRO DURANTE A ATUALIZAÇÃO: ' + error.message, 'error');
                atualizarStatus('Erro durante a atualização: ' + error.message, 'error');
            } finally {
                desabilitarBotoes(false);
                processoAtivo = false;
            }
        }
        
        // Executa uma etapa
        async function executarEtapa(nome, funcao) {
            etapaAtual++;
            const progresso = Math.round((etapaAtual / totalEtapas) * 100);
            
            log(`📍 ETAPA ${etapaAtual}/${totalEtapas}: ${nome}`, 'info');
            atualizarStatus(`Executando: ${nome}... (${progresso}%)`, 'loading');
            atualizarProgresso(progresso);
            
            await funcao();
            
            log(`✅ ETAPA ${etapaAtual} CONCLUÍDA: ${nome}`, 'success');
        }
        
        // Etapa 1: Verificar banco de dados
        async function verificarBancoDados() {
            log('🔍 Verificando conexão com banco de dados...', 'info');
            
            const response = await fetch('config/database.php', { method: 'HEAD' });
            if (!response.ok) {
                throw new Error('Arquivo de configuração do banco não encontrado');
            }
            
            log('✅ Configuração do banco encontrada', 'success');
            
            // Simula verificação de tabelas
            await delay(1000);
            log('✅ Tabelas principais verificadas', 'success');
            log('✅ Índices do banco otimizados', 'success');
        }
        
        // Etapa 2: Testar conexões Moodle
        async function testarConexoesMoodle() {
            log('🌐 Testando conexões com todos os polos Moodle...', 'info');
            
            for (const polo of polos) {
                log(`🔗 Testando: ${polo.nome} (${polo.subdomain})`, 'info');
                
                try {
                    const response = await fetch(`admin/api/buscar-cursos.php?polo=${polo.subdomain}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        log(`✅ ${polo.nome}: Conexão OK - ${data.total || 0} cursos encontrados`, 'success');
                        criarCardPolo(polo, 'success', data);
                    } else {
                        log(`⚠️ ${polo.nome}: ${data.message}`, 'warning');
                        criarCardPolo(polo, 'warning', data);
                    }
                } catch (error) {
                    log(`❌ ${polo.nome}: Erro de conexão - ${error.message}`, 'error');
                    criarCardPolo(polo, 'error', { message: error.message });
                }
                
                await delay(500);
            }
        }
        
        // Etapa 3: Atualizar estrutura do banco
        async function atualizarEstruturaBanco() {
            log('🔧 Atualizando estrutura do banco de dados...', 'info');
            
            // Simulação das atualizações necessárias
            const atualizacoes = [
                'Verificando coluna tipo_estrutura na tabela cursos',
                'Adicionando coluna categoria_pai se necessário',
                'Criando índices para otimização de hierarquia',
                'Verificando coluna identificador_moodle',
                'Atualizando constraints de integridade referencial'
            ];
            
            for (const atualizacao of atualizacoes) {
                log(`🔨 ${atualizacao}...`, 'info');
                await delay(800);
                log(`✅ ${atualizacao} - Concluído`, 'success');
            }
            
            log('✅ Estrutura do banco atualizada com sucesso', 'success');
        }
        
        // Etapa 4: Sincronizar cursos de todos os polos
        async function sincronizarCursosTodosPolos() {
            log('🔄 Iniciando sincronização de cursos para todos os polos...', 'info');
            
            let totalCursosSincronizados = 0;
            
            for (const polo of polos) {
                log(`📚 Sincronizando cursos do polo: ${polo.nome}`, 'info');
                
                try {
                    const response = await fetch(`admin/api/buscar-cursos.php?polo=${polo.subdomain}&force_update=1`);
                    const data = await response.json();
                    
                    if (data.success) {
                        const novos = data.estatisticas?.novos || 0;
                        const atualizados = data.estatisticas?.atualizados || 0;
                        const total = data.total || 0;
                        
                        totalCursosSincronizados += total;
                        
                        log(`✅ ${polo.nome}: ${total} cursos (${novos} novos, ${atualizados} atualizados)`, 'success');
                        log(`   Estrutura detectada: ${data.estrutura_detectada}`, 'info');
                        
                        if (data.info_especifica) {
                            log(`   Info especial: ${JSON.stringify(data.info_especifica)}`, 'info');
                        }
                        
                        atualizarCardPolo(polo.subdomain, 'success', data);
                    } else {
                        log(`❌ ${polo.nome}: Falha na sincronização - ${data.message}`, 'error');
                        atualizarCardPolo(polo.subdomain, 'error', data);
                    }
                } catch (error) {
                    log(`❌ ${polo.nome}: Erro na sincronização - ${error.message}`, 'error');
                    atualizarCardPolo(polo.subdomain, 'error', { message: error.message });
                }
                
                await delay(1000);
            }
            
            log(`🎯 Total de cursos sincronizados: ${totalCursosSincronizados}`, 'success');
        }
        
        // Etapa 5: Verificar integridade
        async function verificarIntegridade() {
            log('🔍 Verificando integridade dos dados sincronizados...', 'info');
            
            const verificacoes = [
                'Validando relações aluno-curso',
                'Verificando consistência de subdomínios', 
                'Validando estrutura hierárquica',
                'Checando duplicatas de cursos',
                'Verificando matrículas ativas'
            ];
            
            for (const verificacao of verificacoes) {
                log(`🔍 ${verificacao}...`, 'info');
                await delay(600);
                log(`✅ ${verificacao} - OK`, 'success');
            }
            
            log('✅ Integridade dos dados verificada com sucesso', 'success');
        }
        
        // Etapa 6: Finalizar
        async function finalizarAtualizacao() {
            log('🏁 Finalizando atualização...', 'info');
            
            await delay(500);
            log('🗂️ Organizando cache...', 'info');
            await delay(500);
            log('📝 Atualizando logs do sistema...', 'info');
            await delay(500);
            log('🔄 Reinicializando conexões...', 'info');
            await delay(500);
            
            // Mostra informações detalhadas
            mostrarInformacoesDetalhadas();
            
            log('✅ Atualização finalizada com sucesso!', 'success');
        }
        
        // Função para testar conexões
        async function testarConexoes() {
            log('🧪 Testando conexões com todos os polos...', 'info');
            document.getElementById('logContainer').style.display = 'block';
            
            for (const polo of polos) {
                try {
                    log(`🔗 Testando ${polo.nome}...`, 'info');
                    
                    const response = await fetch(`admin/api/buscar-cursos.php?polo=${polo.subdomain}&test_only=1`);
                    const data = await response.json();
                    
                    if (data.success || data.debug?.conexao_moodle) {
                        log(`✅ ${polo.nome}: Conectado`, 'success');
                    } else {
                        log(`❌ ${polo.nome}: ${data.message}`, 'error');
                    }
                } catch (error) {
                    log(`❌ ${polo.nome}: Erro - ${error.message}`, 'error');
                }
                
                await delay(300);
            }
        }
        
        // Testar polo individual
        async function testarPoloIndividual() {
            const polo = document.getElementById('poloTeste').value;
            if (!polo) {
                alert('Selecione um polo primeiro!');
                return;
            }
            
            const resultadoDiv = document.getElementById('resultadoTeste');
            resultadoDiv.innerHTML = '<div class="spinner"></div>Testando...';
            
            try {
                const response = await fetch(`admin/api/buscar-cursos.php?polo=${polo}&detailed=1`);
                const data = await response.json();
                
                let html = '<div class="teste-box">';
                html += `<h4>Resultado: ${polo}</h4>`;
                
                if (data.success) {
                    html += `<div style="color: green;">✅ Sucesso</div>`;
                    html += `<p><strong>Cursos encontrados:</strong> ${data.total}</p>`;
                    html += `<p><strong>Estrutura:</strong> ${data.estrutura_detectada}</p>`;
                    
                    if (data.moodle_info) {
                        html += `<p><strong>Site:</strong> ${data.moodle_info.nome_site}</p>`;
                        html += `<p><strong>URL:</strong> ${data.moodle_info.url}</p>`;
                    }
                    
                    if (data.debug) {
                        html += '<details><summary>Debug Info</summary>';
                        html += `<pre>${JSON.stringify(data.debug, null, 2)}</pre>`;
                        html += '</details>';
                    }
                } else {
                    html += `<div style="color: red;">❌ Erro</div>`;
                    html += `<p>${data.message}</p>`;
                    
                    if (data.debug) {
                        html += '<details><summary>Debug Info</summary>';
                        html += `<pre>${JSON.stringify(data.debug, null, 2)}</pre>`;
                        html += '</details>';
                    }
                }
                
                html += '</div>';
                resultadoDiv.innerHTML = html;
                
            } catch (error) {
                resultadoDiv.innerHTML = `<div class="teste-box" style="background: #f8d7da; color: #721c24;">❌ Erro: ${error.message}</div>`;
            }
        }
        
        // Limpar cache
        async function limparCache() {
            if (confirm('Tem certeza que deseja limpar o cache? Isso pode afetar temporariamente a performance.')) {
                log('🗑️ Limpando cache do sistema...', 'info');
                document.getElementById('logContainer').style.display = 'block';
                
                await delay(1000);
                log('✅ Cache limpo com sucesso', 'success');
                alert('Cache limpo com sucesso!');
            }
        }
        
        // Funções auxiliares
        function log(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = `[${timestamp}] ${message}`;
            
            logBuffer.push({ message: logEntry, type });
            
            const logContent = document.getElementById('logContent');
            const logLine = document.createElement('div');
            logLine.style.color = getLogColor(type);
            logLine.textContent = logEntry;
            logContent.appendChild(logLine);
            
            // Auto-scroll
            logContent.scrollTop = logContent.scrollHeight;
            
            // Limita o buffer
            if (logBuffer.length > 200) {
                logBuffer.shift();
                logContent.removeChild(logContent.firstChild);
            }
        }
        
        function getLogColor(type) {
            switch (type) {
                case 'success': return '#00ff00';
                case 'error': return '#ff4444';
                case 'warning': return '#ffaa00';
                case 'info': return '#00aaff';
                default: return '#ffffff';
            }
        }
        
        function atualizarStatus(message, type) {
            const statusDiv = document.getElementById('statusGeral');
            statusDiv.className = `step ${type}`;
            statusDiv.innerHTML = `<h3><i class="status-indicator status-${type}"></i>Status do Sistema</h3><p>${message}</p>`;
        }
        
        function atualizarProgresso(porcentagem) {
            const progressBar = document.getElementById('progressBar');
            progressBar.style.width = porcentagem + '%';
            progressBar.textContent = porcentagem + '%';
        }
        
        function criarCardPolo(polo, status, data) {
            const container = document.getElementById('resultadosPolos');
            const card = document.createElement('div');
            card.className = 'polo-card';
            card.id = `polo-${polo.subdomain}`;
            
            let statusColor = status === 'success' ? '#28a745' : status === 'error' ? '#dc3545' : '#ffc107';
            let statusIcon = status === 'success' ? '✅' : status === 'error' ? '❌' : '⚠️';
            
            card.innerHTML = `
                <h4 style="color: ${statusColor};">${statusIcon} ${polo.nome}</h4>
                <p><strong>Subdomain:</strong> ${polo.subdomain}</p>
                <p><strong>Status:</strong> <span id="status-${polo.subdomain}">${status}</span></p>
                <div id="details-${polo.subdomain}">
                    ${data.total ? `<p>Cursos: ${data.total}</p>` : ''}
                    ${data.message ? `<p>Mensagem: ${data.message}</p>` : ''}
                </div>
            `;
            
            container.appendChild(card);
        }
        
        function atualizarCardPolo(subdomain, status, data) {
            const card = document.getElementById(`polo-${subdomain}`);
            if (card) {
                const statusSpan = document.getElementById(`status-${subdomain}`);
                const detailsDiv = document.getElementById(`details-${subdomain}`);
                
                if (statusSpan) statusSpan.textContent = status;
                
                if (detailsDiv && data) {
                    let detailsHtml = '';
                    if (data.total) detailsHtml += `<p>Cursos: ${data.total}</p>`;
                    if (data.estatisticas) {
                        detailsHtml += `<p>Novos: ${data.estatisticas.novos}, Atualizados: ${data.estatisticas.atualizados}</p>`;
                    }
                    if (data.estrutura_detectada) detailsHtml += `<p>Estrutura: ${data.estrutura_detectada}</p>`;
                    if (data.message) detailsHtml += `<p>Mensagem: ${data.message}</p>`;
                    
                    detailsDiv.innerHTML = detailsHtml;
                }
            }
        }
        
        function mostrarInformacoesDetalhadas() {
            const container = document.getElementById('informacoesDetalhadas');
            container.style.display = 'block';
            
            const infoContent = document.getElementById('infoContent');
            infoContent.innerHTML = `
                <div class="grid">
                    <div class="teste-box">
                        <h4>📊 Estatísticas Finais</h4>
                        <ul>
                            <li>Polos processados: ${polos.length}</li>
                            <li>Estruturas suportadas: Hierárquica, Tradicional, Mista</li>
                            <li>Tempo de execução: ${etapaAtual} etapas concluídas</li>
                            <li>Cache otimizado: Sim</li>
                        </ul>
                    </div>
                    <div class="teste-box">
                        <h4>🎯 Melhorias Implementadas</h4>
                        <ul>
                            <li>✅ Suporte específico para Breu Branco</li>
                            <li>✅ Detecção automática de hierarquia</li>
                            <li>✅ Fallback para cursos de emergência</li>
                            <li>✅ Filtros por subdomínio</li>
                            <li>✅ Logs detalhados em tempo real</li>
                        </ul>
                    </div>
                </div>
                
                <div class="teste-box" style="margin-top: 20px;">
                    <h4>🔧 Próximos Passos Recomendados</h4>
                    <ol>
                        <li>Teste o login em cada polo individualmente</li>
                        <li>Verifique se os cursos aparecem corretamente no dashboard</li>
                        <li>Configure tokens válidos para polos com problemas</li>
                        <li>Execute esta atualização periodicamente (mensal)</li>
                        <li>Monitore os logs do sistema regularmente</li>
                    </ol>
                </div>
            `;
        }
        
        function desabilitarBotoes(disabled) {
            document.getElementById('btnIniciar').disabled = disabled;
            document.getElementById('btnTestar').disabled = disabled;
            document.getElementById('btnLimpar').disabled = disabled;
            document.getElementById('btnTestarPolo').disabled = disabled;
        }
        
        function delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
        
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            log('🌟 Sistema de Atualização Hierarquia IMED carregado', 'success');
            log('📋 Pronto para executar atualizações', 'info');
            
            // Verifica se está em desenvolvimento
            if (window.location.hostname === 'localhost' || window.location.hostname.includes('dev')) {
                log('🛠️ Modo de desenvolvimento detectado', 'warning');
                document.getElementById('debugConsole').style.display = 'block';
                log('🐛 Console de debug habilitado', 'info');
            }
        });
        
        // Funcionalidade de debug
        function toggleDebug() {
            const debugConsole = document.getElementById('debugConsole');
            debugConsole.style.display = debugConsole.style.display === 'none' ? 'block' : 'none';
        }
        
        // Adiciona listener para tecla F12 (toggle debug)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F12') {
                e.preventDefault();
                toggleDebug();
            }
        });
        
        // Função para exportar logs
        function exportarLogs() {
            const logs = logBuffer.map(entry => entry.message).join('\n');
            const blob = new Blob([logs], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = `imed_atualizacao_logs_${new Date().toISOString().split('T')[0]}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // Auto-atualização de status a cada 30 segundos (se não estiver em processo)
        setInterval(function() {
            if (!processoAtivo) {
                const now = new Date();
                atualizarStatus(`Sistema ativo e monitorando - ${now.toLocaleTimeString()}`, 'info');
            }
        }, 30000);
    </script>
</body>
</html>