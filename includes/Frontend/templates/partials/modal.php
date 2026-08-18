<?php

/**
 * Shared modal shell for the "popup" click behavior — one per [ctp_events]
 * container, only included when $args['click_behavior'] === 'popup'. JS
 * (assets/js/frontend.js) clones the clicked card's <template> content into
 * .ctp-events__modal-body and calls .showModal() on the <dialog>, which
 * handles focus trapping/Escape-to-close natively.
 *
 * The body carries autofocus (and the tabindex that makes it focusable at
 * all): without it, showModal()'s focusing steps land on the first focusable
 * element, which is the close button — so the popup opened with its close
 * button already lit up in the focus state, and a screen reader started on
 * "Schließen" rather than on the event. Focus stays inside the dialog either
 * way, so the trap is unaffected.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<dialog class="ctp-events__modal">
    <button type="button" class="ctp-events__modal-close" aria-label="<?php esc_attr_e('Schließen', 'churchtools-plugin'); ?>">&times;</button>
    <div class="ctp-events__modal-body" tabindex="-1" autofocus></div>
</dialog>
