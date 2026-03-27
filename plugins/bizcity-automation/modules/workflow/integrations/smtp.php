<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_smtp extends WaicIntegration {
	protected $_code = 'smtp';
	protected $_category = 'email';
	protected $_logo = 'SM';
	protected $_order = 1;
	private $_socket = false;
	
	public function __construct( $integration = false ) {
		$this->_name = 'SMTP';
		$this->_desc = __('Connect any SMTP server', 'ai-copilot-content-generator');
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
			'host' => array(
				'type' => 'input',
				'label' => __('SMTP Host', 'ai-copilot-content-generator') . ' *',
				'plh' => 'smtp.gmail.com',
				'default' => '',
			),
			'port' => array(
				'type' => 'input',
				'label' => __('Port', 'ai-copilot-content-generator') . ' *',
				'default' => 587,
			),
			'encryption' => array(
				'type' => 'select',
				'label' => __('Encryption', 'ai-copilot-content-generator'),
				'options' => array('TLS' => 'TLS', 'SSL' => 'SSL'),
				'default' => '',
			),
			'username' => array(
				'type' => 'input',
				'label' => __('Username', 'ai-copilot-content-generator'),
				'plh' => 'your-email@domain.com',
				'default' => '',
			),
			'password' => array(
				'type' => 'input',
				'label' => __('Password', 'ai-copilot-content-generator'),
				'encrypt' => true,
				'default' => '',
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
		$error = '';
		$host = $this->getParam('host');
		$port = $this->getParam('port');
		$encryption = $this->getParam('encryption');
		$username = $this->getParam('username');
		$password = $this->getDecryptedParam('password');
		
		if (empty($host)) {
			$error = 'SMTP Host needed';
		} else if (empty($port)) {
			$error = 'Port needed';
		} else if (empty($encryption)) {
			$error = 'Encryption needed';
		}
		$socket = false;
		if (empty($error)) {
			$isSSL = 'SSL' == $encryption;
			//$protocol = ( 'TLS' == $encryption ? 'tls://' : 'ssl://' );
			$protocol = ( $isSSL ? 'ssl://' : '' );
			/*$context = stream_context_create(array(
				'ssl' => array(
					'verify_peer' => false,
					'verify_peer_name' => false,
					'allow_self_signed' => true
				)
			));*/
			//$socket = stream_socket_client($protocol . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
			$socket = @stream_socket_client($protocol . $host . ':' . $port, $errno, $errstr, 60);
			if (!$socket) {
				$error = 'Connection failed: ' . $errstr . '(' . $errno . ')';
			}
		}
		if (empty($error)) {
			stream_set_timeout($socket, 10);
			$greeting = fgets($socket);
			if (!$greeting || stripos($greeting, '220') !== 0) {
				$error = 'Invalid server greeting: ' . trim($greeting);
			}
		}
		if (empty($error)) {
			$ehloHost = parse_url(home_url(), PHP_URL_HOST);
			fwrite($socket, "EHLO $ehloHost\r\n");

			$ehloSuccess = false;
			while ($line = fgets($socket)) {
				if (strpos($line, '250 ') === 0) {
					$ehloSuccess = true;
					break;
				}
				if (preg_match('/^5\d{2}/', $line)) {
					$error = 'EHLO error: ' . trim($line);
					break;
				}
			}
			if (empty($error) && !$ehloSuccess) {
				$error = 'EHLO failed or no valid response.';
			}
		}
		if (empty($error) && !$isSSL) {
			fwrite($socket, "STARTTLS\r\n");
			$tlsResponse = fgets($socket);
			if (stripos($tlsResponse, '220') !== 0) {
				$error = 'STARTTLS failed: ' . trim($tlsResponse);
			} else {
				stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
				fwrite($socket, "EHLO $ehloHost\r\n");
			}
			while ($line = fgets($socket)) {
				if (strpos($line, '250 ') === 0) break;
			}
		}

		if (empty($error)) {
			fwrite($socket, "AUTH LOGIN\r\n");
			$authPrompt = fgets($socket);
			if (stripos($authPrompt, '334') !== 0) {
				$error = 'AUTH LOGIN not accepted: ' . trim($authPrompt);
			} else {
				fwrite($socket, base64_encode($username) . "\r\n");
				$userResponse = fgets($socket);
				if (stripos($userResponse, '334') !== 0) {
					$error = 'Username rejected: ' . trim($userResponse);
				} else {
					fwrite($socket, base64_encode($password) . "\r\n");
					$passResponse = fgets($socket);
					if (stripos($passResponse, '235') !== 0) {
						$error = 'Authentication failed: ' . trim($passResponse);
					}
				}
			}
		}
		if ($socket) {
			if ($close) {
				fwrite($socket, "QUIT\r\n");
				fclose($socket);
			} else {
				$this->_socket = $socket;
			}
		}
		return $error;
	}
	
	public function doSendEmail( $data ) {
		$error = $this->doConnect(false);
		$result = array();
		
		if (empty($error)) {
			if (!$this->_socket) {
				$error = 'Socket Error';
			} else {
				$socket = $this->_socket;
				$from = $this->getParam('username');
				
				fwrite($socket, "MAIL FROM:<$from>\r\n");
				$resp = fgets($socket);
				if (stripos($resp, '250') !== 0) {
					$error = 'MAIL FROM failed: ' . trim($resp);
				}
				$headers  = "From: <$from>\r\n";
				$result['from'] = $from;
			}
		}
		if (empty($error) && !empty($data['to'])) {
			$to = explode(',', $data['to']);
			foreach ($to as $addr) {
				$addr = trim($addr);
				fwrite($socket, "RCPT TO:<$addr>\r\n");
				$resp = fgets($socket);
				if (stripos($resp, '250') !== 0) {
					$error = "RCPT TO failed ($addr): " . trim($resp);
					break;
				}
			}
			$headers .= 'To: ' . ( empty($data['to_name']) ? '' : $data['to_name'] . ' ' ) . $data['to'] . "\r\n";
		}
		if (empty($error) && !empty($data['cc'])) {
			$cc = explode(',', $data['cc']);
			foreach ($cc as $addr) {
				$addr = trim($addr);
				fwrite($socket, "RCPT TO:<$addr>\r\n");
				$resp = fgets($socket);
				if (stripos($resp, '250') !== 0) {
					$error = "RCPT TO CC failed ($addr): " . trim($resp);
					break;
				}
			}
			$headers .= "Cc: " . $data['cc'] . "\r\n";
		}
		if (empty($error) && !empty($data['bcc'])) {
			$bcc = explode(',', $data['bcc']);
			foreach ($bcc as $addr) {
				$addr = trim($addr);
				fwrite($socket, "RCPT TO:<$addr>\r\n");
				$resp = fgets($socket);
				if (stripos($resp, '250') !== 0) {
					$error = "RCPT TO BCC failed ($addr): " . trim($resp);
					break;
				}
			}
		}
		if (empty($error)) {
			fwrite($socket, "DATA\r\n");
			$resp = fgets($socket);
			if (stripos($resp, '354') !== 0) {
				$error = "DATA not accepted: " . trim($resp);
			}
		}
		if (empty($error) && !empty($data['reply'])) {
			$headers .= 'Reply-To: ' . $data['reply'] . "\r\n";
		}
		if (empty($error)) {
			$headers .= 'Subject: ' . $data['subject'] . "\r\n";
			$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

			fwrite($socket, $headers . "\r\n" . $data['message'] . "\r\n.\r\n");
			$resp = fgets($socket);
			if (stripos($resp, '250') !== 0) {
				$error = 'Message not accepted: ' . trim($resp);
			}
		}
		if (!empty($error) && $socket) {
			fgets($socket);

			fwrite($socket, "QUIT\r\n");
			fclose($socket);
		}
		$result['error'] = $error;
		return $result;
	}
}
