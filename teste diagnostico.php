<?php
/**
 * teste_diagnostico.php — Cola na RAIZ do projeto e acesse:
 * http://localhost:8000/teste_diagnostico.php
 *
 * Mostra EXATAMENTE o que está faltando sem depender do chat.php.
 */

header('Content-Type: application/json; charset=utf-8');
$r = [];

// PHP
$r['1_php_version'] = PHP_VERSION;
$r['1_php_ok']      = version_compare(PHP_VERSION, '8.1.0', '>=') ? 'OK' : 'ERRO — precisa PHP 8.1+';

// cURL
$r['2_curl'] = function_exists('curl_init') ? 'OK' : 'ERRO — extensão cURL não instalada';

// Composer vendor
$vendorCandidates = [__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'];
$r['3_vendor'] = 'ERRO — vendor/ não encontrado. Rode: composer install';
foreach ($vendorCandidates as $p) {
    if (file_exists($p)) { $r['3_vendor'] = 'OK: ' . $p; require_once $p; break; }
}

// .env
$envCandidates = [__DIR__ . '/.env', __DIR__ . '/../.env'];
$r['4_env'] = 'ERRO — .env não encontrado';
foreach ($envCandidates as $p) {
    if (file_exists($p)) {
        $r['4_env'] = 'OK: ' . $p;
        if (class_exists('Dotenv\Dotenv')) {
            try { Dotenv\Dotenv::createImmutable(dirname($p))->load(); }
            catch (Throwable $e) { $r['4_env_parse_error'] = $e->getMessage(); }
        }
        break;
    }
}

// Variáveis de ambiente
$token = trim(trim($_ENV['HF_TOKEN'] ?? ''), '"\'');
$r['5_HF_TOKEN']      = $token !== '' ? 'OK (começa com: ' . substr($token, 0, 8) . '...)' : 'ERRO — HF_TOKEN não definido no .env';
$r['5_HF_MODEL_URL']  = $_ENV['HF_MODEL_URL']  ?? '(não definido — usará padrão router.huggingface.co)';
$r['5_HF_MODEL_NAME'] = $_ENV['HF_MODEL_NAME'] ?? '(não definido — usará padrão Qwen/Qwen3-235B-A22B)';

// Diretório memory/
$memDirs = [__DIR__ . '/memory', __DIR__ . '/../memory'];
$r['6_memory_dir'] = 'AVISO — não existe ainda (será criado no 1º uso)';
foreach ($memDirs as $d) {
    if (is_dir($d)) { $r['6_memory_dir'] = 'OK: ' . $d . ' | writable: ' . (is_writable($d) ? 'sim' : 'NÃO'); break; }
}

// chat.php
$chatCandidates = [__DIR__ . '/src/chat.php', __DIR__ . '/chat.php'];
$r['7_chat_php'] = 'ERRO — chat.php não encontrado';
foreach ($chatCandidates as $p) {
    if (file_exists($p)) { $r['7_chat_php'] = 'OK: ' . $p; break; }
}

// Teste real de API (só se tiver token)
if ($token !== '') {
    $url   = rtrim($_ENV['HF_MODEL_URL'] ?? 'https://router.huggingface.co/v1', '/') . '/chat/completions';
    $model = trim(trim($_ENV['HF_MODEL_NAME'] ?? 'Qwen/Qwen3-235B-A22B'), '"\'');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => $model,
            'messages'   => [
                ['role' => 'system', 'content' => 'Responda apenas com a palavra: OK'],
                ['role' => 'user',   'content' => 'teste de conectividade'],
            ],
            'max_tokens' => 5,
            'stream'     => false,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp      = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    $r['8_api_http_code']  = $httpCode;
    $r['8_api_curl_error'] = $curlErr ?: 'nenhum';
    $r['8_api_raw_100']    = substr((string)$resp, 0, 200);

    $decoded = json_decode((string)$resp, true);
    $r['8_api_resultado']  = ($httpCode === 200 && isset($decoded['choices']))
        ? 'OK — API respondeu corretamente!'
        : 'ERRO — veja api_raw_100 acima';
} else {
    $r['8_api_test'] = 'PULADO — HF_TOKEN não definido';
}

echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);