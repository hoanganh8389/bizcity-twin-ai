<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableHistory extends WaicTable {
	public function __construct() {
		$this->_table = '@__history';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'waic_history';
		$this->_addField('id', 'text', 'int')
			 ->_addField('task_id', 'text', 'int')
			 ->_addField('feature', 'text', 'text')
			 ->_addField('user_id', 'text', 'int')
			 ->_addField('ip', 'text', 'text')
			 ->_addField('engine', 'text', 'text')
			 ->_addField('model', 'text', 'text')
			 ->_addField('mode', 'text', 'int')
			 ->_addField('created', 'text', 'text')
			 ->_addField('status', 'text', 'int')
			 ->_addField('tokens', 'text', 'int')
			 ->_addField('cost', 'text', 'decimal');
	}
}
