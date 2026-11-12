import { useCallback, useMemo, useState } from '@wordpress/element';
import { useEscape } from '../api/hooks';

interface Point {
	label: string;
	value: number;
}

const WIDTH = 120;
const HEIGHT = 36;
const PAD = 5;

/** Last 12 scans: a quiet line with the latest point marked. Hover names the point. */
export const Sparkline = ({ points, label }: { points: Point[]; label: string }) => {
	const [active, setActive] = useState<number | null>(null);
	const close = useCallback(() => setActive(null), []);
	useEscape(null !== active, close);
	const data = points.slice(-12);

	const coords = useMemo(() => {
		const max = Math.max(1, ...data.map((point) => point.value));
		const step = data.length > 1 ? (WIDTH - PAD * 2) / (data.length - 1) : 0;
		return data.map((point, index) => ({ x: PAD + index * step, y: HEIGHT - PAD - (point.value / max) * (HEIGHT - PAD * 2) }));
	}, [data]);

	if (data.length < 2) {
		return <span className="rdi-spark" aria-hidden="true" />;
	}

	const path = coords.map((coord, index) => `${index ? 'L' : 'M'}${coord.x.toFixed(1)},${coord.y.toFixed(1)}`).join(' ');
	const marker = coords[active ?? coords.length - 1];
	const onMove = (event: React.PointerEvent<SVGSVGElement>) => {
		const box = event.currentTarget.getBoundingClientRect();
		const ratio = (event.clientX - box.left) / box.width;
		setActive(Math.max(0, Math.min(data.length - 1, Math.round(((ratio * WIDTH) - PAD) / ((WIDTH - PAD * 2) / (data.length - 1))))));
	};

	return (
		<span className="rdi-spark" aria-hidden="true" data-label={label}>
			<svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} onPointerMove={onMove} onPointerLeave={() => setActive(null)}>
				<path d={path} className="rdi-spark__line" />
				<circle cx={marker.x} cy={marker.y} r={4} className="rdi-spark__dot" />
			</svg>
			{null !== active && <span className="rdi-spark__tip" dir="auto"><strong>{data[active].value}</strong> {data[active].label}</span>}
		</span>
	);
};
