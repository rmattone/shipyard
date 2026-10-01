<?php

namespace App\Rules;

use App\Support\Ssh\HostKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class SshHostKey implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            HostKey::normalize($value);
        } catch (Throwable $e) {
            $fail($e->getMessage());
        }
    }
}
