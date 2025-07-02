<?php

/**
 * Template used for displaying single pages
 *
 * @package SM/Views
 */
get_header();
global $wp;
$pieces = explode("/", $wp->request);
$slug = ($pieces[1]); 
load_template(__DIR__.'/content-sermon-wrapper-start.php');
if (empty($slug)) {
  echo "Series not found";
} else {
  $termData = get_term_by("slug", $slug, "wpfc_preacher");
  $term = get_term_by("slug", $slug, "wpfc_preacher");
  if (!$term) {
    echo "Series not found";
  } else {
    /* Pagination */
    if(isset($_GET['stage'])){
        $currentPage = $_GET['stage'];
        if(empty($currentPage)){
            $currentPage = 1;
        }
    }else{
        $currentPage = 1;
    }
    $posts_sermon = new WP_Query(array(
        "post_type"=>'wpfc_sermon',
        "tax_query" => array(
  				array(
  					'taxonomy' => 'wpfc_preacher',
  					'field' => 'term_id',
  					'terms' => array($term->term_id)
  				)
  		),
        'posts_per_page' => 5,
        'orderby'          => 'date',
        'order'            => 'ASC',
        'paged' => $currentPage
    ));
    $posts = $posts_sermon->posts;
    $src = wp_get_attachment_image_src($term->image_id, 'full')[0];
	if (empty($src)) {
          $term = wp_get_post_terms($posts[0]->ID, "wpfc_preacher")[0];
          $term->image_id = 0;
          $assoc = sermon_image_plugin_get_associations();
          if (array_key_exists($term->term_taxonomy_id, $assoc)) {
              $term->image_id = $assoc[$term->term_taxonomy_id];
          }
          $src = wp_get_attachment_image_src($term->image_id, 'full')[0];
     }
	  if (!$src) :
        $src = SM_URL_CURRENT.'/assets_custom/images/SMPro-fallback-image.jpg';
      endif;
    ?>
      <div class="custom-sermon-grid-container search-form">
          <form class="" action="<?php echo home_url();?>" method="get">
              <input type="text" name="s" value="<?php if(isset($_GET["search"])){ echo $_GET["search"]; }?>" placeholder="&#61442; Search">
              <input type="hidden" name="post_type" value="sermon">
              <button type="submit" name="">Search</button>
          </form>
      </div>
      <div class="sermon-custom-header-image"><img src="<?php echo $src; ?>" /></div>
      <h2 style="text-align: center; margin-bottom: 1rem;"><?php echo $termData->name; ?></h2>
    <?php 
    if(!empty($posts)){
      $assoc = sermon_image_plugin_get_associations();
      foreach($posts as $post){
        $thumb_id = get_post_thumbnail_id($post);
        $image = wp_get_attachment_image_src($thumb_id,'full');
        $src = $image[0];
        if(empty($src)){
          $src = SM_URL_CURRENT.'/assets_custom/images/SMPro-fallback-image.jpg';
        }
        
        //$link = get_site_url(null, "/sm-post/$post->post_name", $_SERVER['REQUEST_SCHEME']);
        $link = get_permalink($post);
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
      echo "<div class='sermon_pagination'>" . paginate_links(array(
            'total' => $posts_sermon->max_num_pages,
            'format' => '?stage=%#%',
            'current' => max( 1, $currentPage ),
            'prev_text' => __(''),
            'next_text' => __('')
        )) . "</div>";
    }
  }
}
?>
<?php
load_template(__DIR__.'/content-sermon-wrapper-end.php');
get_footer();