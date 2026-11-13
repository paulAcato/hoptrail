import { Button, SearchControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _x } from '@wordpress/i18n';
import { route } from '../api/client';
import { useDebounced, useResource } from '../api/hooks';
import type { NetworkSite, Paged } from '../api/types';
import { relative, safeHref } from '../lib/format';
import { groupCount, healthBand } from '../lib/meta';
import { pluginName } from '../lib/plugin';

const PER_PAGE = 20;

/** Read-only overview of every site's latest scan (manage_network_options). */
export const NetworkApp = () => {
	const [page, setPage] = useState(1);
	const [search, setSearch] = useState('');
	const query = useDebounced(search.trim(), 300);

	useEffect(() => setPage(1), [query]);

	const { data, loading, error } = useResource<Paged<NetworkSite>>(route('/network/sites', { page, search: query }));
	const pages = data ? Math.max(1, Math.ceil(data.total / PER_PAGE)) : 1;
	const base = window.location.origin;

	return (
		<div className="rdi-app">
			<header className="rdi-banner">
				<div className="rdi-banner__intro">
					<h1 className="rdi-banner__title">{pluginName}</h1>
					<p className="rdi-banner__lead">{__('Latest scan per site. Scans run per site, from each site’s Tools menu.', 'hoptrail')}</p>
				</div>
			</header>

			<section className="rdi-card" aria-labelledby="rdi-network-title">
				<header className="rdi-card__header">
					<h2 id="rdi-network-title">{__('Sites', 'hoptrail')}</h2>
					{loading && <Spinner />}
				</header>
				<div className="rdi-filters">
					<SearchControl __nextHasNoMarginBottom label={__('Search sites', 'hoptrail')} value={search} onChange={(value: string) => setSearch(value ?? '')} />
				</div>
				{error && <div className="notice notice-error inline" role="alert"><p>{error}</p></div>}
				{data && (
					<div className="rdi-table-wrap" role="region" aria-labelledby="rdi-network-title" tabIndex={0}>
						<table className="rdi-table">
							<thead>
								<tr>
									<th scope="col">{__('Site', 'hoptrail')}</th>
									<th scope="col" className="is-num">{__('Score', 'hoptrail')}</th>
									<th scope="col" className="is-num">{__('Rules', 'hoptrail')}</th>
									<th scope="col" className="is-num">{__('Loops', 'hoptrail')}</th>
									<th scope="col" className="is-num">{__('Chains', 'hoptrail')}</th>
									<th scope="col" className="is-num">{_x('Other', 'table column: number of other issue types', 'hoptrail')}</th>
									<th scope="col">{__('Last scan', 'hoptrail')}</th>
								</tr>
							</thead>
							<tbody>
								{data.items.map((site) => {
									const types = site.latest?.types ?? {};
									const loops = groupCount('loops', types);
									const chains = groupCount('chains', types);
									const href = safeHref(site.dashboard, base);
									return (
										<tr key={site.id}>
											<td>
												{href ? <a href={href}>{site.name || site.url}</a> : site.name || site.url}
												<br /><small className="rdi-muted">{site.url}</small>
											</td>
											<td className="is-num">{site.latest ? <span className={`rdi-score is-${healthBand(site.latest.score)}`}>{site.latest.score}</span> : '—'}</td>
											<td className="is-num">{site.latest?.rule_count ?? '—'}</td>
											<td className="is-num">{site.latest ? loops : '—'}</td>
											<td className="is-num">{site.latest ? chains : '—'}</td>
											<td className="is-num">{site.latest ? site.latest.issue_count - loops - chains : '—'}</td>
											<td>{!site.active ? __('Not active', 'hoptrail') : site.latest ? relative(site.latest.finished_at) : _x('Never', 'last scan: no scan has run yet', 'hoptrail')}</td>
										</tr>
									);
								})}
							</tbody>
						</table>
					</div>
				)}
				{data && data.total > PER_PAGE && (
					<nav className="rdi-pagination" aria-label={__('Site pages', 'hoptrail')}>
						<Button variant="tertiary" size="compact" disabled={page <= 1} accessibleWhenDisabled onClick={() => setPage(page - 1)}>{__('Previous', 'hoptrail')}</Button>
						<span>{page} / {pages}</span>
						<Button variant="tertiary" size="compact" disabled={page >= pages} accessibleWhenDisabled onClick={() => setPage(page + 1)}>{__('Next', 'hoptrail')}</Button>
					</nav>
				)}
			</section>
		</div>
	);
};
