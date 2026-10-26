# omnimeet/direct

A video meeting **from browser to browser** for
[omnisense/omnimeet](https://github.com/glitchr-omnisense/omnimeet): two people, their media
encrypted end to end (WebRTC, DTLS-SRTP), nobody in between, nothing recorded. The application
relays the handshake over plain HTTP - no WebSocket server - and, when a network forbids the direct
way, the media go through a TURN relay of its own (coturn), still encrypted. The package calls
nothing: no key, no account, no outgoing request.

## Installation

```sh
composer require omnimeet/direct
```

PHP 8.2 or later, and `omnisense/omnimeet`: nothing else. No framework, no HTTP client - this
gateway calls nothing.

```php
use Omnimeet\Direct\DirectGatewayFactory;

$factory = new DirectGatewayFactory($signalStore);
$gateway = $factory->create(['turn_secret' => '...', 'turn_urls' => ['turn:turn.example.org:3478']]);
```

## Credentials

None for the call. For the relay, coturn's `static-auth-secret` (`turn_secret`): the participants'
temporary credentials are signed with it on this side.

## Options

`new DirectGatewayFactory(?SignalStoreInterface $signals = null)` - the store the handshake waits
in (`InMemorySignalStore` when none is given); `$factory->signaling()` gives the `Signaling` the
application's endpoints call. Options of `create()`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `turn_secret` | ?string | `null` | no | coturn's `static-auth-secret` |
| `turn_urls` | list or comma-separated string | `[]` | no | the relay's addresses; with `turn_secret`, given to each participant with fresh credentials |
| `stun_urls` | list or comma-separated string | `[]` | no | STUN servers; none by default: no third party is asked where the browser is |
| `turn_ttl` | int | `3600` | no | seconds the credentials last (at least 60); also `Access::$expiresAt` |

## Capabilities

`maxParticipants` 2, `thirdParty` false, `endToEnd` true, `recording` false, `screenSharing` true,
`chat` true, `tokens` false, `access` `[ENGINE]`.

| Request | Supported |
|---|---|
| `Open` | yes: the room is the meeting's key; status OPEN |
| `Join` | yes: an `ENGINE` access, `visio.js` |
| `Close` | yes: the room's handshake purged; status ENDED |
| `Fetch` | yes: read from the handshake |
| `Notify` | no: there is nobody to call back |

`join()`'s options: `room` (the reference), `participant` (its id), `role` (`host` or `guest`),
`iceServers` (STUN servers if any, then the relay with credentials made for this participant: a
username `<expiry>:<id>` - the id stripped to `A-Z a-z 0-9 _ . -` - and the base64 HMAC-SHA1 of it).
The addresses of the signal endpoints are the application's: it adds them when it renders the
page.

## How a call is set up

The media go from one browser to the other. To find each other the two exchange a few messages -
the handshake - through the application, over plain HTTP:

1. each page asks `join()` for its `Access`: the engine, its side (`host` offers, `guest`
   answers) and the ICE servers;
2. whoever comes in posts `hello`; the host answers a guest's hello with an `offer`, the guest
   answers the offer with an `answer`, both post their ICE `candidate`s as they find them;
3. each page asks, every second while the call is being set up and every five once it runs, for
   what the other posted since the last message it read;
4. the browsers connect; from there nothing of the call passes through the application.

The order they arrive in does not matter, and a page reloaded starts the handshake again.

## The store

`Signaling` keeps the handshake in a `SignalStoreInterface` you implement on what you store with
- a table, a cache:

```php
interface SignalStoreInterface
{
    /** @return int the number given to the message: greater than every one before it in the room */
    public function append(string $room, string $sender, string $type, string $payload): int;

    /** @return list<Signal> the room's messages numbered above $after, oldest first, without $except's own */
    public function read(string $room, int $after = 0, ?string $except = null, int $limit = 200): array;

    /** @return int how many messages went */
    public function purge(string $room): int;
}
```

A room is named by the meeting's reference (for this gateway: the key you opened it with), a
participant by the opaque identifier you gave (`Participant::$id`). A few kilobytes per call,
gone when the meeting closes. `InMemorySignalStore` keeps them in the PHP process: for tests and
long-running servers - between two requests of an ordinary application it forgets everything.

With PDO, the whole of it:

```php
final class PdoSignalStore implements SignalStoreInterface
{
    public function __construct(private readonly \PDO $pdo) {}   // signal(id AUTO_INCREMENT, room, sender, type, payload)

    public function append(string $room, string $sender, string $type, string $payload): int
    {
        $this->pdo->prepare('INSERT INTO signal (room, sender, type, payload) VALUES (?, ?, ?, ?)')->execute([$room, $sender, $type, $payload]);

        return (int) $this->pdo->lastInsertId();
    }

    public function read(string $room, int $after = 0, ?string $except = null, int $limit = 200): array
    {
        $rows = $this->pdo->prepare('SELECT id, sender, type, payload FROM signal WHERE room = ? AND id > ? AND sender <> ? ORDER BY id LIMIT '.$limit);
        $rows->execute([$room, $after, $except ?? '']);

        return array_map(static fn (array $r) => new Signal((int) $r['id'], $room, $r['sender'], $r['type'], $r['payload']), $rows->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function purge(string $room): int
    {
        $delete = $this->pdo->prepare('DELETE FROM signal WHERE room = ?');
        $delete->execute([$room]);

        return $delete->rowCount();
    }
}
```

## The two endpoints

The application gives the engine one address, answering two methods. **It checks who asks**: its
two participants, while the room is open - `Signaling` relays for whoever it is called for.

```php
$signaling = $factory->signaling();            // or new Signaling($signalStore)

// POST: {"type": "hello|offer|answer|candidate|bye", "payload": ...}
try {
    $signal = $signaling->send($room, $participantId, $body['type'], json_encode($body['payload'] ?? null));
} catch (InvalidSignalException $e) {
    // 400: an unknown message, not JSON, more than 64 kB
}
if ($signal->connects()) {
    // an answer was given: the two are talking - note it if you keep track
}
// 201 {"id": $signal->id}

// GET ?after=N - also the page's "I am here"
// 200 {"signals": [...], "host": bool, "guest": bool, "open": bool, "ended": bool}
$signals = $signaling->receive($room, $participantId, (int) $after);
```

`signals` is `[{id, type, payload}]`. `host` and `guest` say who is there (your own record of
who asked recently): the engine shows "waiting for the host", "the guest is in the waiting room".
`ended: true` or `open: false` stops the page.

The rules `Signaling` holds: five messages and no other; one JSON document of at most 64 kB;
nobody hears themselves; in the order sent, from the number given, 200 at most at once; an
`answer` connects, a `hello` or a `bye` means the handshake starts again or stopped;
`purge($room)` when the room closes - `close()` on the gateway does it.

`fetch()` reads the same handshake: `present` are those who said something and have not said
goodbye since, the status is `LIVE` once an answer was given. A closed room has no trace: a
meeting given as `ENDED`, or whose end is past, reads `ENDED`.

## The engine

`public/js/visio.js`: `RTCPeerConnection`, the handshake through the address above, mute, camera
off, screen sharing (`getDisplayMedia`), a chat on a data channel (never stored). No dependency.
Serve the file (`$access->script`) and give it a root:

```html
<section data-omnimeet-direct
         data-role="host"                                  <!-- $access->options['role'] -->
         data-signal-url="/meetings/TOKEN/signal"
         data-ice-servers='[...]'                          <!-- json_encode($access->options['iceServers']) -->
         data-end-url="/meetings/TOKEN/end" data-csrf="...">
    <p data-visio-status role="status"></p>
    <video data-visio-remote autoplay playsinline></video>
    <video data-visio-local autoplay playsinline muted></video>
    <button type="button" data-visio-join hidden>Enter</button>
    <button type="button" data-visio-mic aria-pressed="false" hidden>Mute</button>
    <button type="button" data-visio-camera aria-pressed="false" hidden>Camera off</button>
    <button type="button" data-visio-screen aria-pressed="false" hidden>Share the screen</button>
    <button type="button" data-visio-hangup hidden>Leave</button>
    <section data-visio-chat hidden>
        <ol data-visio-messages></ol>
        <form data-visio-chat-form><input data-visio-input maxlength="1000"> <button>Send</button></form>
    </section>
    <script type="application/json" data-visio-texts>{"check": "...", "ready": "...", "connected": "..."}</script>
</section>
<script src="/the/engine/visio.js" defer></script>
```

| On the root | |
|---|---|
| `data-omnimeet-direct` | marks it |
| `data-role` | `host` or `guest` |
| `data-signal-url` | the address of the two endpoints |
| `data-ice-servers` | the ICE servers, as JSON - or `data-ice-url`, an address answering `{iceServers}`, asked when joining so the relay's credentials are fresh |
| `data-end-url`, `data-csrf` | optional: the host's "end" is POSTed there, with `X-CSRF-Token` |
| `data-document-url` | optional: a file dropped is POSTed there (`file`, `title`); the answer `{id, url}` is told to the other side, who gets a link |
| `data-relay="1"` | every packet through the relay; `?relay=1` in the page's address does the same |
| `data-sr-class` | the class of text for screen readers only (`sr-only`) |

Inside the root, besides the elements of the markup above: `[data-visio-file]` (a file input) and
`[data-visio-file-label]`, for `data-document-url`. `window.OmnimeetDirect` is `{boot, start}`.

Every element inside is optional but the two videos. The words come from `[data-visio-texts]`:
`check`, `ready`, `denied`, `waiting_host`, `waiting_guest`, `guest_here`, `connecting`,
`connected`, `lost`, `ended`, `file_sent`, `file_received`, `you`. The state is on the root
(`data-visio-state`) and announced there as `omnimeet:state` (`{state}`). A page that swaps its
content without reloading calls `OmnimeetDirect.boot()`.

The page needs `Permissions-Policy: camera=(self), microphone=(self), display-capture=(self)`
and a secure context (HTTPS, or `localhost`).

## The relay (TURN)

Two browsers behind strict networks cannot always reach each other directly. A relay of your own
carries the (still encrypted) media then; without one such a call fails.
`TurnCredentials` issues the temporary credentials coturn checks with `use-auth-secret`: the
username is `<expiry>:<who>`, the password the base64 HMAC-SHA1 of it with the shared secret.
Nothing is stored; they lapse after `turn_ttl`. `join()` puts them in the participant's
`iceServers`.

```yaml
# docker-compose.yml
coturn:
    image: coturn/coturn:4.6
    ports: ["3478:3478/udp", "3478:3478/tcp", "21080-21099:21080-21099/udp"]
    command: [-n, --listening-port=3478, --min-port=21080, --max-port=21099, --use-auth-secret,
              "--static-auth-secret=${TURN_SECRET}", "--realm=${TURN_REALM}", --no-cli, --no-tls, --no-dtls,
              --no-multicast-peers, --denied-peer-ip=10.0.0.0-10.255.255.255]
```

The relay ports (`--min-port`/`--max-port`, published as they are) sit below 32768: coturn's default
49152-65535 is the host's ephemeral range (macOS from 49152, Linux 32768-60999), where an outgoing
socket of the host may hold a port when the container starts, and publishing it fails. Give each
site on a machine a range of its own.

```php
$turn = TurnCredentials::fromOptions($registry->options('direct'));
$turn->isConfigured();                                   // a secret and at least one address
$turn->iceServers('u42');                                // [{urls, username, credential}]
$turn->verify($username, $credential);                   // made with this secret, and still valid
```

`?relay=1` on the page forces every packet through the relay: the way to check the relay itself.
No STUN server is configured by default (`stun_urls`): none of a third party's is asked.

## Example

The whole flow on the PHP side - open, join with the relay, the handshake through the endpoints'
calls, fetch, a refused message, close:

```php
<?php // direct.php

require __DIR__.'/vendor/autoload.php';

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\Exception\InvalidSignalException;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Direct\TurnCredentials;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;

$factory = new DirectGatewayFactory(new InMemorySignalStore());
$gateway = $factory->create(['turn_secret' => 'coturn-static-auth-secret', 'turn_urls' => 'turn:turn.example.org:3478?transport=udp,turn:turn.example.org:3478?transport=tcp', 'turn_ttl' => 600]);

$meeting = $gateway->open(new Meeting('k4f9d2a7c1e8b3f6'));
$access = $gateway->join($meeting, Participant::host('u1'));
echo $access->kind->value, ' ', basename($access->script), ' until ', $access->expiresAt->format('H:i:s'), "\n";
echo json_encode($access->options['iceServers'][0], JSON_UNESCAPED_SLASHES), "\n";
$turn = TurnCredentials::fromOptions(['turn_secret' => 'coturn-static-auth-secret', 'turn_urls' => ['turn:turn.example.org:3478']]);
var_dump($turn->verify($access->options['iceServers'][0]['username'], $access->options['iceServers'][0]['credential']));

// What the application's endpoint does for each POST and GET
$signaling = $factory->signaling();
$signaling->send($meeting->reference, 'u2', 'hello');
$signaling->send($meeting->reference, 'u1', 'offer', '{"type":"offer","sdp":"v=0..."}');
echo json_encode($signaling->receive($meeting->reference, 'u2')), "\n";
$answer = $signaling->send($meeting->reference, 'u2', 'answer', '{"type":"answer","sdp":"v=0..."}');
var_dump($answer->connects());
$state = $gateway->fetch($meeting);
echo $state->status->value, ', present: ', implode(', ', array_map(fn ($p) => $p->id, $state->present)), "\n";
try {
    $signaling->send($meeting->reference, 'u2', 'renegotiate');
} catch (InvalidSignalException $e) {
    echo $e->getMessage(), "\n";
}
echo $gateway->close($meeting)->status->value, ', ', count($signaling->receive($meeting->reference, 'u1')), " message(s) left\n";
```

```
$ php direct.php
engine visio.js until 09:54:14
{"urls":["turn:turn.example.org:3478?transport=udp","turn:turn.example.org:3478?transport=tcp"],"username":"1793008454:u1","credential":"BV94caMIQhCOOyfH7BZkATh/pBw="}
bool(true)
[{"id":2,"type":"offer","payload":{"type":"offer","sdp":"v=0..."}}]
bool(true)
live, present: u2, u1
Unknown signal "renegotiate".
ended, 0 message(s) left
```

(run on 2026-10-26, PHP 8.4.27, no network; the credentials change with the time)

## Errors

- `InvalidSignalException` (`Omnimeet\Direct\Exception`, an `\InvalidArgumentException` and an
  `OmnimeetException`): `Signaling::send()` with no room or sender, an unknown type, a payload that
  is not JSON or larger than 64 kB. Answer the page with a 400.
- `InvalidConfigException`: `join()`, `close()`, `fetch()` of a meeting without a reference;
  `TurnCredentials::issue()` without a secret or an address.
- `NotSupportedException`: `notify()`.

## Verified, and not

A real call between two headless Chromes with fake cameras, on `omnibase/office`'s room in the
Maison de santé site, on 2026-10-05: direct (host candidates on both sides, video decoded both
ways, the chat, the end), then the same appointment with `?relay=1` through a local coturn (relay
candidates on both sides). And the same between two tabs of the harness's demo, in plain PHP.

Not verified: a relay other than coturn; browsers other than Chrome in those runs.

## What it is not

A group call, a recording, a WebSocket server; nor a teleconsultation solution listed by a health
authority - an application says what its trade requires.

## Responsibility

The package reaches no service: the call goes between the participants' browsers and the
application's own relay. The [Responsibility section of omnisense/omnimeet's README](https://github.com/glitchr-omnisense/omnimeet#responsibility)
applies: an independent package, MIT-licensed, published free of charge, with no liability for its
use; an application says what its trade requires of a call.
