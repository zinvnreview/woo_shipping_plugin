<?php
/*
Plugin Name: Shopee Express Service Shipping
Plugin URI: https://github.com/zinvnreview/woo_shipping_plugin
Description: Gộp nhiều hãng vận chuyển Shopee Xpress, J&T, GHN (có district_id), GHTK, ViettelPost thành 1 phương thức duy nhất.
Version: 0.0.2
Author: ™βụτ & ʑɨɲ
Author URI: https://github.com/zinvnreview/woo_shipping_plugin
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'woocommerce_shipping_init', function(){
    class Shopee_Express_Service extends WC_Shipping_Method {

        private $carriers = [
            'spx' => 'Shopee Xpress',
            'jnt' => 'J&T Express',
            'ghn' => 'GHN',
            'ghtk' => 'GHTK',
            'viettelpost' => 'ViettelPost',
        ];

        public function __construct( $instance_id = 0 ) {
            $this->id = 'shopee_express_service';
            $this->instance_id = absint( $instance_id );
            $this->method_title = 'Shopee Express Service';
            $this->method_description = 'Tính phí vận chuyển nhiều hãng trong 1 phương thức';

            $this->supports = [ 'shipping-zones', 'instance-settings' ];
            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option( 'enabled' );
            $this->title = $this->get_option( 'title' );
            $this->selected_carrier = $this->get_option( 'selected_carrier' );
            $this->api_keys = [
                'spx' => $this->get_option('api_key_spx'),
                'jnt' => $this->get_option('api_key_jnt'),
                'ghn' => $this->get_option('api_key_ghn'),
                'ghtk'=> $this->get_option('api_key_ghtk'),
                'viettelpost' => $this->get_option('api_key_viettelpost'),
            ];
            $this->shipping_urls = [
                'spx' => 'https://api.shopee.com/v1/shipping/cost',
                'jnt' => 'https://api.jtexpress.vn/v1/shipping/cost',
                'ghn' => 'https://online-gateway.ghn.vn/shiip/public-api/v2/shipping-order/fee',
                'ghtk'=> 'https://services.giaohangtietkiem.vn/services/shipment/fee',
                'viettelpost' => 'https://partner.viettelpost.vn/v2/order/getPriceAll',
            ];
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Kích hoạt',
                    'type' => 'checkbox',
                    'label' => 'Kích hoạt Shopee Express Service',
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => 'Tên hiển thị',
                    'type' => 'text',
                    'default' => 'Shopee Express Service',
                ],
                'selected_carrier' => [
                    'title' => 'Chọn hãng vận chuyển',
                    'type' => 'select',
                    'options' => $this->carriers,
                    'default' => 'spx',
                    'desc_tip' => true,
                    'description' => 'Chọn hãng vận chuyển để tính phí',
                ],
                'api_key_spx' => [
                    'title' => 'API Key Shopee Xpress',
                    'type' => 'text',
                    'default' => '',
                ],
                'api_key_jnt' => [
                    'title' => 'API Key J&T',
                    'type' => 'text',
                    'default' => '',
                ],
                'api_key_ghn' => [
                    'title' => 'API Key GHN',
                    'type' => 'text',
                    'default' => '',
                ],
                'api_key_ghtk' => [
                    'title' => 'API Key GHTK',
                    'type' => 'text',
                    'default' => '',
                ],
                'api_key_viettelpost' => [
                    'title' => 'API Key ViettelPost',
                    'type' => 'text',
                    'default' => '',
                ],
            ];
        }

        public function calculate_shipping( $package = [] ) {
            $carrier = $this->selected_carrier;
            $weight = $package['contents_weight'];
            $from_postcode = get_option( 'woocommerce_store_postcode' );
            $to_postcode = $package['destination']['postcode'] ?? '';

            if ( empty($to_postcode) || $weight <= 0 ) return;

            $cost = 0;

            switch($carrier) {
                case 'spx':
                    $cost = $this->get_shipping_cost_shopee($from_postcode, $to_postcode, $weight);
                    break;
                case 'jnt':
                    $cost = $this->get_shipping_cost_jnt($from_postcode, $to_postcode, $weight);
                    break;
                case 'ghn':
                    $cost = $this->get_shipping_cost_ghn($weight, $package);
                    break;
                case 'ghtk':
                    $cost = $this->get_shipping_cost_ghtk($weight, $package);
                    break;
                case 'viettelpost':
                    $cost = $this->get_shipping_cost_viettelpost($weight, $package);
                    break;
            }

            if ($cost > 0) {
                $this->add_rate([
                    'id'    => $this->id,
                    'label' => $this->title . ' - ' . $this->carriers[$carrier],
                    'cost'  => $cost,
                ]);
            }
        }

        // Hàm lấy danh sách districts GHN có cache 12h
        private function ghn_get_districts( $token ) {
            $cache_key = 'ghn_districts_cache';

            $districts = get_transient( $cache_key );
            if ( $districts !== false ) {
                return $districts;
            }

            $response = wp_remote_get( 'https://online-gateway.ghn.vn/shiip/public-api/master-data/district', [
                'headers' => [ 'Token' => $token ],
                'timeout' => 10,
            ]);

            if ( is_wp_error( $response ) ) return false;

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty($body['data']) || !is_array($body['data']) ) return false;

            set_transient( $cache_key, $body['data'], 12 * HOUR_IN_SECONDS );
            return $body['data'];
        }

        // Hàm lấy district_id GHN theo tên district
        private function ghn_get_district_id_by_name( $district_name, $token ) {
            $districts = $this->ghn_get_districts( $token );
            if ( ! $districts ) return 0;

            foreach ( $districts as $district ) {
                if ( mb_strtolower($district['DistrictName']) === mb_strtolower($district_name) ) {
                    return intval( $district['DistrictID'] );
                }
            }
            return 0;
        }

        // Hàm lấy phí GHN dựa trên district_id lấy từ địa chỉ
        private function get_shipping_cost_ghn($weight, $package) {
            $token = $this->api_keys['ghn'];
            if (empty($token)) return 0;

            $to_district_name = $package['destination']['city'] ?? '';
            $from_district_name = 'Đống Đa'; // Bạn có thể làm biến cấu hình hoặc lấy dynamic

            $from_district_id = $this->ghn_get_district_id_by_name($from_district_name, $token);
            $to_district_id = $this->ghn_get_district_id_by_name($to_district_name, $token);

            if (!$from_district_id || !$to_district_id) {
                return 0;
            }

            $weight_gram = $weight * 1000;

            $body = [
                'from_district_id' => $from_district_id,
                'to_district_id' => $to_district_id,
                'weight' => $weight_gram,
                'service_id' => 53320,
            ];

            $response = wp_remote_post($this->shipping_urls['ghn'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Token' => $token,
                ],
                'body' => json_encode($body),
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) return 0;

            $body = json_decode(wp_remote_retrieve_body($response), true);

            return isset($body['data']['total']) ? floatval($body['data']['total'] / 1000) : 0;
        }

        // Hàm mẫu các hãng khác, bạn tùy chỉnh thêm theo API thật
        private function get_shipping_cost_shopee($from, $to, $weight) {
            $params = [
                'api_key' => $this->api_keys['spx'],
                'merchant_id' => '',
                'from' => $from,
                'to' => $to,
                'weight' => $weight,
            ];
            $response = wp_remote_post($this->shipping_urls['spx'], [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($params),
                'timeout' => 10,
            ]);
            if (is_wp_error($response)) return 0;
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return isset($body['shipping_cost']) ? floatval($body['shipping_cost']) : 0;
        }
        private function get_shipping_cost_jnt($from, $to, $weight) {
            return 0; // Bạn tùy chỉnh theo API JNT
        }
        private function get_shipping_cost_ghtk($weight, $package) {
            return 0; // Bạn tùy chỉnh theo API GHTK
        }
        private function get_shipping_cost_viettelpost($weight, $package) {
            return 0; // Bạn tùy chỉnh theo API ViettelPost
        }
    }

    add_filter( 'woocommerce_shipping_methods', function( $methods ) {
        $methods['shopee_express_service'] = 'Shopee_Express_Service';
        return $methods;
    });
});
