import { Button, SearchControl, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { regularExpression } from '../lib/icons';
import { route } from '../api/client';
import { useDebounced, useResource } from '../api/hooks';
import type { Issue, Paged, SourceSummary, TypeCounts } from '../api/types';
import { useMultiSource } from '../lib/context';
import { GROUPS, groupCount, groupLabel, type Group } from '../lib/meta';
import { EmptyState } from './EmptyState';
import { IssueCard } from './IssueCard';

const PER_PAGE = 10;
const TABS: Group[] = ['all', 'loops', 'chains', 'conflicts', 'unnecessary', 'broken'];

interface Props {
	scanId: number;
	counts: TypeCounts;
	sources: SourceSummary[];
	group: Group;
	onGroup: (group: Group) => void;
}

export const IssueExplorer = ({ scanId, counts, sources, group, onGroup }: Props) => {
	const [plugin, setPlugin] = useState('');
	const [search, setSearch] = useState('');
	const [regex, setRegex] = useState(false);
	const [page, setPage] = useState(1);
	const query = useDebounced(search.trim(), 300);
	const multi = useMultiSource();

	// Any filter change starts at page 1.
	useEffect(() => setPage(1), [group, plugin, query, regex, scanId]);

	const path = useMemo(
		() => route('/issues', {
			scan: scanId,
			types: 'all' === group ? undefined : GROUPS[group],
			plugin,
			search: query,
			regex: regex && query ? 1 : undefined,
			page,
			per_page: PER_PAGE,
		}),
		[scanId, group, plugin, query, regex, page]
	);

	const { data, loading, error } = useResource<Paged<Issue>>(path);

	// Announce the result count after a filter change (not on first load).
	const filterKey = `${group}|${plugin}|${query}|${regex}`;
	const announced = useRef(filterKey);
	useEffect(() => {
		if (!data || loading || announced.current === filterKey) {
			return;
		}
		announced.current = filterKey;
		speak(sprintf(/* translators: %d: number of issues. */ _n('%d issue found', '%d issues found', data.total, 'onehop-redirect-checker'), data.total), 'polite');
	}, [data, loading, filterKey]);
	const pages = data ? Math.max(1, Math.ceil(data.total / PER_PAGE)) : 1;

	return (
		<section id="rdi-explorer" className="rdi-card rdi-explorer" aria-labelledby="rdi-explorer-title">
			<header className="rdi-card__header">
				<h2 id="rdi-explorer-title" tabIndex={-1}>{__('Issues', 'onehop-redirect-checker')}</h2>
				{loading && <Spinner />}
			</header>

			<div className="rdi-tabs" role="group" aria-label={__('Filter by issue type', 'onehop-redirect-checker')}>
				{TABS.map((tab) => (
					<button key={tab} type="button" className={`rdi-tabs__tab${group === tab ? ' is-active' : ''}`} aria-pressed={group === tab} onClick={() => onGroup(tab)}>
						{groupLabel(tab)}
						<span className="rdi-tabs__count">{groupCount(tab, counts)}</span>
					</button>
				))}
			</div>

			<div className={`rdi-filters${multi ? '' : ' is-single'}`}>
				<div className="rdi-filters__search">
					<SearchControl
						__nextHasNoMarginBottom
						label={regex ? __('Search URLs with a regular expression', 'onehop-redirect-checker') : __('Search URLs', 'onehop-redirect-checker')}
						placeholder={regex ? '^/blog/(.*)$' : __('Search', 'onehop-redirect-checker')}
						value={search}
						onChange={(value: string) => setSearch(value ?? '')}
					/>
					<Button
						className="rdi-filters__regex"
						__next40pxDefaultSize
						variant="secondary"
						icon={regularExpression}
						isPressed={regex}
						label={__('Use regular expression', 'onehop-redirect-checker')}
						showTooltip
						onClick={() => setRegex((value) => !value)}
					/>
				</div>
				{multi && <SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={__('Plugin', 'onehop-redirect-checker')}
					hideLabelFromVision
					value={plugin}
					options={[
						{ label: __('All plugins', 'onehop-redirect-checker'), value: '' },
						...sources.filter((source) => source.available).map((source) => ({ label: source.label, value: source.slug })),
					]}
					onChange={(value: string) => setPlugin(value)}
				/>}
			</div>

			{error && <div className="notice notice-error inline" role="alert"><p>{error}</p></div>}

			<div className="rdi-issues" aria-busy={loading}>
				{data && 0 === data.items.length && !loading && (
					<EmptyState
						title={query || plugin || 'all' !== group ? __('Nothing matches these filters', 'onehop-redirect-checker') : __('No issues found', 'onehop-redirect-checker')}
						text={query || plugin || 'all' !== group ? __('Try another type, plugin or search term.', 'onehop-redirect-checker') : __('Every redirect goes straight to its destination.', 'onehop-redirect-checker')}
					/>
				)}
				{data?.items.map((issue) => <IssueCard key={issue.id} issue={issue} />)}
			</div>

			{data && data.total > PER_PAGE && (
				<nav className="rdi-pagination" aria-label={__('Issue pages', 'onehop-redirect-checker')}>
					<Button variant="tertiary" size="compact" disabled={page <= 1} accessibleWhenDisabled onClick={() => setPage(page - 1)}>
						{__('Previous', 'onehop-redirect-checker')}
					</Button>
					<span>
						{sprintf(/* translators: 1: current page, 2: number of pages, 3: number of issues. */ _n('Page %1$d of %2$d · %3$d issue', 'Page %1$d of %2$d · %3$d issues', data.total, 'onehop-redirect-checker'), page, pages, data.total)}
					</span>
					<Button variant="tertiary" size="compact" disabled={page >= pages} accessibleWhenDisabled onClick={() => setPage(page + 1)}>
						{__('Next', 'onehop-redirect-checker')}
					</Button>
				</nav>
			)}
		</section>
	);
};
