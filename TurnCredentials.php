<?php

namespace Omnimeet\Direct;

use Omnimeet\Exception\InvalidConfigException;

/**
 * Temporary TURN credentials, the "TURN REST API" coturn checks with
 * use-auth-secret: the username is "<expiry>:<who>", the password the
 * base64 HMAC-SHA1 of that username with the shared secret. Nothing to
 * store, nothing to revoke: they lapse by themselves (the ttl).
 */
class TurnCredentials
{
    private readonly ?string $secret;
    /** @var list<string> */
    private readonly array $turnUrls;
    /** @var list<string> */
    private readonly array $stunUrls;

    /**
     * @param list<string> $turnUrls
     * @param list<string> $stunUrls
     */
    public function __construct(
        #[\SensitiveParameter] ?string $secret = null,
        array $turnUrls = [],
        array $stunUrls = [],
        private readonly int $ttl = 3600,
    ) {
        $this->secret = $secret;
        $this->turnUrls = self::urls($turnUrls);
        $this->stunUrls = self::urls($stunUrls);
    }

    /**
     * From a direct gateway's options: turn_secret, turn_urls, stun_urls, turn_ttl.
     *
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options): static
    {
        $secret = $options['turn_secret'] ?? null;

        return new static(
            null === $secret || '' === $secret ? null : (string) $secret,
            (array) ($options['turn_urls'] ?? []),
            (array) ($options['stun_urls'] ?? []),
            (int) ($options['turn_ttl'] ?? 3600) ?: 3600,
        );
    }

    /**
     * A list given as one environment variable arrives as a single
     * comma-separated string: split here, at run time.
     *
     * @return list<string>
     */
    public static function urls(array $urls): array
    {
        $list = [];
        foreach ($urls as $url) {
            foreach (explode(',', (string) $url) as $one) {
                if ('' !== trim($one)) {
                    $list[] = trim($one);
                }
            }
        }

        return $list;
    }

    /** @return list<string> */
    public function turnUrls(): array
    {
        return $this->turnUrls;
    }

    /** @return list<string> */
    public function stunUrls(): array
    {
        return $this->stunUrls;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function isConfigured(): bool
    {
        return null !== $this->secret && '' !== $this->secret && [] !== $this->turnUrls;
    }

    /** @return array{username: string, credential: string, ttl: int} */
    public function issue(string $who, ?int $now = null): array
    {
        if (!$this->isConfigured()) {
            throw new InvalidConfigException('TURN is not configured: the direct gateway\'s turn_secret and turn_urls.');
        }
        $username = \sprintf('%d:%s', ($now ?? time()) + $this->ttl, preg_replace('/[^A-Za-z0-9_.\-]/', '', $who));

        return [
            'username' => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, (string) $this->secret, true)),
            'ttl' => $this->ttl,
        ];
    }

    /** Whether credentials were made with this secret and are still valid. */
    public function verify(string $username, string $credential, ?int $now = null): bool
    {
        $expiry = (int) strtok($username, ':');

        return $expiry >= ($now ?? time()) && hash_equals(base64_encode(hash_hmac('sha1', $username, (string) $this->secret, true)), $credential);
    }

    /**
     * RTCPeerConnection's iceServers: the TURN relay with fresh credentials,
     * the STUN servers if any (none by default: no third party is asked
     * where the browser is).
     *
     * @return list<array{urls: list<string>, username?: string, credential?: string}>
     */
    public function iceServers(string $who, ?int $now = null): array
    {
        $servers = [];
        if ([] !== $this->stunUrls) {
            $servers[] = ['urls' => $this->stunUrls];
        }
        if ($this->isConfigured()) {
            $credentials = $this->issue($who, $now);
            $servers[] = ['urls' => $this->turnUrls, 'username' => $credentials['username'], 'credential' => $credentials['credential']];
        }

        return $servers;
    }
}
