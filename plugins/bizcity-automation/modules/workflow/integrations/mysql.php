<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_mysql extends WaicIntegration {
	protected $_code = 'mysql';
	protected $_category = 'db';
	protected $_logo = 'MY';
	protected $_order = 91;
	//private $_maxAttempts = 3;
	private $_connection = null;
	
	public function __construct( $integration = false ) {
		$this->_name = 'Mysql';
		$this->_desc = __('Connect to MySQL database', 'ai-copilot-content-generator');
		$this->setIntegration($integration);
	}
	
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		
		$this->_settings = array(
			'name' => array(
				'type' => 'input',
				'label' => __('Profile name', 'ai-copilot-content-generator'),
				'plh' => __('Internal name to identify this configuration', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'mode' => array(
				'type' => 'select',
				'label' => __('Connection Type', 'ai-copilot-content-generator'),
				'options' => array(
					'wordpress' => __('WordPress Database (current)', 'ai-copilot-content-generator'),
					'custom' => __('Custom MySQL Database', 'ai-copilot-content-generator'),
				),
				'default' => 'wordpress',
			),
			'host' => array(
				'type' => 'input',
				'label' => __('Database Host', 'ai-copilot-content-generator') . ' *',
				'default' => 'localhost',
				'show' => array('mode' => array('custom')),
			),
			'port' => array(
				'type' => 'input',
				'label' => __('Port', 'ai-copilot-content-generator') . ' *',
				'default' => '3306',
				'show' => array('mode' => array('custom')),
			),
			'database' => array(
				'type' => 'input',
				'label' => __('Database', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'show' => array('mode' => array('custom')),
			),
			'username' => array(
				'type' => 'input',
				'label' => __('Username', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'show' => array('mode' => array('custom')),
			),
			'password' => array(
				'type' => 'input',
				'label' => __('Password', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'encrypt' => true,
				'show' => array('mode' => array('custom')),
			),
			'timeout' => array(
				'type' => 'input',
				'label' => __('Timeout, sec', 'ai-copilot-content-generator'),
				'default' => 10,
				'show' => array('modee' => array('custom')),
			),
		);
	}
	
	public function doTest( $need = false ) {
		$params = $this->getParams();
		if (!$need && !empty($params['_status'])) {
			return true;
		}
		$error = $this->doConnect();
		if (empty($error)) {
			$this->addParam('_status', 1);
			$this->addParam('_status_error', '');
		} else {
			$this->addParam('_status', 7);
			$this->addParam('_status_error', $error);
		}
	}
	
	public function doConnect( $close = true ) {
		$this->_connection = null;
		$mode = $this->getParam('mode');
		if ('wordpress' === $mode) {
			global $wpdb;
			if (!$wpdb) {
				return 'WordPress database connection not available';
			}
			$this->_connection = $wpdb;
		} else {
			// Custom MySQL connection
			$host = $this->getParam('host');
			$database = $this->getParam('database');
			$username = $this->getParam('username');
			$password = $this->getDecryptedParam('password');
			$port = (int) $this->getParam('port');
			$timeout = (int) $this->getParam('timeout', 10, 1);

			if (empty($host) || empty($database) || empty($username)) {
				return 'Database connection credentials are required';
			}

			//mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

			try {
				$connection = new mysqli($host, $username, $password, $database, $port);

				if ($connection->connect_error) {
					return 'Connection failed: ' . $connection->connect_error;
				}
				$connection->set_charset('utf8mb4');
				$this->_connection = $connection;

			} catch (mysqli_sql_exception $e) {
				return 'Database connection error: ' . $e->getMessage();
			}
		}
		return false;
	}
	public function doQuery( $query ) {
		$error = $this->doConnect();
		if (empty($error) && $this->_connection) {
			$startTime = microtime(true);
			$result = $this->_connection instanceof wpdb ? $this->doWPQuery($query) : $this->doMysqliQuery($query);
			$result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);
			$result['query_executed'] = $query;
			return $result;
		} 
		return array(
			'success' => false,
			'error' => $error,
		);
	}
	
	private function getQueryType( $query ) {
		$query = trim($query);
		if (preg_match('/^SELECT/i', $query)) {
			return 'SELECT';
		} elseif (preg_match('/^INSERT/i', $query)) {
			return 'INSERT';
		} elseif (preg_match('/^UPDATE/i', $query)) {
			return 'UPDATE';
		} elseif (preg_match('/^DELETE/i', $query)) {
			return 'DELETE';
		} elseif (preg_match('/^CREATE/i', $query)) {
			return 'CREATE';
		} elseif (preg_match('/^DROP/i', $query)) {
			return 'DROP';
		} else {
			return 'UNKNOWN';
		}
	}
	private function doWPQuery( $query ) {
		global $wpdb;
		
		$error = '';
		$results = array();
		$queryType = $this->getQueryType($query);
		if ('SELECT' === $queryType) {
			$result = $wpdb->get_results($query, ARRAY_A);
			if ($wpdb->last_error) {
				return array(
					'success' => false,
					'error' => 'Query error: ' . $wpdb->last_error,
				);
			} else {
				return array(
					'success' => true,
					'operation' => $queryType,
					'row_count' => count($result),
					'result_data' => is_array($result) ? json_encode($result) : json_encode(array('count' => $result)),
				);
			}
		} else {
			$result = $wpdb->query($query);
			if ($wpdb->last_error) {
				return array(
					'success' => false,
					'error' => 'Query error: ' . $wpdb->last_error,
				);
			}
			return array(
				'success' => true,
				'operation' => $queryType,
				'rows_affected' => $result !== false ? $result : 0,
				'insert_id' => ('INSERT' === $queryType) ? $wpdb->insert_id : 0,
				'result_data' => array(),
				'row_count' => 0,
			);
		}
		return $results;
	}
	private function doMysqliQuery( $query ) {
		$error = '';
		$results = array();
		$queryType = $this->getQueryType($query);
		$connection = $this->_connection;
		$result = $connection->query($query);
		if (!$result) {
			return array(
				'success' => false,
				'error' => 'Query execution failed: ' . $connection->error,
			);
		}
		if ($result instanceof mysqli_result) {
			$data = array();
			while ($row = $result->fetch_assoc()) {
				$data[] = $row;
			}
			return array(
				'success' => true,
				'operation' => $queryType,
				'row_count' => count($data),
				'result_data' => is_array($data) ? json_encode($data) : json_encode(array('count' => $data)),
			);
		} else {
			return array(
				'success' => true,
				'operation' => $queryType,
				'rows_affected' => $connection->affected_rows,
				'insert_id' => ('INSERT' === $queryType) ? $connection->insert_id : 0,
				'result_data' => array(),
				'row_count' => 0,
			);
		}
		return $results;
	}
}
