<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableModules extends WaicTable {
	public function __construct() {
		$this->_table = '@__modules';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'sup_m';
		$this->_addField('label', 'text', 'varchar', 0, 'Label', 128)
				->_addField('type_id', 'selectbox', 'smallint', 0, 'Type')
				->_addField('active', 'checkbox', 'tinyint', 0, 'Active')
				->_addField('params', 'textarea', 'text', 0, 'Params')
				->_addField('code', 'hidden', 'varchar', '', 'Code', 64)
				->_addField('ex_plug_dir', 'hidden', 'varchar', '', 'External plugin directory', 255);
	}
}
