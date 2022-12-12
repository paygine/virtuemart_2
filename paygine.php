<?php

defined('_JEXEC') or die('Restricted access');

/**
 *
 * Paygine payment plugin for VirtueMart 2
 *
 * http://www.paygine.ru
 *
 */

if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentPaygine extends vmPSPlugin {

	function __construct(&$subject, $config) {
		parent::__construct($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush = array(
			'payment_logos' => array('', 'char'),
			'sector_id' => array('', 'string'),
			'password' => array('', 'string'),
			'test_mode' => array('', 'string'),
			'payment_currency' => array('', 'string'),
			'status_pending' => array('', 'string')
		);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	public function getTableSQLFields () {
		$SQLfields = array(
            'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,2) NOT NULL DEFAULT \'0.00\' ',
            'payment_currency' => 'char(3) '
		);
		return $SQLfields;
	}

	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Paygine Table');
	}

	protected function displayLogos($logo_list) {
		$img = "";

		if (!(empty($logo_list))) {
			$url = JURI::root() . str_replace('\\', '/', str_replace(JPATH_ROOT, '', dirname(__FILE__))) . '/';
			if (!is_array($logo_list))
				$logo_list = (array) $logo_list;
			foreach ($logo_list as $logo) {
				$alt_text = substr($logo, 0, strpos($logo, '.'));
				$img .= '<img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /> ';
			}
		}
		return $img;
	}

	function plgVmConfirmedOrder($cart, $order) {
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		return $this->sendTransactionRequest($method,$cart, $order);
	}

	function sendTransactionRequest($method, $cart, $order) {
		$lang = JFactory::getLanguage ();
		$filename = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);

		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}

		$this->getPaymentCurrency($method);
		$currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
		$currency = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_numeric_code');

		$totalInPaymentCurrency = plgVmPaymentPaygine::getAmountInCurrency($order['details']['BT']->order_total,$method->payment_currency);
		$price = $totalInPaymentCurrency['value'];

		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['payment_name'] = $this->renderPluginName($method) . '<br />' . $method->payment_info;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
		$dbValues['payment_currency'] = $currency_code_3;
		$this->storePSPluginInternalData($dbValues);

		if ($method->test_mode == '0')
			$paygine_url = 'https://pay.paygine.com';
		else
			$paygine_url = 'https://test.paygine.com';
		$url = $paygine_url.'/webapi/Register';
		
		$signature  = base64_encode(md5($method->sector_id . ($price * 100) . $currency . $method->password));
		
		$data = array(
			'sector' => $method->sector_id,
			'reference' => $order['details']['BT']->order_number,
			'amount' => $price * 100,
			'description' => '#' . $order['details']['BT']->order_number,
			'email' => htmlspecialchars($order['details']['BT']->email, ENT_QUOTES),
			'currency' => $currency,
			'mode' => 1,
			'url' => $this->getRedirectURL($order),
			'signature' => $signature
		);
		$options = array(
		    'http' => array(
		        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
		        'method'  => 'POST',
		        'content' => http_build_query($data),
		    ),
		);

		$context  = stream_context_create($options);
		$paygine_id = file_get_contents($url, false, $context);

		if (intval($paygine_id) == 0)
			return false;

		$signature = base64_encode(md5($method->sector_id . $paygine_id . $method->password));
		$redirect_url = $paygine_url
			. '/webapi/Purchase'
			. '?sector=' . $method->sector_id
			. '&id=' . $paygine_id
			. '&signature=' . $signature;
		$mainframe = JFactory::getApplication();
		$mainframe->redirect($redirect_url);

		return true;
	}

	function getNewStatus($method) {
		if (isset($method->status_pending) && $method->status_pending != '') {
			return $method->status_pending;
		} else {
			return 'P';
		}
	}

	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
/*		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return NULL;
        }
        $db = JFactory::getDBO();
        $q  = 'SELECT * FROM `' . $this->_tablename . '` ' . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }
        $this->getPaymentCurrency($paymentTable);
       
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";

        return $html;
*/
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
			return NULL;
		}
		VmConfig::loadJLang('com_virtuemart');

		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
		$html .= '</table>' . "\n";
		return $html;
	}

    function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
        return 0;
    }

	public function checkConditions($cart, $method, $cart_prices) {
		return true;
	}

	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		return $this->OnSelectCheck($cart);
	}

	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
	}

	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array()/*, &$paymentCounter*/) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices/*, $paymentCounter*/);
	}

	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams ('payment', $name, $id, $data);
	}

	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	static function getAmountInCurrency($amount, $currencyId) {
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
 		$return = array();
		$paymentCurrency = CurrencyDisplay::getInstance($currencyId);
		$return['value'] = $paymentCurrency->roundForDisplay($amount, $currencyId, 1.0, false, 2);
		$return['display'] = $paymentCurrency->getFormattedCurrency($return['value']);
		return $return;
	}

	public function plgVmOnPaymentResponseReceived(&$html) {
		$lang = JFactory::getLanguage();
		$filename = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);

		if (!class_exists('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		if (!class_exists('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}

		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$order_number = JRequest::getString('reference', 0);

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return false;
		}
		if (!($paymentTables = $this->getDatasByOrderId($virtuemart_order_id))) {
			return false;
		}

		$signature = base64_encode(md5($method->sector_id . JRequest::getInt('id', 0) . JRequest::getInt('operation', 0) . $method->password));

		if ($method->test_mode == '0')
			$paygine_url = 'https://pay.paygine.com';
		else
			$paygine_url = 'https://test.paygine.com';
		$url = $paygine_url . '/webapi/Operation';

		$context = stream_context_create(array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query(array(
					'sector' => $method->sector_id,
					'id' => JRequest::getInt('id', 0),
					'operation' => JRequest::getInt('operation', 0),
					'signature' => $signature
				)),
			)
		));

		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);

		$repeat = 3;
		try {
			while ($repeat) {
				$repeat--;
				sleep(2);

				$xml = file_get_contents($url, false, $context);
				if (!$xml)
					throw new Exception("Empty data");
				$xml = simplexml_load_string($xml);
				if (!$xml)
					throw new Exception("Non valid XML was received");
				$response = json_decode(json_encode($xml));
				if (!$response)
					throw new Exception("Non valid XML was received");

				$tmp_response = (array)$response;
				unset($tmp_response["signature"]);
				$signature = base64_encode(md5(implode('', $tmp_response) . $method->password));
				if ($signature !== $response->signature)
					throw new Exception("Invalid signature");

                if (($response->type != 'PURCHASE' && $response->type != 'PURCHASE_BY_QR' && $response->type != 'AUTHORIZE') || $response->state != 'APPROVED')
					continue;

				$totalInPaymentCurrency = plgVmPaymentPaygine::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);
				$totalInPaymentCurrency = $totalInPaymentCurrency['value'];
				if ($response->amount != intval($totalInPaymentCurrency * 100) || $response->amount <= 0)
					throw new Exception("Invalid price");

				$order['order_status'] = 'C';
				$orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
				$cart = VirtueMartCart::getCart();
				$cart->emptyCart();

				$html = '<table>' . "\n";
				$html .= $this->getHtmlRow('PAYGINE_ORDER_NUMBER', $order_number);
				$html .= $this->getHtmlRow('PAYGINE_ORDER_ID', $virtuemart_order_id);
				$html .= $this->getHtmlRow('PAYGINE_PAYMENT_ID', $response->id);
				$html .= $this->getHtmlRow('PAYGINE_STATUS', JText::_('VMPAYMENT_PAYGINE_SUCCESS'));
				$html .= '</table>' . "\n";

				return true;
			}

			throw new Exception('Unknown error');

		} catch (Exception $ex) {
			error_log($ex->getMessage());
			vmError(JText::_('VMPAYMENT_PAYGINE_ERROR', false));
			$mainframe = JFactory::getApplication();
			$mainframe->redirect(JURI::root() . 'index.php?option=com_virtuemart&view=cart');
			return false;
		}
	}

	public function plgVmOnPaymentNotification() {
		$lang = JFactory::getLanguage();
		$filename = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);

		if (!class_exists('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		if (!class_exists('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}

		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$order_number = JRequest::getString('reference', 0);

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return false;
		}
		if (!($paymentTables = $this->getDatasByOrderId($virtuemart_order_id))) {
			return false;
		}
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);
		
		$xml = file_get_contents('php://input');
		if (!$xml)
			die('error 1');
		$xml = simplexml_load_string($xml);
		if (!$xml)
			die('error 2');
		$response = json_decode(json_encode($xml));
		if (!$response)
			die('error 3');

		header('Content-type: text/plain');

        if (($response->type != 'PURCHASE' && $response->type != 'PURCHASE_BY_QR' && $response->type != 'AUTHORIZE') || $response->state != 'APPROVED')
			die('error 4');

		$signature = $response->signature;
		unset($response->signature);
		$str = implode('', (array)$response) . $method->password;
		$my_signature = base64_encode(md5($str));
		if ($my_signature !== $signature)
			die('error 4');

		$totalInPaymentCurrency = plgVmPaymentPaygine::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);
		$totalInPaymentCurrency = $totalInPaymentCurrency['value'];
		if ($response->amount != intval($totalInPaymentCurrency * 100) || $response->amount <= 0)
			die('error 5');

		$order['order_status'] = 'C';
		$orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
		
		die('ok');
	}
	
	private function getRedirectURL($order) {
		return JURI::root() . "index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm="
			. $order['details']['BT']->virtuemart_paymentmethod_id;
	}

}
