<?php

declare(strict_types=1);

namespace Sermonator;

use Sermonator\Support\VersionGate;

final class Plugin {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        $gate = new VersionGate( PHP_VERSION, get_bloginfo( 'version' ) );
        if ( ! $gate->isSatisfied() ) {
            add_action(
                'admin_notices',
                static function () use ( $gate ): void {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html( $gate->failureMessage() )
                    );
                }
            );
            return;
        }

        add_action(
            'init',
            static function (): void {
                load_plugin_textdomain(
                    'sermonator',
                    false,
                    dirname( plugin_basename( SERMONATOR_FILE ) ) . '/languages'
                );
            }
        );

        ( new \Sermonator\Model\Registrar() )->hook();
        ( new \Sermonator\Model\Capabilities() )->grant();
        ( new \Sermonator\Admin\Authoring\AuthoringServiceProvider() )->hook();
        ( new \Sermonator\Admin\SettingsRegistrar() )->hook();
        ( new \Sermonator\Admin\DisplaySettingsRegistrar() )->hook();
        // Deferred, change-only rewrite flush for the live archive-slug option.
        // Wired unconditionally (like SettingsRegistrar): the add/update write
        // listeners must catch a save in any context, while the init@99 flush
        // self-scopes to admin/cron inside the handler so a front-end visitor
        // never pays the flush. The live key is DISTINCT from the migration
        // prefix-swap artifact, so a verbatim migration re-run never fires it.
        ( new \Sermonator\Frontend\SlugRewriteFlusher() )->hook();
        // Podcast-settings post-meta governance (auth_callback manage_options +
        // sanitize-at-write allowlist). Registered on init in ALL contexts — NOT in
        // the admin-only registerAdmin() — because the governance must guard the
        // front-end/cron feed read path and the migration's own add_post_meta()
        // writes, neither of which run under is_admin(). SettingsPage (Task 8) is
        // add_submenu_page-scoped and cannot host this. show_in_rest=false closes
        // the REST vector; register_post_meta is idempotent (a second call overwrites).
        add_action( 'init', static fn() => \Sermonator\Schema\PodcastMetaSchema::register() );
        // Bible parse-coverage ground-truth audit. All-contexts on purpose: the daily
        // recompute cron (EVENT_HOOK) and the on-save recompute (save_post_<sermon>)
        // must fire outside admin, and the site_status_tests filter is a harmless pure
        // reader everywhere. Without this wiring OPTION_BIBLE_STATS is never computed
        // and the Site Health test never appears.
        ( new \Sermonator\Bible\CoverageAudit() )->hook();
        // Page-builder fail-visible floor (Bundle 2, T10). Wired alongside CoverageAudit and
        // for the same reason: site_status_tests must register so the Site Health "direct" test
        // appears on the Site Health screen AND runs under the weekly wp_site_health_scheduled_check
        // cron (neither is admin-context), while the admin_notices wizard report self-scopes to the
        // wizard screen inside maybeRenderWizardNotice(). Both surfaces are pure reads; the lazy
        // candidateProvider means $wpdb is only touched when a callback actually fires. WITHOUT this
        // wiring neither surface registers and legacy sermon content trapped in a page builder stays
        // a SILENT break at the migration switch — the exact invariant this class exists to enforce.
        ( new \Sermonator\Migration\PageBuilderScanner() )->hook();
        // §63 migration prevalence report (Bundle 2, T11). hook() registers ONLY the
        // admin_notices wizard-report surface, which self-scopes to the wizard screen and is a
        // PURE READER of the precomputed OPTION_MIGRATION_PREVALENCE — it never recomputes or
        // writes on a GET. The rollup is written only on the gated detect/verify actions
        // (Orchestrator::detect / Verifier::verify).
        ( new \Sermonator\Migration\PrevalenceCounter() )->hook();

        self::registerAdmin();
        self::registerFrontend();
        self::registerCliCommands();
    }

    /**
     * Register the read-only front-end display layer (blocks, block template, classic
     * fallback, assets). Booted in ALL contexts: block and block-template registration must
     * be visible to the editor (admin) and REST as well as the front end. The front-end-only
     * pieces self-scope — single_template and wp_enqueue_scripts only fire on front-end
     * requests, and the the_content meta hook is guarded by is_singular()/in_the_loop()/
     * is_main_query(). The layer never writes data.
     */
    private static function registerFrontend(): void {
        ( new \Sermonator\Frontend\FrontendServiceProvider() )->hook();
    }

    /**
     * Register the guided migration wizard (Plan C): the admin page, the thin AJAX
     * controller, and the legacy-data notice. Admin-context only — is_admin() is true
     * for both regular admin screens and admin-ajax.php (where the wp_ajax_* handlers
     * fire), so all three register correctly while never touching front-end requests.
     * The wizard is pure UI over the gated lifecycle services; it adds no migration
     * logic and cannot bypass any data-safety gate.
     */
    private static function registerAdmin(): void {
        if ( ! is_admin() ) {
            return;
        }
        ( new \Sermonator\Admin\MigrationController() )->hook();
        ( new \Sermonator\Admin\MigrationWizard() )->hook();
        ( new \Sermonator\Admin\LegacyDataNotice() )->hook();
        // Settings-page Form 2 (podcast identity) admin-post.php handler. Admin-context
        // only: the admin_post_* hook fires solely on admin-post.php, and the handler is
        // phase-gated + nonce/cap-guarded before it writes through to the podcast meta.
        ( new \Sermonator\Admin\PodcastIdentityController() )->hook();
        // The one opinionated settings page (Bible + Display via Settings API Form 1,
        // Podcast identity via admin-post Form 2). Admin-context only: add_submenu_page +
        // add_settings_* + screen-scoped asset enqueue all belong to admin requests. It
        // registers no option (Form 1's options are owned by SettingsRegistrar +
        // DisplaySettingsRegistrar) and writes nothing itself.
        ( new \Sermonator\Admin\SettingsPage() )->hook();
    }

    /**
     * Register the WP-CLI migration command, but ONLY under a real WP-CLI runtime.
     * Guarded by defined('WP_CLI') && WP_CLI so a normal web/admin request — and the
     * plain phpunit process — never touches the WP_CLI API. The command itself is a
     * thin wrapper over the gated lifecycle services.
     */
    private static function registerCliCommands(): void {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }
        \WP_CLI::add_command( 'sermonator migration', \Sermonator\Cli\MigrationCommand::class );
        \WP_CLI::add_command( 'sermonator audio', \Sermonator\Cli\AudioCommand::class );
        \WP_CLI::add_command( 'sermonator bible', \Sermonator\Cli\BibleCommand::class );
    }
}
