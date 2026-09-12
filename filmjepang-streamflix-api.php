<?php
/*
 * Plugin Name: FilmJepang StreamFlix API
 * Description: Registers a secure REST API endpoint for StreamFlix app.
 * Version: 1.0.0
 * Author: Chris S.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// ==========================================
// DEBUGGING (REMOVE IN PRODUCTION)
// ==========================================
// define( 'WP_DEBUG', true );
// define( 'WP_DEBUG_DISPLAY', true );
// @ini_set( 'display_errors', 1 );
// ==========================================

/**
 * Class StreamFlix_REST_Controller
 * Extends WP_REST_Controller to adopt standard WordPress REST framework conventions.
 */
class StreamFlix_REST_Controller extends WP_REST_Controller {

    private $auth_token;
    private $route_mappings;

    // SORT PARAMETER KEYS
    const SORT_NEW            = 'new'; // latest publish date
    const SORT_RELEASE        = 'release_date'; // latest release date
    const SORT_HOT            = 'hot'; // highest today views
    const SORT_TRENDING       = 'trending'; // highest weekly views
    const SORT_POPULAR        = 'popular'; // highest votes
    const SORT_TOP_RATED      = 'top_rated'; // highest imdb_score
    const SORT_MOST_VIEWED    = 'most_viewed'; // highest total views

    public static function get_allowed_sort_options() {
        return [
            self::SORT_NEW,
            self::SORT_RELEASE,
            self::SORT_HOT,
            self::SORT_TRENDING,
            self::SORT_POPULAR,
            self::SORT_TOP_RATED,
            self::SORT_MOST_VIEWED
        ];
    }

     /**
     * Constructor to define the API namespace, base route, and secret token.
     */
    public function __construct() {
        $this->namespace = 'streamflix/v1';
        $this->rest_base = 'movies';

        // Bearer authentication token to be set in wp-config.php as
        // define( 'STREAMFLIX_API_TOKEN', 'your_long_random_secure_secret_string_here' );
        $this->auth_token =  STREAMFLIX_API_TOKEN;

        $this->route_mappings = [
            'codes'      => 'kode-prefix',
            'actors'     => 'actor',
            'categories' => 'category',
            'genres'     => 'genre',
            'years'      => 'years',
            'studios'    => 'studio',
            'qualities'  => 'quality',
            'countries'  => 'country',
            'series'     => 'movie-series', // Updated to match your database output
        ];
    }

    // #region ==== REGISTER ROUTES ====

    /**
     * Register the API routes under the rest_api_init hook.
     */
     public function register_routes() {
        // Base query parameters definition for reuse across list endpoints
        $pagination_args = [
            'per_page' => [
                'default'           => 35,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
                'type'              => 'integer',
            ],
            'page' => [
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
                'type'              => 'integer',
            ],
            'sort' => [
                'default'           => self::SORT_NEW,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param) {
                    return in_array($param, self::get_allowed_sort_options(), true);
                },
                'type'              => 'string',
            ],
        ];

        // 1. GET /movies
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_movies'],
            'permission_callback' => [$this, 'check_auth_permission'],
            'args'                => $pagination_args
        ]);

        // 2. GET /movies/search?q=[term]
        register_rest_route($this->namespace, '/' . $this->rest_base . '/search', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'search_movies'],
            'permission_callback' => [$this, 'check_auth_permission'],
            'args'                => array_merge($pagination_args, [
                'q' => [
                    'required'          => true, // Block requests completely if ?q= is omitted
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return !empty(trim($param));
                    }
                ]
            ])
        ]);

        // 3. GET /movies/query?q=[term]
        register_rest_route($this->namespace, '/' . $this->rest_base . '/query', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'query_movies'],
            'permission_callback' => [$this, 'check_auth_permission'],
            'args'                => array_merge($pagination_args, [
                'q' => [
                    'required'          => true, // Block requests completely if ?q= is omitted
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return !empty(trim($param));
                    }
                ]
            ])
        ]);

        // 4. GET /movies/rebuild-cache
        register_rest_route($this->namespace, '/' . $this->rest_base . '/rebuild-cache', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rebuild_cache'],
            'permission_callback' => [$this, 'check_auth_permission'],
        ]);

        // 5. GET /movies/rebuild-index
        register_rest_route($this->namespace, '/' . $this->rest_base . '/rebuild-index', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rebuild_index'],
            'permission_callback' => [$this, 'check_auth_permission'],
        ]);

        // 6. GET /movies/[taxonomy] and GET /movies/[taxonomy]/[term]
        foreach ($this->route_mappings as $route_segment => $taxonomy_slug) {
            // GET /movies/[taxonomy]
            register_rest_route($this->namespace, '/' . $this->rest_base . '/' . $route_segment, [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_taxonomy_terms'],
                'permission_callback' => [$this, 'check_auth_permission'],
            ]);

            // GET /movies/[taxonomy]/[term]
            register_rest_route($this->namespace, '/' . $this->rest_base . '/' . $route_segment . '/(?P<term>[a-zA-Z0-9\-_]+)', [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_movies_by_taxonomy_term'],
                'permission_callback' => [$this, 'check_auth_permission'],
                'args'                => $pagination_args,
            ]);
        }

        // 7. GET /movies/[post_id] (Kept at bottom to avoid wildcard regex overlap conflicts)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_movie'],
            'permission_callback' => [$this, 'check_auth_permission'],
        ]);
    }

    // #endregion

    // #region ==== API FUNCTIONS ====

    /**
     *  GET /movies -> Gets a list of movies sorted by latest publish date.
     *
     * @param WP_REST_Request $request The REST request object containing parameters.
     * @return WP_REST_Response The transformed movie collection with pagination metadata.
     */
    public function get_movies($request) {
        global $wpdb;

        // Generate a route-specific cache key signature
        $cache_key = $this->get_cache_key($request);
        
        // 1. LOOK UP ID MAPS ONLY OUT OF THE CACHE ENGINE
        $cached_ids = get_transient($cache_key);
        
        $per_page = !empty($request['per_page']) ? absint($request['per_page']) : 35;
        $page     = !empty($request['page'])     ? absint($request['page'])     : 1;
        $sort     = !empty($request['sort'])     ? sanitize_text_field($request['sort']) : self::SORT_NEW;
        $offset   = ($page - 1) * $per_page;

        if (false !== $cached_ids && is_array($cached_ids)) {
            // Directly pull structural items from the cache profile
            $matched_post_ids = $cached_ids['ids'];
            $total_results    = $cached_ids['total_results'];
        } else {
            // Conditional Meta-Join Architecture
            $meta_join   = "";
            $order_field = "p.post_date";

            switch ($sort) {
                case self::SORT_RELEASE:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sora_date'";
                    $order_field = "IFNULL(pm.meta_value, '1970-01-01')";
                    break;
                case self::SORT_HOT:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'ts_today_view_count'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_TRENDING:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'ts_weekly_view_count'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_POPULAR:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sora_votes'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_TOP_RATED:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sora_imdb'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_MOST_VIEWED:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'wpb_post_views_count'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_NEW:
                default:
                    $order_field = "p.post_date";
                    break;
            }

            $data_query = "
                SELECT p.ID, COUNT(p.ID) OVER() as full_count
                FROM {$wpdb->posts} p
                $meta_join
                WHERE p.post_type = 'post'
                  AND p.post_status = 'publish'
                ORDER BY $order_field DESC
                LIMIT %d, %d
            ";

            $db_results = $wpdb->get_results($wpdb->prepare($data_query, $offset, $per_page));

            if (empty($db_results)) {
                return rest_ensure_response(['page' => $page, 'total_results' => 0, 'total_pages' => 0, 'results' => []]);
            }

            $total_results = intval($db_results[0]->full_count);
            $matched_post_ids = wp_list_pluck($db_results, 'ID');

            // 2. CACHE ONLY THE IDs IN CACHE FOR LOOKUP
            $cache_payload = [
                'ids'           => $matched_post_ids,
                'total_results' => $total_results
            ];

            // Cache volatile list for 30 minutes (hot/trending), static list for 2 hours
            $expiration = in_array($sort, [self::SORT_HOT, self::SORT_TRENDING], true) ? (30 * MINUTE_IN_SECONDS) : (12 * HOUR_IN_SECONDS);
            set_transient($cache_key, $cache_payload, $expiration);
        }

        // 3. GENERATE FULL DATA STRUCTURE OBJECTS OUT OF LIGHT INT IDS ON-THE-FLY
        $movies_payload = $this->transform_movie_collection($matched_post_ids);

        return rest_ensure_response([
            'page'          => $page,
            'total_results' => $total_results,
            'total_pages'   => ceil($total_results / $per_page),
            'results'       => $movies_payload
        ]);
    }

    /**
     * GET /movies/[id] -> Gets a single movie by its post ID.
     *
     * @param WP_REST_Request $request The REST request object containing parameters.
     * @return WP_REST_Response|WP_Error The transformed movie data or an error if not found.
     */
    public function get_movie($request) {
        $post_id = intval($request['id']);

        $cache_key = $this->get_cache_key($request);
        $cached_data = get_transient($cache_key);
        if (false !== $cached_data) {
            return rest_ensure_response($cached_data);
        }

        $post    = get_post($post_id);

        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return new WP_Error('no_movie', 'Movie not found', ['status' => 404]);
        }

        $movie = $this->transform_movie($post_id);

        set_transient( $cache_key, $movie, 12 * HOUR_IN_SECONDS );

        return rest_ensure_response($movie);
    }

     /**
     * GET /movies/[taxonomy_route] -> Gets a list of terms for the specified taxonomy.
     *
     * @param WP_REST_Request $request The REST request object containing parameters.
     * @return WP_REST_Response|WP_Error The list of terms for the specified taxonomy
     */
    public function get_taxonomy_terms($request) {        
        $taxonomy = '';
        $current_route = $request->get_route();
        $cache_key = $this->get_cache_key($request);
        $cached_data = get_transient($cache_key);

        if (false !== $cached_data) {
            return rest_ensure_response($cached_data);
        }

        foreach ($this->route_mappings as $segment => $slug) {
            if (false !== strpos($current_route, '/' . $this->rest_base . '/' . $segment)) {
                $taxonomy = $slug;
                break;
            }
        }

        if (empty($taxonomy)) {
            return new WP_Error('missing_taxonomy', 'Target taxonomy context is missing', ['status' => 400]);
        }

        $term_query = new WP_Term_Query([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        $terms = $term_query->get_terms();

        if (is_wp_error($terms)) {
            return new WP_Error('taxonomy_error', 'Could not retrieve taxonomy metadata terms', ['status' => 500]);
        }

        $payload = [];
        foreach ($terms as $term) {
            $payload[] = [
                'term_id' => $term->term_id,
                'name'    => $term->name,
                'slug'    => $term->slug,
                'count'   => intval($term->count)
            ];
        }

        set_transient( $cache_key, $payload, 12 * HOUR_IN_SECONDS );

        return rest_ensure_response($payload);
    }

    /**
     * GET /movies/[taxonomy_route]/[term] -> Gets a list of movies filtered by a specific taxonomy term.
     * 
     * @param WP_REST_Request $request The REST request object containing parameters.
     * @return WP_REST_Response|WP_Error The list of movies filtered by the specified
     */
    public function get_movies_by_taxonomy_term($request) {
        global $wpdb;
        
        $route_path = trim($request->get_route(), '/');
        $segments = explode('/', $route_path);               
        $term_slug = end($segments);      
        $taxonomy_segment = prev($segments);   
        $taxonomy = isset($this->route_mappings[$taxonomy_segment]) ? $this->route_mappings[$taxonomy_segment] : '';

        if (empty($taxonomy)) {
            return new WP_Error('missing_taxonomy', 'Target taxonomy context is missing or invalid: ' . esc_html($taxonomy_segment), ['status' => 400]);
        }

        $per_page = !empty($request['per_page']) ? absint($request['per_page']) : 35;
        $page     = !empty($request['page'])     ? absint($request['page'])     : 1;
        $sort     = !empty($request['sort'])     ? sanitize_text_field($request['sort']) : self::SORT_NEW;
        $offset   = ($page - 1) * $per_page;

        // Generate a route-specific cache key signature
        $cache_key = $this->get_cache_key($request);
        
        // 1. LOOK UP ID MAPS ONLY OUT OF THE CACHE ENGINE
        $cached_ids = get_transient($cache_key);

        if (false !== $cached_ids && is_array($cached_ids)) {
            $matched_post_ids = $cached_ids['ids'];
            $total_results    = $cached_ids['total_results'];
        } else {
            $query_term = "
                SELECT tt.term_taxonomy_id 
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = %s 
                  AND (t.slug = %s OR LOWER(t.name) = %s)
                LIMIT 1
            ";
            $term_taxonomy_id = $wpdb->get_var($wpdb->prepare($query_term, $taxonomy, $term_slug, str_replace('-', ' ', strtolower($term_slug))));

            if (empty($term_taxonomy_id)) {
                return rest_ensure_response(['taxonomy' => $taxonomy, 'term' => $term_slug, 'page' => $page, 'total_results' => 0, 'total_pages' => 0, 'results' => []]);
            }

            $meta_join   = "";
            $order_field = "p.post_date";

            switch ($sort) {
                case self::SORT_RELEASE:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sora_date'";
                    $order_field = "IFNULL(pm.meta_value, '1970-01-01')";
                    break;
                case self::SORT_HOT:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'ts_today_view_count'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_TRENDING:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'ts_weekly_view_count'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_POPULAR:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sora_votes'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_TOP_RATED:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sora_imdb'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_MOST_VIEWED:
                    $meta_join   = "LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'wpb_post_views_count'";
                    $order_field = "CAST(IFNULL(pm.meta_value, 0) AS UNSIGNED)";
                    break;
                case self::SORT_NEW:
                default:
                    $order_field = "p.post_date";
                    break;
            }

            $data_query = "
                SELECT p.ID, COUNT(p.ID) OVER() as full_count
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                $meta_join
                WHERE tr.term_taxonomy_id = %d
                  AND p.post_type = 'post'
                  AND p.post_status = 'publish'
                ORDER BY $order_field DESC
                LIMIT %d, %d
            ";

            $db_results = $wpdb->get_results($wpdb->prepare($data_query, $term_taxonomy_id, $offset, $per_page));

            if (empty($db_results)) {
                return rest_ensure_response(['taxonomy' => $taxonomy, 'term' => $term_slug, 'page' => $page, 'total_results' => 0, 'total_pages' => 0, 'results' => []]);
            }

            $total_results = intval($db_results[0]->full_count);
            $matched_post_ids = wp_list_pluck($db_results, 'ID');

            // 2. CACHE ONLY THE IDs IN CACHE FOR LOOKUP
            $cache_payload = [
                'ids'           => $matched_post_ids,
                'total_results' => $total_results
            ];

            // Cache volatile list for 30 minutes (hot/trending), static list for 2 hours
            $expiration = in_array($sort, [self::SORT_HOT, self::SORT_TRENDING], true) ? (30 * MINUTE_IN_SECONDS) : (12 * HOUR_IN_SECONDS);
            set_transient($cache_key, $cache_payload, $expiration);
        }

        // 3. GENERATE FULL DATA STRUCTURE OBJECTS OUT OF LIGHT INT IDS ON-THE-FLY
        $movies_payload = $this->transform_movie_collection($matched_post_ids);

        return rest_ensure_response([
            'taxonomy'      => $taxonomy,
            'term'          => $term_slug,
            'page'          => $page,
            'total_results' => $total_results,
            'total_pages'   => ceil($total_results / $per_page),
            'results'       => $movies_payload
        ]);
    }

    /**
     * GET /movies/search?q=[term] -> High Performance MySQL Fulltext with Exact Phrase & Code Boosting.
     * Guarantees full actor names, titles, and codes get absolute priority at the top of the results.
     * 
     * @param WP_REST_Request $request The REST request object containing parameters.
     * @return WP_REST_Response|WP_Error The list of movies matching the search criteria.
     */
    public function search_movies($request) {
        global $wpdb;
        $search_term = sanitize_text_field($request['q']);
        $per_page    = $request['per_page'];
        $page        = $request['page'];
        $sort        = $request['sort']; 
        $offset      = ($page - 1) * $per_page;

        $table = $wpdb->prefix . 'streamflix_search_index';

        if (empty(trim($search_term))) {
            return new WP_Error('empty_query', 'Search query cannot be empty.', ['status' => 400]);
        }

        $priority_ids = [];

        // -----------------------------------------------------------------
        // STEP 1: PRIORITY BOOSTING (Only run on Page 1 to avoid pagination shifts)
        // -----------------------------------------------------------------
        if ($page === 1) {
            // Priority A: Check for an exact unique movie code match first
            $exact_code_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $table WHERE movie_code = %s LIMIT 1",
                $search_term
            ));
            if (!empty($exact_code_id)) {
                $priority_ids[] = intval($exact_code_id);
            }

            // Priority B: Check for Exact Phrase Matches (e.g., Full Actor Name sequence "Yuna Shiina")
            // Wrapping the term in double quotes forces MySQL to look for the exact string combination
            $exact_phrase_term = '"' . $search_term . '"';
            $phrase_matches = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM $table 
                 WHERE MATCH(movie_title, movie_code, meta_data) AGAINST (%s IN BOOLEAN MODE) 
                 LIMIT 20",
                $exact_phrase_term
            ));

            if (!empty($phrase_matches)) {
                $priority_ids = array_merge($priority_ids, array_map('intval', $phrase_matches));
            }

            // Consolidate priority layers
            $priority_ids = array_unique($priority_ids);
        }

        // -----------------------------------------------------------------
        // STEP 2: BROAD FULLTEXT ENGINE MATCHING (Wildcard Fallback)
        // -----------------------------------------------------------------
        $boolean_search_term = $search_term . '*';

        // Map sorting configuration parameter strategies
        switch ($sort) {
            case self::SORT_RELEASE:
                $order_clause = " ORDER BY relevance DESC, release_date DESC";
                break;
            case self::SORT_HOT:
                $order_clause = " ORDER BY relevance DESC, today_views DESC";
                break;
            case self::SORT_TRENDING:
                $order_clause = " ORDER BY relevance DESC, weekly_views DESC";
                break;
            case self::SORT_POPULAR:
                $order_clause = " ORDER BY relevance DESC, votes DESC";
                break;
            case self::SORT_TOP_RATED:
                $order_clause = " ORDER BY relevance DESC, imdb_score DESC";
                break;
            case self::SORT_MOST_VIEWED:
                $order_clause = " ORDER BY relevance DESC, total_views DESC";
                break;
            case self::SORT_NEW:
            default:
                $order_clause = " ORDER BY relevance DESC, created_date DESC";
                break;
        }

        // Fetch Total Found Results count
        $total_results = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE MATCH(movie_title, movie_code, meta_data) AGAINST (%s IN BOOLEAN MODE)", 
            $boolean_search_term
        ));

        $broad_ids = [];
        if ($total_results > 0) {
            $query_string = "SELECT post_id, MATCH(movie_title, movie_code, meta_data) AGAINST (%s IN BOOLEAN MODE) as relevance 
                             FROM $table 
                             WHERE MATCH(movie_title, movie_code, meta_data) AGAINST (%s IN BOOLEAN MODE) 
                             $order_clause 
                             LIMIT %d, %d";
            
            $results = $wpdb->get_results($wpdb->prepare($query_string, $boolean_search_term, $boolean_search_term, $offset, $per_page));
            $broad_ids = wp_list_pluck($results, 'post_id');
        }

        // -----------------------------------------------------------------
        // STEP 3: HYBRID MERGE (Prepend Priority Hits into position #1, #2...)
        // -----------------------------------------------------------------
        if (!empty($priority_ids)) {
            // Strip out priority items from the broad results to avoid duplicates
            $broad_ids = array_diff($broad_ids, $priority_ids);
            
            // Merge them seamlessly on top
            $final_matched_ids = array_merge($priority_ids, $broad_ids);
            
            // Safely calculate cross-boundary total counts adjustments
            if ($total_results == 0) {
                $total_results = count($final_matched_ids);
            }
        } else {
            $final_matched_ids = $broad_ids;
        }

        if (empty($final_matched_ids)) {
            return rest_ensure_response(['query' => $search_term, 'page' => $page, 'total_results' => 0, 'total_pages' => 0, 'results' => []]);
        }

        // Constrain final payload items size to the per_page limit
        $final_matched_ids = array_slice($final_matched_ids, 0, $per_page);

        // Transform the matched post IDs into fully structured movie payloads
        $movies_payload = $this->transform_movie_collection($final_matched_ids);

        return rest_ensure_response([
            'query'         => $search_term,
            'page'          => $page,
            'total_results' => intval($total_results),
            'total_pages'   => ceil($total_results / $per_page),
            'results'       => $movies_payload
        ]);
    }

    /**
     * GET /movies/query?q=[term] -> Aggregate Search Engine combining titles, custom metadata, and taxonomy terms as predicates.
     * 
     * @param WP_REST_Request $request The REST request object containing parameters.   
     * @return WP_REST_Response|WP_Error The list of movies matching the search criteria across multiple sweeps.
     */
    public function query_movies($request) {
        $search_term = sanitize_text_field($request['q']); 
        $per_page    = $request['per_page'];
        $page        = $request['page'];
        $sort        = $request['sort'];   

        // Track all unique post IDs that match any of our search criteria
        $matched_post_ids = [];

        // -----------------------------------------------------------------
        // SWEEP 1: Broad Taxonomy Lookup (Matches Actors, Genres, Years, Qualities, etc.)
        // -----------------------------------------------------------------
        $predicates = [
            'kode-prefix',
            'actor',
            'category',
            'genre',
            'studio',
            'years',
            'quality',
            'country',
            'series'
        ];

        // Fetch any taxonomy term that loosely matches our search query string
        $terms = get_terms([
            'taxonomy'   => $predicates,
            'search'     => $search_term, // Performs a loose SQL 'LIKE' search naturally
            'hide_empty' => true,
        ]);

        if (!is_wp_error($terms) && !empty($terms)) {
            $term_ids = wp_list_pluck($terms, 'term_id');
            
            // Get all post IDs bound to any of those matching taxonomy terms
            $tax_posts = get_objects_in_term($term_ids, $predicates);
            if (!is_wp_error($tax_posts) && !empty($tax_posts)) {
                $matched_post_ids = array_merge($matched_post_ids, $tax_posts);
            }
        }

        // -----------------------------------------------------------------
        // SWEEP 2: Exact Metadata Code Lookup (Matches Custom Meta: _fjsi_code)
        // -----------------------------------------------------------------
        $meta_query = new WP_Query([
            'post_type'      => 'post',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_fjsi_code',
                    'value'   => $search_term,
                    'compare' => 'LIKE'
                ]
            ]
        ]);
        if (!empty($meta_query->posts)) {
            $matched_post_ids = array_merge($matched_post_ids, $meta_query->posts);
        }

        // -----------------------------------------------------------------
        // SWEEP 3: Text Keyword Search (Matches Titles, Contents, Slugs)
        // -----------------------------------------------------------------
        $text_query = new WP_Query([
            'post_type'      => 'post',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
            's'              => $search_term
        ]);
        if (!empty($text_query->posts)) {
            $matched_post_ids = array_merge($matched_post_ids, $text_query->posts);
        }

        // -----------------------------------------------------------------
        // SWEEP 4: Clean up, Consolidate, and Paginate Final Output
        // -----------------------------------------------------------------
        $matched_post_ids = array_unique(array_map('intval', $matched_post_ids));

        // If absolutely no matches were found across all sweeps, return empty arrays instantly
        if (empty($matched_post_ids)) {
            return rest_ensure_response([
                'query'         => $search_term,
                'page'          => $page,
                'total_results' => 0,
                'total_pages'   => 0,
                'results'       => []
            ]);
        }

        // Execute the final paginated request targeting our specific list of IDs
        $args = [
            'post_type'      => 'post',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => 'publish',
            'post__in'       => $matched_post_ids,
            'orderby'        => 'post__in', // Keeps posts sorted by their closest matching relevance order
        ];

        $final_query = new WP_Query($args);
        $movies_payload = [];

        if ($final_query->have_posts()) {
            $movies_payload = $this->transform_movie_collection($final_query->get_posts());
            wp_reset_postdata();
        }

        return rest_ensure_response([
            'query'         => $search_term,
            'page'          => $page,
            'total_results' => intval($final_query->found_posts),
            'total_pages'   => intval($final_query->max_num_pages),
            'results'       => $movies_payload
        ]);
    }

    /**
     * GET /movies/rebuild-cache -> Manual utility to force clear out transient cache layers.
     * 
     * @param WP_REST_Request $request The REST request object containing parameters.
     * @return WP_REST_Response The response indicating cache flush success and performance metrics.
     */
    public function rebuild_cache($request) {
        $start_time = microtime(true);

        // Detect engine context configuration rules for explicit environment telemetry
        $is_persistent = wp_using_ext_object_cache();
        $cache_engine  = 'Database Tables Fallback';

        if ($is_persistent && isset($GLOBALS['wp_object_cache'])) {
            $cache_engine = get_class($GLOBALS['wp_object_cache']);
        }

        $this->purge_all_api_cache();
        
        $execution_time = microtime(true) - $start_time;

        return rest_ensure_response([
            'success'        => true,
            'message'        => 'API cache pools cleared successfully.',
            'telemetry'      => [
                'external_object_cache' => $is_persistent ? 'Active' : 'Inactive',
                'cache_driver_engine'   => $cache_engine,
                'time_taken'            => round($execution_time, 4) . ' seconds'
            ]
        ]);
    }

    /**
     * GET /movies/rebuild-index -> Wipes out and comprehensively regenerates the custom fulltext index rows.
     * 
     * @param WP_REST_Request $request The REST request object containing parameters.
     * @return WP_REST_Response The response indicating index rebuild success and performance metrics.
     */
     public function rebuild_index($request) {
        global $wpdb;
        $start_time = microtime(true);
        $table_name = $wpdb->prefix . 'streamflix_search_index';

        // 1. Temporarily bump the memory limit for this runtime context to safeguard execution
        @ini_set('memory_limit', '512M');

        // 2. Clear out old entries to start fresh
        $wpdb->query("TRUNCATE TABLE $table_name");

        $count       = 0;
        $page        = 1;
        $per_page    = 100; // Small batch size keeps memory usage perfectly flat
        $batch_count = 0;

        // 3. Data Streaming Loop
        do {
            $posts = get_posts([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'fields'         => 'ids',
                'cache_results'  => false, // Crucial: Disables internal object storage caching to free memory
            ]);

            if (empty($posts) || is_wp_error($posts)) {
                break;
            }

            $batch_count = count($posts);

            foreach ($posts as $post_id) {
                $this->index_single_movie($post_id);
                $count++;
            }

            // 4. Force clean internal memory buffers to prevent memory leaks
            unset($posts);
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }

            $page++;
        } while ($batch_count === $per_page); // FIX: Evaluates the tracking integer instead of the deleted array

        // 5. Clean cache transients layers
        $this->purge_all_api_cache();
        $execution_time = microtime(true) - $start_time;

        return rest_ensure_response([
            'success'       => true,
            'indexed_count' => $count,
            'message'       => 'Search indices completely rebuilt using chunked streaming.',
            'performance'   => [
                'time_taken' => round($execution_time, 4) . ' seconds'
            ]
        ]);
    }

    // #endregion
    
    // #region ==== HELPER FUNCTIONS ====

    /**
     * Validate the Bearer Token provided in the Authorization header.
     *
     * @param WP_REST_Request $request Current request.
     * @return true|WP_Error
     */
    public function check_auth_permission($request) {
        $auth_header = $request->get_header('Authorization');
        
        if (empty($auth_header)) {
            return new WP_Error('rest_forbidden', 'Authorization header is missing.', ['status' => 401]);
        }

        // Validate formatting (Bearer <token>)
        if (!preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            return new WP_Error('rest_forbidden', 'Invalid token format alignment.', ['status' => 401]);
        }

        // $matches[1] targets the string value, not the match array structure
        if (!isset($matches[1]) || $matches[1] !== $this->auth_token) {
            return new WP_Error('rest_forbidden', 'Unauthorized application token.', ['status' => 403]);
        }

        return true;
    }

    /**
     * Generate a unique, route-distinct cache key based on the current request path and parameters.
     *
     * @param WP_REST_Request $request
     * @return string
     */
    private function get_cache_key($request) {
        // 1. Grab the exact full route path string context to distinguish Route A from Route B cleanly
        $route = trim($request->get_route(), '/');
        
        $params = $request->get_params();
        
        // 2. Remove authentication and system headers so they don't alter the cache signature layout
        unset($params['headers']);
        
        // 3. Explicitly append the matched URL route parameters (like 'term' or 'id') if they exist
        // This stops /movies/genres/action from colliding with /movies/genres/comedy
        $url_params = $request->get_url_params();
        if (!empty($url_params)) {
            $params['__url_segments'] = $url_params;
        }
        
        // Sort parameters to ensure consistency (e.g., ?page=1&per_page=35 matches ?per_page=35&page=1)
        ksort($params);
        
        $param_hash = !empty($params) ? md5(json_encode($params)) : 'default';
        
        // Return a safe transient key length signature
        return 'sflix_' . substr(md5($route), 0, 15) . '_' . $param_hash;
    }

    /**
     * Purge all StreamFlix API related transients from the database.
     */
    public function purge_all_api_cache() {
        global $wpdb;
        
        // 1. Wipe core database fallback options tables rows
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sflix_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sflix_%'");
        
        // 2. Clear out persistent system memory drop-ins safely on demand
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    /**
     * Transforms a collection of movie posts.
     * 
     * @param array $posts Array of WP_Post objects.
     * @return array Array of transformed movie data.
     */
     private function transform_movie_collection($posts) {
        $movies = [];
        foreach ($posts as $post) {
            // If it's an object, extract the ID. If it's a numeric string/int, use it directly.
            $post_id = is_object($post) ? $post->ID : intval($post);
            
            if ($post_id > 0) {
                $movies[] = $this->transform_movie($post_id);
            }
        }
        // Filter out any empty arrays to guarantee clean output collections
        return array_filter($movies);
    }

    /**
     * Transforms a single movie post into a structured array suitable for the StreamFlix API.
     *
     * @param int $post_id The ID of the movie post.
     * @return array The transformed movie data.
     */
    private function transform_movie($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        // Extract metadata from FilmJepang Smart Importer and Sora plugin fields
        $movie_code         = get_post_meta($post_id, '_fjsi_code', true);
        $duration           = get_post_meta($post_id, 'sora_duration', true);
        $release_date       = get_post_meta($post_id, 'sora_date', true);
        $cover_url          = get_post_meta($post_id, 'sora_cover', true);
        $imdb_score         = get_post_meta($post_id, 'sora_imdb', true);
        $vote_count         = intval(get_post_meta($post_id, 'sora_votes', true));
        $content_rating     = get_post_meta($post_id, 'sora_rated', true);
        $streaming_sources  = get_post_meta($post_id, '_fjsi_sources', true);

        // Extract taxonomy terms via Direct SQL to avoid initialization order conflicts
        $genres             = $this->get_post_terms($post_id, 'genre');
        $actors             = $this->get_post_terms($post_id, 'actor');
        $studios            = $this->get_post_terms($post_id, 'studio');
        $directors          = $this->get_post_terms($post_id, 'director');
        $countries          = $this->get_post_terms($post_id, 'country');
        $quality            = $this->get_post_terms($post_id, 'quality');
        $series             = $this->get_post_terms($post_id, 'series');
        $years              = $this->get_post_terms($post_id, 'years');

        // Extract Rank Math SEO metadata
        $rm_title        = get_post_meta($post_id, 'rank_math_title', true);
        $rm_description  = get_post_meta($post_id, 'rank_math_description', true);
        $rm_keywords     = get_post_meta($post_id, 'rank_math_focus_keyword', true);            
        
        $seo_title       = !empty($rm_title) ? $rm_title : $post->post_title;
        $seo_description = !empty($rm_description) ? $rm_description : wp_strip_all_tags($post->post_excerpt);
        
        // If the custom excerpt field is empty, generate a fallback description excerpt snippet from the post content
        if (empty($seo_description)) {
            $seo_description = wp_html_excerpt($post->post_content, 160, '...');
        }
                
        // Extract WordPress permalink and convert it to host-agnostic relative path (e.g., "/movies/your-movie-slug/")
        // The Streamflix app will have multiple domains, so we will prepend its base URL to this relative path for deep linking
        $wp_permalink = get_permalink($post_id);
        $app_mapped_url = wp_make_link_relative($wp_permalink);

        // Extract categories and tags for additional metadata
        $categories_list    = wp_get_post_categories($post_id, ['fields' => 'names']);
        $tags_list          = wp_get_post_tags($post_id, ['fields' => 'names']);

        // Image Handler Fallback Integration (Falls back to sora_cover text field if featured image is absent)
        $local_poster       = get_the_post_thumbnail_url($post_id, 'full');
        $final_poster_path  = !empty($local_poster) ? $local_poster : $cover_url;

        // Embed and Download Group Processing
        $raw_embeds    = get_post_meta($post_id, 'ab_embedgroup', true);
        $raw_downloads = get_post_meta($post_id, 'ab_downloadgroup', true);

        $video_embeds   = [];
        $download_links = [];
        $video_embed_main  = '';

        // Extract and sanitize the embed group data, ensuring each server node has a name and valid iframe HTML
        if (!empty($raw_embeds) && is_array($raw_embeds)) {
            foreach ($raw_embeds as $index => $item) {
                $name = isset($item['ab_hostname']) ? sanitize_text_field($item['ab_hostname']) : 'Server ' . ($index + 1);
                $code = isset($item['ab_embed']) ? $item['ab_embed'] : ''; // Raw iframe tag string
                
                if (!empty($code)) {
                    $video_embeds[] = [
                        'server_name' => $name,
                        'embed_html'  => $code
                    ];
                }
            }
        }

        // 💡 DYNAMIC FALLBACK: Pick the very first available server node as primary
        if (!empty($video_embeds) && isset($video_embeds[0]['embed_html'])) {
            $video_embed_main = $video_embeds[0]['embed_html'];
        }

        // Extract and sanitize the download group data, ensuring each download node has a name, quality, and valid URL
        if (!empty($raw_downloads) && is_array($raw_downloads)) {
            foreach ($raw_downloads as $index => $item) {
                $name    = isset($item['ab_hostname']) ? sanitize_text_field($item['ab_hostname']) : 'Download Link ' . ($index + 1);
                $quality = isset($item['ab_quality']) ? sanitize_text_field($item['ab_quality']) : 'HD';
                $link    = isset($item['ab_linkurl']) ? esc_url_raw($item['ab_linkurl']) : '';

                if (!empty($link)) {
                    $download_links[] = [
                        'label'   => $name,
                        'quality' => $quality,
                        'url'     => $link
                    ];
                }
            }
        }
        // Build the clean TMDb-compatible JSON node structure
        $movie = [
            'id'                 => $post_id,
            'code'               => $movie_code,
            'slug'               => $post->post_name,
            'title'              => $post->post_title,
            'overview'           => $post->post_content,
            'short_description'  => wp_strip_all_tags($post->post_excerpt ? $post->post_excerpt : $seo_description),
            'runtime'            => $duration,
            'release_date'       => !empty($release_date) ? $release_date : get_the_date('Y-m-d'),
            'poster_path'        => $final_poster_path,
            'backdrop_path'      => get_the_post_thumbnail_url($post_id, 'large'),
            'imdb_score'         => $imdb_score,
            'vote_count'         => $vote_count,
            'content_rating'     => $content_rating,
            // Categorizations
            'categories'         => is_array($categories_list) ? $categories_list : [],
            'tags'               => is_array($tags_list) ? $tags_list : [],
            // Taxonomies
            'series'             => $series,
            'year'               => $years,
            'genres'             => $genres,
            'cast'               => $actors,
            'directors'          => $directors,
            'production_company' => (!empty($studios) && isset($studios[0])) ? $studios[0] : '',
            'origin_country'     => !empty($countries) ? $countries : ['Jepang'],
            'video_quality'      => !empty($quality) ? $quality : ['HD'],
            // Embed and Download Data
            'video_embed_main'   => $video_embed_main, 
            'video_embeds'       => $video_embeds,
            'download_links'     => $download_links,
            // Analytics
            'views' => [
                'today'          => intval(get_post_meta($post_id, 'ts_today_view_count', true)),
                'weekly'         => intval(get_post_meta($post_id, 'ts_weekly_view_count', true)),
                'monthly'        => intval(get_post_meta($post_id, 'ts_monthly_view_count', true)),
                'total'          => intval(get_post_meta($post_id, 'wpb_post_views_count', true)),
            ],
            // SEO Data
            'seo' => [
                'meta_title'       => $seo_title,
                'meta_description' => $seo_description,
                'focus_keywords'   => !empty($rm_keywords) ? explode(',', $rm_keywords) : [],
                'app_target_url'   => $app_mapped_url
            ],
            // Sources
            'streaming_sources'  => is_array($streaming_sources) ? $streaming_sources : [
                'javtrailers'    => '',
                'javguru'        => '',
                'javhdporn'      => '',
                'javlibrary'     => '',
            ]
        ];

        return $movie;
    }

    /**
     * Get post terms
     */
    private function get_post_terms($post_id, $taxonomy) {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT t.name 
            FROM {$wpdb->terms} AS t
            INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
            INNER JOIN {$wpdb->term_relationships} AS tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tr.object_id = %d AND tt.taxonomy = %s
            ORDER BY t.name ASC",
            $post_id,
            $taxonomy
        );

        $results = $wpdb->get_col($query);

        return is_array($results) ? $results : [];
    }

    /**
     * Core Indexer: Compiles and inserts/updates a single movie post into the custom index table.
     */
    public function index_single_movie($post_id) {
        global $wpdb;
        $post = get_post($post_id);        
        $table_name = $wpdb->prefix . 'streamflix_search_index';

        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            $wpdb->delete($table_name, ['post_id' => $post_id]);
            return;
        }

        $movie_code = get_post_meta($post_id, '_fjsi_code', true);
        $release_date = get_post_meta($post_id, 'sora_date', true);
        $imdb_score = intval(get_post_meta($post_id, 'sora_imdb', true));
        $votes = intval(get_post_meta($post_id, 'sora_votes', true));
        $today_views = intval(get_post_meta($post_id, 'ts_today_view_count', true));
        $weekly_views = intval(get_post_meta($post_id, 'ts_weekly_view_count', true));
        $monthly_views = intval(get_post_meta($post_id, 'ts_monthly_view_count', true));
        $total_views = intval(get_post_meta($post_id, 'wpb_post_views_count', true));

        // Collect all related taxonomy term names to feed the fulltext engine
        $taxonomies = ['kode-prefix', 'actor', 'category', 'genre', 'director', 'studio', 'years', 'quality', 'country', 'series'];
        $meta_words = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
            if (!is_wp_error($terms) && !empty($terms)) {
                $meta_words = array_merge($meta_words, $terms);
            }
        }

        // Clean up text and convert to a flat searchable string space-separated vector
        $meta_data_string = implode(' ', array_unique($meta_words));

        $wpdb->replace(
            $table_name,
            [
                'post_id'       => $post_id,
                'movie_title'   => $post->post_title,
                'movie_code'    => !empty($movie_code) ? $movie_code : '',
                'created_date'  => $post->post_date,
                'release_date'  => $release_date,
                'imdb_score'    => $imdb_score,
                'votes'         => $votes,
                'today_views'   => $today_views,
                'weekly_views'  => $weekly_views,
                'monthly_views' => $monthly_views,
                'total_views'   => $total_views,
                'meta_data'     => $meta_data_string
            ],
            // 10 properties = 10 explicitly defined formatting tokens
            ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s']
        );
    }

    /**
     * Maps the API sort string to valid WP_Query argument structures.
     *
     * @param array  $args      The existing WP_Query args array.
     * @param string $sort_type The requested sort parameter.
     * @return array Modified WP_Query arguments.
     */
    private function apply_database_sorting($args, $sort_type) {
        switch ($sort_type) {
            case self::SORT_RELEASE:
                $args['meta_key'] = 'sora_date';
                $args['orderby']  = 'meta_value';
                $args['order']    = 'DESC';
                break;

            case self::SORT_HOT:
                $args['meta_key'] = 'ts_today_view_count';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
                break;

            case self::SORT_TRENDING:
                $args['meta_key'] = 'ts_weekly_view_count';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
                break;

            case self::SORT_POPULAR:
                $args['meta_key'] = 'wpb_post_views_count';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
                break;

            case self::SORT_NEW:
            default:
                $args['orderby']  = 'date';
                $args['order']    = 'DESC';
                break;
        }

        return $args;
    }
    // #endregion
}

// #region ==== REGISTER HOOKS ====

/**
 * Initializes the StreamFlix REST API routes by creating an instance of the controller and registering its routes.
 */
function streamflix_init_rest_routes() {
    $controller = new StreamFlix_REST_Controller();
    $controller->register_routes();
}
add_action( 'rest_api_init', 'streamflix_init_rest_routes' );

/**
 * Create or upgrade the custom search index table safely upon plugin activation or update.
 * Does NOT drop data; uses dbDelta to carefully match structural schema alignments.
 * For future updates, WordPress will safely adjust the table structures via dbDelta(). 
 * Schedules an immediate background cron task to index existing posts safely without timing out.
 * Steps to upgrade:
 * - Deactivate the plugin
 * - Update the plugin code
 * - Reactivate the plugin to trigger dbDelta() and apply schema changes without losing existing data.
 */
function streamflix_api_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'streamflix_search_index';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        post_id bigint(20) unsigned NOT NULL,
        movie_title varchar(255) NOT NULL,
        movie_code varchar(100) NOT NULL, 
        created_date datetime NOT NULL,
        release_date datetime NOT NULL,
        imdb_score bigint(20) NOT NULL DEFAULT 0, 
        votes bigint(20) NOT NULL DEFAULT 0, 
        today_views bigint(20) NOT NULL DEFAULT 0,
        weekly_views bigint(20) NOT NULL DEFAULT 0,
        monthly_views bigint(20) NOT NULL DEFAULT 0,           
        total_views bigint(20) NOT NULL DEFAULT 0,
        meta_data text NOT NULL,

        PRIMARY KEY  (post_id),        
        KEY idx_code (movie_code),
        KEY idx_created (created_date),
        KEY idx_release (release_date),
        
        -- Full-Text index including the new filter columns for universal search box
        FULLTEXT KEY ft_search_idx (movie_title, movie_code, meta_data)
    ) $charset_collate ENGINE=InnoDB;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    // Schedule the one-time indexing sweep to start 5 seconds from now in the background
    if ( ! wp_next_scheduled( 'streamflix_api_async_rebuild_action' ) ) {
        wp_schedule_single_event( time() + 5, 'streamflix_api_async_rebuild_action' );
    }
}
register_activation_hook( __FILE__, 'streamflix_api_activate' );

/**
 * Background Cron Worker to rebuild the search index asynchronously without blocking the main request thread.
 */
function streamflix_api_run_async_rebuild() {
    $controller = new StreamFlix_REST_Controller();
    
    // We pass a dummy WP_REST_Request object since the method expects one
    $controller->rebuild_index( new WP_REST_Request() );
}
add_action( 'streamflix_api_async_rebuild_action', 'streamflix_api_run_async_rebuild' );

/**
 * Register a hook to synchronize the custom search index and purge API caches on post's status changes.
 * Triggers only when movies are published, updated while live, or un-published.
 */
function streamflix_sync_movie_on_status_change($new_status, $old_status, $post) {
    // 1. Structural Guards: Escape quickly if this isn't a standard movie post
    if ($post->post_type !== 'post') return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    // 2. Action Assessment: Determine if we actually need to sync
    $is_becoming_public = ($new_status === 'publish');
    $was_already_public = ($old_status === 'publish');

    // Only run if the post is entering 'publish', being updated while 'publish', or leaving 'publish'
    if ($is_becoming_public || $was_already_public) {
        $controller = new StreamFlix_REST_Controller();
        
        // Synchronize MySQL Fulltext row data for this specific post
        $controller->index_single_movie($post->ID);
        
        // Clear old API caching layers so the app sees the changes immediately
        $controller->purge_all_api_cache();
    }
}
add_action('transition_post_status', 'streamflix_sync_movie_on_status_change', 10, 3);

/**
 * Handle direct post deletion cleanup tasks safely.
 */
function streamflix_sync_movie_on_delete($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') return;

    $controller = new StreamFlix_REST_Controller();
    $controller->index_single_movie($post_id); // Deletes entry from custom index table
    $controller->purge_all_api_cache();
}
add_action('before_delete_post', 'streamflix_sync_movie_on_delete');

// #endregion