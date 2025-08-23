<?php

namespace App\Traits;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

trait EncryptsAttributes
{
    protected function encryptValue($value)
    {
        if ($value === null || $value === '') return $value;
        return Crypt::encryptString((string) $value);
    }

    protected function decryptValue($value)
    {
        if ($value === null || $value === '') return $value;
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value;
        }
    }
}
