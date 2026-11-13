import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { addAction, removeAction } from '@wordpress/hooks';
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

const describe = ({ action, by }: Change): string => {
	const who = by || __('Someone', 'hoptrail');
	switch (action) {
		case 'scheduled-scan':
			return __('A scheduled scan finished. The dashboard is up to date.', 'hoptrail');
		/* translators: %s: name of an administrator. */
		case 'scan': return by ? sprintf(__('%s ran a scan. The dashboard is up to date.', 'hoptrail'), who) : __('A scan ran (WP-CLI or an AI client). The dashboard is up to date.', 'hoptrail');
		/* translators: %s: name of an administrator. */
		case 'fix': return sprintf(__('%s applied a fix.', 'hoptrail'), who);
		/* translators: %s: name of an administrator. */
		case 'migrate': return sprintf(__('%s migrated leftover rules.', 'hoptrail'), who);
		/* translators: %s: name of an administrator. */
		case 'delete': return sprintf(__('%s deleted a leftover rule.', 'hoptrail'), who);
		/* translators: %s: name of an administrator. */
		case 'purge': return sprintf(__('%s deleted leftover data.', 'hoptrail'), who);
		/* translators: %s: name of an administrator. */
		case 'settings': return sprintf(__('%s changed the schedule or retention settings.', 'hoptrail'), who);
		/* translators: %s: name of an administrator. */
		case 'flush': return sprintf(__('%s deleted logs.', 'hoptrail'), who);
		/* translators: %s: name of an administrator. */
		case 'flush-request': return sprintf(__('An AI client used by %s asked to delete logs. It waits for approval on this dashboard.', 'hoptrail'), who);
		/* translators: %s: name of an administrator. */
		case 'flush-declined': return sprintf(__('%s declined the request to delete logs.', 'hoptrail'), who);
		default: return __('Hoptrail was updated by someone else.', 'hoptrail');
	}
};

/**
 * Other administrators on this dashboard, and live updates when anyone changes something.
 * Runs on the WordPress Heartbeat (15 s here; WordPress slows it down in background tabs).
 */
export const usePresence = () => {
	const [others, setOthers] = useState<PresentUser[]>([]);
	const [note, setNote] = useState<string | null>(null);
	const version = useRef(window.hoptrail?.stateVersion ?? 0);

	useEffect(() => {
		const send = (data: Record<string, unknown>) => {
			data.hoptrail = { screen: window.hoptrail?.mode ?? 'site' };
		};
		const tick = (data: Record<string, unknown>) => {
			const payload = data?.hoptrail as Tick | undefined;
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

		// Core's Heartbeat runs these wp.hooks actions on every beat.
		addAction('heartbeat.send', 'hoptrail/presence', send);
		addAction('heartbeat.tick', 'hoptrail/presence', tick);

		// Every 15 s while this dashboard is open (WordPress slows it down in background tabs),
		// and announce ourselves right away instead of after the first interval.
		const heartbeat = (window as unknown as { wp?: { heartbeat?: { interval?: (speed: number) => void; connectNow?: () => void } } }).wp?.heartbeat;
		heartbeat?.interval?.(15);
		heartbeat?.connectNow?.();

		return () => {
			removeAction('heartbeat.send', 'hoptrail/presence');
			removeAction('heartbeat.tick', 'hoptrail/presence');
		};
	}, []);

	const dismiss = useCallback(() => setNote(null), []);

	return { others, note, dismiss };
};
