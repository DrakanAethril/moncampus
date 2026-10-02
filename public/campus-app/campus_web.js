// What the browser does where the phone app has a plugin or dart:io - called by
// lib/services/platform_bridge_web.dart, loaded by index.html before the app.
'use strict';

window.campusWeb = {
    // The live quiz's Mercure stream. `http` cannot read it in a browser (its client hands back a
    // response only once it has ended, and this one never does), and EventSource cannot send the
    // Authorization header the subscriber token rides in - so fetch, read as it arrives. Each
    // event's data goes to onMessage; onEnd gets null when the server closed the stream, a reason
    // when it failed, and nothing at all after close(): the caller asked for that end.
    subscribe(url, token, onMessage, onEnd) {
        const controller = new AbortController();
        (async () => {
            try {
                const response = await fetch(url, {
                    headers: { Authorization: 'Bearer ' + token, Accept: 'text/event-stream' },
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (!response.ok || !response.body) {
                    onEnd('HTTP ' + response.status);

                    return;
                }
                const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
                let pending = '';
                let data = [];
                for (;;) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    pending += value;
                    let end;
                    while ((end = pending.indexOf('\n')) >= 0) {
                        const line = pending.slice(0, end).replace(/\r$/, '');
                        pending = pending.slice(end + 1);
                        if (line === '') {
                            if (data.length) onMessage(data.join('\n'));
                            data = [];
                        } else if (line.startsWith('data:')) {
                            data.push(line.slice(5).replace(/^ /, ''));
                        }
                        // 'id:'/'retry:'/'event:' lines and ':' keep-alive comments are ignored,
                        // as on the phone (QuizLiveService).
                    }
                }
                onEnd(null);
            } catch (error) {
                if (!controller.signal.aborted) onEnd(String(error));
            }
        })();

        return { close: () => controller.abort() };
    },

    // A document whose address the API gives only once asked (it records the opening first). A
    // browser - Safari above all - refuses a tab opened once that answer is in, the tap that asked
    // for it being over by then: so the tab is opened now, during the tap, and sent to the address
    // later. Not 'noopener' here, which would hand back no window to send; the link is cut in go().
    openPending() {
        const tab = window.open('about:blank', '_blank');

        return {
            go(url) {
                if (tab && !tab.closed) {
                    tab.opener = null;
                    tab.location.href = url;
                } else {
                    // Blocked all the same: one last try, then the app's own tab.
                    if (!window.open(url, '_blank', 'noopener')) window.location.assign(url);
                }
            },
            close() {
                if (tab && !tab.closed) tab.close();
            },
        };
    },

    // A file the app downloaded behind its Bearer token (a Courrier pro attachment): handed to the
    // browser as a download, the phone's « open with » having no equivalent here.
    saveFile(name, mime, bytes) {
        const url = URL.createObjectURL(new Blob([bytes], { type: mime || 'application/octet-stream' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    },

    // The magic link opens /campus-app/?login=<token>: once read, the token leaves the address bar,
    // so that a reload or a bookmark does not present a used link again.
    forgetLoginToken() {
        const url = new URL(window.location.href);
        url.searchParams.delete('login');
        window.history.replaceState(window.history.state, '', url.href);
    },
};
