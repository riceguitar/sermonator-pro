<?php

/**
 * Template used for displaying single pages
 *
 * @package SM/Views
 */

get_header();
load_template(__DIR__.'/content-sermon-wrapper-start.php');

if (empty($slug)) {
  echo "Series not found";
} else {
  $term = get_term_by("slug", $slug, "wpfc_sermon_series");
  if (!$term) {
    echo "Series not found";
  } else {
    $posts = get_posts(
  		array(
  			"post_type"=>'wpfc_sermon',
  			"tax_query" => array(
  				array(
  					'taxonomy' => 'wpfc_sermon_series',
  					'field' => 'term_id',
  					'terms' => array($term->term_id)
  				)
  			)
  		)
  	); // Get individual post

	  if (!empty($posts)) {
		  $image = wp_get_attachment_image_src(get_post_thumbnail_id($posts[0]), 'sermon_medium');
	  }

	  $src = $image[0];
	  if (empty($src)) {
		  $term = wp_get_post_terms($posts[0]->ID, "wpfc_sermon_series")[0];
		  $term->image_id = 0;
		  $assoc = sermon_image_plugin_get_associations();
		  if (array_key_exists( $term->term_taxonomy_id, $assoc ) ) {
			  $term->image_id = $assoc[ $term->term_taxonomy_id ];
		  }
		  $src = wp_get_attachment_image_src($term->image_id, 'sermon_medium')[0];
	  }
    ?>
		  <div class="sermon-custom-header-image" style="background-image:url(<?=$src?>);"></div>
      <h2 style="text-align: center; margin-bottom: 1rem;"><?=$term->name?></h2>
		  <div class="custom-sermon-grid-container search-form">
			  <form class="" method="get">
				  <input type="text" name="search" value="<?=$_GET["search"]?>" placeholder="Search">
				  <button type="submit" name="">Search</button>
			  </form>
		  </div>

    <?php
    if(!empty($posts)){
      $assoc = sermon_image_plugin_get_associations();
      foreach($posts as $post){
        $thumb_id = get_post_thumbnail_id($post);
				$image = wp_get_attachment_image_src($thumb_id,'sermon_medium');
        $src = $image[0]; 
        if(empty($src)){
          $term = wp_get_post_terms($post->ID, "wpfc_sermon_series")[0];
          $term->image_id = 0;
      		if ( array_key_exists( $term->term_taxonomy_id, $assoc ) ) {
      			$term->image_id = $assoc[ $term->term_taxonomy_id ];
      		}
          $src = wp_get_attachment_image_src($term->image_id, 'sermon_medium')[0];
        }
        $link = get_site_url(null, "/sm-post/$post->post_name", 'https');
        ?>
        <a href="<?=$link?>" style='color: black;'>
          <div class="custom-sermon-w-100 custom-sermon-container-custom custom-sermon-series-post">
            <div class="custom-sermon-image-container">
              <div class="custom-sermon-image" style='background-image: url("<?=$src?>");'>
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
  }
}
?>
<?php
load_template(__DIR__.'/content-sermon-wrapper-end.php');
get_footer();
