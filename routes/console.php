<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// db:copy-sqlite-to-pgsql --truncate --chunk=500 --tables=users,teams
Artisan::command('db:copy-sqlite-to-pgsql {--truncate} {--chunk=500} {--tables=*}', function () {
    $source = DB::connection('sqlite');
    $target = DB::connection('pgsql');

    $tables = $this->option('tables');
    if (empty($tables)) {
        // Discover tables directly from sqlite schema, excluding internal tables
        try {
            $rows = $source->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $tables = collect($rows)->pluck('name')->filter(function($name){
                return !in_array($name, ['migrations']); // exclude by default
            })->values()->all();
        } catch (\Throwable $e) {
            $this->warn('Falling back to minimal table list (auto-discovery failed): '.$e->getMessage());
            $tables = ['users'];
        }
    }

    $chunkSize = (int) $this->option('chunk');

    if ($this->option('truncate')) {
        $this->info('Truncating target tables (cascade) ...');
        // Temporarily relax constraints for bulk operations
        try { $target->statement('SET session_replication_role = replica'); } catch (\Throwable $e) {}
        foreach (array_reverse($tables) as $t) {
            try {
                $target->statement("TRUNCATE TABLE \"$t\" RESTART IDENTITY CASCADE");
            } catch (\Throwable $e) {
                $this->warn("Could not truncate $t: {$e->getMessage()}");
            }
        }
        try { $target->statement('SET session_replication_role = DEFAULT'); } catch (\Throwable $e) {}
    }

    foreach ($tables as $t) {
        if (!$source->getSchemaBuilder()->hasTable($t)) { $this->warn("Skip $t (missing in source)"); continue; }
        if (!$target->getSchemaBuilder()->hasTable($t)) { $this->warn("Skip $t (missing in target)"); continue; }

        $count = (int) $source->table($t)->count();
        $this->info("Copying $t ($count rows) ...");
        $bar = $this->output->createProgressBar(max(1, (int) ceil($count / max(1, $chunkSize))));
        $bar->start();

        $source->table($t)->orderBy('id')->chunk($chunkSize, function($rows) use ($target, $t, $bar) {
            $payload = [];
            foreach ($rows as $row) {
                $payload[] = (array) $row;
            }
            if (!empty($payload)) {
                try {
                    $target->table($t)->insert($payload);
                } catch (\Throwable $e) {
                    foreach ($payload as $record) {
                        try { $target->table($t)->insert($record); } catch (\Throwable $ie) { /* ignore */ }
                    }
                }
            }
            $bar->advance();
        });

        $bar->finish();
        $this->newLine();

        // Sync sequence to max(id)+1 if an identity/serial exists
        try {
            $target->statement("SELECT setval(pg_get_serial_sequence('\"$t\"','id'), COALESCE((SELECT MAX(id) FROM \"$t\"),0)+1, false)");
        } catch (\Throwable $e) {
            // ignore if no serial/identity sequence
        }
    }

    $this->info('Copy complete.');
})->purpose('Copy data from sqlite (local) to pgsql (Supabase) preserving IDs');

Artisan::command('db:check-pgsql', function () {
    $cfg = config('database.connections.pgsql');
    $this->info('Attempting pgsql connection with:');
    $this->line(' host=' . ($cfg['host'] ?? '')); 
    $this->line(' port=' . ($cfg['port'] ?? ''));
    $this->line(' database=' . ($cfg['database'] ?? ''));
    $this->line(' username=' . ($cfg['username'] ?? ''));
    try {
        DB::connection('pgsql')->getPdo();
        $this->info('PostgreSQL connection successful.');
    } catch (\Throwable $e) {
        $this->error('PostgreSQL connection failed: '.$e->getMessage());
    }
})->purpose('Check connectivity to the configured pgsql database');

Artisan::command('audit:encryption {--connection=}', function () {
    $columns = ['bio','phone','location','website'];

    $connName = $this->option('connection') ?: config('database.default');

    // If default connection fails and no explicit connection provided, try sqlite fallback
    $conn = null;
    try {
        $conn = DB::connection($connName);
        $conn->getPdo();
    } catch (\Throwable $e) {
        if (!$this->option('connection')) {
            // Force sqlite to use the local file path, not env(DB_DATABASE)
            config(['database.connections.sqlite.database' => database_path('database.sqlite')]);
            try {
                $connName = 'sqlite';
                $conn = DB::connection('sqlite');
                $conn->getPdo();
                $this->warn('Default DB unreachable, falling back to sqlite (database/database.sqlite).');
            } catch (\Throwable $ie) {
                $this->error('Could not connect to any database for audit: '.$ie->getMessage());
                return 1;
            }
        } else {
            $this->error('Database connection failed for "'.$connName.'": '.$e->getMessage());
            return 1;
        }
    }

    $total = (int) $conn->table('users')->count();
    $issues = [];
    $conn->table('users')->select(array_merge(['id'], $columns))->orderBy('id')->chunkById(500, function($rows) use (&$issues, $columns) {
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $val = $row->$col;
                if ($val === null || $val === '') continue;
                $looksEncrypted = false;
                try { Crypt::decryptString($val); $looksEncrypted = true; } catch (\Throwable $e) { $looksEncrypted = false; }
                if (!$looksEncrypted) {
                    $issues[] = [ 'id' => $row->id, 'column' => $col, 'sample' => mb_strimwidth((string)$val, 0, 64, '…') ];
                }
            }
        }
    });
    if (empty($issues)) {
        $this->info("Audit complete on connection '$connName': $total users; all PII columns appear encrypted.");
    } else {
        $this->warn('Found plaintext values:');
        foreach ($issues as $i) {
            $this->line(" - user #{$i['id']} {$i['column']}: {$i['sample']}");
        }
        $this->warn('Run: php artisan migrate --force to apply the backfill, or manually update affected rows.');
    }
})->purpose('Audit users table to ensure PII columns are encrypted');
