/* Pterodactyl client websocket console: auth, output, commands, token refresh. */
(function () {
    'use strict';

    var root = document.getElementById('server-console');
    if (!root) {
        return;
    }

    var output = root.querySelector('[data-console-output]');
    var form = root.querySelector('[data-console-form]');
    var input = root.querySelector('[data-console-input]');
    var toggle = root.querySelector('[data-console-toggle]');
    var status = root.querySelector('[data-console-status]');
    var socket = null;
    var manualClose = false;

    function setStatus(message, tone) {
        status.textContent = message;
        status.dataset.tone = tone || '';
    }

    function append(line) {
        if (!line) {
            return;
        }
        var atBottom = output.scrollTop + output.clientHeight >= output.scrollHeight - 20;
        output.appendChild(document.createTextNode(String(line).replace(/\n$/, '') + '\n'));
        while (output.childNodes.length > 800) {
            output.removeChild(output.firstChild);
        }
        if (atBottom) {
            output.scrollTop = output.scrollHeight;
        }
    }

    function send(event, args) {
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({ event: event, args: args }));
            return true;
        }
        return false;
    }

    function fetchCredentials() {
        return fetch(root.dataset.tokenUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok || !body.socket || !body.token) {
                    throw new Error(body.error || root.dataset.errorText);
                }
                return body;
            });
        });
    }

    function connect() {
        manualClose = false;
        toggle.disabled = true;
        setStatus(root.dataset.connectingText, 'info');
        fetchCredentials().then(function (credentials) {
            socket = new WebSocket(credentials.socket);
            socket.addEventListener('open', function () {
                send('auth', [credentials.token]);
                setStatus(root.dataset.connectedText, 'ok');
                toggle.disabled = false;
                toggle.textContent = root.dataset.disconnectText;
                toggle.dataset.connected = '1';
                form.hidden = false;
                input.focus();
            });
            socket.addEventListener('message', function (message) {
                var payload;
                try {
                    payload = JSON.parse(message.data);
                } catch (error) {
                    return;
                }
                var event = payload.event;
                var args = payload.args || [];
                if (event === 'console output') {
                    append(args[0]);
                } else if (event === 'status') {
                    setStatus(args[0] || '', 'info');
                } else if (event === 'token expiring' || event === 'token expired') {
                    fetchCredentials().then(function (fresh) {
                        send('auth', [fresh.token]);
                    }).catch(function (error) {
                        append(error.message);
                    });
                }
            });
            socket.addEventListener('close', function () {
                socket = null;
                form.hidden = true;
                toggle.disabled = false;
                toggle.textContent = root.dataset.connectText;
                toggle.dataset.connected = '';
                setStatus(manualClose ? root.dataset.disconnectedText : root.dataset.lostText, manualClose ? '' : 'warn');
            });
            socket.addEventListener('error', function () {
                setStatus(root.dataset.errorText, 'bad');
            });
        }).catch(function (error) {
            toggle.disabled = false;
            setStatus(error.message, 'bad');
        });
    }

    function disconnect() {
        manualClose = true;
        if (socket) {
            socket.close();
        }
    }

    toggle.addEventListener('click', function () {
        if (socket && socket.readyState === WebSocket.OPEN) {
            disconnect();
        } else {
            connect();
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var command = input.value.trim();
        if (command === '') {
            return;
        }
        if (!send('send command', [command])) {
            append(root.dataset.errorText);
            return;
        }
        input.value = '';
    });
})();
