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
}
