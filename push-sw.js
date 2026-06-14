self.addEventListener('push', function(event) {
    console.log("--- PUSH RECEIVED ---");
    
    let data = {};
    if (event.data) {
        try {
            data = event.data.json();
            console.log("Payload data:", data);
        } catch (e) {
            console.error("JSON Parse Error:", e);
            data = { title: "New Message", body: event.data.text(), url: "/app.php" };
        }
    }

    // CALL NOTIFICATION
    if (data.type === 'incoming_call') {
        const title = data.title || "Incoming call";
        const iconToDisplay = data.icon || "/images/default-profile.png";
        const options = {
            body: data.body || "Tap to answer",
            icon: iconToDisplay,
            badge: "/images/badge.png",
            // Keep the notification visible until user interacts
            requireInteraction: true,
            // Ensure repeated pushes renotify and use a stable per-call tag
            renotify: true,
            tag: `incoming_call_${data.call_request_id || Date.now()}`,
            data: {
                url: data.url || "/app.php",
                call_request_id: data.call_request_id,
                caller_id: data.caller_id,
                caller_name: data.caller_name,
                caller_pic: data.caller_pic
            },
            actions: [
                { action: 'accept', title: 'Accept' },
                { action: 'reject', title: 'Reject' }
            ]
        };
        event.waitUntil(self.registration.showNotification(title, options));
    } else {
        const title = data.title || "New Message";
        // Ensure the icon URL is valid or fallback to default
        const iconToDisplay = data.icon || "/images/default-profile.png";

        const options = {
            body: data.body || "",
            icon: iconToDisplay,
            badge: "/images/badge.png", // Small icon for the notification bar
            data: {
                // This URL now contains the ?token=... from send_message.php
                url: data.url || "/app.php" 
            }
        };

        event.waitUntil(
            self.registration.showNotification(title, options)
        );
    }

    // Also notify any open tabs so they can refresh the chat list in real-time
    event.waitUntil(
        self.clients.matchAll({ type: "window", includeUncontrolled: true })
            .then(clients => {
                clients.forEach(client => {
                    client.postMessage({
                        type: "push_message",
                        payload: data
                    });
                });
            })
    );
});

// Diagnostic: notify server when this Service Worker is installed/activated
self.addEventListener('install', function(event) {
    try {
        event.waitUntil((async () => {
            try {
                await fetch('/sw_action_log.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'sw_install', scope: self.registration.scope || null })
                });
            } catch (e) {
                // ignore
            }
        })());
    } catch (e) {}
});

self.addEventListener('activate', function(event) {
    try {
        event.waitUntil((async () => {
            try {
                await fetch('/sw_action_log.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'sw_activate', scope: self.registration.scope || null })
                });
            } catch (e) {
                // ignore
            }
        })());
    } catch (e) {}
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    // The URL from data.url includes the authentication token
    const targetUrl = event.notification.data.url;

    // If it's a call and action is reject, just ping the app and exit
    if (event.action === 'reject') {
        event.waitUntil(
            clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
                clientList.forEach(client => {
                    client.postMessage({ type: 'call_rejected_from_notification', payload: event.notification.data });
                });
            })
        );
        // Log reject action for diagnostics
        try {
            const logUrl = (event.notification.data && event.notification.data.url) ? (new URL(event.notification.data.url, self.location.href)).origin + '/sw_action_log.php' : self.location.origin + '/sw_action_log.php';
            event.waitUntil(fetch(logUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reject', payload: event.notification.data || {} })
            }).catch(() => {}));
        } catch (e) {}
        return;
    }

    // If the user tapped Accept on the notification action, attempt to mark the
    // call request as accepted (using the short-lived token in the URL) and
    // then open/focus the app window. Also notify any open clients so the in-page
    // UI can render and auto-accept.
    if (event.action === 'accept') {
        event.waitUntil((async () => {
            let finalTarget = targetUrl;
            try {
                // Prefer the URL provided in the notification payload when available
                const target = event.notification.data && event.notification.data.url ? event.notification.data.url : targetUrl;
                const parsed = new URL(target, self.location.href);

                // Ensure the URL explicitly marks this as an incoming_call and an SW-originated accept
                parsed.searchParams.set('incoming_call', '1');
                parsed.searchParams.set('_sw_action', 'accept');
                finalTarget = parsed.toString();

                const token = parsed.searchParams.get('token');
                const callReqId = event.notification.data && event.notification.data.call_request_id ? event.notification.data.call_request_id : null;
                if (token && callReqId) {
                    try {
                        await fetch(parsed.origin + '/update_call_request.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ token: token, id: callReqId, status: 'accepted' })
                        });
                    } catch (e) {
                        console.error('SW: failed to update call_request on accept action', e);
                    }
                }
                // Post a lightweight diagnostic record to the server so we can
                // confirm the SW handled the action and what it attempted to open.
                try {
                    const logUrl = parsed.origin + '/sw_action_log.php';
                    await fetch(logUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'accept', call_request_id: callReqId, token_present: !!token, finalTarget })
                    }).catch(() => {});
                } catch (e) {
                    // ignore
                }
            } catch (err) {
                console.error('SW: error handling accept action', err);
            }

            // Focus or open the app window using the modified URL (finalTarget)
            try {
                const clientList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
                for (const client of clientList) {
                    if (client.url.includes('quilltalk.org') && 'navigate' in client) {
                        try {
                            const navigated = await client.navigate(finalTarget);
                            if (navigated && typeof navigated.focus === 'function') navigated.focus();
                        } catch (e) {}
                        // notify all clients after navigation attempt and mark this
                        // message as originating from the SW "accept" action so
                        // clients can distinguish navigation vs explicit accept.
                        const msgPayload = Object.assign({}, event.notification.data || {}, { _sw_action: 'accept' });
                        const updated = await clients.matchAll({ type: 'window', includeUncontrolled: true });
                        updated.forEach(c => {
                            try { c.postMessage({ type: 'call_accepted_from_notification', payload: msgPayload }); } catch (e) {}
                        });
                        return;
                    }
                }

                if (clients.openWindow) {
                    const win = await clients.openWindow(finalTarget);
                    // Give the new window a moment to initialize then post a message
                    setTimeout(async () => {
                        const msgPayload = Object.assign({}, event.notification.data || {}, { _sw_action: 'accept' });
                        const updated = await clients.matchAll({ type: 'window', includeUncontrolled: true });
                        updated.forEach(c => {
                            try { c.postMessage({ type: 'call_accepted_from_notification', payload: msgPayload }); } catch (e) {}
                        });
                    }, 500);
                }
            } catch (e) {
                console.error('SW: failed to open/navigate window for accept action', e);
            }
        })());
        return;
    }

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            // 1. Check if a tab with the app is already open
            for (const client of clientList) {
                // If the user is already on the site, navigate that tab to the token URL
                if (client.url.includes('quilltalk.org') && 'navigate' in client) {
                    return client.navigate(targetUrl).then(c => c.focus());
                }
            }

            // 2. If no tab is open, open a new one with the token
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
