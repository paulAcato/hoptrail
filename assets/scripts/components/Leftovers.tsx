import { Button, CheckboxControl, Modal, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, invalidate, isAbort, route } from '../api/client';
import { useMutation, useResource } from '../api/hooks';
import type { Leftover, LeftoverRule, MigrationResult } from '../api/types';

interface Target {
	slug: string;
	label: string;
}

interface Props {
	leftovers: Leftover[];
	targets: Target[];
}

const statusLabel = (status: LeftoverRule['status'], target: string): string => ({
	/* translators: %s: plugin. */
	copy: sprintf(__('Will be copied to %s', 'redirect-inspector'), target),
	/* translators: %s: plugin. */
	exists: sprintf(__('Already in %s', 'redirect-inspector'), target),
	/* translators: %s: plugin. */
	unsupported: sprintf(__('%s can’t hold this rule', 'redirect-inspector'), target),
	none: '—',
})[status];

/** One rule row, with an inline "Delete" confirmation. */
const RuleRow = ({ rule, slug, target }: { rule: LeftoverRule; slug: string; target: string }) => {
	const [confirming, setConfirming] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const remove = useMutation<{ deleted: boolean }>();
	const confirmRef = useRef<HTMLButtonElement>(null);
	const triggerRef = useRef<HTMLButtonElement>(null);
	const back = useRef(false);

	useEffect(() => {
		if (confirming) {
			confirmRef.current?.focus();
		} else if (back.current) {
			triggerRef.current?.focus();
		}
		back.current = false;
	}, [confirming]);

	const onDelete = async () => {
		setError(null);
		try {
			await remove.run(route(`/leftovers/${slug}/rules/${encodeURIComponent(rule.id)}`, { from: rule.from }), 'DELETE');
			/* translators: %s: source URL. */
			speak(sprintf(__('Deleted the rule for %s.', 'redirect-inspector'), rule.from), 'polite');
			invalidate('/leftovers');
			invalidate('/summary');
		} catch (caught) {
			if (!isAbort(caught)) {
				back.current = true;
				setConfirming(false);
				setError(errorMessage(caught));
				speak(errorMessage(caught), 'assertive');
			}
		}
	};

	return (
		<tr>
			<td><code>{rule.from}</code></td>
			<td><code>{rule.to}</code></td>
			<td className="is-num">{rule.code}</td>
			<td><span className={`rdi-migrate__status is-${rule.status}`}>{statusLabel(rule.status, target)}</span></td>
			<td className="rdi-migrate__actions">
				{confirming ? (
					<>
						<Button ref={confirmRef} variant="primary" isDestructive size="compact" isBusy={remove.busy} disabled={remove.busy} accessibleWhenDisabled onClick={onDelete}>
							{__('Delete', 'redirect-inspector')}
						</Button>
						<Button variant="tertiary" size="compact" disabled={remove.busy} accessibleWhenDisabled onClick={() => { back.current = true; setConfirming(false); }}>
							{__('Cancel', 'redirect-inspector')}
						</Button>
					</>
				) : (
					<Button
						ref={triggerRef}
						variant="tertiary"
						isDestructive
						size="compact"
						/* translators: %s: source URL. */
						aria-label={sprintf(__('Delete the rule for %s', 'redirect-inspector'), rule.from)}
						onClick={() => setConfirming(true)}
					>
						{__('Delete', 'redirect-inspector')}
					</Button>
				)}
				{error && <span className="rdi-migrate__error" role="alert">{error}</span>}
			</td>
		</tr>
	);
};

/** Everything about one inactive plugin's rules: what migrating does, per rule; delete one or all. */
const LeftoverModal = ({ leftover, targets, onClose }: { leftover: Leftover; targets: Target[]; onClose: () => void }) => {
	const [target, setTarget] = useState(targets[0]?.slug ?? '');
	const [confirmingPurge, setConfirmingPurge] = useState(false);
	const [dropTables, setDropTables] = useState(false);
	const [result, setResult] = useState<string | null>(null);
	const [error, setError] = useState<string | null>(null);
	const migrate = useMutation<MigrationResult>();
	const purge = useMutation<{ deleted: number }>();
	const purgeConfirmRef = useRef<HTMLButtonElement>(null);
	const targetLabel = targets.find((item) => item.slug === target)?.label ?? '';

	const { data, loading, error: loadError } = useResource<{ rules: LeftoverRule[]; total: number }>(route(`/leftovers/${leftover.slug}`, { to: target }));
	const counts = useMemo(() => {
		const tally = { copy: 0, exists: 0, unsupported: 0 };
		data?.rules.forEach((rule) => {
			if (rule.status in tally) {
				tally[rule.status as keyof typeof tally] += 1;
			}
		});
		return tally;
	}, [data]);

	useEffect(() => {
		if (confirmingPurge) {
			purgeConfirmRef.current?.focus();
		}
	}, [confirmingPurge]);

	const report = (text: string, politeness: 'polite' | 'assertive' = 'polite') => {
		setResult(text);
		speak(text, politeness);
	};

	const onMigrate = async () => {
		setError(null);
		try {
			const done = await migrate.run(route('/migrate'), 'POST', { from: leftover.slug, to: target, dry_run: false });
			invalidate();
			/* translators: 1: number of rules, 2: plugin. */
			report(sprintf(_n('%1$d rule copied to %2$s. Run a scan to include it.', '%1$d rules copied to %2$s. Run a scan to include them.', done.created, 'redirect-inspector'), done.created, targetLabel));
		} catch (caught) {
			if (!isAbort(caught)) {
				setError(errorMessage(caught));
				speak(errorMessage(caught), 'assertive');
			}
		}
	};

	const onPurge = async () => {
		setError(null);
		try {
			const done = await purge.run(route(`/leftovers/${leftover.slug}`, { drop_tables: dropTables ? '1' : '' }), 'DELETE');
			invalidate();
			/* translators: 1: number of rules, 2: plugin. */
			report(sprintf(_n('Deleted %1$d stored rule of %2$s.', 'Deleted %1$d stored rules of %2$s.', done.deleted, 'redirect-inspector'), done.deleted, leftover.label));
			onClose();
		} catch (caught) {
			if (!isAbort(caught)) {
				setConfirmingPurge(false);
				setError(errorMessage(caught));
				speak(errorMessage(caught), 'assertive');
			}
		}
	};

	const rules = data?.rules ?? [];
	const nothingToMigrate = !targets.length || (data !== undefined && 0 === counts.copy);

	return (
		<Modal
			/* translators: %s: plugin. */
			title={sprintf(__('Leftover rules of %s', 'redirect-inspector'), leftover.label)}
			onRequestClose={onClose}
			size="large"
			className="rdi-migrate"
		>
			<p className="rdi-migrate__intro">
				{__('This plugin is not active, so these rules redirect nothing. Copy them into an active redirect plugin, or delete them.', 'redirect-inspector')}
			</p>

			{targets.length > 0 && (
				<div className="rdi-migrate__target">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={__('Migrate to', 'redirect-inspector')}
						value={target}
						options={targets.map((item) => ({ label: item.label, value: item.slug }))}
						onChange={(value: string) => setTarget(value)}
					/>
					{data && (
						<p className="rdi-migrate__summary" aria-live="off">
							{[
								/* translators: %d: number of rules. */
								sprintf(_n('%d will be copied', '%d will be copied', counts.copy, 'redirect-inspector'), counts.copy),
								/* translators: %d: number of rules. */
								counts.exists ? sprintf(_n('%d already there', '%d already there', counts.exists, 'redirect-inspector'), counts.exists) : '',
								/* translators: %d: number of rules. */
								counts.unsupported ? sprintf(_n('%d not supported', '%d not supported', counts.unsupported, 'redirect-inspector'), counts.unsupported) : '',
							].filter(Boolean).join(' · ')}
						</p>
					)}
				</div>
			)}

			{loadError && <div className="notice notice-error inline" role="alert"><p>{loadError}</p></div>}
			{loading && !data && <Spinner />}

			{rules.length > 0 && (
				<div className="rdi-table-wrap" role="region" aria-label={__('Stored rules', 'redirect-inspector')} tabIndex={0}>
					<table className="rdi-table rdi-migrate__table">
						<thead>
							<tr>
								<th scope="col">{__('From', 'redirect-inspector')}</th>
								<th scope="col">{__('To', 'redirect-inspector')}</th>
								<th scope="col" className="is-num">{__('Code', 'redirect-inspector')}</th>
								<th scope="col">{__('Migration', 'redirect-inspector')}</th>
								<th scope="col"><span className="screen-reader-text">{__('Actions', 'redirect-inspector')}</span></th>
							</tr>
						</thead>
						<tbody>
							{rules.map((rule) => <RuleRow key={rule.id} rule={rule} slug={leftover.slug} target={targetLabel} />)}
						</tbody>
					</table>
				</div>
			)}

			{result && <p className="rdi-migrate__result">{result}</p>}
			{error && <p className="rdi-migrate__error" role="alert">{error}</p>}

			<div className="rdi-migrate__footer">
				{confirmingPurge ? (
					<div className="rdi-migrate__confirm">
						<p>
							{sprintf(
								/* translators: 1: number of rules, 2: plugin. */
								_n('Permanently delete %1$d stored rule of %2$s? This cannot be undone.', 'Permanently delete %1$d stored rules of %2$s? This cannot be undone.', data?.total ?? leftover.rules, 'redirect-inspector'),
								data?.total ?? leftover.rules,
								leftover.label
							)}
						</p>
						{leftover.tables > 0 && (
							<CheckboxControl
								__nextHasNoMarginBottom
								/* translators: %s: plugin. */
								label={sprintf(__('Also drop the redirect tables of %s', 'redirect-inspector'), leftover.label)}
								help={__('Only when you don’t plan to activate it again; it would have to set up its tables anew.', 'redirect-inspector')}
								checked={dropTables}
								onChange={setDropTables}
							/>
						)}
						<div className="rdi-migrate__buttons">
							<Button __next40pxDefaultSize variant="tertiary" disabled={purge.busy} accessibleWhenDisabled onClick={() => setConfirmingPurge(false)}>
								{__('Cancel', 'redirect-inspector')}
							</Button>
							<Button ref={purgeConfirmRef} __next40pxDefaultSize variant="primary" isDestructive isBusy={purge.busy} disabled={purge.busy} accessibleWhenDisabled onClick={onPurge}>
								{dropTables ? __('Delete rules and tables', 'redirect-inspector') : __('Delete rules', 'redirect-inspector')}
							</Button>
						</div>
					</div>
				) : (
					<div className="rdi-migrate__buttons">
						{!nothingToMigrate && (
							<Button __next40pxDefaultSize variant="tertiary" isDestructive onClick={() => setConfirmingPurge(true)}>
								{__('Delete all data', 'redirect-inspector')}
							</Button>
						)}
						{nothingToMigrate ? (
							<Button __next40pxDefaultSize variant="primary" isDestructive disabled={!data} accessibleWhenDisabled onClick={() => setConfirmingPurge(true)}>
								{__('Delete data', 'redirect-inspector')}
							</Button>
						) : (
							<Button __next40pxDefaultSize variant="primary" isBusy={migrate.busy} disabled={migrate.busy || !data} accessibleWhenDisabled onClick={onMigrate}>
								{/* translators: %d: number of rules. */}
								{sprintf(_n('Migrate %d rule', 'Migrate %d rules', counts.copy, 'redirect-inspector'), counts.copy)}
							</Button>
						)}
					</div>
				)}
			</div>
		</Modal>
	);
};

/** Inactive redirect plugins that still hold rules: those redirect nothing until migrated. */
export const Leftovers = ({ leftovers, targets }: Props) => {
	const [open, setOpen] = useState<string | null>(null);
	const triggers = useRef<Record<string, HTMLButtonElement | null>>({});
	const current = leftovers.find((leftover) => leftover.slug === open);

	if (!leftovers.length) {
		return null;
	}

	const close = () => {
		const slug = open;
		setOpen(null);
		// Back to the button that opened it (the row may be gone after a delete).
		window.requestAnimationFrame(() => (slug && triggers.current[slug]) ? triggers.current[slug]?.focus() : document.getElementById('rdi-leftovers-title')?.focus());
	};

	return (
		<section className="rdi-card rdi-leftovers" aria-labelledby="rdi-leftovers-title">
			<header className="rdi-card__header">
				<h2 id="rdi-leftovers-title" tabIndex={-1}>{__('Leftover redirects', 'redirect-inspector')}</h2>
				<span className="rdi-card__meta">{__('These plugins are not active, so their rules redirect nothing.', 'redirect-inspector')}</span>
			</header>
			<ul className="rdi-leftovers__list">
				{leftovers.map((leftover) => (
					<li key={leftover.slug} className="rdi-leftovers__row">
						<div className="rdi-leftovers__what">
							<strong>{leftover.label}</strong>
							<span className="rdi-muted">
								{/* translators: %d: number of rules. */}
								{sprintf(_n('Not active · %d stored rule', 'Not active · %d stored rules', leftover.rules, 'redirect-inspector'), leftover.rules)}
							</span>
						</div>
						<Button
							ref={(node: HTMLButtonElement | null) => { triggers.current[leftover.slug] = node; }}
							__next40pxDefaultSize
							variant="secondary"
							onClick={() => setOpen(leftover.slug)}
						>
							{__('Review rules', 'redirect-inspector')}
						</Button>
					</li>
				))}
			</ul>
			{current && <LeftoverModal leftover={current} targets={targets} onClose={close} />}
		</section>
	);
};
