import { __ } from '@wordpress/i18n';
import type { IssueType, Severity, TypeCounts } from '../api/types';

export interface TypeMeta {
	label: string;
	plural: string;
	description: string;
}

export const TYPE_ORDER: IssueType[] = ['loop', 'chain', 'conflict', 'noop', 'duplicate', 'redundant', 'shadow', 'broken'];

export const typeMeta = (type: IssueType): TypeMeta => ({
	loop: { label: __('Loop', 'redirect-inspector'), plural: __('Loops', 'redirect-inspector'), description: __('Redirects that never end', 'redirect-inspector') },
	chain: { label: __('Chain', 'redirect-inspector'), plural: __('Chains', 'redirect-inspector'), description: __('Two or more hops before the page loads', 'redirect-inspector') },
	conflict: { label: __('Conflict', 'redirect-inspector'), plural: __('Conflicts', 'redirect-inspector'), description: __('One URL, several different targets', 'redirect-inspector') },
	noop: { label: __('Self-redirect', 'redirect-inspector'), plural: __('Self-redirects', 'redirect-inspector'), description: __('Source and target are the same URL', 'redirect-inspector') },
	duplicate: { label: __('Duplicate', 'redirect-inspector'), plural: __('Duplicates', 'redirect-inspector'), description: __('The same redirect defined twice', 'redirect-inspector') },
	redundant: { label: __('Redundant', 'redirect-inspector'), plural: __('Redundant', 'redirect-inspector'), description: __('WordPress already handles it', 'redirect-inspector') },
	shadow: { label: __('Hides content', 'redirect-inspector'), plural: __('Hides content', 'redirect-inspector'), description: __('Redirects away from a published page', 'redirect-inspector') },
	broken: { label: __('Broken target', 'redirect-inspector'), plural: __('Broken targets', 'redirect-inspector'), description: __('Ends on a page that probably 404s', 'redirect-inspector') },
})[type];

/** Dashboard groups: what the stat tiles and filter tabs show. */
export type Group = 'all' | 'loops' | 'chains' | 'conflicts' | 'unnecessary' | 'broken';

export const GROUPS: Record<Exclude<Group, 'all'>, IssueType[]> = {
	loops: ['loop'],
	chains: ['chain'],
	conflicts: ['conflict'],
	unnecessary: ['noop', 'duplicate', 'redundant', 'shadow'],
	broken: ['broken'],
};

export const groupLabel = (group: Group): string => ({
	all: __('All issues', 'redirect-inspector'),
	loops: __('Loops', 'redirect-inspector'),
	chains: __('Chains', 'redirect-inspector'),
	conflicts: __('Conflicts', 'redirect-inspector'),
	unnecessary: __('Unnecessary', 'redirect-inspector'),
	broken: __('Broken', 'redirect-inspector'),
})[group];

export const groupCount = (group: Group, counts: TypeCounts = {}): number =>
	('all' === group ? TYPE_ORDER : GROUPS[group]).reduce((sum, type) => sum + (counts[type] ?? 0), 0);

export const severityLabel = (severity: Severity): string => ({
	critical: __('Critical', 'redirect-inspector'),
	high: __('High', 'redirect-inspector'),
	medium: __('Medium', 'redirect-inspector'),
	low: __('Low', 'redirect-inspector'),
})[severity];

/** Categorical slots, fixed order = registry order; colour follows the plugin, never its rank. Each ≥ 3:1 on white. */
const PLUGIN_SLOTS: Record<string, string> = {
	redirection: 'hsl(213deg 68% 50%)',
	yoast: 'hsl(17deg 82% 46%)',
	'rank-math': 'hsl(159deg 73% 30%)',
	'safe-redirect-manager': 'hsl(36deg 100% 32%)',
	'simple-301': 'hsl(330deg 60% 45%)',
};
const FALLBACK_SLOTS = ['hsl(120deg 100% 26%)', 'hsl(248deg 62% 58%)', 'hsl(0deg 73% 59%)'];

export const pluginColor = (slug: string, index = 0): string => PLUGIN_SLOTS[slug] ?? FALLBACK_SLOTS[index % FALLBACK_SLOTS.length];

export const healthBand = (score: number): 'good' | 'warning' | 'critical' => {
	if (score >= 80) {
		return 'good';
	}
	return score >= 50 ? 'warning' : 'critical';
};

export const healthLabel = (score: number): string => ({
	good: __('Healthy', 'redirect-inspector'),
	warning: __('Needs attention', 'redirect-inspector'),
	critical: __('Critical', 'redirect-inspector'),
})[healthBand(score)];
