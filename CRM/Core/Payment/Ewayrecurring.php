<?php

// As this handles recurring and non-recurring, we also need to include original api libraries
require_once 'packages/eWAY/eWAY_GatewayRequest.php';
require_once 'packages/eWAY/eWAY_GatewayResponse.php';

/**
 * Class CRM_Core_Payment_Ewayrecurring
 */
class CRM_Core_Payment_Ewayrecurring extends CRM_Core_Payment {

  /**
   * (not used, implicit in the API, might need to convert?)
   */
  const CHARSET  = 'UTF-8';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Class Constructor.
   *
   * @param string $mode the mode of operation: live or test
   * @param array $paymentProcessor
   *
   * @return \CRM_Core_Payment_Ewayrecurring
   */
  public function __construct($mode, &$paymentProcessor) {
    // Mod is live or test.
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('eWay Recurring');
  }


  /**
   * Singleton function used to manage this object.
   *
   * This function is not required on CiviCRM 4.6.
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
  public static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Ewayrecurring($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }


  /**********************************************************
   * This function sends request and receives response from eWAY payment gateway.
   *
   * http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
   *
   * Currently these eWay params are not used for recurring :
   *  - $creditCardType = $params['credit_card_type'];
   *  - $currencyID    = $params['currencyID'];
   *  - $country        = $params['country'];
   *
   * @param array $params
   *
   * @throws Exception
   * @return array
   */
  public function doDirectPayment(&$params) {
    if (!defined('CURLOPT_SSLCERT')) {
      CRM_Core_Error::fatal(ts('eWAY - Gateway requires curl with SSL support'));
    }

    /*
     * OPTIONAL: If TEST Card Number force an Override of URL and CustomerID.
     * During testing CiviCRM once used the LIVE URL.
     * This code can be uncommented to override the LIVE URL that if CiviCRM does that again.
     * if ( ( $gateway_URL == "https://www.eway.com.au/gateway_cvn/xmlpayment.asp")
     *   && ( $params['credit_card_number'] == "4444333322221111" ) ) {
     *   $ewayCustomerID = "87654321";
     *   $gateway_URL    = "https://www.eway.com.au/gateway/rebill/test/Upload_test.aspx";
     * }
     */

    // Was the recurring payment check box checked?
    if (isset($params['is_recur']) && $params['is_recur'] == 1) {
      // Create the customer via the API.
      try{
        $result = $this->createToken($this->_paymentProcessor, $params);
      }
      catch (Exception $e) {
        return self::errorExit(9010, $e->getMessage());
      }

      // We've created the customer successfully.
      $managed_customer_id = $result;

      try {
        $initialPayment = civicrm_api3('ewayrecurring', 'payment', array(
          'invoice_id' => $params['invoiceID'],
          'amount_in_cents' => round(((float) $params['amount']) * 100),
          'managed_customer_id' => $managed_customer_id,
          'description' => $params['description'] . ts('first payment'),
          'payment_processor_id' => $this->_paymentProcessor['id'],
        ));

        // Here we compensate for the fact core accepts 0 as a valid frequency
        // interval and set it.
        $extra = array();
        if (empty($params['frequency_interval'])) {
          $params['frequency_interval'] = 1;
          $extra['frequency_interval'] = 1;
        }
        $params['trxn_id'] = $initialPayment['values'][$managed_customer_id]['trxn_id'];
        $params['contribution_status_id'] = 1;
        $params['payment_status_id'] = 1;
        // If there's only one installment, then the recurring contribution is now complete
        if (isset($params['installments']) && $params['installments'] == 1) {
          $status = CRM_Core_OptionGroup::getValue('contribution_status', 'Completed', 'name');
        }
        else {
          $status = CRM_Core_OptionGroup::getValue('contribution_status', 'In Progress', 'name');
        }
        // Save the eWay customer token in the recurring contribution's processor_id field.
        civicrm_api3('contribution_recur', 'create', array_merge(array(
          'id' => $params['contributionRecurID'],
          'processor_id' => $managed_customer_id,
          'contribution_status_id' => $status,
          'next_sched_contribution_date' => CRM_Utils_Date::isoToMysql(
            date('Y-m-d 00:00:00', strtotime('+' . $params['frequency_interval'] . ' ' . $params['frequency_unit']))),
        ), $extra));

        // Send recurring Notification email for user.
        $recur = new CRM_Contribute_BAO_ContributionRecur();
        $recur->id = $params['contributionRecurID'];
        $recur->find(TRUE);
        // If none found then effectively FALSE.
        $autoRenewMembership = civicrm_api3('membership', 'getcount', array('contribution_recur_id' => $recur->id));
        if ((!empty($params['selectMembership']) || !empty($params['membership_type_id'])
          && !empty($params['auto_renew']))
        ) {
          $autoRenewMembership = TRUE;
        }

        CRM_Contribute_BAO_ContributionPage::recurringNotify(
          CRM_Core_Payment::RECURRING_PAYMENT_START,
          $params['contactID'],
          CRM_Utils_Array::value('contributionPageID', $params),
          $recur,
          $autoRenewMembership
        );
      }
      catch (CiviCRM_API3_Exception $e) {
        return self::errorExit(9014, 'Initial payment not processed' . $e->getMessage());
      }

    }
    // This is a one off payment. This code is similar to in core.
    else {
      try {
        $result = $this->processSinglePayment($params);
        $params = array_merge($params, $result);

      }
      catch (CRM_Core_Exception $e) {
        return self::errorExit(9001, $e->getMessage());
      }
    }
    return $params;
  } // end function doDirectPayment

  // None of these functions have been changed, unless mentioned.

  /**
   * Checks to see if invoice_id already exists in db.
   *
   * @param int $invoiceId The ID to check.
   *
   * @param null $contributionID
   *   If a contribution exists pass in the contribution ID.
   *
   * @return bool True if ID exists, else false
   * True if ID exists, else false
   */
  protected function checkDupe($invoiceId, $contributionID = NULL) {
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->invoice_id = $invoiceId;
    $contribution->contribution_status_id = 1;
    if ($contributionID) {
      $contribution->whereAdd("id <> $contributionID");
    }
    return $contribution->find();
  }

  /*************************************************************************************************
   * This function checks the eWAY response status - returning a boolean false if status != 'true'
   ************************************************************************************************
   *
   * @param GatewayResponse $response
   *
   * @return bool
   */
  public function isError(&$response) {
    $status = $response->Status();
    if ((stripos($status, "true")) === FALSE) {
      return TRUE;
    }
    return FALSE;
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
  public function errorExit ($errorCode = NULL, $errorMessage = NULL) {
    $e = CRM_Core_Error::singleton();

    if ($errorCode) {
      $e->push($errorCode, 0, NULL, $errorMessage);
    }
    else {
      $e->push(9000, 0, NULL, 'Unknown System Error.');
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
  public function doTransferCheckout(&$params, $component) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
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
  public function checkConfig() {
    $errorMsg = array();
    // Not sure why this is not being called but appears that subject is
    // required if an @ is in the username (new style)
    if (empty($this->_paymentProcessor['subject'])) {
      $errorMsg[] = ts('eWAY CustomerID is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['url_site'])) {
      $errorMsg[] = ts('eWAY Gateway URL is not set for this payment processor');
    }

    // TODO: Check that recurring config values have been set
    if (!empty($errorMsg)) {
      if (civicrm_api3('setting', 'getvalue', array(
        'group' => 'eway',
        'name' => 'eway_developer_mode'
      ))) {
        CRM_Core_Session::setStatus(ts('Site is in developer mode so these errors are being ignored: ' . implode(', ', $errorMsg)));
        return NULL;
      }
      return implode('<p>', $errorMsg);
    }
    else {
      return NULL;
    }
  }


  /**
   * Cancel EWay Subscription.
   *
   * All details about recurring contributions are maintained in CiviCRM, and
   * eWAY only records the Token Customer and completed contributions. Given
   * this, we can provide a do-nothing implementation of 'cancelSubscription'
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
  public function cancelSubscription(&$message = '', $params = array()) {
    return TRUE;
  }

  /**
   * Change the amount of the subscription.
   *
   * All details about recurring contributions are maintained in CiviCRM, and
   * eWAY only records the Token Customer and completed contributions. Given
   * this, we can provide a do-nothing implementation of 'changeSubscriptionAmount'
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
  public function changeSubscriptionAmount(&$message = '', $params = array()) {
    return TRUE;
  }

  /**
   * Get an array of the fields that can be edited on the recurring contribution.
   *
   * Some payment processors support editing the amount and other scheduling details of recurring payments, especially
   * those which use tokens. Others are fixed. This function allows the processor to return an array of the fields that
   * can be updated from the contribution recur edit screen.
   *
   * @return array
   */
  public function getEditableRecurringScheduleFields() {
    return array('amount', 'installments', 'next_sched_contribution_date');
  }

  /**
   * Get the subscription URL.
   *
   * @param int $entityID
   * @param null $entity
   * @param string $action
   *
   * @return string
   */
  public function subscriptionURL($entityID = NULL, $entity = NULL, $action = 'cancel') {
    $url = parent::subscriptionURL($entityID, $entity, $action);
    if (!isset($url)) {
      return NULL;
    }
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
   * Get the relevant contact ID.
   *
   * @param $entity
   * @param $entityID
   *
   * @return array|int
   */
  public function getContactID($entity, $entityID) {
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
   * Pass xml to eWay gateway and return response if the call succeeds.
   *
   * @param $requestXML
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  protected function callEwayGateway($requestXML) {
    $submit = curl_init($this->_paymentProcessor['url_site']);

    if (!$submit) {
      throw new CRM_Core_Exception('Could not initiate connection to payment gateway');
    }
    curl_setopt($submit, CURLOPT_POST, TRUE);
    // Return the result on success, FALSE on failure.
    curl_setopt($submit, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($submit, CURLOPT_POSTFIELDS, $requestXML);
    curl_setopt($submit, CURLOPT_TIMEOUT, 36000);
    // if open_basedir or safe_mode are enabled in PHP settings CURLOPT_FOLLOW_LOCATION won't work so don't apply it
    // it's not really required CRM-5841
    if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) {
      // Ensure any Location headers are followed.
      curl_setopt($submit, CURLOPT_FOLLOWLOCATION, 1);
    }

    $responseData = curl_exec($submit);


    //----------------------------------------------------------------------------------------------------
    // See if we had a curl error - if so tell 'em and bail out
    //
    // NOTE: curl_error does not return a logical value (see its documentation), but
    // a string, which is empty when there was no error.
    //----------------------------------------------------------------------------------------------------
    if ((curl_errno($submit) > 0) || (strlen(curl_error($submit)) > 0)) {
      $errorNum  = curl_errno($submit);
      $errorDesc = curl_error($submit);

      if ($errorNum == 0) {
        // Paranoia - in the unlikely event that 'curl' errno fails.
        $errorNum = 9005;
      }

      if (strlen($errorDesc) == 0) {
        // Paranoia - in the unlikely event that 'curl' error fails.
        $errorDesc = "Connection to eWAY payment gateway failed";
      }

      throw new CRM_Core_Exception($errorNum . ' ' . $errorDesc);
    }

    //----------------------------------------------------------------------------------------------------
    // If NULL data returned - tell 'em and bail out
    //
    // NOTE: You will not necessarily get a string back, if the request failed for
    // any reason, the return value will be the boolean false.
    //----------------------------------------------------------------------------------------------------
    if (($responseData === FALSE) || (strlen($responseData) == 0)) {
      throw new CRM_Core_Exception("Error: Connection to payment gateway failed - no data returned.");
    }

    //----------------------------------------------------------------------------------------------------
    // If gateway returned no data - tell 'em and bail out
    //----------------------------------------------------------------------------------------------------
    if (empty($responseData)) {
      throw new CRM_Core_Exception("Error: No data returned from payment gateway.");
    }

    //----------------------------------------------------------------------------------------------------
    // Success so far - close the curl and check the data
    //----------------------------------------------------------------------------------------------------
    curl_close($submit);

    return $responseData;
  }

  /**
   * Does the CiviCRM version supports immediate recurring payments.
   *
   * At this stage this is more a place holder but not all versions can cope with doing the payment now.
   *
   * @return bool
   */
  public function supportsImmediateRecurringPayment() {
    return TRUE;
  }

  /**
   * Create token on eWay.
   *
   * @param $paymentProcessor
   * @param array $params
   *
   * @return int
   *   Unique id of the token created to manage this customer in eway.
   *
   * @throws \CRM_Core_Exception
   */
  protected function createToken($paymentProcessor, $params) {
    if (civicrm_api3('setting', 'getvalue', array(
      'group' => 'eway',
      'name' => 'eway_developer_mode'
      ))) {
      // I'm not sure about setting status as in future we might do this in an api call.
      CRM_Core_Session::setStatus(ts('Site is in developer mode. No communication with eway has taken place'));
      return uniqid();
    }
    $gateway_URL = $paymentProcessor['url_recur'];
    $soap_client = new nusoap_client($gateway_URL, FALSE);
    $err = $soap_client->getError();
    if ($err) {
      throw new CRM_Core_Exception(htmlspecialchars($soap_client->getDebug(), ENT_QUOTES));
    }

    // Set namespace.
    $soap_client->namespaces['man'] = 'https://www.eway.com.au/gateway/managedpayment';

    // Set SOAP header.
    $headers = "<man:eWAYHeader><man:eWAYCustomerID>"
      . $this->_paymentProcessor['subject']
      . "</man:eWAYCustomerID><man:Username>"
      . $this->_paymentProcessor['user_name']
      . "</man:Username><man:Password>"
      . $this->_paymentProcessor['password']
      . "</man:Password></man:eWAYHeader>";
    $soap_client->setHeaders($headers);

    // Add eWay customer.
    $requestBody = array(
      // Crazily eWay makes this a mandatory field with fixed values.
      'man:Title' => 'Mr.',
      'man:FirstName' => $params['first_name'],
      'man:LastName' => $params['last_name'],
      'man:Address' => $params['street_address'],
      'man:Suburb' => $params['city'],
      'man:State' => $params['state_province'],
      'man:Company' => '',
      'man:PostCode' => $params['postal_code'],
      // TODO: Remove this hardcoded hack - use $params['country']
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
      'man:CCNameOnCard' => $this->getCreditCardName($params),
      'man:CCExpiryMonth' => $this->getCreditCardExpiryMonth($params),
      'man:CCExpiryYear' => $this->getCreditCardExpiryYear($params),
    );
    // Hook to allow customer info to be changed before submitting it.
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $requestBody);
    $soapAction = 'https://www.eway.com.au/gateway/managedpayment/CreateCustomer';
    $result = $soap_client->call('man:CreateCustomer', $requestBody, '', $soapAction);
    if ($result === FALSE) {
      throw new CRM_Core_Exception('Failed to create managed customer - result is FALSE');
    }
    elseif (is_array($result)) {
      throw new CRM_Core_Exception('Failed to create managed customer - result ('
        . implode(', ', array_keys($result))
        . ') is ('
        . implode(', ', $result)
        . ')');
    }
    elseif (!is_numeric($result)) {
      throw new CRM_Core_Exception('Failed to create managed customer - result is ' . $result);
    }
    return $result;
  }

  /**
   * Get Credit card name from parameters.
   *
   * @param array $params
   *
   * @return string
   *   Credit card name
   */
  protected function getCreditCardName(&$params) {
    $credit_card_name = $params['first_name'] . " ";
    if (strlen($params['middle_name']) > 0) {
      $credit_card_name .= $params['middle_name'] . " ";
    }
    $credit_card_name .= $params['last_name'];
    return $credit_card_name;
  }

  /**
   * Get credit card expiry month.
   *
   * @param array $params
   *
   * @return string
   */
  protected function getCreditCardExpiryYear(&$params) {
    $expireYear = substr($params['year'], 2, 2);
    return $expireYear;
  }

  /**
   * Get credit card expiry month.
   *
   * 2 Chars Required parameter.
   *
   * @param $params
   *
   * @return string
   */
  protected function getCreditCardExpiryMonth(&$params) {
    return sprintf('%02d', (int) $params['month']);
  }

  /**
   * Get amount in cents.
   *
   * eg. 100 for $1
   *
   * @param $params
   *
   * @return float
   */
  protected function getAmountInCents(&$params) {
    $amountInCents = round(((float) $params['amount']) * 100);
    return $amountInCents;
  }

  /**
   * Get request to send to eWay.
   *
   * @param $params
   *   Form parameters - this could be altered by hook so is a reference
   *
   * @return GatewayRequest
   * @throws \CRM_Core_Exception
   */
  protected function getEwayRequest(&$params) {
    $eWAYRequest = new GatewayRequest();

    if (($eWAYRequest == NULL) || (!($eWAYRequest instanceof GatewayRequest))) {
      throw new CRM_Core_Exception("Error: Unable to create eWAY Request object.");
    }

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
    //  if ( ( $gateway_URL == "https://www.eway.com.au/gateway_cvn/xmlpayment.asp")
    //  && ($params['credit_card_number'] == "4444333322221111" )) {
    //  $ewayCustomerID = "87654321";
    //  $gateway_URL    = "https://www.eway.com.au/gateway_cvn/xmltest/testpage.asp";
    //  }

    // 8 Chars - ewayCustomerID - Required
    $eWAYRequest->EwayCustomerID($this->_paymentProcessor['subject']);
    // 12 Chars - ewayTotalAmount (in cents) - Required
    $eWAYRequest->InvoiceAmount($this->getAmountInCents($params));
    // 50 Chars - ewayCustomerFirstName
    $eWAYRequest->PurchaserFirstName($params['first_name']);
    // 50 Chars - ewayCustomerLastName
    $eWAYRequest->PurchaserLastName($params['last_name']);
    // 50 Chars - ewayCustomerEmail
    $eWAYRequest->PurchaserEmailAddress(CRM_Utils_Array::value('email', $params));
    // 255 Chars - ewayCustomerAddress
    $eWAYRequest->PurchaserAddress($fullAddress);
    // 6 Chars - ewayCustomerPostcode
    $eWAYRequest->PurchaserPostalCode($params['postal_code']);
    // 1000 Chars - ewayCustomerInvoiceDescription
    $eWAYRequest->InvoiceDescription($params['description']);
    // 50 Chars - ewayCustomerInvoiceRef
    $eWAYRequest->InvoiceReference($params['invoiceID']);
    // 50 Chars - ewayCardHoldersName - Required
    $eWAYRequest->CardHolderName($this->getCreditCardName($params));
    // 20 Chars - ewayCardNumber  - Required
    $eWAYRequest->CardNumber($params['credit_card_number']);
    $eWAYRequest->CardExpiryMonth($this->getCreditCardExpiryMonth($params));
    // 2 Chars - ewayCardExpiryYear - Required.
    $eWAYRequest->CardExpiryYear($this->getCreditCardExpiryYear($params));
    // 4 Chars - ewayCVN - Required if CVN Gateway used
    $eWAYRequest->CVN($params['cvv2']);
    // 16 Chars - ewayTrxnNumber
    $eWAYRequest->TransactionNumber($uniqueTrxnNum);
    // 255 Chars - ewayOption1
    $eWAYRequest->EwayOption1('');
    // 255 Chars - ewayOption2
    $eWAYRequest->EwayOption2('');
    // 255 Chars - ewayOption3
    $eWAYRequest->EwayOption3('');
    $eWAYRequest->CustomerBillingCountry($params['country']);

    $eWAYRequest->CustomerIPAddress($params['ip_address']);

    // Allow further manipulation of the arguments via custom hooks ..
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $eWAYRequest);

    // Check for a duplicate after the hook has been called.
    if ($this->checkDupe($params['invoiceID'], CRM_Utils_Array::value('contributionID', $params))) {
      throw new CRM_Core_Exception('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipts.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.');
    }
    return $eWAYRequest;
  }

  /**
   * Process a one-off payment and return result or throw exception.
   *
   * @param $params
   *
   * @return array
   *   Result of payment.
   * @throws \CRM_Core_Exception
   */
  protected function processSinglePayment(&$params) {

    $eWAYRequest = $this->getEwayRequest($params);
    if ($this->getDummySuccessResult()) {
      return $this->getDummySuccessResult();
    }
    $eWAYResponse = new GatewayResponse();

    if (($eWAYResponse == NULL) || (!($eWAYResponse instanceof GatewayResponse))) {
      throw new CRM_Core_Exception("Error: Unable to create eWAY Response object.");
    }

    //----------------------------------------------------------------------------------------------------
    // Convert to XML and send the payment information
    //----------------------------------------------------------------------------------------------------
    $requestXML = $eWAYRequest->ToXML();
    $responseData = $this->callEwayGateway($requestXML);

    //----------------------------------------------------------------------------------------------------
    // Payment successfully sent to gateway - process the response now
    //----------------------------------------------------------------------------------------------------
    $eWAYResponse->ProcessResponse($responseData);

    //----------------------------------------------------------------------------------------------------
    // See if we got an OK result - if not tell 'em and bail out
    //----------------------------------------------------------------------------------------------------
    if (self::isError($eWAYResponse)) {
      $eWayTrxnError = $eWAYResponse->Error();

      if (substr($eWayTrxnError, 0, 6) == "Error:") {
        throw new CRM_Core_Exception($eWayTrxnError);
      }
      $eWayErrorCode = substr($eWayTrxnError, 0, 2);
      $eWayErrorDesc = substr($eWayTrxnError, 3);

      throw new CRM_Core_Exception("Error: [" . $eWayErrorCode . "] - " . $eWayErrorDesc . ".");
    }

    //-----------------------------------------------------------------------------------------------------
    // Cross-Check - the unique 'TrxnReference' we sent out should match the just received 'TrxnReference'
    //
    // PLEASE NOTE: If this occurs (which is highly unlikely) its a serious error as it would mean we have
    // received an OK status from eWAY, but their Gateway has not returned the correct unique
    // token - ie something is broken, BUT money has been taken from the client's account,
    // so we can't very well error-out as CiviCRM will then not process the registration.
    // There is an error message commented out here but my preferred response to this unlikely
    // possibility is to email 'support@eWAY.com.au'
    //-----------------------------------------------------------------------------------------------------
    $eWayTrxnReference_OUT = $params['invoiceID'];
    $eWayTrxnReference_IN = $eWAYResponse->InvoiceReference();

    if ($eWayTrxnReference_IN != $eWayTrxnReference_OUT) {
      // return self::errorExit( 9009, "Error: Unique Trxn code was not returned by eWAY Gateway. This is extremely unusual! Please contact the administrator of this site immediately with details of this transaction.");
    }

    $status = ($eWAYResponse->BeagleScore()) ? ($eWAYResponse->Status() . ': ' . $eWAYResponse->BeagleScore()) : $eWAYResponse->Status();
    $result = array(
      'gross_amount' => $eWAYResponse->Amount(),
      'trxn_id' => $eWAYResponse->TransactionNumber(),
      'trxn_result_code' => $status,
      'payment_status_id' => 1,
    );
    return $result;
  }

  /**
   * If the site is in developer mode then early exit with mock success.
   *
   * @return array|bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function getDummySuccessResult() {
    // If the site is in developer mode we return a mock success.
    if (civicrm_api3('setting', 'getvalue', array(
      'group' => 'eway',
      'name' => 'eway_developer_mode'
    ))) {
      return array(
        'trxn_id' => uniqid(),
        'trxn_result_code' => TRUE,
      );
    }
    return FALSE;
  }

}
