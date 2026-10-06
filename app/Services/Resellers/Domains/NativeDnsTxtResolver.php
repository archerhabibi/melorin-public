<?php

namespace App\Services\Resellers\Domains;

use Throwable;

class NativeDnsTxtResolver implements DnsTxtResolver
{
    public function txt(string $name): array
    {
        try {
            $records = @dns_get_record($name, DNS_TXT);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($records)) {
            return [];
        }

        $out = [];

        foreach ($records as $record) {
            $value = $record['txt'] ?? (isset($record['entries']) ? implode('', (array) $record['entries']) : '');
            $out[] = (string) $value;
        }

        return $out;
    }
}
