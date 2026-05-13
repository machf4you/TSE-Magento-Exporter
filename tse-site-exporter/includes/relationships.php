<?php
/**
 * TSE Site Exporter — Internal Link Relationship Engine (V2).
 *
 * Single pass over PageRecords builds the full internal-link graph: edges,
 * incoming/outgoing indexes, per-page metrics (counts, unique sources/targets,
 * anchor breakdown, classification breakdown), orphan/weak/excessive slices,
 * classification flow matrix, top hubs/authorities, anchor frequency.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry. Returns:
 *  [
 *    'per_page'                 => norm_url => slim metrics (for PageRecord injection),
 *    'graph'                    => internal-link-graph.json content,
 *    'orphan_pages'             => orphan-pages.json content,
 *    'weak_pages'               => weak-pages.json content,
 *    'excessive_outbound_pages' => excessive-outbound-pages.json content (rolled into summary),
 *    'relationship_summary'     => relationship-summary.json content,
 *  ]
 */
function tse_relationships_build( $records, $url_index, $thresholds = array() ) {
    $thresholds = array_merge( array(
        'orphan_max_incoming'    => 0,
        'weak_max_incoming'      => 1,
        'excessive_outbound_min' => 80,
    ), $thresholds );

    // ---- URL → record metadata map ----------------------------------------
    $url_meta = array();
    $homepage_norm = null;
    foreach ( $records as $r ) {
        $norm = tse_normalize_url( $r['url'] );
        $title = '';
        if ( isset( $r['content']['h1'] ) && '' !== (string) $r['content']['h1'] ) {
            $title = (string) $r['content']['h1'];
        } elseif ( isset( $r['seo']['title'] ) ) {
            $title = (string) $r['seo']['title'];
        }
        $url_meta[ $norm ] = array(
            'id'             => (int) $r['id'],
            'url'            => (string) $r['url'],
            'post_type'      => (string) $r['post_type'],
            'classification' => (string) $r['classification'],
            'title'          => $title,
        );
        if ( 'homepage' === $r['classification'] && null === $homepage_norm ) {
            $homepage_norm = $norm;
        }
    }

    // ---- Edge list + in/out indexes ---------------------------------------
    $edges      = array();
    $incoming   = array();   // norm_url => edge[]
    $outgoing   = array();   // norm_url => edge[]
    $self_loops = 0;

    foreach ( $records as $r ) {
        $src_norm = tse_normalize_url( $r['url'] );
        if ( empty( $r['links']['internal'] ) ) continue;

        foreach ( $r['links']['internal'] as $l ) {
            $tgt_norm = tse_normalize_url( $l['url'] );
            $edge = array(
                'source'                => (string) $r['url'],
                'source_norm'           => $src_norm,
                'target'                => (string) $l['url'],
                'target_norm'           => $tgt_norm,
                'anchor'                => isset( $l['anchor'] ) ? (string) $l['anchor'] : '',
                'rel'                   => isset( $l['rel'] ) ? (array) $l['rel'] : array(),
                'is_self'               => ! empty( $l['is_self'] ),
                'source_post_type'      => isset( $l['source_post_type'] )      ? $l['source_post_type']      : $r['post_type'],
                'source_classification' => isset( $l['source_classification'] ) ? $l['source_classification'] : $r['classification'],
                'target_post_type'      => isset( $l['target_post_type'] )      ? $l['target_post_type']      : 'unknown',
                'target_classification' => isset( $l['target_classification'] ) ? $l['target_classification'] : 'unknown',
                'target_id'             => isset( $l['target_id'] )             ? $l['target_id']             : null,
            );
            $edges[] = $edge;

            if ( $edge['is_self'] ) {
                $self_loops++;
                continue;
            }

            $outgoing[ $src_norm ][] = $edge;
            $incoming[ $tgt_norm ][] = $edge;
        }
    }

    // ---- Per-page metrics --------------------------------------------------
    $per_page = array();
    foreach ( $records as $r ) {
        $norm = tse_normalize_url( $r['url'] );
        $in   = isset( $incoming[ $norm ] ) ? $incoming[ $norm ] : array();
        $out  = isset( $outgoing[ $norm ] ) ? $outgoing[ $norm ] : array();

        $unique_sources = array();
        $unique_targets = array();
        $in_anchors     = array();
        $out_anchors    = array();
        $in_cls         = array();
        $out_cls        = array();

        foreach ( $in as $e ) {
            $unique_sources[ $e['source_norm'] ] = true;
            $a = tse_postprocess_normalise_anchor( $e['anchor'] );
            if ( '' !== $a ) $in_anchors[ $a ] = isset( $in_anchors[ $a ] ) ? $in_anchors[ $a ] + 1 : 1;
            $cls = $e['source_classification'];
            $in_cls[ $cls ] = isset( $in_cls[ $cls ] ) ? $in_cls[ $cls ] + 1 : 1;
        }
        foreach ( $out as $e ) {
            $unique_targets[ $e['target_norm'] ] = true;
            $a = tse_postprocess_normalise_anchor( $e['anchor'] );
            if ( '' !== $a ) $out_anchors[ $a ] = isset( $out_anchors[ $a ] ) ? $out_anchors[ $a ] + 1 : 1;
            $cls = $e['target_classification'];
            $out_cls[ $cls ] = isset( $out_cls[ $cls ] ) ? $out_cls[ $cls ] + 1 : 1;
        }

        arsort( $in_anchors );
        arsort( $out_anchors );

        $per_page[ $norm ] = array(
            'incoming_link_count'      => count( $in ),
            'outgoing_link_count'      => count( $out ),
            'unique_linking_pages'     => count( $unique_sources ),
            'unique_target_pages'      => count( $unique_targets ),
            'incoming_anchors'         => tse_relationships_freq_list( $in_anchors ),
            'outgoing_anchors'         => tse_relationships_freq_list( $out_anchors ),
            'inbound_classifications'  => $in_cls,
            'outbound_classifications' => $out_cls,
        );
    }

    // ---- Build graph nodes (meta + full metrics) --------------------------
    $nodes = array();
    foreach ( $records as $r ) {
        $norm  = tse_normalize_url( $r['url'] );
        $meta  = $url_meta[ $norm ];
        $m     = $per_page[ $norm ];
        $nodes[] = array(
            'id'                       => $meta['id'],
            'url'                      => $meta['url'],
            'post_type'                => $meta['post_type'],
            'classification'           => $meta['classification'],
            'title'                    => $meta['title'],
            'incoming_link_count'      => $m['incoming_link_count'],
            'outgoing_link_count'      => $m['outgoing_link_count'],
            'unique_linking_pages'     => $m['unique_linking_pages'],
            'unique_target_pages'      => $m['unique_target_pages'],
            'incoming_anchors'         => $m['incoming_anchors'],
            'outgoing_anchors'         => $m['outgoing_anchors'],
            'inbound_classifications'  => $m['inbound_classifications'],
            'outbound_classifications' => $m['outbound_classifications'],
        );
    }

    // ---- Slim edges (drop internal _norm fields) --------------------------
    $slim_edges = array();
    foreach ( $edges as $e ) {
        $slim_edges[] = array(
            'source'                => $e['source'],
            'target'                => $e['target'],
            'anchor'                => $e['anchor'],
            'rel'                   => $e['rel'],
            'is_self'               => $e['is_self'],
            'source_post_type'      => $e['source_post_type'],
            'source_classification' => $e['source_classification'],
            'target_post_type'      => $e['target_post_type'],
            'target_classification' => $e['target_classification'],
            'target_id'             => $e['target_id'],
        );
    }

    // ---- Orphan / weak / excessive detection ------------------------------
    $orphans   = array();
    $weak      = array();
    $excessive = array();

    foreach ( $records as $r ) {
        $norm        = tse_normalize_url( $r['url'] );
        $m           = $per_page[ $norm ];
        $is_homepage = ( $norm === $homepage_norm );

        $entry = array(
            'id'                  => (int) $r['id'],
            'url'                 => (string) $r['url'],
            'post_type'           => (string) $r['post_type'],
            'classification'      => (string) $r['classification'],
            'title'               => $url_meta[ $norm ]['title'],
            'incoming_link_count' => $m['incoming_link_count'],
            'outgoing_link_count' => $m['outgoing_link_count'],
            'unique_linking_pages'=> $m['unique_linking_pages'],
            'unique_target_pages' => $m['unique_target_pages'],
        );

        if ( ! $is_homepage ) {
            if ( $m['incoming_link_count'] <= $thresholds['orphan_max_incoming'] ) {
                $orphans[] = $entry;
            } elseif ( $m['incoming_link_count'] <= $thresholds['weak_max_incoming'] ) {
                $weak[] = $entry;
            }
        }
        if ( $m['outgoing_link_count'] >= $thresholds['excessive_outbound_min'] ) {
            $excessive[] = $entry;
        }
    }

    // ---- Top hubs / authorities (top 10 each) -----------------------------
    $by_out = $nodes;
    usort( $by_out, function ( $a, $b ) { return $b['outgoing_link_count'] - $a['outgoing_link_count']; } );
    $top_hubs = array();
    foreach ( array_slice( $by_out, 0, 10 ) as $n ) {
        $top_hubs[] = array(
            'id'                  => $n['id'],
            'url'                 => $n['url'],
            'classification'      => $n['classification'],
            'outgoing_link_count' => $n['outgoing_link_count'],
            'unique_target_pages' => $n['unique_target_pages'],
        );
    }

    $by_in = $nodes;
    usort( $by_in, function ( $a, $b ) { return $b['incoming_link_count'] - $a['incoming_link_count']; } );
    $top_authorities = array();
    foreach ( array_slice( $by_in, 0, 10 ) as $n ) {
        $top_authorities[] = array(
            'id'                   => $n['id'],
            'url'                  => $n['url'],
            'classification'       => $n['classification'],
            'incoming_link_count'  => $n['incoming_link_count'],
            'unique_linking_pages' => $n['unique_linking_pages'],
        );
    }

    // ---- Classification flow matrix --------------------------------------
    $flow = array();
    foreach ( $edges as $e ) {
        if ( $e['is_self'] ) continue;
        $src = $e['source_classification'];
        $tgt = $e['target_classification'];
        if ( ! isset( $flow[ $src ] ) ) $flow[ $src ] = array();
        $flow[ $src ][ $tgt ] = isset( $flow[ $src ][ $tgt ] ) ? $flow[ $src ][ $tgt ] + 1 : 1;
    }

    // ---- Global anchor frequency (top 50) --------------------------------
    $anchor_freq_map = array();
    foreach ( $edges as $e ) {
        if ( $e['is_self'] ) continue;
        $a = tse_postprocess_normalise_anchor( $e['anchor'] );
        if ( '' === $a ) continue;
        $anchor_freq_map[ $a ] = isset( $anchor_freq_map[ $a ] ) ? $anchor_freq_map[ $a ] + 1 : 1;
    }
    arsort( $anchor_freq_map );
    $anchor_freq_top = array_slice( tse_relationships_freq_list( $anchor_freq_map ), 0, 50 );

    // ---- Averages ---------------------------------------------------------
    $node_count  = count( $records );
    $edge_count  = count( $edges );
    $non_self    = $edge_count - $self_loops;
    $avg         = $node_count > 0 ? round( $non_self / $node_count, 2 ) : 0;

    $summary = array(
        'totals' => array(
            'pages'                    => $node_count,
            'internal_edges'           => $edge_count,
            'self_loops'               => $self_loops,
            'orphan_pages'             => count( $orphans ),
            'weak_pages'               => count( $weak ),
            'excessive_outbound_pages' => count( $excessive ),
        ),
        'averages' => array(
            'incoming_per_page' => $avg,
            'outgoing_per_page' => $avg,
        ),
        'thresholds'               => $thresholds,
        'top_hubs'                 => $top_hubs,
        'top_authorities'          => $top_authorities,
        'classification_flow'      => $flow,
        'anchor_text_frequency_top'=> $anchor_freq_top,
        'excessive_outbound_pages' => $excessive,
    );

    return array(
        'per_page' => $per_page,
        'graph' => array(
            'totals' => array(
                'nodes'      => $node_count,
                'edges'      => $edge_count,
                'self_loops' => $self_loops,
            ),
            'nodes' => $nodes,
            'edges' => $slim_edges,
        ),
        'orphan_pages' => array(
            'description' => 'Pages with incoming_link_count <= orphan_max_incoming (homepage excluded).',
            'threshold'   => array( 'max_incoming' => $thresholds['orphan_max_incoming'] ),
            'count'       => count( $orphans ),
            'pages'       => $orphans,
        ),
        'weak_pages' => array(
            'description' => 'Pages with weak internal support (between orphan_max_incoming exclusive and weak_max_incoming inclusive; homepage excluded).',
            'threshold'   => array(
                'min_incoming' => $thresholds['orphan_max_incoming'] + 1,
                'max_incoming' => $thresholds['weak_max_incoming'],
            ),
            'count' => count( $weak ),
            'pages' => $weak,
        ),
        'excessive_outbound_pages' => $excessive,
        'relationship_summary'     => $summary,
    );
}

/**
 * Convert a {anchor => count} map to a sorted list of {anchor, count}.
 */
function tse_relationships_freq_list( $map ) {
    $out = array();
    foreach ( $map as $anchor => $count ) {
        $out[] = array( 'anchor' => (string) $anchor, 'count' => (int) $count );
    }
    return $out;
}
