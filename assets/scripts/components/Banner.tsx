import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { update } from '@wordpress/icons';
import type { PresentUser, Scan } from '../api/types';
import { PresenceBadge } from './Presence';
import { dateTime, duration, relative } from '../lib/format';
import { healthBand, healthLabel } from '../lib/meta';
import { pluginName } from '../lib/plugin';

const RADIUS = 44;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

interface Props {
	others: PresentUser[];
	latest: Scan | null;
	running: boolean;
	onScan: () => void;
}

/** Page banner: title and context on the start side; Run scan (top) and the health score on the end side. */
export const Banner = ({ others, latest, running, onScan }: Props) => {
	const score = latest ? latest.score : null;
	const band = null === score ? 'none' : healthBand(score);

	return (
		<header className="rdi-banner">
			<div className="rdi-banner__intro">
				<h1 className="rdi-banner__title">{pluginName}</h1>
				<p className="rdi-banner__lead">
					{__('Follows every redirect rule of your SEO and redirect plugins to where it really ends.', 'hoptrail')}
				</p>
				<p className="rdi-banner__meta" title={latest ? dateTime(latest.finished_at) : undefined}>
					{latest
						? sprintf(
							/* translators: 1: relative time, 2: number of rules, 3: duration. */
							_n('Last scan %1$s · %2$d rule · %3$s', 'Last scan %1$s · %2$d rules · %3$s', latest.rule_count, 'hoptrail'),
							relative(latest.finished_at),
							latest.rule_count,
							duration(latest.duration_ms)
						)
						: __('No scan yet', 'hoptrail')}
				</p>
				<PresenceBadge others={others} />
			</div>

			{window.hoptrail?.wapuu && (
				<img className="rdi-banner__wapuu" src={window.hoptrail.wapuu} alt="" width={128} height={140} decoding="async" />
			)}

			<div className="rdi-banner__side">
				<Button
					variant="primary"
					className="rdi-banner__scan"
					icon={update}
					isBusy={running}
					disabled={running}
					accessibleWhenDisabled
					onClick={onScan}
				>
					{running ? __('Scanning…', 'hoptrail') : __('Run scan', 'hoptrail')}
				</Button>

				<div className={`rdi-gauge is-${band}`}>
					<svg viewBox="0 0 100 100" role="img" aria-label={null === score ? __('No scan yet', 'hoptrail') : `${__('Health score', 'hoptrail')}: ${score}/100, ${healthLabel(score)}`}>
						<circle cx="50" cy="50" r={RADIUS} className="rdi-gauge__track" />
						<circle cx="50" cy="50" r={RADIUS} className="rdi-gauge__fill" strokeDasharray={CIRCUMFERENCE} strokeDashoffset={CIRCUMFERENCE * (1 - (score ?? 0) / 100)} transform="rotate(-90 50 50)" />
					</svg>
					<div className="rdi-gauge__text" aria-hidden="true">
						<span className="rdi-gauge__value">{score ?? '–'}</span>
						<span className="rdi-gauge__label">{null === score ? __('no score', 'hoptrail') : healthLabel(score)}</span>
					</div>
				</div>
			</div>
		</header>
	);
};
