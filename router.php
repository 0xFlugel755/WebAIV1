<?php
/**
 * router.php — Roteador do servidor PHP built-in
 * Uso: php -S localhost:8000 router.php
 *
 * ESTRUTURA ESPERADA (escolha uma):
 *   Opção A — chat.php na raiz:   ./chat.php
 *   Opção B — chat.php em src/:   ./src/chat.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function serveMime(string $filePath): void
{
    $mimes = [
        'html' => 'text/html; charset=UTF-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
    ];
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    readfile($filePath);
}

function jsonError(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
}

// ─── Localiza o chat.php (uma vez, reutilizado em /debug e /chat) ─────────────

$chatFile = null;
foreach ([__DIR__ . '/src/chat.php', __DIR__ . '/chat.php'] as $candidate) {
    if (is_file($candidate)) {
        $chatFile = $candidate;
        break;
    }
}

// ─── /debug ───────────────────────────────────────────────────────────────────

if ($uri === '/debug') {
    header('Content-Type: application/json; charset=utf-8');

    $envPath    = __DIR__ . '/.env';
    $envContent = is_file($envPath) ? file_get_contents($envPath) : '';

    echo json_encode([
        'php_version'  => PHP_VERSION,
        'php_ok'       => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'OK' : 'ERRO — precisa PHP 8.1+',
        'curl'         => function_exists('curl_init') ? 'OK' : 'ERRO — instale a extensão cURL',
        'vendor'       => is_dir(__DIR__ . '/vendor') ? 'OK' : 'FALTANDO — rode: composer install',
        'env'          => is_file($envPath) ? 'OK (' . $envPath . ')' : 'FALTANDO — crie .env na raiz',
        'hf_token'     => str_contains($envContent, 'HF_TOKEN=') ? 'configurado' : 'FALTANDO no .env',
        'chat_php'     => $chatFile ?? 'FALTANDO — coloque chat.php na raiz ou em src/',
        'index_html'   => is_file(__DIR__ . '/public/index.html') ? 'public/index.html'
                        : (is_file(__DIR__ . '/index.html') ? 'index.html (raiz)' : 'FALTANDO'),
        'memory_dir'   => is_dir(__DIR__ . '/memory') ? 'OK' : 'será criado no 1º uso',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    return true;
}

// ─── /chat ────────────────────────────────────────────────────────────────────
// IMPORTANTE: esta rota DEVE vir antes do bloco de arquivos estáticos.
// Se chat.php não for encontrado, retorna JSON de erro — nunca HTML.

if ($uri === '/chat' || $uri === '/chat.php') {
    if ($chatFile === null) {
        jsonError(500,
            'chat.php não encontrado. ' .
            'Coloque o arquivo em uma dessas localizações: ' .
            implode(' OU ', [__DIR__ . '/src/chat.php', __DIR__ . '/chat.php'])
        );
        return true;
    }

    // Inclui o chat.php; ele cuida do próprio output (ob_start + json)
    require $chatFile;
    return true;
}

// ─── / (index.html) ───────────────────────────────────────────────────────────

if ($uri === '/' || $uri === '') {
    $indexFile = is_file(__DIR__ . '/public/index.html')
        ? __DIR__ . '/public/index.html'
        : __DIR__ . '/index.html';

    if (!is_file($indexFile)) {
        jsonError(500, 'index.html não encontrado. Acesse /debug para diagnóstico.');
        return true;
    }

    header('Content-Type: text/html; charset=UTF-8');
    readfile($indexFile);
    return true;
}

// ─── Arquivos estáticos (css, js, imagens…) ───────────────────────────────────
// Só chega aqui para URIs que NÃO são /chat — evita o bug de servir HTML no lugar do JSON.

foreach ([__DIR__ . '/public' . $uri, __DIR__ . $uri] as $staticPath) {
    if (is_file($staticPath)) {
        serveMime($staticPath);
        return true;
    }
}

// ─── 404 ─────────────────────────────────────────────────────────────────────

jsonError(404, 'Não encontrado: ' . $uri);