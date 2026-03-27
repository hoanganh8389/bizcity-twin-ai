<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_lp_users extends WaicLogic {
	protected $_code = 'lp_users';
	protected $_subtype = 3;
	protected $_order = 2;
	
	public function __construct( $block = null ) {
		$this->_name = __('Search Users', 'ai-copilot-content-generator');
		$this->_desc = __('Repeat actions for multiple Users', 'ai-copilot-content-generator');
		$this->_sublabel = array('name');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		global $wp_roles;
		$roles = array();

		foreach ($wp_roles->roles as $roleName => $roleData) {
			$roles[$roleName] = $roleName;
		}
		/*$capabilities = array();
		$roles = array();

		foreach ($wp_roles->roles as $roleName => $roleData) {
			$roles[$roleName] = $roleName;
			foreach ($roleData['capabilities'] as $cap => $value) {
				$capabilities[$cap] = $cap;
			}
		}*/
		$this->_settings = array(
			'name' => array(
				'type' => 'input',
				'label' => __('Node Name', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'ids' => array(
				'type' => 'input',
				'label' => __('Ids separated with commas', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'search' => array(
				'type' => 'input',
				'label' => __('Search value', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'search_in' => array(
				'type' => 'multiple',
				'label' => __('Search in', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'ID' => __('User ID', 'ai-copilot-content-generator'),
					'user_login' => __('User login', 'ai-copilot-content-generator'),
					'user_nicename' => __('User name', 'ai-copilot-content-generator'),
					'user_email' => __('User Email', 'ai-copilot-content-generator'),
					'user_url' => __('User Url', 'ai-copilot-content-generator'),
				),
			),
			'roles' => array(
				'type' => 'multiple',
				'label' => __('Roles', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $roles,
			),
			
		);
	}
	public function getVariables() {
		if (empty($this->_variables)) {
			$this->setVariables();
		}
		return $this->_variables;
	}
	public function setVariables() {
		$this->_variables = array(
			'count_steps' => __('Total Number of Users', 'ai-copilot-content-generator'),
			'count_errors' => __('Number of Errors', 'ai-copilot-content-generator'),
			'count_success' => __('Number of Successful Steps', 'ai-copilot-content-generator'),
			'loop_vars' => array_merge(array('step' => __('Step', 'ai-copilot-content-generator')), $this->getUserVariables()),
		);
		return $this->_variables;
	}
	
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!empty($this->_results)) {
			return $this->_results;
		}
		$args = array(
			'fields' => 'ID',
			'meta_query' => array(),
		);
		$ids = $this->replaceVariables($this->getParam('ids'), $variables);
		if (!empty($ids)) {
			$args['include'] = $this->controlIdsArray(explode(',', $ids));
		}
		$search = $this->replaceVariables($this->getParam('search'), $variables);
		if (!empty($search)) {
			$args['search'] = '*' . $search . '*';
		}
		$columns = $this->getParam('search_in', array(), 2);
		if (!empty($columns)) {
			$args['search_columns'] = $columns;
		}
		$roles = $this->getParam('roles', array(), 2);
		if (!empty($roles)) {
			$args['role__in'] = $roles;
		}
		
		$result = new WP_User_Query($args);
		
		$cnt = 0;
		$loopIds = array();
		if ($result) {
			$cnt = $result->total_users;
			$loopIds = $result->results;
		}
		wp_reset_query();
		
		$this->_results = array(
			'result' => array(
				'loop' => $loopIds,
				'count_steps' => $cnt,
				'count_errors' => 0,
				'count_success' => 0,
			),
			'error' => '',
			'status' => 3,
			'cnt' => $cnt,
			'sourceHandle' => ( $cnt > 0 ? 'output-then' : 'output-else' ),
		);
		return $this->_results;
	}
	public function addLoopVariables( $step, $workflow ) {
		if (!isset($this->_results['result'])) {
			return array();
		}
		
		$result = $this->_results['result'];
		$variables = $result;
		$variables['step'] = $step;
		if (empty($step)) {
			return $variables;
		}
		
		$id = WaicUtils::getArrayValue(WaicUtils::getArrayValue($result, 'loop', array(), 2), ( $step - 1 ), 0, 1);
		if (!empty($id)) {
			$variables = $workflow->addUserVariables($variables, $id);
		}
		return $variables;
	}

}
