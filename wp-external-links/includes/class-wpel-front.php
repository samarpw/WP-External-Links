<?php
/**
 * Class WPEL_Front
 *
 * @package  WPEL
 * @category WordPress Plugin
 * @version  2.0.1
 * @author   Victor Villaverde Laan
 * @link     http://www.finewebdev.com
 * @link     https://github.com/freelancephp/WP-External-Links
 * @license  Dual licensed under the MIT and GPLv2+ licenses
 */
final class WPEL_Front extends WPRun_Base_1x0x0
{

    /**
     * @var WPEL_Settings_Page
     */
    private $settings_page = null;

    /**
     * Initialize
     * @param WPEL_Settings_Page $settings_page
     */
    protected function init( WPEL_Settings_Page $settings_page )
    {
        $this->settings_page = $settings_page;

        // apply page sections
        if ( $this->opt( 'apply_all' ) ) {
            add_action( 'final_output', $this->get_callback( 'scan' ) );
        } else {
            $filter_hooks = array();

            if ( $this->opt( 'apply_post_content' ) ) {
                array_push( $filter_hooks, 'the_title', 'the_content', 'the_excerpt', 'get_the_excerpt' );
            }

            if ( $this->opt( 'apply_comments' ) ) {
                array_push( $filter_hooks, 'comment_text', 'comment_excerpt' );
            }

            if ( $this->opt( 'apply_widgets' ) ) {
                array_push( $filter_hooks, 'widget_output' );
            }

            foreach ( $filter_hooks as $hook ) {
               add_filter( $hook, $this->get_callback( 'scan' ) );
            }
        }
    }

    /**
     * Get option value
     * @param string $key
     * @param string|null $type
     * @return string
     * @triggers E_USER_NOTICE Option value cannot be found
     */
    protected function opt( $key, $type = null )
    {
        return $this->settings_page->get_option_value( $key, $type );
    }

    /**
     * Enqueue scripts and styles
     */
    protected function action_wp_enqueue_scripts()
    {
        $icon_type_int = $this->opt( 'icon_type', 'internal-links' );
        $icon_type_ext = $this->opt( 'icon_type', 'external-links' );

        if ( 'dashicon' === $icon_type_int || 'dashicon' === $icon_type_ext ) {
            wp_enqueue_style( 'dashicons' );
        }

        if ( 'fontawesome' === $icon_type_int || 'fontawesome' === $icon_type_ext ) {
            wp_enqueue_style(
                'font-awesome'
                , 'https://maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css'
                , array()
                , null
            );
        }

        if ( $this->opt( 'icon_type', 'external-links' ) || $this->opt( 'icon_type', 'internal-links' ) ) {
            wp_enqueue_style(
                'wpel-style'
                , plugins_url( '/public/css/wpel.css', WPEL_Plugin::get_plugin_file() )
                , array()
                , null
            );
        }
    }

    /**
     * Scan content for links
     * @param string $content
     * @return string
     */
    public function scan( $content )
    {
        /**
         * Filter whether the plugin settings will be applied on links
         * @param boolean $apply_settings
         */
        $apply_settings = apply_filters( 'wpel_apply_settings', true );

        if ( false === $apply_settings ) {
            return $content;
        }

        /**
         * Filters before scanning content
         * @param string $content
         */
        $content = apply_filters( 'wpel_before_filter', $content );

        $regexp_link = '/<a[^A-Za-z](.*?)>(.*?)<\/a[\s+]*>/is';

        /**
         * Filters for changing regular expression for getting html links
         * @param string $regexp_link
         */
        $regexp_link = apply_filters( 'wpel_regexp_link', $regexp_link );

        $content = preg_replace_callback( $regexp_link, $this->get_callback( 'match_link' ), $content );

        /**
         * Filters after scanning content
         * @param string $content
         */
        $content = apply_filters( 'wpel_after_filter', $content );

        return $content;
    }

    /**
     * Pregmatch callback for handling link
     * @param array $matches  [ 0 ] => link, [ 1 ] => atts_string, [ 2 ] => label
     * @return string
     */
    protected function match_link( $matches )
    {
        $original_link = $matches[ 0 ];
        $atts = $this->parse_atts( $matches[ 1 ] ) ?: array();
        $label = $matches[ 2 ];

        $created_link = $this->get_created_link( $label, $atts );

        if ( false === $created_link ) {
            return $original_link;
        }

        return $created_link;
    }

    /**
     * Parse html attributes
     * @param string $atts
     * @return array
     */
    protected function parse_atts( $atts )
    {
        $regexp_atts = '/([\w\-]+)=([^"\'> ]+|([\'"]?)(?:[^\3]|\3+)+?\3)/';
        preg_match_all( $regexp_atts, $atts, $matches );

        $atts_arr = array();

        for ( $x = 0, $count = count( $matches[ 0 ] ); $x < $count; $x += 1 ) {
            $attr_name = $matches[ 1 ][ $x ];
            $attr_value = str_replace( $matches[ 3 ][ $x ], '', $matches[ 2 ][ $x ] );

            $atts_arr[ $attr_name ] = $attr_value;
        }

        return $atts_arr;
    }

    /**
     * Create html link
     * @param string $label
     * @param array $atts
     * @return string
     */
    protected function get_created_link( $label, array $atts )
    {
        $link = WPEL_Link::create( 'a', $label, $atts );

        if ( $link->isIgnore() ) {
            return false;
        }

        $this->set_link( $link );

        return $link->getHTML();
    }

    /**
     * Set link
     * @param WPEL_Link $link
     */
    protected function set_link( WPEL_Link $link )
    {
        $url = $link->getAttribute( 'href' );

        $excludes_as_internal_links = $this->opt( 'excludes_as_internal_links' );

        // internal, external or excluded
        $is_excluded = $link->isExclude() || $this->is_excluded_url( $url );
        $is_internal = $link->isInternal() || ( $this->is_internal_url( $url ) && ! $this->is_included_url( $url ) ) || ( $is_excluded && $excludes_as_internal_links );
        $is_external = $link->isExternal() || ( ! $is_internal && ! $is_excluded );

        if ( $is_external ) {
            $link->setExternal();
            $this->apply_link_settings( $link, 'external-links' );
        } else if ( $is_internal ) {
            $link->setInternal();
            $this->apply_link_settings( $link, 'internal-links' );
        } else {
            $link->setExclude();
        }

        /**
         * Action for changing link object
         * @param WPEL_Link $link
         * @return void
         */
        do_action( 'wpel_link', $link );
    }

    /**
     * @param WPEL_Link $link
     * @param string $type
     */
    protected function apply_link_settings( WPEL_Link $link, $type )
    {
        if ( ! $this->opt( 'apply_settings', $type ) ) {
            return;
        }

        // set target
        $target = $this->opt( 'target', $type );
        $target_overwrite = $this->opt( 'target_overwrite', $type );
        $has_target = $link->hasAttribute( 'target' );

        if ( $target && ( ! $has_target || $target_overwrite ) ) {
            $link->setAttribute( 'target', $target );
        }

        // add "follow" / "nofollow"
        $follow = $this->opt( 'rel_follow', $type );
        $follow_overwrite = $this->opt( 'rel_follow_overwrite', $type );
        $has_follow = $link->hasAttributeValue( 'rel', 'follow' ) || $link->hasAttributeValue( 'rel', 'nofollow' );

        if ( $follow && ( ! $has_follow || $follow_overwrite ) ) {
            if ( $has_follow ) {
                $link->removeFromAttribute( 'rel', 'follow' );
                $link->removeFromAttribute( 'rel', 'nofollow' );
            }

            $link->addToAttribute( 'rel', $follow );
        }

        // add "external"
        if ( $this->opt( 'rel_external', $type ) ) {
            $link->addToAttribute( 'rel', 'external' );
        }

        // add "noopener"
        if ( $this->opt( 'rel_noopener', $type ) ) {
            $link->addToAttribute( 'rel', 'noopener' );
        }

        // add "noreferrer"
        if ( $this->opt( 'rel_noreferrer', $type ) ) {
            $link->addToAttribute( 'rel', 'noreferrer' );
        }

        // set title
        $title_format = $this->opt( 'title', $type );

        if ( $title_format ) {
            $title = $link->getAttribute( 'title' );
            $text = esc_attr( $link->getContent() );
            $new_title = str_replace( array( '{title}', '{text}' ), array( $title, $text ), $title_format );

            if ( $new_title ) {
                $link->setAttribute( 'title', $new_title );
            }
        }

        // add classes
        $class = $this->opt( 'class', $type );

        if ( $class ) {
            $link->addToAttribute( 'class', $class );
        }

        // add icon
        $icon_type = $this->opt( 'icon_type', $type );
        $no_icon_for_img = $this->opt( 'no_icon_for_img', $type );
        $has_img = preg_match( '/<img([^>]*)>/is', $link->getContent() );

        if ( $icon_type && ! ( $has_img && $no_icon_for_img ) ) {
            if ( 'dashicon' === $icon_type ) {
                $dashicon = $this->opt( 'icon_dashicon', $type );
                $icon = FWP_DOM_Element_1x0x0::create( 'i', '', array(
                    'class'         => 'wpel-icon dashicons-before '. $dashicon,
                    'aria-hidden'   => 'true',
                ) );
            } else if ( 'fontawesome' === $icon_type ) {
                $fa = $this->opt( 'icon_fontawesome', $type );
                $icon = FWP_DOM_Element_1x0x0::create( 'i', '', array( 
                    'class'         => 'wpel-icon fa '. $fa,
                    'aria-hidden'   => 'true',
                ) );
            } else if ( 'image' === $icon_type ) {
                $image = $this->opt( 'icon_image', $type );
                $icon = FWP_DOM_Element_1x0x0::create( 'span', null, array(
                    'class' => 'wpel-icon wpel-image wpel-icon-'. $image,
                ) );
            }

            if ( 'left' === $this->opt( 'icon_position', $type ) ) {
                $link->addToAttribute( 'class', 'wpel-icon-left' );
                $link->prependChild( $icon );
            } else if ( 'right' === $this->opt( 'icon_position', $type ) ) {
                $link->addToAttribute( 'class', 'wpel-icon-right' );
                $link->appendChild( $icon );
            }
        }
    }

    /**
     * Check if url is included as external link
     * @param string $url
     * @return boolean
     */
    protected function is_included_url( $url )
    {
        // should be using private property, but static is more practical
        static $include_urls_arr = null;

        if ( null === $include_urls_arr ) {
            $include_urls = $this->opt( 'include_urls' );
            $include_urls = str_replace( "\n", ',', $include_urls );

            if ( '' === trim( $include_urls ) ) {
                $include_urls_arr = array();
            } else {
                $include_urls_arr = array_map( 'trim', explode( ',', $include_urls ) );
            }
        }

        foreach ( $include_urls_arr as $include_url ) {
			if ( false !== strpos( $url, $include_url ) ) {
				return true;
            }
        }

        return false;
    }

    /**
     * Check if url is excluded as external link
     * @param string $url
     * @return boolean
     */
    protected function is_excluded_url( $url )
    {
        // should be using private property, but static is more practical
        static $exclude_urls_arr = null;

        if ( null === $exclude_urls_arr ) {
            $exclude_urls = $this->opt( 'exclude_urls' );
            $exclude_urls = str_replace( "\n", ',', $exclude_urls );

            if ( '' === trim( $exclude_urls ) ) {
                $exclude_urls_arr = array();
            } else {
                $exclude_urls_arr = array_map( 'trim', explode( ',', $exclude_urls ) );
            }
        }

        foreach ( $exclude_urls_arr as $exclude_url ) {
		if ( false !== strpos( $url, $exclude_url ) ) {
				return true;
            }
        }

        return false;
    }

    /**
     * Check url is internal
     * @param string $url
     * @return boolean
     */
    protected function is_internal_url( $url )
    {
        // all relative url's are internal
        if ( substr( $url, 0, 7 ) !== 'http://'
                && substr( $url, 0, 8 ) !== 'https://'
                && substr( $url, 0, 6 ) !== 'ftp://'
                && substr( $url, 0, 2 ) !== '//'
                && substr( $url, 0, 7 ) !== 'mailto:' ) {
            return true;
        }

        // is internal
        if ( false !== strpos( $url, home_url() ) ) {
            return true;
        }

        // check subdomains
        if ( $this->opt( 'subdomains_as_internal_links' ) && false !== strpos( $url, $this->get_domain() ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get domain name
     * @return string
     */
    protected function get_domain() {
        // should be using private property, but static is more practical
        static $domain_name = null;

        if ( null === $domain_name ) {
            preg_match(
                '/[a-z0-9\-]{1,63}\.[a-z\.]{2,6}$/'
                , parse_url( home_url(), PHP_URL_HOST )
                , $domain_tld
            );

            if ( count( $domain_tld ) > 0 ) {
                $domain_name = $domain_tld[ 0 ];
            } else {
                $domain_name = $_SERVER[ 'SERVER_NAME' ];
            }
        }

        return $domain_name;
    }

}

/*?>*/