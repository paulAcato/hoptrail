import { __ } from '@wordpress/i18n';
import type { LiveResult as Result } from '../api/types';
import { relative } from '../lib/format';

const verdictLabel = (verdict: Result['verdict']): string => ({
	ok: __('Loads after one redirect', 'redirect-inspector'),
	chain: __('Confirmed chain', 'redirect-inspector'),
	loop: __('Confirmed loop', 'redirect-inspector'),
	broken: __('Ends in an error page', 'redirect-inspector'),
	external: __('Leaves this site (not followed)', 'redirect-inspector'),
	error: __('Request failed', 'redirect-inspector'),
})[verdict];

export const LiveResult = ({ result }: { result: Result }) => (
	<div className={`rdi-live rdi-live--${result.verdict}`}>
		<p className="rdi-live__verdict">
			<strong>{verdictLabel(result.verdict)}</strong>
			<span>{__('Live check', 'redirect-inspector')} · {relative(result.checked_at)}</span>
		</p>
		<ol className="rdi-live__hops">
			{result.hops.map((hop, index) => (
				<li key={`${hop.url}-${index}`}>
					<span className={`rdi-live__status is-${null === hop.status ? 'none' : String(hop.status)[0]}xx`}>{hop.status ?? '—'}</span>
					<code>{hop.url}</code>
					{hop.note && <small>{'external' === hop.note ? __('external, not requested', 'redirect-inspector') : hop.note}</small>}
				</li>
			))}
		</ol>
	</div>
);
