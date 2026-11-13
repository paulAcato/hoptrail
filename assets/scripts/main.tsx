import { createRoot } from '@wordpress/element';
import { App } from './components/App';
import { NetworkApp } from './components/NetworkApp';
import '../styles/main.scss';

const mount = () => {
	const root = document.getElementById('hoptrail-root');
	if (!root) {
		return;
	}
	createRoot(root).render('network' === window.hoptrail?.mode ? <NetworkApp /> : <App />);
};

if ('loading' === document.readyState) {
	document.addEventListener('DOMContentLoaded', mount, { once: true });
} else {
	mount();
}
