<?php

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

class DatabaseConfigTest extends TestCase
{
    public function test_metaworks_connection_uses_ssl_certificate_and_disables_server_verification(): void
    {
        $options = config('database.connections.metaworks.options');

        $this->assertIsArray($options);
        $this->assertArrayHasKey(PDO::MYSQL_ATTR_SSL_CA, $options);
        $this->assertSame('/etc/ssl/certs/ca-certificates.crt', $options[PDO::MYSQL_ATTR_SSL_CA]);
        $this->assertArrayHasKey(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $options);
        $this->assertFalse($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function test_metaworks_connection_defaults_to_system_ca_when_env_value_is_empty(): void
    {
        $previousValue = getenv('DB_SUMARIOPMD_SSL_CA');

        $this->setEnvVar('DB_SUMARIOPMD_SSL_CA', '');

        try {
            $config = require base_path('config/database.php');

            $options = $config['connections']['metaworks']['options'];

            $this->assertArrayHasKey(PDO::MYSQL_ATTR_SSL_CA, $options);
            $this->assertSame('/etc/ssl/certs/ca-certificates.crt', $options[PDO::MYSQL_ATTR_SSL_CA]);
        } finally {
            $this->restoreEnvVar('DB_SUMARIOPMD_SSL_CA', $previousValue);
        }
    }

    private function restoreEnvVar(string $key, string|false $value): void
    {
        if ($value === false) {
            $this->setEnvVar($key, null);

            return;
        }

        $this->setEnvVar($key, $value);
    }

    private function setEnvVar(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
