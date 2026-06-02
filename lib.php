<?php
/**
 * PLADIEX Expediente Médico — Librería compartida
 * Funciones reutilizadas por los endpoints de api/.
 * require_once __DIR__ . '/../lib.php';  (lib.php está en la raíz, junto a config.php)
 */

require_once __DIR__ . '/config.php';

// ─── CORS ────────────────────────────────────────────────────────────────────
function sendCors(string $methods): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: ' . $methods . ', OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

function jsonError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// ─── Auth (JWT de Supabase) ────────────────────────────────────────────────────
function getBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';
    if (empty($header) && function_exists('apache_request_headers')) {
        $all    = apache_request_headers();
        $header = $all['Authorization'] ?? $all['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return $m[1];
    return null;
}

function b64urlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}
function b64urlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Verifica y decodifica el JWT de Supabase.
 * Si SUPABASE_JWT_SECRET está configurado, valida la firma HS256 (recomendado).
 * Si no, hace una validación básica (exp + iss) como fallback de desarrollo.
 * Devuelve el payload (array) o null si es inválido.
 */
function verifyJwt(?string $token): ?array {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;

    $payload = json_decode(b64urlDecode($p), true);
    if (empty($payload)) return null;
    if (!empty($payload['exp']) && $payload['exp'] < time()) return null;

    if (SUPABASE_JWT_SECRET !== '') {
        $expected = b64urlEncode(hash_hmac('sha256', $h . '.' . $p, SUPABASE_JWT_SECRET, true));
        if (!hash_equals($expected, $s)) return null;
    } else {
        // Fallback sin firma: al menos validar el emisor
        $expectedIssuer = rtrim(SUPABASE_URL, '/') . '/auth/v1';
        if (!empty($payload['iss']) && $payload['iss'] !== $expectedIssuer) return null;
    }
    return $payload;
}

/** Exige un usuario autenticado; devuelve el payload del JWT o corta con 401. */
function requireAuth(): array {
    $payload = verifyJwt(getBearerToken());
    if (!$payload || empty($payload['sub'])) jsonError('No autorizado', 401);
    return $payload;
}

// ─── HTTP genérico ─────────────────────────────────────────────────────────────
function httpRequest(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    $headerLines = [];
    foreach ($headers as $k => $v) $headerLines[] = "{$k}: {$v}";

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headerLines,
        CURLOPT_TIMEOUT        => 90,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
    }
    curl_setopt_array($ch, $opts);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'raw' => $raw, 'json' => json_decode($raw ?? '', true)];
}

function httpPostJson(string $url, array $headers, array $data): array {
    $headers['Content-Type'] = 'application/json';
    $res = httpRequest('POST', $url, $headers, $data);
    if ($res['code'] >= 400 || $res['raw'] === false) {
        error_log("PLADIEX-med — POST {$url} HTTP {$res['code']} — {$res['raw']}");
        return [];
    }
    return $res['json'] ?? [];
}

// ─── OpenAI ──────────────────────────────────────────────────────────────────
function openaiEmbedding(string $text): ?array {
    $r = httpPostJson('https://api.openai.com/v1/embeddings',
        ['Authorization' => 'Bearer ' . OPENAI_API_KEY],
        ['model' => OPENAI_EMBED_MODEL, 'input' => $text]);
    return $r['data'][0]['embedding'] ?? null;
}

function openaiChat(array $messages, float $temp = 0.3, int $maxTokens = 700): string {
    $r = httpPostJson('https://api.openai.com/v1/chat/completions',
        ['Authorization' => 'Bearer ' . OPENAI_API_KEY],
        ['model' => OPENAI_CHAT_MODEL, 'messages' => $messages,
         'temperature' => $temp, 'max_tokens' => $maxTokens]);
    return $r['choices'][0]['message']['content'] ?? 'Lo siento, ocurrió un error al generar la respuesta.';
}

/** OCR/transcripción de una imagen (bytes) usando OpenAI Vision. */
function openaiVisionOcr(string $imageBytes, string $mime): string {
    $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);
    $instruction = 'Eres un asistente que transcribe documentos clínicos. '
        . 'Extrae TODO el texto y los datos visibles de esta imagen (resultados de laboratorio, '
        . 'valores, unidades, fechas, nombres de estudios, indicaciones, etc.) de forma fiel y estructurada. '
        . 'Si hay tablas de resultados, transcríbelas como "parámetro: valor unidad (rango)". '
        . 'No interpretes ni diagnostiques, solo transcribe. Responde solo con el texto extraído.';
    $r = httpPostJson('https://api.openai.com/v1/chat/completions',
        ['Authorization' => 'Bearer ' . OPENAI_API_KEY],
        [
            'model' => OPENAI_VISION_MODEL,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $instruction],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ]],
            'temperature' => 0.0,
            'max_tokens'  => 1500,
        ]);
    return trim($r['choices'][0]['message']['content'] ?? '');
}

// ─── Pinecone ────────────────────────────────────────────────────────────────
function pineconeUpsert(array $vectors): bool {
    $r = httpPostJson(PINECONE_INDEX_HOST . '/vectors/upsert',
        ['Api-Key' => PINECONE_API_KEY],
        ['vectors' => $vectors, 'namespace' => PINECONE_NAMESPACE]);
    return isset($r['upsertedCount']);
}

/** Consulta filtrada por patient_id. Devuelve matches con metadata. */
function pineconeQueryForPatient(array $vector, string $patientId, int $topK): array {
    $r = httpPostJson(PINECONE_INDEX_HOST . '/query',
        ['Api-Key' => PINECONE_API_KEY],
        [
            'vector'          => $vector,
            'topK'            => $topK,
            'includeMetadata' => true,
            'namespace'       => PINECONE_NAMESPACE,
            'filter'          => ['patient_id' => ['$eq' => $patientId]],
        ]);
    return $r['matches'] ?? [];
}

function pineconeDelete(array $ids): bool {
    $r = httpRequest('POST', PINECONE_INDEX_HOST . '/vectors/delete',
        ['Api-Key' => PINECONE_API_KEY, 'Content-Type' => 'application/json'],
        ['ids' => $ids, 'namespace' => PINECONE_NAMESPACE]);
    return $r['code'] < 400;
}

// ─── Texto → chunks ────────────────────────────────────────────────────────────
function splitIntoChunks(string $text, int $size, int $overlap): array {
    $text  = preg_replace('/\s+/', ' ', $text);
    $words = explode(' ', $text);
    $total = count($words);
    $chunks = [];
    $step   = max(1, $size - $overlap);
    for ($i = 0; $i < $total; $i += $step) {
        $slice = array_slice($words, $i, $size);
        if (empty($slice)) break;
        $chunk = trim(implode(' ', $slice));
        if (strlen($chunk) > 20) $chunks[] = $chunk;
    }
    return $chunks;
}

// ─── Supabase REST (service role — bypasea RLS, usar con verificación propia) ──
function supabaseRest(string $method, string $path, $body = null, array $extraHeaders = []): array {
    $headers = [
        'apikey'        => SUPABASE_SERVICE_ROLE_KEY,
        'Authorization' => 'Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Content-Type'  => 'application/json',
    ] + $extraHeaders;
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . ltrim($path, '/');
    return httpRequest($method, $url, $headers, $body);
}

// ─── Supabase Storage (service role) ──────────────────────────────────────────
/** Descarga el objeto del bucket privado. Devuelve [bytes, mime] o [null, null]. */
function storageDownload(string $path): array {
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/' . SUPABASE_BUCKET . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $bytes = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
    curl_close($ch);
    if ($code >= 400 || $bytes === false) return [null, null];
    return [$bytes, $mime];
}

/** Genera una URL firmada (temporal) para ver/descargar un objeto. */
function storageSignedUrl(string $path, int $ttl): ?string {
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/sign/' . SUPABASE_BUCKET . '/' . ltrim($path, '/');
    $r = httpPostJson($url,
        ['apikey' => SUPABASE_SERVICE_ROLE_KEY, 'Authorization' => 'Bearer ' . SUPABASE_SERVICE_ROLE_KEY],
        ['expiresIn' => $ttl]);
    if (empty($r['signedURL'])) return null;
    return rtrim(SUPABASE_URL, '/') . '/storage/v1' . $r['signedURL'];
}
