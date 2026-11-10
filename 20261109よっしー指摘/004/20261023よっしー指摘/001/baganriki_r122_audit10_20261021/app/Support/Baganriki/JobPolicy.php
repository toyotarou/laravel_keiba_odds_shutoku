<?php
declare(strict_types=1);
namespace App\Support\Baganriki;

final class JobPolicy
{
    public static function mayAggregate(int $now, int $reply, int $publish, array $roles): bool
    {
        if ($now > $publish) return false;
        if ($now > $reply) return true; // equality still accepts a response in the existing contract
        foreach (['1st', '2nd'] as $role) {
            $r = $roles[$role] ?? [];
            if (($r['settled'] ?? false) !== true || ($r['fingerprint_matches'] ?? false) !== true) return false;
        }
        return true;
    }
}
