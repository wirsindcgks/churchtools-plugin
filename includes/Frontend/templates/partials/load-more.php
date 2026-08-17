<?php

/**
 * "Weitere Termine laden" button for the list/grid layouts — appends the next
 * time window (see EventWindow) to the list above via the public AJAX endpoint,
 * without reloading the page.
 *
 * Only included when EventListRenderer::render() has established that there is
 * a further page at all, so it never renders as a dead control. The whole
 * instance configuration travels in one JSON data attribute rather than a
 * handful of separate ones — frontend.js writes the updated page index straight
 * back into it after each successful load, so keeping it as a single value
 * avoids having to keep several attributes in sync.
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events__more">
    <button
        type="button"
        class="ctp-events__load-more"
        data-ctp-paging="<?php echo esc_attr((string) wp_json_encode($args['paging_config'])); ?>"
    >
        <?php esc_html_e('Weitere Termine laden', 'churchtools-plugin'); ?>
    </button>
    <p class="ctp-events__more-error" role="alert" hidden>
        <?php esc_html_e('Die weiteren Termine konnten nicht geladen werden. Bitte noch einmal versuchen.', 'churchtools-plugin'); ?>
    </p>
</div>
