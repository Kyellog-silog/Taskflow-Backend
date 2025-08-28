<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration {
    public function up(): void
    {
        // Encrypt existing plaintext values for critical PII fields (name, email)
        DB::table('users')
            ->select('id', 'name', 'email')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];
                    
                    // Encrypt name if not already encrypted
                    if (!empty($row->name)) {
                        $already = false;
                        try {
                            Crypt::decryptString($row->name);
                            $already = true;
                        } catch (Throwable $e) {
                            $already = false;
                        }
                        if (!$already) {
                            $updates['name'] = Crypt::encryptString((string) $row->name);
                        }
                    }
                    
                    // Encrypt email if not already encrypted
                    if (!empty($row->email)) {
                        $already = false;
                        try {
                            Crypt::decryptString($row->email);
                            $already = true;
                        } catch (Throwable $e) {
                            $already = false;
                        }
                        if (!$already) {
                            $updates['email'] = Crypt::encryptString((string) $row->email);
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
            ->select('id', 'name', 'email')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];
                    
                    // Decrypt name
                    if (!empty($row->name)) {
                        try {
                            $decrypted = Crypt::decryptString($row->name);
                            $updates['name'] = $decrypted;
                        } catch (Throwable $e) {
                            // Leave as-is if decryption fails
                        }
                    }
                    
                    // Decrypt email
                    if (!empty($row->email)) {
                        try {
                            $decrypted = Crypt::decryptString($row->email);
                            $updates['email'] = $decrypted;
                        } catch (Throwable $e) {
                            // Leave as-is if decryption fails
                        }
                    }
                    
                    if (!empty($updates)) {
                        DB::table('users')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }
};
