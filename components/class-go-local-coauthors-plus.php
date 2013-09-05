<?php

// @TODO move code dependencies from go-analytics, go-local-bsocial, go-google-analytics, and go-guestpost into this plugin
// See https://github.com/GigaOM/gigaom-plugins/issues/28

class GO_Local_Coauthors_Plus
{
	public function __construct()
	{
		add_filter( 'go_xpost_pre_send_post', array( $this, 'go_xpost_pre_send_post' ) );
		add_action( 'go_xpost_save_post', array( $this, 'go_xpost_save_post' ), 10, 2 );

		add_filter( 'go_theme_post_author', array( $this, 'go_theme_post_author_filter' ), 5, 2 );

		// This filter was added by VIP:
		// Only do co-author post lookups based on terms to avoid nasty queries because of how many terms there are
		add_filter( 'coauthors_plus_should_query_post_author', '__return_false' );

		add_action( 'wp_ajax_go_coauthors_taxonomy_update', array( $this, 'coauthors_taxonomy_update' ) );

		// turn off coauthor's guest author support, as it conflicts with our own guest author features and is causing pain
		// see http://github.com/GigaOM/legacy-pro/issues/1102 
		add_filter( 'coauthors_guest_authors_enabled' , '__return_false' );

		if ( is_admin() )
		{
			require_once __DIR__ . '/class-go-local-coauthors-plus-admin.php';
			go_local_coauthors_plus_admin();
		}//end if
	}// end __construct

	/**
	 * filter the post author.  Don't use the default WP author, instead
	 * use guest or coauthors data. See GO_GuestPost::coauthors_posts_links
	 *
	 * @param $author author to filter
	 * @param $post WP_Post object
	 * @return author
	 */
	public function go_theme_post_author_filter( $author, $post )
	{
		$author = $this->coauthors_posts_links( NULL, NULL, NULL, NULL, FALSE );

		return $author;
	}//end go_theme_post_author_filter

	/**
	 * Replacement function for coauthors_posts_links that allows us to hook in additional custom functionality
	 */
	public function coauthors_posts_links( $between = NULL, $betweenLast = NULL, $before = NULL, $after = NULL, $echo = TRUE )
	{
		global $post;

		$post_id = get_the_ID();

		if ( ! $post_id )
		{
			return;
		}//end if

		$author = apply_filters( 'go_coauthors_posts_links', coauthors_posts_links( $between, $betweenLast, $before, $after, false ), $post_id );

		if ( $echo )
		{
			echo $author;
		}// end if

		return $author;
	} // END coauthors_posts_links

	/**
	 * Hooked to the go_xpost_pre_send_post filter
	 *
	 * @param $xpost stdClass custom post object
	 * @return $xpost stdClass filtered custom post object
	 */
	public function go_xpost_pre_send_post( $xpost )
	{
		global $coauthors_plus;

		if ( is_object( $coauthors_plus ) )
		{
			$xpost->co_authors_plus = get_coauthors( $xpost->origin->ID );
		}// end if

		return $xpost;
	}// end go_xpost_pre_send_post

	/**
	 * Hooked to the go_xpost_save_post action
	 *
	 * @param $post_id int post id being saved from an xpost
	 * @param $xpost stdClass custom post object
	 */
	public function go_xpost_save_post( $post_id, $xpost )
	{
		global $coauthors_plus;

		if ( ! is_object( $coauthors_plus ) )
		{
			return;
		}// end if

		if ( ! isset( $xpost->co_authors_plus ) )
		{
			return;
		}// end if

		$coauthors = array();

		// allow go_xpost to lookup all of these authors, which will trigger creations if appropriate
		foreach ( $xpost->co_authors_plus as $author )
		{
			go_xpost_util()->get_author( $author );
			$coauthors[] = $author->data->user_nicename;
		}// end foreach

		$coauthors_plus->add_coauthors( $post_id, $coauthors );
	}// end go_xpost_save_post


	/**
	 * admin ajax call to fix posts missing coauthor taxonomy ('author') terms.
	 *
	 * (these are query vars)
	 *
	 * @param post_type type of posts to process; required
	 * @param batch_size number of posts to process; optional. default = 10
	 */
	public function coauthors_taxonomy_update()
	{
		if ( ! current_user_can( 'edit_others_posts' ) )
		{
			wp_die( 'you do not have permission to run this admin ajax hook.' );
		}

		if ( ! isset( $_GET['post_type'] ) || ! $_GET['post_type'] )
		{
			wp_die( 'missing "post_type" query var' );
		}

		echo "<pre>\n\nupdating 'author' taxonomy on published posts\n";

		$post_type = $_GET['post_type'];

		if ( isset( $_GET['batch_size'] ) )
		{
			$batch_size = (int) $_GET['batch_size'];
		}
		else
		{
			$batch_size = 10; // default
		}
		echo "  processing $batch_size '$post_type' posts at a time:\n\n";

		global $coauthors_plus, $wpdb;

		$query = $wpdb->prepare
			(
			"SELECT p.ID, p.post_author
			FROM $wpdb->posts p
			LEFT JOIN (
				SELECT tr.object_id 
				FROM $wpdb->term_relationships tr
				JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			) t ON t.object_id = p.ID
			WHERE 1=1
				AND p.post_status = %s AND p.post_type = %s
				AND t.object_id IS NULL
			GROUP BY p.ID 
			LIMIT %d",
			$coauthors_plus->coauthor_taxonomy,
			'publish',
			$post_type,
			$batch_size
			);
		$rows = $wpdb->get_results( $query );

		foreach( $rows as $row )
		{
			echo "$row->ID needs some coauthor taxonomy love  ";

			// each post has at least one author
			$coauthors = array();
			$author = get_user_by( 'id', (int) $row->post_author );
			if ( is_object( $author ) )
			{
				$coauthors[] = $author->user_login;
			}

			// and may have legacy coauthors stored in post_meta
			$legacy_coauthors = get_post_meta( $row->ID, '_coauthor' ); 
			if( is_array( $legacy_coauthors ) )
			{
				foreach( $legacy_coauthors as $legacy_coauthor )
				{
					$legacy_coauthor = get_user_by( 'id', (int) $legacy_coauthor );
					if ( is_object( $legacy_coauthor ) && ! in_array( $legacy_coauthor->user_login, $coauthors ) )
					{
						$coauthors[] = $legacy_coauthor->user_login;
					}
				}//END foreach
			}//END if

			$coauthors_plus->add_coauthors( $row->ID, $coauthors );

			echo "=>  coauthor(s) added\n";
		}//END foreach

		echo "</pre>\n";
		die;
	}

}//end class GO_Local_Coauthors_Plus

/**
 * singleton
 */
function go_coauthors()
{
	global $go_coauthors;

	if ( ! isset( $go_coauthors ) )
	{
		$go_coauthors = new GO_Local_Coauthors_Plus();
	}// end if

	return $go_coauthors;
}// end go_coauthors