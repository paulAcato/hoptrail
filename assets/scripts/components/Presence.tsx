import { __, _n, sprintf } from '@wordpress/i18n';
import type { PresentUser } from '../api/types';

/** "Anna is also here": avatars of the other administrators with the dashboard open. */
export const PresenceBadge = ({ others }: { others: PresentUser[] }) => {
	if (!others.length) {
		return null;
	}

	const names = others.map((user) => user.name);
	const text = 1 === names.length
		/* translators: %s: name of an administrator. */
		? sprintf(__('%s is also here', 'redirect-inspector'), names[0])
		/* translators: 1: names, 2: number of others. */
		: sprintf(_n('%1$s are also here', '%1$s are also here', names.length, 'redirect-inspector'), names.join(', '));

	return (
		<p className="rdi-presence">
			<span className="rdi-presence__avatars" aria-hidden="true">
				{others.slice(0, 3).map((user) => <img key={user.id} src={user.avatar} alt="" width={24} height={24} />)}
			</span>
			<span>{text}</span>
		</p>
	);
};
