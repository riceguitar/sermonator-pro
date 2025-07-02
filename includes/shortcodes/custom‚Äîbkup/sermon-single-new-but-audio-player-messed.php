<?php
	/**
	 * Template used for displaying single pages
	 *
	 * @package SM/Views
	 */

	get_header();
	global $post;
	?>
	<script src="https://cdn.plyr.io/3.4.3/plyr.js"></script>
	<script>
	jQuery(document).ready(function() {
	<?php
	wp_add_inline_script( 'wpfc-sm-plyr', "window.addEventListener('DOMContentLoaded',function(){var players=plyr.setup(document.querySelectorAll('.wpfc-sermon-player,.wpfc-sermon-video-player'),{\"debug\": " . ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ? 'true' : 'false' ) . "});for(var p in players){if(players.hasOwnProperty(p)){players[p].on('loadedmetadata ready',function(event){if(typeof this.firstChild.dataset.plyr_seek !== 'undefined'){var instance=event.detail.plyr;instance.seek(parseInt(this.firstChild.dataset.plyr_seek));}});}}})" );

	?>
	const player = new Plyr('.wpfc-sermon-video-player');
	});</script>
	<?php
	load_template(__DIR__.'/content-sermon-wrapper-start.php');
	if(empty($slug)){
		echo "Sermon not found";
	}else{
		$post = get_page_by_path($slug, OBJECT, "wpfc_sermon");
		
		
		
		if(!$post){
			echo "Sermon not found";
		}else{
			// echo "<pre>";
			// echo do_shortcode("[included_files]");
			// echo "</pre>";
			// echo "<pre>";
			// print_r($post);
			// echo "</pre>";

			$sermon_video_link = get_post_meta( $post->ID, 'sermon_video_link', true );
			$sermon_video = get_post_meta( $post->ID, 'sermon_video', true );
			$sermon_audio = get_post_meta( $post->ID, 'sermon_audio', true );
			$sermon_audio_id = get_post_meta( $post->ID, 'sermon_audio_id', true );
			?>
			<div class="wpfc-sermon-single-media">
			
				<?php 
				
				if ( $sermon_video_link ) : ?>
					<div class="wpfc-sermon-single-video wpfc-sermon-single-video-link">
						<?php echo wpfc_render_video( $sermon_video_link ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $sermon_video ) : ?>
					<div class="wpfc-sermon-single-video wpfc-sermon-single-video-embed">
						<?php echo do_shortcode( $sermon_video ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $sermon_audio || $sermon_audio_id ) : ?>
					<?php
					$sermon_audio_id  = $sermon_audio_id;
					$sermon_audio_url = $sermon_audio_id ? wp_get_attachment_url( intval( $sermon_audio_id ) ) : $sermon_audio;
					?>
					<div class="wpfc-sermon-single-audio player-<?php echo strtolower( \SermonManager::getOption( 'player', 'plyr' ) ); ?>">
						<div class="custom-sermon-share">
							
							<?php 
							
							
							
							
							
							
							
							
						//	print_r($post->ID);
	//	if( is_singular() ){
		
			// Get current page URL 
			$the_URL = urlencode( get_permalink() );

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
			<style>
	.social-dropdown {
	    position: relative;
	    display: inline-block;
	}
	
	/*the content*/
	.social-dropdown .social-dropdown-menu {
	    position: fixed;
	    visibility: hidden;
	    margin: 0;
	    z-index: 999999;
	    width: 340px; 
	    padding: 24px;
	    background-color: #ffffff;
	    border-radius: 5px;
	    box-shadow: 0 15px 35px 0 rgba(0,0,0,.1), 0 2px 6px 0 rgba(0,0,0,.04);
		-webkit-transition: opacity .6s ease-in-out;
	    -moz-transition: opacity .6s ease-in-out;
	    -o-transition: opacity .6s ease-in-out;
	    -ms-transition: opacity .6s ease-in-out;
	    transition: opacity .6s ease-in-out;
	}
	/*.social-dropdown .share {
	    background-color: #008892;
		padding: 5px;
	    color: #fff;
	    border-radius: 50%;
	    width: 35px;
	    height: 35px;
	    text-align: center;
    	line-height: 1.7;
    	cursor: pointer;
	}*/
	.social-dropdown .share {
		padding: 10px 34px;
	background: black;
	color: white;
	text-transform: uppercase;
	border-radius: 40px;
	font-size: 14px;
	    cursor: pointer;
	}
	.social-dropdown .share:hover {
	
	}
	.social-dropdown a {
	    display: block;
	        padding: 6px;
	    font-size: 21px;
	    text-decoration: none;
    	color: #fff;
	}

	/*content*/
	.social-dropdown .social-title {
		text-align: center;
		font-size: 14px;
		margin-bottom: 25px;
	}
	.social-dropdown h4 {
		font-weight: 600;
	    letter-spacing: 2px;
	    line-height: 2em;
	    text-transform: uppercase;
		font-size: 14px;

	}
	.social-dropdown .social-buttons:after {
		clear: both;
	}
	.social-dropdown .social-buttons:before, .social-dropdown .social-buttons:after {
		display: table;
    	content: " ";
	}
	.social-dropdown .social-buttons {
		width: 80%;
		margin: auto;
		font-family: Proxima-Nova,'helvetica neue',helvetica,arial,sans-serif;
	}
	.social-dropdown .button-wrapper {
		width: 33%;
		float: left;
		text-align: center;
		font-size: 14px;

	}
	.social-dropdown .the-button:hover { 
		opacity: .8;
	}
	.social-dropdown .the-button {
	    width: 60%;
	    height: 0;
	    padding-bottom: 60%;
	    border-radius: 50%;
	    margin: auto;
	}
	.social-dropdown .button-facebook {
		background-color: #4566a2;
	}
	.social-dropdown .button-twitter {
		background-color: #3bb0db;
	}
	.social-dropdown .button-google {
		background-color: #d34836;
	}
	.social-dropdown .button-title {
		margin-top: 10px;
	}

	/*links*/
	.social-dropdown .social-links {
		margin-top: 10px;
	}
	.social-dropdown .form-group label {
		font-size: 14px;
		font-weight: 600;
	}
	.social-dropdown .field-group:after {
		clear: both;
	}
	.social-dropdown .field-group:before, .social-dropdown .field-group:after {
		display: table;
    	content: " ";
	}
	.social-dropdown .field-group {
		border: 1px solid #e1e2e6;
		border-radius: 3px;
		padding: 4px;
		position: relative; 
		color: #7e7f82;
		font-size: 14px;
	}
	.social-dropdown .text-field:after {
		background: linear-gradient(to right,rgba(255,255,255,0),#fff 95%);
	    content: '';
	    display: block;
	    height: 100%;
	    pointer-events: none;
	    position: absolute;
	    right: 0;
	    top: 0;
	    width: 40px;
	}
	.social-dropdown .text-field {
		float: left;
		overflow: hidden;
	    position: relative;
	    white-space: nowrap;
	    padding: 4px;
	    max-width: 79%;
	}
	.social-dropdown .button-field {
		float: right;
		align-items: center;
	    background-color: #edeef0;
	    border-radius: 3px;
	    width: auto;
	    transition: width .15s cubic-bezier(.42,0,.58,1);
	    padding: 4px 12px;
	    cursor: pointer;

	}
	.social-dropdown .form-group {
		margin-top: 20px;
	}

	/*end content*/
	.social-dropdown .social-overlay {
		position: fixed;
	    width: 100%;
	    height: 100%;
	    top: 0;
	    left: 0;
	    cursor: pointer;
	    background: black;
	    z-index: 9;
	    opacity: 0;
	    /*display: none;*/
	    visibility: hidden;
		-webkit-transition: all .6s ease-in-out;
	    -moz-transition: all .6s ease-in-out;
	    -o-transition: all .6s ease-in-out;
	    -ms-transition: all .6s ease-in-out;
	    transition: all .6s ease-in-out;
	}
	.social-dropdown.active .social-overlay {
		visibility: visible;
		display: block;
		opacity: .3;
	}
	.social-dropdown.active .social-dropdown-menu {
		visibility: visible;
		display: block;
		opacity: 1;
	}
	.custom-sermon-related {
    width: 80%;
    margin: auto;
    overflow: hidden;
    margin-top: 40px;
    margin-bottom: 100px;
}
	@media (max-width: 768px) {
		.social-dropdown.active .social-dropdown-menu {
			left: 15px !important;
			width: 295px;
		} 
		.social-dropdown .text-field {
			max-width: 70%;
		}
		.social-dropdown a {
			padding: 0.1em .2em;
		}
	}
</style>
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
    	// set position popup
    	var popup = document.querySelector(".social-dropdown-menu");
    	popup.style.top = ( offset.top - popup.offsetHeight + offset.height) + 'px';
    	popup.style.left = ( offset.left - popup.offsetWidth + offset.width ) + 'px';
		// get element and add class
		var element = document.getElementById("social-dropdown");
    	element.classList.add("active");

    	// encode html code to copy
    	var code = document.querySelector("#text-code");
    	var encoded = encodeHTML(code.innerHTML);
    	code.innerHTML = encoded;
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

<div class="social-dropdown" id="social-dropdown">
	<!-- trigger button -->
	<div class="share" id="share-button" onclick="showPopup()"><i class="fas fa-share-alt"></i> SHARE</div>

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
    		<?php /*<div class="form-group">
    			<label for="">EMBED CODE</label>
    			<div class="field-group">
    				<div class="text-field" id="text-code">
    					<!-- need to change to url video -->
    					<?php $media_url = 'https://subsplash.com/+2e23/embed/mi/+tbnwhyb?video&audio&info&embeddable&shareable&logo_watermark' ?>
    					<div style="position:relative;width:100%;height:0;padding-top:56.25%;">
    						<iframe src="<?php echo $media_url ?>" frameborder="0" webkitallowfullscreen mozallowfullscreen allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;"></iframe>
    					</div>
    				</div>
    				<div class="button-field" onclick="copyFunction('text-code')">
    					Copy
    				</div>
    			</div>
    		</div> */ ?>
    	</div>
    </div>
</div>
			
			<?php

							
							
							
							
							
							
							
							
							
							
							
							
							
							
							
							
							
							
							 ?>
						</div>
						<?php
							$sermon_notes = get_post_meta( $post->ID, 'sermon_notes', true );
							if(!empty($sermon_notes)) :?>
								<div class="custom-sermon-notes" style='background: #efefef; border: 1px solid #ddd; padding: 24px; margin-bottom: 30px;'>
									<strong><?php echo __( 'Download Files', 'sermon-manager-for-wordpress' ); ?></strong><br>
									<a href="<?php echo $sermon_notes ?>"
									   class="sermon-attachments"
									   download="<?php echo basename( $sermon_notes ); ?>">
										<span class="dashicons dashicons-media-document"></span>
										<?php echo __( 'Notes', 'sermon-manager-for-wordpress' ); ?>
									</a>
								</div>
							<?php
							endif;
						?>
						<?php echo wpfc_render_audio( $sermon_audio_url ); ?>
						<a class="wpfc-sermon-single-audio-download"
						   href="<?php echo $sermon_audio_url; ?>"
						   download="<?php echo basename( $sermon_audio_url ); ?>"
						   title="<?php echo __( 'Download Audio File', 'sermon-manager-for-wordpress' ); ?>">
							<svg fill="#000000" height="24" viewBox="0 0 24 24" width="24"
							     xmlns="http://www.w3.org/2000/svg">
								<path d="M0 0h24v24H0z" fill="none"></path>
								<path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"></path>
							</svg>
						</a>
					</div>
				<?php endif; ?>
			</div>
			<div class="" style='margin: 10px 0; margin-bottom: 30px; display: flex; flex-wrap: wrap;'>
				<div class="custom-sermon-description">
					<h3 style="margin: 1rem 0;"><?=$post->post_title?></h3>
					<p><?=get_post_meta( $post->ID, 'sermon_description', true )?></p>
				</div>
			</div>
			<?php
		}
	}
	$termid = get_the_terms($post->ID, 'wpfc_sermon_series');
	$related = $termid[0]->term_id;
	$slink = site_url().'/sm-series/'.$termid[0]->slug;
	$posts = get_posts(  		array(  			"post_type"=>'wpfc_sermon',			'post__not_in' => array ($post->ID),  			"tax_query" => array(  				array(  					'taxonomy' => 'wpfc_sermon_series',  					'field' => 'term_id',  					'terms' => array($related)  				)  			)  		)  	);

?>
	<div class="custom-sermon-related"> <div class="custom-sermon-w-100 custom-title">
	<h3>More From <a href="<?php echo $slink; ?>"><?=$termid[0]->name?></a></h3>
	</div>
<?php  if(!empty($posts)){      
			foreach($posts as $post){        
				$thumb_id = get_post_thumbnail_id($post);	    
				$image = wp_get_attachment_image_src($thumb_id,'full');        
				$src = $image[0];        
				$link = get_site_url(null, "/sm-post/$post->post_name", 'https');      
				
				
				
	  if (empty($src)) {
		  $term = wp_get_post_terms($posts[0]->ID, "wpfc_sermon_series")[0];
		  $term->image_id = 0;
		  $assoc = sermon_image_plugin_get_associations();
		  if (array_key_exists( $term->term_taxonomy_id, $assoc ) ) {
			  $term->image_id = $assoc[ $term->term_taxonomy_id ];
		  }
		  $src = wp_get_attachment_image_src($term->image_id, 'full')[0];
	  }
				
				
				
				  ?>  
				<div class="custom-sermon-w-100 custom-sermon-series-post">        
				<a href="<?=$link?>" class="custom-sermon-related-link" style='color: black;'>            
				<div class="custom-sermon-image-container">              
				<div class="custom-sermon-image" style='background-image: url("<?=$src?>");'>              
				</div>            
				</div>            
				<div class="custom-sermon-text">              <h4><?=$post->post_title?></h4>			  <p class="post_date"><?php echo get_the_date( 'F j, Y' , $post->ID); ?></p>              <p>                <?php if ( has_excerpt( $post ) ) : ?>                  <?php echo get_the_excerpt( $post ); ?>                <?php else : ?>                  <?php echo wp_trim_words( get_post_meta( $post->ID, 'sermon_description', true ), 30 ); ?>                <?php endif; ?>              </p>            </div>        </a>	 </div>        <?php      }    }	?>

<?php
	load_template(__DIR__.'/content-sermon-wrapper-end.php');
	get_footer();
	
	?> 
<?php
