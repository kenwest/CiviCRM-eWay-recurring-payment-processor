<?php

require_once 'ewayrecurring.civix.php';
require_once 'nusoap.php';


/**
 * Implementation of hook_civicrm_config
 *
 * @param $config
 */
function ewayrecurring_civicrm_config(&$config) {
  _ewayrecurring_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function ewayrecurring_civicrm_xmlMenu(&$files) {
  _ewayrecurring_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function ewayrecurring_civicrm_install() {
  return _ewayrecurring_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function ewayrecurring_civicrm_uninstall() {
  return _ewayrecurring_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function ewayrecurring_civicrm_enable() {
  return _ewayrecurring_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function ewayrecurring_civicrm_disable() {
  return _ewayrecurring_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function ewayrecurring_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _ewayrecurring_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @param $entities
 */
function ewayrecurring_civicrm_managed(&$entities) {
  try {
    //handling for versions where job.create api does not exist
    civicrm_api3('job', 'create', array());
  }
  catch (Exception $e) {
    if (stristr($e->getMessage(), 'does not exist')) {
      return;
    }
  }
  return _ewayrecurring_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds eway settings page to the navigation menu.
 *
 * @param array $menu
 */
function ewayrecurring_civicrm_navigationMenu(&$menu) {
  $maxID = CRM_Core_DAO::singleValueQuery("SELECT max(id) FROM civicrm_navigation");
  $parentID = CRM_Core_DAO::singleValueQuery(
    "SELECT id
     FROM civicrm_navigation n
     WHERE  n.name = 'Administer'
       AND n.domain_id = " . CRM_Core_Config::domainID()
  );
  $navID = $maxID + 288;
  $navigationMenu = array(
    'attributes' => array(
      'label' => 'Eway',
      'name' => 'eway',
      'url' => 'civicrm/eway/settings',
      'permission' => 'administer CiviCRM',
      'operator' => NULL,
      'separator' => NULL,
      'parentID' => $parentID,
      'active' => 1,
      'navID' => $navID,
    ),
  );
  if ($parentID) {
    $menu[$parentID]['child'][$navID] = $navigationMenu;
  }
  else {
    $menu[$navID] = $navigationMenu;
  }
}

/**
 * Implements of hook_civicrm_config().
 *
 * @param array $metaDataFolders
 */
function ewayrecurring_civicrm_alterSettingsFolders(&$metaDataFolders) {
  static $configured = FALSE;
  if ($configured) {
    return;
  }
  $configured = TRUE;

  $extRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR;
  $extDir = $extRoot . 'settings';
  if (!in_array($extDir, $metaDataFolders)) {
    $metaDataFolders[] = $extDir;
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * Set default credit card values when in test mode.
 *
 * @param string $formName
 * @param CRM_Contribute_Form_Contribution|CRM_Event_Form_Participant $form
 */
function ewayrecurring_civicrm_buildForm($formName, &$form) {

  $formWhiteList = array('CRM_Contribute_Form_Contribution', 'CRM_Event_Form_Participant');
  if (!in_array($formName, $formWhiteList)) {
    return;
  }

  //CRM_Core_Resources::singleton()->addScriptUrl('https://secure.ewaypayments.com/scripts/eCrypt.js');
  if (!$form->_mode == 'live' || (!civicrm_api3('setting', 'getvalue', array(
    'group' => 'eway',
    'name' => 'eway_developer_mode'
  )))) {
    return;
  }

  if (!isEwayOnlyRelevantProcessor($form)) {
    return;
  }

  CRM_Core_Session::setStatus(ts('Eway is in test mode. Test credentials have been pre-filled. No live transaction will be submitted'));
  $defaults['credit_card_number'] = '4444333322221111';
  $defaults['credit_card_type'] = 'Visa';
  $defaults['cvv2'] = '567';
  $defaults['credit_card_exp_date[Y]'] = 21;
  $defaults['credit_card_exp_date[M]'] = '1';
  $defaults['credit_card_exp_date'] = array('M' => 1, 'Y' => 2021);
  $form->setDefaults($defaults);


}

/**
 * Is eway the only relevant processor on this form.
 *
 * If eWay is the default or the only possible type on this form we can fill in the
 * default credit card values.
 *
 * @param CRM_Core_Form $form
 *
 * @return bool
 */
function isEwayOnlyRelevantProcessor(&$form) {
  if (($processors = array_keys($form->_processors)) == FALSE) {
    return FALSE;
  }
  $processors = civicrm_api3('PaymentProcessor', 'get', array('id' => array('IN' => $processors)));
  if (isset($form->_paymentProcessor) && $processors['values'][$form->_paymentProcessor['id']]['class_name'] == 'Payment_Ewayrecurring') {
    return TRUE;
  }
  foreach ($processors['values'] as $processorID => $processorSpec) {
    if ($processorSpec['class_name'] != 'Payment_Ewayrecurring') {
      return FALSE;
    }
  }
  return TRUE;
}
