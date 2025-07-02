<?php
/**
 * Template used for displaying main page
 *
 * @package SM/Views
 */
get_header();
load_template(__DIR__.'/content-sermon-wrapper-start.php');
wp_register_style( 'hide-box-handle', false );
wp_enqueue_style( 'hide-box-handle' );
wp_add_inline_style( 'hide-box-handle', 'main.wpfc-sermon-container:before{display:none!important;}');
function add_post_date($terms){
	if(!empty($terms[0])){
		for($i=0;$i<count($terms);$i++){
			$latest_post = get_posts(array(
				'posts_per_page' => 1,
				"post_type"=>'wpfc_sermon',
				"tax_query" => array(
					array(
						'taxonomy' => 'wpfc_sermon_series',
						'field' => 'term_id',
						'terms' => array($terms[$i]->term_id)
					)
				)
			));
			if(!empty($latest_post)){
				$terms[$i]->post_date = $latest_post[0]->post_date;
			}else{
				$terms[$i]->post_date = "0000-00-00 00:00:00";
			}
		}
	}
	return $terms;
}
$terms = get_terms(array('wpfc_sermon_series'));
$term_ids = [];
foreach($terms as $term){
	$term_ids[] = $term->term_id;
}

$terms = add_post_date($terms); //Add post_date attribute
$posts = $terms;
if(isset( $_GET["search"])){
    $searchTerm = $_GET["search"];
}else{
    $searchTerm = "";
}
if(!empty($searchTerm)){
	$filtered_posts = [];
	foreach($posts as $post){
		if(get_class($post) == "WP_Post" && strpos(strtoupper($post->post_title), strtoupper($searchTerm)) !== false){
			$filtered_posts[] = $post;
		}else if(get_class($post) == "WP_Term" && strpos(strtoupper($post->name), strtoupper($searchTerm)) !== false){
			$filtered_posts[] = $post;
		}
	}
	$posts = $filtered_posts;
}
uasort($posts, 'compare_element'); //Sort with date descending
$chunks = array_chunk($posts, 9); //Chunk the $posts
if(isset($_GET["current_page"])){
    $ctPage = $_GET["current_page"];
}else{
    $ctPage = 1;
}
$page = max(1, $ctPage);

if($page-1 < count($chunks)){
	$posts = $chunks[$page-1];
}else{
	$posts = null;
}
$smManiTitle = get_option( 'sermonmanager_archive_slug');
if(!empty($posts)) { ?>	


<div class="custom-sermon-grid-container search-form">
    <form class="" action="<?php echo home_url();?>" method="get">
        <input type="text" name="s" value="<?php if (!empty($_GET['search'])){ echo $_GET["search"]; } ?>" placeholder="&#61442; Search">
        <input type="hidden" name="post_type" value="sermon">
        <button type="submit" name="">Search</button>
    </form>
</div> 
    <div class="custom-sermon-grid-container" style="width:100%;">
	<?php
	 $mg=1;
	 if(!empty($posts[0]))
	 {	
		foreach($posts as $post){
    		$title = $post->name;
    		$imageID = get_term_meta( $post->term_id, 'sm_term_image_id', true );
    		$image = wp_get_attachment_image_src($imageID, 'full');
    		$src = $image[0];
    	   if (empty($src)) {
                  $post->image_id = 0;
                  $assoc = sermon_image_plugin_get_associations();
                  if (array_key_exists($post->term_taxonomy_id, $assoc)) {
                      $post->image_id = $assoc[$post->term_taxonomy_id];
                  }
                  $src = wp_get_attachment_image_src($post->image_id, 'full')[0];
            }
        	if (!$src) :
               $src = SM_URL_CURRENT.'/assets_custom/images/SMPro-fallback-image.jpg';
            endif;
    		// $link = esc_url( get_term_link( $post, $post->taxonomy ) );
    		$link = get_site_url(null, $smManiTitle."/sermon-series/$post->slug", $_SERVER['REQUEST_SCHEME']);
    		// $slug = $post->slug;
			// Get current page URL 
			$the_URL = urlencode( get_permalink($post->term_id) );

			// Get current page title
			$the_title = htmlspecialchars( urlencode( html_entity_decode( get_the_title(), ENT_COMPAT, 'UTF-8') ), ENT_COMPAT, 'UTF-8');
			// $the_title = str_replace( ' ', '%20', get_the_title());

			// Get Post Thumbnail for pinterest
			$the_thumbnail = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );
			$the_info = get_bloginfo();
			$the_image = get_site_icon_url();
			if ( $the_thumbnail ) 
			{
				$the_image = $the_thumbnail[0];
			}
			?>
			<div class="custom-sermon-grid-item">
				<div class="blog_detail">
					<a href="<? echo $link; ?>">
						<div class="custom-sermon-image" style='background-image: url("<?php echo $src; ?>")'></div>
					</a>
					<div class="blog_text">
						<span class="blog-heading">
						<h3 style='margin: 10px 0 10px 0px; padding: 0'><?= ucfirst($title)?></h3>
							<!-- <span class="posted-on">--><?php //= date('Y-m-d',strtotime($post->post_date))?><!--</span>-->
						</span>
					</div>
				</div>
			</div>
			<?php
		$mg++;	
		}
		echo '<div class="sermon_pagination">';
		if(!isset($_REQUEST['current_page'])) { echo '<span class="nexthide"><</span>'; }
			echo paginate_links(array(
				// 'base' => str_replace( 999999, '%#%', esc_url( get_pagenum_link( 999999 ) ) ),
				'format' => '?current_page=%#%',
				'current' => max( 1, $page ),
				'total' => count($chunks),
				'prev_text'=>'',
				'next_text'=> ''
			));
			if($page == count($chunks)) { echo '<span class="nexthide"> > </span>'; } 
			echo '</div>';
		}	
		?>
		</div>
		<?php
	} else{
		echo "No sermon found";
	}
load_template(__DIR__.'/content-sermon-wrapper-end.php');
get_footer();
?>