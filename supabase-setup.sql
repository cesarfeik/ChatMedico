-- ============================================================================
-- PLADIEX Expediente Médico — Setup de Supabase
-- Ejecutar en: Supabase → SQL Editor (nuevo proyecto recomendado)
-- ============================================================================

-- ── Extensiones ─────────────────────────────────────────────────────────────
create extension if not exists "pgcrypto";

-- ── Perfiles (rol por usuario) ───────────────────────────────────────────────
create table if not exists profiles (
  user_id    uuid primary key references auth.users(id) on delete cascade,
  role       text not null check (role in ('doctor','patient')),
  full_name  text,
  email      text,
  created_at timestamptz default now()
);

-- ── Doctores ──────────────────────────────────────────────────────────────────
create table if not exists doctors (
  user_id    uuid primary key references auth.users(id) on delete cascade,
  full_name  text,
  specialty  text,
  email      text,
  created_at timestamptz default now()
);

-- Secuencia + función para generar patient_code legible PAC-00001, PAC-00002, ...
create sequence if not exists patient_code_seq start 1;

create or replace function gen_patient_code() returns text as $$
  select 'PAC-' || lpad(nextval('patient_code_seq')::text, 5, '0');
$$ language sql;

-- ── Pacientes ─────────────────────────────────────────────────────────────────
-- patient_code es el ID humano que el doctor escribe en el chat (ej. PAC-00123).
-- Se autogenera al insertar (el cliente no necesita enviarlo).
create table if not exists patients (
  id           uuid primary key default gen_random_uuid(),
  patient_code text unique not null default gen_patient_code(),
  user_id      uuid references auth.users(id) on delete set null,
  full_name    text,
  email        text,
  created_at   timestamptz default now()
);

-- ── Asignación doctor ↔ paciente ─────────────────────────────────────────────
create table if not exists doctor_patient (
  doctor_user_id uuid references auth.users(id) on delete cascade,
  patient_id     uuid references patients(id)   on delete cascade,
  created_at     timestamptz default now(),
  primary key (doctor_user_id, patient_id)
);

-- ── Documentos médicos ────────────────────────────────────────────────────────
create table if not exists medical_documents (
  id              uuid primary key default gen_random_uuid(),
  patient_id      uuid references patients(id) on delete cascade,
  filename        text not null,
  doc_type        text,                       -- pdf | docx | txt | image
  safe_prefix     text not null,              -- prefijo de los IDs en Pinecone
  chunks          int  not null default 0,
  storage_path    text,                       -- ruta en el bucket de Storage
  uploaded_by     text,                       -- email de quien subió
  content_preview text,                       -- primeros ~4000 chars para vista previa
  created_at      timestamptz default now()
);

create index if not exists idx_meddocs_patient on medical_documents(patient_id);

-- ============================================================================
-- ROW LEVEL SECURITY
-- ============================================================================
alter table profiles          enable row level security;
alter table doctors           enable row level security;
alter table patients          enable row level security;
alter table doctor_patient     enable row level security;
alter table medical_documents enable row level security;

-- Funciones auxiliares SECURITY DEFINER: evitan recursión entre políticas
-- (patients ↔ doctor_patient). Corren como owner → ignoran RLS internamente.
create or replace function owns_patient(pid uuid) returns boolean
  language sql security definer stable set search_path = public as $$
  select exists (select 1 from patients p where p.id = pid and p.user_id = auth.uid());
$$;

create or replace function treats_patient(pid uuid) returns boolean
  language sql security definer stable set search_path = public as $$
  select exists (select 1 from doctor_patient dp where dp.patient_id = pid and dp.doctor_user_id = auth.uid());
$$;

-- profiles: cada quien ve/edita su propio perfil
create policy "perfil propio (select)" on profiles
  for select using (auth.uid() = user_id);
create policy "perfil propio (insert)" on profiles
  for insert with check (auth.uid() = user_id);
create policy "perfil propio (update)" on profiles
  for update using (auth.uid() = user_id);

-- doctors: lista pública de doctores (para que el paciente elija) en select;
-- cada doctor edita su propio registro
create policy "doctores visibles" on doctors
  for select using (true);
create policy "doctor edita su registro (insert)" on doctors
  for insert with check (auth.uid() = user_id);
create policy "doctor edita su registro (update)" on doctors
  for update using (auth.uid() = user_id);

-- patients: el paciente ve/edita su propio registro
create policy "paciente propio (select)" on patients
  for select using (auth.uid() = user_id);
create policy "paciente propio (insert)" on patients
  for insert with check (auth.uid() = user_id);
create policy "paciente propio (update)" on patients
  for update using (auth.uid() = user_id);
-- el doctor puede leer los datos de los pacientes que tiene asignados
create policy "doctor ve pacientes asignados" on patients
  for select using (treats_patient(id));

-- doctor_patient: el paciente crea su asignación; el doctor ve sus asignaciones
create policy "paciente crea asignacion" on doctor_patient
  for insert with check (owns_patient(patient_id));
create policy "ver asignacion propia" on doctor_patient
  for select using (auth.uid() = doctor_user_id or owns_patient(patient_id));

-- medical_documents: el paciente gestiona los suyos; el doctor lee los de pacientes asignados
create policy "paciente gestiona sus docs (select)" on medical_documents
  for select using (owns_patient(patient_id) or treats_patient(patient_id));
create policy "paciente inserta sus docs" on medical_documents
  for insert with check (owns_patient(patient_id));
create policy "paciente borra sus docs" on medical_documents
  for delete using (owns_patient(patient_id));

-- ============================================================================
-- STORAGE — bucket privado "expedientes"
-- ============================================================================
insert into storage.buckets (id, name, public)
values ('expedientes', 'expedientes', false)
on conflict (id) do nothing;

-- El paciente sube/lee/borra archivos dentro de la carpeta de su patient_id.
-- (El servidor usa la service role key para leer/firmar en nombre del doctor.)
create policy "paciente sube a su carpeta" on storage.objects
  for insert with check (
    bucket_id = 'expedientes' and owns_patient(((storage.foldername(name))[1])::uuid)
  );
create policy "paciente lee su carpeta" on storage.objects
  for select using (
    bucket_id = 'expedientes' and owns_patient(((storage.foldername(name))[1])::uuid)
  );
create policy "paciente borra de su carpeta" on storage.objects
  for delete using (
    bucket_id = 'expedientes' and owns_patient(((storage.foldername(name))[1])::uuid)
  );

-- ============================================================================
-- DATOS DE PRUEBA (opcional) — descomenta y ajusta tras crear los usuarios en Auth
-- ============================================================================
-- 1) Crea un usuario doctor y un usuario paciente en Authentication → Users.
-- 2) Copia sus UUIDs y úsalos abajo.
--
-- insert into doctors (user_id, full_name, specialty, email)
--   values ('<UUID_DOCTOR>', 'Dra. Ejemplo', 'Medicina General', 'doctor@demo.com');
-- insert into profiles (user_id, role, full_name, email)
--   values ('<UUID_DOCTOR>', 'doctor', 'Dra. Ejemplo', 'doctor@demo.com');
--
-- insert into patients (patient_code, user_id, full_name, email)
--   values (gen_patient_code(), '<UUID_PACIENTE>', 'Juan Pérez', 'paciente@demo.com')
--   returning id, patient_code;  -- anota el patient_code generado (PAC-00001)
-- insert into profiles (user_id, role, full_name, email)
--   values ('<UUID_PACIENTE>', 'patient', 'Juan Pérez', 'paciente@demo.com');
--
-- -- Asigna el paciente al doctor (usa el id del paciente del paso anterior):
-- insert into doctor_patient (doctor_user_id, patient_id)
--   values ('<UUID_DOCTOR>', '<UUID_PACIENTE_PATIENTS_ID>');
