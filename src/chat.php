<?php
/**
 * chat.php — Endpoint da API do Dollynho
 * Coloque em: src/chat.php  OU  na raiz do projeto
 * Servidor: php -S localhost:8000 router.php
 */

// ══════════════════════════════════════════════════════════════════
// BLOCO 1 — Captura de output ANTES de qualquer include/require
// ob_start DEVE ser a instrução absolutamente mais primeira.
// ══════════════════════════════════════════════════════════════════
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Registra um shutdown handler para capturar fatal errors
// que escapam do try/catch (ex: parse error num include)
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'Fatal error PHP: ' . $err['message']
                     . ' em ' . $err['file'] . ':' . $err['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

// ══════════════════════════════════════════════════════════════════
// BLOCO 2 — Funções de resposta
// ══════════════════════════════════════════════════════════════════

function emitJson(array $payload): void
{
    // Descarta TUDO que estiver no buffer (warnings, notices, etc.)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

function apiError(string $msg): void   { emitJson(['error' => $msg]); }
function apiReply(string $reply): void { emitJson(['reply' => $reply]); }

// ══════════════════════════════════════════════════════════════════
// BLOCO 3 — Bootstrap: vendor + .env
// ══════════════════════════════════════════════════════════════════

// Tenta carregar o autoload do Composer
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
$autoloadLoaded = false;
foreach ($autoloadCandidates as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloadLoaded = true;
        break;
    }
}
if (!$autoloadLoaded) {
    apiError(
        'Composer vendor/ não encontrado. ' .
        'Rode "composer install" na raiz do projeto. ' .
        'Candidatos testados: ' . implode(', ', $autoloadCandidates)
    );
}

// Tenta carregar o .env
$envCandidates = [
    __DIR__ . '/../.env',
    __DIR__ . '/.env',
];
$envLoaded = false;
foreach ($envCandidates as $envPath) {
    if (file_exists($envPath)) {
        try {
            Dotenv\Dotenv::createImmutable(dirname($envPath))->load();
            $envLoaded = true;
        } catch (Throwable $e) {
            apiError('Erro ao carregar .env (' . $envPath . '): ' . $e->getMessage());
        }
        break;
    }
}
if (!$envLoaded) {
    apiError(
        '.env não encontrado. Crie o arquivo .env na raiz com: HF_TOKEN=hf_seu_token_aqui. ' .
        'Candidatos testados: ' . implode(', ', $envCandidates)
    );
}

// ══════════════════════════════════════════════════════════════════
// BLOCO 4 — Leitura da requisição
// ══════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Apenas POST é aceito neste endpoint.');
}

$rawInput = file_get_contents('php://input');
if ($rawInput === false || trim($rawInput) === '') {
    apiError('Corpo da requisição está vazio.');
}

$body = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    apiError('JSON inválido na requisição: ' . json_last_error_msg());
}

// Suporte ao botão Reiniciar (clearHistory)
$clearHistory = (bool)($body['clearHistory'] ?? false);

$userMessage = trim($body['message'] ?? '');
if (!$clearHistory && $userMessage === '') {
    apiError('Mensagem vazia.');
}

// ══════════════════════════════════════════════════════════════════
// BLOCO 5 — Configuração da API HuggingFace
// ══════════════════════════════════════════════════════════════════

$hfToken     = trim(trim($_ENV['HF_TOKEN']      ?? ''), '"\'');
$hfBaseUrl   = trim(trim($_ENV['HF_MODEL_URL']  ?? 'https://router.huggingface.co/v1'), '"\'');
$hfModelName = trim(trim($_ENV['HF_MODEL_NAME'] ?? 'Qwen/Qwen3-235B-A22B'), '"\'');

if ($hfToken === '') {
    apiError(
        'HF_TOKEN não está definido no .env. ' .
        'Acesse huggingface.co/settings/tokens, crie um token com ' .
        '"Make calls to Inference Providers" e adicione ao .env.'
    );
}

if (!str_starts_with($hfBaseUrl, 'https://')) {
    $hfBaseUrl = 'https://router.huggingface.co/v1';
}

$hfEndpoint = rtrim($hfBaseUrl, '/') . '/chat/completions';

// ══════════════════════════════════════════════════════════════════
// BLOCO 6 — Histórico de conversa (arquivo JSON)
// ══════════════════════════════════════════════════════════════════

function resolveHistoryPath(): string
{
    $dirs = [
        __DIR__ . '/../memory',
        __DIR__ . '/memory',
        sys_get_temp_dir() . '/dollynho',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir . '/chat_history.json';
        }
    }
    // Último recurso: arquivo temporário
    return tempnam(sys_get_temp_dir(), 'dollynho_') . '.json';
}

function loadHistory(string $path): array
{
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveHistory(string $path, array $history): void
{
    // Janela deslizante: mantém as últimas 20 mensagens
    if (count($history) > 20) {
        $history = array_slice($history, -20);
    }
    file_put_contents($path, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$historyPath = resolveHistoryPath();

// Processar reinício de chat
if ($clearHistory) {
    file_put_contents($historyPath, '[]', LOCK_EX);
    emitJson(['cleared' => true]);
}

// ══════════════════════════════════════════════════════════════════
// BLOCO 7 — Chamada à API HuggingFace
// ══════════════════════════════════════════════════════════════════

define('SYSTEM_PROMPT',
    'Você é o Dollynho, um mentor especialista em Desenvolvimento Web. ' .
    'Ajude com PHP, HTML, CSS e JavaScript de forma simples e didática. ' .
    'Sempre formate código com blocos Markdown (use ``` com o nome da linguagem). ' .
    'Responda sempre em português brasileiro. ' .
    'Seja direto e objetivo, sem enrolação.'
);

$history   = loadHistory($historyPath);
$history[] = ['role' => 'user', 'content' => $userMessage];

$messages = array_merge(
    [['role' => 'system', 'content' => SYSTEM_PROMPT]],
    $history
);

$payload = json_encode([
    'model'       => $hfModelName,
    'messages'    => $messages,
    'max_tokens'  => 1024,
    'temperature' => 0.7,
    'stream'      => false,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

// Executa o cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $hfEndpoint,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $hfToken,
    ],
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
]);

$curlResponse = curl_exec($ch);
$httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError    = curl_error($ch);
curl_close($ch);

// ── Erros de transporte ────────────────────────────────────────────
if ($curlError !== '') {
    apiError('Erro de conexão cURL: ' . $curlError . ' | Endpoint: ' . $hfEndpoint);
}
if ($curlResponse === false || $curlResponse === '') {
    apiError('A API retornou resposta vazia. Código HTTP: ' . $httpCode);
}

$curlResponse = (string) $curlResponse;

// ── Erros HTTP ────────────────────────────────────────────────────
if ($httpCode !== 200) {
    $errData = json_decode($curlResponse, true) ?? [];
    $apiMsg  = $errData['error']['message'] ?? $errData['error'] ?? substr($curlResponse, 0, 300);

    $httpMessages = [
        401 => 'Token inválido (401). Verifique HF_TOKEN e a permissão "Make calls to Inference Providers" em huggingface.co/settings/tokens.',
        400 => 'Requisição inválida (400): ' . $apiMsg,
        404 => 'Modelo não encontrado (404). Modelo: ' . $hfModelName . ' | Endpoint: ' . $hfEndpoint,
        429 => 'Rate limit atingido (429). Aguarde alguns segundos.',
        503 => 'Modelo ainda carregando (503). Tente novamente em instantes.',
    ];

    apiError($httpMessages[$httpCode] ?? 'HuggingFace HTTP ' . $httpCode . ': ' . $apiMsg);
}

// ══════════════════════════════════════════════════════════════════
// BLOCO 8 — Extração da resposta
// ══════════════════════════════════════════════════════════════════

$decoded = json_decode($curlResponse, true);
if (!is_array($decoded)) {
    apiError('A API retornou JSON inválido: ' . substr($curlResponse, 0, 300));
}

$content = $decoded['choices'][0]['message']['content']
        ?? $decoded['generated_text']
        ?? null;

if ($content === null) {
    apiError('API respondeu 200 mas sem conteúdo. Resposta: ' . substr($curlResponse, 0, 300));
}

// Remove blocos <think>...</think> do Qwen3 (raciocínio interno)
$botReply = trim(preg_replace('/<think>.*?<\/think>/s', '', (string) $content));
if ($botReply === '') {
    $botReply = 'Desculpe, não consegui gerar uma resposta. Tente reformular a pergunta.';
}

// ══════════════════════════════════════════════════════════════════
// BLOCO 9 — Salva histórico e responde
// ══════════════════════════════════════════════════════════════════

$history[] = ['role' => 'assistant', 'content' => $botReply];
saveHistory($historyPath, $history);
apiReply($botReply);