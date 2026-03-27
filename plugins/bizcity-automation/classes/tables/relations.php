<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableRelations extends WaicTable {
	public function __construct() {
		$this->_table = '@__relations';
		$this->_id = 'id';
		$this->_alias = 'waic_relations';
		$this->_addField('id', 'text', 'int')
			 ->_addField('ds_id', 'text', 'int')
			 ->_addField('obj_id', 'text', 'int');
	}
}
