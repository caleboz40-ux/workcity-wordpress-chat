<?php
/*
Plugin Name: Workcity Chat
Description: Secure role-based chat for WooCommerce. Custom recipients endpoint, persistent sessions, file uploads, shortcode widget.
Version: 1.1.0
Author: Ale Caleb
Text Domain: workcity-chat
*/

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Workcity_Chat_Plugin {

    public function __construct() {
        add_action('init', array($this, 'register_post_types'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_shortcode('workcity_chat', array($this, 'render_shortcode'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_ajax_wc_chat_poll', array($this, 'ajax_poll'));
        add_action('wp_ajax_nopriv_wc_chat_poll', array($this, 'ajax_poll'));
        add_action('wp_ajax_wc_chat_typing', array($this, 'ajax_typing'));
        add_action('wp_ajax_wc_chat_send', array($this, 'ajax_send'));
        add_action('wp_ajax_wc_chat_upload', array($this, 'ajax_upload'));
        add_action('wp_footer', array($this, 'print_templates'));
        add_action('init', array($this, 'maybe_update_last_activity'));
    }

    public function register_post_types() {
        register_post_type('chat_session', array(
            'label' => 'Chat Sessions',
            'public' => false,
            'show_ui' => true,
            'supports' => array('title','author','custom-fields'),
        ));
    }

    public function enqueue_assets() {
        wp_enqueue_style('workcity-chat-css', plugins_url('assets/chat.css', __FILE__));
        wp_enqueue_script('workcity-chat-js', plugins_url('assets/chat.js', __FILE__), array('jquery'), '1.1', true);
        wp_localize_script('workcity-chat-js', 'WorkcityChat', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => esc_url_raw(rest_url('workcity-chat/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'current_user_id' => get_current_user_id(),
            'strings' => array(
                'choose_recipient' => __('Choose recipient type','workcity-chat'),
                'no_user' => __('No user available','workcity-chat'),
            ),
        ));
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array('product_id' => 0), $atts, 'workcity_chat');
        if ( !is_user_logged_in() ) {
            return '<div class="wc-chat-locked">Please <a href="'.wp_login_url( get_permalink() ).'">log in</a> to use chat.</div>';
        }
        $product_id = intval($atts['product_id']);
        $current = get_current_user_id();
        $active_session = get_user_meta($current, 'wc_chat_active_session', true);
        ob_start();
        ?>
        <div id="workcity-chat-widget" data-product="<?php echo esc_attr($product_id); ?>" data-current-session="<?php echo esc_attr($active_session); ?>">
            <div class="wc-chat-header">
                <h3>Workcity Chat</h3>
                <div class="wc-mode-toggle"><button id="wc-toggle-mode">🌙</button></div>
            </div>
            <div class="wc-chat-controls">
                <label><?php _e('Chat with:','workcity-chat'); ?></label>
                <select id="wc-recipient-type">
                    <option value="designer">Designer</option>
                    <option value="shop_manager">Shop Manager</option>
                    <option value="agent">Agent</option>
                </select>
                <select id="wc-recipient-user" style="min-width:160px;"></select>
            </div>
            <div class="wc-chat-messages" id="wc-messages"></div>
            <div class="wc-chat-compose">
                <input type="text" id="wc-message-input" placeholder="Type a message..." />
                <input type="file" id="wc-file-input" />
                <button id="wc-send-btn">Send</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function register_rest_routes() {
        register_rest_route('workcity-chat/v1', '/sessions', array(
            'methods' => 'GET',
            'callback' => array($this,'rest_get_sessions'),
            'permission_callback' => function(){ return is_user_logged_in(); }
        ));
        register_rest_route('workcity-chat/v1', '/session', array(
            'methods' => 'POST',
            'callback' => array($this,'rest_create_session'),
            'permission_callback' => function(){ return is_user_logged_in(); }
        ));
        register_rest_route('workcity-chat/v1', '/session/(?P<id>\d+)/messages', array(
            'methods' => 'GET',
            'callback' => array($this,'rest_get_messages'),
            'permission_callback' => function(){ return is_user_logged_in(); }
        ));
        register_rest_route('workcity-chat/v1', '/session/(?P<id>\d+)/message', array(
            'methods' => 'POST',
            'callback' => array($this,'rest_post_message'),
            'permission_callback' => function(){ return is_user_logged_in(); }
        ));
        // new recipients endpoint - professional & secure
        register_rest_route('workcity-chat/v1', '/recipients/(?P<role>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this,'rest_get_recipients'),
            'permission_callback' => function(){ return is_user_logged_in(); }
        ));
    }

    // REST handlers
    public function rest_get_sessions($request){
        $user_id = get_current_user_id();
        $args = array('post_type'=>'chat_session','posts_per_page'=>50,'author'=> $user_id);
        $posts = get_posts($args);
        $extra = get_posts(array('post_type'=>'chat_session','meta_query'=>array(array('key'=>'participants','value'=>$user_id,'compare'=>'LIKE')),'posts_per_page'=>50));
        $posts = array_merge($posts,$extra);
        $out = array();
        foreach($posts as $p){
            $out[] = array('id'=>$p->ID,'title'=>$p->post_title,'product'=>get_post_meta($p->ID,'product_id',true));
        }
        return rest_ensure_response($out);
    }

    public function rest_create_session($request){
        $params = $request->get_json_params();
        $title = sanitize_text_field($params['title'] ?? 'Chat');
        $product = intval($params['product_id'] ?? 0);
        $recipient_type = sanitize_text_field($params['recipient_type'] ?? '');
        $recipient_user = intval($params['recipient_user_id'] ?? 0);
        $current = get_current_user_id();
        $session_id = wp_insert_post(array('post_type'=>'chat_session','post_title'=>$title,'post_status'=>'publish','post_author'=>$current));
        if($product) update_post_meta($session_id,'product_id',$product);
        if($recipient_type) update_post_meta($session_id,'recipient_type',$recipient_type);
        if($recipient_user) update_post_meta($session_id,'recipient_user_id',$recipient_user);
        $participants = array($current);
        if($recipient_user) $participants[] = $recipient_user;
        update_post_meta($session_id,'participants', json_encode($participants));
        // persist active session for customer so it loads after reload
        update_user_meta($current,'wc_chat_active_session',$session_id);
        return rest_ensure_response(array('session_id'=>$session_id));
    }

    public function rest_get_messages($request){
        $id = intval($request['id']);
        $comments = get_comments(array('post_id'=>$id,'status'=>'approve','orderby'=>'comment_date','order'=>'ASC'));
        $out = array();
        foreach($comments as $c){
            $meta = get_comment_meta($c->comment_ID);
            $out[] = array(
                'id'=>$c->comment_ID,
                'author_id'=>$c->user_id,
                'content'=>apply_filters('comment_text',$c->comment_content),
                'date'=>$c->comment_date,
                'meta'=>$meta
            );
        }
        return rest_ensure_response($out);
    }

    public function rest_post_message($request){
        $id = intval($request['id']);
        $params = $request->get_json_params();
        $content = wp_kses_post($params['content'] ?? '');
        $user = get_current_user_id();
        $commentdata = array(
            'comment_post_ID' => $id,
            'user_id' => $user,
            'comment_content' => $content,
            'comment_approved' => 1,
        );
        $comment_id = wp_insert_comment($commentdata);
        if($comment_id){
            add_comment_meta($comment_id,'read_by', json_encode(array($user)));
            set_transient('wc_chat_last_'.$user, time(), 60*60);
            return rest_ensure_response(array('ok'=>true,'comment_id'=>$comment_id));
        }
        return new WP_Error('failed','Could not post message', array('status'=>500));
    }

    // recipients handler - returns id + display_name only
    public function rest_get_recipients($request){
        $role = sanitize_text_field($request['role']);
        $allowed = array('designer','shop_manager','agent');
        if(!in_array($role,$allowed)) return rest_ensure_response(array());
        $users = get_users(array('role'=>$role));
        $out = array();
        foreach($users as $u){
            $out[] = array('id'=>$u->ID,'name'=>$u->display_name);
        }
        return rest_ensure_response($out);
    }

    // AJAX polling example
    public function ajax_poll(){
        check_ajax_referer('wc_chat_nonce','nonce');
        $session = intval($_POST['session'] ?? 0);
        if(!$session){ wp_send_json_error('no session'); }
        $last = sanitize_text_field($_POST['last'] ?? '');
        $comments = get_comments(array('post_id'=>$session,'status'=>'approve','orderby'=>'comment_date','order'=>'ASC','date_query'=>($last?array(array('after'=>$last)):array())));
        $out = array();
        foreach($comments as $c){
            $out[] = array('id'=>$c->comment_ID,'author'=>$c->user_id,'content'=>$c->comment_content,'date'=>$c->comment_date);
        }
        wp_send_json_success(array('messages'=>$out,'time'=>time()));
    }

    public function ajax_typing(){
        check_ajax_referer('wc_chat_nonce','nonce');
        $user = get_current_user_id();
        $session = intval($_POST['session'] ?? 0);
        set_transient('wc_chat_typing_'.$session.'_'.$user, time(), 10);
        wp_send_json_success();
    }

    public function ajax_send(){
        check_ajax_referer('wc_chat_nonce','nonce');
        $session = intval($_POST['session'] ?? 0);
        $message = wp_kses_post($_POST['message'] ?? '');
        if(!$session || !$message){ wp_send_json_error('missing'); }
        $user = get_current_user_id();
        $commentdata = array(
            'comment_post_ID' => $session,
            'user_id' => $user,
            'comment_content' => $message,
            'comment_approved' => 1,
        );
        $comment_id = wp_insert_comment($commentdata);
        if($comment_id){
            add_comment_meta($comment_id,'read_by', json_encode(array($user)));
            set_transient('wc_chat_last_'.$user, time(), 60*60);
            wp_send_json_success(array('id'=>$comment_id,'date'=>current_time('mysql')));
        }
        wp_send_json_error('failed');
    }

    public function ajax_upload(){
        check_ajax_referer('wc_chat_nonce','nonce');
        if(empty($_FILES['file'])){ wp_send_json_error('no file'); }
        $file = $_FILES['file'];
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $overrides = array('test_form' => false);
        $movefile = wp_handle_upload($file,$overrides);
        if(isset($movefile['url'])){
            wp_send_json_success(array('url'=>$movefile['url'],'file'=>$movefile));
        } else {
            wp_send_json_error($movefile);
        }
    }

    public function print_templates(){
        if(!is_user_logged_in()) return;
        ?>
        <script type="text/template" id="wc-message-template">
            <div class="wc-msg" data-id="{{id}}">
                <div class="wc-msg-author">{{author}}</div>
                <div class="wc-msg-body">{{content}}</div>
                <div class="wc-msg-time">{{time}}</div>
            </div>
        </script>
        <?php
    }

    public function maybe_update_last_activity(){
        if(is_user_logged_in()){
            $uid = get_current_user_id();
            set_transient('wc_chat_last_'.$uid, time(), 60*60);
        }
    }
}

new Workcity_Chat_Plugin();

add_action('wp_head', function(){
    if(!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wc_chat_nonce');
    echo '<script>window.WC_CHAT_NONCE = "'.$nonce.'";</script>';
});
