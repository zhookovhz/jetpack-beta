<?php

/*
Plugin Name: Jetpack Tester
Plugin URI: https://github.com/Automattic/jetpack
Description: Uses your auto-updater to update your local Jetpack to our latest alpha version from the master branch on GitHub.  DO NOT USE IN PRODUCTION.
Version: 1.0
Author: Jetpack.me (an Automattic team)
Author URI: http://jetpack.me/
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/




require 'plugin-updates/plugin-update-checker.php';
$JetpackAlpha = PucFactory::buildUpdateChecker(
    'http://alpha.bruteprotect.com/jetpack-bleeding-edge.json',
    WP_PLUGIN_DIR . '/jetpack/jetpack.php',
    'jetpack',
    '0.5'
);


function load_debug_bar_jpa_info() {
    do_action( 'add_debug_info', get_current_jetpack_version(), 'jetpack version' );
    do_action( 'add_debug_info', get_option( 'force-jetpack-update' ), 'force-jetpack-update' );
    do_action( 'add_debug_info', get_jp_versions_and_branches(), 'jp-versions' );

}
add_action( 'admin_init', 'load_debug_bar_jpa_info' );

function get_jp_versions_and_branches() {
    $versions = get_transient( 'jetpack_versions' );
    $branches = get_transient( 'jetpack_branches' );
    if( !$versions || !$branches ) {
        $versions = wp_remote_get( 'http://alpha.bruteprotect.com/jetpack-git/releases.json' );
        $branches = wp_remote_get( 'http://alpha.bruteprotect.com/jetpack-git/branches.json' );

        $versions = json_decode( $versions['body'], true );
        $branches = json_decode( $branches['body'], true );

        set_transient( 'jetpack_versions', $versions, 600 );
        set_transient( 'jetpack_branches', $branches, 600 );
    }
    return array( 'versions' => $versions, 'branches', $branches );
}

function get_current_jetpack_version() {
    $jetpack_data = get_plugin_data( WP_PLUGIN_DIR . '/jetpack/jetpack.php' );
    return $jetpack_data[ 'Version' ];
}

function set_force_jetpack_update() {
    update_option( 'force-jetpack-update', get_current_jetpack_version() );
}
//add_action( 'admin_init', 'set_force_jetpack_update' );

add_filter( 'puc_check_now-jetpack', 'check_force_jetpack_update' );
function check_force_jetpack_update( $checkNow ) {
    $forceUpdate = get_option( 'force-jetpack-update' );
    if( !$forceUpdate || $checkNow ) { return $checkNow; }
    if( $forceUpdate != get_current_jetpack_version() ) {
        update_option( 'force-jetpack-update', 0 );
    }
    return true;
}

add_filter( 'puc_request_info_result-jetpack', 'force_jetpack_update' );
function force_jetpack_update( $pluginInfo ) {
    if( !get_option( 'force-jetpack-update' ) ) { return $pluginInfo; }
    $pluginInfo->version = '9999999999999999999-forced-update';
    return $pluginInfo;
}