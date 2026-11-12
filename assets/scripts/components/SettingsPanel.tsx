import { Button, Notice, ToggleControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';
import { errorMessage, invalidate, isAbort, route } from '../api/client';
import { useMutation } from '../api/hooks';
import type { Retention, Schedule, Settings } from '../api/types';
import { dateTime } from '../lib/format';

interface Props {
	settings: Settings;
	retention: Retention;
	nextScan: string | null;
}

type Feedback = { status: 'success' | 'error'; text: string } | null;

const boot = window.redirectInspector;
const locale = document.documentElement.lang || undefined;
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

/** Weekday numbers (0 = Sunday) in the site's week order, with localised short and long names. */
const weekdays = (() => {
	const start = boot?.startOfWeek ?? 1;
	const short = new Intl.DateTimeFormat(locale, { weekday: 'short' });
	const long = new Intl.DateTimeFormat(locale, { weekday: 'long' });
	// 2023-01-01 was a Sunday.
	return Array.from({ length: 7 }, (_, index) => {
		const day = (start + index) % 7;
		const date = new Date(2023, 0, 1 + day);
		return { day, short: short.format(date), long: long.format(date) };
	});
})();

/** "UTC+01:00" for an offset, the zone name (Europe/Amsterdam) otherwise. */
const timezone = (() => {
	const zone = boot?.timezone ?? '';
	return /^[+-]/.test(zone) ? `UTC${zone}` : zone || 'UTC';
})();

const hourLabel = (hour: number): string => `${String(hour).padStart(2, '0')}:00`;

const toggle = (list: number[], value: number): number[] =>
	(list.includes(value) ? list.filter((item) => item !== value) : [...list, value]).sort((a, b) => a - b);

const same = (a: Schedule, b: Schedule): boolean =>
	a.enabled === b.enabled && a.days.join() === b.days.join() && a.hours.join() === b.hours.join();

export const SettingsPanel = ({ settings, retention, nextScan }: Props) => {
	const [days, setDays] = useState(String(settings.retention_days));
	const [schedule, setSchedule] = useState<Schedule>(settings.schedule);
	const [confirming, setConfirming] = useState(false);
	const [feedback, setFeedback] = useState<Feedback>(null);
	const save = useMutation<{ settings: Settings; retention: Retention; next_scan: string | null }>();
	const purge = useMutation<{ deleted: number }>();

	// Focus follows the inline delete confirmation (2.4.3).
	const deleteRef = useRef<HTMLButtonElement>(null);
	const confirmRef = useRef<HTMLButtonElement>(null);
	const returnFocus = useRef(false);
	useEffect(() => {
		if (confirming) {
			confirmRef.current?.focus();
		} else if (returnFocus.current) {
			deleteRef.current?.focus();
		}
		returnFocus.current = false;
	}, [confirming]);
	const closeConfirm = () => {
		returnFocus.current = true;
		setConfirming(false);
	};

	const dirty = Number(days) !== settings.retention_days || !same(schedule, settings.schedule);
	const incomplete = schedule.enabled && (!schedule.days.length || !schedule.hours.length);

	const scheduleSummary = useMemo(() => {
		if (!schedule.enabled) {
			return __('Off. Scans only run when you start them.', 'redirect-inspector');
		}
		if (incomplete) {
			return __('Pick at least one day and one hour.', 'redirect-inspector');
		}
		const dayNames = 7 === schedule.days.length
			? __('every day', 'redirect-inspector')
			: weekdays.filter((weekday) => schedule.days.includes(weekday.day)).map((weekday) => weekday.short).join(', ');
		/* translators: 1: days, e.g. "Mon, Wed", 2: times, e.g. "03:00, 15:00", 3: timezone. */
		return sprintf(__('Runs %1$s at %2$s (%3$s).', 'redirect-inspector'), dayNames, schedule.hours.map(hourLabel).join(', '), timezone);
	}, [schedule, incomplete]);

	const report = (status: 'success' | 'error', text: string) => {
		setFeedback({ status, text });
		speak(text, 'error' === status ? 'assertive' : 'polite');
	};

	const onSave = async () => {
		try {
			await save.run(route('/settings'), 'POST', { retention_days: Number(days), schedule });
			invalidate('/summary');
			report('success', __('Settings saved.', 'redirect-inspector'));
		} catch (error) {
			if (!isAbort(error)) {
				report('error', errorMessage(error));
			}
		}
	};

	const onPurge = async () => {
		try {
			const result = await purge.run(route('/logs'), 'DELETE');
			closeConfirm();
			invalidate();
			/* translators: %d: number of scans. */
			report('success', sprintf(__('Deleted %d scans.', 'redirect-inspector'), result.deleted));
		} catch (error) {
			if (!isAbort(error)) {
				report('error', errorMessage(error));
			}
		}
	};

	return (
		<section id="settings" className="rdi-card rdi-settings" aria-labelledby="rdi-settings-title" tabIndex={-1}>
			<header className="rdi-card__header">
				<h2 id="rdi-settings-title">{__('Schedule & retention', 'redirect-inspector')}</h2>
			</header>

			<div className="rdi-settings__section">
				<div className="rdi-settings__row">
					<div>
						<h3 className="rdi-settings__title">{__('Scheduled scans', 'redirect-inspector')}</h3>
						<p id="rdi-schedule-hint" className={`rdi-settings__hint${incomplete ? ' is-error' : ''}`}>{scheduleSummary}</p>
					</div>
					<ToggleControl
						__nextHasNoMarginBottom
						className="rdi-settings__toggle"
						label={<span className="screen-reader-text">{__('Scheduled scans', 'redirect-inspector')}</span>}
						aria-describedby="rdi-schedule-hint"
						checked={schedule.enabled}
						onChange={(enabled: boolean) => setSchedule((current) => ({
							...current,
							enabled,
							// First time on: weekdays at 03:00.
							days: enabled && !current.days.length ? [1, 2, 3, 4, 5] : current.days,
							hours: enabled && !current.hours.length ? [3] : current.hours,
						}))}
					/>
				</div>

				{schedule.enabled && (
					<>
						<div className="rdi-settings__label" id="rdi-days-label">{__('Days', 'redirect-inspector')}</div>
						<div className="rdi-segmented" role="group" aria-labelledby="rdi-days-label" aria-describedby={incomplete ? 'rdi-schedule-hint' : undefined}>
							{weekdays.map((weekday) => (
								<button
									key={weekday.day}
									type="button"
									aria-pressed={schedule.days.includes(weekday.day)}
									aria-label={weekday.long}
									onClick={() => setSchedule((current) => ({ ...current, days: toggle(current.days, weekday.day) }))}
								>
									{weekday.short}
								</button>
							))}
						</div>

						<div className="rdi-settings__label" id="rdi-hours-label">
							{__('Hours', 'redirect-inspector')} <span className="rdi-muted">{timezone}</span>
						</div>
						<div className="rdi-hours" role="group" aria-labelledby="rdi-hours-label" aria-describedby={incomplete ? 'rdi-schedule-hint' : undefined}>
							{HOURS.map((hour) => (
								<button
									key={hour}
									type="button"
									aria-pressed={schedule.hours.includes(hour)}
									aria-label={hourLabel(hour)}
									onClick={() => setSchedule((current) => ({ ...current, hours: toggle(current.hours, hour) }))}
								>
									{String(hour).padStart(2, '0')}
								</button>
							))}
						</div>

						{!dirty && nextScan && (
							<p className="rdi-card__meta rdi-settings__next">
								{/* translators: %s: date and time. */}
								{sprintf(__('Next scan: %s', 'redirect-inspector'), dateTime(nextScan))}
							</p>
						)}
					</>
				)}
			</div>

			<div className="rdi-settings__section">
				<h3 className="rdi-settings__title">{__('History', 'redirect-inspector')}</h3>
				<div className="rdi-settings__days">
					<NumberControl
						__next40pxDefaultSize
						label={__('Keep scans for', 'redirect-inspector')}
						min={7}
						max={365}
						value={days}
						help={__('7 to 365 days.', 'redirect-inspector')}
						suffix={<span className="rdi-settings__suffix">{__('days', 'redirect-inspector')}</span>}
						onChange={(value?: string) => setDays(value ?? '')}
					/>
				</div>
				<p className="rdi-settings__hint">
					{retention.text}
					{retention.next_cleanup && (
						/* translators: %s: date and time. */
						<> {sprintf(__('Next cleanup: %s.', 'redirect-inspector'), dateTime(retention.next_cleanup))}</>
					)}
				</p>
			</div>

			{feedback && (
				<Notice status={feedback.status} isDismissible onRemove={() => setFeedback(null)} spokenMessage={null}>
					{feedback.text}
				</Notice>
			)}

			<footer className="rdi-settings__footer">
				{confirming ? (
					<div className="rdi-settings__confirm">
						<span>{__('Delete all scan history? This cannot be undone.', 'redirect-inspector')}</span>
						<Button ref={confirmRef} __next40pxDefaultSize variant="primary" isDestructive isBusy={purge.busy} disabled={purge.busy} accessibleWhenDisabled onClick={onPurge}>
							{purge.busy ? __('Deleting…', 'redirect-inspector') : __('Delete all', 'redirect-inspector')}
						</Button>
						<Button __next40pxDefaultSize variant="tertiary" disabled={purge.busy} accessibleWhenDisabled onClick={closeConfirm}>{__('Cancel', 'redirect-inspector')}</Button>
					</div>
				) : (
					<>
						<Button ref={deleteRef} __next40pxDefaultSize variant="tertiary" isDestructive icon={trash} onClick={() => setConfirming(true)}>
							{__('Delete history', 'redirect-inspector')}
						</Button>
						<Button __next40pxDefaultSize variant="primary" isBusy={save.busy} disabled={save.busy || !dirty || !days || incomplete} accessibleWhenDisabled onClick={onSave} aria-describedby={incomplete ? 'rdi-schedule-hint' : undefined}>
							{save.busy ? __('Saving…', 'redirect-inspector') : __('Save settings', 'redirect-inspector')}
						</Button>
					</>
				)}
			</footer>
		</section>
	);
};
