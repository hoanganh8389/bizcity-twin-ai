<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableDatasets extends WaicTable {
	public function __construct() {
		$this->_table = '@__datasets';
		$this->_id = 'id';
		$this->_alias = 'waic_datasets';
		$this->_addField('id', 'text', 'int')
			 ->_addField('author', 'text', 'int')
			 ->_addField('title', 'text', 'varchar')
			 ->_addField('source', 'text', 'int')
			 ->_addField('format', 'text', 'int')
			 ->_addField('status', 'text', 'int')
			 ->_addField('tokens', 'text', 'int')
			 ->_addField('params', 'text', 'varchar')
			 ->_addField('created', 'text', 'text')
			 ->_addField('updated', 'text', 'text');
	}
}
