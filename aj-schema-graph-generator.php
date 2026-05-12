<?php
/**
 * Plugin Name: AJ Schema Graph Generator
 * Plugin URI:  https://github.com/AimeeJurenka/schema-graph-generator-for-topic-entity-search
 * Description: Generates a 3-tier connected @graph JSON-LD knowledge graph (Service Page → Collection Page → Blog Posts) for AI search visibility. Schema is rendered server-side in <head> so every AI crawler, LLM, and search engine can read it.
 * Version:     3.0.0
 * Author:      Aimee Jurenka
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────
define( 'AJSG_VERSION',      '3.0.0' );
define( 'AJSG_OPTION',       'ajsg_options' );
define( 'AJSG_TOPIC_MAP',    'ajsg_topic_map' );
define( 'AJSG_CACHE_PREFIX', 'ajsg_schema_' );
define( 'AJSG_NONCE_TMAP',   'ajsg_save_topic_map' );
define( 'AJSG_NONCE_VALID',  'ajsg_validate_hubs' );
define( 'AJSG_NONCE_REGEN',  'ajsg_bulk_regen' );

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────

function ajsg_get_options() {
    return wp_parse_args( get_option( AJSG_OPTION, [] ), [
        'person_name'      => '',
        'person_job_title' => '',
        'person_li'        => '',
        'website_name'     => '',
        'website_url'      => '',
        'website_logo_url' => '',
        'org_li'           => '',
        'org_twitter'      => '',
        'org_other'        => '',
    ] );
}

function ajsg_get_topic_map() {
    $map = get_option( AJSG_TOPIC_MAP, [] );
    return is_array( $map ) ? $map : [];
}

function ajsg_person_sameas( $opts ) {
    return array_values( array_filter( [ $opts['person_li'] ] ) );
}

function ajsg_org_sameas( $opts ) {
    return array_values( array_filter( [ $opts['org_li'], $opts['org_twitter'], $opts['org_other'] ] ) );
}

function ajsg_clean_url( $url ) {
    return trailingslashit( rtrim( trim( $url ), '/' ) );
}

// ─────────────────────────────────────────────────────────────
// ADMIN MENU
// ─────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_menu_page(
        'AJ Schema Graph', 'Schema Graph', 'manage_options',
        'aj-schema-graph', 'ajsg_render_settings_page',
        'dashicons-networking', 80
    );
    add_submenu_page( 'aj-schema-graph', 'Entity Settings',    'Entity Settings',    'manage_options', 'aj-schema-graph',      'ajsg_render_settings_page' );
    add_submenu_page( 'aj-schema-graph', 'Topic Entity Map',   'Topic Entity Map',   'manage_options', 'aj-schema-topic-map',  'ajsg_render_topic_map_page' );
    add_submenu_page( 'aj-schema-graph', 'Schema Preview',     'Schema Preview',     'manage_options', 'aj-schema-preview',    'ajsg_render_preview_page' );
} );

// ─────────────────────────────────────────────────────────────
// SETTINGS PAGE — Person + Brand
// ─────────────────────────────────────────────────────────────

add_action( 'admin_init', function () {
    register_setting( 'ajsg_settings_group', AJSG_OPTION, 'ajsg_sanitize_options' );
} );

function ajsg_sanitize_options( $raw ) {
    if ( ! is_array( $raw ) ) return [];
    $out = [];
    foreach ( [ 'person_name', 'person_job_title', 'website_name' ] as $f ) {
        $out[ $f ] = sanitize_text_field( $raw[ $f ] ?? '' );
    }
    foreach ( [ 'person_li', 'website_url', 'website_logo_url', 'org_li', 'org_twitter', 'org_other' ] as $f ) {
        $out[ $f ] = esc_url_raw( trim( $raw[ $f ] ?? '' ) );
    }
    // Flush all caches on settings save
    foreach ( ajsg_get_topic_map() as $cat_id => $m ) {
        delete_transient( AJSG_CACHE_PREFIX . $cat_id );
    }
    return $out;
}

function ajsg_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $s = ajsg_get_options();
    ?>
    <div class="wrap">
    <h1>AJ Schema Graph — Entity Settings</h1>
    <p>These values populate the <code>Person</code>, <code>Organization</code>, and <code>WebSite</code> nodes that anchor the entire knowledge graph. All schema is rendered server-side in <code>&lt;head&gt;</code> so AI crawlers can read it without executing JavaScript.</p>
    <?php settings_errors(); ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'ajsg_settings_group' ); ?>
        <h2>Person</h2>
        <table class="form-table">
            <tr><th>Full Name</th><td><input type="text" name="<?= AJSG_OPTION ?>[person_name]" value="<?= esc_attr($s['person_name']) ?>" class="regular-text"></td></tr>
            <tr><th>Job Title</th><td><input type="text" name="<?= AJSG_OPTION ?>[person_job_title]" value="<?= esc_attr($s['person_job_title']) ?>" class="regular-text"></td></tr>
            <tr><th>LinkedIn (Person)</th><td><input type="url" name="<?= AJSG_OPTION ?>[person_li]" value="<?= esc_attr($s['person_li']) ?>" class="regular-text"></td></tr>
        </table>
        <h2>Organization</h2>
        <table class="form-table">
            <tr><th>Brand Name</th><td><input type="text" name="<?= AJSG_OPTION ?>[website_name]" value="<?= esc_attr($s['website_name']) ?>" class="regular-text"></td></tr>
            <tr><th>Website URL</th><td><input type="url" name="<?= AJSG_OPTION ?>[website_url]" value="<?= esc_attr($s['website_url']) ?>" class="regular-text"><p class="description">Must match your site's canonical URL exactly.</p></td></tr>
            <tr><th>Logo URL</th><td><input type="url" name="<?= AJSG_OPTION ?>[website_logo_url]" value="<?= esc_attr($s['website_logo_url']) ?>" class="regular-text"></td></tr>
            <tr><th>LinkedIn (Org)</th><td><input type="url" name="<?= AJSG_OPTION ?>[org_li]" value="<?= esc_attr($s['org_li']) ?>" class="regular-text"></td></tr>
            <tr><th>Twitter / X</th><td><input type="url" name="<?= AJSG_OPTION ?>[org_twitter]" value="<?= esc_attr($s['org_twitter']) ?>" class="regular-text"></td></tr>
            <tr><th>Other sameAs</th><td><input type="url" name="<?= AJSG_OPTION ?>[org_other]" value="<?= esc_attr($s['org_other']) ?>" class="regular-text"></td></tr>
        </table>
        <?php submit_button( 'Save Entity Settings' ); ?>
    </form>

    <hr>
    <h2>Bulk Regenerate Schema Cache</h2>
    <p>Clears all cached schema so the next page load rebuilds it fresh from current settings.</p>
    <button id="ajsg-bulk-regen" type="button" class="button button-secondary"
        data-nonce="<?= esc_attr( wp_create_nonce( AJSG_NONCE_REGEN ) ) ?>">
        Clear All Schema Cache
    </button>
    <span id="ajsg-regen-status" style="margin-left:12px;font-style:italic;"></span>

    <script>
    document.getElementById('ajsg-bulk-regen').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        document.getElementById('ajsg-regen-status').textContent = 'Clearing…';
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=ajsg_bulk_regen&nonce=' + btn.dataset.nonce
        })
        .then(r => r.json())
        .then(d => {
            document.getElementById('ajsg-regen-status').textContent = d.success ? '✓ Cache cleared.' : '✗ ' + (d.data?.message || 'Error.');
            btn.disabled = false;
        });
    });
    </script>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────
// TOPIC ENTITY MAP
// Stores per-category: entity_label, service_page_url, collection_page_url
// ─────────────────────────────────────────────────────────────

add_action( 'admin_post_ajsg_save_topic_map', function () {
    check_admin_referer( AJSG_NONCE_TMAP, 'ajsg_topic_map_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

    $raw = isset( $_POST['ajsg_topic_map'] ) ? (array) $_POST['ajsg_topic_map'] : [];
    $clean = [];
    foreach ( $raw as $cat_id => $data ) {
        $cat_id = absint( $cat_id );
        if ( ! $cat_id ) continue;
        $entity_label        = sanitize_text_field( $data['entity_label']        ?? '' );
        $service_page_url    = esc_url_raw( trim( $data['service_page_url']    ?? '' ) );
        $collection_page_url = esc_url_raw( trim( $data['collection_page_url'] ?? '' ) );
        if ( $entity_label || $service_page_url || $collection_page_url ) {
            $clean[ $cat_id ] = compact( 'entity_label', 'service_page_url', 'collection_page_url' );
            // Bust cache for this cluster
            delete_transient( AJSG_CACHE_PREFIX . $cat_id );
        }
    }
    update_option( AJSG_TOPIC_MAP, $clean );
    wp_safe_redirect( add_query_arg( [ 'page' => 'aj-schema-topic-map', 'ajsg_saved' => '1' ], admin_url( 'admin.php' ) ) );
    exit;
} );

add_action( 'wp_ajax_ajsg_validate_hubs', function () {
    if ( ! check_ajax_referer( AJSG_NONCE_VALID, 'nonce', false ) ) wp_send_json_error( [ 'message' => 'Security check failed.' ] );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );

    $results = [];
    foreach ( ajsg_get_topic_map() as $cat_id => $m ) {
        foreach ( [ 'service_page_url', 'collection_page_url' ] as $field ) {
            $url = $m[ $field ] ?? '';
            if ( ! $url ) continue;
            $r = wp_remote_get( $url, [ 'timeout' => 10, 'redirection' => 0 ] );
            $results[ $cat_id . '_' . $field ] = [
                'url'   => $url,
                'field' => $field,
                'code'  => is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r ),
                'ok'    => ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ),
            ];
        }
    }
    wp_send_json_success( [ 'results' => $results ] );
} );

function ajsg_render_topic_map_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $topic_map  = ajsg_get_topic_map();
    $categories = get_categories( [ 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
    $saved      = isset( $_GET['ajsg_saved'] ) && '1' === $_GET['ajsg_saved'];
    ?>
    <div class="wrap">
    <h1>Topic Entity Map</h1>
    <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p>Topic Entity Map saved.</p></div><?php endif; ?>

    <p>Each row maps one WordPress category to a <strong>topic entity</strong>, a <strong>service page</strong> (Tier 1 — WebPage), and a <strong>collection page</strong> (Tier 2 — CollectionPage). Blog posts in that category automatically become Tier 3 BlogPostings connected to the collection page via <code>isPartOf</code>.</p>

    <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>">
        <input type="hidden" name="action" value="ajsg_save_topic_map">
        <?php wp_nonce_field( AJSG_NONCE_TMAP, 'ajsg_topic_map_nonce' ); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:16%">Category</th>
                    <th style="width:18%">Topic Entity Name</th>
                    <th style="width:26%">Tier 1 — Service Page URL<br><small style="font-weight:normal;color:#646970">Becomes the WebPage node</small></th>
                    <th style="width:26%">Tier 2 — Collection Page URL<br><small style="font-weight:normal;color:#646970">Becomes the CollectionPage node (WP category page)</small></th>
                    <th style="width:14%">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $categories ) ) : ?>
                <tr><td colspan="5">No categories found.</td></tr>
            <?php else : ?>
                <?php foreach ( $categories as $cat ) :
                    $cid     = $cat->term_id;
                    $m       = $topic_map[ $cid ] ?? [];
                    $label   = $m['entity_label']        ?? '';
                    $sp_url  = $m['service_page_url']    ?? '';
                    $cp_url  = $m['collection_page_url'] ?? '';
                ?>
                <tr>
                    <td>
                        <strong><?= esc_html( $cat->name ) ?></strong><br>
                        <code style="font-size:11px;color:#646970"><?= esc_html( $cat->slug ) ?></code><br>
                        <span style="font-size:11px;color:#646970"><?= (int) $cat->count ?> posts</span>
                    </td>
                    <td><input type="text"
                        name="ajsg_topic_map[<?= esc_attr($cid) ?>][entity_label]"
                        value="<?= esc_attr($label) ?>"
                        class="regular-text" placeholder="e.g. AI Visibility"></td>
                    <td><input type="url"
                        name="ajsg_topic_map[<?= esc_attr($cid) ?>][service_page_url]"
                        value="<?= esc_attr($sp_url) ?>"
                        class="regular-text" placeholder="https://yourdomain.com/ai-search-services/"></td>
                    <td><input type="url"
                        name="ajsg_topic_map[<?= esc_attr($cid) ?>][collection_page_url]"
                        value="<?= esc_attr($cp_url) ?>"
                        class="regular-text" placeholder="https://yourdomain.com/topic/ai-visibility/"></td>
                    <td>
                        <?php if ( $sp_url && $cp_url ) : ?>
                            <span style="color:#065f46;background:#d1fae5;padding:2px 7px;border-radius:3px;font-size:12px;font-weight:600;">✓ Mapped</span>
                        <?php elseif ( $sp_url || $cp_url ) : ?>
                            <span style="color:#92400e;background:#fef3c7;padding:2px 7px;border-radius:3px;font-size:12px;font-weight:600;">⚠ Incomplete</span>
                        <?php else : ?>
                            <span style="color:#787c82;font-size:12px;">Not mapped</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php submit_button( 'Save Topic Entity Map' ); ?>
    </form>

    <hr>
    <h2>Validate Hub URLs</h2>
    <p>Check that your service and collection page URLs return 200 OK.</p>
    <button id="ajsg-validate-hubs" type="button" class="button button-secondary"
        data-nonce="<?= esc_attr( wp_create_nonce( AJSG_NONCE_VALID ) ) ?>">
        Validate URLs
    </button>
    <span id="ajsg-validate-status" style="margin-left:12px;font-style:italic;"></span>
    <div id="ajsg-validate-results" style="margin-top:12px;"></div>

    <script>
    document.getElementById('ajsg-validate-hubs').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        document.getElementById('ajsg-validate-status').textContent = 'Checking…';
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=ajsg_validate_hubs&nonce=' + btn.dataset.nonce
        })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if ( ! d.success ) {
                document.getElementById('ajsg-validate-status').textContent = '✗ Error.';
                return;
            }
            document.getElementById('ajsg-validate-status').textContent = 'Done.';
            const results = d.data.results;
            let html = '<table class="widefat" style="max-width:700px"><thead><tr><th>URL</th><th>Type</th><th>Status</th></tr></thead><tbody>';
            for ( const key in results ) {
                const r = results[key];
                const badge = r.ok
                    ? '<span style="color:#065f46;background:#d1fae5;padding:2px 7px;border-radius:3px;font-size:12px">200 OK</span>'
                    : '<span style="color:#991b1b;background:#fee2e2;padding:2px 7px;border-radius:3px;font-size:12px">' + (r.code || 'Error') + '</span>';
                html += '<tr><td><code>' + r.url + '</code></td><td>' + r.field.replace('_url','').replace('_',' ') + '</td><td>' + badge + '</td></tr>';
            }
            html += '</tbody></table>';
            document.getElementById('ajsg-validate-results').innerHTML = html;
        });
    });
    </script>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────
// SCHEMA PREVIEW PAGE
// ─────────────────────────────────────────────────────────────

function ajsg_render_preview_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $selected_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
    $all_posts   = get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'post_type', 'order' => 'ASC' ] );
    ?>
    <div class="wrap">
    <h1>Schema Preview</h1>
    <p>Select any published post or page to inspect the @graph JSON-LD that will be injected server-side into its <code>&lt;head&gt;</code>.</p>
    <form method="get">
        <input type="hidden" name="page" value="aj-schema-preview">
        <select name="post_id" style="min-width:400px;">
            <option value="">— Select a post or page —</option>
            <?php
            $current_type = '';
            foreach ( $all_posts as $p ) {
                if ( $p->post_type !== $current_type ) {
                    if ( $current_type ) echo '</optgroup>';
                    $current_type = $p->post_type;
                    echo '<optgroup label="' . esc_attr( ucfirst( $current_type ) . 's' ) . '">';
                }
                printf( '<option value="%d"%s>%s</option>', $p->ID, selected( $selected_id, $p->ID, false ), esc_html( $p->post_title ) );
            }
            if ( $current_type ) echo '</optgroup>';
            ?>
        </select>
        <?php submit_button( 'Preview Schema', 'secondary', 'submit', false, [ 'style' => 'margin-left:8px' ] ); ?>
    </form>

    <?php if ( $selected_id ) :
        $schema = ajsg_build_schema( $selected_id );
        $json   = $schema ? wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
    ?>
    <hr>
    <h2><?= esc_html( get_the_title( $selected_id ) ) ?></h2>
    <p><strong>URL:</strong> <a href="<?= esc_url( get_permalink( $selected_id ) ) ?>" target="_blank"><?= esc_html( get_permalink( $selected_id ) ) ?></a></p>
    <?php if ( $json ) : ?>
    <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('ajsg-preview-pre').textContent)">Copy to Clipboard</button>
    <pre id="ajsg-preview-pre" style="background:#1e1e1e;color:#d4d4d4;font-family:Consolas,monospace;font-size:12px;line-height:1.5;padding:14px;overflow:auto;max-height:600px;margin-top:8px;border-radius:4px;"><?= esc_html( $json ) ?></pre>
    <?php else : ?>
    <div class="notice notice-warning inline"><p>No schema generated. Configure Entity Settings and Topic Entity Map first.</p></div>
    <?php endif; ?>
    <?php endif; ?>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────
// WP_HEAD INJECTION — server-side so AI tools can read it
// ─────────────────────────────────────────────────────────────

add_action( 'wp_head', function () {
    $post_id = is_singular() ? (int) get_the_ID() : null;
    $schema  = ajsg_build_schema( $post_id );
    if ( empty( $schema ) ) return;

    $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( false === $json ) return;

    // Output is PHP-generated, present in raw HTML source —
    // no JavaScript required, readable by all AI crawlers.
    echo '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
}, 5 );

// ─────────────────────────────────────────────────────────────
// BULK CACHE CLEAR AJAX
// ─────────────────────────────────────────────────────────────

add_action( 'wp_ajax_ajsg_bulk_regen', function () {
    if ( ! check_ajax_referer( AJSG_NONCE_REGEN, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Security check failed.' ] );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
    }
    foreach ( ajsg_get_topic_map() as $cat_id => $m ) {
        delete_transient( AJSG_CACHE_PREFIX . $cat_id );
    }
    wp_send_json_success( [ 'message' => 'All schema caches cleared.' ] );
} );

// ─────────────────────────────────────────────────────────────
// AUTO-BUST CACHE ON POST SAVE
// ─────────────────────────────────────────────────────────────

add_action( 'save_post', function ( $post_id, $post ) {
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) return;
    $topic_map = ajsg_get_topic_map();
    $post_cats = wp_get_post_categories( $post_id, [ 'fields' => 'ids' ] );
    foreach ( $topic_map as $cat_id => $m ) {
        if ( in_array( (int) $cat_id, $post_cats, true ) ) {
            delete_transient( AJSG_CACHE_PREFIX . $cat_id );
        }
    }
}, 10, 2 );

// ─────────────────────────────────────────────────────────────
// SCHEMA BUILDER — page-aware
// ─────────────────────────────────────────────────────────────

/**
 * Build the @graph for a given post/page ID.
 *
 * Brand nodes (Org, Person, WebSite) and the DefinedTermSet with all
 * DefinedTerm refs appear on every page — lightweight, good for site-wide
 * entity recognition.
 *
 * Cluster nodes (WebPage → CollectionPage → BlogPostings) are page-specific:
 *   - Service page  → full cluster for that service page only
 *   - Category page → full cluster for that category only
 *   - Blog post     → WebPage + CollectionPage refs + just the current post node
 *   - Other pages   → brand nodes + entity map only (no cluster nodes)
 */
function ajsg_build_schema( $post_id ) {
    $opts    = ajsg_get_options();
    $s       = ajsg_clean_url( ! empty( $opts['website_url'] ) ? $opts['website_url'] : home_url( '/' ) );
    $site    = rtrim( $s, '/' );
    $org_id  = $site . '/#organization';
    $per_id  = $site . '/#person';
    $web_id  = $site . '/#website';
    $tmap_id = $site . '/#topicmap';

    $topic_map = ajsg_get_topic_map();
    $graph     = [];

    // ── Organization ──────────────────────────────────────────
    $org = [
        '@type'   => 'Organization',
        '@id'     => $org_id,
        'name'    => $opts['website_name'] ?: get_bloginfo( 'name' ),
        'url'     => $site,
        'founder' => [ '@id' => $per_id ],
    ];
    if ( $opts['website_logo_url'] ) {
        $org['logo'] = [ '@type' => 'ImageObject', 'url' => $opts['website_logo_url'] ];
    }
    $org_sa = ajsg_org_sameas( $opts );
    if ( $org_sa ) $org['sameAs'] = $org_sa;
    $graph[] = $org;

    // ── Person ────────────────────────────────────────────────
    $person = [
        '@type'    => 'Person',
        '@id'      => $per_id,
        'name'     => $opts['person_name'] ?: '',
        'url'      => $site,
        'worksFor' => [ '@id' => $org_id ],
    ];
    if ( $opts['person_job_title'] ) $person['jobTitle'] = $opts['person_job_title'];
    $per_sa = ajsg_person_sameas( $opts );
    if ( $per_sa ) $person['sameAs'] = $per_sa;
    $graph[] = $person;

    // ── WebSite ───────────────────────────────────────────────
    $graph[] = [
        '@type'     => 'WebSite',
        '@id'       => $web_id,
        'name'      => $opts['person_name'] ?: ( $opts['website_name'] ?: get_bloginfo( 'name' ) ),
        'url'       => $site,
        'publisher' => [ '@id' => $org_id ],
    ];

    // ── Build active cluster index ────────────────────────────
    $active_clusters = [];
    foreach ( $topic_map as $cat_id => $m ) {
        if ( ! empty( $m['entity_label'] ) && ! empty( $m['service_page_url'] ) && ! empty( $m['collection_page_url'] ) ) {
            $cat = get_category( $cat_id );
            if ( $cat && ! is_wp_error( $cat ) ) {
                $active_clusters[ $cat_id ] = array_merge( $m, [ 'cat' => $cat ] );
            }
        }
    }

    // ── DefinedTermSet — always output with ALL terms ─────────
    // Lightweight. Tells AI systems every topic entity the brand owns,
    // regardless of which page is being viewed.
    if ( ! empty( $active_clusters ) ) {
        $graph[] = [
            '@type'          => 'DefinedTermSet',
            '@id'            => $tmap_id,
            'name'           => ( $opts['person_name'] ?: $opts['website_name'] ) . ' Topic Entities',
            'publisher'      => [ '@id' => $org_id ],
            'hasDefinedTerm' => array_map(
                fn( $cid ) => [ '@id' => $site . '/#term-' . $active_clusters[ $cid ]['cat']->slug ],
                array_keys( $active_clusters )
            ),
        ];

        // All DefinedTerm nodes — lightweight, always output
        foreach ( $active_clusters as $cat_id => $cluster ) {
            $graph[] = [
                '@type'            => 'DefinedTerm',
                '@id'              => $site . '/#term-' . $cluster['cat']->slug,
                'name'             => $cluster['entity_label'],
                'inDefinedTermSet' => [ '@id' => $tmap_id ],
            ];
        }

        // ── Page-specific cluster nodes ───────────────────────
        if ( $post_id ) {
            $current_url = trailingslashit( get_permalink( $post_id ) );
            $post_type   = get_post_type( $post_id );
            $post_cats   = ( 'post' === $post_type )
                ? wp_get_post_categories( $post_id, [ 'fields' => 'ids' ] )
                : [];

            foreach ( $active_clusters as $cat_id => $cluster ) {
                $cat    = $cluster['cat'];
                $termid = $site . '/#term-' . $cat->slug;
                $sp_url = ajsg_clean_url( $cluster['service_page_url'] );
                $sp_id  = $sp_url . '#webpage';
                $cp_url = ajsg_clean_url( $cluster['collection_page_url'] );
                $cp_id  = $cp_url . '#webpage';

                $is_service_page    = ( $current_url === $sp_url );
                $is_collection_page = ( $current_url === $cp_url );
                $is_cluster_post    = in_array( (int) $cat_id, $post_cats, true );

                if ( ! $is_service_page && ! $is_collection_page && ! $is_cluster_post ) {
                    continue; // This cluster is not relevant to the current page — skip
                }

                // Tier 1 — WebPage (service/product page)
                $graph[] = [
                    '@type'     => 'WebPage',
                    '@id'       => $sp_id,
                    'name'      => $cluster['entity_label'],
                    'url'       => $sp_url,
                    'about'     => [ '@id' => $termid ],
                    'publisher' => [ '@id' => $org_id ],
                    'hasPart'   => [ '@id' => $cp_id ],
                ];

                // Get posts for this cluster
                $cluster_posts = get_posts( [
                    'category'    => $cat_id,
                    'post_status' => 'publish',
                    'numberposts' => -1,
                    'orderby'     => 'date',
                    'order'       => 'DESC',
                ] );

                // Tier 2 — CollectionPage
                $col = [
                    '@type'     => 'CollectionPage',
                    '@id'       => $cp_id,
                    'name'      => $cluster['entity_label'],
                    'url'       => $cp_url,
                    'about'     => [ '@id' => $termid ],
                    'publisher' => [ '@id' => $org_id ],
                    'isPartOf'  => [ '@id' => $sp_id ],
                ];
                if ( ! empty( $cluster_posts ) ) {
                    $col['hasPart'] = array_map(
                        fn( $p ) => [ '@id' => trailingslashit( get_permalink( $p ) ) . '#article' ],
                        $cluster_posts
                    );
                }
                $graph[] = $col;

                // Tier 3 — BlogPosting nodes
                // Service page or collection page: output ALL posts in full
                // Individual blog post: output ONLY the current post
                $posts_to_output = ( $is_service_page || $is_collection_page )
                    ? $cluster_posts
                    : array_filter( $cluster_posts, fn( $p ) => (int) $p->ID === (int) $post_id );

                foreach ( $posts_to_output as $p ) {
                    $purl    = trailingslashit( get_permalink( $p ) );
                    $graph[] = [
                        '@type'           => 'BlogPosting',
                        '@id'             => $purl . '#article',
                        'headline'        => get_the_title( $p ),
                        'url'             => $purl,
                        'datePublished'   => get_the_date( 'c', $p ),
                        'dateModified'    => get_the_modified_date( 'c', $p ),
                        'author'          => [ '@id' => $per_id ],
                        'publisher'       => [ '@id' => $org_id ],
                        'isPartOf'        => [ '@id' => $cp_id ],
                        'about'           => [ [ '@id' => $termid ] ],
                        'mainEntityOfPage'=> $purl,
                    ];
                }
            }
        }
    }

    if ( empty( $graph ) ) return null;

    return [ '@context' => 'https://schema.org', '@graph' => $graph ];
}


// ─────────────────────────────────────────────────────────────
// REST API ENDPOINT
// GET /wp-json/ajsg/v1/schema?post_id=123
// Lets external tools (AI crawlers, auditing tools) query
// the schema for any page without loading the full HTML.
// ─────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'ajsg/v1', '/schema', [
        'methods'             => 'GET',
        'callback'            => function ( WP_REST_Request $req ) {
            $post_id = $req->get_param( 'post_id' ) ? absint( $req->get_param( 'post_id' ) ) : null;
            $schema  = ajsg_build_schema( $post_id );
            if ( ! $schema ) {
                return new WP_Error( 'no_schema', 'No schema generated. Check Entity Settings and Topic Entity Map.', [ 'status' => 404 ] );
            }
            return rest_ensure_response( $schema );
        },
        'permission_callback' => '__return_true',
        'args'                => [
            'post_id' => [ 'type' => 'integer', 'required' => false ],
        ],
    ] );
} );
