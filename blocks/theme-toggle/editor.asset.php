<?php
// Version follows the file, so a changed script is never served from a cache.
return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor' ),
	'version'      => (string) filemtime( __DIR__ . '/editor.js' ),
);
