import { dateI18n, getSettings } from '@wordpress/date';
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Dates follow WordPress: the site's date and time formats (Settings → General), the site
 * timezone, and the admin language — all from the settings WordPress passes to wp.date.
 */
const settings = getSettings();
const dateFormat = settings.formats.date;
const timeFormat = settings.formats.time;
const locale = (settings.l10n.locale || document.documentElement.lang || 'en').replace('_', '-');

const numberFormat = new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 });
const relativeFormat = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

export const compact = (value: number): string => (value < 1000 ? String(value) : numberFormat.format(value));

/** Date and time of a moment (ISO 8601), in the site's formats and timezone. */
export const dateTime = (iso: string | null): string => (iso ? dateI18n(`${dateFormat} ${timeFormat}`, iso) : '—');

/** Site UTC offset in minutes, and whether this browser runs on another offset right now. */
const siteOffsetMinutes = Math.round(Number(settings.timezone?.offset ?? 0) * 60);
const browserDiffers = (iso: string): boolean => -new Date(iso).getTimezoneOffset() !== siteOffsetMinutes;

/**
 * Like dateTime(), plus the viewer's own clock when the site runs on another timezone:
 * "12 November 2026 21:00 (22:00 your time)".
 */
export const dateTimeWithLocal = (iso: string | null): string => {
	if (!iso) {
		return '—';
	}
	const site = dateTime(iso);
	if (!browserDiffers(iso)) {
		return site;
	}
	// The viewer's clock, in the site's own formats (date only when it falls on another day).
	const viewer = -new Date(iso).getTimezoneOffset() / 60;
	const sameDay = dateI18n('Y-m-d', iso) === dateI18n('Y-m-d', iso, viewer);
	return sprintf(/* translators: 1: date and time in site time, 2: the same moment on the viewer's clock. */ __('%1$s (%2$s your time)', 'hoptrail'), site, dateI18n(sameDay ? timeFormat : `${dateFormat} ${timeFormat}`, iso, viewer));
};

/** The site timezone as WordPress names it: "Europe/Amsterdam", or "UTC+1" for a plain offset. */
export const siteTimezone = (): string => {
	const zone = settings.timezone?.string;
	if (zone) {
		return zone;
	}
	const hours = Number(settings.timezone?.offset ?? 0);
	return `UTC${hours >= 0 ? '+' : '−'}${Math.abs(hours)}`;
};

/** A calendar day (Y-m-d, already in site time), in the site's date format. */
export const day = (ymd: string): string => dateI18n(dateFormat, `${ymd}T12:00:00Z`, 'UTC');

/** A whole hour (0–23) in the site's time format, e.g. "03:00" or "3:00 am". */
export const hour = (value: number): string => dateI18n(timeFormat, Date.UTC(2000, 0, 1, value), 'UTC');

export const relative = (iso: string | null): string => {
	if (!iso) {
		return _x('never', 'relative time: it has not happened yet', 'hoptrail');
	}
	const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
	const steps: [Intl.RelativeTimeFormatUnit, number][] = [['day', 86400], ['hour', 3600], ['minute', 60]];
	for (const [unit, size] of steps) {
		if (Math.abs(seconds) >= size) {
			return relativeFormat.format(Math.round(seconds / size), unit);
		}
	}
	return __('just now', 'hoptrail');
};

export const duration = (ms: number): string =>
	ms < 1000
		? sprintf(/* translators: %d: milliseconds. */ __('%d ms', 'hoptrail'), ms)
		: sprintf(/* translators: %s: seconds. */ __('%s s', 'hoptrail'), (ms / 1000).toFixed(1));

/** A link target only when it is http(s) — rule strings are untrusted (no javascript: URLs). */
export const safeHref = (url: string, base: string): string | undefined => {
	try {
		const parsed = new URL(url, base);
		return 'http:' === parsed.protocol || 'https:' === parsed.protocol ? parsed.href : undefined;
	} catch {
		return undefined;
	}
};

/** Weekday names in the admin language, from WordPress's own locale data (0 = Sunday). */
export const weekdayNames = (): { short: string[]; long: string[] } => {
	const fallback = (style: 'short' | 'long') => Array.from({ length: 7 }, (_, index) => new Intl.DateTimeFormat(locale, { weekday: style }).format(new Date(2023, 0, 1 + index)));
	const { weekdaysShort, weekdays } = settings.l10n;
	return {
		short: Array.isArray(weekdaysShort) ? weekdaysShort : fallback('short'),
		long: Array.isArray(weekdays) ? weekdays : fallback('long'),
	};
};
