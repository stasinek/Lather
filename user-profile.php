<?php
//------------------------------------------------------------------------------------------------
// INCLUDE
//------------------------------------------------------------------------------------------------
if (!isset($demo_root)) $demo_root = __DIR__;
require_once(str_replace('/',DIRECTORY_SEPARATOR,$demo_root.'/templater.php'));
//------------------------------------------------------------------------------------------------
	/**
	 * Creates a new template for the user's profile.
	 * Fills it with mockup data just for testing.
	 */
	$profile = new Template(str_replace('/',DIRECTORY_SEPARATOR,$demo_root.'/user-profile.tmpl'));
	$profile->set("username", "monk3y");
	$profile->set("photoURL", "photo.jpg");
	$profile->set("name", "Monkey man");
	$profile->set("age", "23");
	$profile->set("location", "Portugal");
//------------------------------------------------------------------------------------------------
	/**
	 * Loads our layout template, settings its title and content.
	 */
	$layout = new Template(str_replace('/',DIRECTORY_SEPARATOR,$demo_root.'/layout.tmpl'));
	$layout->set("title", "User profile");
	$layout->set("content", $profile->output());
//------------------------------------------------------------------------------------------------
	/**
	 * Outputs the page with the user's profile.
	 */
	echo $layout->output();
//------------------------------------------------------------------------------------------------
?>