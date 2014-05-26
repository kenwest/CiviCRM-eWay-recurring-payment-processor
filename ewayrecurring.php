<?php

require_once 'ewayrecurring.civix.php';
require_once 'nusoap.php';


/**
 * Implementation of hook_civicrm_config
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
 */
function ewayrecurring_civicrm_managed(&$entities) {
  try {
    //handling for versions where job.create api does not exist
    civicrm_api3('job', 'create', array());
  }
  catch (Exception $e) {
    if(stristr($e->getMessage(), 'does not exist')) {
      return;
    }
  }
  return _ewayrecurring_civix_civicrm_managed($entities);
}
