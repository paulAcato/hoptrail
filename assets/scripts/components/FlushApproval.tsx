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

	const what = {
		/* translators: %d: number of scans. */
		history: sprintf(_n('%d scan and its issues', '%d scans and their issues', request.scans, 'redirect-inspector'), request.scans),
		/* translators: %d: number of change-log entries. */
		changes: sprintf(_n('%d entry of the change log', '%d entries of the change log', request.changes, 'redirect-inspector'), request.changes),
		/* translators: 1: number of scans, 2: number of change-log entries. */
		all: sprintf(__('%1$d scans with their issues and %2$d change-log entries', 'redirect-inspector'), request.scans, request.changes),
	}[request.scope];

	const act = async (kind: 'approve' | 'decline') => {
		setError(null);
		try {
			const path = route(`/flush-request/${request.id}`);
			if ('approve' === kind) {
				const done = await approve.run(path, 'POST');
				/* translators: 1: number of scans, 2: number of change-log entries. */
				speak(sprintf(__('Deleted %1$d scans and %2$d change-log entries.', 'redirect-inspector'), done.scans, done.changes), 'polite');
			} else {
				await decline.run(path, 'DELETE');
				speak(__('Request declined. Nothing was deleted.', 'redirect-inspector'), 'polite');
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
			<h2 id="rdi-approval-title">{__('An AI client asks to delete logs', 'redirect-inspector')}</h2>
			<p>
				{sprintf(
					/* translators: 1: what would be deleted, 2: administrator's name, 3: date and time. */
					__('Through the Redirect Inspector abilities, an AI client used by %2$s asked to permanently delete %1$s (%3$s). Nothing is deleted unless you approve it here.', 'redirect-inspector'),
					what,
					request.by || __('an administrator', 'redirect-inspector'),
					dateTime(request.created)
				)}
			</p>
			<p className="rdi-muted">
				{/* translators: %s: date and time. */}
				{sprintf(__('If you did not ask for this, decline it. The request expires at %s.', 'redirect-inspector'), dateTime(request.expires))}
			</p>
			{error && <p className="rdi-migrate__error" role="alert">{error}</p>}
			<div className="rdi-approval__buttons">
				<Button __next40pxDefaultSize variant="secondary" disabled={busy} accessibleWhenDisabled isBusy={decline.busy} onClick={() => act('decline')}>
					{__('Decline', 'redirect-inspector')}
				</Button>
				<Button __next40pxDefaultSize variant="primary" isDestructive disabled={busy} accessibleWhenDisabled isBusy={approve.busy} onClick={() => act('approve')}>
					{__('Delete logs', 'redirect-inspector')}
				</Button>
			</div>
		</section>
	);
};
