/**
 * Sistema de Boletos IMEPEDU - Service Worker CORRIGIDO para Upload
 * Arquivo: sw.js - VERSÃO CORRIGIDA PARA UPLOAD
 * Versão: 2.2.0 - Solução definitiva para ERR_FAILED no upload
 */

const CACHE_NAME = 'IMEPEDU-boletos-v2.2.0';
const DATA_CACHE_NAME = 'IMEPEDU-boletos-data-v1.4';

// URLs para cache estático
const STATIC_CACHE_URLS = [
    '/',
    '/index.php',
    '/dashboard.php',
    '/login.php',
    '/manifest.json',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/offline.html'
];

// 🔧 CORREÇÃO CRÍTICA: URLs que NUNCA devem ser interceptadas pelo SW
const NEVER_INTERCEPT_PATTERNS = [
    // Logout patterns
    /\/logout\.php/,
    /\/login\.php\?logout=/,
    /\/index\.php\?logout=/,
    /logout=1/,
    /\?t=\d+/,
    
    // 🆕 UPLOAD patterns - NUNCA interceptar uploads
    /\/admin\/upload-boletos\.php/,
    /\/admin\/api\/upload/,
    /\/admin\/.*upload/,
    /multipart\/form-data/,
    
    // Admin area critical operations
    /\/admin\/.*\.php.*method=POST/,
    /\/admin\/api\/.*\.php.*POST/,
    
    // File operations
    /\/api\/download-boleto\.php/,
    /\/uploads\//,
    /\.pdf$/,
    
    // Cache busting
    /clear_cache=1/,
    /fallback=1/,
    /pwa=1/,
    /force_refresh=1/
];

// Configurações
const CONFIG = {
    CACHE_DURATION: {
        STATIC: 30 * 24 * 60 * 60 * 1000,      // 30 dias
        API: 5 * 60 * 1000,                     // 5 minutos (reduzido)
        IMAGES: 7 * 24 * 60 * 60 * 1000,       // 7 dias
        DYNAMIC: 60 * 60 * 1000                 // 1 hora (reduzido)
    },
    NETWORK_TIMEOUT: 10000,  // Aumentado para 10s
    DEBUG: true,
    LOG_PREFIX: '[SW-IMEPEDU-UPLOAD-FIX]'
};

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
 */
self.addEventListener('install', event => {
    logSW('🔧 Instalando SW com correção de upload v' + CACHE_NAME);
    
    event.waitUntil(
        (async () => {
            try {
                const staticCache = await caches.open(CACHE_NAME);
                
                // Cache apenas recursos essenciais e seguros
                const essentialUrls = ['/', '/dashboard.php', '/login.php', '/offline.html'];
                
                for (const url of essentialUrls) {
                    try {
                        await staticCache.add(url);
                        logSW(`✅ Cached: ${url}`);
                    } catch (error) {
                        logSW(`⚠️ Failed to cache: ${url}`);
                    }
                }
                
                await caches.open(DATA_CACHE_NAME);
                logSW('✅ Instalação concluída com correção de upload');
                await self.skipWaiting();
                
            } catch (error) {
                logSW('❌ Erro na instalação:', error);
            }
        })()
    );
});

/**
 * Event: Activate
 */
self.addEventListener('activate', event => {
    logSW('🚀 Ativando SW com correção de upload');
    
    event.waitUntil(
        (async () => {
            try {
                const cacheNames = await caches.keys();
                const currentCaches = [CACHE_NAME, DATA_CACHE_NAME];
                
                const deletionPromises = cacheNames
                    .filter(name => !currentCaches.includes(name))
                    .map(name => {
                        logSW('🗑️ Removendo cache antigo:', name);
                        return caches.delete(name);
                    });
                
                await Promise.all(deletionPromises);
                await self.clients.claim();
                
                // Notifica sobre nova versão
                const clients = await self.clients.matchAll();
                clients.forEach(client => {
                    client.postMessage({
                        type: 'SW_UPDATED',
                        version: CACHE_NAME,
                        message: 'Service Worker atualizado com correção de upload!'
                    });
                });
                
                logSW('✅ Ativação concluída');
                
            } catch (error) {
                logSW('❌ Erro na ativação:', error);
            }
        })()
    );
});

/**
 * Event: Fetch - VERSÃO TOTALMENTE CORRIGIDA PARA UPLOAD
 */
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // Ignora requisições não HTTP/HTTPS
    if (!request.url.startsWith('http')) {
        return;
    }
    
    // 🔧 CORREÇÃO CRÍTICA: NUNCA intercepta uploads e operações críticas
    if (shouldNeverIntercept(request, url)) {
        logSW('🚫 NEVER INTERCEPT - bypass completo:', request.url);
        logSW('📋 Request details:', {
            method: request.method,
            headers: getRequestHeaders(request),
            url: request.url
        });
        return; // Deixa o browser lidar completamente
    }
    
    // 🔧 CORREÇÃO: POST requests para admin NUNCA são interceptadas
    if (request.method === 'POST' && url.pathname.startsWith('/admin/')) {
        logSW('📝 POST Admin - ignorando completamente:', request.url);
        return;
    }
    
    // 🔧 CORREÇÃO: Multipart form data NUNCA é interceptado
    const contentType = request.headers.get('content-type') || '';
    if (contentType.includes('multipart/form-data')) {
        logSW('📎 Multipart data - ignorando:', request.url);
        return;
    }
    
    // Só intercepta GET requests seguros
    if (request.method === 'GET') {
        if (isStaticResource(url)) {
            event.respondWith(handleStaticResource(request));
        } else if (isAPIRequest(url) && !url.pathname.includes('upload')) {
            event.respondWith(handleAPIRequest(request));
        } else if (isImageRequest(url)) {
            event.respondWith(handleImageRequest(request));
        } else {
            event.respondWith(handleDynamicRequest(request));
        }
    }
});

/**
 * 🔧 FUNÇÃO CRÍTICA MELHORADA: Determina se requisição NUNCA deve ser interceptada
 */
function shouldNeverIntercept(request, url) {
    const fullUrl = url.pathname + url.search;
    const method = request.method.toUpperCase();
    
    // 1. Verifica padrões de URL
    const matchesNeverPattern = NEVER_INTERCEPT_PATTERNS.some(pattern => {
        const matches = pattern.test(fullUrl) || pattern.test(request.url);
        if (matches) {
            logSW(`🚫 Matched never-intercept pattern: ${fullUrl} -> ${pattern}`);
        }
        return matches;
    });
    
    if (matchesNeverPattern) return true;
    
    // 2. Qualquer POST para /admin/
    if (method === 'POST' && url.pathname.startsWith('/admin/')) {
        logSW(`🚫 POST to admin area: ${request.url}`);
        return true;
    }
    
    // 3. Requisições com form data
    const contentType = request.headers.get('content-type') || '';
    if (contentType.includes('multipart/form-data') || 
        contentType.includes('application/x-www-form-urlencoded')) {
        logSW(`🚫 Form data detected: ${request.url}`);
        return true;
    }
    
    // 4. Uploads específicos
    if (url.pathname.includes('upload') || 
        url.pathname.includes('file') ||
        url.pathname.includes('.pdf')) {
        logSW(`🚫 Upload/file operation: ${request.url}`);
        return true;
    }
    
    // 5. Admin API operations
    if (url.pathname.startsWith('/admin/api/') && method !== 'GET') {
        logSW(`🚫 Admin API non-GET: ${request.url}`);
        return true;
    }
    
    return false;
}

/**
 * Obtém headers da requisição para debug
 */
function getRequestHeaders(request) {
    const headers = {};
    if (request.headers) {
        try {
            for (const [key, value] of request.headers.entries()) {
                headers[key] = value;
            }
        } catch (e) {
            headers['error'] = 'Could not read headers';
        }
    }
    return headers;
}

/**
 * Verifica se é um recurso estático
 */
function isStaticResource(url) {
    return STATIC_CACHE_URLS.some(staticUrl => {
        if (typeof staticUrl === 'string') {
            return url.pathname === staticUrl || url.href === staticUrl;
        }
        return false;
    });
}

/**
 * Verifica se é uma requisição de API (só GET)
 */
function isAPIRequest(url) {
    return url.pathname.startsWith('/api/') && !url.pathname.includes('upload');
}

/**
 * Verifica se é uma requisição de imagem
 */
function isImageRequest(url) {
    return /\.(jpg|jpeg|png|gif|webp|svg|ico)$/i.test(url.pathname);
}

/**
 * Manipula recursos estáticos - Cache First
 */
async function handleStaticResource(request) {
    const url = new URL(request.url);
    
    try {
        // Para index.php com parâmetros especiais, sempre busca da rede
        if (url.pathname === '/index.php' && 
            (url.searchParams.has('logout') || url.searchParams.has('t'))) {
            logSW('🏠 Index especial - rede:', request.url);
            return await fetchWithTimeout(request);
        }
        
        const cache = await caches.open(CACHE_NAME);
        const cachedResponse = await cache.match(request);
        
        if (cachedResponse && !isExpired(cachedResponse, CONFIG.CACHE_DURATION.STATIC)) {
            logSW('📦 Cache hit (static):', request.url);
            return cachedResponse;
        }
        
        logSW('🌐 Network (static):', request.url);
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            // Só cacheia se não tem parâmetros especiais
            if (!url.searchParams.has('logout') && 
                !url.searchParams.has('t') && 
                !url.searchParams.has('clear_cache')) {
                await cache.put(request, networkResponse.clone());
                logSW('💾 Cache updated (static):', request.url);
            }
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ Static error:', error.message);
        const cache = await caches.open(CACHE_NAME);
        const fallback = await cache.match(request);
        return fallback || createOfflinePage();
    }
}

/**
 * Manipula requisições de API - Network First
 */
async function handleAPIRequest(request) {
    const url = new URL(request.url);
    
    try {
        // APIs com logout sempre da rede
        if (url.pathname.includes('logout') || 
            url.searchParams.has('logout')) {
            return await fetchWithTimeout(request);
        }
        
        logSW('🌐 Network first (API):', request.url);
        const networkResponse = await fetchWithTimeout(request);
        
        if (networkResponse && networkResponse.status === 200) {
            const cache = await caches.open(DATA_CACHE_NAME);
            await cache.put(request, networkResponse.clone());
            logSW('💾 API cached:', request.url);
        }
        
        return networkResponse;
        
    } catch (error) {
        logSW('❌ API error:', error.message);
        
        const cache = await caches.open(DATA_CACHE_NAME);
        const fallback = await cache.match(request);
        
        if (fallback) {
            logSW('📦 API fallback:', request.url);
            return fallback;
        }
        
        return createOfflineAPIResponse();
    }
}

/**
 * Manipula requisições de imagens
 */
async function handleImageRequest(request) {
    try {
        const cache = await caches.open(CACHE_NAME);
        const cached = await cache.match(request);
        
        if (cached) {
            logSW('📦 Image cache hit:', request.url);
            return cached;
        }
        
        logSW('🌐 Image network:', request.url);
        const response = await fetchWithTimeout(request);
        
        if (response && response.status === 200) {
            await cache.put(request, response.clone());
        }
        
        return response;
        
    } catch (error) {
        logSW('❌ Image error:', error.message);
        return createImagePlaceholder();
    }
}

/**
 * Manipula requisições dinâmicas
 */
async function handleDynamicRequest(request) {
    const url = new URL(request.url);
    
    try {
        // Requisições com parâmetros especiais sempre da rede
        if (url.searchParams.has('logout') || 
            url.searchParams.has('t') ||
            url.searchParams.has('clear_cache')) {
            return await fetchWithTimeout(request);
        }
        
        logSW('🌐 Dynamic network:', request.url);
        const response = await fetchWithTimeout(request);
        
        // Cache apenas HTML válido sem parâmetros especiais
        if (response && 
            response.status === 200 && 
            response.headers.get('content-type')?.includes('text/html')) {
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, response.clone());
            logSW('💾 Dynamic cached:', request.url);
        }
        
        return response;
        
    } catch (error) {
        logSW('❌ Dynamic error:', error.message);
        
        // Fallback apenas se não for logout
        if (!url.searchParams.has('logout')) {
            const cache = await caches.open(CACHE_NAME);
            const fallback = await cache.match(request);
            
            if (fallback) {
                logSW('📦 Dynamic fallback:', request.url);
                return fallback;
            }
        }
        
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
 * Verifica se resposta está expirada
 */
function isExpired(response, maxAge) {
    const dateHeader = response.headers.get('date');
    if (!dateHeader) return false;
    
    const responseTime = new Date(dateHeader).getTime();
    const currentTime = Date.now();
    
    return (currentTime - responseTime) > maxAge;
}

/**
 * Cria página offline
 */
function createOfflinePage() {
    const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - IMEPEDU Boletos</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            margin: 0; 
            background: linear-gradient(135deg, #0066cc, #004499); 
            color: white; 
            text-align: center; 
        }
        .container { max-width: 400px; padding: 2rem; }
        h1 { font-size: 2rem; margin-bottom: 1rem; }
        p { font-size: 1.1rem; margin-bottom: 2rem; opacity: 0.9; }
        .btn { 
            background: rgba(255,255,255,0.2); 
            color: white; 
            padding: 12px 24px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            margin: 0.5rem; 
            text-decoration: none; 
            display: inline-block; 
        }
        .btn:hover { background: rgba(255,255,255,0.3); }
    </style>
</head>
<body>
    <div class="container">
        <h1>📱 Você está offline</h1>
        <p>Verifique sua conexão e tente novamente.</p>
        <button class="btn" onclick="location.reload()">🔄 Tentar Novamente</button>
        <a href="/dashboard.php" class="btn">📊 Ver Dados Salvos</a>
    </div>
</body>
</html>`;
    
    return new Response(html, {
        headers: { 'Content-Type': 'text/html; charset=utf-8' }
    });
}

/**
 * Cria resposta offline para API
 */
function createOfflineAPIResponse() {
    const response = {
        success: false,
        message: 'Sem conexão. Dados podem estar desatualizados.',
        offline: true,
        timestamp: new Date().toISOString()
    };
    
    return new Response(JSON.stringify(response), {
        status: 503,
        headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
}

/**
 * Cria placeholder para imagens
 */
function createImagePlaceholder() {
    const svg = `<svg width="200" height="150" xmlns="http://www.w3.org/2000/svg">
        <rect width="200" height="150" fill="#f8f9fa"/>
        <text x="100" y="80" text-anchor="middle" fill="#6c757d" font-size="14">Imagem indisponível</text>
    </svg>`;
    
    return new Response(svg, {
        headers: { 'Content-Type': 'image/svg+xml' }
    });
}

/**
 * Event: Message - Comandos dos clientes
 */
self.addEventListener('message', event => {
    const data = event.data;
    if (!data || !data.type) return;
    
    logSW('📨 Message:', data.type);
    
    switch (data.type) {
        case 'SKIP_WAITING':
            self.skipWaiting();
            break;
        case 'CLAIM_CLIENTS':
            self.clients.claim();
            break;
        case 'CLEAR_UPLOAD_CACHE':
            event.waitUntil(clearUploadCache());
            break;
    }
});

/**
 * Limpa cache relacionado a upload
 */
async function clearUploadCache() {
    try {
        logSW('🧹 Limpando cache de upload');
        
        const cacheNames = await caches.keys();
        for (const cacheName of cacheNames) {
            const cache = await caches.open(cacheName);
            const requests = await cache.keys();
            
            for (const request of requests) {
                if (request.url.includes('upload') || 
                    request.url.includes('multipart')) {
                    await cache.delete(request);
                    logSW('🗑️ Upload cache removed:', request.url);
                }
            }
        }
        
        logSW('✅ Upload cache cleared');
    } catch (error) {
        logSW('❌ Error clearing upload cache:', error);
    }
}

// Log final
logSW('🚀 Service Worker carregado com CORREÇÃO DE UPLOAD');
logSW('📋 Configurações:', CONFIG);
logSW('🚫 Never intercept patterns:', NEVER_INTERCEPT_PATTERNS.length);
logSW('✅ Upload operations will bypass Service Worker completely');