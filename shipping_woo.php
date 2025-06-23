<?php
/*
Plugin Name: Shopee Xpress Shipping
Plugin URI: https://github.com/zinvnreview/woo_shipping_plugin
Description: Tích hợp dịch vụ vận chuyển Shopee Xpress với WooCommerce.
Version: 0.0.2
Author: ™βụτ & ʑɨɲ
Author URI: https://github.com/zinvnreview/woo_shipping_plugin
License: GPL2
*/

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'woocommerce_shipping_init', 'spx_shipping_method_class' );
function spx_shipping_method_class() {
    class SPX_Shipping_Method extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'spx_shipping';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = 'Shopee Xpress';
            $this->method_description = 'Tính phí vận chuyển Shopee Xpress';

            $this->supports = array(
                'shipping-zones',
                'instance-settings',
            );

            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled      = $this->get_option( 'enabled' );
            $this->title        = $this->get_option( 'title' );
            $this->api_key      = $this->get_option( 'api_key' );
            $this->merchant_id  = $this->get_option( 'merchant_id' );
            $this->shipping_url = $this->get_option( 'shipping_url' );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Kích hoạt',
                    'type'    => 'checkbox',
                    'label'   => 'Kích hoạt Shopee Xpress',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => 'Tên hiển thị',
                    'type'        => 'text',
                    'description' => 'Tên phương thức vận chuyển hiển thị cho khách hàng.',
                    'default'     => 'Shopee Xpress',
                    'desc_tip'    => true,
                ),
                'api_key' => array(
                    'title'       => 'API Key',
                    'type'        => 'text',
                    'description' => 'Nhập API Key của bạn từ Shopee Xpress.',
                    'default'     => 'Nhập API Key đê',
                ),
                'merchant_id' => array(
                    'title'       => 'Merchant ID',
                    'type'        => 'text',
                    'description' => 'Nhập Merchant ID của bạn từ Shopee Xpress.',
                    'default'     => 'Nhập Merchant ID đê',
                ),
                'shipping_url' => array(
                    'title'       => 'Shipping API URL',
                    'type'        => 'text',
                    'description' => 'URL endpoint để lấy phí vận chuyển.',
                    'default'     => 'https://api.shopee.com/v1/shipping/cost',
                ),
            );
        }

        public function calculate_shipping( $package = array() ) {
            $from_postcode = get_option( 'woocommerce_store_postcode' );
            $to_postcode   = isset( $package['destination']['postcode'] ) ? $package['destination']['postcode'] : '';
            $weight        = $package['contents_weight'];

            if ( empty( $to_postcode ) || $weight <= 0 ) {
                return;
            }

            $data = $this->get_shopee_xpress_data( $from_postcode, $to_postcode, $weight );

            if ( $data && isset( $data['shipping_cost'] ) ) {
                $rate = array(
                    'id'    => $this->id,
                    'label' => $this->title,
                    'cost'  => floatval( $data['shipping_cost'] ),
                );
                $this->add_rate( $rate );
            }
        }

        private function get_shopee_xpress_data( $from, $to, $weight ) {
            $url = $this->shipping_url;

            $params = array(
                'api_key'     => $this->api_key,
                'merchant_id' => $this->merchant_id,
                'from'        => $from,
                'to'          => $to,
                'weight'      => $weight,
            );

            $response = wp_remote_post( $url, array(
                'method'  => 'POST',
                'body'    => json_encode( $params ),
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 10,
            ));

            if ( is_wp_error( $response ) ) {
                error_log( 'Shopee Xpress API error: ' . $response->get_error_message() );
                return false;
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! is_array( $data ) || ! isset( $data['shipping_cost'] ) ) {
                error_log( 'Shopee Xpress API returned invalid data: ' . $body );
                return false;
            }

            return $data;
        }
    }
}

add_filter( 'woocommerce_shipping_methods', 'spx_add_shipping_method' );
function spx_add_shipping_method( $methods ) {
    $methods['spx_shipping'] = 'SPX_Shipping_Method';
    return $methods;
}

function spx_shipping_update() {
    require_once plugin_dir_path( __FILE__ ) . 'upldate-plugin/plugin-update-checker.php';
    $Update_plugin = PucFactory::buildUpdateChecker(
    'https://github.com/zinvnreview/woo_shipping_plugin',
    __FILE__,
    'shipping_woo',
);

$Update_plugin->setBranch('main');
add_action( 'init', 'spx_shipping_update' );
}
