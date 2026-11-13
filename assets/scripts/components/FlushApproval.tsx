import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, invalidate, isAbort, route } from '../api/client';
import { useMutation } from '../api/hooks';
import type { FlushRequest } from '../api/types';
import { dateTime } from '../lib/format';

/**
 * A log deletion an AI client asked for (flush-logs ability). Nothing is deleted until an
 * administrator approves it here: the model can file the request, but not press this button.
 */
export const FlushApproval = ({ request }: { request: FlushRequest }) => {
	const [error, setError] = useState<string | null>(null);
	const approve = useMutation<{ scans: number; changes: number }>();
	const decline = useMutation<{ declined: boolean }>();
	const busy = approve.busy || decline.busy;

	// One standalone item per part: no counted fragments inside a sentence.
	const items: string[] = [];
	if ('changes' !== request.scope) {
		items.push(sprintf(/* translators: %d: number of scans. */ _n('%d scan and its issues', '%d scans and their issues', request.scans, 'hoptrail'), request.scans));
	}
	if ('history' !== request.scope) {
		items.push(sprintf(/* translators: %d: number of change log entries. */ _n('%d entry of the change log', '%d entries of the change log', request.changes, 'hoptrail'), request.changes));
	}

	const act = async (kind: 'approve' | 'decline') => {
		setError(null);
		try {
			const path = route(`/flush-request/${request.id}`);
			if ('approve' === kind) {
				const done = await approve.run(path, 'POST');
				speak([
					'changes' !== request.scope && sprintf(/* translators: %d: number of scans. */ _n('Deleted %d scan and its issues.', 'Deleted %d scans and their issues.', done.scans, 'hoptrail'), done.scans),
					'history' !== request.scope && sprintf(/* translators: %d: number of change log entries. */ _n('Deleted %d entry of the change log.', 'Deleted %d entries of the change log.', done.changes, 'hoptrail'), done.changes),
				].filter(Boolean).join(' '), 'polite');
			} else {
				await decline.run(path, 'DELETE');
				speak(__('Request declined. Nothing was deleted.', 'hoptrail'), 'polite');
			}
			invalidate();
		} catch (caught) {
			if (!isAbort(caught)) {
				setError(errorMessage(caught));
				speak(errorMessage(caught), 'assertive');
				invalidate('/summary');
			}
		}
	};

	return (
		<section className="rdi-card rdi-approval" role="region" aria-labelledby="rdi-approval-title">
			<h2 id="rdi-approval-title">{__('An AI client asks to delete logs', 'hoptrail')}</h2>
			<p>
				{request.by
					? sprintf(/* translators: 1: date and time, 2: administrator's name. */ __('On %1$s, an AI client used by %2$s asked through the Hoptrail abilities to permanently delete:', 'hoptrail'), dateTime(request.created), request.by)
					: sprintf(/* translators: %s: date and time. */ __('On %s, an AI client asked through the Hoptrail abilities to permanently delete:', 'hoptrail'), dateTime(request.created))}
			</p>
			<ul className="rdi-approval__list">
				{items.map((item) => <li key={item}>{item}</li>)}
			</ul>
			<p>{__('Nothing is deleted unless you approve it here.', 'hoptrail')}</p>
			<p className="rdi-muted">
				{sprintf(/* translators: %s: date and time. */ __('If you did not ask for this, decline it. The request expires at %s.', 'hoptrail'), dateTime(request.expires))}
			</p>
			{error && <p className="rdi-migrate__error" role="alert">{error}</p>}
			<div className="rdi-approval__buttons">
				<Button __next40pxDefaultSize variant="secondary" disabled={busy} accessibleWhenDisabled isBusy={decline.busy} onClick={() => act('decline')}>
					{__('Decline', 'hoptrail')}
				</Button>
				<Button __next40pxDefaultSize variant="primary" isDestructive disabled={busy} accessibleWhenDisabled isBusy={approve.busy} onClick={() => act('approve')}>
					{__('Delete logs', 'hoptrail')}
				</Button>
			</div>
		</section>
	);
};
