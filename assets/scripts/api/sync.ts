/**
 * Cross-tab sync: open tabs of this dashboard in the same browser tell each other what went stale.
 * Signals only, never data: each tab refetches for itself, with its own nonce and permissions.
 * The channel is scoped to the site, so subsites of a multisite on one domain stay apart.
 *
 * The Heartbeat (presence.ts) covers changes by other people, WP-CLI and AI clients; it skips your
 * own changes, so without this your other tabs would keep showing what you just fixed.
 */

export interface Signal {
	type: 'invalidate';
	prefix: string;
}

type Handler = (signal: Signal) => void;

const NAME = `onehop-redirect-checker:${window.onehopRedirectChecker?.siteUrl ?? window.location.origin}`;
const handlers = new Set<Handler>();
let channel: BroadcastChannel | null = null;

const isSignal = (data: unknown): data is Signal =>
	Boolean(data) && 'object' === typeof data && 'invalidate' === (data as Signal).type && 'string' === typeof (data as Signal).prefix;

const emit = (signal: Signal) => handlers.forEach((handler) => handler(signal));

const open = () => {
	if (channel || !('BroadcastChannel' in window)) {
		return;
	}
	channel = new BroadcastChannel(NAME);
	channel.onmessage = ({ data }: MessageEvent) => {
		if (isSignal(data)) {
			emit(data);
		}
	};
};

const close = () => {
	channel?.close();
	channel = null;
};

/** Tell the other tabs (never this one) that something changed. */
export const broadcast = (signal: Signal): void => {
	try {
		channel?.postMessage(signal);
	} catch {
		// A closed channel or an unsupported browser: the Heartbeat still catches up later.
	}
};

/** Listen for signals from the other tabs. Returns the unsubscribe. */
export const onSignal = (handler: Handler): (() => void) => {
	handlers.add(handler);
	return () => handlers.delete(handler);
};

open();

// An open channel can keep the page out of the back/forward cache: close it on the way out, and on
// the way back in reopen it and reload everything, as signals sent meanwhile were missed.
window.addEventListener('pagehide', close);
window.addEventListener('pageshow', (event: PageTransitionEvent) => {
	open();
	if (event.persisted) {
		emit({ type: 'invalidate', prefix: '' });
	}
});
