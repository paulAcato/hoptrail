import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, invalidate, isAbort, route } from '../api/client';
import { useMutation, usePageVisible, useResource } from '../api/hooks';
import type { Scan, Summary as SummaryData } from '../api/types';
import { duration } from '../lib/format';
import { TYPE_ORDER, typeMeta, GROUPS, type Group } from '../lib/meta';
import { MultiSourceContext } from '../lib/context';
import { usePresence } from '../api/presence';
import { markSeen, pendingSteps, steps, type TourStep } from '../lib/tour';
import { Tour } from './Tour';
import { FlushApproval } from './FlushApproval';
import { AllClear } from './AllClear';
import { Banner } from './Banner';
import { Leftovers } from './Leftovers';
import { BarList } from './BarList';
import { ScoreChart } from './ScoreChart';
import { SeverityChart } from './SeverityChart';
import { EmptyState } from './EmptyState';
import { IssueExplorer } from './IssueExplorer';
import { ScanHistory } from './ScanHistory';
import { SettingsPanel } from './SettingsPanel';
import { SourcesPanel } from './SourcesPanel';
import { StatTiles } from './StatTiles';

const SUMMARY = route('/summary');

const groupOf = (type: (typeof TYPE_ORDER)[number]): Group =>
	(Object.keys(GROUPS) as Exclude<Group, 'all'>[]).find((group) => GROUPS[group].includes(type)) ?? 'all';

export const App = () => {
	const { data: summary, error, loading } = useResource<SummaryData>(SUMMARY);
	const scan = useMutation<Scan>();
	const visible = usePageVisible();
	const presence = usePresence();
	const [group, setGroup] = useState<Group>('all');
	const [feedback, setFeedback] = useState<{ status: 'success' | 'warning' | 'error'; text: string } | null>(null);

	// A scan started elsewhere (another tab, WP-CLI): re-check every 4 s while it runs and the tab is visible.
	useEffect(() => {
		if (!summary?.running || !visible || scan.busy) {
			return undefined;
		}
		const timer = window.setTimeout(() => invalidate('/summary'), 4000);
		return () => window.clearTimeout(timer);
	}, [summary, visible, scan.busy]);

	const loaded = Boolean(summary);
	const [tourSteps, setTourSteps] = useState<TourStep[] | null>(null);
	const endTour = () => {
		setTourSteps(null);
		markSeen();
	};

	// The walkthrough: on the first visit, and after an update that added steps (only those).
	useEffect(() => {
		const tour = window.hoptrail?.tour;
		if (!loaded || !tour?.pending || '#settings' === window.location.hash) {
			return undefined;
		}
		// Let the issue list and history render first, so their steps have something to point at.
		const timer = window.setTimeout(() => {
			const pending = pendingSteps(tour.seen, tour.version);
			if (pending.length) {
				setTourSteps(pending);
			} else {
				markSeen();
			}
		}, 1200);
		return () => window.clearTimeout(timer);
	}, [loaded]);

	// Deep link (plugins screen → #settings): the section only exists once the data has loaded.
	useEffect(() => {
		if (!loaded || '#settings' !== window.location.hash) {
			return;
		}
		const target = document.getElementById('settings');
		const app = target?.closest('.rdi-app');
		if (!target || !app) {
			return;
		}

		// Sections above it (issues, history) load afterwards and push it down: keep it aligned while
		// the layout settles (max 3 s), and stop as soon as the user scrolls themselves.
		const align = () => target.scrollIntoView({ block: 'start' });
		const observer = new ResizeObserver(align);
		const stop = () => {
			observer.disconnect();
			window.clearTimeout(timer);
			['wheel', 'touchstart', 'keydown'].forEach((type) => window.removeEventListener(type, stop));
		};
		const timer = window.setTimeout(stop, 3000);

		align();
		target.focus({ preventScroll: true });
		observer.observe(app);
		['wheel', 'touchstart', 'keydown'].forEach((type) => window.addEventListener(type, stop, { passive: true }));

		return stop;
	}, [loaded]);

	// Filter from a tile or bar, then bring the issue list into view.
	const showGroup = (next: Group) => {
		setGroup(next);
		window.requestAnimationFrame(() => {
			const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			document.getElementById('rdi-explorer')?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
			document.getElementById('rdi-explorer-title')?.focus({ preventScroll: true });
		});
	};

	const runScan = async () => {
		setFeedback(null);
		try {
			const result = await scan.run(route('/scans'), 'POST');
			invalidate();
			const text = sprintf(
				/* translators: 1: number of rules, 2: number of issues, 3: duration. */
				_n('Scanned %1$d rules and found %2$d issue in %3$s.', 'Scanned %1$d rules and found %2$d issues in %3$s.', result.issue_count, 'hoptrail'),
				result.rule_count,
				result.issue_count,
				duration(result.duration_ms)
			);
			setFeedback({ status: 'partial' === result.status ? 'warning' : 'success', text: 'partial' === result.status ? `${text} ${result.message}` : text });
			speak('partial' === result.status ? `${text} ${result.message}` : text, 'polite');
		} catch (caught) {
			if (isAbort(caught)) {
				return;
			}
			setFeedback({ status: 'error', text: errorMessage(caught) });
			speak(errorMessage(caught), 'assertive');
		}
	};

	if (!summary) {
		return (
			<div className="rdi-app rdi-app--loading">
				{error ? <Notice status="error" isDismissible={false}>{error}</Notice> : loading && <Spinner />}
			</div>
		);
	}

	const latest = summary.latest;
	const multiSource = summary.sources.filter((source) => source.available && source.rules > 0).length > 1;
	const counts = latest?.totals.types ?? {};
	const running = scan.busy || summary.running;

	return (
		<MultiSourceContext.Provider value={multiSource}>
		<div className="rdi-app">
			<Banner others={presence.others} latest={latest} running={running} onScan={runScan} />

			{presence.note && (
				<Notice className="rdi-feedback" status="info" onRemove={presence.dismiss} spokenMessage={null}>
					{presence.note}
				</Notice>
			)}

			{feedback && (
				<Notice className="rdi-feedback" status={feedback.status} onRemove={() => setFeedback(null)} spokenMessage={null}>
					{feedback.text}
				</Notice>
			)}

			{summary.flush_request && <FlushApproval request={summary.flush_request} />}

			<Leftovers leftovers={summary.leftovers ?? []} targets={summary.targets ?? []} />

			{latest && 'partial' === latest.status && !feedback && (
				<Notice status="warning" isDismissible={false} spokenMessage={null}>{latest.message}</Notice>
			)}

			{!latest ? (
				<section className="rdi-card">
					<EmptyState title={__('Nothing scanned yet', 'hoptrail')} text={__('Use “Run scan” (top right) to check every redirect rule on this site.', 'hoptrail')} />
				</section>
			) : (
				<>
					<StatTiles summary={summary} active={group} onSelect={showGroup} />

					<div className="rdi-grid rdi-grid--charts">
						<section className="rdi-card rdi-card--chart" aria-labelledby="rdi-score-title">
							<header className="rdi-card__header">
								<h2 id="rdi-score-title">{__('Health score', 'hoptrail')}</h2>
								<span className="rdi-card__meta">{__('below 50 critical · 80 and up healthy', 'hoptrail')}</span>
							</header>
							<ScoreChart trend={summary.trend} />
						</section>
						<section className="rdi-card rdi-card--chart" aria-labelledby="rdi-severity-title">
							<header className="rdi-card__header">
								<h2 id="rdi-severity-title">{__('Issues by severity', 'hoptrail')}</h2>
							</header>
							<SeverityChart trend={summary.trend} />
						</section>
					</div>

					{0 === latest.issue_count ? (
						<>
							{multiSource && <SourcesPanel summary={summary} />}
							<AllClear rules={latest.rule_count} partial={'partial' === latest.status} />
						</>
					) : (
						<>
							<div className="rdi-grid">
								{multiSource && <SourcesPanel summary={summary} />}
								<section className="rdi-card" aria-labelledby="rdi-types-title">
									<header className="rdi-card__header">
										<h2 id="rdi-types-title">{__('Issues by type', 'hoptrail')}</h2>
										<span className="rdi-card__meta">{latest.issue_count}</span>
									</header>
									<BarList
										caption={__('Issues by type', 'hoptrail')}
										bars={TYPE_ORDER.map((type) => ({
											key: type,
											label: typeMeta(type).plural,
											hint: typeMeta(type).description,
											value: counts[type] ?? 0,
											onSelect: () => showGroup(groupOf(type)),
										}))}
									/>
								</section>
							</div>

							<IssueExplorer scanId={latest.id} counts={counts} sources={summary.sources} group={group} onGroup={setGroup} />
						</>
					)}
				</>
			)}

			<div className="rdi-grid rdi-grid--wide-start">
				<ScanHistory />
				<SettingsPanel settings={summary.settings} retention={summary.retention} nextScan={summary.next_scan} />
			</div>

			<p className="rdi-footer">
				<Button variant="link" onClick={() => setTourSteps(steps().filter((step) => null !== document.querySelector(step.target)))}>
					{__('Show the walkthrough', 'hoptrail')}
				</Button>
			</p>

			{tourSteps && <Tour steps={tourSteps} onEnd={endTour} />}
		</div>
		</MultiSourceContext.Provider>
	);
};
