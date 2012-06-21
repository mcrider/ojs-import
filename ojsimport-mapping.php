<?php

/* Supported data sources: 
	
    JSON: the JSON file must have at least following attribute:
        "items" : An array of all items to import
     Each item must have at least the following attribute:
        "type" : Name of the ojs category
     Any other attributes can be defined in the mapping for this 
     ojs category. Attributes that have no mapping are ignored.
  */

  /* Mapping ojs categories and attributes to wordpress types.
     The mapping must be an array of the form:

     array( <ojs category> => array(
	      'type' => <wordpress type>
	      <any required mappings for the wordpress type>
	      'attributes' => array(
	         'ojs attribute' => <attribute mapping>
	      )
	    )
     )
      
     If an entry has the form:

	'ojs category A' => 'ojs category B'

     The mapping for 'ojs category B' will be the same as for 'ojs category A'.

 
     Supported wordpress types:
     "post" : imports element into an wordpress post
	required mappings:
	   "category"    : slug of top level category to import into ( must exist )
	   "primary_key" : attribute which holds the primary key for each item
        supported attribute mappings:
	   A single value or an array of the following types are supported:
	   "category"   : adds post to a subcategory with the name of the attribute value
		          and a parent category with the name of the attribute
	   "post_title" : attribute value becomes the post title
	   "post_excerpt" : attribute value becomes the post excerpt
	   "post_content" : attribute value becomes the post content
	   "post_date"    : attribute value becomes the post publish date
	   "meta"	  : attribute value becomes a post custom value with the attribute
			    name as the key
	   "favicon"	  : attribute value should be a URL. The favicon of this URL will be
			    saved as a post meta with the key "favicon"
	   "attachment"   : attribute value becomes an attachment to the post
			    The attribute value must be either an array containing the
			    keys described in the attachment attribute mapping or a string
			    ( or an array of strings ) of the form '<prefix>:filename'. 
			    The latter will be downloaded from the url given in the attachment_url option.
			    The attachment ID will be stored as a post meta value under
			    the key name.
			    If there exists a key with the name 'attachmentkey_name', it is
			    supposed to hold the name of the attachment.
			    If the attachment is actually an array of attachments, the attachment_name
			    attribue must also be an array of the same size.
	   "globalattachment" : a special attachment where the attachment ID
				will be stored in an option under the key name
	   "calendar_start" : ( requires ec3 plugin ) 
			 attribute value becomes the start date of this post
	   "calendar_end" : ( requires ec3 plugin ) 
			 attribute value becomes the end date of this post

     "attachment" : imports element into a wordpress attachment
	required mappings:
	   "page"    : slug of the page that attachments are attached to ( must exist )
	   "primary_key" : attribute which holds the primary key for each item
        supported attribute mappings:
	   "url" : attribute value holds the URL used to download the attachment
	   "file" : attribute value holds the filename of the attachment
	   "title": attribute value becomes the title of this attachment

     "link" : imports element into a wordpress link
	required mappings:  none
	optional mappings:
	   "default_category": if existent, links which have no category
                               will be assigned to this category
        supported attribute mappings:
	   "link_name':  attribute value will become the link name 
	   "link_url":   attribute value will become the link url
	   "link_description":   attribute value will become the link description
	   "category":   attribute value will become the link category

     "gallery" : creates a post and attachments for this post
	required mappings:
	   "primary_key" : attribute which holds the primary key for each item
	   "category"    : slug of top level category to import into ( must exist )
        supported attribute mappings:
	   "name':  attribute value will become the title of the gallery 
	   "description":   attribute value will become the description of the gallery
	   "featured_image":   attribute value holds the filename of the featured image 
			       for the gallery
	   "gallery_folder":   attribute value is a folder which contains all images for
			       this gallery
  */
  $ojs_mapping = array(
	'articleInfo' => array(
		'type' => 'post',
		'category' => 'article',
		'primary_key' => 'id',
		'attributes' => array(
			'title' => 'post_title',
			'abstract' => 'post_content',
			'authorString' => 'meta',
			'image' => 'attachment',
			'sectionTitle' => 'category',
			'datePublished' => 'meta',
			'pdfUrl' => 'meta',
			'htmlUrl' => 'meta'
		)	
	),
	'issueInfo' => array(
		'type' => 'post',
		'category' => 'issue',
		'primary_key' => 'id',
		'attributes' => array(
			'genre' => array('category','meta'),
			'title' => 'post_title',
			'short_description' => 'post_excerpt',
			'long_description' => 'post_content',
			'genre' => array('category','meta'),
			'eventtype' => array('category','meta'),
			'location' => array('category','meta'),
			'house' => array('category','meta'),
			'room' => array('category','meta'),
			'age' => array('category','meta'),
			'date_begin' => array('calendar_start','meta'),
			'date_end' => array('calendar_end','meta'),
			'image_small' => 'attachment',
			'image_big' => 'attachment',
			'sponsor' => 'attachment',
			'banner' => 'globalattachment',
			'subtitle' => 'meta',
			'homepage' => 'meta',
			'homepagelabel' => 'meta'
		)	
	)
  );

?>
