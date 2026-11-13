import { __, _n, sprintf } from '@wordpress/i18n';
import type { Summary } from '../api/types';
import { compact } from '../lib/format';
import { pluginColor } from '../lib/meta';

/** Rules per plugin: one whole split into parts, plus a legend that carries the numbers. */
export const SourcesPanel = ({ summary }: { summary: Summary }) => {
	const sources = summary.sources;
	const scanned = summary.latest?.totals.sources ?? {};
	const total = sources.reduce((sum, source) => sum + source.rules, 0);

	return (
		<section className="rdi-card rdi-sources" aria-labelledby="rdi-sources-title">
			<header className="rdi-card__header">
				<h2 id="rdi-sources-title">{__('Rules by plugin', 'hoptrail')}</h2>
				<span className="rdi-card__meta">{sprintf(/* translators: %s: number of rules. */ _n('%s rule', '%s rules', total, 'hoptrail'), compact(total))}</span>
			</header>

			<div className="rdi-segments" aria-hidden="true">
				{sources.filter((source) => source.rules > 0).map((source, index) => (
					<span key={source.slug} className="rdi-segments__part" style={{ flexGrow: source.rules, backgroundColor: pluginColor(source.slug, index) }} />
				))}
			</div>

			<ul className="rdi-legend">
				{sources.map((source, index) => {
					const issues = scanned[source.slug]?.issues ?? 0;
					return (
						<li key={source.slug} className={source.available ? '' : 'is-unavailable'}>
							<span className="rdi-legend__swatch" style={{ backgroundColor: pluginColor(source.slug, index) }} aria-hidden="true" />
							<span className="rdi-legend__name">{source.label}</span>
							<span className="rdi-legend__status">
								{source.available
									? (issues ? sprintf(/* translators: %d: number of issues. */ _n('%d issue', '%d issues', issues, 'hoptrail'), issues) : __('No issues', 'hoptrail'))
									: __('Not active', 'hoptrail')}
							</span>
							<span className="rdi-legend__value">{source.available ? compact(source.rules) : '—'}</span>
						</li>
					);
				})}
			</ul>
		</section>
	);
};
