<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTablePosts_Create extends WaicTable {
	public function __construct() {
		$this->_table = '@__posts_create';
		$this->_id = 'id';
		$this->_alias = 'waic_pc';
		$this->_addField('id', 'text', 'int')
			 ->_addField('task_id', 'text', 'int')
			 ->_addField('num', 'text', 'int')
			 ->_addField('params', 'text', 'varchar')
			 ->_addField('status', 'text', 'int')
			 ->_addField('start', 'text', 'text')
			 ->_addField('end', 'text', 'text')
			 ->_addField('updated', 'text', 'text')
			 ->_addField('flag', 'text', 'int')
			 ->_addField('step', 'text', 'int')
			 ->_addField('steps', 'text', 'int')
			 ->_addField('results', 'text', 'text')
			 ->_addField('pub_mode', 'text', 'int')
			 ->_addField('publish', 'text', 'text')
			 ->_addField('post_id', 'text', 'int')
			 ->_addField('added', 'text', 'text')
			 ->_addField('uniq', 'text', 'text');
	}
}
