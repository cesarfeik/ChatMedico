<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
/**
 * PLADIEX Expediente Médico — Chat clínico autenticado (RBAC + RAG por paciente)
 * =============================================================================
 * Método:  POST
 * Headers: Authorization: Bearer <supabase_access_token>  (doctor)
 * Body:    { message, history: [{role, content}] }
 * Retorna: { reply, need_id?, patient?: {code,name}, sources: [{filename,doc_type,url,document_id}] }
 *
 * Flujo:
 *  1. Valida el JWT y que el usuario sea doctor.
 *  2. Extrae el ID del paciente del mensaje (PAC-#####). Si falta, lo pide.
 *  3. Verifica que el doctor tenga asignado a ese paciente (autoritativo, service role).
 *  4. Busca en Pinecone filtrando por patient_id (aislamiento por paciente).
 *  5. Compone la respuesta con GPT y devuelve las fuentes (URLs firmadas).
 */

require_once __DIR__ . '/../lib.php';

sendCors('POST');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

$jwt = requireAuth();
$uid = $jwt['sub'];

// ─── Solo doctores ──────────────────────────────────────────────────────────────
$doc = supabaseRest('GET', "doctors?user_id=eq.{$uid}&select=user_id,full_name");
if ($doc['code'] >= 400 || empty($doc['json'])) {
    jsonError('Acceso restringido a personal médico', 403);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$message = trim($body['message'] ?? '');
$history = $body['history'] ?? [];
if ($message === '') jsonError('Mensaje vacío');

// ─── 1. Extraer ID del paciente ───────────────────────────────────────────────────
$patientCode = extractPatientCode($message);
if (!$patientCode) {
    echo json_encode([
        'reply'   => 'Para consultar un expediente necesito el **ID del paciente** (por ejemplo: PAC-00123). ¿Me lo compartes junto con lo que deseas revisar?',
        'need_id' => true,
        'sources' => [],
    ]);
    exit;
}

// ─── 2. Resolver paciente ──────────────────────────────────────────────────────────
$pat = supabaseRest('GET', "patients?patient_code=eq.{$patientCode}&select=id,patient_code,full_name");
if ($pat['code'] >= 400 || empty($pat['json'])) {
    echo json_encode([
        'reply'   => "No encontré ningún paciente con el ID **{$patientCode}**. Verifica el identificador e inténtalo de nuevo.",
        'sources' => [],
    ]);
    exit;
}
$patient   = $pat['json'][0];
$patientId = $patient['id'];

// ─── 3. RBAC: ¿el doctor tiene asignado a este paciente? ──────────────────────────
$assign = supabaseRest('GET', "doctor_patient?doctor_user_id=eq.{$uid}&patient_id=eq.{$patientId}&select=patient_id");
if ($assign['code'] >= 400 || empty($assign['json'])) {
    echo json_encode([
        'reply'   => "No tienes asignado al paciente **{$patientCode}**, por lo que no puedo mostrarte su información. Si crees que es un error, contacta al administrador.",
        'sources' => [],
    ]);
    exit;
}

// ─── 4. RAG filtrado por paciente ──────────────────────────────────────────────────
$embedding = openaiEmbedding($message);
if (!$embedding) jsonError('Error al procesar la consulta', 500);

$matches = pineconeQueryForPatient($embedding, $patientId, RAG_TOP_K);

$contextParts = [];
$docs = []; // document_id => {filename, doc_type, storage_path}
foreach ($matches as $m) {
    $md = $m['metadata'] ?? [];
    if (!empty($md['text'])) {
        $label = $md['filename'] ?? 'documento';
        $contextParts[] = "[Fuente: {$label}]\n" . $md['text'];
    }
    $did = $md['document_id'] ?? null;
    if ($did && !isset($docs[$did])) {
        $docs[$did] = [
            'document_id'  => $did,
            'filename'     => $md['filename'] ?? 'documento',
            'doc_type'     => $md['doc_type'] ?? 'txt',
            'storage_path' => $md['storage_path'] ?? '',
        ];
    }
}
$context = implode("\n\n---\n\n", $contextParts);

if ($context === '') {
    echo json_encode([
        'reply'   => "El paciente **{$patientCode}**" . (!empty($patient['full_name']) ? " ({$patient['full_name']})" : '') . " no tiene documentos que coincidan con tu consulta en su expediente. Pídele que suba el estudio o documento correspondiente.",
        'patient' => ['code' => $patientCode, 'name' => $patient['full_name'] ?? ''],
        'sources' => [],
    ]);
    exit;
}

// ─── 5. Componer respuesta ──────────────────────────────────────────────────────────
$reply = composeClinicalAnswer($message, $history, $context, $patient);

// ─── 6. Fuentes con URLs firmadas ────────────────────────────────────────────────────
$sources = [];
foreach ($docs as $d) {
    $url = $d['storage_path'] ? storageSignedUrl($d['storage_path'], SIGNED_URL_TTL) : null;
    $sources[] = [
        'document_id' => $d['document_id'],
        'filename'    => $d['filename'],
        'doc_type'    => $d['doc_type'],
        'url'         => $url,
    ];
}

echo json_encode([
    'reply'   => $reply,
    'patient' => ['code' => $patientCode, 'name' => $patient['full_name'] ?? ''],
    'sources' => $sources,
]);


// ═══════════════════════════════════════════════════════════════════════════════════
// FUNCIONES
// ═══════════════════════════════════════════════════════════════════════════════════

/** Extrae y normaliza un ID de paciente del mensaje. Formato: PAC-#####. */
function extractPatientCode(string $message): ?string {
    if (!preg_match('/PAC[-\s]?0*(\d{1,8})/i', $message, $m)) return null;
    return 'PAC-' . str_pad($m[1], 5, '0', STR_PAD_LEFT);
}

function composeClinicalAnswer(string $message, array $history, string $context, array $patient): string {
    $code = $patient['patient_code'] ?? '';
    $systemPrompt = <<<PROMPT
Eres el Asistente Clínico de PLADIEX, una herramienta de apoyo para médicos.
Estás consultando el expediente del paciente con ID {$code}.

IMPORTANTE: Los fragmentos del contexto provienen de los documentos que hay en el expediente de ESTE paciente. Trátalos siempre como información de este paciente, AUNQUE el nombre que aparezca dentro de un documento sea distinto del registrado (puede haber diferencias o errores de captura). NUNCA digas que la información pertenece a "otro paciente" ni rechaces datos que sí están en el contexto por una discrepancia de nombre.

Reglas:
1. Responde en español, de forma clara, profesional y concisa.
2. Básate SOLO en el contexto. Si un dato puntual no aparece, dilo; no inventes valores.
3. Al citar resultados o datos, menciona de qué documento provienen (aparece como [Fuente: archivo]).
4. No emitas diagnósticos definitivos ni indiques tratamientos: ofreces apoyo informativo al médico, quien toma las decisiones.
5. Sé breve (máximo 3-4 párrafos salvo que se pida más detalle).

Contexto (fragmentos del expediente del paciente):
{$context}
PROMPT;

    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach (array_slice($history, -8) as $turn) {
        if (!empty($turn['role']) && !empty($turn['content'])) {
            $messages[] = [
                'role'    => in_array($turn['role'], ['user', 'assistant']) ? $turn['role'] : 'user',
                'content' => $turn['content'],
            ];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    return openaiChat($messages, 0.3, 700);
}
