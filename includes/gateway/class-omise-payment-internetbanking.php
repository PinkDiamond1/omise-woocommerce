<?php
defined( 'ABSPATH' ) or die( 'No direct script access allowed.' );

function register_omise_internetbanking() {
	require_once dirname( __FILE__ ) . '/class-omise-payment.php';

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	if ( class_exists( 'Omise_Payment_Internetbanking' ) ) {
		return;
	}

	class Omise_Payment_Internetbanking extends Omise_Payment {
		public function __construct() {
			parent::__construct();

			$this->id                 = 'omise_internetbanking';
			$this->has_fields         = true;
			$this->method_title       = __( 'Omise Internet Banking', 'omise' );
			$this->method_description = wp_kses(
				__( 'Accept payment through <strong>Internet Banking</strong> via Omise payment gateway (only available in Thailand).', 'omise' ),
				array(
					'strong' => array()
				)
			);

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );

			add_action( 'woocommerce_api_' . $this->id . '_callback', array( $this, 'callback' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'omise_assets' ) );
			add_action( 'woocommerce_order_action_' . $this->id . '_sync_payment', array( $this, 'sync_payment' ) );
		}

		/**
		 * @see WC_Settings_API::init_form_fields()
		 * @see woocommerce/includes/abstracts/abstract-wc-settings-api.php
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'omise' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Omise Internet Banking Payment', 'omise' ),
					'default' => 'no'
				),

				'title' => array(
					'title'       => __( 'Title', 'omise' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'omise' ),
					'default'     => __( 'Internet Banking', 'omise' ),
				),

				'description' => array(
					'title'       => __( 'Description', 'omise' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'omise' )
				),
			);
		}

		/**
		 * @see WC_Payment_Gateway::payment_fields()
		 * @see woocommerce/includes/abstracts/abstract-wc-payment-gateway.php
		 */
		public function payment_fields() {
			parent::payment_fields();

			Omise_Util::render_view( 'templates/payment/form-internetbanking.php', array() );
		}

		/**
		 * @inheritdoc
		 */
		public function charge( $order_id, $order ) {
			$metadata = array_merge(
				apply_filters( 'omise_charge_params_metadata', array(), $order ),
				array( 'order_id' => $order_id ) // override order_id as a reference for webhook handlers.
			);

			return OmiseCharge::create( array(
				'amount'      => $this->format_amount_subunit( $order->get_total(), $order->get_order_currency() ),
				'currency'    => $order->get_order_currency(),
				'description' => apply_filters('omise_charge_params_description', 'WooCommerce Order id ' . $order_id, $order),
				'source'      => array( 'type' => sanitize_text_field( $_POST['omise-offsite'] ) ),
				'return_uri'  => add_query_arg( 'order_id', $order_id, site_url() . "?wc-api=omise_internetbanking_callback" ),
				'metadata'    => $metadata
			) );
		}

		/**
		 * @inheritdoc
		 */
		public function result( $order_id, $order, $charge ) {
			if ( self::STATUS_FAILED == $charge['status'] ) {
				return $this->payment_failed( $charge['failure_message'] . ' (code: ' . $charge['failure_code'] . ')' );
			}

			if ( self::STATUS_PENDING == $charge['status'] ) {
				$order->add_order_note( sprintf( __( 'Omise: Redirecting buyer to %s', 'omise' ), esc_url( $charge['authorize_uri'] ) ) );

				return array (
					'result'   => 'success',
					'redirect' => $charge['authorize_uri'],
				);
			}

			return $this->payment_failed(
				sprintf(
					__( 'Please feel free to try submitting your order again, or contact our support team if you have any questions (Your temporary order id is \'%s\')', 'omise' ),
					$order_id
				)
			);
		}

		/**
		 * @return void
		 */
		public function callback() {
			if ( ! isset( $_GET['order_id'] ) || ! $order = $this->load_order( $_GET['order_id'] ) ) {
				wc_add_notice(
					wp_kses(
						__( 'We cannot validate your payment result:<br/>Note that your payment might already has been processed. Please contact our support team if you have any questions.', 'omise' ),
						array( 'br' => array() )
					),
					'error'
				);

				header( 'Location: ' . WC()->cart->get_checkout_url() );
				die();
			}

			$order->add_order_note( __( 'Omise: Validating the payment result..', 'omise' ) );

			try {
				$charge = OmiseCharge::retrieve( $this->get_charge_id_from_order() );

				if ( self::STATUS_FAILED === $charge['status'] ) {
					throw new Exception( $charge['failure_message'] . ' (code: ' . $charge['failure_code'] . ')' );
				}

				if ( self::STATUS_PENDING === $charge['status'] && ! $charge['paid'] ) {
					$order->add_order_note(
						wp_kses(
							__( 'Omise: The payment has been processing.<br/>Due to the Bank process, this might takes a few seconds or an hour. Please do a manual \'Sync Payment Status\' action from the Order Actions panel or check the payment status directly at Omise dashboard again later', 'omise' ),
							array( 'br' => array() )
						)
					);
					$order->update_status( 'on-hold' );

					WC()->cart->empty_cart();

					header( 'Location: ' . $order->get_checkout_order_received_url() );
					die();
				}

				if ( self::STATUS_SUCCESSFUL === $charge['status'] && $charge['paid'] ) {
					$order->add_order_note(
						sprintf(
							wp_kses(
								__( 'Omise: Payment successful.<br/>An amount %1$s %2$s has been paid', 'omise' ),
								array( 'br' => array() )
							),
							$order->get_total(),
							$order->get_order_currency()
						)
					);

					$order->payment_complete();

					WC()->cart->empty_cart();

					header( 'Location: ' . $order->get_checkout_order_received_url() );
					die();
				}

				throw new Exception( __( 'Note that your payment might already has been processed. Please contact our support team if you have any questions.', 'omise' ) );
			} catch ( Exception $e ) {
				wc_add_notice(
					sprintf(
						wp_kses(
							__( 'Seems we cannot process your payment properly:<br/>%s', 'omise' ),
							array( 'br' => array() )
						),
						$e->getMessage()
					),
					'error'
				);

				$order->add_order_note(
					sprintf(
						wp_kses(
							__( 'Omise: Payment failed.<br/>%s', 'omise' ),
							array( 'br' => array() )
						),
						$e->getMessage()
					)
				);

				$order->update_status( 'failed' );

				header( 'Location: ' . WC()->cart->get_checkout_url() );
				die();
			}

			wp_die( 'Access denied', 'Access Denied', array( 'response' => 401 ) );
			die();
		}

		/**
		 * Register all javascripts
		 */
		public function omise_assets() {
			if ( ! is_checkout() || ! $this->is_available() ) {
				return;
			}

			wp_enqueue_style( 'omise-payment-form-internetbanking-css', plugins_url( '../../assets/css/payment/form-internetbanking.css', __FILE__ ), array(), OMISE_WOOCOMMERCE_PLUGIN_VERSION );
		}
	}

	if ( ! function_exists( 'add_omise_internetbanking' ) ) {
		/**
		 * @param  array $methods
		 *
		 * @return array
		 */
		function add_omise_internetbanking( $methods ) {
			$methods[] = 'Omise_Payment_Internetbanking';
			return $methods;
		}

		add_filter( 'woocommerce_payment_gateways', 'add_omise_internetbanking' );
	}
}
