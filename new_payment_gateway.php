<?php

/*
  Plugin Name: Qhantuy Payment Redirect
  Description: Paga a través de tu método favorito: tarjeta de débito/crédito, Qhantuy, QR Simple.
  Author: Inbodi SRL
  
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Pagos Qhantuy Gateway.
 *
 * Provides a Pagos Qhantuy Gateway, mainly for testing purposes.
 */
add_action('plugins_loaded', 'init_qhantuy_gateway_class');

function init_qhantuy_gateway_class() {

    class WC_Gateway_Qhantuy
        extends WC_Payment_Gateway {

        public $domain;

        /**
         * Constructor for the gateway.
         */
        public function __construct() {
            $this->domain = 'pagos_qhantuy';

            $this->id = 'qhantuy';
            $this->icon = apply_filters( 'woocommerce_custom_gateway_icon', plugins_url( 'images/logoNew.png' , __FILE__ ));
            $this->has_fields = false;
            $this->method_title = __('Qhantuy Payment Redirect', $this->domain);
            $this->method_description = __('Paga a través de tu método favorito: tarjeta de débito/crédito, Qhantuy, QR Simple.', $this->domain);
            
            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions', $this->description);
            $this->app_key = $this->get_option('app_key');
            if($this->get_option('testing')=='no'){
                $this->api_url = 'https://prod.qhantuy.com/api/checkout';
            } else {
                $this->api_url = 'https://testing.qhantuy.com/api/checkout';
            }
            $this->order_status = $this->get_option('order_status', 'completed');
	        $this->pg_logo = $this->get_option('pg_logo');
	        $this->icon = $this->get_option('pg_logo');

	        // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_custom', array($this, 'thankyou_page'));
            add_action( 'woocommerce_api_'. strtolower("WC_Gateway_Qhantuy"), array( $this, 'check_ipn_response' ) );
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        public function init_form_fields() {
            $field_arr = array(
                'enabled' => array(
                    'title' => __('Activo / Inactivo', $this->domain),
                    'type' => 'checkbox',
                    'label' => __('Habilitar método de pago', $this->domain),
                    'default' => 'no'
                ),
                'testing' => array(
                    'title' => __('Modo de Testing', $this->domain),
                    'type' => 'checkbox',
                    'label' => __('Habilitar modo de Testing Qhantuy', $this->domain),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Título', $this->domain),
                    'type' => 'text',
                    'description' => __('Título del método de pago.', $this->domain),
                    'default' => __('Paga con tu método favorito', $this->domain),
                    'desc_tip' => true,
                ),
                'order_status' => array(
                    'title' => __('Status de Orden', $this->domain),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'description' => __('Elija el estado cuando se reciba una orden exitosa.', $this->domain),
                    'default' => 'wc-completed',
                    'desc_tip' => true,
                    'options' => wc_get_order_statuses()
                ),
                'description' => array(
                    'title' => __('Descripción', $this->domain),
                    'type' => 'textarea',
                    'description' => __('Descripción del método de pago para el cliente.', $this->domain),
                    'default' => __('Paga a través de tu método favorito: tarjeta de débito/crédito, Qhantuy, QR Simple.', $this->domain),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Instrucciones', $this->domain),
                    'type' => 'textarea',
                    'description' => __('Instrucciones de pago para el cliente.', $this->domain),
                    'default' => 'Paga a través de tu método favorito: tarjeta de débito/crédito, Qhantuy, QR Simple.',
                    'desc_tip' => true,
                ),
                'app_key' => array(
                    'title' => __('Appkey', $this->domain),
                    'type' => 'text',
                    'description' => __('Appkey provista por Qhantuy', $this->domain),
                    'default' => '',
                    'desc_tip' => true,
                )
            );

            $logo_desc = "Logo de Método de Pago";
    	    if($this->icon != "") {
    		$logo_desc = "<img height='32px' src='".$this->icon."' />";
    	    }

    	    $field_arr['pg_logo'] = array(
                'title' => __('Logo de Método de Pago'),
                'type' => 'file',
                'description' => $logo_desc
            );

            $this->form_fields = $field_arr;
        }

        public function process_admin_options() {
            $this->init_settings();

            $post_data = $this->get_post_data();
            
            foreach ( $this->get_form_fields() as $key => $field ) {
                if ( 'title' !== $this->get_field_type( $field ) ) {
                    try {
                        $setting_value = $this->get_field_value( $key, $field, $post_data );

                        if($this->get_field_type( $field ) == "file" && $key == "pg_logo") {
                            if(isset($_FILES['woocommerce_qhantuy_pg_logo']) && $_FILES['woocommerce_qhantuy_pg_logo']['size'] > 0) {
                                $img_type = exif_imagetype($_FILES['woocommerce_qhantuy_pg_logo']['tmp_name']);
                                if($img_type == 1 || $img_type == 2 || $img_type == 3 || $img_type == 6) {
                                    $source = $_FILES["woocommerce_qhantuy_pg_logo"]["tmp_name"];
                                    $dest = plugin_dir_path(__FILE__)."images/".basename($_FILES["woocommerce_qhantuy_pg_logo"]["name"]);
                                    $url = plugins_url( 'images/' , __FILE__ ).basename($_FILES["woocommerce_qhantuy_pg_logo"]["name"]);
                                    move_uploaded_file($source, $dest);
                                    $setting_value = $url;
                                    $this->settings[ $key ] = $setting_value;
                                } else {
                                    //error in file type
                                    WC_Admin_Settings::add_error("Please upload image type file (JPEG/GIF/PNG).");
                                    //echo "<pre>";print_r($this);exit;
                                    return;
                                }
                            } else {
                                $url = plugins_url( 'images/' , __FILE__ ).basename('logoNew.png');
                                $this->settings[ $key ] = $url;
                            }           
                        } else {
                            $this->settings[ $key ] = $setting_value;
                        }
                    } catch ( Exception $e ) {
                        $this->add_error( $e->getMessage() );
                    }
                    }
                }
            
                return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ) );
        }

        public function thankyou_page() {
            if ($this->instructions)
                echo wpautop(wptexturize($this->instructions));
        }
        
        public function check_ipn_response() {
           $transaction_id = $_REQUEST['transaction_id'];
            global $wpdb;
            $order_data = $wpdb->get_row("SELECT * FROM `".$wpdb->postmeta."` WHERE meta_key='_transaction_id' AND meta_value='".$transaction_id."'", OBJECT);           
            $recieved_status = $_REQUEST['status'];
            $message = $_REQUEST['message'];
            //$cancel_order = $_REQUEST['cancel_order'];
            $order_id = $order_data->post_id;
            $order = wc_get_order($order_id);
            $status = $order->get_status();
            if($transaction_id==''){
                echo "Please provide transaction id";exit;
            }elseif($status=='error'&&$message){
                echo $message;exit;
            }
            //echo WC_Cart::get_cart_url();exit;
            if($recieved_status=='error')
            {
                wc_add_notice('Your order was cancelled.', $notice_type = 'notice');
                $order->update_status( 'cancelled' );
                wp_redirect(WC_Cart::get_cart_url());
                exit;
            }
            
            if($status!='completed'){
                if ($recieved_status == 'success') {
                    $order->update_status( 'completed' );
                    // Return thankyou redirect
                    wp_redirect($order->get_checkout_order_received_url());
                    WC()->cart->empty_cart();
                }
                else {
                     wc_add_notice($message, $notice_type = 'error');
                     wp_redirect($order->get_checkout_payment_url());
                }
            }
            else{
                wp_redirect($order->get_checkout_order_received_url());
                echo "This order is completed.";
            }
            exit;
        }

        public function email_instructions($order, $sent_to_admin, $plain_text = false) {
            if ($this->instructions && !$sent_to_admin && 'custom' === $order->payment_method && $order->has_status('on-hold')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            } 
        }

        public function process_payment($order_id) {
            global $woocommerce;
            $order = wc_get_order($order_id);
            $currency_code = 'BOB';
            if($order->get_currency()=='USD'){
                $currency_code = 'USD';
            }
            $order_data = $order->get_data();
            $order_total = $order->get_total();
            $order_email = $order_data['billing']['email'];
            $order_first_name = $order_data['billing']['first_name'];
            $order_last_name = $order_data['billing']['last_name'];
            $order_shipping_method = $order->get_shipping_method() ;
            $order_shipping_total = $order->get_total_shipping();
            $lineas_detalle_deuda = array();
            $main_product_array = array(
                "appkey" => $this->app_key,
                "customer_email" => $order_email,
                "customer_first_name" => $order_first_name,
                "customer_last_name" => $order_last_name,
                "currency_code" => $currency_code,
                "internal_code" => $order->get_id(),
                "payment_type" => 'REDIRECT',
                "payment_method" => 'ALL',
                "detail" => 'Pedido en woocommerce: #'.$order->get_id(),
                "callback_url" => site_url().'/?wc-api=WC_Gateway_Qhantuy'
            );

            foreach ($order->get_items() as $item_key => $item_values):
                $item_id = $item_values->get_id();
                $item_name = $item_values->get_name(); // Name of the product
                $item_data = $item_values->get_data();
                $quantity = $item_data['quantity'];
                $line_total = $item_data['total'];
                $temp["name"] = $item_name;
                $temp["quantity"] = $quantity;
                $temp["price"]= round($line_total/$quantity);
                $lineas_detalle_deuda[] = $temp;
            endforeach;

            if($order_shipping_total>0){
                $temp["name"] = 'Envío: '.$order_shipping_method;
                $temp["quantity"] = 1;
                $temp["price"]= $order_shipping_total;
                $lineas_detalle_deuda[] = $temp;                
            }
            $main_product_array["items"] = $lineas_detalle_deuda;

            $url = $this->api_url;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($main_product_array));
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $result = curl_exec($ch);

            curl_close($ch);
            $product_result = json_decode($result);

            $transaction_id = $product_result->transaction_id;
            $api_url = $product_result->payment_url;

            if (!empty($transaction_id)) {
                $order->set_transaction_id($transaction_id);
            }

            if ($product_result->process === true) {
                $status = 'wc-' === substr($this->order_status, 0, 3) ? substr($this->order_status, 3) : $this->order_status;
                $order->update_status('pending', __('Checkout with pagos qhantuy. ', $this->domain));
                $order->reduce_order_stock();
                return array(
                    'result' => 'success',
                    'redirect' => $api_url
                );
            } else {
                WC()->session->set('refresh_totals', true);
                wc_add_notice($product_result->mensaje, $notice_type = 'error');
                return array(
                    'result' => 'failure',
                    'redirect' => WC_Cart::get_checkout_url()
                );
            }
        }
    }
}

add_filter('woocommerce_payment_gateways', 'add_qhantuy_gateway_class');
function add_qhantuy_gateway_class($methods) {
    $methods[] = 'WC_Gateway_Qhantuy';
    return $methods;
}

add_filter( 'woocommerce_available_payment_gateways', 'enable_gateway_order_pay' );
function enable_gateway_order_pay( $methods ) {
    if ( is_checkout() && is_wc_endpoint_url( 'order-pay' ) ){
        $methods['qhantuy']->chosen = true;
    }
    return $methods;
}