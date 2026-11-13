import { __, _x, sprintf } from '@wordpress/i18n';
import { Icon, arrowRight, loop as loopIcon } from '@wordpress/icons';
import type { Hop } from '../api/types';
import { safeHref } from '../lib/format';
import { useMultiSource } from '../lib/context';
import { pluginColor } from '../lib/meta';

const siteUrl = window.hoptrail?.siteUrl ?? window.location.origin;

/** URL → [code · plugin] → URL …; a loop's last arrow points back to the start. */
export const HopPath = ({ hops, isLoop }: { hops: Hop[]; isLoop: boolean }) => {
	const multi = useMultiSource();

	if (!hops.length) {
		return null;
	}

	return (
		<ol className={`rdi-hops${isLoop ? ' is-loop' : ''}`} aria-label={__('Redirect path', 'hoptrail')}>
			<li className="rdi-hops__node"><code>{hops[0].from}</code></li>
			{hops.map((hop, index) => {
				const last = index === hops.length - 1;
				const edit = hop.rule.edit ? safeHref(hop.rule.edit, siteUrl) : undefined;
				return (
					<li key={`${hop.rule.source}-${hop.rule.id}-${index}`} className="rdi-hops__step">
						<span className="rdi-hops__edge" style={{ '--rdi-plugin': pluginColor(hop.rule.source) } as React.CSSProperties}>
							<Icon icon={isLoop && last ? loopIcon : arrowRight} size={16} />
							{multi || !edit
								? <span className="rdi-hops__code">{hop.code}</span>
								: <a className="rdi-hops__code" href={edit} aria-label={sprintf(/* translators: 1: HTTP status code, e.g. 301, 2: target URL. */ _x('%1$s – edit the rule to %2$s', 'link label on a status code', 'hoptrail'), String(hop.code), hop.to)}>{hop.code}</a>}
							{multi && (edit
								? <a className="rdi-hops__plugin" href={edit} aria-label={sprintf(/* translators: 1: plugin name, 2: target URL. */ _x('%1$s – edit the rule to %2$s', 'link label on a plugin name', 'hoptrail'), hop.rule.label, hop.to)}>{hop.rule.label}</a>
								: <span className="rdi-hops__plugin">{hop.rule.label}</span>)}
						</span>
						<span className={`rdi-hops__node${isLoop && last ? ' is-start' : ''}`}>
							<code>{hop.to}</code>
							{isLoop && last && <span className="rdi-hops__note">{__('back to start', 'hoptrail')}</span>}
						</span>
					</li>
				);
			})}
		</ol>
	);
};
