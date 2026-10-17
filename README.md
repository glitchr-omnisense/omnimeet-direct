# omnimeet/direct

A video meeting **from browser to browser**, for [omniscient/omnimeet](https://github.com/glitchr-studio/omnimeet):
two people, their media encrypted end to end (WebRTC, DTLS-SRTP), nobody in between, nothing
recorded. The application relays the handshake over plain HTTP - no WebSocket server to run - and,
when a network forbids the direct way, the media go through a TURN relay of your own: still
encrypted, never through a third party. No key, no account, no outgoing call.

```php
$factory = new DirectGatewayFactory($signalStore);         // where the handshake waits: yours
$gateway = $factory->create(['turn_secret' => $secret, 'turn_urls' => ['turn:turn.example.org:3478']]);

$meeting = $gateway->open(new Meeting($token));            // the room is the meeting's key
$access = $gateway->join($meeting, Participant::host('u42'));
$access->script;                                           // public/js/visio.js: the engine, to serve
$access->options;                                          // room, participant, role, iceServers (fresh TURN credentials)

$signaling = $factory->signaling();                        // the application's two endpoints call it:
$signaling->send($room, 'u42', 'offer', $json);            //   POST: a message of the handshake
$signaling->receive($room, 'u7', $after);                  //   GET: what the other sent since

$gateway->close($meeting);                                 // the handshake is purged
```

```yaml
omnimeet:
    gateways:
        direct:
            factory: direct
            options:
                turn_secret: '%env(TURN_SECRET)%'      # coturn's static-auth-secret
                turn_urls: '%env(TURN_URLS)%'          # "turn:host:3478?transport=udp,turn:host:3478?transport=tcp"
                stun_urls: []                          # none by default: no third party is asked where the browser is
                turn_ttl: 3600                         # seconds the credentials last
```

`maxParticipants: 2`, `thirdParty: false`, `endToEnd: true`, `recording: false`. It does `open`,
`join`, `close`, `fetch`; not `notify` - there is nobody to call back.

What is in the package:

- `Signaling` - the handshake's rules, on a `SignalStoreInterface` the application implements
  (three methods; `InMemorySignalStore` for tests);
- `TurnCredentials` - temporary credentials for coturn's `use-auth-secret` (the "TURN REST API");
- `public/js/visio.js` - the engine: no dependency, 340 lines;
- the gateway itself (`DirectGatewayFactory`).

[Documentation](docs/index.md): the store, the two endpoints, the engine's markup, the relay.

It was the video room of [`omnibase/office`](https://github.com/glitchr-studio/omnibase-office),
which now uses it from here: the room, its waiting room and its pages stayed there.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
