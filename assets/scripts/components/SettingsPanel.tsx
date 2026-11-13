import { Button, Notice, SelectControl, ToggleControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';
import { errorMessage, invalidate, isAbort, route } from '../api/client';
import { useMutation } from '../api/hooks';
import type { Retention, Schedule, Settings } from '../api/types';
import { dateTimeWithLocal, hour, siteTimezone, weekdayNames } from '../lib/format';

interface Props {
	settings: Settings;
	retention: Retention;
	nextScan: string | null;
}

type Feedback = { status: 'success' | 'error'; text: string } | null;

const boot = window.hoptrail;
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

/** Weekday numbers (0 = Sunday) in the site's week order, with WordPress's own short and long names. */
const weekdays = (() => {
	const start = boot?.startOfWeek ?? 1;
	const names = weekdayNames();
	return Array.from({ length: 7 }, (_, index) => {
		const day = (start + index) % 7;
		return { day, short: names.short[day], long: names.long[day] };
	});
})();

/** "UTC+01:00" for an offset, the zone name (Europe/Amsterdam) otherwise. */
const timezone = siteTimezone();

const hourLabel = (value: number): string => hour(value);

const toggle = (list: number[], value: number): number[] =>
	(list.includes(value) ? list.filter((item) => item !== value) : [...list, value]).sort((a, b) => a - b);

const same = (a: Schedule, b: Schedule): boolean =>
	a.enabled === b.enabled && a.frequency === b.frequency && a.month_day === b.month_day && a.days.join() === b.days.join() && a.hours.join() === b.hours.join();

const FREQUENCIES: { value: Schedule['frequency']; label: string }[] = [
	{ value: 'daily', label: __('Daily', 'hoptrail') },
	{ value: 'weekly', label: __('Weekly', 'hoptrail') },
	{ value: 'monthly', label: __('Monthly', 'hoptrail') },
];

/** "1st", "15th" … via sprintf so translators control the ordinal; 0 = last day. */
const monthDayLabel = (day: number): string =>
	0 === day
		? __('the last day', 'hoptrail')
		/* translators: %d: day of the month. */
		: sprintf(__('day %d', 'hoptrail'), day);

export const SettingsPanel = ({ settings, retention, nextScan }: Props) => {
	const [days, setDays] = useState(String(settings.retention_days));
	const [mode, setMode] = useState<Settings['retention_mode']>(settings.retention_mode);
	const [count, setCount] = useState(String(settings.retention_count));
	const [schedule, setSchedule] = useState<Schedule>(settings.schedule);
	const [confirming, setConfirming] = useState(false);
	const [feedback, setFeedback] = useState<Feedback>(null);
	const save = useMutation<{ settings: Settings; retention: Retention; next_scan: string | null }>();
	const purge = useMutation<{ deleted: number }>();

	// Settings saved elsewhere (another admin, an AI client): adopt them unless there are unsaved edits.
	const shown = useRef(settings);
	const [outdated, setOutdated] = useState(false);
	useEffect(() => {
		const before = shown.current;
		if (JSON.stringify(before) === JSON.stringify(settings)) {
			return;
		}
		const untouched = Number(days) === before.retention_days && mode === before.retention_mode && Number(count) === before.retention_count && same(schedule, before.schedule);
		shown.current = settings;
		if (untouched) {
			setDays(String(settings.retention_days));
			setMode(settings.retention_mode);
			setCount(String(settings.retention_count));
			setSchedule(settings.schedule);
			setOutdated(false);
		} else {
			setOutdated(true);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Only when the saved settings change.
	}, [settings]);

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

	const dirty = Number(days) !== settings.retention_days || mode !== settings.retention_mode || Number(count) !== settings.retention_count || !same(schedule, settings.schedule);
	const incomplete = schedule.enabled && (!schedule.hours.length || ('weekly' === schedule.frequency && !schedule.days.length));

	const scheduleSummary = useMemo(() => {
		if (!schedule.enabled) {
			return __('Off. Scans only run when you start them.', 'hoptrail');
		}
		if (incomplete) {
			return __('Pick at least one day and one hour.', 'hoptrail');
		}
		const times = schedule.hours.map(hourLabel).join(', ');
		if ('daily' === schedule.frequency) {
			/* translators: 1: times, e.g. "03:00, 15:00", 2: timezone. */
			return sprintf(__('Runs every day at %1$s, site time (%2$s).', 'hoptrail'), times, timezone);
		}
		if ('monthly' === schedule.frequency) {
			/* translators: 1: "day 15" or "the last day", 2: times, 3: timezone. */
			return sprintf(__('Runs on %1$s of every month at %2$s, site time (%3$s).', 'hoptrail'), monthDayLabel(schedule.month_day), times, timezone);
		}
		const dayNames = 7 === schedule.days.length
			? __('every day', 'hoptrail')
			: weekdays.filter((weekday) => schedule.days.includes(weekday.day)).map((weekday) => weekday.short).join(', ');
		/* translators: 1: days, e.g. "Mon, Wed", 2: times, e.g. "03:00, 15:00", 3: timezone. */
		return sprintf(__('Runs %1$s at %2$s, site time (%3$s).', 'hoptrail'), dayNames, times, timezone);
	}, [schedule, incomplete]);

	const report = (status: 'success' | 'error', text: string) => {
		setFeedback({ status, text });
		speak(text, 'error' === status ? 'assertive' : 'polite');
	};

	const onSave = async () => {
		try {
			await save.run(route('/settings'), 'POST', { retention_mode: mode, retention_days: Number(days), retention_count: Number(count), schedule });
			invalidate('/summary');
			report('success', __('Settings saved.', 'hoptrail'));
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
			report('success', sprintf(__('Deleted %d scans.', 'hoptrail'), result.deleted));
		} catch (error) {
			if (!isAbort(error)) {
				report('error', errorMessage(error));
			}
		}
	};

	return (
		<section id="settings" className="rdi-card rdi-settings" aria-labelledby="rdi-settings-title" tabIndex={-1}>
			<header className="rdi-card__header">
				<h2 id="rdi-settings-title">{__('Schedule & retention', 'hoptrail')}</h2>
			</header>

			<div className="rdi-settings__section">
				<div className="rdi-settings__row">
					<div>
						<h3 className="rdi-settings__title">{__('Scheduled scans', 'hoptrail')}</h3>
						<p id="rdi-schedule-hint" className={`rdi-settings__hint${incomplete ? ' is-error' : ''}`}>{scheduleSummary}</p>
					</div>
					<ToggleControl
						__nextHasNoMarginBottom
						className="rdi-settings__toggle"
						label={<span className="screen-reader-text">{__('Scheduled scans', 'hoptrail')}</span>}
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
						<div className="rdi-settings__label" id="rdi-frequency-label">{__('Frequency', 'hoptrail')}</div>
						<div className="rdi-segmented rdi-segmented--three" role="group" aria-labelledby="rdi-frequency-label">
							{FREQUENCIES.map((option) => (
								<button
									key={option.value}
									type="button"
									aria-pressed={schedule.frequency === option.value}
									onClick={() => setSchedule((current) => ({ ...current, frequency: option.value }))}
								>
									{option.label}
								</button>
							))}
						</div>

						{'monthly' === schedule.frequency && (
							<div className="rdi-settings__month-day">
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={__('Day of the month', 'hoptrail')}
									value={String(schedule.month_day)}
									options={[
										...Array.from({ length: 28 }, (_, index) => ({ label: String(index + 1), value: String(index + 1) })),
										{ label: __('Last day of the month', 'hoptrail'), value: '0' },
									]}
									onChange={(value: string) => setSchedule((current) => ({ ...current, month_day: Number(value) }))}
								/>
							</div>
						)}

						{'weekly' === schedule.frequency && (<>
						<div className="rdi-settings__label" id="rdi-days-label">{__('Days', 'hoptrail')}</div>
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
						</>)}

						<div className="rdi-settings__label" id="rdi-hours-label">
							{__('Hours', 'hoptrail')} <span className="rdi-muted">{timezone}</span>
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
								{sprintf(__('Next scan: %s', 'hoptrail'), dateTimeWithLocal(nextScan))}
							</p>
						)}
					</>
				)}
			</div>

			<div className="rdi-settings__section">
				<h3 className="rdi-settings__title">{__('History', 'hoptrail')}</h3>
				<div className="rdi-settings__label" id="rdi-keep-label">{__('Keep', 'hoptrail')}</div>
				<div className="rdi-segmented rdi-segmented--two" role="group" aria-labelledby="rdi-keep-label">
					<button type="button" aria-pressed={'days' === mode} onClick={() => setMode('days')}>
						{__('Scans of the last days', 'hoptrail')}
					</button>
					<button type="button" aria-pressed={'count' === mode} onClick={() => setMode('count')}>
						{__('The newest scans', 'hoptrail')}
					</button>
				</div>
				<div className="rdi-settings__days">
					{'days' === mode ? (
						<NumberControl
							__next40pxDefaultSize
							label={__('Keep scans for', 'hoptrail')}
							min={7}
							max={365}
							value={days}
							help={__('7 to 365 days.', 'hoptrail')}
							suffix={<span className="rdi-settings__suffix">{__('days', 'hoptrail')}</span>}
							onChange={(value?: string) => setDays(value ?? '')}
						/>
					) : (
						<NumberControl
							__next40pxDefaultSize
							label={__('Keep the newest', 'hoptrail')}
							min={5}
							max={1000}
							value={count}
							help={__('5 to 1,000 scans.', 'hoptrail')}
							suffix={<span className="rdi-settings__suffix">{__('scans', 'hoptrail')}</span>}
							onChange={(value?: string) => setCount(value ?? '')}
						/>
					)}
				</div>
				<p className="rdi-settings__hint">
					{retention.text}
					{retention.next_cleanup && (
						/* translators: %s: date and time. */
						<> {sprintf(__('Next cleanup: %s.', 'hoptrail'), dateTimeWithLocal(retention.next_cleanup))}</>
					)}
				</p>
			</div>

			{outdated && (
				<Notice status="warning" isDismissible onRemove={() => setOutdated(false)} spokenMessage={null}>
					{__('Someone else saved these settings while you were editing. Saving now replaces their changes with yours.', 'hoptrail')}
				</Notice>
			)}

			{feedback && (
				<Notice status={feedback.status} isDismissible onRemove={() => setFeedback(null)} spokenMessage={null}>
					{feedback.text}
				</Notice>
			)}

			<footer className="rdi-settings__footer">
				{confirming ? (
					<div className="rdi-settings__confirm">
						<span>{__('Delete all scan history? This cannot be undone.', 'hoptrail')}</span>
						<Button ref={confirmRef} __next40pxDefaultSize variant="primary" isDestructive isBusy={purge.busy} disabled={purge.busy} accessibleWhenDisabled onClick={onPurge}>
							{purge.busy ? __('Deleting…', 'hoptrail') : __('Delete all', 'hoptrail')}
						</Button>
						<Button __next40pxDefaultSize variant="tertiary" disabled={purge.busy} accessibleWhenDisabled onClick={closeConfirm}>{__('Cancel', 'hoptrail')}</Button>
					</div>
				) : (
					<>
						<Button ref={deleteRef} __next40pxDefaultSize variant="tertiary" isDestructive icon={trash} onClick={() => setConfirming(true)}>
							{__('Delete history', 'hoptrail')}
						</Button>
						<Button __next40pxDefaultSize variant="primary" isBusy={save.busy} disabled={save.busy || !dirty || !days || !count || incomplete} accessibleWhenDisabled onClick={onSave} aria-describedby={incomplete ? 'rdi-schedule-hint' : undefined}>
							{save.busy ? __('Saving…', 'hoptrail') : __('Save settings', 'hoptrail')}
						</Button>
					</>
				)}
			</footer>
		</section>
	);
};
