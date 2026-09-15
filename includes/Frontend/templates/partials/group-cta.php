<?php

/**
 * Der Absprung zu einer Gruppe in ChurchTools - ein Button, kein Kachel-Klick
 * (plan.md, G3/G4). Gleiches Fenster: Ein neuer Tab ohne Ankuendigung ist fuer
 * Screenreader schwerer zu bemerken, und der Button sagt schon, wohin es geht.
 *
 * `aria-describedby` nennt den Gruppennamen dazu: Sonst hoerte man in der
 * Linkliste eines Screenreaders nur mehrmals „In ChurchTools ansehen".
 *
 * @var array  $group
 * @var string $titleId
 */

use ChurchToolsPlugin\Frontend\GroupListRenderer;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if ((string) ($group['url'] ?? '') !== '') : ?>
    <p class="ctp-events__cta-row">
        <a class="ctp-events__cta ctp-button" href="<?php echo esc_url($group['url']); ?>" aria-describedby="<?php echo esc_attr($titleId); ?>">
            <?php echo esc_html(GroupListRenderer::ctaLabel()); ?>
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
            <?php echo Icons::external(); ?>
        </a>
    </p>
<?php endif; ?>
