<?php

// Define module version for release build tool
//MODULE_VERSION,'1.0';

use WHMCS\Database\Capsule;



function webmoney_config() 
{
   $wm = new WebMoney();
   return $wm->whmcs_config();
}

function webmoney_link($params) 
{
    $wm = new WebMoney();
    return $wm->whmcs_buylink($params);
}

class WebMoney                                                                                                                                              
{
    private $GATEWAY;
    private $purse;
    private $amount;
    private $simmode;
    private $invDesc;
    private $PayerPurse;
    private $PayerWMID;
    private $invoiceid;
    private $WMT_invoiceid;
    private $WMT_transactionid;
    private $DateTime;
    private $hash;
    private $isActive=false;
    
    public function __construct() 
    {
        try{
            $this->GATEWAY = getGatewayVariables('webmoney');
        }catch(Exception $e){

        }
        $this->isActive=$this->GATEWAY["type"]?true:false;

        $this->purse = $_REQUEST["LMI_PAYEE_PURSE"];
        $this->amount = $_REQUEST["LMI_PAYMENT_AMOUNT"];
        $this->simmode = $_REQUEST["LMI_MODE"];
        $this->invDesc = $_REQUEST["LMI_PAYMENT_DESC"];
        $this->PayerPurse = $_REQUEST["LMI_PAYER_PURSE"];
        $this->PayerWMID = $_REQUEST["LMI_PAYER_WM"];
        $this->invoiceid = $_REQUEST["LMI_PAYMENT_NO"];
        $this->WMT_invoiceid = $_REQUEST['LMI_SYS_INVS_NO'];
        $this->WMT_transactionid = $_REQUEST['LMI_SYS_TRANS_NO'];
        $this->DateTime = $_REQUEST['LMI_SYS_TRANS_DATE'];
        $this->hash = $_REQUEST['LMI_HASH'];
    }
    
    public function getCurrency($curCode=null)
    {
        $currencies = localAPI("getcurrencies",array(),$this->GATEWAY['whmcs_admin']);
        if ($currencies && $currencies['result'] === 'success')
        {
            foreach ($currencies['currencies']['currency'] as $currency) 
            {
                if($curCode){
                    if($curCode === $currency['code']){ return $currency;}
                }
                else if($currency['rate'] === '1.00000') {return $currency;}
            }
        }
        else {
            throw new \Exception('getcurrencies ERROR:: '.  print_r($currencies,true));
        }
    }
    
    public function whmcs_config()
    {
        $configarray = Array();
        $configarray["FriendlyName"] = array("Type" => "System", "Value"=>"WebMoney");

            $currencies = localAPI("getcurrencies", array(), $this->GATEWAY['whmcs_admin']);
            if ($currencies && $currencies['result'] === 'success') {
                foreach ($currencies['currencies']['currency'] as $currency) {
                    $configarray["purse_" . $currency['code']] = array("FriendlyName" => "Purse " . $currency['code'],
                        "Type" => "text", "Size" => "13", "Description" => "Purse number for currency" . $currency['code'] . " (letter and 12 numbers)",);
                    $configarray["secretkey_" . $currency['code']] = array("FriendlyName" => "Purse secret code for currency " . $currency['code'],
                        "Type" => "text", "Size" => "30", "Description" => "Enter the secret code you have specified WM Transfer to a purse in " . $currency['code'],);
                }
            }


        $configarray["licensekey"] = array("FriendlyName" => "License Key", "Type" => "text", "Size" => "30");
        $configarray["whmcs_admin"] = array("FriendlyName" => "WHMCS Admin Login", "Type" => "text", "Size" => "30");
        return $configarray;
    }
    
    public function whmcs_buylink($params)
    {
        if(!$this->isLicenseValid()){
            return;
        }

        $invoiceid = $params['invoiceid'];
        $description = $params["description"];
        $amount = $params['amount'];   
        $paycurrency = $params['currency']; 
        $purse = $params['purse_'.$paycurrency];

        if ($purse === "")
        {
            $MAmount = $amount;
            $FExchangePurse = 1;

            $BaseCurrency = $this->getCurrency();

            if ($params['purse_'.$BaseCurrency['code']] !== "")
            {
                $purse = $params['purse_'.$BaseCurrency['code']]; 
            }
            else {
                die("The WebMoney purse number for the base currency is not specified!");
            }


            $currency = $this->getCurrency($paycurrency);
            $CurrencyRate = (float)$currency['rate'];


            if ($CurrencyRate) {
                $amount = round($amount / $CurrencyRate, 0);
            } else {
                die("Currency rate not found ".$paycurrency);
            }
        }   


   
        $PaymentDesc = base64_encode("Order #".$invoiceid." Client: ".$params['clientdetails']['lastname']." ".$params['clientdetails']['firstname']);
        $code = '<form method="POST" action="https://merchant.wmtransfer.com/lmi/payment.asp">';  
        $code .= '<input type="hidden" name="LMI_PAYMENT_AMOUNT" value="'.$amount.'">';
        $code .= '<input type="hidden" name="LMI_PAYMENT_DESC_BASE64" value="'.$PaymentDesc.'">';
        $code .= '<input type="hidden" name="LMI_PAYMENT_NO" value="'.$invoiceid.'">';
        $code .= '<input type="hidden" name="LMI_PAYEE_PURSE" value="'.$purse.'">';

        if ($FExchangePurse) 
        {
            $code .= '<input type="hidden" name="M_CURRENCY" value="'.$paycurrency.'">';
            $code .= '<input type="hidden" name="M_AMOUNT" value="'.$MAmount.'">';
        }
        $code .= '<input type="submit" value="Pay" />';
        $code .= '</form>';

        return $code;
    }
    
    public function merchantCallback()
    {
        if(!$this->isLicenseValid()){
            return;
        }

        switch ($_REQUEST['result']) {
            case "result": {
                $FAmountCorrect = true;
                $FPurseCorrect = true;

                $invoiceid = checkCbInvoiceID($this->invoiceid, $this->GATEWAY["name"]);

                $values['invoiceid'] = $this->invoiceid;
                $invoice = localAPI("getinvoice", $values, $this->GATEWAY['whmcs_admin']);

                $inv_amount = $invoice['total'];
                $my_amount = $inv_amount;


                if (isset($_REQUEST["M_CURRENCY"]))
                {

                    $currency = $this->getCurrency($_REQUEST["M_CURRENCY"]);
                    $CurrencyRate = (float)$currency['rate'];

                    $my_amount = round($my_amount / $CurrencyRate, 0);
                    $my_amount = number_format($my_amount, 2, ".", "");


                    if ($inv_amount !== $_REQUEST["M_AMOUNT"] || $my_amount !== $this->amount) {
                        $FAmountCorrect = false;
                        echo "Payment amount is incorrect";
                    }


                    $BaseCurrency = $this->getCurrency();
                    if ($this->GATEWAY['purse_' . $BaseCurrency['code']] !== $this->purse) {
                        $FPurseCorrect = false;
                        echo "Wallet number is incorrect";
                    }
                } else {

                    if ($my_amount !== $this->amount) {
                        $FAmountCorrect = false;
                        echo "Payment amount is incorrect";
                    }

                    if (!in_array($this->purse, $this->GATEWAY)) {
                        $FPurseCorrect = false;
                        echo "Wallet number is incorrect";
                    }
                }

                if ($_REQUEST["LMI_PREREQUEST"] === 1) {

                    $trans_desc = "Received a preliminary request for payment: ";
                    if ($FPurseCorrect && $FAmountCorrect) {
                        echo "YES";
                        $trans_desc .= "Successfully";
                    } else {
                        $trans_desc .= "Error (invalid wallet number and / or amount of payment)";
                    }
                } else {

                    $trans_desc = "Notification of payment: ";
                    if ($FPurseCorrect && $FAmountCorrect) {
                        $mode = 1;
                        if ($this->GATEWAY['simmode'] === "Выкл.") {
                            $mode = 0;
                        }
                        $SecretKeyField = "secretkey_" . substr(array_search($this->purse, $this->GATEWAY), -3, 3);
                        $SecretKey = $this->GATEWAY[$SecretKeyField];


                        $myhash = strtoupper(hash('sha256',
                            $this->purse .
                            $my_amount .
                            $invoiceid .
                            $mode .
                            $this->WMT_invoiceid .
                            $this->WMT_transactionid .
                            $this->DateTime .
                            $SecretKey .
                            $this->PayerPurse .
                            $this->PayerWMID));

                        if ($myhash === $this->hash) {
                            $trans_desc .= "Successfully";
                        } else {
                            $trans_desc .= "Error (invalid checksum)";
                        }
                    } else {
                        $trans_desc .= "Error (invalid wallet number and / or amount of payment)";
                    }
                }
                logTransaction($this->GATEWAY["name"], $_REQUEST, $trans_desc);
                break;
            }

            case "success": {
                checkCbTransID($this->WMT_transactionid);
                $values['invoiceid'] = $this->invoiceid;
                $invoice = localAPI("getinvoice", $values, $this->GATEWAY['whmcs_admin']);
                $inv_amount = $invoice['total'];

                addInvoicePayment($this->invoiceid, $this->WMT_transactionid, $inv_amount, 0, $this->GATEWAY["name"]);
                unset($values);

                $values["orderid"] = Capsule::table('tblclients')->where('invoiceid', $this->invoiceid)->value('id');

                $values["autosetup"] = true;
                $values["sendemail"] = true;

                $results = localAPI("acceptorder", $values, $this->GATEWAY['whmcs_admin']);

                $trans_desc = $this->GATEWAY['name'] . ' Payment receipt: Successful';
                logTransaction($this->GATEWAY["name"], $_REQUEST, $trans_desc);

                header('Location: ../../../viewinvoice.php?id=' . $this->invoiceid . '&paymentsuccess=true');

                exit();
                break;
            }

            case "fail": {

                $trans_desc = $this->GATEWAY['name'] . ' Payment receipt: Error';
                logTransaction($this->GATEWAY["name"], $_REQUEST, $trans_desc);

                header('' . 'Location: ../../../viewinvoice.php?id=' . $this->invoiceid);
                break;
            }
        }
    }


}
