import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { errorMessage, get, getCached, hasCached, isAbort, onInvalidate, send } from './client';

interface ResourceState<T> {
	data: T | undefined;
	error: string | null;
	loading: boolean;
}

/**
 * GET a resource. Aborts on unmount and when the path changes (superseded request); served from
 * cache when already loaded; reloads when a mutation invalidates the cache. `null` = don't fetch.
 */
export const useResource = <T,>(path: string | null) => {
	const [state, setState] = useState<ResourceState<T>>(() => ({
		data: path ? getCached<T>(path) : undefined,
		error: null,
		loading: Boolean(path) && !hasCached(path as string),
	}));
	const [version, setVersion] = useState(0);

	useEffect(() => onInvalidate(() => setVersion((value) => value + 1)), []);

	useEffect(() => {
		if (!path) {
			return undefined;
		}

		if (hasCached(path)) {
			setState({ data: getCached<T>(path), error: null, loading: false });
			return undefined;
		}

		const controller = new AbortController();
		setState((previous) => ({ ...previous, loading: true, error: null }));

		(async () => {
			try {
				const data = await get<T>(path, controller.signal);
				setState({ data, error: null, loading: false });
			} catch (error) {
				if (controller.signal.aborted || isAbort(error)) {
					return;
				}
				setState((previous) => ({ ...previous, loading: false, error: errorMessage(error) }));
			}
		})();

		return () => controller.abort();
	}, [path, version]);

	return state;
};

/**
 * A mutation (POST/PUT/DELETE) with busy state. A new call aborts the previous one; unmount aborts.
 */
export const useMutation = <T,>() => {
	const controllerRef = useRef<AbortController | null>(null);
	const [busy, setBusy] = useState(false);

	useEffect(() => () => controllerRef.current?.abort(), []);

	const run = useCallback(async (path: string, method: 'POST' | 'PUT' | 'DELETE', data?: Record<string, unknown>): Promise<T> => {
		controllerRef.current?.abort();
		const controller = new AbortController();
		controllerRef.current = controller;
		setBusy(true);
		try {
			return await send<T>(path, method, controller.signal, data);
		} finally {
			if (controllerRef.current === controller) {
				controllerRef.current = null;
				setBusy(false);
			}
		}
	}, []);

	return { run, busy };
};

/** Debounce a value (search input → request). */
export const useDebounced = <T,>(value: T, delay = 300): T => {
	const [debounced, setDebounced] = useState(value);

	useEffect(() => {
		const timer = window.setTimeout(() => setDebounced(value), delay);
		return () => window.clearTimeout(timer);
	}, [value, delay]);

	return debounced;
};

/** Whether the document is visible; background work pauses while hidden. */
export const usePageVisible = (): boolean => {
	const [visible, setVisible] = useState(() => !document.hidden);

	useEffect(() => {
		const update = () => setVisible(!document.hidden);
		document.addEventListener('visibilitychange', update);
		return () => document.removeEventListener('visibilitychange', update);
	}, []);

	return visible;
};

/** While `active`, Escape calls `close` (dismissible hover content, WCAG 1.4.13). */
export const useEscape = (active: boolean, close: () => void): void => {
	useEffect(() => {
		if (!active) {
			return undefined;
		}
		const onKey = (event: KeyboardEvent) => {
			if ('Escape' === event.key) {
				close();
			}
		};
		document.addEventListener('keydown', onKey);
		return () => document.removeEventListener('keydown', onKey);
	}, [active, close]);
};
