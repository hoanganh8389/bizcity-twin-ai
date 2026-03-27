<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableDs_Data extends WaicTable {
	public function __construct() {
		$this->_table = '@__ds_data';
		$this->_id = 'id';
		$this->_alias = 'waic_ds_data';
		$this->_addField('id', 'text', 'int')
			 ->_addField('ds_id', 'text', 'int')
			 ->_addField('prompt', 'text', 'varchar')
			 ->_addField('completion', 'text', 'varchar')
			 ->_addField('obj_id', 'text', 'int');
	}
}
