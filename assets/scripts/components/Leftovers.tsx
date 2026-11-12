import { Button, SelectControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, invalidate, isAbort, route } from '../api/client';
import { useMutation } from '../api/hooks';
import type { Leftover, MigrationResult } from '../api/types';

interface Props {
	leftovers: Leftover[];
	targets: { slug: string; label: string }[];
}

/** What a (dry) run will do / did, in one sentence. */
const describe = (result: MigrationResult, target: string): string => {
	const parts: string[] = [
		result.dry_run
			/* translators: 1: number of rules, 2: plugin. */
			? sprintf(_n('%1$d rule will be copied to %2$s', '%1$d rules will be copied to %2$s', result.created, 'redirect-inspector'), result.created, target)
			/* translators: 1: number of rules, 2: plugin. */
			: sprintf(_n('%1$d rule copied to %2$s', '%1$d rules copied to %2$s', result.created, 'redirect-inspector'), result.created, target),
	];
	if (result.existing) {
		/* translators: %d: number of rules. */
		parts.push(sprintf(_n('%d already there', '%d already there', result.existing, 'redirect-inspector'), result.existing));
	}
	if (result.unsupported) {
		/* translators: %d: number of rules. */
		parts.push(sprintf(_n('%d it can’t hold', '%d it can’t hold', result.unsupported, 'redirect-inspector'), result.unsupported));
	}
	if (result.failed) {
		/* translators: %d: number of rules. */
		parts.push(sprintf(_n('%d failed', '%d failed', result.failed, 'redirect-inspector'), result.failed));
	}
	return `${parts.join(', ')}.`;
};

const Row = ({ leftover, targets }: { leftover: Leftover; targets: Props['targets'] }) => {
	const [target, setTarget] = useState(targets[0]?.slug ?? '');
	const [preview, setPreview] = useState<{ target: string; result: MigrationResult } | null>(null);
	const [done, setDone] = useState<MigrationResult | null>(null);
	const [error, setError] = useState<string | null>(null);
	const run = useMutation<MigrationResult>();
	const label = targets.find((item) => item.slug === target)?.label ?? '';
	const errorId = `rdi-leftover-error-${leftover.slug}`;
	const doneRef = useRef<HTMLParagraphElement>(null);
	useEffect(() => {
		if (done) {
			doneRef.current?.focus();
		}
	}, [done]);

	const go = async (dryRun: boolean) => {
		setError(null);
		try {
			const result = await run.run(route('/migrate'), 'POST', { from: leftover.slug, to: target, dry_run: dryRun });
			if (dryRun) {
				setPreview({ target, result });
			} else {
				setDone(result);
				invalidate();
			}
			speak(describe(result, label), 'polite');
		} catch (caught) {
			if (!isAbort(caught)) {
				setError(errorMessage(caught));
				speak(errorMessage(caught), 'assertive');
			}
		}
	};

	const previewed = preview && preview.target === target;

	return (
		<li className="rdi-leftovers__row">
			<div className="rdi-leftovers__what">
				<strong>{leftover.label}</strong>
				<span className="rdi-muted">
					{/* translators: %d: number of rules. */}
					{sprintf(_n('Not active · %d stored rule', 'Not active · %d stored rules', leftover.rules, 'redirect-inspector'), leftover.rules)}
				</span>
			</div>

			{done ? (
				<p className="rdi-leftovers__done" ref={doneRef} tabIndex={-1}>{describe(done, label)} {__('Run a scan to include them.', 'redirect-inspector')}</p>
			) : targets.length ? (
				<div className="rdi-leftovers__actions">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={__('Migrate to', 'redirect-inspector')}
						hideLabelFromVision
						value={target}
						options={targets.map((item) => ({ label: sprintf(__('Migrate to %s', 'redirect-inspector'), item.label), value: item.slug }))}
						onChange={(value: string) => setTarget(value)}
						aria-describedby={error ? errorId : undefined}
					/>
					<Button __next40pxDefaultSize variant="secondary" isBusy={run.busy && !previewed} disabled={run.busy} accessibleWhenDisabled onClick={() => go(true)}>
						{__('Preview', 'redirect-inspector')}
					</Button>
					<Button __next40pxDefaultSize variant="primary" isBusy={run.busy && Boolean(previewed)} disabled={run.busy || !previewed || !preview?.result.created} accessibleWhenDisabled onClick={() => go(false)}>
						{__('Migrate', 'redirect-inspector')}
					</Button>
				</div>
			) : (
				<p className="rdi-muted">{__('Activate a redirect plugin to migrate these rules into.', 'redirect-inspector')}</p>
			)}

			{previewed && !done && <p className="rdi-leftovers__preview">{describe(preview.result, label)}</p>}
			{error && <p className="rdi-leftovers__error" id={errorId}>{error}</p>}
		</li>
	);
};

/** Inactive redirect plugins that still hold rules: those redirect nothing until migrated. */
export const Leftovers = ({ leftovers, targets }: Props) => {
	if (!leftovers.length) {
		return null;
	}

	return (
		<section className="rdi-card rdi-leftovers" aria-labelledby="rdi-leftovers-title">
			<header className="rdi-card__header">
				<h2 id="rdi-leftovers-title">{__('Leftover redirects', 'redirect-inspector')}</h2>
				<span className="rdi-card__meta">{__('These plugins are not active, so their rules redirect nothing.', 'redirect-inspector')}</span>
			</header>
			<ul className="rdi-leftovers__list">
				{leftovers.map((leftover) => <Row key={leftover.slug} leftover={leftover} targets={targets} />)}
			</ul>
			<p className="rdi-card__meta">{__('Migrating copies the rules; the inactive plugin’s data is left as it is. Each copied rule is logged.', 'redirect-inspector')}</p>
		</section>
	);
};
