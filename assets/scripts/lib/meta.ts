import { __ } from '@wordpress/i18n';
import type { IssueType, Severity, TypeCounts } from '../api/types';

export interface TypeMeta {
	label: string;
	plural: string;
	description: string;
}

export const TYPE_ORDER: IssueType[] = ['loop', 'chain', 'conflict', 'noop', 'duplicate', 'redundant', 'shadow', 'broken'];

export const typeMeta = (type: IssueType): TypeMeta => ({
	loop: { label: __('Loop', 'hoptrail'), plural: __('Loops', 'hoptrail'), description: __('Redirects that never end', 'hoptrail') },
	chain: { label: __('Chain', 'hoptrail'), plural: __('Chains', 'hoptrail'), description: __('Two or more hops before the page loads', 'hoptrail') },
	conflict: { label: __('Conflict', 'hoptrail'), plural: __('Conflicts', 'hoptrail'), description: __('One URL, several different targets', 'hoptrail') },
	noop: { label: __('Self-redirect', 'hoptrail'), plural: __('Self-redirects', 'hoptrail'), description: __('Source and target are the same URL', 'hoptrail') },
	duplicate: { label: __('Duplicate', 'hoptrail'), plural: __('Duplicates', 'hoptrail'), description: __('The same redirect defined twice', 'hoptrail') },
	redundant: { label: __('Redundant', 'hoptrail'), plural: __('Redundant', 'hoptrail'), description: __('WordPress already handles it', 'hoptrail') },
	shadow: { label: __('Hides content', 'hoptrail'), plural: __('Hides content', 'hoptrail'), description: __('Redirects away from a published page', 'hoptrail') },
	broken: { label: __('Broken target', 'hoptrail'), plural: __('Broken targets', 'hoptrail'), description: __('Ends on a page that probably 404s', 'hoptrail') },
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
	all: __('All issues', 'hoptrail'),
	loops: __('Loops', 'hoptrail'),
	chains: __('Chains', 'hoptrail'),
	conflicts: __('Conflicts', 'hoptrail'),
	unnecessary: __('Unnecessary', 'hoptrail'),
	broken: __('Broken', 'hoptrail'),
})[group];

export const groupCount = (group: Group, counts: TypeCounts = {}): number =>
	('all' === group ? TYPE_ORDER : GROUPS[group]).reduce((sum, type) => sum + (counts[type] ?? 0), 0);

export const severityLabel = (severity: Severity): string => ({
	critical: __('Critical', 'hoptrail'),
	high: __('High', 'hoptrail'),
	medium: __('Medium', 'hoptrail'),
	low: __('Low', 'hoptrail'),
})[severity];

/** Categorical slots, fixed order = registry order; colour follows the plugin, never its rank. Each ≥ 3:1 on white. */
const PLUGIN_SLOTS: Record<string, string> = {
	redirection: 'hsl(213deg 68% 50%)',
	yoast: 'hsl(17deg 82% 46%)',
	'rank-math': 'hsl(159deg 73% 30%)',
	'safe-redirect-manager': 'hsl(36deg 100% 32%)',
	'simple-301': 'hsl(330deg 60% 45%)',
	'301-redirects': 'hsl(248deg 55% 52%)',
	seopress: 'hsl(186deg 80% 27%)',
	'slim-seo': 'hsl(95deg 55% 30%)',
	smartcrawl: 'hsl(280deg 45% 46%)',
	'the-seo-framework': 'hsl(205deg 18% 40%)',
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
	good: __('Healthy', 'hoptrail'),
	warning: __('Needs attention', 'hoptrail'),
	critical: __('Critical', 'hoptrail'),
})[healthBand(score)];
