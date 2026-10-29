/**
 * Settings form built with DataForm. Field ids match Settings::fields() in PHP,
 * which also sanitizes and clamps every value on save.
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DataForm, useFormValidity } from '@wordpress/dataviews';

const fields = [
	{
		id: 'example_label',
		label: __( 'Related posts button text', 'monks-central' ),
		type: 'text',
		isValid: { required: true, maxLength: 60 },
	},
	{
		id: 'example_count',
		label: __( 'Related posts to show', 'monks-central' ),
		type: 'integer',
		isValid: { required: true, min: 1, max: 10 },
	},
];

const form = {
	layout: { type: 'regular' },
	fields: [ 'example_label', 'example_count' ],
};

export default function Settings( { settings, onSave } ) {
	const [ edits, setEdits ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const data = { ...settings, ...edits };
	const { validity, isValid } = useFormValidity( data, fields, form );

	const submit = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		await onSave( edits );
		setEdits( {} );
		setIsSaving( false );
	};

	return (
		<Card className="monks-central-admin__card">
			<CardHeader>
				<h2>{ __( 'Settings', 'monks-central' ) }</h2>
			</CardHeader>
			<CardBody>
				<form onSubmit={ submit }>
					<DataForm
						data={ data }
						fields={ fields }
						form={ form }
						validity={ validity }
						onChange={ ( changes ) =>
							setEdits( ( current ) => ( {
								...current,
								...changes,
							} ) )
						}
					/>
					<Button
						__next40pxDefaultSize
						className="monks-central-admin__save"
						variant="primary"
						type="submit"
						isBusy={ isSaving }
						disabled={
							isSaving ||
							! isValid ||
							! Object.keys( edits ).length
						}
						accessibleWhenDisabled
					>
						{ __( 'Save settings', 'monks-central' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
