<?php
/**
 * TSE Site Exporter — Schema (JSON-LD) extraction, classification, quality flags, site rollup.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * LocalBusiness subtype catalog (Schema.org)
 * Recognise common LocalBusiness subtypes so a page using `Dentist` still
 * counts as LocalBusiness present.
 * ---------------------------------------------------------------------- */
function tse_schema_localbusiness_subtypes() {
    return array(
        'LocalBusiness',
        'AnimalShelter','ArchiveOrganization','AutomotiveBusiness','AutoBodyShop','AutoDealer','AutoPartsStore','AutoRental','AutoRepair','AutoWash','GasStation','MotorcycleDealer','MotorcycleRepair',
        'ChildCare','DryCleaningOrLaundry','EmergencyService','FireStation','Hospital','PoliceStation',
        'EmploymentAgency','EntertainmentBusiness','AdultEntertainment','AmusementPark','ArtGallery','Casino','ComedyClub','MovieTheater','NightClub',
        'FinancialService','AccountingService','AutomatedTeller','BankOrCreditUnion','InsuranceAgency',
        'FoodEstablishment','Bakery','BarOrPub','Brewery','CafeOrCoffeeShop','FastFoodRestaurant','IceCreamShop','Restaurant','Winery',
        'GovernmentOffice','PostOffice',
        'HealthAndBeautyBusiness','BeautySalon','DaySpa','HairSalon','HealthClub','NailSalon','TattooParlor',
        'HomeAndConstructionBusiness','Electrician','GeneralContractor','HVACBusiness','HousePainter','Locksmith','MovingCompany','Plumber','RoofingContractor',
        'InternetCafe','LegalService','Attorney','Notary','Library','LodgingBusiness','BedAndBreakfast','Campground','Hostel','Hotel','Motel','Resort','VacationRental',
        'MedicalBusiness','CommunityHealth','Dentist','Dermatology','DietNutrition','Emergency','Geriatric','Gynecologic','MedicalClinic','CovidTestingFacility','Midwifery','Nursing','Obstetric','Oncologic','Optician','Optometric','Otolaryngologic','Pediatric','Pharmacy','Physician','Physiotherapy','PlasticSurgery','Podiatric','PrimaryCare','Psychiatric','PublicHealth',
        'ProfessionalService','RadioStation','RealEstateAgent','RecyclingCenter','SelfStorage','ShoppingCenter',
        'SportsActivityLocation','GolfCourse','SkiResort','StadiumOrArena',
        'Store','BikeStore','BookStore','ClothingStore','ComputerStore','ConvenienceStore','DepartmentStore','ElectronicsStore','Florist','FurnitureStore','GardenStore','GroceryStore','HardwareStore','HobbyShop','HomeGoodsStore','JewelryStore','LiquorStore','MensClothingStore','MobilePhoneStore','MovieRentalStore','MusicStore','OfficeEquipmentStore','OutletStore','PawnShop','PetStore','ShoeStore','SportingGoodsStore','TireShop','ToyStore','WholesaleStore',
        'TelevisionStation','TouristInformationCenter','TravelAgency',
    );
}

function tse_schema_is_localbusiness( $types ) {
    $catalog = tse_schema_localbusiness_subtypes();
    foreach ( (array) $types as $t ) {
        if ( in_array( (string) $t, $catalog, true ) ) {
            return true;
        }
    }
    return false;
}

/* -------------------------------------------------------------------------
 * Resilient JSON-LD extraction
 * ---------------------------------------------------------------------- */

/**
 * Pull every <script type="application/ld+json"> block from raw HTML.
 * Returns ['blocks' => [...], 'malformed' => [...]]
 */
function tse_schema_extract_from_html( $html ) {
    $out = array( 'blocks' => array(), 'malformed' => array() );
    if ( '' === trim( (string) $html ) ) {
        return $out;
    }

    // Loose match: any attribute order, single/double quotes, optional whitespace, captures CDATA-wrapped contents.
    if ( preg_match_all( '#<script\b[^>]*\btype\s*=\s*(?:"application/ld\+json"|\'application/ld\+json\')[^>]*>(.*?)</script>#is', $html, $m ) ) {
        foreach ( $m[1] as $raw ) {
            $decoded = tse_schema_decode_resilient( $raw );
            if ( $decoded['ok'] ) {
                tse_schema_flatten( $decoded['data'], $out['blocks'] );
            } else {
                $out['malformed'][] = array(
                    'error' => $decoded['error'],
                    'raw'   => substr( trim( (string) $raw ), 0, 500 ),
                );
            }
        }
    }
    return $out;
}

/**
 * Try increasingly lenient JSON parsing.
 */
function tse_schema_decode_resilient( $raw ) {
    $trimmed = trim( (string) $raw );
    if ( '' === $trimmed ) {
        return array( 'ok' => false, 'error' => 'empty', 'raw' => '' );
    }

    // 1. Strict.
    $d = json_decode( $trimmed, true );
    if ( JSON_ERROR_NONE === json_last_error() ) {
        return array( 'ok' => true, 'data' => $d );
    }

    // 2. HTML-entity decode + CDATA strip.
    $candidate = html_entity_decode( $trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $candidate = preg_replace( '/^\s*<!\[CDATA\[/', '', $candidate );
    $candidate = preg_replace( '/\]\]>\s*$/', '', $candidate );
    $d = json_decode( $candidate, true );
    if ( JSON_ERROR_NONE === json_last_error() ) {
        return array( 'ok' => true, 'data' => $d );
    }

    // 3. Strip JS-style comments.
    $candidate = preg_replace( '!/\*.*?\*/!s', '', $candidate );
    $candidate = preg_replace( '!^\s*//.*$!m', '', $candidate );
    $d = json_decode( $candidate, true );
    if ( JSON_ERROR_NONE === json_last_error() ) {
        return array( 'ok' => true, 'data' => $d );
    }

    // 4. Strip trailing commas.
    $candidate = preg_replace( '/,\s*([\]}])/', '$1', $candidate );
    $d = json_decode( $candidate, true );
    if ( JSON_ERROR_NONE === json_last_error() ) {
        return array( 'ok' => true, 'data' => $d );
    }

    return array( 'ok' => false, 'error' => json_last_error_msg(), 'raw' => substr( $trimmed, 0, 500 ) );
}

/**
 * Recursively flatten a decoded JSON-LD payload into individual @type blocks.
 * - Top-level arrays   → walked
 * - @graph containers  → walked
 * - Objects with @type → captured as a block
 */
function tse_schema_flatten( $node, &$out ) {
    if ( ! is_array( $node ) ) {
        return;
    }

    // Top-level numeric array of blocks.
    if ( ! empty( $node ) && array_keys( $node ) === range( 0, count( $node ) - 1 ) ) {
        foreach ( $node as $item ) {
            tse_schema_flatten( $item, $out );
        }
        return;
    }

    // @graph wrapper.
    if ( ! empty( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
        foreach ( $node['@graph'] as $item ) {
            tse_schema_flatten( $item, $out );
        }
        return;
    }

    if ( ! empty( $node['@type'] ) ) {
        $out[] = $node;
    }
}

/**
 * Normalise a block's @type into a plain string list.
 */
function tse_schema_types( $block ) {
    if ( ! isset( $block['@type'] ) ) {
        return array();
    }
    return is_array( $block['@type'] )
        ? array_map( 'strval', $block['@type'] )
        : array( (string) $block['@type'] );
}

/**
 * Walk any decoded JSON-LD value and collect every nested entity whose
 * @type contains $target.
 */
function tse_schema_deep_collect_type( $node, $target, &$out ) {
    if ( ! is_array( $node ) ) {
        return;
    }
    if ( isset( $node['@type'] ) ) {
        $types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
        if ( in_array( $target, array_map( 'strval', $types ), true ) ) {
            $out[] = $node;
        }
    }
    foreach ( $node as $v ) {
        if ( is_array( $v ) ) {
            tse_schema_deep_collect_type( $v, $target, $out );
        }
    }
}

/* -------------------------------------------------------------------------
 * Interpretation per block
 * ---------------------------------------------------------------------- */

function tse_schema_interpret_block( $block ) {
    $types   = tse_schema_types( $block );
    $primary = isset( $types[0] ) ? $types[0] : 'Thing';
    $summary = array();

    if ( in_array( 'FAQPage', $types, true ) ) {
        $questions = array();
        tse_schema_deep_collect_type( $block, 'Question', $questions );
        $summary['questions'] = count( $questions );
    } elseif ( in_array( 'BreadcrumbList', $types, true ) ) {
        $items = isset( $block['itemListElement'] ) ? (array) $block['itemListElement'] : array();
        $summary['depth'] = count( $items );
    } elseif ( in_array( 'Product', $types, true ) ) {
        if ( isset( $block['name'] ) )  $summary['name']  = (string) $block['name'];
        if ( isset( $block['brand'] ) ) $summary['brand'] = is_array( $block['brand'] ) ? ( isset( $block['brand']['name'] ) ? (string) $block['brand']['name'] : null ) : (string) $block['brand'];

        $reviews = array();
        tse_schema_deep_collect_type( $block, 'Review', $reviews );
        $summary['reviews'] = count( $reviews );

        $ratings = array();
        tse_schema_deep_collect_type( $block, 'AggregateRating', $ratings );
        if ( ! empty( $ratings ) ) {
            $summary['aggregate_rating'] = array(
                'value' => isset( $ratings[0]['ratingValue'] ) ? $ratings[0]['ratingValue'] : null,
                'count' => isset( $ratings[0]['reviewCount'] )
                    ? $ratings[0]['reviewCount']
                    : ( isset( $ratings[0]['ratingCount'] ) ? $ratings[0]['ratingCount'] : null ),
            );
        }
    } elseif ( in_array( 'Article', $types, true ) || in_array( 'BlogPosting', $types, true ) || in_array( 'NewsArticle', $types, true ) ) {
        $summary['headline']      = isset( $block['headline'] ) ? (string) $block['headline'] : null;
        $summary['author']        = isset( $block['author']['name'] ) ? (string) $block['author']['name'] : ( isset( $block['author'] ) && is_string( $block['author'] ) ? $block['author'] : null );
        $summary['datePublished'] = isset( $block['datePublished'] ) ? (string) $block['datePublished'] : null;
    } elseif ( in_array( 'Organization', $types, true ) || tse_schema_is_localbusiness( $types ) ) {
        $summary['name']      = isset( $block['name'] ) ? (string) $block['name'] : null;
        $summary['url']       = isset( $block['url'] )  ? (string) $block['url']  : null;
        $summary['telephone'] = isset( $block['telephone'] ) ? (string) $block['telephone'] : null;
        $summary['address']   = isset( $block['address'] ) ? $block['address'] : null;
    } elseif ( in_array( 'Review', $types, true ) ) {
        $summary['author'] = isset( $block['author']['name'] ) ? (string) $block['author']['name'] : ( isset( $block['author'] ) && is_string( $block['author'] ) ? $block['author'] : null );
        $summary['rating'] = isset( $block['reviewRating']['ratingValue'] ) ? $block['reviewRating']['ratingValue'] : null;
    } elseif ( in_array( 'WebSite', $types, true ) ) {
        $summary['name'] = isset( $block['name'] ) ? (string) $block['name'] : null;
        $summary['url']  = isset( $block['url'] )  ? (string) $block['url']  : null;
    } elseif ( in_array( 'Service', $types, true ) ) {
        $summary['name']        = isset( $block['name'] ) ? (string) $block['name'] : null;
        $summary['serviceType'] = isset( $block['serviceType'] ) ? (string) $block['serviceType'] : null;
    }

    return array(
        'type'    => $primary,
        'types'   => $types,
        'summary' => $summary,
        'raw'     => $block,
    );
}

/* -------------------------------------------------------------------------
 * Per-page schema section
 * ---------------------------------------------------------------------- */

function tse_schema_build_page_section( $html, $classification, $is_homepage = false ) {
    $extracted = tse_schema_extract_from_html( $html );
    $blocks    = $extracted['blocks'];
    $malformed = $extracted['malformed'];

    $interpreted = array();
    $types_seen  = array();
    $faq_count   = 0;
    $review_count = 0;
    $agg_rating   = null;
    $org = false; $lb = false; $bc = false; $prod = false;
    $article = false; $website = false; $webpage = false; $service = false;

    foreach ( $blocks as $block ) {
        $interp = tse_schema_interpret_block( $block );
        $interpreted[] = $interp;

        foreach ( $interp['types'] as $t ) {
            $types_seen[ $t ] = true;
        }

        $types = $interp['types'];
        if ( in_array( 'FAQPage', $types, true ) ) {
            $faq_count += isset( $interp['summary']['questions'] ) ? (int) $interp['summary']['questions'] : 0;
        }
        if ( in_array( 'Organization', $types, true ) )                $org     = true;
        if ( tse_schema_is_localbusiness( $types ) )                   $lb      = true;
        if ( in_array( 'BreadcrumbList', $types, true ) )              $bc      = true;
        if ( in_array( 'Product', $types, true ) )                     $prod    = true;
        if ( in_array( 'Article', $types, true ) || in_array( 'BlogPosting', $types, true ) || in_array( 'NewsArticle', $types, true ) ) $article = true;
        if ( in_array( 'WebSite', $types, true ) )                     $website = true;
        if ( in_array( 'WebPage', $types, true ) )                     $webpage = true;
        if ( in_array( 'Service', $types, true ) )                     $service = true;

        // Deep counts (handles nested Review / AggregateRating inside Product/Service/etc.).
        $reviews = array();
        tse_schema_deep_collect_type( $block, 'Review', $reviews );
        $review_count += count( $reviews );

        if ( null === $agg_rating ) {
            $ratings = array();
            tse_schema_deep_collect_type( $block, 'AggregateRating', $ratings );
            if ( ! empty( $ratings ) ) {
                $agg_rating = array(
                    'value' => isset( $ratings[0]['ratingValue'] ) ? $ratings[0]['ratingValue'] : null,
                    'count' => isset( $ratings[0]['reviewCount'] )
                        ? $ratings[0]['reviewCount']
                        : ( isset( $ratings[0]['ratingCount'] ) ? $ratings[0]['ratingCount'] : null ),
                );
            }
        }
    }

    $summary = array(
        'schema_types_detected'    => array_values( array_keys( $types_seen ) ),
        'faq_count'                => $faq_count,
        'review_count'             => $review_count,
        'aggregate_rating'         => $agg_rating,
        'aggregate_rating_present' => ( null !== $agg_rating ),
        'organization_present'     => $org,
        'localbusiness_present'    => $lb,
        'breadcrumb_present'       => $bc,
        'product_present'          => $prod,
        'article_present'          => $article,
        'website_present'          => $website,
        'webpage_present'          => $webpage,
        'service_present'          => $service,
        'malformed_count'          => count( $malformed ),
        'total_blocks'             => count( $blocks ),
    );

    // Quality flags per page.
    $flags = array();
    if ( ! empty( $malformed ) ) {
        $flags[] = 'malformed-schema-detected';
    }
    if ( 0 === $summary['total_blocks'] ) {
        $flags[] = 'no-schema-detected';
    }
    if ( 'money' === $classification ) {
        if ( ! in_array( 'FAQPage', $summary['schema_types_detected'], true ) ) {
            $flags[] = 'money-page-missing-faq';
        }
        if ( 0 === $review_count && null === $agg_rating ) {
            $flags[] = 'money-page-missing-reviews';
        }
    }
    if ( 'article' === $classification && ! $article ) {
        $flags[] = 'article-missing-article-schema';
    }
    if ( $is_homepage && ! $org ) {
        $flags[] = 'homepage-missing-organization';
    }
    if ( $is_homepage && ! $lb ) {
        $flags[] = 'homepage-missing-localbusiness';
    }
    if ( ! $is_homepage && ! $bc && $summary['total_blocks'] > 0 ) {
        $flags[] = 'page-missing-breadcrumb';
    }

    return array(
        'raw_blocks'    => $blocks,
        'interpreted'   => $interpreted,
        'malformed'     => $malformed,
        'summary'       => $summary,
        'quality_flags' => $flags,
    );
}

/* -------------------------------------------------------------------------
 * Site-wide rollup
 * ---------------------------------------------------------------------- */

function tse_schema_build_rollup( $records ) {
    $total_blocks = 0;
    $pages_with_schema = 0;
    $pages_without_schema = 0;
    $malformed_blocks = 0;

    $type_dist       = array();
    $org_present     = false;
    $lb_present      = false;
    $website_present = false;

    $money_missing_faq        = array();
    $money_missing_reviews    = array();
    $articles_missing_article = array();
    $pages_missing_breadcrumb = array();
    $homepage_missing_org     = array();
    $homepage_missing_lb      = array();
    $malformed_pages          = array();
    $pages_without_any_schema = array();

    foreach ( $records as $r ) {
        if ( empty( $r['schema'] ) ) {
            continue;
        }
        $s = $r['schema'];

        $blocks_count = isset( $s['summary']['total_blocks'] ) ? (int) $s['summary']['total_blocks'] : 0;
        $total_blocks += $blocks_count;
        if ( $blocks_count > 0 ) {
            $pages_with_schema++;
        } else {
            $pages_without_schema++;
            $pages_without_any_schema[] = $r['url'];
        }

        $malformed_blocks += isset( $s['summary']['malformed_count'] ) ? (int) $s['summary']['malformed_count'] : 0;

        if ( ! empty( $s['summary']['schema_types_detected'] ) ) {
            foreach ( $s['summary']['schema_types_detected'] as $t ) {
                $type_dist[ $t ] = isset( $type_dist[ $t ] ) ? $type_dist[ $t ] + 1 : 1;
            }
        }

        if ( ! empty( $s['summary']['organization_present'] ) )  $org_present     = true;
        if ( ! empty( $s['summary']['localbusiness_present'] ) ) $lb_present      = true;
        if ( ! empty( $s['summary']['website_present'] ) )       $website_present = true;

        if ( ! empty( $s['quality_flags'] ) ) {
            foreach ( $s['quality_flags'] as $f ) {
                switch ( $f ) {
                    case 'money-page-missing-faq':        $money_missing_faq[]        = $r['url']; break;
                    case 'money-page-missing-reviews':    $money_missing_reviews[]    = $r['url']; break;
                    case 'article-missing-article-schema':$articles_missing_article[] = $r['url']; break;
                    case 'page-missing-breadcrumb':       $pages_missing_breadcrumb[] = $r['url']; break;
                    case 'homepage-missing-organization': $homepage_missing_org[]     = $r['url']; break;
                    case 'homepage-missing-localbusiness':$homepage_missing_lb[]      = $r['url']; break;
                }
            }
        }

        if ( ! empty( $s['malformed'] ) ) {
            foreach ( $s['malformed'] as $mal ) {
                $malformed_pages[] = array(
                    'url'   => $r['url'],
                    'error' => isset( $mal['error'] ) ? $mal['error'] : '',
                    'raw'   => isset( $mal['raw'] )   ? $mal['raw']   : '',
                );
            }
        }
    }

    arsort( $type_dist );

    $recs = array();
    if ( ! $org_present )     $recs[] = 'No Organization schema detected sitewide. Add it via your SEO plugin or theme.';
    if ( ! $lb_present )      $recs[] = 'No LocalBusiness schema detected sitewide. If this is a local business, add LocalBusiness (or a subtype like Dentist / Restaurant / Plumber) — ideally on the homepage.';
    if ( ! $website_present ) $recs[] = 'No WebSite schema detected. Add it to improve eligibility for Google sitelinks search box.';
    if ( ! empty( $homepage_missing_org ) ) $recs[] = 'Homepage is missing Organization schema.';
    if ( ! empty( $homepage_missing_lb ) )  $recs[] = 'Homepage is missing LocalBusiness schema.';
    if ( ! empty( $money_missing_faq ) )    $recs[] = count( $money_missing_faq ) . ' money page(s) missing FAQ schema.';
    if ( ! empty( $money_missing_reviews ) )$recs[] = count( $money_missing_reviews ) . ' money page(s) missing Review/AggregateRating schema.';
    if ( ! empty( $articles_missing_article ) ) $recs[] = count( $articles_missing_article ) . ' article(s) missing Article/BlogPosting/NewsArticle schema.';
    if ( ! empty( $pages_missing_breadcrumb ) ) $recs[] = count( $pages_missing_breadcrumb ) . ' page(s) have other schema but no BreadcrumbList.';
    if ( ! empty( $malformed_pages ) )      $recs[] = count( $malformed_pages ) . ' JSON-LD block(s) failed to parse — review the malformed_pages list.';
    if ( ! empty( $pages_without_any_schema ) ) $recs[] = count( $pages_without_any_schema ) . ' published page(s) have zero JSON-LD schema.';

    return array(
        'totals' => array(
            'total_blocks'         => $total_blocks,
            'pages_with_schema'    => $pages_with_schema,
            'pages_without_schema' => $pages_without_schema,
            'malformed_blocks'     => $malformed_blocks,
        ),
        'types_distribution' => $type_dist,
        'site_level' => array(
            'organization_present_site_wide'  => $org_present,
            'localbusiness_present_site_wide' => $lb_present,
            'website_schema_present'          => $website_present,
        ),
        'issues' => array(
            'pages_without_any_schema'        => $pages_without_any_schema,
            'homepage_missing_organization'   => $homepage_missing_org,
            'homepage_missing_localbusiness'  => $homepage_missing_lb,
            'money_pages_missing_faq'         => $money_missing_faq,
            'money_pages_missing_reviews'     => $money_missing_reviews,
            'articles_missing_article_schema' => $articles_missing_article,
            'pages_without_breadcrumb'        => $pages_missing_breadcrumb,
            'malformed_pages'                 => $malformed_pages,
        ),
        'recommendations' => $recs,
    );
}
