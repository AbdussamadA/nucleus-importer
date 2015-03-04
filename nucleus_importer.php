<?php
/*
Plugin Name: Nucleus to Wordpress importer
Description: Import content from a Nucleus CMS powered site into WordPress
Author: Abdussamad 
License: GPL
Version: 0.2
*/

set_time_limit(0);
ini_set('display_errors', true);

/* borrowed from movable type importer plugin */
if ( !defined('WP_LOAD_IMPORTERS') )
	return;


// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}
/* End borrowed */

/**
	The Main Importer Class
**/
class nucleus_import extends WP_Importer  {

	private $nucdb; // nucleus db wpdb class object
	private $nucpre; //nucleus database prefix;
	private $nucblog; //blog id (bnumber) of nucleus blog;
	private $step = 0; //step of the import process;
	private $site_url; //wp site url overriden via opening web form or default
	
	private $defaults = array( 
								'db_name'      => '',
								'db_user'      => '',
								'db_password'  => '',
								'db_host'      => 'localhost',
								'table_prefix' => 'nucleus_'
								);

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Import Nucleus CMS').'</h2>';
		echo '<p>'.__('Steps may take a few minutes depending on the size of your database. Please be patient.').'</p>';
	}

	function footer() {
		echo '</div>';
	}
	
	function greet() {
		?>
		<p><?php _e( 'This importer allows you to extract posts from any Nucleus CMS site into your blog.', 'nucleustowp' ) ?></p>
		<p><?php _e( 'Please enter your Nucleus configuration settings:', 'nucleustowp' ) ?></p>
		<form action='admin.php?import=nucleus&amp;step=<?php echo $this->step + 1 ?>' method='post'>
		<?php
		$this->db_form();
		?>
		<input type="submit" name="submit" value="<?php _e( 'Select blog', 'nucleustowp' )?>" />
		</form>
		<?php
	}

	function get_nucleus_db() {
		if ( ! $this->nucdb ) {
			$user = get_option('nucleus_user');
			$pass = get_option('nucleus_pass');
			$dbname = get_option('nucleus_name');
			$host = get_option('nucleus_host');
			if( $user && $pass && $dbname && $host ) {
				$nucdb = new wpdb( $user, $pass , $dbname , $host );
			} else {
			  	$nucdb = NULL ;
			}
		} else {
		  	$nucdb = $this->nucdb;
		}
		return $nucdb;
	}
	
	function get_nucleus_comments()	{
		return $this->nucdb->get_results("SELECT * FROM {$this->nucpre}comment WHERE `cblog` = $this->nucblog ; ", ARRAY_A);
	}
	
	function get_nucleus_cats() {
		return $this->nucdb->get_results( "SELECT `catid`, `cname`, `cdesc` FROM {$this->nucpre}category WHERE `cblog` = $this->nucblog ;", ARRAY_A );
	}	

	function cat2wp( $categories='' ) {
		// General Housekeeping
		global $wpdb;
		$count = 0;
		$nuc_cat_2_wp = array() ;
		if( is_array( $categories ) ) {
			echo '<p>'.__('Importing Categories...').'<br /><br /></p>';
			
			foreach ( $categories as $category ) {
				$count++;
				extract($category);
				$args = array( 'category_nicename' => $catid, 'cat_name' => $cname, 'category_description' => $cdesc );
				$ret_id = wp_insert_category( $args );
				$nuc_cat_2_wp[ $catid ] = $ret_id;
			}
			
			update_option( 'nuc_cat_2_wp', $nuc_cat_2_wp );
			echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> categories imported.'), $count).'<br /><br /></p>';
			return true;
		}
		echo __('No Categories to Import!');
		return false;
	}
	
	function get_nucleus_users() {
		return $this->nucdb->get_results( "SELECT `mnumber`, `mname`, `mrealname`, `memail`, `murl`, `madmin` FROM {$this->nucpre}member ;", ARRAY_A );
	}
	
	function get_user_blog_privileges( $user_id ) {
	  	return $this->nucdb->get_row( "SELECT * FROM {$this->nucpre}team WHERE `tmember` = $user_id AND `tblog` = $this->nucblog ;", ARRAY_A );
	}

	function users2wp( $users = '' ) {
		$count = 0;
		$nuc_user_2_wp_user = array();
		
		if( is_array( $users ) ) {
			echo '<p>'.__('Importing Users...').'<br /><br /></p>';
			foreach( $users as $user ) {
				$count++;
				extract($user);
				$args = array(	'user_login'	=> $mname,
						'user_nicename'	=> $mrealname,
						'user_email'	=> $memail,
						'user_url'	=> $murl,
						'display_name'	=> $mrealname
					);
				if( $madmin ) {
				  	$args[ 'role' ] = 'administrator';
				} else {
				  	$args[ 'role' ] = 'subscriber' ;
					$privileges = $this->get_user_blog_privileges( $mnumber );
					if( is_array( $privileges ) ) {
					  	$args[ 'role' ] = 'author';
						if( $privileges[ 'tadmin' ] ) {
						  	$args[ 'role' ] = 'editor';
						}
					}
				}
				if( $uinfo = get_user_by('login', $mname ) ) {
					$args[ 'ID' ] = $uinfo->ID;
				} else {
					$args[ 'user_pass' ] = NULL ;
				}
				$ret_id = wp_insert_user( $args );
		
				$nuc_user_2_wp_user[ $mnumber ] = $ret_id;
							
			}// End foreach($users as $user)
			
			// Store id translation array for future use
			update_option( 'nuc_user_2_wp_user', $nuc_user_2_wp_user );
			echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> users imported.'), $count).'<br /><br /></p>';
			return true;
		}// End if(is_array($users)
		
		echo __('No Users to Import!');
		return false;
		
	}// End function users2wp()
	
	function get_nucleus_posts( $start=0 ) {
		return $this->nucdb->get_results("SELECT 	`inumber`, `ititle`, `ibody`, `imore`, `iblog`, `iauthor`, `itime`, 
								`iclosed`, `idraft`, `icat` FROM {$this->nucpre}item 
								WHERE `iblog` = $this->nucblog
									LIMIT $start,500", ARRAY_A);
		
	}
	
	private function nucleus_image_links( $text, $author_id ) {
		$matched = preg_match_all( '/<%image\((.*)\)%>/', $text, $matches );
		$html_replace = array();
		if( $matched ) {
			foreach( $matches[ 1 ] as $match ) {
				$pieces =  explode( '|', $match );
				$file = $pieces[ 0 ];
	
				if( strpos( $file, '/' ) !== false ) { //public collection
	  				$abs_url = "$this->site_url/media/$file";
				} else {
		  			$abs_url = "$this->site_url/media/$author_id/$file";
				}
	
				$html = "<img src='$abs_url'";
				if( isset( $pieces[ 1 ] ) ){
		  			$html .= " width='$pieces[1]' ";
				}
	
				if( isset( $pieces[ 2 ] ) ) {
	  				$html .= " height='$pieces[2]' ";
				}
	
				if( isset( $pieces[3] ) ) {
	  				$html .= " alt='$pieces[3]' ";
				} else {
	  				$html .= " alt='" . basename( $abs_url ) . "'";
				}
	
				$html .= ' />';
				$html_replace[] = $html;
			}
			return str_replace( $matches[ 0 ], $html_replace, $text );
		} else {
		  	return $text;
		}
	}
	

	function posts2wp( $posts='' ) {
		$count = 0;
      		$nuc_posts_2_wp_posts = get_option('nuc_posts_2_wp_posts');
        	if ( ! $nuc_posts_2_wp_posts  ) 
			$nuc_posts_2_wp_posts = array();

		$cats = array();

		// Do the Magic
		if( is_array( $posts ) ) {
			echo '<p>'.__('Importing Posts...').'<br /><br /></p>';
			foreach( $posts as $post ) {
				$count++;
				extract($post);
				
				$post_status = null;
				if( $idraft ){
					$post_status = 'draft';
				} else {
					$post_status = 'publish';
				}

				$user_map = get_option( 'nuc_user_2_wp_user' );
				$authorid = $user_map[ $iauthor ];

				$post_title = $ititle;
				
				if ( $imore != '' ) {
					$post_body = $ibody . '<!--more-->'. $imore;
				} else {
					$post_body = $ibody;
				}
				$post_body = $this->nucleus_image_links( $post_body, $iauthor ); //convert <%image()%> type tags to html image link code
				//
				$post_date = date( 'Y-m-d H:i:s', strtotime( $itime ) );
				$post_date_gmt = get_gmt_from_date( $post_date );
				
				$insert_args = array(
							'post_date'			=> $post_date,
							'post_date_gmt'			=> $post_date_gmt,
							'post_author'			=> $authorid,
							'post_modified'			=> $post_date,
							'post_modified_gmt'		=> $post_date_gmt,
							'post_title'			=> $post_title,
							'post_content'			=> $post_body,
							'post_status'			=> $post_status,
							'post_name'			=> $post_title,
							'menu_order'			=> $inumber
							);

				$ret_id = wp_insert_post( $insert_args );
				$nuc_posts_2_wp_posts[ $inumber ] = $ret_id;
				
				if ( $icat == NULL ) {
				  	wp_set_post_categories( $ret_id, get_option('default_category') );
				} else {
					$cat_map = get_option( 'nuc_cat_2_wp' );
					wp_set_post_categories( $ret_id, array( $cat_map[ $icat ] ) );
				}

// 				
// 				$tags = $this->get_s9y_tag_assoc($id);
// 				if (is_array($tags)) {
// 					array_walk_recursive($tags, 'set_tags_from_s9y', $ret_id);
// 				}
// 				-----
			}
		}
		// Store ID translation for later use
		update_option('nuc_posts_2_wp_posts', $nuc_posts_2_wp_posts);
		
		echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> posts imported.'), $count).'<br /><br /></p>';
		return true;	
	}

	private function get_wp_user_details( $user_id ) {
	  	global $wpdb;
		return $wpdb->get_row( "SELECT * FROM $wpdb->users WHERE `ID` = $user_id", ARRAY_A );
	}

	function comments2wp( $comments = '' ) {
		
		$count = 0;
		$post_arr = get_option( 'nuc_posts_2_wp_posts' );
			
		if( is_array( $comments ) ) {
			echo '<p>'.__('Importing Comments...').'<br /><br /></p>';
			foreach( $comments as $comment ) {
				$count++;
				extract($comment);
				
				$posted = date( 'Y-m-d H:i:s', strtotime( $ctime ) );
				$comment_date_gmt = get_gmt_from_date( $posted );

				if( $cmember ) {
				  	$nuc_user_2_wp_user = get_option( 'nuc_user_2_wp_user' );
					$user_id = $nuc_user_2_wp_user[ $cmember ];
					$user_details = $this->get_wp_user_details( $user_id );
					$author = $user_details[ 'display_name' ];
					$email = $user_details[ 'user_email' ];
					$url = $user_details[ 'user_url' ];
				} else {
				  	$user_id = 0;
					$author = $cuser;
					$email = $cemail;
					$url = $cmail;
				}

				$args = array(
							'comment_post_ID'		=> $post_arr[ $citem ],
							'comment_author'		=> $author,
							'comment_author_email'		=> $email,
							'comment_author_url'		=> $url,
							'comment_author_IP'		=> $cip,
							'comment_date'			=> $posted,
							'comment_content'		=> $cbody,
							'comment_approved'		=> 1,
							'user_id'			=> $user_id
						);
						
				$ret_id = wp_insert_comment( $args );
			}
					
			echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> comments imported.'), $count).'<br /><br /></p>';
			return true;
		}
		echo __('No Comments to Import!');
		return false;
	}
		
	function get_nucleus_blogs() {
	  	return $this->nucdb->get_results( "SELECT * FROM {$this->nucpre}blog;", ARRAY_A ) ;
	}
	
	function select_blog() {
	  	$blogs = $this->get_nucleus_blogs();
		if( is_array( $blogs ) ) {
			?>
				<form name='frmnucleusblogs' action='admin.php?import=nucleus&amp;step=<?php echo $this->step + 1 ?>' method='post'>
			<?php
			$count = 0;
		  	foreach( $blogs as $blog ) {
			  	extract( $blog );
				$default = '';
				if ( $count == 0 ) {
				  	$default = ' checked = "yes" ';
				}
				?>
					<p><input type='radio' name='nucleus_blog' value='<?php echo $bnumber ?>'<?php echo $default ?>><?php echo $bname ?></input></p>
				<?php
				$count ++;
		  	}
			?>
					<p><input type="submit" name="submit" value="<?php _e( 'Pre Import', 'nucleustowp' ); ?>"></p>
				</form>
			<?php
		}
	}

	function pre_import() {
		$this->prepare_wpdb();
		$this->load_nucleus_meta();		
		$this->set_wp_permalink();
		?>		
		<p>
			Done!
		</p>
		<form action="admin.php?import=nucleus&amp;step=<?php echo $this->step + 1 ?>" method="post">
			<input type="submit" name="submit" value="<?php _e( 'Import Categories', 'nuctowp' ); ?>">
		</form>
		<?php	
	}

	function prepare_wpdb() {
	  	global $wpdb;
		?>
		<p>
			<?php _e( 'Preparing WordPress database for import' ) ?>
		</p>
		<?php
		$wpdb->query( "TRUNCATE $wpdb->posts;" );
		$wpdb->query( "TRUNCATE $wpdb->postmeta;" );
		$wpdb->query( "TRUNCATE $wpdb->term_relationships;" );
		$wpdb->query( "TRUNCATE $wpdb->term_taxonomy;" );
		$wpdb->query( "TRUNCATE $wpdb->comments;" );
		$wpdb->query( "TRUNCATE $wpdb->commentmeta;" );
		$wpdb->query( "DELETE from $wpdb->terms WHERE {$wpdb->terms}.term_id != 1;" );
	}

	function get_nucleus_meta() {
  		return $this->nucdb->get_row( "SELECT * FROM {$this->nucpre}blog WHERE bnumber = $this->nucblog", ARRAY_A );
	}

	function load_nucleus_meta() {
		?>
		<p>
			<?php _e( 'Loading Nucleus meta data' ) ?>
		</p>
		<?php
	  	$meta = $this->get_nucleus_meta();
		if( is_array( $meta ) ) {
			extract( $meta );
			update_option( 'blogname', $bname );
			update_option( 'blogdescription', $bdesc ) ;
			if( $bcomments ) {
				update_option( 'default_comment_status', 'open' );
			} else {
			  	update_option( 'default_comment_status', 'closed' );
			}
		}
	}

	function set_wp_permalink() {
	  	?>	
		<p>
			<?php _e( 'Setting WP permalink structure' ) ?>
		</p>
		<?php
		
		update_option( 'permalink_structure', '/item/%post_id%' );
		update_option( 'category_base', '/category' );
	}

	function import_categories() {	
		// Category Import	
		$cats = $this->get_nucleus_cats();
		$this->cat2wp($cats);
				
		?>
		<form action="admin.php?import=nucleus&amp;step=<?php echo $this->step + 1 ?>" method="post">
			<input type="submit" name="submit" value="<?php _e('Import Users') ?>" />
		</form>
		<?php
	}
	
	function import_users()
	{
		// User Import
		$users = $this->get_nucleus_users(); 
		$this->users2wp( $users );
		
		?>
		<form action="admin.php?import=nucleus&amp;step=<?php echo $this->step + 1 ?>" method="post">
			<input type="submit" name="submit" value="<?php _e( 'Import Posts' )?>" />
		</form>
		<?php
	}
	
	function import_posts() {
		// Process 500 posts per load, and reload between runs
		$start = isset( $_GET["start"] ) ? $_GET["start"] : 0 ;
		$posts = $this->get_nucleus_posts( $start );
		
		if ( count($posts) != 0 ) 
			$this->posts2wp( $posts );
			
		if ( count($posts) == 500 ) {
			echo "Reloading: More work to do.";
			$url = "admin.php?import=nucleus&step=$this->step&start=".( $start + 500 );
			?>
			<script type="text/javascript">
			window.location = '<?php echo $url; ?>';
			</script>
			<?php
			return;
		}
		
		?>
		<form action="admin.php?import=nucleus&amp;step=<?php echo $this->step + 1 ?>" method="post">
			<input type="submit" name="submit" value="<?php _e( 'Import Comments' )?>" />
		</form>
		<?php
	}
	
	function import_comments() {
		// Comment Import
		$comments = $this->get_nucleus_comments();
		$this->comments2wp($comments);
		
		?>
		<form action="admin.php?import=nucleus&amp;step=<?php echo $this->step + 1?>" method="post">
			<input type="submit" name="submit" value="<?php _e( 'Finish' ) ?>" />
		</form>
		<?php
	}
	
	function cleanup_nucleus_import() {
		global $wpdb;
		delete_option('nucleus_prefix');
		delete_option('nuc_cat_2_wp');
		delete_option('nuc_posts_2_wp_posts');
		delete_option('nuc_user_2_wp_user');
		delete_option('nucleus_user');
		delete_option('nucleus_pass');
		delete_option('nucleus_name');
		delete_option('nucleus_host');
		delete_option('nucleus_blog');
		delete_option('nucleus_wp_site_url');
	
		$wpdb->query( "UPDATE $wpdb->comments AS a SET a.`comment_post_ID` = (SELECT b.`menu_order` FROM  $wpdb->posts AS b WHERE a.`comment_post_ID`= b.`ID`);" );
		$wpdb->query( "UPDATE $wpdb->term_relationships SET `object_id`=`object_id`+20000;" );
		$wpdb->query( "UPDATE $wpdb->term_relationships AS a SET a.`object_id`= (SELECT b.`menu_order` FROM $wpdb->posts AS b WHERE a.`object_id`-20000= b.`ID`);" );
		$wpdb->query( "UPDATE $wpdb->posts SET `ID`=`ID`+20000;" );
		$wpdb->query( "UPDATE $wpdb->posts SET `ID`=`menu_order`, `guid`=CONCAT( '" . $this->site_url . "/?p=',`menu_order`), `menu_order`=NULL;" );

		$post_id = $wpdb->get_var( "SELECT `id`+1 FROM $wpdb->posts ORDER BY `id` DESC LIMIT 0,1;" );
		$wpdb->query( "ALTER TABLE $wpdb->posts AUTO_INCREMENT = $post_id;" );
		$this->tips();
	}

	function tips()
	{
		echo '<p>'.__('Welcome to WordPress.  We hope (and expect!) that you will find this platform incredibly rewarding!  As a new WordPress user coming from serendipity, there are some things that we would like to point out.  Hopefully, they will help your transition go as smoothly as possible.').'</p>';
		echo '<h3>'.__('Users').'</h3>';
		echo '<p>'.__('You have already setup WordPress and have been assigned an administrative login and password.  Forget it.  You didn\'t have that login in serendipity, why should you have it here?  Instead we have taken care to import all of your users into our system.  Unfortunately there is one downside.  Because both WordPress and serendipity uses a strong encryption hash with passwords, it is impossible to decrypt it and we are forced to assign temporary passwords to all your users.') . ' <strong>' . __( 'Every user has the same username, but their passwords have been reset. Users are advised to use the forgot password option to generate new passwords.') .'</strong></p>';
		echo '<h3>'.__('Preserving Authors').'</h3>';
		echo '<p>'.__('Secondly, we have attempted to preserve post authors.  If you are the only author or contributor to your blog, then you are safe.  In most cases, we are successful in this preservation endeavor.  However, if we cannot ascertain the name of the writer due to discrepancies between database tables, we assign it to you, the administrative user.').'</p>';
			echo '<h3>'.__('WordPress Resources').'</h3>';
		echo '<p>'.__('Finally, there are numerous WordPress resources around the internet.  Some of them are:').'</p>';
		echo '<ul>';
		echo '<li>'.__('<a href="http://www.wordpress.org">The official WordPress site</a>').'</li>';
		echo '<li>'.__('<a href="http://wordpress.org/support/">The WordPress support forums').'</li>';
		echo '<li>'.__('<a href="http://codex.wordpress.org">The Codex (In other words, the WordPress Bible)</a>').'</li>';
		echo '</ul>';
		echo '<p>'.sprintf(__('That\'s it! What are you waiting for? Go <a href="%1$s">login</a>!'), '/wp-login.php').'</p>';
	}
	
	private function db_form()
	{
		?>
		<ul>
			<li>
				<label for="dbuser"><?php _e( 'Nucleus Database User:' ) ?></label> 
				<input type="text" name="dbuser" id="dbuser" value="<?php echo esc_attr( $this->defaults[ 'db_user' ] ); ?>" />
			</li>
			<li>
				<label for="dbpass"><?php _e( 'Nucleus Database Password:' ) ?></label>
				<input type="password" name="dbpass" id="dbpass" value="<?php echo esc_attr( $this->defaults[ 'db_password' ] ); ?>" />
			</li>
			<li>
				<label for="dbname"><?php _e( 'Nucleus Database Name:' ) ?></label>
				<input type="text" id="dbname" name="dbname" value='<?php echo esc_attr( $this->defaults[ 'db_name' ] ); ?>' />
			</li>
			<li>
				<label for="dbhost"><?php _e( 'Nucleus Database Host:' ) ?></label>
				<input type="text" id="dbhost" name="dbhost" value="<?php echo esc_attr( $this->defaults[ 'db_host' ] ); ?>" />
			</li>
			<li>
				<label for="dbprefix"><?php _e( 'Nucleus Table prefix (if any):' ) ?></label>
				<input type="text" name="dbprefix" id="dbprefix" value = "<?php echo esc_attr( $this->defaults[ 'table_prefix' ] ); ?>" />
			</li>
			<li>
				<label for="site_url"><?php _e( 'WordPress Site URL (override) (no trailing slash): http://' ) ?></label>
				<input type="text" name="site_url" id="site_url" value = "" />
			</li>	
		</ul>
		<?php
	}
	
	public function dispatch() {
		$this->header();

		switch ( $this->step ) {
			default:
			case 0 :
				$this->greet();
				break;
			case 1 :
				$this->select_blog();
				break;
			case 2 :
				$this->pre_import();
				break;
			case 3 :
				$this->import_categories();
				break;
			case 4 :
				$this->import_users();
				break;
			case 5 :
				$this->import_posts();
				break;
			case 6 :
				$this->import_comments();
				break;
			case 7 :
				$this->cleanup_nucleus_import();
				break;
		}
		
		$this->footer();
	}
	
	private function set_form_options() {
	  	
		if ( $this->step > 0 ) {
			if( isset( $_POST['dbuser'] ) )	{
				update_option( 'nucleus_user', $_POST['dbuser'] );
			}
			if( isset( $_POST['dbpass'] ) )	{
				update_option( 'nucleus_pass', $_POST['dbpass'] );
			}
			
			if( isset( $_POST['dbname'] ) )	{
				update_option( 'nucleus_name', $_POST['dbname'] );
			}
			if( isset( $_POST['dbhost'] ) )	{
				update_option( 'nucleus_host', $_POST['dbhost'] ); 
			}

			if( isset( $_POST['dbprefix'] ) ) {
				update_option( 'nucleus_prefix', $_POST['dbprefix'] ); 
			}

			if( isset( $_POST[ 'nucleus_blog' ] ) ) {
				if( is_numeric( $_POST[ 'nucleus_blog' ] ) ) {
			  		update_option( 'nucleus_blog', $_POST[ 'nucleus_blog' ] ) ;
				}
			}
			
			if( isset( $_POST[ 'site_url' ] ) ) {
			  	update_option( 'nucleus_wp_site_url', $_POST[ 'site_url' ] ) ;
			}
		}
	}
	
	private function load_options() {
		$this->step = empty ( $_GET['step'] ) ? 0 : $_GET[ 'step' ];
		$this->set_form_options();
		$this->nucblog = get_option( 'nucleus_blog' );
		$this->nucdb = $this->get_nucleus_db();
		$this->nucpre = get_option( 'nucleus_prefix' );
		
		if( $this->site_url = get_option( 'nucleus_wp_site_url' ) ) {
			if( substr( $this->site_url, 0, 7 ) != 'http://' ) {
			  	$this->site_url = 'http://' . $this->site_url;
			}
		} else {
		  	$this->site_url = home_url();
		}
	}

	function __construct() {
		register_importer( 'nucleus', 'Nucleus CMS', __( 'Import posts from a Nucleus CMS site' ), array ( $this, 'dispatch' ) );
		$this->load_options();
	}
}

new nucleus_import();
