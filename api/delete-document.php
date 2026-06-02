<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
/**
 * PLADIEX Expediente Médico — Eliminar documento
 * ==============================================
 * Borra: vectores en Pinecone + archivo en Storage + fila en medical_documents.
 * Método:  DELETE
 * Headers: Authorization: Bearer <supabase_access_token>  (paciente dueño)
 * Body:    { document_id }
 * Retorna: { success, deleted }
 */

require_once __DIR__ . '/../lib.php';

sendCors('DELETE');
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Método no permitido', 405);

$jwt = requireAuth();
$uid = $jwt['sub'];

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$documentId = trim($body['document_id'] ?? '');
if ($documentId === '') jsonError('Falta document_id');

// ─── Cargar el documento ────────────────────────────────────────────────────────
$res = supabaseRest('GET', "medical_documents?id=eq.{$documentId}&select=id,patient_id,safe_prefix,chunks,storage_path");
if ($res['code'] >= 400 || empty($res['json'])) jsonError('Documento no encontrado', 404);
$doc       = $res['json'][0];
$patientId = $doc['patient_id'];

// ─── Verificar que el paciente pertenece a este usuario ────────────────────────────
$own = supabaseRest('GET', "patients?id=eq.{$patientId}&user_id=eq.{$uid}&select=id");
if ($own['code'] >= 400 || empty($own['json'])) jsonError('No autorizado para este documento', 403);

// ─── 1. Borrar vectores de Pinecone ────────────────────────────────────────────────
$chunks = (int) $doc['chunks'];
$ids = [];
for ($i = 0; $i < $chunks; $i++) $ids[] = $doc['safe_prefix'] . '_chunk_' . $i;
$deleted = 0;
foreach (array_chunk($ids, 100) as $batch) {
    if (pineconeDelete($batch)) $deleted += count($batch);
}

// ─── 2. Borrar archivo de Storage ───────────────────────────────────────────────────
if (!empty($doc['storage_path'])) {
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/' . SUPABASE_BUCKET . '/' . ltrim($doc['storage_path'], '/');
    httpRequest('DELETE', $url, [
        'apikey'        => SUPABASE_SERVICE_ROLE_KEY,
        'Authorization' => 'Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
    ]);
}

// ─── 3. Borrar la fila ────────────────────────────────────────────────────────────────
$del = supabaseRest('DELETE', "medical_documents?id=eq.{$documentId}");
if ($del['code'] >= 400) jsonError('Error al borrar el registro', 500);

echo json_encode(['success' => true, 'deleted' => $deleted]);
