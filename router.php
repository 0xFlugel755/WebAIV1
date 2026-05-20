<?php 
// ── Rota de diagnóstico ──────────────────────────────────────────────────────
if ($uri === '/debug') {
    header('Content-Type: application/json');
    
    $envPath = __DIR__ . '/.env';
    $hasEnv = file_exists($envPath);
    $hfTokenSet = false;
    
    if ($hasEnv) {
        $envContent = file_get_contents($envPath);
        $hfTokenSet = str_contains($envContent, 'HF_TOKEN=');
    }

    $chatPhpPath = is_file(__DIR__ . '/src/chat.php')  ? 'src/chat.php'
                 : (is_file(__DIR__ . '/chat.php') ? 'chat.php (raiz)' : 'FALTANDO');
    $indexPath   = is_file(__DIR__ . '/public/index.html') ? 'public/index.html'
                 : (is_file(__DIR__ . '/index.html') ? 'index.html (raiz)' : 'FALTANDO');

    echo json_encode([
        'php'          => PHP_VERSION,
        'curl'         => function_exists('curl_init') ? 'ok' : 'FALTANDO',
        'vendor'       => is_dir(__DIR__ . '/vendor')    ? 'ok' : 'FALTANDO — rode: composer install',
        'env'          => $hasEnv ? 'ok' : 'FALTANDO — copie .env.example para .env',
        'index_html'   => $indexPath,
        'chat_php'     => $chatPhpPath,
        'huggingface'  => $hfTokenSet ? 'Token configurado' : 'PENDENTE — configure HF_TOKEN no .env',
    ], JSON_PRETTY_PRINT);
    return true;
}