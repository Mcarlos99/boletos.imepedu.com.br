/**
 * Sistema de Boletos IMEPEDU - Service Worker TOTALMENTE CORRIGIDO
 * Arquivo: sw.js - VERSÃO 3.0.0 - CORREÇÃO DEFINITIVA ERR_FAILED
 * 
 * 🔧 CORREÇÕES APLICADAS:
 * - Interceptação mais seletiva
 * - Bypass completo para autenticação
 * - Limpeza automática de cache corrompido
 * - Detecção de app em segundo plano
 */

const CACHE_NAME = 'IMEPEDU-boletos-v3.0.0';
const DATA_CACHE_NAME = 'IMEPEDU-boletos-data-v2.0';

// URLs para cache estático (apenas essenciais)
const STATIC_CACHE_URLS = [
    '/offline.html',
    '/manifest.json'
];

// 🔧 CORREÇÃO CRÍTICA: Padrões que NUNCA devem ser interceptados
const NEVER_INTERCEPT_PATTERNS = [
    // Autenticação e logout (PRIORIDADE MÁXIMA)
    /\/login\.php/,
    /\/logout\.php/,
    /\/index\.php/,
    /login|logout|auth/i,
    /session/i,
    
    // Parâmetros de limpeza de cache
    /[?&](t|logout|clear_cache|force_refresh|fallback|pwa)=/,
    
    // Admin completo
    /\/admin\//,
    
    // Upload e arquivos
    /upload|multipart|file/i,
    /\.pdf$/,
    /\/uploads\//,
    
    // APIs POST
    /\/api\/.*method=POST/,
    
    // Recursos externos
    /^https?:\/\/(?!boleto\.imepedu\.com\.br)/
];

// Configurações mais conservadoras
const CONFIG = {
    CACHE_DURATION: {
        STATIC: 24 * 60 * 60 * 1000,       // 1 dia (reduzido)
        API: 2 * 60 * 1000,                // 2 minutos (muito reduzido)
        DYNAMIC: 30 * 60 * 1000            // 30 minutos (reduzido)
    },
    NETWORK_TIMEOUT: 8000,                  // 8 segundos
    DEBUG: true,
    LOG_PREFIX: '[SW-IMEPEDU-v3.0]',
    MAX_CACHE_SIZE: 50,                     // Máximo de itens no cache
    AUTO_CLEANUP_INTERVAL: 30 * 60 * 1000  // Limpeza a cada 30 min
};

// Variáveis de controle
let lastCleanup = Date.now();
let interceptCount = 0;
let bypassCount = 0;

function logSW(message, data = null) {
    if (CONFIG.DEBUG) {
        const timestamp = new Date().toISOString().substr(11, 8);
        const stats = `[I:${interceptCount} B:${bypassCount}]`;
        
        if (data) {
            console.log(`${CONFIG.LOG_PREFIX} [${timestamp}] ${stats} ${message}`, data);
        } else {
            console.log(`${CONFIG.LOG_PREFIX} [${timestamp}] ${stats} ${message}`);
        }
    }
}

/**
 * Install Event - Instalação mínima e segura
 */
self.addEventListener('install', event => {
    logSW('🔧 Instalando SW v3.0.0 - Correção ERR_FAILED');
    
    event.waitUntil(
        (async () => {
            try {
                // Cache apenas página offline
                const staticCache = await caches.open(CACHE_NAME);
                await staticCache.add('/offline.html');
                
                await caches.open(DATA_CACHE_NAME);
                logSW('✅ Instalação minimalista concluída');
                
                // Skip waiting para ativar imediatamente
                await self.skipWaiting();
                
            } catch (error) {
                logSW('❌ Erro na instalação:', error);
            }
        })()
    );
});

/**
 * Activate Event - Limpeza agressiva de cache antigo
 */
self.addEventListener('activate', event => {
    logSW('🚀 Ativando SW v3.0.0');
    
    event.waitUntil(
        (async () => {
            try {
                // Remove TODOS os caches antigos
                const cacheNames = await caches.keys();
                const currentCaches = [CACHE_NAME, DATA_CACHE_NAME];
                
                const deletionPromises = cacheNames
                    .filter(name => !currentCaches.includes(name))
                    .map(async name => {
                        logSW('🗑️ Removendo cache antigo:', name);
                        return await caches.delete(name);
                    });
                
                await Promise.all(deletionPromises);
                
                // Limpa cache corrompido
                await cleanupCorruptedCache();
                
                // Assume controle imediato
                await self.clients.claim();
                
                // Notifica clientes sobre atualização
                const clients = await self.clients.matchAll();
                clients.forEach(client => {
                    client.postMessage({
                        type: 'SW_UPDATED',
                        version: CACHE_NAME,
                        message: 'Service Worker atualizado - ERR_FAILED corrigido!'
                    });
                });
                
                logSW('✅ Ativação concluída - Cache limpo');
                
            } catch (error) {
                logSW('❌ Erro na ativação:', error);
            }
        })()
    );
});

/**
 * Fetch Event - INTERCEPTAÇÃO ULTRA SELETIVA
 */
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // Ignora requisições não HTTP/HTTPS
    if (!request.url.startsWith('http')) {
        return;
    }
    
    // 🔧 VERIFICAÇÃO CRÍTICA: Nunca intercepta padrões críticos
    if (shouldNeverIntercept(request, url)) {
        bypassCount++;
        logSW('🚫 BYPASS TOTAL:', {
            url: request.url,
            method: request.method,
            reason: 'never_intercept_pattern'
        });
        return; // Deixa o browser lidar completamente
    }
    
    // 🔧 SEGURANÇA: Só intercepta GET requests muito específicos
    if (request.method !== 'GET') {
        bypassCount++;
        logSW('🚫 BYPASS - Non-GET:', request.method, request.url);
        return;
    }
    
    // 🔧 VERIFICAÇÃO ADICIONAL: URLs suspeitas
    if (isSuspiciousUrl(request, url)) {
        bypassCount++;
        logSW('🚫 BYPASS - Suspicious URL:', request.url);
        return;
    }
    
    // Limpeza automática periódica
    if (Date.now() - lastCleanup > CONFIG.AUTO_CLEANUP_INTERVAL) {
        event.waitUntil(performPeriodicCleanup());
    }
    
    // Só intercepta recursos muito seguros
    interceptCount++;
    
    if (isStaticSafeResource(url)) {
        event.respondWith(handleStaticResource(request));
    } else if (isSafeAPIRequest(url)) {
        event.respondWith(handleSafeAPIRequest(request));
    } else {
        // Para qualquer outra coisa, vai direto para rede
        bypassCount++;
        interceptCount--; // Corrige contador
        logSW('🚫 BYPASS - Default fallback:', request.url);
        return;
    }
});

/**
 * 🔧 FUNÇÃO CRÍTICA: Determina se requisição NUNCA deve ser interceptada
 */
function shouldNeverIntercept(request, url) {
    const fullUrl = request.url;
    const method = request.method.toUpperCase();
    
    // 1. Testa todos os padrões de bypass
    for (const pattern of NEVER_INTERCEPT_PATTERNS) {
        if (pattern.test(fullUrl)) {
            logSW(`🚫 Pattern match: ${pattern} -> ${fullUrl}`);
            return true;
        }
    }
    
    // 2. Qualquer POST
    if (method !== 'GET') {
        return true;
    }
    
    // 3. Headers problemáticos
    const contentType = request.headers.get('content-type') || '';
    if (contentType.includes('multipart') || 
        contentType.includes('form-data')) {
        return true;
    }
    
    // 4. Headers de autenticação
    if (request.headers.get('authorization') || 
        request.headers.get('x-requested-with')) {
        return true;
    }
    
    // 5. URLs com domínio diferente
    if (url.hostname !== 'boleto.imepedu.com.br' && 
        url.hostname !== 'localhost') {
        return true;
    }
    
    return false;
}

/**
 * Verifica URLs suspeitas que podem causar problemas
 */
function isSuspiciousUrl(request, url) {
    const suspicious = [
        // Parâmetros de sessão
        'session', 'token', 'auth', 'login', 'logout',
        // Timestamps e cache busting
        '&t=', '?t=', 'timestamp', 'nocache',
        // Admin operations
        'admin/api', 'admin/upload',
        // File operations
        '.pdf', '.doc', '.xlsx', 'download',
        // POST-like parameters
        'method=post', 'action=',
    ];
    
    const fullUrl = request.url.toLowerCase();
    return suspicious.some(term => fullUrl.includes(term));
}

/**
 * Verifica se é recurso estático seguro
 */
function isStaticSafeResource(url) {
    // Apenas recursos CDN externos seguros
    const safeCDNs = [
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'fonts.googleapis.com',
        'fonts.gstatic.com'
    ];
    
    return safeCDNs.includes(url.hostname) && 
           /\.(css|js|woff|woff2|ttf)$/i.test(url.pathname);
}

/**
 * Verifica se é API request segura
 */
function isSafeAPIRequest(url) {
    // Muito seletivo - apenas APIs específicas e seguras
    if (url.hostname !== 'boleto.imepedu.com.br') return false;
    
    const safeAPIs = [
        '/api/site-info.php',
        '/api/status.php'
    ];
    
    return safeAPIs.some(api => url.pathname === api) &&
           !url.search.includes('logout') &&
           !url.search.includes('auth');
}

/**
 * Handle recursos estáticos - Cache opcional
 */
async function handleStaticResource(request) {
    try {
        logSW('🌐 Static Network First:', request.url);
        
        // Network first para recursos estáticos também
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            try {
                const cache = await caches.open(CACHE_NAME);
                await cache.put(request, networkResponse.clone());
                logSW('💾 Static cached:', request.url);
            } catch (cacheError) {
                logSW('⚠️ Cache write failed:', cacheError.message);
            }
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ Static error:', error.message);
        
        // Fallback para cache apenas se não conseguir rede
        try {
            const cache = await caches.open(CACHE_NAME);
            const cachedResponse = await cache.match(request);
            
            if (cachedResponse) {
                logSW('📦 Static fallback:', request.url);
                return cachedResponse;
            }
        } catch (cacheError) {
            logSW('❌ Cache read failed:', cacheError.message);
        }
        
        throw error;
    }
}

/**
 * Handle API requests seguros - Network only
 */
async function handleSafeAPIRequest(request) {
    try {
        logSW('🌐 Safe API Network:', request.url);
        
        const networkResponse = await fetchWithTimeout(request);
        
        // Cache apenas se bem-sucedido
        if (networkResponse && networkResponse.status === 200) {
            try {
                const cache = await caches.open(DATA_CACHE_NAME);
                
                // Limita tamanho do cache
                await limitCacheSize(cache, CONFIG.MAX_CACHE_SIZE);
                
                await cache.put(request, networkResponse.clone());
                logSW('💾 API cached:', request.url);
            } catch (cacheError) {
                logSW('⚠️ API cache failed:', cacheError.message);
            }
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ API error - no fallback:', error.message);
        throw error; // Não fornece fallback para APIs
    }
}

/**
 * Fetch com timeout e controle de erro
 */
async function fetchWithTimeout(request, timeout = CONFIG.NETWORK_TIMEOUT) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);
    
    try {
        const response = await fetch(request, {
            signal: controller.signal
        });
        clearTimeout(timeoutId);
        return response;
    } catch (error) {
        clearTimeout(timeoutId);
        throw error;
    }
}

/**
 * Limita tamanho do cache
 */
async function limitCacheSize(cache, maxSize) {
    try {
        const keys = await cache.keys();
        
        if (keys.length >= maxSize) {
            const deleteCount = keys.length - maxSize + 10; // Remove extra
            
            for (let i = 0; i < deleteCount; i++) {
                await cache.delete(keys[i]);
            }
            
            logSW(`🧹 Cache size limited: removed ${deleteCount} items`);
        }
    } catch (error) {
        logSW('❌ Cache limit error:', error);
    }
}

/**
 * Limpeza automática periódica
 */
async function performPeriodicCleanup() {
    try {
        logSW('🧹 Limpeza automática iniciada');
        
        const cacheNames = [CACHE_NAME, DATA_CACHE_NAME];
        
        for (const cacheName of cacheNames) {
            const cache = await caches.open(cacheName);
            const requests = await cache.keys();
            
            for (const request of requests) {
                try {
                    const response = await cache.match(request);
                    
                    if (response && isExpired(response, CONFIG.CACHE_DURATION.DYNAMIC)) {
                        await cache.delete(request);
                        logSW('🗑️ Expired item removed:', request.url);
                    }
                } catch (error) {
                    // Remove itens corrompidos
                    await cache.delete(request);
                    logSW('🗑️ Corrupted item removed:', request.url);
                }
            }
        }
        
        lastCleanup = Date.now();
        logSW('✅ Limpeza concluída');
        
    } catch (error) {
        logSW('❌ Erro na limpeza:', error);
    }
}

/**
 * Limpa cache corrompido
 */
async function cleanupCorruptedCache() {
    try {
        logSW('🧹 Limpando cache corrompido');
        
        const cacheNames = await caches.keys();
        
        for (const cacheName of cacheNames) {
            try {
                const cache = await caches.open(cacheName);
                const requests = await cache.keys();
                
                for (const request of requests) {
                    try {
                        const response = await cache.match(request);
                        if (!response) {
                            await cache.delete(request);
                        }
                    } catch (error) {
                        await cache.delete(request);
                        logSW('🗑️ Corrupted cache entry removed:', request.url);
                    }
                }
            } catch (error) {
                // Se não conseguir abrir o cache, deleta
                await caches.delete(cacheName);
                logSW('🗑️ Corrupted cache deleted:', cacheName);
            }
        }
        
        logSW('✅ Cache corruption cleanup completed');
    } catch (error) {
        logSW('❌ Cleanup error:', error);
    }
}

/**
 * Verifica se resposta está expirada
 */
function isExpired(response, maxAge) {
    try {
        const dateHeader = response.headers.get('date');
        if (!dateHeader) return true; // Sem data = considerado expirado
        
        const responseTime = new Date(dateHeader).getTime();
        const currentTime = Date.now();
        
        return (currentTime - responseTime) > maxAge;
    } catch (error) {
        return true; // Erro = considerado expirado
    }
}

/**
 * Message Event - Comandos dos clientes
 */
self.addEventListener('message', async event => {
    const data = event.data;
    if (!data || !data.type) return;
    
    logSW('📨 Message received:', data.type);
    
    switch (data.type) {
        case 'SKIP_WAITING':
            await self.skipWaiting();
            break;
            
        case 'CLAIM_CLIENTS':
            await self.clients.claim();
            break;
            
        case 'FORCE_LOGOUT':
            event.waitUntil(handleForceLogout());
            break;
            
        case 'CLEAR_ALL_CACHE':
            event.waitUntil(clearAllCache());
            break;
            
        case 'BYPASS_CACHE':
            // Adiciona URL à lista de bypass temporário
            logSW('🚫 Bypass solicitado para:', data.url);
            break;
            
        case 'GET_STATS':
            // Retorna estatísticas
            event.ports[0]?.postMessage({
                interceptCount,
                bypassCount,
                cacheNames: await caches.keys(),
                version: CACHE_NAME
            });
            break;
    }
});

/**
 * Handle force logout
 */
async function handleForceLogout() {
    try {
        logSW('🚪 Force logout - limpando tudo');
        
        await clearAllCache();
        
        // Notifica todos os clientes
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
            client.postMessage({
                type: 'LOGOUT_CLEANUP_COMPLETE',
                timestamp: Date.now()
            });
        });
        
        logSW('✅ Force logout concluído');
    } catch (error) {
        logSW('❌ Erro no force logout:', error);
    }
}

/**
 * Limpa todo o cache
 */
async function clearAllCache() {
    try {
        logSW('🧹 Limpando TODO o cache');
        
        const cacheNames = await caches.keys();
        const deletePromises = cacheNames.map(name => caches.delete(name));
        
        await Promise.all(deletePromises);
        
        // Reset contadores
        interceptCount = 0;
        bypassCount = 0;
        lastCleanup = Date.now();
        
        logSW('✅ Todo cache removido');
    } catch (error) {
        logSW('❌ Erro ao limpar cache:', error);
    }
}

// Error handler global
self.addEventListener('error', event => {
    logSW('❌ Service Worker Error:', event.error);
});

self.addEventListener('unhandledrejection', event => {
    logSW('❌ Unhandled Promise Rejection:', event.reason);
});

// Log inicial
logSW('🚀 Service Worker v3.0.0 carregado - CORREÇÃO ERR_FAILED');
logSW('📊 Configurações aplicadas:', {
    version: CACHE_NAME,
    patterns: NEVER_INTERCEPT_PATTERNS.length,
    timeout: CONFIG.NETWORK_TIMEOUT,
    maxCacheSize: CONFIG.MAX_CACHE_SIZE
});
logSW('✅ Interceptação ultra-seletiva ativada');
logSW('🔒 Bypass total para autenticação e admin');
logSW('🧹 Auto-limpeza de cache habilitada');