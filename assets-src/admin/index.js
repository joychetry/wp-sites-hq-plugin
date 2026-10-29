/**
 * Admin app for Settings > Monks Central. Initial data comes from
 * window.monksCentralAdmin (Admin/Page.php); changes are saved to /monks-central/v1/settings.
 */
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';
import { createRoot, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
				text: __( 'Saved.', 'monks-central' ),
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
			<Modules
				modules={ window.monksCentralAdmin.modules }
				enabled={ settings.modules }
				onToggle={ ( id, on ) => save( { modules: { [ id ]: on } } ) }
			/>
		</div>
	);
}

createRoot( document.getElementById( 'monks-central-admin' ) ).render(
	<App />
);
