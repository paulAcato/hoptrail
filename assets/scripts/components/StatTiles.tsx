import { __, sprintf } from '@wordpress/i18n';
import type { Summary } from '../api/types';
import { compact, dateTime } from '../lib/format';
import { groupCount, groupLabel, type Group } from '../lib/meta';
import { Sparkline } from './Sparkline';

const TILES: Exclude<Group, 'all'>[] = ['loops', 'chains', 'conflicts', 'unnecessary', 'broken'];

/** One tile per issue group: count, change since the previous scan, 12-scan trend. Click filters. */
export const StatTiles = ({ summary, active, onSelect }: { summary: Summary; active: Group; onSelect: (group: Group) => void }) => {
	const trend = summary.trend;
	const current = summary.latest?.totals.types ?? {};
	const previous = trend.length > 1 ? trend[trend.length - 2].types : null;

	return (
		<div className="rdi-tiles">
			{TILES.map((group) => {
				const value = groupCount(group, current);
				const delta = previous ? value - groupCount(group, previous) : null;
				const tone = !delta ? 'flat' : delta < 0 ? 'good' : 'bad';

				return (
					<button
						type="button"
						key={group}
						className={`rdi-tile rdi-tile--${group}${active === group ? ' is-active' : ''}${value ? ' has-issues' : ''}`}
						aria-pressed={active === group}
						onClick={() => onSelect(active === group ? 'all' : group)}
					>
						<span className="rdi-tile__label">{groupLabel(group)}</span>
						<span className="rdi-tile__value">{compact(value)}</span>
						<span className={`rdi-tile__delta is-${tone}`}>
							{null === delta || 0 === delta
								? __('No change', 'hoptrail')
								/* translators: %s: signed change, e.g. +2. */
								: sprintf(__('%s since last scan', 'hoptrail'), `${delta > 0 ? '+' : '−'}${Math.abs(delta)}`)}
						</span>
						<Sparkline points={trend.map((point) => ({ label: dateTime(point.finished_at), value: groupCount(group, point.types) }))} label={groupLabel(group)} />
					</button>
				);
			})}
		</div>
	);
};
