<?php

/**
 * Shared modal shell for the "popup" click behavior — one per [ctp_events]
 * container, only included when $args['click_behavior'] === 'popup'. JS
 * (assets/js/frontend.js) clones the clicked card's <template> content into
 * .ctp-events__modal-body and calls .showModal() on the <dialog>, which
 * handles focus trapping/Escape-to-close natively.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<dialog class="ctp-events__modal">
    <button type="button" class="ctp-events__modal-close" aria-label="<?php esc_attr_e('Schließen', 'churchtools-plugin'); ?>">&times;</button>
    <div class="ctp-events__modal-body"></div>
</dialog>
