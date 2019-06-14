<?php

ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
//error_reporting(~0);

//require 'Slim/Slim.php';
//\Slim\Slim::registerAutoloader();
require 'vendor/autoload.php';

//============== Headers To Enable Cors ======================================
// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');    // cache for 1 day
header("Access-Control-Allow-Headers:Cache-Control, Pragma, Origin, Authorization, Content-Type, X-Requested-With");

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    //header("Access-Control-Allow-Origin: *");
    exit(0);
}


require 'mssql/Sync.php';
require 'dashboard.php';
require 'mssql/Activities2.php';
require 'mssql/Companies.php';
require 'mssql/Contacts.php';
require 'mssql/ActivityTypes.php';
require 'mssql/CallCycle.php';
require 'mssql/Orders.php';
require 'mssql/Prices.php';
require 'mssql/Prices2.php';
require 'mssql/PriceLists.php';
require 'mssql/Users.php';
require 'mssql/ProductCategories2.php';
require 'mssql/DisplayFields.php';
require 'mssql/Discount.php';
require 'mssql/DiscountCondition.php';
require 'mssql/DiscountValues.php';
require 'mssql/UserCode.php';
require 'mssql/Tree.php';
require 'mssql/Stock.php';
require 'mssql/Addresses.php';
require 'utils/S3.php';
require 'utils/Dynamo.php';
require 'utils/MySQL.php';
require_once 'utils/Notification.php';
require_once 'utils/Workflow.php';
//require 'utils/XMLSerializer.php';
require 'redis/RedisTools.php';

$app = new \Slim\Slim();


//SMTP server config for sending emails
$app->config('SMTPServer', 'email-smtp.us-east-1.amazonaws.com');
$app->config('SMTPPort', 587);
$app->config('AuthenticationRequired', true);
$app->config('EnableSSL', true);
$app->config('FromEmail', 'noreply@rapidtrade.biz');
$app->config('UserID', 'AKIAJFVEPZTPMTLXGWSA');
$app->config('Password', 'At9f8VcNXT5T1pGJuHMxpGZK7uvLR3BQG0mZTDNhGUJL');
$app->config('Domain', '');
$app->config('ServerURL', 'http://23.21.227.179');
$app->config('XSLFilePath', getcwd() . '/xslt/');
$app->config('XSLSALES', 'ORDER.xsl');
$app->config('XSLINVOI', 'ORDER.xsl');


$app->hook('slim.before.route',function() use ($app){
    $app->environment['PATH_INFO'] = strtolower($app->environment['PATH_INFO']);
});

/**
 * Authenticate the user, if not valid, throw an exception
 */
$app->hook('slim.before.dispatch',function() use ($app){
        try {
                $app->environment['PATH_INFO'] = strtolower($app->environment['PATH_INFO']);
                $userID = $app->request()->headers('PHP_AUTH_USER');
                $password = $app->request()->headers('PHP_AUTH_PW');
	if(strpos(strtolower($userID),"supergrp\\") > -1 ){
		return true;
	} 

        $conn = dbConnection::Connect();
        $usp_result = odbc_exec($conn, "Select SupplierID,UserID,Role,RepID From [users](nolock) Where UserId = '$userID' And PasswordHash = '$password'");
        if (odbc_fetch_array($usp_result)) {
            //$user = odbc_fetch_array($usp_result, 1);
            //Remember the user for 5 minutes in REDIS
            //$redis->set("apiUser|$userID", json_encode($user));
            //$redis->setTimeout("apiUser|$userID", 300);
            //$app->User = (object)$user;
            odbc_close($conn);
        } else {
            throw new Exception('Not a valid user');
        }

        } catch (Exception $ex){
                utility::handleEndPointException('Authenticate', __CLASS__, __METHOD__, $ex, false);
                throw new Exception('Not a valid user');
        }
});

/**
 * Initialisation for all Sync methods
 * @param $logInfo
 * @param $app
 * @param $supplierId
 * @param $userId
 * @param $version
 * @param $skip
 * @param $top
 * @param $format
 */
function initGetMethods($logInfo, $app, &$supplierId, &$userId, &$version, &$skip, &$top, &$format ){
    $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
    $request = $app->request();
    $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($request->get());
    logging::debugArray($requestParams);

    $supplierId  = utility::getParamValue($requestParams, 'supplierid');
    $userId  = utility::getParamValue($requestParams, 'userid');
    $version = utility::getParamValue($requestParams, 'version');
    $skip    = utility::getParamValue($requestParams, 'skip', 0);
    $top     = utility::getParamValue($requestParams, 'top');
    $format  = utility::getParamValue($requestParams, 'format', 'json');
    logging::info($logInfo . '|supplierid=' . $supplierId . '|userid=' . $userId . '|version=' . $version . '|skip=' . $skip . '|top=' . $top );
}

$app->get('/Orders/ReEmail', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();

        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $orderId    = utility::getParamValue($requestParams, 'orderid');
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $xslSheet   = utility::getParamValue($requestParams, 'xslsheet');
        $subject    = utility::getParamValue($requestParams, 'subject');
        $logo       = utility::getParamValue($requestParams, 'logo');
        $email       = utility::getParamValue($requestParams, 'email');

        $ordersFetcher = new Orders();
        $ordersFetcher->setApp($app);
        $result = $ordersFetcher->reEmail($supplierId, $orderId, $xslSheet, $subject, $logo, $email);

        $jsonResponse = array(
            "Status" => 200,
            "Message" => $result
        );
        echo json_encode($jsonResponse, JSON_NUMERIC_CHECK);
        logging::debug('Leaving /Orders/ReEmail normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/Order/ReEmail', __CLASS__, __METHOD__, $exc, false);
    }

});

$app->get('/Orders/GetOrderInfoAsXML', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/xml');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $orderID = utility::getParamValue($requestParams, 'orderid');
        logging::info('/Orders/GetOrderInfoAsXML|orderid=' . $orderID);

        $ordersFetcher = new Orders();
        $ordersFetcher->setApp($app);
        $resultXML = $ordersFetcher->getOrderInfoAsXML($orderID);
        $app->response->setStatus(200);
        echo $resultXML;
        logging::debug('Leaving /Orders/GetOrderInfoAsXML normally');
    } catch (Exception $e) {
        utility::handleEndPointException('/Orders/GetOrderInfoAsXML', __CLASS__, __METHOD__, $e, false);
    }
});

$app->get('/Users/ReadStaff', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $request = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($request->get());
        $supplierId    = utility::getParamValue($requestParams, 'supplierid');
        $userId        = utility::getParamValue($requestParams, 'userid');
        $skip          = utility::getParamValue($requestParams, 'skip', 0);
        $top           = utility::getParamValue($requestParams, 'top');
        $callback      = utility::getParamValue($requestParams, 'callback');
        $format        = utility::getParamValue($requestParams, 'format', 'json');
        logging::info('/Users/ReadStaff|supplierid=' . $supplierId . '|userid=' . $userId . '|skip=' . $skip . '|top=' . $top );

        $usersFetcher = new Users();
        $result = $usersFetcher->readStaff($supplierId, $userId, $skip, $top, null, $format);
        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::debug('Leaving /Users/ReadStaff normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Users/ReadStaff', __CLASS__, __METHOD__, $exception, false);
    }
});

$app->get('/Users/ForgotPassword', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $request = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($request->get());
        $userId        = utility::getParamValue($requestParams, 'userid');
        $format        = utility::getParamValue($requestParams, 'format', 'json');
        logging::info('/Users/ForgotPassword2|userid=' . $userId );

		$users = new Users();
		$user = $users->getUser($userId);
		if (!empty($user)) {
			if (empty($user['Email'])) {
				$response = "A valid e-mail address is not defined for the user. Please call support to fix the issue.";
			} else {
				$email_addresses = explode(',',$user['Email'] );
				$mail = new PHPMailer;
				$mail->isSMTP();                                      		// Set mailer to use SMTP
				$mail->Host = $app->config('SMTPServer');  					// Specify main and backup SMTP servers
				$mail->SMTPAuth = $app->config('AuthenticationRequired');   // Enable SMTP authentication
				$mail->Username = $app->config('UserID');                 	// SMTP username
				$mail->Password = $app->config('Password');                 // SMTP password
				$mail->SMTPSecure = 'TLS';                            		// Enable TLS encryption, `ssl` also accepted
				$mail->Port = $app->config('SMTPPort');
				$mail->setFrom($app->config('FromEmail'));
				for($i = 0; $i < count($email_addresses); $i++){
		            if($i == 0)
		                $mail->addAddress($email_addresses[$i]);
		            else
		             $mail->AddCC($email_addresses[$i]);
		        }
				$mail->isHTML(false);
				$mail->Subject = "RapidTrade - ForgotPassword";
				$mail->Body = "Your password is " . $user['PasswordHash'];

				$response = "";

				if(!$mail->send()) {
					$response = "An error occurred on sending you the email";
					logging::error($mail->ErrorInfo, __CLASS__, __METHOD__);
				} else {
					$response = "Yor password has been sent successfully";
				}
			}
		} else {
			$response = "Couldn't find user data for entered one. Please check it and try again.";
		}
		echo $response;

    } catch (Exception $exception) {
        utility::handleEndPointException('/Users/ForgotPassword2', __CLASS__, __METHOD__, $exception, false);
    }
});

$app->get('/Users/VerifyPassword', function () use ($app) {
    try {
    	$app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userID = utility::getParamValue($requestParams, 'userid');
        $password = utility::getParamValue($requestParams, 'password');
        $app_name = utility::getParamValue($requestParams, 'appname');
        logging::info('/Users/VerifyPassword|userid=' . $userID . '|password=' . $password . '|appname=' . $app_name );

        $Constr = dbConnection::Connect();
        $validApp =  utility::ProccessDbData(odbc_exec($Constr, "{CALL  Supplier_GetApps(". "'$app_name'". "," . "'$userID'" .  ")}"))[0]['isValid'];
		$userFetcher = new Users();
        $user = $userFetcher->getUser($userID);
        if ($validApp == 1){
            if (empty($user)) {
                $JSON = array("ErrorMessage" => "You have entered incorrect login details", "Status" => false, "UserInfo" => []);
                echo json_encode($JSON);
            } else {
                 if(isset($user['PasswordSalt']) && (strpos(strtoupper($user['PasswordSalt']),'ACTIVEDIR') > -1 || strpos(strtoupper($user['PasswordSalt']),'JWT') > -1 )){
                    logging::info('Entering Active Directory VerifyUser');
                    $roles = split(",",$user['PasswordSalt']);
                    for($cnt = 0; $cnt < count($roles); $cnt++){
                        if(!strpos($roles[$cnt],'ACTIVEDIR') > -1 || !strpos(strtoupper($user['PasswordSalt']),'JWT') > -1) continue;
                        $ADURL= '';
                        try{
                            $ADURL = strpos($roles[$cnt],'ACTIVEDIR') > -1 ? split("=",$roles[$cnt])[1] : $roles[$cnt];
                        } catch (Exception $exception) {
                            logging::error('Error Extracting Active Directory URL from:'. $role[$cnt]);
                        }
                    }
                    $resp = utility::ADAuthentication($ADURL,$userID,$password);
                    logging::info('Active Directory Auth Result: ' . $resp);
                    logging::info('Exiting Active Directory VerifyUser');
                    if(!$resp){
                        logging::info('Failed Active Directory Authentication');
                        echo json_encode(array("ErrorMessage" => "Active Directory Authentication failed", "Status" => false, "UserInfo" => '[]'));
                        return;
                    }
                } else if ($user['PasswordHash'] !== $password){
                    $output = array("ErrorMessage" => "Wrong password....", "Status" => false, "UserInfo" => []);
                    echo json_encode($output);
                    return;
                }  

                $user['PasswordHash'] = '';
                //The roles are ok not. So we do not need the PasswordSalrt anymore
                //$user['Role'] = $user['PasswordSalt']; //only here as role used to be stored in passwordsalt, wont be needed going forard
                $JSON = array("ErrorMessage" => NULL, "Status" => true, "UserInfo" => $user);
                echo json_encode($JSON);
                #Blank the Password When Sending it in JSON
            }
        } else {
            $output = array("ErrorMessage" => "'Supplier not authorised on this application. Please contact them to obtain the proper application.", "Status" => false, "UserInfo" => []);
            echo json_encode($output);
            return;
        }
        
        logging::debug('Leaving /Users/VerifyPassword normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Users/VerifyPassword', __CLASS__, __METHOD__, $exception, false);
    }
});

$app->get('/Companies/Sync2', function () use ($app) {
    try {
        initGetMethods('/Companies/Sync2', $app, $supplierId, $userId, $version, $skip, $top, $format );

        if (RedisTools::hasCache($supplierId, 'Accounts', $userId,$skip) == false) {
            $companiesFetcher = new Companies();
            echo $companiesFetcher->sync2($userId, $version, $skip, $top, null, $format);
        } else {
            echo RedisTools::fetchRedisViaUserID($supplierId, 'Accounts', $userId, $skip, $top, $version);
        }
        logging::debug('Leaving /Companies/Sync2 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Companies/Sync2', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/Companies/Get', function () use ($app) {
    try {
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $supplierID = utility::getParamValue($requestParams, 'supplierid');
        $id = utility::getParamValue($requestParams, 'id');
        logging::info('/Companies/Get|supplierid=' . $supplierID . '|id=' . $id );
        $companiesFetcher = new Companies();
        echo $companiesFetcher->getCompany($supplierID, $id, null, 'json');
        logging::debug('Leaving /Companies/Get normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Companies/Sync2', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/UserCode/Sync4', function() use($app) {
    try {
        initGetMethods('/UserCode/Sync4', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $userCodeFetcher = new UserCode();
        $result = $userCodeFetcher->sync4($supplierId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /UserCode/Sync4 normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/UserCode/Sync4', __CLASS__, __METHOD__, $exc, true);
    }

});

$app->get('/Stock/Sync4', function () use ($app) {
    try {
        initGetMethods('/Stock/Sync4', $app, $supplierId, $userId, $version, $skip, $top, $format );
        if (RedisTools::hasCache($supplierId, 'Stock', $userId,$skip) == false) {
            $stockFetcher = new Stock();
            echo $stockFetcher->sync4($supplierId, $userId, $version, $skip, $top, null, $format);
        } else {
            echo RedisTools::fetchFromRedisUserKeys($supplierId, 'Stock', $userId, $skip, $top, $version);
        }
        logging::debug('Leaving /Stock/Sync4 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Stock/Sync4', __CLASS__, __METHOD__, $exception, true);
    }

});

$app->get('/Stock/WarehouseSync4', function () use ($app) {
    try {
        initGetMethods('/Stock/WarehouseSync4', $app, $supplierId, $userId, $version, $skip, $top, $format );
        $request = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($request->get());
        $warehouse  = utility::getParamValue($requestParams, 'warehouse');

        $stockFetcher = new Stock();
        echo $stockFetcher->warehouseSync4($supplierId, $warehouse, $version, $skip, $top, null, $format);
        logging::debug('Leaving /Stock/WarehouseSync4 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Stock/Sync4', __CLASS__, __METHOD__, $exception, true);
    }

});


$app->get('/Discount/Sync4', function () use ($app) {
    try {
        initGetMethods('/Discount/Sync4', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $discountFetcher = new Discount();
        $result = $discountFetcher->sync4($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /Discount/Sync4 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Discount/Sync4', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/DiscountValues/Sync4', function () use ($app) {
    try {
        initGetMethods('/DiscountValues/Sync4', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $discountValuesFetcher = new DiscountValues();
        $result = $discountValuesFetcher->sync4($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /DiscountValues/Sync4 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/DiscountValues/Sync4', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/DiscountCondition/Sync4', function () use ($app) {
    try {
        initGetMethods('/DiscountCondition/Sync4', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $discountConditionFetcher = new DiscountCondition();
        $result = $discountConditionFetcher->sync4($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /DiscountCondition/Sync4 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/DiscountCondition/Sync4', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/Pricelists/Sync5', function () use ($app) {
    try {
        initGetMethods('/Pricelist/Sync5', $app, $supplierId, $userId, $version, $skip, $top, $format );
        if (RedisTools::hasCache($supplierId, 'Pricelist', $userId,$skip) == false) {
            $priceListsFetcher = new PriceLists();
            echo $priceListsFetcher->sync5($supplierId, $userId, $version, $skip, $top, null, $format);
        } else {
            echo RedisTools::fetchFromRedisUserKeys($supplierId, 'Pricelist', $userId, $skip, $top, $version);
        }
        logging::debug('Leaving /Pricelists/Sync5 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Pricelists/Sync5', __CLASS__, __METHOD__, $exception, true);
    }

});

$app->get('/Pricelists/SyncBig', function () use ($app) {
    try {
        initGetMethods('/Pricelist/SyncBig', $app, $supplierId, $userId, $version, $skip, $top, $format );

        if (RedisTools::hasCache($supplierId, 'Pricelist', $userId,$skip) == false) {
            $priceListsFetcher = new PriceLists();
            echo $priceListsFetcher->syncBig($supplierId, $userId, $version, $skip, $top, null, $format);
        } else {
            echo RedisTools::fetchFromRedisUserKeys($supplierId, 'Pricelist', $userId, $skip, $top, $version);
        }
        logging::debug('Leaving /Pricelists/SyncBig normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Pricelists/SyncBig', __CLASS__, __METHOD__, $exception, true);
    }

});

$app->get('/ProductCategories2/Sync2', function () use ($app) {
    try {
        initGetMethods('/ProductCategories2/Sync2', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $productCategoriesFetcher = new ProductCategories2();
        $result = $productCategoriesFetcher->sync2($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /ProductCategories2/Sync2 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/ProductCategories2/Sync2', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/ProductCategories2/Sync3', function () use ($app) {
    try {
        initGetMethods('/ProductCategories2/Sync3', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $productCategoriesFetcher = new ProductCategories2();
        $result = $productCategoriesFetcher->sync3($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /ProductCategories2/Sync3 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/ProductCategories2/Sync3', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/Contacts/Sync2', function () use ($app) {
    try {
        initGetMethods('/Contacts/Sync2', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $contactsProcessor = new Contacts();
        $result = $contactsProcessor->sync2($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /Contacts/Sync2 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Contacts/Sync2', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/Address/Sync4', function () use ($app) {
    try {
        initGetMethods('/Address/Sync4', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $addressesProcessor = new Addresses();
        $result = $addressesProcessor->sync4($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /Address/Sync4 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Address/Sync4', __CLASS__, __METHOD__, $exception, true);
    }

});

$app->get('/DisplayFields/Sync2', function () use ($app) {
    try {
        initGetMethods('/DisplayFields/Sync2', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $displayFieldsProcessor = new DisplayFields();
        $result = $displayFieldsProcessor->sync2($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /DisplayFields/Sync2 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/DisplayFields/Sync2', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/CallCycle/Sync', function () use ($app) {
    try {
        initGetMethods('/CallCycle/Sync', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $callCycleFetcher = new CallCycle();
        $result = $callCycleFetcher->sync($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /CallCycle/Sync normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/CallCycle/Sync', __CLASS__, __METHOD__, $exc, true);
    }
});

$app->get('/Options/Sync2', function () use ($app) {
    try {
        initGetMethods('/Options/Sync2', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $optionsFetcher = new Options();
        $result = $optionsFetcher->sync2($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /Options/Sync2 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Options/Sync2', __CLASS__, __METHOD__, $exception, true);
    }

});

$app->get('/Tpm/Sync2', function () use ($app) {
	try {
		initGetMethods('/Sync2', $app, $supplierId, $userId, $version, $skip, $top, $format );
		$req = $app->request();
		$storedProc = 'usp_tpm_sync2';
		$table = 'TPM';
		logging::info("/Sync $storedProc | $table ");

		$synchronizer = new Sync();
		echo $synchronizer->genericSyncResponse($storedProc,$table, true, $supplierId, true, $userId, $version, true, $skip, $top, null, 'json');

		logging::debug('Leaving /GetStoredProc/Sync normally');
	} catch (Exception $exc) {
		utility::handleEndPointException('/GetStoredProc/Sync', __CLASS__, __METHOD__, $exc, true);
	}
});

$app->get('/ActivityTypes/GetCollection', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $request = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($request->get());
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $skip       = utility::getParamValue($requestParams, 'skip', 0);
        $top        = utility::getParamValue($requestParams, 'top');
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        $callback   = utility::getParamValue($requestParams, 'callback');

        logging::info('/ActivityTypes/GetCollection|supplierid=' . $supplierId . '|userid=' . $userId . '|skip=' . $skip . '|top=' . $top );

        $activityTypesFetcher = new ActivityTypes();
        $result = $activityTypesFetcher->getCollection($supplierId, $skip, $top, null, $format);
        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::debug('Leaving /ActivityTypes/GetCollection normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/ActivityTypes/GetCollection', __CLASS__, __METHOD__, $exception, false);
    }
});

$app->get('/ActivityTypes/Sync4', function () use ($app) {
    try {
        initGetMethods('Entering /ActivityTypes/Sync4', $app, $supplierId, $userId, $version, $skip, $top, $format );

        $activityTypesProcessor = new ActivityTypes();
        $result = $activityTypesProcessor->sync4($supplierId, $userId, $version, $skip, $top, null, $format);
        echo $result;
        logging::debug('Leaving /ActivityTypes/Sync4 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/ActivityTypes/Sync4', __CLASS__, __METHOD__, $exception, true);
    }
});

$app->get('/Dashboard/GetUsers', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userId     = utility::getParamValue($requestParams, 'userid');
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $callback   = utility::getParamValue($requestParams, 'callback');
        $format     = utility::getParamValue($requestParams, 'format', 'json');

        logging::info('/Dashboard/GetUsers|supplierid=' . $supplierId . '|userid=' . $userId  );

        $dashboardManager = new dashboard();
        $result = $dashboardManager->getUserSummary($supplierId, $userId, null, $format);
        $resultantJson = json_encode($result, JSON_NUMERIC_CHECK);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::debug('Leaving /Dashboard/GetUsers normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/Dashboard/GetUsers', __CLASS__, __METHOD__, $exc, false);
    }

});

$app->get('/Dashboard/GetActivities', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userId     = utility::getParamValue($requestParams, 'userid');
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $callback   = utility::getParamValue($requestParams, 'callback');
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        logging::info('/Dashboard/GetActivities|supplierid=' . $supplierId . '|userid=' . $userId  );

        $dashboardManager = new dashboard();
        $result = $dashboardManager->getActivities($supplierId, $userId, null, $format);
        $resultantJson = json_encode($result, JSON_NUMERIC_CHECK);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::debug('Leaving /Dashboard/GetActivities normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Dashboard/GetActivities', __CLASS__, __METHOD__, $exception, false);
    }
});

$app->get('/Dashboard/GetUserOrdering', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userId     = utility::getParamValue($requestParams, 'userid');
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        logging::info('/Dashboard/GetUserOrdering|supplierid=' . $supplierId . '|userid=' . $userId  );

        $dashboardManager = new dashboard();
        echo $dashboardManager->getOrderCountByUser($supplierId, $userId, null, $format);

        logging::debug('Leaving /Dashboard/GetUserOrdering normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/Dashboard/GetUserOrdering', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->get('/Activities2/GetCollection', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userId        = utility::getParamValue($requestParams, 'userid');
        $supplierId    = utility::getParamValue($requestParams, 'supplierid');
        $accountId     = utility::getParamValue($requestParams, 'accountid');
        $activityTypes = utility::getParamValue($requestParams, 'activitytypes');
        $fromDate      = utility::getParamValue($requestParams, 'fromdate');
        $toDate        = utility::getParamValue($requestParams, 'todate');
        $skip          = utility::getParamValue($requestParams, 'skip', 0);
        $top           = utility::getParamValue($requestParams, 'top');
        $userOnly      = (boolean) utility::getParamValue($requestParams, 'useronly', false);
        $format        = utility::getParamValue($requestParams, 'format', 'json');
        $callback      = utility::getParamValue($requestParams, 'callback');
        logging::info('/Dashboard/GetUserOrdering|supplierid=' . $supplierId . '|userid=' . $userId  . '|accountid=' . $accountId . '|activitytypes=' . $activityTypes . '|fromdate=' . $fromDate . '|todate=' . $toDate  . '|useronly=' . $userOnly     );

        $activitiesFetcher = new Activities2();
        $result = $activitiesFetcher->getCollection($supplierId, $userId, $accountId, $fromDate, $toDate, $activityTypes, $userOnly, $skip, $top, $format);

        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::debug('Leaving /Activities2/GetCollection normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/Activities2/GetCollection', __CLASS__, __METHOD__, $exc, false);
    }

});

$app->get('/Activities2/GetCollection2', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userId        = utility::getParamValue($requestParams, 'userid');
        $supplierId    = utility::getParamValue($requestParams, 'supplierid');
        $accountId     = utility::getParamValue($requestParams, 'accountid');
        $activityTypes = utility::getParamValue($requestParams, 'activitytypes');
        $includeReps   = utility::getParamValue($requestParams, 'includereps');
        $fromDate      = utility::getParamValue($requestParams, 'fromdate');
        $toDate        = utility::getParamValue($requestParams, 'todate');
        $skip          = utility::getParamValue($requestParams, 'skip', 0);
        $top           = utility::getParamValue($requestParams, 'top');
        $format        = utility::getParamValue($requestParams, 'format', 'json');
        $callback      = utility::getParamValue($requestParams, 'callback');

        logging::info('/Activities2/GetCollection2|supplierid=' . $supplierId . '|userid=' . $userId  . '|accountid=' . $accountId . '|activitytypes=' . $activityTypes . '|fromdate=' . $fromDate . '|todate=' . $toDate  . '|includereps=' . $includeReps     );

        $activitiesFetcher = new Activities2();
        $result = $activitiesFetcher->getCollection2($supplierId, $userId, $accountId, $fromDate,
            $toDate, $includeReps, $activityTypes, $skip, $top);
        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;
        logging::debug('Leaving /Activities2/GetCollection2 normally');
    } catch (Exception $e) {
        utility::handleEndPointException('/Activities2/GetCollection2', __CLASS__, __METHOD__, $e, false);
    }
});

$app->get('/callcycle/GetReport', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userId     = utility::getParamValue($requestParams, 'userid');
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $startDate  = utility::getParamValue($requestParams, 'startdate');
        $skip       = utility::getParamValue($requestParams, 'skip', 0);
        $top        = utility::getParamValue($requestParams, 'top');
        $format     = utility::getParamValue($requestParams, 'format', 'json');

        logging::info('/callcycle/GetReport|supplierid=' . $supplierId . '|userid=' . $userId . '|startdate=' . $startDate );

        $callCycleManager = new CallCycle();
        $result = $callCycleManager->getReport($supplierId, $userId, $startDate, $skip, $top, null, $format);
        $newResult = array();
        foreach ($result as $row) {
            $newRow = array();
            foreach ($row as $name => $value) {
                $newRow[ucfirst($name)] = $value;
            }
            $newResult[] = $newRow;
        }
        echo json_encode($newResult, JSON_NUMERIC_CHECK);
        logging::debug('Leaving /callcycle/GetReport normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/callcycle/GetReport', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->get('/CallCycle/GetCollection', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userId     = utility::getParamValue($requestParams, 'userid');
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $skip       = utility::getParamValue($requestParams, 'skip', 0);
        $top        = utility::getParamValue($requestParams, 'top');
        $format     = utility::getParamValue($requestParams, 'format', 'json');

        logging::info('/callcycle/GetCollection|supplierid=' . $supplierId . '|userid=' . $userId  );

        $callCycleManager = new CallCycle();
        $result = $callCycleManager->getCollection($supplierId, $userId, $skip, $top, null, $format);
        echo $result;

        logging::debug('Leaving /CallCycle/GetCollection normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/CallCycle/GetCollection', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->get('/Orders/GetOrderItemsByType3', function () use ($app) {
	try {
		$req = $app->request();
		$rslt = utility::callStoredProcedure($app, "usp_orderitems_readbytype3a");
		echo json_encode($rslt);
	} catch (Exception $exception) {
		utility::handleEndPointException('/Orders/GetOrderItems', __CLASS__, __METHOD__, $exception, false);
	}
});


$app->get('/Orders/GetUnpostedByType', function () use ($app) {
	try {
		$req = $app->request();
		$rslt = utility::callStoredProcedure($app, "usp_orders_readunpostedbytype");
		echo json_encode($rslt);
	} catch (Exception $exception) {
		utility::handleEndPointException('/Orders/GetOrderItems', __CLASS__, __METHOD__, $exception, false);
	}
});

$app->get('/Orders/GetOrderItems', function () use ($app) {
    try {
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $orderId    = utility::getParamValue($requestParams, 'orderid');
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $accountId  = utility::getParamValue($requestParams, 'accountid');
        $skip       = utility::getParamValue($requestParams, 'skip', 0);
        $top        = utility::getParamValue($requestParams, 'top');
        $callback   = utility::getParamValue($requestParams, 'callback');
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        logging::info("/callcycle/GetCollection|supplierid=$supplierId|orderId=$orderId|accountid=$accountId|skip=$skip|top=$top "  );

        $ordersFetcher = new Orders();
        $ordersFetcher->setApp($app);

        if (empty($format) || strtolower($format) === 'json') {
            $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
            $result = $ordersFetcher->getOrderItems($supplierId, $accountId, $orderId, $skip, $top, null, $format);
            $resultantJson = json_encode($result);
            if (!empty($callback)) {
                $resultantJson = $callback . '(' . $resultantJson . ')';
            }
            echo $resultantJson;
        } else {
            $app->response->headers->set('Content-Type', 'application/xml');
            $resultXML = $ordersFetcher->getOrderItemsAsXML($supplierId, $accountId, $orderId, $skip, $top);
            $app->response->setStatus(200);
            echo $resultXML;
        }
        logging::debug('Leaving /Orders/GetOrderItems normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Orders/GetOrderItems', __CLASS__, __METHOD__, $exception, false);
    }
});

/**
 * So far json format only.
 */
$app->get('/Orders/GetOrderItemsByType3', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $supplierId     = utility::getParamValue($requestParams, 'supplierid');
        $accountId      = utility::getParamValue($requestParams, 'accountid');
        $userId         = utility::getParamValue($requestParams, 'userid');
        $orderType      = utility::getParamValue($requestParams, 'ordertype');
        $orderStatus    = utility::getParamValue($requestParams, 'orderstatus');
        $callWeekNumber = utility::getParamValue($requestParams, 'callweeknumber');
        $callDayOfWeek  = utility::getParamValue($requestParams, 'calldayofweek');
        $orderId        = utility::getParamValue($requestParams, 'orderid');
        $skip           = utility::getParamValue($requestParams, 'skip', 0);
        $top            = utility::getParamValue($requestParams, 'top', 300);
        $format         = utility::getParamValue($requestParams, 'format', 'json');
        $callback       = utility::getParamValue($requestParams, 'callback');

        logging::info('/Orders/GetOrderItemsByType3|supplierid=' . $supplierId . '|userid=' . $userId . '|accountid=' . $accountId . '|ordertype=' . $orderType  . '|skip=' . $skip . '|top=' . $top   );

        $ordersFetcher = new Orders();
        $ordersFetcher->setApp($app);

        $result = $ordersFetcher->getOrderItemsByType3($supplierId, $accountId, $userId, $orderType,
            $orderStatus, $callWeekNumber, $callDayOfWeek, $orderId, $skip, $top, null, $format);
        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::debug('Leaving /Orders/GetOrderItemsByType3 normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/Orders/GetOrderItemsByType3', __CLASS__, __METHOD__, $exc, false);
    }
});

/*
 * TODO must complete logging changes from this point down
 */

$app->get('/Orders/GetCollection', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::info('Parameters:');
        logging::infoArray($requestParams);

        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $accountId  = utility::getParamValue($requestParams, 'accountid');
        $skip       = utility::getParamValue($requestParams, 'skip', 0);
        $top        = utility::getParamValue($requestParams, 'top');
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        $callback   = utility::getParamValue($requestParams, 'callback');
        logging::info("/Orders/GetCollection $supplierId | $accountId | $skip | $top");

        $ordersManager = new Orders();
        $result = $ordersManager->getCollection($supplierId, $accountId, $skip, $top, null, $format);
        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;
        logging::debug('Leaving /Orders/GetCollection normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/Orders/GetCollection', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->get('/getPrice2', function () use ($app) {
    try {
         $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $supplierID = utility::getParamValue($requestParams, 'supplierid');
        $productID = utility::getParamValue($requestParams, 'productid');
        logging::info("/getPrice2 $supplierId | $productID");

        $priceObj = new Prices();
        $priceObj->GetResourceData($supplierID, $productID);
        $priceObj->getPrice3();
    } catch (Exception $exception) {
        utility::handleEndPointException('/getPrice2', __CLASS__, __METHOD__, $exception, false);
    }
});

/**
 * Utility methods for quick database access
 */
$app->get('/GetView', function () use ($app) {
    try {
        logging::info('Entering /GetView');

        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $view   = utility::getParamValue($requestParams, 'view');
        $params = utility::getParamValue($requestParams, 'params');
        logging::info("/GetView:$view|$params");

        $conn = dbConnection::Connect();
        if (isset($params)) {
            $qryStr = 'select * from ' . $view . " " . $params;
            $ViewQuery = odbc_exec($conn, $qryStr);
            $Result_Array = utility::ProcessMultiDbData_v2($ViewQuery);
            echo json_encode($Result_Array, JSON_NUMERIC_CHECK);
        } else {
            $qryStr = 'select * from ' . $view;
            $ViewQuery = odbc_exec($conn, $qryStr);
            $Result_Array = utility::ProcessMultiDbData_v2($ViewQuery);
            echo json_encode($Result_Array, JSON_NUMERIC_CHECK);
        }
        logging::debug('Leaving /GetView normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/GetView', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->get('/GetCodeDescription', function () use ($app) {
    try {
        logging::info('Entering /GetCodeDescription');

        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::info('Parameters:');
        logging::infoArray($requestParams);

        $ViewName    = utility::getParamValue($requestParams, 'table');
        $code        = utility::getParamValue($requestParams, 'code');
        $description = utility::getParamValue($requestParams, 'description');
        $Params      = utility::getParamValue($requestParams, 'params');

        $conn = dbConnection::Connect();
        $result = odbc_exec($conn, "Select  $code as Code, $description as Description into #temp from $ViewName $Params order by $description; select * from #temp");
        $rslt_arr = Utility::ProcessMultiDbData_v2($result);
        echo Utility::DisplayView($rslt_arr);

        logging::debug('Leaving /GetCodeDescription normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/GetCodeDescription', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->post('/post/post.aspx', function () use ($app) {
    try {
        logging::post("Entering /post/post.aspx");

        //Using PHP's global $_POST to get the form data
        //as after internal rewrite it will still keep the
        // original form data. On the contrary, Slim's request
        //will not have that body.

        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');

        $table = $_POST["Table"];
        $method = $_POST["Method"];
        $json = $_POST["json"];

        logging::postArray(
            array(
                'Table'  => $table,
                'Method' => $method,
                'json'   => $json
            )
        );

        switch ($table) {
            case 'Orders':
                if (!empty($method) && strtolower($method) === 'modify2') {
                    $orderData = json_decode($json, true);
                    ordersModify2Function($app, $orderData);
                } else if (!empty($method) && strtolower($method) === 'modify3') {
                    $orderData = json_decode($json, true);
                    ordersModify3Function($app, $orderData);
                } else {
                    echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                    return;
                }
                break;
            case 'Activities':
                if (!empty($method)) {
                    $methodLowered = strtolower($method);
                    if ($methodLowered === 'modify') {
                        $eventInfo = json_decode($json, true);
                        $activitiesManager = new Activities2();
                        $activitiesManager->modify($app, $eventInfo);
                    } else {
                        echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                        return;
                    }
                } else {
                    echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                    return;
                }
                break;
            case 'File':
                if (!empty($method)) {
                	$methodLowered = strtolower($method);
                	if ($methodLowered === 'uploadimage') {
                		try {
                			logging::info("/PostImage");
                			$app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
                			$req = $app->request();
                			$obj = json_decode($json, true);
                			//Save to S3
                			$s3 = new S3();
                			$url = $s3->SendImage($obj['SupplierID'], $obj['Name'], base64_decode( $obj['FileData']));

                			//Save signature url to orderfiles
                			$sql_str = "{CALL usp_OrderFiles_modify(" . $obj['SupplierID'] . ",'signature','" . str_replace(".svgx", "", $obj['Name']) . "','$url')}";
                			$conn = dbConnection::Connect();
                			odbc_exec($conn, $sql_str);
                			$response = ["status" => true, "url" => $url];

                			//echo  json_encode( $response) ;
                		} catch (Exception $exc) {
                			utility::handlePOSTEndPointException('/Send', __CLASS__, __METHOD__, $exc, false);
                		}
                	} else {
                		echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                		return;
                	}
                } else {
                	echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                	return;
                }
                break;
          	case 'CallCycle':
                if (!empty($method)) {
                    $methodLowered = strtolower($method);
                    if ($methodLowered === 'modifyall') {
                        $callcycles = json_decode($json, true);
                        $callcycle = new CallCycle();
                        $callcycle->modify($app, $callcycles);
                    } else {
                        echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                        return;
                    }
                } else {
                    echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                    return;
                }
              	break;
            case 'Companies':
                if (!empty($method)) {
                    $methodLowered = strtolower($method);
                    if ($methodLowered === 'modify') {
                        $account = json_decode($json, true);
                        $companiesManager = new Companies();
                        $companiesManager->modify($account);
                    } else if ($methodLowered === 'modifyall') {
                        $accounts = json_decode($json, true);
                        $companiesManager = new Companies();
                        $companiesManager->modifyAll($accounts);
                    } else {
                        echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                        return;
                    }
                } else {
                    echo json_encode(baseResponse::getPostAspxUnexpectedMethodErrorResponse($app, $table, $method));
                    return;
                }
                break;
            default:
                $app->response->setStatus(400);
                echo json_encode(array(
                    'status' => 400,
                    'Error'  => "Unexpected table '$table'"
                ));
                return;
        }

        echo json_encode(baseResponse::getPostAspxSuccessResponse($app));
        logging::post("Leaving /post/post.aspx normally");
    } catch (Exception $exception) {
        $notification = new Notifications();
        $notification->EmailPostError("/post/post.aspx");
        utility::handlePOSTEndPointException("/post/post.aspx",
            __CLASS__, __METHOD__, $exception, false);

        $app->response->setStatus(500);
        echo json_encode(array(
            'status' => 500,
            'Error'  => $exception->getMessage()
        ));
    } finally {

    }

});

$app->post('/View/ModifyAll', function () use ($app) {
    try {
        logging::post('Entering /View/ModifyAll');

        header("Access-Control-Allow-Origin: *");
        //$app->contentType('text/html');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::post('Parameters:');
        logging::postArray($requestParams);

        $tblName = utility::getParamValue($requestParams, 'table');
        $type    = utility::getParamValue($requestParams, 'type');

        $conn    = dbConnection::Connect();

        $PostedData = json_decode($req->getBody());

        $usp_identity_qry = odbc_exec($conn, "{CALL  usp_get_IDENTITY_TABLES($tblName)}");
        $usp_identity_result = odbc_fetch_array($usp_identity_qry);
        $usp_column_list_result = odbc_exec($conn, "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$tblName'");
        $counter = 0;
        $column_list = [];
        while ($rows = odbc_fetch_array($usp_column_list_result)) {
            $column_list[$counter] = $rows['COLUMN_NAME'];
            $counter++;
        }

        var_dump($usp_identity_result);

        $usp_result = odbc_exec($conn, "{CALL  usp_get_Pkeys($tblName)}");
        $fieldName = [];
        $pk_Counter = 0; //Primary Key Result Counter
        while ($rows = odbc_fetch_array($usp_result)) {
            $fieldName[$pk_Counter] = $rows['COLUMN_NAME'];
            $pk_Counter++;
        }
        $ON_stmt_str = 'ON (';
        for ($i = 0; $i < count($fieldName); $i++) {
            if (count($fieldName) - $i === 1) {
                $ON_stmt_str .= "trgt.$fieldName[$i] = source.$fieldName[$i]) ";

            } else {
                $ON_stmt_str .= " trgt.$fieldName[$i] = source.$fieldName[$i]" . ' and ' . '';
            }
        }

        if (isset($PostedData)) {

            $InstQry = "Insert Into $tblName(";
            $InstValQry = "Values(";

            if ($type === 'update' || $type === 'insert') {
                $sqlMerge = "Merge $tblName as trgt using ( SELECT ";
                $sqlSource = 'as source (';
                $sqlWhenMatched = 'When Matched THEN UPDATE set ';
                $sqlWhenNotMatchedInsert = 'When Not Matched THEN INSERT ( ';
                $sqlWhenNotMatchedValues = 'VALUES ( ';
                $sql_stmt = '';

                $cased_column_list = array_map('strtolower', $column_list);

                foreach ($PostedData as $key => $val) {
                    if (in_array(strtolower($key), $cased_column_list)) {

                        if ($val === "") {
                            if ($key !== $usp_identity_result['COLUMN_NAME']) {
                                $sqlWhenNotMatchedValues .= " '',";
                            }
                            $sqlMerge .= " '',";
                        } else {
                            $sqlMerge .= " '$val',";
                            if ($key !== $usp_identity_result['COLUMN_NAME']) {
                                $sqlWhenNotMatchedValues .= "'$val',";
                            }
                        }
                        //$sqlMerge .= '?,';
                        //array_push($Params, $val);
                        $sqlSource .= " [$key],";
                        if ($key !== $usp_identity_result['COLUMN_NAME']) {
                            $sqlWhenMatched .= "[$key]=source.[$key],";
                            $sqlWhenNotMatchedInsert .= "[$key],";
                        }
                    }
                }

                $sqlWhenMatched = substr($sqlWhenMatched, 0, -1);
                $sqlMerge = substr($sqlMerge, 0, -1) . ') ';
                $sqlSource = substr($sqlSource, 0, -1) . ') ';
                $sqlWhenNotMatchedInsert = substr($sqlWhenNotMatchedInsert, 0, -1) . ') ';
                $sqlWhenNotMatchedValues = substr($sqlWhenNotMatchedValues, 0, -1) . ') ';

                $sql_stmt = $sqlMerge . $sqlSource . $ON_stmt_str . $sqlWhenMatched . ' ' . $sqlWhenNotMatchedInsert . $sqlWhenNotMatchedValues . '; ';
                echo $sql_stmt;
                $stmt = odbc_exec($conn, $sql_stmt);

            } else if ($type === 'delete') {
                $DelQry = "Delete From $tblName where ";
                $loopCounter = 1; //Check where in the Counting Primary Keys So That we can concatinate the and part of the statement
                foreach ($PostedData as $key => $val) {
                    if (in_array($key, $fieldName)) {
                        if($loopCounter === 1){
                            $DelQry .= " $key = '$val' ";
                            $loopCounter++;
                        }else{
                            $DelQry .= "and $key = '$val' ";
                            $loopCounter++;
                        }
                    }
                }#foreach
                echo $DelQry;
                odbc_exec($conn, $DelQry);
            }
        }#if

        logging::post('Leaving /View/ModifyAll normally');
    } catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/View/ModifyAll");
        utility::handlePOSTEndPointException('/View/ModifyAll', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->post('/Displayfields/ModifyAll', function () use ($app) {
    try {
        logging::post('Entering /Displayfields/ModifyAll');

        $app->contentType('text/json');

        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::post('Parameters:');
        logging::postArray($requestParams);

        $tblName = utility::getParamValue($requestParams, 'table');
        $resp = $app->response();
        $conn = dbConnection::Connect();
        $PostedData = json_decode($req->getBody());

        /**
         * This Following Section deals With the Fecth of the the Primary Keys
         * For the specific table sent in
         * where
         * $fieldName = The Array Holding the field Names of the primary key
         * $ON_stmt_str = Constructed string holding a part of the sql statement
         * $ON_stmt_str output Example = ON (trgt.PK1 = source.PK2 and trgt.PK2 = source.PK2 andtrgt.PK3 = source.PK3)
         */

        $usp_result = odbc_exec($conn, "{CALL  sp_pkeys($tblName)}");
        $fieldName = [];
        $pk_Counter = 0; //Primary Key Result Counter
        while ($rows = odbc_fetch_array($usp_result)) {
            $fieldName[$pk_Counter] = $rows['COLUMN_NAME'];
            $pk_Counter++;
        }
        $ON_stmt_str = 'ON (';
        for ($i = 0; $i < count($fieldName); $i++) {
            if (count($fieldName) - $i === 1) {
                $ON_stmt_str .= "trgt.$fieldName[$i] = source.$fieldName[$i]) ";

            } else {
                $ON_stmt_str .= " trgt.$fieldName[$i] = source.$fieldName[$i]" . ' and ' . '';
            }
        }

        /**
         * This Section Constructs A Merge Query From the posted Data
         * first loop, loops throught the The JSON converted to an object
         * Second loop loops through the individual JSON objects
         * $sqlMerge = This is the The first section of the The Sql String,
         * it will contain the Merge and Select Satement And The values for the Select Satement
         * $sqlSource = Contains The Source Section of the statement
         * $sqlWhenMatched = Contain the update statement when the Data is matched
         * $sqlWhenNotMatchedInsert and $sqlWhenNotMatchedValues Contain the The Values of the Insert Statement when data is not Matched
         **/

        if (isset($PostedData)) {
            foreach ($PostedData as $json_data) {
                //Initialization of the The String Var
                $sqlMerge = "Merge $tblName as trgt using ( SELECT ";
                $sqlSource = 'as source (';
                $sqlWhenMatched = 'When Matched THEN UPDATE set ';
                $sqlWhenNotMatchedInsert = 'When Not Matched THEN INSERT ( ';
                $sqlWhenNotMatchedValues = 'VALUES ( ';
                $sql_stmt = '';

                foreach ($json_data as $key => $val) {

                    if ($val === "") {
                        $sqlWhenNotMatchedValues .= " '',";
                        $sqlMerge .= " '',";
                    } else {
                        $sqlMerge .= " '$val',";
                        $sqlWhenNotMatchedValues .= "'$val',";
                    }
                    $sqlSource .= " $key,";
                    $sqlWhenMatched .= "$key=source.$key,";
                    $sqlWhenNotMatchedInsert .= "$key,";
                }
                $sqlWhenMatched = substr($sqlWhenMatched, 0, -1);
                $sqlMerge = substr($sqlMerge, 0, -1) . ') ';
                $sqlSource = substr($sqlSource, 0, -1) . ') ';
                $sqlWhenNotMatchedInsert = substr($sqlWhenNotMatchedInsert, 0, -1) . ') ';
                $sqlWhenNotMatchedValues = substr($sqlWhenNotMatchedValues, 0, -1) . ') ';

                $sql_stmt = $sqlMerge . $sqlSource . $ON_stmt_str . $sqlWhenMatched . ' ' . $sqlWhenNotMatchedInsert . $sqlWhenNotMatchedValues . '; ';
                $stmt = odbc_exec($conn, $sql_stmt);


            }#Top Level For Each
            $res = array('status' => true, 'resultString' => 'DisplayFields Successfully Saved', 'ErrorMsg' => '');
            $resp->write(json_encode($res));
        }

        logging::post('Leaving /Displayfields/ModifyAll normally');
    } catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/Displayfields/ModifyAll");
        utility::handlePOSTEndPointException('/Displayfields/ModifyAll', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->get("/Orders/Exists", function () use ($app) {
    try {

        $app->response->headers->set('Content-Type','application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $orderId = utility::getParamValue($requestParams, 'orderid');
        $format = utility::getParamValue($requestParams, 'format', 'json');
        logging::info("/Orders/Exists $supplierId | $orderId");

        $ordersManager = new Orders();
        echo $ordersManager->exists($supplierId, $orderId, null, $format);
        logging::info('Leaving /Orders/Exists normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Orders/Exists',
            __CLASS__, __METHOD__, $exception, false);
    }
});

$app->post("/orders/ModifyAll", function () use ($app) {
    try {
        logging::post('Entering /orders/ModifyAll');

        $app->contentType('text/json');

        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::post('Parameters:');
        logging::postArray($requestParams);

        $tblName = utility::getParamValue($requestParams, 'table');
        $resp = $app->response();

        $conn = dbConnection::Connect();
        $orderitems = [];

        $PostedData = json_decode($req->getBody());

        /**
         * This Following Section deals With the Fetch of the the Primary Keys
         * For the specific table sent in
         * where
         * $fieldName = The Array Holding the field Names of the primary key
         * $ON_stmt_str = Constructed string holding a part of the sql statement
         * $ON_stmt_str output Example = ON (trgt.PK1 = source.PK2 and trgt.PK2 = source.PK2 andtrgt.PK3 = source.PK3)
         */

        $usp_result = odbc_exec($conn, "{CALL  sp_pkeys($tblName)}");
        $fieldName = [];
        $pk_Counter = 0; //Primary Key Result Counter
        while ($rows = odbc_fetch_array($usp_result)) {
            $fieldName[$pk_Counter] = $rows['COLUMN_NAME'];
            $pk_Counter++;
        }
        $ON_stmt_str = 'ON (';
        for ($i = 0; $i < count($fieldName); $i++) {
            if (count($fieldName) - $i === 1) {
                $ON_stmt_str .= "trgt.$fieldName[$i] = source.$fieldName[$i]) ";

            } else {
                $ON_stmt_str .= " trgt.$fieldName[$i] = source.$fieldName[$i]" . ' and ' . '';
            }
        }

        /**
         * This Section Constructs A Merge Query From the posted Data
         * first loop, loops throught the The JSON converted to an object
         * Second loop loops through the individual JSON objects
         * $sqlMerge = This is the The first section of the The Sql String,
         * it will contain the Merge and Select Satement And The values for the Select Satement
         * $sqlSource = Contains The Source Section of the statement
         * $sqlWhenMatched = Contain the update statement when the Data is matched
         * $sqlWhenNotMatchedInsert and $sqlWhenNotMatchedValues Contain the The Values of the Insert Statement when data is not Matched
         **/

        if (isset($PostedData)) {

            //Initialization of the The String Var
            $sqlMerge = "Merge $tblName as trgt using ( SELECT ";
            $sqlSource = 'as source (';
            $sqlWhenMatched = 'When Matched THEN UPDATE set ';
            $sqlWhenNotMatchedInsert = 'When Not Matched THEN INSERT ( ';
            $sqlWhenNotMatchedValues = 'VALUES ( ';

            if (!array_key_exists('UserID', $PostedData) && !array_key_exists('BranchID', $PostedData)) {
                $SupplierID = $PostedData->SupplierID;
                $OrderID = $PostedData->OrderID;
                $sql = "Select BranchID,UserID from orders where SupplierID = '$SupplierID' and OrderID = '$OrderID' ";
                $sqlrslt = odbc_exec($conn, $sql);
                $rslt = odbc_fetch_array($sqlrslt);
                $PostedData->UserID = $rslt['UserID'];
                $PostedData->BranchID = $rslt['BranchID'];
            } elseif (!array_key_exists('UserID', $PostedData)) {

                $SupplierID = $PostedData->SupplierID;
                $OrderID = $PostedData->OrderID;
                $sql = "Select UserID from orders where SupplierID = '$SupplierID' and OrderID = '$OrderID' ";
                $sqlrslt = odbc_exec($conn, $sql);
                $rslt = odbc_fetch_array($sqlrslt);
                $PostedData->UserID = $rslt['UserID'];

            } elseif (!array_key_exists('BranchID', $PostedData)) {
                $SupplierID = $PostedData->SupplierID;
                $OrderID = $PostedData->OrderID;
                $sql = "Select BranchID from orders where SupplierID = '$SupplierID' and OrderID = '$OrderID' ";
                $sqlrslt = odbc_exec($conn, $sql);
                $rslt = odbc_fetch_array($sqlrslt);
                $PostedData->BranchID = $rslt['BranchID'];

            }

            foreach ($PostedData as $key => $val) {
                $sql_stmt = '';
                if (strtolower($key) === "orderitems") {
                    $orderitems = $val;
                    continue;
                }

                if ($val === "") {
                    $sqlWhenNotMatchedValues .= " '',";
                    $sqlMerge .= " '',";
                } else {
                    $sqlMerge .= " '$val',";
                    $sqlWhenNotMatchedValues .= "'$val',";
                }

                $sqlSource .= " $key,";
                $sqlWhenMatched .= "$key=source.$key,";
                $sqlWhenNotMatchedInsert .= "$key,";
            }

            $sqlWhenMatched = substr($sqlWhenMatched, 0, -1);
            $sqlMerge = substr($sqlMerge, 0, -1) . ') ';
            $sqlSource = substr($sqlSource, 0, -1) . ') ';
            $sqlWhenNotMatchedInsert = substr($sqlWhenNotMatchedInsert, 0, -1) . ') ';
            $sqlWhenNotMatchedValues = substr($sqlWhenNotMatchedValues, 0, -1) . ') ';
            $sql_stmt = $sqlMerge . $sqlSource . $ON_stmt_str . $sqlWhenMatched . ' ' . $sqlWhenNotMatchedInsert . $sqlWhenNotMatchedValues . '; ';
            $stmt = odbc_exec($conn, $sql_stmt);
            if (dbConnection::$isInfoOn) {
                logging::InfoLog(" ", " ", " Implementation ", "Index/Orders/Modify", $sql_stmt);
            }

            //Logic For Posting The Order Items To The Order Items Table In The Database
            if (count($orderitems)) {

                $usp_result = odbc_exec($conn, "{CALL  sp_pkeys(orderitems)}");
                $fieldName = [];
                $pk_Counter = 0; //Primary Key Result Counter

                while ($rows = odbc_fetch_array($usp_result)) {
                    $fieldName[$pk_Counter] = $rows['COLUMN_NAME'];
                    $pk_Counter++;
                }

                $ON_stmt_str = 'ON (';

                for ($i = 0; $i < count($fieldName); $i++) {
                    if (count($fieldName) - $i === 1) {
                        $ON_stmt_str .= "trgt.$fieldName[$i] = source.$fieldName[$i]) ";

                    } else {
                        $ON_stmt_str .= " trgt.$fieldName[$i] = source.$fieldName[$i]" . ' and ' . '';
                    }
                }#EndOfForLoop

                //foreach ($orderitems as $json_data ){
                for ($x = 0; $x < count($orderitems); $x++) {
                    //Reinitializing The Variable To Used In The Orderitems Section Of the Post
                    $sqlMerge = "Merge orderitems as trgt using ( SELECT ";
                    $sqlSource = 'as source (';
                    $sqlWhenMatched = 'When Matched THEN UPDATE set ';
                    $sqlWhenNotMatchedInsert = 'When Not Matched THEN INSERT ( ';
                    $sqlWhenNotMatchedValues = 'VALUES ( ';
                    //$Params = [];
                    $sql_stmt = '';

                    foreach ($orderitems[$x] as $key => $val) {
                        if ($val === "") {
                            $sqlWhenNotMatchedValues .= " '',";
                            $sqlMerge .= " '',";
                        } else {
                            $val = strtolower($key) == "userfield01" ? substr($val, 0, 50) : $val;
                            $val = str_replace("'", "", $val);
                            $sqlMerge .= " '$val',";
                            $sqlWhenNotMatchedValues .= "'$val',";
                        }
                        $sqlSource .= " $key,";
                        $sqlWhenMatched .= "$key=source.$key,";
                        $sqlWhenNotMatchedInsert .= "$key,";
                    }
                    $sqlWhenMatched = substr($sqlWhenMatched, 0, -1);
                    $sqlMerge = substr($sqlMerge, 0, -1) . ') ';
                    $sqlSource = substr($sqlSource, 0, -1) . ') ';
                    $sqlWhenNotMatchedInsert = substr($sqlWhenNotMatchedInsert, 0, -1) . ') ';
                    $sqlWhenNotMatchedValues = substr($sqlWhenNotMatchedValues, 0, -1) . ') ';

                    $sql_stmt = $sqlMerge . $sqlSource . $ON_stmt_str . $sqlWhenMatched . ' ' . $sqlWhenNotMatchedInsert . $sqlWhenNotMatchedValues . '; ';
                    if (dbConnection::$isInfoOn) {
                        logging::InfoLog(" ", " ", " Implementation OrderItems", "Index/Orders/Modify", $sql_stmt);
                    }
                    $stmt = odbc_exec($conn, $sql_stmt);

                }#Top For Loop

            }#if Orderitem.Count
            $res = array('status' => true, 'resultString' => 'Order Saved Ok', 'ErrorMsg' => '');
            $resp->write(json_encode($res));
        }#if Posted Data

        logging::post('Leaving /orders/ModifyAll normally');
    } catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/orders/ModifyAll");
        utility::handlePOSTEndPointException('/orders/ModifyAll', __CLASS__, __METHOD__, $exc, false);
    }


});

function ordersModify2Function($app, $postedData) {
    $ordersFetcher = new Orders();
    $ordersFetcher->setApp($app);
    return json_encode($ordersFetcher->Modify2($postedData));
};

function ordersModify3Function($app, $postedData) {
    $ordersFetcher = new Orders();
    $ordersFetcher->setApp($app);
    return json_encode($ordersFetcher->Modify3($postedData));
};

$app->post('/Orders/ModifyOrder/:id', function ($id) use ($app) {
    try {
        logging::post("Entering /Orders/ModifyOrder/$id");

        $app->contentType('text/json');
        $request = $app->request();
        logging::post('Request body: ' . $request->getBody());
        $postedData = json_decode($request->getBody(), true);

        echo ordersModify2Function($app, $postedData);

        logging::post("Leaving /Orders/ModifyOrder/$id normally");
    } catch (Exception $exception) {
        $notification = new Notifications();
        $notification->EmailPostError("/Orders/ModifyOrder/$id");
        utility::handlePOSTEndPointException("/Orders/ModifyOrder/$id",
            __CLASS__, __METHOD__, $exception, false);
    }
})->name('ModifyOrderRoute');

$app->post('/Orders/Modify2', function () use ($app) {
    try {
        $app->contentType('text/json');
        $request = $app->request();
        logging::post('/Orders/Modify2: ' . $request->getBody());
        $postedData = json_decode($request->getBody(), true);
        echo ordersModify2Function($app, $postedData);
        logging::debug("Leaving /Orders/Modify2 normally");
    } catch (Exception $exception) {
        $notification = new Notifications();
        $notification->EmailPostError("/Orders/Modify2");
        utility::handlePOSTEndPointException("/Orders/Modify2",
            __CLASS__, __METHOD__, $exception, false);
    }
})->name('OrderModify2Route');

$app->post('/Orders/Modify3', function () use ($app) {
    try {
        $app->contentType('text/json');
        $request = $app->request();
        logging::post('/Orders/Modify3: ' . $request->getBody());
        $postedData = json_decode($request->getBody(), true);
		// $ordersFetcher = new Orders();
	    // echo json_encode($ordersFetcher->Modify3($app,$postedData));
        echo ordersModify3Function($app, $postedData);
        logging::debug("Leaving /Orders/Modify3 normally");
    } catch (Exception $exception) {
        $notification = new Notifications();
        $notification->EmailPostError("/Orders/Modify3");
        utility::handlePOSTEndPointException("/Orders/Modify3", __CLASS__, __METHOD__, $exception, false);
    }
})->name('OrderModify3Route');

$app->post('/Orders/ModifyOrderItemsAll/', function () use ($app) {
	utility::putMany($app, 'OrderItems');
});

$app->post('/Orders/:id', function () use ($app) {
	try {
		$app->contentType('text/json');
		$request = $app->request();
		logging::post('/Orders/Modify2: ' . $request->getBody());
		$postedData = json_decode($request->getBody(), true);
		echo ordersModify2Function($app, $postedData);
		logging::debug("Leaving /Orders/Modify2 normally");
	} catch (Exception $exception) {
        $notification = new Notifications();
        $notification->EmailPostError("/Orders/Modify2");
		utility::handlePOSTEndPointException("/Orders/Modify2",
				__CLASS__, __METHOD__, $exception, false);
	}
})->name('OrderModify2Route');

$app->get('/Tree/Sync', function () use ($app) {
    try {

        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $version    = utility::getParamValue($requestParams, 'version', 0);
        $skip       = utility::getParamValue($requestParams, 'skip', 0);
        $top        = utility::getParamValue($requestParams, 'top', 250);
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        logging::info("/Orders/GetCollection $supplierId | $version | $skip | $top");

        $treeFetcher = new Tree();
        $result = $treeFetcher->sync($supplierId, $version, $skip, $top, null, $format);
        echo $result;
        logging::info('Leaving /Tree/Sync normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/Tree/Sync', __CLASS__, __METHOD__, $exc, true);
    }
});


/*
 * TODO To be deleted and replaced with /Get
 */
$app->get('/GetStoredProc', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
		$sql_keyWords = ['SELECT','FROM','PROCEDURE','ALTER','BEGIN','INSERT','UPDATE','ROLLBACK','COMMIT','EXEC','EXECUTE','PROC','DELETE','DROP','RESTRICT','REVOKE','SET','CURSOR','CREATE','CONVERT','CONSTRAINT','CONSTRAINTS'];
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $storedProc = utility::getParamValue($requestParams, 'storedproc');
		if (strtolower(substr($storedProc, 0 ,2)) == 'sp') {
			echo "Unauthorized Method Call";
			$app->response->setStatus(403);
			return;
        }
        $params     = utility::getParamValue($requestParams, 'params');
        logging::info("/GetStoredProc $storedProc | $params");

        $conn = dbConnection::Connect();
        $params = trim($params, '()');//Removes the Brackets form the received string
        $params_array = explode("|", $params);//Separate the pipe delimited string into an array
        $sql_str = "{ CALL  $storedProc(";
        for ($i = 0; $i < count($params_array); $i++) {
			foreach ($sql_keyWords as $cleaner) {
				if(strcmp(strtoupper($params_array[$i]),$cleaner) == 0){
					$app->response->setStatus(403);
					echo "Unauthorized Method Call";
					return;
				}
			}

			//Checking for alphameric values
			if( ctype_alnum($params_array[$i]) && !ctype_digit($params_array[$i]) ){
					$params_array[$i] = "'$params_array[$i]'";
			}
            $sql_str .= " $params_array[$i],";
        };
        $sql_str = rtrim($sql_str, ",");//Cuts off the LAst Commas in the String
        $sql_str .= " ) }";
        $usp_result = odbc_exec($conn, $sql_str);

        $Result_Array = utility::ProcessMultiDbData_v2($usp_result);
        echo json_encode($Result_Array);

        logging::debug('Leaving /GetStoredProc normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/GetStoredProc', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->get('/Get', function () use ($app) {
	try {
		$app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
		$storedProc = utility::getParamValue($requestParams, 'method');
		$sql_keyWords = ['SELECT','FROM','PROCEDURE','ALTER','BEGIN','INSERT','UPDATE','ROLLBACK','COMMIT','EXEC','EXECUTE','PROC','DELETE','DROP','RESTRICT','REVOKE','SET','CURSOR','CREATE','CONVERT','CONSTRAINT','CONSTRAINTS'];
		//Ensuring System Calls Aren't done for security purposes
		if (strtolower(substr($storedProc, 0 ,2)) == 'sp') {
			echo "Unauthorized Method Call";
			$app->response->setStatus(403);
			return;
		}
		$conn = dbConnection::Connect();
		$sql_str = "{ CALL  $storedProc(";
		$params = $req->get();
		// logging::info("/Get $storedProc | $params");
		foreach ($params as $key => $val) {
			if ($key == "method") {
				continue;
			}
			//Searching to see if the parameters contain any unsafe sql strings
    	 	foreach ($sql_keyWords as $cleaner){
        		if(strcmp(strtoupper($val),$cleaner) == 0){
            			$app->response->setStatus(403);
            			echo "Unauthorized Method Call";
            			return;
       			}
    		}

			//Check if the value sent in is an alphameric value
			if(ctype_alnum($val) && !ctype_digit($val)){
					$val = "'$val'";
			}

			$sql_str .= "$val,";
		}
		$sql_str = rtrim($sql_str, ",");//Cuts off the LAst Commas in the String
		$sql_str .= " ) }";
		$usp_result = odbc_exec($conn, $sql_str);

		$Result_Array = utility::ProcessMultiDbData_v2($usp_result);
        // if we have elements, then check for a JSON field and decode it, if needed
        if (sizeof($Result_Array) > 0) {
            if (array_key_exists("JSON",$Result_Array[0])) {
                $rlt = [];
                foreach ($Result_Array as $row) {
                    $row["JSON"] = json_decode($row["JSON"]);
                    array_push($rlt, $row);
                }
                echo json_encode($rlt);
                return;
            }
        }
        echo json_encode($Result_Array);

		logging::debug('Leaving /Get normally');
	} catch (Exception $exc) {
		utility::handleEndPointException('/GetStoredProc', __CLASS__, __METHOD__, $exc, false);
	}
});

$app->get('/BI/Get/', function () use ($app){
    try{
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
		$storedProc = utility::getParamValue($requestParams, 'method');
		$sql_keyWords = ['SELECT','FROM','PROCEDURE','ALTER','BEGIN','INSERT','UPDATE','ROLLBACK','COMMIT','EXEC','EXECUTE','PROC','DELETE','DROP','RESTRICT','REVOKE','SET','CURSOR','CREATE','CONVERT','CONSTRAINT','CONSTRAINTS'];
		//Ensuring System Calls Aren't done for security purposes
		if (strtolower(substr($storedProc, 0 ,2)) == 'sp') {
			echo "Unauthorized Method Call";
			$app->response->setStatus(403);
			return;
		}
		$conn = dbConnection::MysqlConnect();
		$sql_str = "CALL  $storedProc(";
		$params = $req->get();

		foreach ($params as $key => $val) {
			if ($key == "method") {
				continue;
			}
			//Searching to see if the parameters contain any unsafe sql strings
    	 	foreach ($sql_keyWords as $cleaner){
        		if(strcmp(strtoupper($val),$cleaner) == 0){
            			$app->response->setStatus(403);
            			echo "Unauthorized Method Call";
            			return;
       			}
    		}

			//Check if the value sent in is an alphameric value
			if(ctype_alnum($val) && !ctype_digit($val)){
					$val = "'$val'";
			}

			$sql_str .= "$val,";
		}
		$sql_str = rtrim($sql_str, ",");//Cuts off the LAst Commas in the String
		$sql_str .= ")";
		$qry_result = $conn->query($sql_str);
        $rows = array();
        while($row = $qry_result->fetch_assoc()) {
            $rows[] = $row;
        }
        // $qry_result = ($qry_result->num_rows == 0) ? [] : $qry_result->fetch_assoc();
        echo json_encode($rows);
    } catch (Exception $exc) {
		utility::handleEndPointException('/BI/Get/', __CLASS__, __METHOD__, $exc, false);
	}
});

//TODO This is to be deleted, replaced with /Sync
$app->get('/GetStoredProc/Sync', function () use ($app) {
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $storedProc = utility::getParamValue($requestParams, 'storedproc');
        $table      = utility::getParamValue($requestParams, 'table');
        $params     = utility::getParamValue($requestParams, 'params');
        logging::info("/Orders/GetCollection $storedProc | $table | $params");

        $conn = dbConnection::Connect();
        $params = trim($params, '()');//Removes the Brackets form the received string
        $params_array = explode("|", $params);//Separate the pipe delimited string into an array
        $sql_str = "{ CALL  $storedProc(";
        for ($i = 0; $i < count($params_array); $i++) {
            $sql_str .= " $params_array[$i],";
        };
        $sql_str = rtrim($sql_str, ",");//Cuts off the LAst Commas in the String
        $sql_str .= " ) }";
        $usp_result = odbc_exec($conn, $sql_str);
        $Result_Array = utility::ProcessMultiDbData_v2($usp_result);
        echo Utility::SyncSuccessbaseResponse($Result_Array, $table);

        logging::debug('Leaving /GetStoredProc/Sync normally');
    } catch (Exception $exc) {
        utility::handleEndPointException('/GetStoredProc/Sync', __CLASS__, __METHOD__, $exc, true);
    }
});

$app->get('/Sync', function () use ($app) {
	try {
		initGetMethods('/Sync2', $app, $supplierId, $userId, $version, $skip, $top, $format );
		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
		$storedProc = utility::getParamValue($requestParams, 'method');
		$table = utility::getParamValue($requestParams, 'table');
		logging::info("/Sync $storedProc | $table ");

		$synchronizer = new Sync();
		echo $synchronizer->genericSyncResponse($storedProc,$table, true, $supplierId, true, $userId, $version, true, $skip, $top, null, 'json');

		logging::debug('Leaving /GetStoredProc/Sync normally');
	} catch (Exception $exc) {
		utility::handleEndPointException('/GetStoredProc/Sync', __CLASS__, __METHOD__, $exc, true);
	}
});

/**
 * Send an email
 */
$app->post('/Send' , function () use($app){

	try {
		$app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
		$userId = utility::getParamValue($requestParams, 'userid');
		$subject = utility::getParamValue($requestParams, 'subject');
		$body = $req->getBody();
		logging::post('/Send|userId=' . $userId . '|subject=' . $subject);

		$users = new Users();
		$user = $users->getUser($userId);
        $email_addresses = explode(',',$user['Email'] );

		$mail = new PHPMailer;
		$mail->isSMTP();                                      		// Set mailer to use SMTP
		$mail->Host = $app->config('SMTPServer');  					// Specify main and backup SMTP servers
		$mail->SMTPAuth = $app->config('AuthenticationRequired');   // Enable SMTP authentication
		$mail->Username = $app->config('UserID');                 	// SMTP username
		$mail->Password = $app->config('Password');                 // SMTP password
		$mail->SMTPSecure = 'TLS';                            		// Enable TLS encryption, `ssl` also accepted
		$mail->Port = $app->config('SMTPPort');
		$mail->setFrom($app->config('FromEmail'));
		for($i = 0; $i < count($email_addresses); $i++){
            if($i == 0)
                $mail->addAddress($email_addresses[$i]);
            else
             $mail->AddCC($email_addresses[$i]);
        }
		$mail->isHTML(true);
		$mail->Subject = $subject;
		$mail->Body = $body;

		$response = ["status" => True, "message" => ''];

		if(!$mail->send()) {
			$response['status'] = false;
			$response['message'] = $mail->ErrorInfo;
			logging::error($mail->ErrorInfo, __CLASS__, __METHOD__);
		} else {
			$response['status'] = true;
		}
		echo json_encode($response);
	} catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/Send");
		utility::handlePOSTEndPointException('/Send', __CLASS__, __METHOD__, $exc, false);
	}
});

$app->post('/PostImage' , function () use($app){
	try {
		logging::info("/PostImage");
		$app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
		$req = $app->request();
		$obj = json_decode($req->getBody(), true);
		$s3 = new S3();
        // If filename doesnt have an extension, then add an extension if JPG or PNG
        $filename = $obj['Id'];
        if (strpos($obj['Id'], '.') === false) {
            if (strpos($obj['Type'],"jpeg")) {
                $filename = $obj['Id'] . "jpg";
            }
            if (strpos($obj['Type'],"png")) {
                $filename = $obj['Id'] . "png";
            }
        }
		$url = $s3->SendImage($obj['SupplierID'], $filename, base64_decode( $obj['FileData']));

		$response = ["status" => true, "url" => $url];
		echo  json_encode( $response) ;
	} catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/PostImage");
		utility::handlePOSTEndPointException('/PostImage', __CLASS__, __METHOD__, $exc, false);
	}
});

$app->post('/RemoveFromBucket', function() use($app){
    try{
        logging::info("/RemoveFromBucket");
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $obj = json_decode($req->getBody(), true);
        $s3 = new S3();
        $rslt = $s3->deleteObj($obj['Bucket'],$obj['file'], $obj['deleteFolder']);
        $response = ["status" => true, "response" => "Num Deleted Items : ". $rslt];
        echo  json_encode( $response) ;
    }catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/RemoveFromBucket");
        utility::handlePOSTEndPointException('/RemoveFromBucket', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->post('/SendToBucket' , function () use($app){
	try {
		logging::info("/SendToBucket");
		$app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
		$req = $app->request();
		$obj = json_decode($req->getBody(), true);
		$s3 = new S3();
        $filename = $obj['Id'];
		$url = $s3->SendToBucket($obj['SupplierID'], $filename, base64_decode( $obj['FileData']));
		$response = ["status" => true, "url" => $url];
		echo  json_encode( $response) ;
	} catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/SendToBucket");
		utility::handlePOSTEndPointException('/SendToBucket', __CLASS__, __METHOD__, $exc, false);
	}
});

$app->post('/PostToBucket' , function () use($app){
    try {
        logging::info("/PostToBucket");
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $supplier = utility::getParamValue($requestParams, 'supplierid');
        $Id = utility::getParamValue($requestParams, 'id');
        $s3 = new S3();
        $url = $s3->createObject($supplier,$Id , $_FILES["file"]);
        $response = ["status" => true, "url" => $url];
        echo  json_encode( $response) ;
    } catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/PostToBucket");
        utility::handlePOSTEndPointException('/PostToBucket', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->post('/PostFile' , function () use($app){
    try {
        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        $supplier = utility::getParamValue($requestParams, 'supplierid');
        $renameID = utility::getParamValue($requestParams, 'renameid');
        $isOrder  = utility::getParamValue($requestParams, 'isorder');
        $userID   = utility::getParamValue($requestParams, 'userid');
        logging::info("/PostFile:$supplier|$renameID|$isOrder|$userID" );

        //print_r($_FILES);
        $numofits = 0;
            //if the renameID is part of the url then we rename the file
        for($i = 0; $i < count($_FILES["file"]["name"]);$i++){
            if ($_FILES["file"]["error"][$i] > 0) {
                continue;
            }
            $numofits++;
            $sql_str = "";
            $temp = "";
            $filename = "";
            if($renameID){
                 $temp = explode(".",$_FILES["file"]["name"][$i]);
                 $filename = $renameID."-".$i.".".end($temp);
            }else{
                $filename = $_FILES["file"]["name"][$i];
            }
            $uploadFolder = "/var/www/uploads/$supplier/".$filename;
            move_uploaded_file($_FILES["file"]["tmp_name"][$i],$uploadFolder);
            $str = file_get_contents($uploadFolder);
            // Send uploaded file to s3
            $s3 = new S3();
            $rslt_url = $s3->SendImage($supplier,$filename, $str);
           //delete file after sending it to s3
           unlink($uploadFolder);
            if ($isOrder){
                $link =  str_replace('https', 'http', $rslt_url);
                $conn = dbConnection::Connect();
                $sql_str = '{CALL usp_OrderFiles_modify('.$supplier.','.$userID.','."'$renameID'".",'$link')}";
                odbc_exec($conn, $sql_str);
            }

        }

        $response = ["status" => True, "message" => 'Files Successfully Uploaded',"numberofitarations" => $numofits];
        echo json_encode($response);

        logging::debug('Leaving /PostFile normally');
    } catch (Exception $e) {
        $notification = new Notifications();
        $notification->EmailPostError("/PostFile");
        utility::handlePOSTEndPointException('/PostFile', __CLASS__, __METHOD__, $e, false);
    }
});

$app->get('/BI/UpdateManager', function () use ($app){
    try{
        logging::debug('Entering /BI/UpdateManager');

		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $userID = utility::getParamValue($requestParams, 'userid');
        $manager = utility::getParamValue($requestParams, 'manager');
        $supplierID = utility::getParamValue($requestParams, 'supplierid');

        logging::post('/BI/UpdateManager Parameters:');
        logging::postArray($requestParams);

        MySQL::UpdateManager($supplierID,$userID,$manager);

        $result = array('status' => true, 'resultString' => 'update successful!', 'ErrorMsg' => '');
		echo json_encode($result);
    } catch (Exception $exc){
        $notification = new Notifications();
        $notification->EmailPostError("/BI/UpdateManager");
        utility::handleEndPointException('/BI/UpdateManager', __CLASS__, __METHOD__, $exc, false);
    }
});

//RTSync Put Many Rest Service
$app->post('/PutMany', function () use ($app) {
    try {
        logging::post('Entering /PutMany');

        set_time_limit(120);
        $app->contentType('text/json');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $tblName = utility::getParamValue($requestParams, 'table');
        utility::putMany($app, $tblName);

/*
 *		below should be deleted after Jan 2016 as it was put into putMany
 *
        logging::post('Parameters:');
        logging::postArray($requestParams);
        logging::post('Request body: ' . $req->getBody());

        $resp = $app->response();

        $conn = dbConnection::Connect();
        $PostedData = json_decode($req->getBody());

        $usp_result = odbc_exec($conn, "{CALL  sp_pkeys($tblName)}");
        $fieldName = [];
        $pk_Counter = 0; //Primary Key Result Counter
        while ($rows = odbc_fetch_array($usp_result)) {
            $fieldName[$pk_Counter] = $rows['COLUMN_NAME'];
            $pk_Counter++;
        }
        $ON_stmt_str = 'ON (';
        for ($i = 0; $i < count($fieldName); $i++) {
            if (count($fieldName) - $i === 1) {
                $ON_stmt_str .= "trgt.$fieldName[$i] = source.$fieldName[$i]) ";

            } else {
                $ON_stmt_str .= " trgt.$fieldName[$i] = source.$fieldName[$i]" . ' and ' . '';
            }
        }

        $count = 0;
        if (isset($PostedData)) {
            foreach ($PostedData as $json_data) {
                //Initialization of the The String Var
                $sqlMerge = "Merge $tblName as trgt using ( SELECT ";
                $sqlSource = 'as source (';
                $sqlWhenMatched = 'When Matched THEN UPDATE set ';
                $sqlWhenNotMatchedInsert = 'When Not Matched THEN INSERT ( ';
                $sqlWhenNotMatchedValues = 'VALUES ( ';
                //$Params = [];
                $sql_stmt = '';

                $count++;

                foreach ($json_data as $key => $val) {

                    if ($val === "") {
                        $sqlWhenNotMatchedValues .= " '',";
                        $sqlMerge .= " '',";
                    } else {
                        $sqlMerge .= " '$val',";
                        $sqlWhenNotMatchedValues .= "'$val',";
                    }
                    //$sqlMerge .= '?,';
                    //array_push($Params, $val);
                    $sqlSource .= " $key,";
                    $sqlWhenMatched .= "$key=source.$key,";
                    $sqlWhenNotMatchedInsert .= "$key,";
                }
                $sqlWhenMatched = substr($sqlWhenMatched, 0, -1);
                $sqlMerge = substr($sqlMerge, 0, -1) . ') ';
                $sqlSource = substr($sqlSource, 0, -1) . ') ';
                $sqlWhenNotMatchedInsert = substr($sqlWhenNotMatchedInsert, 0, -1) . ') ';
                $sqlWhenNotMatchedValues = substr($sqlWhenNotMatchedValues, 0, -1) . ') ';

                $sql_stmt = $sqlMerge . $sqlSource . $ON_stmt_str . $sqlWhenMatched . ' ' . $sqlWhenNotMatchedInsert . $sqlWhenNotMatchedValues . '; ';
                echo($sql_stmt . '<br>');
                $stmt = odbc_exec($conn, $sql_stmt);


            }#Top Level For Each
            $res = array('status' => true, 'resultString' => 'Data Successfully Saved', 'ErrorMsg' => '');
            $resp->write(json_encode($res));
        }
        logging::post('Leaving /PutMany normally');
*/
    } catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/PutMany");
        utility::handlePOSTEndPointException('/PutMany', __CLASS__, __METHOD__, $exc, false);
    }

});

/*
 * TODO delete below and call /Post
 */
$app->post('/StoredProcModify', function () use ($app) {
    try {
        logging::post('Entering /StoredProcModify');

        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::post('Parameters:');
        logging::postArray($requestParams);
        logging::post('Request body: ' . $req->getBody());

        $storedProc = utility::getParamValue($requestParams, 'storedproc');
        $PostedData = json_decode($req->getBody());
        $post_arr = [];

        $conn = dbConnection::Connect();
        if (isset($PostedData)) {
            //stored Procedure for windows ODBC Driver
            //$sql_sp_params = "{CALL  usp_get_storedprocparams($storedProc)}";

            //stored Procedure for Unix Odbc Driver
            $sql_sp_params = "{CALL  usp_get_storedprocparams_2($storedProc)}";

            $sql_sp_params_result = odbc_exec($conn, $sql_sp_params);
            while ($row = odbc_fetch_array($sql_sp_params_result)) {

                foreach ($PostedData as $key => $val) {
                    if (in_array('@' . $key, $row)) {
                        if ($row['type'] === 'int') {
                            $post_arr[$row['param_order']] = $val; //Windows
                            //$post_arr[$row['parameter_id']] = $val; //Unix
                        } else {
                            $clean_val = str_replace("'", "''", $val);
                            $post_arr[$row['param_order']] = "'$clean_val'"; //Windows
                            //$post_arr[$row[$row['parameter_id']]] = "'$clean_val'";
                        }
                    }
                }
            }
            $sql_qry = "{ CALL  $storedProc(";
            for ($i = 1; $i <= count($post_arr); $i++) {
                $sql_qry .= "$post_arr[$i],";
            }
            $sql_qry = substr($sql_qry, 0, -1) . ') } ';//Cuts off the LAst Commas in the String
            $usp_result = odbc_exec($conn, $sql_qry);
            //$sql_str = "{CALL  $storedProc($supplierID,$userID,$version,$offest,$numrows)}";
        }
        logging::post('Leaving /StoredProcModify normally');
    } catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/StoredProcModify");
        utility::handlePOSTEndPointException('/StoredProcModify', __CLASS__, __METHOD__, $exc, false);
    }

});

$app->post('/Post', function () use ($app) {
	try {
		logging::debug('Entering /Post');

		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
    	$method = utility::getParamValue($requestParams, 'method');
		$postData = json_decode($req->getBody());

		logging::post("/Post method: $method");
		logging::post('Request body: ' . $req->getBody());

		utility::post($app, $method, $postData);
        // If method exists then save to dynamodb and then save to mysql
        if ($method == 'usp_event_modify3') {
            MySQL::PostEvents($postData);
            $activityID = $postData->EventID;
            unset($postData->EventID);
            $postData->ActivityID = $activityID;
            $postData->TypeID = $postData->EventTypeID;
            unset($postData->EventTypeID);
            Dynamo::PutItem('Activities',$postData);
        }
        $res = array('status' => true, 'resultString' => 'Records successfully saved', 'ErrorMsg' => '');
		echo json_encode($res);
	} catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/POST");
		utility::handlePOSTEndPointException('/POST', __CLASS__, __METHOD__, $exc, false);
	}
});

$app->post('/Post/Activity', function () use ($app){
    try{
        logging::debug('Entering /Post/Activity');
		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
        $table = utility::getParamValue($requestParams, 'table');
 		$postData = json_decode($req->getBody());
		logging::post('Request body: ' . $req->getBody());

        //MSSQL Call
        MySQL::PostEvents($postData);

       //DynamoDB Call
       $activityID = $postData->EventID;
       unset($postData->EventID);
       $postData->ActivityID = $activityID;
       $postData->TypeID = $postData->EventTypeID;
       unset($postData->EventTypeID);
       Dynamo::PutItem($table,$postData);
       $res = array('status' => true, 'resultString' => 'Records successfully saved to dynamo', 'ErrorMsg' => '');
		echo json_encode($res);
    } catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/POST/ACTIVITY");
        utility::handlePOSTEndPointException('/POST/ACTIVITY', __CLASS__, __METHOD__, $exc, false);
    }
});

$app->post('/PostMany', function () use ($app) {
	try {
		logging::debug('Entering /Post');

		$req = $app->request();
		$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
		$method = utility::getParamValue($requestParams, 'method');
		$json_data = json_decode($req->getBody());

		logging::post("/Post method: $method");
		logging::post('Request body: ' . $req->getBody());

		foreach ($json_data as $postData) {
			utility::post($app, $method, $postData);
		}
		$res = array('status' => true, 'resultString' => 'Records successfully saved', 'ErrorMsg' => '');
		echo json_encode($res);
	} catch (Exception $exc) {
        $notification = new Notifications();
        $notification->EmailPostError("/POSTMANY");
		utility::handlePOSTEndPointException('/POSTMANY', __CLASS__, __METHOD__, $exc, false);
	}
});

$app->get('/PriceLists/GetCollection2', function () use ($app) {
    try {
        logging::info('Entering /PriceLists/GetCollection2');

        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::info('Parameters:');
        logging::infoArray($requestParams);

        $supplierId = utility::getParamValue($requestParams, 'supplierid');
        $accountId = utility::getParamValue($requestParams, 'accountid');
        $branchId = utility::getParamValue($requestParams, 'branchid');
        $searchString = utility::getParamValue($requestParams, 'searchstring');
        $category = utility::getParamValue($requestParams, 'category');
        $offset = utility::getParamValue($requestParams, 'offset', 0);
        $noRows = utility::getParamValue($requestParams, 'norows');
        $myRange = utility::getParamValue($requestParams, 'myrange');
        $includeCatalogues = utility::getParamValue($requestParams, 'includecatalogues');
        $format = utility::getParamValue($requestParams, 'format', 'json');
        $callback = utility::getParamValue($requestParams, 'callback');

        $priceLists = new PriceLists();
        $result = $priceLists->getCollection2($supplierId, $accountId, $branchId,
            $searchString, $category, $offset, $noRows, $myRange, $includeCatalogues, null, $format);
        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;
        logging::debug('Leaving /PriceLists/GetCollection2 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/PriceLists/GetCollection2', __CLASS__, __METHOD__, $exception, false);
    }
});

$app->get('/Prices/GetPrice3', function () use ($app) {
    try {
        logging::debug('Entering /Prices/GetPrice3');

        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::info('Parameters:');
        logging::infoArray($requestParams);

        $supplierID = utility::getParamValue($requestParams, 'supplierid');
        $productID  = utility::getParamValue($requestParams, 'productid');
        $accountID  = utility::getParamValue($requestParams, 'accountid');
        $checkStock = utility::getParamValue($requestParams, 'checkstock');
        $checkPrice = utility::getParamValue($requestParams, 'checkprice');
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        $callback   = utility::getParamValue($requestParams, 'callback');

        $pricesFetcher = new Prices2();
        $result = $pricesFetcher->getPrice3($supplierID, $productID, $accountID,
            $checkStock, $checkPrice, null, $format);
        $resultantJson = json_encode($result);
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::debug('Leaving /Prices/GetPrice3 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Prices/GetPrice3', __CLASS__, __METHOD__, $exception, false);
    }

});

/**
 * Below is for backwards compatibility as Rapidtrade calls with lower case, must update the APP and then below can be deleted once a new version is released
 * --Shaun
 */
$app->get('/prices/getprice3', function () use ($app) {
    try {
        logging::info('Entering /Prices/GetPrice3');

        $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $req = $app->request();
        $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());

        logging::infoArray($requestParams);

        $supplierID = utility::getParamValue($requestParams, 'supplierid');
        $productID  = utility::getParamValue($requestParams, 'productid');
        $accountID  = utility::getParamValue($requestParams, 'accountid');
        $checkStock = utility::getParamValue($requestParams, 'checkstock');
        $checkPrice = utility::getParamValue($requestParams, 'checkprice');
        $format     = utility::getParamValue($requestParams, 'format', 'json');
        $callback   = utility::getParamValue($requestParams, 'callback');

        $redis = new Redis();
        $redis->connect($app->config('redis'));
        $key = $supplierID . $productID . $accountID;
        if ($redis->exists($key) == true) {
            $resultantJson = $redis->get($key);
        } else {
            $pricesFetcher = new Prices2();
            $result = $pricesFetcher->getPrice3($supplierID, $productID, $accountID,
                $checkStock, $checkPrice, null, $format);
            $resultantJson = json_encode($result);
            $redis->setex($key,90,$resultantJson);
        }
        if (!empty($callback)) {
            $resultantJson = $callback . '(' . $resultantJson . ')';
        }
        echo $resultantJson;

        logging::info('Leaving /Prices/GetPrice3 normally');
    } catch (Exception $exception) {
        utility::handleEndPointException('/Prices/GetPrice3', __CLASS__, __METHOD__, $exception, false);
    }

});

/**
 * Below are for RapidSync
 */
$app->post('/Companies/', function () use ($app) {
    $req = $app->request();
    $requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
    $postData = json_decode($req->getBody());
    foreach ($postData as $json_data) {
        utility::post($app, 'usp_account_modify5',$json_data);
    }
});

$app->post('/Address/', function () use ($app) {
	utility::putMany($app, 'Address');
});

$app->post('/Contacts/', function () use ($app) {
	utility::putMany($app, 'Contacts');
});

$app->post('/DiscountValues/', function () use ($app) {
	utility::putMany($app, 'DiscountValues');
});

$app->post('/Prices/', function () use ($app) {
	utility::putMany($app, 'Price');
});

$app->post('/ProductCategories2/', function () use ($app) {
	utility::putMany($app, 'ProductCategory2');
});

$app->post('/Products/', function () use ($app) {
	utility::putMany($app, 'Product');
});

$app->post('/Stock/', function () use ($app) {
	utility::putMany($app, 'Stock');
});

$app->post('/UserCompany/', function () use ($app) {
	utility::putMany($app, 'UserAccounts');
});

$app->post('/Orders/', function () use ($app) {
	utility::putMany($app, 'Orders');
});


/**
 * Below is because rapidsync still calls old soap service
 */
$app->post('/Users.asmx', function () use ($app) {
	$usersFetcher = new Users();
	$rslt =  $usersFetcher->getUserXML($app);
	$app->response->headers->set('Content-Type', 'text/xml');

	header("Content-type: text/xml; charset=utf-8");
	//disable gzip
	@ini_set('zlib.output_compression', 'Off');
	@ini_set('output_buffering', 'Off');
	@ini_set('output_handler', '');
	@apache_setenv('no-gzip', 1);

	echo $rslt;
});

/**
 * http://api.rapidtrade.biz/rest/getimage.aspx?imagename=A7-BULLET-AZURO&subfolder=DEMO&width=300&height=300
 */
$app->get('/getimage.aspx', function () use ($app) {
	$req = $app->request();
	$requestParams = utility::getRequestParamsMapWithLowerCaseKeys($req->get());
	$subfolder = utility::getParamValue($requestParams, 'subfolder');
	$imagename = utility::getParamValue($requestParams, 'imagename');
	$width = utility::getParamValue($requestParams, 'width');
	$height = utility::getParamValue($requestParams, 'height');
	logging::info("GetImage: subfolder=$subfolder&imagename=$imagename");

	$url = "http://app.rapidtrade.biz/getimage.aspx?imagename=$imagename&subfolder=$subfolder&width=$width&height=$height";
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$output = curl_exec($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);
	echo $output;
});

$app->post('/workflowreprint/:domain/:workflowtype', function($domain,$workflowtype) use ($app){
    try {
        // We will send in the template input in a ready format
        logging::info('Entering /workflowreprint/:domain/:workflowtype');
        $req = $app->request();
        $templateInput = json_decode($req->getBody());
        $swf = new Workflow();
        $tasklist = $templateInput->ActivityTaskList;
        $tags =  $templateInput->SupplierID . "," . $templateInput->UserID . "," .$workflowtype ."," . $templateInput->SupplierID;
        $swf->BeginWorkflow($templateInput,$domain,$workflowtype,$tags);
        $swfActivityResp = $swf->pollForActivityTask($domain,$tasklist);
        echo json_encode($swfActivityResp);
    } catch(Exception $exception) {
        utility::handleEndPointException('Exception executing /workflowreprint/:domain/:workflowtype', __CLASS__, __METHOD__, $exception, false);
    }
});


/**
 * Run the main application
 */
$app->run();

/**
function hasCache($app,$insupplierid, $intablename, $inuserid){
	try {
		$redis = new Redis();
		$redis->connect($app->config('redis'));
		$redis->auth($app->config('redispassword'));
		$key = $insupplierid . '|Accounts|' .  $inuserid . '|0';
		$result = $redis->exists($key);
		$redis->close();
		logging::debug('hasCache: ' . $key . ':' . $result);
		return $result;
	} catch (Exception $exc){
		logging::error('Error initialising REDIS ' . $exc->getMessage(),'','');
		return false;
	}
}

function getVersion($redis, $insupplierid, $intable, $keyvalue, $inskip, $intop, $inversion ){
	$results = array();
	$key = $insupplierid . '|' . $intable . '|' .  $keyvalue . '|' . $inversion;
	$top = ($redis->lLen($key) < $intop ? -1 : $intop);
	$lrange = $redis->lRange($key, $inskip, $top);

	//decode to json objects
	$retAccounts = array();
	foreach ($lrange as $row) {
		array_push($results,json_decode($row));
	}
	return $results;
}

function getNewVersions($redis, $insupplierid, $intable, $keyvalue, $inskip, $intop, $inversion){
	$results = array();
	//Get versions for this keyvalue
	$key = 'Versions|' . $insupplierid . '|' . $intable . '|' . $keyvalue;
	$versions = $redis->sMembers($key);

	//loop through all keyvalue versions available to see if we must download
	foreach ($versions as $version){
		if ($inversion <= $version) {
			$key = $insupplierid . '|' . $intable . '|' .  $keyvalue . '|' . $version;
			if ($redis->exists($key) ==  false) {continue;}

			// fetch all rows for this version
			$list = getVersion($redis, $insupplierid, $intable, $keyvalue, 0, -1, $version);
			// add all rows to an array
			foreach ($list as $row){
				array_push($results, $row);
			}
		}
	}
	// use chunks to get the correct SKIP/TOP rows
	if ($intop > 0) {
		$chunks = array_chunk($results, $intop);
		$num = $inskip / $intop;
		$results = (count($chunks) == 0) ? array() : $chunks[$num];
	}
	//return utility::RedisSuccessbaseResponse($results, $intable, $redis);
	return $results;
}


function fetchFromRedisUserKeys($app, $insupplierid, $intable, $inuserid, $inskip, $intop, $inversion){
	$redis = new Redis();
	$redis->connect($app->config('redis'));
	$redis->auth($app->config('redispassword'));

	$results = array();
	//Get user record. eg. DEMO|UserPricelist|DEMO
	$key = $insupplierid . '|User' . $intable . '|' . $inuserid;
	$rows = $redis->sMembers($key);
	$results = array();

	//loop through all pricelists or warehouses for this user to see if we must download the pricelist or stock
	foreach ($rows as $pricelist){
		if ($inversion == 0){
			$list = getVersion($redis,$insupplierid, $intable, $pricelist, $inskip, -1, 0);
		} else {
			$list = getNewVersions($redis, $insupplierid, $intable, $pricelist, $inskip, -1, $inversion);
		}

		foreach ($list as $row){
			array_push($results, $row);
		}
	}
	// use chunks to get the correct SKIP/TOP values
	$chunks = array_chunk($results, $intop);
	$num = $inskip / $intop;
	$cnt = (count($chunks) == 0) ? 0 : count($chunks[0]);
	$results = (count($chunks) == 0 || $num >= count($chunks)) ? null : $chunks[$num];
	logging::info('Redis row count (user keys): ' . $cnt);
	return (utility::RedisSuccessbaseResponse($results, $intable, $redis));
}

function fetchRedisViaUserID($app, $supplierId, $table, $userId, $skip, $top, $version) {
	$redis = new Redis();
	$redis->connect($app->config('redis'));
	$redis->auth($app->config('redispassword'));

	if ($version == 0) {
		$results = getVersion($redis,$supplierId, $table, $userId, $skip, $top, 0);
	} else {
		$results = getNewVersions($redis, $supplierId, $table, $userId, $skip, $top, $version);
	}
	logging::info('Redis row count (userID): ' . count($results));
	return (utility::RedisSuccessbaseResponse($results, $table, $redis));
}

**/
