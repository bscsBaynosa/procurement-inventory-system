SELECT user_id,
       username,
       password_hash,
       full_name,
       email,
       role,
       branch_id,
       is_active,
       created_at,
       created_by,
       updated_at,
       updated_by,
       last_login_at,
       last_login_ip,
       failed_login_attempts,
       locked_until,
       password_changed_at
FROM public.users
LIMIT 1000;