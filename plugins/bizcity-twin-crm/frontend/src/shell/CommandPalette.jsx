import React, { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import {
	Inbox, BarChart3, Bot, Tag, Sparkles, Settings, Clock4, Activity, Plug,
	Briefcase, Receipt, Mail, Plus,
} from 'lucide-react';
import { setActiveTab } from '../redux/uiTabs.js';
import { setCommandOpen } from '../redux/uiPrefs.js';
import {
	Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem, CommandSeparator,
} from '../components/ui/command.jsx';

const NAV_ITEMS = [
	{ id: 'inbox',      label: 'Inbox',         icon: Inbox },
	{ id: 'sales',      label: 'Sales pipeline', icon: Briefcase },
	{ id: 'invoices',   label: 'Invoices',      icon: Receipt },
	{ id: 'email',      label: 'Email & CF7',  icon: Mail },
	{ id: 'reports',    label: 'Reports',       icon: BarChart3 },
	{ id: 'automation', label: 'Automation',    icon: Bot },
	{ id: 'labels',     label: 'Labels',        icon: Tag },
	{ id: 'macros',     label: 'Macros',        icon: Sparkles },
	{ id: 'attrs',      label: 'Custom Attributes', icon: Settings },
	{ id: 'sla',        label: 'SLA & Working Hours', icon: Clock4 },
	{ id: 'audit',      label: 'Audit',         icon: Activity },
	{ id: 'channels',   label: 'Channels',      icon: Plug },
];

const ACTIONS = [
	{ id: 'new-lead',     label: 'Tạo Lead mới',         tab: 'sales' },
	{ id: 'new-invoice',  label: 'Tạo Invoice mới',      tab: 'invoices' },
	{ id: 'new-rule',     label: 'Tạo Automation rule',  tab: 'automation' },
	{ id: 'new-label',    label: 'Tạo Label',            tab: 'labels' },
	{ id: 'new-macro',    label: 'Tạo Macro',            tab: 'macros' },
	{ id: 'new-attr',     label: 'Tạo Custom Attribute', tab: 'attrs' },
];

/**
 * Global Command palette (⌘K / Ctrl+K). Wraps cmdk inside Radix Dialog.
 */
export default function CommandPalette() {
	const open = useSelector( ( s ) => s.uiPrefs.commandOpen );
	const dispatch = useDispatch();

	// Global ⌘K / Ctrl+K hotkey
	useEffect( () => {
		const onKey = ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
				e.preventDefault();
				dispatch( setCommandOpen( true ) );
			}
			if ( e.key === 'Escape' && open ) {
				dispatch( setCommandOpen( false ) );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ dispatch, open ] );

	const close = () => dispatch( setCommandOpen( false ) );
	const goto = ( tab ) => { dispatch( setActiveTab( tab ) ); close(); };

	return (
		<DialogPrimitive.Root open={ open } onOpenChange={ ( v ) => dispatch( setCommandOpen( !! v ) ) }>
			<DialogPrimitive.Portal>
				<DialogPrimitive.Overlay className="bzc-dialog-overlay" />
				<DialogPrimitive.Content className="bzc-cmd-content">
					<DialogPrimitive.Title className="sr-only">Command Palette</DialogPrimitive.Title>
					<Command label="Command Palette">
						<CommandInput placeholder="Đi tới tab, tạo mới, tìm hành động…" autoFocus />
						<CommandList>
							<CommandEmpty>Không tìm thấy.</CommandEmpty>

							<CommandGroup heading="Đi tới">
								{ NAV_ITEMS.map( ( it ) => {
									const Icon = it.icon;
									return (
										<CommandItem key={ it.id } onSelect={ () => goto( it.id ) } value={ 'goto ' + it.label }>
											<Icon size={ 14 } /> <span>{ it.label }</span>
										</CommandItem>
									);
								} ) }
							</CommandGroup>

							<CommandSeparator />

							<CommandGroup heading="Hành động">
								{ ACTIONS.map( ( a ) => (
									<CommandItem key={ a.id } onSelect={ () => goto( a.tab ) } value={ 'action ' + a.label }>
										<Plus size={ 14 } /> <span>{ a.label }</span>
									</CommandItem>
								) ) }
							</CommandGroup>
						</CommandList>
					</Command>
				</DialogPrimitive.Content>
			</DialogPrimitive.Portal>
		</DialogPrimitive.Root>
	);
}
