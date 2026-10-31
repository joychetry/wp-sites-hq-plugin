/**
 * The connection screen: the on/off switch, connected apps, approval requests
 * and the activity log. Data and actions come from /monks-central/v1/connector,
 * which hands them through from the shared connector library.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Flex,
	Notice,
	ToggleControl,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const API = '/monks-central/v1/connector';

function ConfirmRevoke( { items, closeModal, onRevoke } ) {
	const [ busy, setBusy ] = useState( false );
	return (
		<>
			<p>
				{ sprintf(
					/* translators: %s: app name */
					__(
						'%s is signed out at once and has to connect again to reach this site.',
						'wp-sites-hq'
					),
					items[ 0 ].name
				) }
			</p>
			<Flex justify="flex-end">
				<Button variant="tertiary" onClick={ closeModal }>
					{ __( 'Cancel', 'wp-sites-hq' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ busy }
					onClick={ async () => {
						setBusy( true );
						await onRevoke( items[ 0 ] );
						closeModal();
					} }
				>
					{ __( 'Revoke', 'wp-sites-hq' ) }
				</Button>
			</Flex>
		</>
	);
}

function Connections( { rows, onRevoke, onOperator } ) {
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 20,
		titleField: 'name',
		fields: [ 'user', 'scope_label', 'approvals', 'last_used' ],
		layout: {},
		filters: [],
	} );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'App', 'wp-sites-hq' ),
				enableHiding: false,
			},
			{
				id: 'user',
				label: __( 'Signs in as', 'wp-sites-hq' ),
				render: ( { item } ) => `${ item.user } (${ item.role })`,
			},
			{
				id: 'scope_label',
				label: __( 'Access', 'wp-sites-hq' ),
			},
			{
				id: 'approvals',
				label: __( 'Approvals', 'wp-sites-hq' ),
				enableSorting: false,
				render: ( { item } ) => {
					if ( item.operator !== null ) {
						return (
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Operator: updates, user changes and deletes run without asking',
									'wp-sites-hq'
								) }
								checked={ item.operator }
								onChange={ ( skip ) =>
									onOperator( item, skip )
								}
							/>
						);
					}
					return item.trusted
						? __( 'Skips approvals', 'wp-sites-hq' )
						: __( 'Asks before changes', 'wp-sites-hq' );
				},
			},
			{
				id: 'last_used',
				label: __( 'Last used', 'wp-sites-hq' ),
				enableSorting: false,
			},
		],
		[ onOperator ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'revoke',
				label: __( 'Revoke', 'wp-sites-hq' ),
				isPrimary: true,
				modalHeader: __( 'Revoke this connection?', 'wp-sites-hq' ),
				RenderModal: ( props ) => (
					<ConfirmRevoke { ...props } onRevoke={ onRevoke } />
				),
			},
		],
		[ onRevoke ]
	);

	return (
		<Card className="monks-central-admin__card">
			<CardHeader>
				<h2>{ __( 'Connected apps', 'wp-sites-hq' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Revoking signs the app out at once. Deactivating the last plugin that runs the connection revokes every connection.',
						'wp-sites-hq'
					) }
				</p>
				<DataViews
					data={ rows }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					getItemId={ ( item ) => item.uuid }
					paginationInfo={ {
						totalItems: rows.length,
						totalPages: 1,
					} }
					defaultLayouts={ { table: {} } }
					empty={
						<p>
							{ __(
								'No apps are connected yet.',
								'wp-sites-hq'
							) }
						</p>
					}
				>
					<DataViews.Layout />
				</DataViews>
			</CardBody>
		</Card>
	);
}

function Pending( { rows } ) {
	if ( ! rows.length ) {
		return null;
	}
	return (
		<Card className="monks-central-admin__card">
			<CardHeader>
				<h2>{ __( 'Waiting for your approval', 'wp-sites-hq' ) }</h2>
			</CardHeader>
			<CardBody>
				<ul className="monks-central-admin__pending">
					{ rows.map( ( row ) => (
						<li key={ row.id }>
							<a href={ row.approve_url }>{ row.tool }</a>{ ' ' }
							{ sprintf(
								/* translators: %s: app name */
								__( 'from %s', 'wp-sites-hq' ),
								row.client
							) }
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}

function Activity( { version, onNotice } ) {
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 20,
		titleField: 'tool',
		fields: [ 'when', 'client_label', 'user', 'status', 'result_summary' ],
		layout: {},
		filters: [],
	} );
	const [ log, setLog ] = useState( { rows: [], total: 0 } );
	const [ loading, setLoading ] = useState( true );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			setLog(
				await apiFetch( {
					path: `${ API }/activity?page=${ view.page }&per_page=${ view.perPage }`,
				} )
			);
		} catch ( error ) {
			onNotice( { status: 'error', text: error.message } );
		}
		setLoading( false );
	}, [ view.page, view.perPage, onNotice ] );

	useEffect( () => {
		load();
	}, [ load, version ] );

	const fields = useMemo(
		() => [
			{
				id: 'tool',
				label: __( 'Tool', 'wp-sites-hq' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item } ) => <code>{ item.tool }</code>,
			},
			{
				id: 'when',
				label: __( 'When', 'wp-sites-hq' ),
				enableSorting: false,
			},
			{
				id: 'client_label',
				label: __( 'App', 'wp-sites-hq' ),
				enableSorting: false,
			},
			{
				id: 'user',
				label: __( 'User', 'wp-sites-hq' ),
				enableSorting: false,
			},
			{
				id: 'status',
				label: __( 'Result', 'wp-sites-hq' ),
				enableSorting: false,
				render: ( { item } ) =>
					item.approve_url ? (
						<a href={ item.approve_url }>{ item.status }</a>
					) : (
						item.status
					),
			},
			{
				id: 'result_summary',
				label: __( 'Details', 'wp-sites-hq' ),
				enableSorting: false,
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'undo',
				label: __( 'Undo', 'wp-sites-hq' ),
				isPrimary: true,
				isEligible: ( item ) => item.can_undo,
				callback: async ( [ item ] ) => {
					try {
						await apiFetch( {
							path: `${ API }/activity/${ item.id }/undo`,
							method: 'POST',
						} );
						onNotice( {
							status: 'success',
							text: __( 'Change undone.', 'wp-sites-hq' ),
						} );
					} catch ( error ) {
						onNotice( { status: 'error', text: error.message } );
					}
					load();
				},
			},
		],
		[ load, onNotice ]
	);

	return (
		<Card className="monks-central-admin__card">
			<CardHeader>
				<h2>{ __( 'Activity', 'wp-sites-hq' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Every change a connected app made or asked for, newest first. Reads are not listed.',
						'wp-sites-hq'
					) }
				</p>
				<DataViews
					data={ log.rows }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					isLoading={ loading }
					getItemId={ ( item ) => String( item.id ) }
					paginationInfo={ {
						totalItems: log.total,
						totalPages: Math.max(
							1,
							Math.ceil( log.total / view.perPage )
						),
					} }
					defaultLayouts={ { table: {} } }
					empty={ <p>{ __( 'Nothing yet.', 'wp-sites-hq' ) }</p> }
				>
					<DataViews.Layout />
					<DataViews.Pagination />
				</DataViews>
			</CardBody>
		</Card>
	);
}

function Status( { state, runsHere, onToggle } ) {
	if ( state.legacy ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Classic Monks connects this site to WP Sites HQ for now. Manage the connection under Classic Monks > WP Sites HQ.',
					'wp-sites-hq'
				) }
			</Notice>
		);
	}
	if ( ! runsHere ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ sprintf(
					/* translators: %s: plugin name */
					__(
						'%s runs the connection on this site, so its screen manages it.',
						'wp-sites-hq'
					),
					state.host
				) }{ ' ' }
				<a href={ state.page_url }>
					{ __( 'Open it', 'wp-sites-hq' ) }
				</a>
			</Notice>
		);
	}
	return (
		<Card className="monks-central-admin__card">
			<CardHeader>
				<h2>{ __( 'Connect to WP Sites HQ', 'wp-sites-hq' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Lets the WP Sites HQ desktop app, or another MCP client that signs in with OAuth, manage this site: updates, plugins, themes, content, media, users and comments. Each app signs in as a WordPress user who approves it, with that user’s permissions. Changes that delete or replace something wait for your approval unless you marked the app as your operator.',
						'wp-sites-hq'
					) }
				</p>
				{ state.supported ? (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Allow WP Sites HQ to connect',
							'wp-sites-hq'
						) }
						checked={ state.enabled }
						onChange={ onToggle }
					/>
				) : (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'WP Sites HQ needs WordPress 7.1 or newer. Update WordPress under Dashboard > Updates first.',
							'wp-sites-hq'
						) }
					</Notice>
				) }
				{ state.enabled && (
					<div className="monks-central-admin__address">
						<p>
							{ __(
								'Add this site in WP Sites HQ with its address:',
								'wp-sites-hq'
							) }{ ' ' }
							<code>{ state.site }</code>
						</p>
						<p className="monks-central-admin__muted">
							{ __(
								'Other MCP clients use the endpoint:',
								'wp-sites-hq'
							) }{ ' ' }
							<code>{ state.endpoint }</code>
						</p>
						{ ! state.https && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Apps can only sign in over HTTPS (or on a site with a local environment type). Turn on SSL for this site first.',
									'wp-sites-hq'
								) }
							</Notice>
						) }
					</div>
				) }
				<p className="monks-central-admin__version">
					{ sprintf(
						/* translators: %s: version number */
						__( 'Connector %s', 'wp-sites-hq' ),
						state.version
					) }{ ' ' }
					<ExternalLink href="https://github.com/joychetry/wp-sites-hq-plugin">
						{ __( 'About', 'wp-sites-hq' ) }
					</ExternalLink>
				</p>
			</CardBody>
		</Card>
	);
}

export default function Connector( { initial, onNotice } ) {
	const [ screen, setScreen ] = useState( initial );
	const [ version, setVersion ] = useState( 0 );

	const refresh = useCallback( ( data ) => {
		setScreen( data );
		setVersion( ( v ) => v + 1 );
	}, [] );

	const act = useCallback(
		async ( request, done ) => {
			try {
				refresh( await apiFetch( request ) );
				onNotice( { status: 'success', text: done } );
			} catch ( error ) {
				onNotice( { status: 'error', text: error.message } );
			}
		},
		[ refresh, onNotice ]
	);

	const onToggle = async ( enabled ) => {
		try {
			await apiFetch( { path: API, method: 'POST', data: { enabled } } );
			// The connector loads its endpoint on the next request.
			window.location.reload();
		} catch ( error ) {
			onNotice( { status: 'error', text: error.message } );
		}
	};

	const onRevoke = useCallback(
		( item ) =>
			act(
				{
					path: `${ API }/connections`,
					method: 'DELETE',
					data: { user_id: item.user_id, id: item.uuid },
				},
				__( 'Connection revoked.', 'wp-sites-hq' )
			),
		[ act ]
	);

	const onOperator = useCallback(
		( item, skip ) =>
			act(
				{
					path: `${ API }/connections/operator`,
					method: 'POST',
					data: { user_id: item.user_id, id: item.uuid, skip },
				},
				__( 'Connection saved.', 'wp-sites-hq' )
			),
		[ act ]
	);

	const { state } = screen;
	const runsHere = screen.runs_here && ! state.legacy;

	return (
		<>
			<Status
				state={ state }
				runsHere={ screen.runs_here }
				onToggle={ onToggle }
			/>
			{ runsHere && state.enabled && (
				<>
					<Connections
						rows={ screen.connections }
						onRevoke={ onRevoke }
						onOperator={ onOperator }
					/>
					<Pending rows={ screen.pending } />
					<Activity version={ version } onNotice={ onNotice } />
				</>
			) }
		</>
	);
}
