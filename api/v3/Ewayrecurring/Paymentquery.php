<?php

/**
 * Query Eway customer token details.
 *
 * @param array $params
 *
 * @throws API_Exception
 * @throws CiviCRM_API3_Exception
 *
 * @return array
 *   API Result array
 */
function civicrm_api3_ewayrecurring_payment_query($params) {
  $result = array();
  if (empty($params['managed_customer_id'])) {
    $recur = civicrm_api3('contribution_recur', 'getsingle', array('id' => $params['contribution_recur_id']));
    $params['managed_customer_id'] = (float) $recur['processor_id'];
    $params['payment_processor_id'] = $recur['payment_processor_id'];
  }
  $client = CRM_Core_Payment_EwayUtils::getClient($params['payment_processor_id']);
  $endPoint = 'https://www.eway.com.au/gateway/managedpayment/QueryPayment';
  $ewayData = array(
    'man:managedCustomerID' => $params['managed_customer_id'],
  );

  $ewayResult = $client->call('man:QueryPayment', $ewayData, '', $endPoint);
  if (empty($ewayResult)) {
    throw new API_Exception('No response from eWay.');
  }
  if (!empty($ewayResult['faultcode'])) {
    throw new API_Exception($ewayResult['faultstring']);
  }
  $result[$params['managed_customer_id']] = array_merge($ewayResult, array(
    'contact_id' => $recur['contact_id'],
    'code' => $params['managed_customer_id'],
    'reference' => $ewayResult['CCNumber'],
    'email' => $ewayResult['CustomerEmail'],
    'expiry_date' => date('Y-m-d', strtotime(
      $ewayResult['CCExpiryYear'] . '-' . $ewayResult['CCExpiryMonth'] .
      '-01')),
      'payment_processor_id' => $recur['payment_processor_id'],
  ));
  return civicrm_api3_create_success($result, $params);
}

/**
 * Define metadata for ewayrecurring.tokenquery.
 *
 * @param array $params
 */
function _civicrm_api3_ewayrecurring_payment_query_spec(&$params) {
  $params['contribution_recur_id'] = array(
    'api.required' => TRUE,
    'title' => 'Contribution Recur ID',
    'type' => CRM_Utils_Type::T_INT,
  );
}
