<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableTraining extends WaicTable {
	public function __construct() {
		$this->_table = '@__training';
		$this->_id = 'id';
		$this->_alias = 'waic_training';
		$this->_addField('id', 'text', 'int')
			 ->_addField('author', 'text', 'int')
			 ->_addField('obj_type', 'text', 'int')
			 ->_addField('title', 'text', 'varchar')
			 ->_addField('obj_key', 'text', 'varchar')
			 ->_addField('engine', 'text', 'varchar')
			 ->_addField('model', 'text', 'varchar')
			 ->_addField('cnt', 'text', 'int')
			 ->_addField('status', 'text', 'int')
			 ->_addField('params', 'text', 'varchar')
			 ->_addField('file_id', 'text', 'varchar')
			 ->_addField('job_id', 'text', 'varchar')
			 ->_addField('vector', 'text', 'varchar')
			 ->_addField('message', 'text', 'varchar')
			 ->_addField('created', 'text', 'text')
			 ->_addField('updated', 'text', 'text');
	}
}
