<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableDs_posts extends WaicTable {
	public function __construct() {
		$this->_table = '@__ds_posts';
		$this->_id = 'id';
		$this->_alias = 'waic_ds_posts';
		$this->_addField('id', 'text', 'int')
			 ->_addField('ds_id', 'text', 'int')
			 ->_addField('post_id', 'text', 'int')
			 ->_addField('format', 'text', 'int')
			 ->_addField('prompt', 'text', 'text')
			 ->_addField('status', 'text', 'int')
			 ->_addField('start', 'text', 'text')
			 ->_addField('end', 'text', 'text')
			 ->_addField('updated', 'text', 'text')
			 ->_addField('flag', 'text', 'int')
			 ->_addField('results', 'text', 'text');
	}
}
