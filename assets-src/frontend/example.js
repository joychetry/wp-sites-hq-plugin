/**
 * Example module store: loads related posts from the REST route on click.
 * Server state (endpoint, texts) comes from wp_interactivity_state() in Frontend.php.
 */
import { store, getContext } from '@wordpress/interactivity';
import './example.css';

const { state } = store( 'monks-central/example', {
	actions: {
		*load() {
			const context = getContext();
			context.loading = true;
			context.message = '';
			try {
				const response = yield fetch(
					`${ state.endpoint }${ context.postId }`
				);
				if ( ! response.ok ) {
					throw new Error( response.statusText );
				}
				const posts = yield response.json();
				context.posts = posts;
				context.hasPosts = posts.length > 0;
				context.message = posts.length ? '' : state.emptyText;
				context.loaded = true;
			} catch {
				context.message = state.errorText;
			} finally {
				context.loading = false;
			}
		},
	},
} );
