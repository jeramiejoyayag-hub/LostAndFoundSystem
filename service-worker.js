self.addEventListener("push", function(event) {
    if (!event.data) return;

    var payload;
    try {
        payload = event.data.json();
    } catch (error) {
        payload = { title: "Lost & Found", body: event.data.text() };
    }

    var options = {
        body: payload.body || "You have a new Lost & Found alert.",
        icon: payload.icon || "logo.jpg",
        tag: payload.tag || "lost-and-found-alert",
        data: { url: payload.url || "dashboard.php" }
    };

    event.waitUntil(self.registration.showNotification(payload.title || "Lost & Found", options));
});

self.addEventListener("notificationclick", function(event) {
    event.notification.close();
    var target = new URL(event.notification.data.url || "dashboard.php", self.registration.scope).href;

    event.waitUntil(clients.matchAll({ type: "window", includeUncontrolled: true }).then(function(clientList) {
        for (var i = 0; i < clientList.length; i++) {
            if (clientList[i].url.indexOf(self.registration.scope) === 0) {
                return clientList[i].navigate(target).then(function() {
                    return clientList[i].focus();
                });
            }
        }
        return clients.openWindow(target);
    }));
});
