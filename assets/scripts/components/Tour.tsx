import { Button, Popover } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import type { TourStep } from '../lib/tour';

interface Props {
	steps: TourStep[];
	onEnd: () => void;
}

/**
 * The quick walkthrough: one popover at a time, pointing at a part of the dashboard.
 * A non-modal dialog: focus moves into it, Escape or "Skip" ends it, and focus goes back to where it
 * was (or to the page heading).
 */
export const Tour = ({ steps, onEnd }: Props) => {
	const [index, setIndex] = useState(0);
	const [anchor, setAnchor] = useState<HTMLElement | null>(null);
	const returnFocus = useRef<HTMLElement | null>(document.activeElement instanceof HTMLElement ? document.activeElement : null);
	const primary = useRef<HTMLButtonElement>(null);
	const step = steps[index];
	const last = index + 1 === steps.length;

	const end = () => {
		onEnd();
		const back = returnFocus.current && returnFocus.current !== document.body ? returnFocus.current : (document.getElementById('rdi-explorer-title') ?? document.getElementById('rdi-leftovers-title'));
		// After the popover has gone (it hands focus back itself first).
		window.requestAnimationFrame(() => back?.focus?.());
	};

	// Point at this step's target; skip a step whose target isn't on the page (any more).
	useEffect(() => {
		const target = step ? document.querySelector<HTMLElement>(step.target) : null;
		if (!target) {
			if (step && !last) {
				setIndex(index + 1);
			} else {
				end();
			}
			return;
		}

		const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		target.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
		setAnchor(target);
		speak(`${step.title}. ${step.text}`, 'polite');
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Per step.
	}, [index]);

	// Focus the main button of each step, once the popover is placed.
	useEffect(() => {
		if (anchor) {
			window.requestAnimationFrame(() => primary.current?.focus());
		}
	}, [anchor, index]);

	if (!step || !anchor) {
		return null;
	}

	return (
		<Popover
			key={index}
			anchor={anchor}
			placement={step.placement ?? 'bottom-start'}
			offset={12}
			noArrow={false}
			className="rdi-tour"
			focusOnMount={false}
			onClose={end}
			onFocusOutside={() => undefined}
		>
			<div className="rdi-tour__dialog" role="dialog" aria-labelledby="rdi-tour-title" aria-describedby="rdi-tour-text">
				<h3 id="rdi-tour-title" className="rdi-tour__title">{step.title}</h3>
				<p id="rdi-tour-text" className="rdi-tour__text">{step.text}</p>
				<div className="rdi-tour__footer">
					<span className="rdi-tour__count">
						{sprintf(/* translators: 1: step number, 2: number of steps. */ __('%1$d of %2$d', 'hoptrail'), index + 1, steps.length)}
					</span>
					{!last && (
						<Button variant="tertiary" size="compact" onClick={end}>
							{__('Skip walkthrough', 'hoptrail')}
						</Button>
					)}
					{index > 0 && (
						<Button variant="secondary" size="compact" onClick={() => setIndex(index - 1)}>
							{__('Back', 'hoptrail')}
						</Button>
					)}
					<Button ref={primary} variant="primary" size="compact" onClick={() => (last ? end() : setIndex(index + 1))}>
						{last ? __('Done', 'hoptrail') : __('Next', 'hoptrail')}
					</Button>
				</div>
			</div>
		</Popover>
	);
};
