/**
 * Sistema de Boletos IMED - Service Worker PWA
 * Arquivo: sw.js
 * 
 * Service Worker completo com cache inteligente, funcionamento offline,
 * notificações push e sincronização em background
 */

const CACHE_NAME = 'imed-boletos-v2.1.0';
const DATA_CACHE_NAME = 'imed-boletos-data-v1.0';

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
    'https://code.jquery.com/jquery-3.6.0.min.js',
    
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

// Configurações
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
    BACKGROUND_SYNC_TAG: 'boletos-sync'
};

/**
 * Event: Install
 * Instala o Service Worker e faz cache inicial
 */
self.addEventListener('install', event => {
    console.log('[SW] Instalando Service Worker v' + CACHE_NAME);
    
    event.waitUntil(
        (async () => {
            try {
                // Cache estático inicial
                const staticCache = await caches.open(CACHE_NAME);
                await staticCache.addAll(STATIC_CACHE_URLS);
                
                // Cache de dados
                const dataCache = await caches.open(DATA_CACHE_NAME);
                
                console.log('[SW] Cache inicial criado com sucesso');
                
                // Força atualização imediata
                await self.skipWaiting();
                
            } catch (error) {
                console.error('[SW] Erro durante instalação:', error);
                
                // Cache essencial mesmo se houver erros
                try {
                    const essentialCache = await caches.open(CACHE_NAME);
                    await essentialCache.addAll([
                        '/',
                        '/dashboard.php',
                        '/offline.html'
                    ]);
                } catch (essentialError) {
                    console.error('[SW] Erro no cache essencial:', essentialError);
                }
            }
        })()
    );
});

/**
 * Event: Activate
 * Ativa o novo Service Worker e limpa caches antigos
 */
self.addEventListener('activate', event => {
    console.log('[SW] Ativando Service Worker v' + CACHE_NAME);
    
    event.waitUntil(
        (async () => {
            try {
                // Limpa caches antigos
                const cacheNames = await caches.keys();
                const deletionPromises = cacheNames
                    .filter(name => name !== CACHE_NAME && name !== DATA_CACHE_NAME)
                    .map(name => {
                        console.log('[SW] Removendo cache antigo:', name);
                        return caches.delete(name);
                    });
                
                await Promise.all(deletionPromises);
                
                // Assume controle imediato de todas as abas
                await self.clients.claim();
                
                console.log('[SW] Service Worker ativado e assumiu controle');
                
                // Notifica clientes sobre a ativação
                notifyClients({
                    type: 'SW_ACTIVATED',
                    version: CACHE_NAME,
                    message: 'Sistema atualizado! Nova versão disponível.'
                });
                
            } catch (error) {
                console.error('[SW] Erro durante ativação:', error);
            }
        })()
    );
});

/**
 * Event: Fetch
 * Intercepta todas as requisições e aplica estratégias de cache
 */
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // Ignora requisições não HTTP/HTTPS
    if (!request.url.startsWith('http')) {
        return;
    }
    
    // Estratégia baseada no tipo de recurso
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
 * Event: Background Sync
 * Sincroniza dados quando a conexão é restaurada
 */
self.addEventListener('sync', event => {
    console.log('[SW] Background sync evento:', event.tag);
    
    if (event.tag === CONFIG.BACKGROUND_SYNC_TAG) {
        event.waitUntil(syncPendingData());
    }
});

/**
 * Event: Push
 * Recebe notificações push
 */
self.addEventListener('push', event => {
    console.log('[SW] Notificação push recebida');
    
    let notificationData = {
        title: 'IMED Boletos',
        body: 'Você tem uma nova notificação',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/badge-72x72.png',
        tag: 'boletos-notification',
        data: {}
    };
    
    if (event.data) {
        try {
            const data = event.data.json();
            notificationData = { ...notificationData, ...data };
        } catch (e) {
            console.error('[SW] Erro ao processar dados push:', e);
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
    console.log('[SW] Notificação clicada:', event.notification.tag);
    
    event.notification.close();
    
    // Determina a URL baseada no tipo de notificação
    let targetUrl = '/dashboard.php';
    const data = event.notification.data;
    
    if (data && data.url) {
        targetUrl = data.url;
    } else if (data && data.boleto_id) {
        targetUrl = `/dashboard.php?boleto=${data.boleto_id}`;
    }
    
    event.waitUntil(
        (async () => {
            // Busca uma aba aberta do app
            const clients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true
            });
            
            // Se há uma aba aberta, foca nela
            for (const client of clients) {
                if (client.url.includes(targetUrl) && 'focus' in client) {
                    return client.focus();
                }
            }
            
            // Senão, abre nova aba
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })()
    );
});

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
 * Manipula recursos estáticos - Cache First
 */
async function handleStaticResource(request) {
    try {
        const cache = await caches.open(CACHE_NAME);
        const cachedResponse = await cache.match(request);
        
        if (cachedResponse && !isExpired(cachedResponse, CONFIG.CACHE_DURATION.STATIC)) {
            console.log('[SW] Cache hit (static):', request.url);
            return cachedResponse;
        }
        
        // Busca da rede
        const networkResponse = await fetchWithTimeout(request);
        
        // Atualiza cache se a resposta for válida
        if (networkResponse && networkResponse.status === 200) {
            await cache.put(request, networkResponse.clone());
            console.log('[SW] Cache atualizado (static):', request.url);
        }
        
        return networkResponse;
        
    } catch (error) {
        console.log('[SW] Rede falhou, tentando cache (static):', request.url);
        
        const cache = await caches.open(CACHE_NAME);
        const fallbackResponse = await cache.match(request);
        
        if (fallbackResponse) {
            return fallbackResponse;
        }
        
        // Fallback final para recursos críticos
        if (request.url.includes('dashboard.php')) {
            return caches.match('/offline.html');
        }
        
        throw error;
    }
}

/**
 * Manipula requisições de API - Network First com Cache Fallback
 */
async function handleAPIRequest(request) {
    const cacheName = DATA_CACHE_NAME;
    
    try {
        // Network First para APIs críticas
        if (isNetworkFirst(new URL(request.url))) {
            const networkResponse = await fetchWithTimeout(request);
            
            if (networkResponse && networkResponse.status === 200) {
                const cache = await caches.open(cacheName);
                await cache.put(request, networkResponse.clone());
                console.log('[SW] API cache atualizado (network-first):', request.url);
            }
            
            return networkResponse;
        }
        
        // Cache First para outras APIs
        const cache = await caches.open(cacheName);
        const cachedResponse = await cache.match(request);
        
        if (cachedResponse && !isExpired(cachedResponse, CONFIG.CACHE_DURATION.API)) {
            console.log('[SW] Cache hit (API):', request.url);
            
            // Atualiza cache em background
            updateCacheInBackground(request, cache);
            
            return cachedResponse;
        }
        
        // Busca da rede
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            await cache.put(request, networkResponse.clone());
            console.log('[SW] API cache atualizado:', request.url);
        }
        
        return networkResponse;
        
    } catch (error) {
        console.log('[SW] API erro, tentando cache:', request.url);
        
        // Fallback para cache
        const cache = await caches.open(cacheName);
        const fallbackResponse = await cache.match(request);
        
        if (fallbackResponse) {
            // Agenda sincronização para mais tarde
            await scheduleBackgroundSync();
            return fallbackResponse;
        }
        
        // Resposta offline personalizada para APIs
        return new Response(
            JSON.stringify({
                success: false,
                message: 'Sem conexão. Dados podem estar desatualizados.',
                offline: true,
                cached_at: new Date().toISOString()
            }),
            {
                status: 503,
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-cache'
                }
            }
        );
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
            return cachedResponse;
        }
        
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            await cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
        
    } catch (error) {
        const cache = await caches.open(CACHE_NAME);
        const fallbackResponse = await cache.match(request);
        
        if (fallbackResponse) {
            return fallbackResponse;
        }
        
        // Imagem placeholder para offline
        return new Response(
            '<svg width="200" height="150" xmlns="http://www.w3.org/2000/svg"><rect width="200" height="150" fill="#f0f0f0"/><text x="100" y="75" text-anchor="middle" fill="#999">Imagem indisponível</text></svg>',
            {
                status: 200,
                headers: {
                    'Content-Type': 'image/svg+xml',
                    'Cache-Control': 'max-age=86400'
                }
            }
        );
    }
}

/**
 * Manipula requisições dinâmicas - Network First
 */
async function handleDynamicRequest(request) {
    try {
        const networkResponse = await fetchWithTimeout(request);
        
        // Cacheia apenas respostas HTML válidas
        if (networkResponse && 
            networkResponse.status === 200 && 
            networkResponse.headers.get('content-type')?.includes('text/html')) {
            
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
        
    } catch (error) {
        // Fallback para cache
        const cache = await caches.open(CACHE_NAME);
        const cachedResponse = await cache.match(request);
        
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // Página offline
        if (request.mode === 'navigate') {
            return caches.match('/offline.html') || createOfflinePage();
        }
        
        throw error;
    }
}

/**
 * Fetch com timeout
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
 * Atualiza cache em background
 */
async function updateCacheInBackground(request, cache) {
    try {
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            await cache.put(request, networkResponse.clone());
            console.log('[SW] Background cache update:', request.url);
        }
    } catch (error) {
        console.log('[SW] Background update falhou:', error);
    }
}

/**
 * Agenda sincronização em background
 */
async function scheduleBackgroundSync() {
    try {
        await self.registration.sync.register(CONFIG.BACKGROUND_SYNC_TAG);
        console.log('[SW] Background sync agendado');
    } catch (error) {
        console.log('[SW] Background sync não suportado:', error);
    }
}

/**
 * Sincroniza dados pendentes
 */
async function syncPendingData() {
    console.log('[SW] Iniciando sincronização de dados pendentes');
    
    try {
        // Busca dados que precisam ser sincronizados
        const clients = await self.clients.matchAll();
        
        // Notifica clientes para sincronizar
        clients.forEach(client => {
            client.postMessage({
                type: 'SYNC_REQUEST',
                message: 'Sincronizando dados...'
            });
        });
        
        // Aqui você pode implementar lógica específica de sincronização
        // Por exemplo, reenviar formulários, atualizar dados, etc.
        
        console.log('[SW] Sincronização concluída');
        
    } catch (error) {
        console.error('[SW] Erro na sincronização:', error);
        throw error;
    }
}

/**
 * Notifica todos os clientes
 */
async function notifyClients(data) {
    const clients = await self.clients.matchAll();
    
    clients.forEach(client => {
        client.postMessage(data);
    });
}

/**
 * Cria página offline básica
 */
function createOfflinePage() {
    const offlineHTML = `
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Offline - IMED Boletos</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                    background: linear-gradient(135deg, #0066cc, #004499);
                    color: white;
                    text-align: center;
                    padding: 20px;
                }
                .icon {
                    font-size: 4rem;
                    margin-bottom: 1rem;
                }
                h1 {
                    margin-bottom: 0.5rem;
                }
                p {
                    margin-bottom: 2rem;
                    opacity: 0.9;
                }
                .btn {
                    background: rgba(255,255,255,0.2);
                    color: white;
                    padding: 12px 24px;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 1rem;
                    transition: background 0.3s;
                }
                .btn:hover {
                    background: rgba(255,255,255,0.3);
                }
            </style>
        </head>
        <body>
            <div class="icon">📱</div>
            <h1>Você está offline</h1>
            <p>Verifique sua conexão com a internet e tente novamente.</p>
            <button class="btn" onclick="location.reload()">Tentar Novamente</button>
            
            <script>
                // Verifica conexão automaticamente
                window.addEventListener('online', () => {
                    location.reload();
                });
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
 * Limpa caches antigos e otimiza espaço
 */
async function cleanupCaches() {
    console.log('[SW] Iniciando limpeza de caches');
    
    try {
        const cacheNames = await caches.keys();
        
        for (const cacheName of cacheNames) {
            const cache = await caches.open(cacheName);
            const requests = await cache.keys();
            
            // Remove entradas antigas de cada cache
            const limit = getCacheLimit(cacheName);
            if (requests.length > limit) {
                const oldRequests = requests.slice(0, requests.length - limit);
                await Promise.all(oldRequests.map(req => cache.delete(req)));
                console.log(`[SW] Removidas ${oldRequests.length} entradas antigas de ${cacheName}`);
            }
        }
        
    } catch (error) {
        console.error('[SW] Erro na limpeza de caches:', error);
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
 * Executa limpeza periódica
 */
setInterval(cleanupCaches, 6 * 60 * 60 * 1000); // A cada 6 horas

// Log de inicialização
console.log('[SW] Service Worker carregado - IMED Boletos PWA v' + CACHE_NAME);
console.log('[SW] Configurações:', CONFIG);

// Testa capacidades do navegador
if ('serviceWorker' in navigator) {
    console.log('[SW] Service Worker suportado');
}

if ('PushManager' in window) {
    console.log('[SW] Push Notifications suportadas');
}

if ('BackgroundSync' in window) {
    console.log('[SW] Background Sync suportado');
} else {
    console.log('[SW] Background Sync não suportado');
}