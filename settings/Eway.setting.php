<?php

return array(
  'eway_developer_mode' => array(
    'group_name' => 'eway',
    'group' => 'eway',
    'filter' => 'eway',
    'name' => 'eway_developer_mode',
    'type' => 'Boolean',
    'add' => '1.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'In developer mode transactions are not passed to eway.',
    'title' => 'Enable developer mode for eWay',
    'help_text' => 'Success is assumed',
    'default' => 0,
    'html_type' => 'checkbox',
    'settings_pages' => ['eway' => ['weight' => 10]],
  ),
);
