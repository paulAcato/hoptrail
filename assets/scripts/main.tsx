import { createRoot } from '@wordpress/element';
import { App } from './components/App';
import { NetworkApp } from './components/NetworkApp';
import '../styles/main.scss';

const mount = () => {
	const root = document.getElementById('onehop-redirect-checker-root');
	if (!root) {
		return;
	}
	createRoot(root).render('network' === window.onehopRedirectChecker?.mode ? <NetworkApp /> : <App />);
};

if ('loading' === document.readyState) {
	document.addEventListener('DOMContentLoaded', mount, { once: true });
} else {
	mount();
}
