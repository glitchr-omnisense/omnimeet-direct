<?php

namespace Omnimeet\Direct;

use Omnimeet\Config;
use Omnimeet\Direct\Action\CloseAction;
use Omnimeet\Direct\Action\FetchAction;
use Omnimeet\Direct\Action\JoinAction;
use Omnimeet\Direct\Action\OpenAction;
use Omnimeet\GatewayFactory;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Capabilities;

/**
 * From browser to browser: two people, their media encrypted end to end
 * (WebRTC, DTLS-SRTP), nobody in between, nothing recorded. The
 * application relays the handshake over HTTP and keeps it in the store it
 * gives; when a network forbids the direct way the media go through the
 * application's own TURN relay - still encrypted, never through a third
 * party.
 *
 *   options:
 *     turn_secret: '%env(TURN_SECRET)%'   # coturn's static-auth-secret: the temporary credentials are signed with it
 *     turn_urls: ['turn:turn.example.org:3478?transport=udp', 'turn:turn.example.org:3478?transport=tcp']
 *     stun_urls: []                       # none by default: no third party is asked where the browser is
 *     turn_ttl: 3600                      # seconds the credentials last
 *
 * No option is required: without a relay the two browsers connect
 * directly, or not at all. No callbacks: there is no provider to send any
 * (notify() is not supported).
 */
final class DirectGatewayFactory extends GatewayFactory
{
    private readonly SignalStoreInterface $signals;

    /** @param SignalStoreInterface|null $signals where the handshake waits; in this process's memory when none is given */
    public function __construct(?SignalStoreInterface $signals = null)
    {
        $this->signals = $signals ?? new InMemorySignalStore();
    }

    /** The handshake's rules on this factory's store: what the application's two endpoints (post, poll) call. */
    public function signaling(): Signaling
    {
        return new Signaling($this->signals);
    }

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnimeet.factory_name' => 'direct',
            'omnimeet.factory_title' => 'Direct',
            'omnimeet.required_options' => [],
            'omnimeet.capabilities' => new Capabilities(
                maxParticipants: 2,
                thirdParty: false,
                endToEnd: true,
                recording: false,
                screenSharing: true,
                chat: true,
                tokens: false,
                access: [AccessKind::ENGINE],
            ),
            'turn_secret' => null,
            'turn_urls' => [],
            'stun_urls' => [],
            'turn_ttl' => 3600,
            'omnimeet.api' => fn (Config $c) => new Api($this->signaling(), new TurnCredentials(
                null === $c['turn_secret'] || '' === $c['turn_secret'] ? null : (string) $c['turn_secret'],
                $c->list('turn_urls'),
                $c->list('stun_urls'),
                max(60, (int) $c['turn_ttl']),
            )),
            'omnimeet.action.open' => new OpenAction(),
            'omnimeet.action.join' => new JoinAction(),
            'omnimeet.action.close' => new CloseAction(),
            'omnimeet.action.fetch' => new FetchAction(),
        ]);
    }
}
