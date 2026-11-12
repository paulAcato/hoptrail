export const EmptyState = ({ title, text, children }: { title: string; text: string; children?: React.ReactNode }) => (
	<div className="rdi-empty">
		<h3>{title}</h3>
		<p>{text}</p>
		{children}
	</div>
);
