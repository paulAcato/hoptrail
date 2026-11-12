import type { ReactNode } from 'react';

export interface Tip {
	/** Horizontal position in chart (SVG) coordinates, 0 = left edge. */
	x: number;
	y: number;
	content: ReactNode;
}

/** Positioned in SVG space; charts plot time left-to-right in every writing direction. */
export const ChartTooltip = ({ tip, width }: { tip: Tip | null; width: number }) => {
	if (!tip) {
		return null;
	}
	const flip = tip.x > width - 160;
	return (
		<div className={`rdi-chart__tip${flip ? ' is-flipped' : ''}`} style={{ insetBlockStart: tip.y, ...(flip ? { insetInlineEnd: width - tip.x + 10 } : { insetInlineStart: tip.x + 10 }) }} role="presentation" dir="auto">
			{tip.content}
		</div>
	);
};
