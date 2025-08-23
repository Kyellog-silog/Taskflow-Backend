# Supabase setup

- Find your DB connection in Supabase > Project Settings > Database.
- If you use the connection pooler, replace host with `*.pooler.supabase.com` and port `6543`.
- Laravel `.env` needs `DB_SSLMODE=require`.

### Connect Laravel to Supabase

1. Copy `.env.example.supabase` to `.env` and fill values.
2. Run:
   - php artisan db:check-pgsql
   - php artisan migrate --force
3. Optional: copy data from local sqlite
   - php artisan db:copy-sqlite-to-pgsql --truncate

### Notes
- After switching DB, clear caches:
  - php artisan config:clear
  - php artisan cache:clear
- Update CORS/Sanctum for your frontend domain.
