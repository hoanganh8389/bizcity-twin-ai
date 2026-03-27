<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableTasks extends WaicTable {
	public function __construct() {
		$this->_table = '@__tasks';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'waic_tasks';
		$this->_addField('id', 'text', 'int')
			 ->_addField('feature', 'text', 'varchar')
			 ->_addField('author', 'text', 'int')
			 ->_addField('title', 'text', 'varchar')
			 ->_addField('params', 'text', 'varchar')
			 ->_addField('cnt', 'text', 'int')
			 ->_addField('status', 'text', 'int')
			 ->_addField('created', 'text', 'text')
			 ->_addField('updated', 'text', 'text')
			 ->_addField('start', 'text', 'text')
			 ->_addField('end', 'text', 'text')
			 ->_addField('step', 'text', 'int')
			 ->_addField('steps', 'text', 'int')
			 ->_addField('cycle', 'text', 'int')
			 ->_addField('message', 'text', 'text')
			 ->_addField('tokens', 'text', 'int')
			 ->_addField('mode', 'text', 'text')
			 ->_addField('obj_id', 'text', 'int');
	}
}
