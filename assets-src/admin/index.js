/**
 * Admin app for Settings > WP Plugin Starter. Initial data comes from
 * window.wpsAdmin (Admin/Page.php); changes are saved to /wps/v1/settings.
 */
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';
import { createRoot, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Modules from './modules';
import Settings from './settings';
import './admin.css';

function App() {
	const [ settings, setSettings ] = useState( window.wpsAdmin.settings );
	const [ notice, setNotice ] = useState( null );

	const save = async ( changes ) => {
		setNotice( null );
		try {
			const saved = await apiFetch( {
				path: '/wps/v1/settings',
				method: 'POST',
				data: changes,
			} );
			setSettings( saved );
			setNotice( {
				status: 'success',
				text: __( 'Saved.', 'wp-plugin-starter' ),
			} );
		} catch ( error ) {
			setNotice( { status: 'error', text: error.message } );
		}
	};

	return (
		<div className="wps-admin">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<Modules
				modules={ window.wpsAdmin.modules }
				enabled={ settings.modules }
				onToggle={ ( id, on ) => save( { modules: { [ id ]: on } } ) }
			/>
			<Settings settings={ settings } onSave={ save } />
		</div>
	);
}

createRoot( document.getElementById( 'wps-admin' ) ).render( <App /> );
