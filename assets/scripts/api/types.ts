export type IssueType = 'loop' | 'chain' | 'conflict' | 'noop' | 'duplicate' | 'redundant' | 'shadow' | 'broken';
export type Severity = 'critical' | 'high' | 'medium' | 'low';
export type ScanStatus = 'running' | 'done' | 'partial' | 'failed';

export type TypeCounts = Partial<Record<IssueType, number>>;

export interface SourceStats {
	label: string;
	available: boolean;
	rules: number;
	skipped: number;
	issues?: number;
}

export interface Scan {
	id: number;
	status: ScanStatus;
	trigger: 'manual' | 'cli' | 'cron' | 'ability';
	user: string;
	started_at: string;
	finished_at: string | null;
	duration_ms: number;
	rule_count: number;
	issue_count: number;
	score: number;
	totals: {
		types?: TypeCounts;
		severities?: Partial<Record<Severity, number>>;
		sources?: Record<string, SourceStats>;
	};
	message: string;
}

export interface TrendPoint {
	id: number;
	finished_at: string | null;
	score: number;
	issue_count: number;
	types: TypeCounts;
	severities: Partial<Record<Severity, number>>;
}

export interface SourceSummary {
	slug: string;
	label: string;
	available: boolean;
	rules: number;
}

export interface Schedule {
	enabled: boolean;
	frequency: 'daily' | 'weekly' | 'monthly';
	/** Day of the month 1–28, or 0 for the last day (monthly). */
	month_day: number;
	/** 0 = Sunday … 6 = Saturday. */
	days: number[];
	/** 0–23, site time. */
	hours: number[];
}

export interface Settings {
	retention_mode: 'days' | 'count';
	retention_count: number;
	retention_days: number;
	schedule: Schedule;
}

export interface Retention {
	text: string;
	next_cleanup: string | null;
}

export interface Summary {
	latest: Scan | null;
	trend: TrendPoint[];
	sources: SourceSummary[];
	running: boolean;
	settings: Settings;
	retention: Retention;
	next_scan: string | null;
	leftovers: Leftover[];
	targets: { slug: string; label: string }[];
}

export interface Leftover {
	slug: string;
	label: string;
	rules: number;
	/** Number of own tables (Redirection, Rank Math); 0 for option/post storage. */
	tables: number;
}

export interface LeftoverRule {
	id: string;
	from: string;
	to: string;
	code: number;
	match: string;
	status: 'copy' | 'expand' | 'exists' | 'unsupported' | 'none';
	/** For 'expand': the exact rules it becomes (first 50), and how many. */
	expanded: Array<{ from: string; to: string }>;
	expands: number;
}

export interface MigrationResult {
	created: number;
	existing: number;
	unsupported: number;
	failed: number;
	expanded: number;
	dry_run: boolean;
}

export interface HopRule {
	source: string;
	label: string;
	id: string;
	from: string;
	to: string;
	match: string;
	edit: string;
}

export interface Hop {
	from: string;
	to: string;
	code: number;
	rule: HopRule;
}

export interface LiveHop {
	url: string;
	status: number | null;
	note: string;
}

export interface LiveResult {
	url: string;
	verdict: 'ok' | 'chain' | 'loop' | 'broken' | 'external' | 'error';
	hops: LiveHop[];
	checked_at: string;
}

export interface FixRule {
	source: string;
	label: string;
	id: string;
	from: string;
	to: string;
}

export interface Fix {
	action: 'retarget' | 'disable';
	to?: string;
	rules: FixRule[];
}

export interface Fixed {
	action: Fix['action'];
	changes: { source: string; id: string; from: string; result: 'retargeted' | 'disabled' | 'removed' }[];
	by: string;
	at: string;
}

export interface Issue {
	id: number;
	scan_id: number;
	type: IssueType;
	severity: Severity;
	source_url: string;
	target_url: string;
	plugins: string[];
	hops: Hop[];
	suggestion: string;
	live: LiveResult | null;
	fix: Fix | null;
	fixed: Fixed | null;
	is_new: boolean;
}

export interface Paged<T> {
	items: T[];
	total: number;
}

export interface NetworkSite {
	id: number;
	name: string;
	url: string;
	dashboard: string;
	active: boolean;
	latest: null | {
		finished_at: string | null;
		score: number;
		rule_count: number;
		issue_count: number;
		types: TypeCounts;
		status: ScanStatus;
	};
}

export interface BootData {
	mode: 'site' | 'network';
	version: string;
	retention: string;
	siteUrl: string;
	startOfWeek: number;
	timezone: string;
	wapuu: string;
	stateVersion: number;
	userId: number;
	tour: { version: string; seen: string; pending: boolean };
}

declare global {
	interface Window {
		redirectInspector?: BootData;
	}
}

export interface PresentUser {
	id: number;
	name: string;
	avatar: string;
}
