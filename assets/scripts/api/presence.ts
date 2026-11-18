import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { addAction, removeAction } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { refresh } from './client';
import type { PresentUser } from './types';
import { pluginName } from '../lib/plugin';

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
	const who = by || __('Someone', 'onehop-redirect-checker');
	switch (action) {
		case 'scheduled-scan':
			return __('A scheduled scan finished. The dashboard is up to date.', 'onehop-redirect-checker');
		case 'scan': return by ? sprintf(/* translators: %s: name of an administrator. */ __('%s ran a scan. The dashboard is up to date.', 'onehop-redirect-checker'), who) : __('A scan ran (WP-CLI or an AI client). The dashboard is up to date.', 'onehop-redirect-checker');
		case 'fix': return sprintf(/* translators: %s: name of an administrator. */ __('%s applied a fix.', 'onehop-redirect-checker'), who);
		case 'migrate': return sprintf(/* translators: %s: name of an administrator. */ __('%s migrated leftover rules.', 'onehop-redirect-checker'), who);
		case 'delete': return sprintf(/* translators: %s: name of an administrator. */ __('%s deleted a leftover rule.', 'onehop-redirect-checker'), who);
		case 'purge': return sprintf(/* translators: %s: name of an administrator. */ __('%s deleted leftover data.', 'onehop-redirect-checker'), who);
		case 'settings': return sprintf(/* translators: %s: name of an administrator. */ __('%s changed the schedule or retention settings.', 'onehop-redirect-checker'), who);
		case 'flush': return sprintf(/* translators: %s: name of an administrator. */ __('%s deleted logs.', 'onehop-redirect-checker'), who);
		case 'flush-request': return sprintf(/* translators: %s: name of an administrator. */ __('An AI client used by %s asked to delete logs. It waits for approval on this dashboard.', 'onehop-redirect-checker'), who);
		case 'flush-declined': return sprintf(/* translators: %s: name of an administrator. */ __('%s declined the request to delete logs.', 'onehop-redirect-checker'), who);
		default: return sprintf(/* translators: %s: plugin name. */ __('%s was updated by someone else.', 'onehop-redirect-checker'), pluginName);
	}
};

/**
 * Other administrators on this dashboard, and live updates when anyone changes something.
 * Runs on the WordPress Heartbeat (15 s here; WordPress slows it down in background tabs).
 */
export const usePresence = () => {
	const [others, setOthers] = useState<PresentUser[]>([]);
	const [note, setNote] = useState<string | null>(null);
	const version = useRef(window.onehopRedirectChecker?.stateVersion ?? 0);

	useEffect(() => {
		const send = (data: Record<string, unknown>) => {
			data.onehop_redirect_checker = { screen: window.onehopRedirectChecker?.mode ?? 'site' };
		};
		const tick = (data: Record<string, unknown>) => {
			const payload = data?.onehop_redirect_checker as Tick | undefined;
			if (!payload) {
				return;
			}
			setOthers(Array.isArray(payload.others) ? payload.others : []);

			if ('number' === typeof payload.version && payload.version > version.current) {
				version.current = payload.version;
				// Our own changes already refreshed this tab, and the other tabs over BroadcastChannel.
				if (payload.change && !payload.change.self) {
					refresh();
					const text = describe(payload.change);
					setNote(text);
					speak(text, 'polite');
				}
			}
		};

		// Core's Heartbeat runs these wp.hooks actions on every beat.
		addAction('heartbeat.send', 'onehop-redirect-checker/presence', send);
		addAction('heartbeat.tick', 'onehop-redirect-checker/presence', tick);

		// Every 15 s while this dashboard is open (WordPress slows it down in background tabs),
		// and announce ourselves right away instead of after the first interval.
		const heartbeat = (window as unknown as { wp?: { heartbeat?: { interval?: (speed: number) => void; connectNow?: () => void } } }).wp?.heartbeat;
		heartbeat?.interval?.(15);
		heartbeat?.connectNow?.();

		return () => {
			removeAction('heartbeat.send', 'onehop-redirect-checker/presence');
			removeAction('heartbeat.tick', 'onehop-redirect-checker/presence');
		};
	}, []);

	const dismiss = useCallback(() => setNote(null), []);

	return { others, note, dismiss };
};
