import { __, sprintf } from '@wordpress/i18n';

const locale = document.documentElement.lang || undefined;
const numberFormat = new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 });
const dateFormat = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' });
const relativeFormat = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

export const compact = (value: number): string => (value < 1000 ? String(value) : numberFormat.format(value));

export const dateTime = (iso: string | null): string => (iso ? dateFormat.format(new Date(iso)) : '—');

export const relative = (iso: string | null): string => {
	if (!iso) {
		return __('never', 'redirect-inspector');
	}
	const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
	const steps: [Intl.RelativeTimeFormatUnit, number][] = [['day', 86400], ['hour', 3600], ['minute', 60]];
	for (const [unit, size] of steps) {
		if (Math.abs(seconds) >= size) {
			return relativeFormat.format(Math.round(seconds / size), unit);
		}
	}
	return __('just now', 'redirect-inspector');
};

export const duration = (ms: number): string =>
	ms < 1000
		/* translators: %d: milliseconds. */
		? sprintf(__('%d ms', 'redirect-inspector'), ms)
		/* translators: %s: seconds. */
		: sprintf(__('%s s', 'redirect-inspector'), (ms / 1000).toFixed(1));

/** A link target only when it is http(s) — rule strings are untrusted (no javascript: URLs). */
export const safeHref = (url: string, base: string): string | undefined => {
	try {
		const parsed = new URL(url, base);
		return 'http:' === parsed.protocol || 'https:' === parsed.protocol ? parsed.href : undefined;
	} catch {
		return undefined;
	}
};
