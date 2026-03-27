<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableWorkspace extends WaicTable {
	public function __construct() {
		$this->_table = '@__workspace';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'sup_w';
		$this->_addField('id', 'text', 'int')
			->_addField('name', 'text', 'text', 0, 'Name')
			->_addField('value', 'text', 'text', 0, 'Value')
			->_addField('flag', 'text', 'int', 0, 'Flag')
			->_addField('timeout', 'text', 'int', 0, 'Timeout');
	}
}
