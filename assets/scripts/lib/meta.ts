import { __, _x } from '@wordpress/i18n';
import type { IssueType, Severity, TypeCounts } from '../api/types';

export interface TypeMeta {
	label: string;
	plural: string;
	description: string;
}

export const TYPE_ORDER: IssueType[] = ['loop', 'chain', 'conflict', 'noop', 'duplicate', 'redundant', 'shadow', 'broken'];

export const typeMeta = (type: IssueType): TypeMeta => ({
	loop: { label: _x('Loop', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Loops', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('Redirects that never end', 'onehop-redirect-checker') },
	chain: { label: _x('Chain', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Chains', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('Two or more hops before the page loads', 'onehop-redirect-checker') },
	conflict: { label: _x('Conflict', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Conflicts', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('One URL, several different targets', 'onehop-redirect-checker') },
	noop: { label: _x('Self-redirect', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Self-redirects', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('Source and target are the same URL', 'onehop-redirect-checker') },
	duplicate: { label: _x('Duplicate', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Duplicates', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('The same redirect defined twice', 'onehop-redirect-checker') },
	redundant: { label: _x('Redundant', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Redundant', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('WordPress already handles it', 'onehop-redirect-checker') },
	shadow: { label: _x('Hides content', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Hides content', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('Redirects away from a published page', 'onehop-redirect-checker') },
	broken: { label: _x('Broken target', 'issue type, one issue', 'onehop-redirect-checker'), plural: _x('Broken targets', 'issue type, heading for several issues', 'onehop-redirect-checker'), description: __('Ends on a page that probably 404s', 'onehop-redirect-checker') },
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
	all: __('All issues', 'onehop-redirect-checker'),
	loops: __('Loops', 'onehop-redirect-checker'),
	chains: __('Chains', 'onehop-redirect-checker'),
	conflicts: __('Conflicts', 'onehop-redirect-checker'),
	unnecessary: _x('Unnecessary', 'issue group', 'onehop-redirect-checker'),
	broken: _x('Broken', 'issue group', 'onehop-redirect-checker'),
})[group];

export const groupCount = (group: Group, counts: TypeCounts = {}): number =>
	('all' === group ? TYPE_ORDER : GROUPS[group]).reduce((sum, type) => sum + (counts[type] ?? 0), 0);

export const severityLabel = (severity: Severity): string => ({
	critical: _x('Critical', 'issue severity', 'onehop-redirect-checker'),
	high: _x('High', 'issue severity', 'onehop-redirect-checker'),
	medium: _x('Medium', 'issue severity', 'onehop-redirect-checker'),
	low: _x('Low', 'issue severity', 'onehop-redirect-checker'),
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
	good: _x('Healthy', 'health status of the redirects', 'onehop-redirect-checker'),
	warning: _x('Needs attention', 'health status of the redirects', 'onehop-redirect-checker'),
	critical: _x('Critical', 'health status of the redirects', 'onehop-redirect-checker'),
})[healthBand(score)];
