<?php

namespace App;

use Illuminate\Http\Request;

class PersonalAccess
{
    public function configured(): bool
    {
        return config('memyselfai.username') !== ''
            && str_starts_with((string) config('memyselfai.password_hash'), '$2y$');
    }

    public function fingerprint(): string
    {
        // Changing either credential invalidates sessions created with the old values.
        return hash_hmac(
            'sha256',
            config('memyselfai.username').'|'.config('memyselfai.password_hash'),
            config('app.key'),
        );
    }

    public function authenticated(Request $request): bool
    {
        return $this->configured()
            && hash_equals($this->fingerprint(), (string) $request->session()->get('personal_access', ''));
    }
}
