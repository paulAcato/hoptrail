import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * The quick walkthrough (components/Tour.tsx).
 *
 * Every step says in which version it was added. Admin\Tour::VERSION is the newest; after an update
 * that adds steps, an administrator who completed an older walkthrough sees only the new steps.
 */
export interface TourStep {
	/** Plugin version the step was added in. */
	since: string;
	/** Element the pointer points at; the step is left out when it isn't on the page. */
	target: string;
	title: string;
	text: string;
	/** Where the popover sits relative to the target (Popover placement). */
	placement?: 'bottom-start' | 'bottom-end' | 'left' | 'left-start' | 'top-start';
}

export const steps = (): TourStep[] => [
	{
		since: '1.0.0',
		target: '.rdi-banner__scan',
		title: __('Run a scan', 'hoptrail'),
		text: __('Reads the redirect rules of all supported SEO and redirect plugins and follows every one to where it really ends. Scheduled scans do the same on their own.', 'hoptrail'),
		placement: 'bottom-end',
	},
	{
		since: '1.0.0',
		target: '.rdi-banner .rdi-gauge',
		title: __('Health score', 'hoptrail'),
		text: __('100 means every redirect goes straight to its destination. Loops cost the most, then chains and conflicts.', 'hoptrail'),
		placement: 'left',
	},
	{
		since: '1.0.0',
		target: '.rdi-leftovers',
		title: __('Leftover redirects', 'hoptrail'),
		text: __('Rules of plugins that are no longer active. Review them, copy them into an active plugin, or delete them. A pattern is copied as exact rules when the other plugin only holds exact ones.', 'hoptrail'),
	},
	{
		since: '1.0.0',
		target: '.rdi-tiles',
		title: __('Issues per type', 'hoptrail'),
		text: __('Loops, chains, conflicts, redirects you don’t need, and broken targets, with their trend. Select one to filter the list.', 'hoptrail'),
	},
	{
		since: '1.0.0',
		target: '.rdi-filters__search',
		title: __('Search', 'hoptrail'),
		text: __('Search the URLs of every hop. Switch on .* to search with a regular expression, such as ^/blog/.', 'hoptrail'),
	},
	{
		since: '1.0.0',
		target: '.rdi-issue__footer',
		title: __('Verify and fix', 'hoptrail'),
		text: __('Verify live requests the URL on this site to confirm what visitors get. Apply fix appears when the fix is clear-cut, and changes the rule in the plugin it belongs to.', 'hoptrail'),
	},
	{
		since: '1.0.0',
		target: '#settings .rdi-card__header',
		title: __('Schedule and retention', 'hoptrail'),
		text: __('Scan daily, weekly or monthly at the hours you choose, and decide how much scan history to keep.', 'hoptrail'),
		placement: 'left-start',
	},
];

/** -1, 0 or 1, like PHP's version_compare for plain x.y.z versions. */
export const compareVersions = (a: string, b: string): number => {
	const left = a.split('.').map(Number);
	const right = b.split('.').map(Number);
	for (let i = 0; i < Math.max(left.length, right.length); i++) {
		const diff = (left[i] ?? 0) - (right[i] ?? 0);
		if (diff) {
			return diff > 0 ? 1 : -1;
		}
	}
	return 0;
};

/** Steps newer than what this user saw ('' = all), present on the page. */
export const pendingSteps = (seen: string, version: string): TourStep[] => steps().filter(
	(step) => (!seen || compareVersions(step.since, seen) > 0) && compareVersions(step.since, version) <= 0 && null !== document.querySelector(step.target)
);

/** Store that this user completed (or skipped) the current walkthrough. */
export const markSeen = async (): Promise<void> => {
	try {
		await apiFetch({ path: '/hoptrail/v1/tour', method: 'POST' });
		if (window.hoptrail) {
			window.hoptrail.tour = { ...window.hoptrail.tour, pending: false };
		}
	} catch {
		// Not saved: the walkthrough simply shows again next time.
	}
};
