<?php
/**
 * The Ask AI review report.
 *
 * Expects $stored_review — a normalised report array from VibeSnip_AI::review().
 * Included from admin/views/edit.php (itself required inside a method, so these
 * variables are method-local, not globals).
 *
 * The report is ADVISORY. It deliberately does not claim the code is
 * syntactically valid: VibeSnip_Guard::validate() is the only authority on that,
 * and it runs before any activation regardless of what this says.
 *
 * @package VibeSnip
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this file is require()d inside VibeSnip_Admin::render_editor(); its variables are method-local, not global.

defined( 'ABSPATH' ) || exit;

if ( empty( $stored_review ) || ! is_array( $stored_review ) ) {
	return;
}

$verdict = isset( $stored_review['verdict'] ) ? $stored_review['verdict'] : 'caution';

$verdict_labels = array(
	'safe'    => __( 'Looks safe', 'vibesnip' ),
	'caution' => __( 'Use caution', 'vibesnip' ),
	'unsafe'  => __( 'Not safe', 'vibesnip' ),
);
$verdict_label = isset( $verdict_labels[ $verdict ] ) ? $verdict_labels[ $verdict ] : $verdict_labels['caution'];

$sections = array(
	__( 'What it does', 'vibesnip' )           => isset( $stored_review['what_it_does'] ) ? $stored_review['what_it_does'] : '',
	__( 'Effect on your site', 'vibesnip' )    => isset( $stored_review['site_impact'] ) ? $stored_review['site_impact'] : '',
	__( 'When it should run', 'vibesnip' )     => isset( $stored_review['recommended_conditions'] ) ? $stored_review['recommended_conditions'] : '',
	__( 'After you switch it on', 'vibesnip' ) => isset( $stored_review['notes'] ) ? $stored_review['notes'] : '',
);

// Chips that fill in form fields. Rendered only where the report actually
// disagrees with, or adds to, what is already on the form.
$chips = array();
if ( ! empty( $stored_review['suggested_title'] ) && $stored_review['suggested_title'] !== $snippet->title ) {
	$chips[] = array(
		'field' => 'title',
		'value' => $stored_review['suggested_title'],
		/* translators: %s: suggested snippet name. */
		'label' => sprintf( __( 'Name: %s', 'vibesnip' ), $stored_review['suggested_title'] ),
	);
}
if ( ! empty( $stored_review['recommended_type'] ) && $stored_review['recommended_type'] !== $snippet->type ) {
	$types = VibeSnip_Snippet::types();
	$chips[] = array(
		'field' => 'type',
		'value' => $stored_review['recommended_type'],
		/* translators: %s: snippet type label. */
		'label' => sprintf( __( 'Type: %s', 'vibesnip' ), isset( $types[ $stored_review['recommended_type'] ] ) ? $types[ $stored_review['recommended_type'] ] : $stored_review['recommended_type'] ),
	);
}
if ( ! empty( $stored_review['recommended_location'] ) && $stored_review['recommended_location'] !== $snippet->location ) {
	$chips[] = array(
		'field' => 'location',
		'value' => $stored_review['recommended_location'],
		/* translators: %s: location label. */
		'label' => sprintf( __( 'Where: %s', 'vibesnip' ), VibeSnip_Snippet::location_label( $stored_review['recommended_location'] ) ),
	);
}
if ( ! empty( $stored_review['suggested_tags'] ) && is_array( $stored_review['suggested_tags'] ) ) {
	$tag_value = implode( ', ', $stored_review['suggested_tags'] );
	if ( $tag_value !== $snippet->tags ) {
		$chips[] = array(
			'field' => 'tags',
			'value' => $tag_value,
			/* translators: %s: comma-separated tags. */
			'label' => sprintf( __( 'Tags: %s', 'vibesnip' ), $tag_value ),
		);
	}
}
?>
<div class="vibesnip-panel vibesnip-review-panel vs-verdict-<?php echo esc_attr( $verdict ); ?>">
	<div class="vibesnip-review-head">
		<span class="vs-verdict-chip vs-verdict-<?php echo esc_attr( $verdict ); ?>"><?php echo esc_html( $verdict_label ); ?></span>
		<span class="vibesnip-review-advisory"><?php esc_html_e( 'AI assessment — an opinion, not a syntax check.', 'vibesnip' ); ?></span>
	</div>

	<p class="vibesnip-review-summary"><?php echo esc_html( $stored_review['summary'] ); ?></p>

	<?php if ( ! empty( $stored_review['risks'] ) && is_array( $stored_review['risks'] ) ) : ?>
		<h3 class="vibesnip-review-h"><?php esc_html_e( 'Risks', 'vibesnip' ); ?></h3>
		<ul class="vibesnip-review-risks">
			<?php foreach ( $stored_review['risks'] as $risk ) : ?>
				<li><?php echo esc_html( $risk ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php foreach ( $sections as $heading => $text ) : ?>
		<?php if ( '' !== trim( (string) $text ) ) : ?>
			<h3 class="vibesnip-review-h"><?php echo esc_html( $heading ); ?></h3>
			<p><?php echo esc_html( $text ); ?></p>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php if ( ! empty( $chips ) ) : ?>
		<h3 class="vibesnip-review-h"><?php esc_html_e( 'Suggestions', 'vibesnip' ); ?></h3>
		<div class="vibesnip-review-chips">
			<?php foreach ( $chips as $chip ) : ?>
				<button type="button" class="vs-apply-chip" data-field="<?php echo esc_attr( $chip['field'] ); ?>" data-value="<?php echo esc_attr( $chip['value'] ); ?>">
					<?php echo esc_html( $chip['label'] ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p class="vibesnip-microcopy">
		<?php
		if ( ! empty( $stored_review['reviewed_at'] ) ) {
			printf(
				/* translators: 1: date and time, 2: model name. */
				esc_html__( 'Reviewed %1$s by %2$s.', 'vibesnip' ),
				esc_html( VibeSnip_Admin::format_time( $stored_review['reviewed_at'] ) ),
				esc_html( isset( $stored_review['model'] ) ? $stored_review['model'] : '' )
			);
		}
		if ( empty( $stored_review['should_activate'] ) ) {
			echo ' ' . esc_html__( 'The reviewer did not recommend switching this on as-is.', 'vibesnip' );
		}
		?>
	</p>
</div>
