-- ============================================================================
-- FIX: recursión infinita en políticas RLS (error 42P17)
-- Ejecutar en Supabase → SQL Editor (una sola vez).
-- Reemplaza las políticas que se referenciaban en círculo entre
-- patients ↔ doctor_patient por funciones SECURITY DEFINER (que ignoran RLS
-- internamente y por tanto NO recursan).
-- ============================================================================

-- Funciones auxiliares (corren como owner → sin RLS → sin recursión)
create or replace function owns_patient(pid uuid) returns boolean
  language sql security definer stable set search_path = public as $$
  select exists (select 1 from patients p where p.id = pid and p.user_id = auth.uid());
$$;

create or replace function treats_patient(pid uuid) returns boolean
  language sql security definer stable set search_path = public as $$
  select exists (select 1 from doctor_patient dp where dp.patient_id = pid and dp.doctor_user_id = auth.uid());
$$;

-- ── patients ────────────────────────────────────────────────────────────────
drop policy if exists "doctor ve pacientes asignados" on patients;
create policy "doctor ve pacientes asignados" on patients
  for select using (treats_patient(id));

-- ── doctor_patient ───────────────────────────────────────────────────────────
drop policy if exists "paciente crea asignacion" on doctor_patient;
create policy "paciente crea asignacion" on doctor_patient
  for insert with check (owns_patient(patient_id));

drop policy if exists "ver asignacion propia" on doctor_patient;
create policy "ver asignacion propia" on doctor_patient
  for select using (auth.uid() = doctor_user_id or owns_patient(patient_id));

-- ── medical_documents ─────────────────────────────────────────────────────────
drop policy if exists "paciente gestiona sus docs (select)" on medical_documents;
create policy "paciente gestiona sus docs (select)" on medical_documents
  for select using (owns_patient(patient_id) or treats_patient(patient_id));

drop policy if exists "paciente inserta sus docs" on medical_documents;
create policy "paciente inserta sus docs" on medical_documents
  for insert with check (owns_patient(patient_id));

drop policy if exists "paciente borra sus docs" on medical_documents;
create policy "paciente borra sus docs" on medical_documents
  for delete using (owns_patient(patient_id));

-- ── storage.objects (bucket expedientes) ───────────────────────────────────────
drop policy if exists "paciente sube a su carpeta" on storage.objects;
create policy "paciente sube a su carpeta" on storage.objects
  for insert with check (
    bucket_id = 'expedientes' and owns_patient(((storage.foldername(name))[1])::uuid)
  );

drop policy if exists "paciente lee su carpeta" on storage.objects;
create policy "paciente lee su carpeta" on storage.objects
  for select using (
    bucket_id = 'expedientes' and owns_patient(((storage.foldername(name))[1])::uuid)
  );

drop policy if exists "paciente borra de su carpeta" on storage.objects;
create policy "paciente borra de su carpeta" on storage.objects
  for delete using (
    bucket_id = 'expedientes' and owns_patient(((storage.foldername(name))[1])::uuid)
  );

-- Recargar el caché de esquema de la API
notify pgrst, 'reload schema';
