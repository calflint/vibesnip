<?php
/**
 * Snippet editor view.
 *
 * @package VibeSnip
 * @var VibeSnip_Snippet $snippet
 * @var int              $id
 * @var array            $revisions
 */

defined( 'ABSPATH' ) || exit;

// Every variable below is LOCAL to VibeSnip_Admin::render_editor(), which
// require's this template from inside the method — none are globals. PHPCS
// cannot see the enclosing function, so its "prefix all globals" rule is a
// false positive here; disable just that sniff for this template.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$is_new     = ! $id;
$post_url   = admin_url( 'admin-post.php' );
$manage_url = admin_url( 'admin.php?page=vibesnip' );
$all_types  = VibeSnip_Snippet::types();
$all_locs   = VibeSnip_Snippet::locations();
$err        = $snippet->error();

// On multisite, only a Super Admin may author PHP. Drop it from the picker rather
// than let someone write a snippet the save handler will refuse.
$can_php = VibeSnip_Guard::can_author( 'php' );
if ( ! $can_php ) {
	unset( $all_types['php'] );
	// A new snippet defaults to PHP, so without this the editor would open on a
	// type that is no longer in the picker — and read as locked before anything
	// had been written. Start it on the first type this user can actually author.
	if ( $is_new ) {
		$snippet->type = (string) key( $all_types );
	}
}

// An EXISTING php snippet stays visible and readable to someone who may not author
// PHP — it just cannot be edited, tested or saved.
$php_locked = ! $can_php && ! $is_new && 'php' === $snippet->type;
$ext_map    = array(
	'php'  => 'php',
	'css'  => 'css',
	'js'   => 'js',
	'html' => 'html',
	'text' => 'txt',
);
$ext  = isset( $ext_map[ $snippet->type ] ) ? $ext_map[ $snippet->type ] : 'txt';
$cond = VibeSnip_Conditions::decode( $snippet->conditions );
$cond_on       = ! empty( $cond['enabled'] );
$cond_relation = ( isset( $cond['relation'] ) && 'OR' === strtoupper( $cond['relation'] ) ) ? 'OR' : 'AND';
?>
<div class="wrap vibesnip-wrap vibesnip-editor">
	<h1 class="wp-heading-inline">
		<?php echo $is_new ? esc_html__( 'Add Snippet', 'vibesnip' ) : esc_html__( 'Edit Snippet', 'vibesnip' ); ?>
	</h1>
	<a href="<?php echo esc_url( $manage_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to all snippets', 'vibesnip' ); ?></a>

	<?php if ( $php_locked ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php echo esc_html( VibeSnip_Guard::author_denied_message() ); ?>
			<?php esc_html_e( 'You can read this snippet, but not change or activate it.', 'vibesnip' ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'error' === $snippet->status && $err ) : ?>
		<div class="notice notice-error inline"><p>
			<strong><?php esc_html_e( 'This snippet was auto-deactivated after a fatal error:', 'vibesnip' ); ?></strong>
			<?php echo esc_html( $err['message'] ); ?>
			<?php if ( ! empty( $err['line'] ) ) : ?><em>(line <?php echo (int) $err['line']; ?>)</em><?php endif; ?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $post_url ); ?>" id="vibesnip-form">
		<input type="hidden" name="action" value="vibesnip_save" />
		<input type="hidden" name="snippet_id" value="<?php echo (int) $id; ?>" />
		<?php wp_nonce_field( 'vibesnip_save' ); ?>

		<div class="vibesnip-grid">
			<div class="vibesnip-main">
				<?php // required, so the browser stops the submit and points at this box rather than filing an untitled row you will never find again. ?>
				<input type="text" name="title" id="vibesnip-title" class="vibesnip-title-input" required
					value="<?php echo esc_attr( $snippet->title ); ?>"
					placeholder="<?php esc_attr_e( 'Snippet name — required', 'vibesnip' ); ?>" />

				<div class="vibesnip-editor-shell">
					<div class="vibesnip-editor-bar lang-<?php echo esc_attr( $snippet->type ); ?>" id="vs-editor-bar">
						<span class="vs-lang-dot" aria-hidden="true"></span>
						<span class="vs-editor-name" id="vs-editor-name">snippet.<?php echo esc_html( $ext ); ?></span>
						<span class="vs-lang-pill" id="vs-lang-pill"><?php echo esc_html( strtoupper( $snippet->type ) ); ?></span>
						<?php
						// Nothing about AI appears on a site that has not switched it on — not
						// even a disabled button, which would only advertise a feature the owner
						// has deliberately declined.
						if ( VibeSnip_AI::is_enabled() && current_user_can( 'vibesnip_use_ai' ) ) :
							// The button is always visible, so the feature is discoverable even when
							// it cannot run yet. Whatever is blocking it goes in the tooltip — a
							// silently grey button reads as "Ask AI is broken". The tooltip lives on
							// the wrapper because browsers fire no hover events on a disabled button.
							$no_key   = ! VibeSnip_AI::has_key();
							$no_code  = '' === trim( (string) $snippet->code );
							$unsaved  = ! $id;
							$ask_tips = VibeSnip_AI::ask_tips();

							if ( $no_key ) {
								$ask_tip = $ask_tips['nokey'];
							} elseif ( $no_code ) {
								$ask_tip = $ask_tips['empty'];
							} elseif ( $unsaved ) {
								$ask_tip = $ask_tips['unsaved'];
							} else {
								$ask_tip = $ask_tips['ready'];
							}
							?>
							<span class="vs-ask-wrap" id="vs-ask-wrap" title="<?php echo esc_attr( $ask_tip ); ?>">
								<button type="button" class="vs-ask-btn" id="vs-ask-btn"
									data-id="<?php echo (int) $id; ?>"
									<?php echo $no_key ? 'data-nokey="1"' : ''; ?>
									<?php
									// Correct initial state server-side so the button isn't briefly dead on
									// an existing snippet; admin.js keeps it in sync as you type.
									disabled( $no_key || $no_code );
									?>>✨ <?php esc_html_e( 'Ask AI', 'vibesnip' ); ?></button>
							</span>
						<?php endif; ?>
					</div>
					<textarea name="code" id="vibesnip-code" class="vibesnip-code" spellcheck="false"><?php echo esc_textarea( $snippet->code ); ?></textarea>
				</div>
				<p class="vibesnip-hint" id="vibesnip-hint"></p>

				<?php
				// Ask AI report. Rendered server-side when one is already stored, and
				// replaced in place by admin.js after a fresh review.
				$stored_review = $snippet->review();
				?>
				<div class="vibesnip-review" id="vs-review" <?php echo $stored_review ? '' : 'hidden'; ?>>
					<?php if ( $stored_review ) : ?>
						<?php require VIBESNIP_DIR . 'admin/views/review.php'; ?>
					<?php endif; ?>
				</div>
				<p class="vibesnip-review-error" id="vs-review-error" hidden></p>

				<?php if ( $id && ! $stored_review && VibeSnip_AI::is_ready() ) : ?>
					<p class="vibesnip-nudge">
						<?php esc_html_e( 'Not sure what this code does? Click Ask AI for a plain-English safety report before you switch it on.', 'vibesnip' ); ?>
					</p>
				<?php endif; ?>

				<label class="vibesnip-field-label" for="vibesnip-description"><?php esc_html_e( 'Notes', 'vibesnip' ); ?></label>
				<textarea name="description" id="vibesnip-description" rows="2" class="widefat"
					placeholder="<?php esc_attr_e( 'What does this snippet do? (optional)', 'vibesnip' ); ?>"><?php echo esc_textarea( $snippet->description ); ?></textarea>

				<div class="vibesnip-conditions" id="vibesnip-conditions">
					<label class="vibesnip-cond-enable">
						<input type="checkbox" name="conditions_enabled" id="conditions_enabled" value="1" <?php checked( $cond_on ); ?> />
						<span><?php esc_html_e( 'Smart conditional logic — only run when these rules match', 'vibesnip' ); ?></span>
					</label>

					<div class="vibesnip-cond-body" id="vibesnip-cond-body" <?php echo $cond_on ? '' : 'hidden'; ?>>
						<p class="vibesnip-cond-relation">
							<?php esc_html_e( 'Match', 'vibesnip' ); ?>
							<select name="conditions_relation" id="conditions_relation">
								<option value="AND" <?php selected( $cond_relation, 'AND' ); ?>><?php esc_html_e( 'ALL', 'vibesnip' ); ?></option>
								<option value="OR" <?php selected( $cond_relation, 'OR' ); ?>><?php esc_html_e( 'ANY', 'vibesnip' ); ?></option>
							</select>
							<?php esc_html_e( 'of the rules below:', 'vibesnip' ); ?>
						</p>
						<div id="vs-conditions-rules" class="vibesnip-cond-rules"></div>
						<button type="button" class="button" id="vs-add-condition">+ <?php esc_html_e( 'Add rule', 'vibesnip' ); ?></button>
						<p class="vibesnip-microcopy"><?php esc_html_e( 'PHP snippets with conditions run later in the request (once page context is known) instead of as early as possible.', 'vibesnip' ); ?></p>
					</div>
				</div>
			</div>

			<div class="vibesnip-side">
				<div class="vibesnip-panel">
					<h2><?php esc_html_e( 'Save', 'vibesnip' ); ?></h2>
					<div class="vibesnip-status-line">
						<?php esc_html_e( 'Status:', 'vibesnip' ); ?>
						<span class="vs-status vs-<?php echo esc_attr( $snippet->status ); ?>"><?php echo esc_html( $snippet->status ); ?></span>
					</div>
					<?php if ( $php_locked ) : ?>
						<p class="vibesnip-microcopy"><?php echo esc_html( VibeSnip_Guard::author_denied_message() ); ?></p>
					<?php else : ?>
						<div class="vibesnip-save-buttons">
							<?php $activate_first = ! empty( VibeSnip_Admin::prefs()['activate_by_default'] ); ?>
							<button type="submit" name="save" class="button <?php echo $activate_first ? 'button-secondary' : 'button-primary'; ?>"><?php esc_html_e( 'Save', 'vibesnip' ); ?></button>
							<button type="submit" name="save_activate" class="button <?php echo $activate_first ? 'button-primary' : 'button-secondary'; ?>"><?php esc_html_e( 'Save & Activate', 'vibesnip' ); ?></button>
						</div>
						<p class="vibesnip-microcopy"><?php esc_html_e( 'PHP is syntax-checked before it can go active. If a live snippet ever fatals, VibeSnip switches it off automatically.', 'vibesnip' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="vibesnip-panel">
					<h2><?php esc_html_e( 'Behaviour', 'vibesnip' ); ?></h2>

					<label class="vibesnip-field-label" for="vibesnip-type"><?php esc_html_e( 'Type', 'vibesnip' ); ?></label>
					<select name="type" id="vibesnip-type" class="widefat">
						<?php foreach ( $all_types as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $snippet->type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>

					<label class="vibesnip-field-label" for="vibesnip-location"><?php esc_html_e( 'Where it runs', 'vibesnip' ); ?></label>
					<select name="location" id="vibesnip-location" class="widefat">
						<?php
						foreach ( $all_locs as $slug => $meta ) :
							$applies = implode( ' ', $meta[1] );
							?>
							<option value="<?php echo esc_attr( $slug ); ?>" data-types="<?php echo esc_attr( $applies ); ?>" <?php selected( $snippet->location, $slug ); ?>><?php echo esc_html( $meta[0] ); ?></option>
						<?php endforeach; ?>
					</select>

					<label class="vibesnip-field-label" for="vibesnip-priority"><?php esc_html_e( 'Priority', 'vibesnip' ); ?></label>
					<input type="number" name="priority" id="vibesnip-priority" class="small-text" value="<?php echo (int) $snippet->priority; ?>" />
					<span class="vibesnip-microcopy"><?php esc_html_e( 'Lower runs first.', 'vibesnip' ); ?></span>

					<label class="vibesnip-field-label" for="vibesnip-tags"><?php esc_html_e( 'Tags', 'vibesnip' ); ?></label>
					<input type="text" name="tags" id="vibesnip-tags" class="widefat"
						value="<?php echo esc_attr( $snippet->tags ); ?>"
						placeholder="<?php esc_attr_e( 'comma, separated', 'vibesnip' ); ?>" />

					<?php if ( 'shortcode' === $snippet->location && $id ) : ?>
						<p class="vibesnip-microcopy"><?php esc_html_e( 'Shortcode:', 'vibesnip' ); ?> <code>[vibesnip id="<?php echo (int) $id; ?>"]</code></p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $revisions ) ) : ?>
					<div class="vibesnip-panel">
						<h2><?php esc_html_e( 'Revisions', 'vibesnip' ); ?></h2>
						<ul class="vibesnip-revisions">
							<?php foreach ( $revisions as $rev ) : ?>
								<li>
									<span class="vs-rev-msg"><?php echo esc_html( $rev->message ); ?></span>
									<span class="vs-rev-time"><?php echo esc_html( VibeSnip_Admin::format_time( $rev->created_at ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="vibesnip-microcopy"><?php echo esc_html( VibeSnip_Admin::timezone_note() ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</form>
	<?php VibeSnip_Donate::render(); ?>
</div>
