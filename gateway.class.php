<?php
class Gateway {
    private $_config;
    private $_module;
    private $_basket;
    private $_result_message;
    private $_url;
    private $https_url;
    private $_path;

    public static $STATUS_CREATED = 0;
    public static  $STATUS_WAIT_PAY = 1;
    public static $STATUS_WAIT_PROCESSING = 2;
    public static $STATUS_PROCESSING = 3;
    public static $STATUS_FINISHED = 4;
    public static $STATUS_CANCELLED = 5;
    public static $STATUS_FROZEN = 6;
    public static $STATUS_ERROR = 99;

    public function __construct($module = false, $basket = false) {
        $this->_db		=& $GLOBALS['db'];

        $this->_module	= $module;
        $this->_basket =& $GLOBALS['cart']->basket;
        $this->_url			= 'https://z-payment.com/merchant.php';
        $this->https_url = 'https://z-payment.com/api/get_status_pay.php';
    }

    ##################################################

    public function transfer() {
        $transfer	= array(
            'action'	=> $this->_url,
            'method'	=> 'post',
            'target'	=> '_self',
            'submit'	=> 'auto',
        );
        return $transfer;
    }

    ##################################################

    public function repeatVariables() {
        return (isset($hidden)) ? $hidden : false;
    }

    public function fixedVariables() {

            $hidden = array(
                'LMI_PAYEE_PURSE'           => $this->_module['shop_id'],
                //'merchant' 				=> $this->_module['merchant_key'],
                'LMI_PAYMENT_AMOUNT'	    => $this->_basket['total'],
                'LMI_PAYMENT_NO'			=> $this->_basket['cart_order_id'],
                'CLIENT_MAIL'               => $this->_basket['billing_address']['email'],
                'LMI_PAYMENT_DESC'			=> "Payment for order #".$this->_basket['cart_order_id'],
                'ZP_SIGN'               =>
                    $this->ZP_SIGN($this->_module['shop_id'], $this->_basket['cart_order_id'], $this->_basket['total'], $this->_module['init_pass'] ),
                'x_method'				=> 'cc',

                'x_first_name'			=> $this->_basket['billing_address']['first_name'],
                'x_last_name'			=> $this->_basket['billing_address']['last_name'],
                'x_address'				=> $this->_basket['billing_address']['line1'].' '.$this->_basket['billing_address']['line2'],
                'x_city'				=> $this->_basket['billing_address']['town'],
                'x_state'				=> $this->_basket['billing_address']['state'],
                'x_zip'					=> $this->_basket['billing_address']['postcode'],
                'x_country'				=> $this->_basket['billing_address']['country_iso'],

                'x_email'				=> $this->_basket['billing_address']['email'],
                'x_phone'				=> $this->_basket['billing_address']['phone'],

            );

        return (isset($hidden)) ? $hidden : false;
    }

    public function call() {
        return false;
    }

    public function process() {

        ## We're being returned from Z-payment
        ## This function can do some pre-processing, but must assume NO variables are being passed around
        ## The basket will be emptied when we get to _a=complete, and the status isn't Failed/Declined

        $order				= Order::getInstance();
        $cart_order_id 		= $this->_basket['cart_order_id'];
        $order_summary		= $order->getSummary($cart_order_id);
        $fields = array();

        if (isset($_REQUEST)){
            ## Process the payment
            $fields	= array(
                'LMI_PAYEE_PURSE' => $_REQUEST['LMI_PAYEE_PURSE'],
                'LMI_PAYMENT_AMOUNT' => $_REQUEST['LMI_PAYMENT_AMOUNT'],
                'LMI_PAYMENT_NO' => $_REQUEST['LMI_PAYMENT_NO'],
                'LMI_SYS_TRANS_NO' => $_REQUEST['LMI_SYS_TRANS_NO'],
                'LMI_SECRET_KEY' => $_REQUEST['LMI_SECRET_KEY'],
                'LMI_MODE' => $_REQUEST['LMI_MODE'],
                'LMI_SYS_INVS_NO' => $_REQUEST['LMI_SYS_INVS_NO'],
                'LMI_SYS_TRANS_DATE' => $_REQUEST['LMI_SYS_TRANS_DATE'],
                'LMI_PAYER_PURSE' => $_REQUEST['LMI_PAYER_PURSE'],
                'LMI_PAYER_WM' => $_REQUEST['LMI_PAYER_WM'],
                'LMI_HASH' => $_REQUEST['LMI_HASH'],
                'shop_id' => $_REQUEST['LMI_PAYEE_PURSE'],
            );
        }
        //TEST VAL
        //$fields['LMI_SYS_TRANS_NO'] = 3216234;

        // request to Z-Payment to get order status;
        $HASH = md5($this->_module['shop_id']. $fields['LMI_SYS_TRANS_NO'] . $this->_module['merchant_key']);
        $Values = "ID_SHOP=".$this->_module['shop_id']."&ID_OPER=".$fields['LMI_SYS_TRANS_NO'] ."&HASH=$HASH";
        /*$request	= new Request($this->https_url);
        $request->setSSL(true);
        $request->setMethod('GET');
        $request->setData($Values);
        $data		= $request->send();*/
        $data = $this->SendRequest($this->https_url, $Values, "GET");
        $Data = explode('&', $data);
        $ArValue = array();
        if (!empty($cart_order_id) && !empty($data)) {
            $order				= Order::getInstance();
            $order_summary		= $order->getSummary($cart_order_id);
            $transData['notes']	= array();
            foreach ($Data as $key => $Value) {
                $PartValue = explode('=', $Value);
                $ArValue[$PartValue[0]] = $PartValue[1];
                if ($PartValue[0] == 'STATUS')
                {
                    //update db
                    $transData['status']= $PartValue[1];
                    if ($PartValue[1] == 4 || $PartValue[1] == 6) {
                        $status	= 'Approved';
                        $transData['notes'][]	= "Payment successful.";
                        $order->paymentStatus(Order::PAYMENT_SUCCESS, $cart_order_id);
                        $order->orderStatus(Order::ORDER_COMPLETE, $cart_order_id);
                    } else if ($PartValue[1] <= 6) {
                        $status	= 'Error';
                        $transData['notes'][]	= "Server validation fail";
                        $order->paymentStatus(Order::PAYMENT_DECLINE, $cart_order_id);
                        $order->orderStatus(Order::ORDER_DECLINED, $cart_order_id);

                    } else
                    {
                        $status	= 'Error';
                        $transData['notes'][]	= "Unspecified Error.";
                        $order->paymentStatus(Order::PAYMENT_DECLINE, $cart_order_id);
                        $order->orderStatus(Order::ORDER_DECLINED, $cart_order_id);

                    }
                }
            }
        }

        ## Build the transaction log data
        $extraField = array();
        $transData['gateway']		= $_GET['module'];
        $transData['order_id']		= $cart_order_id;
        $transData['trans_id']		= $_POST['LMI_PAYMENT_NO'];
        $transData['amount']		= $_POST['LMI_PAYMENT_AMOUNT'];
        $transData['customer_id']	= $order_summary['customer_id'];
        $transData['extra']			= implode("; ", $extraField);
        $order->logTransaction($transData);

        if($status=='Approved') {
            httpredir(currentPage(array('_g', 'type', 'cmd', 'module'), array('_a' => 'complete')));
        } else {
            $cart =  Cart::getInstance();
            $cart->clear();
        }
    }

    public function fail() {
        $order				= Order::getInstance();
        $cart_order_id 		= $this->_basket['cart_order_id'];
        $order_summary		= $order->getSummary($cart_order_id);

        $transData['notes'][]	= "This means that a payment was reversed due to a chargeback or other type of reversal. The funds have been debited from your account balance and returned to the customer. The reason for the reversal is given by the reason_code variable.";
        $order->paymentStatus(Order::PAYMENT_CANCEL, $cart_order_id);
        $order->orderStatus(Order::ORDER_CANCELLED, $cart_order_id);
        $cart =  Cart::getInstance();
        $cart->clear();
        $order->logTransaction($transData);
        return true;
    }

    public function result() {

        $order				= Order::getInstance();
        $cart_order_id 		= $this->_basket['cart_order_id'];
        $order_summary		= $order->getSummary($cart_order_id);

        /*$request	= new Request($this->_url);
        $request->setSSL();
        $request->setData($fields_array);
        $data		= $request->send();*/

        if (isset($_REQUEST)){
            ## Process the payment
            $fields_array	= array(
                'LMI_PAYEE_PURSE' => $_REQUEST['LMI_PAYEE_PURSE'],
                'LMI_PAYMENT_AMOUNT' => $_REQUEST['LMI_PAYMENT_AMOUNT'],
                'LMI_PAYMENT_NO' => $_REQUEST['LMI_PAYMENT_NO'],
                'LMI_SYS_TRANS_NO' => $_REQUEST['LMI_SYS_TRANS_NO'],
                'LMI_SECRET_KEY' => $_REQUEST['LMI_SECRET_KEY'],
                'LMI_MODE' => $_REQUEST['LMI_MODE'],
                'LMI_SYS_INVS_NO' => $_REQUEST['LMI_SYS_INVS_NO'],
                'LMI_SYS_TRANS_DATE' => $_REQUEST['LMI_SYS_TRANS_DATE'],
                'LMI_PAYER_PURSE' => $_REQUEST['LMI_PAYER_PURSE'],
                'LMI_PAYER_WM' => $_REQUEST['LMI_PAYER_WM'],
                'LMI_HASH' => $_REQUEST['LMI_HASH'],
                'shop_id' => $_REQUEST['LMI_PAYEE_PURSE'],
            );

            ## Get the Order ID
            $cart_order_id	= $_REQUEST['LMI_PAYMENT_NO'];

            $data = $fields_array;
            if (!empty($cart_order_id) && !empty($data)) {
                $order				= Order::getInstance();
                $order_summary		= $order->getSummary($cart_order_id);
                $transData['notes']	= array();
                //check LMI_HASH
                if ($this->check_hash($this->_module['merchant_key'])) {
                    $status	= 'Approved';
                    $transData['notes'][]	= "Payment successful. <br />Address: ".$_POST['address_status']."<br />Payer Status: ".$_POST['payer_status'];
                    $order->paymentStatus(Order::PAYMENT_SUCCESS, $cart_order_id);
                    $order->orderStatus(Order::ORDER_COMPLETE, $cart_order_id);
                    $cart =  Cart::getInstance();
                    $cart->clear();
                } else {
                    $status	= 'Error';
                    $transData['notes'][]	= "Server validation fail";
                    $order->paymentStatus(Order::PAYMENT_DECLINE, $cart_order_id);
                    $order->orderStatus(Order::ORDER_DECLINED, $cart_order_id);
                }
            } else {
                $status	= 'Error';
                $transData['notes'][]	= "Unspecified Error.";
                $order->paymentStatus(Order::PAYMENT_DECLINE, $cart_order_id);
                $order->orderStatus(Order::ORDER_DECLINED, $cart_order_id);
            }
        }

        ## Build the transaction log data
        $extraField = array();
        $transData['gateway']		= $_GET['module'];
        $transData['order_id']		= $cart_order_id;
        $transData['trans_id']		= $_POST['LMI_PAYMENT_NO'];
        $transData['amount']		= $_POST['LMI_PAYMENT_AMOUNT'];
        $transData['customer_id']	= $order_summary['customer_id'];
        $transData['extra']			= implode("; ", $extraField);
        $order->logTransaction($transData);

        return true;
    }

    ##################################################

    private function formatMonth($val) {
        return $val." - ".strftime("%b", mktime(0,0,0,$val,1 ,2009));
    }

    public function form() {


    }

    private static function _getFingerprint($api_login_id, $transaction_key, $amount, $fp_sequence, $fp_timestamp) {
        if (function_exists('hash_hmac')) {
            return hash_hmac("md5", $api_login_id . "^" . $fp_sequence . "^" . $fp_timestamp . "^" . $amount . "^", $transaction_key);
        }
        return bin2hex(mhash(MHASH_MD5, $api_login_id . "^" . $fp_sequence . "^" . $fp_timestamp . "^" . $amount . "^", $transaction_key));
    }

    private static function ZP_SIGN($shop_id, $no, $amount, $pass) {
        $sign = md5((int)$shop_id . $no . $amount . $pass);
        return $sign;
    }

    private static function check_hash ($key){
        echo($key);
        $result = false;

        $LMI_PAYEE_PURSE = $_REQUEST['LMI_PAYEE_PURSE'];
        $LMI_PAYMENT_AMOUNT = $_REQUEST['LMI_PAYMENT_AMOUNT'];
        $LMI_PAYMENT_NO = $_REQUEST['LMI_PAYMENT_NO'];
        $LMI_SYS_TRANS_NO = $_REQUEST['LMI_SYS_TRANS_NO'];
        $LMI_SECRET_KEY = $_REQUEST['LMI_SECRET_KEY'];
        $LMI_MODE = $_REQUEST['LMI_MODE'];
        $LMI_SYS_INVS_NO = $_REQUEST['LMI_SYS_INVS_NO'];
        $LMI_SYS_TRANS_DATE = $_REQUEST['LMI_SYS_TRANS_DATE'];
        $LMI_PAYER_PURSE = $_REQUEST['LMI_PAYER_PURSE'];
        $LMI_PAYER_WM = $_REQUEST['LMI_PAYER_WM'];
        $LMI_HASH = $_REQUEST['LMI_HASH'];
        $shop_id = $_REQUEST['LMI_PAYEE_PURSE'];

        if(isset($LMI_SECRET_KEY)) {
            if($LMI_SECRET_KEY==$key) {
                $result = true;
            }
        } else {
            $CalcHash = md5($LMI_PAYEE_PURSE.$LMI_PAYMENT_AMOUNT.$LMI_PAYMENT_NO.$LMI_MODE.$LMI_SYS_INVS_NO.$LMI_SYS_TRANS_NO.$LMI_SYS_TRANS_DATE.$key.$LMI_PAYER_PURSE.$LMI_PAYER_WM);
            if($LMI_HASH == strtoupper($CalcHash)) {
                $result = true;
            }
        }


        return $result;
    }

    function SendRequest($ACT_URL, $Values, $POST_OR_GET = 'POST')
    {
        //Если метод передачи GET
        if ($POST_OR_GET == 'GET') {
            $Result = file($ACT_URL . '?' . $Values);
            if ($Result === false) {
                die("Не удалось получить ответ по запросу");
            }
            $Result = $Result[0];

        } //Если метод передачи POST
        elseif ($POST_OR_GET == 'POST') {
            /////////////////////////////////////////////////////
            //Для работы примера требуется установленный модуль//
            //библиотеки CURL cайт пакета http://curl.haxx.se  //
            /////////////////////////////////////////////////////
            //Инициализация модуля CURL
            $ch = curl_init($ACT_URL);
            if (!$ch) {
                die('Инициализация модуля CURL не выполнена, проверьте его установку');
            }
            //Запрос POST
            curl_setopt($ch, CURLOPT_POST, 1);
            //Задаем переменные
            curl_setopt($ch, CURLOPT_POSTFIELDS, $Values);
            //HTTP заголовки не выводить
            curl_setopt($ch, CURLOPT_HEADER, 0);
            //Требуется получить результат запроса
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            //Редирект запретить
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
            //Выполнение запроса
            $Result = curl_exec($ch);
            if ($Result === false) {
                die("Error CURL number: " . curl_errno($ch) . " message: " . curl_error($ch));
            }
            curl_close($ch);
        }
        return $Result;
    }
}