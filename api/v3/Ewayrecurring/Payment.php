<?php

/**
 * Make eway payment.
 *
 * @param array $params
 *
 * @throws API_Exception
 * @throws CiviCRM_API3_Exception
 *
 * @return array
 *   API Result array
 */
function civicrm_api3_ewayrecurring_payment($params) {
  // If the site is in developer mode we return a mock success.
  if (civicrm_api3('setting', 'getvalue', array(
    'group' => 'eway',
    'name' => 'eway_developer_mode',
  ))) {
    return civicrm_api3_create_success(array(
      $params['managed_customer_id'] => array('trxn_id' => uniqid()),
    ), $params);
  }

  $client = CRM_Core_Payment_EwayUtils::getClient($params['payment_processor_id']);
  $endPoint = 'https://www.eway.com.au/gateway/managedpayment/ProcessPayment';

  $paymentInfo = array(
    'man:managedCustomerID' => $params['managed_customer_id'],
    'man:amount' => $params['amount_in_cents'],
    'man:InvoiceReference' => $params['invoice_id'],
    'man:InvoiceDescription' => $params['description'],
  );
  $result = $client->call('man:ProcessPayment', $paymentInfo, '', $endPoint);

  if (empty($result)) {
    throw new API_Exception('No response from eWay.');
  }
  if (!empty($result['faultcode'])) {
    throw new API_Exception($result['faultstring']);
  }

  if ($result['ewayTrxnStatus'] == 'True') {
    return civicrm_api3_create_success(array($params['managed_customer_id'] => array('trxn_id' => $result['ewayTrxnNumber'])), $params);
  }
  else {
    if (!empty($result['ewayTrxnError'])) {
      throw new API_Exception($result['ewayTrxnError']);
    }
    throw new API_Exception('unknown EWAY processing error');
  }
}

/**
 * Define metadata parameters for Eway recurring payment function.
 *
 * @param array $params
 *   Existing specifications.
 */
function _civicrm_api3_ewayrecurring_payment_spec(&$params) {
  $params['amount_in_cents'] = array(
    'title' => 'Amount in cents',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => TRUE,
  );
  $params['managed_customer_id'] = array(
    'title' => 'Eway managed customer ID',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => TRUE,
  );
  $params['invoice_id'] = array(
    'title' => 'CiviCRM Invoice ID',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => TRUE,
  );
  $params['description'] = array(
    'title' => 'CiviCRM Description',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => TRUE,
  );
  $params['payment_processor_id'] = array(
    'title' => 'Payment processor ID',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => TRUE,
  );
}
