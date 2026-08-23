import { createElement, forwardRef } from '@wordpress/element';

export const BaseControl = ( { children, help, id, label } ) =>
	createElement(
		'div',
		null,
		label && createElement( 'label', { htmlFor: id }, label ),
		children,
		help && createElement( 'span', null, help )
	);

export const Button = forwardRef(
	(
		{
			children,
			icon,
			isBusy,
			isDestructive,
			label,
			showTooltip,
			variant,
			__experimentalIsFocusable,
			...buttonProps
		},
		ref
	) =>
		createElement(
			'button',
			{
				...buttonProps,
				ref,
				type: buttonProps.type || 'button',
				'aria-label': label,
				'aria-busy': isBusy || undefined,
			},
			children
		)
);

export const TextControl = ( {
	autoComplete,
	help,
	id,
	label,
	max,
	min,
	name,
	onChange,
	placeholder,
	type = 'text',
	value,
} ) =>
	createElement(
		'div',
		null,
		createElement( 'label', { htmlFor: id }, label ),
		createElement( 'input', {
			autoComplete,
			id,
			max,
			min,
			name,
			onChange: ( event ) => onChange( event.target.value ),
			placeholder,
			type,
			value,
		} ),
		help && createElement( 'span', null, help )
	);

export const SelectControl = ( {
	help,
	id,
	label,
	name,
	onChange,
	options = [],
	value,
} ) =>
	createElement(
		'div',
		null,
		createElement( 'label', { htmlFor: id }, label ),
		createElement(
			'select',
			{
				id,
				name,
				onChange: ( event ) => onChange( event.target.value ),
				value,
			},
			options.map( ( option ) =>
				createElement(
					'option',
					{ key: option.value, value: option.value },
					option.label
				)
			)
		),
		help && createElement( 'span', null, help )
	);

export const CheckboxControl = ( { checked, id, label, name, onChange } ) =>
	createElement(
		'label',
		{ htmlFor: id },
		createElement( 'input', {
			checked,
			id,
			name,
			onChange: ( event ) => onChange( event.target.checked ),
			type: 'checkbox',
			value: '1',
		} ),
		label
	);

export const RadioControl = ( {
	id,
	label,
	name,
	onChange,
	options = [],
	selected,
} ) =>
	createElement(
		'fieldset',
		null,
		createElement( 'legend', null, label ),
		options.map( ( option, index ) => {
			const optionId = `${ id }-${ index }`;
			return createElement(
				'label',
				{ htmlFor: optionId, key: option.value },
				createElement( 'input', {
					checked: selected === option.value,
					id: optionId,
					name,
					onChange: () => onChange( option.value ),
					type: 'radio',
					value: option.value,
				} ),
				option.label
			);
		} )
	);

export const Modal = ( { children, className, onKeyDown, title } ) =>
	createElement(
		'div',
		{
			'aria-label': title,
			className,
			onKeyDown,
			role: 'dialog',
		},
		children
	);
