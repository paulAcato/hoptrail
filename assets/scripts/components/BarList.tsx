export interface Bar {
	key: string;
	label: string;
	hint?: string;
	value: number;
	onSelect?: () => void;
}

/** Count per category: one hue, rounded bars, the number in text. */
export const BarList = ({ bars, caption }: { bars: Bar[]; caption: string }) => {
	const max = Math.max(1, ...bars.map((bar) => bar.value));

	return (
		<table className="rdi-bars">
			<caption className="screen-reader-text">{caption}</caption>
			<tbody>
				{bars.map((bar) => (
					<tr key={bar.key} className={bar.value ? '' : 'is-zero'}>
						<th scope="row">
							{bar.onSelect && bar.value
								? <button type="button" className="rdi-bars__label" onClick={bar.onSelect}>{bar.label}</button>
								: <span className="rdi-bars__label">{bar.label}</span>}
							{bar.hint && <small>{bar.hint}</small>}
						</th>
						<td className="rdi-bars__track" aria-hidden="true">
							<span style={{ inlineSize: `${bar.value ? Math.max(2, (bar.value / max) * 100) : 0}%` }} />
						</td>
						<td className="rdi-bars__value">{bar.value}</td>
					</tr>
				))}
			</tbody>
		</table>
	);
};
