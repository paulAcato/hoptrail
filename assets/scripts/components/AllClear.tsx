import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, check } from '@wordpress/icons';
import type { IssueType } from '../api/types';
import { TYPE_ORDER, typeMeta } from '../lib/meta';

const passed = (type: IssueType): string => ({
	loop: __('No loops', 'onehop-redirect-checker'),
	chain: __('No chains', 'onehop-redirect-checker'),
	conflict: __('No conflicts', 'onehop-redirect-checker'),
	noop: __('No self-redirects', 'onehop-redirect-checker'),
	duplicate: __('No duplicates', 'onehop-redirect-checker'),
	redundant: __('No redundant rules', 'onehop-redirect-checker'),
	shadow: __('No hidden content', 'onehop-redirect-checker'),
	broken: __('No broken targets', 'onehop-redirect-checker'),
})[type];

/** Shown instead of the issue breakdown and list when the latest scan found nothing. */
export const AllClear = ({ rules, partial }: { rules: number; partial: boolean }) => (
	<section className="rdi-card rdi-clear" aria-labelledby="rdi-clear-title">
		<div className="rdi-clear__head">
			<span className="rdi-clear__badge" aria-hidden="true"><Icon icon={check} size={32} /></span>
			<div>
				<h2 id="rdi-clear-title">
					{partial
						? __('No issues found so far', 'onehop-redirect-checker')
						: sprintf(/* translators: %d: number of redirect rules. */ _n('All clear: no issues in %d redirect rule', 'All clear: no issues in %d redirect rules', rules, 'onehop-redirect-checker'), rules)}
				</h2>
				<p>{__('Every redirect goes straight to its destination. These are the checks it passed:', 'onehop-redirect-checker')}</p>
			</div>
		</div>
		<ul className="rdi-clear__checks">
			{TYPE_ORDER.map((type) => (
				<li key={type}>
					<Icon icon={check} size={20} />
					<span>
						<strong>{passed(type)}</strong>
						<small>{typeMeta(type).description}</small>
					</span>
				</li>
			))}
		</ul>
	</section>
);
