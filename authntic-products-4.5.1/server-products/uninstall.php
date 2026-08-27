<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// remove plugin options (products are intentionally NOT deleted — use
// "Remove all products" in Settings before uninstalling if you want them gone).
$opts = array(
    'spp_enrollment_key', 'spp_server_url', 'spp_margin_tiers', 'spp_category_margins',
    'spp_sync_cursor', 'spp_autosync_enabled', 'spp_removing', 'spp_status',
    'spp_known_sources', 'spp_purge_keys', 'spp_reprice', 'spp_reprice_after',
    'spp_quote_whatsapp', 'spp_quote_button_label', 'spp_quote_price_label',
    'spp_quote_message', 'spp_quote_include_name', 'spp_quote_include_link',
    'spp_theme_hidden_cat', 'spp_theme_allowed_brands', 'spp_theme_target_blank',
    'spp_theme_search', 'spp_theme_featured_first',
    'spp_checkout_enabled', 'spp_checkout_discount', 'spp_checkout_label',
);
foreach ($opts as $o) delete_option($o);

// clear transients we set
delete_transient('spp_hidden_cat_ids');
delete_transient('spp_featured_ids');

// clear the heartbeat
wp_clear_scheduled_hook('spp_cron_sync');
