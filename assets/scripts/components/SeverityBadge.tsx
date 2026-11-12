import { Icon, error, caution, cautionFilled, info } from '@wordpress/icons';
import type { Severity } from '../api/types';
import { severityLabel } from '../lib/meta';

/** Status colour always ships with an icon and a label. */
export const SeverityBadge = ({ severity }: { severity: Severity }) => (
	<span className={`rdi-severity rdi-severity--${severity}`}>
		<Icon icon={{ critical: error, high: cautionFilled, medium: caution, low: info }[severity]} size={16} />
		{severityLabel(severity)}
	</span>
);
