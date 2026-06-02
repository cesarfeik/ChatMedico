-- ============================================================================
-- PLADIEX Expediente Médico — Datos de prueba (doctores y pacientes)
-- Ejecutar en: Supabase → SQL Editor, DESPUÉS de supabase-setup.sql
-- Crea usuarios de login listos para usar. Contraseña de todos: Demo1234
-- Puedes correrlo varias veces sin duplicar (es idempotente por email).
-- ============================================================================

-- Helper: crea (o reutiliza) un usuario de auth con email+password ya confirmado.
create or replace function create_demo_user(p_email text, p_password text)
returns uuid
language plpgsql
security definer
as $$
declare uid uuid;
begin
  select id into uid from auth.users where email = p_email;
  if uid is not null then return uid; end if;

  uid := gen_random_uuid();
  insert into auth.users (
    instance_id, id, aud, role, email, encrypted_password, email_confirmed_at,
    raw_app_meta_data, raw_user_meta_data, created_at, updated_at,
    confirmation_token, recovery_token, email_change_token_new, email_change
  ) values (
    '00000000-0000-0000-0000-000000000000', uid, 'authenticated', 'authenticated',
    p_email, crypt(p_password, gen_salt('bf')), now(),
    '{"provider":"email","providers":["email"]}', '{}', now(), now(),
    '', '', '', ''
  );

  insert into auth.identities (
    id, user_id, identity_data, provider, provider_id, last_sign_in_at, created_at, updated_at
  ) values (
    gen_random_uuid(), uid,
    jsonb_build_object('sub', uid::text, 'email', p_email, 'email_verified', true),
    'email', uid::text, now(), now(), now()
  );

  return uid;
end;
$$;

-- Helper: registra un doctor (idempotente).
create or replace function seed_doctor(p_email text, p_name text, p_specialty text)
returns uuid
language plpgsql
as $$
declare uid uuid;
begin
  uid := create_demo_user(p_email, 'Demo1234');
  insert into doctors(user_id, full_name, specialty, email)
    values (uid, p_name, p_specialty, p_email)
    on conflict (user_id) do update set full_name = excluded.full_name, specialty = excluded.specialty;
  insert into profiles(user_id, role, full_name, email)
    values (uid, 'doctor', p_name, p_email)
    on conflict (user_id) do update set role = 'doctor', full_name = excluded.full_name;
  return uid;
end;
$$;

-- Helper: registra un paciente y lo asigna a un doctor (idempotente).
create or replace function seed_patient(p_email text, p_name text, p_doctor_email text)
returns text
language plpgsql
as $$
declare uid uuid; pid uuid; did uuid; code text;
begin
  uid := create_demo_user(p_email, 'Demo1234');
  insert into profiles(user_id, role, full_name, email)
    values (uid, 'patient', p_name, p_email)
    on conflict (user_id) do update set role = 'patient', full_name = excluded.full_name;

  select id into pid from patients where user_id = uid;
  if pid is null then
    insert into patients(user_id, full_name, email) values (uid, p_name, p_email)
      returning id into pid;
  end if;

  select user_id into did from doctors where email = p_doctor_email;
  if did is not null then
    insert into doctor_patient(doctor_user_id, patient_id) values (did, pid)
      on conflict do nothing;
  end if;

  select patient_code into code from patients where id = pid;
  return code;
end;
$$;

-- ── Crear doctores ──────────────────────────────────────────────────────────
select seed_doctor('doctor1@demo.com', 'Dra. Ana López',      'Medicina General');
select seed_doctor('doctor2@demo.com', 'Dr. Luis Martínez',   'Cardiología');

-- ── Crear pacientes y asignarlos ────────────────────────────────────────────
select seed_patient('paciente1@demo.com', 'Juan Pérez',     'doctor1@demo.com');
select seed_patient('paciente2@demo.com', 'María García',   'doctor1@demo.com');
select seed_patient('paciente3@demo.com', 'Carlos Ruiz',    'doctor2@demo.com');
select seed_patient('paciente4@demo.com', 'Lucía Sánchez',  'doctor2@demo.com');

-- ── Ver el resultado (anota los patient_code para usarlos en el chat) ────────
select p.patient_code, p.full_name as paciente, p.email,
       d.full_name as doctor_asignado
from patients p
left join doctor_patient dp on dp.patient_id = p.id
left join doctors d on d.user_id = dp.doctor_user_id
order by p.patient_code;

-- ============================================================================
-- CREDENCIALES (todas con contraseña: Demo1234)
--   Doctores:  doctor1@demo.com · doctor2@demo.com   → entran en /doctor/
--   Pacientes: paciente1@demo.com … paciente4@demo.com → entran en /patient/
--
-- Para agregar MÁS, repite las líneas select seed_doctor(...) / seed_patient(...).
-- ============================================================================
