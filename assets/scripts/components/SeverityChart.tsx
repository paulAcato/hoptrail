import { useCallback, useState } from '@wordpress/element';
import { useEscape } from '../api/hooks';
import { __ } from '@wordpress/i18n';
import type { TrendPoint } from '../api/types';
import { SEVERITIES, SEVERITY_COLORS, useWidth } from '../lib/chart';
import { dateTime } from '../lib/format';
import { severityLabel } from '../lib/meta';
import { ChartTooltip, type Tip } from './ChartTooltip';

const HEIGHT = 170;
const PAD = { top: 12, right: 12, bottom: 24, left: 34 };
const GAP = 2;

const niceMax = (value: number): number => {
	if (value <= 5) {
		return 5;
	}
	const step = 10 ** Math.floor(Math.log10(value));
	return Math.ceil(value / step) * step;
};

/** Issues per scan, stacked by severity (critical at the baseline). Legend carries the latest counts. */
export const SeverityChart = ({ trend }: { trend: TrendPoint[] }) => {
	const [ref, width] = useWidth<HTMLDivElement>();
	const [tip, setTip] = useState<Tip | null>(null);
	const [active, setActive] = useState<number | null>(null);
	const hide = useCallback(() => { setTip(null); setActive(null); }, []);
	useEscape(null !== tip, hide);

	const latest = trend[trend.length - 1]?.severities ?? {};
	const max = niceMax(Math.max(1, ...trend.map((point) => SEVERITIES.reduce((sum, key) => sum + (point.severities?.[key] ?? 0), 0))));
	const plotWidth = Math.max(0, width - PAD.left - PAD.right);
	const plotHeight = HEIGHT - PAD.top - PAD.bottom;
	const slot = trend.length ? plotWidth / trend.length : 0;
	const barWidth = Math.max(4, Math.min(28, slot * 0.6));
	const y = (value: number) => PAD.top + plotHeight - (value / max) * plotHeight;

	const show = (index: number) => {
		const point = trend[index];
		const total = SEVERITIES.reduce((sum, key) => sum + (point.severities?.[key] ?? 0), 0);
		setActive(index);
		setTip({
			x: PAD.left + slot * index + slot / 2,
			y: Math.max(0, y(total) - 40),
			content: (
				<>
					<span>{dateTime(point.finished_at)}</span>
					{SEVERITIES.map((key) => (
						<span key={key} className="rdi-chart__tip-row"><i style={{ backgroundColor: SEVERITY_COLORS[key] }} />{severityLabel(key)} <strong>{point.severities?.[key] ?? 0}</strong></span>
					))}
				</>
			),
		});
	};

	return (
		<>
			<ul className="rdi-chart-legend">
				{SEVERITIES.map((key) => (
					<li key={key}>
						<i style={{ backgroundColor: SEVERITY_COLORS[key] }} aria-hidden="true" />
						{severityLabel(key)} <strong>{latest[key] ?? 0}</strong>
					</li>
				))}
			</ul>
			<div className="rdi-chart" ref={ref}>
				{width > 0 && (
					<svg width={width} height={HEIGHT} aria-hidden="true" onPointerLeave={hide}>
						{[0, max / 2, max].map((tick) => (
							<g key={tick}>
								<line x1={PAD.left} x2={width - PAD.right} y1={y(tick)} y2={y(tick)} className={`rdi-chart__grid${0 === tick ? ' is-base' : ''}`} />
								<text x={PAD.left - 8} y={y(tick)} className="rdi-chart__tick" textAnchor="end" dominantBaseline="middle">{tick}</text>
							</g>
						))}
						{trend.map((point, index) => {
							const left = PAD.left + slot * index + (slot - barWidth) / 2;
							let base = 0;
							return (
								<g key={point.id} className={active === null || active === index ? '' : 'is-dimmed'}>
									{SEVERITIES.map((key) => {
										const value = point.severities?.[key] ?? 0;
										if (!value) {
											return null;
										}
										const top = y(base + value);
										const height = Math.max(1, y(base) - top - (base ? GAP : 0));
										base += value;
										return <rect key={key} x={left} y={top} width={barWidth} height={height} rx={1} style={{ fill: SEVERITY_COLORS[key] }} />;
									})}
									<rect x={PAD.left + slot * index} y={PAD.top} width={slot} height={plotHeight} className="rdi-chart__hit" onPointerEnter={() => show(index)} />
								</g>
							);
						})}
						{trend.length > 0 && (
							<>
								<text x={PAD.left + slot / 2} y={HEIGHT - 6} className="rdi-chart__tick" textAnchor={trend.length > 1 ? 'start' : 'middle'}>{dateTime(trend[0].finished_at)}</text>
								{trend.length > 1 && <text x={PAD.left + slot * (trend.length - 0.5)} y={HEIGHT - 6} className="rdi-chart__tick" textAnchor="end">{dateTime(trend[trend.length - 1].finished_at)}</text>}
							</>
						)}
					</svg>
				)}
				<ChartTooltip tip={tip} width={width} />
				<table className="screen-reader-text">
					<caption>{__('Issues per scan by severity', 'redirect-inspector')}</caption>
					<thead>
						<tr>
							<th scope="col">{__('Scan', 'redirect-inspector')}</th>
							{SEVERITIES.map((key) => <th key={key} scope="col">{severityLabel(key)}</th>)}
						</tr>
					</thead>
					<tbody>
						{trend.map((point) => (
							<tr key={point.id}>
								<th scope="row">{dateTime(point.finished_at)}</th>
								{SEVERITIES.map((key) => <td key={key}>{point.severities?.[key] ?? 0}</td>)}
							</tr>
						))}
					</tbody>
				</table>
			</div>
		</>
	);
};
