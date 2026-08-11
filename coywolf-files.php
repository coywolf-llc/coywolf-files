<?php
/**
 * Plugin Name:       Coywolf Files
 * Plugin URI:        https://coywolf.com/notes/coywolf-files/
 * Description:        Upload any-sized file to Cloudflare R2 or Amazon S3 and embed it with a Coywolf File block — a download card with light and dark themes, plus an All Files library that tracks which posts and pages use each file.
 * Version:           1.0.7
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/jon-henshaw/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-files
 * Update URI:        https://github.com/coywolf-llc/coywolf-files
 *
 * @package CoywolfFiles
 *
 * Coywolf Files
 * Copyright (C) 2026 Coywolf LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COYWOLF_FILES_FILE', __FILE__ );
define( 'COYWOLF_FILES_PATH', plugin_dir_path( __FILE__ ) );
define( 'COYWOLF_FILES_URL', plugin_dir_url( __FILE__ ) );

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once __DIR__ . '/includes/class-github-updater.php';
// Flags this as the GitHub distribution (stripped from the WordPress.org build).
define( 'COYWOLF_FILES_GITHUB_BUILD', true );
/* wporg-strip:end */
require_once __DIR__ . '/includes/class-coywolf-files-crypto.php';
require_once __DIR__ . '/includes/class-coywolf-files-s3.php';
require_once __DIR__ . '/includes/class-coywolf-files-settings.php';
require_once __DIR__ . '/includes/class-coywolf-files-storage.php';
require_once __DIR__ . '/includes/class-coywolf-files-registry.php';
require_once __DIR__ . '/includes/class-coywolf-files-index.php';
require_once __DIR__ . '/includes/class-coywolf-files-rest.php';
require_once __DIR__ . '/includes/class-coywolf-files-block.php';
require_once __DIR__ . '/includes/class-coywolf-files-download.php';
require_once __DIR__ . '/includes/class-coywolf-files-docs.php';
require_once __DIR__ . '/includes/class-coywolf-files-admin.php';
require_once __DIR__ . '/includes/class-coywolf-files.php';

register_activation_hook( __FILE__, array( 'Coywolf_Files', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Coywolf_Files', 'on_deactivate' ) );

Coywolf_Files::instance();

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
// Wire in the GitHub self-updater so releases show up on Dashboard → Updates.
( new Coywolf_Files_GitHub_Updater( __FILE__, Coywolf_Files::VERSION ) )->init();
/* wporg-strip:end */
