<?php
/**
 * Created by PhpStorm.
 * User: eileen
 * Date: 18/11/2014
 * Time: 4:59 PM
 */
require_once 'nusoap.php';

class CRM_Core_Payment_EwayUtils {
  /**
   * Get a single eWay client instance.
   *
   * (we cache the client in case of multiple requests)
   *
   * @param int $id
   *
   * @return nusoap_client
   * @throws CiviCRM_API3_Exception
   */
  public static function getClient($id) {
    static $processors = array();
    if (empty($processors[$id])) {
      $processor = civicrm_api3('payment_processor', 'getsingle', array('id' => $id));
      $processors['id'] = self::createClient($processor['url_recur'], $processor['subject'], $processor['user_name'], $processor['password']);
    }
    return $processors['id'];
  }

  /**
   * Create an eWay SOAP client to the eWay token API.
   *
   * @param string $gateway_url
   *          URL of the gateway to connect to (could be the test or live gateway)
   * @param string $eway_customer_id
   *          Your eWay customer ID
   * @param string $username
   *          Your eWay business centre username
   * @param string $password
   *          Your eWay business centre password
   *
   * @return nusoap_client
   *   A SOAP client to the eWay token API
   */
  public function createClient($gateway_url, $eway_customer_id, $username, $password) {
    // Set up SOAP client
    $soap_client = new nusoap_client($gateway_url, FALSE);
    $soap_client->namespaces['man'] = 'https://www.eway.com.au/gateway/managedpayment';

    // Set up SOAP headers
    $headers = "<man:eWAYHeader><man:eWAYCustomerID>" . $eway_customer_id .
      "</man:eWAYCustomerID><man:Username>" . $username .
      "</man:Username><man:Password>" . $password .
      "</man:Password></man:eWAYHeader>";
    $soap_client->setHeaders($headers);

    return $soap_client;
  }
}
