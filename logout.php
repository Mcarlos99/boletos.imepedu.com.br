<?php
/**
 * Sistema de Boletos IMEPEDU - Logout CORRIGIDO
 * Arquivo: logout.php
 * 
 * CORREÇÃO: Resolve conflito com Service Worker e problemas de cache
 */

session_start();

// Log do logout se usuário estiver logado
if (isset($_SESSION['aluno_cpf'])) {
    try {
        require_once 'config/database.php';
        
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
            VALUES ('logout', ?, 'Logout realizado', ?, ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['aluno_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Ignora erros de log
        error_log("Erro no log de logout: " . $e->getMessage());
    }
}

// 🔧 CORREÇÃO 1: Headers para evitar cache
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 🔧 CORREÇÃO 2: Remove cookies de forma mais agressiva
if (isset($_COOKIE[session_name()])) {
    // Remove cookie da sessão atual
    setcookie(session_name(), '', time() - 3600, '/', '', false, true);
    setcookie(session_name(), '', time() - 3600, '/', $_SERVER['HTTP_HOST'], false, true);
}

// Remove outros cookies possíveis do sistema
$cookiesToRemove = ['PHPSESSID', 'imed_session', 'user_session'];
foreach ($cookiesToRemove as $cookieName) {
    if (isset($_COOKIE[$cookieName])) {
        setcookie($cookieName, '', time() - 3600, '/');
        setcookie($cookieName, '', time() - 3600, '/', $_SERVER['HTTP_HOST']);
    }
}

// 🔧 CORREÇÃO 3: Destrói a sessão completamente
session_unset();
session_destroy();

// 🔧 CORREÇÃO 4: Força limpeza de todas as variáveis de sessão
$_SESSION = array();

// 🔧 CORREÇÃO 5: Detecta se a requisição vem de PWA/Service Worker
$isPWA = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    isset($_SERVER['HTTP_SERVICE_WORKER']) ||
    strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'PWA') !== false ||
    isset($_GET['pwa'])
);

// 🔧 CORREÇÃO 6: Redirecionamento inteligente
if ($isPWA || isset($_GET['clear_cache'])) {
    // Para PWA: Redireciona com parâmetro especial e JavaScript para limpar cache
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Logout - IMEPEDU</title>
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta http-equiv="Pragma" content="no-cache">
        <meta http-equiv="Expires" content="0">
        <style>
            body {
                font-family: Arial, sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                background: linear-gradient(135deg, #0066cc, #004499);
                color: white;
                text-align: center;
            }
            .logout-container {
                background: rgba(255,255,255,0.1);
                padding: 2rem;
                border-radius: 10px;
                backdrop-filter: blur(10px);
            }
            .spinner {
                border: 3px solid rgba(255,255,255,0.3);
                border-top: 3px solid white;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin: 0 auto 1rem auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <div class="logout-container">
            <div class="spinner"></div>
            <h2>Logout realizado</h2>
            <p>Limpando cache e redirecionando...</p>
            <p><small>Se não redirecionar automaticamente, <a href="/index.php?t=<?= time() ?>" style="color: #ffc107;">clique aqui</a></small></p>
        </div>

        <script>
            console.log('🔄 Iniciando processo de logout PWA');
            
            // 🔧 FUNÇÃO 1: Limpa Local Storage
            function clearLocalStorage() {
                try {
                    localStorage.clear();
                    sessionStorage.clear();
                    console.log('✅ Local/Session Storage limpo');
                } catch (e) {
                    console.log('⚠️ Erro ao limpar storage:', e);
                }
            }
            
            // 🔧 FUNÇÃO 2: Limpa Cache do Service Worker
            async function clearServiceWorkerCache() {
                if ('caches' in window) {
                    try {
                        const cacheNames = await caches.keys();
                        console.log('🗑️ Limpando caches:', cacheNames);
                        
                        await Promise.all(
                            cacheNames.map(cacheName => {
                                console.log('🗑️ Removendo cache:', cacheName);
                                return caches.delete(cacheName);
                            })
                        );
                        
                        console.log('✅ Cache do Service Worker limpo');
                    } catch (e) {
                        console.log('⚠️ Erro ao limpar cache SW:', e);
                    }
                }
            }
            
            // 🔧 FUNÇÃO 3: Força atualização do Service Worker
            async function updateServiceWorker() {
                if ('serviceWorker' in navigator) {
                    try {
                        const registration = await navigator.serviceWorker.getRegistration();
                        if (registration) {
                            console.log('🔄 Atualizando Service Worker');
                            await registration.update();
                            
                            // Se há um worker esperando, ativa-o
                            if (registration.waiting) {
                                registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                            }
                        }
                    } catch (e) {
                        console.log('⚠️ Erro ao atualizar SW:', e);
                    }
                }
            }
            
            // 🔧 FUNÇÃO 4: Redireciona com cache-busting
            function redirectToHome() {
                const timestamp = Date.now();
                const url = `/index.php?logout=1&t=${timestamp}&cb=${Math.random()}`;
                
                console.log('🏠 Redirecionando para:', url);
                
                // Força substituição completa da página
                window.location.replace(url);
            }
            
            // 🔧 EXECUÇÃO PRINCIPAL
            async function performLogout() {
                try {
                    console.log('🚀 Iniciando limpeza completa...');
                    
                    // Etapa 1: Limpa storages
                    clearLocalStorage();
                    
                    // Etapa 2: Limpa cache do SW
                    await clearServiceWorkerCache();
                    
                    // Etapa 3: Atualiza SW
                    await updateServiceWorker();
                    
                    // Etapa 4: Pequena pausa para garantir limpeza
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // Etapa 5: Redireciona
                    redirectToHome();
                    
                } catch (error) {
                    console.error('❌ Erro no logout:', error);
                    // Fallback: redireciona mesmo com erro
                    setTimeout(redirectToHome, 1000);
                }
            }
            
            // 🔧 LISTENERS DE EVENTOS
            window.addEventListener('load', () => {
                console.log('📱 Página de logout carregada');
                
                // Executa limpeza após pequeno delay
                setTimeout(performLogout, 800);
            });
            
            // Fallback: se não redirecionar em 5 segundos, força redirecionamento
            setTimeout(() => {
                console.log('⏰ Timeout - forçando redirecionamento');
                redirectToHome();
            }, 5000);
            
            // Se usuário clicar, redireciona imediatamente
            document.addEventListener('click', (e) => {
                if (e.target.tagName === 'A') {
                    e.preventDefault();
                    redirectToHome();
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
} else {
    // 🔧 CORREÇÃO 7: Redirecionamento tradicional com cache-busting
    $timestamp = time();
    $redirectUrl = "/index.php?logout=1&t={$timestamp}";
    
    header("Location: {$redirectUrl}");
    exit;
}
?>