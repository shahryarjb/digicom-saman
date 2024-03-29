<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_Digicom
 * @subpackage 	trangell_Saman
 * @copyright   trangell team => https://trangell.com
 * @copyright   Copyright (C) 20016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
 
defined('_JEXEC') or die;

require_once(dirname(__FILE__) . '/trangellsaman/helper.php');
if (!class_exists ('checkHack')) {
	require_once( dirname(__FILE__) . '/trangellsaman/trangell_inputcheck.php');
}

class plgDigiCom_PayTrangellSaman extends JPlugin
{
	protected $autoloadLanguage = true;

	function __construct(& $subject, $config)
	{
		parent::__construct($subject, $config);
		$this->responseStatus= array (
			'Completed' => 'A',
			'Pending' 	=> 'P',
			'Failed' 		=> 'P',
			'Denied' 		=> 'P',
			'Refunded'	=> 'RF'
		);
	}
	
	public function onDigicomSidebarMenuItem()
	{
		$pluginid = $this->getPluginId('trangellsaman','digicom_pay','plugin');
		$params 	= $this->params;
		$link 		= JRoute::_("index.php?option=com_plugins&client_id=0&task=plugin.edit&extension_id=".$pluginid);
		return '<a target="_blank" href="' . $link . '" title="trangellsaman" id="plugin-'.$pluginid.'">' . 'trangellsaman' . '</a>';
	}
	
	function getPluginId($element,$folder, $type)
	{
	    $db = JFactory::getDBO();
	    $query = $db->getQuery(true);
	    $query
	        ->select($db->quoteName('a.extension_id'))
	        ->from($db->quoteName('#__extensions', 'a'))
	        ->where($db->quoteName('a.element').' = '.$db->quote($element))
	        ->where($db->quoteName('a.folder').' = '.$db->quote($folder))
	        ->where($db->quoteName('a.type').' = '.$db->quote($type));

	    $db->setQuery($query);
	    $db->execute();
	    if($db->getNumRows()){
	        return $db->loadResult();
	    }
	    return false;
	}

	function buildLayoutPath($layout)
	{
		if(empty($layout)) $layout = "default";
		$core_file 	= dirname(__FILE__) . '/' . $this->_name . '/tmpl/' . $layout . '.php';
		return $core_file;
	}

	function buildLayout($vars, $layout = 'default' )
	{
		ob_start();
		$layout = $this->buildLayoutPath($layout);
		include($layout);
		$html = ob_get_contents();
		ob_end_clean();
		return $html;
	}

	function onDigicom_PayGetHTML($vars,$pg_plugin)
	{
		if($pg_plugin != $this->_name) return;
		$vars->custom_name= $this->params->get( 'plugin_name' );
		$configs = JComponentHelper::getComponent('com_digicom')->params;
		//$vars->custom_email=$this->params->get( 'plugin_mail' );
		$price = intval(str_replace(".","",plgDigiCom_PayTrangellSamanHelper::getPayerPrice($vars->order_id)));
		//=========================================================
		$vars->merchantId = $this->params->get('samanmerchantId');
		$vars->reservationNumber = time();
		$vars->totalAmount =  $price;
		$vars->callBackUrl  = $vars->return;
		$vars->sendUrl = "https://sep.shaparak.ir/Payment.aspx";
		$html = $this->buildLayout($vars);
		return $html;
	}

	function onDigicom_PayGetInfo($config)
	{
		if(!in_array($this->_name,$config)) return;
		$obj 		= new stdClass;
		$obj->name 	= $this->params->get( 'plugin_name' );
		$obj->id	= $this->_name;
		return $obj;
	}

	function onDigicom_PayProcesspayment($data)
	{
		$processor = JFactory::getApplication()->input->get('processor','');
		if($processor != $this->_name) return;
		$app	= JFactory::getApplication();	
		$jinput = $app->input;
		$orderId = $jinput->get->get('order_id', '0', 'INT');
		$price = intval(str_replace(".","",plgDigiCom_PayTrangellSamanHelper::getPayerPrice($orderId)));
		//========================================================================
		$resNum = $jinput->post->get('ResNum', '0', 'INT');
		$trackingCode = $jinput->post->get('TRACENO', '0', 'INT');
		$stateCode = $jinput->post->get('stateCode', '1', 'INT');
		
		$refNum = $jinput->post->get('RefNum', 'empty', 'STRING');
		if (checkHack::strip($refNum) != $refNum )
			$refNum = "illegal";
		$state = $jinput->post->get('State', 'empty', 'STRING');
		if (checkHack::strip($state) != $state )
			$state = "illegal";
		$cardNumber = $jinput->post->get('SecurePan', 'empty', 'STRING'); 
		if (checkHack::strip($cardNumber) != $cardNumber )
			$cardNumber = "illegal";
			
		$merchantId = $this->params->get('samanmerchantId');
			
		if (
			checkHack::checkNum($resNum) &&
			checkHack::checkNum($trackingCode) &&
			checkHack::checkNum($stateCode) 
		){
			if (isset($state) && ($state == 'OK' || $stateCode == 0)) {
				try {
					$out    = new SoapClient('https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL');
					$resultCode    = $out->VerifyTransaction($refNum, $merchantId);
				
					if ($resultCode == $price) {
						$payment_status = $this->translateResponse('Completed');
						$db = JFactory::getDbo();
						$query = $db->getQuery(true);
						$fields = array($db->qn('amount_paid') . ' = ' .$db->q($price) );
						$conditions = array($db->qn('id') . ' = ' . $db->q(intval($orderId)));
						$query->update($db->qn('#__digicom_orders'))->set($fields)->where($conditions);
						$db->setQuery((string)$query); 
						$db->execute(); 
						$msg= plgDigiCom_PayTrangellSamanHelper::getGateMsg(1); 
						$message = "کد پیگیری".$trackingCode;
						$app->enqueueMessage($msg.'<br/>' .$message, 'message');
					}
					else {
						$msg= plgDigiCom_PayTrangellSamanHelper::getGateMsg($state); 
						$app->enqueueMessage($msg, 'error');
						$payment_status = $this->translateResponse('Failed');	
					}
				}
				catch(\SoapFault $e)  {
					$msg= plgDigiCom_PayTrangellSamanHelper::getGateMsg('error'); 
					$app->enqueueMessage($msg, 'error');
					$payment_status = $this->translateResponse('Failed');	
				}
			}
			else {
				$msg= plgDigiCom_PayTrangellSamanHelper::getGateMsg($state);
				$app->enqueueMessage($msg, 'error');
				$payment_status = $this->translateResponse('Pending');
			}
		}
		else {
			$msg= plgDigiCom_PayTrangellSamanHelper::getGateMsg('hck2'); 
			$app->enqueueMessage($msg, 'error');
			$payment_status = $this->translateResponse('Failed');
		}

		$data['payment_status'] = $payment_status;
		if(!isset($data['payment_status']))
		{
			$info = array('raw_data'	=>	$data);
			$this->onDigicom_PayStorelog($this->_name, $info);
		}

		$result = array(
			'transaction_id'	=>	$this->getUniqueTransactionId($orderId),
			'order_id'				=>	$orderId,
			'status'					=>	$payment_status,
			'raw_data'				=>	json_encode($data),
			'trackingcode'			=>	"کد پیگیری".$trackingCode,
			'card_Number'			=> 	"شماره کارت " . $cardNumber,
			'processor'				=>	$processor
		);
		return $result;
	}

	function translateResponse($invoice_status){

		foreach($this->responseStatus as $key=>$value)
		{
			if($key==$invoice_status)
			return $value;
		}
	}

	function onDigicom_PayStorelog($name, $data)
	{
		if($name != $this->_name) return;
		plgDigiCom_PayTrangellSamanHelper::Storelog($this->_name,$data);
	}

	function getUniqueTransactionId($order_id){
		$uniqueValue = $order_id.time();
		$long = md5(uniqid($uniqueValue, true));
		return substr($long, 0, 15);
	}

	
}
