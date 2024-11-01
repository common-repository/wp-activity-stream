<?php
/*
Plugin Name: Activity Stream
Plugin URI: http://yourdomain.com/
Description: Displays a stream of author actions
Version: 1.6
Author: Don Kukral
Author URI: http://yourdomain.com
License: GPL
*/

define( 'ACTIVITY_STREAM_VERSION' , '1.5' );
define( 'ACTIVITY_STREAM_ROOT' , dirname(__FILE__) );
define( 'ACTIVITY_STREAM_URL' , plugins_url(plugin_basename(dirname(__FILE__)).'/') );
define( 'ACTIVITY_STREAM_PAGE', 'activity-stream');
define( 'ACTIVITY_STREAM_ADMIN', get_admin_url() . "index.php?page=" . ACTIVITY_STREAM_PAGE);
define( 'ACTIVITY_STREAM_PER_PAGE' , 25);

global $astream_db_ver;
$astream_db_ver = "1.0";

// create an instance
$as = new ActivityStream();

add_action( 'admin_menu', array( &$as, 'menu' ) );
add_action( 'admin_enqueue_scripts', array( &$as,'activity_stream_admin_scripts' ) );
add_action( 'admin_print_styles', array( &$as, 'activity_stream_admin_styles' ) );
add_action( 'wp_login', array( &$as, 'login' ) );
add_action( 'wp_logout', array( &$as, 'logout' ) );
add_action( 'pre_post_update', array( &$as, 'log_post' ) );

add_action('admin_print_styles-post.php', array(&$as, 'activity_stream_post_admin_styles'));

add_action( 'admin_init', array (&$as, 'activity_stream_custom_box') );

class ActivityStream {
    
    function __contruct() {
    }
    
    function activity_stream_admin_scripts() {
    	if ((array_key_exists('page', $_GET)) && ($_GET['page'] == ACTIVITY_STREAM_PAGE)) {
    	    wp_enqueue_script("activity-stream-js", ACTIVITY_STREAM_URL . "js/activity-stream.js", array('jquery'), '1.0');	
    	} else {
    	    wp_deregister_script("activity-stream-js");
    	}

    }
    
    function activity_stream_admin_styles() {
        if ((array_key_exists('page', $_GET)) && ($_GET['page'] == ACTIVITY_STREAM_PAGE)) {
    	    wp_enqueue_style( 'activity-stream-css', ACTIVITY_STREAM_URL . 'css/activity-stream.css', false );
        }
    }
    
    function activity_stream_post_admin_styles() {
	    wp_enqueue_style( 'activity-stream-css', ACTIVITY_STREAM_URL . 'css/activity-stream.css', false );        
    }
    
    function log($user_id, $post_id, $action) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . "activity_stream";
        
        $rows_affected = $wpdb->insert($table_name, 
            array( 
                'user_id' => $user_id, 
                'post_id' => $post_id,
                'action' => $action)
        );            
    }
    
    function login($login) {
        $user = get_userdatabylogin($login);
        $this->log($user->ID, 0, 'login');
    }
    
    function logout() {
        $user = wp_get_current_user();
        $this->log($user->ID, 0, 'logout');
    }
    
    function log_post($post_id) {    
        global $user_ID;
        
        $post = get_post($post_id);
        if ($post->post_type != 'post') { return; }
        
        $ignore_status = array( 'inherit', 'auto-draft');
        $current_user = wp_get_current_user();    
        $action = get_post_status($post_id);

        if ( in_array( $action, $ignore_status ) )
            return;
            
        $this->log($user_ID, $post_id, $action);
    }
    
    function get_action($post_id, $action) {
        if ($post_id) {
            $post = get_post($post_id);

            $status = get_post_status($post_id);
            $view = get_permalink($post_id);
            $edit = get_admin_url() . 'post.php?post=' . $post_id . '&action=edit';
            $activity = ACTIVITY_STREAM_ADMIN . '&post_id=' . $post_id;
            $view_link = "<a href='" . $view . "'>view</a>";
            $edit_link = "<a href='" . $edit . "'>edit</a>";
            $activity_link = "<a href='" . $activity . "'>activity</a>";
            $link = "<br/><em>" . $post->post_title . "</em>&nbsp;";
            
            $link .= "(" . $view_link . " / " . $edit_link;
            if (!(isset($_GET['post_id']))) {
                $link .=  " / " . $activity_link;
            }
            $link .= ")";

            
            if ( $status == "trash" ) {
                $link = "";
            }
            
        }
        if ( $action == 'login' ) {
            return 'logged in';
        } elseif ( $action == 'logout' ) {
            return 'logged out';
        } elseif ( $action == 'draft' ) {
            return 'saved draft of post ' . $link;
        } elseif ( $action == 'publish' ) {
            return 'saved published post ' . $link;
        } elseif ( $action == 'trash' ) {
            return 'deleted post ' . $link;
        } elseif ( $action == 'pending' ) {
            return 'saved pending post ' . $link;
        } elseif ( $action == 'future' ) {
            return 'saved scheduled post ' . $link;
        } elseif ( $action == 'export' ) {
            return 'exported post ' . $link;
        } else {
            return $action;
        }
        
    }
    
    function menu() {
        $page = add_dashboard_page(
            'Activity Stream', 
            'Activity Stream', 
            'add_users', 
            'activity-stream', 
            array( &$this, 'display_page' ));
    }
    
    function display_page() {
        ?>
    	<div class="wrap" id="activity-stream-wrap">
    	    <div id="activity-stream-title">
    	        <h2>Activity Stream</h2>
    	    </div>
    	    <?php $this->display_stream(); ?>
    	    <?php $this->display_navigation(); ?>
    	</div>    	
        <?php
    }
    
    function stream($limit = ACTIVITY_STREAM_PER_PAGE) {
        global $wpdb;
        $table_name = $wpdb->prefix . "activity_stream";
        
        $ppage = $_GET['ppage'];
        if (!$ppage) { $ppage = 0; }
        else { $ppage = ($ppage * $limit) + 1  - $limit; }        
        
        $sql = "SELECT user_id, post_id, action, ts FROM " . $table_name;
        $sql .= " ORDER BY ts DESC LIMIT " . ($ppage) . ", " . $limit;

        $rows = $wpdb->get_results($sql);
        return $rows;
    }
    
    function post_stream($limit = ACTIVITY_STREAM_PER_PAGE) {
        global $wpdb;
        $table_name = $wpdb->prefix . "activity_stream";
        
        $ppage = $_GET['ppage'];
        if (!$ppage) { $ppage = 0; }
        else { $ppage = ($ppage * $limit) + 1  - $limit; }        
        
        $sql = "SELECT user_id, post_id, action, ts FROM " . $table_name;
        $sql .= " WHERE post_id=" . $_GET['post_id'];
        $sql .= " ORDER BY ts DESC LIMIT " . ($ppage) . ", " . $limit;

        $rows = $wpdb->get_results($sql);
        return $rows;
    }
    
    function author_stream($limit = ACTIVITY_STREAM_PER_PAGE) {
        global $wpdb;
        $table_name = $wpdb->prefix . "activity_stream";
        
        $ppage = $_GET['ppage'];
        if (!$ppage) { $ppage = 0; }
        else { $ppage = ($ppage * $limit) + 1  - $limit; }        
        
        $sql = "SELECT user_id, post_id, action, ts FROM " . $table_name;
        $sql .= " WHERE user_id=" . $_GET['user_id'];
        $sql .= " ORDER BY ts DESC LIMIT " . ($ppage) . ", " . $limit;

        $rows = $wpdb->get_results($sql);
        return $rows;        
    }
    
    function display_stream($limit = ACTIVITY_STREAM_PER_PAGE) {

        if (isset($_GET['post_id'])) {
            $rows = $this->post_stream();
        }  elseif (isset($_GET['user_id'])) {
            $rows = $this->author_stream();
        } else {
            $rows = $this->stream();
        }
        
        ?>
        <table id="activity-stream-results">
        <tr>
            <th class="time">Time</th>
            <th class="activity">Activity</th>
        </tr>
        <?php foreach ($rows as $row) { 
            $a_link = '<a href="'. ACTIVITY_STREAM_ADMIN . '&user_id=' . $row->user_id . '">';
        ?>
        <tr>
            <td><?php echo $row->ts; ?></td>
            <td><?php $user = get_userdata($row->user_id); 
                echo '<a href="'. ACTIVITY_STREAM_ADMIN . '&user_id=' . $row->user_id . '">';
                echo $user->display_name . '</a>'; ?>
                <?php echo $this->get_action($row->post_id, $row->action); ?></td>
        </tr>
        <?php } ?>
        </table>
        <?php        
    }
    
    function display_navigation($limit = ACTIVITY_STREAM_PER_PAGE) {
        global $wpdb;
        
        $ppage = $_GET['ppage'];
        if (!$ppage) { $ppage = 1; }
        
        $sql = "SELECT COUNT(*) AS c FROM " . $wpdb->prefix . "activity_stream";
        if (isset($_GET['post_id'])) {
            $sql .= " WHERE post_id=" . $_GET['post_id'];
        }
        
        if (isset($_GET['user_id'])) {
            $sql .= " WHERE user_id=" . $_GET['user_id'];
        }
        
        
        $row = $wpdb->get_row($sql);
        
        $pages = round($row->c / $limit);
        if ($pages < 1) { $pages = 1; }
        
        if ($ppage > 1) {
            $prev = '<a href="' . ACTIVITY_STREAM_ADMIN . '&ppage=' . ($ppage-1);
            if (isset($_GET['post_id'])) {
                $prev .= '&post_id=' . $_GET['post_id'];
            }
            if (isset($_GET['user_id'])) {
                $prev .= '&user_id=' . $_GET['user_id'];
            }
            $prev .= '">&lt;</a>';
        } else { $prev = ''; }
        
        #echo $prev;
        #echo " " . $ppage . " of " . $pages;        
        
        if ($ppage < $pages) {
            $next = '<a href="' . ACTIVITY_STREAM_ADMIN . '&ppage='. ($ppage+1);
            if (isset($_GET['post_id'])) {
                $next .= '&post_id=' . $_GET['post_id'];
            }
            if (isset($_GET['user_id'])) {
                $next .= '&user_id=' . $_GET['user_id'];
            }
            $next .= '">&gt;</a>';            
        } else { $next = ''; }
        #echo $next;
        
        ?>
        <div id="activity-stream-nav">
        <span class="arrow"><?php echo $prev; ?></span>
        <span><?php echo " " . $ppage . " of " . $pages;?></span>
        <span class="arrow"><?php echo $next; ?></span>
        <?php
        if ((isset($_GET['user_id'])) || (isset($_GET['post_id']))) {
            echo '<p><a href="' . ACTIVITY_STREAM_ADMIN . '">Return to Full Stream</a></p>';
        }
        ?>
        </div>
        <?php        
    }

    function activity_stream_custom_box() {
        add_meta_box("activity-stream", __("Activity Stream", "activity_stream"), array (&$this, "activity_stream_innerbox_html"), "post", "advanced");
    }
    
    function activity_stream_innerbox_html() {
        global $post;
        global $wpdb;
        
        $table_name = $wpdb->prefix . "activity_stream";
        
        $sql = "SELECT user_id, post_id, action, ts FROM " . $table_name;
        $sql .= " WHERE post_id=" . $post->ID;
        $sql .= " ORDER BY ts DESC ";

        $rows = $wpdb->get_results($sql);
        ?>
        <table id="activity-stream-results">
        <tr>
            <th class="time">Time</th>
            <th class="activity">Activity</th>
        </tr>
        <?php foreach ($rows as $row) { 
            $a_link = '<a href="'. ACTIVITY_STREAM_ADMIN . '&user_id=' . $row->user_id . '">';
        ?>
        <tr>
            <td><?php echo $row->ts; ?></td>
            <td><?php $user = get_userdata($row->user_id); 
                echo '<a href="'. ACTIVITY_STREAM_ADMIN . '&user_id=' . $row->user_id . '">';
                echo $user->display_name . '</a>'; ?>
                <?php echo $this->get_action($row->post_id, $row->action); ?></td>
        </tr>
        <?php } ?>
        </table>
        <?php
    }
}


function astream_install() {
    global $wpdb;
    global $astream_db_ver;
    
    $table_name = $wpdb->prefix . "activity_stream";
    
    $sql = "CREATE TABLE " . $table_name . " (
        id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NOT NULL,
        post_id BIGINT(20) NOT NULL DEFAULT 0,
        action VARCHAR(255) NOT NULL,
        ts TIMESTAMP NOT NULL,
        UNIQUE KEY id (id));";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    add_option("astream_db_ver", $astream_db_ver);
    
}

register_activation_hook(__FILE__, 'astream_install');
