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
?>
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.2/jquery.min.js"></script>
	<script src="https://cdn.plyr.io/3.4.3/plyr.js"></script>
	<script>
	jQuery(document).ready(function() {
	<?php
	wp_add_inline_script( 'wpfc-sm-plyr', "window.addEventListener('DOMContentLoaded',function(){var players=plyr.setup(document.querySelectorAll('.wpfc-sermon-player,.wpfc-sermon-video-player'),{\"debug\": " . ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ? 'true' : 'false' ) . "});for(var p in players){if(players.hasOwnProperty(p)){players[p].on('loadedmetadata ready',function(event){if(typeof this.firstChild.dataset.plyr_seek !== 'undefined'){var instance=event.detail.plyr;instance.seek(parseInt(this.firstChild.dataset.plyr_seek));}});}}})" );
	?>
	const player = new Plyr('.wpfc-sermon-video-player');
	});
	</script>
	<?php
	load_template(__DIR__.'/content-sermon-wrapper-start.php');
	
	if(empty($slug)){
		echo "Sermon not found";
	}else{
		$post = get_page_by_path($slug, OBJECT, "wpfc_sermon");
		if(!$post){
			echo "Sermon not found";
            wp_register_style( 'single-box-handle', false );
            wp_enqueue_style( 'single-box-handle' );
            wp_add_inline_style( 'single-box-handle', 'main#main::before{ display:none !important; }' );

    	}else{
			$sermon_video_link = get_post_meta( $post->ID, 'sermon_video_link', true );
			$sermon_video = get_post_meta( $post->ID, 'sermon_video', true );
			$sermon_audio = get_post_meta( $post->ID, 'sermon_audio', true );
			$sermon_audio_id = get_post_meta( $post->ID, 'sermon_audio_id', true );
			$term = wp_get_post_terms($post->ID, "wpfc_sermon_series")[0];
            $term->image_id = 0;
            $assoc = sermon_image_plugin_get_associations();
            if (array_key_exists($term->term_taxonomy_id, $assoc)) {
                $term->image_id = $assoc[$term->term_taxonomy_id];
            }

            $series_image = SM_URL_CURRENT.'/assets_custom/images/SMPro-fallback-image.jpg';
            
			?>
            <div class="custom-sermon-grid-container search-form">
                <form class="" action="<?php echo home_url();?>" method="get">
                    <input type="text" name="s" value="<?php if(isset($_GET["search"])){ echo $_GET["search"]; }?>" placeholder="&#61442; Search">
                    <input type="hidden" name="post_type" value="sermon">
                    <button type="submit" name="">Search</button>
                </form>
            </div>
			<div class="wpfc-sermon-single-media">
				<?php if ( $sermon_video_link ) : ?>
					<div class="wpfc-sermon-single-video wpfc-sermon-single-video-link">
						<?php echo wpfc_render_video( $sermon_video_link ); ?>
					</div>
                <?php else : ?>
                    <div class="wpfc-sermon-single-video wpfc-sermon-single-video-link">
                        <?php if (has_post_thumbnail( $post->ID ) ): ?>
                            <?php $image = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'single-post-thumbnail' )[0]; ?>
                        <?php else :?>
                        <?php $image = $series_image; ?>
                        <?php endif; ?>
                        <img src=<?php echo $image; ?>>
                    </div>
				<?php endif; ?>
				<?php if ( $sermon_video ) : ?>
					<div class="wpfc-sermon-single-video wpfc-sermon-single-video-embed">
						<?php  echo do_shortcode( $sermon_video ); ?>
					</div>
				<?php endif; ?>

					<div class="wpfc-sermon-single-audio player-<?php echo strtolower( \SermonManager::getOption( 'player', 'plyr' ) ); ?>">
						<div class="custom-sermon-share">
							<?php 
								// Get current page URL 
								$the_URL = urlencode( get_permalink($post->ID) );

								// Get current page title
								$the_title = htmlspecialchars( urlencode( html_entity_decode( get_the_title(), ENT_COMPAT, 'UTF-8') ), ENT_COMPAT, 'UTF-8');
								// $the_title = str_replace( ' ', '%20', get_the_title());
								
								// Get Post Thumbnail for pinterest
								$the_thumbnail = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );
								$the_info = get_bloginfo();
						 
								// Construct sharing URL without using any script
								$twitterURL = 'https://twitter.com/intent/tweet?text='.$the_title.'&amp;url='.$the_URL;
								$facebookURL = 'https://www.facebook.com/sharer/sharer.php?u='.$the_URL;
								$googleURL = 'https://plus.google.com/share?url='.$the_URL;

								// if thumbnail image is null change to site icon
								$the_image = get_site_icon_url();
								if ( $the_thumbnail ) {
									$the_image = $the_thumbnail[0];
								}
								// Based on popular demand added Pinterest too
								$pinterestURL = 'https://pinterest.com/pin/create/button/?url='.$the_URL.'&amp;media='.$the_image.'&amp;description='.$the_title;
						 	?>
<script>
	function copyFunction(containerid) {
	  	/* Select the text */
		if (document.selection) { // IE
	        var range = document.body.createTextRange();
	        range.moveToElementText(document.getElementById(containerid));
	        range.select();
	    } else if (window.getSelection) {
	        var range = document.createRange();
	        range.selectNode(document.getElementById(containerid));
	        window.getSelection().removeAllRanges();
	        window.getSelection().addRange(range);
	    }

	  /* Copy the text */
	  document.execCommand("copy");
	}

	function showPopup() {
		var btn = document.getElementById('share-button');
		var offset = btn.getBoundingClientRect();
    	var popup = document.querySelector(".social-dropdown-menu");
    	popup.style.top = ( offset.top - popup.offsetHeight + offset.height) + 'px';
    	popup.style.left = ( offset.left - popup.offsetWidth + offset.width ) + 'px';
		var element = document.getElementById("social-dropdown");
    	element.classList.add("active");
	}

	function closePopup() {
		var element = document.getElementById("social-dropdown");
    	element.classList.remove("active");
	}

	window.addEventListener("scroll", closePopup);

	var encodeHTML = (function() {
 
	    var encodeHTMLmap = {
	        "&" : "&amp;",
	        "'" : "&#39;",
	        '"' : "&quot;",
	        "<" : "&lt;",
	        ">" : "&gt;"
	    };
	 
	    /**
	    * encode character as HTML entity
	    * @param {String} ch character to map to entity
	    * @return {String}
	    */
	    function encodeHTMLmapper(ch) {
	        return encodeHTMLmap[ch];
	    }
	 
	    return function(text) {
	        // search for HTML special characters, convert to HTML entities
	        return text.replace(/[&"'<>]/g, encodeHTMLmapper);
	    };
	 
	})();
</script>
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.2.0/css/all.css" integrity="sha384-hWVjflwFxL6sNzntih27bfxkr27PmbbK/iSvJ+a4+0owXq79v+lsFkW54bOGbiDQ" crossorigin="anonymous">
<script src='https://kit.fontawesome.com/a076d05399.js'></script>
<div class="social-dropdown" id="social-dropdown">
	<!-- trigger button -->
	<div class="share ggdfg" id="share-button" onclick="showPopup()"><i class="fas fa-share-alt"></i> SHARE</div>

	<div class="social-overlay" onclick="closePopup()"></div>
    <!-- dropdown menu -->
    <div class="social-dropdown-menu">
    	<div class="social-title"><h4>SHARE</h4></div>
    	<div class="social-buttons">
    		<div class="button-wrapper">
    			<div class="the-button button-facebook">
    				<a class="" href="<?php echo  $facebookURL ?>" target="_blank"><i class="fab fa-facebook"></i></a>
    			</div>
    			<div class="button-title">Facebook</div>
    		</div>
    		<div class="button-wrapper">
    			<div class="the-button button-twitter">
    				<a class="" href="<?php echo  $twitterURL ?>" target="_blank"><i class="fab fa-twitter"></i></a>
    			</div>
    			<div class="button-title">Twitter</div>
    		</div>
    		<div class="button-wrapper">
    			<div class="the-button button-google">
    				<a class="" href="<?php echo  $googleURL ?>" target="_blank"><i class="fab fa-google-plus"></i></a>
    			</div>
    			<div class="button-title">Google</div>
    		</div>
    	</div>
    	<div class="social-links">
    		<div class="form-group">
    			<label for="">LINK</label>
    			<div class="field-group">
    				<div class="text-field" id="text-url">
    					<?php 
	    					global $wp;
							echo home_url( $wp->request );
    					 ?>
    				</div>
    				<div class="button-field" onclick="copyFunction('text-url')">
    					Copy
    				</div>
    			</div>
    		</div>
    	</div>
    </div>
</div>
						</div>
						<?php
							$sermon_notes = get_post_meta( $post->ID, 'sermon_notes', true );
							if(!empty($sermon_notes)) :?>
								<div class="custom-sermon-notes" style='background: #efefef; border: 1px solid #ddd; padding: 24px; margin-bottom: 30px;'>
									<strong><?php echo __( 'Download Files', 'sermon-manager-for-wordpress' ); ?></strong><br>
									<a href="<?php echo $sermon_notes ?>"
									   class="sermon-attachments"
                                       target="_blank"
									   download="<?php echo basename( $sermon_notes ); ?>">
										<i aria-hidden="true" class="fas fa-file-alt"></i>
										<?php echo __( 'Notes', 'sermon-manager-for-wordpress' ); ?>
									</a>
								</div>
							<?php
							endif;
						?>
						<?php 
                            if ( $sermon_audio || $sermon_audio_id ) : 
					           $sermon_audio_id  = $sermon_audio_id;
					           $sermon_audio_url = $sermon_audio_id ? wp_get_attachment_url( intval( $sermon_audio_id ) ) : $sermon_audio;
						        echo wpfc_render_audio( $sermon_audio_url ); 
						    endif;
						?>						
					</div>
			</div>
			<?php 
			function get_sermons_tags($postid,$taxnomoyname,$tagtype){
			    $smManiTitle = get_option( 'sermonmanager_archive_slug');
				$term_sermon_tag_list = get_the_terms( $postid, $taxnomoyname);
				if(!empty($term_sermon_tag_list))
				{
				    $tag_list = "";
					if($tagtype == 'preachers'){
					    $speakerLable = get_option( 'sermonmanager_preacher_label');
					    if(!empty($speakerLable)){
					        $tag_list .= ' <strong>'.$speakerLable.'</strong>: ';
					    }else{
					        $tag_list .= ' <strong>'.ucfirst($tagtype).'</strong>: ';
					    }
					    $tagtype = 'speakers';
					}else{
					    $tag_list .= ' <strong>'.ucfirst($tagtype).'</strong>: ';
					}
					
					foreach($term_sermon_tag_list as $sermon_taglist)
					{
					   $startingURL = '';
					  
                        if($tagtype == 'speakers'){
                             $speakerDefault = get_option( 'sermonmanager_preacher_label');
                             if(!empty($speakerDefault)){
                                $startingURL = strtolower($speakerDefault)."/";
                             }else{
                                 $startingURL = "preacher/";
                             }
                        }
                        if($tagtype == "topics"){
                            $startingURL = "topics/";
                        }
                        if($tagtype == "series"){
                            $startingURL = "series/";
                        }
						$taglink = get_site_url(null, $startingURL.$sermon_taglist->slug, $_SERVER['REQUEST_SCHEME']);
						$tag_list .= '<a href="'.$taglink.'">'.$sermon_taglist->name.'</a>, ';

					} 
					return $tag_list;
				}	
			}
			$series_tag = get_sermons_tags( $post->ID, 'wpfc_sermon_series','series');
			$topics_tag = get_sermons_tags( $post->ID, 'wpfc_sermon_topics','topics');
			//$bible_book_tag = get_sermons_tags( $post->ID, 'wpfc_bible_book','books');
			$preache = get_sermons_tags( $post->ID, 'wpfc_preacher','preachers');
			$bibleMessage  = get_post_meta($post->ID,'bible_passage',true);
			if(!empty($bibleMessage)){
			    $bibleMessagedata = ' <strong>Verses</strong>: '.$bibleMessage;
			}else{
			    $bibleMessagedata = '';
			}
			
			
			?>
			<div class="" style='margin: 10px 0; margin-bottom: 30px; display: flex; flex-wrap: wrap;'>
				<div class="custom-sermon-description">
					<h3 style="margin: 1rem 0;"><?= ucfirst($post->post_title); ?></h3>
					<div class="sermon_tag_class"><?= rtrim($series_tag,', '); ?> &nbsp;<?=  rtrim($topics_tag,', '); ?>&nbsp;<?=  rtrim($preache,', '); ?>&nbsp;<?=  rtrim($bibleMessagedata,', '); ?></div> 
					<p class="description_text"><?=get_post_meta( $post->ID, 'sermon_description', true )?></p>
				</div>
			</div>
			<?php
			/* meta data */
			 $metaTitle = ucfirst($post->post_title);
			 $metaDesc  = get_post_meta( $post->ID, 'sermon_description', true );
			 if(isset($image)){
			     $metaImage = $image;
			 }else{
			    $metaImage = $series_image;
			 }
			 
			 $metaUrl = home_url( $wp->request );

		}
	}
	$smManiTitle = get_option( 'sermonmanager_archive_slug');
	$termid = get_the_terms($post->ID, 'wpfc_sermon_series');
	$related = $termid[0]->term_id;
	$slink = site_url().'/series/'.$termid[0]->slug;

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
    'post__not_in' => array ($post->ID),
    "tax_query" => array(
            array(
                    'taxonomy' => 'wpfc_sermon_series',
                'field' => 'term_id',
                'terms' => array($related)
            )
    )   ,
    'posts_per_page' => 5,
    'orderby'          => 'date',
    'order'            => 'ASC',
    'paged' => $currentPage
));
$posts = $posts_sermon->posts;

?>

<?php
if(!empty($posts)){
	?>
	<div class="custom-sermon-related">
		<div class="custom-sermon-w-100 custom-title">
			<strong>More From <a href="<?php echo $slink; ?>" class="sm-gray-color"><?=$termid[0]->name?></a></strong>
		</div> 
	<?php
	foreach($posts as $post){        
			$thumb_id = get_post_thumbnail_id($post);	    
			$image = wp_get_attachment_image_src($thumb_id,'full');        
			$src = $image[0];        
			//$link = get_site_url(null, "/sm-post/$post->post_name", $_SERVER['REQUEST_SCHEME']);   
			$link = get_permalink($post);  
			if (empty($src)) 
			{
				$src = SM_URL_CURRENT.'/assets_custom/images/SMPro-fallback-image.jpg';
			}
			?>  
			<div class="custom-sermon-w-100 custom-sermon-series-post custom-sermon-box">        
				<a href="<?=$link?>" class="custom-sermon-related-link" style='color: black;'>
					<div class="custom-sermon-image-container">
						<div class="custom-sermon-image" style='background-image: url("<? echo $src; ?>");'></div>
					</div>
					<div class="custom-sermon-text">
						<h4><?= ucfirst($post->post_title); ?></h4>
						<p class="post_date">
							<?php echo get_the_date( 'F j, Y' , $post->ID); ?>
						</p>
						<p class="sermons-text-short-desc"> 
							<?php if ( has_excerpt( $post ) ) : ?>
								<?php echo get_the_excerpt( $post ); ?>
							<?php else : ?>
								<?php echo wp_trim_words( get_post_meta( $post->ID, 'sermon_description', true ), 30 ); ?>
							<?php endif; ?>
						</p>
					</div>
				</a>
			</div>
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
load_template(__DIR__.'/content-sermon-wrapper-end.php');
get_footer();
?>