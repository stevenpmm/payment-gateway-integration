<?php

// load Braintree API
require __DIR__ . '/transaction.php';

// load Paypal API
require __DIR__ . '/bootstrap.php';
use PayPal\Api\Amount;
use PayPal\Api\CreditCard;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\FundingInstrument;
use PayPal\Api\Transaction;
use PayPal\Rest\ApiContext;

define('PAYPAL',1);
define('BRAINTREE',2);

// validation class
class validation {

	private $errors = array();
	private $credit_card_length = 16;
	private $credit_card_cvv_length = 3;
	private $pattern_expiry = "/^([0-9]{2}\-[0-9]{4})$/";

	function __construct(){}

	public function validate(){

		extract($_POST); // for local scope

		if(empty($or_fname) || empty($or_sname)){
			$this->errors[] = 'NAMES: Letters, numbers or spaces only!';
		}
			
		if(empty($or_amount) || !ctype_digit($or_amount)) {
			$this->errors[] = 'AMOUNT: numbers only!';
		}

		if(empty($cc_num) || !ctype_digit($cc_num) || strlen($cc_num) < $this->credit_card_length) {
			$this->errors[] = 'CREDIT CARD: 16 numbers expected!';
		}
			
		if(empty($cc_cvv) || !ctype_digit($cc_cvv) || strlen($cc_cvv) < $this->credit_card_cvv_length) {
			$this->errors[] = 'CREDIT CARD CVV: 3 numbers expected!';
		}

		if(empty($cc_expiry) || !preg_match($this->pattern_expiry,$cc_expiry)) {
			$this->errors[] = 'CREDIT CARD EXPIRY: Format mm-yyyy';
		}
			
		if(count($this->errors)){
			return $this->errors;
		}

		return false;
	}

}

// base class for the gateway objects
abstract class PGFactory 
{
	
	private $message;
	/**
	 * @return Payment Gateway Object
	 */
	public static function getPGObject($type)
	{

		switch ($type)
		{
			case PAYPAL:
				return new MyPayPal();
			case BRAINTREE:
				return new MyBraintree();
		}
		return null;
	}
	
	public function log_activity($data){
		
		// load db config file
        require __DIR__ . '/db_config.php';
		
		$link = new PDO("mysql:host=$dbhost;dbname=$dbname",$dbusername,$dbpassword);
        $statement = $link->prepare("INSERT INTO request_response(request, response, gateway)VALUES(?, ?, ?)");
        $statement->execute(array(serialize($data['request']), $data['response'], $data['gateway']));
	}
	
	public function set_status($data){
		$this->message = $data;
	}
	
	public function get_status(){
		return $this->message;
	}

	// to be defined at run time
	abstract function process();
}

class MyBraintree extends PGFactory {

	public function __construct() {}

	public function process(){
		
		extract($_POST); // for local scope
		
		// expiry processing
		$cc_expiry_array = explode('-',$cc_expiry);
		
		$result = Braintree_Transaction::sale(array(
		    "amount" => $or_amount,
		    "creditCard" => array(
		        "number" => $cc_num,
		        "cvv" => $cc_cvv,
		        "expirationMonth" => $cc_expiry_array[0],
		        "expirationYear" => $cc_expiry_array[1]
		    ),
		    "options" => array(
		        "submitForSettlement" => true
		    )
		));
		
		if ($result->success) {
		    $data = "<h2>Braintree SUCCESS !</h2>Transaction ID: ".$result->transaction->id;
		    $status['response'] = $result->transaction->id;
		} else if ($result->transaction) {
			$data = "<h2>Braintree FAILURE</h2>Details:".$result->transaction->processorResponseCode;
			$status['response'] = $result->transaction->processorResponseCode;
		} else {
			
		    $str="";
		    foreach (($result->errors->deepAll()) as $error) {
		        $str."- " . $error->message . "<br/>";
		    }
		    
		    $data = "<h2>Braintree FAILURE</h2>Validation error(s): ".$str;
		    $status['response'] = $str;
		}
		
		$status['request'] = $_POST;
		$status['gateway'] = 'braintree';
		
		$this->log_activity($status);
		$this->set_status($data);
		
	}

}


class MyPayPal extends PGFactory {

	public function __construct() {}

	public function process(){

		extract($_POST); // for local scope

		// expiry processing
		$cc_expiry_array = explode('-',$cc_expiry);

		// ### CreditCard
		$card = new CreditCard();
		$card->setType("visa");
		$card->setNumber($cc_num);
		$card->setExpire_month($cc_expiry_array[0]);
		$card->setExpire_year($cc_expiry_array[1]);
		$card->setCvv2($cc_cvv);
		$card->setFirst_name($or_fname);
		$card->setLast_name($or_sname);

		// ### FundingInstrument
		$fi = new FundingInstrument();
		$fi->setCredit_card($card);

		// ### Payer
		$payer = new Payer();
		$payer->setPayment_method("credit_card");
		$payer->setFunding_instruments(array($fi));

		// ### Amount
		$amount = new Amount();
		$amount->setCurrency($or_currency);
		$amount->setTotal($or_amount);

		// ### Transaction
		$transaction = new Transaction();
		$transaction->setAmount($amount);
		$transaction->setDescription("Credit card payment");

		// ### Payment
		$payment = new Payment();
		$payment->setIntent("sale");
		$payment->setPayer($payer);
		$payment->setTransactions(array($transaction));

		// ### Api Context
		$apiContext = new ApiContext($cred, 'Request' . time());

		// ### Create Payment
		// The return object contains the status;
		try {
			$payment->create($apiContext);
			$data = "<h2>Paypal SUCCESS !</h2>Transaction ID: ".$payment->getId();
			$status['response'] = $payment->getId();
		} catch (\PPConnectionException $ex) {
			$data = "<h2>Paypal FAILURE</h2>Details:".$ex->getMessage() . $ex->getData();
			$status['response'] = $ex->getMessage() . $ex->getData();		
		}

		$status['request'] = $_POST;
		$status['gateway'] = 'paypal';
		
		$this->log_activity($status);
		$this->set_status($data);
	}
}

if($_POST){
	
	extract($_POST);
	
	// business logic starts here ...
	
	/*
	 After submitting the form, use a different gateway based on these rules:
	if credit card type is AMEX, then use Paypal.
	if currency is USD, EUR, or AUD, then use Paypal. Otherwise use Braintree.
	if currency is not USD and credit card is AMEX, return error message, 
	that AMEX is possible to use only for USD
	*/
	
	if($cc_type == 'AMEX' && $or_currency !== 'USD'){
		$errors[] = "AMEX can only be used with USD!";
	}else{
			// set Payment Gateway
			$currencies_array = array('USD','EUR','AUD');
			if($cc_type == 'AMEX' && in_array($or_currency,$currencies_array) ){
			   $cc_type = 'AMEX';
			}else{
			   $cc_type = 'OTHER'; // default
			}
			
			// input validation
			$val = new validation();
			if(!$errors = $val->validate()){
				
				switch ($cc_type){
					
					case "AMEX":
						$pg_type = PAYPAL;
						break; 
						
					case "OTHER":
						$pg_type = BRAINTREE;
						break;
						
					default:
						echo "Payment Gateway NOT recognised";die;
				}
				
				$pg_obj = PGFactory::getPGObject($pg_type);
				$pg_obj->process();
			
			}
	}
}
?>


<!DOCTYPE html>
<html>
<body>

<?php 

if($errors){

    echo "<p>There are input errors ...</p><ul>";
	foreach ($errors as $err){
		echo "<li>".$err."</li>";
	}
	echo "</ul>";
}

if($pg_obj){
   echo $pg_obj->get_status();
}

?>

<form method="post" action="#" autocomplete="on">
<h2>ORDER SECTION:</h2>

Amount:<br>
<input type="text" name="or_amount" value="<?=$or_amount;?>">
<br>

Currency:<br>
<select id="or_currency"  name="or_currency">
    <option  value="USD" <?=($or_currency == 'USD' ? 'selected="selected"' : '')?> >USD</option>
    <option  value="EUR" <?=($or_currency == 'EUR' ? 'selected="selected"' : '')?> >EUR</option>
    <option  value="THB" <?=($or_currency == 'THB' ? 'selected="selected"' : '')?> >THB</option>
    <option  value="HKD" <?=($or_currency == 'HKD' ? 'selected="selected"' : '')?> >HKD</option>
    <option  value="SGD" <?=($or_currency == 'SGD' ? 'selected="selected"' : '')?> >SGD</option>
    <option  value="AUD" <?=($or_currency == 'AUD' ? 'selected="selected"' : '')?> >AUD</option>   
</select>
<br><br>

Customer First name:<br>
<input type="text" name="or_fname" value="<?=$or_fname;?>">
<br>

Customer Surname:<br>
<input type="text" name="or_sname" value="<?=$or_sname;?>">
<br>




<h2>PAYMENT SECTION:</h2>

Credit card holder name:<br>
<input type="text" name="cc_name" value="<?=$cc_name;?>">
<br>

Credit Card Type:<br>
<select id="cc_type"  name="cc_type">
    <option  value="AMEX" <?=($cc_type == 'AMEX' ? 'selected="selected"' : '')?> >AMEX</option>
    <option  value="OTHER" <?=($cc_type == 'OTHER' ? 'selected="selected"' : '')?> >OTHER</option>  
</select>
<br><br>

Credit card number:<br>
<input type="text" name="cc_num" value="<?=$cc_num;?>">
<br>

Credit card expiry (FORMAT mm-yyyy):<br>
<input type="text" name="cc_expiry" value="<?=$cc_expiry;?>">
<br>

Credit card CVV:<br>
<input type="text" name="cc_cvv" value="<?=$cc_cvv;?>">
<br>



<input type="submit" name="submit">
</form>

</body>
</html>
