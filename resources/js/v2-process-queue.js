export const ACTIVE_MS = 8000;
export const IDLE_MS = 60000;
const sessions = new Map();
// A request already sent by the outgoing shell must finish before its replacement reads.
const requestGate = {flight: null};

export function queueState(saved = {}) {
    const seen = new Set(Array.isArray(saved.seen) ? saved.seen.slice(-512) : []);
    const pending = new Map(Array.isArray(saved.pending) ? saved.pending.slice(-512) : []);
    return {
        observe(jobs, opened = false) {
            for (const job of jobs) {
                if (!job.terminal_key || seen.has(job.terminal_key)) continue;
                seen.add(job.terminal_key);
                pending.set(job.terminal_key, job.status);
            }
            if (opened) pending.clear();
            while (seen.size > 512) seen.delete(seen.values().next().value);
            while (pending.size > 512) pending.delete(pending.keys().next().value);
        },
        acknowledge() { pending.clear(); },
        indicator(active) {
            return active ? 'active' : [...pending.values()].includes('failed') ? 'failed' : pending.size ? 'ready' : 'idle';
        },
        count: () => pending.size,
        save: () => ({seen: [...seen], pending: [...pending]}),
    };
}

export function queuePoller({read, apply, error, visible, alive, schedule = setTimeout, cancel = clearTimeout, gate = requestGate}) {
    let timer = null, running = false, requested = false, stopped = false, active = false;
    const clear = () => { if (timer !== null) cancel(timer); timer = null; };
    const valid = () => !stopped && alive();
    const refresh = async () => {
        clear();
        if (!valid() || !visible()) return;
        if (running) { requested = true; return; }
        running = true;
        let failed = false;
        try {
            // Recheck after every await: rapid Back/Forward may dispose this owner while waiting.
            while (gate.flight) { await gate.flight.catch(() => {}); }
            if (!valid() || !visible()) return;
            const flight = Promise.resolve().then(read);
            gate.flight = flight;
            let snapshot;
            try { snapshot = await flight; } finally { if (gate.flight === flight) gate.flight = null; }
            if (!valid()) return;
            active = !!snapshot.has_active;
            apply(snapshot);
        } catch (_) {
            failed = true;
            if (valid()) error();
        } finally {
            running = false;
            if (valid() && visible()) {
                const delay = requested ? 0 : !failed && active ? ACTIVE_MS : IDLE_MS;
                requested = false;
                timer = schedule(refresh, delay);
            }
        }
    };
    return {
        refresh,
        visibility() { clear(); if (visible()) void refresh(); },
        destroy() { stopped = true; requested = false; clear(); },
    };
}

export function mountProcessQueue(ctx, win = window, doc = document) {
    const root = ctx.root, select = selector => root.querySelector(selector);
    const key = `metkurd:v2:process-queue:${root.dataset.customer}`;
    if (!sessions.has(key)) {
        let saved;
        try { saved = JSON.parse(win.sessionStorage.getItem(key) || '{}'); } catch (_) { /* Private browsing. */ }
        sessions.set(key, queueState(saved || {}));
    }
    const state = sessions.get(key), toggle = select('[data-queue-toggle]'), copy = select('[data-queue-copy]').dataset;
    let snapshot = {jobs: [], has_active: false}, rendered = null, opened = false;
    const persist = () => { try { win.sessionStorage.setItem(key, JSON.stringify(state.save())); } catch (_) { /* In-memory fallback. */ } };
    const indicator = () => {
        const status = state.indicator(snapshot.has_active), count = state.count();
        toggle.dataset.state = status;
        select('[data-queue-indicator]').textContent = copy[status];
        const badge = select('[data-queue-new]');
        badge.hidden = !count;
        badge.textContent = count;
        badge.setAttribute('aria-label', `${count} ${copy.new}`);
        persist();
    };
    const apply = data => {
        snapshot = data;
        state.observe(data.jobs, opened);
        indicator();
        select('[data-queue-error]').hidden = true;
        const serialized = JSON.stringify(data.jobs);
        if (serialized !== rendered) {
            const list = select('[data-queue-list]');
            const rows = data.jobs.map(job => {
                const row = select('[data-queue-row]').content.firstElementChild.cloneNode(true);
                row.href = job.url;
                row.dataset.status = job.status;
                row.querySelector('[data-queue-label]').textContent = job.label;
                row.querySelector('[data-queue-time]').textContent = job.when;
                row.querySelector('[data-queue-status]').textContent = job.status_label;
                return row;
            });
            list.replaceChildren(...rows);
            rendered = serialized;
        }
        select('[data-queue-empty]').hidden = data.jobs.length > 0;
        select('[data-queue-more]').hidden = !data.truncated;
    };
    try { apply(JSON.parse(root.dataset.snapshot)); } catch (_) { /* Refresh restores an expired history snapshot. */ }
    const poller = queuePoller({
        read: () => ctx.component().refreshQueue(), apply,
        error: () => { select('[data-queue-error]').hidden = false; },
        visible: () => !doc.hidden, alive: ctx.alive,
        schedule: (cb, ms) => win.setTimeout(cb, ms), cancel: id => win.clearTimeout(id),
    });
    ctx.listen(doc, 'visibilitychange', () => poller.visibility());
    ctx.listen(win, 'metkurd:job-submitted', () => { void poller.refresh(); });
    ctx.listen(toggle, 'shown.bs.dropdown', () => { opened = true; state.acknowledge(); indicator(); void poller.refresh(); });
    ctx.listen(toggle, 'hidden.bs.dropdown', () => { opened = false; });
    ctx.on('customerPlanUpdated', async () => {
        try {
            const limit = await ctx.component().refreshQueueLimit();
            if (ctx.alive()) { select('[data-queue-limit]').textContent = limit; void poller.refresh(); }
        } catch (_) { /* Existing allowance remains until the next shell mount. */ }
    });
    ctx.cleanup(() => poller.destroy());
    void poller.refresh();
    return {destroy: () => poller.destroy()};
}

if (typeof window !== 'undefined') {
    (window.MetKurdV2Pages ||= []).push({key: 'process-queue', selector: '[data-process-queue]', boot: ctx => mountProcessQueue(ctx)});
}
