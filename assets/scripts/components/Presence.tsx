import { __, _n, sprintf } from '@wordpress/i18n';
import type { PresentUser } from '../api/types';
import { safeHref } from '../lib/format';

/** "Anna is also here": avatars of the other administrators with the dashboard open. */
export const PresenceBadge = ({ others }: { others: PresentUser[] }) => {
	if (!others.length) {
		return null;
	}

	const names = others.map((user) => user.name);
	const text = 1 === names.length
		/* translators: %s: name of an administrator. */
		? sprintf(__('%s is also here', 'hoptrail'), names[0])
		/* translators: 1: names, 2: number of others. */
		: sprintf(_n('%1$s are also here', '%1$s are also here', names.length, 'hoptrail'), names.join(', '));

	return (
		<p className="rdi-presence">
			<span className="rdi-presence__avatars" aria-hidden="true">
				{others.slice(0, 3).map((user) => {
					// Avatar URLs are filterable: only http(s), like every other data-derived URL.
					const src = safeHref(user.avatar, window.location.href);
					return src ? <img key={user.id} src={src} alt="" width={24} height={24} /> : null;
				})}
			</span>
			<span>{text}</span>
		</p>
	);
};
