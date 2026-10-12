<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * Validation and safe presentation for customer-managed SSH public keys.
 *
 * Keys belong to the customer's VirtFusion user (POST /ssh_keys, DELETE
 * /ssh_keys/{id}, GET /ssh_keys/user/{id}); ConfigureService performs the API
 * calls. This class is pure: it decides which input may reach the API and
 * what may be returned to the browser (id, name, fingerprint — never the key).
 */
class SshKeys
{
    /** Longest accepted public key line, in bytes. */
    public const MAX_KEY_BYTES = 16384;

    /** Minimum base64 body length for ssh-rsa: a 2048-bit modulus encodes to 372 characters. */
    public const MIN_RSA_BODY = 372;

    /** Accepted OpenSSH public key line: type, base64 blob, optional comment. */
    public const PATTERN = '/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com) ([A-Za-z0-9+\/]+={0,3})( [^\r\n]{0,200})?$/D';

    /**
     * Validate a key label: 1–64 printable characters after trimming.
     *
     * @param  mixed  $name  Raw request value.
     *
     * @throws SshKeyRejected Empty, too long, invalid UTF-8 or containing control/format characters.
     */
    public static function name($name): string
    {
        $name = is_string($name) ? trim($name, " \t\r\n") : '';
        if ($name === '' || ! preg_match('/^[^\p{C}]{1,64}$/uD', $name)) {
            throw new SshKeyRejected('SSH key name must be 1-64 printable characters.');
        }

        return $name;
    }

    /**
     * Validate one OpenSSH public key line and return it trimmed.
     *
     * Beyond the line pattern, the base64 blob must decode strictly and start
     * with the same key type it declares, and RSA keys must be at least 2048 bits.
     *
     * @param  mixed  $key  Raw request value.
     *
     * @throws SshKeyRejected Malformed, unsupported, oversized or weak key.
     */
    public static function publicKey($key): string
    {
        $key = is_string($key) ? trim($key, " \t\r\n") : '';
        if ($key === '') {
            throw new SshKeyRejected('Paste an SSH public key.');
        }
        if (strlen($key) > self::MAX_KEY_BYTES) {
            throw new SshKeyRejected('SSH public key is too large (16 KiB maximum).');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $key) || ! preg_match('//u', $key) || ! preg_match(self::PATTERN, $key, $m)) {
            throw new SshKeyRejected('Unsupported or malformed SSH public key. Paste one line starting with ssh-ed25519, ssh-rsa, ecdsa-sha2-nistp256/384/521 or sk-ssh-ed25519@openssh.com.');
        }
        $blob = base64_decode($m[2], true);
        if ($blob === false || strlen($blob) < 4 + strlen($m[1])
            || unpack('N', substr($blob, 0, 4))[1] !== strlen($m[1]) || substr($blob, 4, strlen($m[1])) !== $m[1]) {
            throw new SshKeyRejected('SSH public key data is not valid for its key type.');
        }
        if ($m[1] === 'ssh-rsa' && strlen($m[2]) < self::MIN_RSA_BODY) {
            throw new SshKeyRejected('RSA keys must be at least 2048 bits. Use ssh-ed25519 or a larger RSA key.');
        }

        return $key;
    }

    /**
     * OpenSSH-style SHA256 fingerprint of a public key line, or null when it cannot be decoded.
     *
     * @param  mixed  $key  Public key line as stored by VirtFusion.
     */
    public static function fingerprint($key): ?string
    {
        if (! is_string($key) || ! preg_match('/^\S+ ([A-Za-z0-9+\/]+={0,3})(?:\s|$)/', trim($key), $m)) {
            return null;
        }
        $blob = base64_decode($m[1], true);

        return $blob === false || $blob === '' ? null : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Project a VirtFusion key list to what the browser may see.
     *
     * @param  array  $keys  Rows from GET /ssh_keys/user/{id} (data list).
     * @return array<int, array{id: int, name: string, fingerprint: string|null}>
     */
    public static function present(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (! is_array($key) || ! ServiceTools::matchesId($key['id'] ?? null, (int) ($key['id'] ?? 0))) {
                continue;
            }
            $id = (int) $key['id'];
            $name = is_string($key['name'] ?? null) && $key['name'] !== '' ? $key['name'] : 'Key #' . $id;
            $out[] = ['id' => $id, 'name' => $name, 'fingerprint' => self::fingerprint($key['publicKey'] ?? null)];
        }

        return $out;
    }

    /**
     * Map an SSH key action failure to a safe HTTP status and message.
     *
     * SshKeyRejected messages are customer-written and pass through as 400;
     * any other InvalidArgumentException is a generic 400. RuntimeExceptions raised by ConfigureService
     * with 403/404/409/502/503 carry customer-safe text. Anything else is 500.
     *
     * @return array{status: int, message: string}
     */
    public static function clientError(\Throwable $e): array
    {
        if ($e instanceof SshKeyRejected) {
            return ['status' => 400, 'message' => $e->getMessage()];
        }
        if ($e instanceof \InvalidArgumentException) {
            return ['status' => 400, 'message' => 'Invalid SSH key request.'];
        }
        if ($e instanceof \RuntimeException && in_array($e->getCode(), [403, 404, 409, 502, 503], true)) {
            return ['status' => (int) $e->getCode(), 'message' => $e->getMessage()];
        }

        return ['status' => 500, 'message' => 'Unable to update SSH keys right now.'];
    }
}
