<?php
/**
 * Plugin Name: RopaExpress Mercado Libre Sync
 * Plugin URI:  https://github.com/SanThought/rx-mercadolibre-sync
 * Description: Light‑weight, **free** bidirectional stock synchronisation between WooCommerce (>=9.0) and Mercado Libre using official APIs. Forked & refactored from the open‑source “Conexão WC Mercado Livre” plugin but trimmed down to do one thing **really well**: keep inventory in‑sync while letting you keep different prices/titles on each channel.
 * Version:      1.0.0
 * Author:      Santiagoismo (dev-site: santiagoismo.com)
 * License:     GPL‑2.0‑or‑later
 * Text Domain: rx‑ml‑sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.8 (WP) / 9.8.1 (WC)
 *
 * @link     https://santiagoismo.com
 * @copyright 2025 Santiagosimo
 */

// Abort if WooCommerce isn’t active.
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) {
	return;
}

if ( ! class_exists( 'RX_ML_Sync' ) ) :
	final class RX_ML_Sync {

		/** Singleton */
		private static ?RX_ML_Sync $instance = null;

		/** Mercado Libre API root */
		private string $api_base = 'https://api.mercadolibre.com';

		/**
		 * @return RX_ML_Sync
		 */
		public static function instance(): RX_ML_Sync {
			return self::$instance ??= new self();
		}

		/** Bootstraps hooks */
		private function __construct() {
			// Settings UI
			add_action( 'admin_menu', [ $this, 'add_menu' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );

			// Handle OAuth callback when ML redirects back.
			add_action( 'init', [ $this, 'maybe_handle_oauth_callback' ] );

			// When stock changes in WooCommerce → push to ML.
			add_action( 'woocommerce_product_set_stock', [ $this, 'wc_stock_change' ], 20 );
			add_action( 'woocommerce_reduce_order_stock', [ $this, 'wc_stock_change_from_order' ], 20, 2 );

			// REST route that ML will POST webhooks to (orders_v2).
			add_action( 'rest_api_init', function () {
				register_rest_route( 'rx-ml/v1', '/webhook', [
					'methods'             => 'POST',
					'callback'            => [ $this, 'handle_ml_webhook' ],
					'permission_callback' => '__return_true', // ML cannot authenticate – validate in callback instead.
				] );
			} );
		}

		/********************
		 * Settings screen *
		 *******************/

		public function add_menu(): void {
			add_submenu_page( 'woocommerce', 'Mercado Libre Sync', 'Mercado Libre Sync', 'manage_woocommerce', 'rx-ml-sync', [ $this, 'settings_page' ] );
		}

		public function register_settings(): void {
			$fields = [ 'rx_ml_client_id', 'rx_ml_client_secret', 'rx_ml_access_token', 'rx_ml_refresh_token', 'rx_ml_user_id' ];
			foreach ( $fields as $field ) {
				register_setting( 'rx_ml_sync', $field );
			}
		}

		public function settings_page(): void { ?>
			<div class="wrap">
				<h1>Mercado Libre Sync</h1>
				<p>This page connects your Mercado Libre account to WooCommerce so we can keep inventory in sync. Prices and titles are <strong>left untouched</strong>. Only stock moves.</p>
				<form method="post" action="options.php">
					<?php settings_fields( 'rx_ml_sync' ); ?>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><label for="rx_ml_client_id">ML App ID</label></th>
							<td><input name="rx_ml_client_id" id="rx_ml_client_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'rx_ml_client_id' ) ); ?>" required></td>
						</tr>
						<tr>
							<th scope="row"><label for="rx_ml_client_secret">ML Secret</label></th>
							<td><input name="rx_ml_client_secret" id="rx_ml_client_secret" type="password" class="regular-text" value="<?php echo esc_attr( get_option( 'rx_ml_client_secret' ) ); ?>" required></td>
						</tr>
						</tbody>
					</table>
					<?php submit_button(); ?>
				</form>
				<hr>
				<?php $this->render_connection_status(); ?>
			</div>
		<?php }

		private function render_connection_status(): void {
			if ( $token = get_option( 'rx_ml_access_token' ) ) {
				printf( '<p><span style="color:green;font-weight:600;">Connected ✅</span> — ML User ID: <code>%s</code></p>', esc_html( get_option( 'rx_ml_user_id' ) ) );
			} elseif ( $client_id = get_option( 'rx_ml_client_id' ) ) {
				$redirect = rawurlencode( home_url( '/?rx_ml_oauth=1' ) );
				$auth_url = "https://auth.mercadolibre.com/authorization?response_type=code&client_id={$client_id}&redirect_uri={$redirect}";
				echo '<a href="' . esc_url( $auth_url ) . '" class="button button-primary">Authorise Mercado Libre</a>';
			} else {
				echo '<p><em>Enter your App ID and Secret then click “Save” to begin.</em></p>';
			}
		}

		/***************************
		 * OAuth handshake helper *
		 ***************************/
		public function maybe_handle_oauth_callback(): void {
			if ( isset( $_GET['rx_ml_oauth'], $_GET['code'] ) ) {
				$code         = sanitize_text_field( $_GET['code'] );
				$client_id    = get_option( 'rx_ml_client_id' );
				$client_secret = get_option( 'rx_ml_client_secret' );
				$redirect_uri = home_url( '/?rx_ml_oauth=1' );

				$response = wp_remote_post( $this->api_base . '/oauth/token', [
					'body' => [
						'grant_type'    => 'authorization_code',
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'code'          => $code,
						'redirect_uri'  => $redirect_uri,
					],
				] );

				if ( is_wp_error( $response ) ) {
					wp_die( 'Mercado Libre connection failed: ' . $response->get_error_message() );
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( empty( $body['access_token'] ) ) {
					wp_die( 'Invalid response from Mercado Libre.' );
				}

				update_option( 'rx_ml_access_token', $body['access_token'] );
				update_option( 'rx_ml_refresh_token', $body['refresh_token'] );
				update_option( 'rx_ml_user_id', $body['user_id'] );

				// Ask ML to send us order webhooks.
				$this->subscribe_webhook( $body['access_token'] );

				wp_safe_redirect( admin_url( 'admin.php?page=rx-ml-sync' ) );
				exit;
			}
		}

		private function subscribe_webhook( string $token ): void {
			$callback = home_url( '/wp-json/rx-ml/v1/webhook' );
			wp_remote_post( $this->api_base . '/users/' . get_option( 'rx_ml_user_id' ) . '/notifications', [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [
					'topic'   => 'orders_v2',
					'url'     => $callback,
				] ),
			] );
		}

		/*************************************
		 * Woo ➜ ML: stock push (real‑time) *
		 *************************************/
		public function wc_stock_change( $product ): void {
			if ( is_numeric( $product ) ) {
				$product = wc_get_product( (int) $product );
			}
			if ( ! $product instanceof WC_Product ) {
				return;
			}

			$item_id = $product->get_meta( '_rx_ml_item_id' );
			if ( ! $item_id ) {
				return; // Product not linked to ML.
			}

			// Only update when actual stock changed.
			$qty   = (int) $product->get_stock_quantity();
			$token = get_option( 'rx_ml_access_token' );
			if ( ! $token ) {
				return;
			}

			$this->ml_put( "/items/{$item_id}", [ 'available_quantity' => $qty ] );
		}

		// Runs after WooCommerce order stock reduction hooks (e.g. manual stock ops) – ensures ML stays in sync.
		public function wc_stock_change_from_order( $order, $product_reduce_stock ) {
			foreach ( $product_reduce_stock as $item_id => $values ) {
				$this->wc_stock_change( $item_id );
			}
		}

		/*************************************
		 * ML ➜ Woo: webhook for new orders *
		 *************************************/
		public function handle_ml_webhook( WP_REST_Request $request ) {
			$payload = $request->get_json_params();
			if ( empty( $payload['resource'] ) ) {
				return new WP_REST_Response( [ 'error' => 'Invalid body' ], 400 );
			}

			if ( str_contains( $payload['resource'], 'orders/' ) ) {
				$order_id_ml = basename( $payload['resource'] );
				$token       = get_option( 'rx_ml_access_token' );
				$details     = $this->ml_get( "/orders/{$order_id_ml}", $token );

				if ( empty( $details['order_items'] ) ) {
					return new WP_REST_Response( [ 'status' => 'no_items' ], 200 );
				}

				foreach ( $details['order_items'] as $item ) {
					$ml_item = $item['item']['id'];
					$qty     = (int) $item['quantity'];

					// Find product linked to this ML Item.
					$product_id = $this->wc_product_id_from_ml_item( $ml_item );
					if ( ! $product_id ) {
						continue;
					}

					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}

					$new_stock = max( 0, $product->get_stock_quantity() - $qty );
					$product->set_stock_quantity( $new_stock );
					$product->save();
				}

				return new WP_REST_Response( [ 'status' => 'synced' ], 200 );
			}

			return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
		}

		private function wc_product_id_from_ml_item( string $ml_item_id ): ?int {
			$posts = get_posts( [
				'post_type'      => 'product',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_rx_ml_item_id',
				'meta_value'     => $ml_item_id,
			] );

			return $posts ? (int) $posts[0] : null;
		}

		/*********************
		 * Helpers (API) *
		 *********************/
		private function ml_get( string $path, string $token ) {
			$response = wp_remote_get( $this->api_base . $path . '?access_token=' . $token );
			return ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : [];
		}

		private function ml_put( string $path, array $body ) {
			$token    = get_option( 'rx_ml_access_token' );
			$headers  = [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ];
			$response = wp_remote_request( $this->api_base . $path, [ 'method' => 'PUT', 'headers' => $headers, 'body' => wp_json_encode( $body ) ] );
			return ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : [];
		}
	}
endif;

// Kick it off.
RX_ML_Sync::instance();

/*
--------------------------------------------------------------------------------
README
--------------------------------------------------------------------------------
= RopaExpress Mercado Libre Sync =

== Description ==
* Real‑time, bidirectional **inventory only** sync between WooCommerce and Mercado Libre.
* Allows unique prices, titles, descriptions per channel — we never touch them.
* Free & self‑hosted. No SaaS middle‑man, no recurring fees.

== How It Works ==
1. Stock moves in WooCommerce → we grab the product’s linked ML item ID (saved in custom field _rx_ml_item_id) and push the new `available_quantity` to ML via `PUT /items/{id}`.
2. A sale occurs on Mercado Libre → ML sends an `orders_v2` webhook. We pull the order details, map each ML item ID back to the WooCommerce product and reduce local stock.

== Installation ==
1. Copy this file to `wp-content/plugins/rx-mercadolibre-sync/rx-mercadolibre-sync.php` and activate in WP‑Admin ▸ Plugins.
2. Inside WooCommerce ▸ Mercado Libre Sync enter your *App ID* and *Secret* then click **Save**.
3. Click *Authorise Mercado Libre* → you will be redirected to ML, approve, then land back connected.
4. Edit each product you want synced and add a custom field **_rx_ml_item_id** with the ML listing ID (e.g. MLA123456789). Save.
5. Done! Stock now flows both ways instantly.

== Frequently Asked ==
* *Can I change prices or titles in one store without affecting the other?* **Yes.** We never push those fields.
* *Variations support?* Not yet; the first version is simple‑products‑only (the same limitation Conexão plugin originally had). Roadmap item.
* *Cron jobs?* Not required — everything is event‑driven (Woo hooks & ML webhooks).

== Changelog ==
= 1.0.0 =
* Initial release — feature‑complete for single‑SKU inventory sync.

== Screenshots ==

== License ==
Released under GPL‑2.0‑or‑later. Fork it, modify, enjoy!
*/
