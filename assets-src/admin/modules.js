/**
 * Modules list: a DataViews table with an enable toggle per module.
 */
import {
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

export default function Modules( { modules, enabled, onToggle } ) {
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 20,
		titleField: 'label',
		descriptionField: 'description',
		fields: [ 'enabled' ],
		layout: {},
		filters: [],
		sort: { field: 'label', direction: 'asc' },
	} );

	const fields = useMemo(
		() => [
			{
				id: 'label',
				label: __( 'Module', 'monks-central' ),
				enableHiding: false,
				enableGlobalSearch: true,
			},
			{
				id: 'description',
				label: __( 'Description', 'monks-central' ),
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'enabled',
				label: __( 'Enabled', 'monks-central' ),
				enableSorting: false,
				enableHiding: false,
				getValue: ( { item } ) => !! enabled[ item.id ],
				render: ( { item } ) => (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ item.label }
						hideLabelFromVision
						checked={ !! enabled[ item.id ] }
						onChange={ ( on ) => onToggle( item.id, on ) }
					/>
				),
			},
		],
		[ enabled, onToggle ]
	);

	const { data, paginationInfo } = filterSortAndPaginate(
		modules,
		view,
		fields
	);

	return (
		<Card className="monks-central-admin__card">
			<CardHeader>
				<h2>{ __( 'Modules', 'monks-central' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'A disabled module adds no hooks and loads none of its code. Changes apply on the next page load.',
						'monks-central'
					) }
				</p>
				<DataViews
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
				/>
			</CardBody>
		</Card>
	);
}
