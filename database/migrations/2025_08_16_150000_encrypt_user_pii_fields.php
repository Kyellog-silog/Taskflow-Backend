<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration {
    public function up(): void
    {
        // Encrypt existing plaintext values for selected user columns
        DB::table('users')
            ->select('id', 'bio', 'phone', 'location', 'website')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ([ 'bio', 'phone', 'location', 'website' ] as $col) {
                        $val = $row->$col;
                        if ($val === null || $val === '') continue;
                        // Skip if already encrypted
                        $already = false;
                        try {
                            Crypt::decryptString($val);
                            $already = true;
                        } catch (Throwable $e) {
                            $already = false;
                        }
                        if (!$already) {
                            $updates[$col] = Crypt::encryptString((string) $val);
                        }
                    }
                    if (!empty($updates)) {
                        DB::table('users')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Best-effort decrypt to revert; if decrypt fails, leave as-is
        DB::table('users')
            ->select('id', 'bio', 'phone', 'location', 'website')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ([ 'bio', 'phone', 'location', 'website' ] as $col) {
                        $val = $row->$col;
                        if ($val === null || $val === '') continue;
                        try {
                            $updates[$col] = Crypt::decryptString($val);
                        } catch (Throwable $e) {
                            // ignore
                        }
                    }
                    if (!empty($updates)) {
                        DB::table('users')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }
};
