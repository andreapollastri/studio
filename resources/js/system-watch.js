/**
 * Administration → System follows an update live: it asks the updater's state
 * every few seconds while one runs (and now and then otherwise), keeps asking
 * through the maintenance of the switch (503/502 just mean "wait"), and
 * reloads onto the new release when the update has finished.
 */
function systemWatch({ url, running, finishedAt, serverFinishedAt }) {
    return {
        live: running,
        maintenance: false,
        status: null,
        message: null,
        log: [],
        timer: null,
        lastFinished: finishedAt,
        lastServerFinished: serverFinishedAt,

        init() {
            this.schedule();
            window.addEventListener('system-update-started', () => {
                this.live = true;
                this.schedule(1500);
            });
        },

        destroy() {
            clearTimeout(this.timer);
        },

        schedule(delay = null) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.tick(), delay ?? (this.live ? 3000 : 30000));
        },

        async tick() {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                const json = response.ok && (response.headers.get('content-type') ?? '').includes('json') ? await response.json() : null;

                if (!json) {
                    // Maintenance (503), a release switching (502), a login page: wait.
                    this.maintenance = this.live;
                } else {
                    this.maintenance = false;
                    // Studio's update first; the server's packages when only they are on the move.
                    const server = json.server ?? {};
                    const studioRunning = json.status === 'running';
                    const serverRunning = server.status === 'running';
                    const shown = studioRunning || !serverRunning ? json : server;
                    this.status = shown.status;
                    this.message = shown.message;
                    this.log = shown.log ?? [];

                    const finished = (json.finished_at && json.finished_at !== this.lastFinished)
                        || (server.finished_at && server.finished_at !== this.lastServerFinished);
                    this.lastFinished = json.finished_at ?? this.lastFinished;
                    this.lastServerFinished = server.finished_at ?? this.lastServerFinished;

                    if (studioRunning || serverRunning) {
                        this.live = true;
                    } else if (this.live && finished) {
                        // Done (or rolled back): show what serves now.
                        setTimeout(() => window.location.reload(), 1200);
                        return;
                    } else if (finished) {
                        // Something finished on its own (the nightly run): draw the page again.
                        this.$wire.$refresh();
                    }
                }
            } catch {
                this.maintenance = this.live;
            }
            this.schedule();
        },
    };
}

const register = () => window.Alpine.data('systemWatch', systemWatch);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
