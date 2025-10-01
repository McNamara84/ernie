<?php

namespace App\Support\Database;

use PDO;

class MySqlSslOptions
{
    /**
     * Build an array of SSL options for PDO MySQL connections.
     *
     * @param  string|null  $caPath
     * @param  string|null  $certPath
     * @param  string|null  $keyPath
     * @return array<int, string>
     */
    public static function fromValues(?string $caPath, ?string $certPath = null, ?string $keyPath = null): array
    {
        return array_filter([
            PDO::MYSQL_ATTR_SSL_CA => self::normalisePath($caPath),
            PDO::MYSQL_ATTR_SSL_CERT => self::normalisePath($certPath),
            PDO::MYSQL_ATTR_SSL_KEY => self::normalisePath($keyPath),
        ]);
    }

    private static function normalisePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $trimmed = trim($path);

        return $trimmed === '' ? null : $trimmed;
    }
}
