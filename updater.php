<?php
// File: updater.php

if ( ! class_exists( 'Puc_v5_Factory' ) ) {
    require_once plugin_dir_path( __DIR__ ) . 'plugin-update-checker/plugin-update-checker.php';
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/zinvnreview/woo_shipping_plugin',
    __DIR__ . '/shipping_woo.php',
    'woo_shipping_plugin'
);
$updateChecker->setBranch('main');