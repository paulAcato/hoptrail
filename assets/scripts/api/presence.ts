import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { invalidate } from './client';
import type { PresentUser } from './types';

interface Change {
	action: string;
	by: string;
	self: boolean;
	at: string | null;
}

interface Tick {
	others?: PresentUser[];
	version?: number;
	change?: Change;
}

/** jQuery as loaded by WordPress for the Heartbeat API (not bundled). */
type JQueryLike = (target: Document) => {
	on: (events: string, handler: (event: unknown, data: Record<string, unknown>) => void) => unknown;
	off: (events: string) => unknown;
};

const describe = ({ action, by }: Change): string => {
	const who = by || __('Someone', 'redirect-inspector');
	switch (action) {
		case 'scheduled-scan':
			return __('A scheduled scan finished. The dashboard is up to date.', 'redirect-inspector');
		/* translators: %s: name of an administrator. */
		case 'scan': return by ? sprintf(__('%s ran a scan. The dashboard is up to date.', 'redirect-inspector'), who) : __('A scan ran (WP-CLI or an AI client). The dashboard is up to date.', 'redirect-inspector');
		/* translators: %s: name of an administrator. */
		case 'fix': return sprintf(__('%s applied a fix.', 'redirect-inspector'), who);
		/* translators: %s: name of an administrator. */
		case 'migrate': return sprintf(__('%s migrated leftover rules.', 'redirect-inspector'), who);
		/* translators: %s: name of an administrator. */
		case 'delete': return sprintf(__('%s deleted a leftover rule.', 'redirect-inspector'), who);
		/* translators: %s: name of an administrator. */
		case 'purge': return sprintf(__('%s deleted leftover data.', 'redirect-inspector'), who);
		/* translators: %s: name of an administrator. */
		case 'settings': return sprintf(__('%s changed the schedule or retention settings.', 'redirect-inspector'), who);
		/* translators: %s: name of an administrator. */
		case 'flush': return sprintf(__('%s deleted logs.', 'redirect-inspector'), who);
		default: return __('Redirect Inspector was updated by someone else.', 'redirect-inspector');
	}
};

/**
 * Other administrators on this dashboard, and live updates when anyone changes something.
 * Runs on the WordPress Heartbeat (15 s here; WordPress slows it down in background tabs).
 */
export const usePresence = () => {
	const [others, setOthers] = useState<PresentUser[]>([]);
	const [note, setNote] = useState<string | null>(null);
	const version = useRef(window.redirectInspector?.stateVersion ?? 0);

	useEffect(() => {
		const jq = (window as unknown as { jQuery?: JQueryLike }).jQuery;
		if (!jq) {
			return undefined;
		}

		const send = (_event: unknown, data: Record<string, unknown>) => {
			data.redirect_inspector = { screen: window.redirectInspector?.mode ?? 'site' };
		};
		const tick = (_event: unknown, data: Record<string, unknown>) => {
			const payload = data?.redirect_inspector as Tick | undefined;
			if (!payload) {
				return;
			}
			setOthers(Array.isArray(payload.others) ? payload.others : []);

			if ('number' === typeof payload.version && payload.version > version.current) {
				version.current = payload.version;
				// Our own changes already refreshed what they touched.
				if (payload.change && !payload.change.self) {
					invalidate();
					const text = describe(payload.change);
					setNote(text);
					speak(text, 'polite');
				}
			}
		};

		jq(document).on('heartbeat-send.redirectInspector', send);
		jq(document).on('heartbeat-tick.redirectInspector', tick);
		// Every 15 s while this dashboard is open (WordPress slows it down in background tabs),
		// and announce ourselves right away instead of after the first interval.
		const heartbeat = (window as unknown as { wp?: { heartbeat?: { interval?: (speed: number) => void; connectNow?: () => void } } }).wp?.heartbeat;
		heartbeat?.interval?.(15);
		heartbeat?.connectNow?.();

		return () => {
			jq(document).off('heartbeat-send.redirectInspector');
			jq(document).off('heartbeat-tick.redirectInspector');
		};
	}, []);

	const dismiss = useCallback(() => setNote(null), []);

	return { others, note, dismiss };
};
