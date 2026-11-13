import { useCallback, useState } from '@wordpress/element';
import { useEscape } from '../api/hooks';
import { __, _x } from '@wordpress/i18n';
import type { TrendPoint } from '../api/types';
import { useWidth } from '../lib/chart';
import { dateTime } from '../lib/format';
import { healthLabel } from '../lib/meta';
import { ChartTooltip, type Tip } from './ChartTooltip';

const HEIGHT = 170;
const PAD = { top: 12, right: 12, bottom: 24, left: 34 };
const TICKS = [0, 50, 80, 100];

/** Health score per scan on a fixed 0–100 axis, with the rating thresholds (50, 80) as gridlines. */
export const ScoreChart = ({ trend }: { trend: TrendPoint[] }) => {
	const [ref, width] = useWidth<HTMLDivElement>();
	const [tip, setTip] = useState<Tip | null>(null);
	const [active, setActive] = useState<number | null>(null);
	const hide = useCallback(() => { setTip(null); setActive(null); }, []);
	useEscape(null !== tip, hide);

	const plotWidth = Math.max(0, width - PAD.left - PAD.right);
	const plotHeight = HEIGHT - PAD.top - PAD.bottom;
	const x = (index: number) => PAD.left + (trend.length > 1 ? (index / (trend.length - 1)) * plotWidth : plotWidth / 2);
	const y = (score: number) => PAD.top + plotHeight - (score / 100) * plotHeight;
	const line = trend.map((point, index) => `${index ? 'L' : 'M'}${x(index).toFixed(1)},${y(point.score).toFixed(1)}`).join(' ');
	const last = trend[trend.length - 1];

	const show = (index: number) => {
		const point = trend[index];
		setActive(index);
		setTip({
			x: x(index),
			y: Math.max(0, y(point.score) - 30),
			content: <><strong>{point.score}/100</strong> {healthLabel(point.score)}<br /><span>{dateTime(point.finished_at)}</span></>,
		});
	};

	return (
		<div className="rdi-chart" ref={ref}>
			{width > 0 && (
				<svg width={width} height={HEIGHT} aria-hidden="true" onPointerLeave={hide}>
					{TICKS.map((tick) => (
						<g key={tick}>
							<line x1={PAD.left} x2={width - PAD.right} y1={y(tick)} y2={y(tick)} className={`rdi-chart__grid${50 === tick || 80 === tick ? ' is-threshold' : ''}`} />
							<text x={PAD.left - 8} y={y(tick)} className="rdi-chart__tick" textAnchor="end" dominantBaseline="middle">{tick}</text>
						</g>
					))}
					{trend.length > 1 && <path d={line} className="rdi-chart__line" />}
					{trend.map((point, index) => (
						<circle key={point.id} cx={x(index)} cy={y(point.score)} r={active === index || point === last ? 4 : 2.5} className="rdi-chart__point" />
					))}
					{trend.map((point, index) => (
						<rect
							key={`hit-${point.id}`}
							x={x(index) - Math.max(6, plotWidth / Math.max(1, trend.length - 1) / 2)}
							y={PAD.top}
							width={Math.max(12, plotWidth / Math.max(1, trend.length - 1))}
							height={plotHeight}
							className="rdi-chart__hit"
							onPointerEnter={() => show(index)}
						/>
					))}
					{trend.length > 0 && (
						<>
							<text x={x(0)} y={HEIGHT - 6} className="rdi-chart__tick" textAnchor={trend.length > 1 ? 'start' : 'middle'}>{dateTime(trend[0].finished_at)}</text>
							{trend.length > 1 && <text x={x(trend.length - 1)} y={HEIGHT - 6} className="rdi-chart__tick" textAnchor="end">{dateTime(last.finished_at)}</text>}
						</>
					)}
				</svg>
			)}
			<ChartTooltip tip={tip} width={width} />
			<table className="screen-reader-text">
				<caption>{__('Health score per scan', 'hoptrail')}</caption>
				<thead><tr><th scope="col">{_x('Scan', 'noun, table column: the date of a scan', 'hoptrail')}</th><th scope="col">{__('Score', 'hoptrail')}</th></tr></thead>
				<tbody>
					{trend.map((point) => <tr key={point.id}><th scope="row">{dateTime(point.finished_at)}</th><td>{point.score} ({healthLabel(point.score)})</td></tr>)}
				</tbody>
			</table>
		</div>
	);
};
