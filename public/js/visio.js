/*
 * omnimeet/direct - the engine of a meeting from browser to browser. No
 * dependency: RTCPeerConnection, the handshake relayed over HTTP by the
 * application (posted, then polled every second while the call is being set
 * up, every five once it runs - which is also the "I am here" a waiting
 * room can read), a data channel for the chat. The media go from browser
 * to browser, through the application's own TURN relay when a network
 * forbids the direct way; nothing passes through a third party.
 *
 * The page gives the engine a root element and, on it:
 *
 *   data-omnimeet-direct          marks the root
 *   data-role="host|guest"        the participant's side (Access options: role)
 *   data-signal-url               POST {type, payload} there; GET ?after=N answers
 *                                 {signals: [{id, type, payload}], host, guest, open, ended}
 *   data-ice-url                  GET answers {iceServers}: asked when joining, so the
 *                                 relay's credentials are fresh - or
 *   data-ice-servers              the same list, as JSON (Access options: iceServers)
 *   data-end-url, data-csrf       optional: the host's "end" is POSTed there (X-CSRF-Token)
 *   data-document-url             optional: a file dropped is POSTed there (file, title),
 *                                 the answer {id, url} is told to the other side
 *   data-relay="1"                every packet through the relay (so does ?relay=1)
 *
 * and inside it the elements it drives, each optional but the two videos:
 * [data-visio-local], [data-visio-remote], [data-visio-status],
 * [data-visio-join], [data-visio-mic], [data-visio-camera],
 * [data-visio-screen], [data-visio-hangup], [data-visio-file-label],
 * [data-visio-file], [data-visio-chat], [data-visio-chat-form],
 * [data-visio-input], [data-visio-messages], and a
 * <script type="application/json" data-visio-texts> of the words it says
 * (check, ready, denied, waiting_host, waiting_guest, guest_here,
 * connecting, connected, lost, ended, file_sent, file_received, you).
 *
 * The state is on the root, data-visio-state, and announced there as an
 * "omnimeet:state" event ({state}).
 *
 * The host offers, the guest answers. Whoever comes in says "hello": the
 * host answers a guest's hello with an offer, the guest answers a host's
 * hello with its own - so the order they arrive in does not matter, and a
 * page reloaded starts the handshake again from scratch. Each offer gets a
 * fresh connection on both sides; hellos that cross within three seconds
 * make one offer, not two.
 */
(function () {
    'use strict';

    function start(root) {
        if (root.dataset.visioBound) return;
        root.dataset.visioBound = '1';

        var isHost = root.dataset.role === 'host';
        var texts = {};
        try { texts = JSON.parse(root.querySelector('[data-visio-texts]').textContent); } catch (e) {}
        var $ = function (name) { return root.querySelector('[data-visio-' + name + ']'); };
        var statusEl = $('status'), localEl = $('local'), remoteEl = $('remote');
        var joinBtn = $('join'), micBtn = $('mic'), camBtn = $('camera'), screenBtn = $('screen');
        var hangBtn = $('hangup'), fileLabel = $('file-label'), fileInput = $('file');
        var chatBox = $('chat'), chatForm = $('chat-form'), chatInput = $('input'), messages = $('messages');

        var pc = null, channel = null, localStream = null, screenStream = null;
        var after = 0, joined = false, connected = false, over = false, timer = null, pending = [];
        var iceServers = [], offeredAt = 0;

        var relayOnly = root.dataset.relay === '1' || /[?&]relay=1/.test(window.location.search);
        var srClass = root.dataset.srClass || 'sr-only';

        function status(key) {
            root.dataset.visioState = key;
            if (statusEl && texts[key]) statusEl.textContent = texts[key];
            try { root.dispatchEvent(new CustomEvent('omnimeet:state', { bubbles: true, detail: { state: key } })); } catch (e) {}
        }
        function show(el, on) { if (el) el.hidden = !on; }

        function request(method, url, body, headers) {
            return fetch(url, {
                method: method,
                credentials: 'same-origin',
                headers: Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, headers || {}),
                body: body
            });
        }
        function send(type, payload) {
            return request('POST', root.dataset.signalUrl, JSON.stringify({ type: type, payload: payload === undefined ? null : payload }), { 'Content-Type': 'application/json' });
        }

        function addMessage(text, mine, link) {
            if (!messages) return;
            var li = document.createElement('li');
            li.className = mine ? 'is-mine' : 'is-theirs';
            var who = document.createElement('span');
            who.className = srClass;
            who.textContent = mine ? (texts.you || '') + ' : ' : '';
            li.appendChild(who);
            if (link) {
                var a = document.createElement('a');
                a.href = link; a.textContent = text; a.setAttribute('download', '');
                li.appendChild(a);
            } else {
                li.appendChild(document.createTextNode(text));
            }
            messages.appendChild(li);
            li.scrollIntoView({ block: 'nearest' });
        }

        function bindChannel(ch) {
            channel = ch;
            ch.onopen = function () { show(chatBox, true); show(fileLabel, true); };
            // The other side hung up or left: ask at once what happened rather than at the next slow poll.
            ch.onclose = function () { if (!over && channel === ch) { connected = false; poll().then(schedule); } };
            ch.onmessage = function (event) {
                var data;
                try { data = JSON.parse(event.data); } catch (e) { return; }
                if (data.t === 'msg') addMessage(String(data.text).slice(0, 1000), false);
                if (data.t === 'file') addMessage(texts.file_received || 'Document', false, isHost ? null : data.url);
            };
        }

        function closePeer() {
            if (channel) { try { channel.close(); } catch (e) {} channel = null; }
            if (pc) {
                pc.onicecandidate = pc.ontrack = pc.onconnectionstatechange = pc.ondatachannel = null;
                try { pc.close(); } catch (e) {}
                pc = null;
            }
            pending = [];
            connected = false;
        }

        function newPeer() {
            closePeer();
            // data-relay="1" or "?relay=1" forces every packet through the TURN relay: the way to check the relay itself works.
            pc = new RTCPeerConnection({ iceServers: iceServers, iceTransportPolicy: relayOnly ? 'relay' : 'all' });
            if (localStream) localStream.getTracks().forEach(function (track) { pc.addTrack(track, localStream); });
            pc.onicecandidate = function (event) { if (event.candidate) send('candidate', event.candidate.toJSON()); };
            pc.ontrack = function (event) { if (remoteEl.srcObject !== event.streams[0]) remoteEl.srcObject = event.streams[0]; };
            pc.ondatachannel = function (event) { bindChannel(event.channel); };
            pc.onconnectionstatechange = function () {
                if (!pc) return;
                if (pc.connectionState === 'connected') {
                    connected = true;
                    status('connected');
                    schedule();
                } else if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
                    connected = false;
                    status('lost');
                    schedule();
                    if (isHost && pc.connectionState === 'failed') offer(true);
                }
            };
            return pc;
        }

        function offer(restart) {
            if (!restart || !pc) {
                newPeer();
                bindChannel(pc.createDataChannel('chat'));
            }
            status('connecting');
            offeredAt = Date.now();
            return pc.createOffer(restart ? { iceRestart: true } : undefined)
                .then(function (description) { return pc.setLocalDescription(description); })
                .then(function () { return send('offer', pc.localDescription.toJSON()); });
        }

        function handle(signal) {
            var payload = signal.payload;
            if (signal.type === 'hello') {
                // The guest is there (or back): the host offers - once, even if two hellos cross.
                if (isHost) return pc && !connected && Date.now() - offeredAt < 3000 ? null : offer(false);
                // The host is there (or back) and has not offered yet: say hello again, it answers with its offer.
                return Date.now() - offeredAt < 3000 ? null : send('hello');
            }
            if (signal.type === 'offer' && !isHost) {
                offeredAt = Date.now();
                newPeer();
                status('connecting');
                var answering = pc;
                return pc.setRemoteDescription(payload)
                    .then(function () { return flush(); })
                    .then(function () { return answering.createAnswer(); })
                    .then(function (description) { return answering.setLocalDescription(description); })
                    .then(function () { if (pc === answering) return send('answer', answering.localDescription.toJSON()); });
            }
            if (signal.type === 'answer' && isHost && pc && pc.signalingState === 'have-local-offer') {
                return pc.setRemoteDescription(payload).then(flush);
            }
            if (signal.type === 'candidate' && payload) {
                if (pc && pc.remoteDescription) return pc.addIceCandidate(payload).catch(function () {});
                pending.push(payload);
                return null;
            }
            if (signal.type === 'bye') {
                closePeer();
                remoteEl.srcObject = null;
                status(isHost ? 'waiting_guest' : 'waiting_host');
            }
            return null;
        }
        function flush() {
            var list = pending; pending = [];
            return Promise.all(list.map(function (candidate) { return pc.addIceCandidate(candidate).catch(function () {}); }));
        }

        function poll() {
            if (over) return Promise.resolve();
            return request('GET', root.dataset.signalUrl + '?after=' + after)
                .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                .then(function (data) {
                    if (data.ended || !data.open) { finish(false); return null; }
                    var chain = Promise.resolve();
                    data.signals.forEach(function (signal) {
                        after = Math.max(after, signal.id);
                        chain = chain.then(function () { return handle(signal); }).catch(function () {});
                    });
                    if (!connected && root.dataset.visioState !== 'connecting') {
                        if (isHost) status(data.guest ? 'guest_here' : 'waiting_guest');
                        else status(data.host ? 'connecting' : 'waiting_host');
                    }
                    return chain;
                })
                .catch(function () {});
        }
        function schedule() {
            clearTimeout(timer);
            if (over || !joined) return;
            timer = setTimeout(function () { poll().then(schedule); }, connected ? 5000 : 1000);
        }

        function join() {
            if (joined) return;
            joined = true;
            show(joinBtn, false);
            show(hangBtn, true);
            status(isHost ? 'waiting_guest' : 'waiting_host');
            // What was said before this page came in belongs to an earlier handshake: skip it, then say hello.
            var given = [];
            try { given = JSON.parse(root.dataset.iceServers || '[]'); } catch (e) {}
            (root.dataset.iceUrl
                ? request('GET', root.dataset.iceUrl).then(function (response) { return response.ok ? response.json() : { iceServers: given }; })
                : Promise.resolve({ iceServers: given }))
                .then(function (data) { iceServers = data.iceServers || []; return request('GET', root.dataset.signalUrl + '?after=0'); })
                .then(function (response) { return response.ok ? response.json() : { signals: [] }; })
                .then(function (data) {
                    (data.signals || []).forEach(function (signal) { after = Math.max(after, signal.id); });
                    return send('hello');
                })
                .then(schedule, schedule);
        }

        function finish(tell) {
            if (over) return;
            over = true;
            clearTimeout(timer);
            if (tell) {
                send('bye');
                if (isHost && root.dataset.endUrl) request('POST', root.dataset.endUrl, null, { 'X-CSRF-Token': root.dataset.csrf || '' });
            }
            closePeer();
            [localStream, screenStream].forEach(function (stream) { if (stream) stream.getTracks().forEach(function (track) { track.stop(); }); });
            localEl.srcObject = remoteEl.srcObject = null;
            [micBtn, camBtn, screenBtn, hangBtn, fileLabel, joinBtn].forEach(function (el) { show(el, false); });
            status('ended');
        }

        function toggle(kind, button) {
            if (!localStream) return;
            var tracks = kind === 'audio' ? localStream.getAudioTracks() : localStream.getVideoTracks();
            var off = button.getAttribute('aria-pressed') !== 'true';
            tracks.forEach(function (track) { track.enabled = !off; });
            button.setAttribute('aria-pressed', off ? 'true' : 'false');
        }

        function shareScreen() {
            var sender = pc && pc.getSenders().find(function (s) { return s.track && s.track.kind === 'video'; });
            if (!sender) return;
            if (screenStream) {
                screenStream.getTracks().forEach(function (track) { track.stop(); });
                screenStream = null;
                sender.replaceTrack(localStream.getVideoTracks()[0] || null);
                screenBtn.setAttribute('aria-pressed', 'false');
                return;
            }
            navigator.mediaDevices.getDisplayMedia({ video: true }).then(function (stream) {
                screenStream = stream;
                var track = stream.getVideoTracks()[0];
                sender.replaceTrack(track);
                screenBtn.setAttribute('aria-pressed', 'true');
                track.onended = function () { if (screenStream) shareScreen(); };
            }).catch(function () {});
        }

        if (joinBtn) joinBtn.addEventListener('click', join);
        if (micBtn) micBtn.addEventListener('click', function () { toggle('audio', micBtn); });
        if (camBtn) camBtn.addEventListener('click', function () { toggle('video', camBtn); });
        if (screenBtn) screenBtn.addEventListener('click', shareScreen);
        if (hangBtn) hangBtn.addEventListener('click', function () { finish(true); });
        if (chatForm) chatForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var text = chatInput.value.trim();
            if (!text || !channel || channel.readyState !== 'open') return;
            channel.send(JSON.stringify({ t: 'msg', text: text }));
            addMessage(text, true);
            chatInput.value = '';
        });
        if (fileInput) fileInput.addEventListener('change', function () {
            var file = fileInput.files[0];
            if (!file) return;
            var form = new FormData();
            form.append('file', file);
            form.append('title', file.name);
            if (!root.dataset.documentUrl) return;
            request('POST', root.dataset.documentUrl, form, { 'X-CSRF-Token': root.dataset.csrf || '' })
                .then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); })
                .then(function (result) {
                    if (!result.ok) { addMessage(result.data.error || '!', true); return; }
                    addMessage((texts.file_sent || 'Document') + ' : ' + file.name, true);
                    if (channel && channel.readyState === 'open') channel.send(JSON.stringify({ t: 'file', id: result.data.id, url: result.data.url }));
                })
                .catch(function () {});
            fileInput.value = '';
        });
        window.addEventListener('pagehide', function () { if (joined && !over) { try { navigator.sendBeacon && send('bye'); } catch (e) {} } });

        if (!navigator.mediaDevices || !window.RTCPeerConnection) { status('denied'); return; }
        navigator.mediaDevices.getUserMedia({ audio: true, video: { width: { ideal: 1280 }, height: { ideal: 720 } } })
            .then(function (stream) {
                localStream = stream;
                localEl.srcObject = stream;
                status('ready');
                [joinBtn, micBtn, camBtn].forEach(function (el) { show(el, true); });
                show(screenBtn, !!navigator.mediaDevices.getDisplayMedia);
            })
            .catch(function () { status('denied'); });
    }

    function boot() { document.querySelectorAll('[data-omnimeet-direct]').forEach(start); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    // A page that swaps its content without reloading boots the engine again: OmnimeetDirect.boot(),
    // or the events such a page already sends.
    window.OmnimeetDirect = { boot: boot, start: start };
    ['transparent:load', 'turbo:load'].forEach(function (name) { window.addEventListener(name, boot); });
})();
