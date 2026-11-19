<?php

namespace App\Services;

final class MailAddresses
{
    public static function resolve(string $email): string
    {
        $email = strtolower(trim($email));
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $aliases = config('mail.domain_aliases', []);

        return isset($aliases[$domain]) ? $name.'@'.$aliases[$domain] : $email;
    }
}
