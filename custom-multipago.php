<?php
/*
Plugin Name: Multipago
Description: Habilita la pasarela de pago Multipago
Version: 1.0.1
Author:     Jorge Arteaga
Author URI: http://ticketeg.com
 */
if (!defined('ABSPATH')) {
	exit;
}
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	return;
}
add_filter('woocommerce_payment_gateways', 'multipago_add_gateway_class');
function multipago_add_gateway_class($gateways) {
	$gateways[] = 'WC_Multipago_Gateway';

	return $gateways;
}
add_action('plugins_loaded', 'multipago_init_gateway_class');
function multipago_init_gateway_class() {
	class WC_Multipago_Gateway extends WC_Payment_Gateway {
		public function __construct() {
			$this->id                 = 'multipago';
			$this->icon               = plugins_url( '/multipagoNew.png' , __FILE__ );
			$this->has_fields         = true;
			$this->method_title       = 'Multipago Gateway';
			$this->method_description = 'Multipago payment gateway';
			$this->supports           = [
				'products',
			];
			$this->init_form_fields();
			$this->init_settings();
			$this->title       = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->enabled     = $this->get_option('enabled');
			$this->api         = $this->get_option('url');
			$this->uid         = $this->get_option('uid');
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
			add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
			add_action('woocommerce_api_multipago-payment-complete', [$this, 'webhook']);
		}
		public function init_form_fields() {
			$this->form_fields = [
				'enabled'    	 => [
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Multipago Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				],
				'title'      	 => [
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'Multipago',
					'desc_tip'    => true,
				],
				'description' 	=> [
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay with your credit card via our Multipago.',
				],
				'url' 			=> [
					'title' => 'URL link',
					'type'  => 'text',
				], 
				'uid'         => [
					'title' => 'UID',
					'type'  => 'text',
				],
			];
		}
		public function payment_fields() {
		}
		public function payment_scripts() {
		}
		public function validate_fields() {
		}
		public function process_payment($order_id) {
			global $woocommerce;
			$order         = wc_get_order($order_id);
			debug_backtrace();
			$token_request = wp_remote_post($this->api . 'get_token', [
				'headers'     => [
					'Content-Type' => 'application/json; charset=utf-8',
				],
				'body'        => json_encode([
					'provider' => 'ridvan-academy',
					'uid'      => $this->uid,
				]),
				'method'      => 'POST',
				'data_format' => 'body',
			]);
			if (!is_wp_error($token_request)) {
				$items = [];
				foreach ($order->get_items() as $item) {
					$items[] = [
						'id'         => $item->get_product_id(),
						'unit_price' => round($item->get_total() / $item->get_quantity(), 2),
						'quantity'   => $item->get_quantity(),
						'concept'    => $item->get_product()->get_name(),
					];
				}
				$token_response = json_decode($token_request['body']);
				$order_request  = wp_remote_post($this->api . 'external_services/payorders', [
					'headers'     => [
						'Content-Type'  => 'application/json; charset=utf-8',
						'Authorization' => $token_response->data,
					],
					'body'        => json_encode([
						"service"      => [
							"code" => "RIDVAN_ACADEMY",
						],
						"payment_data" => [
							"item_selecteds"  => $items,
							"url_to_response" => site_url()."/?wc-api=multipago",
							"url_to_redirect" => site_url()."/?wc-api=multipago"
						],
						"device"       => [
							"fcm_token" => "",
						],
						"client"       => [
							"name"          => $order->get_billing_first_name(),
							"last_name"     => $order->get_billing_last_name(),
							"phone"         => ' ',
							"email"         => $order->get_billing_email(),
							"business_name" => ' ',
							'ci'            => ' ',
							'nit'           => ' ',
						],
					]),
					'method'      => 'POST',
					'data_format' => 'body',
				]);
				if (!is_wp_error($order_request)) {
					$order_response = json_decode($order_request['body']);
					$order->update_status('on-hold', 'Procesando pago');
					$order->set_transaction_id($order_response->data->pay_order->pay_order_number);
					$woocommerce->cart->empty_cart();

					return [
						'result'   => 'success',
						'redirect' => $order_response->data->url_to_pay,
					];
				} else {
					wc_add_notice('Please try again.', 'error');
					
					return;
				}
			} else {
				wc_add_notice('Please try again.', 'error');
				return;
			}
		}



		public function webhook() {
			global $wpdb;
			$order_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_transaction_id' AND meta_value='%s' LIMIT 1", $_POST['pay_order_number']));
			$order    = new WC_Order($order_id);
			$order->payment_complete();
			$order->add_order_note('El pago ha sido acreditado', true);
			$order->reduce_order_stock();
		}
	}
}