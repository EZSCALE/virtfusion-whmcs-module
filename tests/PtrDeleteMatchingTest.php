<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Client;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\Config;
use WHMCS\Module\Server\VirtFusionDirect\PowerDns\PtrManager;

/** PowerDNS client over one in-memory reverse zone; records every PATCH. */
final class FakePtrClient extends Client
{
    /** @var array<int,array{0:string,1:array}> */
    public array $patches = [];

    /** @param array<string,string> $ptrs ptr name => content */
    public function __construct(private array $ptrs)
    {
        parent::__construct(['endpoint' => 'https://pdns.example.test', 'apiKey' => 'k']);
    }

    public function listZones(): array
    {
        return ['2.0.192.in-addr.arpa.'];
    }

    public function getZone(string $zoneName): ?array
    {
        $rrsets = [];
        foreach ($this->ptrs as $name => $content) {
            $rrsets[] = ['name' => $name, 'type' => 'PTR', 'ttl' => 3600, 'records' => [['content' => $content, 'disabled' => false]]];
        }

        return ['name' => $zoneName, 'rrsets' => $rrsets];
    }

    public function patchRRset(string $zoneName, array $rrset): array
    {
        $this->patches[] = [$zoneName, $rrset];

        return ['ok' => true, 'http' => 204, 'body' => ''];
    }
}

final class PtrDeleteMatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        DB::schema()->create('tbladdonmodules', function ($t) {
            $t->string('module');
            $t->string('setting');
            $t->text('value')->nullable();
        });
        DB::table('tbladdonmodules')->insert([
            ['module' => 'virtfusiondns', 'setting' => 'enabled', 'value' => 'on'],
            ['module' => 'virtfusiondns', 'setting' => 'endpoint', 'value' => 'https://pdns.example.test'],
            ['module' => 'virtfusiondns', 'setting' => 'apiKey', 'value' => 'test-key'],
        ]);
        Config::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
        parent::tearDown();
    }

    private static function server(): array
    {
        return ['data' => ['id' => 1, 'hostname' => 'old.example', 'network' => ['interfaces' => [
            ['ipv4' => [['address' => '192.0.2.1'], ['address' => '192.0.2.2']], 'ipv6' => []]]]]];
    }

    #[Test]
    public function matching_mode_deletes_only_ptrs_naming_the_stored_hostname(): void
    {
        // .2 has been reassigned to another customer, who set their own PTR.
        $client = new FakePtrClient(['1.2.0.192.in-addr.arpa.' => 'OLD.example.', '2.2.0.192.in-addr.arpa.' => 'mail.other.test.']);
        $summary = (new PtrManager($client))->deleteForServer(self::server(), 'old.example');

        $this->assertCount(1, $client->patches);
        $this->assertSame('1.2.0.192.in-addr.arpa.', $client->patches[0][1]['name']);
        $this->assertSame('DELETE', $client->patches[0][1]['changetype']);
        $this->assertSame(1, $summary['deleted']);
        $this->assertSame(1, $summary['preserved']);
    }

    #[Test]
    public function default_mode_still_deletes_every_ptr(): void
    {
        $client = new FakePtrClient(['1.2.0.192.in-addr.arpa.' => 'old.example.', '2.2.0.192.in-addr.arpa.' => 'mail.other.test.']);
        (new PtrManager($client))->deleteForServer(self::server());

        $this->assertCount(2, $client->patches);
    }
}
