<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\Module\Server\VirtFusionDirect\Curl;
use WHMCS\Module\Server\VirtFusionDirect\SshKeys;

/** ConfigureService wired to a scripted VirtFusion: one user (1010) and its key inventory. */
class SshKeyPanel extends ConfigureService
{
    public ?array $vfUser = ['id' => 1010, 'extRelationId' => 10];

    public array $keys = [];

    public array $requests = [];

    public int $postCode = 201;

    public int $deleteCode = 204;

    public function __construct()
    {
        $property = new \ReflectionProperty(ConfigureService::class, 'cp');
        $property->setValue($this, ['url' => 'https://panel.example.test/api/v1', 'token' => 'test-token']);
    }

    public function getVFUserDetails(int $id): ?array
    {
        return $this->vfUser;
    }

    public function initCurl($token)
    {
        return new class($this) extends Curl
        {
            private SshKeyPanel $panel;

            private array $options = [];

            private int $code = 0;

            public function __construct(SshKeyPanel $panel)
            {
                $this->panel = $panel;
            }

            public function addOption($name, $value)
            {
                $this->options[$name] = $value;
            }

            public function get($url = null)
            {
                $path = (string) parse_url((string) $url, PHP_URL_PATH);
                $this->panel->requests[] = ['GET', $path, null];
                $this->code = 200;

                return json_encode(['data' => $this->panel->keys]);
            }

            public function post($url = null)
            {
                $body = json_decode((string) ($this->options[CURLOPT_POSTFIELDS] ?? 'null'), true);
                $this->panel->requests[] = ['POST', (string) parse_url((string) $url, PHP_URL_PATH), $body];
                $this->code = $this->panel->postCode;
                if ($this->code === 201) {
                    $this->panel->keys[] = ['id' => 99, 'name' => $body['name'], 'publicKey' => $body['publicKey'], 'enabled' => true];

                    return json_encode(['data' => ['id' => 99, 'name' => $body['name'], 'type' => 'OpenSSH']]);
                }

                return json_encode(['errors' => ['The public key has already been taken.']]);
            }

            public function delete($url = null)
            {
                $path = (string) parse_url((string) $url, PHP_URL_PATH);
                $this->panel->requests[] = ['DELETE', $path, null];
                $this->code = $this->panel->deleteCode;
                $id = (int) basename($path);
                $this->panel->keys = array_values(array_filter($this->panel->keys, fn ($k) => (int) $k['id'] !== $id));

                return '';
            }

            public function getRequestInfo($param = false)
            {
                return $param === 'http_code' ? $this->code : ['http_code' => $this->code];
            }
        };
    }
}

final class SshKeyManagementTest extends TestCase
{
    /** Build an OpenSSH public key line whose blob is well-formed for $type. */
    private static function key(string $type, int $material = 32, string $comment = ' user@host'): string
    {
        $blob = pack('N', strlen($type)) . $type;
        if ($type === 'ssh-rsa') {
            $blob .= pack('N', 3) . "\x01\x00\x01" . pack('N', $material + 1) . "\x00" . str_repeat("\xab", $material);
        } else {
            $blob .= pack('N', $material) . str_repeat("\x5a", $material);
        }

        return $type . ' ' . base64_encode($blob) . $comment;
    }

    private static function body(string $key): string
    {
        return explode(' ', $key)[1];
    }

    public static function acceptedTypes(): array
    {
        return [
            'ed25519' => [self::key('ssh-ed25519')],
            'rsa 2048' => [self::key('ssh-rsa', 256)],
            'rsa 4096' => [self::key('ssh-rsa', 512)],
            'ecdsa 256' => [self::key('ecdsa-sha2-nistp256', 65)],
            'ecdsa 384' => [self::key('ecdsa-sha2-nistp384', 97)],
            'ecdsa 521' => [self::key('ecdsa-sha2-nistp521', 133)],
            'sk ed25519' => [self::key('sk-ssh-ed25519@openssh.com')],
            'sk ecdsa' => [self::key('sk-ecdsa-sha2-nistp256@openssh.com', 65)],
            'no comment' => [self::key('ssh-ed25519', 32, '')],
        ];
    }

    #[Test]
    #[DataProvider('acceptedTypes')]
    public function accepts_each_supported_key_type(string $key): void
    {
        $this->assertSame($key, SshKeys::publicKey("  {$key}\n"));
    }

    #[Test]
    public function rsa_body_length_boundary_is_2048_bits(): void
    {
        $this->assertSame(372, strlen(self::body(self::key('ssh-rsa', 256))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2048');
        SshKeys::publicKey(self::key('ssh-rsa', 128));
    }

    public static function rejectedKeys(): array
    {
        $ed = self::key('ssh-ed25519');

        return [
            'empty' => [''],
            'not a string' => [['ssh-ed25519']],
            'unknown type' => ['ssh-dss ' . self::body($ed)],
            'private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----"],
            'bad base64 characters' => ['ssh-ed25519 AAAA$$$$C3NzaC1lZDI1NTE5'],
            'base64 of the wrong type' => ['ssh-ed25519 ' . self::body(self::key('ssh-rsa', 256))],
            'garbage base64' => ['ssh-ed25519 QUJDREVGR0hJSktM'],
            'control character in comment' => [self::key('ssh-ed25519', 32, " user\x01@host")],
            'nul byte' => [self::key('ssh-ed25519', 32, " user\x00")],
            'two keys' => [$ed . "\n" . $ed],
            'long comment' => [self::key('ssh-ed25519', 32, ' ' . str_repeat('c', 201))],
            'oversize' => ['ssh-rsa ' . str_repeat('A', 16384)],
            'invalid utf-8 comment' => [self::key('ssh-ed25519', 32, " \xff\xfe")],
        ];
    }

    #[Test]
    #[DataProvider('rejectedKeys')]
    public function rejects_malformed_keys($key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SshKeys::publicKey($key);
    }

    #[Test]
    public function name_must_be_1_to_64_printable_characters(): void
    {
        $this->assertSame('Laptop – work', SshKeys::name('  Laptop – work '));
        $this->assertSame(str_repeat('n', 64), SshKeys::name(str_repeat('n', 64)));
        foreach (['', '   ', str_repeat('n', 65), "bad\x07name", "line\nbreak", "tab\tname", "\xff\xfe", ['x']] as $bad) {
            try {
                SshKeys::name($bad);
                $this->fail('Accepted invalid name: ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('64', $e->getMessage());
            }
        }
    }

    #[Test]
    public function add_posts_the_key_under_the_clients_own_vf_user(): void
    {
        $panel = new SshKeyPanel;
        $key = self::key('ssh-ed25519');

        $list = $panel->addClientSshKey(10, 'Laptop', $key);

        $this->assertContains(['POST', '/api/v1/ssh_keys', ['userId' => 1010, 'name' => 'Laptop', 'publicKey' => $key]], $panel->requests);
        $this->assertSame([99], array_column($list, 'id'));
        $this->assertSame('Laptop', $list[0]['name']);
        $this->assertStringStartsWith('SHA256:', $list[0]['fingerprint']);
        $this->assertStringNotContainsString(self::body($key), json_encode($list));
        $this->assertStringNotContainsString(self::body($key), json_encode($GLOBALS['vfd_test_log']));
    }

    #[Test]
    public function add_re_validates_and_never_posts_an_invalid_key(): void
    {
        $panel = new SshKeyPanel;

        try {
            $panel->addClientSshKey(10, 'Old', self::key('ssh-rsa', 128));
            $this->fail('Short RSA key accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $panel->requests);
        }
    }

    #[Test]
    public function add_rejected_by_virtfusion_is_a_400_without_raw_api_text(): void
    {
        $panel = new SshKeyPanel;
        $panel->postCode = 422;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VirtFusion rejected this key');
        $panel->addClientSshKey(10, 'Laptop', self::key('ssh-ed25519'));
    }

    #[Test]
    public function no_vf_user_yet_is_a_409_for_add_and_delete(): void
    {
        foreach ([fn (SshKeyPanel $p) => $p->addClientSshKey(10, 'Laptop', self::key('ssh-ed25519')), fn (SshKeyPanel $p) => $p->deleteClientSshKey(10, 5)] as $call) {
            $panel = new SshKeyPanel;
            $panel->vfUser = null;

            try {
                $call($panel);
                $this->fail('Accepted without a VirtFusion user.');
            } catch (\RuntimeException $e) {
                $this->assertSame(409, $e->getCode());
                $this->assertSame('Your control panel account is created with your first server; add keys after it is active.', $e->getMessage());
            }
            $this->assertSame([], $panel->requests);
        }
    }

    #[Test]
    public function delete_refuses_a_key_owned_by_another_vf_user_without_calling_delete(): void
    {
        $panel = new SshKeyPanel;
        $panel->keys = [['id' => 5, 'name' => 'Mine', 'publicKey' => self::key('ssh-ed25519'), 'enabled' => true]];

        try {
            $panel->deleteClientSshKey(10, 6);
            $this->fail('Foreign key deleted.');
        } catch (\RuntimeException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('SSH key not found.', $e->getMessage());
        }
        $this->assertSame([['GET', '/api/v1/ssh_keys/user/1010', null]], $panel->requests);
    }

    #[Test]
    public function delete_fails_closed_when_the_inventory_names_another_owner(): void
    {
        $panel = new SshKeyPanel;
        $panel->keys = [['id' => 6, 'name' => 'Theirs', 'ownerId' => 2020]];

        try {
            $panel->deleteClientSshKey(10, 6);
            $this->fail('Foreign inventory accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertNotContains('DELETE', array_column($panel->requests, 0));
    }

    #[Test]
    public function delete_rejects_non_positive_ids_before_any_request(): void
    {
        $panel = new SshKeyPanel;
        foreach ([0, -3] as $id) {
            try {
                $panel->deleteClientSshKey(10, $id);
                $this->fail('Accepted id ' . $id);
            } catch (\RuntimeException $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
        $this->assertSame([], $panel->requests);
    }

    #[Test]
    public function delete_of_an_owned_key_calls_delete_and_returns_the_refreshed_list(): void
    {
        $mine = self::key('ssh-ed25519');
        $panel = new SshKeyPanel;
        $panel->keys = [
            ['id' => 5, 'name' => 'Old', 'publicKey' => $mine, 'enabled' => true],
            ['id' => 7, 'name' => 'Keep', 'publicKey' => self::key('ssh-rsa', 256), 'enabled' => true],
        ];

        $list = $panel->deleteClientSshKey(10, 5);

        $this->assertContains(['DELETE', '/api/v1/ssh_keys/5', null], $panel->requests);
        $this->assertSame([['id' => 7, 'name' => 'Keep', 'fingerprint' => SshKeys::fingerprint(self::key('ssh-rsa', 256))]], $list);
        $this->assertStringNotContainsString(self::body($mine), json_encode($list));
    }

    #[Test]
    public function delete_failure_at_virtfusion_is_a_502(): void
    {
        $panel = new SshKeyPanel;
        $panel->keys = [['id' => 5, 'name' => 'Old', 'enabled' => true]];
        $panel->deleteCode = 500;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(502);
        $panel->deleteClientSshKey(10, 5);
    }

    #[Test]
    public function present_projects_id_name_and_fingerprint_only(): void
    {
        $key = self::key('ssh-ed25519');
        $out = SshKeys::present([
            ['id' => 3, 'name' => 'A', 'publicKey' => $key, 'type' => 'OpenSSH', 'enabled' => true, 'created' => 'x'],
            ['id' => 4, 'name' => '', 'publicKey' => 'garbage'],
            ['name' => 'no id'],
            'not an array',
        ]);

        $this->assertSame([
            ['id' => 3, 'name' => 'A', 'fingerprint' => SshKeys::fingerprint($key)],
            ['id' => 4, 'name' => 'Key #4', 'fingerprint' => null],
        ], $out);
        // OpenSSH format: SHA256 of the decoded blob, base64 without padding.
        $this->assertSame('SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode(self::body($key)), true)), '='), SshKeys::fingerprint($key));
    }

    public static function errors(): array
    {
        return [
            'validation' => [new \InvalidArgumentException('SSH key name must be 1-64 printable characters.'), 400, 'SSH key name must be 1-64 printable characters.'],
            'forbidden' => [new \RuntimeException('SSH key ownership mismatch.', 403), 403, 'SSH key ownership mismatch.'],
            'not found' => [new \RuntimeException('SSH key not found.', 404), 404, 'SSH key not found.'],
            'no account' => [new \RuntimeException('later', 409), 409, 'later'],
            'upstream' => [new \RuntimeException('Unable to add the SSH key right now. Try again later.', 502), 502, 'Unable to add the SSH key right now. Try again later.'],
            'other' => [new \RuntimeException('SQLSTATE secret', 0), 500, 'Unable to update SSH keys right now.'],
            'type error' => [new \TypeError('internal'), 500, 'Unable to update SSH keys right now.'],
        ];
    }

    #[Test]
    #[DataProvider('errors')]
    public function errors_map_to_safe_client_responses(\Throwable $e, int $status, string $message): void
    {
        $this->assertSame(['status' => $status, 'message' => $message], SshKeys::clientError($e));
    }
}
