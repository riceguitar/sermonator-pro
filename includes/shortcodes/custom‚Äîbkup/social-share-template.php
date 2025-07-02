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
	.social-dropdown .share {
	    background-color: #008892;
		padding: 5px;
	    color: #fff;
	    border-radius: 50%;
	    width: 35px;
	    height: 35px;
	    text-align: center;
    	line-height: 1.7;
    	cursor: pointer;
	}
	
	.social-dropdown a {
	    display: block;
	    padding: .4em .6em;
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
	<div class="share" id="share-button" onclick="showPopup()"><i class="fas fa-share-alt"></i></div>

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
    		<div class="form-group">
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
    		</div>
    	</div>
    </div>
</div>