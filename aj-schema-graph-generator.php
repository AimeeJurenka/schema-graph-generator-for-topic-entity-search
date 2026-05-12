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
// SCHEMA BUILDER
// ─────────────────────────────────────────────────────────────

/**
 * Build the full @graph for a given post/page ID (or null for archives).
 * Returns the full schema array, ready to json_encode.
 *
 * 3-tier hierarchy per topic cluster:
 *   Organization
 *     └── WebPage (service page)          — hasPart → CollectionPage
 *           └── CollectionPage (category) — hasPart → BlogPosting[]
 *                 └── BlogPosting         — isPartOf → CollectionPage
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

    // ── DefinedTermSet ────────────────────────────────────────
    $active_clusters = [];
    foreach ( $topic_map as $cat_id => $m ) {
        if ( ! empty( $m['entity_label'] ) && ! empty( $m['service_page_url'] ) && ! empty( $m['collection_page_url'] ) ) {
            $cat = get_category( $cat_id );
            if ( $cat && ! is_wp_error( $cat ) ) {
                $active_clusters[ $cat_id ] = array_merge( $m, [ 'cat' => $cat ] );
            }
        }
    }

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

        // ── Per-cluster nodes ─────────────────────────────────
        foreach ( $active_clusters as $cat_id => $cluster ) {
            $cat    = $cluster['cat'];
            $termid = $site . '/#term-' . $cat->slug;
            $sp_url = ajsg_clean_url( $cluster['service_page_url'] );
            $sp_id  = $sp_url . '#webpage';
            $cp_url = ajsg_clean_url( $cluster['collection_page_url'] );
            $cp_id  = $cp_url . '#webpage';

            // Get published posts in this category
            $posts = get_posts( [
                'category'    => $cat_id,
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ] );

            // DefinedTerm
            $graph[] = [
                '@type'           => 'DefinedTerm',
                '@id'             => $termid,
                'name'            => $cluster['entity_label'],
                'inDefinedTermSet'=> [ '@id' => $tmap_id ],
            ];

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

            // Tier 2 — CollectionPage (blog category page)
            $col = [
                '@type'     => 'CollectionPage',
                '@id'       => $cp_id,
                'name'      => $cluster['entity_label'],
                'url'       => $cp_url,
                'about'     => [ '@id' => $termid ],
                'publisher' => [ '@id' => $org_id ],
                'isPartOf'  => [ '@id' => $sp_id ],
            ];
            if ( ! empty( $posts ) ) {
                $col['hasPart'] = array_map(
                    fn( $p ) => [ '@id' => trailingslashit( get_permalink( $p ) ) . '#article' ],
                    $posts
                );
            }
            $graph[] = $col;

            // Tier 3 — BlogPostings
            foreach ( $posts as $p ) {
                $purl  = trailingslashit( get_permalink( $p ) );
                $node  = [
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
                $graph[] = $node;
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
<?php
/**
 * Plugin Name: AJ Schema Graph Generator
 * Plugin URI:  https://github.com/aj/schema-graph-generator
 * Description: Defines a site-wide entity graph and injects connected @graph JSON-LD schema into every page and post.
 * Version:     2.0.0
 * Author:      AJ
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aj-schema-graph
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'AJSG_VERSION',       '2.0.0' );
define( 'AJSG_OPTION',        'ajsg_options' );
define( 'AJSG_TOPIC_MAP',     'ajsg_topic_map' );
define( 'AJSG_META_KEY',      '_ajsg_last_generated' );
define( 'AJSG_NONCE_REGEN',   'ajsg_bulk_regen' );
define( 'AJSG_NONCE_VALID',   'ajsg_validate_hubs' );
define( 'AJSG_NONCE_TMAP',    'ajsg_save_topic_map' );

error_log( 'AJSG: file loaded v' . AJSG_VERSION );

// ===========================================================================
// HELPERS  (used across multiple sections)
// ===========================================================================

function ajsg_get_options() {
	$defaults = array(
		'person_name'        => '',
		'person_job_title'   => '',
		'person_description' => '',
		'person_image_url'   => '',
		'website_name'       => '',
		'website_url'        => '',
		'website_logo_url'   => '',
		'primary_topic'      => '',
		'social_urls'        => '',
	);
	$saved = get_option( AJSG_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( $defaults, $saved );
}

function ajsg_get_topic_map() {
	$map = get_option( AJSG_TOPIC_MAP, array() );
	if ( ! is_array( $map ) ) {
		$map = array();
	}
	return $map;
}

function ajsg_get_same_as_urls() {
	$opts = ajsg_get_options();
	if ( empty( $opts['social_urls'] ) ) {
		return array();
	}
	$lines = explode( "\n", $opts['social_urls'] );
	$urls  = array();
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( ! empty( $line ) ) {
			$urls[] = $line;
		}
	}
	return $urls;
}

// ===========================================================================
// SECTION 1 — MENU REGISTRATION
// ===========================================================================

add_action( 'admin_menu', 'ajsg_register_menus', 99 );

function ajsg_register_menus() {
	error_log( 'AJSG: admin_menu fired (priority 99)' );

	add_menu_page(
		'AJ Schema Graph',
		'Schema Graph',
		'manage_options',
		'aj-schema-graph',
		'ajsg_render_settings_page',
		'dashicons-networking',
		80
	);

	add_submenu_page(
		'aj-schema-graph',
		'Entity Settings',
		'Entity Settings',
		'manage_options',
		'aj-schema-graph',
		'ajsg_render_settings_page'
	);

	add_submenu_page(
		'aj-schema-graph',
		'Topic Entity Map',
		'Topic Entity Map',
		'manage_options',
		'aj-schema-topic-map',
		'ajsg_render_topic_map_page'
	);

	add_submenu_page(
		'aj-schema-graph',
		'Schema Preview',
		'Schema Preview',
		'manage_options',
		'aj-schema-preview',
		'ajsg_render_preview_page'
	);
}

// ===========================================================================
// SECTION 2 — SETTINGS PAGE
// ===========================================================================

add_action( 'admin_init', 'ajsg_register_settings', 99 );

function ajsg_register_settings() {
	register_setting( 'ajsg_settings_group', AJSG_OPTION, 'ajsg_sanitize_options' );

	// Person / Author
	add_settings_section(
		'ajsg_section_person',
		'Person / Author',
		'__return_false',
		'aj-schema-graph'
	);
	$person_fields = array(
		'person_name'        => 'Full Name',
		'person_job_title'   => 'Job Title',
		'person_description' => 'Description',
		'person_image_url'   => 'Profile Image URL',
	);
	foreach ( $person_fields as $id => $label ) {
		add_settings_field(
			'ajsg_' . $id,
			$label,
			'ajsg_render_text_field',
			'aj-schema-graph',
			'ajsg_section_person',
			array( 'id' => $id )
		);
	}

	// Website / Organization
	add_settings_section(
		'ajsg_section_website',
		'Website / Organization',
		'__return_false',
		'aj-schema-graph'
	);
	$website_fields = array(
		'website_name'     => 'Website / Org Name',
		'website_url'      => 'Website URL',
		'website_logo_url' => 'Logo URL',
		'primary_topic'    => 'Primary Topic / Niche',
	);
	foreach ( $website_fields as $id => $label ) {
		add_settings_field(
			'ajsg_' . $id,
			$label,
			'ajsg_render_text_field',
			'aj-schema-graph',
			'ajsg_section_website',
			array( 'id' => $id )
		);
	}

	// Social Profiles
	add_settings_section(
		'ajsg_section_social',
		'Social Profiles (sameAs)',
		'ajsg_render_social_section_desc',
		'aj-schema-graph'
	);
	add_settings_field(
		'ajsg_social_urls',
		'Social URLs',
		'ajsg_render_textarea_field',
		'aj-schema-graph',
		'ajsg_section_social',
		array( 'id' => 'social_urls' )
	);
}

function ajsg_sanitize_options( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out         = array();
	$text_fields = array( 'person_name', 'person_job_title', 'person_description', 'website_name', 'primary_topic' );
	foreach ( $text_fields as $field ) {
		$out[ $field ] = isset( $raw[ $field ] ) ? sanitize_text_field( $raw[ $field ] ) : '';
	}
	$url_fields = array( 'person_image_url', 'website_url', 'website_logo_url' );
	foreach ( $url_fields as $field ) {
		$out[ $field ] = isset( $raw[ $field ] ) ? esc_url_raw( trim( $raw[ $field ] ) ) : '';
	}
	$social_raw   = isset( $raw['social_urls'] ) ? $raw['social_urls'] : '';
	$social_lines = array_filter( array_map( 'trim', explode( "\n", $social_raw ) ) );
	$social_clean = array();
	foreach ( $social_lines as $line ) {
		$clean = esc_url_raw( $line );
		if ( $clean ) {
			$social_clean[] = $clean;
		}
	}
	$out['social_urls'] = implode( "\n", $social_clean );
	return $out;
}

function ajsg_render_social_section_desc() {
	ob_start();
	?>
	<p>Enter one social profile URL per line (Twitter/X, LinkedIn, Facebook, YouTube, Instagram, etc.).
	These populate <code>sameAs</code> on both the Organization and Person nodes.</p>
	<?php
	echo ob_get_clean();
}

function ajsg_render_text_field( $args ) {
	$id    = $args['id'];
	$opts  = ajsg_get_options();
	$value = isset( $opts[ $id ] ) ? $opts[ $id ] : '';
	ob_start();
	?>
	<input
		type="text"
		id="<?php echo esc_attr( $id ); ?>"
		name="<?php echo esc_attr( AJSG_OPTION ); ?>[<?php echo esc_attr( $id ); ?>]"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
	/>
	<?php
	echo ob_get_clean();
}

function ajsg_render_textarea_field( $args ) {
	$id    = $args['id'];
	$opts  = ajsg_get_options();
	$value = isset( $opts[ $id ] ) ? $opts[ $id ] : '';
	ob_start();
	?>
	<textarea
		id="<?php echo esc_attr( $id ); ?>"
		name="<?php echo esc_attr( AJSG_OPTION ); ?>[<?php echo esc_attr( $id ); ?>]"
		rows="7"
		class="large-text"
	><?php echo esc_textarea( $value ); ?></textarea>
	<?php
	echo ob_get_clean();
}

function ajsg_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	ob_start();
	?>
	<div class="wrap">
		<h1>AJ Schema Graph Generator</h1>
		<p class="description">Configure your site-wide entity graph. Schema is automatically injected into every page and post.</p>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'ajsg_settings_group' );
			do_settings_sections( 'aj-schema-graph' );
			submit_button( 'Save Settings' );
			?>
		</form>

		<hr />

		<h2>Bulk Regenerate Schema</h2>
		<p>After changing settings, update all published posts and pages to reflect the latest entity graph.</p>
		<button id="ajsg-bulk-regen" type="button" class="button button-secondary">Bulk Regenerate Now</button>
		<span id="ajsg-regen-status" style="margin-left:12px;font-style:italic;"></span>
		<div id="ajsg-regen-progress" style="margin-top:10px;display:none;">
			<progress id="ajsg-progress-bar" value="0" max="100" style="width:300px;vertical-align:middle;"></progress>
			<span id="ajsg-progress-text" style="margin-left:8px;"></span>
		</div>
	</div>
	<?php
	echo ob_get_clean();
}

// ===========================================================================
// SECTION 3 — TOPIC ENTITY MAP
// ===========================================================================

add_action( 'admin_post_ajsg_save_topic_map', 'ajsg_save_topic_map' );
add_action( 'wp_ajax_ajsg_validate_hubs', 'ajsg_ajax_validate_hubs' );

function ajsg_save_topic_map() {
	check_admin_referer( AJSG_NONCE_TMAP, 'ajsg_topic_map_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}

	$raw_map   = isset( $_POST['ajsg_topic_map'] ) ? (array) $_POST['ajsg_topic_map'] : array();
	$clean_map = array();

	foreach ( $raw_map as $cat_id => $data ) {
		$cat_id = absint( $cat_id );
		if ( ! $cat_id ) {
			continue;
		}
		$hub_url      = isset( $data['hub_url'] ) ? esc_url_raw( trim( $data['hub_url'] ) ) : '';
		$entity_label = isset( $data['entity_label'] ) ? sanitize_text_field( $data['entity_label'] ) : '';
		if ( $hub_url || $entity_label ) {
			$clean_map[ $cat_id ] = array(
				'hub_url'      => $hub_url,
				'entity_label' => $entity_label,
			);
		}
	}

	update_option( AJSG_TOPIC_MAP, $clean_map );

	wp_safe_redirect(
		add_query_arg(
			array( 'page' => 'aj-schema-topic-map', 'ajsg_saved' => '1' ),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

function ajsg_ajax_validate_hubs() {
	if ( ! check_ajax_referer( AJSG_NONCE_VALID, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		return;
	}

	$topic_map = ajsg_get_topic_map();
	$results   = array();

	foreach ( $topic_map as $cat_id => $mapping ) {
		if ( empty( $mapping['hub_url'] ) ) {
			continue;
		}
		$url      = $mapping['hub_url'];
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'sslverify'   => true,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			$results[ $cat_id ] = array(
				'url'   => $url,
				'code'  => 0,
				'ok'    => false,
				'error' => $response->get_error_message(),
			);
		} else {
			$code               = (int) wp_remote_retrieve_response_code( $response );
			$results[ $cat_id ] = array(
				'url'   => $url,
				'code'  => $code,
				'ok'    => ( 200 === $code ),
				'error' => '',
			);
		}
	}

	wp_send_json_success( array( 'results' => $results ) );
}

function ajsg_render_topic_map_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$topic_map  = ajsg_get_topic_map();
	$categories = get_categories(
		array(
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	$saved = isset( $_GET['ajsg_saved'] ) && '1' === $_GET['ajsg_saved'];

	ob_start();
	?>
	<div class="wrap">
		<h1>Topic Entity Map</h1>

		<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p>Topic Entity Map saved.</p></div>
		<?php endif; ?>

		<p>Map each category to a hub/pillar page URL and assign a clean entity label used in schema output.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ajsg_save_topic_map" />
			<?php wp_nonce_field( AJSG_NONCE_TMAP, 'ajsg_topic_map_nonce' ); ?>

			<table class="wp-list-table widefat fixed striped" id="ajsg-topic-table">
				<thead>
					<tr>
						<th style="width:22%;">Category</th>
						<th style="width:28%;">Topic Entity Label</th>
						<th style="width:35%;">Hub Page URL</th>
						<th style="width:15%;">URL Status</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $categories ) ) : ?>
					<tr><td colspan="4">No categories found. Create some categories first.</td></tr>
				<?php else : ?>
					<?php foreach ( $categories as $cat ) :
						$cat_id       = $cat->term_id;
						$mapping      = isset( $topic_map[ $cat_id ] ) ? $topic_map[ $cat_id ] : array();
						$hub_url      = isset( $mapping['hub_url'] ) ? $mapping['hub_url'] : '';
						$entity_label = isset( $mapping['entity_label'] ) ? $mapping['entity_label'] : '';
					?>
					<tr data-cat-id="<?php echo esc_attr( $cat_id ); ?>">
						<td>
							<strong><?php echo esc_html( $cat->name ); ?></strong><br />
							<code style="font-size:11px;color:#646970;"><?php echo esc_html( $cat->slug ); ?></code><br />
							<span style="font-size:11px;color:#646970;"><?php echo (int) $cat->count; ?> posts</span>
						</td>
						<td>
							<input
								type="text"
								name="ajsg_topic_map[<?php echo esc_attr( $cat_id ); ?>][entity_label]"
								value="<?php echo esc_attr( $entity_label ); ?>"
								class="regular-text"
								placeholder="e.g. AI Search Visibility"
							/>
						</td>
						<td>
							<input
								type="url"
								name="ajsg_topic_map[<?php echo esc_attr( $cat_id ); ?>][hub_url]"
								value="<?php echo esc_attr( $hub_url ); ?>"
								class="regular-text"
								placeholder="https://"
							/>
						</td>
						<td class="ajsg-status-cell" data-cat-id="<?php echo esc_attr( $cat_id ); ?>">
							<?php if ( $hub_url ) : ?>
							<span class="ajsg-badge" style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;">&#8212;</span>
							<?php else : ?>
							<span style="color:#787c82;font-size:12px;">No URL</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php submit_button( 'Save Topic Entity Map' ); ?>
		</form>

		<hr />

		<h2>Validate Hub Pages</h2>
		<p>Check that each mapped hub URL returns a 200 OK response.</p>
		<button
			id="ajsg-validate-hubs"
			type="button"
			class="button button-secondary"
			data-nonce="<?php echo esc_attr( wp_create_nonce( AJSG_NONCE_VALID ) ); ?>"
		>Validate Hub Pages</button>
		<span id="ajsg-validate-status" style="margin-left:12px;font-style:italic;"></span>
	</div>
	<style>
	.ajsg-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; }
	.ajsg-ok    { background:#d1fae5 !important; color:#065f46 !important; }
	.ajsg-fail  { background:#fee2e2 !important; color:#991b1b !important; }
	.ajsg-load  { background:#fef3c7 !important; color:#92400e !important; }
	</style>
	<?php
	echo ob_get_clean();
}

// ===========================================================================
// SECTION 4 — SCHEMA PREVIEW PAGE
// ===========================================================================

function ajsg_render_preview_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$selected_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

	$all_posts = get_posts(
		array(
			'post_type'        => array( 'post', 'page' ),
			'post_status'      => 'publish',
			'posts_per_page'   => 500,
			'orderby'          => 'post_type',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);

	ob_start();
	?>
	<div class="wrap">
		<h1>Schema Preview</h1>
		<p>Select any published post or page to inspect the full @graph JSON-LD that will be injected into its &lt;head&gt;.</p>

		<form method="get" action="">
			<input type="hidden" name="page" value="aj-schema-preview" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ajsg-post-select">Post / Page</label></th>
					<td>
						<select id="ajsg-post-select" name="post_id" style="min-width:400px;">
							<option value="">&mdash; Select a post or page &mdash;</option>
							<?php
							$current_type = '';
							foreach ( $all_posts as $p ) {
								if ( $p->post_type !== $current_type ) {
									if ( $current_type ) {
										echo '</optgroup>';
									}
									$current_type = $p->post_type;
									echo '<optgroup label="' . esc_attr( ucfirst( $current_type ) . 's' ) . '">';
								}
								printf(
									'<option value="%d"%s>%s</option>',
									(int) $p->ID,
									selected( $selected_id, $p->ID, false ),
									esc_html( $p->post_title )
								);
							}
							if ( $current_type ) {
								echo '</optgroup>';
							}
							?>
						</select>
						<?php
						submit_button(
							'Preview Schema',
							'secondary',
							'submit',
							false,
							array( 'style' => 'margin-left:8px;' )
						);
						?>
					</td>
				</tr>
			</table>
		</form>

		<?php if ( $selected_id ) :
			$schema   = ajsg_build_schema( $selected_id );
			$json     = $schema
				? wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				: '';
			$post_url = get_permalink( $selected_id );
		?>
		<hr />
		<h2><?php echo esc_html( get_the_title( $selected_id ) ); ?></h2>
		<p>
			<strong>URL:</strong>
			<a href="<?php echo esc_url( $post_url ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( $post_url ); ?>
			</a><br />
			<strong>Type:</strong>
			<?php echo esc_html( ucfirst( (string) get_post_type( $selected_id ) ) ); ?>
		</p>
		<?php if ( $json ) : ?>
		<style>
		#ajsg-preview-pre {
			background    : #1e1e1e;
			color         : #d4d4d4;
			font-family   : Consolas, 'Courier New', monospace;
			font-size     : 12px;
			line-height   : 1.5;
			padding       : 14px;
			overflow      : auto;
			max-height    : 620px;
			white-space   : pre;
			border-radius : 4px;
			margin-top    : 8px;
		}
		#ajsg-preview-copy { margin-bottom: 6px; }
		</style>
		<button type="button" id="ajsg-preview-copy" class="button button-secondary">Copy to Clipboard</button>
		<pre id="ajsg-preview-pre"><?php echo esc_html( $json ); ?></pre>
		<?php else : ?>
		<div class="notice notice-warning inline">
			<p>No schema generated. Configure Entity Settings first.</p>
		</div>
		<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	echo ob_get_clean();
}

// ===========================================================================
// SECTION 5 — WP_HEAD SCHEMA INJECTION + BULK REGEN AJAX
// ===========================================================================

add_action( 'wp_head', 'ajsg_inject_schema', 5 );
add_action( 'wp_ajax_ajsg_bulk_regen', 'ajsg_ajax_bulk_regen' );

function ajsg_inject_schema() {
	$post_id = null;
	if ( is_singular() ) {
		$id = (int) get_the_ID();
		if ( $id > 0 ) {
			$post_id = $id;
		}
	}
	$schema = ajsg_build_schema( $post_id );
	if ( empty( $schema ) ) {
		return;
	}
	$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json ) {
		return;
	}
	ob_start();
	echo '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
	echo ob_get_clean();
}

function ajsg_ajax_bulk_regen() {
	if ( ! check_ajax_referer( AJSG_NONCE_REGEN, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		return;
	}
	$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
	$per_page = 50;

	$query = new WP_Query(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);

	$total     = (int) $query->found_posts;
	$processed = min( $page * $per_page, $total );
	$stamp     = time();

	foreach ( $query->posts as $pid ) {
		update_post_meta( (int) $pid, AJSG_META_KEY, $stamp );
	}
	wp_reset_postdata();

	wp_send_json_success(
		array(
			'processed' => $processed,
			'total'     => $total,
			'done'      => ( $processed >= $total ),
		)
	);
}

// ------------------------------------------------------------------
// Schema builder — returns array, no output
// ------------------------------------------------------------------

function ajsg_build_schema( $post_id ) {
	$opts       = ajsg_get_options();
	$site_url   = trailingslashit( ! empty( $opts['website_url'] ) ? $opts['website_url'] : home_url( '/' ) );
	$org_id     = $site_url . '#organization';
	$person_id  = $site_url . '#person';
	$website_id = $site_url . '#website';
	$same_as    = ajsg_get_same_as_urls();
	$graph      = array();

	// --- Organization ---
	$org = array(
		'@type' => 'Organization',
		'@id'   => $org_id,
		'name'  => ! empty( $opts['website_name'] ) ? $opts['website_name'] : get_bloginfo( 'name' ),
		'url'   => $site_url,
	);
	if ( ! empty( $opts['website_logo_url'] ) ) {
		$org['logo'] = array(
			'@type' => 'ImageObject',
			'@id'   => $site_url . '#logo',
			'url'   => $opts['website_logo_url'],
		);
	}
	if ( ! empty( $same_as ) ) {
		$org['sameAs'] = $same_as;
	}
	$graph[] = $org;

	// --- Person ---
	if ( ! empty( $opts['person_name'] ) ) {
		$person = array(
			'@type'    => 'Person',
			'@id'      => $person_id,
			'name'     => $opts['person_name'],
			'worksFor' => array( '@id' => $org_id ),
		);
		if ( ! empty( $opts['person_job_title'] ) ) {
			$person['jobTitle'] = $opts['person_job_title'];
		}
		if ( ! empty( $opts['person_description'] ) ) {
			$person['description'] = $opts['person_description'];
		}
		if ( ! empty( $opts['person_image_url'] ) ) {
			$person['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $opts['person_image_url'],
			);
		}
		if ( ! empty( $same_as ) ) {
			$person['sameAs'] = $same_as;
		}
		$graph[] = $person;
	}

	// --- WebSite ---
	$website = array(
		'@type'           => 'WebSite',
		'@id'             => $website_id,
		'name'            => ! empty( $opts['website_name'] ) ? $opts['website_name'] : get_bloginfo( 'name' ),
		'url'             => $site_url,
		'publisher'       => array( '@id' => $org_id ),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $site_url . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		),
	);
	if ( ! empty( $opts['primary_topic'] ) ) {
		$website['about'] = array(
			'@type' => 'Thing',
			'name'  => $opts['primary_topic'],
		);
	}
	$graph[] = $website;

	// --- DefinedTermSet (global) ---
	$dts = ajsg_build_defined_term_set( $site_url, $org_id );
	if ( ! empty( $dts ) ) {
		$graph[] = $dts;
	}

	// --- Page-specific nodes ---
	if ( ! empty( $post_id ) && $post_id > 0 ) {
		$post_nodes = ajsg_build_post_nodes( $post_id, $org_id, $person_id, $website_id, $site_url );
		foreach ( $post_nodes as $node ) {
			$graph[] = $node;
		}
	} else {
		$archive_nodes = ajsg_build_archive_nodes( $website_id, $org_id, $site_url );
		foreach ( $archive_nodes as $node ) {
			$graph[] = $node;
		}
	}

	if ( empty( $graph ) ) {
		return null;
	}
	return array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);
}

function ajsg_build_defined_term_set( $site_url, $org_id ) {
	$topic_map = ajsg_get_topic_map();
	if ( empty( $topic_map ) ) {
		return null;
	}
	$opts      = ajsg_get_options();
	$site_name = ! empty( $opts['website_name'] ) ? $opts['website_name'] : get_bloginfo( 'name' );
	$term_refs = array();

	foreach ( $topic_map as $cat_id => $mapping ) {
		if ( empty( $mapping['entity_label'] ) ) {
			continue;
		}
		$cat = get_category( $cat_id );
		if ( ! $cat || is_wp_error( $cat ) ) {
			continue;
		}
		$term_refs[] = array( '@id' => $site_url . '#term-' . $cat->slug );
	}
	if ( empty( $term_refs ) ) {
		return null;
	}
	return array(
		'@type'          => 'DefinedTermSet',
		'@id'            => $site_url . '#topicmap',
		'name'           => $site_name . ' Topic Entities',
		'publisher'      => array( '@id' => $org_id ),
		'hasDefinedTerm' => $term_refs,
	);
}

function ajsg_build_post_nodes( $post_id, $org_id, $person_id, $website_id, $site_url ) {
	$post = get_post( $post_id );
	if ( ! ( $post instanceof WP_Post ) ) {
		return array();
	}

	$nodes     = array();
	$page_url  = get_permalink( $post_id );
	$post_type = get_post_type( $post_id );
	$is_post   = ( 'post' === $post_type );

	$date_pub = get_the_date( 'c', $post );
	$date_mod = get_the_modified_date( 'c', $post );

	// Safe description — no apply_filters('the_content')
	$description = trim( $post->post_excerpt );
	if ( empty( $description ) ) {
		$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 55, '...' );
	}
	$description = wp_strip_all_tags( $description );

	$image_node = ajsg_get_featured_image_node( $post_id );
	$topic_map  = ajsg_get_topic_map();

	$article_sections = array();
	$keywords         = array();
	$about_nodes      = array();
	$hub_part_of      = null;

	if ( $is_post ) {
		$categories = get_the_category( $post_id );
		if ( ! empty( $categories ) ) {
			foreach ( $categories as $cat ) {
				$article_sections[] = $cat->name;
				$cat_id             = $cat->term_id;
				$mapping            = isset( $topic_map[ $cat_id ] ) ? $topic_map[ $cat_id ] : array();
				if ( ! empty( $mapping['entity_label'] ) ) {
					$term_ref      = $site_url . '#term-' . $cat->slug;
					$about_nodes[] = array(
						'@type'            => 'DefinedTerm',
						'@id'              => $term_ref,
						'name'             => $mapping['entity_label'],
						'inDefinedTermSet' => array( '@id' => $org_id ),
					);
				}
				if ( null === $hub_part_of && ! empty( $mapping['hub_url'] ) ) {
					$hub_part_of = trailingslashit( $mapping['hub_url'] ) . '#webpage';
				}
			}
		}
		$tags = get_the_tags( $post_id );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				$keywords[] = $tag->name;
			}
		}
	}

	// Detect whether this (non-post) page is itself a hub page
	$page_url_bare = rtrim( $page_url, '/' );
	$hub_matches   = array();
	if ( ! $is_post ) {
		foreach ( $topic_map as $cat_id => $mapping ) {
			if ( empty( $mapping['hub_url'] ) ) {
				continue;
			}
			if ( rtrim( $mapping['hub_url'], '/' ) === $page_url_bare ) {
				$cat = get_category( $cat_id );
				if ( $cat && ! is_wp_error( $cat ) ) {
					$hub_matches[ $cat_id ] = array( 'cat' => $cat, 'mapping' => $mapping );
				}
			}
		}
	}
	$is_hub = ! empty( $hub_matches );

	// Build primary schema node
	if ( $is_post ) {
		$node = array(
			'@type'            => 'BlogPosting',
			'@id'              => $page_url . '#article',
			'headline'         => get_the_title( $post_id ),
			'description'      => $description,
			'url'              => $page_url,
			'datePublished'    => $date_pub,
			'dateModified'     => $date_mod,
			'author'           => array( '@id' => $person_id ),
			'publisher'        => array( '@id' => $org_id ),
			'isPartOf'         => $hub_part_of ? array( '@id' => $hub_part_of ) : array( '@id' => $website_id ),
			'mainEntityOfPage' => $page_url,
			'inLanguage'       => get_bloginfo( 'language' ),
		);
		if ( ! empty( $image_node ) ) {
			$node['image'] = $image_node;
		}
		if ( ! empty( $article_sections ) ) {
			$node['articleSection'] = $article_sections;
		}
		if ( ! empty( $keywords ) ) {
			$node['keywords'] = implode( ', ', $keywords );
		}
		if ( ! empty( $about_nodes ) ) {
			$node['about'] = $about_nodes;
		}

	} elseif ( $is_hub ) {
		// Add standalone DefinedTerm nodes for each matched category
		foreach ( $hub_matches as $cat_id => $data ) {
			$cat     = $data['cat'];
			$mapping = $data['mapping'];
			if ( ! empty( $mapping['entity_label'] ) ) {
				$nodes[] = array(
					'@type'            => 'DefinedTerm',
					'@id'              => $site_url . '#term-' . $cat->slug,
					'name'             => $mapping['entity_label'],
					'inDefinedTermSet' => array( '@id' => $org_id ),
				);
			}
		}
		$node = ajsg_build_hub_page_node( $post_id, $page_url, $hub_matches, $org_id, $website_id, $site_url );

	} else {
		$node = array(
			'@type'            => 'WebPage',
			'@id'              => $page_url . '#webpage',
			'name'             => get_the_title( $post_id ),
			'description'      => $description,
			'url'              => $page_url,
			'datePublished'    => $date_pub,
			'dateModified'     => $date_mod,
			'author'           => array( '@id' => $person_id ),
			'publisher'        => array( '@id' => $org_id ),
			'isPartOf'         => array( '@id' => $website_id ),
			'mainEntityOfPage' => $page_url,
			'inLanguage'       => get_bloginfo( 'language' ),
		);
		if ( ! empty( $image_node ) ) {
			$node['image'] = $image_node;
		}
	}

	$nodes[] = $node;

	// BreadcrumbList
	$crumbs = ajsg_build_breadcrumb_items( $post_id, $post_type );
	if ( ! empty( $crumbs ) ) {
		$nodes[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $page_url . '#breadcrumb',
			'itemListElement' => $crumbs,
		);
	}

	return $nodes;
}

function ajsg_build_hub_page_node( $post_id, $page_url, $hub_matches, $org_id, $website_id, $site_url ) {
	$about_refs  = array();
	$has_part    = array();
	$entity_name = get_the_title( $post_id );

	foreach ( $hub_matches as $cat_id => $data ) {
		$cat     = $data['cat'];
		$mapping = $data['mapping'];
		if ( ! empty( $mapping['entity_label'] ) ) {
			if ( 1 === count( $hub_matches ) ) {
				$entity_name = $mapping['entity_label'];
			}
			$about_refs[] = array( '@id' => $site_url . '#term-' . $cat->slug );
		}
		$cat_posts = get_posts(
			array(
				'category'         => $cat_id,
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $cat_posts as $pid ) {
			$has_part[] = array( '@id' => get_permalink( $pid ) . '#article' );
		}
	}

	// Deduplicate hasPart
	$seen    = array();
	$deduped = array();
	foreach ( $has_part as $item ) {
		$key = $item['@id'];
		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = true;
			$deduped[]    = $item;
		}
	}

	$node = array(
		'@type'      => 'CollectionPage',
		'@id'        => $page_url . '#webpage',
		'name'       => $entity_name,
		'url'        => $page_url,
		'publisher'  => array( '@id' => $org_id ),
		'isPartOf'   => array( '@id' => $website_id ),
		'inLanguage' => get_bloginfo( 'language' ),
	);
	if ( ! empty( $about_refs ) ) {
		$node['about'] = ( 1 === count( $about_refs ) ) ? $about_refs[0] : $about_refs;
	}
	if ( ! empty( $deduped ) ) {
		$node['hasPart'] = $deduped;
	}
	return $node;
}

function ajsg_build_archive_nodes( $website_id, $org_id, $site_url ) {
	global $wp;
	$nodes       = array();
	$request     = isset( $wp->request ) ? trailingslashit( $wp->request ) : '/';
	$current_url = home_url( $request );

	if ( is_home() || is_front_page() ) {
		$node = array(
			'@type'       => 'WebPage',
			'@id'         => $current_url . '#webpage',
			'url'         => $current_url,
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'isPartOf'    => array( '@id' => $website_id ),
			'inLanguage'  => get_bloginfo( 'language' ),
		);
	} elseif ( is_category() ) {
		$queried   = get_queried_object();
		$cat_id    = $queried ? (int) $queried->term_id : 0;
		$topic_map = ajsg_get_topic_map();
		$mapping   = ( $cat_id && isset( $topic_map[ $cat_id ] ) ) ? $topic_map[ $cat_id ] : array();
		$node = array(
			'@type'       => 'CollectionPage',
			'@id'         => $current_url . '#webpage',
			'url'         => $current_url,
			'name'        => single_cat_title( '', false ),
			'description' => ( ! empty( $queried->description ) ) ? $queried->description : '',
			'isPartOf'    => array( '@id' => $website_id ),
			'inLanguage'  => get_bloginfo( 'language' ),
		);
		if ( ! empty( $mapping['entity_label'] ) && $queried ) {
			$node['about'] = array( '@id' => $site_url . '#term-' . $queried->slug );
		}
	} elseif ( is_tag() ) {
		$queried = get_queried_object();
		$node = array(
			'@type'       => 'CollectionPage',
			'@id'         => $current_url . '#webpage',
			'url'         => $current_url,
			'name'        => single_tag_title( '', false ),
			'description' => ( ! empty( $queried->description ) ) ? $queried->description : '',
			'isPartOf'    => array( '@id' => $website_id ),
			'inLanguage'  => get_bloginfo( 'language' ),
		);
	} elseif ( is_author() ) {
		$queried = get_queried_object();
		$node = array(
			'@type'      => 'ProfilePage',
			'@id'        => $current_url . '#webpage',
			'url'        => $current_url,
			'name'       => ( $queried instanceof WP_User ) ? $queried->display_name : '',
			'isPartOf'   => array( '@id' => $website_id ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
	} elseif ( is_date() ) {
		$node = array(
			'@type'      => 'CollectionPage',
			'@id'        => $current_url . '#webpage',
			'url'        => $current_url,
			'name'       => get_the_archive_title(),
			'isPartOf'   => array( '@id' => $website_id ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
	} else {
		$node = array(
			'@type'      => 'WebPage',
			'@id'        => $current_url . '#webpage',
			'url'        => $current_url,
			'isPartOf'   => array( '@id' => $website_id ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
	}

	$node    = array_filter( $node, 'ajsg_is_not_empty_string' );
	$nodes[] = $node;
	return $nodes;
}

function ajsg_build_breadcrumb_items( $post_id, $post_type ) {
	$items    = array();
	$position = 1;

	$items[] = array(
		'@type'    => 'ListItem',
		'position' => $position++,
		'name'     => 'Home',
		'item'     => home_url( '/' ),
	);

	if ( 'post' === $post_type ) {
		$categories = get_the_category( $post_id );
		if ( ! empty( $categories ) ) {
			usort( $categories, 'ajsg_sort_cats_by_term_id' );
			$primary = $categories[0];
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $primary->name,
				'item'     => get_category_link( $primary->term_id ),
			);
		}
	} elseif ( 'page' === $post_type ) {
		$ancestors = array_reverse( get_post_ancestors( $post_id ) );
		foreach ( $ancestors as $ancestor_id ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => get_the_title( $ancestor_id ),
				'item'     => get_permalink( $ancestor_id ),
			);
		}
	}

	$items[] = array(
		'@type'    => 'ListItem',
		'position' => $position,
		'name'     => get_the_title( $post_id ),
		'item'     => get_permalink( $post_id ),
	);
	return $items;
}

function ajsg_sort_cats_by_term_id( $a, $b ) {
	return (int) $a->term_id - (int) $b->term_id;
}

function ajsg_get_featured_image_node( $post_id ) {
	if ( ! has_post_thumbnail( $post_id ) ) {
		return array();
	}
	$thumb_id  = get_post_thumbnail_id( $post_id );
	$thumb_src = wp_get_attachment_image_src( $thumb_id, 'full' );
	if ( ! $thumb_src ) {
		return array();
	}
	$node = array(
		'@type' => 'ImageObject',
		'url'   => $thumb_src[0],
	);
	if ( ! empty( $thumb_src[1] ) ) {
		$node['width'] = (int) $thumb_src[1];
	}
	if ( ! empty( $thumb_src[2] ) ) {
		$node['height'] = (int) $thumb_src[2];
	}
	$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
	if ( $alt ) {
		$node['caption'] = sanitize_text_field( $alt );
	}
	return $node;
}

function ajsg_is_not_empty_string( $value ) {
	return ! ( is_string( $value ) && '' === $value );
}

// ===========================================================================
// SECTION 6 — POST EDITOR META BOX
// ===========================================================================

add_action( 'add_meta_boxes', 'ajsg_add_meta_box', 99 );

function ajsg_add_meta_box() {
	$types = array( 'post', 'page' );
	foreach ( $types as $type ) {
		add_meta_box(
			'ajsg-preview',
			'Schema Graph Preview',
			'ajsg_render_meta_box',
			$type,
			'normal',
			'low'
		);
	}
}

function ajsg_render_meta_box( $post ) {
	$valid_statuses = array( 'publish', 'private', 'draft', 'pending' );
	if ( ! in_array( $post->post_status, $valid_statuses, true ) ) {
		echo '<p>Save the post first to preview schema.</p>';
		return;
	}

	ob_start();

	if ( 'post' === get_post_type( $post->ID ) ) {
		ajsg_render_meta_box_hub_status( $post->ID );
	}

	$schema = ajsg_build_schema( $post->ID );
	if ( empty( $schema ) ) {
		?>
		<p>No schema generated. Please configure Entity Settings first.</p>
		<?php
	} else {
		$json = wp_json_encode(
			$schema,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		?>
		<style>
		#ajsg-schema-pre {
			background    : #1e1e1e;
			color         : #d4d4d4;
			font-family   : Consolas, 'Courier New', monospace;
			font-size     : 12px;
			line-height   : 1.5;
			padding       : 14px;
			overflow      : auto;
			max-height    : 480px;
			white-space   : pre;
			border-radius : 4px;
			margin-top    : 8px;
		}
		#ajsg-copy-btn { margin-bottom: 6px; }
		</style>
		<button type="button" id="ajsg-copy-btn" class="button button-secondary">Copy to Clipboard</button>
		<pre id="ajsg-schema-pre"><?php echo esc_html( $json ); ?></pre>
		<?php
	}

	echo ob_get_clean();
}

function ajsg_render_meta_box_hub_status( $post_id ) {
	$topic_map  = ajsg_get_topic_map();
	$categories = get_the_category( $post_id );

	if ( empty( $categories ) ) {
		ob_start();
		?>
		<div style="background:#fff8e5;border-left:4px solid #dba617;padding:8px 12px;margin-bottom:10px;">
			<strong>&#9888; No categories assigned.</strong>
			Assign at least one category to enable topic entity mapping.
		</div>
		<?php
		echo ob_get_clean();
		return;
	}

	$mapped   = array();
	$unmapped = array();

	foreach ( $categories as $cat ) {
		$cat_id = $cat->term_id;
		$entry  = isset( $topic_map[ $cat_id ] ) ? $topic_map[ $cat_id ] : array();
		if ( ! empty( $entry['hub_url'] ) || ! empty( $entry['entity_label'] ) ) {
			$mapped[] = array(
				'cat_name'     => $cat->name,
				'hub_url'      => isset( $entry['hub_url'] ) ? $entry['hub_url'] : '',
				'entity_label' => isset( $entry['entity_label'] ) ? $entry['entity_label'] : '',
			);
		} else {
			$unmapped[] = $cat->name;
		}
	}

	ob_start();
	if ( ! empty( $mapped ) ) {
		?>
		<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin-bottom:10px;">
			<strong>Hub page mappings:</strong>
			<?php foreach ( $mapped as $m ) : ?>
			<span style="display:block;margin-top:4px;">
				<strong><?php echo esc_html( $m['cat_name'] ); ?></strong>
				<?php if ( ! empty( $m['entity_label'] ) ) : ?>
					&rarr; <em><?php echo esc_html( $m['entity_label'] ); ?></em>
				<?php endif; ?>
				<?php if ( ! empty( $m['hub_url'] ) ) : ?>
					&rarr; <a href="<?php echo esc_url( $m['hub_url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( $m['hub_url'] ); ?>
					</a>
				<?php endif; ?>
			</span>
			<?php endforeach; ?>
		</div>
		<?php
	}
	if ( ! empty( $unmapped ) ) {
		$map_url = admin_url( 'admin.php?page=aj-schema-topic-map' );
		?>
		<div style="background:#fff8e5;border-left:4px solid #dba617;padding:8px 12px;margin-bottom:10px;">
			<strong>&#9888; Unmapped categories:</strong>
			<?php echo esc_html( implode( ', ', $unmapped ) ); ?><br />
			<small>Go to <a href="<?php echo esc_url( $map_url ); ?>">Topic Entity Map</a> to configure hub pages.</small>
		</div>
		<?php
	}
	echo ob_get_clean();
}

// ===========================================================================
// ADMIN SCRIPTS
// ===========================================================================

add_action( 'admin_enqueue_scripts', 'ajsg_enqueue_scripts', 99 );

function ajsg_enqueue_scripts( $hook ) {
	error_log( 'AJSG: admin_enqueue_scripts hook = ' . $hook );

	if ( 'toplevel_page_aj-schema-graph' === $hook ) {
		add_action( 'admin_footer', 'ajsg_output_settings_js' );
	}
	if ( 'schema-graph_page_aj-schema-topic-map' === $hook ) {
		add_action( 'admin_footer', 'ajsg_output_topic_map_js' );
	}
	if ( 'schema-graph_page_aj-schema-preview' === $hook ) {
		add_action( 'admin_footer', 'ajsg_output_preview_js' );
	}
	$screen = get_current_screen();
	if ( $screen && 'post' === $screen->base ) {
		add_action( 'admin_footer', 'ajsg_output_meta_box_js' );
	}
}

function ajsg_output_settings_js() {
	$nonce = wp_create_nonce( AJSG_NONCE_REGEN );
	ob_start();
	?>
	<script>
	(function($) {
		'use strict';
		$('#ajsg-bulk-regen').on('click', function() {
			var $btn    = $(this);
			var $status = $('#ajsg-regen-status');
			var $wrap   = $('#ajsg-regen-progress');
			var $bar    = $('#ajsg-progress-bar');
			var $text   = $('#ajsg-progress-text');
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			$btn.prop('disabled', true);
			$status.text('Processing\u2026');
			$wrap.show();
			$bar.val(0);
			$text.text('');

			function processPage(page) {
				$.post(
					window.ajaxurl,
					{action: 'ajsg_bulk_regen', nonce: nonce, page: page},
					function(response) {
						if (!response.success) {
							$status.text(response.data.message || 'An error occurred.');
							$btn.prop('disabled', false);
							return;
						}
						var d   = response.data;
						var pct = d.total > 0 ? Math.round((d.processed / d.total) * 100) : 100;
						$bar.val(pct);
						$text.text(d.processed + ' / ' + d.total);
						if (d.done) {
							$status.text('Done! All posts updated.');
							$btn.prop('disabled', false);
						} else {
							processPage(page + 1);
						}
					},
					'json'
				).fail(function() {
					$status.text('Request failed. Please try again.');
					$btn.prop('disabled', false);
				});
			}
			processPage(1);
		});
	})(jQuery);
	</script>
	<?php
	echo ob_get_clean();
}

function ajsg_output_topic_map_js() {
	ob_start();
	?>
	<script>
	(function($) {
		'use strict';
		$('#ajsg-validate-hubs').on('click', function() {
			var $btn    = $(this);
			var $status = $('#ajsg-validate-status');
			var nonce   = $btn.data('nonce');

			$btn.prop('disabled', true);
			$status.text('Checking URLs\u2026');

			$('.ajsg-status-cell .ajsg-badge').each(function() {
				$(this).removeClass('ajsg-ok ajsg-fail').addClass('ajsg-load').text('Checking\u2026');
			});

			$.post(
				window.ajaxurl,
				{action: 'ajsg_validate_hubs', nonce: nonce},
				function(response) {
					$btn.prop('disabled', false);
					if (!response.success) {
						$status.text(response.data.message || 'Validation failed.');
						return;
					}
					var results  = response.data.results;
					var okCount  = 0;
					var badCount = 0;
					$.each(results, function(catId, info) {
						var $cell  = $('.ajsg-status-cell[data-cat-id="' + catId + '"]');
						var $badge = $cell.find('.ajsg-badge');
						if (!$badge.length) {
							$badge = $('<span class="ajsg-badge">');
							$cell.html($badge);
						}
						$badge.removeClass('ajsg-load');
						if (info.ok) {
							$badge.addClass('ajsg-ok').text('200 OK');
							okCount++;
						} else {
							var label = info.code > 0 ? String(info.code) : (info.error || 'Error');
							$badge.addClass('ajsg-fail').text(label);
							if (info.error) { $badge.attr('title', info.error); }
							badCount++;
						}
					});
					$('.ajsg-load').removeClass('ajsg-load').text('\u2014');
					var summary = okCount + ' OK';
					if (badCount > 0) { summary += ', ' + badCount + ' failed'; }
					$status.text(summary);
				},
				'json'
			).fail(function() {
				$btn.prop('disabled', false);
				$status.text('Request failed.');
				$('.ajsg-load').removeClass('ajsg-load').text('\u2014');
			});
		});
	})(jQuery);
	</script>
	<?php
	echo ob_get_clean();
}

function ajsg_output_preview_js() {
	ob_start();
	?>
	<script>
	(function() {
		'use strict';
		var btn = document.getElementById('ajsg-preview-copy');
		if (!btn) { return; }
		btn.addEventListener('click', function() {
			var text = document.getElementById('ajsg-preview-pre').textContent;
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					btn.textContent = 'Copied!';
					setTimeout(function() { btn.textContent = 'Copy to Clipboard'; }, 2000);
				});
			} else {
				var ta = document.createElement('textarea');
				ta.style.cssText = 'position:fixed;opacity:0;';
				ta.value = text;
				document.body.appendChild(ta);
				ta.select();
				document.execCommand('copy');
				document.body.removeChild(ta);
				btn.textContent = 'Copied!';
				setTimeout(function() { btn.textContent = 'Copy to Clipboard'; }, 2000);
			}
		});
	})();
	</script>
	<?php
	echo ob_get_clean();
}

function ajsg_output_meta_box_js() {
	ob_start();
	?>
	<script>
	(function($) {
		'use strict';
		$('#ajsg-copy-btn').on('click', function() {
			var $btn = $(this);
			var text = $('#ajsg-schema-pre').text();
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					$btn.text('Copied!');
					setTimeout(function() { $btn.text('Copy to Clipboard'); }, 2000);
				});
			} else {
				var $ta = $('<textarea style="position:fixed;opacity:0;">').val(text).appendTo('body').select();
				document.execCommand('copy');
				$ta.remove();
				$btn.text('Copied!');
				setTimeout(function() { $btn.text('Copy to Clipboard'); }, 2000);
			}
		});
	})(jQuery);
	</script>
	<?php
	echo ob_get_clean();
}
