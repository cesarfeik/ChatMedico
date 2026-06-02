<?php
/**
 * PLADIEX Expediente Médico — Configuración
 * Lee variables de entorno (Vercel) o usa valores vacíos como fallback.
 * Las keys reales se configuran en: Vercel Dashboard → Settings → Environment Variables
 * Seguro de commitear: no contiene secretos.
 */

// Lee una variable de entorno buscando en getenv(), $_ENV y $_SERVER
function env(string $key, string $default = ''): string {
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? $default));
}

// ── OpenAI ─────────────────────────────────────────────────────────────────
define('OPENAI_API_KEY',     env('OPENAI_API_KEY'));
define('OPENAI_CHAT_MODEL',  env('OPENAI_CHAT_MODEL',  'gpt-4o-mini'));
define('OPENAI_EMBED_MODEL', env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'));
define('OPENAI_VISION_MODEL',env('OPENAI_VISION_MODEL','gpt-4o'));

// ── Pinecone ────────────────────────────────────────────────────────────────
define('PINECONE_API_KEY',    env('PINECONE_API_KEY'));
define('PINECONE_INDEX_HOST', env('PINECONE_INDEX_HOST'));
define('PINECONE_NAMESPACE',  env('PINECONE_NAMESPACE', 'expediente-medico'));

// ── Supabase ────────────────────────────────────────────────────────────────
define('SUPABASE_URL',              env('SUPABASE_URL'));
define('SUPABASE_ANON_KEY',         env('SUPABASE_ANON_KEY'));
define('SUPABASE_JWT_SECRET',       env('SUPABASE_JWT_SECRET'));
define('SUPABASE_SERVICE_ROLE_KEY', env('SUPABASE_SERVICE_ROLE_KEY'));
define('SUPABASE_BUCKET',           env('SUPABASE_BUCKET', 'expedientes'));

// ── RAG ─────────────────────────────────────────────────────────────────────
define('RAG_TOP_K',         (int) env('RAG_TOP_K',         '5'));
define('RAG_CHUNK_SIZE',    (int) env('RAG_CHUNK_SIZE',    '500'));
define('RAG_CHUNK_OVERLAP', (int) env('RAG_CHUNK_OVERLAP', '50'));

// Tiempo de vida (segundos) de las URLs firmadas para ver/descargar archivos
define('SIGNED_URL_TTL', (int) env('SIGNED_URL_TTL', '300'));

// ── CORS ─────────────────────────────────────────────────────────────────────
define('ALLOWED_ORIGIN', env('ALLOWED_ORIGIN', '*'));
