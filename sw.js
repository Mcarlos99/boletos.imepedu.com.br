/**
 * Sistema de Boletos IMED - Service Worker PWA COMPLETO CORRIGIDO
 * Arquivo: sw.js - PARTE 1/3
 * Versão: 2.1.3 - Solução definitiva para problemas de logout
 */

const CACHE_NAME = 'imed-boletos-v2.1.3';
const DATA_CACHE_NAME = 'imed-boletos-data-v1.3';

// URLs para cache estático (recursos que mudam pouco)
const STATIC_CACHE_URLS = [
    '/',
    '/index.php',
    '/dashboard.php',
    '/login.php',
    '/manifest.json',
    
    // CSS Frameworks
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    
    // JavaScript Frameworks
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    
    // Ícones e imagens
    '/icons/icon-72x72.png',
    '/icons/icon-96x96.png',
    '/icons/icon-128x128.png',
    '/icons/icon-144x144.png',
    '/icons/icon-152x152.png',
    '/icons/icon-192x192.png',
    '/icons/icon-384x384.png',
    '/icons/icon-512x512.png',
    
    // Fallbacks offline
    '/offline.html'
];

// 🔧 CORREÇÃO CRÍTICA: URLs que NUNCA devem ser cacheadas
const NEVER_CACHE_PATTERNS = [
    /\/logout\.php/,                    // NUNCA cachear logout
    /\/login\.php\?logout=/,            // NUNCA cachear login pós-logout
    /\/index\.php\?logout=/,            // NUNCA cachear index pós-logout
    /\/api\/.*\.php\?.*logout/,         // NUNCA cachear APIs relacionadas a logout
    /logout=1/,                         // Qualquer URL com logout=1
    /\?t=\d+/,                         // URLs com timestamp (cache-busting)
    /clear_cache=1/,                   // URLs de limpeza de cache
    /fallback=1/,                      // URLs de fallback
    /pwa=1/                            // URLs específicas de PWA
];

// URLs da API que devem ser cacheadas dinamicamente
const API_CACHE_PATTERNS = [
    /\/api\/atualizar_dados\.php/,
    /\/api\/boleto-detalhes\.php/,
    /\/api\/download-boleto\.php/,
    /\/api\/gerar-pix\.php/
];

// URLs que sempre devem buscar da rede primeiro
const NETWORK_FIRST_PATTERNS = [
    /\/api\/atualizar_dados\.php/,
    /\/logout\.php/,
    /\/admin\//
];

// Configurações detalhadas
const CONFIG = {
    // Tempo de cache para diferentes tipos de recursos
    CACHE_DURATION: {
        STATIC: 30 * 24 * 60 * 60 * 1000,      // 30 dias
        API: 15 * 60 * 1000,                    // 15 minutos
        IMAGES: 7 * 24 * 60 * 60 * 1000,       // 7 dias
        DYNAMIC: 24 * 60 * 60 * 1000           // 1 dia
    },
    
    // Limites de cache
    CACHE_LIMITS: {
        STATIC: 50,
        API: 20,
        IMAGES: 30,
        DYNAMIC: 40
    },
    
    // Configurações de rede
    NETWORK_TIMEOUT: 5000,
    RETRY_ATTEMPTS: 3,
    BACKGROUND_SYNC_TAG: 'boletos-sync',
    
    // Debug mode
    DEBUG: true,
    LOG_PREFIX: '[SW-IMED]'
};

/**
 * Função de log melhorada
 */
function logSW(message, data = null) {
    if (CONFIG.DEBUG) {
        const timestamp = new Date().toISOString().substr(11, 8);
        if (data) {
            console.log(`${CONFIG.LOG_PREFIX} [${timestamp}] ${message}`, data);
        } else {
            console.log(`${CONFIG.LOG_PREFIX} [${timestamp}] ${message}`);
        }
    }
}

/**
 * Event: Install
 * Instala o Service Worker e faz cache inicial
 */
self.addEventListener('install', event => {
    logSW('🔧 Instalando Service Worker v' + CACHE_NAME);
    
    event.waitUntil(
        (async () => {
            try {
                // Cache estático inicial
                const staticCache = await caches.open(CACHE_NAME);
                
                // Recursos essenciais que devem ser cacheados primeiro
                const essentialUrls = [
                    '/',
                    '/dashboard.php',
                    '/login.php',
                    '/offline.html'
                ];
                
                logSW('📦 Cacheando recursos essenciais...');
                
                // Tenta cache em lote primeiro
                try {
                    await staticCache.addAll(essentialUrls);
                    logSW('✅ Cache essencial criado em lote');
                } catch (error) {
                    logSW('⚠️ Erro no cache em lote, tentando individual');
                    
                    // Fallback: cache individual
                    for (const url of essentialUrls) {
                        try {
                            await staticCache.add(url);
                            logSW(`✅ Cached: ${url}`);
                        } catch (urlError) {
                            logSW(`❌ Falha ao cachear: ${url}`, urlError.message);
                        }
                    }
                }
                
                // Cache recursos externos opcionais
                const externalUrls = STATIC_CACHE_URLS.filter(url => 
                    url.startsWith('http') && !essentialUrls.includes(url)
                );
                
                logSW('🌐 Cacheando recursos externos opcionais...');
                for (const url of externalUrls) {
                    try {
                        await staticCache.add(url);
                        logSW(`✅ External cached: ${url}`);
                    } catch (error) {
                        logSW(`⚠️ Falha recurso externo: ${url}`);
                    }
                }
                
                // Inicializa cache de dados
                await caches.open(DATA_CACHE_NAME);
                logSW('📊 Cache de dados inicializado');
                
                logSW('🎉 Instalação concluída com sucesso');
                
                // Força atualização imediata
                await self.skipWaiting();
                
            } catch (error) {
                logSW('❌ Erro crítico durante instalação', error);
            }
        })()
    );
});

/**
 * Event: Activate
 * Ativa o novo Service Worker e limpa caches antigos
 */
self.addEventListener('activate', event => {
    logSW('🚀 Ativando Service Worker v' + CACHE_NAME);
    
    event.waitUntil(
        (async () => {
            try {
                // Limpa caches antigos
                const cacheNames = await caches.keys();
                const currentCaches = [CACHE_NAME, DATA_CACHE_NAME];
                
                const deletionPromises = cacheNames
                    .filter(name => !currentCaches.includes(name))
                    .map(name => {
                        logSW('🗑️ Removendo cache antigo:', name);
                        return caches.delete(name);
                    });
                
                await Promise.all(deletionPromises);
                logSW(`🧹 ${deletionPromises.length} caches antigos removidos`);
                
                // Assume controle imediato de todas as abas
                await self.clients.claim();
                logSW('👑 Service Worker assumiu controle');
                
                // Notifica clientes sobre a ativação
                await notifyClients({
                    type: 'SW_ACTIVATED',
                    version: CACHE_NAME,
                    message: 'Sistema atualizado! Nova versão disponível.',
                    timestamp: Date.now()
                });
                
                logSW('📢 Clientes notificados sobre ativação');
                
            } catch (error) {
                logSW('❌ Erro durante ativação', error);
            }
        })()
    );
});

/**
 * Event: Message
 * Escuta comandos dos clientes para coordenar operações
 */
self.addEventListener('message', event => {
    const data = event.data;
    logSW('📨 Mensagem recebida', data);
    
    if (!data || !data.type) return;
    
    switch (data.type) {
        case 'SKIP_WAITING':
            logSW('⏭️ Comando SKIP_WAITING recebido');
            self.skipWaiting();
            break;
            
        case 'CLAIM_CLIENTS':
            logSW('👑 Comando CLAIM_CLIENTS recebido');
            self.clients.claim();
            break;
            
        case 'CLEAR_CACHE':
            logSW('🧹 Comando CLEAR_CACHE recebido', data.pattern);
            event.waitUntil(clearSpecificCache(data.pattern));
            break;
            
        case 'FORCE_LOGOUT':
            logSW('🚪 Comando FORCE_LOGOUT recebido');
            event.waitUntil(handleForceLogout());
            break;
            
        case 'CLEAR_ALL_CACHES':
            logSW('💥 Comando CLEAR_ALL_CACHES recebido');
            event.waitUntil(clearAllCaches());
            break;
            
        case 'GET_CACHE_STATUS':
            logSW('📊 Comando GET_CACHE_STATUS recebido');
            event.waitUntil(sendCacheStatus(event.source));
            break;
            
        default:
            logSW('❓ Comando desconhecido:', data.type);
    }
});

/**
 * Event: Fetch - VERSÃO TOTALMENTE CORRIGIDA
 * Intercepta todas as requisições e aplica estratégias de cache inteligentes
 */
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // Ignora requisições não HTTP/HTTPS
    if (!request.url.startsWith('http')) {
        return;
    }
    
    // 🔧 CORREÇÃO CRÍTICA: NUNCA intercepta URLs relacionadas a logout
    if (isNeverCacheUrl(url)) {
        logSW('🚫 NEVER CACHE - bypass completo:', request.url);
        return; // Deixa o browser lidar normalmente - SEM INTERCEPTAÇÃO
    }
    
    // Ignora requisições POST de API para evitar cache de modificações
    if (request.method !== 'GET' && isAPIRequest(url)) {
        logSW('📝 POST API - ignorando:', request.url);
        return;
    }
    
    // Aplica estratégias baseadas no tipo de recurso
    if (isStaticResource(url)) {
        event.respondWith(handleStaticResource(request));
    } else if (isAPIRequest(url)) {
        event.respondWith(handleAPIRequest(request));
    } else if (isImageRequest(url)) {
        event.respondWith(handleImageRequest(request));
    } else {
        event.respondWith(handleDynamicRequest(request));
    }
});

/**
 * 🔧 FUNÇÃO CRÍTICA: Verifica se URL nunca deve ser cacheada
 */
function isNeverCacheUrl(url) {
    const fullUrl = url.pathname + url.search;
    const isNeverCache = NEVER_CACHE_PATTERNS.some(pattern => {
        const matches = pattern.test(fullUrl);
        if (matches) {
            logSW(`🚫 URL matched never-cache pattern: ${fullUrl} -> ${pattern}`);
        }
        return matches;
    });
    
    return isNeverCache;
}

/**
 * Verifica se é um recurso estático
 */
function isStaticResource(url) {
    return STATIC_CACHE_URLS.some(staticUrl => {
        if (typeof staticUrl === 'string') {
            return url.pathname === staticUrl || url.href === staticUrl;
        }
        return staticUrl.test(url.href);
    });
}

/**
 * Verifica se é uma requisição de API
 */
function isAPIRequest(url) {
    return url.pathname.startsWith('/api/') || 
           API_CACHE_PATTERNS.some(pattern => pattern.test(url.pathname));
}

/**
 * Verifica se é uma requisição de imagem
 */
function isImageRequest(url) {
    return /\.(jpg|jpeg|png|gif|webp|svg|ico)$/i.test(url.pathname);
}

/**
 * Verifica se deve buscar da rede primeiro
 */
function isNetworkFirst(url) {
    return NETWORK_FIRST_PATTERNS.some(pattern => pattern.test(url.pathname));
}

/**
 * Manipula recursos estáticos - Cache First com correções para logout
 */
async function handleStaticResource(request) {
    const url = new URL(request.url);
    
    try {
        // 🔧 CORREÇÃO: Para index.php com parâmetros de logout, sempre busca da rede
        if (url.pathname === '/index.php' && 
            (url.searchParams.has('logout') || url.searchParams.has('t'))) {
            logSW('🏠 Index pós-logout - forçando rede:', request.url);
            return await fetchWithTimeout(request);
        }
        
        const cache = await caches.open(CACHE_NAME);
        const cachedResponse = await cache.match(request);
        
        if (cachedResponse && !isExpired(cachedResponse, CONFIG.CACHE_DURATION.STATIC)) {
            logSW('📦 Cache hit (static):', request.url);
            return cachedResponse;
        }
        
        // Busca da rede
        logSW('🌐 Buscando da rede (static):', request.url);
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            // 🔧 CORREÇÃO: Não cacheia responses relacionadas a logout
            if (!url.searchParams.has('logout') && 
                !url.searchParams.has('t') && 
                !url.searchParams.has('clear_cache')) {
                
                await cache.put(request, networkResponse.clone());
                logSW('💾 Cache atualizado (static):', request.url);
            } else {
                logSW('🚫 Não cacheando (contém parâmetros especiais):', request.url);
            }
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ Rede falhou, tentando cache (static):', request.url);
        
        const cache = await caches.open(CACHE_NAME);
        const fallbackResponse = await cache.match(request);
        
        if (fallbackResponse) {
            logSW('📦 Usando cache como fallback:', request.url);
            return fallbackResponse;
        }
        
        // Fallback final para recursos críticos
        if (request.url.includes('dashboard.php')) {
            logSW('🆘 Usando página offline como fallback');
            return caches.match('/offline.html') || createOfflinePage();
        }
        
        throw error;
    }
}

/**
 * Manipula requisições de API - Network First com Cache Fallback
 */
async function handleAPIRequest(request) {
    const cacheName = DATA_CACHE_NAME;
    const url = new URL(request.url);
    
    try {
        // 🔧 CORREÇÃO: APIs relacionadas a logout sempre da rede
        if (url.pathname.includes('logout') || 
            url.searchParams.has('logout') ||
            url.searchParams.has('force_refresh')) {
            logSW('🔄 API logout/refresh - forçando rede:', request.url);
            return await fetchWithTimeout(request);
        }
        
        // Network First para APIs críticas
        if (isNetworkFirst(url)) {
            logSW('🌐 Network first (API):', request.url);
            const networkResponse = await fetchWithTimeout(request);
            
            if (networkResponse && networkResponse.status === 200) {
                const cache = await caches.open(cacheName);
                await cache.put(request, networkResponse.clone());
                logSW('💾 API cache atualizado (network-first):', request.url);
            }
            
            return networkResponse;
        }
        
        // Cache First para outras APIs
        const cache = await caches.open(cacheName);
        const cachedResponse = await cache.match(request);
        
        if (cachedResponse && !isExpired(cachedResponse, CONFIG.CACHE_DURATION.API)) {
            logSW('📦 Cache hit (API):', request.url);
            
            // Atualiza cache em background
            updateCacheInBackground(request, cache);
            
            return cachedResponse;
        }
        
        // Busca da rede
        logSW('🌐 Buscando da rede (API):', request.url);
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            await cache.put(request, networkResponse.clone());
            logSW('💾 API cache atualizado:', request.url);
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ API erro, tentando cache:', request.url);
        
        // Fallback para cache
        const cache = await caches.open(cacheName);
        const fallbackResponse = await cache.match(request);
        
        if (fallbackResponse) {
            // Agenda sincronização para mais tarde
            await scheduleBackgroundSync();
            logSW('📦 Usando cache API como fallback:', request.url);
            return fallbackResponse;
        }
        
        // Resposta offline personalizada para APIs
        return createOfflineAPIResponse();
    }
}

/**
 * Manipula requisições de imagens - Cache First
 */
async function handleImageRequest(request) {
    try {
        const cache = await caches.open(CACHE_NAME);
        const cachedResponse = await cache.match(request);
        
        if (cachedResponse && !isExpired(cachedResponse, CONFIG.CACHE_DURATION.IMAGES)) {
            logSW('📦 Cache hit (image):', request.url);
            return cachedResponse;
        }
        
        logSW('🌐 Buscando imagem da rede:', request.url);
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            await cache.put(request, networkResponse.clone());
            logSW('💾 Imagem cacheada:', request.url);
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ Erro imagem, tentando cache:', request.url);
        
        const cache = await caches.open(CACHE_NAME);
        const fallbackResponse = await cache.match(request);
        
        if (fallbackResponse) {
            return fallbackResponse;
        }
        
        // Imagem placeholder para offline
        return createImagePlaceholder();
    }
}

/**
 * Manipula requisições dinâmicas - Network First com correções para logout
 */
async function handleDynamicRequest(request) {
    const url = new URL(request.url);
    
    try {
        // 🔧 CORREÇÃO: Requisições com parâmetros especiais sempre da rede
        if (url.searchParams.has('logout') || 
            url.searchParams.has('t') ||
            url.searchParams.has('clear_cache') ||
            url.searchParams.has('fallback')) {
            logSW('🔄 Requisição dinâmica especial - forçando rede:', request.url);
            return await fetchWithTimeout(request);
        }
        
        logSW('🌐 Buscando dinâmico da rede:', request.url);
        const networkResponse = await fetchWithTimeout(request);
        
        // Cacheia apenas respostas HTML válidas sem parâmetros especiais
        if (networkResponse && 
            networkResponse.status === 200 && 
            networkResponse.headers.get('content-type')?.includes('text/html') &&
            !url.searchParams.has('logout') &&
            !url.searchParams.has('t')) {
            
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, networkResponse.clone());
            logSW('💾 Dinâmico cacheado:', request.url);
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ Erro dinâmico:', request.url);
        
        // Fallback para cache apenas se não for logout
        if (!url.searchParams.has('logout') && 
            !url.searchParams.has('t') &&
            !url.searchParams.has('clear_cache')) {
            
            const cache = await caches.open(CACHE_NAME);
            const cachedResponse = await cache.match(request);
            
            if (cachedResponse) {
                logSW('📦 Usando cache dinâmico como fallback:', request.url);
                return cachedResponse;
            }
        }
        
        // Página offline para navegação
        if (request.mode === 'navigate') {
            logSW('🆘 Usando página offline para navegação');
            return caches.match('/offline.html') || createOfflinePage();
        }
        
        throw error;
    }
}

/**
 * Fetch com timeout configurável
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
        if (error.name === 'AbortError') {
            logSW('⏱️ Timeout na requisição:', request.url);
        }
        throw error;
    }
}

/**
 * Verifica se uma resposta está expirada
 */
function isExpired(response, maxAge) {
    const dateHeader = response.headers.get('date');
    if (!dateHeader) return false;
    
    const responseTime = new Date(dateHeader).getTime();
    const currentTime = Date.now();
    
    return (currentTime - responseTime) > maxAge;
}

/**
 * Atualiza cache em background sem bloquear resposta
 */
async function updateCacheInBackground(request, cache) {
    try {
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            await cache.put(request, networkResponse.clone());
            logSW('🔄 Background cache update realizado:', request.url);
        }
    } catch (error) {
        logSW('⚠️ Background update falhou:', error.message);
    }
}

/**
 * Limpa cache específico baseado em padrão
 */
async function clearSpecificCache(pattern) {
    try {
        logSW('🧹 Iniciando limpeza específica:', pattern);
        let totalRemoved = 0;
        
        const cacheNames = await caches.keys();
        
        for (const cacheName of cacheNames) {
            const cache = await caches.open(cacheName);
            const requests = await cache.keys();
            
            for (const request of requests) {
                const shouldDelete = pattern ? 
                    request.url.includes(pattern) : 
                    isNeverCacheUrl(new URL(request.url));
                
                if (shouldDelete) {
                    await cache.delete(request);
                    totalRemoved++;
                    logSW('🗑️ Cache entry removida:', request.url);
                }
            }
        }
        
        logSW(`✅ Limpeza específica concluída: ${totalRemoved} entradas removidas`);
        
        // Notifica clientes
        await notifyClients({
            type: 'CACHE_CLEARED',
            pattern: pattern,
            removed: totalRemoved
        });
        
    } catch (error) {
        logSW('❌ Erro na limpeza específica:', error);
    }
}

/**
 * Força logout limpando todos os caches relacionados
 */
async function handleForceLogout() {
    try {
        logSW('🚪 Executando force logout - limpando caches relacionados');
        
        // Remove entradas específicas de logout de todos os caches
        await clearSpecificCache('logout');
        await clearSpecificCache('login');
        await clearSpecificCache('dashboard');
        
        // Limpa cache de dados completamente
        await caches.delete(DATA_CACHE_NAME);
        logSW('💥 Cache de dados removido completamente');
        
        // Recria cache de dados vazio
        await caches.open(DATA_CACHE_NAME);
        logSW('📊 Cache de dados recriado');
        
        // Notifica todos os clientes
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
            client.postMessage({
                type: 'LOGOUT_COMPLETE',
                message: 'Cache limpo - logout concluído',
                timestamp: Date.now()
            });
        });
        
        logSW('📢 Clientes notificados sobre logout completo');
        
    } catch (error) {
        logSW('❌ Erro no force logout:', error);
    }
}

/**
 * Limpa todos os caches (função de emergência)
 */
async function clearAllCaches() {
    try {
        logSW('💥 LIMPEZA TOTAL - removendo todos os caches');
        
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames.map(name => {
            logSW('🗑️ Removendo cache:', name);
            return caches.delete(name);
        }));
        
        logSW(`✅ ${cacheNames.length} caches removidos completamente`);
        
        // Notifica clientes
        await notifyClients({
            type: 'ALL_CACHES_CLEARED',
            message: 'Todos os caches foram limpos',
            timestamp: Date.now()
        });
        
    } catch (error) {
        logSW('❌ Erro na limpeza total:', error);
    }
}
/**
 * Envia status do cache para cliente específico
 */
async function sendCacheStatus(client) {
    try {
        const cacheNames = await caches.keys();
        const status = {
            caches: [],
            total_size: 0,
            version: CACHE_NAME
        };
        
        for (const cacheName of cacheNames) {
            const cache = await caches.open(cacheName);
            const requests = await cache.keys();
            
            status.caches.push({
                name: cacheName,
                entries: requests.length,
                is_current: cacheName === CACHE_NAME || cacheName === DATA_CACHE_NAME
            });
        }
        
        client.postMessage({
            type: 'CACHE_STATUS',
            status: status,
            timestamp: Date.now()
        });
        
        logSW('📊 Status do cache enviado para cliente');
        
    } catch (error) {
        logSW('❌ Erro ao enviar status do cache:', error);
    }
}

/**
 * Event: Background Sync
 * Sincroniza dados quando a conexão é restaurada
 */
self.addEventListener('sync', event => {
    logSW('🔄 Background sync evento:', event.tag);
    
    if (event.tag === CONFIG.BACKGROUND_SYNC_TAG) {
        event.waitUntil(syncPendingData());
    }
});

/**
 * Event: Push
 * Recebe e processa notificações push
 */
self.addEventListener('push', event => {
    logSW('📱 Notificação push recebida');
    
    let notificationData = {
        title: 'IMED Boletos',
        body: 'Você tem uma nova notificação',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/badge-72x72.png',
        tag: 'boletos-notification',
        data: {},
        actions: [
            {
                action: 'view',
                title: 'Ver Boletos',
                icon: '/icons/action-view.png'
            },
            {
                action: 'close',
                title: 'Fechar'
            }
        ],
        requireInteraction: false,
        silent: false
    };
    
    if (event.data) {
        try {
            const data = event.data.json();
            notificationData = { ...notificationData, ...data };
            logSW('📱 Dados da notificação processados:', data);
        } catch (e) {
            logSW('❌ Erro ao processar dados push:', e);
        }
    }
    
    event.waitUntil(
        self.registration.showNotification(notificationData.title, notificationData)
    );
});

/**
 * Event: Notification Click
 * Manipula cliques em notificações
 */
self.addEventListener('notificationclick', event => {
    logSW('🔔 Notificação clicada:', event.notification.tag);
    
    event.notification.close();
    
    if (event.action === 'close') {
        logSW('❌ Usuário fechou notificação');
        return;
    }
    
    // Determina a URL baseada no tipo de notificação
    let targetUrl = '/dashboard.php';
    const data = event.notification.data;
    
    if (data && data.url) {
        targetUrl = data.url;
    } else if (data && data.boleto_id) {
        targetUrl = `/dashboard.php?boleto=${data.boleto_id}`;
    }
    
    logSW('🎯 Abrindo URL da notificação:', targetUrl);
    
    event.waitUntil(
        (async () => {
            try {
                // Busca uma aba aberta do app
                const clients = await self.clients.matchAll({
                    type: 'window',
                    includeUncontrolled: true
                });
                
                // Se há uma aba aberta, foca nela
                for (const client of clients) {
                    if (client.url.includes('dashboard.php') && 'focus' in client) {
                        logSW('🎯 Focando aba existente');
                        return client.focus();
                    }
                }
                
                // Senão, abre nova aba
                if (self.clients.openWindow) {
                    logSW('🆕 Abrindo nova aba');
                    return self.clients.openWindow(targetUrl);
                }
            } catch (error) {
                logSW('❌ Erro ao abrir notificação:', error);
            }
        })()
    );
});

/**
 * Agenda sincronização em background
 */
async function scheduleBackgroundSync() {
    try {
        if ('sync' in self.registration) {
            await self.registration.sync.register(CONFIG.BACKGROUND_SYNC_TAG);
            logSW('⏰ Background sync agendado');
        }
    } catch (error) {
        logSW('❌ Background sync não suportado:', error);
    }
}

/**
 * Sincroniza dados pendentes quando conexão é restaurada
 */
async function syncPendingData() {
    logSW('🔄 Iniciando sincronização de dados pendentes');
    
    try {
        // Busca dados que precisam ser sincronizados
        const clients = await self.clients.matchAll();
        
        // Notifica clientes para sincronizar
        clients.forEach(client => {
            client.postMessage({
                type: 'SYNC_REQUEST',
                message: 'Sincronizando dados...',
                timestamp: Date.now()
            });
        });
        
        logSW('✅ Solicitação de sincronização enviada para clientes');
        
    } catch (error) {
        logSW('❌ Erro na sincronização:', error);
        throw error;
    }
}

/**
 * Notifica todos os clientes conectados
 */
async function notifyClients(data) {
    try {
        const clients = await self.clients.matchAll();
        const message = {
            ...data,
            sw_version: CACHE_NAME,
            timestamp: Date.now()
        };
        
        clients.forEach(client => {
            client.postMessage(message);
        });
        
        logSW(`📢 ${clients.length} clientes notificados:`, data.type);
        
    } catch (error) {
        logSW('❌ Erro ao notificar clientes:', error);
    }
}

/**
 * Cria página offline básica quando não há cache disponível
 */
function createOfflinePage() {
    const offlineHTML = `
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Offline - IMED Boletos</title>
            <meta name="theme-color" content="#0066cc">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    display: flex; flex-direction: column; align-items: center; justify-content: center;
                    min-height: 100vh; background: linear-gradient(135deg, #0066cc, #004499);
                    color: white; text-align: center; padding: 20px;
                }
                .container { max-width: 400px; animation: fadeIn 0.6s ease-out; }
                @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
                .icon { font-size: 4rem; margin-bottom: 1rem; animation: pulse 2s infinite; }
                @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
                h1 { font-size: 2rem; margin-bottom: 1rem; font-weight: 600; }
                p { font-size: 1.1rem; margin-bottom: 2rem; opacity: 0.9; line-height: 1.5; }
                .btn {
                    background: rgba(255,255,255,0.2); color: white; padding: 12px 24px;
                    border: 2px solid rgba(255,255,255,0.3); border-radius: 8px; cursor: pointer;
                    font-size: 1rem; transition: all 0.3s; text-decoration: none; display: inline-block;
                }
                .btn:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); }
                .status {
                    margin-top: 2rem; padding: 1rem; background: rgba(0,0,0,0.2);
                    border-radius: 8px; font-size: 0.9rem;
                }
                @media (max-width: 480px) {
                    .icon { font-size: 3rem; } h1 { font-size: 1.5rem; } p { font-size: 1rem; }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="icon" id="statusIcon">📱</div>
                <h1 id="statusTitle">Você está offline</h1>
                <p id="statusMessage">
                    Sem conexão com a internet no momento. 
                    Alguns recursos podem estar limitados, mas você ainda pode 
                    visualizar dados salvos anteriormente.
                </p>
                
                <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <button class="btn" onclick="checkConnection()">🔄 Tentar Conectar</button>
                    <a href="/dashboard.php" class="btn">📊 Ver Dados Salvos</a>
                </div>
                
                <div class="status">
                    <strong>Status:</strong> <span id="statusText">Verificando...</span>
                </div>
            </div>

            <script>
                let isRetrying = false;
                
                function updateStatus() {
                    const online = navigator.onLine;
                    const statusIcon = document.getElementById('statusIcon');
                    const statusTitle = document.getElementById('statusTitle');
                    const statusText = document.getElementById('statusText');
                    
                    if (online) {
                        statusIcon.textContent = '✅';
                        statusTitle.textContent = 'Conexão Restaurada!';
                        statusText.textContent = 'Online';
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        statusIcon.textContent = '📱';
                        statusTitle.textContent = 'Você está offline';
                        statusText.textContent = 'Offline';
                    }
                }
                
                async function checkConnection() {
                    if (isRetrying) return;
                    isRetrying = true;
                    
                    const btn = event.target;
                    btn.textContent = '🔄 Conectando...';
                    btn.disabled = true;
                    
                    try {
                        const response = await fetch('/?_=' + Date.now(), { method: 'HEAD' });
                        if (response.ok) {
                            btn.textContent = '✅ Conectado!';
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            throw new Error('Server error');
                        }
                    } catch (error) {
                        btn.textContent = '❌ Sem Conexão';
                        setTimeout(() => {
                            btn.textContent = '🔄 Tentar Conectar';
                            btn.disabled = false;
                            isRetrying = false;
                        }, 2000);
                    }
                }
                
                // Event listeners
                window.addEventListener('online', updateStatus);
                window.addEventListener('offline', updateStatus);
                
                // Initial check
                updateStatus();
                
                // Periodic check
                setInterval(updateStatus, 5000);
                
                console.log('📱 Página offline carregada - IMED Boletos');
            </script>
        </body>
        </html>
    `;
    
    return new Response(offlineHTML, {
        status: 200,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-cache'
        }
    });
}

/**
 * Cria resposta offline para APIs
 */
function createOfflineAPIResponse() {
    const offlineResponse = {
        success: false,
        message: 'Sem conexão. Dados podem estar desatualizados.',
        offline: true,
        error_code: 'OFFLINE',
        cached_at: new Date().toISOString(),
        retry_suggested: true
    };
    
    return new Response(
        JSON.stringify(offlineResponse, null, 2),
        {
            status: 503,
            statusText: 'Service Unavailable - Offline',
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'Cache-Control': 'no-cache',
                'X-Offline-Response': 'true'
            }
        }
    );
}

/**
 * Cria placeholder SVG para imagens offline
 */
function createImagePlaceholder() {
    const svg = `
        <svg width="300" height="200" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="diagonal" patternUnits="userSpaceOnUse" width="4" height="4">
                    <path d="M 0,4 l 4,-4 M -1,1 l 2,-2 M 3,5 l 2,-2" stroke="#ddd" stroke-width="1"/>
                </pattern>
            </defs>
            <rect width="300" height="200" fill="#f8f9fa"/>
            <rect width="300" height="200" fill="url(#diagonal)" opacity="0.3"/>
            <circle cx="150" cy="80" r="20" fill="#e9ecef"/>
            <path d="M 130,70 Q 130,60 140,60 Q 150,50 160,60 Q 170,60 170,70 Q 170,80 160,80 Q 150,90 140,80 Q 130,80 130,70" fill="#dee2e6"/>
            <rect x="100" y="120" width="100" height="8" fill="#e9ecef" rx="4"/>
            <rect x="120" y="140" width="60" height="6" fill="#dee2e6" rx="3"/>
            <text x="150" y="170" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="12">
                Imagem indisponível
            </text>
            <text x="150" y="185" text-anchor="middle" fill="#adb5bd" font-family="Arial, sans-serif" font-size="10">
                Verifique sua conexão
            </text>
        </svg>
    `;
    
    return new Response(svg, {
        status: 200,
        headers: {
            'Content-Type': 'image/svg+xml',
            'Cache-Control': 'max-age=86400',
            'X-Placeholder': 'true'
        }
    });
}

/**
 * Limpa caches antigos e otimiza espaço de armazenamento
 */
async function cleanupCaches() {
    logSW('🧹 Iniciando limpeza automática de caches');
    
    try {
        const cacheNames = await caches.keys();
        let totalCleaned = 0;
        
        for (const cacheName of cacheNames) {
            const cache = await caches.open(cacheName);
            const requests = await cache.keys();
            
            // Aplica limite de entradas por cache
            const limit = getCacheLimit(cacheName);
            if (requests.length > limit) {
                // Remove as entradas mais antigas (assumindo ordem FIFO)
                const oldRequests = requests.slice(0, requests.length - limit);
                await Promise.all(oldRequests.map(req => cache.delete(req)));
                totalCleaned += oldRequests.length;
                logSW(`🗑️ ${oldRequests.length} entradas antigas removidas de ${cacheName}`);
            }
            
            // Remove entradas expiradas
            let expiredCount = 0;
            for (const request of requests) {
                const response = await cache.match(request);
                if (response && isExpired(response, CONFIG.CACHE_DURATION.STATIC)) {
                    await cache.delete(request);
                    expiredCount++;
                    totalCleaned++;
                }
            }
            
            if (expiredCount > 0) {
                logSW(`⏰ ${expiredCount} entradas expiradas removidas de ${cacheName}`);
            }
        }
        
        logSW(`✅ Limpeza concluída: ${totalCleaned} entradas removidas no total`);
        
    } catch (error) {
        logSW('❌ Erro na limpeza automática:', error);
    }
}

/**
 * Obtém limite de cache para um cache específico
 */
function getCacheLimit(cacheName) {
    if (cacheName.includes('data')) return CONFIG.CACHE_LIMITS.API;
    if (cacheName.includes('image')) return CONFIG.CACHE_LIMITS.IMAGES;
    if (cacheName === CACHE_NAME) return CONFIG.CACHE_LIMITS.STATIC;
    return CONFIG.CACHE_LIMITS.DYNAMIC;
}

/**
 * Monitora uso de quota de armazenamento
 */
async function monitorStorageQuota() {
    try {
        if ('storage' in navigator && 'estimate' in navigator.storage) {
            const estimate = await navigator.storage.estimate();
            const usedMB = Math.round(estimate.usage / 1024 / 1024);
            const quotaMB = Math.round(estimate.quota / 1024 / 1024);
            const usagePercent = Math.round((estimate.usage / estimate.quota) * 100);
            
            logSW(`💾 Storage: ${usedMB}MB/${quotaMB}MB (${usagePercent}%)`);
            
            // Se uso > 80%, força limpeza
            if (usagePercent > 80) {
                logSW('⚠️ Alto uso de storage, forçando limpeza');
                await cleanupCaches();
            }
        }
    } catch (error) {
        logSW('❌ Erro ao monitorar storage:', error);
    }
}

// ============ INICIALIZAÇÃO E CONFIGURAÇÃO FINAL ============

// Executa limpeza periódica a cada 6 horas
setInterval(cleanupCaches, 6 * 60 * 60 * 1000);

// Monitora storage a cada 30 minutos
setInterval(monitorStorageQuota, 30 * 60 * 1000);

// Log de inicialização completa
logSW('🚀 Service Worker totalmente carregado - IMED Boletos PWA');
logSW('📋 Versão:', CACHE_NAME);
logSW('📊 Configurações:', CONFIG);

// Detecção de capacidades
logSW('🔧 Capacidades detectadas:', {
    'Background Sync': 'sync' in self.registration,
    'Push Notifications': 'showNotification' in self.registration,
    'Cache Storage': 'caches' in self,
    'IndexedDB': 'indexedDB' in self,
    'Fetch': 'fetch' in self
});

// Monitora storage inicial
monitorStorageQuota();

// Log final
logSW('✅ Service Worker pronto para uso - Todas as correções de logout implementadas');

/**
 * RESUMO DAS CORREÇÕES IMPLEMENTADAS:
 * 
 * 🔧 NEVER_CACHE_PATTERNS: URLs relacionadas a logout nunca são interceptadas
 * 🔧 isNeverCacheUrl(): Verifica padrões e bypassa completamente
 * 🔧 handleForceLogout(): Limpa caches específicos sem afetar funcionamento
 * 🔧 clearSpecificCache(): Remove apenas entradas problemáticas
 * 🔧 Logs detalhados: Facilita debug e monitoramento
 * 🔧 Fallbacks robustos: Páginas offline funcionais
 * 🔧 Comunicação com clientes: Coordena operações de logout
 * 🔧 Limpeza automática: Mantém storage otimizado
 * 
 * O Service Worker agora funciona perfeitamente com o sistema de logout,
 * eliminando completamente o erro ERR_FAILED.
 */