<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\RechargeTrait;

class BkashTransactionController extends Controller {

    use RechargeTrait;

    private $TokenizedPaymentCreateUrl = "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/create";
    private $CheckoutUrl = "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/execute";
    private $QueryPaymentUrl = "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/payment/status";
    private $SearchPaymentUrl = "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/general/searchTransaction";
    private $DeductPercent = 1.5;

    public function __construct() {
        $this->middleware('auth');
    }

    public function addRecharge(Request $request) {
		//Receive request of transaction initiate. Better use post request to this method
        $Required = [
            'amount' => 'required|numeric|min:10|max:50000',
        ];
        $Message = [
            'amount.required' => 'You must enter recharge amount.',
            'amount.numeric' => 'Recharge amount must be number.',
            'amount.min' => 'Recharge amount must be minimum 1000.',
            'amount.max' => 'Recharge amount can be maximum 50,000.',
        ];

        $request->validate($Required, $Message);

        $DeductRecharge = \Auth::user()->getCompany->deduct_online_charge; //should user bear the transaction charge or not from database

        $Insert = new \App\RechargeTransaction();

        $Insert->amount = $request->amount;
        $Insert->deduct_charge = $DeductRecharge;
        $Insert->user_id = auth()->id();
        $Insert->status = 'processing';

        if ($Insert->save()) {
            $Invoice = 'bkash-sms-recharge-' . $Insert->id;
            $Insert->geteway_id = $Invoice;
            if ($Insert->save()) {
                $Data = $this->Init_Payment($request->amount, $Invoice, $Insert->id);



                if ($Data == false) {
                    return back()->with('error', "Error while processing payment initiate.");
                }

                if (!isset($Data['statusCode'])) {
                    return back()->with('error', $Data['message']);
                }

                if ($Data['statusCode'] != '0000') {
                    return back()->with('error', $Data['statusMessage']);
                }


                $Insert->gateway_transaction_id = $Data['paymentID'];

                $Insert->save();


                return redirect($Data['bkashURL']);
            }
        }
    }

    public function Init_Payment($Amount, $Invoice, $id) {

        $Token = \App\Setting::where('setting_name', 'bkash_token')->first(); //token from DB
        $Token = $Token->setting_value;

        $id = $this->encrypt($id); //Encryption of the ID to disguise to user from URL hack


        //$Token = $this->Grand_Token();
        //$Token = $Token['id_token'];

        $AppKey = \App\Setting::where('setting_name', 'bkash_app_key')->first(); //App key from DB
        $Header = [
            "Accept: application/json",
            "Content-Type: application/json",
            "Authorization: " . $Token,
            "X-APP-Key: " . $AppKey->setting_value
        ];
        $PostData = [
            'mode' => "0011",
            "payerReference" => $Invoice, //unique transaction id of our end to bkash end
            'callbackURL' => route('Bkash_Callback', $id), //callback of success and error of payment
            'amount' => $Amount,
            'currency' => "BDT",
            'intent' => "sale",
            'merchantInvoiceNumber' => $Invoice,
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->TokenizedPaymentCreateUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($PostData),
            CURLOPT_HTTPHEADER => $Header,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);


        curl_close($curl);

        if ($err) {
            return false;
        } else {
            $Data = json_decode($response, true);

            return $Data;
        }
    }

    public function call_back(Request $request, $id) {

        $id = $this->decrypt($id); //decrypting encrypted ID in url

        $PaymentId = $request->paymentID; //bkash given payment id
        $PaymentStatus = $request->status; //bkash given payment status

        $Data = \App\RechargeTransaction::find($id);

        if ($Data != null) { //Data found.
            if ($Data->gateway_transaction_id == $PaymentId) { //Database transaction id and given id match
                if ($PaymentStatus == 'success') { //payment is success
					//If payment is successs always execute payment at bkash end
                    $ExecutePayment = $this->Execute_Payment($PaymentId);

                    $this->Query_Payment($PaymentId); //search payment from bkash end to verify the status


                    if ($ExecutePayment['statusCode'] == '0000' && $ExecutePayment['statusMessage'] == 'Successful') {

                        if ((float) $Data->amount == (float) $ExecutePayment['amount']) { //amount matched
                            \DB::beginTransaction();

                            try {
                                $Data->status = 'success';
                                $Data->received_amount = $Data->amount * (100 - $this->DeductPercent) / 100;
                                $Data->gateway_message = 'Bkash-Online-Own-Server';
                                $Data->server_response = json_encode($ExecutePayment);
                                $Data->save();

                                $RechargeId = $this->Recharge_Account($Data->amount, $Data->received_amount, $Data->user_id, 'Bkash-Online', "Bkash Recharge Online via Shiram SMS", $this->BkashAmountReceiveId);

                                $Data->recharge_id = $RechargeId;
                                $Data->save();
                                \DB::commit();
                            } catch (\Exception $ex) {
                                \DB::rollback();
                                return redirect(route('recharge.add'))->with('error', 'Transaction complete but cannot add recharge. System error. Contact admin.');
                            }

                            if ($Data->deduct_charge == 'Yes') {
                                $Fee = $Data->amount - $Data->received_amount;
                                $Msg = "Account recharge successful.<br/>Recharge Amount:  {$Data->received_amount}/-<br/>Gateway Fee: {$Fee}/-<br/>Total: <strong> {$Data->amount}</strong>/-";
                            } else {
                                $Msg = "Account recharge successful.<br/>Recharge Amount:  {$Data->amount}/-";
                            }
                            return redirect(route('recharge.add'))->with('success', $Msg);
                        } else {
                            //payment successful but payment amount did not match.
                        }

//                        "statusCode" => "0000"
//                        "statusMessage" => "Successful"
//                        "paymentID" => "TR0011AA1636734779639"
//                        "payerReference" => "bkash-sms-recharge-1811"
//                        "customerMsisdn" => "01770618575"
//                        "trxID" => "8KC7056803"
//                        "amount" => "1000"
//                        "transactionStatus" => "Completed"
//                        "paymentExecuteTime" => "2021-11-12T22:35:36:681 GMT+0600"
//                        "currency" => "BDT"
//                        "intent" => "sale"
//                        "merchantInvoiceNumber" => "bkash-sms-recharge-1811"
                        //$this->Query_Payment($PaymentId);
                    } else {
                        $Error = $ExecutePayment['statusMessage'];
                        return redirect(route('recharge.add'))->withErrors('Cannot recharge.' . $Error);
                    }
                } else { //payment is not successful
                    $this->Query_Payment($PaymentId);
                    return redirect(route('recharge.add'))->withErrors('Cannot recharge. Payment ' . $PaymentStatus);
                }
            }
        }
    }

    private function Execute_Payment($PaymentId) {
		//Execute payment at bkash end when payment is successful. This only do one time otherwise will return error
        $Token = \App\Setting::where('setting_name', 'bkash_token')->first();
        $Token = $Token->setting_value;


        $AppKey = \App\Setting::where('setting_name', 'bkash_app_key')->first();
        $Header = [
            "Accept: application/json",
            "Content-Type: application/json",
            "Authorization: " . $Token,
            "X-APP-Key: " . $AppKey->setting_value
        ];
        $PostData = [
            'paymentID' => $PaymentId,
        ];



        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->CheckoutUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($PostData),
            CURLOPT_HTTPHEADER => $Header,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return false;
        } else {
            return json_decode($response, true);
        }
    }

    private function Query_Payment($PaymentId) {
		//Search payment at bkash end with bkash given transaction id
        $Token = \App\Setting::where('setting_name', 'bkash_token')->first();
        $Token = $Token->setting_value;


        $AppKey = \App\Setting::where('setting_name', 'bkash_app_key')->first();
        $Header = [
            "Accept: application/json",
            "Content-Type: application/json",
            "Authorization: " . $Token,
            "X-APP-Key: " . $AppKey->setting_value
        ];
        $PostData = [
            'paymentID' => $PaymentId,
        ];


        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->QueryPaymentUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($PostData),
            CURLOPT_HTTPHEADER => $Header,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            dd($err);
            return false;
        } else {
            dd($response);
        }
    }

    private function Search_Transaction($LocalPaymentId) {
		//Search transcation at bkash end using our generated unique payment id given to bkash at initiate payment
		
        $Token = \App\Setting::where('setting_name', 'bkash_token')->first();
        $Token = $Token->setting_value;


        $AppKey = \App\Setting::where('setting_name', 'bkash_app_key')->first();
        $Header = [
            "Accept: application/json",
            "Content-Type: application/json",
            "Authorization: " . $Token,
            "X-APP-Key: " . $AppKey->setting_value
        ];
        $PostData = [
            'trxID' => $LocalPaymentId,
        ];
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->SearchPaymentUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($PostData),
            CURLOPT_HTTPHEADER => $Header,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return false;
        } else {
            dd($response);
        }
    }

    public function Grand_Token() {

        $Username = \App\Setting::where('setting_name', 'bkash_username')->first();
        $Password = \App\Setting::where('setting_name', 'bkash_password')->first();
        $AppKey = \App\Setting::where('setting_name', 'bkash_app_key')->first();
        $AppSecret = \App\Setting::where('setting_name', 'bkash_app_secret')->first();

        $Data = [
            'app_key' => $AppKey->setting_value,
            'app_secret' => $AppSecret->setting_value,
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($Data),
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Content-Type: application/json",
                "password: " . $Password->setting_value,
                "username: " . $Username->setting_value,
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return $err;
        } else {
            return json_decode($response, true);
        }
    }

    private function encrypt($id) {
		//encrypt id 
        return time() . "." . $id . "." . rand(1000000, 100000000);
    }

    private function decrypt($id) {
		//decrypt id
        list($timestamp, $origina_id, $random) = explode(".", $id);
        return $origina_id;
    }

}
