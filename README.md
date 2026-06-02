# PLADIEX — Expediente Médico (Chatbot Clínico)

Segundo chatbot de PLADIEX, separado del público. Un **médico** consulta vía chat el historial clínico
**solo de sus pacientes asignados**; los **pacientes** suben sus documentos (PDF, Word, TXT e **imágenes/fotos**
de estudios) desde su perfil. Las respuestas del bot **citan los documentos fuente** con opción de ver/descargar.

Mismo stack que el bot público: **PHP serverless en Vercel + OpenAI + Pinecone + Supabase** (aquí se añade Supabase Storage).

---

## Arquitectura

```
api/process-document.php  → sube texto/embeddings a Pinecone (OCR de imágenes con OpenAI Vision)
api/chat.php              → chat del doctor: valida sesión, RBAC, RAG filtrado por paciente, devuelve fuentes
api/delete-document.php   → borra vectores + archivo de Storage + fila
lib.php / config.php      → librería y configuración compartidas (raíz)
chatbot/widget.js|css     → Asistente Clínico (autenticado)
js/config.js              → ÚNICO archivo a editar con URL + anon key de Supabase
doctor/                   → login + panel (lista de pacientes + widget)
patient/                  → login/registro + perfil (subir archivos + mis documentos)
supabase-setup.sql        → tablas, RLS y bucket
```

- **Aislamiento por paciente:** los vectores en Pinecone llevan `patient_id` en metadata; el chat filtra por ese ID.
- **RBAC:** `chat.php` verifica en el servidor (service role) que el doctor tenga asignado al paciente antes de responder.
- **Pinecone:** namespace nuevo `expediente-medico` (puede ser el mismo índice del bot público; 1536 dims).

---

## Puesta en marcha

### 1. Supabase
1. Crea un **proyecto nuevo** (recomendado, para aislar datos clínicos).
2. SQL Editor → pega y ejecuta `supabase-setup.sql` (crea tablas, RLS y el bucket privado `expedientes`).
3. Authentication → Providers → Email: **desactiva "Confirm email"** (para que el registro de pacientes
   tenga sesión inmediata en esta fase de demo).
4. Crea el/los usuario(s) **médico** en Authentication → Users. Luego, en SQL Editor, registra cada médico:
   ```sql
   insert into doctors (user_id, full_name, specialty, email)
     values ('<UUID_DEL_DOCTOR>', 'Dra. Ejemplo', 'Medicina General', 'doctor@demo.com');
   insert into profiles (user_id, role, full_name, email)
     values ('<UUID_DEL_DOCTOR>', 'doctor', 'Dra. Ejemplo', 'doctor@demo.com');
   ```
   (Los pacientes se registran solos desde `patient/` y eligen a su médico.)

### 2. Frontend
Edita **`js/config.js`** con la URL y la anon key de tu proyecto Supabase (Settings → API).

### 3. Vercel (proyecto nuevo)
Conecta este repo y define las **Environment Variables**:

| Variable | Valor |
|---|---|
| `OPENAI_API_KEY` | tu key de OpenAI |
| `OPENAI_CHAT_MODEL` | `gpt-4o-mini` |
| `OPENAI_EMBED_MODEL` | `text-embedding-3-small` |
| `OPENAI_VISION_MODEL` | `gpt-4o` |
| `PINECONE_API_KEY` | tu key de Pinecone |
| `PINECONE_INDEX_HOST` | host del índice (puede ser el mismo del bot público) |
| `PINECONE_NAMESPACE` | `expediente-medico` |
| `SUPABASE_URL` | URL del proyecto Supabase |
| `SUPABASE_ANON_KEY` | anon key |
| `SUPABASE_JWT_SECRET` | Settings → API → JWT Secret (**necesario** para validar firmas) |
| `SUPABASE_SERVICE_ROLE_KEY` | Settings → API → service_role (**secreto**, solo en Vercel) |
| `SUPABASE_BUCKET` | `expedientes` |
| `ALLOWED_ORIGIN` | tu dominio (o `*` en pruebas) |
| `RAG_TOP_K` / `RAG_CHUNK_SIZE` / `RAG_CHUNK_OVERLAP` | `5` / `500` / `50` |

Deploy. Listo.

---

## Prueba rápida (end-to-end)
1. **Paciente:** entra a `/patient/`, regístrate eligiendo al médico, anota tu **ID** (PAC-#####). Sube un PDF y una **foto** de un estudio.
2. **Doctor:** entra a `/doctor/`, verás al paciente en la lista. Abre el **Asistente Clínico** (botón inferior derecho).
3. Pregunta **sin ID** → debe pedirlo. Pregunta *"estudios de sangre del paciente PAC-00001"* → responde citando los documentos, con botones **Ver / descargar**.
4. Pregunta por un paciente **no asignado** → el bot niega el acceso.

---

## Migración a los portales reales de Pladiex
Cuando se tenga acceso a los portales de doctor/paciente, se reemplaza la capa de auth/datos
(login y tablas) **sin tocar Pinecone**: los embeddings y el namespace `expediente-medico` persisten.
Solo hay que mapear el `patient_id` de Pinecone al ID real del paciente en el portal.

## Aviso de privacidad
Maneja datos personales sensibles de salud. Antes de producción real, revisar cumplimiento de la
**LFPDPPP** (consentimiento, aviso de privacidad, cifrado, retención). El bucket es privado y el acceso
del doctor se hace con URLs firmadas de corta duración tras verificar la asignación.
