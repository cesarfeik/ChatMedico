<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
/**
 * PLADIEX Expediente Médico — Procesar documento de un paciente e indexar en Pinecone
 * ==================================================================================
 * Método:  POST
 * Headers: Authorization: Bearer <supabase_access_token>  (paciente dueño del expediente)
 * Body:    { patient_id, filename, doc_type, storage_path, text? }
 *          - PDF/DOCX/TXT: el cliente envía "text" ya extraído.
 *          - image: sin "text" → el servidor descarga el archivo y usa OpenAI Vision (OCR).
 * Retorna: { success, document_id, safe_prefix, chunks }
 */

require_once __DIR__ . '/../lib.php';

sendCors('POST');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

$jwt = requireAuth();
$uid = $jwt['sub'];

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$patientId   = trim($body['patient_id']   ?? '');
$filename    = trim($body['filename']     ?? '');
$docType     = strtolower(trim($body['doc_type'] ?? ''));
$storagePath = trim($body['storage_path'] ?? '');
$text        = $body['text'] ?? '';

if ($patientId === '' || $filename === '') jsonError('Faltan campos: patient_id y filename');

// ─── Verificar que el paciente pertenece a este usuario (autoritativo) ──────────
$check = supabaseRest('GET', "patients?id=eq.{$patientId}&user_id=eq.{$uid}&select=id,patient_code");
if ($check['code'] >= 400 || empty($check['json'])) {
    jsonError('No autorizado para este expediente', 403);
}

// ─── Imágenes: OCR con OpenAI Vision ────────────────────────────────────────────
$isImage = $docType === 'image' || preg_match('/\.(jpe?g|png|webp|gif|bmp|heic)$/i', $filename);
if (trim($text) === '' && $isImage) {
    if ($storagePath === '') jsonError('Falta storage_path para procesar la imagen');
    [$bytes, $mime] = storageDownload($storagePath);
    if ($bytes === null) jsonError('No se pudo leer la imagen del almacenamiento', 500);
    $text = openaiVisionOcr($bytes, $mime ?: 'image/jpeg');
    $docType = 'image';
}

$text = trim($text);
if (strlen($text) < 10) {
    jsonError('No se pudo extraer texto suficiente del documento (imagen ilegible o archivo vacío)');
}

// ─── Fragmentar ─────────────────────────────────────────────────────────────────
$chunks = splitIntoChunks($text, RAG_CHUNK_SIZE, RAG_CHUNK_OVERLAP);
if (empty($chunks)) jsonError('No se pudieron generar fragmentos del texto');

// ─── IDs ─────────────────────────────────────────────────────────────────────────
$documentId = uuidv4();
$safeFile   = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
$safePrefix = $patientId . '_' . substr($documentId, 0, 8) . '_' . $safeFile;

// ─── Embeddings + vectores ────────────────────────────────────────────────────────
$vectors = [];
foreach ($chunks as $i => $chunk) {
    $embedding = openaiEmbedding($chunk);
    if (!$embedding) { error_log("PLADIEX-med: fallo embedding chunk {$i} de {$filename}"); continue; }
    $vectors[] = [
        'id'       => $safePrefix . '_chunk_' . $i,
        'values'   => $embedding,
        'metadata' => [
            'text'         => $chunk,
            'patient_id'   => $patientId,
            'document_id'  => $documentId,
            'filename'     => $filename,
            'doc_type'     => $docType ?: 'txt',
            'storage_path' => $storagePath,
            'chunk'        => $i,
        ],
    ];
}
if (empty($vectors)) jsonError('No se pudo crear ningún embedding. Verifica la API key de OpenAI', 500);

// ─── Guardar en Pinecone (lotes de 100) ─────────────────────────────────────────
foreach (array_chunk($vectors, 100) as $batch) {
    if (!pineconeUpsert($batch)) jsonError('Error al guardar en Pinecone', 500);
}

// ─── Registrar en Supabase (service role) ────────────────────────────────────────
$preview = mb_substr($text, 0, 4000);
$row = supabaseRest('POST', 'medical_documents',
    [[
        'id'              => $documentId,
        'patient_id'      => $patientId,
        'filename'        => $filename,
        'doc_type'        => $docType ?: 'txt',
        'safe_prefix'     => $safePrefix,
        'chunks'          => count($vectors),
        'storage_path'    => $storagePath,
        'uploaded_by'     => $jwt['email'] ?? '',
        'content_preview' => $preview,
    ]],
    ['Prefer' => 'return=representation']
);
if ($row['code'] >= 400) {
    error_log('PLADIEX-med: error insertando medical_documents — ' . $row['raw']);
    jsonError('Documento indexado pero no se pudo registrar en la base de datos', 500);
}

echo json_encode([
    'success'     => true,
    'document_id' => $documentId,
    'safe_prefix' => $safePrefix,
    'chunks'      => count($vectors),
    'doc_type'    => $docType ?: 'txt',
]);


// ─── util ─────────────────────────────────────────────────────────────────────────
function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
