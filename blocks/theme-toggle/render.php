<?php
/**
 * Server render for openzfs-summit/theme-toggle.
 *
 * A toggle button: the label names the feature ("Dark mode") and
 * aria-pressed says whether it is on; view.js keeps aria-pressed current.
 *
 * @package openzfs-summit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<button type="button" class="ozs-theme-toggle" data-ozs-theme-toggle aria-pressed="false"
	aria-label="<?php esc_attr_e( 'Dark mode', 'openzfs-summit' ); ?>"
	title="<?php esc_attr_e( 'Dark mode', 'openzfs-summit' ); ?>">
	<svg class="ozs-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
	<svg class="ozs-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
