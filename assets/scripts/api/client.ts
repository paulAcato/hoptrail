import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Every request carries an AbortSignal. Identical GETs in flight are shared (deduplicated); the
 * shared request is only aborted when every consumer has aborted. Successful GETs are cached
 * until invalidated by a mutation, so re-renders and remounts never refetch.
 */

interface InFlight {
	promise: Promise<unknown>;
	controller: AbortController;
	users: number;
}

const inflight = new Map<string, InFlight>();
const cache = new Map<string, unknown>();
const listeners = new Set<() => void>();
const CACHE_LIMIT = 60;

export const NAMESPACE = '/hoptrail/v1';

const abortError = () => new DOMException('Aborted', 'AbortError');

export const isAbort = (error: unknown): boolean => error instanceof DOMException && 'AbortError' === error.name;

export const errorMessage = (error: unknown): string => {
	if (error && 'object' === typeof error && 'message' in error && 'string' === typeof error.message && error.message) {
		return error.message;
	}
	return __('Something went wrong. Please try again.', 'hoptrail');
};

export const getCached = <T,>(path: string): T | undefined => cache.get(path) as T | undefined;

export const hasCached = (path: string): boolean => cache.has(path);

const remember = (path: string, value: unknown) => {
	cache.delete(path);
	cache.set(path, value);
	if (cache.size > CACHE_LIMIT) {
		cache.delete(cache.keys().next().value as string);
	}
};

/** Drop cached GETs (all, or those starting with a prefix) and tell mounted resources to reload. */
export const invalidate = (prefix = ''): void => {
	for (const key of [...cache.keys()]) {
		if (key.startsWith(NAMESPACE + prefix)) {
			cache.delete(key);
		}
	}
	listeners.forEach((listener) => listener());
};

export const onInvalidate = (listener: () => void): (() => void) => {
	listeners.add(listener);
	return () => listeners.delete(listener);
};

export const get = <T,>(path: string, signal: AbortSignal): Promise<T> => {
	let entry = inflight.get(path);

	if (!entry) {
		const controller = new AbortController();
		const created: InFlight = {
			controller,
			users: 0,
			promise: apiFetch<T>({ path, signal: controller.signal }).then((data) => {
				remember(path, data);
				return data;
			}).finally(() => {
				if (inflight.get(path) === created) {
					inflight.delete(path);
				}
			}),
		};
		entry = created;
		inflight.set(path, entry);
	}

	const shared = entry;
	shared.users += 1;

	return new Promise<T>((resolve, reject) => {
		const release = () => {
			shared.users -= 1;
			if (shared.users <= 0) {
				shared.controller.abort();
				if (inflight.get(path) === shared) {
					inflight.delete(path);
				}
			}
			reject(abortError());
		};

		if (signal.aborted) {
			release();
			return;
		}

		signal.addEventListener('abort', release, { once: true });

		shared.promise.then(
			(data) => {
				signal.removeEventListener('abort', release);
				resolve(data as T);
			},
			(error: unknown) => {
				signal.removeEventListener('abort', release);
				reject(error);
			}
		);
	});
};

export const send = <T,>(path: string, method: 'POST' | 'PUT' | 'DELETE', signal: AbortSignal, data?: Record<string, unknown>): Promise<T> =>
	apiFetch<T>({ path, method, data, signal });

/** Build a namespaced path with a stable (sorted) query string, so cache keys are canonical. */
export const route = (endpoint: string, query: Record<string, string | number | string[] | undefined> = {}): string => {
	const params = new URLSearchParams();
	Object.keys(query).sort().forEach((key) => {
		const value = query[key];
		if (undefined === value || '' === value) {
			return;
		}
		if (Array.isArray(value)) {
			value.forEach((item) => params.append(`${key}[]`, item));
			return;
		}
		params.set(key, String(value));
	});
	const search = params.toString();
	return `${NAMESPACE}${endpoint}${search ? `?${search}` : ''}`;
};
