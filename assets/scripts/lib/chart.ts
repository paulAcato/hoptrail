import { useEffect, useRef, useState } from '@wordpress/element';
import type { Severity } from '../api/types';

/** Ordinal ramp per colour scheme (values in styles/base/_theme.scss). Always paired with a label. */
export const SEVERITY_COLORS: Record<Severity, string> = {
	critical: 'var(--rdi-sev-critical)',
	high: 'var(--rdi-sev-high)',
	medium: 'var(--rdi-sev-medium)',
	low: 'var(--rdi-sev-low)',
};

export const SEVERITIES: Severity[] = ['critical', 'high', 'medium', 'low'];

/** Width of an element, kept up to date. */
export const useWidth = <T extends HTMLElement>() => {
	const ref = useRef<T>(null);
	const [width, setWidth] = useState(0);

	useEffect(() => {
		const element = ref.current;
		if (!element || !('ResizeObserver' in window)) {
			return undefined;
		}
		const observer = new ResizeObserver(([entry]) => setWidth(Math.round(entry.contentRect.width)));
		observer.observe(element);
		return () => observer.disconnect();
	}, []);

	return [ref, width] as const;
};
