<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | Copyright Henare Degan (C) 2012                                    |
 +--------------------------------------------------------------------+
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007.                                       |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License along with this program; if not, contact CiviCRM LLC       |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/
/**
 * EWay API call.
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor.
 *
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_eway($params) {
  require_once 'nusoap.php';

  $apiResult = array();

  // Create eWay token clients
  $eway_token_clients = get_eway_token_clients($params['domain_id']);

  // Get any pending contributions and process them
  $pending_contributions = get_pending_recurring_contributions($eway_token_clients);

  $apiResult[] = "Processing " . count($pending_contributions) . " pending contributions";
  foreach ($pending_contributions as $pending_contribution) {
    $apiResult = array_merge($apiResult, _civicrm_api3_job_eway_process_contribution($pending_contribution));
  }

  // Process today's scheduled contributions and process them
  $scheduled_contributions = get_scheduled_contributions($eway_token_clients, $params);

  $apiResult[] = "Processing " . count($scheduled_contributions) . " scheduled contributions";
  foreach ($scheduled_contributions as $scheduled_contribution) {
    $apiResult = array_merge($apiResult, _civicrm_api3_job_eway_process_contribution($scheduled_contribution));
  }

  return civicrm_api3_create_success($apiResult, $params);
}

/**
 * Process a contribution.
 *
 * @param array $instance
 *
 * @return array
 */
function _civicrm_api3_job_eway_process_contribution($instance) {
  $apiResult = array();

  // Process the payment.
  $apiResult[] = "Processing payment for " . $instance['type'] . " contribution ID: " . $instance['contribution']->id;
  $amount_in_cents = str_replace('.', '', $instance['contribution_recur']->amount);
  $managed_customer_id = $instance['contribution_recur']->processor_id;
  $instance['contribution_recur']->contribution_status_id = _eway_recurring_get_contribution_status_id('In Progress');

  try {
    $result = civicrm_api3('ewayrecurring', 'payment', array(
      'invoice_id' => $instance['contribution']->invoice_id,
      'amount_in_cents' => $amount_in_cents,
      'managed_customer_id' => $managed_customer_id,
      'description' => !empty($instance['contribution']->source) ? $instance['contribution']->source : ts('Recurring payment'),
      'payment_processor_id' => $instance['contribution_recur']->payment_processor_id,
    ));

    // Process the contribution as either Completed or Failed.
    $apiResult[] = "Successfully processed payment for " . $instance['type'] . " contribution ID: " . $instance['contribution']->id;
    $apiResult[] = "Marking contribution as complete";
    $instance['contribution']->trxn_id = $result['values'][$managed_customer_id]['trxn_id'];
    if (empty($instance['contribution']->id)) {
      repeat_contribution($instance['contribution'], 'Completed', $amount_in_cents);
    }
    else {
      complete_contribution($instance['contribution']);
    }
    $instance['contribution_recur']->failure_count = 0;
  }
  catch (CiviCRM_API3_Exception $e) {
    $apiResult[] = "ERROR: failed to process payment for " . $instance['type'] . " contribution ID: " . $instance['contribution']->id;
    $apiResult[] = 'eWAY managed customer: ' . $instance['contribution_recur']->processor_id;
    $apiResult[] = 'eWAY response: ' . $result['faultstring'];
    $apiResult[] = "Marking contribution as failed";
    // I hate doing this save as it bypasses hooks but need a bigger review to do a better job.
    $instance['contribution']->contribution_status_id = _eway_recurring_get_contribution_status_id('Failed');
    $instance['contribution']->save();
    $instance['contribution_recur']->failure_count += 1;
    if (_eway_recurring_is_recurring_expired($instance['contribution_recur']->id)) {
      $instance['contribution_recur']->contribution_status_id = _eway_recurring_get_contribution_status_id('Cancelled');
      $instance['contribution_recur']->cancel_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
    }
  }

  // Update the recurring transaction
  $apiResult[] = "Updating recurring contribution";
  update_recurring_contribution($instance['contribution_recur']);
  $apiResult[] = "Finished processing contribution ID: " . $instance['contribution']->id;

  return $apiResult;
}

/**
 * Alter metadata.
 *
 * @param array $params
 */
function _civicrm_api3_job_eway_spec(&$params) {
  $params['domain_id']['api.default'] = CRM_Core_Config::domainID();
  $params['domain_id']['type'] = CRM_Utils_Type::T_INT;
  $params['domain_id']['title'] = ts('Domain');
  $params['contribution_recur_id']['title'] = ts('Recurring Contribution ID (optional to only process one entity)');
  $params['contribution_recur_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * Get the eWAY recurring payment processors as an array of client objects.
 *
 * @param int $domainID
 *
 * @throws CiviCRM_API3_Exception
 *
 * @return array
 *   An associative array of Processor Id => eWAY Token Client
 */
function get_eway_token_clients($domainID) {
  $params = array(
    'class_name' => 'Payment_Ewayrecurring',
    'return' => 'id',
  );
  if (!empty($domainID)) {
    $params['domain_id'] = $domainID;
  }

  $processors = civicrm_api3('payment_processor', 'get', $params);
  $result = array();
  foreach (array_keys($processors['values']) as $id) {
    $result[$id] = CRM_Core_Payment_EwayUtils::getClient($id);
  }
  return $result;
}

/**
 * Get first_contribution_from_recurring.
 *
 * find the latest contribution belonging to the recurring contribution so that we
 * can extract some info for cloning, like source etc
 *
 * @param int $recur_id
 *
 * @return CRM_Contribute_BAO_Contribution
 *   Contribution Object.
 */
function get_first_contribution_from_recurring($recur_id) {
  $contributions = new CRM_Contribute_BAO_Contribution();
  $contributions->whereAdd("`contribution_recur_id` = " . $recur_id);
  $contributions->orderBy("`id`");
  $contributions->find();

  while ($contributions->fetch()) {
    return clone ($contributions);
  }
}

/**
 * Get pending_recurring_contributions.
 *
 * Gets recurring contributions that are in a pending state.
 * These are for newly created recurring contributions and should
 * generally be processed the same day they're created. These do not
 * include the regularly processed recurring transactions.
 *
 * @param $eway_token_clients
 *
 * @return array
 *   Array of associative arrays containing contribution & contribution_recur objects.
 */
function get_pending_recurring_contributions($eway_token_clients) {
  if (empty($eway_token_clients)) {
    return array();
  }

  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  // Get the Recurring Contributions that are Pending for this Payment Processor
  $recurring = new CRM_Contribute_BAO_ContributionRecur();
  $recurring->whereAdd("`contribution_status_id` = " . array_search('Pending', $contributionStatus));
  $recurring->whereAdd("`payment_processor_id` in (" . implode(', ', array_keys($eway_token_clients)) . ")");
  $recurring->find();

  $result = array();

  while ($recurring->fetch()) {
    // Get the Contribution.
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->whereAdd("`contribution_recur_id` = " . $recurring->id);

    if ($contribution->find(TRUE)) {
      $result[] = array(
        'type' => 'Pending',
        'contribution' => clone ($contribution),
        'contribution_recur' => clone ($recurring),
      );
    }
  }
  return $result;
}

/**
 * Gets recurring contributions that are scheduled to be processed today.
 *
 * @param array $eway_token_clients
 * @param array $params
 *
 * @return array
 *   An array of contribution_recur objects.
 */
function get_scheduled_contributions($eway_token_clients, $params) {
  if (empty($eway_token_clients)) {
    return array();
  }

  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  // Get Recurring Contributions that are In Progress and are due to be processed by the eWAY Recurring processor
  $scheduled_today = new CRM_Contribute_BAO_ContributionRecur();
  if (_versionAtLeast(4.4)) {
    $scheduled_today->whereAdd("`next_sched_contribution_date` <= '" . date('Y-m-d 00:00:00') . "'");
  }
  else {
    $scheduled_today->whereAdd("`next_sched_contribution` <= '" . date('Y-m-d 00:00:00') . "'");
  }
  if (!empty($params['contribution_recur_id'])) {
    $scheduled_today->id = $params['contribution_recur_id'];
  }
  $scheduled_today->whereAdd("`contribution_status_id` = " . array_search('In Progress', $contributionStatus));
  $scheduled_today->whereAdd("`payment_processor_id` in (" . implode(', ', array_keys($eway_token_clients)) . ")");
  $scheduled_today->find();

  $result = array();

  while ($scheduled_today->fetch()) {
    $past_contribution = get_first_contribution_from_recurring($scheduled_today->id);

    $new_contribution_record = new CRM_Contribute_BAO_Contribution();
    $new_contribution_record->contact_id = $scheduled_today->contact_id;
    $new_contribution_record->receive_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
    $new_contribution_record->total_amount = $scheduled_today->amount;
    $new_contribution_record->non_deductible_amount = $scheduled_today->amount;
    $new_contribution_record->net_amount = $scheduled_today->amount;
    $new_contribution_record->invoice_id = md5(uniqid(rand(), TRUE));
    $new_contribution_record->contribution_recur_id = $scheduled_today->id;
    $new_contribution_record->contribution_status_id = array_search('Pending', $contributionStatus);
    if (_versionAtLeast(4.4)) {
      $new_contribution_record->financial_type_id = $scheduled_today->financial_type_id;
    }
    else {
      $new_contribution_record->contribution_type_id = $scheduled_today->contribution_type_id;
    }
    $new_contribution_record->currency = $scheduled_today->currency;

    // copy info from previous contribution belonging to the same recurring contribution
    if ($past_contribution != NULL) {
      $new_contribution_record->contribution_page_id = $past_contribution->contribution_page_id;
      $new_contribution_record->payment_instrument_id = $past_contribution->payment_instrument_id;
      $new_contribution_record->source = $past_contribution->source;
      $new_contribution_record->address_id = $past_contribution->address_id;
    }

    $result[] = array(
      'type' => 'Scheduled',
      'contribution' => clone ($new_contribution_record),
      'contribution_recur' => clone ($scheduled_today),
    );
  }

  return $result;
}

/**
 * Process_eWay payment.
 *
 * Processes an eWay token payment
 *
 * @param object $soap_client
 *          An eWay SOAP client set up and ready to go
 * @param string $managed_customer_id
 *          The eWay token ID for the credit card you want to process
 * @param string $amount_in_cents
 *          The amount in cents to charge the customer
 * @param string $invoice_reference
 *          InvoiceReference to send to eWay
 * @param string $invoice_description
 *   InvoiceDescription to send to eWay
 *
 * @throws SoapFault exceptions
 *
 * @return array
 *   eWay response
 */
function process_eway_payment($soap_client, $managed_customer_id, $amount_in_cents, $invoice_reference, $invoice_description) {
  // PHP bug: https://bugs.php.net/bug.php?id=49669. issue with value greater than 2147483647.
  settype($managed_customer_id, "float");
  // @todo call the new ewayrecurring.payment api to do this & rely on it setting trxn_id
  // or throwing an Exception.
  $paymentinfo = array(
    'man:managedCustomerID' => $managed_customer_id,
    'man:amount' => $amount_in_cents,
    'man:InvoiceReference' => $invoice_reference,
    'man:InvoiceDescription' => $invoice_description,
  );
  $soapaction = 'https://www.eway.com.au/gateway/managedpayment/ProcessPayment';

  $result = $soap_client->call('man:ProcessPayment', $paymentinfo, '', $soapaction);

  return $result;
}

/**
 * Complete contribution.
 *
 * Marks a contribution as complete.
 *
 * @param CRM_Contribute_BAO_Contribution $contribution
 *  The contribution to mark as complete
 *
 * @return CRM_Contribute_BAO_Contribution
 *   The contribution object.
 */
function complete_contribution($contribution) {
  civicrm_api3('contribution', 'completetransaction', array(
    'id' => $contribution->id,
    'trxn_id' => $contribution->trxn_id,
  ));
  return $contribution;
}

/**
 * Repeat contribution.
 *
 * Marks a contribution as complete.
 *
 * @param CRM_Contribute_BAO_Contribution $contribution
 *  The contribution to mark as complete
 *
 * @param int $status_id
 *
 * @param float $amount_in_cents
 *
 * @return \CRM_Contribute_BAO_Contribution.
 *   The contribution object.
 * @throws \CiviCRM_API3_Exception
 */
function repeat_contribution($contribution, $status_id, $amount_in_cents) {
  $actions = civicrm_api3('Contribution', 'getactions', array());
  if (in_array('repeattransaction', $actions['values'])) {
    civicrm_api3('contribution', 'repeattransaction', array(
      'trxn_id' => $contribution->trxn_id,
      'contribution_status_id' => $status_id,
      'total_amount' => $amount_in_cents / 100,
      'original_contribution_id' => civicrm_api3('contribution', 'getvalue', array(
        'return' => 'id',
        'contribution_recur_id' => $contribution->contribution_recur_id,
        'options' => array('limit' => 1, 'sort' => 'id ASC'),
      )),
    ));
  }
  else {
    // Legacy - expect messed up line items. CRM-15996.
    $contribution->save();
    complete_contribution($contribution);
  }
  return $contribution;
}

/**
 * Marks a contribution as failed.
 *
 * @param CRM_Contribute_BAO_Contribution $failedContribution
 *   The contribution to mark as failed
 *
 * @return CRM_Contribute_BAO_Contribution
 *   The contribution object.
 */
function fail_contribution($failedContribution) {
  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

  $failedContribution->contribution_status_id = array_search('Failed', $contributionStatus);
  $failedContribution->receive_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
  $failedContribution->save();
  return $failedContribution;
}

/**
 * Check to see if failed contribution is expired.
 *
 * @param int $recurringContributionID
 *
 * @return bool
 *
 * @throws \CiviCRM_API3_Exception
 */
function _eway_recurring_is_recurring_expired($recurringContributionID) {
  try {
    $tokenStatus = civicrm_api3('Ewayrecurring', 'Tokenquery', array(
      'contribution_recur_id' => $recurringContributionID,
      'sequential' => 1,
    ));
  }
  catch (CiviCRM_API3_Exception $e) {
    // This means it is not valid - is there something else we should do?
    return FALSE;
  }

  if (isset($tokenStatus['values'][0]['expiry_date']) && strtotime($tokenStatus['values'][0]['expiry_date']) < strtotime('now')) {
    return TRUE;
  }
  return FALSE;

}

/**
 * Get the relevant status id.
 *
 * @param string $statusName
 *
 * @return int
 */
function _eway_recurring_get_contribution_status_id($statusName) {
  $statuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
  return array_search($statusName, $statuses);
}

/**
 * Update the recurring contribution.
 *
 * @param object $current_recur
 *   The ID of the recurring contribution
 *
 * @return object
 *   The recurring contribution object.
 */
function update_recurring_contribution($current_recur) {
  /*
   * Creating a new recurrence object as the DAO had problems saving unless all the dates were overwritten. Seems easier to create a new object and only update the fields that are needed @todo - switching to using the api would solve the DAO dates problem & api accepts 'In Progress' so no need to resolve it first.
   */
  $updated_recur = $current_recur;
  $updated_recur->id = $current_recur->id;
  $updated_recur->modified_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
  $updated_recur->failure_count = $current_recur->failure_count;

  /*
   * Update the next date to schedule a contribution. If all installments complete, mark the recurring contribution as complete
   */
  if (_versionAtLeast(4.4)) {
    $updated_recur->next_sched_contribution_date = CRM_Utils_Date::isoToMysql(date('Y-m-d 00:00:00', strtotime('+' . $current_recur->frequency_interval . ' ' . $current_recur->frequency_unit)));
  }
  else {
    $updated_recur->next_sched_contribution = CRM_Utils_Date::isoToMysql(date('Y-m-d 00:00:00', strtotime('+' . $current_recur->frequency_interval . ' ' . $current_recur->frequency_unit)));
  }
  if (isset($current_recur->installments) && $current_recur->installments > 0) {
    $contributions = new CRM_Contribute_BAO_Contribution();
    $contributions->whereAdd("`contribution_recur_id` = " . $current_recur->id);
    $contributions->find();
    if ($contributions->N >= $current_recur->installments) {
      if (_versionAtLeast(4.4)) {
        $updated_recur->next_sched_contribution_date = NULL;
      }
      else {
        $updated_recur->next_sched_contribution = NULL;
      }
      $updated_recur->contribution_status_id = _eway_recurring_get_contribution_status_id('Completed');
      $updated_recur->end_date = CRM_Utils_Date::isoToMysql(date('Y-m-d 00:00:00'));
    }
  }

  return $updated_recur->save();
}

/**
 * Sends a receipt for a contribution.
 *
 * @param string $contribution_id
 *  The ID of the contribution to mark as complete.
 */
function send_receipt_email($contribution_id) {
  civicrm_api3('contribution', 'sendconfirmation', array('id' => $contribution_id));
}

/**
 * Version agnostic receipt sending function.
 *
 * @param array $params
 */
function _sendReceipt($params) {
  if (_versionAtLeast(4.4)) {
    list($sent) = CRM_Core_BAO_MessageTemplate::sendTemplate($params);
  }
  else {
    list($sent) = CRM_Core_BAO_MessageTemplates::sendTemplate($params);
  }
  return $sent;
}

/**
 * Is version of at least the version provided.
 *
 * @param string $version
 *
 * @return bool
 */
function _versionAtLeast($version) {
  $codeVersion = explode('.', CRM_Utils_System::version());
  if (version_compare($codeVersion[0] . '.' . $codeVersion[1], $version) >= 0) {
    return TRUE;
  }
  return FALSE;
}
