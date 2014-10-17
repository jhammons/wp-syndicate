<?php
class WP_SYND_Action {
	private $args = array(
					'post_type' => 'wp-syndicate',
					'posts_per_page' => -1,
					'post_status' => 'publish'
				);
	private $post = '';
	private $host = '';
	private $match_count = 0;

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'save_post', array( $this, 'set_event' ) );
		add_action( 'admin_head', array( $this, 'ping' ) );
		add_action( 'template_redirect', array( $this, 'ping' ) );
		$posts = get_posts($this->args);

		if ( empty($posts) )
			return;

		foreach ( $posts as $post ) {
			add_action( 'wp_syndicate_' . $post->post_name . '_import', array( $this, 'import' ) );
		}
	}

	public function ping() {
		$posts = get_posts($this->args);
		if ( empty($posts) )
			return;

		foreach ( $posts as $post ) {
			$key = $post->post_name;
			$hook = 'wp_syndicate_' . $key . '_import';

			if ( !wp_next_scheduled( $hook, array( $post->ID ) ) ) {
				$this->set_event($post->ID);
				$subject = '[' . get_bloginfo( 'name' ) . ']' . __( 'WP Cron Error', WPSYND_DOMAIN );
				$msg  = sprintf( __( '%s of WP Cron restart, because it stopped.', WPSYND_DOMAIN ), $hooke ) . "\n". __( 'action time', WPSYND_DOMAIN ). ':' . date_i18n('Y/m/d:H:i:s') . "\n\n\n";
				$msg .= admin_url();
				$error_post_id = WP_SYND_logger::get_instance()->error( $subject, $msg );
				$options = get_option( 'wp_syndicate_options' );
				wp_mail( $options['error_mail'], $subject, $msg );
			}
		}
	}

	public function cron_schedules($schedules) {
		$posts = get_posts($this->args);

		if ( empty($posts) )
			return;

		foreach ( $posts as $post ) {
			$key = 'wp_syndicate_' . $post->post_name;
			$interval_min = get_post_meta( $post->ID, 'wp_syndicate-feed-retrieve-term', true );
			$interval = intval($interval_min)*60;
			$display = get_the_title( $post->ID );
			$schedules[$key] = array( 'interval' => $interval, 'display' => $interval_min . 'min' );
		}

		return $schedules;
	}
	
	public function set_event($post_id) {

		if ( wp_is_post_revision($post_id) )
			return;

		if ( 'wp-syndicate' != get_post_type($post_id) )
			return;

		$post = get_post( $post_id );
		if ( !is_object($post) )
			return;

		$key = $post->post_name;
		$interval_min = get_post_meta( $post_id, 'wp_syndicate-feed-retrieve-term', true );
		$interval = intval($interval_min)*60;
		$action_time = time() + $interval;

		$hook = 'wp_syndicate_' . $key . '_import';
		$event = 'wp_syndicate_' . $key;

		if ( wp_next_scheduled( $hook, array( $post_id )) );
			wp_clear_scheduled_hook( $hook, array( $post_id ) );

		wp_schedule_event( $action_time, $event, $hook, array( $post_id )  );
		spawn_cron( $action_time );
	}

	public function import($post_id) {
		global $allowedposttags;

		$allowedposttags = apply_filters( 'wp_syndicate_allowedposttags', $allowedposttags );

		$options = get_option( 'wp_syndicate_options' );
		$post = get_post($post_id);
		
		if ( !is_object($post) )
			return;

		$feed_url = get_post_meta( $post_id, 'wp_syndicate-feed-url', true );

		add_action('wp_feed_options', function(&$feed, $url){
    		$feed->set_timeout(30); // set to 30 seconds
            $feed->force_feed(true);
		}, 10, 2);
		
		add_filter( 'wp_feed_cache_transient_lifetime' , array( $this, 'return_0' ) );
		$rss = fetch_feed( $feed_url );
		remove_filter( 'wp_feed_cache_transient_lifetime' , array( $this, 'return_0' ) );
		if ( is_wp_error( $rss ) ) {
			$subject = '[' . get_bloginfo( 'name' ) . ']' . __( 'feed import failed', WPSYND_DOMAIN );
			$msg  = sprintf( __( 'An error occurred at a feed retrieval of %s', WPSYND_DOMAIN ), $post->post_name ) . "\n". __( 'action time', WPSYND_DOMAIN ). ':' . date_i18n('Y/m/d:H:i:s') . "\n\n\n";
			$msg .= __( 'below error message', WPSYND_DOMAIN ) . "\n";
			$msg .= $rss->get_error_message()."\n\n";
			$msg .= __( 'feed URL', WPSYND_DOMAIN ) . ':' . $feed_url;
			$error_post_id = WP_SYND_logger::get_instance()->error( $subject, $msg );
			$msg .= admin_url('/post.php?post=' . $error_post_id . '&action=edit');
			wp_mail( $options['error_mail'], $subject, $msg );
			return;
		}

		$url = parse_url($rss->get_base());
		$this->host = $url['host'];

		$rss_items = $rss->get_items(0, 50);
		$post_ids = array();
		$flg = true;
		$registration_method = get_post_meta( $post_id, 'wp_syndicate-registration-method', true );
		$post_type = get_post_meta( $post_id, 'wp_syndicate-default-post-type', true );
		foreach ( $rss_items as $item ) {
			
			//投稿ID取得
			$slug = $post->post_name . '_' . $item->get_id();
			$set_post = get_page_by_path( sanitize_title($slug), OBJECT, $post_type );
			$set_post_id = $set_post == null ? '' : $set_post->ID;
			
			if ( $registration_method == 'insert' && is_object($set_post) )
				continue;
			
			//投稿時刻
			$pub_time = $item->get_date('Y/m/d H:i:s');
			$pub_time = strtotime($pub_time);
			$pub_time = date_i18n('Y/m/d H:i:s', $pub_time);
		
			$this->post = new wp_post_helper(array(
								'ID' => $set_post_id,
								'post_name' => $slug,
								'post_author' => get_post_meta( $post_id, 'wp_syndicate-author-id', true ),
								'post_date' => $pub_time,
								'post_type' => get_post_meta( $post_id, 'wp_syndicate-default-post-type', true ),
								'post_status' => get_post_meta( $post_id, 'wp_syndicate-default-post-status', true ), 								'post_title' => $item->get_title(),
								'post_content' => '',
							));
							
			//画像の登録
			if ( $set_post_id ) {
				$images = get_attached_media( 'image', $set_post_id );
				if ( is_array( $images ) ) {
					foreach ( $images as $image ) {
						wp_delete_attachment( $image->ID );
					}
				}
			}
			$content = apply_filters( 'the_content', $item->get_content() );
			$this->match_count = 0;
			$content = preg_replace_callback( '/<img(.*)src="(.*?)"(.*)\/>/',  array($this, 'update_link'), $content, -1 );
			$this->match_count = 0;

			$this->post->set(array(
								'post_content' => $content
							));
			$this->post->add_meta( 'wp_syndicate-origin-of-syndication-slug', $post->post_name, true );
			$this->post->add_meta( 'wp_syndicate-origin-of-syndication-siteurl', $this->host, true );

			$update_post_id = $this->post->update();
			if ( !$update_post_id ) {
				$flg = false;
			} else {
				do_action( 'wp_syndicate_save_post', $update_post_id, $item );
				$post_ids[] = $update_post_id;
			}
		} 
		
		if ( $flg ) {
			$subject = '[' . get_bloginfo( 'name' ) . ']' . __( 'feed import success', WPSYND_DOMAIN );
			$msg = sprintf( __( 'Feed acquisition completion of %s', WPSYND_DOMAIN ), $post->post_name ) . "\n" . __( 'action time', WPSYND_DOMAIN ). ':' . date_i18n('Y/m/d:H:i:s') . "\n\n\n";
			$msg .= __( 'below ID data updates', WPSYND_DOMAIN ) . "\n";
			$msg .= implode( "\n", $post_ids );
			WP_SYND_logger::get_instance()->success( $subject, $msg );
		} else {
			$subject = '[' . get_bloginfo( 'name' ) . ']' . __( 'feed import failed', WPSYND_DOMAIN );
			$msg = sprintf( __( 'Failed to some data registration of %s', WPSYND_DOMAIN ), $post->post_name ) . "\n". __( 'action time', WPSYND_DOMAIN ). ':' . date_i18n('Y/m/d:H:i:s') . "\n\n\n";
			$msg .= __( 'below ID data updates', WPSYND_DOMAIN ) . "\n";
			$msg .= implode( "\n", $post_ids );
			$error_post_id = WP_SYND_logger::get_instance()->error( $subject, $msg );
			$msg .= admin_url('/post.php?post=' . $error_post_id . '&action=edit');
			wp_mail( $options['error_mail'], $subject, $msg );
		}
		
	}

	public function return_0( $seconds ) {
		return 0;
	}

	public function update_link( $matches ) {
	
		if ( is_array($matches) && array_key_exists(2, $matches) &&  strpos($matches[2], $this->host) !== false ) {
			$args    = array();
			$options = get_option( 'wp_syndicate_options' );
			$user    = $options['wp_syndicate-basic-auth-user'];
			$pass    = $options['wp_syndicate-basic-auth-pass'];
			if ( !empty($user) && !empty($pass) ) {
				$args = array(
					'headers' =>
						array( 'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ) )
				);
			}
			if ( $media = remote_get_file($matches[2], '', $args) ) {
				$thumnail_flg = $this->match_count > 0 ? false : true;
				$this->post->add_media($media, '', '', '', $thumnail_flg);
				$url = preg_split( '/wp-content/', $media );
				$url = home_url( 'wp-content' . $url[1] );
				$this->match_count++;

				return '<img' . $matches[1] . 'src="' . $url . '"' . $matches[3] . '/>';
			} else {
				return $matches[0];
			} 
		}
		return $matches[0];
	}
}
new WP_SYND_Action();