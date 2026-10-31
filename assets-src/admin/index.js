/**
 * Admin app for Settings > WP Sites HQ. Initial data comes from
 * window.monksCentralAdmin (Admin/Page.php); the connection screen talks to
 * /monks-central/v1/connector, module toggles are saved to /monks-central/v1/settings.
 */
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';
import { createRoot, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Connector from './connector';
import Modules from './modules';
import './admin.css';

function App() {
	const [ settings, setSettings ] = useState(
		window.monksCentralAdmin.settings
	);
	const [ notice, setNotice ] = useState( null );

	const save = async ( changes ) => {
		setNotice( null );
		try {
			const saved = await apiFetch( {
				path: '/monks-central/v1/settings',
				method: 'POST',
				data: changes,
			} );
			setSettings( saved );
			setNotice( {
				status: 'success',
				text: __( 'Saved.', 'wp-sites-hq' ),
			} );
		} catch ( error ) {
			setNotice( { status: 'error', text: error.message } );
		}
	};

	return (
		<div className="monks-central-admin">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ window.monksCentralAdmin.connector ? (
				<Connector
					initial={ window.monksCentralAdmin.connector }
					onNotice={ setNotice }
				/>
			) : (
				<Notice status="error" isDismissible={ false }>
					{ __( 'The connector is not loaded.', 'wp-sites-hq' ) }
				</Notice>
			) }
			{ window.monksCentralAdmin.modules.length > 0 && (
				<Modules
					modules={ window.monksCentralAdmin.modules }
					enabled={ settings.modules }
					onToggle={ ( id, on ) =>
						save( { modules: { [ id ]: on } } )
					}
				/>
			) }
		</div>
	);
}

createRoot( document.getElementById( 'monks-central-admin' ) ).render(
	<App />
);
