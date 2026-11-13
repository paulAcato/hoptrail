import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { seen, tool } from '@wordpress/icons';
import { invalidate, errorMessage, isAbort, route } from '../api/client';
import { useMutation } from '../api/hooks';
import type { Fix, Fixed, Issue, LiveResult as Result } from '../api/types';
import { relative } from '../lib/format';
import { typeMeta } from '../lib/meta';
import { HopPath } from './HopPath';
import { LiveResult } from './LiveResult';
import { SeverityBadge } from './SeverityBadge';

/** Plugins without an "off" state: switching a rule off removes it there. */
const NO_DISABLE = ['yoast', 'simple-301'];

/** What the fix will do, in words, for the confirmation. */
const describe = (fix: Fix): string => {
	const [first] = fix.rules;
	if ('retarget' === fix.action) {
		/* translators: 1: source URL, 2: new target, 3: plugin. */
		return sprintf(__('Point %1$s straight to %2$s in %3$s?', 'hoptrail'), first.from, fix.to ?? '', first.label);
	}
	if (fix.rules.length > 1) {
		/* translators: %d: number of rules. */
		return sprintf(_n('Switch off %d duplicate rule?', 'Switch off %d duplicate rules?', fix.rules.length, 'hoptrail'), fix.rules.length);
	}
	return NO_DISABLE.includes(first.source)
		/* translators: 1: source URL, 2: plugin. */
		? sprintf(__('Remove the rule for %1$s from %2$s? It has no off switch; the old rule is kept in the fix log.', 'hoptrail'), first.from, first.label)
		/* translators: 1: source URL, 2: plugin. */
		: sprintf(__('Switch off the rule for %1$s in %2$s? You can switch it back on there.', 'hoptrail'), first.from, first.label);
};

const fixLabel = (fix: Fix): string => ('retarget' === fix.action ? __('Shorten chain', 'hoptrail') : __('Switch off rule', 'hoptrail'));

export const IssueCard = ({ issue }: { issue: Issue }) => {
	const [live, setLive] = useState<Result | null>(issue.live);
	const [fixed, setFixed] = useState<Fixed | null>(issue.fixed);
	const [confirming, setConfirming] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const check_ = useMutation<Result[]>();
	const apply = useMutation<Fixed>();
	const meta = typeMeta(issue.type);
	const errorId = `rdi-issue-error-${issue.id}`;

	// Keep focus where the user is when the inline confirmation swaps the buttons (2.4.3).
	const triggerRef = useRef<HTMLButtonElement>(null);
	const applyRef = useRef<HTMLButtonElement>(null);
	const fixedRef = useRef<HTMLParagraphElement>(null);
	const returnFocus = useRef(false);
	useEffect(() => {
		if (fixed) {
			fixedRef.current?.focus();
		} else if (confirming) {
			applyRef.current?.focus();
		} else if (returnFocus.current) {
			triggerRef.current?.focus();
		}
		returnFocus.current = false;
	}, [confirming, fixed]);
	const closeConfirm = () => {
		returnFocus.current = true;
		setConfirming(false);
	};

	const fail = (caught: unknown) => {
		if (isAbort(caught)) {
			return;
		}
		setError(errorMessage(caught));
		speak(errorMessage(caught), 'assertive');
	};

	const verify = async () => {
		setError(null);
		try {
			const [result] = await check_.run(route('/live-check'), 'POST', { issue_id: issue.id });
			setLive(result);
			invalidate('/issues');
			speak(__('Live check finished.', 'hoptrail'), 'polite');
		} catch (caught) {
			fail(caught);
		}
	};

	const applyFix = async () => {
		setError(null);
		try {
			const result = await apply.run(route(`/issues/${issue.id}/fix`), 'POST');
			setFixed(result);
			setConfirming(false);
			invalidate('/issues');
			speak(__('Fix applied. Run a new scan to update the numbers.', 'hoptrail'), 'polite');
		} catch (caught) {
			closeConfirm();
			fail(caught);
		}
	};

	return (
		<article className={`rdi-issue rdi-issue--${issue.severity}${fixed ? ' is-fixed' : ''}`} aria-labelledby={`rdi-issue-${issue.id}`}>
			<header className="rdi-issue__header">
				<SeverityBadge severity={issue.severity} />
				<h3 id={`rdi-issue-${issue.id}`} className="rdi-issue__title">
					<span className="rdi-issue__type">{meta.label}</span>
					<code className="rdi-issue__source">{issue.source_url}</code>
				</h3>
				{issue.is_new && !fixed && <span className="rdi-chip rdi-chip--new">{__('New', 'hoptrail')}</span>}
				{fixed && <span className="rdi-chip rdi-chip--fixed">{__('Fixed', 'hoptrail')}</span>}
				<span className="rdi-issue__hops-count">
					{issue.hops.length} {1 === issue.hops.length ? __('hop', 'hoptrail') : __('hops', 'hoptrail')}
				</span>
			</header>

			<HopPath hops={issue.hops} isLoop={'loop' === issue.type} />

			<p className="rdi-issue__suggestion">{issue.suggestion}</p>

			{fixed ? (
				<p className="rdi-issue__fixed" ref={fixedRef} tabIndex={-1}>
					{sprintf(
						/* translators: 1: who, 2: relative time. */
						__('Fixed by %1$s %2$s. Run a new scan to update the numbers.', 'hoptrail'),
						fixed.by,
						relative(fixed.at)
					)}
				</p>
			) : confirming && issue.fix ? (
				<div className="rdi-issue__confirm" role="group" aria-label={__('Confirm fix', 'hoptrail')}>
					<span>{describe(issue.fix)}</span>
					<Button ref={applyRef} variant="primary" size="compact" isBusy={apply.busy} disabled={apply.busy} accessibleWhenDisabled onClick={applyFix}>
						{apply.busy ? __('Applying…', 'hoptrail') : __('Apply', 'hoptrail')}
					</Button>
					<Button variant="tertiary" size="compact" disabled={apply.busy} accessibleWhenDisabled onClick={closeConfirm}>
						{__('Cancel', 'hoptrail')}
					</Button>
				</div>
			) : (
				<footer className="rdi-issue__footer">
					<Button variant="secondary" size="compact" icon={seen} isBusy={check_.busy} disabled={check_.busy} accessibleWhenDisabled onClick={verify} aria-describedby={error ? errorId : undefined}>
						{check_.busy ? __('Checking…', 'hoptrail') : __('Verify live', 'hoptrail')}
					</Button>
					{issue.fix && (
						<Button ref={triggerRef} variant="secondary" size="compact" icon={tool} onClick={() => setConfirming(true)} aria-describedby={error ? errorId : undefined}>
							{__('Apply fix', 'hoptrail')}: {fixLabel(issue.fix)}
						</Button>
					)}
					{error && <span className="rdi-issue__error" id={errorId}>{error}</span>}
				</footer>
			)}

			{live && <LiveResult result={live} />}
		</article>
	);
};
