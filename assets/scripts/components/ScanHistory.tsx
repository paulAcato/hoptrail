import { Button, DatePicker, Dropdown, Spinner } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { calendar, closeSmall } from '@wordpress/icons';
import { route } from '../api/client';
import { useResource } from '../api/hooks';
import type { Paged, Scan } from '../api/types';
import { dateTime, day, duration } from '../lib/format';
import { healthBand, healthLabel } from '../lib/meta';

const PER_PAGE = 8;

type SortKey = 'date' | 'rules' | 'issues' | 'score' | 'duration';
type Order = 'asc' | 'desc';

interface History extends Paged<Scan> {
	days: string[];
}

const statusLabel = (status: Scan['status']): string => ({
	running: __('Running', 'redirect-inspector'),
	done: __('Complete', 'redirect-inspector'),
	partial: __('Partial', 'redirect-inspector'),
	failed: __('Failed', 'redirect-inspector'),
})[status];

/** Y-m-d of a Date in local (browser) time; the picker works in local dates. */
const ymd = (date: Date): string => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** Noon avoids a date shifting across midnight in any timezone. */
const fromYmd = (value: string): Date => new Date(`${value}T12:00:00`);

export const ScanHistory = () => {
	const [page, setPage] = useState(1);
	const pickerRef = useRef<HTMLButtonElement>(null);
	const [date, setDate] = useState('');
	const [sort, setSort] = useState<{ key: SortKey; order: Order }>({ key: 'date', order: 'desc' });

	useEffect(() => setPage(1), [date, sort]);

	const { data, loading, error } = useResource<History>(route('/scans', { page, per_page: PER_PAGE, date, orderby: sort.key, order: sort.order }));
	const pages = data ? Math.max(1, Math.ceil(data.total / PER_PAGE)) : 1;

	// Keep the days from the last response while a new page loads, so the picker doesn't flicker.
	const [days, setDays] = useState<string[]>([]);
	useEffect(() => {
		if (data?.days) {
			setDays(data.days);
		}
	}, [data]);
	const events = useMemo(() => days.map((day) => ({ date: fromYmd(day) })), [days]);

	// Only the span that has scans can be picked: first scan day … last scan day.
	const bounds = useMemo(() => {
		const sorted = [...days].sort();
		return sorted.length ? { min: sorted[0], max: sorted[sorted.length - 1] } : null;
	}, [days]);

	const sortBy = (key: SortKey) => setSort((current) => ({
		key,
		// Same column flips; a new column starts with the most useful direction.
		order: current.key === key ? ('asc' === current.order ? 'desc' : 'asc') : ('score' === key ? 'asc' : 'desc'),
	}));

	const header = (key: SortKey, label: string, numeric = false) => {
		const active = sort.key === key;
		return (
			<th scope="col" className={numeric ? 'is-num' : undefined} aria-sort={active ? ('asc' === sort.order ? 'ascending' : 'descending') : 'none'}>
				<button type="button" className={`rdi-sort${active ? ' is-active' : ''}`} onClick={() => sortBy(key)}>
					{label}
					<span className="rdi-sort__arrow" aria-hidden="true">{active ? ('asc' === sort.order ? '↑' : '↓') : '↕'}</span>
				</button>
			</th>
		);
	};

	return (
		<section className="rdi-card rdi-history" aria-labelledby="rdi-history-title">
			<header className="rdi-card__header">
				<h2 id="rdi-history-title">{__('Scan history', 'redirect-inspector')}</h2>
				<div className="rdi-history__tools">
					{loading && <Spinner />}
					<Dropdown
						popoverProps={{ placement: 'bottom-end' }}
						renderToggle={({ isOpen, onToggle }) => (
							<Button ref={pickerRef} variant="secondary" size="compact" icon={calendar} aria-expanded={isOpen} onClick={onToggle}>
								{date ? day(date) : __('Pick a date', 'redirect-inspector')}
							</Button>
						)}
						renderContent={({ onClose }) => (
							<div className="rdi-history__picker">
								<DatePicker
									currentDate={date ? fromYmd(date) : null}
									events={events}
									startOfWeek={(window.redirectInspector?.startOfWeek ?? 1) as 0 | 1 | 2 | 3 | 4 | 5 | 6}
									isInvalidDate={(day: Date) => !bounds || ymd(day) < bounds.min || ymd(day) > bounds.max}
									onChange={(value: string) => {
										setDate(ymd(new Date(value)));
										onClose();
									}}
								/>
								<p className="rdi-card__meta">{__('Days with a scan are marked.', 'redirect-inspector')}</p>
							</div>
						)}
					/>
					{date && (
						<Button variant="tertiary" size="compact" icon={closeSmall} label={__('Show all dates', 'redirect-inspector')} onClick={() => { setDate(''); pickerRef.current?.focus(); }} />
					)}
				</div>
			</header>
			{error && <div className="notice notice-error inline" role="alert"><p>{error}</p></div>}
			{data && (
				<div className="rdi-table-wrap" role="region" aria-labelledby="rdi-history-title" tabIndex={0}>
					<table className="rdi-table">
						<thead>
							<tr>
								{header('date', __('Scanned', 'redirect-inspector'))}
								<th scope="col">{__('By', 'redirect-inspector')}</th>
								{header('rules', __('Rules', 'redirect-inspector'), true)}
								{header('issues', __('Issues', 'redirect-inspector'), true)}
								{header('score', __('Score', 'redirect-inspector'), true)}
								{header('duration', __('Took', 'redirect-inspector'), true)}
								<th scope="col">{__('Status', 'redirect-inspector')}</th>
							</tr>
						</thead>
						<tbody>
							{0 === data.items.length && (
								<tr>
									<td colSpan={7}>
										{date
											/* translators: %s: date. */
											? sprintf(__('No scans on %s.', 'redirect-inspector'), day(date))
											: __('No scans yet.', 'redirect-inspector')}
									</td>
								</tr>
							)}
							{data.items.map((scan) => (
								<tr key={scan.id}>
									<td>{dateTime(scan.started_at)}</td>
									<td>{scan.user || '—'}</td>
									<td className="is-num">{scan.rule_count}</td>
									<td className="is-num">{scan.issue_count}</td>
									<td className="is-num">
										{'failed' === scan.status ? '—' : <span className={`rdi-score is-${healthBand(scan.score)}`}>{scan.score}<span className="screen-reader-text"> ({healthLabel(scan.score)})</span></span>}
									</td>
									<td className="is-num">{duration(scan.duration_ms)}</td>
									<td><span className={`rdi-status rdi-status--${scan.status}`} title={scan.message || undefined}>{statusLabel(scan.status)}</span></td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
			{data && data.total > PER_PAGE && (
				<nav className="rdi-pagination" aria-label={__('Scan history pages', 'redirect-inspector')}>
					<Button variant="tertiary" size="compact" disabled={page <= 1} accessibleWhenDisabled onClick={() => setPage(page - 1)}>{__('Previous', 'redirect-inspector')}</Button>
					<span>{page} / {pages}</span>
					<Button variant="tertiary" size="compact" disabled={page >= pages} accessibleWhenDisabled onClick={() => setPage(page + 1)}>{__('Next', 'redirect-inspector')}</Button>
				</nav>
			)}
		</section>
	);
};
