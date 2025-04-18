<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

$fields = [
    'rx_ml_client_id',
    'rx_ml_client_secret',
    'rx_ml_access_token',
    'rx_ml_refresh_token',
    'rx_ml_user_id'
];

foreach ( $fields as $field ) {
    delete_option( $field );
}
