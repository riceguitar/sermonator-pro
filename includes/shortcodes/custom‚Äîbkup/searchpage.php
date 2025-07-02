<?php
/**
 * Template used for displaying single pages
 *
 * @package SM/Views
 */

get_header();
load_template(__DIR__.'/content-sermon-wrapper-start.php');
if(!empty($posts)){
    $term = get_term_by("slug", $slug, "wpfc_sermon_series");
    
    $src = wp_get_attachment_image_src($term->image_id, 'full')[0];
    if (empty($src)) {
        $term = wp_get_post_terms($posts[0]->ID, "wpfc_sermon_series")[0];
    	if(!empty($term)){
    		$term->image_id = '0';
    		$assoc = sermon_image_plugin_get_associations();
    		if (array_key_exists($term->term_taxonomy_id, $assoc)) {
    			$term->image_id = $assoc[$term->term_taxonomy_id];
    		}
    		$src = wp_get_attachment_image_src($term->image_id, 'full')[0];
    	}
    }
    if (!$src) :
        $src = SM_URL_CURRENT.'/assets_custom/images/SMPro-fallback-image.jpg';
    endif;
}
wp_register_style( 'search-box-handle', false );
wp_enqueue_style( 'search-box-handle' );
wp_add_inline_style( 'search-box-handle', '.no-res{text-align: center; margin-bottom: 1rem;} main.wpfc-sermon-container:before { background: #171717; width: 0; content: ""; height: 0; display: none;} .wpfc-sermon-before:before{ display:none;} .custom-sermon-grid-container.search-form { margin-bottom:22px; }' );
?>
<div class="custom-sermon-grid-container search-form">
    <form class="" action="<?php echo home_url();?>" method="get">
        <input type="text" name="s" value="<?php if(isset($_GET["search"])){ echo $_GET["search"]; }?>" placeholder="&#61442; Search">
        <input type="hidden" name="post_type" value="sermon">
        <button type="submit" name="">Search</button>
    </form>
</div>
<h2 style="text-align: center; margin-bottom: 1rem;">Search Results For: <?=$_GET["s"]?></h2>
<?php
if(!empty($posts)){
    $assoc = sermon_image_plugin_get_associations();
	
       foreach($posts as $post){
			if( get_post_type($post->ID) == 'wpfc_sermon'){
				$thumb_id = get_post_thumbnail_id($post);
				$image = wp_get_attachment_image_src($thumb_id,'full');
				$src = $image[0];
				if(empty($src)){
					$src = SM_URL_CURRENT.'/assets_custom/images/SMPro-fallback-image.jpg';
				}

				//$link = get_site_url(null, "/sm-post/$post->post_name");
				$link = get_permalink($post);
			?>
			<a href="<?=$link?>" style='color: black;'>
				<div class="custom-sermon-w-100 custom-sermon-container-custom custom-sermon-series-post">
					<div class="custom-sermon-image-container">
						<div class="custom-sermon-image" style='background-image: url("<? echo $src; ?>");'>
						</div>
					</div>
					<div class="custom-sermon-text">
						<h4><?=$post->post_title?></h4>
						<p>
							<?php if ( has_excerpt( $post ) ) : ?>
								<?php echo get_the_excerpt( $post ); ?>
							<?php else : ?>
								<?php echo wp_trim_words( get_post_meta( $post->ID, 'sermon_description', true ), 30 ); ?>
							<?php endif; ?>
						</p>
					</div>
				</div>
			</a>
			<?php
		   }
    }
} else { ?>
    <h2 class="no-res">Nothing Found.</h2>
<?php } ?>

<?php
load_template(__DIR__.'/content-sermon-wrapper-end.php');
get_footer();
