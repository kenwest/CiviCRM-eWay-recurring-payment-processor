<?php

class CRM_Core_Payment_Ewayrecurring extends CRM_Core_Payment
{

    const CHARSET  = 'UTF-8'; # (not used, implicit in the API, might need to convert?)

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = null;

  /**********************************************************
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @param $paymentProcessor
   *
   * @return \CRM_Core_Payment_Ewayrecurring
   */
    function __construct( $mode, &$paymentProcessor )
    {
        // As this handles recurring and non-recurring, we also need to include original api libraries
        require_once 'packages/eWAY/eWAY_GatewayRequest.php';
        require_once 'packages/eWAY/eWAY_GatewayResponse.php';

        $this->_mode             = $mode;             // live or test
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('eWay Recurring');
    }


  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @param array $paymentProcessor
   * @param null $paymentForm
   * @param bool $force
   *
   * @return object
   * @static
   */
    static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE)
    {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null ) {
            self::$_singleton[$processorName] = new CRM_Core_Payment_Ewayrecurring($mode, $paymentProcessor);
        }
        return self::$_singleton[$processorName];
    }


  /**********************************************************
   * This function sends request and receives response from
   * eWAY payment process
   *********************************************************
   *
   * @param array $params
   *
   * @throws Exception
   * @return array
   */
    function doDirectPayment( &$params )
    {
        if ( ! defined( 'CURLOPT_SSLCERT' ) ) {
            CRM_Core_Error::fatal( ts( 'eWAY - Gateway requires curl with SSL support' ) );
        }

        $ewayCustomerID = $this->_paymentProcessor['subject'];   // eWAY Client ID

        /*
        //-------------------------------------------------------------
        // NOTE: eWAY Doesn't use the following at the moment:
        //-------------------------------------------------------------
        $creditCardType = $params['credit_card_type'];
        $currencyID    = $params['currencyID'];
        $country        = $params['country'];
        */

        //-------------------------------------------------------------
        // Prepare some composite data from _paymentProcessor fields, data that is shared across one off and recurring payments.
        //-------------------------------------------------------------
        $expireYear    = substr ($params['year'], 2, 2);
        $expireMonth   = sprintf('%02d', (int) $params['month']); // Pad month with zeros
        $txtOptions    = "";
        $amountInCents = round(((float) $params['amount']) * 100);
        $credit_card_name  = $params['first_name'] . " ";
        if (strlen($params['middle_name']) > 0 ) {
            $credit_card_name .= $params['middle_name'] . " ";
        }
        $credit_card_name .= $params['last_name'];

        //----------------------------------------------------------------------------------------------------
        // OPTIONAL: If TEST Card Number force an Override of URL and CustomerID.
        // During testing CiviCRM once used the LIVE URL.
        // This code can be uncommented to override the LIVE URL that if CiviCRM does that again.
        //----------------------------------------------------------------------------------------------------
        //        if ( ( $gateway_URL == "https://www.eway.com.au/gateway_cvn/xmlpayment.asp")
        //             && ( $params['credit_card_number'] == "4444333322221111" ) ) {
        //$ewayCustomerID = "87654321";
        //$gateway_URL    = "https://www.eway.com.au/gateway/rebill/test/Upload_test.aspx";
        //        }

        //----------------------------------------------------------------------------------------------------
        // Now set the payment details - see http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
        //----------------------------------------------------------------------------------------------------

        // Was the recurring payment check box checked?
        if (isset($params['is_recur']) && $params['is_recur'] == 1) {

            // eWAY Gateway URL
            $gateway_URL = $this->_paymentProcessor['url_recur'];

            $soap_client = new nusoap_client($gateway_URL, false);
            $err = $soap_client->getError();
            if ($err) {
                echo '<h2>Constructor error</h2><pre>' . $err . '</pre>';
                echo '<h2>Debug</h2><pre>' . htmlspecialchars($soap_client->getDebug(), ENT_QUOTES) . '</pre>';
                exit();
            }

            // set namespace
            $soap_client->namespaces['man'] = 'https://www.eway.com.au/gateway/managedpayment';

            // set SOAP header
            $headers = "<man:eWAYHeader><man:eWAYCustomerID>"
                     . $ewayCustomerID
                     . "</man:eWAYCustomerID><man:Username>"
                     . $this->_paymentProcessor['user_name']
                     . "</man:Username><man:Password>"
                     . $this->_paymentProcessor['password']
                     . "</man:Password></man:eWAYHeader>";
            $soap_client->setHeaders($headers);

            // Add eWay customer
            $requestBody = array(
                'man:Title' => 'Mr.', // Crazily eWay makes this a mandatory field with fixed values
                'man:FirstName' => $params['first_name'],
                'man:LastName' => $params['last_name'],
                'man:Address' => $params['street_address'],
                'man:Suburb' => $params['city'],
                'man:State' => $params['state_province'],
                'man:Company' => '',
                'man:PostCode' => $params['postal_code'],
                // 'man:Country' => $params['country'],
                // TODO: Remove this hardcoded hack
                'man:Country' => 'au',
                'man:Email' => $params['email'],
                'man:Fax' => '',
                'man:Phone' => '',
                'man:Mobile' => '',
                'man:CustomerRef' => '',
                'man:JobDesc' => '',
                'man:Comments' => '',
                'man:URL' => '',
                'man:CCNumber' => $params['credit_card_number'],
                'man:CCNameOnCard' => $credit_card_name,
                'man:CCExpiryMonth' => $expireMonth,
                'man:CCExpiryYear' => $expireYear
            );

            // Hook to allow customer info to be changed before submitting it
            CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $requestBody );

            // Create the customer via the API
            try{
                $soapAction = 'https://www.eway.com.au/gateway/managedpayment/CreateCustomer';
                $result = $soap_client->call('man:CreateCustomer', $requestBody, '', $soapAction);

                if ($result === false) {
                    return self::errorExit(9011, 'Failed to create managed customer - result is false');
                } else if (is_array($result)) {
                    return self::errorExit(9011, 'Failed to create managed customer - result ('
                                                . implode(', ', array_keys($result))
                                                . ') is ('
                                                . implode(', ', $result)
                                                . ')');
                } else if (!is_numeric($result)) {
                    return self::errorExit(9011, 'Failed to create managed customer - result is ' . $result);
                }
            } catch (Exception $e) {
                return self::errorExit(9010, $e->getMessage());
            }

            // We've created the customer successfully
            $managed_customer_id = $result;

            // Save the eWay customer token in the recurring contribution's processor_id field
            CRM_Core_DAO::setFieldValue(
                'CRM_Contribute_DAO_ContributionRecur',
                $params['contributionRecurID'],
                'processor_id',
                $managed_customer_id
            );

            //send recurring Notification email for user
            $recur = new CRM_Contribute_BAO_ContributionRecur();
            $recur->id = $params['contributionRecurID'];
            $recur->find(true);
            $autoRenewMembership = FALSE;
            CRM_Contribute_BAO_ContributionPage::recurringNotify(
                CRM_Core_Payment::RECURRING_PAYMENT_START,
                $params['contactID'],
                $params['contributionPageID'],
                $recur,
                $autoRenewMembership
            );

            /* And we're done - this payment will staying in a pending state until it's processed
             * by the Job
             */
        }
        // This is a one off payment, most of this is lifted straight from the original code, so I wont document it.
        else {
            $gateway_URL    = $this->_paymentProcessor['url_site'];    // eWAY Gateway URL
            $eWAYRequest  = new GatewayRequest;

            if ( ($eWAYRequest == null) || ( ! ($eWAYRequest instanceof GatewayRequest)) ) {
                return self::errorExit( 9001, "Error: Unable to create eWAY Request object.");
            }

            $eWAYResponse = new GatewayResponse;

            if ( ($eWAYResponse == null) || ( ! ($eWAYResponse instanceof GatewayResponse) ) ) {
                return self::errorExit( 9002, "Error: Unable to create eWAY Response object.");
            }

            //-------------------------------------------------------------
            // Prepare some composite data from _paymentProcessor fields
            //-------------------------------------------------------------
            $fullAddress = $params['street_address'] . ", " . $params['city'] . ", " . $params['state_province'] . ".";

            //----------------------------------------------------------------------------------------------------
            // We use CiviCRM's params 'invoiceID' as the unique transaction token to feed to eWAY
            // Trouble is that eWAY only accepts 16 chars for the token, while CiviCRM's invoiceID is an 32.
            // As its made from a "$invoiceID = md5(uniqid(rand(), true));" then using the first 16 chars
            // should be alright
            //----------------------------------------------------------------------------------------------------
            $uniqueTrxnNum = substr($params['invoiceID'], 0, 16);

            //----------------------------------------------------------------------------------------------------
            // OPTIONAL: If TEST Card Number force an Override of URL and CustomerID.
            // During testing CiviCRM once used the LIVE URL.
            // This code can be uncommented to override the LIVE URL that if CiviCRM does that again.
            //----------------------------------------------------------------------------------------------------
            //        if ( ( $gateway_URL == "https://www.eway.com.au/gateway_cvn/xmlpayment.asp")
            //             && ( $params['credit_card_number'] == "4444333322221111" ) ) {
            //            $ewayCustomerID = "87654321";
            //            $gateway_URL    = "https://www.eway.com.au/gateway_cvn/xmltest/testpage.asp";
            //        }

            //----------------------------------------------------------------------------------------------------
            // Now set the payment details - see http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
            //----------------------------------------------------------------------------------------------------
            $eWAYRequest->EwayCustomerID($ewayCustomerID);  //    8 Chars - ewayCustomerID                 - Required
            $eWAYRequest->InvoiceAmount($amountInCents);  //   12 Chars - ewayTotalAmount  (in cents)    - Required
            $eWAYRequest->PurchaserFirstName($params['first_name']);  //   50 Chars - ewayCustomerFirstName
            $eWAYRequest->PurchaserLastName($params['last_name']);  //   50 Chars - ewayCustomerLastName
            $eWAYRequest->PurchaserEmailAddress($params['email']);  //   50 Chars - ewayCustomerEmail
            $eWAYRequest->PurchaserAddress($fullAddress);  //  255 Chars - ewayCustomerAddress
            $eWAYRequest->PurchaserPostalCode($params['postal_code']);  //    6 Chars - ewayCustomerPostcode
            $eWAYRequest->InvoiceDescription($params['description']);  // 1000 Chars - ewayCustomerInvoiceDescription
            $eWAYRequest->InvoiceReference($params['invoiceID']);  //   50 Chars - ewayCustomerInvoiceRef
            $eWAYRequest->CardHolderName($credit_card_name);  //   50 Chars - ewayCardHoldersName            - Required
            $eWAYRequest->CardNumber($params['credit_card_number']);  //   20 Chars - ewayCardNumber                 - Required
            $eWAYRequest->CardExpiryMonth($expireMonth);  //    2 Chars - ewayCardExpiryMonth            - Required
            $eWAYRequest->CardExpiryYear($expireYear);  //    2 Chars - ewayCardExpiryYear             - Required
            $eWAYRequest->CVN($params['cvv2']);  //    4 Chars - ewayCVN                        - Required if CVN Gateway used
            $eWAYRequest->TransactionNumber($uniqueTrxnNum);  //   16 Chars - ewayTrxnNumber
            $eWAYRequest->EwayOption1($txtOptions);  //  255 Chars - ewayOption1
            $eWAYRequest->EwayOption2($txtOptions);  //  255 Chars - ewayOption2
            $eWAYRequest->EwayOption3($txtOptions);  //  255 Chars - ewayOption3
            $eWAYRequest->CustomerBillingCountry($params['country']);

            $eWAYRequest->CustomerIPAddress ($params['ip_address']);

            // Allow further manipulation of the arguments via custom hooks ..
            CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $eWAYRequest );

            //----------------------------------------------------------------------------------------------------
            // Check to see if we have a duplicate before we send
            //----------------------------------------------------------------------------------------------------
            if ( $this->_checkDupe( $params['invoiceID'] ) ) {
                return self::errorExit(9003, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from eWAY.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.' );
            }

            //----------------------------------------------------------------------------------------------------
            // Convert to XML and send the payment information
            //----------------------------------------------------------------------------------------------------
            $requestXML = $eWAYRequest->ToXML();

            $submit = curl_init( $gateway_URL );

            if ( ! $submit ) {
                return self::errorExit(9004, 'Could not initiate connection to payment gateway');
            }

            curl_setopt($submit, CURLOPT_POST,           true        );
            curl_setopt($submit, CURLOPT_RETURNTRANSFER, true        );  // return the result on success, FALSE on failure
            curl_setopt($submit, CURLOPT_POSTFIELDS,     $requestXML );
            curl_setopt($submit, CURLOPT_TIMEOUT,        36000       );
            // if open_basedir or safe_mode are enabled in PHP settings CURLOPT_FOLLOW_LOCATION won't work so don't apply it
            // it's not really required CRM-5841
            if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) {
                curl_setopt($submit, CURLOPT_FOLLOWLOCATION, 1           );  // ensures any Location headers are followed
            }

            // Send the data out over the wire
            //--------------------------------
            $responseData = curl_exec($submit);

            //----------------------------------------------------------------------------------------------------
            // See if we had a curl error - if so tell 'em and bail out
            //
            // NOTE: curl_error does not return a logical value (see its documentation), but
            //       a string, which is empty when there was no error.
            //----------------------------------------------------------------------------------------------------
            if ( (curl_errno($submit) > 0) || (strlen(curl_error($submit)) > 0) ) {
                $errorNum  = curl_errno($submit);
                $errorDesc = curl_error($submit);

                if ($errorNum == 0) { $errorNum = 9005; } // Paranoia - in the unlikely event that 'curl' errno fails

                if (strlen($errorDesc) == 0) { // Paranoia - in the unlikely event that 'curl' error fails
                    $errorDesc = "Connection to eWAY payment gateway failed";
                }

                return self::errorExit( $errorNum, $errorDesc );
            }

            //----------------------------------------------------------------------------------------------------
            // If null data returned - tell 'em and bail out
            //
            // NOTE: You will not necessarily get a string back, if the request failed for
            //       any reason, the return value will be the boolean false.
            //----------------------------------------------------------------------------------------------------
            if ( ( $responseData === false )  || (strlen($responseData) == 0) ) {
                return self::errorExit( 9006, "Error: Connection to payment gateway failed - no data returned.");
            }

            //----------------------------------------------------------------------------------------------------
            // If gateway returned no data - tell 'em and bail out
            //----------------------------------------------------------------------------------------------------
            if ( empty($responseData) ) {
                return self::errorExit( 9007, "Error: No data returned from payment gateway.");
            }

            //----------------------------------------------------------------------------------------------------
            // Success so far - close the curl and check the data
            //----------------------------------------------------------------------------------------------------
            curl_close( $submit );

            //----------------------------------------------------------------------------------------------------
            // Payment successfully sent to gateway - process the response now
            //----------------------------------------------------------------------------------------------------
            $eWAYResponse->ProcessResponse($responseData);

            //----------------------------------------------------------------------------------------------------
            // See if we got an OK result - if not tell 'em and bail out
            //----------------------------------------------------------------------------------------------------
            if ( self::isError($eWAYResponse ) ) {
                $eWayTrxnError = $eWAYResponse->Error();

                if (substr($eWayTrxnError, 0, 6) == "Error:") {
                    return self::errorExit( 9008, $eWayTrxnError);
                }
                $eWayErrorCode = substr($eWayTrxnError, 0, 2);
                $eWayErrorDesc = substr($eWayTrxnError, 3   );

                return self::errorExit( 9008, "Error: [" . $eWayErrorCode . "] - " . $eWayErrorDesc . ".");
            }

            //-----------------------------------------------------------------------------------------------------
            // Cross-Check - the unique 'TrxnReference' we sent out should match the just received 'TrxnReference'
            //
            // PLEASE NOTE: If this occurs (which is highly unlikely) its a serious error as it would mean we have
            //              received an OK status from eWAY, but their Gateway has not returned the correct unique
            //              token - ie something is broken, BUT money has been taken from the client's account,
            //              so we can't very well error-out as CiviCRM will then not process the registration.
            //              There is an error message commented out here but my preferred response to this unlikely
            //              possibility is to email 'support@eWAY.com.au'
            //-----------------------------------------------------------------------------------------------------
            $eWayTrxnReference_OUT = $eWAYRequest->GetTransactionNumber();
            $eWayTrxnReference_IN  = $eWAYResponse->InvoiceReference();

            if ($eWayTrxnReference_IN != $eWayTrxnReference_OUT) {
                // return self::errorExit( 9009, "Error: Unique Trxn code was not returned by eWAY Gateway. This is extremely unusual! Please contact the administrator of this site immediately with details of this transaction.");

                self::send_alert_email( $eWAYResponse->TransactionNumber(), $eWayTrxnReference_OUT, $eWayTrxnReference_IN, $requestXML, $responseData);
            }

            //=============
            // Success !
            //=============
            $beagleStatus = $eWAYResponse->BeagleScore();
            if ( !empty( $beagleStatus ) ) {
                $beagleStatus = ": ". $beagleStatus;
            }
            $params['trxn_result_code'] = $eWAYResponse->Status() . $beagleStatus;
            $params['gross_amount']     = $eWAYResponse->Amount();
            $params['trxn_id']          = $eWAYResponse->TransactionNumber();
        }
        return $params;
    } // end function doDirectPayment

    // None of these functions have been changed, unless mentioned.

    /**
     * Checks to see if invoice_id already exists in db
     * @param  int     $invoiceId   The ID to check
     * @return bool                 True if ID exists, else false
    */
    function _checkDupe( $invoiceId )
    {
        $contribution = new CRM_Contribute_DAO_Contribution( );
        $contribution->invoice_id = $invoiceId;
        return $contribution->find( );
    }

  /*************************************************************************************************
   * This function checks the eWAY response status - returning a boolean false if status != 'true'
   ************************************************************************************************
   *
   * @param GatewayResponse $response
   *
   * @return bool
   */
    function isError(&$response)
    {
        $status = $response->Status();

        if ( (stripos($status, "true")) === false ) {
            return true;
        }
        return false;
    }

  /**************************************************
   * Produces error message and returns from class
   *************************************************
   *
   * @param null $errorCode
   * @param null $errorMessage
   *
   * @return object
   */
    function &errorExit ( $errorCode = null, $errorMessage = null )
    {
        $e =& CRM_Core_Error::singleton( );

        if ( $errorCode ) {
            $e->push( $errorCode, 0, null, $errorMessage );
        } else {
            $e->push( 9000, 0, null, 'Unknown System Error.' );
        }
        return $e;
    }

  /**************************************************
   * NOTE: 'doTransferCheckout' not implemented
   *************************************************
   *
   * @param $params
   * @param $component
   *
   * @throws Exception
   */
    function doTransferCheckout( &$params, $component )
    {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }

  /********************************************************************************************
   * This public function checks to see if we have the right processor config values set
   *
   * NOTE: Called by Events and Contribute to check config params are set prior to trying
   *       register any credit card details
   *
   * @internal param string $mode the mode we are operating in (live or test) - not used but could be
   * to check that the 'test' mode CustomerID was equal to '87654321' and that the URL was
   * set to https://www.eway.com.au/gateway_cvn/xmltest/TestPage.asp
   *
   * @return null|string $errorMsg if any errors found - null if OK
   */
    //function checkConfig( $mode )          // CiviCRM V1.9 Declaration
    function checkConfig( )                // CiviCRM V2.0 Declaration
    {
        $errorMsg = array();

        if ( empty( $this->_paymentProcessor['subject'] ) ) {
            $errorMsg[] = ts( 'eWAY CustomerID is not set for this payment processor' );
        }

        if ( empty( $this->_paymentProcessor['url_site'] ) ) {
            $errorMsg[] = ts( 'eWAY Gateway URL is not set for this payment processor' );
        }

        // TODO: Check that recurring config values have been set

        if ( ! empty( $errorMsg ) ) {
            return implode( '<p>', $errorMsg );
        } else {
            return null;
        }
    }


  /**
   * Cancel EWay Subscription
   * All details about recurring contributions are maintained in CiviCRM, and
   * eWAY only records the Token Customer and completed contributions. Given
   * this, we can provide a do-nothing implementation of 'cancelSubscription'
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
  function cancelSubscription($message = '', $params = array() ) {
        return TRUE;
    }
  /**
   * All details about recurring contributions are maintained in CiviCRM, and
   * eWAY only records the Token Customer and completed contributions. Given
   * this, we can provide a do-nothing implementation of 'changeSubscriptionAmount'
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
    function changeSubscriptionAmount($message = '', $params = array() ) {
        return TRUE;
    }

  /**
   * @param null $entityID
   * @param null $entity
   * @param string $action
   *
   * @return string
   */
  function subscriptionURL($entityID = NULL, $entity = NULL, $action = 'cancel') {
    $url = parent::subscriptionURL($entityID, $entity, $action);
    if (stristr($url, '&cs=')) {
      return $url;
    }
    $user_id = CRM_Core_Session::singleton()->get('userID');
    $contact_id = $this->getContactID($entity, $entityID);
    if ($contact_id && $user_id != $contact_id) {
      return $url . '&cs=' . CRM_Contact_BAO_Contact_Utils::generateChecksum($contact_id, NULL, 'inf');
    }
    return $url;
  }

  /**
   * get relevant contact ID
   * @param $entity
   * @param $entityID
   *
   * @return array|int
   */
  function getContactID($entity, $entityID) {
    if ($entity == 'recur') {
      $entity = 'contribution_recur';
    }
    try {
      return civicrm_api3($entity, 'getvalue', array('id' => $entityID, 'return' => 'contact_id'));
    }
    catch (Exception $e) {
      return 0;
    }
  }

  /**
   * @param $p_eWAY_tran_num
   * @param $p_trxn_out
   * @param $p_trxn_back
   * @param $p_request
   * @param $p_response
   */
    function send_alert_email($p_eWAY_tran_num, $p_trxn_out, $p_trxn_back, $p_request, $p_response)
    {
        // Initialization call is required to use CiviCRM APIs.
        civicrm_initialize( true );

        list( $fromName, $fromEmail ) = CRM_Core_BAO_Domain::getNameAndEmail( );
        $from      = "$fromName <$fromEmail>";

        $toName    = 'Support at eWAY';
        $toEmail   = 'Support@eWAY.com.au';

        $subject   = "ALERT: Unique Trxn Number Failure : eWAY Transaction # = [". $p_eWAY_tran_num . "]";

        $message   = "
TRXN sent out with request   = '$p_trxn_out'.
TRXN sent back with response = '$p_trxn_back'.

This is a ['$this->_mode'] transaction.


Request XML =
---------------------------------------------------------------------------
$p_request
---------------------------------------------------------------------------


Response XML =
---------------------------------------------------------------------------
$p_response
---------------------------------------------------------------------------


Regards

The CiviCRM eWAY Payment Processor Module
";
        //$cc       = 'Name@Domain';

        // create the params array
        $params                = array( );

        $params['groupName'  ] = 'eWay Email Sender';
        $params['from'       ] = $from;
        $params['toName'     ] = $toName;
        $params['toEmail'    ] = $toEmail;
        $params['subject'    ] = $subject;
        $params['text'       ] = $message;

        CRM_Utils_Mail::send( $params );
    }

    // Will need to be updated when we get cron working again.
    /*
    function processRecur($params,$paymentDate,$paymentTransaction)
    {
        The code found in the eWayEmailprocessor should be here, but I was getting cron errors. These may have been fixed now and the code can be moved back into this function again.
    }
    */
}
