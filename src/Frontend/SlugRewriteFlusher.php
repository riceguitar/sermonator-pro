<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers;

/**
 * Safe, deferred rewrite-rule flush for the live archive-slug option (Bundle 4,
 * spec §1.4 / Task 3).
 *
 * {@see Identifiers::OPTION_ARCHIVE_SLUG} drives BOTH the CPT archive base AND
 * every single-sermon permalink (it is fed to {@see \Sermonator\Model\Registrar}
 * as the CPT `rewrite['slug']` at `init@5`). When an admin changes it the rewrite
 * rules WordPress compiled from the old slug are stale and 404 the archive /
 * single permalinks until `flush_rewrite_rules()` runs. But `flush_rewrite_rules()`
 * is expensive (it rebuilds and re-persists the whole rules map) and MUST NOT run:
 *
 *   - INLINE on the save request — the save handler runs before the CPT is even
 *     re-registered under the new slug on the NEXT request, so an inline flush
 *     would persist rules built from the OLD slug; and
 *   - on a FRONT-END visitor request — a public pageview must never pay for an
 *     admin's configuration change.
 *
 * So this class splits the work in two:
 *
 *   1. WRITE path — `add_option_{slug}` / `update_option_{slug}` listeners set a
 *      PERSISTENT {@see Identifiers::OPTION_REWRITE_FLUSH_PENDING} flag, but ONLY
 *      when the new value actually differs from the old (a no-op re-save of the
 *      same slug schedules nothing). No flush happens here.
 *   2. FLUSH path — an `init@99` handler (AFTER the CPT is registered at `init@5`
 *      under the now-current slug), scoped to `is_admin() || wp_doing_cron()`,
 *      flushes EXACTLY ONCE when the flag is set and immediately clears it. A
 *      front-end visitor never enters this path, so they never trigger the flush;
 *      the next admin screen (or a cron tick) absorbs it once and the flag is
 *      gone.
 *
 * MIGRATION RE-RUN NEVER FLUSHES (spec §1.2). Because the live slug key is
 * DISTINCT from the migration's `sermonator_archive_slug` prefix-swap artifact
 * (see {@see Identifiers::OPTION_ARCHIVE_SLUG} docblock), `OptionWriter`'s
 * verbatim re-run writes only the artifact row — never the live key — so these
 * listeners never fire on a migration re-run and no spurious flush is scheduled.
 *
 * Wired UNCONDITIONALLY in {@see \Sermonator\Plugin}::boot (like
 * {@see \Sermonator\Admin\SettingsRegistrar}): the write listeners must catch a
 * save in any context, and the `init@99` flush self-scopes to admin/cron inside
 * the handler.
 */
final class SlugRewriteFlusher {
    public function hook(): void {
        // WRITE path. add_option_{$option} fires on the FIRST save (when no live
        // row exists yet) and passes ( $option, $value ); update_option_{$option}
        // fires on every subsequent save and passes ( $old_value, $value, $option ).
        // Both routes must be wired or the common first-time configuration save is
        // missed (the same add/update split SettingsRegistrar handles for its
        // cache-gen bump).
        add_action( 'add_option_' . Identifiers::OPTION_ARCHIVE_SLUG, array( $this, 'onSlugAdded' ), 10, 2 );
        add_action( 'update_option_' . Identifiers::OPTION_ARCHIVE_SLUG, array( $this, 'onSlugUpdated' ), 10, 2 );

        // FLUSH path. Priority 99 so it runs AFTER the CPT is (re)registered at
        // init@5 under the current slug; the handler itself scopes to admin/cron.
        add_action( 'init', array( $this, 'maybeFlush' ), 99 );
    }

    /**
     * First-save (create) listener. There is no stored predecessor, so the
     * "old" routing slug is the explicit {@see DisplayDefaults} seed the CPT was
     * registered with while the live row was absent. Schedule a flush only when
     * the newly-stored value actually diverges from that seed — a first save that
     * merely persists the seed value changes no routing and needs no flush.
     *
     * @param mixed $option The option name (passed by the add_option_{$option} hook).
     * @param mixed $value  The newly-stored value.
     */
    public function onSlugAdded( $option, $value = null ): void {
        $this->markPendingIfChanged( DisplayDefaults::defaultArchiveSlug(), $value );
    }

    /**
     * Update listener. Schedule a flush only on a REAL value change — WordPress
     * already suppresses the `update_option_{$option}` action when the new value
     * equals the old, but the explicit guard keeps the change-only contract
     * self-evident and directly testable.
     *
     * @param mixed $oldValue The previously-stored value.
     * @param mixed $value    The newly-stored value.
     */
    public function onSlugUpdated( $oldValue, $value = null ): void {
        $this->markPendingIfChanged( $oldValue, $value );
    }

    /**
     * Set the persistent pending flag iff the (string-normalized) new slug differs
     * from the old. Slugs are scalar strings; comparing the string forms avoids a
     * spurious schedule from a benign int/string type drift through the options API.
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    private function markPendingIfChanged( $oldValue, $newValue ): void {
        if ( $this->asString( $oldValue ) === $this->asString( $newValue ) ) {
            return;
        }

        // A persistent option, not a transient: the flush must survive across the
        // save request (which never flushes) until the next admin/cron request
        // absorbs it. update_option creates the row when absent.
        update_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING, true );
    }

    /**
     * The `init@99` flush. No-op unless the pending flag is set AND the request is
     * an admin or cron context — a front-end visitor never pays the flush. When it
     * does run it flushes EXACTLY ONCE and clears the flag so the next request does
     * not flush again.
     */
    public function maybeFlush(): void {
        if ( ! is_admin() && ! wp_doing_cron() ) {
            return;
        }

        if ( ! get_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING, false ) ) {
            return;
        }

        // Clear FIRST so a flush_rewrite_rules() that itself errors/dies cannot
        // wedge the flag set forever and flush on every subsequent admin pageview.
        delete_option( Identifiers::OPTION_REWRITE_FLUSH_PENDING );

        flush_rewrite_rules();
    }

    private function asString( $value ): string {
        return is_scalar( $value ) ? (string) $value : '';
    }
}
